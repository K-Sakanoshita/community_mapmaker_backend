'use strict';

const grid = document.getElementById('projectGrid');
const search = document.getElementById('projectSearch');
const count = document.getElementById('projectCount');
const status = document.getElementById('loadStatus');
const empty = document.getElementById('emptyState');
let projects = [];

function safeUrl(value) {
  try {
    const url = new URL(value);
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
  } catch { return null; }
}

function card(project) {
  const link = document.createElement('a');
  link.className = 'project-card';
  link.href = safeUrl(project.frontend_url);
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  const visual = document.createElement('div');
  visual.className = 'card-visual';
  const fallback = document.createElement('span');
  fallback.className = 'card-map-symbol';
  fallback.textContent = '⌖';
  visual.append(fallback);
  const body = document.createElement('div');
  body.className = 'card-body';
  const eyebrow = document.createElement('span');
  eyebrow.className = 'card-eyebrow';
  eyebrow.textContent = 'COMMUNITY PROJECT';
  const title = document.createElement('h3');
  title.textContent = project.project_name;
  const description = document.createElement('p');
  description.className = 'card-description';
  description.textContent = '地域の地図を見に行く';
  const footer = document.createElement('span');
  footer.className = 'card-footer';
  footer.textContent = '地図を見る ↗';
  body.append(eyebrow, title, description, footer);
  link.append(visual, body);
  project.element = link;
  project.descriptionElement = description;
  project.visualElement = visual;
  return link;
}

function render() {
  const query = search.value.trim().normalize('NFKC').toLocaleLowerCase('ja');
  const shown = projects.filter(project => `${project.project_name} ${project.description || ''}`.normalize('NFKC').toLocaleLowerCase('ja').includes(query));
  grid.replaceChildren(...shown.map(project => project.element));
  count.textContent = `${shown.length} PROJECT${shown.length === 1 ? '' : 'S'}`;
  empty.hidden = shown.length !== 0;
  empty.querySelector('h3').textContent = projects.length ? '該当するプロジェクトがありません' : '公開プロジェクトはまだありません';
  empty.querySelector('p').textContent = projects.length ? '検索語を変えて、もう一度お試しください。' : '公開された地図が増えるまで、少しお待ちください。';
}

async function loadPreview(project) {
  try {
    const response = await fetch(`api/project-preview.php?app=${encodeURIComponent(project.app_key)}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) return;
    const preview = await response.json();
    if (typeof preview.description === 'string' && preview.description) {
      project.description = preview.description;
      project.descriptionElement.textContent = preview.description;
    }
    const imageUrl = safeUrl(preview.image_url);
    if (imageUrl) {
      const image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      image.decoding = 'async';
      image.referrerPolicy = 'no-referrer';
      image.addEventListener('load', () => { project.visualElement.classList.add('has-image'); project.visualElement.replaceChildren(image); }, { once: true });
      image.addEventListener('error', () => image.remove(), { once: true });
      image.src = imageUrl;
      project.visualElement.append(image);
    }
    if (search.value) render();
  } catch { /* Project remains available when its preview cannot be loaded. */ }
}

async function loadProjects() {
  try {
    const response = await fetch('api/public-projects.php', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (!Array.isArray(data)) throw new Error('Invalid project list');
    projects = data.filter(item => item && typeof item.app_key === 'string' && typeof item.project_name === 'string' && safeUrl(item.frontend_url));
    projects.forEach(project => { project.description = ''; card(project); });
    status.hidden = true;
    render();
    projects.forEach(loadPreview);
  } catch {
    status.textContent = 'プロジェクトを読み込めませんでした。ページを再読み込みしてお試しください。';
  }
}

search.addEventListener('input', render);
document.addEventListener('keydown', event => {
  if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
    event.preventDefault(); search.focus();
  }
});
loadProjects();
