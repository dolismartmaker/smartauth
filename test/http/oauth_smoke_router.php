<?php

/**
 * HTTP smoke router for the OAuth2 front controller (public/index.php).
 *
 * The point of this router is to catch a very specific bug class:
 *   a controller file that declares `use SomeTrait;` inside its class body
 *   WITHOUT also `dol_include_once`-ing the trait file at the top.
 *
 * The integration-dolibarr PHPUnit suite cannot catch this because every
 * test file pre-loads the OAuth2 controllers (and their traits) via
 * `dol_include_once` BEFORE the route dispatcher runs. PHPUnit discovers
 * all test files up front and their top-level `require_once` warm the
 * trait in memory long before any single test executes. So even if a
 * controller forgets to require its trait, the trait is already there.
 *
 * This router does the opposite: it bootstraps Dolibarr ONLY and then
 * `require`s `public/index.php`, which is the production entry point.
 * `public/index.php` is the one in charge of the full include chain of
 * every OAuth2 controller. A missing `dol_include_once` of a trait fires
 * a PHP fatal exactly the way it does in production.
 *
 * Tests using this router go through `OAuthFrontControllerSmokeTestCase`.
 *
 * The Dolibarr SQLite bootstrap mirrors test/http/router.php so the two
 * harnesses can coexist on different ports.
 */

// Deliberately do NOT define PHPUNIT_RUNNING here.
//
// public/index.php has at least one guard of the shape:
//   if (!(defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING)) {
//       \SmartAuth\Api\RouteController::emitSecurityHeaders();
//   }
// If we set PHPUNIT_RUNNING we skip that branch entirely, which means
// any missing dol_include_once on the classes referenced inside is
// invisible to the smoke. The whole purpose of this router is to
// reproduce a fresh prod-like request, so we keep PHPUNIT_RUNNING
// undefined. The price is that json_reply() exits the request when
// reached (instead of throwing) - that is exactly what it does in
// production, and the PHP built-in server forks per request so the
// exit only ends the current response, not the whole server.

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Serve static files directly (none expected on this vhost, but keep the
// guard so a stray favicon request from a curl-with-redirects does not
// blow up the router).
if (preg_match('/\.(js|css|png|jpg|gif|ico|svg)$/i', $requestPath)) {
    return false;
}

// ----------------------------------------------------------------------
// RAM-backed SQLite database, scoped to the server port. Same pattern as
// test/http/router.php so the two routers do not collide on disk.
// ----------------------------------------------------------------------

$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
$serverPort = $_SERVER['SERVER_PORT'] ?? '0';
$ramDbPath = $ramDiskPath . '/smartauth_oauth_smoke_port_' . $serverPort . '.sdb';

$GLOBALS['SMARTAUTH_RAM_DB_PATH'] = $ramDbPath;

$projectRoot = dirname(__DIR__, 2);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';
$backupDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb_save';
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
        file_put_contents($markerPath, (string) time());
    }
} else {
    if (!is_link($originalDbPath) && file_exists($ramDbPath)) {
        if (file_exists($originalDbPath)) {
            unlink($originalDbPath);
        }
        symlink($ramDbPath, $originalDbPath);
    }
}

// ----------------------------------------------------------------------
// Composer autoload + Dolibarr bootstrap. From here we MUST NOT load any
// SmartAuth OAuth2 controller: the whole point of this smoke is to let
// public/index.php exercise the include chain itself.
//
// Why we strip the SmartAuth\Api\* PSR-4 mapping from composer's
// autoloader RIGHT AFTER requiring it: in production, smartauth ships
// as a Dolibarr custom module, and public/index.php does NOT load
// vendor/autoload.php. The module relies exclusively on dol_include_once
// to wire up its classes. A controller that declares `use SomeTrait;`
// without first dol_include_once-ing the trait file would FATAL in
// production but appear healthy in dev, because composer's PSR-4
// autoloader silently resolves `SmartAuth\Api\OAuth2\SomeTrait`
// against api/OAuth2/SomeTrait.php and loads it on demand. Stripping
// the mapping forces public/index.php to behave the way it does on
// the deployed server: any missing dol_include_once becomes a fatal.
//
// We KEEP composer's classmap for `class/` (CommonObject subclasses
// Dolibarr expects to find via its own loader) and the autoloader
// for other vendor packages, because those mimic the Dolibarr
// runtime where the Dolibarr core itself takes care of loading them.
// ----------------------------------------------------------------------

$composerLoader = require_once $projectRoot . '/vendor/autoload.php';
if (is_object($composerLoader) && method_exists($composerLoader, 'setPsr4')) {
    $composerLoader->setPsr4('SmartAuth\\Api\\', []);
    $composerLoader->setPsr4('SmartAuth\\DolibarrMapping\\', []);
}

$dolibarrPath = realpath($projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/htdocs');

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

$_SERVER['SCRIPT_FILENAME'] = $dolibarrPath . '/test.php';
$_SERVER['DOCUMENT_ROOT'] = $dolibarrPath;

$originalDir = getcwd();
chdir($dolibarrPath);

ob_start();
$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
global $conf, $db, $user, $langs, $hookmanager, $mysoc;
require_once $dolibarrPath . '/filefunc.inc.php';
require_once DOL_DOCUMENT_ROOT . '/master.inc.php';
error_reporting($previousErrorReporting);
ob_end_clean();

chdir($originalDir);

if (!$db || !$user) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Dolibarr failed to initialize']);
    exit;
}

$user->fetch(1);

if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
    $conf->file->dol_document_root = ['main' => DOL_DOCUMENT_ROOT];
}
$parentDir = dirname($projectRoot);
$conf->file->dol_document_root['alt0'] = $parentDir;

require_once $projectRoot . '/core/modules/modSmartauth.class.php';
$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
$moduleSmartAuth = new modSmartauth($db);
$moduleSmartAuth->init();
error_reporting($previousErrorReporting);

if (!isset($conf->smartauth)) {
    $conf->smartauth = new stdClass();
}
$conf->smartauth->enabled = 1;
$conf->smartauth->dir_output = DOL_DATA_ROOT . '/smartauth';
$conf->smartauth->dir_temp = DOL_DATA_ROOT . '/smartauth/temp';
if (!is_dir($conf->smartauth->dir_output)) {
    @mkdir($conf->smartauth->dir_output, 0755, true);
}

// Enable the OAuth2 server so public/index.php routes past the early
// "OAuth disabled" 503 short-circuit and actually instantiates each
// controller. Setting the global by hand (rather than via Dolibarr's
// admin UI) is enough because OAuthConfig::isEnabled() reads it via
// getDolGlobalInt() on every call.
if (!isset($conf->global)) {
    $conf->global = new stdClass();
}
$conf->global->SMARTAUTH_OAUTH_ENABLED = 1;

// public/index.php discovers main.inc.php by walking up from its own
// dirname and falling back to a few hard-coded paths. None match our
// dev checkout, so it would 500 with "Server configuration error" the
// first time we hit it. Point it at the SQLite vendor's main.inc.php
// via the documented $_ENV override. require_once inside public/index.php
// becomes a no-op because the file we just bootstrapped (master.inc.php)
// already pulled in everything main.inc.php would.
$_ENV['DOLIBARR_MAIN_INC'] = $dolibarrPath . '/main.inc.php';

// Hand off to the production entry point. From this line on, any forgotten
// dol_include_once of a trait file inside an OAuth2 controller will fire
// a real PHP fatal exactly as it would on the deployed server.
require $projectRoot . '/public/index.php';
