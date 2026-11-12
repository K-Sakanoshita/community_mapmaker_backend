'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.textContent = ''; }
  append(...nodes) { this.children.push(...nodes); }
  addEventListener() {}
  set innerHTML(value) { throw new Error('Post content must not be interpreted as HTML'); }
}
const elements = new Map();
const window = { addEventListener() {} };
const context = { window, document: {
  getElementById(id) { if (!elements.has(id)) elements.set(id, new Element('div')); return elements.get(id); },
  createElement(tag) { return new Element(tag); },
}, SiteLanguage: {locale:'ja-JP', t:(s,p={})=>s.replace(/\{(\w+)\}/g,(_,k)=>p[k] ?? '')}, location:{search:'?app=playgrounds'}, URL, URLSearchParams, Intl };
let source = fs.readFileSync(require('node:path').join(__dirname,'../www/project-dashboard.js'),'utf8');
source = source.replace('  load();\n})();', '  window.renderRow = row;\n})();');
vm.runInNewContext(source, context);
const walk = node => [node, ...node.children.flatMap(walk)];
const text = node => walk(node).map(n=>n.textContent).filter(Boolean).join('\n');
const row = window.renderRow({id:'Playgrounds/example',osmid:'relation/19470971',created_at:'2026-11-03T04:55:30Z',data:{title:'',body:'',score:'act_score_5',good_points:'shade,water',picture_url1:'File:清水池公園（高槻市）.jpg',custom:0,flag:false}}, {
  score:{label:'評価',type:'select',order:1,options:[{value:'act_score_5',label:'おすすめ'}]},
  good_points:{label:'よい点',type:'checkbox',order:2,options:[{value:'shade',label:'木陰'},{value:'water',label:'水飲み場'}]},
  picture_url1:{label:'写真URL1',type:'image',order:3},custom:{label:'件数',order:4},flag:{label:'確認済み',order:5},
});
const visible = text(row);
assert.equal(row.children[0].textContent,'タイトルなしの投稿');
for(const value of ['評価','おすすめ','よい点','木陰','水飲み場','写真URL1','件数','0','確認済み','いいえ']) assert.ok(visible.includes(value),value);
const image = walk(row).find(n=>n.tag==='img');
assert.ok(image.src.startsWith('https://commons.wikimedia.org/wiki/Special:FilePath/'));
assert.equal(image.referrerPolicy,'no-referrer');
assert.equal(image.loading,'lazy');
assert.equal(walk(row).find(n=>n.tag==='details').children[0].textContent,'投稿ID・地物ID');
const long = '<script>alert(1)</script>'+'a'.repeat(700);
const raw = window.renderRow({id:'id',created_at:'2026-11-03T00:00:00Z',data:{body:long,other:'javascript:alert(1)',array:['one','two'],nested:{value:'custom'}}},{other:{type:'image'}});
assert.ok(text(raw).includes(long),'Full body must remain visible');
assert.equal(walk(raw).filter(n=>n.tag==='a'||n.tag==='img').length,0,'Unsafe image/link protocols rejected');
assert.ok(text(raw).includes('{"value":"custom"}'),'Unknown structured data remains visible');
const empty=window.renderRow({id:'id',created_at:'2026-11-03T00:00:00Z',data:{body:'',picture_url1:null}},{});
assert.ok(text(empty).includes('この投稿には項目データがありません。'));
console.log('Dashboard content: schema labels/options, photo-only posts, Commons images, custom fields, false/zero, full text and safe URLs passed.');
