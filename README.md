# Community Mapmaker Backend

Community Map Makerで共通利用するPHPバックエンドです。Activityの保存・取得、ProjectとSchemaの管理、ユーザー登録・メール確認・パスワード再設定を提供します。

Activityは1投稿をDBの1レコードに保存し、サイト固有項目を`data_json`に保持します。APIは旧Google Spreadsheetとの互換性を考慮したフラットなオブジェクトを返します。

## ドキュメント

| 目的 | 参照先 |
| --- | --- |
| エンドポイント・引数・応答・認証条件を調べる | [APIリファレンス](docs/api-reference.md) |
| Docker、LAN/VPN、HTTPS、ローカルDBを使う | [ローカル開発環境](docs/local-development.md) |
| ポータル・管理画面・ユーザー登録を使う | [画面と管理操作](docs/admin-guide.md) |
| 本番へ配置する | [さくらサーバーへのデプロイ](SAKURA_DEPLOY.md) |

## 必要環境とセットアップ

PHP 8.1以上、PDO MySQL、MySQL 8.0以上または互換DB、`mail()`から送信可能なSendmail環境、HTTPSが必要です。

1. 新規DBには`schema.sql`を適用します。既存DBは未適用の`migrations/001_activity_core.sql`から`migrations/013_project_page_rendering.sql`までを番号順に適用します。
2. `config/config.example.php`をWeb公開ディレクトリ外へコピーし、DB接続・許可Origin・メール送信者・確認URL・パスワード再設定URLを設定します。
3. `CMM_AUTH_CONFIG`に実設定ファイルの絶対パス、`CMM_RATE_LIMIT_SECRET`に32文字以上のランダムな秘密値を設定します。
4. 静的Projectは`activity.apps`に登録します。新規Projectは管理画面からDBへ作成できます。

```sh
# 秘密値の生成
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'

# 既存ユーザーに最初のadmin権限を付与（実設定を読み込めるCLI環境）
php scripts/grant-admin-role.php USERID_OR_EMAIL
```

公開ファイルは `www/`、非公開のPHP処理・設定・SQL・運用ツールはリポジトリ直下に分けています。Webサーバーのドキュメントルートは必ず `www/` に設定してください。`config/config.php` はGit管理対象外です。

```text
community_mapmaker_backend/    # /home/ACCOUNT/ 配下の非公開リポジトリ
├── www/                      # 公開：画面、画像、CSS/JS、api/、auth/
├── bootstrap.php, lib/       # 非公開：PHP共通処理
├── config/, var/             # 非公開：設定・運用データ
└── scripts/, migrations/, schema.sql, tests/, docker/, docs/
```

さくらでは `sh scripts/deploy-sakura.sh "$HOME/www/community-mapmaker-backend"` で `www/` の中身を公開先へコピーします。詳しい構成と更新手順は[配置ガイド](SAKURA_DEPLOY.md)を参照してください。

### Activity設定

指定できる`app`は有効なDB Projectまたは`activity.apps`に登録されたアプリです。静的Projectを管理画面で初めて更新すると、同じ`app_key`でDB管理へ移行します。

```php
'activity' => [
    'max_payload_bytes' => 262144,
    'apps' => [
        'playgrounds' => [
            'id_prefix' => 'Playgrounds',
            'schema_file' => getenv('CMM_PLAYGROUNDS_ACTIVITY_SCHEMA') ?: __DIR__ . '/activity-schemas/playgrounds.json',
            'write_auth_required' => true,
        ],
    ],
],
```

本番では`CMM_PLAYGROUNDS_ACTIVITY_SCHEMA`にSchemaファイルの絶対パスを指定するか、`schema_file`を実配置に合わせます。Schemaは入力検証と管理画面の列・入力型を定義し、項目の追加にDBの`ALTER TABLE`は不要です。Schemaにない安全な独自項目も保持し、画面への表示は`admin.visible`で制御します。

### SPAのサーバーレンダリング支援とCloudflare

SPAの個別ページURLに対応する初期HTMLへ、メタ情報や本文を反映するための支援機能です。現在付属の公開ページ情報API `GET api/park-page.php?app=playgrounds&osmid=way%2F123456` とWorkersサンプルは公園向けの実装です。Cloudflare Workersが元の`index.html`とこのJSONを取得し、title・description・OGP・canonical・公園概要の本文を反映します。地図の操作は既存SPAが担当します。現在のAPIはOSMの公園・遊び場・レクリエーション広場を対象とし、説明文も公園向けのため、他の対象への適用には実装の拡張が必要です。

```text
https://playgrounds.openacrossbase.net/parks/way/123456
  → Cloudflare Workers
      ├─ SPA配信元 /index.html
      └─ バックエンド /api/park-page.php?app=playgrounds&osmid=way%2F123456
  → 公園情報を含むHTML（ブラウザーのURLは維持）
```

