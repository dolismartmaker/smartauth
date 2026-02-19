<?php

/**
 * PHPUnit Bootstrap for SmartAuth Integration Tests with Real Dolibarr
 *
 * Uses cap-rel/dolibarr-integration-sqlite package for a complete Dolibarr environment
 * Optimized with SQLite in RAM for faster test execution
 */

// Define constant to signal we're running in PHPUnit test environment
// This allows json_reply() to skip exit() calls during tests
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// Determine RAM disk location (Linux: /dev/shm, macOS: /tmp)
$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
$ramDbPath = $ramDiskPath . '/smartauth_test_' . getmypid() . '.sdb';

// Store RAM DB path in global for cleanup
$GLOBALS['SMARTAUTH_RAM_DB_PATH'] = $ramDbPath;

// Reset SQLite database to clean state before running tests
// This ensures tests start with a known state regardless of previous test runs
$projectRoot = dirname(__DIR__, 3);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';
$backupDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb_save';

// Prepare clean database
if (is_dir($sqliteVendorPath . '/.git')) {
    // The sqlite package has its own git repo, reset it directly
    exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git reset --hard HEAD 2>/dev/null');
} elseif (is_file($backupDbPath)) {
    copy($backupDbPath, $originalDbPath);
} elseif (is_file($originalDbPath)) {
    copy($originalDbPath, $backupDbPath);
}

// Copy database to RAM disk for ultra-fast I/O
if (is_file($originalDbPath)) {
    // Backup original if it exists
    if (file_exists($originalDbPath) && !file_exists($originalDbPath . '.backup')) {
        copy($originalDbPath, $originalDbPath . '.backup');
    }

    // Copy to RAM
    copy($originalDbPath, $ramDbPath);

    // Replace original with symlink to RAM version
    unlink($originalDbPath);
    symlink($ramDbPath, $originalDbPath);

    fwrite(STDERR, "🚀 Using SQLite in RAM: $ramDbPath\n");

    // Register cleanup: restore original database
    register_shutdown_function(function() use ($originalDbPath, $ramDbPath) {
        if (is_link($originalDbPath)) {
            unlink($originalDbPath);
        }
        if (file_exists($originalDbPath . '.backup')) {
            copy($originalDbPath . '.backup', $originalDbPath);
            unlink($originalDbPath . '.backup');
        }
    });
} else {
    throw new Exception("Original SQLite database not found at: $originalDbPath");
}

// Register shutdown function to cleanup RAM database
register_shutdown_function(function() use ($ramDbPath) {
    if (file_exists($ramDbPath)) {
        unlink($ramDbPath);
        fwrite(STDERR, "\n🧹 Cleaned up RAM database: $ramDbPath\n");
    }
});

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
// Dolibarr will automatically use the symlinked database in RAM
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

// Add module path to dol_document_root so dol_buildpath() can find module files
// This is CRITICAL for Dolibarr to find numbering classes (mod_auth_standard), triggers, etc.
if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
    $conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT);
}

// Create symlink 'smartauth' -> 'smartAuth' for case-insensitive module path resolution
// The module calls _load_tables('/smartauth/sql/') but the folder is 'smartAuth'
$parentDir = dirname($projectRoot);
$symlinkPath = $parentDir . '/smartauth';
if (!file_exists($symlinkPath) && basename($projectRoot) !== 'smartauth') {
    @symlink($projectRoot, $symlinkPath);
    // Register cleanup
    register_shutdown_function(function() use ($symlinkPath) {
        if (is_link($symlinkPath)) {
            @unlink($symlinkPath);
        }
    });
}

$conf->file->dol_document_root['alt0'] = $parentDir;

// Initialize SmartAuth module by calling its init() method
// This is the real activation process, same as when the module is enabled in Dolibarr
require_once $projectRoot . '/core/modules/modSmartauth.class.php';

// Suppress warnings during init (SQL conversion warnings are expected with SQLite)
$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

$moduleSmartAuth = new modSmartauth($db);
$result = $moduleSmartAuth->init();

// Restore error reporting
error_reporting($previousErrorReporting);

// Note: init() may return errors for non-fatal issues like "column already exists"
// We verify the essential tables exist instead of relying solely on return value
$requiredTables = ['llx_smartauth_auth', 'llx_smartauth_devices', 'llx_smartauth_token_family', 'llx_smartauth_ratelimit', 'llx_smartauth_logs'];
foreach ($requiredTables as $table) {
    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='" . $db->escape($table) . "'";
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) == 0) {
        throw new Exception("Failed to initialize SmartAuth module: table $table was not created");
    }
}

// Create sync tables manually (SQLite-compatible syntax)
// These may not be created by _load_tables() due to MySQL-specific syntax
$syncTables = [
    "CREATE TABLE IF NOT EXISTS llx_smartauth_sync_clients (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        fk_device INTEGER NOT NULL,
        client_uuid VARCHAR(64) NOT NULL,
        last_sync_at DATETIME DEFAULT NULL,
        sync_scope TEXT DEFAULT NULL,
        app_version VARCHAR(32) DEFAULT NULL,
        date_creation DATETIME NOT NULL,
        tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status INTEGER DEFAULT 1 NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS llx_smartauth_sync_events (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        fk_client INTEGER NOT NULL,
        event_type VARCHAR(32) NOT NULL,
        table_name VARCHAR(64) DEFAULT NULL,
        object_id INTEGER DEFAULT NULL,
        event_data TEXT DEFAULT NULL,
        date_creation DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS llx_smartauth_sync_conflicts (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        fk_client INTEGER NOT NULL,
        object_type VARCHAR(64) NOT NULL,
        object_id INTEGER NOT NULL,
        client_data TEXT NOT NULL,
        server_data TEXT NOT NULL,
        resolution_strategy VARCHAR(32) DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        date_creation DATETIME NOT NULL,
        status INTEGER DEFAULT 0 NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS llx_smartauth_sync_tombstones (
        rowid INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        object_type VARCHAR(64) NOT NULL,
        object_id INTEGER NOT NULL,
        deleted_at DATETIME NOT NULL,
        deleted_by INTEGER DEFAULT NULL
    )"
];
foreach ($syncTables as $sql) {
    $db->query($sql);
}

// Initialize SmartAuth module configuration in $conf
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

// Module is now properly initialized with tables, permissions, menus, etc.

// Initialize RouteCache for SmartAuth module
// This is required by JwtKeyHelper::getKey() to auto-detect module name
require_once __DIR__ . '/../../../api/RouteCache.php';
\SmartAuth\Api\RouteCache::init('smartauth');

// Load test base class
require_once __DIR__ . '/DolibarrRealTestCase.php';

// Load OAuth2 test base class if OAuth2 directory exists
if (is_dir(__DIR__ . '/OAuth2')) {
    require_once __DIR__ . '/OAuth2/OAuthTestCase.php';
}
