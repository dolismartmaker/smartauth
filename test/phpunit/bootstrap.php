<?php

/**
 * PHPUnit Bootstrap for SmartAuth tests
 *
 * This file sets up the test environment without requiring a full Dolibarr installation.
 */

// Configure pcov for code coverage
if (extension_loaded('pcov')) {
    ini_set('pcov.directory', dirname(__DIR__, 2));
}

// Autoload composer dependencies
require_once __DIR__ . '/../../vendor/autoload.php';

// Define MAIN_DB_PREFIX for tests (used in SQL queries)
if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

// Define DOL_DOCUMENT_ROOT for tests
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', '/tmp/dolibarr-mock');
}

// Initialize global $conf object
global $conf;
$conf = new stdClass();
$conf->cache = [];
$conf->cache['smartmakers'] = [];

// Mock dol_syslog function if not defined
if (!function_exists('dol_syslog')) {
    function dol_syslog($message, $level = 0)
    {
        // Silent in tests, or uncomment to debug:
        // echo "[LOG $level] $message\n";
    }
}

// Mock dol_now function if not defined
if (!function_exists('dol_now')) {
    function dol_now()
    {
        return time();
    }
}

// Mock getDolGlobalInt function if not defined
if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt($key, $default = 0)
    {
        return $default;
    }
}

// Mock getDolGlobalString function if not defined
if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString($key, $default = '')
    {
        return $default;
    }
}

// Mock sanitizeVal function if not defined
if (!function_exists('sanitizeVal')) {
    function sanitizeVal($value, $type = 'alphanohtml')
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}

// Mock json_reply function if not defined
if (!function_exists('json_reply')) {
    function json_reply($data, $httpCode = 200)
    {
        throw new \SmartAuth\Tests\Mocks\JsonReplyException($data, $httpCode);
    }
}

// Mock dol_include_once function if not defined
if (!function_exists('dol_include_once')) {
    function dol_include_once($path, $classname = '')
    {
        // No-op in tests - classes are autoloaded
    }
}

// Mock isModEnabled function if not defined
if (!function_exists('isModEnabled')) {
    function isModEnabled($module)
    {
        return false;
    }
}

// Mock getEntity function if not defined
if (!function_exists('getEntity')) {
    function getEntity($element, $shared = 1)
    {
        return '1';
    }
}
