# Community Mapmaker Backend

Community Map Makerで共通利用するPHPバックエンドです。Activity Core APIと管理画面、およびユーザー登録、メール確認、確認メール再送、パスワード再設定を提供します。

Activityは1件をDBの1レコードとして保存し、サイト固有項目は`data_json`へJSONオブジェクトとして保持します。APIでは旧Google Spreadsheetと互換性の高いフラットなActivityオブジェクトへ戻します。

## 構成

```text
auth/       認証APIのエンドポイント
api/        Activity APIのエンドポイント
admin/      Schema駆動の表形式Activity管理画面
config/     設定例とApacheのアクセス制御
lib/        Activity、認証、DB、メール、Rate Limitの実装
migrations/ 既存DBへ機能を追加するDDL
tests/      振る舞いテスト（RepositoryテストはDBを使用）
bootstrap.php
schema.sql
```

## 必要環境

- PHP 8.1以上
- PDO MySQL
- MySQL 8.0以上または互換DB
- `mail()` から送信可能なSendmail環境
- HTTPS

## セットアップ

1. MySQLにデータベースを作り、`schema.sql`を適用します。認証テーブル導入済みのDBには`migrations/001_activity_core.sql`、`migrations/002_projects_core.sql`、`migrations/003_admin_users.sql`、`migrations/004_optional_user_email.sql`、`migrations/005_activity_soft_delete.sql`、`migrations/006_activity_coordinates.sql`、`migrations/007_activity_bbox_index.sql`の順で適用します。
2. `config/config.example.php`を、Web公開ディレクトリ外の場所へコピーします。
3. DB接続、許可Origin、確認URL、再設定画面URL、送信者情報を設定します。
4. `CMM_AUTH_CONFIG`環境変数に実設定ファイルの絶対パスを指定します。
5. 既存の静的Projectを使う場合は`activity.apps`へ`app_key`、Activity Schema、Activity IDのprefixを設定します。新規Projectは管理画面からDBへ作成できます。
6. 32文字以上のランダムな`CMM_RATE_LIMIT_SECRET`を設定します。

既存ユーザーへ最初のadmin権限を付与する場合は、実設定を読み込めるCLI環境で次を実行します。平文パスワードは扱いません。

```sh
php scripts/grant-admin-role.php USERID_OR_EMAIL
```

秘密値の生成例:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

ローカル確認時のみ、`config/config.php`へ設定を置くこともできます。このファイルは`.gitignore`対象で、Apacheからの直接アクセスも`.htaccess`で拒否します。本番では公開ディレクトリ外を使用してください。

### Dockerローカルテスト環境

Docker Engine、Docker Compose v2、`curl`があれば、Web、HTTPS gateway、MariaDBをまとめて起動できます。Webもコンテナ内で動くため、起動した端末を閉じても片方だけ停止しません。

```sh
./scripts/local-test-servers.sh start
./scripts/local-test-servers.sh migrate
./scripts/local-test-servers.sh status
./scripts/local-test-servers.sh test
```

`start`はDBを先に起動し、未適用migrationとローカル管理ユーザーを反映してからWebを起動します。これにより、保持済みのDB volumeがある場合も現在のSchemaへ更新されます。

既定の接続先とローカル専用認証情報:

```text
管理画面  http://127.0.0.1:18080/admin/
LAN管理画面 http://LAN_IP:18080/admin/
VPN管理画面 http://TAILSCALE_IP:18080/admin/
LAN管理画面 https://LAN_IP:18443/admin/
MariaDB   127.0.0.1:13306
DB名      community_mapmaker
DBユーザー cmm / cmm_local_test
管理ユーザー localadmin / LocalTestPass!
編集ユーザー localeditor / LocalTestPass!
閲覧ユーザー localviewer / LocalTestPass!
未割当ユーザー localunassigned / LocalTestPass!
```

