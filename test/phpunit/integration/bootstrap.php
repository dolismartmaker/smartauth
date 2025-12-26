<?php

/**
 * PHPUnit Bootstrap for SmartAuth Integration Tests
 *
 * This file sets up a minimal Dolibarr environment using SQLite
 * for testing SmartAuth classes that extend CommonObject.
 */

// Autoload composer dependencies
require_once __DIR__ . '/../../../vendor/autoload.php';

// Define DOL_DOCUMENT_ROOT FIRST (before loading fixtures that may need it)
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', __DIR__ . '/../fixtures/dolibarr');
}

// Load Dolibarr fixtures (minimal implementations)
require_once __DIR__ . '/../fixtures/dolibarr/DoliDB.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

// Define Dolibarr constants
if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

if (!defined('DOL_DATA_ROOT')) {
    define('DOL_DATA_ROOT', '/tmp/dolibarr-test-data');
}

if (!defined('DOL_MAIN_URL_ROOT')) {
    define('DOL_MAIN_URL_ROOT', 'http://localhost');
}

// Initialize global $conf object
global $conf;
$conf = new stdClass();
$conf->entity = 1;
$conf->cache = [];
$conf->cache['smartmakers'] = [];
$conf->global = new stdClass();
$conf->global->MAIN_INFO_SOCIETE_NOM = 'Test Company';
$conf->global->SMARTAUTH_COLLECT_LOGS = '';

/**
 * Set a global config value
 */
$conf->setValues = function ($db) use ($conf) {
    // Nothing to reload in tests
    return 1;
};

// Initialize global database connection (SQLite in memory)
global $db;
$db = new DoliDB('sqlite', '', '', '', ':memory:');

// Initialize global user
global $user;
$user = null;

// Initialize global mysoc (company)
global $mysoc;
$mysoc = new Societe($db);
$mysoc->name = 'Test Company';
$mysoc->nom = 'Test Company';

// Initialize global extrafields
global $extrafields;
$extrafields = new ExtraFields($db);

// Initialize global langs
global $langs;
$langs = new Translate('', $conf);
$langs->setDefaultLang('en_US');

// Mock Dolibarr functions
if (!function_exists('dol_syslog')) {
    function dol_syslog($message, $level = 0)
    {
        // Uncomment to debug:
        // echo "[LOG $level] $message\n";
    }
}

if (!function_exists('dol_now')) {
    function dol_now()
    {
        return time();
    }
}

if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt($key, $default = 0)
    {
        global $conf;
        return isset($conf->global->$key) ? (int) $conf->global->$key : $default;
    }
}

if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString($key, $default = '')
    {
        global $conf;
        return isset($conf->global->$key) ? (string) $conf->global->$key : $default;
    }
}

if (!function_exists('sanitizeVal')) {
    function sanitizeVal($value, $type = 'alphanohtml')
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('json_reply')) {
    function json_reply($data, $httpCode = 200)
    {
        throw new \SmartAuth\Tests\Mocks\JsonReplyException($data, $httpCode);
    }
}

if (!function_exists('dol_include_once')) {
    function dol_include_once($path, $classname = '')
    {
        // Classes are autoloaded, nothing to do
    }
}

if (!function_exists('isModEnabled')) {
    function isModEnabled($module)
    {
        global $conf;
        $key = strtoupper($module);
        return !empty($conf->$module->enabled) || getDolGlobalInt($key . '_ENABLED', 0);
    }
}

if (!function_exists('getEntity')) {
    function getEntity($element, $shared = 1)
    {
        global $conf;
        return (string) ($conf->entity ?? 1);
    }
}

if (!function_exists('checkLoginPassEntity')) {
    function checkLoginPassEntity($login, $pass, $entity, $authmode = [], $context = '')
    {
        // Simplified for tests - just return login if not empty
        return !empty($login) && !empty($pass) ? $login : '';
    }
}

if (!function_exists('dolGetButtonTitle')) {
    function dolGetButtonTitle($label, $helpText = '', $iconClass = '', $url = '', $id = '', $status = 1, $params = [])
    {
        return '<a href="' . $url . '">' . $label . '</a>';
    }
}

if (!function_exists('img_picto')) {
    function img_picto($title, $picto, $moreatt = '', $pictoisfullpath = 0, $srconly = 0, $notitle = 0, $alt = '', $morecss = '', $marginleftonlyshort = 2)
    {
        return '<span class="' . $picto . '" title="' . $title . '"></span>';
    }
}

if (!function_exists('dol_print_error')) {
    function dol_print_error($db = null, $error = '')
    {
        if (!empty($error)) {
            echo "ERROR: " . $error . "\n";
        }
        if ($db) {
            echo "DB Error: " . $db->lasterror() . "\n";
        }
    }
}

if (!function_exists('dol_strlen')) {
    function dol_strlen($string, $charset = 'UTF-8')
    {
        return mb_strlen($string, $charset);
    }
}

if (!function_exists('dol_substr')) {
    function dol_substr($string, $start, $length = null, $charset = 'UTF-8')
    {
        return mb_substr($string, $start, $length, $charset);
    }
}

