'use strict';

(() => {
  const status = document.getElementById('authorizationStatus');
  const panel = document.getElementById('appConsent');
  const allow = document.getElementById('allowApp');
  const deny = document.getElementById('denyApp');
  const headers = { 'X-Console-Session': '1', 'Content-Type': 'application/json' };
  const labels = {
    'batch:create': '投稿を追加（一括同期）',
    'batch:update': '投稿を更新（一括同期）',
    'batch:delete': '投稿を削除（一括同期）',
    'activities:create': '投稿を追加',
    'activities:update': '投稿を更新',
    'activities:delete': '投稿を削除',
  };
  const errors = {
    invalid_client: 'このアプリは未登録か、連携が停止されています。',
    invalid_redirect_uri: 'このアプリの戻り先 URL は登録と一致しません。',
    invalid_request: '認証リクエストに不備があります。アプリからやり直してください。',
    project_access_denied: 'このプロジェクトへの参加と投稿権限が必要です。',
    authentication_required: '認証セッションが切れています。アプリからやり直してください。',
    authorization_expired: '認証リクエストが失効しました。アプリからやり直してください。',
  };
  let nonce;
  async function api(path, input) {
    const response = await fetch(path, { headers, cache: 'no-store',
      ...(input === undefined ? {} : { method: 'POST', body: JSON.stringify(input) }) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.code);
    return data;
  }
  async function load() {
    const query = new URLSearchParams(location.search);
    if (query.get('resume') !== '1') {
      const input = Object.fromEntries(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state'].map(key => [key, query.get(key)]));
      history.replaceState(null, '', location.pathname);
      const response = await api('api/osm-auth.php', { ...input, action: 'external', lang: 'ja' });
      if (response.authorization_url) {
        location.replace(response.authorization_url);
        return;
      }
      if (!response.consent) throw new Error('authentication_required');
      history.replaceState(null, '', '?resume=1');
    }
    const detail = await api('api/external-consent.php');
    nonce = detail.nonce;
    for (const [element, value] of Object.entries({ appName: detail.app_name, appOrigin: detail.allowed_origin,
      appProject: `${detail.project_name} (${detail.app_key})`, appUser: detail.userid })) {
      document.getElementById(element).textContent = value;
    }
    document.getElementById('appScopes').replaceChildren(...detail.scopes.map(scope => {
      const row = document.createElement('li');
      row.textContent = labels[scope];
      return row;
    }));
    document.getElementById('ownerRestriction').hidden = detail.role !== 'contributor';
    status.textContent = 'アプリに許可する操作を確認してください。';
    panel.hidden = false;
  }
  async function finish(approve) {
    allow.disabled = deny.disabled = true;
    try {
      const response = await api('api/external-consent.php', { nonce, approve });
      location.replace(response.redirect_uri);
    } catch (error) {
      status.textContent = errors[error.message] || '連携を完了できませんでした。アプリからやり直してください。';
      panel.hidden = true;
    }
  }
  allow.addEventListener('click', () => finish(true));
  deny.addEventListener('click', () => finish(false));
  load().catch(error => {
    status.textContent = errors[error.message] || '認証を開始できませんでした。アプリからやり直してください。';
  });
})();
