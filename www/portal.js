'use strict';

const grid = document.getElementById('projectGrid');
const count = document.getElementById('projectCount');
const status = document.getElementById('loadStatus');
const empty = document.getElementById('emptyState');
let projects = [];
let hasConsoleSession = false;
let projectLoadVersion = 0;
const permissions = { activities: false, columns: false, users: false, trash: false };
const selectedApp = new URLSearchParams(location.search).get('app');
const singleProjectMode = selectedApp !== null;
if (singleProjectMode) {
  document.body.classList.add('single-project-mode');
  document.getElementById('projectBreadcrumbs').hidden = false;
  count.hidden = true;
  document.querySelector('.hero').hidden = true;
  document.getElementById('join').hidden = true;
  const heading = document.createElement('h1');
  heading.id = 'projects-title'; heading.textContent = SiteLanguage.t('プロジェクト詳細');
  document.getElementById('projects-title').replaceWith(heading);
  document.getElementById('directoryDescription').textContent = SiteLanguage.t('投稿データと投稿者を確認し、各管理画面を開けます。管理画面の利用にはログインと各操作の権限が必要です。');
}

// Loads the logged-in user's console access so management buttons stay hidden without permission.
async function loadPermissions() {
  Object.keys(permissions).forEach(key => { permissions[key] = false; });
  try {
    const response = await fetch('api/console-session.php', {
      headers: { Accept: 'application/json', 'X-Console-Session': '1' },
      credentials: 'same-origin', cache: 'no-store',
    });
    if (!response.ok) return;
    const session = await response.json();
    if (!session || typeof session !== 'object') return;
    const project = Array.isArray(session.projects)
      ? session.projects.find(item => item && item.app_key === selectedApp) ?? null : null;
    const isAdmin = session.user && session.user.role === 'admin';
    permissions.activities = project !== null;
    permissions.columns = isAdmin || project?.access_role === 'project_admin';
    permissions.users = !!project?.id && (isAdmin || project?.access_role === 'project_admin');
    permissions.trash = isAdmin;
    return session;
  } catch { /* Keep all management buttons hidden when the session cannot be verified. */ }
}

function managementLink(label, view, className, appKey) {
  const link = document.createElement('a'); link.textContent = label;
  link.className = className;
  link.href = `admin/?${new URLSearchParams({ app: appKey, view })}`;
  return link;
}

function addDashboardLink(actions, appKey) {
  if (actions.querySelector('.project-dashboard-link')) return;
  const link = document.createElement('a');
  link.className = 'project-detail-link project-dashboard-link';
  link.textContent = SiteLanguage.t('ダッシュボード');
  link.href = `project-dashboard.html?${new URLSearchParams({ app: appKey, lang: SiteLanguage.lang })}`;
  actions.prepend(link);
}

function safeUrl(value) {
  try {
    const url = new URL(value);
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
  } catch { return null; }
}

function projectDetailUrl(appKey) {
  return `./?${new URLSearchParams({ app: appKey })}`;
}

