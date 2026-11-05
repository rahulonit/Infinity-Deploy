'use strict';
const fs=require('fs');
const os=require('os');
const path=require('path');
/** Full site orchestration is isolated from credentials, IPC initialization, and renderer. */
function registerFullSiteHandlers(ipcMain, { normalizeConfig, apiJson, assertFullSiteCompatibility, notifyProgress, downloadPackageToFile, uploadResumable, journal, cancelled, uploadFileStream }) {
  ipcMain.handle('infinity-deploy:clone-full-site', async (_event, site, config, options = {}) => {
    const clean = normalizeConfig(config, site);
    cancelled.delete(String(site.id));
    journal.begin(site.id, 'clone');
    const cancelledNow = () => cancelled.has(String(site.id));
    const report = (event, message, percent) => {
      if (percent < 100 && cancelledNow()) throw new Error('Operation cancelled at a safe checkpoint. Some earlier changes may already be applied.');
      journal.progress(site.id, message, percent);
      notifyProgress(event, message, percent);
    };
    try {
    const { includeDb = true, includeUploads = true, includePlugins = true, preserveUsers = false } = options;

    report(_event, 'Validating Local and Live Bridge versions...', 5);
    const [localStatus, liveStatus] = await Promise.all([
      apiJson(clean, 'local', 'GET', '/status'),
      apiJson(clean, 'live', 'GET', '/status'),
    ]);
    assertFullSiteCompatibility(localStatus, 'Local');
    assertFullSiteCompatibility(liveStatus, 'Live');

    let dbResult = null;
    let siteResult = [];

    // 1. DATABASE FIRST (Fastest, most critical part of clone)
    if (includeDb) {
      report(_event, 'Exporting complete Live database...', 15);
      const dbRes = await apiJson(clean, 'live', 'POST', '/db/export', { exclude_users: preserveUsers });
      if (dbRes && dbRes.file_key) {
        const tempSql = path.join(os.tmpdir(), `infinity-clone-db-${Date.now()}-${dbRes.filename}`);
        try {
          const mbSize = dbRes.size ? Math.max(1, Math.round(dbRes.size / 1024 / 1024)) : 0;
          report(_event, `Downloading database (${mbSize} MB)...`, 30);
          await downloadPackageToFile(clean, 'live', dbRes.file_key, tempSql);

          // Clean up SQL export on live server
          await apiJson(clean, 'live', 'POST', '/package/cleanup', { file_key: dbRes.file_key }).catch(() => {});

          report(_event, 'Importing database into Local & running serialized search-replace...', 45);
          dbResult = await uploadResumable({ config: clean, target: 'local', apiJson, uploadFileStream, filePath: tempSql, importPath: '/db/import', isCancelled: cancelledNow, onCheckpoint: state => journal.progress(site.id, `Database upload ${state.offset || 0}/${state.size || 0}`, 42), extraFields: {
            source_url: clean.liveUrl,
            target_url: clean.localUrl,
			source_path: dbRes.site_path || '',
            source_prefix: dbRes.prefix,
            preserve_users: preserveUsers ? '1' : '0',
          } });
        } finally {
          try { fs.unlinkSync(tempSql); } catch (_) {}
        }
      }
    }

    // 2. FILES SYNC (Themes first, then plugins, then uploads)
    const components = ['themes'];
    if (includePlugins) components.push('plugins');
    if (includeUploads) components.push('uploads');

    for (let i = 0; i < components.length; i++) {
      const comp = components[i];
      const baseProgress = 50 + Math.round((i / components.length) * 40); // 50% to 90%
      report(_event, `Packaging live site files: ${comp}...`, baseProgress);

      try {
        const exportRes = await apiJson(clean, 'live', 'POST', '/site/export', { components: [comp] });
        if (exportRes && exportRes.file_key) {
          const tempZip = path.join(os.tmpdir(), `infinity-clone-${comp}-${Date.now()}.zip`);
          try {
            const mbSize = exportRes.size ? Math.max(1, Math.round(exportRes.size / 1024 / 1024)) : 0;
            report(_event, `Downloading ${comp} (${mbSize} MB)...`, baseProgress + 5);
            await downloadPackageToFile(clean, 'live', exportRes.file_key, tempZip);

            // Clean up package on live server
            await apiJson(clean, 'live', 'POST', '/package/cleanup', { file_key: exportRes.file_key }).catch(() => {});

            report(_event, `Extracting ${comp} into Local environment...`, baseProgress + 10);
            const importRes = await uploadResumable({ config: clean, target: 'local', apiJson, uploadFileStream, filePath: tempZip, importPath: '/site/import', isCancelled: cancelledNow, onCheckpoint: state => journal.progress(site.id, `${comp} upload ${state.offset || 0}/${state.size || 0}`, baseProgress + 10) });
            siteResult.push({ component: comp, result: importRes });
          } finally {
            try { fs.unlinkSync(tempZip); } catch (_) {}
          }
        }
      } catch (compErr) {
        // Log individual component failure without killing entire database migration
        siteResult.push({ component: comp, error: compErr.message });
      }
    }

    report(_event, 'Finalizing Local site permalinks and cache flush...', 95);
    const maintenance = await apiJson(clean, 'local', 'POST', '/maintenance', {});
	const failedComponents = siteResult.filter((item) => item && item.error);
	if (failedComponents.length > 0) {
	  throw new Error(`Database clone completed, but these file components failed: ${failedComponents.map((item) => `${item.component}: ${item.error}`).join('; ')}`);
	}
	if (!maintenance || !maintenance.ok) {
	  throw new Error('Clone data transferred, but the final Local health check failed. Review the Bridge activity log.');
	}
    report(_event, 'Site cloning completed successfully!', 100);

    journal.finish(site.id, 'completed', 'Clone completed');
    return { ok: true, db: dbResult, siteFiles: siteResult, maintenance };
    } catch (error) {
      journal.finish(site.id, cancelledNow() ? 'cancelled' : 'failed', error.message);
      throw error;
    } finally { cancelled.delete(String(site.id)); }
  });

  ipcMain.handle('infinity-deploy:push-full-site', async (_event, site, config, options = {}) => {
    const clean = normalizeConfig(config, site);
    cancelled.delete(String(site.id));
    journal.begin(site.id, 'push');
    const cancelledNow = () => cancelled.has(String(site.id));
    const report = (event, message, percent) => {
      if (percent < 100 && cancelledNow()) throw new Error('Operation cancelled at a safe checkpoint. Some earlier changes may already be applied.');
      journal.progress(site.id, message, percent);
      notifyProgress(event, message, percent);
    };
    try {
    const { includeDb = true, includeUploads = true, includePlugins = true, preserveUsers = true } = options;

    report(_event, 'Validating Local and Live Bridge versions...', 5);
    const [localStatus, liveStatus] = await Promise.all([
      apiJson(clean, 'local', 'GET', '/status'),
      apiJson(clean, 'live', 'GET', '/status'),
    ]);
    assertFullSiteCompatibility(localStatus, 'Local');
    assertFullSiteCompatibility(liveStatus, 'Live');

    // Determine the exact file scope before creating recovery points.
    const components = [];
    if (includeUploads) components.push('uploads');
    if (includePlugins) components.push('plugins');
    components.push('themes');

    // Fail closed: both recovery artifacts must exist before any production mutation.
    report(_event, 'Creating safety snapshot of Live database...', 10);
    const safetySnapshot = await apiJson(clean, 'live', 'POST', '/db/snapshot', { label: 'pre-push-full' });
    if (!safetySnapshot || !safetySnapshot.snapshot || !safetySnapshot.snapshot.key) {
      throw new Error('Live database safety snapshot did not return a recovery key; deployment aborted.');
    }

    report(_event, 'Backing up Live site files before deployment...', 12);
    const liveFilesBackup = await apiJson(clean, 'live', 'POST', '/site/export', { components });
    if (!liveFilesBackup || !liveFilesBackup.file_key || !(liveFilesBackup.size > 0)) {
      throw new Error('Live site-file backup could not be verified; deployment aborted before file changes.');
    }
    // This ZIP stays on Live for recovery. Do not call /package/cleanup on its file_key.
    // It is an overlay backup, not an atomic whole-site rollback.
    let dbResult = null;
    let siteResult = [];

    // Preserve recovery identifiers when any subsequent production step fails.
    try {
    // 2. Files Sync (Uploads, Plugins, Themes)

    for (let i = 0; i < components.length; i++) {
      const comp = components[i];
      const baseProgress = 15 + Math.round((i / components.length) * 45); // 15% to 60%
      report(_event, `Packaging local site files: ${comp}...`, baseProgress);

      const localPack = await apiJson(clean, 'local', 'POST', '/site/export', { components: [comp] });
      if (localPack && localPack.file_key) {
        const tempZip = path.join(os.tmpdir(), `infinity-push-${comp}-${Date.now()}.zip`);
        try {
          report(_event, `Downloading local ${comp} package...`, baseProgress + 5);
          await downloadPackageToFile(clean, 'local', localPack.file_key, tempZip);
          await apiJson(clean, 'local', 'POST', '/package/cleanup', { file_key: localPack.file_key }).catch(() => {});

          const mbSize = localPack.size ? Math.max(1, Math.round(localPack.size / 1024 / 1024)) : 0;
          report(_event, `Streaming ${comp} to Live server (${mbSize} MB)...`, baseProgress + 10);
          const importRes = await uploadResumable({ config: clean, target: 'live', apiJson, uploadFileStream, filePath: tempZip, importPath: '/site/import', isCancelled: cancelledNow, onCheckpoint: state => journal.progress(site.id, `${comp} upload ${state.offset || 0}/${state.size || 0}`, baseProgress + 10) });
          siteResult.push({ component: comp, result: importRes });
        } finally {
          try { fs.unlinkSync(tempZip); } catch (_) {}
        }
      }
    }

    // 3. Database Sync
    if (includeDb) {
      report(_event, 'Exporting Local database...', 65);
      const localDb = await apiJson(clean, 'local', 'POST', '/db/export', { exclude_users: preserveUsers });
      if (localDb && localDb.file_key) {
        const tempSql = path.join(os.tmpdir(), `infinity-push-db-${Date.now()}-${localDb.filename}`);
        try {
          report(_event, 'Downloading local database dump...', 72);
          await downloadPackageToFile(clean, 'local', localDb.file_key, tempSql);
          await apiJson(clean, 'local', 'POST', '/package/cleanup', { file_key: localDb.file_key }).catch(() => {});

          const mbSize = localDb.size ? Math.max(1, Math.round(localDb.size / 1024 / 1024)) : 0;
          report(_event, `Uploading database to Live & running serialized search-replace (${mbSize} MB)...`, 85);
          dbResult = await uploadResumable({ config: clean, target: 'live', apiJson, uploadFileStream, filePath: tempSql, importPath: '/db/import', isCancelled: cancelledNow, onCheckpoint: state => journal.progress(site.id, `Database upload ${state.offset || 0}/${state.size || 0}`, 85), extraFields: {
            source_url: clean.localUrl,
            target_url: clean.liveUrl,
			source_path: localDb.site_path || '',
            source_prefix: localDb.prefix,
            preserve_users: preserveUsers ? '1' : '0',
          } });
        } finally {
          try { fs.unlinkSync(tempSql); } catch (_) {}
        }
      }
    }

    report(_event, 'Running live health checks and cache flush...', 95);
    const maintenance = await apiJson(clean, 'live', 'POST', '/maintenance', {});
	if (!maintenance || !maintenance.ok) {
	  throw new Error('Deployment data transferred, but the final Live health check failed. Review the Bridge activity log and restore the safety snapshot if needed.');
	}
    report(_event, 'Full site deployment completed successfully!', 100);

    journal.finish(site.id, 'completed', 'Live deployment completed');
    return { ok: true, safetySnapshot: safetySnapshot.snapshot, liveFilesBackup: liveFilesBackup.file_key, db: dbResult, siteFiles: siteResult, maintenance };
    } catch (error) {
      throw new Error(`${error.message} Recovery artifacts on Live: database snapshot ${safetySnapshot.snapshot.key}; site-file ZIP ${liveFilesBackup.file_key}. File recovery is an overlay and does not remove new files.`);
    }
    } catch (error) {
      journal.finish(site.id, cancelledNow() ? 'cancelled' : 'failed', error.message);
      throw error;
    } finally { cancelled.delete(String(site.id)); }
  });

}
module.exports={registerFullSiteHandlers};
