'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const {compareContent, compareFiles, preflight}=require('../local-addon/lib/deployment-models');

test('content diff exposes title and slug changes with stable hashes',()=>{
 const result=compareContent({items:[
   {uuid:'a',type:'page',title:'New',slug:'new',hash:'111'},
   {uuid:'b',type:'page',title:'Same',slug:'same',hash:'222'}
 ],media:[{uuid:'m',filename:'pic.jpg',hash:'aaa'}]},{
   items:{a:{title:'Old',slug:'old',hash:'000',modified_gmt:'2026-01-01'},b:{title:'Same',slug:'same',hash:'222'}},
   media:{m:{hash:'aaa'}}
 });
 assert.equal(result.changes[0].state,'changed');
 assert.deepEqual(result.changes[0].fields,['title','slug']);
 assert.equal(result.changes[1].state,'unchanged');
 assert.equal(result.summary.mediaUnchanged,1);
});

test('file comparison never claims missing hash is unchanged',()=>{
 const result=compareFiles({files:[
  {path:'wp-content/themes/app/style.css',size:200,sha256:'abc'},
  {path:'wp-content/uploads/large.bin',size:100000,sha256:null}
 ],truncated:true},{files:[
  {path:'wp-content/themes/app/style.css',size:200,sha256:'def'},
  {path:'wp-content/uploads/large.bin',size:100000,sha256:null},
  {path:'wp-content/plugins/other.php',size:1,sha256:'x'}
 ]});
 assert.equal(result.files.find(x=>x.path.endsWith('style.css')).state,'changed');
 assert.equal(result.files.find(x=>x.path.endsWith('large.bin')).state,'unverified');
 assert.equal(result.files.find(x=>x.path.endsWith('other.php')).state,'live-only');
 assert.equal(result.partial,true);
});

test('preflight blocks disabled Bridge and preserves advisory disk-space warnings',()=>{
 const status={ok:true,bridge_version:'1.0.4',deployment_enabled:true,zip_available:true,max_package_mb:200,free_disk_bytes:1000000,home_url:'https://example.com/'};
 assert.equal(preflight(status,status,'1.0.4',['themes']).ok,true);
 assert.equal(preflight(status,{...status,deployment_enabled:false},'1.0.4',['themes']).ok,false);
 assert.equal(preflight(status,{...status,free_disk_bytes:null},'1.0.4',['themes']).ok,true);
});
