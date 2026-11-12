'use strict';
(() => {
  const get = id => document.getElementById(id);
  const app = new URLSearchParams(location.search).get('app') || '';
  const status = get('dashboardStatus'), content = get('dashboardContent'), list = get('dashboardItems');
  const previous = get('dashboardPrevious'), next = get('dashboardNext'), retry = get('dashboardRetry');
  let page = 1, version = 0;
  function clear() { version++; content.hidden = true; list.replaceChildren(); }
  function safeUrl(value) {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch { return null; }
  }
  function present(value) {
    return value !== null && value !== undefined && !(typeof value === 'string' && value.trim() === '')
      && !(Array.isArray(value) && value.length === 0);
  }
  function printable(value) {
    if (typeof value === 'boolean') return SiteLanguage.t(value ? 'はい' : 'いいえ');
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
  }
  function fieldValue(container, value, definition) {
    let values = Array.isArray(value) ? value : [value];
    if (definition.type === 'checkbox' && typeof value === 'string') values = value.split(',').map(part => part.trim()).filter(Boolean);
    for (const entry of values) {
      const block = document.createElement('div');
      const option = (definition.options || []).find(option => typeof option === 'object' && option !== null && String(option.value) === String(entry));
      const text = option?.label || printable(entry);
      const commons = typeof entry === 'string' && /^File:.+/i.test(entry);
      const imageUrl = commons ? `https://commons.wikimedia.org/wiki/Special:FilePath/${encodeURIComponent(entry.slice(5).trim())}?width=400` : safeUrl(entry);
      if (['image', 'wikimedia'].includes(definition.type) && imageUrl) {
        const link = document.createElement('a');
        link.href = commons ? `https://commons.wikimedia.org/wiki/${encodeURIComponent(entry.trim())}` : imageUrl;
        link.target = '_blank'; link.rel = 'noopener noreferrer'; link.dataset.noLanguage = '';
        const image = document.createElement('img'); image.src = imageUrl; image.alt = text;
        image.loading = 'lazy'; image.decoding = 'async'; image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => { image.hidden = true; });
        const caption = document.createElement('span'); caption.textContent = text;
        link.append(image, caption); block.append(link);
      } else if (safeUrl(entry)) {
        const link = document.createElement('a'); link.href = safeUrl(entry); link.textContent = text;
        link.target = '_blank'; link.rel = 'noopener noreferrer'; link.dataset.noLanguage = ''; block.append(link);
      } else { block.textContent = text; }
      container.append(block);
    }
  }
  function row(item, fields = {}) {
    const li = document.createElement('li'), title = document.createElement('h3'), time = document.createElement('time');
    const data = item.data || {};
    title.textContent = [data.title, data.name, data.subject].find(value => typeof value === 'string' && value.trim()) || SiteLanguage.t('タイトルなしの投稿');
    time.dateTime = item.created_at;
    time.textContent = SiteLanguage.t('登録日時：{date}', { date: new Intl.DateTimeFormat(SiteLanguage.locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Tokyo' }).format(new Date(item.created_at)) });
    li.append(title, time);
    const details = document.createElement('dl'); details.className = 'dashboard-fields';
    const entries = Object.entries(data).filter(([, value]) => present(value));
    entries.sort(([a], [b]) => (fields[a]?.order ?? 999) - (fields[b]?.order ?? 999));
    for (const [key, value] of entries) {
      const definition = fields[key] || {};
      const label = document.createElement('dt'); label.textContent = definition.label || key;
      const content = document.createElement('dd'); fieldValue(content, value, definition);
      details.append(label, content);
    }
    if (entries.length) li.append(details);
    else { const empty = document.createElement('p'); empty.textContent = SiteLanguage.t('この投稿には項目データがありません。'); li.append(empty); }
    const metadata = document.createElement('details'), summary = document.createElement('summary');
    summary.textContent = SiteLanguage.t('投稿ID・地物ID'); metadata.append(summary);
    const id = document.createElement('p'); id.textContent = `ID: ${item.id}`; metadata.append(id);
    if (item.osmid) {
      const osm = document.createElement('p'); osm.textContent = `OSM: ${item.osmid}`; metadata.append(osm);
    }
    li.append(metadata);
    return li;
  }
  async function load(requested = 1) {
    const current = ++version;
    content.hidden = true; list.replaceChildren(); get('dashboardLogin').hidden = true; retry.hidden = true;
    status.textContent = SiteLanguage.t('読み込み中…');
    previous.disabled = true; next.disabled = true;
    try {
      const response = await fetch(`api/project-dashboard.php?${new URLSearchParams({ app, page: String(requested) })}`, { headers: { Accept: 'application/json', 'X-Console-Session': '1' }, credentials: 'same-origin', cache: 'no-store' });
      if (current !== version) return;
      if (response.status === 401) { get('dashboardLogin').hidden = false; status.textContent = SiteLanguage.t('ダッシュボードを見るにはログインしてください。'); return; }
      if (response.status === 403 || response.status === 404) { status.textContent = SiteLanguage.t('このプロジェクトのダッシュボードを閲覧できません。'); return; }
      if (!response.ok) throw new Error('load_failed');
      const result = await response.json();
      if (current !== version) return;
      get('dashboardTitle').textContent = `${result.project.project_name} — ${SiteLanguage.t('ダッシュボード')}`;
      for (const [id, key] of [['Total', 'total'], ['Today', 'today'], ['Week', 'week'], ['Month', 'month']]) get(`count${id}`).textContent = new Intl.NumberFormat(SiteLanguage.locale).format(result.counts[key]);
      list.replaceChildren(...result.items.map(item => row(item, result.fields)));
      page = result.pagination.page;
      previous.disabled = page <= 1; next.disabled = page >= result.pagination.total_pages;
      get('dashboardPage').textContent = SiteLanguage.t('{page} / {total}ページ', { page, total: result.pagination.total_pages });
      get('dashboardEmpty').hidden = result.pagination.total !== 0;
      get('dashboardPagination').hidden = result.pagination.total_pages <= 1;
      content.hidden = false; status.textContent = '';
    } catch {
      if (current !== version) return;
      clear(); status.textContent = SiteLanguage.t('ダッシュボードを読み込めませんでした。'); retry.hidden = false;
    }
  }
  previous.addEventListener('click', () => load(page - 1)); next.addEventListener('click', () => load(page + 1)); retry.addEventListener('click', () => load(page));
  window.addEventListener('site-session-change', event => {
    if (event.detail.signedIn) load(page);
    else { clear(); status.textContent = SiteLanguage.t('ダッシュボードを見るにはログインしてください。'); get('dashboardLogin').hidden = false; }
  });
  window.addEventListener('pageshow', event => { if (event.persisted) load(page); });
  load();
})();
