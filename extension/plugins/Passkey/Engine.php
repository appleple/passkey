<?php

namespace Acms\Plugins\Passkey;

use DB;
use SQL;
use ACMS_RAM;
use ACMS_Session;
use Acms\Services\Facades\Login;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

class Engine
{
    public const SESSION_REGISTER_CHALLENGE = 'passkey.register.challenge';
    public const SESSION_REGISTER_UID = 'passkey.register.uid';
    public const SESSION_LOGIN_CHALLENGE = 'passkey.login.challenge';

    /** @var string */
    protected $rpName;

    /** @var string */
    protected $rpId;

    /** @var string */
    protected $userVerification;

    /** @var ACMS_Session */
    protected $session;

    public function __construct(string $rpName, string $rpId, string $userVerification = 'preferred')
    {
        $this->rpName = $rpName;
        $this->rpId = $rpId;
        $this->userVerification = $userVerification;
        $this->session = ACMS_Session::singleton();
    }

    public function getRpId(): string
    {
        return $this->rpId;
    }

    public function getRpName(): string
    {
        return $this->rpName;
    }

    public function getUserVerification(): string
    {
        return $this->userVerification;
    }

    /**
     * 登録（attestation）用 PublicKeyCredentialCreationOptions を生成
     *
     * @param int $uid
     * @param string $userName a-blog cms の user_code / user_name
     * @param string $displayName a-blog cms の user_name
     * @return \stdClass JS にそのまま渡せる形（challenge / user.id 等は base64url 文字列）
     */
    public function getRegisterOptions(int $uid, string $userName, string $displayName): \stdClass
    {
        $webauthn = $this->createWebAuthn();

        $userHandle = $this->buildUserHandle($uid);
        $excludeIds = $this->getCredentialIdsByUser($uid);
        $excludeBin = array_map(function ($base64url) {
            return ByteBuffer::fromBase64Url($base64url)->getBinaryString();
        }, $excludeIds);

        $args = $webauthn->getCreateArgs(
            $userHandle,
            $userName,
            $displayName,
            60,
            true,
            $this->userVerification === 'required',
            null,
            $excludeBin
        );

        $challenge = $webauthn->getChallenge();
        $this->session->set(self::SESSION_REGISTER_CHALLENGE, $challenge->jsonSerialize());
        $this->session->set(self::SESSION_REGISTER_UID, $uid);
        $this->session->save();

        return $args;
    }

    /**
     * 登録（attestation）レスポンスを検証して DB に保存
     *
     * @param int $uid
     * @param string $clientDataJsonB64 base64url
     * @param string $attestationObjectB64 base64url
     * @param string|null $nickname
     * @return array{passkey_id:int}
     * @throws \RuntimeException
     */
    public function verifyRegister(int $uid, string $clientDataJsonB64, string $attestationObjectB64, ?string $nickname = null): array
    {
        $challengeB64 = $this->session->get(self::SESSION_REGISTER_CHALLENGE);
        $sessionUid = (int)$this->session->get(self::SESSION_REGISTER_UID);

        // 単発で消費
        $this->session->delete(self::SESSION_REGISTER_CHALLENGE);
        $this->session->delete(self::SESSION_REGISTER_UID);
        $this->session->save();

        if (empty($challengeB64) || $sessionUid !== $uid) {
            throw new \RuntimeException('登録セッションが無効です。');
        }

        $clientDataJson = ByteBuffer::fromBase64Url($clientDataJsonB64)->getBinaryString();
        $attestationObject = ByteBuffer::fromBase64Url($attestationObjectB64)->getBinaryString();
        $challenge = ByteBuffer::fromBase64Url($challengeB64);

        $webauthn = $this->createWebAuthn();
        $data = $webauthn->processCreate(
            $clientDataJson,
            $attestationObject,
            $challenge,
            $this->userVerification === 'required',
            true,
            false // failIfRootMismatch=false（プラットフォーム認証器の証明書チェーンは追わない）
        );

        $credentialIdB64 = $this->binToBase64Url($data->credentialId);
        $publicKeyPem = $data->credentialPublicKey;
        $signCount = is_int($data->signatureCounter) ? $data->signatureCounter : 0;
        $aaguid = $data->AAGUID ? $this->aaguidToString($data->AAGUID) : null;

        // 重複ガード
        $sql = SQL::newSelect('passkey');
        $sql->setSelect('passkey_id');
        $sql->addWhereOpr('passkey_credential_id', $credentialIdB64);
        if (DB::query($sql->get(dsn()), 'one')) {
            throw new \RuntimeException('このパスキーは既に登録されています。');
        }

        $sql = SQL::newInsert('passkey');
        $sql->addInsert('passkey_user_id', $uid);
        $sql->addInsert('passkey_credential_id', $credentialIdB64);
        $sql->addInsert('passkey_public_key', $publicKeyPem);
        $sql->addInsert('passkey_sign_count', (string)$signCount);
        if ($aaguid) {
            $sql->addInsert('passkey_aaguid', $aaguid);
        }
        if ($nickname !== null && $nickname !== '') {
            $sql->addInsert('passkey_nickname', mb_substr($nickname, 0, 255));
        }
        $sql->addInsert('passkey_created_at', date('Y-m-d H:i:s', REQUEST_TIME));
        DB::query($sql->get(dsn()), 'exec');

        $insertId = (int)DB::query('SELECT LAST_INSERT_ID()', 'one');

        return [
            'passkey_id' => $insertId,
        ];
    }

