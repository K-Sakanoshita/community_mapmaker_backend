# 外部アプリからの OSM ログインと投稿

Community Map Maker（以下 CMM）が OpenStreetMap のログインと利用者同意を処理し、外部アプリ向けの認可コードとアクセストークンを発行します。CMM のセッション Cookie は CMM の画面と同一オリジンの API で使います。

## 初期登録と管理

既存 DB には `migrations/018_external_apps.sql` を適用します。新規 DB は `schema.sql` に含まれます。初期登録は次の2件です。

| クライアント ID | アプリ | 許可オリジン | 戻り先 URL | プロジェクト | API |
| --- | --- | --- | --- | --- | --- |
| `machi-scrapbook` | まちのスクラップブック | `https://machi-scrapbook.openacrossbase.net` | `https://machi-scrapbook.openacrossbase.net/oauth/callback/` | `machi-scrapbook` | `activities-batch.php` の追加・更新・削除 |
| `playgrounds` | 遊具のある公園マップ | `https://playgrounds.openacrossbase.net` | `https://playgrounds.openacrossbase.net/oauth/callback/` | `playgrounds` | `activities.php` の追加・更新・削除 |

外部フロントエンドは、登録されたパスにコールバックを実装します。すでに使っているコールバックがある場合は、管理画面でその正確な HTTPS URL を登録してください。

1. CMM に管理者でログインし、マイページの「連携アプリ」を開きます。
2. 新規登録ではクライアント ID、アプリ名、HTTPS オリジン、戻り先 URL、対象プロジェクトキー、許可する操作を入力して保存します。オリジンに末尾スラッシュは不要です。戻り先 URL は同じオリジンに置き、`code`、`state`、`error` は返却用に予約します。
3. 変更は登録一覧の「変更」で、停止は「停止」で行います。変更・停止時には、既存の認可コードとアクセストークンが失効します。再開後は利用者がアプリとの連携をやり直します。
4. 利用者は同じ画面の「許可したアプリ」で、自分の連携を解除できます。そのアプリの認可コードとアクセストークンが失効します。

管理 API は `GET/POST/PUT/DELETE /api/admin-external-apps.php` です。作成・変更の JSON は `client_id`、`app_name`、`allowed_origin`、`redirect_uri`、`app_key`、`scopes`、`enabled` を受け取ります。停止は `DELETE ?client_id=...` です。本人の一覧・解除は `GET/DELETE /api/external-app-grants.php` です。どちらも CMM のセッション Cookie と `X-Console-Session: 1` が必要です。

## 外部アプリの実装

### 1. ログインを開始する

ブラウザーで推測困難な `state` と43〜128字の PKCE verifier を作り、`sessionStorage` などに今回の認証に限って保持します。verifier を SHA-256 でハッシュし、パディングなしの Base64URL に変換したものが `code_challenge` です。ブラウザーを次の URL に遷移させます。

```js
const cmm = "https://cmapmaker.openacrossbase.net";
const clientId = "machi-scrapbook";
const redirectUri = "https://machi-scrapbook.openacrossbase.net/oauth/callback/";
const hex = bytes => Array.from(bytes, value => value.toString(16).padStart(2, "0")).join("");
const verifier = hex(crypto.getRandomValues(new Uint8Array(32)));
const state = hex(crypto.getRandomValues(new Uint8Array(24)));
const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier));
const challenge = btoa(String.fromCharCode(...new Uint8Array(digest)))
  .replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/, "");
sessionStorage.setItem("cmm-authorization", JSON.stringify({ verifier, state }));
const authorize = new URL("/external-authorize.php", cmm);
authorize.search = new URLSearchParams({
  client_id: clientId, redirect_uri: redirectUri, response_type: "code",
  code_challenge: challenge, code_challenge_method: "S256", state
});
location.assign(authorize);
```

CMM では既存の OSM ログインを使用します。すでに有効な OSM ログインセッションがある場合は、アプリ名・ドメイン・プロジェクト・操作の同意画面へ進みます。利用者には、対象プロジェクトの投稿者・編集者・プロジェクト管理者のいずれかの参加権限が必要です。初めて利用する場合は、先に CMM アカウントを作成し、対象プロジェクトに参加します。

### 2. 認可コードを交換する

戻り先には `?state=…&code=…` が付きます。利用者が拒否した場合は `?state=…&error=access_denied` です。保存した `state` と完全一致を確認してから、コードを交換します。コードの有効期間は90秒で、一度だけ使えます。

