'use strict';
(() => {
  const root = new URL('./', document.currentScript.src);
  const normalize = value => typeof value === 'string' && ['ja', 'en'].includes(value.toLowerCase().split('-')[0]) ? value.toLowerCase().split('-')[0] : null;
  const current = new URL(location.href);
  const relative = current.pathname.startsWith(root.pathname) ? current.pathname.slice(root.pathname.length) : '';
  const directory = /^(ja|en)(?:\/|$)/.exec(relative)?.[1] || null;
  const browserLanguages = navigator.languages?.length ? navigator.languages : [navigator.language];
  const lang = normalize(current.searchParams.get('lang')) || directory
    || browserLanguages.map(normalize).find(Boolean) || 'en';
  const sourceLang = document.documentElement.lang;
  document.documentElement.lang = lang;
  const isAdmin = /^(?:(?:ja|en)\/)?admin(?:\/|$)/.test(relative);
  const pagePath = relative.replace(/^(?:ja|en)\//, '');
  const publicPages = new Set(['', 'index.html', 'index.php', 'mypage.html', 'project-dashboard.html', 'mypage.php', 'register.html', 'register.php', 'privacy.html', 'privacy.php', 'account.html', 'account.php', 'reset-password.html', 'reset-password.php', 'verify-email.html', 'verify-email.php', 'project.html', 'project.php']);
  const isPage = path => publicPages.has(path.replace(/^(?:ja|en)\//, '')) || /^(?:(?:ja|en)\/)?admin\/(?:index\.(?:html|php))?$/.test(path);
  const hooks = [];

  function t(source, params = {}) {
    const message = lang === 'en' ? window.SiteMessages?.[source] ?? source : source;
    return message.replace(/\{([a-zA-Z_]+)\}/g, (match, name) => Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match);
  }

  function url(value, chosen = lang) {
    const result = new URL(value, document.baseURI);
    if (result.origin !== root.origin || !result.pathname.startsWith(root.pathname)) return result;
    const path = result.pathname.slice(root.pathname.length);
    if (!isPage(path)) return result;
    if (/^(?:(?:ja|en)\/)?admin\//.test(path)) result.pathname = new URL(chosen === 'en' ? 'en/admin/' : 'admin/', root).pathname;
    result.searchParams.set('lang', chosen);
    return result;
  }

  function translate(container = document) {
    container.querySelectorAll('[data-site-i18n]').forEach(element => { element.textContent = t(element.dataset.siteI18n); });
    ['title', 'alt', 'aria-label', 'placeholder', 'content'].forEach(attribute => {
      container.querySelectorAll(`[data-site-i18n-${attribute}]`).forEach(element => {
        element.setAttribute(attribute, t(element.getAttribute(`data-site-i18n-${attribute}`)));
      });
    });
  }

  function localizeLink(link) {
    if (typeof link.href !== 'string' || link.matches('.project-map-link, .project-frontend-link, #projectReturnLink') || (link.target === '_blank' && !link.getAttribute('href')?.includes('privacy.'))) return;
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#') || link.hasAttribute('download') || link.closest('[data-no-language]')) return;
    const result = url(raw);
    if (result.href !== link.href) link.href = result.href;
  }
  function links(container) {
    if (container.matches?.('a[href]')) localizeLink(container);
    container.querySelectorAll?.('a[href]').forEach(localizeLink);
  }

  window.SiteLanguage = { lang, locale: lang === 'ja' ? 'ja-JP' : 'en', root, t, url, translate, onSwitch: hook => hooks.push(hook) };
  // The administration interface already has complete static Japanese/English variants.
  if (isAdmin && sourceLang !== lang) {
    const target = new URL(lang === 'en' ? 'en/admin/' : 'admin/', root);
    target.search = current.search; target.hash = current.hash; target.searchParams.set('lang', lang);
    location.replace(target.href);
  }

  document.addEventListener('DOMContentLoaded', () => {
    translate(); links(document);
    const nav = document.querySelector('.site-header .header-links');
    if (nav) {
      const label = document.createElement('label'); label.className = 'site-language-menu';
      const caption = document.createElement('span'); caption.textContent = lang === 'ja' ? '言語' : 'Language';
      const select = document.createElement('select'); select.id = 'siteLanguageSelect'; select.setAttribute('aria-label', lang === 'ja' ? '表示言語' : 'Display language');
      select.append(new Option('日本語', 'ja'), new Option('English', 'en')); select.value = lang;
      select.addEventListener('change', () => {
        const chosen = select.value;
        if (chosen === lang) return;
        const event = new CustomEvent('site-language-before-change', { cancelable: true });
        if (!window.dispatchEvent(event)) { select.value = lang; return; }
        const target = url(location.href, chosen);
        if (directory && !isAdmin) {
          const filename = pagePath === '' || /^index\./.test(pagePath) ? '' : pagePath.replace(/\.html$/, '.php');
          target.pathname = new URL(`${chosen}/${filename}`, root).pathname;
        }
        // Token-bearing account screens restore their in-memory context only for this same-origin navigation.
        hooks.forEach(hook => hook(target));
        location.assign(target.href);
      });
      label.append(caption, select); nav.append(label);
    }
    new MutationObserver(records => {
      records.forEach(record => {
        if (record.type === 'attributes') { if (record.target.matches('a[href]')) localizeLink(record.target); }
        else record.addedNodes.forEach(node => { if (node.nodeType === 1) links(node); });
      });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
  });
})();
