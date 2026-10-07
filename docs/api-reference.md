# APIリファレンス

この作業ツリーの実装に対応する仕様です。URLはバックエンドの公開ルートからの相対パスです。

[README](../README.md) · [共通仕様](#共通仕様) · [Activity](#activity一覧) · [Project](#project管理) · [ユーザー管理](#ユーザー管理) · [認証](#登録メール確認パスワード再設定)

## エンドポイント一覧

| メソッド | パス | 用途 | 認証・権限 |
| --- | --- | --- | --- |
| GET | [`api/activities.php`](#activity一覧) | 一覧・単体取得・CSV export | 不要 |
| POST / PUT / DELETE | [`api/activities.php`](#activity追加更新削除) | 追加・更新・論理削除 | Project書き込み権限 ※ |
| POST | [`api/activities-batch.php`](#activity一括保存) | 一括追加・更新・削除 | Project書き込み権限 |
| POST | [`api/activity-import.php`](#activity-import) | JSON import | `admin` |
| POST | [`api/activity-import-csv.php`](#activity-import) | CSV import・Schema候補 | `admin` |
| GET | [`api/activity-schema.php`](#activity-schema) | アプリ一覧・Schema | 不要 |
| GET / POST / PUT / DELETE | [`api/projects.php`](#project管理) | Project管理 | 操作別（下表） |
| GET | [`api/public-projects.php`](#公開project) | 公開Project一覧 | 不要 |
| GET | [`api/project-preview.php`](#公開project) | 公開Projectのプレビュー | 不要 |
| GET | [`api/project-trash.php`](#削除済みproject) | 削除済み管理画面への転送（JSONではない） | 管理者セッション |
| GET / POST | [`api/project-trash-data.php`](#削除済みproject) | 削除済み一覧・復元・物理削除 | `admin` |
| GET / POST / DELETE | [`api/console-session.php`](#管理画面のセッション) | セッション復元・ログイン・ログアウト | 操作別（下表） |
| GET / POST / PUT | [`api/admin-users.php`](#ユーザー管理) | ユーザー管理 | `admin` |
| GET | [`api/admin-audit-logs.php`](#監査ログ) | 監査ログ一覧 | `admin` |
| POST | [`auth/register.php`](#登録メール確認パスワード再設定) | ユーザー登録 | 不要 |
| POST | [`auth/resend-verification.php`](#登録メール確認パスワード再設定) | 確認メール再送 | 不要 |
| GET | [`auth/verify.php`](#登録メール確認パスワード再設定) | メール確認 | トークン |
| POST | [`auth/request-password-reset.php`](#登録メール確認パスワード再設定) | パスワード再設定要求 | 不要 |
| POST | [`auth/reset-password.php`](#登録メール確認パスワード再設定) | 新しいパスワードの保存 | トークン |

※ 単体POSTのみ、アプリ設定の`write_auth_required=false`で認証を省略できます。本番では無効にしないでください。PUT・DELETE・batchは常にProject書き込み権限が必要です。

## 共通仕様

- POST・PUTの本文は`Content-Type: application/json`で送信します。通常の応答はJSON、Activityの`format=csv`はUTF-8 BOM付きCSVです。
- Activityの日時はUTCの`YYYY-MM-DD HH:MM:SS`文字列です。日時検索の入力形式は`updated_since`の仕様を参照してください。
- 別OriginからのアクセスはCORSの許可設定が必要です。OPTIONSは許可Originで204、不許可Originで403です。

### 認証と権限

| 名称 | 意味 |
| --- | --- |
| HTTP Basic | 有効なユーザーIDまたはメールアドレスとパスワード。外部クライアント向け |
| 管理画面セッション | 同一OriginのHttpOnly Cookie。管理画面のリクエストには`X-Console-Session: 1`を付ける |
| `admin` | 全体管理者。全Projectの管理・書き込み、ユーザー管理、importが可能 |
| `project_admin` | 割り当てProjectの設定・Schema変更とActivityの書き込みが可能 |
| `contributor` | アカウント権限では管理画面・Project作成を禁止。Project権限では割り当てProjectのActivity書き込みを許可し、管理画面の表は利用不可 |
| `editor` | 割り当てProjectのActivityの書き込みが可能 |
| `viewer` | 割り当てProjectの閲覧のみ |

公開登録のアカウント権限は常に`contributor`です。入力で`role`を指定しても昇格できません。アカウントの`contributor`は既存の`editor`割り当てがあっても管理画面セッションを作成できません。アプリのHTTP Basic認証によるActivity書き込みにはProjectの`contributor` / `editor` / `project_admin`割り当てが必要です。公開Activity参照APIの公開範囲は変わりません。

`pending`・`disabled`ユーザーは認証できません。Projectの削除は`admin`、または作成者本人かつ`project_admin`に限ります。割り当てのないユーザーは書き込みできません。

### 本文サイズの上限

| API | 上限（既定） |
| --- | --- |
| `auth/*` | 64 KiB |
| Activity単体・JSON import・Project書き込み | `activity.max_payload_bytes`（256 KiB） |
| Activity batch・CSV import | 上記設定値の10倍（2.5 MiB） |
| ユーザー管理 | 256 KiB |
| 削除済みProject操作 | 4 KiB |

### エラー

通常は`{"status":"error","code":"..."}`を返します。入力検証エラーには`errors`、内部エラーには`request_id`を付けます。

| HTTP | 代表的な`code` | 意味 |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_or_expired_token` | JSONまたはトークンが不正 |
| 401 | `authentication_required` | 認証が必要 |
| 403 | `project_access_denied`, `admin_required`, `origin_not_allowed`, `registration_disabled` | 権限・Origin・登録設定による拒否 |
| 404 | `activity_not_found`, `app_not_found`, `project_not_found`, `user_not_found` | 対象が存在しない／参照できない |
| 405 | `method_not_allowed` | メソッド非対応。`Allow`ヘッダーを返す |
| 409 | `activity_already_exists`, `project_already_exists`, `identity_already_registered`, `last_active_admin` | IDの重複・最後の管理者の保護 |
| 413 | `payload_too_large` | 本文サイズ超過 |
| 422 | `validation_failed` | 引数・入力値の検証エラー |
| 429 | `rate_limit_exceeded` | Rate Limit。`Retry-After: 60` |
| 500 | `server_error` | 内部エラー。詳細はサーバーログに記録 |

セッション用ヘッダーが必要な操作で欠ける場合は403と`{"code":"console_header_required"}`です。`X-Console-Session: 1`付きの401はBasic認証ダイアログを出さないよう`WWW-Authenticate`を省略します。

## Activity一覧

**`GET api/activities.php`** — 一覧はActivity配列、`id`指定は1件のオブジェクトを返します。

| 引数 | 必須 | 形式・既定値 | 動作 |
| --- | --- | --- | --- |
| `app` | ○ | 有効なアプリキー | 対象Project／静的アプリ |
| `id` | — | 投稿ID | 単体取得。URLでは`/`を`%2F`にエンコード |
| `osmid` | — | `node/123`, `way/123`, `relation/123` | 単一OSM IDで絞り込み |
| `osmids` | — | カンマ区切りまたは`osmids[]`配列、最大1,000種類 | 空要素・重複を除く。空指定は空配列 |
| `bbox` | — | `west,south,east,north` | 保存済み座標で絞り込み。境界を含む |
| `updated_since` | — | タイムゾーン付きRFC3339日時 | 指定日時以降の更新。境界を含む |
| `limit` | — | 整数1〜100。省略時は制限なし | 最大取得件数。不正値・100超は422 |
| `summary` | — | `0`（既定）または`1` | `1`で下記の概要フィールドのみ |
| `format` | — | `json`（既定）／`csv` | 出力形式 |

**組み合わせと順序**

- `osmids`指定時は`bbox`を無視します。`osmid`は`osmids`・`bbox`と併用できません。
- `updated_since`・`limit`・`summary`は一覧専用です。`id`との併用は422。OSM ID・BBOX絞り込み、CSVとは併用できます。
- 更新日時の降順、同時刻は内部IDの降順。削除済みActivityは除外します。
- 引数省略時は従来の全件・全フィールド取得です。最新30件の表示には`limit=30&summary=1`を指定します。更新を漏れなく全件取得するためのページ分割は未対応です。
- `mode`・`score_min`・`attributes`・`match_mode`・`recent_only`・`photo_only`・`detail_only`・`research_mode`・`page`・`per_page`は一覧で非対応（422）です。

**日時・BBOXの規則**

| 項目 | 規則 |
| --- | --- |
| 日時 | 例：`2026-10-05T10:00:00+09:00`、`2026-10-05T01:00:00Z`。小数秒は最大6桁。UTCに変換して比較 |
| URLエンコード | `+`は`%2B`にするか、curlの`--data-urlencode`を使用 |
| BBOX | 経度-180〜180、緯度-90〜90、`west < east`、`south < north`。日付変更線をまたぐ指定は非対応 |
| 未登録座標 | 緯度・経度が両方未登録のActivityは、BBOX指定時も互換性のため含める |

### Activityの応答フィールド

| フィールド | 型 | 内容 |
| --- | --- | --- |
| `id` | string | 投稿ID。DBの内部IDは返さない |
| `osmid` | string | OSM ID |
| `created_at`, `updated_at` | string | 作成・更新日時（UTC） |
| `updated_by_userid` | string / null | 最終編集者のログイン用ユーザーID（`users.userid`）。未記録・ユーザー削除済みはnull |
| `latitude`, `longitude` | number / null | 保存済み位置スナップショット。常に返す |
| `form_key` | string | 存在する場合のみ |
| 可変項目 | Schema・保存値による | `name`、`title`、`body`などを同じ階層へ展開 |

`summary=1`では`id`・`osmid`・日時・座標・`updated_by_userid`と、存在する場合の`form_key`・`name`だけを返します。表示名は保存された`name`です。DB内部ID・`data_json`・削除フラグは返しません。

CSVの通常列は`id,osmid,latitude,longitude,form_key,updated_by_userid`とSchemaの定義列です。`summary=1`のCSVは`id,osmid,created_at,updated_at,latitude,longitude,form_key,name,updated_by_userid`です。

```sh
curl --get 'http://127.0.0.1:18080/api/activities.php' \
  --data-urlencode 'app=playgrounds' \
  --data-urlencode 'updated_since=2026-10-05T10:00:00+09:00' \
  --data-urlencode 'limit=30' \
  --data-urlencode 'summary=1'
```

## Activity追加・更新・削除

| メソッド | パス・指定 | 成功時 |
| --- | --- | --- |
| POST | `api/activities.php`、本文に`app` | 201、保存したActivity |
| PUT | `api/activities.php?app=...&id=...` | 200、更新したActivity |
| DELETE | `api/activities.php?app=...&id=...` | 200、`{"status":"ok"}` |

POST・PUTは次の本文を送ります。PUTは部分更新ではなく、Schemaで必要な入力項目と`osmid`を含めて送ってください。

| フィールド | 規則 |
| --- | --- |
| `app` | 必須（クエリでの指定も可） |
| `id` | POSTは省略可。prefix・時刻・乱数で生成。PUTでは既存IDを変更不可 |
| `osmid` | 必須。`node/`・`way/`・`relation/`と正の数値ID |
| `form_key` | 任意 |
| `latitude`, `longitude` | 任意。変更する場合は必ず両方を指定（下表） |
| 可変項目 | 対象アプリのSchemaで検証。Schemaにない安全なキーも保持 |
| `created_at`, `updated_at`, `updated_by_userid`, `is_deleted`, `deleted_at` | サーバー管理項目。入力しても設定されない |

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

### 座標の保存規則

座標はクライアントが把握した位置のスナップショットです。OSMから自動取得・同期はしません。Schemaの入力フィールドとして定義する必要はありません。

| 入力 | 動作 |
| --- | --- |
| 緯度・経度の両方を省略 | 新規作成は両方null。更新・既存行importは現在値を保持 |
| 両方が有限数値（数値文字列も可） | 緯度-90〜90、経度-180〜180。小数点以下7桁で保存 |
| 両方が`null` | 座標を解除 |
| 片方のみ・片方だけnull・非数値・範囲外 | 422 |
| CSVで両座標列が空セル | 両方nullとして解除。座標列がない既存CSVは現在値を保持 |

### 論理削除とID

DELETEとbatchの`deletes`は本文・投稿者を保持し、内部の削除フラグ・削除日時・更新日時を設定します。削除済みは通常の取得、CSV、ユーザー投稿件数から除外します。

| 操作 | 削除済みIDへの動作 |
| --- | --- |
| 単体GET・PUT・DELETE | 404 `activity_not_found` |
| 同じアプリへのPOST | 409 `activity_already_exists` |
| JSON／CSV import | 更新対象として扱い、実行時に復元。dry-runでは更新件数に含める |

Activity単体の復元・物理削除APIは提供しません。既存DBはmigration `005`（論理削除）、`006`（座標）、`007`（BBOX索引）を適用してください。

## Activity一括保存

**`POST api/activities-batch.php`** — Project書き込み権限が必要です。最大1,000変更を1トランザクションで処理し、1件でも失敗すると全件ロールバックします。

| 本文 | 型・既定値 |
| --- | --- |
| `app` | 対象アプリキー（必須） |
| `creates` | 新規Activityオブジェクトの配列、既定`[]` |
| `updates` | ID付きActivityオブジェクトの配列、既定`[]` |
| `deletes` | 投稿IDの文字列配列、既定`[]` |

```json
{
  "app": "playgrounds",
  "creates": [{"osmid":"node/2","actdate":"2026-10-05","title":"追加"}],
  "updates": [{"id":"Playgrounds/0001","osmid":"way/1","actdate":"2026-10-05","title":"更新"}],
  "deletes": ["Playgrounds/0003"]
}
```

成功時は200、`status: "ok"`と`created`・`updated`（Activity配列）、`deleted`（ID配列）を返します。入力・座標・論理削除の規則は単体APIと共通です。

## Activity import

両APIとも`admin`専用です。最大5,000行。既定はdry-runで、`dry_run: false`のときだけDBへ反映します。`app`と投稿IDが一致する行は更新し、削除済みなら復元します。実行はトランザクションで処理します。

| メソッド・パス | 本文 | 200応答 |
| --- | --- | --- |
| POST `api/activity-import.php` | `app`, `rows`（Activity配列）, `dry_run`（既定true） | `status`, `valid`, `created`, `updated`, `dry_run` |
| POST `api/activity-import-csv.php` | `app`, `csv`（CSV文字列）, `dry_run`（既定true） | 上記＋`headers`, `schema_candidate`, `missing_fields`, `changed_fields`, `schema_changes`, `schema_update_fields`, `normalizations` |

```json
{
  "app": "playgrounds",
  "dry_run": true,
  "rows": [{"id":"Playgrounds/0001","osmid":"way/123","actdate":"2026-09-03"}]
}
```

投稿ID（`id`または`activity_key`）は必須です。既存行で`osmid`を省略すると保存値を保持します。新規行には妥当なOSM IDが必要です。importは既存の可変項目を保持して入力項目を上書きします。

CSVはUTF-8 BOM・引用セル内の改行に対応します。妥当な`YYYY/MM/DD`日付を`YYYY-MM-DD`へ正規化し、URL列の`File:...`等を`wikimedia`型の候補にします。`schema_candidate`を確認して管理画面でSchemaに反映してから実行します。実行時は保存済みSchemaで再検証します。座標列はSchema候補に含めません。

## Activity Schema

**`GET api/activity-schema.php`** — 認証不要。

| 引数 | 200応答 |
| --- | --- |
| なし | `{"apps":["playgrounds", ...]}` |
| `app=playgrounds` | `app`とSchema（`fields`など） |

Schemaの各フィールドは`label`、`type`、`required`、`order`、管理画面用の`admin.visible`・`admin.editable`・`admin.width`などを持ちます。Schemaから列を外しても保存済みのJSON値は削除されず、後の編集でも未定義値を保持します。

## Project管理

| メソッド・パス | 引数・本文 | 権限 | 成功応答 |
| --- | --- | --- | --- |
| GET `api/projects.php` | `app`省略 | 認証済みユーザー | 200、参照可能なProject配列 |
| GET `api/projects.php?app=...` | アプリキー | 割り当て済み／`admin` | 200、Projectオブジェクト |
| POST `api/projects.php` | 下表 | 認証済みユーザー | 201、作成したProject |
| PUT `api/projects.php?app=...` | 下表の変更項目 | 担当`project_admin`／`admin` | 200、更新したProject |
| DELETE `api/projects.php?app=...` | アプリキー | 作成者本人かつ`project_admin`／`admin` | 200、`{"status":"ok"}` |

| 本文フィールド | 作成 | 更新 |
| --- | --- | --- |
| `app_key` | 必須。小文字英数字で始まる1〜64文字、`_`・`-`使用可 | 変更不可。クエリ`app`省略時の対象指定にも使用 |
| `project_name` | 必須、最大255文字 | 指定時のみ変更 |
| `schema` | 省略時は`actdate`・`title`・`body`の定義 | 指定時に変更 |
| `frontend_url` | 任意のHTTP/HTTPS絶対URL | null／空文字で解除 |
| `frontend_public` | boolean、既定false | 公開指定にはURLが必要 |

応答には`app_key`・`project_name`・`schema`・`enabled`・フロントエンド設定などに加え、`access_role`・`activity_count`を返します。一般ユーザーが作成するとProjectの作成と`project_admin`割り当てを同一トランザクションで実行します。

削除は論理削除で、Project・Activity・ユーザー割り当てを保持します。通常のProject一覧・参照・Activity APIから除外し、削除済み`app_key`の再利用を禁止します。既存DBはmigration `008`（フロントエンド）、`009`（論理削除）、`010`（作成者）を適用します。既存Projectの作成者はnullのため、一般ユーザーからは削除できません。

## 公開Project

| メソッド・パス | 引数 | 200応答 |
| --- | --- | --- |
| GET `api/public-projects.php` | なし | 有効・未削除・公開指定・URL設定済みProjectの配列。各要素は`app_key`, `project_name`, `frontend_url` |
| GET `api/project-preview.php` | `app`必須 | `description`（文字列）, `image_url`（URLまたはnull） |

認証不要です。プレビューは公開Projectだけが対象（対象外は404）。結果を6時間キャッシュし、取得先を公開IPのHTTP/HTTPS標準ポートに制限します。取得失敗時は空の説明・nullの画像を返します。

## 削除済みProject

**`api/project-trash-data.php`** — `admin`専用。削除済みProjectだけを対象にします。

| メソッド | 引数・本文 | 200応答 |
| --- | --- | --- |
| GET | なし | 削除済みProject配列 |
| POST | `{"app_key":"...","action":"restore"}` | `{"status":"ok"}` |
| POST | `{"app_key":"...","action":"purge","confirm_app_key":"..."}` | `status: "ok"`, `deleted`（削除結果） |

`purge`は`confirm_app_key`の完全一致が必須です。Project・同じアプリのActivity・割り当てをトランザクションで物理削除します。復元・削除は監査ログへ記録します。

`GET api/project-trash.php`はJSON APIではなく、管理者のセッションを確認して`../admin/?view=trash`へ303で転送します。未認証は401、一般ユーザーは403です。

## 管理画面のセッション

| メソッド・パス | 認証・ヘッダー | 200応答 |
| --- | --- | --- |
| GET `api/console-session.php` | CookieまたはHTTP Basic | `user`（`id`, `userid`, `role`）, `projects`（参照可能なProject配列） |
| POST `api/console-session.php` | 有効な認証＋`X-Console-Session: 1`。通常はHTTP Basicでログイン | 上記応答＋セッションCookieを設定 |
| DELETE `api/console-session.php` | `X-Console-Session: 1` | `{"status":"ok"}`、セッションを破棄 |

CookieはHttpOnly・SameSite=Strict・有効期限なしです。通常はブラウザ終了で破棄されますが、ブラウザのセッション復元機能で復元される場合があります。確実に終了するにはログアウトしてください。24時間以上未使用のセッションは削除対象で、無効化・パスワード変更もセッションを無効にします。サーバーのセッション保存先には書き込み権限が必要です。詳細は[画面と管理操作](admin-guide.md)を参照してください。

## ユーザー管理

**`api/admin-users.php`** — 全操作`admin`専用。

| メソッド | 指定 | 成功応答 |
| --- | --- | --- |
| GET | `id`省略、下表の一覧引数 | 200、`items`と`pagination` |
| GET | `id=123` | 200、ユーザー詳細 |
| POST | `action`省略／`create` | 201、ユーザー詳細＋`creation_mode`・`password_setup_email_sent` |
| PUT | `id`をクエリまたは本文に指定 | 200、更新したユーザー詳細 |
| POST | `action`と対象`id` | 200、下表の操作結果 |

### 一覧のクエリ引数

| 引数 | 既定・制約 |
| --- | --- |
| `page` | 1。最小1に補正 |
| `per_page` | 25。1〜100に補正 |
| `search` | ユーザーID・メールの検索。最大255文字 |
| `status` | `pending` / `active` / `disabled` |
| `verified` | `yes` / `no` |
| `role` | `contributor` / `user` / `admin` |
| `project_id` | 正のProject内部ID |

`pagination`は`page`, `per_page`, `total`, `total_pages`です。詳細にはProject割り当て、Activity投稿件数・最近の投稿などを含みます。削除済みActivityは投稿件数と最近の投稿から除外します。

### 作成・更新・操作の本文

| 操作 | 本文フィールド | 動作 |
| --- | --- | --- |
| POST `create`（メールあり） | `userid`, `email`, 任意`role`, 任意`projects` | `pending`ユーザーを作り、パスワード設定メールで招待 |
| POST `create`（メールなし） | `userid`, `password`, `password_confirmation`, 任意`role`, 任意`projects` | メールなしの`active`ユーザーを作成 |
| PUT | `id`, 任意`status`, 任意`role`, 任意`projects` | 状態・全体role・Project割り当てを更新 |
| POST `add_email` | `id`, `email` | メール未登録ユーザーへ追加し、確認メールを送信 |
| POST `resend_verification` | `id` | 確認メール再送 |
| POST `send_password_reset` | `id` | 再設定メール送信 |
| POST `set_password` | `id`, `password`, `password_confirmation` | 状態を変えずパスワード再設定。`status`と`user`を返す |

`projects`は`[{"project_id":1,"role":"editor"}]`形式です。roleは`viewer` / `contributor` / `editor` / `project_admin`。PUTで省略すると現在値を保持し、空配列で割り当てを解除します。

状態・権限・メール・パスワード操作は監査ログへ記録します。最後の`active admin`は無効化・降格できません。初期パスワード・ハッシュ・トークンは応答にも監査ログにも含めません。

## 監査ログ

**`GET api/admin-audit-logs.php`** — `admin`専用。`page`は既定1・最小1、`per_page`は既定50・1〜100に補正します。

200応答は`items`と`pagination`。各ログには管理者・操作・対象・詳細・作成日時を含みます。

## 登録・メール確認・パスワード再設定

認証不要の公開APIです。メール確認・再設定は有効期限内かつ未使用のトークンが必要です。

| メソッド・パス | 必須入力 | 成功応答 |
| --- | --- | --- |
| GET `auth/registration-projects.php` | なし | 200、`projects`（登録対象の公開Projectの`app_key`, `project_name`） |
| POST `auth/register.php` | `userid`, `email`, `password`, `password_confirmation` | 201、`status: "ok"`, `verification_required`, `verification_email_sent` |
| POST `auth/resend-verification.php` | `identity`（ユーザーIDまたはメール） | 202、`status: "ok"`, `accepted`, `verification_email_sent` |
| GET `auth/verify.php` | クエリ`token` | 200、`{"status":"ok","email_verified":true}` |
| POST `auth/request-password-reset.php` | `email` | 202、`status: "ok"`, `accepted: true`, `message` |
| POST `auth/reset-password.php` | `token`, `password`, `password_confirmation` | 200、`{"status":"ok","password_reset":true}` |

```json
{
  "userid": "sakano",
  "email": "example@example.jp",
  "password": "long-password",
  "password_confirmation": "long-password"
}
```

- 登録時のroleは`contributor`（投稿者）。メール確認必須なら`pending`、任意なら`active`です。アプリから投稿・編集できますが、管理画面やスプレッドシートは利用できません。
- 任意の`app_key`を送ると、そのProjectへ`contributor`として自動参加します。対象は有効・未削除・`frontend_public: true`のProjectだけです。対象外の指定は422となりアカウントを作りません。アカウント作成と参加設定は同一トランザクションで行います。`app_key`を省略した場合は参加Projectなしで登録します。
- 登録画面のURLに`register.html?app_key=playgrounds`のようにProject識別子を指定すると参加先が初期選択されます。画面で参加先を変更でき、選択値が登録APIへ送られます。URL引数なしでも公開Projectを選択できます。アカウントやProjectのroleを登録リクエストで昇格することはできません。
- パスワードは既定8文字以上・最大4096バイト。`auth.password_min_length`で最小文字数を設定できます（下限8）。登録・管理者作成・再設定に共通です。
- 再設定要求はアカウントの存在有無にかかわらず同じ受付メッセージを返します。登録・確認メール再送・再設定要求にはRate Limitがあります。
- 公開登録成功時は、メールを持つ有効な管理者へ通知します。通知失敗は登録を取り消しません。通知にはパスワード・トークンを含めず、管理者招待・確認メール再送は通知対象外です。

画面のURLとメール設定は[画面と管理操作](admin-guide.md#ユーザー登録)を参照してください。
