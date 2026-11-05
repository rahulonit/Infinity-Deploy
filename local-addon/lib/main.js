'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const zlib = require('zlib');
const { OperationJournal, uploadResumable } = require('./reliability');
const { compareContent, compareFiles, preflight } = require('./deployment-models');
const { registerFullSiteHandlers } = require('./full-site');
const ADDON_VERSION = '1.1.0';
const MIN_FULL_SITE_BRIDGE_VERSION = '1.0.4';

const CHANNELS = [
  'infinity-deploy:get-config',
  'infinity-deploy:save-config',
  'infinity-deploy:test',
  'infinity-deploy:preview',
  'infinity-deploy:push-theme',
  'infinity-deploy:push-content',
  'infinity-deploy:pull-content',
  'infinity-deploy:rollback',
  'infinity-deploy:clone-full-site',
  'infinity-deploy:push-full-site',
  'infinity-deploy:get-db-snapshots',
  'infinity-deploy:restore-db-snapshot',
  'infinity-deploy:get-operation',
  'infinity-deploy:cancel-operation',
  'infinity-deploy:preflight',
  'infinity-deploy:compare-files',
  'infinity-deploy:get-audit-logs',
];

function infinityDeployMain(context) {
  const { ipcMain } = context.electron;
  let safeStorage = context.electron.safeStorage;
  let electronApp = context.electron.app;
  if (!safeStorage) {
    try { safeStorage = require('electron').safeStorage; } catch (_) { safeStorage = null; }
  }
  if (!electronApp) {
    try { electronApp = require('electron').app; } catch (_) { electronApp = null; }
  }
  const userDataPath = context.environment && context.environment.userDataPath
    ? context.environment.userDataPath
    : electronApp.getPath('userData');
  const store = new ConfigStore(path.join(userDataPath, 'infinity-deploy', 'connections.json'), safeStorage);
  const journal = new OperationJournal(path.join(userDataPath, 'infinity-deploy', 'operations'));
  const cancelled = new Set();
  CHANNELS.forEach((channel) => {
    try { ipcMain.removeHandler(channel); } catch (_) { /* Handler was not registered. */ }
  });

  ipcMain.handle('infinity-deploy:get-operation', (_event, site) => {
    const record = journal.read(site.id);
    return record && record.status === 'running' ? { ...record, status: 'interrupted' } : record;
  });
  ipcMain.handle('infinity-deploy:cancel-operation', (_event, site) => {
    cancelled.add(String(site.id));
    const record = journal.read(site.id);
    if (record && record.status === 'running') journal.progress(site.id, 'Cancellation requested; waiting for safe checkpoint', record.percent);
    return { ok: true, cooperative: true };
  });


  ipcMain.handle('infinity-deploy:get-config', async (_event, site) => {
    const saved = store.get(site.id);
    return Object.assign({
      localUrl: `http://${site.domain}`,
      localUser: '',
      localPassword: '',
      liveUrl: '',
      liveUser: '',
      livePassword: '',
      themeSlug: 'rahul-graphics',
    }, saved);
  });

  ipcMain.handle('infinity-deploy:save-config', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    store.set(site.id, clean);
    return { ok: true };
  });

  ipcMain.handle('infinity-deploy:test', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    const [local, live] = await Promise.all([
      apiJson(clean, 'local', 'GET', '/status').catch((err) => {
        throw new Error(`Local connection failed: ${err.message}. Ensure your Local WordPress username is your actual login username (e.g. "admin"), not the password label.`);
      }),
      apiJson(clean, 'live', 'GET', '/status').catch((err) => {
        throw new Error(`Live connection failed: ${err.message}. Ensure your Live WordPress username is your actual admin email/username (e.g. "rahulonit@gmail.com"), not the password label.`);
      }),
    ]);
    return { ok: true, local, live };
  });

  ipcMain.handle('infinity-deploy:preview', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    const [source, target] = await Promise.all([
      apiJson(clean, 'local', 'GET', '/content/export'),
      apiJson(clean, 'live', 'GET', '/content/manifest'),
    ]);
    return compareContent(source, target);
  });

  ipcMain.handle('infinity-deploy:preflight', async (_event, site, config, options={}) => {
    const clean=normalizeConfig(config,site);
    const [local,live]=await Promise.all([apiJson(clean,'local','GET','/status'),apiJson(clean,'live','GET','/status')]);
    const components=['themes'];
    if(options.includeUploads)components.push('uploads');
    if(options.includePlugins)components.push('plugins');
    return preflight(local,live,MIN_FULL_SITE_BRIDGE_VERSION,components);
  });
  ipcMain.handle('infinity-deploy:get-audit-logs', async (_event, site, config) => {
    const clean=normalizeConfig(config,site);
    return apiJson(clean,'live','GET','/logs');
  });
  ipcMain.handle('infinity-deploy:compare-files', async (_event, site, config, options={}) => {
    const clean=normalizeConfig(config,site);
    const components=['themes'];
    if(options.includeUploads)components.push('uploads');
    if(options.includePlugins)components.push('plugins');
    const [local,live]=await Promise.all([
      apiJson(clean,'local','POST','/site/manifest',{components}),
      apiJson(clean,'live','POST','/site/manifest',{components})
    ]);
    return compareFiles(local,live);
  });

  ipcMain.handle('infinity-deploy:push-theme', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    notifyProgress(_event, 'Validating live connection...', 10);
    await apiJson(clean, 'live', 'GET', '/status');

    notifyProgress(_event, 'Packaging theme directory...', 30);
    const packagePath = packageTheme(site, clean.themeSlug);
    try {
      notifyProgress(_event, 'Calculating package checksum...', 50);
      const body = fs.readFileSync(packagePath);
      const checksum = crypto.createHash('sha256').update(body).digest('hex');

      notifyProgress(_event, `Uploading theme (${(body.length / (1024 * 1024)).toFixed(1)} MB)...`, 65);
      const result = await apiMultipart(clean, 'live', '/theme/deploy',
        { theme_slug: clean.themeSlug },
        { field: 'package', filename: `${clean.themeSlug}.zip`, mime: 'application/zip', data: body },
        { 'X-Infinity-Checksum': checksum }
      );

      notifyProgress(_event, 'Running theme verification & health checks...', 85);
      const maintenance = await apiJson(clean, 'live', 'POST', '/maintenance', {});
      notifyProgress(_event, 'Theme deployed successfully.', 100);
      return { ok: true, deployment: result, maintenance };
    } finally {
      try { fs.unlinkSync(packagePath); } catch (_) { /* Temporary package already removed. */ }
    }
  });

  ipcMain.handle('infinity-deploy:push-content', async (_event, site, config, selectedUuids) => {
    const clean = normalizeConfig(config, site);
    notifyProgress(_event, 'Exporting local content & reading live manifest...', 10);
    const [source, target] = await Promise.all([
      apiJson(clean, 'local', 'GET', '/content/export'),
      apiJson(clean, 'live', 'GET', '/content/manifest'),
    ]);

    const selectedSet = Array.isArray(selectedUuids) && selectedUuids.length > 0 ? new Set(selectedUuids) : null;
    const items = source.items
      .filter((item) => !selectedSet || selectedSet.has(item.uuid))
      .filter((item) => !target.items[item.uuid] || target.items[item.uuid].hash !== item.hash)
      .map((item) => Object.assign({}, item, {
        expected_remote_hash: target.items[item.uuid] ? target.items[item.uuid].hash : '',
      }));

    // Find media relevant to selected items (or all changed media if pushing everything)
    const mediaToSync = (source.media || []).filter((item) => {
      const remote = target.media ? target.media[item.uuid] : null;
      return !remote || remote.hash !== item.hash;
    });

    notifyProgress(_event, `Synchronizing ${mediaToSync.length} media file(s)...`, 25);
    const mediaResults = await pMap(mediaToSync, 3, async (item, index) => {
      try {
        notifyProgress(_event, `Uploading media ${index + 1} of ${mediaToSync.length} (${item.filename})...`, 25 + Math.round((index / (mediaToSync.length || 1)) * 35));
        const mediaBytes = await requestRaw(item.url, clean.localUser, clean.localPassword);
        const uploaded = await apiMultipart(clean, 'live', '/media/sync',
          { uuid: item.uuid, hash: item.hash, title: item.title || '', alt: item.alt || '' },
          { field: 'media', filename: item.filename, mime: item.mime || 'application/octet-stream', data: mediaBytes },
          {}
        );
        return uploaded.media;
      } catch (err) {
        return { uuid: item.uuid, status: 'error', message: err.message };
      }
    });

    notifyProgress(_event, `Synchronizing ${items.length} content item(s)...`, 65);
    const contentResults = [];
    for (let index = 0; index < items.length; index += 25) {
      const chunk = items.slice(index, index + 25);
      notifyProgress(_event, `Syncing items ${index + 1} to ${Math.min(index + 25, items.length)} of ${items.length}...`, 65 + Math.round((index / (items.length || 1)) * 25));
      const response = await apiJson(clean, 'live', 'POST', '/content/sync', {
        items: chunk,
        source_url: clean.localUrl,
      });
      if (response && response.results) {
        contentResults.push(...response.results);
      }
    }

    notifyProgress(_event, 'Finalizing live maintenance...', 95);
    const maintenance = await apiJson(clean, 'live', 'POST', '/maintenance', {});
    notifyProgress(_event, 'Content push complete.', 100);
    return { ok: true, media: mediaResults, content: contentResults, maintenance };
  });

  ipcMain.handle('infinity-deploy:pull-content', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    notifyProgress(_event, 'Exporting live content & reading local manifest...', 10);
    const [source, target] = await Promise.all([
      apiJson(clean, 'live', 'GET', '/content/export'),
      apiJson(clean, 'local', 'GET', '/content/manifest'),
    ]);

    const mediaToSync = (source.media || []).filter((item) => {
      const localMedia = target.media ? target.media[item.uuid] : null;
      return !localMedia || localMedia.hash !== item.hash;
    });

    notifyProgress(_event, `Pulling ${mediaToSync.length} media file(s)...`, 25);
    const mediaResults = await pMap(mediaToSync, 3, async (item, index) => {
      try {
        notifyProgress(_event, `Downloading media ${index + 1} of ${mediaToSync.length} (${item.filename})...`, 25 + Math.round((index / (mediaToSync.length || 1)) * 35));
        const mediaBytes = await requestRaw(item.url, clean.liveUser, clean.livePassword);
        const uploaded = await apiMultipart(clean, 'local', '/media/sync',
          { uuid: item.uuid, hash: item.hash, title: item.title || '', alt: item.alt || '' },
          { field: 'media', filename: item.filename, mime: item.mime || 'application/octet-stream', data: mediaBytes },
          {}
        );
        return uploaded.media;
      } catch (mediaErr) {
        return { uuid: item.uuid, status: 'error', message: mediaErr.message };
      }
    });

    const items = (source.items || [])
      .filter((item) => !target.items[item.uuid] || target.items[item.uuid].hash !== item.hash)
      .map((item) => Object.assign({}, item, { expected_remote_hash: '' }));

    notifyProgress(_event, `Applying ${items.length} content item(s) to local...`, 65);
    const contentResults = [];
    for (let index = 0; index < items.length; index += 25) {
      const chunk = items.slice(index, index + 25);
      notifyProgress(_event, `Applying items ${index + 1} to ${Math.min(index + 25, items.length)} of ${items.length}...`, 65 + Math.round((index / (items.length || 1)) * 25));
      const response = await apiJson(clean, 'local', 'POST', '/content/sync', {
        items: chunk,
        source_url: clean.liveUrl,
      });
      if (response && response.results) {
        contentResults.push(...response.results);
      }
    }

    notifyProgress(_event, 'Finalizing local site maintenance...', 95);
    const maintenance = await apiJson(clean, 'local', 'POST', '/maintenance', {});
    notifyProgress(_event, 'Content pull complete.', 100);
    return {
      ok: true,
      media: mediaResults,
      content: contentResults,
      pulledCount: contentResults.length,
      mediaCount: mediaResults.filter((m) => m && m.status === 'uploaded').length,
      maintenance,
    };
  });

  ipcMain.handle('infinity-deploy:rollback', async (_event, site, config, backupKey) => {
    const clean = normalizeConfig(config, site);
    notifyProgress(_event, `Restoring theme backup ${backupKey}...`, 40);
    const res = await apiJson(clean, 'live', 'POST', '/theme/rollback', { backup_key: backupKey, theme_slug: clean.themeSlug });
    notifyProgress(_event, 'Rollback completed.', 100);
    return res;
  });

  registerFullSiteHandlers(ipcMain, { normalizeConfig, apiJson, assertFullSiteCompatibility, notifyProgress, downloadPackageToFile, uploadResumable, journal, cancelled, uploadFileStream });

  ipcMain.handle('infinity-deploy:get-db-snapshots', async (_event, site, config) => {
    const clean = normalizeConfig(config, site);
    return apiJson(clean, 'live', 'GET', '/db/snapshots');
  });

  ipcMain.handle('infinity-deploy:restore-db-snapshot', async (_event, site, config, snapshotKey) => {
    const clean = normalizeConfig(config, site);
    notifyProgress(_event, `Restoring Live database snapshot ${snapshotKey}...`, 50);
    const res = await apiJson(clean, 'live', 'POST', '/db/restore', { snapshot_key: snapshotKey });
    notifyProgress(_event, 'Database restored.', 100);
    return res;
  });
}

