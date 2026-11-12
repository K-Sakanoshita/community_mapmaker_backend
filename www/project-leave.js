'use strict';
(() => {
  const root = new URL('./', document.currentScript.src);
  const headers = { Accept: 'application/json', 'X-Console-Session': '1' };
  const en = document.documentElement.lang === 'en';
  const messages = {
    project_creator_required: en ? 'Project creators cannot leave their own project.' : '作成したプロジェクトの参加は解除できません。',
    last_project_administrator: en ? 'Add another active project administrator before leaving.' : '最後の管理者のため解除できません。別の有効な管理者を追加してください。',
    project_participation_banned: en ? 'You cannot remove a participation ban yourself.' : '参加禁止の設定は、この画面から解除できません。',
    authentication_required: en ? 'Sign in again to leave this project.' : 'もう一度ログインしてから参加を解除してください。',
  };

  function render({ appKey, membership, actions, info, onLeave }) {
    if (!actions.isConnected || !membership.authenticated || !membership.is_member) return;
    const note = document.createElement('p'); note.className = 'project-leave-status';
    note.setAttribute('role', 'status'); note.setAttribute('aria-live', 'polite');
    const button = document.createElement('button'); button.type = 'button'; button.className = 'project-leave-button';
    button.textContent = en ? 'Leave project' : '参加を解除'; button.disabled = membership.can_leave !== true;
    note.textContent = button.disabled
      ? messages[membership.leave_reason] || (en ? 'You cannot leave this project at this time.' : '現在、このプロジェクトの参加は解除できません。')
      : en ? 'Published contributions remain after leaving.' : '参加解除後も、投稿済みのデータは残ります。';
    button.addEventListener('click', async () => {
      button.disabled = true; note.textContent = en ? 'Leaving project…' : '参加を解除しています…';
      try {
        const response = await fetch(new URL('api/my-projects.php', root), { method: 'DELETE', headers: { ...headers, 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify({ app: appKey }) });
        const payload = await response.json();
        if (!actions.isConnected) return;
        if (!response.ok) {
          note.textContent = messages[payload.code] || (en ? 'Could not leave the project. Reload to check your membership.' : '参加を解除できませんでした。再読み込みして参加状況を確認してください。');
          if (response.status !== 409 && response.status !== 403 && response.status !== 401) button.disabled = false;
          return;
        }
        button.remove(); note.textContent = en ? 'You left the project. Published contributions remain.' : '参加を解除しました。投稿済みのデータは残ります。';
        try { await onLeave?.(); }
        catch { note.textContent += en ? ' Reload the page to update your access.' : '権限の表示を更新するため、ページを再読み込みしてください。'; }
      } catch {
        if (!actions.isConnected) return;
        note.textContent = en ? 'Could not confirm the result. Reload to check your membership.' : '解除結果を確認できませんでした。再読み込みして参加状況を確認してください。'; button.disabled = false;
      }
    });
    actions.prepend(button); info.append(note);
  }

  async function load(options) {
    try {
      const url = new URL('api/project-membership.php', root); url.searchParams.set('app', options.appKey);
      const response = await fetch(url, { headers, credentials: 'same-origin', cache: 'no-store' });
      if (response.ok) render({ ...options, membership: await response.json() });
    } catch { /* No action is offered without a verified membership. */ }
  }
  window.ProjectLeave = { load, render };
})();
