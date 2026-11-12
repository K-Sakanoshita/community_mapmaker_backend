// Install Miniflare separately; no production dependency is added by this test.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { matchPark } from '../examples/cloudflare/park-pages.mjs';
const { Miniflare, Response } = await import(process.env.CMM_MINIFLARE_MODULE || 'miniflare');

assert.equal(matchPark('/map/parks/relation/123', '/map/parks/{osm_type}/{osm_id}'), 'relation/123');
assert.equal(matchPark('/parks/way/0', '/parks/{osm_type}/{osm_id}'), null);
assert.equal(matchPark('/parks/way/1/extra', '/parks/{osm_type}/{osm_id}'), null);
assert.equal(matchPark('/parks/way/123', '/parks/{osm_type}/{osm_id}'), 'way/123');
let apiStatus = 200, malformed = false, shellStatus = 200, mismatch = false;
const calls = [];
const page = {
  app_key: 'playgrounds', osmid: 'way/123', name: '公園 </script><script>alert(1)</script>',
  title: '公園 <script>alert(1)</script> | Map', description: '説明 " & < >',
  canonical_url: 'https://pages.example.test/parks/way/123', image_url: 'https://pages.example.test/ogp.png',
  source_url: 'https://www.openstreetmap.org/way/123', attribution: '© OpenStreetMap contributors', address: '大阪市'
};
const mf = new Miniflare({
  modules: true, compatibilityDate: '2026-07-30',
  script: await fs.readFile(new URL('../examples/cloudflare/park-pages.mjs', import.meta.url), 'utf8'),
  bindings: {
    APP_KEY: 'playgrounds', PUBLIC_BASE_URL: 'https://pages.example.test/',
    SPA_INDEX_URL: 'https://origin.example.test/index.html', PARK_PAGE_API_URL: 'https://api.example.test/api/park-page.php'
  },
  outboundService: async request => {
    calls.push({ url: request.url, cookie: request.headers.get('Cookie'), auth: request.headers.get('Authorization') });
    if (request.url.startsWith('https://api.example.test/')) return new Response(malformed ? '{' : JSON.stringify({ ...page, canonical_url: mismatch ? 'https://other.example.test/' : page.canonical_url }), { status: apiStatus, headers: { 'Content-Type': 'application/json' } });
    return new Response('<!doctype html><html><head><title>Old</title><meta name="description" content="Old"><link rel="canonical" href="https://pages.example.test/"><meta property="og:title" content="Old"><meta property="og:image" content="old.png"><base href="https://old.example.test/"></head><body><div id="app">SPA shell</div><script src="./app.js"></script></body></html>', { status: shellStatus, headers: { 'Content-Type': 'text/html', ETag: '"old"', Link: '<https://pages.example.test/>; rel="canonical"' } });
  }
});
try {
  const response = await mf.dispatchFetch('https://pages.example.test/parks/way/123?tracking=abc', { headers: { Cookie: 'secret=123', Authorization: 'Bearer secret' } });
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('Cache-Control'), 'no-store');
  assert.equal(response.headers.get('ETag'), null); assert.equal(response.headers.get('Link'), null);
  const html = await response.text();
  assert.equal((html.match(/<title>/g) || []).length, 1);
  assert.ok(html.includes('<title>公園 &lt;script&gt;alert(1)&lt;/script&gt; | Map</title>'));
  assert.ok(html.includes('content="説明 &quot; &amp; &lt; &gt;"'));
  assert.ok(html.includes('rel="canonical" href="https://pages.example.test/parks/way/123"'));
  assert.ok(html.includes('<base href="https://pages.example.test/">'));
  assert.ok(!html.includes('old.png') && html.includes('id="app"') && html.includes('src="./app.js"'));
  assert.ok(html.includes('id="park-page-heading"') && html.includes('大阪市'));
  assert.ok(!html.includes('<script>alert(1)</script>'));
  assert.deepEqual(JSON.parse(html.match(/id="park-page-data">(.*?)<\/script>/s)[1]), page);
  assert.ok(calls.every(call => call.cookie === null && call.auth === null));
  assert.ok(!calls[0].url.includes('tracking='));
  assert.ok(calls[0].url.includes('osmid=way%2F123'));
  page.image_url = null;
  const noImage = await (await mf.dispatchFetch('https://pages.example.test/parks/way/123')).text();
  assert.ok(!noImage.includes('property="og:image"') && noImage.includes('content="summary"'));
  apiStatus = 404; assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123')).status, 404);
  apiStatus = 503; assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123')).status, 503);
  apiStatus = 200; malformed = true; assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123')).status, 503); malformed = false;
  mismatch = true; assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123')).status, 503); mismatch = false;
  shellStatus = 500; assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123')).status, 503); shellStatus = 200;
  const head = await mf.dispatchFetch('https://pages.example.test/parks/way/123', { method: 'HEAD' });
  assert.equal(head.status, 200); assert.equal(await head.text(), '');
  assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/0')).status, 404);
  assert.equal((await mf.dispatchFetch('https://pages.example.test/parks/way/123', { method: 'POST' })).status, 405);
  console.log('Worker runtime: HTML metadata/body, assets base, XSS escaping, JSON bootstrap, credentials isolation, canonical checks and HTTP statuses passed.');
} finally { await mf.dispose(); }
