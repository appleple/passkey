<?php

namespace Acms\Plugins\Passkey;

use App;
use Acms\Services\Facades\Login;

class Hook
{
    /**
     * 設定推奨モーダルの「ログイン直後 1 回だけ」を実現する一時 Cookie 名。
     * 管理ログイン POST で立て、ログイン後最初の管理画面表示で消費する。値は '1' のみ。
     *
     * @var string
     */
    const PROMPT_PENDING_COOKIE = 'acms_passkey_prompt_pending';

    /**
     * POST モジュール処理前のフック。
     * 管理ログインの送信を検知したら、設定推奨モーダル用の一時 Cookie を立てる。
     * （認証の成否はここでは分からないが、表示側でログイン済みチェックをするので問題ない）
     *
     * @param \ACMS_POST $thisModule
     * @return void
     */
    public function beforePostFire($thisModule)
    {
        if (Compat::isAdminLoginModule($thisModule)) {
            $this->setPromptPendingCookie();
        }
    }

    /**
     * レスポンス HTML への差し込み。テーマを触らずプラグインだけで完結させるための仕掛け。
     *
     * @param string &$res
     * @return void
     */
    public function afterBuild(&$res)
    {
        $this->injectLoginButton($res);
        $this->injectUserEditRow($res);
        $this->injectRegistrationPrompt($res);
    }

    /**
     * 管理ログイン画面（/login/）にパスキーログインボタンを差し込む。
     *
     * @param string &$res
     * @return void
     */
    protected function injectLoginButton(&$res)
    {
        if (!Compat::isLoginPage($res)) {
            return;
        }
        // バージョンごとにマークアップが異なるため、候補を順に試して差し込み位置を決める
        $pos = false;
        foreach (Compat::loginButtonAnchors() as $anchor) {
            $found = strpos($res, $anchor['marker']);
            if ($found === false) {
                continue;
            }
            if ($anchor['position'] === 'before') {
                $pos = $found;
                break;
            }
            // 'after-paragraph': 目印を含む段落の終わりに差し込む
            $close = strpos($res, '</p>', $found);
            if ($close === false) {
                continue;
            }
            $pos = $close + strlen('</p>');
            break;
        }
        if ($pos === false) {
            return;
        }
        $snippet = @file_get_contents(dirname(__FILE__) . '/template/admin-login.html');
        if ($snippet === false || $snippet === '') {
            return;
        }
        $res = substr($res, 0, $pos) . $snippet . "\n" . substr($res, $pos);
    }

    /**
     * ユーザー編集画面（/admin/user_edit/）の基本設定に「パスキー：設定する」行を差し込む。
     * ２段階認証行の直後（無ければパスワード確認行の直後）に入れる。
     * テーマが既に user_passkey へのリンクを持っている場合は二重表示になるため何もしない。
     *
     * @param string &$res
     * @return void
     */
    protected function injectUserEditRow(&$res)
    {
        if (!defined('ADMIN') || ADMIN !== 'user_edit') {
            return;
        }
        if (!defined('UID') || !UID) {
            // 新規ユーザー作成画面では出さない（保存後に設定してもらう）
            return;
        }
        if (strpos($res, 'admin/user_passkey/') !== false) {
            // テーマカスタマイズ等で設置済み
            return;
        }

        // アンカー：２段階認証の設定リンク → 無ければパスワード確認の入力欄
        $pos = false;
        foreach (['admin/user_tfa/', 'name="retype_pass"'] as $anchor) {
            $pos = strpos($res, $anchor);
            if ($pos !== false) {
                break;
            }
        }
        if ($pos === false) {
            return;
        }
        $trEnd = strpos($res, '</tr>', $pos);
        if ($trEnd === false) {
            return;
        }
        $insertAt = $trEnd + strlen('</tr>');

        $url = acmsLink([
            'bid' => BID,
            'uid' => UID,
            'admin' => 'user_passkey',
        ]);
        $row = "\n" . '<tr><th>パスキー'
            . '<i class="acms-admin-icon-tooltip js-acms-tooltip-hover" data-acms-tooltip="パスキーの設定を行います。"></i></th>'
            . '<td><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="acms-admin-btn acms-admin-btn-info">設定する</a></td></tr>';

        $res = substr($res, 0, $insertAt) . $row . substr($res, $insertAt);
    }

    /**
     * パスキー未登録のログインユーザーに、設定をすすめるモーダルを管理画面へ差し込む。
     * - config「passkey_login_prompt」が on（未設定時のデフォルトも on）のときのみ
     * - 「あとで」やオーバーレイクリックで閉じると localStorage で30日間非表示（JS 側）
     * - パスキー関連ページでは出さない（設定中にかぶせない）
     *
     * @param string &$res
     * @return void
     */
    protected function injectRegistrationPrompt(&$res)
    {
        if (!Compat::isLoggedIn() || !SUID) {
            return;
        }
        if (empty($_COOKIE[self::PROMPT_PENDING_COOKIE])) {
            // ログイン直後ではない（通常の画面遷移）
            return;
        }
        // ログイン1回につき判定1回。ここで消費する
        // （login_auto_redirect が on だとログイン直後はフロントページに戻るため、
        //   管理画面に限定せず最初に表示されたページで出す）
        $this->clearPromptPendingCookie();

        if (defined('ADMIN') && in_array(ADMIN, ['user_passkey', 'app_passkey_index', 'app_passkey_edit'], true)) {
            return;
        }
        if (config('passkey_login_prompt', 'on') !== 'on') {
            return;
        }

        /** @var \Acms\Plugins\Passkey\Engine $engine */
        $engine = App::make('passkey.engine');
        if (!empty($engine->getCredentialIdsByUser(intval(SUID)))) {
            // 登録済みユーザーには出さない
            return;
        }

        $pos = strripos($res, '</body>');
        if ($pos === false) {
            return;
        }
        $snippet = @file_get_contents(dirname(__FILE__) . '/template/admin-prompt.html');
        if ($snippet === false || $snippet === '') {
            return;
        }
        $url = acmsLink([
            'bid' => BID,
            'uid' => intval(SUID),
            'admin' => 'user_passkey',
        ]);
        $days = intval(config('passkey_login_prompt_suppress_days', '30'));
        if ($days < 1) {
            $days = 30;
        }
        $snippet = str_replace(
            ['{{PASSKEY_URL}}', '{{SUID}}', '{{SUPPRESS_DAYS}}'],
            [htmlspecialchars($url, ENT_QUOTES), intval(SUID), $days],
            $snippet
        );

        $res = substr($res, 0, $pos) . $snippet . "\n" . substr($res, $pos);
    }

    /**
     * 設定推奨モーダル用の一時 Cookie を立てる（有効期限 1 時間）
     *
     * @return void
     */
    protected function setPromptPendingCookie()
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::PROMPT_PENDING_COOKIE, '1', [
            'expires' => REQUEST_TIME + 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 設定推奨モーダル用の一時 Cookie を消す
     *
     * @return void
     */
    protected function clearPromptPendingCookie()
    {
        unset($_COOKIE[self::PROMPT_PENDING_COOKIE]);
        if (headers_sent()) {
            return;
        }
        setcookie(self::PROMPT_PENDING_COOKIE, '', [
            'expires' => REQUEST_TIME - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
