'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../local-addon/lib/main.js'), 'utf8');
const start = source.indexOf('class ConfigStore {');
const end = source.indexOf('\nfunction normalizeConfig(', start);
assert.ok(start >= 0 && end > start, 'ConfigStore must remain testable');
const ConfigStore = vm.runInNewContext(source.slice(start, end) + '\nConfigStore;', { fs, path, Buffer, Map });

test('never writes raw or base64 passwords when encryption is unavailable', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-config-test-'));
  try {
    const file = path.join(dir, 'connections.json');
    const store = new ConfigStore(file, { isEncryptionAvailable: () => false });
    store.set('site-1', { localUser: 'admin', localPassword: 'local-SECRET', livePassword: 'live-SECRET' });
    const disk = fs.readFileSync(file, 'utf8');
    assert.ok(!disk.includes('local-SECRET'));
    assert.ok(!disk.includes('live-SECRET'));
    assert.ok(!disk.includes(Buffer.from('local-SECRET').toString('base64')));
    assert.ok(!disk.includes(Buffer.from('live-SECRET').toString('base64')));
    assert.equal(store.get('site-1').localPassword, 'local-SECRET');
    assert.equal(store.get('site-1').livePassword, 'live-SECRET');
    const nextSession = new ConfigStore(file, { isEncryptionAvailable: () => false });
    assert.equal(nextSession.get('site-1').localPassword, '');
    assert.equal(nextSession.get('site-1').livePassword, '');
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('encrypted secrets persist and decrypt with safeStorage', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-config-test-'));
  const safeStorage = {
    isEncryptionAvailable: () => true,
    encryptString: value => Buffer.from('encrypted:' + value),
    decryptString: bytes => bytes.toString().slice('encrypted:'.length),
  };
  try {
    const file = path.join(dir, 'connections.json');
    const store = new ConfigStore(file, safeStorage);
    store.set('site-2', { localPassword: 'LOCAL', livePassword: 'LIVE' });
    const disk = fs.readFileSync(file, 'utf8');
    assert.ok(!disk.includes('"localPassword": "LOCAL"'));
    assert.ok(!disk.includes('"livePassword": "LIVE"'));
    assert.equal(new ConfigStore(file, safeStorage).get('site-2').localPassword, 'LOCAL');
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('legacy plaintext/base64 secrets are not decoded from saved config', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'infinity-config-test-'));
  try {
    const file = path.join(dir, 'connections.json');
    fs.writeFileSync(file, JSON.stringify({ legacy: { localPassword: 'plain-secret', livePassword: 'base64:c2VjcmV0' } }));
    const saved = new ConfigStore(file, { isEncryptionAvailable: () => false }).get('legacy');
    assert.equal(saved.localPassword, '');
    assert.equal(saved.livePassword, '');
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('full-site push gates file mutations on verified recovery points', () => {
  const start = source.indexOf("ipcMain.handle('infinity-deploy:push-full-site'");
  const end = source.indexOf("ipcMain.handle('infinity-deploy:get-db-snapshots'", start);
  const flow = source.slice(start, end);
  const databaseBackup = flow.indexOf("'/db/snapshot'");
  const fileBackup = flow.indexOf("'/site/export', { components }");
  const fileMutation = flow.indexOf("'/site/import'");
  assert.ok(databaseBackup >= 0 && fileBackup > databaseBackup && fileMutation > fileBackup);
  assert.ok(flow.includes('!liveFilesBackup.file_key'));
  assert.ok(flow.includes('liveFilesBackup: liveFilesBackup.file_key'));
  assert.ok(flow.includes('Recovery artifacts on Live: database snapshot'));
  assert.ok(flow.includes('site-file ZIP ${liveFilesBackup.file_key}'));
});
