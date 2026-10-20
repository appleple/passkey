<?php

namespace Acms\Plugins\Passkey\GET\Passkey;

use App;
use ACMS_GET;
use Template;
use ACMS_Corrector;
use Acms\Services\Facades\Login;
use Acms\Plugins\Passkey\Engine;
use Acms\Plugins\Passkey\Compat;

/**
 * 全ユーザーの登録済みパスキー一覧（管理者専用の台帳）。
 * 標準のユーザー一覧（Admin_User_Index）に表示を合わせる。
 *
 * テンプレ側で:
 *   <!-- BEGIN_MODULE Passkey_AdminLists -->
 *     <!-- BEGIN_IF [{passkey_count}/gt/0] -->
 *       <table>
 *         <!-- BEGIN passkey:loop -->
 *           <tr>
 *             <td>{passkey_uid}</td>
 *             <td><img src="%{ARCHIVES_DIR}{user_icon}">{user_name} / {user_code}</td>
 *             <td><!-- BEGIN auth#administrator -->管理者<!-- END auth#administrator -->...</td>
 *             <td>{passkey_created_date}<br>{passkey_created_time}</td>
 *           </tr>
 *         <!-- END passkey:loop -->
 *       </table>
 *     <!-- ELSE -->
 *       <p>登録済みのパスキーはありません。</p>
 *     <!-- END_IF -->
 *   <!-- END_MODULE Passkey_AdminLists -->
 */
class AdminLists extends ACMS_GET
{
    public function get()
    {
        if (!Compat::isLoggedIn() || !SUID) {
            return '';
        }
        if (!sessionWithAdministration()) {
            return '';
        }

        $tpl = new Template($this->tpl, new ACMS_Corrector());

        /** @var \Acms\Plugins\Passkey\Engine $engine */
        $engine = App::make('passkey.engine');
        $rows = $engine->listAll();

        foreach ($rows as $row) {
            $uid = intval($row['passkey_user_id']);
            $userExists = !empty($row['user_id']);

            // 権限ラベル（ユーザー一覧と同じ auth# ブロック方式。ロール考慮）
            if ($userExists) {
                $auth = function_exists('getAuthConsideringRole')
                    ? getAuthConsideringRole($uid)
                    : $row['user_auth'];
                if ($auth) {
                    $tpl->add('auth#' . $auth);
                }
            }

            $aaguid = $row['passkey_aaguid'] ?: '';
            $label = Engine::aaguidLabel($aaguid);
            $tpl->add('passkey:loop', array_merge(
                [
                    'passkey_id' => $row['passkey_id'],
                    'passkey_uid' => $uid,
                    'user_name' => $row['user_name'] ?: '（削除済みユーザー #' . $uid . '）',
                    'user_code' => $row['user_code'] ?: '',
                    'user_icon' => function_exists('loadUserIcon') ? loadUserIcon($uid) : '',
                    'passkey_nickname' => $row['passkey_nickname'] ?: 'パスキー #' . $row['passkey_id'],
                    'passkey_aaguid' => $aaguid,
                    'passkey_aaguid_label' => $label ?: '不明',
                ],
                Engine::datetimeVars('passkey_created', $row['passkey_created_at']),
                Engine::datetimeVars('passkey_last_used', $row['passkey_last_used_at'])
            ));
        }
        $tpl->add(null, [
            'passkey_count' => count($rows),
            // フック拡張が無効だとログインボタンもモーダルも動かないため画面で警告する
            'hook_disabled' => (!defined('HOOK_ENABLE') || !HOOK_ENABLE) ? '1' : '',
        ]);

        return $tpl->get();
    }
}
