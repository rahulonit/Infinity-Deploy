'use strict';

function infinityDeployRenderer(context) {
  const React = context.React;
  const h = React.createElement;
  let ipc;
  try { ipc = require('electron').ipcRenderer; }
  catch (_) { ipc = context.electron.ipcRenderer; }

  injectStyles();

  function InfinityDeployPanel({ site }) {
    const [config, setConfig] = React.useState(null);
    const [busy, setBusy] = React.useState('');
    const [progress, setProgress] = React.useState(null);
    const [message, setMessage] = React.useState(null);
    const [preview, setPreview] = React.useState(null);
    const [selectedUuids, setSelectedUuids] = React.useState([]);
    const [backups, setBackups] = React.useState([]);

    const [fullSiteOptions, setFullSiteOptions] = React.useState({
      includeDb: true,
      includeUploads: true,
      includePlugins: false,
      preserveUsers: true,
    });
    const [dbSnapshots, setDbSnapshots] = React.useState([]);

    React.useEffect(() => {
      let active = true;
      ipc.invoke('infinity-deploy:get-config', site).then((saved) => {
        if (active) setConfig(saved);
      }).catch((error) => setMessage({ type: 'error', text: error.message }));
      return () => { active = false; };
    }, [site.id]);

    React.useEffect(() => {
      const onProgress = (_event, data) => setProgress(data);
      if (ipc && ipc.on) {
        ipc.on('infinity-deploy:progress', onProgress);
      }
      return () => {
        if (ipc && ipc.removeListener) {
          ipc.removeListener('infinity-deploy:progress', onProgress);
        }
      };
    }, []);

    const update = (key, value) => setConfig((current) => Object.assign({}, current, { [key]: value }));
    const updateFullSiteOpt = (key, value) => setFullSiteOptions((current) => Object.assign({}, current, { [key]: value }));

    const run = async (action, callback) => {
      setBusy(action); setMessage(null); setProgress({ message: 'Starting...', percent: 5 });
      try {
        await ipc.invoke('infinity-deploy:save-config', site, config);
        const result = await callback();
        if (result && result.live && result.live.backups) setBackups(result.live.backups);
        if (result && result.live && result.live.db_snapshots) setDbSnapshots(result.live.db_snapshots);
        setMessage({ type: 'success', text: successMessage(action, result) });
        return result;
      } catch (error) {
        setMessage({ type: 'error', text: `${error.message}${error.code ? ` (${error.code})` : ''}` });
        return null;
      } finally {
        setBusy(''); setProgress(null);
      }
    };

    if (!config) return h('div', { className: 'infinity-deploy-panel' }, 'Loading Infinity Deploy…');

    const test = () => run('test', () => ipc.invoke('infinity-deploy:test', site, config));
    const compare = async () => {
      const result = await run('preview', () => ipc.invoke('infinity-deploy:preview', site, config));
      if (result) {
        setPreview(result);
        const changedList = (result.changes || []).filter((item) => item.state !== 'unchanged').map((item) => item.uuid);
        setSelectedUuids(changedList);
      }
    };
    const pushTheme = () => {
      if (!window.confirm(`Deploy the local “${config.themeSlug}” theme to ${config.liveUrl}? Infinity Deploy will create a backup and run health checks.`)) return;
      run('theme', () => ipc.invoke('infinity-deploy:push-theme', site, config)).then((result) => {
        if (result && result.deployment && result.deployment.backup_key) setBackups((items) => [{ key: result.deployment.backup_key }].concat(items));
      });
    };
    const pushContent = () => {
      if (!preview) { setMessage({ type: 'error', text: 'Run Compare first so conflicts can be checked.' }); return; }
      if (selectedUuids.length === 0) { setMessage({ type: 'error', text: 'Please select at least one content item to push.' }); return; }
      if (!window.confirm(`Push ${selectedUuids.length} selected content item(s) and referenced media to ${config.liveUrl}?`)) return;
      run('content', () => ipc.invoke('infinity-deploy:push-content', site, config, selectedUuids)).then((result) => { if (result) setPreview(null); });
    };
    const pullContent = () => {
      if (!window.confirm(`Pull pages, portfolio entries, and media from ${config.liveUrl} into this local site? Local content will be synchronized with the live website.`)) return;
      run('pull', () => ipc.invoke('infinity-deploy:pull-content', site, config)).then((result) => {
        if (result && result.ok) {
          setMessage({
            type: 'success',
            text: `Successfully pulled ${result.pulledCount || 0} content item(s) and ${result.mediaCount || 0} media file(s) from live website.`
          });
          setPreview(null);
        }
      });
    };
    const rollback = (key) => {
      if (!window.confirm(`Restore backup ${key} on ${config.liveUrl}? The current live theme files will be replaced.`)) return;
      run('rollback', () => ipc.invoke('infinity-deploy:rollback', site, config, key));
    };

    const cloneFullSite = () => {
      if (!window.confirm(`⚠️ CLONE ENTIRE SITE FROM LIVE?\n\nThis will download the LIVE database and files, import them into this Local site, and rewrite domain URLs to ${config.localUrl}.\n\nExisting local database and selected content will be replaced.\n\nProceed?`)) return;
      run('clone-full', () => ipc.invoke('infinity-deploy:clone-full-site', site, config, fullSiteOptions));
    };

    const pushFullSite = () => {
      if (!window.confirm(`🚨 DEPLOY ENTIRE SITE TO LIVE (${config.liveUrl})?\n\nThis will upload your Local database and files to PRODUCTION.\nAn automatic pre-deployment safety snapshot will be created on the live server before import.\n\nProceed with production deployment?`)) return;
      run('push-full', () => ipc.invoke('infinity-deploy:push-full-site', site, config, fullSiteOptions)).then((res) => {
        if (res && res.safetySnapshot) {
          setDbSnapshots((items) => [res.safetySnapshot, ...items]);
        }
      });
    };

    const restoreDbSnapshot = (key) => {
      if (!window.confirm(`Restore Live database snapshot "${key}" on ${config.liveUrl}? The live database will be reverted.`)) return;
      run('restore-db', () => ipc.invoke('infinity-deploy:restore-db-snapshot', site, config, key));
    };

    const toggleItem = (uuid) => {
      setSelectedUuids((current) => current.includes(uuid) ? current.filter((id) => id !== uuid) : [...current, uuid]);
    };
    const selectAll = () => {
      if (!preview) return;
      const allChanged = preview.changes.filter((item) => item.state !== 'unchanged').map((item) => item.uuid);
      setSelectedUuids(allChanged);
    };
    const deselectAll = () => setSelectedUuids([]);

    return h('section', { className: 'infinity-deploy-panel' },
      h('header', { className: 'infinity-deploy-header' },
        h('span', { className: 'infinity-deploy-logo', 'aria-hidden': 'true' }, '∞'),
        h('div', null, h('h3', null, 'Infinity Deploy'), h('p', null, 'Selective & full site deployments with database search-replace.'))
      ),
      busy && progress && h('div', { className: 'infinity-deploy-progress' },
        h('div', { className: 'infinity-deploy-progress-bar', style: { width: `${progress.percent || 15}%` } }),
        h('div', { className: 'infinity-deploy-progress-label' },
          h('strong', null, 'Progress: '),
          progress.message || 'Processing...'
        )
      ),
      message && h('div', { className: `infinity-deploy-message is-${message.type}`, role: message.type === 'error' ? 'alert' : 'status' }, message.text),
      h('div', { className: 'infinity-deploy-columns' },
        h('div', { className: 'infinity-deploy-fields' },
          h('h4', null, 'Local connection'),
          field('Local site URL', 'url', config.localUrl, (value) => update('localUrl', value), 'http://rahulgraphics.local'),
          field('Local WordPress username', 'text', config.localUser, (value) => update('localUser', value), 'e.g. admin (your WP username)'),
          field('Local Application Password', 'password', config.localPassword, (value) => update('localPassword', value), 'xxxx xxxx xxxx xxxx'),
          h('h4', null, 'Live connection'),
          field('Live site URL', 'url', config.liveUrl, (value) => update('liveUrl', value), 'https://rahul.graphics'),
          field('Live WordPress username', 'text', config.liveUser, (value) => update('liveUser', value), 'e.g. rahulonit@gmail.com (your WP admin email)'),
          field('Live Application Password', 'password', config.livePassword, (value) => update('livePassword', value), 'xxxx xxxx xxxx xxxx'),
          field('Theme slug', 'text', config.themeSlug, (value) => update('themeSlug', value), 'rahul-graphics')
        ),
        h('div', { className: 'infinity-deploy-actions' },
          h('h4', null, 'Selective deployment'),
          button('Test both connections', 'test', test, busy),
          button('Compare portfolio content', 'preview', compare, busy),
          button('Push theme with backup', 'theme', pushTheme, busy, 'primary'),
          button(selectedUuids.length > 0 ? `Push changed content (${selectedUuids.length} selected)` : 'Push changed content & media', 'content', pushContent, busy, 'primary', !preview || selectedUuids.length === 0),
          button('⬇ Pull portfolio data from Live', 'pull', pullContent, busy, 'pull'),

          h('div', { className: 'infinity-deploy-full-section' },
            h('h4', null, 'Full Site Migration (Code + Database)'),
            h('div', { className: 'infinity-deploy-full-options' },
              h('label', { className: 'infinity-deploy-subcheck' },
                h('input', { type: 'checkbox', checked: fullSiteOptions.includeDb, onChange: (e) => updateFullSiteOpt('includeDb', e.target.checked) }),
                ' Include Database (with serialized search-replace)'
              ),
              h('label', { className: 'infinity-deploy-subcheck' },
                h('input', { type: 'checkbox', checked: fullSiteOptions.includeUploads, onChange: (e) => updateFullSiteOpt('includeUploads', e.target.checked) }),
                ' Include Uploads (Media)'
              ),
              h('label', { className: 'infinity-deploy-subcheck' },
                h('input', { type: 'checkbox', checked: fullSiteOptions.includePlugins, onChange: (e) => updateFullSiteOpt('includePlugins', e.target.checked) }),
                ' Include Plugins (themes are always included)'
              ),
              h('label', { className: 'infinity-deploy-subcheck' },
                h('input', { type: 'checkbox', checked: fullSiteOptions.preserveUsers, onChange: (e) => updateFullSiteOpt('preserveUsers', e.target.checked) }),
                ' Preserve Live Users/Customers (Recommended)'
              )
            ),
            button('⬇ Clone Complete Site (Live → Local)', 'clone-full', cloneFullSite, busy, 'clone'),
            button('⬆ Push Complete Site (Local → Live)', 'push-full', pushFullSite, busy, 'danger')
          ),

          h('p', { className: 'infinity-deploy-help' }, 'Dedicated Application Passwords ensure individual revocability. Full site deployment creates an automated safety snapshot on the live server before import.'),
          preview && previewSummary(preview, selectedUuids, toggleItem, selectAll, deselectAll),
          backups.length > 0 && h('div', { className: 'infinity-deploy-backups' },
            h('h4', null, 'Theme rollbacks'),
            ...backups.slice(0, 3).map((item) => h('div', { className: 'infinity-deploy-backup', key: item.key },
              h('code', null, item.key),
              h('button', { type: 'button', disabled: Boolean(busy), onClick: () => rollback(item.key) }, 'Restore')
            ))
          ),
          dbSnapshots.length > 0 && h('div', { className: 'infinity-deploy-backups' },
            h('h4', null, 'Live database safety snapshots'),
            ...dbSnapshots.slice(0, 3).map((item) => h('div', { className: 'infinity-deploy-backup', key: item.key },
              h('code', null, item.key),
              h('button', { type: 'button', disabled: Boolean(busy), onClick: () => restoreDbSnapshot(item.key) }, 'Revert DB')
            ))
          )
        )
      )
    );
  }

  function field(label, type, value, onChange, placeholder) {
    return h('label', { className: 'infinity-deploy-field' },
      h('span', null, label),
      h('input', { type, value: value || '', placeholder: placeholder || '', autoComplete: type === 'password' ? 'new-password' : 'off', onChange: (event) => onChange(event.target.value) })
    );
  }

  function button(label, action, onClick, busy, kind, disabled) {
    const kindClass = kind ? `is-${kind}` : '';
    return h('button', { type: 'button', className: `infinity-deploy-button ${kindClass}`.trim(), disabled: Boolean(busy) || disabled, onClick }, busy === action ? 'Working…' : label);
  }

  function previewSummary(data, selectedUuids, onToggle, onSelectAll, onDeselectAll) {
    const summary = data.summary;
    const diffItems = data.changes.filter((item) => item.state !== 'unchanged');
    return h('div', { className: 'infinity-deploy-preview' },
      h('h4', null, 'Latest comparison'),
      h('div', { className: 'infinity-deploy-stats' },
        stat(summary.new, 'New content'), stat(summary.changed, 'Changed content'), stat(summary.unchanged, 'Unchanged'),
        stat(summary.mediaNew, 'New media'), stat(summary.mediaChanged, 'Changed media')
      ),
      diffItems.length > 0 && h('div', { className: 'infinity-deploy-controls' },
        h('span', { className: 'infinity-deploy-selection-count' }, `${selectedUuids.length} of ${diffItems.length} selected`),
        h('button', { type: 'button', className: 'infinity-deploy-text-btn', onClick: onSelectAll }, 'Select all'),
        h('span', null, '|'),
        h('button', { type: 'button', className: 'infinity-deploy-text-btn', onClick: onDeselectAll }, 'Deselect all')
      ),
      h('ul', { className: 'infinity-deploy-list' }, ...diffItems.slice(0, 30).map((item) => {
        const isSelected = selectedUuids.includes(item.uuid);
        return h('li', { key: item.uuid, className: 'infinity-deploy-item' },
          h('label', { className: 'infinity-deploy-item-label' },
            h('input', { type: 'checkbox', checked: isSelected, onChange: () => onToggle(item.uuid) }),
            h('span', { className: `state-${item.state}` }, item.state),
            h('span', { className: 'infinity-deploy-item-title' }, ` ${item.title} `),
            h('small', null, `(${item.type})`)
          )
        );
      }))
    );
  }

  function stat(value, label) { return h('span', null, h('strong', null, String(value)), label); }

  function successMessage(action, result) {
    if (action === 'test') return `Connected. Local WP ${result.local.wordpress_version} / Bridge ${result.local.bridge_version}; Live WP ${result.live.wordpress_version} / Bridge ${result.live.bridge_version}; live theme ${result.live.active_theme.version}.`;
    if (action === 'preview') return `Comparison complete: ${result.summary.new} new and ${result.summary.changed} changed content items.`;
    if (action === 'theme') return `Theme ${result.deployment.theme.version} deployed and health checks completed.`;
    if (action === 'content') return `${result.content.length} content items and ${result.media.length} media items processed.`;
    if (action === 'pull') return `Successfully pulled ${result.pulledCount || 0} content item(s) and ${result.mediaCount || 0} media file(s) from live website.`;
    if (action === 'rollback') return `Backup ${result.backup_key} restored.`;
    if (action === 'clone-full') return 'Complete site cloned to Local! Database imported and URLs remapped successfully.';
    if (action === 'push-full') return `Complete site deployed to Live! Safety snapshot created: ${result.safetySnapshot ? result.safetySnapshot.key : 'saved'}. Health checks passed.`;
    if (action === 'restore-db') return `Live database reverted to snapshot ${result.result ? result.result.restored_key : 'selected'}.`;
    return 'Completed.';
  }

  context.hooks.addFilter('siteInfoToolsItem', (menu) => [
    ...menu,
    {
      menuItem: 'Infinity Deploy',
      path: '/infinity-deploy',
      render: (props) => h(InfinityDeployPanel, props),
    },
  ]);
}

