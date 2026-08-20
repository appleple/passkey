# a-blog cms パスキーログイン プラグイン

a-blog cms の **管理画面ログインをパスキー（WebAuthn）でできるようにする**プラグイン。

- 配布形態: プラグイン（`extension/plugins/Passkey/`）
- 依存: `lbuchs/webauthn ^2.2`（MIT、PHP 8.0+、依存なし）
- 動作確認: ddev / PHP 8.4 / a-blog cms standard

---

## 1. プラグインができること

### A. 管理ログイン画面 `/login/` にパスキーボタン
- 「サイトに戻る」直前に **「パスキーでログイン」ボタン**が出る
- 押すと `navigator.credentials.get({publicKey, ...})` でブラウザ／OS の認証器が呼ばれる
- usernameless（discoverable credential）方式なので、ID やメアドの入力なしで認証完了
- 認証成功で `generateSession($uid)` が走り、標準ログインと同じセッションが張られる

### B. 管理画面のサイドバー「拡張メニュー > Passkey」
- `/bid/X/admin/app_passkey_index/` — **全ユーザーのパスキー台帳**（ユーザー / 権限 / 認証器名 / 登録日 / 最終使用 / 削除）。`app_*` ページは本体仕様で管理者専用のため、管理者向けの監査・失効（退職・端末紛失）用途
- `/bid/X/admin/app_passkey_edit/` — パスキー追加フォーム（自分の分）
- 一覧／追加の 2 画面構成は、標準の `user/index` `user/edit` などと同じ作法

### B2. ユーザー管理ページからの自分のパスキー管理（v0.6.0 テーマ拡張 → v0.7.0 自動注入）
- `/bid/X/uid/Y/admin/user_passkey/` — 自分のパスキー一覧＋追加＋削除（1 ページ完結）
- **v0.7.0 からテーマ改修不要**。2 つの注入で実現：
  - ページ本体： `_layouts/admin.html` の inject ポイント `admin-main` / `admin-topicpath` に、`ADMIN === 'user_passkey'` のとき `template/admin/user.html` / `topicpath.html` を注入（ServiceProvider）。テーマが `admin/user/passkey.html` を持つ場合は callable 内の判定でテーマ側を優先（`themeProvidesUserPasskeyTemplate()`。テーマ名の `@` 継承チェーンを遡って is_file 判定）
  - 設定ボタン： `Hook::afterBuild()` で `ADMIN === 'user_edit'` のレスポンス HTML に「パスキー：設定する」行を文字列挿入。アンカーは `admin/user_tfa/`（２段階認証行）→ 無ければ `name="retype_pass"`。レスポンスに `admin/user_passkey/` が既に含まれる場合（テーマで設置済み）はスキップ。新規作成画面（UID 空）もスキップ
- `app_*` と違い管理者以外（編集者・投稿者）もアクセスできる。GET/POST モジュールが SUID ベースなので、誰が開いても自分のパスキーしか操作できない。他人の uid で開くと案内のみ表示（テンプレ側 `BEGIN_IF [%{UID}/eq/%{SUID}]`）
- **認証器名表示**：AAGUID から「Apple iCloud Keychain」「Windows Hello」「Google Password Manager」「YubiKey 5 Series」等を解決して表示（辞書は Engine::aaguidLabel() に内蔵）
- **デフォルト名プリフィル**：登録時、User-Agent から推測して名前欄に初期値を入れる（Apple → `My Apple devices`、Windows → `Windows Hello`、Android → `Android` 等）

### C. インストール → 即使える
- テーマファイルの編集不要
- `private/config.system.yaml` への追記不要
- パスキー機能 ON/OFF は `acms_app.app_status` がそのまま反映

---

## 2. アーキテクチャ

