<?php

namespace Acms\Plugins\Passkey;

class Hook
{
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
    }

    /**
     * 管理ログイン画面（/login/）にパスキーログインボタンを差し込む。
     *
     * @param string &$res
     * @return void
     */
    protected function injectLoginButton(&$res)
    {
        if (!defined('IS_SYSTEM_LOGIN_PAGE') || !IS_SYSTEM_LOGIN_PAGE) {
            return;
        }
        $marker = '<div class="acms-admin-login-main-box-bottom">';
        $pos = strpos($res, $marker);
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
}