プロジェクト設定に`page_rendering`を追加します。`enabled`、`path_template`（既定`/parks/{osm_type}/{osm_id}`）、`title_template`、`default_description`、`default_image_url`を管理します。既存の`frontend_url`は利用者向けトップURL、`frontend_public`は公開可否として維持します。ページ設定はActivity Schemaとは別に保存し、有効・公開・ページ生成有効のProjectだけを公開APIの対象にします。

公園基本情報はOSMから取得してサーバーにキャッシュします。投稿の有無とは別に公園を解決するため、投稿が0件でも公園ページを生成できます。外部取得障害は503、存在しない／対象外の公園は404として区別します。公開JSONには投稿本文やアカウント情報を含めません。

Cloudflare側の設定概要：

1. 独自ドメインをCloudflareのプロキシ対象にし、公園ページのパスにWorkers Routeを設定します（例：`playgrounds.openacrossbase.net/parks/*`）。
2. Workersに公開トップURL、ルートのパステンプレート、元HTMLの配信元URL、公開ページAPI URL、Projectの`app_key`を設定します。元HTMLはWorkerへ再入しない配信元から取得します。
3. `HTMLRewriter`でメタ情報と公園概要を反映します。通常利用者とクローラーへ同じHTMLを返し、404／503を維持します。初期構成では合成HTMLを共有キャッシュせず、公開停止や設定更新の反映を優先します。
4. SPA側で公園別URLを読み取り、対象公園を開く処理と共有リンクを対応させます。ネストしたURLでもJS・CSSを取得できるよう、アセットURLを調整します。これは別フロントエンドの変更です。

設定例・Workersサンプル・動作確認手順は[サーバーレンダリング支援](docs/server-rendering.md)を参照してください。バックエンドの配置だけではWorkers設定とSPAのURL対応は反映されません。

### ローカルで起動する

```sh
./scripts/local-test-servers.sh start
./scripts/local-test-servers.sh status
./scripts/local-test-servers.sh test
./scripts/local-test-servers.sh stop
```

`start`は未適用migrationとテストユーザーを反映します。管理画面は`http://127.0.0.1:18080/admin/`、メール受信箱は`http://127.0.0.1:18025/`です。接続情報・証明書・ポート変更は[ローカル開発環境](docs/local-development.md)を参照してください。

## APIの使用例

