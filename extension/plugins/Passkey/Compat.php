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
        // 本体の Login::isLoggedIn() は引数なしだとセッションユーザー ID の検査と
        // 等価なので、SUID をそのまま使う。ファサードは __callStatic で動的解決する
        // ため method_exists() による存在確認ができず、分岐しても意味がない。
        return defined('SUID') && (bool)SUID;
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
     * パスキーログインボタンの差し込み位置の候補。先に見つかったものを使う。
     *
     * position は挿入方法：
     *   'before'          … marker の直前に挿入
     *   'after-paragraph' … marker 以降で最初に現れる </p> の直後に挿入
     *
     * パスワード入力とログインボタンより後ろに置きたいので、3.0 では
     * 「パスワードをお忘れですか？」の段落の直後を第一候補にする。
     *
     * @return array<int,array{marker:string,position:string}>
     */
    public static function loginButtonAnchors(): array
    {
        return [
            // 3.1 以降：ログインフォーム下部の専用ブロック
            ['marker' => '<div class="acms-admin-login-main-box-bottom">', 'position' => 'before'],
            // 3.0：パスワード再発行リンクの段落の直後（LOGIN_SEGMENT が変わっても一致する）
            ['marker' => 'alt/remind/', 'position' => 'after-paragraph'],
            // 3.0：再発行リンクが無い構成では、ログインボタンの段落の直後
            ['marker' => '<p class="loginAction">', 'position' => 'after-paragraph'],
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
     * 現在のブログ・URL がログインページとして正当か（POST の経路チェック用）。
     *
     * Login::canLoginPage() は 3.1 以降の API。3.0 には無いため素通しする
     * （呼び出し側では後続のログイン状態チェックが継続する）。
     *
     * @return bool
     */
    public static function isValidLoginRoute(): bool
    {
        if (self::isLegacy()) {
            return true;
        }
        try {
            return (bool)Login::canLoginPage(BID, LOGIN_SEGMENT);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 現在の IP からログインが許可されているか。
     *
     * 管理ログイン用の IP 制限は 3.1 以降の機能で、3.0 には存在しない。
     * 3.0 では制限そのものが無いため常に許可する（塞ぐべき抜け道が無い）。
     *
     * @return bool
     */
    public static function canAccessFromCurrentIp(): bool
    {
        if (self::isLegacy()) {
            return true;
        }
        try {
            return (bool)Login::canAccessAdminLoginFromCurrentIp();
        } catch (\Throwable $e) {
            return true; // 本体に判定が無ければ制限なしとみなす
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