`start`はHTTP 18080番をlocalhost、ローカルホスト名のIPv4、検出したLAN IP、検出したTailscale IPで公開します。そのため、`battle1`がこのホスト自身で`127.0.1.1`へ解決される構成も含め、LAN/VPNの両方から同じHTTP APIへ接続できます。LAN IPv4はHTTPS証明書とHTTPS待受にも利用します。MariaDBはlocalhost限定のままです。検出結果を変更する場合は、起動時に明示します。

```sh
CMM_TEST_LAN_HOST=192.168.1.6 ./scripts/local-test-servers.sh start
```

例えば、名前解決できる環境では`http://battle1:18080/api/activities.php?app=playgrounds`、Tailscale経由では`http://TAILSCALE_IP:18080/api/activities.php?app=playgrounds`を利用できます。ローカルDocker設定のCORSには、localhost、LAN IP、Tailscale IP、ローカルホスト名、Tailscale DNS名が自動登録されます。本番用の`config/config.php`や`config/config.example.php`のOrigin制限は変更しません。

初回起動時にCaddyのローカル認証局が作成され、公開CA証明書が`docker/local/cmm-local-ca.crt`へ出力されます。このファイルをLAN内の接続端末へコピーし、信頼されたルート証明機関として登録してから`https://LAN_IP:18443/admin/`へ接続してください。FirefoxがOSとは別の証明書ストアを使う構成ではFirefoxにも登録が必要です。秘密鍵はDocker volume内に残り、exportされません。

このCAはローカルテスト専用です。信頼できるLAN内だけで使用し、インターネットへポート転送しないでください。CAを再出力する場合は次を実行します。

```sh
./scripts/local-test-servers.sh certificate
```

証明書を登録せずにブラウザ表示だけを確認する場合は`http://LAN_IP:18080/admin/`または`http://TAILSCALE_IP:18080/admin/`を利用できます。ただしHTTP Basic認証のID・パスワードは暗号化されないため、テスト専用アカウントと信頼できるLAN/VPNに限定してください。認証を含む本番相当の確認にはHTTPSを使用します。ホストのファイアウォールでTCP 18080番を許可する必要がある場合があります。通常、LAN/VPN内からの利用にルーター側のポート転送は不要です。

終了時は`./scripts/local-test-servers.sh stop`を実行します。DBデータとローカルCAはDocker volumeへ保持されます。DBを初期Schemaとテストユーザーだけの状態へ戻す場合は、破壊操作を明示する`./scripts/local-test-servers.sh reset --yes`を使います。ログは`logs`、`logs web`、`logs https-gateway`、`logs database`で確認できます。

ポートが使用中の場合は、起動前に変更できます。

```sh
CMM_TEST_WEB_PORT=18081 CMM_TEST_HTTPS_PORT=18444 CMM_TEST_DB_PORT=13307 ./scripts/local-test-servers.sh start
```

実運用DBでは適用前に`SELECT VERSION();`でMySQL/MariaDBの種類とバージョンを確認してください。現在のDDLはネイティブ`JSON`型を使い、ローカルのMariaDB 11.8でもmigrationとPDO CRUDの結合確認を行っています。実運用バージョンで`JSON`の互換性に問題がある場合は、環境確認後に`LONGTEXT`と`JSON_VALID`制約を用いるmigrationを別途作成します。

### Activity設定

`config/config.example.php`には`playgrounds`の静的設定例があります。APIで指定できるのは、有効なDB Projectまたは`activity.apps`に登録されたアプリです。静的Projectを管理画面で初めて更新すると、同じ`app_key`でDB管理へ移行します。

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

設定ファイルをリポジトリ外へ置く本番環境では、`CMM_PLAYGROUNDS_ACTIVITY_SCHEMA`にSchemaファイルの絶対パスを設定するか、`schema_file`を実配置に合わせて変更してください。

Activity SchemaはDBの物理スキーマではなく、入力検証と管理画面の列・入力型の定義です。項目を追加しても`activities`テーブルの`ALTER TABLE`は不要です。Schemaにない安全なキーもJSONへ保持されるため、旧データの移行で独自列を失いません。ただし管理画面へ表示する列はSchemaの`admin.visible`で制御します。

## API

