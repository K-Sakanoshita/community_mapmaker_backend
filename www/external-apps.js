'use strict';

(() => {
  const status = document.getElementById('appsStatus');
  const form = document.getElementById('externalAppForm');
  const fields = form.elements;
  let editing = false;
  const headers = { 'X-Console-Session': '1', 'Content-Type': 'application/json' };
  const registry = 'api/admin-external-apps.php';
  const grants = 'api/external-app-grants.php';
  async function api(path, method = 'GET', input) {
    const response = await fetch(path, { method, headers, cache: 'no-store',
      body: input === undefined ? undefined : JSON.stringify(input) });
    const data = await response.json();
    if (!response.ok) throw Object.assign(new Error(data.code), { status: response.status, errors: data.errors });
    return data;
  }
  function showError(error) {
    status.textContent = error.errors ? Object.values(error.errors).join(' ') : ({
      client_already_exists: 'このクライアント ID は使用されています。',
      project_access_denied: '対象プロジェクトを確認してください。',
      authentication_required: 'ログインしてください。',
    }[error.message] || '処理を完了できませんでした。');
  }
  function button(label, action) {
    const element = document.createElement('button');
    element.type = 'button';
    element.textContent = label;
    element.addEventListener('click', async () => {
      element.disabled = true;
      try { await action(); } catch (error) { showError(error); }
      finally { element.disabled = false; }
    });
    return element;
  }
  function edit(app) {
    form.reset();
    editing = Boolean(app);
    document.getElementById('editorTitle').textContent = app ? 'アプリを変更' : 'アプリを登録';
    fields.client_id.readOnly = editing;
    if (!app) return;
    for (const key of ['client_id', 'app_name', 'allowed_origin', 'redirect_uri', 'app_key']) fields[key].value = app[key];
    fields.enabled.checked = app.enabled;
    for (const field of fields.scopes) field.checked = app.scopes.includes(field.value);
    fields.app_name.focus();
  }
  async function refresh(isAdmin) {
    const { apps } = await api(grants);
    document.getElementById('appGrants').hidden = false;
    document.getElementById('grantList').replaceChildren(...(apps.length ? apps.map(app => {
      const item = document.createElement('li');
      item.append(`${app.app_name}（${app.allowed_origin}、${app.app_key}） `,
        button('連携を解除', async () => {
          await api(`${grants}?client_id=${encodeURIComponent(app.client_id)}`, 'DELETE');
          await refresh(isAdmin);
          status.textContent = '連携を解除しました。';
        }));
      return item;
    }) : [Object.assign(document.createElement('li'), { textContent: '許可したアプリはありません。' })]));
    if (!isAdmin) return;
    const result = await api(registry);
    document.getElementById('appRegistry').hidden = false;
    document.getElementById('registeredApps').replaceChildren(...result.apps.map(app => {
      const item = document.createElement('li');
      item.append(`${app.app_name}（${app.client_id}、${app.enabled ? '有効' : '停止中'}） `, button('変更', () => edit(app)));
      if (app.enabled) item.append(' ', button('停止', async () => {
        await api(`${registry}?client_id=${encodeURIComponent(app.client_id)}`, 'DELETE');
        edit(null);
        await refresh(true);
        status.textContent = 'アプリを停止しました。';
      }));
      return item;
    }));
  }
  document.getElementById('resetEditor').addEventListener('click', () => { edit(null); fields.client_id.focus(); });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    if (submit.disabled) return;
    const formData = new FormData(form);
    const input = { ...Object.fromEntries(formData), scopes: formData.getAll('scopes'), enabled: fields.enabled.checked };
    if (!input.scopes.length) { status.textContent = '操作を1つ以上選んでください。'; return; }
    submit.disabled = true;
    try {
      await api(registry, editing ? 'PUT' : 'POST', input);
      edit(null);
      await refresh(true);
      status.textContent = '保存しました。';
    } catch (error) { showError(error); }
    finally { submit.disabled = false; }
  });
  api('api/console-session.php?summary=1').then(result => refresh(result.user.role === 'admin')).catch(error => {
    if (error.status === 401) document.getElementById('appsLogin').hidden = false;
    showError(error);
  });
})();
