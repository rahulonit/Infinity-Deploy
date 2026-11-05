'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

class OperationJournal {
  constructor(directory) {
    this.directory = directory;
    fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  }
  filename(siteId) {
    return path.join(this.directory, crypto.createHash('sha256').update(String(siteId)).digest('hex') + '.json');
  }
  read(siteId) {
    try { return JSON.parse(fs.readFileSync(this.filename(siteId), 'utf8')); }
    catch (_) { return null; }
  }
  write(siteId, state) {
    const file = this.filename(siteId);
    const tmp = file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify({ ...state, updatedAt: new Date().toISOString() }), { mode: 0o600 });
    fs.renameSync(tmp, file);
  }
  begin(siteId, kind) {
    const old = this.read(siteId);
    if (old && old.status === 'running') this.write(siteId, { ...old, status: 'interrupted' });
    const record = { id: crypto.randomBytes(12).toString('hex'), kind, status: 'running', step: 'Starting', percent: 0, startedAt: new Date().toISOString() };
    this.write(siteId, record);
    return record;
  }
  progress(siteId, step, percent) {
    const current = this.read(siteId);
    if (current && current.status === 'running') this.write(siteId, { ...current, step, percent });
  }
  finish(siteId, status, note) {
    const current = this.read(siteId);
    if (current) this.write(siteId, { ...current, status, note: String(note || '').slice(0, 1000) });
  }
}

async function uploadResumable({ config, target, apiJson, uploadFileStream, filePath, importPath, extraFields = {}, onCheckpoint = () => {}, isCancelled = () => false }) {
  const stat = fs.statSync(filePath);
  const size = stat.size;
  const hash = crypto.createHash('sha256');
  await new Promise((resolve, reject) => {
    const stream = fs.createReadStream(filePath);
    stream.on('data', chunk => hash.update(chunk));
    stream.on('error', reject);
    stream.on('end', resolve);
  });
  const fingerprint = hash.digest('hex');
  const upload = await apiJson(config, target, 'POST', '/transfer/start', { filename: path.basename(filePath), size, sha256: fingerprint });
  const id = upload.id;
  const chunkSize = Math.min(upload.chunk_size || 4 * 1024 * 1024, 4 * 1024 * 1024);
  const chunkPath = filePath + '.chunk-' + id;
  let completed = false;
  try {
    let offset = 0;
    let failures = 0;
    while (offset < size) {
      if (isCancelled()) throw new Error('Transfer cancelled at a safe chunk boundary.');
      const length = Math.min(chunkSize, size - offset);
      const fd = fs.openSync(filePath, 'r');
      const buffer = Buffer.allocUnsafe(length);
      try {
        const read = fs.readSync(fd, buffer, 0, length, offset);
        if (read !== length) throw new Error('Source file changed during resumable upload.');
      } finally { fs.closeSync(fd); }
      fs.writeFileSync(chunkPath, buffer, { mode: 0o600 });
      try {
        const result = await uploadFileStream(config, target, '/transfer/chunk', chunkPath, 'chunk', 'chunk.bin', {
          id, offset, sha256: crypto.createHash('sha256').update(buffer).digest('hex'),
        });
        if (!result || result.offset !== offset + length) throw new Error('Unexpected server chunk acknowledgment.');
        offset = result.offset;
        failures = 0;
        onCheckpoint({ id, offset, size, sha256: fingerprint });
      } catch (error) {
        if (++failures > 3) throw error;
        const status = await apiJson(config, target, 'GET', '/transfer/status?id=' + encodeURIComponent(id));
        if (status.offset < offset || status.offset > size) throw new Error('Invalid server resume offset.');
        offset = status.offset;
      }
    }
    const result = await apiJson(config, target, 'POST', '/transfer/complete', { id });
    if (!result || result.sha256 !== fingerprint) throw new Error('Final checksum mismatch.');
    completed = true;
    return await apiJson(config, target, 'POST', importPath, { file_key: result.file_key, ...extraFields });
  } finally {
    try { fs.unlinkSync(chunkPath); } catch (_) {}
    if (!completed) {
      // Preserve the remote session for its bounded TTL; status can be used for diagnostics.
      onCheckpoint({ id, interrupted: true, size, sha256: fingerprint });
    }
  }
}
module.exports = { OperationJournal, uploadResumable };