すべてのPOST APIは`Content-Type: application/json`で送信します。レスポンスもJSONです。

### Activity一覧・絞り込み・export

```text
GET api/activities.php?app=playgrounds
GET api/activities.php?app=playgrounds&id=Playgrounds%2F0001
GET api/activities.php?app=playgrounds&osmid=way/123
GET api/activities.php?app=playgrounds&bbox=135,34,136,35
GET api/activities.php?app=playgrounds&osmids=way/1,node/2
GET api/activities.php?app=playgrounds&format=csv
```

通常のGETはフラットなActivity配列を返し、`id`指定時は該当する1件のActivityオブジェクトを返します。`bbox`指定時は範囲内のActivityと座標未登録のActivityの配列を、`osmids`指定時は候補OSM IDに紐づくActivity配列を返します。両方を指定した場合は`osmids`を優先し、`bbox`を無視します。CSVは共通列（`id`、`osmid`、`latitude`、`longitude`、`form_key`）とSchemaに定義された列を出力します。

### Activityの位置スナップショット

`latitude` / `longitude` はActivity本体の任意の共通メタデータで、Schemaのユーザー入力フィールドには定義しません。作成・更新時にクライアントが把握した位置を保存し、外部OSMサービスからの取得・同期は行いません。

- 緯度は-90〜90、経度は-180〜180の有限の数値（数値文字列も可）。小数点以下7桁に丸めて保存します。
- 両方を省略した作成は両方 `NULL`。更新・既存行のimportでは現在の座標を保持します。
- 変更時は必ずセットで指定します。両方を `null` にすると解除します。片方だけの指定、片方だけ `null`、非数値・範囲外は422エラーです。
- JSONレスポンスには両方を常に返します。保存済みなら数値、未保存なら `null` です。batchとJSON importも同じ扱いです。
- CSV import/exportも両列に対応します。両方の空セルは `null` として解除、両列がない既存CSVは従来どおり処理します。座標列はSchema候補に含めません。

既存DBではコード更新前に `migrations/006_activity_coordinates.sql` を一度適用してください。既存行は両方 `NULL` のままで、バックフィルは行いません。新規DBは `schema.sql` を使用します。

BBOX検索を使う既存DBには、座標列の追加後に `migrations/007_activity_bbox_index.sql` を一度適用してください。`app_key`、削除状態、経度、緯度の順の索引です。

### Activity追加・更新・削除

```text
POST   api/activities.php
PUT    api/activities.php?app=playgrounds&id=Playgrounds%2F0001
DELETE api/activities.php?app=playgrounds&id=Playgrounds%2F0001
```

削除は論理削除です。単体DELETEと一括保存の`deletes`は、対象行を残したままDB内部の`is_deleted`を`1`にし、`deleted_at`と`updated_at`に削除日時（UTC）を記録します。本文・投稿者などの既存データは保持します。成功時は従来どおりHTTP 200と`{"status":"ok"}`（一括保存は従来の結果形式）を返します。

- 削除済みActivityは一覧、OSM ID絞り込み、CSV、ユーザー管理APIの投稿件数・最近の投稿から除外します。`osmids`による一覧にも削除済みActivityは含まれません。
- 削除済みIDの個別GET・PUT・再DELETEはHTTP 404、`activity_not_found`です。
- 同じapp内では削除済みIDも予約され、同一IDでのPOST・import実行はHTTP 409、`activity_already_exists`です。importのdry-runは入力検証用のため、この衝突を検出せず新規件数に数える場合があります。実行時の衝突はトランザクション全体をロールバックします。
- 削除フラグ・削除日時は内部管理用で、通常のレスポンスには含めません。POST・PUT・importに`is_deleted` / `deleted_at`を渡しても無視します。復元・物理削除APIは提供しません。
- 認証・Project書き込み権限と一括保存の全件ロールバック仕様は従来どおりです。

既存DBはコード更新前に`migrations/005_activity_soft_delete.sql`を一度適用してください。既存行は未削除（`is_deleted=0`、`deleted_at=NULL`）になります。新規DBは更新済み`schema.sql`を使用します。