module.exports = infinityDeployMain;
module.exports.default = infinityDeployMain;

class ConfigStore {
  constructor(filename, safeStorage) {
    this.filename = filename;
    this.safeStorage = safeStorage;
    // Passwords remain in memory for this Local session when secure storage is unavailable.
    this.sessionSecrets = new Map();
  }
  read() {
    try { return JSON.parse(fs.readFileSync(this.filename, 'utf8')); } catch (_) { return {}; }
  }
  write(data) {
    fs.mkdirSync(path.dirname(this.filename), { recursive: true });
    fs.writeFileSync(this.filename, JSON.stringify(data, null, 2), { mode: 0o600 });
    try { fs.chmodSync(this.filename, 0o600); } catch (_) { /* Windows does not implement POSIX modes. */ }
  }
  protect(value) {
    if (!value) return '';
    if (this.safeStorage && this.safeStorage.isEncryptionAvailable()) {
      return `safe:${this.safeStorage.encryptString(value).toString('base64')}`;
    }
    // Never write an obfuscated (but recoverable) Application Password to disk.
    return '';
  }
  reveal(value) {
    if (!value) return '';
    if (value.startsWith('safe:') && this.safeStorage && this.safeStorage.isEncryptionAvailable()) {
      return this.safeStorage.decryptString(Buffer.from(value.slice(5), 'base64'));
    }
    if (value.startsWith('safe:')) return '';
    // Legacy base64/plaintext values are deliberately not loaded; re-enter the password.
    return '';
  }
  get(siteId) {
    const item = this.read()[siteId] || {};
    const session = this.sessionSecrets.get(siteId) || {};
    return Object.assign({}, item, {
      livePassword: session.livePassword || this.reveal(item.livePassword || ''),
      localPassword: session.localPassword || this.reveal(item.localPassword || ''),
    });
  }
  set(siteId, config) {
    const all = this.read();
    this.sessionSecrets.set(siteId, {
      livePassword: config.livePassword || '',
      localPassword: config.localPassword || '',
    });
    all[siteId] = Object.assign({}, config, {
      livePassword: this.protect(config.livePassword),
      localPassword: this.protect(config.localPassword),
    });
    this.write(all);
  }
}

