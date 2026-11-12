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
| GET | [`api/park-page.php`](server-rendering.md#公開ページapi) | SPAサーバーレンダリング用の公開情報（現実装は公園向け） | 不要（公開・ページ生成有効Projectのみ） |
| GET / POST | [`api/project-membership.php`](#プロジェクトへの自己参加) | 参加状態確認・本人の参加 | POSTはログイン必須 |
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
- 同一Origin（スキーム・ホスト・ポートが一致）からのアクセスは自動で許可します。別OriginからのアクセスはCORSの許可設定が必要です。OPTIONSは許可Originで204、不許可Originで403です。

### 認証と権限

| 名称 | 意味 |
| --- | --- |
| HTTP Basic | 有効なユーザーIDまたはメールアドレスとパスワード。外部クライアント向け |
| 管理画面セッション | 同一OriginのHttpOnly Cookie。管理画面のリクエストには`X-Console-Session: 1`を付ける |
| `admin` | 全体管理者。全Projectの管理・書き込み、ユーザー管理、importが可能 |
| `project_admin` | 割り当てProjectの設定・Schema変更とActivityの書き込みが可能 |
| `user` | 通常アカウント。管理画面へのログイン・Project作成が可能。参加先の操作はProject権限で制限 |
| `contributor` | Project権限の投稿者。割り当てProjectのActivity書き込みが可能。管理画面の表は利用不可 |
| `editor` | 割り当てProjectのActivityの書き込みが可能 |
| `viewer` | 割り当てProjectの閲覧のみ |

公開登録のアカウント権限は常に`user`です。メール確認後に管理画面へログインし、自分のProjectを作成できます。作成者にはそのProjectの`project_admin`を付与します。入力で`role`を指定しても全体管理者には昇格できません。既存アカウントの権限は自動変更しません。アカウント権限は`user` / `admin`のみです。`contributor`はProject権限としてのみ利用します。アプリのHTTP Basic認証によるActivity書き込みにはProjectの`contributor` / `editor` / `project_admin`割り当てが必要です。公開Activity参照APIの公開範囲は変わりません。

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
| `batch` | — | `1` | JSONを最大100件ずつ分割取得。`limit`省略時100件 |
| `cursor` | — | 分割応答の`next_cursor` | `batch=1`で次の分を取得 |

**組み合わせと順序**

- `osmids`指定時は`bbox`を無視します。`osmid`は`osmids`・`bbox`と併用できません。
- `updated_since`・`limit`・`summary`は一覧専用です。`id`との併用は422。OSM ID・BBOX絞り込み、CSVとは併用できます。
- 更新日時の降順、同時刻は内部IDの降順。削除済みActivityは除外します。
- 引数省略時は従来の全件・全フィールド取得です。最新30件の表示には`limit=30&summary=1`を指定します。分割取得は`batch=1`を指定します。
- `mode`・`score_min`・`attributes`・`match_mode`・`recent_only`・`photo_only`・`detail_only`・`research_mode`・`page`・`per_page`は一覧で非対応（422）です。

**分割取得**

既存DBでは`migrations/011_activity_cursor_index.sql`を適用すると、Project・削除状態・内部IDによる範囲取得にインデックスを使えます。新規DBの`schema.sql`には含まれます。

`GET api/activities.php?app=playgrounds&batch=1&limit=100`は`{"items": [...], "next_cursor": "123"}`を返します。次の要求に`&cursor=123`を付け、`next_cursor`が`null`になるまで続けます。通常取得の配列形式は変わりません。

分割時は更新日時による並び替えを行わず、変更されない内部IDの降順で取得します。既存行の更新によって取得位置がずれることはありません。初回取得後に追加された投稿は次回の再読み込みで取得します。削除済み行は除外します。取得全体のデータ内容を同一時点に固定するものではありません。

`batch=1`は`app`・`limit`・`cursor`・`format=json`と使用し、`id`・OSM絞り込み・BBOX・`updated_since`・`summary`・CSVとは併用できません。カーソルは正の整数、`limit`は1〜100で、不正な組み合わせは422です。

管理画面は100件ずつ追加表示します。途中の検索は読み込み済みのデータが対象で、一括保存・取り込み・行追加は全件取得完了後に利用できます。取得失敗時は部分的な表示と再読み込みボタンを残し、一括保存・取り込みを禁止します。

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

CSVはUTF-8 BOM・引用セル内の改行に対応します。妥当な`YYYY/MM/DD`日付を`YYYY-MM-DD`へ正規化し、URL列の`File:...`等の画像参照は`image`型の候補になります。`schema_candidate`を確認して管理画面でSchemaに反映してから実行します。実行時は保存済みSchemaで再検証します。座標列はSchema候補に含めません。

### 画像サムネイルAPI

`GET api/thumbnail.php?app=APP&id=ACTIVITY_ID&field=image` は、登録済みActivityの画像URLから軽量なサムネイルを生成し、**画像バイナリ**を返します。`<img src="...">`に直接指定できます。読み取り範囲はActivity GETと同じで、認証不要です。毎回Project・Activityの存在を確認するため、削除後は生成済みキャッシュも取得できません。

| パラメーター | 内容 |
|---|---|
| `app` | 必須。Projectキー |
| `id` | 必須。Activity ID（`/`などはURLエンコード） |
| `field` | `image`型のフィールド名。省略時は`image` |
| `index` | 値がURL配列の場合の位置。0始まり、既定0、最大999 |

登録前のURLには `POST api/thumbnail.php` を使用します。Basic認証または管理画面セッションと、指定Projectへの書き込み権限が必要です。匿名投稿を許可しているProjectでも認証を要求します。元データへの登録・変更は行いません。

```json
{"app":"APP","url":"https://example.org/photo.jpg"}
```

POSTも200で画像バイナリを返します。例えば次のように保存できます。

```sh
curl --user USERID --json '{"app":"APP","url":"https://example.org/photo.jpg"}' \
  https://api.example.jp/api/thumbnail.php --output thumbnail.webp
```

- 長辺400px以内、縦横比を保持し、拡大しません。品質80のWebPで保存し、GDのWebP非対応時はJPEG品質80（元JPEG）または透過PNGへ切り替えます。応答の`Content-Type`で形式を判断してください。元画像によっては容量が減らない場合もあります。
- EXIF拡張が有効な場合はJPEGの向き補正を行います。再エンコード時にEXIF等のメタデータを除去します。透過は保持し、GIF等のアニメーションは静止画になります。
- 入力はHTTP/HTTPSの直接画像URLのみ。JPEG・PNG・GIF・WebP、最大8MiB／800万画素。利用可能メモリが不足する場合も拒否します。`File:...`、ファイル名だけの参照、SVG、HEIC等は対象外です。
- 公開IPv4のポート80／443のみへ接続し、接続IPを固定します。内部IP、資格情報、フラグメントは禁止。リダイレクトは最大3回で毎回取得先を検査し、通信時間はDNS解決を除き合計12秒以内です。元サーバが取得を拒否する画像には対応できません。
- キャッシュは元URLと変換条件で識別し、既定24時間・最大1000件。失敗は60秒保持し、期限切れ・古いファイルは次回生成時に削除します。同じ画像の重複生成を防ぎ、別画像は同じキャッシュ保存先につき最大2件まで並列生成します。生成済みキャッシュの配信はこの制限に含めません。
- キャッシュミス時は接続元IPごとに30回／60秒に制限します。キャッシュヒットはこの制限を消費しません。リバースプロキシ経由ではバックエンドに見える接続元IP単位になります。
- `ETag`と条件付きGET（304）に対応。ブラウザーには`Cache-Control: private, no-cache`を返し、削除状態を毎回再確認します。

エラーは既存APIと同じJSON形式です。422は入力不正、401／403はPOSTの認証・権限不足、404はProject・Activity・画像なし、429は生成回数超過、502（`image_unavailable`）は取得・デコード失敗やサイズ・接続先制限、503（`thumbnail_busy`、`Retry-After: 2`）は同じ画像を生成中、または2件の生成枠が使用中、500はGD未導入や保存領域の問題等です。

設定は`activity.thumbnail`の`cache_dir`、`cache_ttl`（60〜604800秒）、`max_entries`（1〜10000）。キャッシュ保存先はWeb公開領域の外に置き、PHP実行ユーザーの書き込みを許可してください。未指定時はシステム一時ディレクトリの`cmm-thumbnails-v1`を使用します。原本の保存・Activityへの自動的なURL追加・一括事前生成は行いません。

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
| `recruiting` | boolean、既定false | 公開・有効なProjectへの自己参加を受け付ける |
| `page_rendering` | object、省略時はページ生成無効 | 指定した項目だけを更新。`enabled`, `path_template`, `title_template`, `default_description`, `default_image_url`。詳細は[サーバーレンダリング支援](server-rendering.md) |

応答には`app_key`・`project_name`・`schema`・`enabled`・フロントエンド設定などに加え、`access_role`・`activity_count`を返します。一般ユーザーが作成するとProjectの作成と`project_admin`割り当てを同一トランザクションで実行します。

削除は論理削除で、Project・Activity・ユーザー割り当てを保持します。通常のProject一覧・参照・Activity APIから除外し、削除済み`app_key`の再利用を禁止します。既存DBはmigration `008`（フロントエンド）、`009`（論理削除）、`010`（作成者）を適用します。既存Projectの作成者はnullのため、一般ユーザーからは削除できません。

## 公開Project

| メソッド・パス | 引数 | 200応答 |
| --- | --- | --- |
| GET `api/public-projects.php` | `app`任意（1件に絞り込み） | 有効・未削除・公開指定・URL設定済みProjectの配列。各要素は`app_key`, `project_name`, `frontend_url`, `recruiting`, `activity_count`, `contributors`（投稿作成者・最終更新者のユーザーID一覧、重複なし）。削除済み投稿は集計対象外 |
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
| GET `api/console-session.php?summary=1` | 同上 | `user`のみ。共通ヘッダーの状態確認用で、Project取得・投稿件数集計は行いません |
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
| `role` | `user` / `admin` |
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
| POST `restore` | `id`, `confirm_userid` | 削除済みユーザーのみ復活。削除前の状態（履歴なしはメール未確認ならpending、それ以外active）へ戻し、`user_restored: true`と`restored_status`を返す |
| POST `purge` | `id`, `confirm_userid` | 削除済みユーザーのアカウント・参加記録・認証情報を完全削除。`user_purged: true`。投稿・プロジェクトは保持し、ユーザー参照を解除 |


`projects`は`[{"project_id":1,"role":"editor"}]`形式です。roleは`viewer` / `contributor` / `editor` / `project_admin`。PUTで省略すると現在値を保持し、空配列で割り当てを解除します。

状態・権限・メール・パスワード操作は監査ログへ記録します。最後の`active admin`は無効化・降格できません。初期パスワード・ハッシュ・トークンは応答にも監査ログにも含めません。

## 監査ログ

**`GET api/admin-audit-logs.php`** — `admin`専用。`page`は既定1・最小1、`per_page`は既定50・1〜100に補正します。

200応答は`items`と`pagination`。各ログには管理者・操作・対象・詳細・作成日時を含みます。

## 登録・メール確認・パスワード再設定

認証不要の公開APIです。メール確認・再設定は有効期限内かつ未使用のトークンが必要です。

| メソッド・パス | 必須入力 | 成功応答 |
| --- | --- | --- |
| GET `auth/registration-projects.php` | なし | 200、`projects`（登録対象の公開Projectの`app_key`, `project_name`, `frontend_url`） |
| POST `auth/register.php` | `userid`, `email`, `password`, `password_confirmation` | 201、`status: "ok"`, `verification_required`, `verification_email_sent` |
| POST `auth/resend-verification.php` | `identity`（ユーザーIDまたはメール） | 202、`status: "ok"`, `accepted`, `verification_email_sent` |
| GET `auth/verify.php` | クエリ`token` | 200、`status: "ok"`, `email_verified: true`, `projects`（確認したアカウントの参加先のうち有効・公開のProject） |
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

- 登録時のアカウントroleは`user`（一般ユーザー）。メール確認必須なら`pending`、任意なら`active`です。有効化後は管理画面で自分のProjectを作成・管理できます。参加先には別途Projectの`contributor`を付与し、そのProjectの設定・管理画面の表は利用できません。
- 任意の`app_key`を送ると、そのProjectへ`contributor`として自動参加します。対象は有効・未削除・`frontend_public: true`・`recruiting: true`のProjectだけです。対象外の指定は422となりアカウントを作りません。アカウント作成と参加設定は同一トランザクションで行います。`app_key`を省略した場合は参加Projectなしで登録します。
- 登録画面のURLに`register.html?app_key=playgrounds`のようにProject識別子を指定すると参加先が初期選択されます。画面で参加先を変更でき、選択値が登録APIへ送られます。URL引数なしでも公開Projectを選択できます。アカウントやProjectのroleを登録リクエストで昇格することはできません。
- パスワードは既定8文字以上・最大4096バイト。`auth.password_min_length`で最小文字数を設定できます（下限8）。登録・管理者作成・再設定に共通です。
- 再設定要求はアカウントの存在有無にかかわらず同じ受付メッセージを返します。登録・確認メール再送・再設定要求にはRate Limitがあります。
- 公開登録成功時は、メールを持つ有効な管理者へ通知します。通知失敗は登録を取り消しません。通知にはパスワード・トークンを含めず、管理者招待・確認メール再送は通知対象外です。

画面のURLとメール設定は[画面と管理操作](admin-guide.md#ユーザー登録)を参照してください。

登録画面の見出し・説明は選択Projectの`project_name`に追従します。戻り先は管理者が設定した`frontend_url`だけを使い、HTTP/HTTPSの絶対URL・ホスト名あり・認証情報なしを検証します。URLが未設定・不正なら`frontend_url`はnull、戻りリンクは表示しません。URL引数の`return_url`は使いません。確認メールには登録先の`app_key`を付け、再送では参加先が1つの場合に引き継ぎます。確認後の`projects`はトークンで確認したユーザーのDB上の参加先から取得します。

## 本人によるアカウント削除

**`POST auth/delete-account.php`** — 管理コンソールの利用権限を必要としません。Cookieや対象ユーザーIDでは認証せず、毎回本人の認証情報をJSONで受け付けます。メール確認前・無効状態のアカウントも、現在のパスワードが一致すれば削除できます。

必須ヘッダーは`Content-Type: application/json`と`X-Account-Action: delete`。後者をCORSの許可ヘッダーへ追加しないでください。自己削除画面`account.html`は同一Originから`credentials: omit`で送信します。本番では必ずHTTPSを使用してください。

```json
{
  "identity": "example-user",
  "password": "current-password",
  "confirm_userid": "example-user"
}
```

`identity`はユーザーIDまたはメールアドレス。`confirm_userid`には登録済みユーザーIDの大文字・小文字も含め完全一致が必要です。任意の`id`や`user_id`を送っても、別のアカウントを対象にできません。

- 成功：200、`{"status":"ok","account_deleted":true}`。
- 認証不一致：401 `invalid_credentials`、確認入力不一致：422 `validation_failed`、最後の有効な管理者：409 `last_administrator`。
- 不正なメソッド：405、ヘッダー不足：403 `account_header_required`、回数制限：429 `rate_limit_exceeded`（アクセス元10回/時・識別子5回/時）。
- 同一トランザクションでユーザー行、認証トークン、参加権限、本人を対象または操作主体とする管理監査ログ、ユーザーID・メール由来のRate Limit記録を削除します。アクセス元由来のRate Limitは保持します。
- `activities`と`projects`は保持し、外部キーにより投稿者・編集者・作成者の紐付けをnullへ変更します。投稿データ、座標、日時、公開状態は変更しません。削除済みアカウントの認証・既存コンソールセッション・確認トークンは利用できません。
- データ本文に含まれるユーザー情報を自動検出・削除する機能や、外部へ配布済みのコピーの回収機能はありません。個人情報の混入は問い合わせ窓口で扱います。

## Projectの共同管理者

`/api/project-administrators.php?app=PROJECT_KEY` を使用します。HTTP Basic認証または管理画面セッションが必要です。全体管理者、または対象Projectの有効な`project_admin`だけが利用できます。

- GET: `app_key`、`project_name`、`administrators`を返します。各管理者は`id`、`userid`、`status`、`can_manage`、`is_creator`のみで、メールアドレス・パスワードは返しません。
- POST: JSON `{"userid":"existing-user"}` で既存の有効な`user`／`admin`を追加します。すでに管理者なら変更しません。既存のProject割り当てがあれば`project_admin`に変更します。
- DELETE: JSON `{"userid":"existing-user"}` で管理者を解除し、対象Projectの`editor`に変更します。作成者は`409 project_creator_required`、最後の有効な管理者は`409 last_project_administrator`で拒否します。

他Projectや全体のアカウント権限・認証情報は変更しません。作成者・共同管理者の変更は既存の`user_projects`を使用するため、DBマイグレーションは不要です。非DB管理・削除済みのProjectは404、管理権限がなければ403、追加できないユーザーIDは422です。追加・解除は監査ログと同一トランザクションで保存します。

## プロジェクトへの自己参加

既存DBは`migrations/012_project_recruitment.sql`で`recruiting`列を追加します（既存値はfalse）。

- `GET api/project-membership.php?app=...`: `authenticated`, `is_member`, `can_join`を返します。Cookieセッションの識別には`X-Console-Session: 1`が必要です。匿名ユーザーにも公開Projectの参加受付状態だけを返します。
- `POST api/project-membership.php`: `X-Console-Session: 1`とログインが必須です。JSONは`{"app":"..."}`。公開・有効・募集中の場合、認証された本人だけを`contributor`として追加します。指定されたユーザーIDやロールは使いません。既存の参加権限は上書きせず、再送も安全です。募集停止は409、非公開・無効・削除済み等への新規参加を拒否します。
- 登録済みユーザーの権限変更、募集設定の変更、自己参加は別の操作です。募集設定の変更には既存のProject管理権限が必要です。

### プロジェクトのユーザー管理

`api/project-users.php` は、認証済みの全体管理者または対象プロジェクトの `project_admin` 専用です。

- GET: `app`（必須）、`search`（ユーザーID）、`page`、`per_page`（最大100）。対象プロジェクトの参加者と参加禁止ユーザーのみを返します。各項目は `id`, `userid`, `project_role`, `is_global_admin`。他プロジェクトの割当やメール・パスワードは返しません。
- PUT: JSON `{ "app": "playgrounds", "id": 123, "role": "banned" }`。`role` は `viewer`, `contributor`, `editor`, `project_admin`, `banned`、または参加・禁止解除の `null`。`X-Console-Session: 1` が必要です。対象プロジェクトに割当済みのユーザーのみ変更できます。全体のアカウント変更フィールドは拒否します。
- 権限不足は403、作成者・最後の管理者の降格は409、対象外ユーザーや無効な入力は422です。更新と監査ログは同じトランザクションで確定します。

全ユーザーのアカウント操作は、引き続き全体管理者専用の `api/admin-users.php` を使います。

### 自分の参加プロジェクト

`api/my-projects.php` は、有効なログインユーザー本人の参加状況を扱います。対象ユーザーIDを指定する機能はありません。

- GET: `page`（既定1）。本人の参加一覧を25件ずつ返します。`items` は `app_key`, `project_name`, `role`, `enabled`, `frontend_url`, `frontend_public`, `can_leave`, `leave_reason`。参加禁止・削除済みは除外します。ページ情報は `pagination` に含みます。
- DELETE: JSON `{ "app": "playgrounds" }`、`X-Console-Session: 1` が必要です。他のフィールドは拒否し、本人の参加権限のみ削除します。投稿データは変更しません。
- 未認証は401、参加禁止の自己解除は403、作成者・最後の有効な管理者の解除は409、未参加・削除済み・存在しないプロジェクトは404です。権限の再確認・参加解除・監査ログをトランザクションで処理します。

`api/project-membership.php` のGETには、本人の参加状態に加えて `can_leave`, `leave_reason` を返します。詳細画面では対象1件の参加権限だけを確認し、解除操作には上記 `api/my-projects.php` のDELETEを利用します。

### ユーザーの論理削除（全体管理者専用）

`POST api/admin-users.php` に `{ "action": "soft_delete", "id": 123, "confirm_userid": "target-user" }` を送ります。全体管理者の認証と `X-Console-Session: 1` が必須です。対象のユーザーIDを完全一致で確認し、成功時は `{ "status": "ok", "user_deleted": true }` を返します。状態は `deleted` になり、未使用の認証トークンは無効になります。ユーザー行・参加割当・投稿・プロジェクトとその帰属は残します。

一般ユーザー・プロジェクト管理者は403、確認用ユーザーIDの不一致は422、自分自身または最後の有効な全体管理者の削除は409です。通常の一覧は削除済みを除外し、`GET api/admin-users.php?status=deleted` で削除済みを参照できます。削除済みの変更要求は409 `user_deleted`。通常のPUTに `status=deleted` を指定する操作は受け付けません。プロジェクト用APIには論理削除機能を提供しません。

`restore` / `purge` は全体管理者と `X-Console-Session: 1` が必須です。対象IDと確認用ユーザーIDの完全一致を検証し、未削除アカウントは409 `user_not_deleted`、対象なしは404、確認不一致は422です。操作と監査ログは同一トランザクションです。復活時も古いセッション・トークンは無効です。


### カラム定義の事前検証

POST api/project-schema.php?app=playgrounds は、認証された全体管理者または対象のproject_admin向けに、本文のJSON { "fields": { ... } } を保存と同じルールで検証します。応答は { "schema": { "fields": { ... } } }。並び順や既定値を正規化し、DB・プロジェクト設定・投稿データは変更しません。不正な定義は422、権限不足は403、対象なしは404です。プロジェクト設定画面のJSONインポート／エクスポートで使用します。

CSV取り込みの422エラーは、例として `{"errors":{"csv_rows.5":{"osmid":"OSM ID is required."}}}` のように元CSVの行番号と項目ごとの原因を返します。行番号はヘッダーを1行目とし、空行と引用符で囲まれたセル内の改行も数えます。JSON取り込みの `rows.0` 等は引き続き0始まりのデータ行インデックスです。ID空欄、OSM ID空欄／形式不正、ID重複などはそれぞれ異なる原因を返し、管理画面で日本語・英語の具体的な説明に表示します。

CSV取り込みでは、入力・保存済みの座標がない行に限り、OSMIDをまとめてOverpassへ照会します。Overpassの失敗・未取得時は残りの時間予算内でOSM公式APIへ代替照会します。nodeは座標、way・relationは構成要素が揃った場合の範囲中心を使用し、不完全な形状から座標を推定しません。応答のcoordinate_lookupはfilled（補完件数）、preserved（保存済み座標の保持件数）、unresolved（取得不能行の配列。各要素はlineとosmid）です。lineは元CSVの物理行番号です。未取得行は座標をnullにして取り込み可能ですが、確認・実行結果に明示します。1つだけ座標がある場合は従来どおり検証エラーとなります。

### 投稿データの一括論理削除（管理画面）

`GET /api/activity-delete-all.php?app=KEY` は認証済みの全体管理者・対象プロジェクト管理者に、`app_key`, `project_name`, `count`, `snapshot_max_id`（文字列）を返します。投稿本体を転送せず全件数を確認できます。

`POST /api/activity-delete-all.php` は `X-Console-Session: 1` ヘッダーとJSON `{ "app": "KEY", "confirm_app_key": "KEY", "snapshot_max_id": "123", "expected_count": 100 }` を必要とします。KEYの完全一致と確認件数を検証し、該当プロジェクトの有効な投稿のうち、確認時の最大ID以下を削除済みに移します。確認後の新規投稿は保持し、対象件数が変わればHTTP409 `activity_delete_conflict` を返します。成功時は `{ "status": "ok", "deleted_count": 100, "remaining_count": 0 }` を返します。権限不足は403、入力不正は422、存在しない・削除済みプロジェクトは404です。監査イベント `activities.soft_delete_all` と投稿更新を同一トランザクションで保存し、失敗時はすべて取り消します。投稿の物理削除は行いません。


## Activity応答のgzip圧縮

`GET api/activities.php`の成功応答は、クライアントの`Accept-Encoding`がgzipを許可する場合、JSON・CSVの両方をgzip圧縮します。ブラウザーの`fetch()`等では通常、自動で展開されるため呼び出し側の変更は不要です。手動のクライアントでは`Content-Encoding: gzip`に応じて展開してください（curlなら`--compressed`）。

1KiB未満、圧縮しても小さくならない応答、gzip非対応や`gzip;q=0`を指定した要求は未圧縮で返します。書き込み応答やエラー応答は対象外です。既存の圧縮が有効な場合は二重圧縮しません。`Vary: Accept-Encoding`を付け、CORSの`Vary: Origin`、CSVのBOM・ファイル名・改行、JSONの内容を保持します。圧縮にはPHPのzlibを使い、DB検索や取得件数は変更しません。

### プロジェクトダッシュボード

`GET api/project-dashboard.php?app={app_key}&page=1` は認証済みの参加メンバー（viewer / contributor / editor / project_admin）とシステム管理者向けです。非公開・停止中プロジェクトも参加メンバーは閲覧でき、未参加・参加禁止・脱退後は閲覧できません。削除済みプロジェクトは404です。

`project`、`counts`（`total` / `today` / `week` / `month`）、`as_of`、`timezone`、`items`、`pagination` を返します。集計は削除済み投稿を除き、DBの `created_at` を使用します。新規投稿数は日本時間の今日、今日を含む7日間・30日間です（削除を差し引いた純増ではありません）。過去データのインポートではインポート時の作成日時が基準になる場合があります。一覧は作成日時降順、同時刻は内部ID降順で20件ずつ取得します。

画面は `project-dashboard.html?app={app_key}`。マイページの参加プロジェクト一覧にある「ダッシュボード」から開けます。
