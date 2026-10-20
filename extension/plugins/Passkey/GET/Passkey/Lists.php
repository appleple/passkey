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
 * セッションユーザ（SUID）のパスキー一覧を返す GET モジュール。
 *
 * テンプレ側で:
 *   <!-- BEGIN_MODULE Passkey_Lists -->
 *     <!-- BEGIN_IF [{passkey_count}/gt/0] -->
 *       <table>
 *         <!-- BEGIN passkey:loop -->
 *           <tr><td>{passkey_nickname}</td>...</tr>
 *         <!-- END passkey:loop -->
 *       </table>
 *     <!-- ELSE -->
 *       <p>登録済みのパスキーはありません。</p>
 *     <!-- END_IF -->
 *   <!-- END_MODULE Passkey_Lists -->
 */
class Lists extends ACMS_GET
{
    public function get()
    {
        if (!Compat::isLoggedIn() || !SUID) {
            return '';
        }

        $tpl = new Template($this->tpl, new ACMS_Corrector());

        /** @var \Acms\Plugins\Passkey\Engine $engine */
        $engine = App::make('passkey.engine');
        $rows = $engine->listByUser(intval(SUID));

        foreach ($rows as $row) {
            $aaguid = $row['passkey_aaguid'] ?: '';
            $label = Engine::aaguidLabel($aaguid);
            $tpl->add('passkey:loop', array_merge(
                [
                    'passkey_id' => $row['passkey_id'],
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
        ]);

        return $tpl->get();
    }
}
