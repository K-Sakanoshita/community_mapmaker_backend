"use strict";
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname, '..', 'www');
function element(){return {value:'',textContent:'',disabled:false,hidden:false,open:false,listeners:{},addEventListener(type,fn){this.listeners[type]=fn;},classList:{toggle(){}},closest(){return this.parent??={hidden:false};},showModal(){this.open=true;},close(){this.open=false;this.listeners.close?.();},querySelector(){return element();}};}
(async()=>{
for(const locale of ['ja','en']){
 const elements=new Map(),messages=JSON.parse(fs.readFileSync(path.join(root,'admin',locale+'.json'))),requests=[],reports=[];
 let response={app_key:'preview',project_name:'Test',count:1496,snapshot_max_id:'1800'},reloads=0;
 const context=vm.createContext({window:{addEventListener(){}},document:{getElementById(id){if(!elements.has(id))elements.set(id,element());return elements.get(id);},querySelectorAll(){return [];}},location:{search:'?app=preview&view=activities'},URLSearchParams,t(key,args={}){return Object.entries(args).reduce((s,[k,v])=>s.replaceAll('{'+k+'}',String(v)),messages[key]||key);},testRequest:async(p,o)=>{requests.push({p,o});if(response instanceof Error)throw response;return response;},testReport:m=>reports.push(m),testReload:async()=>{reloads++;}});
 vm.runInContext(fs.readFileSync(path.join(root,'admin/admin.js'),'utf8'),context);
 const run=s=>vm.runInContext(s,context),el=id=>elements.get(id);
 run('request=testRequest;setStatus=testReport;loadActivities=testReload;state.projects=[{app_key:"preview",project_name:"Test",access_role:"editor"}];state.sessionUser={role:"user"};updateDirtyControls();');
 assert.equal(el('deleteAllActivitiesButton').hidden,true);
 await run('openBulkActivityDelete()');assert.equal(requests.length,0);
 run('state.projects[0].access_role="project_admin";state.activityLoading=true;updateDirtyControls();');
 assert.equal(el('deleteAllActivitiesButton').hidden,false);assert.equal(el('deleteAllActivitiesButton').disabled,false,'Does not wait for all rows to load');
 run('state.newActivityRows.add("new");updateDirtyControls();');assert.equal(el('deleteAllActivitiesButton').disabled,true);
 await run('openBulkActivityDelete()');assert.equal(requests.length,0);
 run('state.newActivityRows.clear();');await run('openBulkActivityDelete()');
 assert.equal(el('bulkActivityDeleteDialog').open,true);assert.match(el('bulkActivityDeleteCount').textContent,/1496/);
 assert.equal(el('bulkActivityDeleteConfirm').disabled,true);
 el('bulkActivityDeleteKey').value='wrong';el('bulkActivityDeleteKey').listeners.input();
 await run('submitBulkActivityDelete({preventDefault(){}})');assert.equal(requests.length,1);
 el('bulkActivityDeleteKey').value='preview';el('bulkActivityDeleteKey').listeners.input();assert.equal(el('bulkActivityDeleteConfirm').disabled,false);
 response={deleted_count:1496,remaining_count:1};
 await run('submitBulkActivityDelete({preventDefault(){}})');
 assert.deepEqual(JSON.parse(requests[1].o.body),{app:'preview',confirm_app_key:'preview',snapshot_max_id:'1800',expected_count:1496});
 assert.equal(reloads,1);assert.equal(el('bulkActivityDeleteDialog').open,false);assert.equal(run('state.bulkDeleteBusy'),false);assert.match(reports.at(-1),/1496/);
 // Failed POST requires a fresh preview, never a blind second submission.
 response={app_key:'preview',project_name:'Test',count:2,snapshot_max_id:'1801'};await run('openBulkActivityDelete()');el('bulkActivityDeleteKey').value='preview';response=new Error('conflict');
 await run('submitBulkActivityDelete({preventDefault(){}})');assert.equal(el('bulkActivityDeleteRetry').hidden,false);assert.equal(run('state.bulkDeleteTarget'),null);
 const count=requests.length;await run('submitBulkActivityDelete({preventDefault(){}})');assert.equal(requests.length,count);
 // No posts means no confirmation, and stale previews cannot apply to another project.
 response={app_key:'preview',project_name:'Test',count:0,snapshot_max_id:'0'};await run('openBulkActivityDelete()');el('bulkActivityDeleteKey').value='preview';el('bulkActivityDeleteKey').listeners.input();assert.equal(el('bulkActivityDeleteConfirm').disabled,true);
 let release;context.testRequest=()=>new Promise(r=>{release=r;});run('request=testRequest;');const pending=run('openBulkActivityDelete()');run('state.currentApp="other";');release({app_key:'preview',project_name:'Test',count:2,snapshot_max_id:'4'});await pending;assert.equal(run('state.bulkDeleteTarget'),null);
 // Global administrators also have access; editor membership alone never does.
 run('state.currentApp="preview";state.projects[0].access_role="editor";state.sessionUser.role="admin";updateDirtyControls();');assert.equal(el('deleteAllActivitiesButton').hidden,false);
}
console.log('Activity bulk deletion UI: JA/EN permissions, independent counts, unsaved changes, typed key, request scope, reload and stale/conflict guards passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
