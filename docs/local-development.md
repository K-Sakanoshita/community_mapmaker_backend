# ローカル開発環境

ローカルの新規登録画面は `http://127.0.0.1:18080/register.html`、確認メールの受信箱は `http://127.0.0.1:18025/` です。ローカル設定でもメール確認が必須です。Mailpitはローカル端末からのみアクセスでき、実際の宛先へメールを配送しません。

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


[READMEへ戻る](../README.md)
