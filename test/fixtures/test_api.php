<?php

/**
 * Test API entry point for HTTP integration tests
 *
 * This file is used by PHPUnit HTTP tests to test real HTTP responses
 * from the RouteController and AuthController.
 */

// Minimal bootstrap for testing
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define test constants
define('MAIN_DB_PREFIX', 'llx_');
define('DOL_DOCUMENT_ROOT', __DIR__ . '/../../vendor/cap-rel/dolibarr-integration-sqlite/htdocs');
define('DOL_DATA_ROOT', '/tmp');

// Mock dol_include_once BEFORE loading AuthController
if (!function_exists('dol_include_once')) {
    function dol_include_once($path, $classname = '') {
        // No-op for tests
    }
}

// Mock dol_syslog if not exists
if (!function_exists('dol_syslog')) {
    function dol_syslog($message, $level = 0) {}
}

// Mock getDolGlobalString if not exists
if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString($key, $default = '') {
        global $conf;
        return $conf->global->$key ?? $default;
    }
}

// Mock sanitizeVal if not exists
if (!function_exists('sanitizeVal')) {
    function sanitizeVal($val, $check = 'alphanoithypnospecial', $default = '') {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $val ?? '');
    }
}

// Mock getEntity if not exists
if (!function_exists('getEntity')) {
    function getEntity($tablename, $shared = 0) {
        return '1';
    }
}

// Mock price2num if not exists
if (!function_exists('price2num')) {
    function price2num($amount, $type = '', $round = 0) {
        return (float) $amount;
    }
}

// Autoload
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../api/tools.php';
require_once __DIR__ . '/../../api/RouteController.php';

use SmartAuth\Api\RouteController;
use SmartAuth\Api\AuthController;

// Mock global $db for tests
$GLOBALS['db'] = new class {
    public function escape($val) { return addslashes($val); }
    public function query($sql) { return true; }
    public function fetch_object($res) { return null; }
    public function prefix() { return MAIN_DB_PREFIX; }
    public function lasterror() { return ''; }
    public function free($res) {}
};

// Mock global $conf
$GLOBALS['conf'] = new class {
    public $entity = 1;
    public $global;
    public function __construct() {
        $this->global = new \stdClass();
        $this->global->SMARTAUTH_COLLECT_LOGS = '';
    }
    public function setValues($db) {}
};

// Mock global $mysoc
$GLOBALS['mysoc'] = new class {
    public function setMysoc($conf) {}
};

// Mock global $smartAuthAppID
$GLOBALS['smartAuthAppID'] = 1;

// Mock dol_syslog if not exists
if (!function_exists('dol_syslog')) {
    function dol_syslog($message, $level = 0) {}
}

// Mock getDolGlobalString if not exists
if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString($key, $default = '') {
        global $conf;
        return $conf->global->$key ?? $default;
    }
}

// Mock sanitizeVal if not exists
if (!function_exists('sanitizeVal')) {
    function sanitizeVal($val, $check = 'alphanoithypnospecial', $default = '') {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $val ?? '');
    }
}

// Define routes
RouteController::get('health', 'HealthController', 'check', false);
RouteController::post('auth/login', AuthController::class, 'Login', false);
RouteController::get('protected/test', 'ProtectedController', 'test', true);

// Simple health check controller for testing
class HealthController {
    public function check($payload) {
        return [['status' => 'ok', 'timestamp' => time()], 200];
    }
}

// Protected test controller
class ProtectedController {
    public function test($payload) {
        return [['message' => 'authenticated'], 200];
    }
}