```
extension/plugins/Passkey/
├── ServiceProvider.php       # メタ + Hook attach + InjectTemplate + Engine DI
├── Engine.php                # WebAuthn ラッパ（lbuchs/webauthn + DB I/O + AAGUID lookup）
├── Hook.php                  # afterBuild で /login/ にボタン HTML を str-replace 注入
├── composer.json / .lock
├── POST/Passkey/
│   ├── RegisterOptions.php   # 登録チャレンジ発行
│   ├── RegisterVerify.php    # attestation 検証 + DB 保存
│   ├── LoginOptions.php      # 認証チャレンジ発行
│   ├── LoginVerify.php       # assertion 検証 + generateSession
│   └── Unregister.php        # 削除（フォーム送信式）
├── GET/Passkey/
│   └── Lists.php             # SUID のパスキー一覧を返す GET モジュール
├── schema/
│   ├── db-schema.yaml        # acms_passkey
│   └── db-index.yaml
├── template/
│   ├── admin-login.html      # /login/ に注入される HTML
│   └── admin/
│       ├── topicpath.html    # admin パンくず
│       ├── index.html        # 管理画面一覧
│       └── edit.html         # 管理画面追加フォーム
└── bundle/
    ├── passkey-login.js      # 管理ログイン用 JS
    └── passkey-manage.js     # 管理画面 追加 用 JS
```

### a-blog cms との接続点

| プラグイン側 | a-blog cms 側 | 役割 |
|---|---|---|
| `ServiceProvider extends ACMS_App` | 拡張アプリ ライフサイクル | install / uninstall / activate |
| `$menu = 'passkey_index'` | サイドバー拡張メニュー | リンク自動追加 |
| `InjectTemplate::add('admin-topicpath' / 'admin-main', ...)` | `<!-- BEGIN_MODULE Admin_InjectTemplate -->` | メニュー画面のテンプレ提供 |
| `HookFactory::attach('PasskeyHook', new Hook())` + `afterBuild()` | 出力フィルタ | `/login/` への HTML 注入 |
| `ACMS_POST extends` の 5 クラス | POST ルーティング | `ACMS_POST_Passkey_*` トリガ |
| `ACMS_GET extends` の 1 クラス | GET モジュール解決 | `<!-- BEGIN_MODULE Passkey_Lists -->` |
| `extension/schema` 経由ではなく `install()` で SQL 直 CREATE | `acms_passkey` テーブル | 独立 ER |

---

## 3. 認証フロー

### 3.1 登録フロー（管理画面メニューから自分が登録）

```
ブラウザ                                      サーバ
   │
   │ GET /bid/X/admin/app_passkey_edit/
   │ ─────────────────────────────────────────►
   │                                         InjectTemplate で edit.html を返す
   │ ◄─────────────────────────────────────────
   │
   │ [名前入力] → 「パスキーを追加」クリック
   │
   │ POST /bid/X/admin/app_passkey_edit/
   │   ACMS_POST_Passkey_RegisterOptions=1
   │   formToken=<csrf>
   │ ─────────────────────────────────────────►
   │                                         RegisterOptions::post()
   │                                           - Login::isLoggedIn() / isCSRF() チェック
   │                                           - Engine::getRegisterOptions(SUID, ...)
   │                                             - challenge を発行、session に保存
   │                                             - lbuchs/WebAuthn::getCreateArgs()
   │ ◄───────────── JSON publicKey options ─
   │
   │ navigator.credentials.create({publicKey})
   │   → ブラウザ／OS が認証器（Touch ID 等）に登録要求
   │   → ユーザが承認
   │   → AttestationResponse 返却
   │
   │ POST /bid/X/admin/app_passkey_edit/
   │   ACMS_POST_Passkey_RegisterVerify=1
   │   formToken=<csrf>
   │   clientDataJSON / attestationObject / nickname
   │ ─────────────────────────────────────────►
   │                                         RegisterVerify::post()
   │                                           - Engine::verifyRegister()
   │                                             - session から challenge を取り出して即削除
   │                                             - sessionUid !== SUID なら拒否（他人なりすまし防止）
   │                                             - lbuchs::processCreate() で attestation 検証
   │                                             - acms_passkey に INSERT
   │                                               (user_id, credential_id, public_key,
   │                                                sign_count, aaguid, nickname)
   │ ◄───────────── JSON {ok: true} ─────
   │
   │ location.href = data-redirect (index page)
   ▼
```

