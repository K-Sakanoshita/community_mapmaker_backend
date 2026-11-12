# SPAのサーバーレンダリング支援

本機能は、SPAの初期HTMLにメタ情報（説明・OGP・canonical等）や本文を反映する支援機能です。バックエンドがページ情報をJSONで返し、Cloudflare Workersが配信元の`index.html`に反映します。初期HTMLに公園名・説明・OGP・canonical・本文を含め、地図操作は既存SPAが担当します。現在のAPI・Workers実装はOSMの公園・遊び場・レクリエーション広場に限定されており、説明文は公園向けの内容を使います。

## URLと設定

| 用途 | URL例 |
| --- | --- |
| 利用者の公園URL | `https://playgrounds.openacrossbase.net/parks/way/123456` |
| 元HTMLの取得先 | `https://＜実際のSPA配信元＞/index.html` |
| 公開ページ情報API | `https://armd-01.sakura.ne.jp/community-mapmaker-backend/api/park-page.php?app=playgrounds&osmid=way%2F123456` |

ドメイン・IDは構成例です。本番配置と対象公園の存在は配置後に確認します。ブラウザーへのリダイレクトは行いません。

既存DBに`migrations/013_project_page_rendering.sql`を適用してください。`projects.page_rendering_json`（NULL可のJSON）を追加し、既存Projectはページ生成無効として扱います。新規DBは`schema.sql`に含まれます。

管理画面のプロジェクト設定で、フロントエンドURL・ポータル公開を設定し、「SPAのサーバーレンダリング支援」を開きます。新規Projectは作成後に設定します。担当`project_admin`または`admin`が更新できます。APIでは既存の`PUT api/projects.php?app=playgrounds`に次を送ります。

```json
{
  "frontend_url": "https://playgrounds.openacrossbase.net/",
  "frontend_public": true,
  "page_rendering": {
    "enabled": true,
    "path_template": "/parks/{osm_type}/{osm_id}",
    "title_template": "{name}の遊具・公園情報 | {project_name}",
    "default_description": "遊具や利用者からの公園情報を地図で確認できます。",
    "default_image_url": null
  }
}
```

`page_rendering`省略時や部分更新時は、既存の未指定項目を保持します。初期値は`enabled=false`、上記のパス、`title_template="{name} | {project_name}"`、空の説明、画像なしです。無効化は`{"page_rendering":{"enabled":false}}`です。Activity Schemaのインポート・エクスポートには含めません。

パスはルート相対で`{osm_type}`・`{osm_id}`を各1回含めます。`/map/`配下なら`/map/parks/{osm_type}/{osm_id}`とします。有効な公開Projectの`frontend_url`にはクエリ・フラグメントを指定できません。タイトルは`{name}`必須、`{project_name}`任意です。説明は1000文字まで、画像は資格情報・フラグメントを含まないHTTP(S)絶対URLです。

静的Projectでは`activity.apps.<app_key>.page_rendering`に同じ設定を指定できます。管理画面で保存すると既存の移行規則に従いDB管理になります。

## OSM取得とキャッシュ

PHP cURLを使用します。`config/config.php`の`activity`内に必要に応じて設定します。取得先はサーバー設定で固定し、公開APIから変更できません。

```php
'park_lookup' => [
    'endpoint' => 'https://overpass-api.de/api/interpreter',
    'cache_ttl' => 86400,
    'cache_dir' => '/private/writable/osm-park-cache',
],
```

未指定時は既定OverpassとPHPの一時ディレクトリを使います。キャッシュはWeb公開外の書き込み可能な場所に置いてください。

OSM基本情報は既定24時間、存在しない／対象外の結果は5分、取得失敗は30秒キャッシュします。同じIDの同時取得は抑制し、更新中の別要求は503を返します。公園名やOSM上の削除は期限まで反映が遅れます。即時更新が必要なら、該当OSM IDのSHA-256をファイル名とするキャッシュJSONを運用側で削除します。投稿件数・Project設定は毎回確認するため、公開停止は次の要求から反映します。APIと合成HTMLは`Cache-Control: no-store`です。

## 公開ページAPI

`GET api/park-page.php?app=playgrounds&osmid=way%2F123456`。認証不要です。`app`・`osmid`は必須文字列、OSM IDは`node/ID`・`way/ID`・`relation/ID`（先頭ゼロなしの正整数、最大19桁）です。

有効・未削除・公開URL設定済み・`frontend_public=true`・`page_rendering.enabled=true`のProjectだけが対象です。OSMの`leisure=park`・`playground`・`recreation_ground`を取得し、投稿0件でも公園情報を返します。名前は`name:ja`、`name`の順、未設定なら「名前のない公園／遊び場」です。nodeは自身の位置、way・relationはOverpassの中心座標、住所はOSMに記載された範囲だけを使います。公園内の個別遊具の空間集計は行わず、未確認の遊具を説明に追加しません。

