# Community Mapmaker Backend

Community Map Makerで共通利用するPHPバックエンドです。現在はユーザー登録、メール確認、確認メール再送、パスワード再設定を提供します。

Activity保存先との接続とフロントエンド画面はまだ含みません。

## 構成

```text
auth/       認証APIのエンドポイント
config/     設定例とApacheのアクセス制御
lib/        認証、DB、メール、Rate Limitの実装
tests/      DBを使用しない振る舞いテスト
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

1. MySQLにデータベースを作り、`schema.sql`を適用します。
2. `config/config.example.php`を、Web公開ディレクトリ外の場所へコピーします。
3. DB接続、許可Origin、確認URL、再設定画面URL、送信者情報を設定します。
4. `CMM_AUTH_CONFIG`環境変数に実設定ファイルの絶対パスを指定します。
5. 32文字以上のランダムな`CMM_RATE_LIMIT_SECRET`を設定します。

秘密値の生成例:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

ローカル確認時のみ、`config/config.php`へ設定を置くこともできます。このファイルは`.gitignore`対象で、Apacheからの直接アクセスも`.htaccess`で拒否します。本番では公開ディレクトリ外を使用してください。

## API

すべてのPOST APIは`Content-Type: application/json`で送信します。レスポンスもJSONです。

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

メール確認が必須なら`pending`、任意なら`active`で作成します。パスワードは`password_hash()`の結果だけを保存します。

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
- CORSは設定したOriginだけを許可
- API payloadは64 KiBまで
- 内部例外はリクエストIDだけを返し、詳細はサーバーログへ記録
- `pending`および`disabled`ユーザーは`AuthService::authenticate()`で認証不可

プロキシ配下で`X-Forwarded-For`を使う場合だけ`trust_proxy_headers`を有効にします。信頼できないクライアントからこのヘッダーを直接受け取る構成では有効にしないでください。

## テスト

```sh
php tests/AuthServiceTest.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

テストでは、登録、正規化、平文パスワード非保存、確認前認証拒否、1回限りトークン、パスワード再設定、旧パスワード拒否、Rate Limit、メール確認任意設定を確認します。

## 現在の実装範囲

- 認証APIとDBスキーマ: 実装済み
- DB・メール送信環境との結合確認: 未実施
- Activity Storage: 未実装
- フロントエンドの登録・ログイン画面: 別リポジトリで今後実装

## ライセンス

MIT Licenseです。詳細は`LICENSE`を参照してください。
