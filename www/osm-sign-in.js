'use strict';

(() => {
  const endpoint = new URL('api/osm-auth.php', document.currentScript.src);
  const panel = document.querySelector('[data-osm-action]');
  const action = panel.dataset.osmAction;
  const button = panel.querySelector('button');
  const status = panel.querySelector('[role="status"]');
  const headers = { 'X-Console-Session': '1' };

  async function update() {
    panel.hidden = true;
    try {
      const response = await fetch(endpoint, { headers });
      const result = await response.json();
      if (!response.ok || !result.enabled || (action === 'link' && !result.signed_in)) return;
      button.hidden = action === 'link' && result.linked;
      status.textContent = action === 'link' && result.linked ? SiteLanguage.t('OpenStreetMap と連携済みです。') : '';
      panel.hidden = false;
    } catch {
      // Password sign-in and My Page remain available when OSM status cannot be loaded.
    }
  }

  button.addEventListener('click', async () => {
    button.disabled = true;
    status.textContent = '';
    try {
      const current = new URLSearchParams(location.search);
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, lang: SiteLanguage.lang, view: current.get('view'), app: current.get('app') }),
      });
      const result = await response.json();
      if (!response.ok || !result.authorization_url) throw new Error();
      location.assign(result.authorization_url);
    } catch {
      status.textContent = SiteLanguage.t('OpenStreetMap の認証を開始できませんでした。もう一度お試しください。');
      button.disabled = false;
    }
  });

  window.addEventListener('site-session-change', () => update());
  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      button.disabled = false;
      update();
    }
  });
  update();
})();
