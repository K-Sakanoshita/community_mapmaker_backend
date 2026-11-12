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
  const headers = { Accept: 'application/json', 'X-Console-Session': '1' };
  const roles = { viewer: SiteLanguage.t('閲覧者'), contributor: SiteLanguage.t('投稿者'), editor: SiteLanguage.t('編集者'), project_admin: SiteLanguage.t('プロジェクト管理者') };
  const reasons = {
    project_creator_required: SiteLanguage.t('作成したプロジェクトの参加は解除できません。プロジェクト設定から管理してください。'),
    last_project_administrator: SiteLanguage.t('最後の管理者のため解除できません。別の有効な管理者を追加してください。'),
    project_participation_banned: SiteLanguage.t('参加禁止の設定は、この画面から解除できません。'),
  };
  let page = 1, version = 0, totalPages = 1, leaving = false;

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
  window.addEventListener('site-session-change', event => { if (!event.detail.signedIn) signedOut(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { list.replaceChildren(); load(page); } });
  load();
})();
