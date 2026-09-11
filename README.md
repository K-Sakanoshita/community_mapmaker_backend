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

1. MySQLにデータベースを作り、`schema.sql`を適用します。認証テーブル導入済みのDBには`migrations/001_activity_core.sql`、`migrations/002_projects_core.sql`、`migrations/003_admin_users.sql`、`migrations/004_optional_user_email.sql`、`migrations/005_activity_soft_delete.sql`の順で適用します。
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
GET api/activities.php?app=playgrounds&format=csv
```

`id`なしのGETとJSON exportはフラットなActivity配列を返し、`id`指定時は該当する1件のActivityオブジェクトを返します。CSVはSchemaに定義された列を出力します。

### Activity追加・更新・削除

```text
POST   api/activities.php
PUT    api/activities.php?app=playgrounds&id=Playgrounds%2F0001
DELETE api/activities.php?app=playgrounds&id=Playgrounds%2F0001
```

削除は論理削除です。単体DELETEと一括保存の`deletes`は、対象行を残したままDB内部の`is_deleted`を`1`にし、`deleted_at`と`updated_at`に削除日時（UTC）を記録します。本文・投稿者などの既存データは保持します。成功時は従来どおりHTTP 200と`{"status":"ok"}`（一括保存は従来の結果形式）を返します。

- 削除済みActivityは一覧、OSM ID絞り込み、CSV、検索集計、ユーザー管理APIの投稿件数・最近の投稿から除外します。検索の候補`osmids`に指定した場合は、未登録と同様に残っているActivityだけで評価します。
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

CSVは`POST api/activity-import-csv.php`へ`app`、`csv`、`dry_run`を送ります。`dry_run`の既定値は`true`です。改行入り引用セルとUTF-8 BOMに対応し、ヘッダーから型を推定した`schema_candidate`、未定義カラム、追加が必要な選択肢、型変更候補を返します。旧Spreadsheetの日付`YYYY/MM/DD`は妥当な日付に限り`YYYY-MM-DD`へ正規化し、URL列内の`File:...`等は`wikimedia`型の候補として扱います。管理画面ではこれらの候補をSchemaへ反映してからActivityを取り込み、実Import時は保存済みSchemaで再検証します。CSVには`id`（または`activity_key`）と`osmid`が必要です。

### Activity検索API

`GET api/activity-search.php?app=playgrounds`は、同一OSM IDの複数Activityを集約し、平均評価・よかった点・最終確認日・写真・詳細情報の条件で絞り込みます。地図の表示範囲（bbox）検索、公園と遊具の親子関係による集約、クライアントの未ロード時切替は含みません。

#### リクエスト

認証不要の読み取り専用APIです。レスポンスはJSONです。

| クエリパラメータ | 既定値 | 仕様 |
| --- | --- | --- |
| `app` | 必須 | 有効なProjectのキー。例: `playgrounds` |
| `score_min` | `0` | 平均評価の下限（0〜5、小数可） |
| `attributes` | 指定なし | よかった点のコードをカンマ区切りで指定。例: `act_good_points_1,act_good_points_2`。最大50種類 |
| `match_mode` | `and` | `and`: 全コードを含む、`or`: いずれかを含む |
| `recent_only` | `0` | `1`で最近確認されたものだけ |
| `photo_only` | `0` | `1`で写真情報があるものだけ |
| `detail_only` | `0` | `1`で詳細情報があるものだけ |
| `research_mode` | 空文字 | `missing` / `stale` / `photo` / `sparse`。下記参照 |
| `osmids` | 指定なし | 候補OSM IDをカンマ区切りで指定。例: `way/1,node/2`。最大1000種類 |
| `page` | `1` | ページ番号（1〜1000000） |
| `per_page` | `100` | 1ページの件数（1〜500） |

`attributes`と`osmids`は`attributes[]=...`などの配列形式も受け付け、空要素と重複を除きます。属性コードは英数字で始まる1〜64文字で、英数字と`_ . : -`が使用できます。OSM IDは`node/`、`way/`、`relation/`と、先頭ゼロのない1〜19桁の正整数の組み合わせです。

真偽値は`1/true/yes/on`と`0/false/no/off`（空文字もfalse）を受け付けます。通常の検索条件同士はANDで結合します。

`research_mode`の意味は次のとおりです。指定時は`score_min`、`attributes`、`recent_only`、`photo_only`、`detail_only`による絞り込みを行いません。ただし、これらの入力値の検証は行います。候補OSM IDとページ指定は引き続き適用します。

| 値 | 条件 |
| --- | --- |
| `missing` | 詳細情報なし |
| `stale` | 詳細情報があり、最近の確認なし |
| `photo` | 写真情報なし |
| `sparse` | 情報量が設定閾値未満（既定2） |

例: `api/activity-search.php?app=playgrounds&score_min=4&photo_only=1`。JSONの`items`にOSM ID別の集約結果、`pagination`に件数・ページ情報を返します。`per_page`は既定100・最大500、`osmids`は最大1000件です。未知のパラメータや不正値はHTTP 422になります。

`osmids`を省略した検索は、Activityが存在するOSM IDだけを対象にし、`coverage: "activities_only"`、`complete: false`を返します。表示範囲などの候補OSM IDを`osmids`で渡した場合は、Activity未登録の候補も空の集約結果として評価し、`coverage: "requested_osmids"`、`complete: true`を返します。DBは公園全件や座標を保持していないため、未ロード状態から「Activityが一件もない全公園」を検索するには、クライアントまたはOSM取得APIから候補OSM IDを渡す必要があります。`research_mode`指定時は他の検索条件より優先されます。

#### レスポンス

以下は`score_min=4&photo_only=1`に対する架空の応答例です。

```json
{
  "status": "ok",
  "items": [{
    "osmid": "way/100",
    "activity_count": 2,
    "score": 4,
    "attributes": ["act_good_points_1", "act_good_points_2"],
    "confirmed": "2026-09-07T00:00:00Z",
    "has_photo": true,
    "has_detail": true,
    "memo": "見守りやすい公園です。",
    "latest_activity_id": "Playgrounds/2",
    "is_recent": true,
    "information_count": 5
  }],
  "pagination": {"page": 1, "per_page": 100, "total": 1, "total_pages": 1},
  "criteria": {
    "score_min": 4,
    "attributes": [],
    "match_mode": "and",
    "recent_only": false,
    "photo_only": true,
    "detail_only": false,
    "research_mode": ""
  },
  "coverage": "activities_only",
  "complete": false,
  "candidate_count": null
}
```

| 項目 | 意味 |
| --- | --- |
| `items` | 条件に一致したOSM ID別の集約結果。Activity本体の配列ではありません |
| `pagination.total` | ページ分割前の一致OSM ID数。Activity件数ではありません |
| `pagination.total_pages` | 総ページ数。0件なら0。範囲外のページは`items: []` |
| `criteria` | 既定値を補完・正規化した検索条件（`app`、`osmids`、ページ指定は含まない） |
| `coverage` | `activities_only`または`requested_osmids` |
| `complete` | 指定された候補OSM ID全体を評価したか。全公園を網羅した意味でも、全ページを返した意味でもありません |
| `candidate_count` | 重複除去後の候補数。`osmids`省略時は`null` |

`osmids=`を明示して空にすると、候補0件として`items: []`、`candidate_count: 0`、`complete: true`になります。

#### 集約ルール

- `activity_count`: 同一OSM IDのActivity件数。
- `score`: 正の評価だけの平均を小数第1位に丸めます。評価なしは0。Playgrounds設定では`act_score_1`は0（平均対象外）、`act_score_6`は5です。
- `attributes`: 各Activityの`good_points`の和集合です。
- `confirmed` / `latest_activity_id`: 既定では`actdate`を優先し、同値なら`updatetime`、さらに同値ならActivity IDの文字列順で最新を選びます。確認日はUTCのISO 8601形式で、日付なしは空文字です。
- `is_recent`: 確認日が現在時刻から設定日数（既定365日）以内かを表します。
- `has_photo`: いずれかの`picture_urlN`に空でない値があるかです。画像の取得可否は検証しません。
- `memo`: DB取得順で最初の空でない本文です。必ずしも`latest_activity_id`の本文ではありません。
- `has_detail`: 正の評価、よかった点、写真、本文、詳細URLのいずれかがあるかです。
- `information_count`: 評価あり（1）＋よかった点の種類数＋写真あり（1）＋本文あり（1）＋詳細URLあり（1）。

結果は`confirmed`の降順、同値なら`osmid`の文字列昇順です。参照フィールドや評価コードのオフセット、最近とみなす日数、情報量の閾値はProjectごとの`search`設定に従います。

#### エラーと利用例

| HTTPステータス | `code` | 意味 |
| --- | --- | --- |
| 404 | `app_not_found` | Projectが存在しない、未指定、または無効 |
| 405 | `method_not_allowed` | GET以外の非対応メソッド |
| 422 | `validation_failed` | 不正値、件数上限超過、未知のパラメータ（`bbox`等） |
| 500 | `server_error` | サーバー内部エラー。照会用の`request_id`を返す |

```json
{"status":"error","code":"validation_failed","errors":{"score_min":"Score minimum must be between 0 and 5."}}
```

```sh
# 評価4以上で写真あり
curl --get 'http://192.168.1.6:18080/api/activity-search.php' \
  --data-urlencode 'app=playgrounds' \
  --data-urlencode 'score_min=4' \
  --data-urlencode 'photo_only=1'

