# a-blog cms Passkey プラグイン

a-blog cms の **管理画面ログインをパスキー（WebAuthn）でできるようにする**プラグイン。

## できること

- 管理ログイン画面 (`/login/`) に「パスキーでログイン」ボタンを自動追加
- 管理画面サイドバー「拡張メニュー > Passkey」に**全ユーザーのパスキー台帳**（管理者専用）。誰が登録しているかの一覧と、退職・端末紛失時の削除ができる
- 認証器名表示（AAGUID から「Apple iCloud Keychain」「Windows Hello」等を判別）
- インストールだけで完結、テーマ修正・YAML 編集 **不要**
- **管理者以外（編集者・投稿者など）も**、ユーザー管理ページの「パスキー：設定する」ボタンから自分のパスキーを登録／削除できる（v0.7.0〜 テーマ改修不要・自動表示）

## インストール

リポジトリのディレクトリ構造を a-blog cms にそのまま重ねます。

```bash
# このリポジトリを clone（または zip をダウンロード）して
git clone https://github.com/appleple/passkey.git
cd passkey

# extension/plugins/Passkey/ をそのまま a-blog cms 側にコピー
cp -r extension/plugins/Passkey /path/to/ablogcms/extension/plugins/

# composer 依存（lbuchs/webauthn）はリポジトリに同梱済み
# 必要なら再インストール
cd /path/to/ablogcms/extension/plugins/Passkey
composer install --no-dev
```

その後、a-blog cms 管理画面の「拡張アプリ」から **Passkey をインストール → 有効化**。
サイドバー「拡張メニュー > Passkey」が現れます。

## 管理者以外のユーザーのパスキー設定（v0.7.0〜 テーマ改修不要）

サイドバーの「拡張メニュー > Passkey」（`/admin/app_passkey_index/`）は a-blog cms の仕様で**管理者しかアクセスできません**。そのため v0.7.0 から、編集者・投稿者を含む全ユーザーが使える「自分のパスキー管理ページ」をプラグインが自動で提供します。

- **ユーザー管理ページ**（`/admin/user_edit/`）の２段階認証の下に「パスキー：設定する」ボタンが自動で表示されます
- ボタンの先 `/bid/{BID}/uid/{UID}/admin/user_passkey/` がパスキー管理ページ（一覧・追加・削除が1ページで完結）になります
- どちらもプラグインがレスポンスに注入するため、**テーマの編集は不要**です

登録・削除の処理は**セッションユーザー（本人）ベース**で行うため、どの権限のユーザーがアクセスしても自分のパスキーしか操作できません（他人の uid で開いても案内が表示されるだけです）。

### 表示をカスタマイズしたい場合

パスキー管理ページの見た目を変えたい場合は、`theme-dist/admin/user/passkey.html` をテーマにコピーして編集してください。

```bash
cp theme-dist/admin/user/passkey.html /path/to/themes/あなたのテーマ/admin/user/passkey.html
```

テーマに `admin/user/passkey.html` が存在する場合、プラグインは自動注入を止めてテーマ側を優先します。また、テーマの `admin/user/edit.html` に `admin/user_passkey/` へのリンクを独自設置している場合も、ボタンの自動注入はスキップされます（二重表示防止）。

> **Note**: テンプレート変更後に表示が変わらない場合は、テンプレートキャッシュ（`cache/template-*`）を削除してください。

## 動作要件

- a-blog cms 3.2.x
- PHP 8.1 以上（ext-openssl, ext-mbstring 必須、ext-sodium 推奨）
- HTTPS（WebAuthn API がブラウザで HTTP を拒否）
- ブラウザは WebAuthn Level 2 対応（最新の Chrome / Safari / Edge / Firefox）

## 詳細仕様

[extension/plugins/Passkey/SPEC.md](./extension/plugins/Passkey/SPEC.md) を参照してください。アーキテクチャ、認証フロー（シーケンス図付き）、DB スキーマ、エンドポイント、セキュリティ、本体組み込み時の設計提案を記載しています。

## ライセンス

MIT
