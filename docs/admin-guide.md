# 画面と管理操作

## 公開ポータル

ルートの`index.html`には公開Projectの一覧・検索と、ユーザー登録・ログインへの導線があります。公開設定されたProjectのみ表示し、各フロントエンドの`og:image`と`og:description`をカードに使用します。取得できない場合は地図風の代替表示とProject名を表示します。`api/project-preview.php?app=...`は公開Projectのメタデータだけを取得し、結果を6時間キャッシュします。取得先は公開IPのHTTP/HTTPS標準ポートに限定します。

## プロジェクト管理

`admin/index.html`を開き、独立したログイン画面から有効なユーザーのHTTP Basic認証情報でログインします。認証成功後にだけコンソールを表示し、ログアウトするとパスワードと画面上の管理データを破棄してログイン画面へ戻ります。`admin`はProject一覧、カラム定義、Activity表、ユーザー管理を利用できます。一般ユーザーにもProject一覧と作成ボタンを表示します。作成したProjectは直後から設定・Activity編集ができ、`editor`は割り当て済みActivityを編集可能、`viewer`は閲覧のみです。Activity Schemaの`type`に応じて`text`、`textarea`、`number`、`date`、`select`、`checkbox`、`url`、`wikimedia`の編集欄を生成します。MIT LicenseのTabulator 6.5.2を採用し、CSS/JavaScriptはバージョンとSRIハッシュを固定してUNPKGから読み込みます。

管理画面のフォーム、ボタン、表、カードなどはBootstrap 5.3.3を使用します。BootstrapのCSSはバージョンを固定してjsDelivrから読み込み、モーダル用のJavaScriptは`admin/vendor/bootstrap.bundle.min.js`を同一オリジンから読み込みます。表のスクロールやTabulator固有の表示などには`admin/admin.css`を使用します。操作結果やエラーはBootstrapの通知モーダルで表示しますが、ログイン成功時とProject・Activity・ユーザー一覧の通常の読み込み完了時には開きません。

英語表示は`/en/admin/`です。管理画面の処理は`admin/admin.js`で共通化し、動的な表示文言は翻訳キーを通して`admin/ja.json`と`admin/en.json`で管理します。英語版の静的な画面文言は`en/admin/index.html`にあります。プロジェクト名やカラムの表示名など、保存されたデータは翻訳せず登録された文字列を表示します。

- Projectの作成、表示名編集、削除（`app_key`は変更不可）
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

セッションAPIと各操作の権限は[APIリファレンス](api-reference.md#認証と権限)を参照してください。

## ユーザー登録

公開画面は `/register.html` です。`/admin/` のログイン画面から移動できます。メールに届く `/verify-email.html?token=...` を開き、確認ボタンを押すと登録が有効になります。確認前はログインできません。登録直後の権限は通常の `user` です。メール確認後は自分でProjectを作成でき、作成したProjectには`project_admin`として自動割り当てされます。既存Projectへの参加は管理者による割り当てが必要です。

公開登録が成功すると、メールアドレスを持つ有効な全体管理者（`role=admin`、`status=active`）全員に通知メールを送ります。通知には登録者のユーザーID、未確認のメールアドレス、状態を記載し、確認用トークンやパスワードは含めません。管理者にメールアドレスがなければ通知先にはなりません。通知失敗はサーバーログへ記録し、登録と本人宛確認メールの結果には影響させません。管理者によるユーザー招待や確認メール再送は通知対象外です。

本番では `auth.email_verification=true` とし、`auth.verification_url` を公開 HTTPS の `/verify-email.html` に設定してください。PHP の `mail()` が実際に配送できることと、送信元アドレスの設定を実メールで確認してください。

[READMEへ戻る](../README.md)
