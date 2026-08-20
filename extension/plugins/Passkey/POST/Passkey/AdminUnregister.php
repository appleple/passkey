<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use ACMS_POST;
use AcmsLogger;
use Acms\Services\Facades\Login;

/**
 * 管理者による任意ユーザーのパスキー削除（台帳画面用）。
 * 退職者のパスキー整理・端末紛失時の失効に使う。
 * <button type="submit" name="ACMS_POST_Passkey_AdminUnregister" value="{passkey_id}"> から呼ばれる
 */
class AdminUnregister extends ACMS_POST
{
    public function post()
    {
        if (!Login::isLoggedIn() || !SUID) {
            return $this->Post;
        }
        if (!sessionWithAdministration()) {
            return $this->Post;
        }

        $passkeyId = intval($this->Post->get('passkey_id'));
        if ($passkeyId === 0) {
            // submit ボタンの value をそのまま受け取れない環境用に、フォーム送信値を再取得
            $passkeyId = intval($_POST['ACMS_POST_Passkey_AdminUnregister'] ?? 0);
        }
        if ($passkeyId === 0) {
            return $this->Post;
        }

        /** @var \Acms\Plugins\Passkey\Engine $engine */
        $engine = App::make('passkey.engine');
        $deleted = $engine->deleteById($passkeyId);

        if ($deleted !== null && class_exists('AcmsLogger')) {
            AcmsLogger::info('管理者がパスキーを削除しました', [
                'by_uid' => SUID,
                'target_uid' => $deleted['passkey_user_id'],
                'passkey_id' => $passkeyId,
                'nickname' => $deleted['passkey_nickname'],
            ]);
        }

        // 直前ページに戻す
        $back = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] ? $_SERVER['HTTP_REFERER'] : '/';
        redirect($back);
    }
}
