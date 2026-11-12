'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const context = vm.createContext({window:{addEventListener(){}},document:{getElementById(){return {addEventListener(){}};},querySelectorAll(){return [];}},location:{search:''},URLSearchParams});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../www/admin/admin.js'),'utf8'),context);
const run = code => JSON.parse(JSON.stringify(vm.runInContext(code,context)));
const columns = [{field:'id'},{field:'description'},{field:'note'}];
const start = {column:columns[1]}, end = {column:columns[2]};
let bounds = {start,end:start}, cells = [];
context.table = {options:{clipboardCopyConfig:{rowHeaders:false,columnHeaders:false}},modules:{export:{generateExportList(){return cells.map(row=>({columns:row.map(value=>({value}))}));}},selectRange:{activeRange:{getBounds(){return bounds;}}}},columnManager:{getVisibleColumnsByIndex(){return columns;}}};
vm.runInContext('state.activityTable=table;',context);
for (const value of ['plain','first\nsecond','first\r\nsecond','first\tsecond','say "hello"','"starts quoted"','\n','', '日本語\n説明\t"引用"']) {
  cells = [[value]];
  const text = vm.runInContext('formatActivityClipboard("plain", "original")',context);
  context.clipboard = text;
  assert.deepEqual(run('parseActivityClipboard.call({table},clipboard)'),[{description:value}]);
}
cells = [['first\nsecond','note\twith "quotes"'],['next','']];
context.clipboard = vm.runInContext('formatActivityClipboard("plain", "original")',context);
assert.deepEqual(run('parseActivityClipboard.call({table},clipboard)'),[{description:'first\nsecond',note:'note\twith "quotes"'},{description:'next',note:''}]);
for (const text of ['a\tb\r\nc\td\r\n','a\tb\nc\td\n']) {
 context.clipboard=text;
 assert.deepEqual(run('parseActivityClipboard.call({table},clipboard)'),[{description:'a',note:'b'},{description:'c',note:'d'}]);
}
bounds={start,end};context.clipboard='"one\ntwo"';
assert.deepEqual(run('parseActivityClipboard.call({table},clipboard)'),[{description:'one\ntwo',note:'one\ntwo'}]);
assert.equal(vm.runInContext('formatActivityClipboard("html", "<table>unchanged</table>")',context),'<table>unchanged</table>');
context.clipboard='"unclosed\ntext';assert.equal(vm.runInContext('parseActivityClipboard.call({table},clipboard)',context),false);
context.clipboard='"closed"invalid';assert.equal(vm.runInContext('parseActivityClipboard.call({table},clipboard)',context),false);
context.table.modules.selectRange.activeRange=null;assert.equal(vm.runInContext('parseActivityClipboard.call({table},"plain")',context),false);
console.log('Activity clipboard: multiline/tab/quote single-cell round trips, multi-cell ranges, CRLF, terminal newline, range tiling and invalid-input guards passed.');
