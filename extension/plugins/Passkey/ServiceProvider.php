<?php

namespace Acms\Plugins\Passkey;

use App;
use DB;
use ACMS_App;
use Acms\Services\Facades\Config;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\HookFactory;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.11.0';

    /**
     * @var string
     */
    public $name = 'Passkey';

    /**
     * @var string
     */
    public $author = 'com.appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var bool|string メニュー識別子。サイドバーから /admin/app_passkey_index/ に遷移
     */
    public $menu = 'passkey_index';

    /**
     * @var string
     */
    public $desc = 'パスキー（WebAuthn）での管理ログイン機能と、自分のパスキー管理画面を提供します。';

    /**
     * @var array
     */
    protected $installTable = [
        'passkey',
    ];

    /**
     * 同梱 vendor のオートローダーを読み込む。
     *
     * require_once で直接読むと、アップロード漏れなどで欠けていた場合に
     * サイト全体が致命的エラーで停止してしまうため、存在を確認してから読む。
     *
     * @return bool 読み込めたかどうか
     */
    protected function loadVendorAutoload(): bool
    {
        $autoload = dirname(__FILE__) . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            if (class_exists('AcmsLogger')) {
                \AcmsLogger::error(
                    'Passkey プラグインの vendor/autoload.php が見つからないため、機能を無効化しました。'
                    . 'アップロード時に vendor ディレクトリが欠落している可能性があります。',
                    ['path' => $autoload]
                );
            }
            return false;
        }
        require_once $autoload;
        return class_exists('lbuchs\\WebAuthn\\WebAuthn');
    }

    /**
     * サービスの初期処理
     */
    public function init()
    {
        // vendor/autoload.php が欠けていても require_once でサイト全体を落とさない。
        // アップロード漏れなどで欠落した場合は、パスキー機能だけを無効化して
        // 管理画面に原因を表示する（$vendorMissing）。
        $vendorMissing = !$this->loadVendorAutoload();

        if (!$vendorMissing) {
            // 管理ログイン (/login/) のレスポンスにパスキーボタンを差し込む Hook
            HookFactory::singleton()->attach('PasskeyHook', new Hook());
        }

        // 管理画面：
        //   /admin/app_passkey_index/     → 全ユーザーのパスキー台帳（管理者専用）
        //   /admin/app_passkey_edit/      → 追加フォーム
        //   /admin/app_passkey_setting/   → プラグイン設定（管理者専用）
        //   /uid/Y/admin/user_passkey/    → 自分のパスキー管理（全権限。テーマ改修不要）
        if (defined('ADMIN')) {
            $inject = InjectTemplate::singleton();
            $base = PLUGIN_DIR . $this->name . '/template/admin/';
            if (ADMIN === 'app_passkey_index') {
                $inject->add('admin-topicpath', $base . 'topicpath.html');
                $inject->add('admin-main', $base . 'index.html');
            } elseif (ADMIN === 'app_passkey_edit') {
                $inject->add('admin-topicpath', $base . 'topicpath.html');
                $inject->add('admin-main', $base . 'edit.html');
            } elseif (ADMIN === 'app_passkey_setting') {
                $inject->add('admin-topicpath', $base . 'topicpath.html');
                $inject->add('admin-main', $base . 'setting.html');
            } elseif (ADMIN === 'user_passkey') {
                // 文字列パスのみを使う（callable 形式は Ver. 3.2.24 以降のため）
                $inject->add('admin-topicpath', $base . 'topicpath.html');
                $inject->add('admin-main', $base . 'user.html');
            }
        }

        App::singleton('passkey.engine', function () {
            $rpName = config('passkey_rp_name');
            if (empty($rpName)) {
                $rpName = config('site_name');
            }
            if (empty($rpName)) {
                $rpName = 'a-blog cms';
            }
            $rpId = config('passkey_rp_id');
            if (empty($rpId)) {
                $rpId = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : 'localhost';
                // 本番運用では rpId は明示設定を推奨（プロキシ越し / 複数ドメインで Host ヘッダ依存は事故りやすい）
                if (class_exists('AcmsLogger')) {
                    \AcmsLogger::notice('passkey_rp_id が未設定のため HTTP_HOST から自動決定しました。本番運用では config.system.yaml で明示設定を推奨します。', [
                        'rpId' => $rpId,
                    ]);
                }
            }
            $userVerification = config('passkey_user_verification');
            if (!in_array($userVerification, ['required', 'preferred', 'discouraged'], true)) {
                $userVerification = 'preferred';
            }
            return new Engine($rpName, $rpId, $userVerification);
        });
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストールするときの処理
     * データベーステーブルの初期化。
     *
     * 標準の ACMS_POST_App_Install は catch(Exception) しかせず PHP の \Error 系を
     * 取りこぼすため、ここで \Throwable を一度受けて監査ログに詳細を残してから
     * Exception 系で再 throw する。
     *
     * @return void
     */
    public function install()
    {
        try {
            // schema YAML は file_get_contents で直接読む（Storage::get() は
            // ディレクトリトラバーサル防止で public_html/ 配下しか読まないため、
            // git/ などへの symlink 構成だと realpath が安全領域外と判定されて失敗する）
            $yamlTable = preg_replace(
                '/%{PREFIX}/',
                DB_PREFIX,
                (string)@file_get_contents(dirname(__FILE__) . '/schema/db-schema.yaml')
            );
            $tablesData = Config::yamlParse($yamlTable);
            if (!is_array($tablesData)) {
                $tablesData = [];
            }
            if (!empty($tablesData[0])) {
                unset($tablesData[0]);
            }
            $tableList = array_merge(array_diff(array_keys($tablesData), ['']));

            $yamlIndex = preg_replace(
                '/%{PREFIX}/',
                DB_PREFIX,
                (string)@file_get_contents(dirname(__FILE__) . '/schema/db-index.yaml')
            );
            $indexData = Config::yamlParse($yamlIndex);
            if (!is_array($indexData)) {
                $indexData = [];
            }
            if (!empty($indexData[0])) {
                unset($indexData[0]);
            }
            foreach ($tableList as $tb) {
                $index = isset($indexData[$tb]) ? $indexData[$tb] : null;
                $this->dbCreateTables($tb, $tablesData[$tb], $index);
            }
        } catch (\Throwable $e) {
            $this->logInstallFailure('install', $e);
            // 上位の catch(Exception) にも乗せる（\Error 系も Exception でラップ）
            throw new \RuntimeException(
                'Passkey プラグインのインストールに失敗しました: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * アンインストールするときの処理
     *
     * @return void
     */
    public function uninstall()
    {
        try {
            $this->dbDropTables([
                'passkey',
            ]);
        } catch (\Throwable $e) {
            $this->logInstallFailure('uninstall', $e);
            throw new \RuntimeException(
                'Passkey プラグインのアンインストールに失敗しました: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }

    /**
     * DB テーブルの削除
     *
     * @param array $tbs
     * @return void
     */
    protected function dbDropTables($tbs)
    {
        $DB = DB::singleton(dsn());
        foreach ($tbs as $tb) {
            if (dbTableExists($tb)) {
                $q = 'DROP TABLE ' . DB_PREFIX . $tb;
                $DB->query($q, 'exec');
            }
        }
    }

    /**
     * DB テーブルの作成
     *
     * @param string $tb
     * @param array $data
     * @param array|null $idx
     * @return void
     */
    protected function dbCreateTables($tb, $data, $idx = null)
    {
        $DB = DB::singleton(dsn());

        $q = "CREATE TABLE $tb (\r\n";
        foreach ($data as $row) {
            $row['Null'] = (isset($row['Null']) && $row['Null'] === 'NO') ? 'NOT NULL' : 'NULL';
            $row['Default'] = !empty($row['Default']) ? "default '" . $row['Default'] . "'" : null;
            $extra = !empty($row['Extra']) ? $row['Extra'] : '';
            $q .= $row['Field'] . ' ' . $row['Type'] . ' ' . $row['Null'] . ' ' . $row['Default'] . ' ' . $extra . ",\r\n";
        }
        if (is_array($idx) && !empty($idx)) {
            foreach ($idx as $key) {
                $q .= $key . ",\r\n";
            }
        }
        $q = preg_replace('@,(\r\n)$@', '$1', $q);
        $q .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        $DB->query($q, 'exec');
    }

    /**
     * インストール／アンインストール失敗時の詳細を監査ログに残す。
     *
     * 標準の ACMS_POST_App_Install / Uninstall は catch(Exception) しかせず PHP の
     * \Error 系（型エラー、メモリ不足、ファイル未読み込み等）を取りこぼすため、
     * ここで一度 \Throwable を受けて AcmsLogger に詳細を吐く。
     *
     * @param string $phase 'install' | 'uninstall'
     * @param \Throwable $e
     * @return void
     */
    protected function logInstallFailure(string $phase, \Throwable $e): void
    {
        if (!class_exists('AcmsLogger')) {
            return;
        }
        \AcmsLogger::error(
            'Passkey プラグインの ' . $phase . ' に失敗しました',
            [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'php_version' => PHP_VERSION,
                'db_prefix' => defined('DB_PREFIX') ? DB_PREFIX : '?',
            ]
        );
    }
}