    /**
     * 認証（assertion）用 PublicKeyCredentialRequestOptions を生成
     * usernameless（discoverable）認証のため allowCredentials は空
     *
     * @return \stdClass
     */
    public function getLoginOptions(): \stdClass
    {
        $webauthn = $this->createWebAuthn();
        $args = $webauthn->getGetArgs([], 60, true, true, true, true, true, $this->userVerification === 'required');

        $challenge = $webauthn->getChallenge();
        $this->session->set(self::SESSION_LOGIN_CHALLENGE, $challenge->jsonSerialize());
        $this->session->save();

        return $args;
    }

    /**
     * 認証（assertion）レスポンスを検証し、ヒットした user の full row を返す
     *
     * @param string $credentialIdB64
     * @param string $clientDataJsonB64
     * @param string $authenticatorDataB64
     * @param string $signatureB64
     * @param string|null $userHandleB64  ブラウザが返す userHandle（base64url）。指定時は照合する
     * @return array<string,mixed> user row
     * @throws \RuntimeException
     */
    public function verifyLogin(
        string $credentialIdB64,
        string $clientDataJsonB64,
        string $authenticatorDataB64,
        string $signatureB64,
        ?string $userHandleB64 = null
    ): array {
        $challengeB64 = $this->session->get(self::SESSION_LOGIN_CHALLENGE);
        $this->session->delete(self::SESSION_LOGIN_CHALLENGE);
        $this->session->save();

        if (empty($challengeB64)) {
            throw new \RuntimeException('認証セッションが無効です。');
        }

        // credential_id から DB レコードを引く
        $sql = SQL::newSelect('passkey');
        $sql->addWhereOpr('passkey_credential_id', $credentialIdB64);
        $row = DB::query($sql->get(dsn()), 'row');
        if (empty($row)) {
            throw new \RuntimeException('登録されていないパスキーです。');
        }

        $uid = intval($row['passkey_user_id']);
        $publicKey = $row['passkey_public_key'];
        $prevSignCount = intval($row['passkey_sign_count']);

        // userHandle を照合（送信されていれば）
        if ($userHandleB64 !== null && $userHandleB64 !== '') {
            $userHandle = ByteBuffer::fromBase64Url($userHandleB64)->getBinaryString();
            $expected = $this->buildUserHandle($uid);
            if (!hash_equals($expected, $userHandle)) {
                throw new \RuntimeException('userHandle が一致しません。');
            }
        }

        // user が現存して open か
        $userSql = SQL::newSelect('user');
        $userSql->addWhereOpr('user_id', $uid);
        $userSql->addWhereOpr('user_status', 'open');
        $userSql->addWhereOpr('user_login_expire', date('Y-m-d', REQUEST_TIME), '>=');
        $user = DB::query($userSql->get(dsn()), 'row');
        if (empty($user)) {
            throw new \RuntimeException('ユーザーが無効です。');
        }

        $clientDataJson = ByteBuffer::fromBase64Url($clientDataJsonB64)->getBinaryString();
        $authenticatorData = ByteBuffer::fromBase64Url($authenticatorDataB64)->getBinaryString();
        $signature = ByteBuffer::fromBase64Url($signatureB64)->getBinaryString();
        $challenge = ByteBuffer::fromBase64Url($challengeB64);

        $webauthn = $this->createWebAuthn();
        $webauthn->processGet(
            $clientDataJson,
            $authenticatorData,
            $signature,
            $publicKey,
            $challenge,
            $prevSignCount > 0 ? $prevSignCount : null,
            $this->userVerification === 'required',
            true
        );

        $newCount = $webauthn->getSignatureCounter();
        if ($newCount === null) {
            $newCount = $prevSignCount;
        }

        // 単調増加チェック（クローン検出）。新カウンタが 0 または前回以下なら不審
        if ($newCount > 0 && $newCount <= $prevSignCount) {
            throw new \RuntimeException('認証器の状態が不正です。');
        }

        // 更新
        $sql = SQL::newUpdate('passkey');
        $sql->addUpdate('passkey_sign_count', (string)$newCount);
        $sql->addUpdate('passkey_last_used_at', date('Y-m-d H:i:s', REQUEST_TIME));
        $sql->addWhereOpr('passkey_id', intval($row['passkey_id']));
        DB::query($sql->get(dsn()), 'exec');

        return $user;
    }

