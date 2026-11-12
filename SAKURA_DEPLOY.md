# さくらのレンタルサーバへの配置

公開するファイルはリポジトリ内の `www/` にまとめています。リポジトリ自体は `/home/ACCOUNT/` 配下の非公開フォルダーに置き、`www/` の中身だけをサーバーの公開フォルダーへコピーします。

## フォルダー構成

```text
/home/ACCOUNT/
├── community_mapmaker_backend/          # Git clone。非公開
│   ├── www/                            # 公開用ファイルの原本
│   │   ├── index.html, *.css, *.js, 画像
│   │   ├── admin/, en/, ja/             # 管理画面・言語別ページ
│   │   ├── api/, auth/                 # HTTPエンドポイント
│   │   ├── _runtime.php                # 非公開PHPの参照先
│   │   └── .htaccess
│   ├── bootstrap.php                   # DB接続・サービス初期化
│   ├── lib/                            # 共通PHP処理
│   ├── config/                         # 設定・秘密情報・Schema
│   ├── var/                            # ローカル運用データ（使用時）
│   ├── schema.sql, migrations/         # DB作成・更新
│   └── scripts/, tests/, docker/, docs/
└── www/
    └── community-mapmaker-backend/      # サーバーの公開フォルダー
        ├── index.html, *.css, *.js, 画像
        ├── admin/, en/, ja/
        ├── api/, auth/
        ├── _runtime.php                # 非公開側の絶対パスを自動生成
        └── .htaccess
```

`bootstrap.php`、`lib/`、`config/` はサーバーの公開フォルダーには置きません。`_runtime.php` は参照先だけを定義し、秘密値をコピーしません。API・ページの公開URLは従来どおりです。非公開リポジトリは、公開コピーを配置した後もPHP実行に必要です。

## 初回配置

SSH でさくらのレンタルサーバへ接続し、ホームディレクトリで clone します。

```sh
cd "$HOME"
git clone https://github.com/K-Sakanoshita/community_mapmaker_backend.git
cd community_mapmaker_backend
```

本番設定を作ります。

```sh
cp config/config.example.php config/config.php
vi config/config.php
```

最低限、次を本番用に変更してください。

- DB の DSN、ユーザー、パスワード
- `auth.verification_url`
- `auth.password_reset_url`
- `auth.allowed_origins`（別ドメインのWebサイトからAPIを利用する場合。同一Originは自動許可）
- `auth.rate_limit_secret`
- `mail.from_address`
- `mail.from_name`

`mail.from_address` は表示上のFromと、sendmailの`-f`で指定する実際の送信元（エラー返送先）の両方に使用します。受信可能なアドレスを設定し、そのドメインの既存SPFレコードで実際の送信サーバーを許可してください。独自ドメインのFromを使っていても、さくらのサーバーがSPFで許可されておらずDKIM署名もなければ、Gmailなどで拒否・迷惑メール判定される可能性があります。SPFレコードは既存の送信元を残して更新し、複数の`v=spf1`レコードを作らないでください。DKIMを利用する場合はFromと同じドメインで署名される設定も確認します。

`mail()`の成功は配送受付を示し、宛先への到着を保証しません。反映後は確認メールを再送し、受信メールの`Return-Path`が設定アドレス、`Authentication-Results`が`spf=pass`および`dmarc=pass`になっていることを確認してください。未着時は返送先のエラーメールと送信サーバーのログを確認します。設定変更前のメールは旧返送先にエラーが戻る場合があります。

`auth.password_reset_url` は、同梱の `reset-password.html` の公開 URL を指定します。既定の配置先なら次の形式です。

```php
'password_reset_url' => 'https://YOUR_HOST/community-mapmaker-backend/reset-password.html',
```

メールに記載される URL にはバックエンドが `?token=...` を自動で付加します。`reset-password.html` はその token を読み取り、同じ配置先の `auth/reset-password.php` へ新しいパスワードを POST します。

ランダムな Rate Limit secret は次のように生成できます。

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

次に公開フォルダーへ配置します。

```sh
chmod +x scripts/deploy-sakura.sh
./scripts/deploy-sakura.sh "$HOME/www/community-mapmaker-backend"
```

引数を省略した場合も `$HOME/www/community-mapmaker-backend` が使われます。

独自ドメイン側で別の公開フォルダーを設定している場合は、その実パスを引数に指定してください。

```sh
./scripts/deploy-sakura.sh "$HOME/www/example.jp/backend"
```

## deploy-sakura.sh が行うこと

