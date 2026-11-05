'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { OperationJournal, uploadResumable } = require('../local-addon/lib/reliability');

test('operation journal persists and reports interrupted work', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-journal-'));
  try {
    const journal = new OperationJournal(dir);
    const start = journal.begin('site-one', 'push');
    journal.progress('site-one', 'Uploading', 40);
    const restored = new OperationJournal(dir).read('site-one');
    assert.equal(restored.id, start.id);
    assert.equal(restored.step, 'Uploading');
    assert.equal(restored.percent, 40);
    journal.finish('site-one', 'failed', 'Test interruption');
    assert.equal(new OperationJournal(dir).read('site-one').status, 'failed');
    assert.ok(!journal.filename('site-one').includes('site-one'));
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

test('chunked client uploads resumably with SHA-256 and import key', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-chunks-'));
  try {
    const filePath = path.join(dir, 'test.zip');
    const file = crypto.randomBytes(5500);
    fs.writeFileSync(filePath, file);
    let offset = 0;
    let firstFailure = true;
    const calls = [];
    const apiJson = async (_config, _target, method, route, body) => {
      calls.push(route);
      if (route === '/transfer/start') {
        assert.equal(body.size, file.length);
        assert.equal(body.sha256, crypto.createHash('sha256').update(file).digest('hex'));
        return { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', chunk_size: 1024 };
      }
      if (route.startsWith('/transfer/status')) return { offset, size: file.length };
      if (route === '/transfer/complete') {
        assert.equal(offset, file.length);
        return { file_key: 'upload-key.zip', sha256: crypto.createHash('sha256').update(file).digest('hex') };
      }
      if (route === '/site/import') {
        assert.equal(body.file_key, 'upload-key.zip');
        return { ok: true };
      }
      throw new Error('Unexpected route ' + route);
    };
    const uploadFileStream = async (_config, _target, route, chunkPath, _field, _name, fields) => {
      assert.equal(route, '/transfer/chunk');
      const chunk = fs.readFileSync(chunkPath);
      assert.equal(fields.sha256, crypto.createHash('sha256').update(chunk).digest('hex'));
      if (firstFailure) { firstFailure = false; throw new Error('Temporary socket reset'); }
      assert.equal(fields.offset, offset);
      offset += chunk.length;
      return { offset };
    };
    const result = await uploadResumable({ config: {}, target: 'local', apiJson, uploadFileStream, filePath, importPath: '/site/import' });
    assert.equal(result.ok, true);
    assert.ok(calls.some(route => route.startsWith('/transfer/status')));
    assert.ok(calls.includes('/transfer/complete'));
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

test('cancellation stops before next chunk', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-cancel-'));
  try {
    const filePath = path.join(dir, 'test.zip');
    fs.writeFileSync(filePath, crypto.randomBytes(3000));
    let cancelled = false;
    const apiJson = async (_config, _target, _method, route) => {
      if (route === '/transfer/start') return { id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', chunk_size: 1000 };
      throw new Error('Unexpected route');
    };
    await assert.rejects(uploadResumable({
      config: {}, target: 'local', apiJson, filePath, importPath: '/site/import',
      uploadFileStream: async () => { cancelled = true; return { offset: 1000 }; },
      isCancelled: () => cancelled,
    }), /cancelled/);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});
