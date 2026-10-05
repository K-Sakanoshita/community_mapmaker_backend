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

1. 新規DBには`schema.sql`を適用します。既存DBは未適用の`migrations/001_activity_core.sql`から`migrations/010_project_creator.sql`までを番号順に適用します。
2. `config/config.example.php`をWeb公開ディレクトリ外へコピーし、DB接続・許可Origin・メール送信者・確認URL・パスワード再設定URLを設定します。
3. `CMM_AUTH_CONFIG`に実設定ファイルの絶対パス、`CMM_RATE_LIMIT_SECRET`に32文字以上のランダムな秘密値を設定します。
4. 静的Projectは`activity.apps`に登録します。新規Projectは管理画面からDBへ作成できます。

```sh
# 秘密値の生成
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'

# 既存ユーザーに最初のadmin権限を付与（実設定を読み込めるCLI環境）
php scripts/grant-admin-role.php USERID_OR_EMAIL
```

ローカル確認用の`config/config.php`はGit管理対象外で、Apacheからの直接アクセスも拒否します。本番では公開ディレクトリ外に配置してください。

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

[API一覧](docs/api-reference.md#エンドポイント一覧)から、Activity、Project、ユーザー管理、認証の各仕様へ移動できます。

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

# ローカル環境の起動・migration適用後。一時テーブルを使用。
docker exec community-mapmaker-backend-test-web-1 php /app/tests/ActivityRepositoryTest.php

# PHP・JavaScriptの構文確認
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check admin/admin.js
```

Activityテストは可変項目・Schema検証・ID生成・CRUD・論理削除・新着取得・座標・候補OSM ID・import・batchのロールバックを確認します。Projectと認証のテストは権限境界、ユーザー招待、最後の管理者の保護、メール確認、パスワード再設定、Rate Limit、監査ログを確認します。

## 構成と実装状況

| ディレクトリ／ファイル | 内容 |
| --- | --- |
| `api/` / `auth/` | Activity・Project・ユーザー管理／認証API |
| `admin/` / `en/admin/` | 日本語／英語の管理画面 |
| `config/` / `lib/` | 設定例／DB・認証・メールなどの実装 |
| `migrations/` / `schema.sql` | 既存DB用DDL／新規DB用DDL |
| `tests/` / `scripts/` | 振る舞いテスト／開発・運用スクリプト |
| `bootstrap.php` | 設定とサービスの初期化 |

Activity、Project/Schema管理、JSON/CSV入出力、認証・ユーザー管理・監査ログは実装済みです。MariaDBとの結合確認は実施済みです。実メール配送環境との結合確認、匿名投稿・投稿Rate Limit、各フロントエンドのActivityStore切替・登録／ログイン統合は未完了です。

## ライセンス

[MIT License](LICENSE)
