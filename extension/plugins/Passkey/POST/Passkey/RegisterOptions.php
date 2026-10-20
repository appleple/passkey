<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use ACMS_POST;
use ACMS_RAM;
use Acms\Services\Facades\Login;
use Acms\Plugins\Passkey\Engine;
use Acms\Plugins\Passkey\Compat;

class RegisterOptions extends ACMS_POST
{
    public function post()
    {
        try {
            if (!Compat::isLoggedIn() || !SUID) {
                throw new \RuntimeException('ログインしてください。');
            }
            if (isCSRF()) {
                throw new \RuntimeException('不正なリクエストです。');
            }

            $uid = intval(SUID);

            /** @var \Acms\Plugins\Passkey\Engine $engine */
            $engine = App::make('passkey.engine');

            // 管理ログイン適格性チェック（パスキーは管理ログインの代替なので、その権限が無いユーザの登録は許可しない）
            $user = $engine->loadUser($uid);
            if ($user === null) {
                throw new \RuntimeException('ユーザーが無効です。');
            }
            $errors = Engine::checkAdminLoginEligibility($user);
            if (!empty($errors)) {
                throw new \RuntimeException(implode('、', $errors));
            }

            $userCode = ACMS_RAM::userCode($uid);
            $userName = ACMS_RAM::userName($uid);
            $displayName = $userName ?: ('user-' . $uid);
            $loginId = $userCode ?: ('user-' . $uid);

            $options = $engine->getRegisterOptions($uid, $loginId, $displayName);

            $this->jsonResponse([
                'ok' => true,
                'publicKey' => $options->publicKey,
            ]);
        } catch (\Throwable $e) {
            $this->jsonError(400, $e->getMessage());
        }
        exit;
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function jsonResponse(array $payload): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function jsonError(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
}