    /**
     * ユーザが管理ログイン経路を使える資格を持つかチェックする。
     * 標準 ACMS_POST_Member_Admin_Login と同等のガード。
     *
     * 古いバージョンの a-blog cms には一部メソッドが無いため、Facade が
     * BadMethodCallException を投げた場合はそのチェックだけスキップする。
     *
     * @param array<string,mixed> $user user テーブルの 1 行
     * @return string[] エラー理由（空配列なら通過）
     */
    public static function checkAdminLoginEligibility(array $user): array
    {
        $errors = [];
        $auth = isset($user['user_auth']) ? (string)$user['user_auth'] : '';

        // 権限チェック（3.0 はログインが一本化され本体も権限で制限しないため null が返る）
        $allowedAuths = Compat::allowedLoginAuths();
        if ($allowedAuths !== null && !in_array($auth, $allowedAuths, true)) {
            $errors[] = '管理ログインの権限がありません';
        }

        // ブログ所属チェック
        $loginAnywhere = isset($user['user_login_anywhere']) ? (string)$user['user_login_anywhere'] : '';
        $userBid = isset($user['user_blog_id']) ? intval($user['user_blog_id']) : 0;
        if ($loginAnywhere !== 'on' && $userBid !== intval(BID)) {
            $errors[] = '別のブログに所属するユーザーです';
        }

        // IP 制限（3.1 以降の機能。3.0 には存在しないため常に許可）
        if (!Compat::canAccessFromCurrentIp()) {
            $errors[] = 'このIPアドレスからのログインは許可されていません';
        }

        // 端末制限：パスワードログインと同じ判定を必ず通す（省くと抜け道になる）
        if (!Compat::isAllowedDevice($user)) {
            $errors[] = '許可されていない端末です';
        }

        return $errors;
    }

    /**
     * SUID を user テーブルから取得（管理ログイン適格性チェック用）
     *
     * @param int $uid
     * @return array<string,mixed>|null
     */
    public function loadUser(int $uid): ?array
    {
        $sql = SQL::newSelect('user');
        $sql->addWhereOpr('user_id', $uid);
        $sql->addWhereOpr('user_status', 'open');
        $sql->addWhereOpr('user_login_expire', date('Y-m-d', REQUEST_TIME), '>=');
        $user = DB::query($sql->get(dsn()), 'row');
        return is_array($user) && !empty($user) ? $user : null;
    }