function normalizeConfig(config, site) {
  const normalized = {
    localUrl: normalizeUrl(config.localUrl || `http://${site.domain}`),
    localUser: String(config.localUser || '').trim(),
    localPassword: String(config.localPassword || '').replace(/\s/g, ''),
    liveUrl: normalizeUrl(config.liveUrl || ''),
    liveUser: String(config.liveUser || '').trim(),
    livePassword: String(config.livePassword || '').replace(/\s/g, ''),
    themeSlug: String(config.themeSlug || 'rahul-graphics').toLowerCase().replace(/[^a-z0-9_-]/g, ''),
    requestTimeoutMs: Math.min(1800000, Math.max(30000, Number(config.requestTimeoutMs) || 600000)),
  };
  if (!normalized.liveUrl || !normalized.liveUser || !normalized.livePassword) throw new Error('Live URL, username, and Application Password are required.');
  if (!normalized.localUser || !normalized.localPassword) throw new Error('Local username and Application Password are required for content export.');
  const live = new URL(normalized.liveUrl);
  const liveIsLocal = live.hostname === 'localhost' || live.hostname === '127.0.0.1' || live.hostname.endsWith('.local');
  if ('https:' !== live.protocol && !liveIsLocal) throw new Error('The live WordPress URL must use HTTPS.');
  return normalized;
}

