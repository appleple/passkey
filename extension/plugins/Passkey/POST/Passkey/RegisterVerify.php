<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use ACMS_POST;
use AcmsLogger;
use Acms\Services\Facades\Login;
use Acms\Services\Facades\Common;
use Acms\Plugins\Passkey\Engine;
use Acms\Plugins\Passkey\Compat;

class RegisterVerify extends ACMS_POST
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

            $clientDataJSON = isset($_POST['clientDataJSON']) ? (string)$_POST['clientDataJSON'] : '';
            $attestationObject = isset($_POST['attestationObject']) ? (string)$_POST['attestationObject'] : '';
            $nickname = isset($_POST['nickname']) ? trim((string)$_POST['nickname']) : '';

            if ($clientDataJSON === '' || $attestationObject === '') {
                throw new \RuntimeException('リクエストデータが不正です。');
            }
            if ($nickname === '') {
                throw new \RuntimeException('パスキーの名前を入力してください。');
            }

            /** @var \Acms\Plugins\Passkey\Engine $engine */
            $engine = App::make('passkey.engine');

            // 管理ログイン適格性チェック（パスキー登録時にも実施。RegisterOptions で許可されたユーザでも、
            // verify までに状態が変わっている可能性があるため二重チェック）
            $user = $engine->loadUser(intval(SUID));
            if ($user === null) {
                throw new \RuntimeException('ユーザーが無効です。');
            }
            $errors = Engine::checkAdminLoginEligibility($user);
            if (!empty($errors)) {
                throw new \RuntimeException(implode('、', $errors));
            }

            $result = $engine->verifyRegister(intval(SUID), $clientDataJSON, $attestationObject, $nickname);

            if (class_exists('AcmsLogger')) {
                AcmsLogger::info('パスキーを登録しました', [
                    'uid' => SUID,
                    'passkey_id' => $result['passkey_id'],
                ]);
            }

            $this->jsonResponse([
                'ok' => true,
                'passkey_id' => $result['passkey_id'],
            ]);
        } catch (\Throwable $e) {
            if (class_exists('AcmsLogger')) {
                AcmsLogger::warning('パスキー登録に失敗しました: ' . $e->getMessage(), Common::exceptionArray($e));
            }
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