応答項目：`app_key`, `osmid`, `name`, `title`, `description`, `canonical_url`, `image_url`, `latitude`, `longitude`, `address`, `activity_count`, `activity_updated_at`, `osm_updated_at`, `fetched_at`, `source_url`, `attribution`。

説明は公園名を含む定型文と共通説明を組み合わせます。投稿は同一Project・同一OSM IDの未削除分だけを数えます。投稿本文、アカウント情報、OSM編集者は返しません。

| 状態 | HTTP | code |
| --- | --- | --- |
| 正常 | 200 | — |
| Project非公開・削除済み・無効・ページ設定無効 | 404 | `project_not_found` |
| 公園なし・対象タグではない | 404 | `park_not_found` |
| 入力不正 | 422 | `validation_failed` |
| GET以外 | 405 | `method_not_allowed` |
| 外部取得失敗・不完全な応答・キャッシュ更新中 | 503 | `park_source_unavailable` |

## Cloudflare側の設定概要

サンプルは[`park-pages.mjs`](../examples/cloudflare/park-pages.mjs)、設定例は[`wrangler.jsonc`](../examples/cloudflare/wrangler.jsonc)です。

1. 公開ホストをCloudflareのプロキシ対象にし、既存サイトへのアクセスを確認します。
2. Workersへサンプルを登録し、Routeを`playgrounds.openacrossbase.net/parks/*`に設定します。設定例の`zone_name`は実際のZoneへ合わせます。
3. 次の環境変数を設定します。サンプルの元HTML配信元は必ず置き換えます。

| 変数 | 設定例 |
| --- | --- |
| `APP_KEY` | `playgrounds` |
| `PUBLIC_BASE_URL` | `https://playgrounds.openacrossbase.net/`（末尾`/`必須） |
| `PAGE_PATH_TEMPLATE` | `/parks/{osm_type}/{osm_id}`（Project設定と一致させる） |
| `SPA_INDEX_URL` | 元HTMLの実際の配信元URL。Workerへ再入しない別オリジン |
| `PARK_PAGE_API_URL` | `https://armd-01.sakura.ne.jp/community-mapmaker-backend/api/park-page.php` |

4. APIと元HTMLがリダイレクトなしで200を返すURLを指定します。Cookie・Authorizationは取得先へ転送しません。Workersのサーバー間取得にCORS許可Originの追加は不要です。
5. Cache Rulesなどで`no-store`を上書きしないでください。配信元のCSPが公開ドメインからのアセット読み込みを許可することも確認します。

Workersはメタタグを1組に揃え、title・description・canonical・OGP・Twitterカードを設定します。画像未指定時は古いOGP画像を除去します。`<base>`を公開トップへ揃え、本文に公園概要とOSM帰属リンク、`script[type=application/json]#park-page-data`に初期情報を挿入します。通常利用者とクローラーへ同じHTMLを返します。404／503を維持し、共通トップHTMLの200で代用しません。

仕様根拠：[Workers Routes](https://developers.cloudflare.com/workers/configuration/routing/routes/)、[HTMLRewriter](https://developers.cloudflare.com/workers/runtime-apis/html-rewriter/)、[SPA shell例](https://developers.cloudflare.com/workers/examples/spa-shell/)。

## SPA側の接続と配置後の確認

Playgroundsなど別リポジトリのSPAは、この変更だけでは公園別URLに対応しません。パスか`#park-page-data`を読み取り、対象OSM IDの公園を開く処理と共有リンクを対応させます。SPA起動時にメタ情報をトップ用へ戻さず、概要本文を統合する場合は同じ情報を表示してから引き継ぎます。

`<base>`で相対アセットURLを公開トップへ解決しますが、動的import・Service Worker・CSPとの整合は実際のSPAで確認します。バックエンド配置、Workers配置、SPA接続、本番の検索・共有プレビュー確認は別工程です。配置後は実在するIDでAPI・HTMLソース・SPA起動後の対象公園・404／503を確認してください。

## ローカル検証

```sh
php tests/ParkPageTest.php
php tests/EnglishAdminTest.php
node tests/ColumnTransferAdminTest.cjs

# Docker専用。一時テーブルと固有名のfixtureを使い、最後にfixtureを削除。
docker exec -e CMM_LOCAL_PARK_TEST=1 community-mapmaker-backend-test-web-1 php /app/tests/ParkPageHttpTest.php

# Workers互換ランタイム。依存は検証用ディレクトリのみに配置。
npm --prefix /tmp/cmm-ssr-tools install --no-audit --no-fund miniflare
CMM_MINIFLARE_MODULE=/tmp/cmm-ssr-tools/node_modules/miniflare/dist/src/index.js node tests/ParkPageWorkerTest.mjs
```

HTTP検証のOSM応答はキャッシュfixtureを使い、外部サービスに依存させません。