### 3.2 認証フロー（管理ログイン画面から）

```
ブラウザ                                      サーバ
   │
   │ GET /login/
   │ ─────────────────────────────────────────►
   │                                         _member-admin/login.html レンダリング
   │                                         Hook::afterBuild()
   │                                           - IS_SYSTEM_LOGIN_PAGE をチェック
   │                                           - <div class="acms-admin-login-main-box-bottom">
   │                                             の直前に admin-login.html を str_replace 注入
   │ ◄──────────── HTML (ID/Pass フォーム + パスキーボタン) ─
   │
   │ 「パスキーでログイン」クリック
   │
   │ POST /login/
   │   ACMS_POST_Passkey_LoginOptions=1
   │   formToken=<csrf>
   │ ─────────────────────────────────────────►
   │                                         LoginOptions::post()
   │                                           - 未ログインチェック
   │                                           - Engine::getLoginOptions()
   │                                             - challenge を発行、session に保存
   │                                             - allowCredentials=[] (usernameless)
   │ ◄───────────── JSON publicKey options ─
   │
   │ navigator.credentials.get({publicKey})
   │   → ブラウザ／OS が認証器でパスキー選択
   │   → AssertionResponse 返却
   │
   │ POST /login/
   │   ACMS_POST_Passkey_LoginVerify=1
   │   credentialId / clientDataJSON /
   │   authenticatorData / signature
   │ ─────────────────────────────────────────►
   │                                         LoginVerify::post()
   │                                           - Engine::verifyLogin()
   │                                             - session から challenge を取り出して即削除
   │                                             - credential_id で DB 逆引き → user_id
   │                                             - user_status='open' & login_expire OK 確認
   │                                             - lbuchs::processGet() で署名検証
   │                                             - sign_count 単調増加チェック（クローン検出）
   │                                             - acms_passkey の sign_count / last_used_at 更新
   │                                           - SQL: user.user_login_datetime 更新
   │                                           - generateSession($uid)     ← 標準ログインと同じ
   │                                           - Webhook::call(BID, 'user', ['user:login'], $uid)
   │ ◄───────────── JSON {ok: true, redirect: '/'} ─
   │
   │ location.href = redirect
   ▼
```

---

## 4. DB スキーマ

