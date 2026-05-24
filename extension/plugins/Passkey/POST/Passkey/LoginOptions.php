<?php

namespace Acms\Plugins\Passkey\POST\Passkey;

use App;
use ACMS_POST;
use Acms\Services\Facades\Login;

class LoginOptions extends ACMS_POST
{
    public function post()
    {
        try {
            if (Login::isLoggedIn()) {
                throw new \RuntimeException('既にログイン済みです。');
            }

            /** @var \Acms\Plugins\Passkey\Engine $engine */
            $engine = App::make('passkey.engine');
            $options = $engine->getLoginOptions();

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