POST/PUTの例:

```json
{
  "app": "playgrounds",
  "id": "Playgrounds/0001",
  "form_key": "review",
  "osmid": "way/123",
  "actdate": "2026-09-03",
  "title": "遊具について",
  "score": "act_score_5",
  "genre": "独自項目もJSONへ保存"
}
```

`id`を省略したPOSTでは、アプリ設定のprefixと時刻・乱数から衝突しにくいIDをサーバー側で生成します。レスポンスにはDB内部IDや`data_json`を出さず、`id`、`osmid`、`form_key`、読み取り専用の`created_at` / `updated_at`と可変項目を同じ階層へ展開します。

初期状態では書き込みにHTTP Basic認証が必要で、既存の有効なユーザーIDまたはメールアドレスとパスワードを利用します。#1で匿名投稿用の投稿コンテキストとRate Limitを追加するまでは、公開環境で`write_auth_required`を無効にしないでください。

### Activity Schema

```text
GET api/activity-schema.php
GET api/activity-schema.php?app=playgrounds
```

引数なしでは許可されたアプリ一覧、`app`指定時は管理画面の列・入力型を含むSchemaを返します。

### Project管理

```text
GET    api/projects.php
GET    api/projects.php?app=playgrounds
POST   api/projects.php
PUT    api/projects.php?app=playgrounds
DELETE api/projects.php?app=playgrounds
```

全操作にHTTP Basic認証と`admin` roleが必要です。Projectは表示用の`project_name`と、作成後に変更できない`app_key`、`schema`、`enabled`を保持します。`app_key`は全Projectで一意です。Schemaの`fields`には`label`、`type`、`required`、`order`と、管理画面用の`admin.visible`、`admin.editable`、`admin.width`を保存します。Schemaからカラムを外しても既存Activityの`data_json`は削除されず、そのActivityを後で編集しても未定義JSON値を保持します。

### Activity一括保存

`POST api/activities-batch.php`へ、`app`と`creates`、`updates`、`deletes`の配列を送ります。`admin` roleが必要です。最大1,000変更までを1トランザクションで処理し、1件でも検証・保存に失敗した場合は全変更をロールバックします。

```json
{
  "app": "playgrounds",
  "creates": [{"id":"Playgrounds/0002","osmid":"node/2","title":"追加"}],
  "updates": [{"id":"Playgrounds/0001","osmid":"way/1","title":"更新"}],
  "deletes": ["Playgrounds/0003"]
}
```

### JSON import

`POST api/activity-import.php`へ次の形式を送ります。`admin` roleが必要です。

```json
{
  "app": "playgrounds",
  "dry_run": true,
  "rows": [
    {"id":"Playgrounds/0001","osmid":"way/123","actdate":"2026-09-03"}
  ]
}
```

最初に`dry_run: true`で検証と新規・更新件数を確認し、問題なければ`false`で取り込みます。`app_key + activity_key`が一致する行は更新されます。一度に取り込めるのは5,000件までで、DB反映はトランザクション内で行います。

CSVは`POST api/activity-import-csv.php`へ`app`、`csv`、`dry_run`を送ります。`dry_run`の既定値は`true`です。改行入り引用セルとUTF-8 BOMに対応し、ヘッダーから型を推定した`schema_candidate`、未定義カラム、追加が必要な選択肢、型変更候補を返します。旧Spreadsheetの日付`YYYY/MM/DD`は妥当な日付に限り`YYYY-MM-DD`へ正規化し、URL列内の`File:...`等は`wikimedia`型の候補として扱います。管理画面ではこれらの候補をSchemaへ反映してからActivityを取り込み、実Import時は保存済みSchemaで再検証します。CSVには`id`（または`activity_key`）が必須です。`osmid`列は任意で、既存Activityの行で省略した場合は保存済みのOSM IDが保持されますが、新規登録行は引き続き妥当な`osmid`が必要です。`latitude`／`longitude`列は任意で、空セルは`null`として座標を解除し、数値セルは数値として検証されます。座標列はSchema候補には含めません。