```sql
CREATE TABLE acms_passkey (
    passkey_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    passkey_user_id       INT(11)       NOT NULL,             -- acms_user.user_id
    passkey_credential_id VARCHAR(512)  NOT NULL,             -- base64url
    passkey_public_key    TEXT          NOT NULL,             -- COSE 公開鍵 PEM
    passkey_sign_count    INT UNSIGNED  NOT NULL DEFAULT 0,
    passkey_aaguid        VARCHAR(36)   NULL,                 -- 認証器モデル UUID
    passkey_transports    VARCHAR(255)  NULL,                 -- usb,nfc,ble,internal,hybrid（未使用）
    passkey_nickname      VARCHAR(255)  NULL,                 -- ユーザがつける名前
    passkey_last_used_at  DATETIME      NULL,
    passkey_created_at    DATETIME      NOT NULL,
    PRIMARY KEY (passkey_id),
    UNIQUE KEY passkey_credential_id_unique (passkey_credential_id),
    KEY passkey_user_id_index (passkey_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5. エンドポイント一覧

### POST（プラグイン）

| トリガ | クラス | 認可 | 役割 |
|---|---|---|---|
| `ACMS_POST_Passkey_RegisterOptions` | `POST/Passkey/RegisterOptions` | ログイン + CSRF | 登録 challenge 発行 |
| `ACMS_POST_Passkey_RegisterVerify` | `POST/Passkey/RegisterVerify` | ログイン + CSRF | attestation 検証＋保存 |
| `ACMS_POST_Passkey_LoginOptions` | `POST/Passkey/LoginOptions` | 未ログイン + CSRF | 認証 challenge 発行 |
| `ACMS_POST_Passkey_LoginVerify` | `POST/Passkey/LoginVerify` | 未ログイン + CSRF | assertion 検証＋セッション |
| `ACMS_POST_Passkey_Unregister` | `POST/Passkey/Unregister` | ログイン + 自分のもののみ | 削除 |

POST は `ACMS_POST` 標準 CSRF（`formToken`）に乗る。JS は `window.csrfToken` または `<meta name="csrf-token">` から取得して FormData で送る（Favorite プラグインと同じ作法）。

### GET モジュール

| モジュール名 | クラス | 役割 |
|---|---|---|
| `Passkey_Lists` | `GET/Passkey/Lists` | SUID のパスキー一覧（`passkey:loop` ブロックに各レコードを add） |

### 管理画面 URL（プラグインのメニュー機能経由）

| URL | テンプレ |
|---|---|
| `/admin/app_passkey_index/` | `template/admin/index.html`（Passkey_AdminLists / 管理者専用の全ユーザー台帳） |
| `/admin/app_passkey_edit/` | `template/admin/edit.html` |
| `/uid/Y/admin/user_passkey/` | テーマの `admin/user/passkey.html`（`theme-dist/` から配布。Passkey_Lists / 本人のみ） |

---

## 6. セキュリティ

### 守れているもの

| 項目 | 実装 |
|---|---|
| **XSS** | a-blog cms の `Corrector::correct()` がデフォルトで `escape` を付与（自動エスケープ） |
| **ユーザなりすまし** | 登録時 `passkey_user_id = SUID` をサーバ側で固定。クライアントから user_id を送らない |
| **CSRF（登録系）** | `isCSRF()` + `formToken` 必須 |
| **CSRF（認証系）** | 未ログイン経路だが `formToken` を要求（ajax_security_level=2 対応） |
| **正規 URL からの POST のみ受理** | `LoginVerify::isValidPostRoute()` が `Login::canLoginPage(BID, LOGIN_SEGMENT)` を要求 |
| **管理ログイン権限** | `LoginVerify` で `getAdminLoginAuth()` / `user_blog_id` または `user_login_anywhere` / `canAccessAdminLoginFromCurrentIp()` / `checkAllowedDevice()` を全部通すまでセッション発行しない |
| **パスキー登録対象の限定** | `RegisterOptions`/`RegisterVerify` でも上記と同じ管理ログイン適格性を要求。一般読者には登録導線が開かない |
| **削除権限** | `Engine::delete()` は `passkey_user_id = SUID` を WHERE に含む二重チェック |
| **チャレンジ リプレイ** | セッションに保存、verify 時に **先に delete** してから検証（成否を問わず使い捨て） |
| **クローン検出** | `sign_count` 単調増加チェック。古い値が来たら例外 |
| **credential_id 重複登録** | DB UNIQUE 制約 + Engine 内で SELECT 事前チェック |
| **userHandle 照合** | JS が `assertion.response.userHandle` を送り、サーバが `buildUserHandle($uid)` と `hash_equals` で照合 |
| **署名・origin・rpId 検証** | `lbuchs/webauthn` ライブラリが担当（仕様準拠） |
| **HTTPS 必須** | WebAuthn API がブラウザで HTTP を拒否 |
| **WebAuthn エラーの日本語化** | JS の catch 節で `InvalidStateError`（既に登録済み・iCloud Keychain 同期時など）/ `NotAllowedError`（キャンセル・タイムアウト）/ `SecurityError`（HTTPS / ドメイン不一致）を判別して分かりやすい日本語に変換 |

### 残課題（本体組み込み時に解決したい）

| 項目 | 内容 | 対策案 |
|---|---|---|
| **再登録攻撃 / Sudo mode** | セッション乗っ取り後、攻撃者の認証器を登録され永続ログイン化される | パスキー追加・削除・パスワード変更・メアド変更の前に直近の本人確認を要求する `Login::requireRecentAuth($minutes)` 共通ユーティリティを本体に追加したい（複数機能で使い回せる枠） |
| **TFA / 試行ロック合流** | 標準 `ACMS_POST_Member_Signin` の `Tfa::isAvailableAccount()` / `Common::validateLockPost()` 経路に乗っていない | `AuthMethod` インタフェース or `Login::loginAsUser($uid, $method)` 的な共通合流点を本体に作り、Password / SNS / Passkey すべて同じ最終処理に通す |
| **userVerification 既定** | 現状 `preferred` で認証器が UV をスキップする余地あり | 管理ログイン用途では `required` を既定 or 設定可能項目に |
| **user handle のランダム化** | `'acms-user-' . $uid` で uid 値が認証器側のメタデータに残る（軽微） | ランダム値を `passkey_user_handle` カラムに保存し、認証時にそれと照合する設計に |
| **rpId 明示設定** | 未設定時は `HTTP_HOST` から自動決定。プロキシ越し／複数ドメインで事故るリスク | プラグイン側では起動時に notice ログを出す。本番では `config.system.yaml` に `passkey_rp_id: example.com` を明記推奨。Issue として「rpId / allowed-origin の設定 UI を admin/config/login.html に追加」を起こしたい |

---

## 7. プラグインの制約（＝本体に入れたい理由）

プラグインとして動くものは作れていますが、**本来 a-blog cms 本体に入っていれば綺麗に書ける部分**を、プラグインから外側で頑張った箇所がいくつかあります。

### 7.1 `/login/` 画面への UI 注入
- 標準 SNS は `themes/system/_member-admin/parts/login/sns.html` で `Touch_SnsAdminLogin` + `Member_Sns` モジュールが綺麗に組み込まれている
- プラグインからはこの差し込み口がなく、`afterBuild` Hook で **HTML を str_replace で書き換える** という強引な手段に
- **本体組み込み案**：`_member-admin/login.html` に `<!-- BEGIN_MODULE Admin_InjectTemplate id="admin-login-actions" --><!-- END_MODULE -->` のような正規拡張点を作ってもらえると、プラグインから素直に `inject->add()` できる

### 7.2 URL コンテキストの追加
- 標準は `EMAIL_UPDATE_SEGMENT = 'mypage/update-email'` のように `LoginServiceProvider` で定数 + ルーティングが hardcode
- プラグインから新規 URL コンテキスト（例：`mypage/passkey`）を追加できず、結果として管理画面のメニュー URL（`/admin/app_passkey_index/` ＝拡張アプリのメニューが提供する標準作法）を採用するしかなかった
- **本体組み込み案**：`PASSKEY_SETTINGS_SEGMENT` を `LoginServiceProvider` に追加 + `tpl_passkey-settings: _member/passkey.html` を既定化すれば、会員向けにも `/mypage/passkey/` で公開できる

### 7.3 ログインゲートの統合
- `ACMS_POST_Member_Signin` は `canAccessSigninFromCurrentIp()` / `validateLockPost()` / `Tfa::isAvailableAccount()` をワンパスで通している
- プラグインの `LoginVerify` で同等を呼ぶには **すべて自前で配線が必要** で、もれが起きやすい
- **本体組み込み案**：`AuthMethod` インタフェース or `Login::loginAsUser($uid, $method)` 的な API を整備して、各認証方式から共通の最終処理を呼ぶ形にすると、Passkey/SNS/Password で安全な合流点が作れる

### 7.4 管理画面 UI 統合
- 標準 SNS は管理画面のユーザ編集（`admin/user/edit.html`）に「Google 認証」「LINE 認証」の認証/解除ボタンが組み込まれている
- 同じ場所に「パスキー」行を増やすには `themes/system/admin/user/edit.html` を直接編集するしかない（プラグインからは手が届かない）
- **本体組み込み案**：`admin-user-edit-auth` のような inject ポイントを `edit.html` に置く、または最初から SNS と並列で Passkey 行を持つ

### 7.5 設定 UI
- 標準 SNS は `themes/system/admin/config/login.html` に snslogin の ON/OFF や対象権限のセレクトが組み込まれている
- プラグインから同じ場所への inject ポイントがないため、現状は設定 UI 自体を持っていない（インストールしたら即 ON、`config.system.yaml` で上書き可能のみ）
- **本体組み込み案**：admin/config/login.html にも inject ポイント、または Passkey 用の専用設定セクション

### 7.6 再認証（Sudo mode）の枠
- パスキー追加・削除・パスワード変更・メアド変更などは「直近の本人認証」を要求するのがベストプラクティス
- a-blog cms 共通機構として枠がないため、各プラグインが個別実装することになり実装にバラつきが出やすい
- **本体組み込み案**：`Login::requireRecentAuth($minutesAgo)` 的なユーティリティを `Services/Login` に追加

---

## 8. 本体組み込み時の設計提案（叩き台）

PR/Issue にする場合の骨子。プラグイン版コードを namespace 付け替えで本体側に移植する形で書ける部分が多いです。

1. **定数追加**（`Services/Login/LoginServiceProvider.php`）
   ```php
   define('PASSKEY_LOGIN_SEGMENT', 'login');           // 管理ログインに同居（既存）
   define('PASSKEY_SETTINGS_SEGMENT', 'mypage/passkey'); // 会員向け管理 URL
   ```

2. **テンプレ既定値**（`config.system.default.yaml`）
   ```yaml
   tpl_passkey-settings: _member/passkey.html
   ```

3. **`ACMS_GET_Admin_User_Passkey`**（`php/ACMS/GET/Admin/User/Passkey.php`）
   - 標準 SNS の `Member_Sns` と同等の `Passkey_Member` GET モジュール
   - `admin/user/edit.html` に組み込み

4. **`ACMS_POST_Member_Passkey_*`**（`php/ACMS/POST/Member/Passkey/`）
   - `RegisterOptions`, `RegisterVerify`, `LoginOptions`, `LoginVerify`, `Unregister`
   - 標準 `ACMS_POST_Member_*` と並列

5. **`acms_user.user_passkey_*`** 列 or 独立 `acms_passkey` テーブル
   - 1 ユーザ複数パスキーが必要なので、独立テーブルが妥当

6. **`admin/config/login.html` に**「パスキーログインを有効化」「ユーザ検証 required/preferred/discouraged」のフォーム追加

7. **`_member-admin/login.html` と `_member/parts/signin/index.html` に**`Touch_PasskeyLogin` モジュール wrapped で「パスキーでログイン」ボタンを標準配置

8. **`navigator.credentials.get({mediation: 'conditional'})`** で password 入力欄に autofill ヒントを出す（一段上の UX）

9. **Sudo mode ユーティリティ**（`Services/Login/Helper.php`）追加
   - `requireRecentAuth(int $minutes)` でパスキー追加・パスワード変更などをガード

10. **依存ライブラリ**は `lbuchs/webauthn` でも、より一般的な `web-auth/webauthn-lib` でも選べる。このプラグインでは `lbuchs/webauthn` を選択（依存少・小ぶり）

---

## 9. 動作要件

- PHP 8.0 以上（ext-openssl, ext-mbstring, ext-sodium 推奨）
- HTTPS 必須（WebAuthn API がブラウザで HTTP を拒否）
- ブラウザは WebAuthn Level 2 対応（最新の Chrome / Safari / Edge / Firefox）
- 認証器は platform（Touch ID / Face ID / Hello / Android 生体）または roaming（YubiKey 等）

---

## 10. インストール

```
1. extension/plugins/Passkey/ をプロジェクトに配置
2. cd extension/plugins/Passkey && composer install --no-dev
3. 管理画面 > 拡張アプリ > Passkey をインストール → 有効化
4. サイドバー「拡張メニュー > Passkey」から自分のパスキーを登録
5. ログアウト → /login/ で「パスキーでログイン」を試す
```

テーマ修正・YAML 編集ともに **不要**。

---

## 11. 参考

- WebAuthn 仕様: https://www.w3.org/TR/webauthn/
- lbuchs/webauthn: https://github.com/lbuchs/WebAuthn
- AAGUID 辞書（認証器名 lookup の元）: https://github.com/passkeydeveloper/passkey-authenticator-aaguids
- a-blog cms 拡張アプリ開発: https://developer.a-blogcms.jp/document/develop_apps/
- a-blog cms フック処理: https://developer.a-blogcms.jp/document/user_hook/