function projectRow(project) {
  const row = document.createElement('li');
  row.className = 'project-row';
  const overview = document.createElement('div'); overview.className = 'project-overview';
  const image = document.createElement('img'); image.className = 'project-preview-image';
  image.alt = SiteLanguage.t('{name}の紹介画像', { name: project.project_name });
  image.decoding = 'async'; image.referrerPolicy = 'no-referrer';
  image.hidden = true;
  image.addEventListener('load', () => { image.hidden = false; });
  image.addEventListener('error', () => { image.hidden = true; });
  const info = document.createElement('div');
  info.className = 'project-info';
  const title = document.createElement('h2');
  title.textContent = project.project_name;
  if (project.frontend_public === false) {
    const badge = document.createElement('span'); badge.className = 'project-private-badge';
    badge.textContent = SiteLanguage.t('プライベート'); title.prepend(badge);
  }
  if (!singleProjectMode && project.recruiting === true && !project.console_only && project.enabled !== false) {
    const badge = document.createElement('span'); badge.className = 'project-recruiting-icon';
    badge.title = SiteLanguage.t('メンバー募集中'); badge.setAttribute('role', 'img'); badge.setAttribute('aria-label', SiteLanguage.t('メンバー募集中'));
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8'); svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M12.5 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M19 8v6M16 11h6');
    const label = document.createElement('span'); label.textContent = SiteLanguage.t('募集中');
    svg.append(path); badge.append(svg, label); title.append(badge);
  }
  const description = document.createElement('p');
  description.className = 'project-description';
  description.textContent = SiteLanguage.t('プロジェクトの詳細を確認し、活動に参加できます。');
  const summary = document.createElement('div'); summary.className = 'project-summary';
  const total = document.createElement('p'); total.className = 'project-data-count';
  total.textContent = SiteLanguage.t('投稿データ {count}件', { count: Number(project.activity_count ?? 0).toLocaleString(SiteLanguage.locale) });
  const contributors = document.createElement('div'); contributors.className = 'project-contributors';
  const names = Array.isArray(project.contributors) ? project.contributors.filter(name => typeof name === 'string') : [];
  const label = document.createElement('span'); label.textContent = SiteLanguage.t('投稿者 {count}人', { count: names.length });
  const icons = document.createElement('div'); icons.className = 'contributor-icons';
  names.slice(0, 8).forEach(name => {
    const icon = document.createElement('span'); icon.className = 'contributor-icon';
    icon.textContent = Array.from(name)[0]?.toLocaleUpperCase('ja-JP') || SiteLanguage.t('人');
    icon.title = name; icon.setAttribute('aria-label', name); icon.setAttribute('role', 'img');
    icons.append(icon);
  });
  if (names.length > 8) {
    const more = document.createElement('span'); more.className = 'contributor-icon contributor-more';
    more.textContent = `+${names.length - 8}`; icons.append(more);
  }
  contributors.append(label, icons);
  summary.append(total, contributors);
  info.append(title);
  if (singleProjectMode) {
    const key = document.createElement('p'); key.className = 'project-key';
    key.textContent = `KEY: ${project.app_key}`;
    info.append(key);
  }
  info.append(description);
  if (singleProjectMode) info.append(summary);
  if (!Array.isArray(project.contributors)) contributors.hidden = true;
  const actions = document.createElement('div');
  actions.className = 'project-links';
  const detail = document.createElement('a');
  detail.className = 'project-detail-link';
  detail.href = projectDetailUrl(project.app_key);
  detail.textContent = SiteLanguage.t('プロジェクト詳細');
  detail.setAttribute('aria-label', SiteLanguage.t('{name}：プロジェクト詳細', { name: project.project_name }));
  const map = document.createElement('a');
  map.className = 'project-map-link';
  map.href = safeUrl(project.frontend_url);
  map.target = '_blank'; map.rel = 'noopener noreferrer';
  map.textContent = SiteLanguage.t('マップを開く');
  map.setAttribute('aria-label', SiteLanguage.t('{name}：マップを開く（新しいタブ）', { name: project.project_name }));
  const arrow = document.createElement('span'); arrow.textContent = '↗'; arrow.setAttribute('aria-hidden', 'true');
  map.append(arrow);
  if (singleProjectMode) {
    if (permissions.activities) actions.append(managementLink(SiteLanguage.t('投稿データ管理'), 'activities', 'project-detail-link', project.app_key));
    if (permissions.columns) actions.append(managementLink(SiteLanguage.t('プロジェクト設定'), 'columns', 'project-management-link', project.app_key));
    if (permissions.users) actions.append(managementLink(SiteLanguage.t('ユーザー管理'), 'users', 'project-management-link', project.app_key));
    if (permissions.trash) actions.append(managementLink(SiteLanguage.t('削除済みデータ管理'), 'trash', 'project-management-link', project.app_key));
    if (safeUrl(project.frontend_url)) actions.append(map);
  } else { if (safeUrl(project.frontend_url)) actions.append(map); actions.append(detail); }
  if (singleProjectMode && permissions.activities) addDashboardLink(actions, project.app_key);
  overview.append(image, info);
  row.append(overview, actions);
  project.previewImage = image;
  project.element = row;
  project.descriptionElement = description;
  return row;
}

function render() {
  grid.replaceChildren(...projects.map(project => project.element));
  count.textContent = SiteLanguage.t('{count}件', { count: projects.length });
  empty.hidden = projects.length !== 0;
  empty.querySelector('h3').textContent = singleProjectMode ? SiteLanguage.t('プロジェクトが見つからないか、現在公開されていません') : SiteLanguage.t('表示できるプロジェクトはまだありません');
  empty.querySelector('p').textContent = singleProjectMode ? SiteLanguage.t('プロジェクト一覧から選んでください。') : SiteLanguage.t('公開されるプロジェクトが増えるまで、少しお待ちください。');
}