### Activity一覧の範囲指定

`GET api/activities.php?app=playgrounds&bbox=135,34,136,35`は、保存済み座標が範囲内にあるActivityを1件ずつJSON配列で返します。`bbox`は`west,south,east,north`（経度、緯度、経度、緯度）の順で、境界を含みます。経度は-180〜180、緯度は-90〜90、west < east、south < northが必要です。日付変更線をまたぐ指定はできません。緯度・経度が両方未登録のActivityは、互換性のためBBOXに関係なく常に含みます。

`osmids=way/1,node/2`は、指定したOSM IDに紐づくActivityだけを返します。`osmids[]=way/1`の配列形式も受け付け、空要素と重複を除きます。最大1000種類です。`osmids`を指定した場合は`bbox`を無視し、`osmids=`の空指定は空配列を返します。`osmid`（単数形）は従来どおり単一OSM IDで絞り、`osmids`や`bbox`と併用できません。

```sh
curl --get 'http://192.168.1.6:18080/api/activities.php' \
  --data-urlencode 'app=playgrounds' \
  --data-urlencode 'bbox=135,34,136,35'
```

`bbox`と`osmids`を指定した一覧も`format=csv`でCSVとして出力できます。別オリジンのブラウザから呼ぶ場合はサーバーのCORS許可設定が必要です。範囲指定時はSQLで対象のActivityを絞ってから取得します。

## 管理コンソール

`admin/index.html`を開き、独立したログイン画面から有効なユーザーのHTTP Basic認証情報でログインします。認証成功後にだけコンソールを表示し、ログアウトするとパスワードと画面上の管理データを破棄してログイン画面へ戻ります。`admin`はProject一覧、カラム定義、Activity表、ユーザー管理を利用できます。一般ユーザーには割り当て済みProjectのActivity表だけを表示し、`editor` / `project_admin`は編集可能、`viewer`は閲覧のみです。Activity Schemaの`type`に応じて`text`、`textarea`、`number`、`date`、`select`、`checkbox`、`url`、`wikimedia`の編集欄を生成します。MIT LicenseのTabulator 6.5.2を採用し、CSS/JavaScriptはバージョンとSRIハッシュを固定してUNPKGから読み込みます。

管理画面のフォーム、ボタン、表、カードなどはBootstrap 5.3.3を使用します。BootstrapのCSSはバージョンを固定してjsDelivrから読み込み、モーダル用のJavaScriptは`admin/vendor/bootstrap.bundle.min.js`を同一オリジンから読み込みます。表のスクロールやTabulator固有の表示などには`admin/admin.css`を使用します。操作結果やエラーはBootstrapの通知モーダルで表示しますが、ログイン成功時とProject・Activity・ユーザー一覧の通常の読み込み完了時には開きません。

- Projectの作成、表示名編集、有効・無効切替、削除（`app_key`は変更不可）
- Tabulatorによるカラムの追加、削除、ドラッグ順序変更、入力型・必須・表示・編集可否・幅のセル編集（選択肢は`select` / `checkbox`型のみ編集可能）
- `app_key`切替、全列検索、Tabulatorの列別絞り込み（空欄は`""`を入力。通常の検索語も`"公園"`のように引用符で囲めます）・並び替え・列幅変更
- 画面内の利用可能領域へ表を自動追従し、ページではなく表内のスクロールだけで全行・全列を移動
- セル範囲選択、Tab / Shift+Tab・矢印キー移動、複数セルのコピー&ペースト
- 行追加・削除を含むローカル変更の一括保存と、未保存セル・行の表示
- 未保存変更がある状態での画面移動・再読込・離脱警告
- 一括保存失敗時のDBロールバックと画面上の編集内容保持
- JSON/CSV export
- JSON importのdry-run確認、CSVヘッダーからのSchema候補確認とimport
- URLの`view` / `app`による表示状態復元
- ユーザー一覧、検索、status・メール確認・role・Projectによる絞り込みとpagination
- ユーザー詳細、`active` / `disabled`切替、`user` / `admin`変更、Project role設定
- メールによるユーザー招待、または管理者が初期パスワードを設定するメールなしユーザー追加
- 確認メール再送、パスワード再設定メール送信（メール登録ユーザーのみ）
- ユーザー詳細画面からの管理者による直接パスワード再設定（状態・role・Project権限の保存とは独立）
- Activity投稿数・最近の投稿表示（作成者・最終更新者をActivityへ記録）