# 候補のうち詳細情報がないもの（Activity未登録も含む）
curl --get 'http://192.168.1.6:18080/api/activity-search.php' \
  --data-urlencode 'app=playgrounds' \
  --data-urlencode 'osmids=way/100,node/200' \
  --data-urlencode 'research_mode=missing'
```

ブラウザから別オリジンで呼ぶ場合はサーバーのCORS許可設定が必要です。現実装はProjectのActivity全件を読み込んで集約するため、ページ分割や候補指定によってDB読み込み量が減るわけではありません。

## 管理コンソール

`admin/index.html`を開き、独立したログイン画面から有効なユーザーのHTTP Basic認証情報でログインします。認証成功後にだけコンソールを表示し、ログアウトするとパスワードと画面上の管理データを破棄してログイン画面へ戻ります。`admin`はProject一覧、カラム定義、Activity表、ユーザー管理を利用できます。一般ユーザーには割り当て済みProjectのActivity表だけを表示し、`editor` / `project_admin`は編集可能、`viewer`は閲覧のみです。Activity Schemaの`type`に応じて`text`、`textarea`、`number`、`date`、`select`、`checkbox`、`url`、`wikimedia`の編集欄を生成します。MIT LicenseのTabulator 6.5.2を採用し、CSS/JavaScriptはバージョンとSRIハッシュを固定してUNPKGから読み込みます。

- Projectの作成、表示名編集、有効・無効切替、削除（`app_key`は変更不可）
- Tabulatorによるカラムの追加、削除、ドラッグ順序変更、入力型・必須・表示・編集可否・幅のセル編集（選択肢は`select` / `checkbox`型のみ編集可能）
- `app_key`切替、全列検索、Tabulatorの列別絞り込み・並び替え・列幅変更
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
php tests/ActivitySearchServiceTest.php
php tests/ProjectServiceTest.php
php tests/ProjectAccessServiceTest.php
php tests/CsvActivityImportTest.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check admin/admin.js
```

Activityテストでは、JSON可変項目、Schema削除後の未知フィールド保持、アプリ分離、OSM ID絞り込み、追加・更新・削除、一括保存、投稿者記録、サーバー生成ID、Schema検証、import dry-run/upsert、Activity検索の集約・条件・候補OSM IDを確認します。Projectテストでは`app_key`の一意性・不変性、カラム順、選択肢順、Schema検証を、Project accessテストではadmin・editor・project_admin・viewer・未割り当ての境界を確認します。CSVテストではBOM、改行入りセル、必須ヘッダー、Schema候補を確認します。認証・ユーザー管理テストでは、登録、招待、role、最後のadmin保護、Project権限、監査ログ、平文パスワード非保存、確認前認証拒否、1回限りトークン、パスワード再設定、Rate Limitを確認します。

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