function normalizeUrl(value) {
  if (!value) return '';
  const url = new URL(value.includes('://') ? value : `https://${value}`);

  // Keep a WordPress subdirectory (for example, example.com/portfolio) intact.
  url.pathname = url.pathname.replace(/\/+$/, '') || '/'; url.search = ''; url.hash = '';
	return url.toString().replace(/\/$/, '');
}

function assertFullSiteCompatibility(status, label) {
  const actual = status && status.bridge_version ? String(status.bridge_version) : 'unknown';
  if (!versionAtLeast(actual, MIN_FULL_SITE_BRIDGE_VERSION)) {
    throw new Error(`${label} is running Infinity Deploy Bridge ${actual}. Full-site migration requires Bridge ${MIN_FULL_SITE_BRIDGE_VERSION} or later. Update the Bridge on both sites, then test both connections again.`);
  }
  if (!status.deployment_enabled) {
    throw new Error(`${label} Infinity Deploy deployments are disabled. Enable them under WordPress → Tools → Infinity Deploy.`);
  }
}

function versionAtLeast(actual, minimum) {
  if (!/^\d+(\.\d+){1,2}$/.test(actual)) return false;
  const left = actual.split('.').map(Number);
  const right = minimum.split('.').map(Number);
  for (let index = 0; index < Math.max(left.length, right.length); index++) {
    const a = left[index] || 0;
    const b = right[index] || 0;
    if (a > b) return true;
    if (a < b) return false;
  }
  return true;
}