管理画面は認証APIと同じオリジンへ配置する想定です。一般公開せず、Webサーバー側のアクセス制御も併用してください。

管理画面のログイン成功後は、HttpOnly・SameSite=StrictのセッションCookie（有効期限なし）で認証を維持します。再読み込みや同じオリジンの別タブでも復元し、平文パスワードをlocalStorage/sessionStorageには保存しません。通常はブラウザ終了でCookieが破棄されますが、ブラウザのセッション復元機能で復元される場合があるため、確実に終了する場合はログアウトしてください。サーバーのセッション保存領域は書き込み可能にする必要があり、24時間以上未使用のセッションは削除対象になります。アカウント無効化・パスワード変更でも既存セッションを無効化します。

`api/console-session.php`はGETで復元、POSTでログイン（HTTP Basic）、DELETEでログアウトを行います。管理画面のリクエストには`X-Console-Session: 1`が必要で、このヘッダーはクロスオリジンの許可対象には含めません。従来の外部クライアント向けHTTP Basic認証は引き続き利用できます。

ログイン時は`GET api/console-session.php`がユーザー情報と利用可能なProjectだけを返します。一般ユーザーによるActivityの追加・更新・削除・一括保存は、画面表示だけでなくAPI側でも`user_projects`の割り当てを検証します。未割り当てProjectと`viewer`からの書き込みはHTTP 403で拒否します。Project作成・Schema変更・ユーザー管理・importは引き続き`admin`専用です。

### ユーザー管理API

```text
GET  api/admin-users.php
GET  api/admin-users.php?id=123
POST api/admin-users.php
PUT  api/admin-users.php?id=123
GET  api/admin-audit-logs.php
```

一覧は`page`、`per_page`、`search`、`status`、`verified=yes|no`、`role`、`project_id`で絞り込めます。`POST`は管理者専用です。`email`を指定すると`pending`ユーザーを作成し、サーバーが生成した仮パスワードのハッシュを保存して本人へパスワード設定メールを送ります。`email`を省略または空欄にする場合は`password`と`password_confirmation`が必須で、メールを持たない`active`ユーザーを作成します。`action: set_password`と対象`id`、`password`、`password_confirmation`を送ると、ユーザーの状態を変えずに管理者がWeb画面からパスワードを再設定できます。初期パスワード、password hash、tokenはレスポンスや監査ログへ含めません。

状態・権限変更、メール再送、パスワード再設定メール送信、Project権限変更は`admin_audit_logs`へ記録します。最後のactive adminは無効化・降格できません。

### ユーザー登録

`POST auth/register.php`

```json
{
  "userid": "sakano",
  "email": "example@example.jp",
  "password": "long-password",
  "password_confirmation": "long-password"
}
```

メール確認が必須なら`pending`、任意なら`active`で作成します。パスワードは既定で8文字以上（最大4096バイト）です。登録・管理者による作成・再設定で共通の制約を適用し、`password_hash()`の結果だけを保存します。`auth.password_min_length`で最小文字数を変更できます（下限8）。

### 確認メール再送

`POST auth/resend-verification.php`

```json
{"identity":"sakano"}
```

`identity`にはユーザーIDまたはメールアドレスを指定できます。

### メール確認

`GET auth/verify.php?token=...`

有効な1回限りのトークンならユーザーを`active`へ変更します。DBにはトークンのSHA-256ハッシュだけを保存します。

### パスワード再設定要求

`POST auth/request-password-reset.php`

```json
{"email":"example@example.jp"}
```

アカウントの存在有無にかかわらず、同じ受付メッセージを返します。

### 新しいパスワードの保存

`POST auth/reset-password.php`