module.exports = infinityDeployRenderer;
module.exports.default = infinityDeployRenderer;

function injectStyles() {
  if (document.getElementById('infinity-deploy-styles')) return;
  const style = document.createElement('style');
  style.id = 'infinity-deploy-styles';
  style.textContent = `
    .infinity-deploy-panel{margin:20px 0;padding:24px;border:1px solid #d8d9e6;border-radius:14px;background:#fff;color:#202231;box-shadow:0 7px 24px rgba(25,30,60,.07)}
    .infinity-deploy-header{display:flex;align-items:center;gap:14px;margin-bottom:20px}.infinity-deploy-header h3,.infinity-deploy-header p{margin:0}.infinity-deploy-header p{margin-top:3px;color:#686b7b}
    .infinity-deploy-logo{display:grid;width:46px;height:46px;place-items:center;border-radius:13px;background:linear-gradient(135deg,#6d4aff,#09a7ff);color:#fff;font-size:30px;font-weight:800}
    .infinity-deploy-columns{display:grid;grid-template-columns:minmax(330px,1.2fr) minmax(280px,.8fr);gap:28px}.infinity-deploy-fields h4,.infinity-deploy-actions h4{margin:18px 0 10px}.infinity-deploy-fields h4:first-child,.infinity-deploy-actions h4:first-child{margin-top:0}
    .infinity-deploy-field{display:block;margin:10px 0}.infinity-deploy-field span{display:block;margin-bottom:5px;font-size:12px;font-weight:700}.infinity-deploy-field input{box-sizing:border-box;width:100%;padding:9px 11px;border:1px solid #c9cad6;border-radius:7px;background:#fff;color:#202231}
    .infinity-deploy-button{display:block;width:100%;margin:0 0 9px;padding:10px 13px;border:1px solid #c9cad6;border-radius:8px;background:#f7f7fa;color:#252637;font-weight:700;text-align:left;cursor:pointer;transition:all .15s ease}.infinity-deploy-button:hover:not(:disabled){border-color:#6d4aff}.infinity-deploy-button.is-primary{border-color:#6549ef;background:#6d4aff;color:#fff}.infinity-deploy-button.is-pull{border-color:#0284c7;background:linear-gradient(135deg,#0284c7,#0ea5e9);color:#fff}.infinity-deploy-button.is-pull:hover:not(:disabled){border-color:#0369a1;background:#0369a1}.infinity-deploy-button.is-clone{border-color:#059669;background:linear-gradient(135deg,#059669,#10b981);color:#fff}.infinity-deploy-button.is-clone:hover:not(:disabled){border-color:#047857;background:#047857}.infinity-deploy-button.is-danger{border-color:#dc2626;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff}.infinity-deploy-button.is-danger:hover:not(:disabled){border-color:#b91c1c;background:#b91c1c}.infinity-deploy-button:disabled{cursor:not-allowed;opacity:.55}
    .infinity-deploy-full-section{margin-top:20px;padding:14px;border:1px solid #e2e4f0;border-radius:10px;background:#f9f9fd}
    .infinity-deploy-full-options{margin-bottom:12px;display:flex;flex-direction:column;gap:6px}
    .infinity-deploy-subcheck{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:600;color:#3b3e55;cursor:pointer}
    .infinity-deploy-message{margin:0 0 16px;padding:10px 12px;border-radius:8px}.infinity-deploy-message.is-success{background:#e8f8ee;color:#17623a}.infinity-deploy-message.is-error{background:#fff0f0;color:#9c2727}.infinity-deploy-help{color:#686b7b;font-size:12px;line-height:1.55}
    .infinity-deploy-progress{margin:0 0 18px;padding:12px;border-radius:9px;background:#f0f2fb;border:1px solid #d5daf5;position:relative;overflow:hidden}
    .infinity-deploy-progress-bar{position:absolute;top:0;left:0;bottom:0;background:linear-gradient(90deg,#6d4aff,#09a7ff);opacity:0.25;transition:width 0.25s ease}
    .infinity-deploy-progress-label{position:relative;z-index:1;font-size:12px;color:#2c3258}
    .infinity-deploy-preview{margin-top:18px;padding-top:15px;border-top:1px solid #ececf2}.infinity-deploy-stats{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}.infinity-deploy-stats span{display:flex;gap:5px;padding:6px 9px;border-radius:99px;background:#f0f1f6;font-size:11px}
    .infinity-deploy-controls{display:flex;align-items:center;gap:8px;font-size:11px;color:#686b7b;margin-bottom:8px}
    .infinity-deploy-selection-count{font-weight:700;color:#202231}
    .infinity-deploy-text-btn{border:none;background:none;color:#6d4aff;font-size:11px;font-weight:700;cursor:pointer;padding:0;text-decoration:underline}
    .infinity-deploy-list{max-height:200px;overflow:auto;padding:0;margin:0;list-style:none}
    .infinity-deploy-item{margin:4px 0;padding:4px 6px;border-radius:6px;transition:background .1s ease}
    .infinity-deploy-item:hover{background:#f8f8fc}
    .infinity-deploy-item-label{display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;margin:0}
    .infinity-deploy-item-title{font-weight:600;color:#202231}
    .infinity-deploy-item small{color:#76788a}
    .state-new{color:#18794e;font-weight:700}.state-changed{color:#a15c00;font-weight:700}
    .infinity-deploy-backups{margin-top:18px}.infinity-deploy-backup{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #eee}.infinity-deploy-backup code{overflow:hidden;text-overflow:ellipsis}.infinity-deploy-backup button{border:0;background:none;color:#6044e4;cursor:pointer;font-weight:700}
    @media(max-width:850px){.infinity-deploy-columns{grid-template-columns:1fr}}
  `;
  document.head.appendChild(style);
}
