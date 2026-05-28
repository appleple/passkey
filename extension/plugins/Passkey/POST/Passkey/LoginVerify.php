<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use DB;
use SQL;
use ACMS_POST;
use ACMS_RAM;
use AcmsLogger;
use Acms\Services\Facades\Login;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Webhook;
use Acms\Plugins\Passkey\Engine;

class LoginVerify extends ACMS_POST
{
    /**
     * 正常なルート（管理ログイン URL）からのみ受け付ける。
     * 旧バージョンの a-blog cms に Login::canLoginPage が無い場合は素通し
     * （後続の Login::isLoggedIn 等のチェックは継続）。
     */
    protected function isValidPostRoute(): bool
    {
        try {
            return Login::canLoginPage(BID, LOGIN_SEGMENT);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function post()
    {
        try {
            if (Login::isLoggedIn()) {
                throw new \RuntimeException('既にログイン済みです。');
            }

            $credentialId = isset($_POST['credentialId']) ? (string)$_POST['credentialId'] : '';
            $clientDataJSON = isset($_POST['clientDataJSON']) ? (string)$_POST['clientDataJSON'] : '';
            $authenticatorData = isset($_POST['authenticatorData']) ? (string)$_POST['authenticatorData'] : '';
            $signature = isset($_POST['signature']) ? (string)$_POST['signature'] : '';
            $userHandle = isset($_POST['userHandle']) ? (string)$_POST['userHandle'] : null;

            if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
                throw new \RuntimeException('リクエストデータが不正です。');
            }

            /** @var \Acms\Plugins\Passkey\Engine $engine */
            $engine = App::make('passkey.engine');
            $user = $engine->verifyLogin($credentialId, $clientDataJSON, $authenticatorData, $signature, $userHandle);
            $uid = intval($user['user_id']);

            // 標準管理ログイン相当のガード
            // （user_auth / user_blog_id / IP / 端末制限を満たすか）
            $eligibilityErrors = Engine::checkAdminLoginEligibility($user);
            if (!empty($eligibilityErrors)) {
                if (class_exists('AcmsLogger')) {
                    AcmsLogger::warning('パスキー認証は成功したが管理ログインを許可しなかった', [
                        'id' => $uid,
                        'errors' => $eligibilityErrors,
                    ]);
                }
                // 情報漏えいを避けるため、JS にはまとめて 1 つのメッセージで返す
                throw new \RuntimeException('ログインが許可されていません。');
            }

            // user_login_datetime 更新
            $sql = SQL::newUpdate('user');
            $sql->addUpdate('user_login_datetime', date('Y-m-d H:i:s', REQUEST_TIME));
            $sql->addWhereOpr('user_id', $uid);
            DB::query($sql->get(dsn()), 'exec');

            // セッション生成（標準ログインと同じ）
            generateSession($uid);

            $userName = ACMS_RAM::userName($uid);
            if (class_exists('AcmsLogger')) {
                AcmsLogger::info('ユーザー「' . $userName . '」がパスキーでサインインしました', [
                    'id' => $uid,
                ]);
            }
            Webhook::call(BID, 'user', ['user:login'], $uid);

            // リダイレクト先決定
            $redirect = isset($_POST['redirect']) ? (string)$_POST['redirect'] : '';
            if ($redirect === '') {
                $redirect = acmsLink([
                    'protocol' => (SSL_ENABLE && (config('login_ssl') === 'on')) ? 'https' : 'http',
                    'bid' => BID,
                    'query' => [],
                ], false);
            }

            $this->jsonResponse([
                'ok' => true,
                'redirect' => $redirect,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('AcmsLogger')) {
                AcmsLogger::warning('パスキー認証に失敗しました: ' . $e->getMessage(), Common::exceptionArray($e));
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
