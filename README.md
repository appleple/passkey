# a-blog cms Passkey プラグイン

a-blog cms の **管理画面ログインをパスキー（WebAuthn）でできるようにする**プラグイン。

## できること

- 管理ログイン画面 (`/login/`) に「パスキーでログイン」ボタンを自動追加
- 管理画面サイドバー「拡張メニュー > Passkey」から自分のパスキーを登録／削除
- 認証器名表示（AAGUID から「Apple iCloud Keychain」「Windows Hello」等を判別）
- インストールだけで完結、テーマ修正・YAML 編集 **不要**

## インストール

リポジトリのディレクトリ構造を a-blog cms にそのまま重ねます。

```bash
# このリポジトリを clone（または zip をダウンロード）して
git clone https://github.com/kazumich/passkey.git
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

## 動作要件

- PHP 8.0 以上（ext-openssl, ext-mbstring 必須、ext-sodium 推奨）
- HTTPS（WebAuthn API がブラウザで HTTP を拒否）
- ブラウザは WebAuthn Level 2 対応（最新の Chrome / Safari / Edge / Firefox）

## 詳細仕様

[extension/plugins/Passkey/SPEC.md](./extension/plugins/Passkey/SPEC.md) を参照してください。アーキテクチャ、認証フロー（シーケンス図付き）、DB スキーマ、エンドポイント、セキュリティ、本体組み込み時の設計提案を記載しています。

## ライセンス

MIT
