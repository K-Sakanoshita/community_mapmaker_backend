# さくらのレンタルサーバへの配置

このリポジトリは、ローカル開発ではリポジトリ直下をそのまま実行できますが、本番では Git 作業ツリーと DB 設定を Web 公開フォルダーに置かない構成を推奨します。

## 推奨フォルダー構成

```text
/home/ACCOUNT/
├── community_mapmaker_backend/          # Git clone。Web 非公開
│   ├── admin/
│   ├── api/
│   ├── auth/
│   ├── config/
│   │   ├── config.php                   # 本番設定・秘密情報
│   │   └── activity-schemas/
│   ├── lib/
│   ├── migrations/
│   ├── scripts/
│   ├── bootstrap.php
│   ├── reset-password.html
│   └── schema.sql
└── www/
    └── community-mapmaker-backend/      # Web 公開側
        ├── .htaccess
        ├── admin/
        ├── api/
        ├── auth/
        ├── reset-password.html          # メールから開くパスワード再設定画面
        ├── bootstrap.php                # PHP include 用。HTTP 直接アクセスは禁止
        ├── config/
        │   ├── .htaccess
        │   └── config.php               # 非公開側 config.php を読むだけのブリッジ
        └── lib/                         # PHP include 用。HTTP 直接アクセスは禁止
```

`config/config.php` の実体、migration、schema、テスト、Docker 関連ファイルは `www` の外に残します。

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
- `auth.allowed_origins`
- `auth.rate_limit_secret`
- `mail.from_address`
- `mail.from_name`

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

- `admin/`、`api/`、`auth/` を公開側へコピー
- `reset-password.html` を公開側へコピー
- PHP 実行に必要な `bootstrap.php` と `lib/` を公開側へコピー
- `config/config.php` の実体は Git 作業ツリー側に残す
- 公開側には、非公開側の `config/config.php` を `require` するだけのブリッジを生成
- `.htaccess` で `bootstrap.php`、`lib/`、`config/` への直接 HTTP アクセスを拒否
- ディレクトリ一覧表示を無効化
- さくらの設置条件に合わせ、公開側の PHP ファイルを 755、その他の通常ファイルを 644、ディレクトリを 755 に設定

PHP からの `require` は `.htaccess` の HTTP アクセス制限の影響を受けないため、API 自体は従来の相対パスのまま動作します。

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

`admin/`、`api/`、`auth/`、`lib/` は毎回入れ替えるため、削除済みファイルが公開側に残りません。`reset-password.html` も再配置されます。`config/config.php` の実体は非公開側にあるため、再配置で上書きされません。

## 動作確認

配置後、まず読み取り系 API と管理画面を確認します。

```text
https://YOUR_HOST/community-mapmaker-backend/admin/
https://YOUR_HOST/community-mapmaker-backend/api/activities.php?app=playgrounds
https://YOUR_HOST/community-mapmaker-backend/reset-password.html
```

`reset-password.html` を token なしで直接開いた場合は、無効な URL である旨を表示します。通常はパスワード再設定メールのリンクから開いてください。

次の URL は HTTP 403 になることを確認してください。

```text
https://YOUR_HOST/community-mapmaker-backend/bootstrap.php
https://YOUR_HOST/community-mapmaker-backend/lib/Database.php
https://YOUR_HOST/community-mapmaker-backend/config/config.php
```

API が HTTP 500 の場合は、さくらのコントロールパネルのエラーログと PHP バージョン、PDO MySQL の有効化、DB 接続情報を確認してください。

書き込み API の Basic 認証だけ失敗する場合は PHP の動作モードも確認してください。さくらの仕様上、PHP による HTTP 認証はモジュールモードで利用可能と案内されています。このバックエンドは `Authorization` / `REDIRECT_HTTP_AUTHORIZATION` も読み取りますが、サーバー側で Authorization ヘッダーが PHP に渡らない構成では認証できません。

## 補足

さくらのレンタルサーバでは `.htaccess` と `mod_rewrite` を利用できますが、`Options FollowSymlinks` は利用できません。そのため、この手順は公開側をシンボリックリンクだけで構成せず、必要なランタイムファイルをコピーする方式にしています。