- リポジトリの `www/` の中身だけを公開先へコピー
- 公開側の `_runtime.php` に非公開リポジトリと設定ファイルの絶対パスを設定
- `CMM_SAKURA_CONFIG_FILE` 未指定時は非公開側の `config/config.php` を利用
- 旧構成の公開側 `bootstrap.php`、`lib/`、`config/` を削除
- `.htaccess` でディレクトリ一覧と `_runtime.php` の直接アクセスを拒否
- 公開側のPHPファイルを755、その他の通常ファイルを644、ディレクトリを755に設定

公開先が非公開リポジトリの内部や親フォルダーに当たる場合、または実設定が公開先の内部にある場合は、配置を中止します。公開先はこのアプリ専用のフォルダーを指定してください。

ポータルのOGP画像・説明文プレビューにはPHPの`curl`拡張が必要です。利用できない環境ではProjectカードの代替画像を表示し、地図へのリンクは引き続き利用できます。

## DB の作成

新規 DB では `schema.sql` を使用します。既存 DB を更新する場合は `migrations/` の SQL を番号順に適用してください。

Git 作業ツリーは Web 非公開なので、SQL や migration ファイルを公開フォルダーへコピーする必要はありません。

## 管理ユーザー

本番設定を作成した後、最初の admin 権限は Web 非公開側の作業ツリーから付与します。

```sh
cd "$HOME/community_mapmaker_backend"
php scripts/grant-admin-role.php USERID_OR_EMAIL
```

## 更新

コード更新時は Web 非公開側で `git pull` し、再度 deployment script を実行します。

```sh
cd "$HOME/community_mapmaker_backend"
git pull
./scripts/deploy-sakura.sh "$HOME/www/community-mapmaker-backend"
```

`admin/`、`api/`、`auth/`、`en/`、`ja/` は毎回入れ替えるため、削除済みファイルが公開側に残りません。`reset-password.html` も再配置されます。`config/config.php` の実体は非公開側にあるため、再配置で上書きされません。

## 動作確認

配置後、まず読み取り系 API と管理画面を確認します。

```text
https://YOUR_HOST/community-mapmaker-backend/
https://YOUR_HOST/community-mapmaker-backend/admin/
https://YOUR_HOST/community-mapmaker-backend/en/admin/
https://YOUR_HOST/community-mapmaker-backend/api/activities.php?app=playgrounds
https://YOUR_HOST/community-mapmaker-backend/reset-password.html
```

`reset-password.html` を token なしで直接開いた場合は、無効な URL である旨を表示します。通常はパスワード再設定メールのリンクから開いてください。

次の非公開ファイルの URL は HTTP 404（サーバー側の拒否設定がある場合は403）になることを確認してください。

```text
https://YOUR_HOST/community-mapmaker-backend/bootstrap.php
https://YOUR_HOST/community-mapmaker-backend/lib/Database.php
https://YOUR_HOST/community-mapmaker-backend/config/config.php
```

`_runtime.php` への直接アクセスはHTTP 403になります。

API が HTTP 500 の場合は、さくらのコントロールパネルのエラーログと PHP バージョン、PDO MySQL の有効化、DB 接続情報を確認してください。

書き込み API の Basic 認証だけ失敗する場合は PHP の動作モードも確認してください。さくらの仕様上、PHP による HTTP 認証はモジュールモードで利用可能と案内されています。このバックエンドは `Authorization` / `REDIRECT_HTTP_AUTHORIZATION` も読み取りますが、サーバー側で Authorization ヘッダーが PHP に渡らない構成では認証できません。

## 補足

### 画像サムネイルAPI

`api/thumbnail.php`にはPHPのGD・cURLが必要です。`extension_loaded('gd')`、`extension_loaded('curl')`と`gd_info()`で実サーバの対応形式を確認してください。GDがWebPに対応していない場合はJPEG／透過PNGで生成します。EXIF拡張が有効ならJPEGの向きを補正します。Imagickは不要です。

`activity.thumbnail.cache_dir`を設定する場合はWeb公開ディレクトリの外の、PHPが書き込める専用ディレクトリを指定してください。未設定時はシステム一時ディレクトリを使います。共有サーバーへの負荷を抑えるため、同じ画像を重複生成せず、別画像の生成は同じキャッシュ保存先につき最大2件に制限し、容量・画素数・取得時間・生成回数の上限を設けています。API仕様・制限は[APIリファレンス](docs/api-reference.md#画像サムネイルapi)を参照してください。

さくらのレンタルサーバでは `.htaccess` と `mod_rewrite` を利用できますが、`Options FollowSymlinks` は利用できません。そのため、この手順は公開側をシンボリックリンクだけで構成せず、必要なランタイムファイルをコピーする方式にしています。
