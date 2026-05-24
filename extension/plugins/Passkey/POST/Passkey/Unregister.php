<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use ACMS_POST;
use AcmsLogger;
use Acms\Services\Facades\Login;

/**
 * 標準 SNS の Unregister と同じ作法。
 * <button type="submit" name="ACMS_POST_Passkey_Unregister" value="{passkey_id}"> から呼ばれる
 */
class Unregister extends ACMS_POST
{
    public function post()
    {
        if (!Login::isLoggedIn() || !SUID) {
            return $this->Post;
        }
        // 削除は機能 OFF でも許可（無効化後に既存ユーザが解除できるように）

        $passkeyId = intval($this->Post->get('passkey_id'));
        if ($passkeyId === 0) {
            // submit ボタンの value をそのまま受け取れない環境用に、フォーム送信値を再取得
            $passkeyId = intval($_POST['ACMS_POST_Passkey_Unregister'] ?? 0);
        }
        if ($passkeyId === 0) {
            return $this->Post;
        }

        /** @var \Acms\Plugins\Passkey\Engine $engine */
        $engine = App::make('passkey.engine');
        $deleted = $engine->delete($passkeyId, intval(SUID));

        if ($deleted && class_exists('AcmsLogger')) {
            AcmsLogger::info('パスキーを削除しました', [
                'uid' => SUID,
                'passkey_id' => $passkeyId,
            ]);
        }

        // 直前ページに戻す
        $back = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] ? $_SERVER['HTTP_REFERER'] : '/';
        redirect($back);
    }
}