最新30件の概要を取得します。引数の詳細は[Activity一覧](docs/api-reference.md#activity一覧)を参照してください。

```sh
curl --get 'http://127.0.0.1:18080/api/activities.php' \
  --data-urlencode 'app=playgrounds' \
  --data-urlencode 'limit=30' \
  --data-urlencode 'summary=1'
```

Activityの応答には、最終編集者のログイン用ユーザーIDを`updated_by_userid`として返します。通常取得・`summary=1`・CSVに共通で、編集者が未記録またはユーザーが削除済みの場合は`null`（CSVでは空欄）です。詳しくは[応答フィールド](docs/api-reference.md#activityの応答フィールド)を参照してください。

[API一覧](docs/api-reference.md#エンドポイント一覧)から、Activity、Project、ユーザー管理、認証の各仕様へ移動できます。

## ユーザー登録と参加プロジェクト

ユーザー登録画面は`register.html`です。URLの`app_key`引数で、参加プロジェクトを初期選択できます。

```text
https://armd-01.sakura.ne.jp/community-mapmaker-backend/register.html?app_key=playgrounds
```

この例では「遊具のある公園マップ」（`app_key=playgrounds`）を初期選択します。他のプロジェクトでは、`playgrounds`をそのプロジェクトの`app_key`に置き換えてください。

- 登録画面の「参加プロジェクト」で参加先を選択・変更できます。URL引数なしでも選択できます。
- 選択できるのは、DBに保存された有効・未削除・公開（`frontend_public=true`）かつメンバー募集中（`recruiting=true`）のプロジェクトです。
- 登録時に、選択したプロジェクトの`contributor`（投稿者）権限を付与します。メール確認後にアプリから投稿・編集できます。アカウントの初期権限は`user`（一般ユーザー）で、管理画面から自分のプロジェクトを作成・管理できます。参加先のプロジェクトを管理する権限は付与しません。
- 初期値の「後で決める・自分でつくる」を選ぶと、既存プロジェクトに参加せず登録します。メール確認後に自分のプロジェクトを作成できます。既存プロジェクトへの参加を後から希望する場合は、その管理者へ相談してください。
- 対象外の`app_key`を指定した場合は、画面で参加先を選び直してください。

登録画面は参加先名・登録目的・Open Across Baseの運営情報と問い合わせ先`info@openacrossbase.net`を表示します。利用元へ戻るリンクには、管理者が登録したProjectの`frontend_url`を使います。登録完了・確認メール・確認後の案内でも参加先を引き継ぎます。

[プライバシーポリシー](www/privacy.html)で、投稿内容・ユーザーIDの公開と退会後のデータ保持を説明しています。[アカウント削除](www/account.html)では、現在のパスワードと確認用ユーザーIDを入力して本人のアカウントを削除できます。全プロジェクトの参加権限を失いますが、投稿データとProjectは保持し、アカウントとの紐付けを解除します。最後の有効な管理者は別の有効な管理者を追加してから削除します。

登録APIを直接使う場合も、`POST auth/register.php`のJSONに任意の`app_key`を指定できます。詳しくは[登録・メール確認・パスワード再設定](docs/api-reference.md#登録メール確認パスワード再設定)を参照してください。

## セキュリティと運用

- 公開環境ではHTTPSを使用し、CORSの許可Originを設定します。管理画面にはWebサーバー側のアクセス制御も併用します。
- Activityの書き込みにはProject権限が必要です。匿名投稿用の投稿コンテキスト・Rate Limitが未実装のため、本番で`write_auth_required`を無効にしないでください。
- パスワードはハッシュのみ、メール確認・再設定トークンはSHA-256ハッシュのみを保存します。トークンは`random_bytes(32)`で生成します。
- ユーザーID・メールは小文字化したカラムで一意性を保証します。DBの条件値はPDOで扱います。
- 登録・確認メール再送・再設定要求にはRate Limitを適用します。識別値は秘密salt付きHMACで保存し、生IPはDBへ保存しません。
- 管理画面はHttpOnlyセッションCookieを使用します。レスポンス・監査ログへパスワードやトークンを含めません。
- `trust_proxy_headers`は信頼できるプロキシ配下でだけ有効にします。

本番では`auth.email_verification=true`とし、確認URLを公開HTTPSの`/verify-email.html`に設定します。実際の宛先へメールが届くことを確認してください。本文サイズの上限とエラー形式は[API共通仕様](docs/api-reference.md#共通仕様)にまとめています。

実運用DBは適用前に`SELECT VERSION();`で種類とバージョンを確認してください。DDLはネイティブ`JSON`型を使用し、ローカルのMariaDB 11.8でmigrationとPDO CRUDを確認しています。互換性に問題がある環境では、確認後に`LONGTEXT`と`JSON_VALID`を使うmigrationを別途検討します。

## テスト

```sh
php tests/AuthServiceTest.php
php tests/AdminUserServiceTest.php
php tests/ActivityServiceTest.php
php tests/ActivityApiTest.php
php tests/ProjectServiceTest.php
php tests/ProjectAccessServiceTest.php
php tests/CsvActivityImportTest.php
php tests/DeploymentLayoutTest.php

# ローカル環境の起動・migration適用後。一時テーブルを使用。
docker exec community-mapmaker-backend-test-web-1 php /app/tests/ActivityRepositoryTest.php

# ローカル専用。一時DBで削除と保持・ロールバックを検証し、最後にDBを破棄。
docker exec -e CMM_ISOLATED_ACCOUNT_TEST=1 community-mapmaker-backend-test-web-1 php /app/tests/AccountDeletionTest.php
# ローカルHTTP/Mailpitで登録・再送・確認・投稿・削除。固有名のfixtureだけを後始末。
docker exec -e CMM_LOCAL_REGISTRATION_FLOW=1 community-mapmaker-backend-test-web-1 php /app/tests/RegistrationFlowTest.php

# PHP・JavaScriptの構文確認
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check www/admin/admin.js
```

Activityテストは可変項目・Schema検証・ID生成・CRUD・論理削除・新着取得・座標・候補OSM ID・import・batchのロールバックを確認します。Projectと認証のテストは権限境界、ユーザー招待、最後の管理者の保護、メール確認、パスワード再設定、Rate Limit、監査ログを確認します。

## 構成と実装状況

| ディレクトリ／ファイル | 内容 |
| --- | --- |
| `www/api/` / `www/auth/` | Activity・Project・ユーザー管理／認証API |
| `www/admin/` / `www/en/admin/` | 日本語／英語の管理画面（共通ヘッダーから切り替え） |
| `config/` / `lib/` | 設定例／DB・認証・メールなどの実装 |
| `migrations/` / `schema.sql` | 既存DB用DDL／新規DB用DDL |
| `tests/` / `scripts/` | 振る舞いテスト／開発・運用スクリプト |
| `bootstrap.php` | 設定とサービスの初期化 |

Activity、Project/Schema管理、JSON/CSV入出力、認証・ユーザー管理・監査ログは実装済みです。MariaDBとの結合確認は実施済みです。実メール配送環境との結合確認、匿名投稿・投稿Rate Limit、各フロントエンドのActivityStore切替・登録／ログイン統合は未完了です。

## ライセンス

[MIT License](LICENSE)

Project一覧の「管理者」から、そのProjectの管理者を複数追加できます。詳細は[画面と管理操作](docs/admin-guide.md#プロジェクトの共同管理者)を参照してください。

表示言語は `?lang=ja` / `?lang=en`、`/ja/` / `/en/`、ブラウザの優先言語の順で判定します。画面移動でも言語指定を引き継ぎます。公開画面は `site-language.js` と `site-messages.js`、管理画面は既存の日英HTML・翻訳JSONを共有して利用します。詳細は [管理画面ガイド](docs/admin-guide.md#表示言語) を参照してください。