    /**
     * 指定ユーザの登録済みパスキー一覧
     *
     * @param int $uid
     * @return array<array<string,mixed>>
     */
    public function listByUser(int $uid): array
    {
        $sql = SQL::newSelect('passkey');
        $sql->addWhereOpr('passkey_user_id', $uid);
        $sql->setOrder('passkey_created_at', 'DESC');
        $rows = DB::query($sql->get(dsn()), 'all');
        if (!is_array($rows)) {
            return [];
        }
        return $rows;
    }

    /**
     * 日時を「日付」「時刻」に分割したテンプレ変数を作る（テーブルの幅節約の2段表示用）
     *
     * @param string $prefix 変数名の接頭辞（例: 'passkey_created'）
     * @param string|null $datetime 'Y-m-d H:i:s'
     * @return array<string,string> {prefix}_at / {prefix}_date / {prefix}_time
     */
    public static function datetimeVars(string $prefix, ?string $datetime): array
    {
        $ts = $datetime ? strtotime($datetime) : false;
        if ($ts === false) {
            return [
                $prefix . '_at' => '—',
                $prefix . '_date' => '—',
                $prefix . '_time' => '',
            ];
        }
        return [
            $prefix . '_at' => date('Y-m-d H:i:s', $ts),
            $prefix . '_date' => date('Y/m/d', $ts),
            $prefix . '_time' => date('H:i', $ts),
        ];
    }

    /**
     * 全ユーザーの登録済みパスキー一覧（管理者の台帳用）
     * ユーザー情報（名前・コード・権限）を JOIN して返す
     *
     * @return array<array<string,mixed>>
     */
    public function listAll(): array
    {
        $sql = SQL::newSelect('passkey');
        $sql->addLeftJoin('user', 'user_id', 'passkey_user_id');
        $sql->setOrder('passkey_created_at', 'DESC');
        $rows = DB::query($sql->get(dsn()), 'all');
        if (!is_array($rows)) {
            return [];
        }
        return $rows;
    }

    /**
     * パスキー削除（所有者チェックなし。管理者操作用 — 呼び出し側で権限を確認すること）
     *
     * @param int $passkeyId
     * @return array<string,mixed>|null 削除した行（監査ログ用）。見つからなければ null
     */
    public function deleteById(int $passkeyId): ?array
    {
        $sql = SQL::newSelect('passkey');
        $sql->addWhereOpr('passkey_id', $passkeyId);
        $row = DB::query($sql->get(dsn()), 'row');
        if (empty($row)) {
            return null;
        }
        $sql = SQL::newDelete('passkey');
        $sql->addWhereOpr('passkey_id', $passkeyId);
        DB::query($sql->get(dsn()), 'exec');
        return $row;
    }

    /**
     * パスキー削除（本人のみ）
     *
     * @param int $passkeyId
     * @param int $uid
     * @return bool true: 削除した
     */
    public function delete(int $passkeyId, int $uid): bool
    {
        $sql = SQL::newSelect('passkey');
        $sql->addWhereOpr('passkey_id', $passkeyId);
        $sql->addWhereOpr('passkey_user_id', $uid);
        $row = DB::query($sql->get(dsn()), 'row');
        if (empty($row)) {
            return false;
        }
        $sql = SQL::newDelete('passkey');
        $sql->addWhereOpr('passkey_id', $passkeyId);
        $sql->addWhereOpr('passkey_user_id', $uid);
        DB::query($sql->get(dsn()), 'exec');
        return true;
    }

    /**
     * 指定ユーザの credential_id 一覧（excludeCredentials 用）
     *
     * @param int $uid
     * @return string[] base64url 形式の credential_id 配列
     */
    public function getCredentialIdsByUser(int $uid): array
    {
        $sql = SQL::newSelect('passkey');
        $sql->setSelect('passkey_credential_id');
        $sql->addWhereOpr('passkey_user_id', $uid);
        $rows = DB::query($sql->get(dsn()), 'all');
        if (!is_array($rows)) {
            return [];
        }
        return array_map(function ($row) {
            return $row['passkey_credential_id'];
        }, $rows);
    }

