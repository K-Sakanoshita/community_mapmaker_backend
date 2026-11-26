'use strict';

// Once the callback document has loaded, this same-origin request can use the
// Strict console cookie even though the browser arrived from OpenStreetMap.
const params = new URLSearchParams(location.search);
history.replaceState(null, '', location.pathname);
const statusText = document.getElementById('osmStatus');
const returnLink = document.getElementById('osmReturn');
const messages = {
  checking: ['OpenStreetMap の認証を確認しています…', 'Checking OpenStreetMap authorization…'],
  back: ['ログイン画面へ戻る', 'Return to sign-in'],
  mypage: ['マイページへ戻る', 'Return to My Page'],
  osm_authorization_cancelled: ['OpenStreetMap の認証がキャンセルされたか、完了しませんでした。', 'OpenStreetMap authorization was cancelled or incomplete.'],
  osm_account_unavailable: ['この OSM アカウントでログインできません。ID・パスワードでログインし、マイページで連携を確認してください。', 'This OSM account cannot sign in. Sign in with your Community Map Maker credentials and check the connection on My Page.'],
  osm_link_conflict: ['このアカウントは連携できませんでした。どちらかのアカウントがすでに連携されています。', 'The accounts could not be linked. One of them is already linked.'],
  invalid_osm_state: ['認証の有効期限が切れたか、すでに使用されています。もう一度お試しください。', 'The authorization request expired or was already used. Please try again.'],
  osm_session_changed: ['ログイン状態が変わりました。もう一度お試しください。', 'Your sign-in session changed. Please try again.'],
  failed: ['OpenStreetMap の認証を完了できませんでした。もう一度お試しください。', 'Could not complete OpenStreetMap authorization. Please try again.'],
};
let lang = navigator.language.toLowerCase().startsWith('ja') ? 'ja' : 'en';
const message = key => (messages[key] || messages.failed)[lang === 'en' ? 1 : 0];
document.documentElement.lang = lang;
statusText.textContent = message('checking');
returnLink.textContent = message('back');

async function complete() {
  try {
    const response = await fetch('osm-auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Console-Session': '1' },
      body: JSON.stringify({ action: 'complete', state: params.get('state'), code: params.get('code') }),
    });
    const result = await response.json();
    if (['ja', 'en'].includes(result.lang)) lang = result.lang;
    document.documentElement.lang = lang;
    const mypage = result.link || (response.ok && result.view === 'mypage');
    const target = new URL(mypage ? '../mypage.html' : lang === 'en' ? '../en/admin/' : '../admin/', location.href);
    target.searchParams.set('lang', lang);
    if (!mypage && result.view && result.view !== 'mypage') target.searchParams.set('view', result.view);
    if (!mypage && result.app) target.searchParams.set('app', result.app);
    returnLink.href = target;
    returnLink.textContent = message(mypage ? 'mypage' : 'back');
    if (response.ok && result.status === 'ok') {
      location.replace(target);
      return;
    }
    statusText.textContent = message(result.code);
  } catch {
    statusText.textContent = message('failed');
  }
}

complete();