function endpoint(config, target, apiPath) {
  const base = target === 'live' ? config.liveUrl : config.localUrl;
  return `${base}/wp-json/infinity-deploy/v1${apiPath}`;
}

function credentials(config, target) {
  return target === 'live'
    ? { user: config.liveUser, password: config.livePassword }
    : { user: config.localUser, password: config.localPassword };
}

async function apiJson(config, target, method, apiPath, body) {
  const auth = credentials(config, target);
  const payload = body === undefined ? null : Buffer.from(JSON.stringify(body));
  const response = await request(endpoint(config, target, apiPath), {
    method,
    user: auth.user,
    password: auth.password,
    headers: payload ? { 'Content-Type': 'application/json', 'Content-Length': String(payload.length) } : {},
    body: payload,
    timeout: config.requestTimeoutMs,
  });
  return parseJsonResponse(response);
}

async function apiMultipart(config, target, apiPath, fields, file, extraHeaders) {
  const boundary = `----InfinityDeploy${crypto.randomBytes(12).toString('hex')}`;
  const parts = [];
  Object.entries(fields).forEach(([name, value]) => {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${escapeHeader(name)}"\r\n\r\n${String(value)}\r\n`));
  });
  parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${escapeHeader(file.field)}"; filename="${escapeHeader(file.filename)}"\r\nContent-Type: ${file.mime}\r\n\r\n`));
  parts.push(file.data);
  parts.push(Buffer.from(`\r\n--${boundary}--\r\n`));
  const body = Buffer.concat(parts);
  const auth = credentials(config, target);
  const response = await request(endpoint(config, target, apiPath), {
    method: 'POST', user: auth.user, password: auth.password,
    headers: Object.assign({ 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'Content-Length': String(body.length) }, extraHeaders),
    body,
    timeout: config.requestTimeoutMs,
  });
  return parseJsonResponse(response);
}

function requestRaw(url, user, password) {
  return request(url, { method: 'GET', user, password, headers: {}, body: null }).then((result) => {
    if (result.status < 200 || result.status >= 400) throw new Error(`Media download failed with HTTP ${result.status}.`);
    return result.body;
  });
}