```json
{
  "token": "メールURLのトークン",
  "password": "new-long-password",
  "password_confirmation": "new-long-password"
}
```

有効期限切れ、使用済み、不正なトークンは拒否します。

## セキュリティ上の境界

- ユーザーIDとメールアドレスは小文字化した別カラムに一意制約を設定
- SQLはPDO prepared statementのみ使用
- メール確認・再設定トークンは`random_bytes(32)`で生成し、DBにはハッシュのみ保存
- 登録、再送、再設定要求をクライアント・メール・ユーザーID単位でRate Limit
- Rate Limitの識別値は秘密salt付きHMACで保存し、生IPをDBへ保存しない
- CORSは設定したOriginだけを許可（ローカルDocker検証環境では検出したLAN/VPN Originを自動登録）
- API payloadは64 KiBまで
- 内部例外はリクエストIDだけを返し、詳細はサーバーログへ記録
- `pending`および`disabled`ユーザーは`AuthService::authenticate()`で認証不可
- Project・Schema・ユーザー管理・import APIはUI表示だけでなくサーバー側でも`role=admin`を必須化
- Activity書き込みAPIは一般ユーザーのProject割り当てと`editor` / `project_admin` roleを検証
- 管理画面は明示的なAuthorizationヘッダーを使用し、セッションCookieへ認証情報を保存しない
- 管理APIレスポンスと監査ログへpassword hash、平文パスワード、tokenを含めない

プロキシ配下で`X-Forwarded-For`を使う場合だけ`trust_proxy_headers`を有効にします。信頼できないクライアントからこのヘッダーを直接受け取る構成では有効にしないでください。

## テスト

実MariaDBでの論理削除テスト（ローカル環境の起動・migration適用後）:

```sh
docker exec community-mapmaker-backend-test-web-1 php /app/tests/ActivityRepositoryTest.php
```

一時テーブルを使用し、行・本文の保持、アプリ分離、取得・更新拒否、ID再利用拒否、検索・ユーザー管理からの除外、一括保存ロールバックを検証します。


```sh
php tests/AuthServiceTest.php
php tests/AdminUserServiceTest.php
php tests/ActivityServiceTest.php
php tests/ProjectServiceTest.php
php tests/ProjectAccessServiceTest.php
php tests/CsvActivityImportTest.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check admin/admin.js
```

Activityテストでは、JSON可変項目、Schema削除後の未知フィールド保持、アプリ分離、OSM ID絞り込み、追加・更新・削除、一括保存、投稿者記録、サーバー生成ID、Schema検証、import dry-run/upsert、BBOX・候補OSM IDによるActivity一覧を確認します。Projectテストでは`app_key`の一意性・不変性、カラム順、選択肢順、Schema検証を、Project accessテストではadmin・editor・project_admin・viewer・未割り当ての境界を確認します。CSVテストではBOM、改行入りセル、必須ヘッダー、座標列、Schema候補を確認します。認証・ユーザー管理テストでは、登録、招待、role、最後のadmin保護、Project権限、監査ログ、平文パスワード非保存、確認前認証拒否、1回限りトークン、パスワード再設定、Rate Limitを確認します。

## 現在の実装範囲

- Activity Core DB・Repository・API: 実装済み
- Project CRUD・Activity Schema編集・表形式管理画面・一括保存: 実装済み
- JSON/CSV export・JSON/CSV import・CSV Schema候補: 実装済み
- 認証APIとDBスキーマ: 実装済み
- admin/user role、ユーザー管理API・画面、Project権限構造、Activity投稿者、監査ログ: 実装済み
- MariaDBでのMigration・ユーザー管理・権限制御・Activity投稿者の結合確認: 実施済み
- 実メール配送環境との結合確認: 未実施
- 匿名投稿・投稿Rate Limit: #1で未実装
- Google/PHP ActivityStore切替: #1のフロントエンド側で未実装
- フロントエンドの登録・ログイン画面: 別リポジトリで今後実装

## ライセンス

MIT Licenseです。詳細は`LICENSE`を参照してください。
