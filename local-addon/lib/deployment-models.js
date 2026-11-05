'use strict';

/** Pure presentation models; no credentials, network calls, or WordPress side effects. */
function compareContent(source, target) {
  const remoteItems = target.items || {};
  const remoteMedia = target.media || {};
  const changes = (source.items || []).map(item => {
    const other = remoteItems[item.uuid];
    const state = !other ? 'new' : other.hash === item.hash ? 'unchanged' : 'changed';
    const fields = [];
    if (other) {
      if (other.title !== undefined && other.title !== item.title) fields.push('title');
      if (other.slug !== undefined && other.slug !== item.slug) fields.push('slug');
    }
    if (state === 'changed' && fields.length === 0) fields.push('content, metadata or relationships');
    return { uuid:item.uuid, type:item.type, title:item.title, slug:item.slug || '', state, fields,
      localHash:item.hash || '', remoteHash:other ? other.hash : '', remoteModified:other ? other.modified_gmt || '' : '' };
  });
  const media = (source.media || []).map(item => {
    const other = remoteMedia[item.uuid];
    return { uuid:item.uuid, filename:item.filename, size:item.size || null, state: !other ? 'new' : other.hash === item.hash ? 'unchanged' : 'changed',
      localHash:item.hash || '', remoteHash:other ? other.hash : '' };
  });
  const summary = {new:0,changed:0,unchanged:0,mediaNew:0,mediaChanged:0,mediaUnchanged:0};
  for (const item of changes) summary[item.state]++;
  for (const item of media) summary['media'+item.state[0].toUpperCase()+item.state.slice(1)]++;
  return {ok:true,changes,media,summary,sourceGeneratedGmt:source.generated_gmt,targetGeneratedGmt:target.generated_gmt};
}

/** Incomplete manifests are explicitly marked as partial; unverifiable hashes are never called unchanged. */
function compareFiles(local, live) {
  const remote = new Map((live.files || []).map(f => [f.path, f]));
  const files = (local.files || []).map(item => {
    const other = remote.get(item.path);
    let state = 'new';
    if (other) state = item.size !== other.size ? 'changed'
      : item.sha256 && other.sha256 ? (item.sha256 === other.sha256 ? 'unchanged' : 'changed') : 'unverified';
    remote.delete(item.path);
    return { path:item.path, size:item.size, remoteSize: other ? other.size : null, state };
  });
  // These are informational: overlay imports do not delete destination-only files.
  for (const extra of remote.values()) files.push({path:extra.path,size:0,remoteSize:extra.size,state:'live-only'});
  return {files, partial:Boolean(local.truncated || live.truncated), scope:'Allowed wp-content files only; no deletions are propagated'};
}

function preflight(local, live, requiredVersion, components) {
  const checks = [];
  const add = (key, passed, message, severity='block') => checks.push({key, passed:Boolean(passed), message, severity});
  function versionAtLeast(a,b) {
    if (!/^\d+\.\d+(\.\d+)?$/.test(String(a))) return false;
    const aa=String(a).split('.').map(Number),bb=String(b).split('.').map(Number);
    for(let i=0;i<3;i++){if((aa[i]||0)>(bb[i]||0))return true;if((aa[i]||0)<(bb[i]||0))return false;}
    return true;
  }
  for(const [label, status] of [['Local',local],['Live',live]]) {
    add(label+'-connected', status && status.ok, label+' authenticated and reachable');
    add(label+'-compatible', status && versionAtLeast(status.bridge_version,requiredVersion), label+' Bridge '+requiredVersion+'+');
    add(label+'-enabled', status && status.deployment_enabled, label+' deployments enabled');
    add(label+'-zip', status && status.zip_available, label+' ZIP extension available');
    add(label+'-storage', status && typeof status.free_disk_bytes==='number' && status.free_disk_bytes > 0,
      label+' free space reported', 'warning');
  }
  add('live-https', /^https:\/\//i.test(String(live.home_url || '')) || /^(http:\/\/)(localhost|127\.0\.0\.1|[^/]+\.local)(:\d+)?\//i.test(String(live.home_url || '')),
    'Live endpoint is HTTPS (or recognized local development hostname)');
  add('package-limit', Number(live.max_package_mb)>0, 'Live package limit available');
  return {ok:checks.every(c=>c.passed || c.severity==='warning'),checks,components,checkedAt:new Date().toISOString(),
    note:'Preflight is advisory; it cannot guarantee package size, backup disk space, or production recovery.'};
}
module.exports={compareContent,compareFiles,preflight};
