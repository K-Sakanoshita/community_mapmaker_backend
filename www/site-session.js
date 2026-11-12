"use strict";

(() => {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const login = header.querySelector('#headerLoginLink');
  const publicListUrl = new URL('./#projects', document.currentScript.src);
  const registration = header.querySelector('#headerRegistrationLink');
  const session = header.querySelector('#headerSession');
  const allUsers = document.createElement('a');
  allUsers.id = 'headerAllUsersLink'; allUsers.hidden = true;
  allUsers.textContent = document.documentElement.lang === 'en' ? 'All Users' : '全ユーザー管理';
  allUsers.href = new URL(document.documentElement.lang === 'en' ? 'en/admin/?view=all-users' : 'admin/?view=all-users', document.currentScript.src).href;
  const myPage = document.createElement('a');
  myPage.id = 'headerMyPageLink'; myPage.hidden = true;
  myPage.textContent = document.documentElement.lang === 'en' ? 'My Page' : 'マイページ';
  myPage.href = new URL('mypage.html', document.currentScript.src).href;
  if (location.pathname === new URL(myPage.href).pathname) myPage.setAttribute('aria-current', 'page');
  session.before(myPage, allUsers);
  const username = header.querySelector('#currentAdmin');
  username.href = myPage.href;
  const logout = header.querySelector('#logoutButton');
  const api = new URL('api/console-session.php', document.currentScript.src);
  const headers = { Accept: 'application/json', 'X-Console-Session': '1' };

  function render(user) {
    const signedIn = typeof user?.userid === 'string' && user.userid !== '';
    login.hidden = signedIn;
    myPage.hidden = !signedIn;
    allUsers.hidden = !signedIn || user.role !== 'admin';
    document.querySelectorAll('[data-project-list-link]').forEach(link => {
      link.href = publicListUrl.href;
    });
    registration.hidden = signedIn;
    session.hidden = !signedIn;
    username.textContent = signedIn ? user.userid : '';
    username.title = signedIn ? user.userid : '';
    header.dataset.signedIn = String(signedIn);
    window.dispatchEvent(new CustomEvent("site-session-change", { detail: { signedIn } }));
  }

  window.SiteSessionHeader = { render };
  // The console restores its own session and retains its unsaved-change guard.
  if (header.dataset.sessionManaged === 'admin') return;

  async function refresh() {
    try {
      const url = new URL(api); url.searchParams.set('summary', '1');
      const response = await fetch(url, { headers, credentials: 'same-origin', cache: 'no-store' });
      render(response.ok ? (await response.json()).user : null);
    } catch { render(null); }
  }

  logout.addEventListener('click', async () => {
    logout.disabled = true;
    try {
      const response = await fetch(api, { method: 'DELETE', headers, credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('logout_failed');
      render(null);
    } catch {
      alert(document.documentElement.lang === 'en'
        ? 'Could not sign out. Please try again.' : 'ログアウトできませんでした。もう一度お試しください。');
    } finally { logout.disabled = false; }
  });
  refresh();
  window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
})();