    protected function createWebAuthn(): WebAuthn
    {
        // base64url モードで JS との連携を簡素化
        return new WebAuthn($this->rpName, $this->rpId, null, true);
    }

    /**
     * user_id から不透明な user handle を作る
     * usernameless 認証で authenticator は userHandle を保存するが、credential_id で逆引きするので
     * 用途は識別子として十分なバイナリ表現があればよい
     */
    protected function buildUserHandle(int $uid): string
    {
        return 'acms-user-' . $uid;
    }

    /**
     * バイナリを base64url 文字列に
     */
    protected function binToBase64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * AAGUID（16 バイトバイナリ）を UUID 文字列に
     */
    protected function aaguidToString(string $bin): string
    {
        if (strlen($bin) !== 16) {
            return '';
        }
        $hex = bin2hex($bin);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * AAGUID から認証器の表示名（ベンダ／製品名）を返す。
     * 未知の AAGUID は null。
     *
     * 参考: https://github.com/passkeydeveloper/passkey-authenticator-aaguids
     */
    public static function aaguidLabel(?string $aaguid): ?string
    {
        if (empty($aaguid)) {
            return null;
        }
        $map = [
            '00000000-0000-0000-0000-000000000000' => '不明（attestation なし）',
            // Apple
            'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'Apple iCloud Keychain',
            'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'Apple iCloud Keychain（Managed）',
            // Windows Hello
            '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello（Hardware）',
            '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello（Software）',
            '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello（Pre-RS5）',
            // Google
            'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
            'b93fd961-f2e6-462f-b122-82002247de78' => 'Android Authenticator（Weak Biometric）',
            // Chrome / Edge profile
            'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on Mac（Profile Authenticator）',
            // 1Password / Bitwarden / Dashlane
            'b5397666-4885-aef6-77d6-ec713fdde8a8' => 'Bitwarden',
            '0ea242b4-43c4-4a1b-8b17-dd6d0b6baec6' => '1Password',
            'bada5566-a7aa-401f-bd96-45619a55120d' => 'Dashlane',
            'f3809540-7f14-49c1-a8b3-8f813b225541' => 'Enpass',
            'cfcb13a2-0f88-4f25-986b-93e88197321a' => 'NordPass',
            'eec5af0f-eed5-4ce0-86ce-bcaa7f7ad6cd' => 'KeePassXC',
            'b84e4048-15dc-4dd0-8640-f4f60813c8af' => 'NordPass',
            '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
            // YubiKey
            'cb69481e-8ff7-4039-93ec-0a2729a154a8' => 'YubiKey 5 Series',
            'f8a011f3-8c0a-4d15-8006-17111f9edc7d' => 'Security Key by Yubico',
            '2fc0579f-8113-47ea-b116-bb5a8db9202a' => 'YubiKey 5 Series NFC',
            'c5ef55ff-ad9a-4b9f-b580-adebafe026d0' => 'YubiKey 5Ci',
            'fa2b99dc-9e39-4257-8f92-4a30d23c4118' => 'YubiKey 5 NFC（FIDO2）',
            // Google Titan
            '42b4fb4a-2866-43b2-9bf7-6c6669c2e5d3' => 'Google Titan Security Key',
            // SoloKeys / Token2 / Feitian
            '8876631b-d4a0-427f-5773-0ec71c9e0279' => 'SoloKey',
            'fcb1bcb4-f370-078c-6993-bc24d0ae3fbe' => 'Token2 PIN+ Security Key',
            '833b721a-ff5f-4d00-bb2e-bdda3ec01e29' => 'Feitian ePass FIDO2',
        ];
        $key = strtolower($aaguid);
        return isset($map[$key]) ? $map[$key] : null;
    }
}