function request(urlString, options, redirects = 0, retries = 2) {
  return new Promise((resolve, reject) => {
    const url = new URL(urlString);
    const transport = url.protocol === 'https:' ? https : http;
    const isLocal = url.hostname.endsWith('.local') || url.hostname === 'localhost' || url.hostname === '127.0.0.1';
    const headers = Object.assign({
      Connection: 'close',
    }, options.headers, {
      Accept: 'application/json',
      Authorization: `Basic ${Buffer.from(`${options.user}:${options.password}`).toString('base64')}`,
      'User-Agent': `InfinityDeployLocal/${ADDON_VERSION}`,
    });
    const timeoutMs = options.timeout || 600000;
    const req = transport.request(url, {
      method: options.method || 'GET', headers, timeout: timeoutMs,
      rejectUnauthorized: !isLocal,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        if ([301, 302, 307, 308].includes(res.statusCode) && res.headers.location && redirects < 3) {
          const next = new URL(res.headers.location, url);
          if (next.hostname !== url.hostname || (url.protocol === 'https:' && next.protocol !== 'https:')) {
            reject(new Error('WordPress redirected the authenticated request to an unsafe destination.')); return;
          }
          resolve(request(next.toString(), options, redirects + 1, retries)); return;
        }
        resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) });
      });
    });
    req.on('timeout', () => req.destroy(new Error(`The request timed out after ${Math.round(timeoutMs / 1000)} seconds.`)));
    req.on('error', (err) => {
      if ((err.code === 'ECONNRESET' || err.message.includes('socket hang up')) && retries > 0) {
        setTimeout(() => {
          resolve(request(urlString, options, redirects, retries - 1));
        }, 1000);
      } else {
        reject(err);
      }
    });
    if (options.body) req.write(options.body);
    req.end();
  });
}

function stripHtml(html) {
  return String(html || '')
    .replace(/<[^>]*>?/gm, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function downloadPackageToFile(config, target, fileKey, destPath, onProgress, redirects = 0, retries = 2) {
  if (redirects > 3) {
    return Promise.reject(new Error('Too many redirects while downloading package.'));
  }
  return new Promise((resolve, reject) => {
    const auth = credentials(config, target);
    const query = new URLSearchParams({ file_key: fileKey }).toString();
    const urlStr = `${endpoint(config, target, '/package/download')}?${query}`;
    const url = new URL(urlStr);
    const transport = url.protocol === 'https:' ? https : http;
    const isLocal = url.hostname.endsWith('.local') || url.hostname === 'localhost' || url.hostname === '127.0.0.1';

    const req = transport.request(url, {
      method: 'GET',
      headers: {
        Connection: 'close',
        Authorization: `Basic ${Buffer.from(`${auth.user}:${auth.password}`).toString('base64')}`,
        'User-Agent': `InfinityDeployLocal/${ADDON_VERSION}`,
      },
      timeout: config.requestTimeoutMs || 600000,
      rejectUnauthorized: !isLocal,
    }, (res) => {
      if ([301, 302, 307, 308].includes(res.statusCode) && res.headers.location) {
        const next = new URL(res.headers.location, url);
        if (next.hostname !== url.hostname || (url.protocol === 'https:' && next.protocol !== 'https:')) {
          reject(new Error('WordPress redirected download request to an unsafe destination.'));
          return;
        }
        resolve(downloadPackageToFile(config, target, fileKey, destPath, onProgress, redirects + 1, retries));
        return;
      }

      if (res.statusCode < 200 || res.statusCode >= 300) {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const bodyStr = Buffer.concat(chunks).toString('utf8');
          reject(new Error(`Download failed (HTTP ${res.statusCode}): ${stripHtml(bodyStr)}`));
        });
        return;
      }

      const total = parseInt(res.headers['content-length'] || '0', 10);
      let downloaded = 0;
      fs.mkdirSync(path.dirname(destPath), { recursive: true });
      const fileStream = fs.createWriteStream(destPath);

      res.on('data', (chunk) => {
        downloaded += chunk.length;
        if (onProgress && total > 0) {
          onProgress(downloaded, total);
        }
      });

      res.pipe(fileStream);

      fileStream.on('finish', () => {
        fileStream.close(() => resolve({ path: destPath, size: downloaded }));
      });
      fileStream.on('error', (err) => {
        try { fs.unlinkSync(destPath); } catch (_) {}
        reject(err);
      });
    });

    req.on('timeout', () => req.destroy(new Error('Package download request timed out.')));
    req.on('error', (err) => {
      if ((err.code === 'ECONNRESET' || err.message.includes('socket hang up')) && retries > 0) {
        setTimeout(() => {
          resolve(downloadPackageToFile(config, target, fileKey, destPath, onProgress, redirects, retries - 1));
        }, 1000);
      } else {
        reject(err);
      }
    });
    req.end();
  });
}

