<?php

/**
 * HTTP Test Router
 *
 * Simple router for HTTP tests using dolibarr-integration-sqlite bootstrap.
 * Used with PHP built-in server: php -S localhost:8888 test/http/router.php
 */

// Prevent CLI-specific constants from being redefined
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Serve static files directly
if (preg_match('/\.(js|css|png|jpg|gif|ico|svg)$/i', $requestPath)) {
    return false;
}

// RAM disk path for this server process
$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
$ramDbPath = $ramDiskPath . '/smartauth_http_test_' . getmypid() . '.sdb';

// Store RAM DB path in global for cleanup
$GLOBALS['SMARTAUTH_RAM_DB_PATH'] = $ramDbPath;

// Project root
$projectRoot = dirname(__DIR__, 2);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';
$backupDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb_save';

// Prepare clean database (only once per server process)
static $dbInitialized = false;
if (!$dbInitialized) {
    if (is_dir($sqliteVendorPath . '/.git')) {
        exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git reset --hard HEAD 2>/dev/null');
    } elseif (is_file($backupDbPath)) {
        copy($backupDbPath, $originalDbPath);
    }

    // Copy database to RAM disk
    if (is_file($originalDbPath)) {
        if (file_exists($originalDbPath) && !file_exists($originalDbPath . '.backup')) {
            copy($originalDbPath, $originalDbPath . '.backup');
        }
        copy($originalDbPath, $ramDbPath);
        unlink($originalDbPath);
        symlink($ramDbPath, $originalDbPath);

        register_shutdown_function(function () use ($originalDbPath, $ramDbPath) {
            if (is_link($originalDbPath)) {
                unlink($originalDbPath);
            }
            if (file_exists($originalDbPath . '.backup')) {
                copy($originalDbPath . '.backup', $originalDbPath);
                unlink($originalDbPath . '.backup');
            }
            if (file_exists($ramDbPath)) {
                unlink($ramDbPath);
            }
        });
    }
    $dbInitialized = true;
}

// Load composer autoload
require_once $projectRoot . '/vendor/autoload.php';

// Path to dolibarr-integration-sqlite package
$dolibarrPath = realpath($projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/htdocs');

// Define Dolibarr constants
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

// Set server variables
$_SERVER['SCRIPT_FILENAME'] = $dolibarrPath . '/test.php';
$_SERVER['DOCUMENT_ROOT'] = $dolibarrPath;

// Change to Dolibarr htdocs directory
$originalDir = getcwd();
chdir($dolibarrPath);

// Load Dolibarr
ob_start();
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
global $conf, $db, $user, $langs, $hookmanager, $mysoc;
require_once $dolibarrPath . '/filefunc.inc.php';
require_once DOL_DOCUMENT_ROOT . '/master.inc.php';
error_reporting(E_ALL);
ob_end_clean();

chdir($originalDir);

// Verify Dolibarr is initialized
if (!$db || !$user) {
    http_response_code(500);
    echo json_encode(['error' => 'Dolibarr failed to initialize']);
    exit;
}

// Load admin user
$user->fetch(1);

// Add module path
if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
    $conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT);
}
$parentDir = dirname($projectRoot);
$conf->file->dol_document_root['alt0'] = $parentDir;

// Initialize SmartAuth module
require_once $projectRoot . '/core/modules/modSmartauth.class.php';
$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
$moduleSmartAuth = new modSmartauth($db);
$moduleSmartAuth->init();
error_reporting($previousErrorReporting);

// Initialize SmartAuth configuration
if (!isset($conf->smartauth)) {
    $conf->smartauth = new stdClass();
}
$conf->smartauth->enabled = 1;
$conf->smartauth->dir_output = DOL_DATA_ROOT . '/smartauth';
$conf->smartauth->dir_temp = DOL_DATA_ROOT . '/smartauth/temp';

if (!is_dir($conf->smartauth->dir_output)) {
    @mkdir($conf->smartauth->dir_output, 0755, true);
}

// Initialize RouteCache
require_once $projectRoot . '/api/RouteCache.php';
\SmartAuth\Api\RouteCache::init('smartauth');

// Load SmartAuth classes
require_once $projectRoot . '/api/SmartAuthLogger.php';
require_once $projectRoot . '/api/PwaController.php';

use SmartAuth\Api\PwaController;

// Route the request
switch (true) {
    case $requestPath === '/manifest.webmanifest':
        $controller = new PwaController();
        $controller->manifest();
        break;

    case preg_match('#^/icon/(\d+)$#', $requestPath, $matches):
        $controller = new PwaController();
        $controller->icon(['size' => (int)$matches[1]]);
        break;

    case $requestPath === '/ping':
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'timestamp' => time()]);
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found', 'path' => $requestPath]);
        break;
}
