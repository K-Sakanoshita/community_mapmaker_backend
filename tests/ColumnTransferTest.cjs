"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const context = vm.createContext({ window: {} });
vm.runInContext(fs.readFileSync(require("node:path").join(__dirname, "../www/admin/column-transfer.js"), "utf8"), context);
const transfer = context.window.ColumnTransfer;
const plain = value => JSON.parse(JSON.stringify(value));
const schema = { fields: {
  category: { type: "select", label: "分類", required: true, order: 0, maxLength: 123, options: [{value:"a|b",label:"公園\n遊具"},{value:"zero",label:"0"}], admin:{visible:false,editable:false,width:320} },
  taken_at: { type: "datetime", label: "日時", order: 1 },
  photo: { type: "image", label: "写真", order: 2 },
  accessible: { type: "boolean", label: "利用可", order: 3 }
} };
assert.deepEqual(plain(transfer.parse(transfer.stringify(schema))), schema);
assert.deepEqual(plain(transfer.parse("\uFEFF"+JSON.stringify(schema))),schema);
assert.deepEqual(plain(transfer.parse(transfer.stringify({fields:[]}))),{fields:{}});
const file = JSON.parse(transfer.stringify(schema));
assert.deepEqual(Object.keys(file),["format","version","schema"]);
assert.equal(file.version,1);
assert.deepEqual(file.schema.fields.id, { label: "ID", type: "text", required: false, admin: { width: 210 } });
assert.deepEqual(file.schema.fields.osmid, { label: "OSM ID", type: "text", required: true, admin: { width: 150 } });
assert.deepEqual(Object.keys(file.schema.fields), ["id", "osmid", ...Object.keys(schema.fields)]);
assert.deepEqual(plain(transfer.parse(JSON.stringify(file.schema))), schema, "Raw schemas also ignore matching system definitions.");
assert.deepEqual(plain(transfer.parse(transfer.stringify({ fields: {} }))), { fields: {} });
// Object member order does not change a definition; all values and members do.
const reordered = { fields: { id: { admin: { width: 210 }, required: false, type: "text", label: "ID" } } };
assert.deepEqual(plain(transfer.parse(JSON.stringify(reordered))), { fields: {} });
for (const field of ["id", "osmid"]) {
  for (const mutate of [def => { def.type = "number"; }, def => { def.required = !def.required; }, def => { def.label += " changed"; }, def => { def.admin.width++; }, def => { delete def.required; }, def => { def.order = 0; }, def => { def.admin = []; }]) {
    const changed = structuredClone(file);
    mutate(changed.schema.fields[field]);
    assert.throws(() => transfer.parse(JSON.stringify(changed)), new RegExp(`^Error: columns\\.system_schema_mismatch\\.${field}$`));
  }
}
const singleSystem = { fields: { osmid: file.schema.fields.osmid, ...schema.fields } };
assert.deepEqual(plain(transfer.parse(JSON.stringify(singleSystem))), schema);
assert.equal(file.schema.fields.category.options[0].value,"a|b");
assert.deepEqual(plain(transfer.diff({fields:{old:{},category:{}}},schema)),{total:4,added:["taken_at","photo","accessible"],removed:["old"]});
for (const invalid of ["not json", "null", "[]", '{"fields":[]}', '{"fields":{"name":null}}', '{"fields":{"name":[]}}', '{"schema":{"fields":{}}}', '{"format":"other","version":1,"schema":{"fields":{}}}', '{"format":"community-mapmaker-columns","version":2,"schema":{"fields":{}}}']) {
  assert.throws(()=>transfer.parse(invalid), /^Error: columns\.(invalid_json|invalid_format)$/);
}
// Field keys are data, including property names with special meaning in JS.
const special = transfer.parse('{"fields":{"__proto__":{"type":"text"},"constructor":{"type":"text"}}}');
assert.deepEqual(Object.keys(special.fields),["__proto__","constructor"]);
assert.equal({}.type,undefined);
console.log("Column transfer: system ID export, matching definitions ignored, schema mismatch rejection, legacy/raw JSON, metadata, diff and malformed input passed.");
