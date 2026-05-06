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

// RAM disk path scoped to the server PORT (stable across worker forks of
// the PHP built-in server, unlike getmypid()).
$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
$serverPort = $_SERVER['SERVER_PORT'] ?? '0';
$ramDbPath = $ramDiskPath . '/smartauth_http_test_port_' . $serverPort . '.sdb';

$GLOBALS['SMARTAUTH_RAM_DB_PATH'] = $ramDbPath;

$projectRoot = dirname(__DIR__, 2);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';
$backupDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb_save';

// Prepare the RAM DB once: a marker file (next to the RAM DB) tracks
// whether initialisation has already happened for this server port. The
// PHP built-in server forks per request so process-static state would
// not survive between requests; this on-disk flag does.
$markerPath = $ramDbPath . '.ready';

if (!file_exists($markerPath)) {
    if (is_dir($sqliteVendorPath . '/.git')) {
        exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git reset --hard HEAD 2>/dev/null');
    } elseif (is_file($backupDbPath)) {
        copy($backupDbPath, $originalDbPath);
    }

    if (is_file($originalDbPath)) {
        if (!file_exists($originalDbPath . '.backup')) {
            copy($originalDbPath, $originalDbPath . '.backup');
        }
        copy($originalDbPath, $ramDbPath);
        if (is_file($originalDbPath) && !is_link($originalDbPath)) {
            unlink($originalDbPath);
        }
        if (!is_link($originalDbPath)) {
            symlink($ramDbPath, $originalDbPath);
        }
        // Mark as initialised so subsequent requests reuse the same RAM DB.
        file_put_contents($markerPath, (string) time());
    }
} else {
    // Subsequent request: ensure the symlink is still in place.
    if (!is_link($originalDbPath) && file_exists($ramDbPath)) {
        if (file_exists($originalDbPath)) {
            unlink($originalDbPath);
        }
        symlink($ramDbPath, $originalDbPath);
    }
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

// Account / registration controllers (Lots 5-7)
require_once $projectRoot . '/api/OAuth2/OAuthConfig.php';
require_once $projectRoot . '/api/OAuth2/HookHelper.php';
require_once $projectRoot . '/api/Account/EmailValidationToken.php';
require_once $projectRoot . '/api/Account/RegistrationService.php';
require_once $projectRoot . '/api/Account/RegisterController.php';

// Make sure the SOCIETE_CODECLIENT default works on the SQLite test DB
if (empty($conf->global->SOCIETE_CODECLIENT_ADDON)) {
    $conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_monkey';
}
if (empty($conf->global->SOCIETE_CODEFOURNISSEUR_ADDON)) {
    $conf->global->SOCIETE_CODEFOURNISSEUR_ADDON = 'mod_codefournisseur_panda';
}

use SmartAuth\Api\PwaController;
use SmartAuth\Api\Account\RegisterController;

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

    case $requestPath === '/_test/seed-oauth-client':
        // Seed a branded OAuth2 client so the /register branding test can
        // reach an existing client_id. Test-only route; never exposed in
        // production routing (public/index.php).
        require_once $projectRoot . '/class/smartauthoauthclient.class.php';
        $clientId = $_GET['client_id'] ?? '';
        $clientName = $_GET['name'] ?? '';
        if ($clientId === '' || $clientName === '') {
            http_response_code(400);
            echo json_encode(['error' => 'client_id and name required']);
            break;
        }
        $client = new SmartAuthOAuthClient($db);
        $existing = $client->fetch(0, null, $clientId);
        if ($existing > 0) {
            header('Content-Type: application/json');
            echo json_encode(['client_pk' => (int) $client->id, 'reused' => true]);
            break;
        }
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'BRANDED-' . $clientId;
        $client->client_id = $clientId;
        $client->name = $clientName;
        $client->setRedirectUrisArray(['https://app.example.com/cb']);
        $client->setAllowedScopesArray(['openid', 'profile', 'email']);
        $client->setAllowedGrantsArray(['authorization_code']);
        $client->is_confidential = 1;
        $client->require_pkce = 0;
        $client->access_token_lifetime = 3600;
        $client->refresh_token_lifetime = 2592000;
        $client->status = 1;
        $client->entity = 1;
        $result = $client->create($user);

        // Force-write columns that createCommon may not persist on SQLite
        if ($result > 0) {
            $update = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_clients SET";
            $update .= " name = '" . $db->escape($clientName) . "',";
            $update .= " status = 1,";
            $update .= " entity = 1";
            $update .= " WHERE rowid = " . ((int) $client->id);
            $db->query($update);
        }

        // Confirm the row is fetchable via the same lookup that
        // RegisterController will use, so we can surface the actual state.
        $verify = new SmartAuthOAuthClient($db);
        $verifyResult = $verify->fetch(0, null, $clientId);

        header('Content-Type: application/json');
        echo json_encode([
            'client_pk' => $result > 0 ? (int) $client->id : -1,
            'errors' => $result > 0 ? [] : (array) $client->errors,
            'verify_fetch' => $verifyResult,
            'verify_status' => $verify->status ?? null,
            'verify_name' => $verify->name ?? null,
            'verify_enabled' => method_exists($verify, 'isEnabled') ? $verify->isEnabled() : null,
        ]);
        break;

    case $requestPath === '/register':
        // RegisterController instantiates RegistrationService with a default
        // (real) SMTP-backed sender. For HTTP tests we only inspect the
        // rendered HTML and HTTP status, so use a no-op email sender by
        // injecting it through the RegistrationService constructor.
        $registrationService = new \SmartAuth\Api\Account\RegistrationService($db, function () {
            return true;
        });
        $controller = new RegisterController($db, $registrationService);
        $controller->handle();
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found', 'path' => $requestPath]);
        break;
}