if (!function_exists('price')) {
    function price($amount, $form = 0, $outlangs = null, $trunc = 1, $rounding = -1, $forcerounding = -1, $currency_code = '')
    {
        return number_format((float) $amount, 2, '.', ' ');
    }
}

if (!function_exists('price2num')) {
    function price2num($amount, $rounding = '')
    {
        return (float) str_replace([' ', ','], ['', '.'], $amount);
    }
}

if (!function_exists('dol_print_date')) {
    function dol_print_date($time, $format = '', $tzoutput = 'auto', $outputlangs = null, $encodetooutput = false)
    {
        if (empty($time)) {
            return '';
        }
        $f = 'Y-m-d H:i:s';
        if ($format == 'day' || $format == '%d/%m/%Y') {
            $f = 'd/m/Y';
        } elseif ($format == 'daytext') {
            $f = 'd F Y';
        }
        return date($f, $time);
    }
}

if (!function_exists('dol_mktime')) {
    function dol_mktime($hour, $minute, $second, $month, $day, $year, $gm = 'auto', $check = 1)
    {
        return mktime($hour, $minute, $second, $month, $day, $year);
    }
}

/**
 * Create database schema for SmartAuth tables
 */
function createSmartAuthSchema($db)
{
    $sqls = [];

    // SmartAuth main table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_auth (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT,
        appuid INTEGER,
        salt TEXT,
        token_type TEXT DEFAULT 'access',
        family_id INTEGER,
        refresh_count INTEGER DEFAULT 0,
        date_creation TEXT,
        date_eol TEXT,
        date_lastused TEXT,
        fk_user_creat INTEGER,
        fk_user_modif INTEGER,
        fk_authid INTEGER,
        fk_device_id INTEGER,
        auth_element TEXT,
        ip TEXT,
        status INTEGER DEFAULT 1,
        entity INTEGER DEFAULT 1,
        tms TEXT
    )";

    // SmartAuth devices table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_devices (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT,
        uuid TEXT,
        label TEXT,
        description TEXT,
        date_creation TEXT,
        date_validation TEXT,
        fk_user_creat INTEGER,
        fk_user_valid INTEGER,
        fk_user_modif INTEGER,
        status INTEGER DEFAULT 0,
        entity INTEGER DEFAULT 1,
        tms TEXT
    )";

    // Token family table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_token_family (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        fk_user INTEGER,
        created_at INTEGER,
        last_refresh_at INTEGER,
        refresh_count INTEGER DEFAULT 0,
        revoked INTEGER DEFAULT 0
    )";

    // Rate limit table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_ratelimit (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier TEXT,
        action TEXT,
        attempt_time INTEGER,
        success INTEGER DEFAULT 0
    )";

    // Logs table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_logs (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        fk_key INTEGER,
        appuid TEXT,
        entity INTEGER,
        dol_element TEXT,
        ip TEXT,
        method TEXT,
        http_status INTEGER,
        bytes_sent INTEGER,
        content_type TEXT,
        url_requested TEXT,
        user_agent TEXT,
        fk_device_id INTEGER,
        referer TEXT,
        datec TEXT DEFAULT CURRENT_TIMESTAMP
    )";

    // User table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "user (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT,
        pass_crypted TEXT,
        lastname TEXT,
        firstname TEXT,
        email TEXT,
        admin INTEGER DEFAULT 0,
        employee INTEGER DEFAULT 1,
        statut INTEGER DEFAULT 1,
        entity INTEGER DEFAULT 1,
        socid INTEGER,
        date_creation TEXT,
        tms TEXT,
        fk_user_creat INTEGER,
        fk_user_modif INTEGER
    )";

    // Societe table
    $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "societe (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT,
        name_alias TEXT,
        entity INTEGER DEFAULT 1,
        address TEXT,
        zip TEXT,
        town TEXT,
        country_id INTEGER,
        email TEXT,
        phone TEXT,
        fax TEXT,
        url TEXT,
        client INTEGER DEFAULT 0,
        fournisseur INTEGER DEFAULT 0,
        code_client TEXT,
        code_fournisseur TEXT,
        status INTEGER DEFAULT 1,
        date_creation TEXT,
        tms TEXT,
        fk_user_creat INTEGER,
        fk_user_modif INTEGER
    )";

    foreach ($sqls as $sql) {
        $result = $db->query($sql);
        if ($result === false) {
            throw new Exception("Failed to create schema: " . $db->lasterror() . "\nSQL: " . $sql);
        }
    }

    return true;
}

// Create schema on bootstrap
createSmartAuthSchema($db);

// Define smartAuthAppID and smartAuthAppKey for tests
global $smartAuthAppID, $smartAuthAppKey;
$smartAuthAppID = 1;
$smartAuthAppKey = 'test-secret-key-for-integration-tests-12345';

// Load test mocks
require_once __DIR__ . '/../Mocks/JsonReplyException.php';

// Load integration test base class
require_once __DIR__ . '/DolibarrTestCase.php';