function uploadFileStream(config, target, apiPath, filePath, fieldName, filename, extraFields = {}, onProgress = null) {
  return new Promise((resolve, reject) => {
    const stat = fs.statSync(filePath);
    const fileSize = stat.size;
    const boundary = `----InfinityDeploy${crypto.randomBytes(12).toString('hex')}`;

    let headerParts = '';
    for (const [key, value] of Object.entries(extraFields)) {
      if (value !== undefined && value !== null) {
        headerParts += `--${boundary}\r\nContent-Disposition: form-data; name="${escapeHeader(key)}"\r\n\r\n${String(value)}\r\n`;
      }
    }
    headerParts += `--${boundary}\r\nContent-Disposition: form-data; name="${escapeHeader(fieldName)}"; filename="${escapeHeader(filename)}"\r\nContent-Type: application/octet-stream\r\n\r\n`;

    const headerBuf = Buffer.from(headerParts, 'utf8');
    const footerBuf = Buffer.from(`\r\n--${boundary}--\r\n`, 'utf8');
    const totalLength = headerBuf.length + fileSize + footerBuf.length;

    const auth = credentials(config, target);
    const url = new URL(endpoint(config, target, apiPath));
    const transport = url.protocol === 'https:' ? https : http;
    const isLocal = url.hostname.endsWith('.local') || url.hostname === 'localhost' || url.hostname === '127.0.0.1';

    const req = transport.request(url, {
      method: 'POST',
      headers: {
        Connection: 'close',
        'Content-Type': `multipart/form-data; boundary=${boundary}`,
        'Content-Length': String(totalLength),
        Accept: 'application/json',
        Authorization: `Basic ${Buffer.from(`${auth.user}:${auth.password}`).toString('base64')}`,
        'User-Agent': `InfinityDeployLocal/${ADDON_VERSION}`,
      },
      timeout: config.requestTimeoutMs || 600000,
      rejectUnauthorized: !isLocal,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        try {
          const body = Buffer.concat(chunks);
          resolve(parseJsonResponse({ status: res.statusCode, headers: res.headers, body }));
        } catch (err) {
          reject(err);
        }
      });
    });

    req.on('timeout', () => req.destroy(new Error('Package upload timed out.')));
    req.on('error', reject);

    req.write(headerBuf);

    const readStream = fs.createReadStream(filePath);
    let uploadedBytes = 0;
    readStream.on('data', (chunk) => {
      uploadedBytes += chunk.length;
      if (onProgress && fileSize > 0) {
        onProgress(uploadedBytes, fileSize);
      }
    });
    readStream.on('end', () => {
      req.write(footerBuf);
      req.end();
    });
    readStream.on('error', (err) => {
      req.destroy(err);
      reject(err);
    });
    // Stream the file body between the multipart header and footer. Using
    // pipe() also handles backpressure for large database and site packages.
    readStream.pipe(req, { end: false });
  });
}

function parseJsonResponse(response) {
  let data;
  const rawBody = response.body ? response.body.toString('utf8') : '';
  try {
    data = JSON.parse(rawBody);
  } catch (_) {
    let cleanMsg = stripHtml(rawBody);
    if (cleanMsg.length > 300) cleanMsg = cleanMsg.substring(0, 300) + '...';
    if (!cleanMsg) cleanMsg = `HTTP ${response.status} with empty response`;
    throw new Error(`WordPress returned HTTP ${response.status}: ${cleanMsg}`);
  }
  if (response.status < 200 || response.status >= 300) {
    const errorMsg = data.message || data.data?.error?.message || `WordPress returned HTTP ${response.status}.`;
    const error = new Error(stripHtml(errorMsg));
    error.code = data.code || 'wordpress_error';
    error.details = data.data || null;
    throw error;
  }
  return data;
}

