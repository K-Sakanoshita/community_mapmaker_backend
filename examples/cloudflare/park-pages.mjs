// Cloudflare Workers ES module. Configure a Route for park pages only.
export function matchPark(pathname, template) {
  const parts = template.split(/(\{osm_type\}|\{osm_id\})/);
  if (parts.filter(part => part === '{osm_type}').length !== 1 || parts.filter(part => part === '{osm_id}').length !== 1) throw new Error('Invalid PAGE_PATH_TEMPLATE');
  const fields = [];
  const pattern = parts.map(part => {
    if (part === '{osm_type}') { fields.push('type'); return '(node|way|relation)'; }
    if (part === '{osm_id}') { fields.push('id'); return '([1-9][0-9]{0,18})'; }
    return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }).join('');
  const matched = new RegExp(`^${pattern}$`).exec(pathname);
  if (!matched) return null;
  const values = Object.fromEntries(fields.map((field, index) => [field, matched[index + 1]]));
  return `${values.type}/${values.id}`;
}

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const errorResponse = (status, text) => new Response(text, {
  status, headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store', ...(status === 503 ? { 'Retry-After': '30' } : {}) }
});

export function renderParkPage(shell, page, publicBaseUrl) {
  const metadata = `<title>${escapeHtml(page.title)}</title>
    <base href="${escapeHtml(publicBaseUrl)}">
    <meta name="description" content="${escapeHtml(page.description)}">
    <link rel="canonical" href="${escapeHtml(page.canonical_url)}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="${escapeHtml(page.title)}">
    <meta property="og:description" content="${escapeHtml(page.description)}">
    <meta property="og:url" content="${escapeHtml(page.canonical_url)}">
    <meta name="twitter:card" content="${page.image_url ? 'summary_large_image' : 'summary'}">
    <meta name="twitter:title" content="${escapeHtml(page.title)}">
    <meta name="twitter:description" content="${escapeHtml(page.description)}">
    ${page.image_url ? `<meta property="og:image" content="${escapeHtml(page.image_url)}"><meta name="twitter:image" content="${escapeHtml(page.image_url)}">` : ''}`;
  // Escaping '<' prevents even a park named '</script>' from ending the inert JSON block.
  const bootstrap = JSON.stringify(page).replace(/</g, '\\u003c');
  const summary = `<section id="park-page-summary" aria-labelledby="park-page-heading">
    <h1 id="park-page-heading">${escapeHtml(page.name)}</h1>
    ${page.address ? `<p>${escapeHtml(page.address)}</p>` : ''}
    <p>${escapeHtml(page.description)}</p>
    <p><a href="${escapeHtml(page.source_url)}">${escapeHtml(page.attribution)}</a></p>
    </section><script type="application/json" id="park-page-data">${bootstrap}</script>`;
  const headers = new Headers(shell.headers);
  for (const name of ['Content-Length', 'ETag', 'Last-Modified', 'Content-MD5', 'Content-Location', 'Link']) headers.delete(name);
  headers.set('Content-Type', 'text/html; charset=utf-8');
  // Always recheck project publication settings. The backend caches OSM lookups separately.
  headers.set('Cache-Control', 'no-store');
  const response = new Response(shell.body, { status: 200, headers });
  return new HTMLRewriter()
    .on('title, base, meta[name="description"], link[rel="canonical"], meta[property="og:title"], meta[property="og:description"], meta[property="og:url"], meta[property="og:image"], meta[property="og:type"], meta[name="twitter:card"], meta[name="twitter:title"], meta[name="twitter:description"], meta[name="twitter:image"], #park-page-summary, #park-page-data', { element(element) { element.remove(); } })
    .on('head', { element(element) { element.prepend(metadata, { html: true }); } })
    .on('body', { element(element) { element.prepend(summary, { html: true }); } })
    .transform(response);
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (!['GET', 'HEAD'].includes(request.method)) return errorResponse(405, 'Method not allowed');
    try {
      const publicBase = new URL(env.PUBLIC_BASE_URL);
      if (!publicBase.pathname.endsWith('/') || publicBase.search || publicBase.hash || publicBase.origin !== url.origin) throw new Error('Invalid PUBLIC_BASE_URL');
      const osmid = matchPark(url.pathname, env.PAGE_PATH_TEMPLATE || '/parks/{osm_type}/{osm_id}');
      if (!osmid) return errorResponse(404, 'Park page not found');
      const api = new URL(env.PARK_PAGE_API_URL);
      api.searchParams.set('app', env.APP_KEY);
      api.searchParams.set('osmid', osmid);
      // Use only configured URLs. Never forward client cookies, credentials or arbitrary query parameters.
      const result = await fetch(api, { headers: { Accept: 'application/json' }, redirect: 'manual', signal: AbortSignal.timeout(10000), cache: 'no-store' });
      if (result.status === 404) return errorResponse(404, 'Park page not found');
      if (!result.ok) return errorResponse(503, 'Park page temporarily unavailable');
      const page = await result.json();
      const canonical = new URL(page.canonical_url);
      if (page.app_key !== env.APP_KEY || page.osmid !== osmid || canonical.origin !== url.origin || canonical.pathname !== url.pathname || canonical.search || canonical.hash) throw new Error('Page URL mismatch');
      const origin = new URL(env.SPA_INDEX_URL);
      if (origin.origin === url.origin) throw new Error('SPA_INDEX_URL must use a separate origin');
      if (!['https:', 'http:'].includes(origin.protocol)) throw new Error('Invalid SPA_INDEX_URL');
      const shell = await fetch(origin, { headers: { Accept: 'text/html' }, redirect: 'manual', signal: AbortSignal.timeout(10000), cache: 'no-store' });
      if (!shell.ok || !shell.headers.get('Content-Type')?.includes('text/html')) return errorResponse(503, 'SPA temporarily unavailable');
      const rendered = renderParkPage(shell, page, publicBase.href);
      return request.method === 'HEAD' ? new Response(null, { status: rendered.status, headers: rendered.headers }) : rendered;
    } catch (error) {
      console.error('Park page rendering failed:', error.message);
      return errorResponse(503, 'Park page temporarily unavailable');
    }
  }
};
