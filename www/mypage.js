'use strict';
(() => {
  const list = document.getElementById('myProjects');
  const status = document.getElementById('myPageStatus');
  const login = document.getElementById('myPageLogin');
  const empty = document.getElementById('myProjectsEmpty');
  const pagination = document.getElementById('myProjectsPagination');
  const previous = document.getElementById('myProjectsPrevious');
  const next = document.getElementById('myProjectsNext');
  const retry = document.getElementById('myProjectsRetry');
  const useridSettings = document.getElementById('useridSettings');
  const useridForm = document.getElementById('useridForm');
  const useridInput = document.getElementById('newUserid');
  const useridStatus = document.getElementById('useridStatus');
  const saveUserid = useridForm.querySelector('button[type="submit"]');
  const passwordSettings = document.getElementById('passwordSettings');
  const passwordForm = document.getElementById('consolePasswordForm');
  const currentPassword = passwordForm.elements.current_password;
  const newPassword = passwordForm.elements.password;
  const confirmation = passwordForm.elements.password_confirmation;
  const savePassword = passwordForm.querySelector('button[type="submit"]');
  const passwordStatus = document.getElementById('consolePasswordStatus');
  const headers = { Accept: 'application/json', 'X-Console-Session': '1' };
  const roles = { viewer: SiteLanguage.t('閲覧者'), contributor: SiteLanguage.t('投稿者'), editor: SiteLanguage.t('編集者'), project_admin: SiteLanguage.t('プロジェクト管理者') };
  const reasons = {
    project_creator_required: SiteLanguage.t('作成したプロジェクトの参加は解除できません。プロジェクト設定から管理してください。'),
    last_project_administrator: SiteLanguage.t('最後の管理者のため解除できません。別の有効な管理者を追加してください。'),
    project_participation_banned: SiteLanguage.t('参加禁止の設定は、この画面から解除できません。'),
  };
  let page = 1, version = 0, totalPages = 1, leaving = false;
  let passwordRequest = 0, passwordOptions = null;

  function renderUserid() {
    const current = window.SiteSessionHeader?.user?.userid || '';
    useridSettings.hidden = !current;
    if (useridInput.defaultValue !== current) {
      useridInput.defaultValue = current;
      useridInput.value = current;
      useridStatus.textContent = '';
    }
  }

  useridForm.addEventListener('submit', async event => {
    event.preventDefault();
    const current = window.SiteSessionHeader?.user;
    if (!current || saveUserid.disabled) return;
    saveUserid.disabled = true;
    useridStatus.className = '';
    useridStatus.textContent = SiteLanguage.t('ユーザー ID を保存しています…');
    try {
      const response = await fetch('api/console-session.php', {
        method: 'PATCH',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify({ current_userid: current.userid, userid: useridInput.value }),
      });
      const result = await response.json();
      if (window.SiteSessionHeader.user?.id !== current.id) return;
      if (response.status === 401) { window.SiteSessionHeader.render(null); return; }
      if (!response.ok) throw new Error(result.code);
      window.SiteSessionHeader.render(result.user);
      useridStatus.textContent = SiteLanguage.t('ユーザー ID を保存しました。');
    } catch (error) {
      const message = {
        userid_taken: 'このユーザー ID は使われています。別の ID を入力してください。',
        userid_changed: 'ユーザー ID の状態が変わりました。ページを再読み込みしてください。',
        validation_failed: 'ユーザー ID の形式を確認してください。',
      }[error.message] || 'ユーザー ID を保存できませんでした。';
      useridStatus.className = 'error';
      useridStatus.textContent = SiteLanguage.t(message);
    } finally {
      saveUserid.disabled = false;
    }
  });

  async function loadPasswordSettings() {
    const request = ++passwordRequest;
    const current = window.SiteSessionHeader?.user;
    if (!current) {
      passwordSettings.hidden = true;
      passwordSettings.open = false;
      passwordForm.reset();
      passwordStatus.textContent = '';
      passwordOptions = null;
      return;
    }
    try {
      const response = await fetch('api/console-password.php', { headers, cache: 'no-store' });
      if (request !== passwordRequest || !response.ok) return;
      const options = await response.json();
      if (request !== passwordRequest) return;
      passwordOptions = options;
      currentPassword.disabled = !options.requires_current_password;
      document.getElementById('currentPasswordField').hidden = currentPassword.disabled;
      newPassword.minLength = confirmation.minLength = options.password_min_length;
      document.getElementById('consolePasswordRules').textContent = SiteLanguage.t(
        '{min}文字以上、{max}バイト以内で入力してください。',
        { min: options.password_min_length, max: options.password_max_bytes }
      );
      savePassword.disabled = false;
      passwordSettings.hidden = false;
    } catch { /* Keep the other My Page controls available. */ }
  }

  function showPasswordStatus(message, error = false) {
    passwordStatus.className = error ? 'error' : '';
    passwordStatus.textContent = SiteLanguage.t(message);
  }

  passwordForm.addEventListener('submit', async event => {
    event.preventDefault();
    const current = window.SiteSessionHeader?.user;
    if (!current || !passwordOptions || savePassword.disabled) return;
    if (newPassword.value !== confirmation.value) {
      showPasswordStatus('確認用パスワードが一致しません。', true);
      return;
    }
    if (new TextEncoder().encode(newPassword.value).length > passwordOptions.password_max_bytes) {
      showPasswordStatus('パスワードの長さを確認してください。', true);
      return;
    }
    savePassword.disabled = true;
    showPasswordStatus('パスワードを保存しています…');
    try {
      const response = await fetch('api/console-password.php', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(new FormData(passwordForm))),
      });
      const result = await response.json();
      if (window.SiteSessionHeader.user?.id !== current.id) return;
      if (response.status === 401) { window.SiteSessionHeader.render(null); return; }
      if (response.ok) {
        showPasswordStatus('ログイン用パスワードを保存しました。');
        return;
      }
      const message = result.errors?.current_password ? '現在のパスワードが一致しません。'
        : result.errors?.password ? 'パスワードの長さを確認してください。'
        : result.errors?.password_confirmation ? '確認用パスワードが一致しません。'
        : result.code === 'rate_limit_exceeded' ? '操作が多すぎます。時間をおいて再度お試しください。'
        : 'パスワードを保存できませんでした。';
      showPasswordStatus(message, true);
    } catch {
      showPasswordStatus('パスワードを保存できませんでした。', true);
    } finally {
      passwordForm.reset();
      savePassword.disabled = false;
    }
  });

  function signedOut() {
    version++; list.replaceChildren(); pagination.hidden = true; empty.hidden = true; retry.hidden = true;
    login.hidden = false; status.className = ''; status.textContent = SiteLanguage.t('参加プロジェクトを見るにはログインしてください。');
  }

  function safeUrl(value) {
    try { const url = new URL(value); return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : null; }
    catch { return null; }
  }

  function projectRow(project) {
    const row = document.createElement('li'); row.className = 'my-project';
    const info = document.createElement('div'); info.className = 'project-info';
    const name = document.createElement('h3'); name.textContent = project.project_name;
    const role = document.createElement('p'); role.textContent = `${roles[project.role] || project.role}${project.enabled ? '' : SiteLanguage.t(' ・ 停止中')}`;
    info.append(name, role);
    if (!project.can_leave) {
      const note = document.createElement('p'); note.className = 'project-note'; note.textContent = reasons[project.leave_reason] || SiteLanguage.t('現在、このプロジェクトの参加は解除できません。'); info.append(note);
    }
    const actions = document.createElement('div'); actions.className = 'project-actions';
    const dashboard = document.createElement('a'); dashboard.className = 'button'; dashboard.textContent = SiteLanguage.t('ダッシュボード');
    dashboard.href = `project-dashboard.html?${new URLSearchParams({ app: project.app_key, lang: SiteLanguage.lang })}`; actions.append(dashboard);
    const mapUrl = safeUrl(project.frontend_url);
    if (project.enabled && mapUrl) {
      const map = document.createElement('a'); map.className = 'button'; map.dataset.noLanguage = ''; map.textContent = SiteLanguage.t('マップを開く'); map.href = mapUrl; map.target = '_blank'; map.rel = 'noopener noreferrer'; actions.append(map);
    }
    if (project.enabled && (project.frontend_public || project.role !== 'contributor')) {
      const detail = document.createElement('a'); detail.className = 'button'; detail.textContent = SiteLanguage.t('プロジェクト詳細');
      detail.href = `./?${new URLSearchParams({ app: project.app_key })}`; actions.append(detail);
    }
    const leave = document.createElement('button'); leave.type = 'button'; leave.className = 'leave-button'; leave.textContent = SiteLanguage.t('参加を解除');
    leave.setAttribute('aria-label', SiteLanguage.t('{name}の参加を解除', { name: project.project_name })); leave.disabled = !project.can_leave; leave.dataset.canLeave = String(project.can_leave);
    leave.addEventListener('click', async () => {
      if (leaving) return;
      leaving = true;
      list.querySelectorAll('button').forEach(button => { button.disabled = true; });
      previous.disabled = true; next.disabled = true;
      status.className = ''; status.textContent = SiteLanguage.t('参加を解除しています…');
      const requestVersion = version;
      try {
        const response = await fetch('api/my-projects.php', { method: 'DELETE', headers: { ...headers, 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify({ app: project.app_key }) });
        const result = await response.json();
        if (requestVersion !== version) return;
        if (response.status === 401) { signedOut(); return; }
        if (!response.ok) throw new Error(result.code || 'server_error');
        row.remove();
        await load(page, SiteLanguage.t('{name}の参加を解除しました。', { name: project.project_name }));
      } catch (error) {
        if (requestVersion !== version) return;
        status.className = 'error'; status.textContent = reasons[error.message] || SiteLanguage.t('参加を解除できませんでした。再読み込みして状態を確認してください。'); retry.hidden = false;
      } finally {
        leaving = false;
        if (requestVersion === version) {
          list.querySelectorAll('[data-can-leave="true"]').forEach(button => { button.disabled = false; });
          previous.disabled = page <= 1; next.disabled = page >= totalPages;
        }
      }
    });
    actions.prepend(leave); row.append(info, actions); return row;
  }

  async function load(requestedPage = 1, message = '') {
    const requestVersion = ++version;
    status.className = ''; status.textContent = SiteLanguage.t('参加プロジェクトを読み込んでいます…');
    login.hidden = true; retry.hidden = true;
    previous.disabled = true; next.disabled = true;
    try {
      const response = await fetch(`api/my-projects.php?${new URLSearchParams({ page: String(requestedPage) })}`, { headers, credentials: 'same-origin', cache: 'no-store' });
      if (requestVersion !== version) return;
      if (response.status === 401) { signedOut(); return; }
      const result = await response.json();
      if (requestVersion !== version) return;
      if (!response.ok || !Array.isArray(result.items) || !result.pagination) throw new Error('load_failed');
      page = result.pagination.page; totalPages = result.pagination.total_pages;
      list.replaceChildren(...result.items.map(projectRow)); empty.hidden = result.pagination.total !== 0;
      pagination.hidden = result.pagination.total_pages <= 1;
      document.getElementById('myProjectsPage').textContent = SiteLanguage.t('{page} / {total}ページ', { page, total: result.pagination.total_pages });
      previous.disabled = page <= 1; next.disabled = page >= result.pagination.total_pages;
      status.textContent = message;
    } catch {
      if (requestVersion !== version) return;
      status.className = 'error'; status.textContent = `${message ? message + ' ' : ''}${SiteLanguage.t('参加プロジェクトを読み込めませんでした。')}`; retry.hidden = false;
    }
  }
  previous.addEventListener('click', () => load(page - 1));
  next.addEventListener('click', () => load(page + 1));
  retry.addEventListener('click', () => load(page));
  window.addEventListener('site-session-change', event => {
    renderUserid();
    loadPasswordSettings();
    if (!event.detail.signedIn) signedOut();
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { list.replaceChildren(); load(page); } });
  renderUserid();
  loadPasswordSettings();
  load();
})();