```js
const callback = new URL(location.href);
const saved = JSON.parse(sessionStorage.getItem("cmm-authorization") || "null");
sessionStorage.removeItem("cmm-authorization");
if (!saved || callback.searchParams.get("state") !== saved.state) throw new Error("Invalid state");
if (callback.searchParams.has("error")) throw new Error(callback.searchParams.get("error"));
const code = callback.searchParams.get("code");
if (!code) throw new Error("Missing authorization code");
for (const key of ["code", "state"]) callback.searchParams.delete(key);
history.replaceState(null, "", callback);
const response = await fetch(cmm + "/api/external-token.php", {
  method: "POST", credentials: "omit",
  body: new URLSearchParams({
    grant_type: "authorization_code", client_id: clientId, redirect_uri: redirectUri,
    code, code_verifier: saved.verifier
  })
});
if (!response.ok) throw new Error("Token exchange failed");
const token = await response.json();
```

応答例

```json
{"access_token":"<token>","token_type":"Bearer","expires_in":900,"scope":"batch:create batch:update batch:delete"}
```

アクセストークンはアプリのメモリ内で管理し、`Authorization: Bearer <token>` ヘッダーで送ります。ブラウザーが付ける `Origin` は登録済みオリジンと一致する必要があります。期限後は認可からやり直します。

### 3. 公開用ユーザー ID を取得する

`GET https://cmapmaker.openacrossbase.net/api/external-me.php` に Bearer ヘッダーを送ります。

```json
{"userid":"osm-example-user","app_key":"machi-scrapbook","scopes":["batch:create","batch:update","batch:delete"]}
```

公開マップの URL や投稿検索には、この応答の `userid` を使います。

### 4. 投稿 API を呼ぶ

まちのスクラップブックは、`POST https://cmapmaker.openacrossbase.net/api/activities-batch.php` に JSON と Bearer ヘッダーを送ります。

```json
{"app":"machi-scrapbook","creates":[{"id":"machi-scrapbook/123","osmid":"node/456","title":"まちの記録"}],"updates":[],"deletes":[]}
```

成功は HTTP 200 です。応答の `created` と `updated` は Activity オブジェクトの配列、`deleted` は削除した投稿 ID の配列です。

```json
{
  "status": "ok",
  "created": [{"id":"machi-scrapbook/123","osmid":"node/456","title":"まちの記録","latitude":null,"longitude":null,"created_at":"2026-11-26 09:00:00","updated_at":"2026-11-26 09:00:00","updated_by_userid":"osm-example-user","updated_by_osm_display_name":"Example mapper"}],
  "updated": [],
  "deleted": []
}
```

公園マップは、`https://cmapmaker.openacrossbase.net/api/activities.php` を使います。

| 操作 | リクエスト例 | 成功応答 |
| --- | --- | --- |
| 追加 | `POST /api/activities.php?app=playgrounds` と JSON `{"osmid":"way/123","review":"遊具が充実","image":"https://example.net/park.jpg"}` | HTTP 201、作成した Activity |
| 更新 | `PUT /api/activities.php?app=playgrounds&id=playgrounds%2F123` と更新後の Activity JSON | HTTP 200、更新した Activity |
| 削除 | `DELETE /api/activities.php?app=playgrounds&id=playgrounds%2F123` | HTTP 200、`{"status":"ok"}` |

公園マップから追加する JavaScript の例です。更新や削除には、追加時に返る `id` を使ってください。

```js
const response = await fetch(cmm + "/api/activities.php?app=playgrounds", {
  method: "POST", credentials: "omit",
  headers: { "Content-Type": "application/json", Authorization: "Bearer " + token.access_token },
  body: JSON.stringify({ osmid: "way/123", review: "遊具が充実", image: "https://example.net/park.jpg" })
});
if (!response.ok) throw new Error("Activity creation failed");
const created = await response.json();
```

各投稿の固有項目は、対象プロジェクトで有効な Activity Schema に合わせてください。投稿者（`contributor`）の更新・削除は本人が作成した投稿に限ります。一括同期では1件でも拒否されると、そのリクエスト内の変更は一括で取り消されます。

| 拒否の例 | 応答 |
| --- | --- |
| 不明なクライアント、戻り先が不一致 | 認可画面で `invalid_client` または `invalid_redirect_uri`。戻り先へ遷移しません |
| PKCE が不一致、コードが期限切れまたは使用済み | HTTP 400 `invalid_grant` |
| 期限切れ・停止・連携解除済みのトークン | HTTP 401 `invalid_token`。登録オリジン自体が無効になったときは CORS でも拒否されます |
| 異なるプロジェクトまたは許可されていない操作 | HTTP 403 `insufficient_scope` |
| 参加権限がない、プロジェクトが無効 | HTTP 403 `project_access_denied` |
| 投稿者が他人の投稿を変更 | HTTP 403 `activity_owner_required` |

## ローカルでの検証

ローカル Docker の Web コンテナから `php /app/tests/ExternalAppsTest.php` を実行します。一時 DB 内で、認可コードの再利用、現在の参加権限、発行済みトークンの失効、所有者違反を含む一括同期のロールバックを検証します。
