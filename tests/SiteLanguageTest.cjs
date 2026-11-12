'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '..', 'www');
function run(href, languages = ['ja-JP'], sourceLang = 'ja', mount = '/') {
  const redirects = [];
  const document = { documentElement: { lang: sourceLang }, currentScript: { src: `https://example.test${mount}site-language.js` }, baseURI: `https://example.test${mount}`, addEventListener() {} };
  const context = vm.createContext({ window: {}, document, navigator: { languages, language: languages[0] || "ja-JP" }, location: { href, replace: value => redirects.push(String(value)) }, URL, URLSearchParams });
  vm.runInContext(fs.readFileSync(path.join(root, 'site-messages.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(root, 'site-language.js'), 'utf8'), context);
  return { language: context.window.SiteLanguage, document, redirects };
}
assert.equal(run('https://example.test/?lang=en', ['ja']).language.lang, 'en');
assert.equal(run('https://example.test/en/?lang=ja', ['en']).language.lang, 'ja');
assert.equal(run('https://example.test/en/?lang=unsupported', ['ja']).language.lang, 'en');
assert.equal(run('https://example.test/ja/', ['en']).language.lang, 'ja');
assert.equal(run('https://example.test/', ['fr-FR', 'en-US', 'ja-JP']).language.lang, 'en');
assert.equal(run('https://example.test/', ['ja-JP', 'en-US']).language.lang, 'ja');
assert.equal(run('https://example.test/', ['fr']).language.lang, 'en');
assert.equal(run('https://example.test/', []).language.lang, 'ja');
const english = run('https://example.test/?lang=en').language;
assert.equal(english.t('プロジェクト一覧'), 'Project list');
assert.equal(english.t('投稿者 {count}人', { count: 2 }), 'Contributors: 2');
assert.equal(english.t('untranslated project name'), 'untranslated project name');
const local = english.url('admin/?view=users&app=playgrounds#selected');
assert.equal(local.pathname, '/en/admin/');
assert.equal(local.searchParams.get('app'), 'playgrounds');
assert.equal(local.searchParams.get('view'), 'users');
assert.equal(local.searchParams.get('lang'), 'en');
assert.equal(local.hash, '#selected');
assert.equal(english.url('https://other.test/map?app=one').href, 'https://other.test/map?app=one');
assert.equal(english.url('api/my-projects.php').searchParams.has('lang'), false);
assert.equal(english.url('auth/verify.php?token=secret').searchParams.has('lang'), false);
const mounted = run('https://example.test/cmm/en/mypage.php', ['ja'], 'en', '/cmm/').language;
assert.equal(mounted.lang, 'en');
assert.equal(mounted.url('mypage.html').href, 'https://example.test/cmm/mypage.html?lang=en');
const redirect = run('https://example.test/admin/?view=users&app=one#selection', ['en']);
const target = new URL(redirect.redirects[0]);
assert.equal(target.pathname, '/en/admin/');
assert.equal(target.searchParams.get('app'), 'one');
assert.equal(target.hash, '#selection');
assert.equal(run('https://example.test/en/admin/?lang=ja', ['en'], 'en').redirects[0], 'https://example.test/admin/?lang=ja');
assert.equal(run('https://example.test/en/admin/', ['ja'], 'en').redirects.length, 0);
console.log('Site language: URL/directory/browser precedence, localization, mounted paths and safe navigation passed.');

const messagesContext = vm.createContext({ window: {} });
vm.runInContext(fs.readFileSync(path.join(root,'site-messages.js'),'utf8'),messagesContext);
const catalog = messagesContext.window.SiteMessages;
for (const name of ['index.html','mypage.html','register.html','privacy.html','account.html','reset-password.html','verify-email.html','project.html','portal.js','mypage.js']) {
  const source = fs.readFileSync(path.join(root,name),'utf8');
  for (const match of source.matchAll(/data-site-i18n(?:-[a-z-]+)?="([^"]*)"/g)) {
    const key = match[1].replace(/&quot;/g,'"').replace(/&#x27;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
    assert.ok(Object.hasOwn(catalog,key), `Missing static translation in ${name}: ${key}`);
  }
  for (const match of source.matchAll(/SiteLanguage\.t\(('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")/g)) {
    const key = vm.runInNewContext(match[1]);
    assert.ok(Object.hasOwn(catalog,key), `Missing dynamic translation in ${name}: ${key}`);
  }
}
console.log('Public static and dynamic translation coverage: passed.');
