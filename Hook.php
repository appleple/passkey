<?php

namespace Acms\Plugins\Passkey;

class Hook
{
    /**
     * 管理ログイン画面（/login/）のレスポンス HTML にパスキーログインボタンを差し込む。
     * テーマを触らずプラグインだけで完結させるための仕掛け。
     *
     * @param string &$res
     * @return void
     */
    public function afterBuild(&$res)
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
}
