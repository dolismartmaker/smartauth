<?php

/**
 * PHPUnit Bootstrap for SmartAuth Integration Tests with Real Dolibarr
 *
 * Uses cap-rel/dolibarr-integration-sqlite package for a complete Dolibarr environment
 */

// Reset SQLite database to clean state before running tests
// This ensures tests start with a known state regardless of previous test runs
$projectRoot = dirname(__DIR__, 3);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
if (is_dir($sqliteVendorPath . '/.git')) {
    // The sqlite package has its own git repo, reset it directly
    exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git reset --hard HEAD 2>/dev/null');
}

// Load composer autoload first - this triggers autoload-init.php which defines DOL_DOCUMENT_ROOT
require_once __DIR__ . '/../../../vendor/autoload.php';

// Path to dolibarr-integration-sqlite package
$dolibarrPath = realpath(__DIR__ . '/../../../vendor/cap-rel/dolibarr-integration-sqlite/htdocs');

if (!$dolibarrPath || !is_dir($dolibarrPath)) {
    throw new Exception(
        "dolibarr-integration-sqlite not found. Install with:\n" .
        "composer require --dev cap-rel/dolibarr-integration-sqlite:18.0.x-dev"
    );
}

// Define Dolibarr constants for CLI mode
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}

// Set minimal server variables for CLI
$_SERVER['PHP_SELF'] = '/test.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/test.php';
$_SERVER['SCRIPT_FILENAME'] = $dolibarrPath . '/test.php';
$_SERVER['REQUEST_URI'] = '/test.php';
$_SERVER['DOCUMENT_ROOT'] = $dolibarrPath;

// Change to Dolibarr htdocs directory for proper conf.php loading
$originalDir = getcwd();
chdir($dolibarrPath);

// Suppress output and warnings during Dolibarr bootstrap
ob_start();
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

// Load master.inc.php which initializes $conf, $db, $user, $langs
// We need to capture the global variables it creates
// PHPUnit's FileLoader loads bootstrap in a scope where globals are not automatically propagated
global $conf, $db, $user, $langs, $hookmanager, $mysoc;

// First load filefunc.inc.php to define DOL_DOCUMENT_ROOT
require_once $dolibarrPath . '/filefunc.inc.php';

// Then load master.inc.php
require_once DOL_DOCUMENT_ROOT . '/master.inc.php';

error_reporting(E_ALL);
ob_end_clean();

// Restore original directory
chdir($originalDir);

// Variables are already set via global declaration above

// Verify Dolibarr is properly initialized
if (!$db || !$user) {
    throw new Exception("Dolibarr failed to initialize properly. Ensure cap-rel/dolibarr-integration-sqlite is correctly installed.");
}

// Load admin user for tests
$user->fetch(1);

// Initialize SmartAuth module configuration in $conf
// This simulates what happens when the SmartAuth module is enabled
if (!isset($conf->smartauth)) {
    $conf->smartauth = new stdClass();
}
$conf->smartauth->enabled = 1;
$conf->smartauth->dir_output = DOL_DATA_ROOT . '/smartauth';
$conf->smartauth->dir_temp = DOL_DATA_ROOT . '/smartauth/temp';

// Ensure output directory exists
if (!is_dir($conf->smartauth->dir_output)) {
    @mkdir($conf->smartauth->dir_output, 0755, true);
}

// Create SmartAuth tables if not exist
createSmartAuthTables($db);

/**
 * Create SmartAuth tables in the database
 */
function createSmartAuthTables($db)
{
    $sqls = [];

    // Check if tables already exist
    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='llx_smartauth_auth'";
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        return true; // Tables already exist
    }

    // SmartAuth main table
    $sqls[] = "CREATE TABLE IF NOT EXISTS llx_smartauth_auth (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT,
        appuid INTEGER,
        salt TEXT,
        token_type TEXT DEFAULT 'access',
        parent_token_id INTEGER,
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
    $sqls[] = "CREATE TABLE IF NOT EXISTS llx_smartauth_devices (
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
    $sqls[] = "CREATE TABLE IF NOT EXISTS llx_smartauth_token_family (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        fk_user INTEGER,
        created_at INTEGER,
        last_refresh_at INTEGER,
        refresh_count INTEGER DEFAULT 0,
        revoked INTEGER DEFAULT 0
    )";

    // Rate limit table
    $sqls[] = "CREATE TABLE IF NOT EXISTS llx_smartauth_ratelimit (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier TEXT,
        action TEXT,
        attempt_time INTEGER,
        success INTEGER DEFAULT 0
    )";

    // Logs table
    $sqls[] = "CREATE TABLE IF NOT EXISTS llx_smartauth_logs (
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

    foreach ($sqls as $sql) {
        $result = $db->query($sql);
        if ($result === false) {
            throw new Exception("Failed to create SmartAuth tables: " . $db->lasterror());
        }
    }

    return true;
}

// Load test base class
require_once __DIR__ . '/DolibarrRealTestCase.php';
