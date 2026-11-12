"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const root=path.join(__dirname, '..', 'www');
function element(){return {value:"",checked:false,textContent:"",disabled:false,addEventListener(){},classList:{toggle(){}},showModal(){this.open=true;},close(){this.open=false;},querySelector(){return this.submit??=(element());}};}
(async()=>{
for(const locale of ["ja","en"]){
 const els=new Map(),messages=JSON.parse(fs.readFileSync(path.join(root,"admin/"+locale+".json")));
 const reports=[],requests=[];
 const lookup={filled:1,preserved:2,unresolved:[{line:5,osmid:"way/404"}]};
 const preview={valid:4,created:4,updated:0,missing_fields:[],coordinate_lookup:lookup};
 const context=vm.createContext({
  window:{addEventListener(){}},document:{getElementById(id){if(!els.has(id))els.set(id,element());return els.get(id);},querySelectorAll(){return [];}},
  location:{search:"?app=preview&view=activities"},URLSearchParams,
  t(key,args={}){return Object.entries(args).reduce((s,[k,v])=>s.replaceAll("{"+k+"}",String(v)),messages[key]||key);},
  testRequest:async(p,o)=>{requests.push(JSON.parse(o.body));return preview;},
  testReport:m=>reports.push(m)
 });
 vm.runInContext(fs.readFileSync(path.join(root,"admin/admin.js"),"utf8"),context);
 vm.runInContext('request=testRequest;setStatus=testReport;updateDirtyControls=()=>{};loadActivities=async()=>{};',context);
 const run=s=>vm.runInContext(s,context);
 await run('previewCsv("id,osmid\\nx,node/1")');
 assert.equal(run("state.pendingCsv.app"),"preview");
 assert.equal(els.get("csvDialog").open,true);
 assert.match(els.get("csvMissing").textContent,/way\/404/);
 assert.match(els.get("csvMissing").textContent,locale==="ja"?/CSV 5行目/:/CSV line 5/);
 assert.equal(run("state.csvImporting"),false);
 await run("applyCsv()");
 assert.equal(requests.length,2);
 assert.equal(requests[1].dry_run,false);
 assert.equal(requests[1].app,"preview");
 assert.match(els.get("csvImportStatus").textContent,/way\/404/);
 assert.equal(run("state.csvApplying"),false);
 // Never apply a preview to the project opened after it was prepared.
 await run('previewCsv("id,osmid\\nx,node/1")');
 const count=requests.length;
 run('state.currentApp="other";');
 await run("applyCsv()");
 assert.equal(requests.length,count);
 // A delayed preview response must not open a dialog for another project.
 let release;
 context.testRequest=()=>new Promise(r=>{release=r;});
 run('request=testRequest;state.currentApp="preview";state.pendingCsv=null;els.csvDialog.close();');
 const pending=run('previewCsv("id,osmid\\nx,node/1")');
 run('state.currentApp="other";');
 release(preview);await pending;
 assert.equal(run("state.pendingCsv"),null);
 assert.equal(els.get("csvDialog").open,false);
}
console.log("CSV coordinate UI: JA/EN progress/results, unresolved source lines, apply warnings and project-switch protection passed.");
})().catch(e=>{console.error(e);process.exitCode=1;});
