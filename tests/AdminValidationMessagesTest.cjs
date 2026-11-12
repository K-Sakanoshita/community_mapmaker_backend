"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const root=path.join(__dirname, '..', 'www');
for (const locale of ["ja","en"]) {
  const messages=JSON.parse(fs.readFileSync(path.join(root,"admin/"+locale+".json")));
  const context=vm.createContext({
    window:{addEventListener(){}},
    document:{getElementById(){return {addEventListener(){}};},querySelectorAll(){return [];}},
    location:{search:""},URLSearchParams,
    t(key,args={}){return Object.entries(args).reduce((text,[key,value])=>text.replaceAll("{"+key+"}",String(value)),messages[key]||key);}
  });
  vm.runInContext(fs.readFileSync(path.join(root,"admin/admin.js"),"utf8"),context);
  vm.runInContext('state.schema={fields:{title:{label:"Title"}}};',context);
  function format(errors) {
    context.payload={code:"validation_failed",errors};
    return vm.runInContext("formatApiError(payload,422)",context);
  }
  let result=format({"csv_rows.5":{osmid:"OSM ID is required."}});
  assert.match(result,locale==="ja"?/CSV 5行目 \/ 「OSM ID」: 空欄です/:/CSV line 5 \/ "OSM ID": OSM ID is blank/);
  assert.match(result,/node\/123/);
  assert.ok(result.includes("\n") && !result.includes("\\n"),"Use real line breaks.");
  assert.ok(!result.includes("[object Object]"),"Nested messages must be expanded.");
  result=format({"csv_rows.2":{id:"Activity ID is required for import."}});
  assert.match(result,locale==="ja"?/CSV 2行目 \/ 「ID」: 空欄です/:/CSV line 2 \/ "ID": ID is blank/);
  result=format({"rows.0":{id:"Activity ID is duplicated in this import.",title:"Must not exceed 40 bytes."}});
  assert.match(result,locale==="ja"?/データ1件目/:/Data row 1/);
  assert.match(result,/Title/);
  assert.match(result,/40/);
  result=format({"headers.1":"CSV header names must not be empty."});
  assert.match(result,locale==="ja"?/CSVヘッダー 2列目/:/CSV header column 2/);
  result=format({"csv_rows.7.osmid":"OSM ID must look like node/123, way/456, or relation/789."});
  assert.match(result,/7/);assert.match(result,/relation\/789/);
  result=format({"csv_rows.9":{custom:"Additional specific validation reason."}});
  assert.match(result,/Additional specific validation reason\./,"Unknown reasons must not be silently discarded.");
  result=format({field:{options:{"1":"Option values must be unique."}}});
  assert.ok(!result.includes("[object Object]"));
  result=format({"csv_rows.4":"CSV row has more values than the header."});
  assert.match(result,locale==="ja"?/ヘッダーより値の数が多い/:/more values than the header/);
}
console.log("Admin validation messages: JA/EN nested CSV/JSON errors, source lines, IDs, headers, field labels, length limits, unknown reasons and real line breaks passed.");
