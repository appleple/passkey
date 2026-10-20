<?php

namespace Acms\Plugins\Passkey;

use Field;
use Acms\Services\Facades\Login;

/**
 * a-blog cms のバージョン差を吸収する互換レイヤー。
 *
 * Ver. 3.1 で管理ログイン（/login/）と会員サインイン（/signin/）が分離され、
 * 関連 API が大きく変わった。3.0 以前との差分はすべてこのクラスに集約する。
 *
 * | 項目             | 3.1 以降                        | 3.0 以前                  |
 * |------------------|---------------------------------|---------------------------|
 * | ログインPOST     | ACMS_POST_Member_Admin_Login    | ACMS_POST_Login_Auth      |
 * | ログイン画面判定 | IS_SYSTEM_LOGIN_PAGE            | なし（マークアップで判定）|
 * | セッション生成   | generateSession($uid)           | generateSession($user)    |
 * | ログイン可能権限 | Login::getAdminLoginAuth()      | なし（制限なし）          |
 * | ログイン判定     | Login::isLoggedIn()             | なし（SUID で判定）       |
 */
class Compat
{
    /**
     * 3.0 以前かどうか。
     * 管理ログイン専用の POST モジュールの有無で判定する（3.1 の会員機能刷新で追加された）。
     *
     * @return bool
     */
    public static function isLegacy(): bool
    {
        return !class_exists('ACMS_POST_Member_Admin_Login');
    }

    /**
     * ログイン済みか。
     * 3.0 の Login サービスは isLoggedIn() を持たないため SUID で判定する。
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        if (!defined('SUID') || !SUID) {
            return false;
        }
        if (method_exists(Login::class, 'isLoggedIn')) {
            try {
                return (bool)Login::isLoggedIn();
            } catch (\Throwable $e) {
                // サービス側に無ければ SUID の判定に委ねる
            }
        }
        return true;
    }

    /**
     * 現在のリクエストがログイン画面かどうか。
     * 3.0 には IS_SYSTEM_LOGIN_PAGE が無いため、レスポンス中のログインボタンで判定する。
     *
     * @param string $res レスポンス HTML
     * @return bool
     */
    public static function isLoginPage(string $res): bool
    {
        if (defined('IS_SYSTEM_LOGIN_PAGE') && IS_SYSTEM_LOGIN_PAGE) {
            return true;
        }
        if (!self::isLegacy()) {
            return false;
        }
        // 3.0: ログイン認証の POST モジュールを持つフォームがあればログイン画面
        return strpos($res, 'ACMS_POST_Login_Auth') !== false;
    }

    /**
     * パスキーログインボタンを差し込む位置（この文字列の直前に挿入する）。
     * 先に見つかったものを使う。
     *
     * @return string[]
     */
    public static function loginButtonMarkers(): array
    {
        return [
            // 3.1 以降の管理ログイン画面
            '<div class="acms-admin-login-main-box-bottom">',
            // 3.0: SNS ログインの「もしくは」区切り（SNS ログインが有効な場合）
            '<p class="acms-admin-border">',
            // 3.0: ログインボタンの段落（最後の砦）
            '<p class="loginAction">',
        ];
    }

    /**
     * 管理ログインの POST モジュールかどうか（設定推奨モーダルの検知用）。
     *
     * @param object $module
     * @return bool
     */
    public static function isAdminLoginModule($module): bool
    {
        foreach (['ACMS_POST_Member_Admin_Login', 'ACMS_POST_Login_Auth'] as $class) {
            if (class_exists($class) && $module instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * ログインセッションを生成する。
     * 3.0 はユーザー配列、3.1 以降はユーザー ID を受け取る。
     *
     * @param array<string,mixed> $user user テーブルの 1 行
     * @return void
     */
    public static function generateLoginSession(array $user): void
    {
        if (self::isLegacy()) {
            generateSession($user);
            return;
        }
        generateSession(intval($user['user_id']));
    }

    /**
     * ログインを許可する権限の一覧。
     *
     * 3.0 はログインが一本化されており本体も権限で制限していないため、
     * パスキー経路でも制限しない（読者もログインできるのは本体仕様どおり）。
     * 3.1 以降は本体の管理ログイン可能権限に従う。
     *
     * @return string[]|null null なら権限による制限なし
     */
    public static function allowedLoginAuths(): ?array
    {
        if (self::isLegacy()) {
            return null;
        }
        try {
            return Login::getAdminLoginAuth();
        } catch (\Throwable $e) {
            return ['administrator', 'editor', 'contributor'];
        }
    }

    /**
     * 端末制限を満たしているか。
     *
     * パスワードログインが課している制限をパスキー経路でも必ず踏襲する
     * （省略すると端末制限の抜け道になる）。
     * 3.1 以降は本体の判定に委ね、3.0 は本体と同じロジックを再現する。
     *
     * @param array<string,mixed> $user
     * @return bool
     */
    public static function isAllowedDevice(array $user): bool
    {
        if (!self::isLegacy()) {
            try {
                return (bool)Login::checkAllowedDevice($user);
            } catch (\Throwable $e) {
                return true; // 本体に判定が無ければ制限なしとみなす
            }
        }

        // 3.0: ACMS_POST_Login_Auth::postValidate() と同じ判定
        //      管理者は対象外。Cookie と UA のハッシュが一致しなければ拒否
        if (($user['user_auth'] ?? '') === 'administrator') {
            return true;
        }
        if (($user['user_login_terminal_restriction'] ?? '') !== 'on') {
            return true;
        }
        if (!class_exists('Field') || !defined('UA')) {
            // 判定材料が無い場合は安全側に倒して拒否する
            return false;
        }
        $cookie = Field::singleton('cookie');
        return $cookie->get('acms_config_login_terminal_restriction') === sha1('permission' . UA);
    }
}