async function loadPreview(project) {
  try {
    const response = await fetch(`api/project-preview.php?app=${encodeURIComponent(project.app_key)}`, { headers: { Accept: 'application/json', 'X-Console-Session': '1' }, credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) return;
    const preview = await response.json();
    const imageUrl = safeUrl(preview.image_url);
    if (imageUrl) project.previewImage.src = imageUrl;
    if (typeof preview.description === 'string' && preview.description) {
      project.description = preview.description;
      project.descriptionElement.textContent = preview.description;
    }
  } catch { /* Project remains available when its preview cannot be loaded. */ }
}

async function loadJoinAction(project) {
  const headers = { Accept: 'application/json', 'X-Console-Session': '1' };
  try {
    const response = await fetch(`api/project-membership.php?${new URLSearchParams({ app: project.app_key })}`, { headers, credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) return;
    const membership = await response.json();
    if (!project.element.isConnected) return;
    if (membership.is_member) {
      addDashboardLink(project.element.querySelector('.project-links'), project.app_key);
      ProjectLeave.render({ appKey: project.app_key, membership, actions: project.element.querySelector('.project-links'), info: project.element.querySelector('.project-info'), onLeave: async () => {
        await loadProjects(); status.textContent = SiteLanguage.t('参加を解除しました。投稿済みのデータは残ります。'); status.hidden = false;
      } });
      return;
    }
    if (!membership.can_join) return;
    const actions = project.element.querySelector('.project-links');
    const note = document.createElement('p'); note.className = 'project-join-status';
    note.setAttribute('role', 'status'); note.setAttribute('aria-live', 'polite');
    const control = document.createElement(membership.authenticated ? 'button' : 'a');
    control.className = 'project-join-button'; control.textContent = SiteLanguage.t('参加する');
    if (!membership.authenticated) {
      control.href = `register.html?${new URLSearchParams({ app_key: project.app_key })}`;
      note.textContent = SiteLanguage.t('ユーザー登録から参加できます。登録済みの方はログインしてください。');
    } else {
      control.type = 'button';
      control.addEventListener('click', async () => {
        control.disabled = true; note.textContent = SiteLanguage.t('参加手続き中です…');
        try {
          const result = await fetch('api/project-membership.php', { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify({ app: project.app_key }) });
          const payload = await result.json();
          if (!result.ok) {
            if (payload.code === 'project_participation_banned') { control.remove(); note.textContent = SiteLanguage.t('このプロジェクトには参加できません。'); return; }
            if (payload.code === 'project_not_recruiting') { control.remove(); note.textContent = SiteLanguage.t('現在、このプロジェクトはメンバーを募集していません。'); return; }
            if (result.status === 401) { note.textContent = SiteLanguage.t('ログイン後に、もう一度参加してください。'); return; }
            throw new Error('join_failed');
          }
          if (!payload.is_member) throw new Error('invalid_membership');
          status.hidden = true;
          control.remove(); note.textContent = SiteLanguage.t('参加しました。マップから情報を投稿できます。');
          await loadJoinAction(project);
        } catch { note.textContent = SiteLanguage.t('参加できませんでした。時間をおいて、もう一度お試しください。'); }
        finally { control.disabled = false; }
      });
    }
    actions.prepend(control); project.element.querySelector('.project-info').append(note);
  } catch { /* Never offer membership when its availability cannot be verified. */ }
}

async function loadProjects() {
  try {
    const loadVersion = ++projectLoadVersion;
    const publicRequest = fetch(`api/public-projects.php${singleProjectMode ? `?${new URLSearchParams({ app: selectedApp })}` : ''}`, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(async response => { if (!response.ok) throw new Error('public_projects_failed'); const data = await response.json(); if (!Array.isArray(data)) throw new Error('invalid_projects'); return data; })
      .catch(() => null);
    const [publicProjects, session] = await Promise.all([publicRequest, loadPermissions()]);
    if (loadVersion !== projectLoadVersion) return;
    if (publicProjects === null && !session) throw new Error('project_list_failed');
    hasConsoleSession = Boolean(session?.user);
    const merged = new Map();
    for (const project of publicProjects || []) {
      if (project && typeof project.app_key === 'string' && typeof project.project_name === 'string' && safeUrl(project.frontend_url)) merged.set(project.app_key, { ...project, console_only: false });
    }
    for (const project of Array.isArray(session?.projects) ? session.projects : []) {
      if (project && typeof project.app_key === 'string' && typeof project.project_name === 'string') {
        const existing = merged.get(project.app_key);
        merged.set(project.app_key, { ...existing, ...project, console_only: !existing });
      }
    }
    projects = [...merged.values()].filter(project => !singleProjectMode || project.app_key === selectedApp);
    document.getElementById('portalTools').hidden = singleProjectMode || !hasConsoleSession;
    document.getElementById('portalTrashLink').hidden = session?.user?.role !== 'admin';
    projects.forEach(project => { project.description = ''; projectRow(project); });
    if (singleProjectMode && projects.length) {
      document.title = `${projects[0].project_name} | ${SiteLanguage.t('コミュニティマップメーカー')}`;
      document.getElementById("breadcrumbProjectName").textContent = projects[0].project_name;
    }
    status.hidden = true;
    render();
    if (singleProjectMode && projects[0]) loadJoinAction(projects[0]);
    projects.filter(project => safeUrl(project.frontend_url)).forEach(loadPreview);
  } catch {
    status.textContent = SiteLanguage.t('プロジェクトを読み込めませんでした。ページを再読み込みしてお試しください。');
  }
}

window.addEventListener('site-session-change', event => {
  if (!event.detail.signedIn && hasConsoleSession) {
    hasConsoleSession = false; projects = []; grid.replaceChildren();
    document.getElementById('portalTools').hidden = true;
    loadProjects();
  }
});
window.addEventListener('pageshow', event => { if (event.persisted) loadProjects(); });
loadProjects();