function packageTheme(site, themeSlug) {
	const sitePath = expandUserPath(site.path);
	const themesRoot = path.join(sitePath, 'app', 'public', 'wp-content', 'themes');

	if (!fs.existsSync(themesRoot)) {
		throw new Error(`Themes directory not found at: ${themesRoot}. Please check that the site exists in Local.`);
	}

	// 1. Check exact match
	let source = path.join(themesRoot, themeSlug);

	// 2. If not found directly, auto-discover by folder name or style.css headers
	if (!fs.existsSync(path.join(source, 'style.css'))) {
		try {
			const entries = fs.readdirSync(themesRoot, { withFileTypes: true });
			const available = [];
			let matched = null;

			for (const entry of entries) {
				if (!entry.isDirectory()) continue;
				available.push(entry.name);

				// Match case-insensitively or ignoring spaces/hyphens
				const normEntry = entry.name.toLowerCase().replace(/[^a-z0-9]/g, '');
				const normSlug  = themeSlug.toLowerCase().replace(/[^a-z0-9]/g, '');
				if (normEntry === normSlug) {
					matched = entry.name;
					break;
				}

				// Check style.css inside the folder
				const candidateStyle = path.join(themesRoot, entry.name, 'style.css');
				if (fs.existsSync(candidateStyle)) {
					const content = fs.readFileSync(candidateStyle, 'utf8');
					if (new RegExp(`Text Domain:\\s*${themeSlug}\\b`, 'i').test(content) ||
					    new RegExp(`Theme Name:\\s*Rahul Graphics\\b`, 'i').test(content)) {
						matched = entry.name;
						break;
					}
				}
			}

			if (matched) {
				source = path.join(themesRoot, matched);
			} else {
				throw new Error(
					`Theme "${themeSlug}" not found at ${source}. Available theme folders: [${available.join(', ')}]. ` +
					`Please ensure your theme folder exists inside ${themesRoot}.`
				);
			}
		} catch (err) {
			if (err.message.includes('Available theme folders')) throw err;
			throw new Error(`Theme not found at ${source}: ${err.message}`);
		}
	}

	if (!fs.existsSync(path.join(source, 'style.css'))) {
		throw new Error(`Theme not found at ${source} (missing style.css).`);
	}

	const output = path.join(os.tmpdir(), `infinity-${themeSlug}-${Date.now()}.zip`);
	let result;

	// Always stage into a clean directory named themeSlug so the ZIP root is always themeSlug/
	const tempRoot = path.join(os.tmpdir(), `infinity-stage-${Date.now()}`);
	const stagedTheme = path.join(tempRoot, themeSlug);
	fs.mkdirSync(tempRoot, { recursive: true });
	fs.cpSync(source, stagedTheme, { recursive: true });

	try {
		if (process.platform === 'win32') {
			const destination = output.replace(/'/g, "''");
			result = spawnSync('powershell.exe', ['-NoProfile', '-Command', `Compress-Archive -Path '${stagedTheme.replace(/'/g, "''")}' -DestinationPath '${destination}' -Force`], { encoding: 'utf8' });
		} else {
			result = spawnSync('zip', ['-q', '-r', output, themeSlug, '-x', '*.DS_Store', '*.git*', 'node_modules/*'], { cwd: tempRoot, encoding: 'utf8' });
		}
	} finally {
		try { fs.rmSync(tempRoot, { recursive: true, force: true }); } catch (_) {}
	}

	if (result.error || result.status !== 0 || !fs.existsSync(output)) {
		throw new Error(`Theme packaging failed: ${result.stderr || result.error || 'unknown error'}`);
	}
	return output;
}

/**
 * Local can expose a compact site path beginning with "~/". Node's fs and
 * path modules do not expand that shell shorthand, so resolve it before any
 * filesystem checks or ZIP operations.
 */
function expandUserPath(value) {
	const input = String(value || '').trim();
	if (input === '~') return os.homedir();
	if (input.startsWith('~/') || input.startsWith('~\\')) {
		return path.join(os.homedir(), input.slice(2));
	}
	return path.resolve(input);
}

function summarize(changes, media) {
  const result = { new: 0, changed: 0, unchanged: 0, mediaNew: 0, mediaChanged: 0, mediaUnchanged: 0 };
  changes.forEach((item) => { result[item.state] += 1; });
  media.forEach((item) => { result[`media${item.state[0].toUpperCase()}${item.state.slice(1)}`] += 1; });
  return result;
}

function escapeHeader(value) { return String(value).replace(/[\r\n"]/g, ''); }

function notifyProgress(event, message, percent) {
  try {
    if (event && event.sender && typeof event.sender.send === 'function' && !event.sender.isDestroyed()) {
      event.sender.send('infinity-deploy:progress', { message, percent });
    }
  } catch (_) { /* ignore */ }
}

async function pMap(items, concurrency, mapper) {
  if (!items || items.length === 0) return [];
  const results = new Array(items.length);
  let index = 0;
  const limit = Math.max(1, Math.min(concurrency, items.length));
  const workers = new Array(limit).fill(null).map(async () => {
    while (index < items.length) {
      const current = index++;
      results[current] = await mapper(items[current], current);
    }
  });
  await Promise.all(workers);
  return results;
}
