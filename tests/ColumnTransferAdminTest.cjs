"use strict";
const assert = require("node:assert/strict"), fs = require("node:fs"), vm = require("node:vm"), path = require("node:path");
const {spawnSync} = require("node:child_process");
const root=path.join(__dirname, '..', 'www');
const controls=new Map();
function element() {
  return {value:"",checked:false,disabled:false,hidden:false,textContent:"",files:[],classList:{toggle(){},add(){},remove(){}},addEventListener(){},querySelectorAll(){return [];},replaceChildren(){},append(){},setAttribute(){},removeAttribute(){},showModal(){this.open=true;},close(){this.open=false;},matches(){return false;}};
}
const project={app_key:"target",project_name:"Production",frontend_url:"https://example.test/production",frontend_public:false,recruiting:false,page_rendering:{enabled:false,path_template:"/production/parks/{osm_type}/{osm_id}",title_template:"{name} - Production",default_description:"Target description",default_image_url:"https://example.test/ogp.png"},schema:{fields:{old:{type:"text",label:"Old"}}}};
const requests=[],notices=[];
const messages=JSON.parse(fs.readFileSync(path.join(root,"admin/en.json")));
let stored=structuredClone(project);
const context=vm.createContext({
  window:{addEventListener(){},ColumnTransfer:null,Tabulator:class{
    constructor(el,options){this.rows=options.data.map(data=>({getData:()=>data}));}
    getRows(){return this.rows;} getData(){return this.rows.map(row=>row.getData());}
    destroy(){} on(){} validate(){return true;}
  }},
  document:{createElement:element,getElementById(id){if(!controls.has(id)) controls.set(id,element());return controls.get(id);},querySelectorAll(){return [];},documentElement:{lang:"en"}},
  location:{href:"http://localhost/admin/?app=target&view=columns&lang=en",search:"?app=target&view=columns"},URL,URLSearchParams,Blob,structuredClone,console,setTimeout,clearTimeout,
  t(key,args={}){return Object.entries(args).reduce((text,[key,value])=>text.replaceAll("{"+key+"}",String(value)),messages[key]||key);},
  testRequest:async (endpoint,options)=>{
    requests.push({endpoint,method:options.method});
    const body=JSON.parse(options.body);
    const schema=endpoint.startsWith("project-schema.php")?body:body.schema;
    const php=spawnSync("php",["-r", 'ob_start(); require $argv[1]."/tests/ProjectServiceTest.php"; ob_end_clean(); try { echo json_encode($service->sanitizeSchema(json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(2); }',path.dirname(root),Buffer.from(JSON.stringify(schema)).toString('base64')],{encoding:"utf8",timeout:10000});
    if(php.status!==0) throw new Error("Validation failed: "+php.stderr);
    const validated=JSON.parse(php.stdout);
    if(endpoint.startsWith("project-schema.php")) return {schema:validated};
    assert.ok(endpoint.startsWith("projects.php"));
    stored={...stored,...body,schema:validated};return structuredClone(stored);
  },
  testNotice:(message,error)=>notices.push({message,error})
});
vm.runInContext(fs.readFileSync(path.join(root,"admin/column-transfer.js"),"utf8"),context);
vm.runInContext(fs.readFileSync(path.join(root,"admin/admin.js"),"utf8"),context);
vm.runInContext('request=testRequest; setStatus=testNotice; populateProjectSelect=()=>{};',context);
const run=code=>vm.runInContext(code,context);
context.fixture=structuredClone(project);
run('state.currentApp="target";state.view="columns";state.projects=[fixture];openColumns();');
// Internal form metadata is not a configured display column, but saving must retain it.
assert.equal(run('activityColumns().some(column=>column.field==="form_key")'),false);
assert.equal(run('rowPayload({id:"post/1",osmid:"node/1",form_key:"review"}).form_key'),"review");
assert.equal(run('rowPayload({id:"post/2",osmid:"node/2"}).form_key'),"");
const importSchema={fields:{
  category:{type:"select",label:"分類",required:true,maxLength:123,options:[{value:"a|b",label:"公園\n遊具"}],admin:{visible:false,editable:false,width:320},order:1},
  time:{type:"datetime",label:"日時",order:0}
}};
function file(schema) {
  return {name:"columns.json",size:200,text:async()=>JSON.stringify({format:"community-mapmaker-columns",version:1,schema})};
}
(async()=>{
  // Exercise the export handler and feed its actual downloaded content back in.
  let exported;
  context.URL = { createObjectURL(blob) { exported = blob; return "blob:columns"; }, revokeObjectURL() {} };
  context.document.createElement = () => ({ click() {} });
  await run("exportColumns()");
  assert.ok(exported, JSON.stringify(notices));
  const exportedFile = JSON.parse(await exported.text());
  assert.deepEqual(Object.keys(exportedFile.schema.fields), ["id", "osmid", "old"]);
  const systemFields = { id: exportedFile.schema.fields.id, osmid: exportedFile.schema.fields.osmid };
  controls.get("importColumnsFile").files=[file({ fields: { ...systemFields, ...importSchema.fields } })];
  await run("previewColumnsImport()");
  assert.ok(controls.get("importColumnsDialog").open,JSON.stringify(notices));
  assert.match(controls.get("importColumnsSummary").textContent,/2 columns \(2 added, 1 removed\)/);
  assert.deepEqual(stored,project,"Preview must not write project settings.");
  assert.equal(run("state.schemaDirty"),false);
  run("applyColumnsImport()");
  assert.equal(run("state.schemaDirty"),true);
  assert.equal(controls.get("saveColumnsButton").disabled,false);
  assert.deepEqual(stored,project,"Loading into the editor must still not save.");
  assert.deepEqual(Object.keys(run("collectSchema().fields")),["time","category"]);
  assert.deepEqual(JSON.parse(JSON.stringify(run("collectSchema().fields.category.options"))),importSchema.fields.category.options,"Separators and line breaks must not corrupt options.");
  await run("saveColumns()");
  assert.equal(run("state.schemaDirty"),false);
  assert.equal(stored.project_name,project.project_name);
  assert.equal(stored.frontend_url,project.frontend_url);
  assert.equal(stored.frontend_public,false);
  assert.equal(stored.recruiting,false);
  assert.deepEqual(stored.page_rendering,project.page_rendering,"Schema import/save preserves target page settings.");
  assert.equal(stored.schema.fields.category.admin.width,320);
  assert.equal(stored.schema.fields.category.maxLength,123);
  assert.equal(Object.hasOwn(stored.schema.fields, "id"), false);
  assert.equal(Object.hasOwn(stored.schema.fields, "osmid"), false);
  assert.equal(requests.filter(r=>r.method==="PUT").length,1);
  // Invalid input never changes the current editor or stored state.
  const saved=JSON.stringify(stored);
  controls.get("importColumnsFile").files=[file({fields:{id:{type:"text"}}})];
  await run("previewColumnsImport()");
  assert.equal(run("state.pendingColumnImport"),null);
  assert.equal(JSON.stringify(stored),saved);
  assert.equal(run("state.schemaDirty"),false);
  assert.equal(notices.at(-1).error,true);
  assert.match(notices.at(-1).message, /schema for id differs/);
  controls.get("importColumnsFile").files=[file({fields:{osmid:{...systemFields.osmid,required:false}}})];
  await run("previewColumnsImport()");
  assert.equal(run("state.pendingColumnImport"),null);
  assert.equal(JSON.stringify(stored),saved);
  assert.match(notices.at(-1).message, /schema for osmid differs/);
  // A response arriving after the user leaves the screen must be ignored.
  let release;
  context.testRequest=()=>new Promise(resolve=>{release=resolve;});
  run("request=testRequest;");
  controls.get("importColumnsFile").files=[file(importSchema)];
  const pending=run("previewColumnsImport()");
  await new Promise(resolve=>setImmediate(resolve));
  run('state.view="activities";');
  release({schema:importSchema});await pending;
  assert.equal(run("state.pendingColumnImport"),null);
  console.log("Column admin flow: preview without writes, explicit load/save, metadata/options preservation, invalid-file protection and stale response rejection passed.");
})().catch(error=>{console.error(error);process.exitCode=1;});
