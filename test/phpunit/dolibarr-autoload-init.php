<?php

/**
 * Early Dolibarr initialization for PHPUnit autoloader
 *
 * This file is loaded via composer's autoload files directive.
 * It only activates for integration-dolibarr tests to avoid interfering
 * with unit tests and mock-based integration tests.
 *
 * The SmartAuth classes have require_once DOL_DOCUMENT_ROOT which needs
 * DOL_DOCUMENT_ROOT to be defined before the class files are included.
 */

// Only initialize if DOL_DOCUMENT_ROOT is not already defined
if (defined('DOL_DOCUMENT_ROOT')) {
    return;
}

// Check if we're running integration-dolibarr tests
// This is determined by checking PHP_ARGV or by an environment variable
$_isIntegrationDolibarrTest = false;

// Check command line arguments for integration-dolibarr config
if (isset($_SERVER['argv'])) {
    foreach ($_SERVER['argv'] as $_arg) {
        if (strpos($_arg, 'integration-dolibarr') !== false) {
            $_isIntegrationDolibarrTest = true;
            break;
        }
    }
}

// Also check environment variable that can be set before running tests
if (getenv('DOLIBARR_INTEGRATION_TEST') === '1') {
    $_isIntegrationDolibarrTest = true;
}

// If not running integration-dolibarr tests, don't initialize real Dolibarr
if (!$_isIntegrationDolibarrTest) {
    unset($_isIntegrationDolibarrTest);
    return;
}

unset($_isIntegrationDolibarrTest);

// Check if cap-rel/dolibarr-integration-sqlite is installed
$_dolibarr_autoload_init_path = __DIR__ . '/../../vendor/cap-rel/dolibarr-integration-sqlite/htdocs';
if (!is_dir($_dolibarr_autoload_init_path)) {
    return; // Package not installed
}

$_dolibarr_autoload_init_path = realpath($_dolibarr_autoload_init_path);

// Define DOL_DOCUMENT_ROOT immediately (before any class loading)
define('DOL_DOCUMENT_ROOT', $_dolibarr_autoload_init_path);

// Read conf.php to extract the instance_unique_id for $conf
// We use file_get_contents to avoid require_once caching
$_dolibarr_conf_content = file_get_contents($_dolibarr_autoload_init_path . '/conf/conf.php');
$_dolibarr_instance_unique_id = '';
$_dolibarr_db_encryption = 0;

// Extract dolibarr_main_instance_unique_id
if (preg_match('/\$dolibarr_main_instance_unique_id\s*=\s*[\'"]([^\'"]+)[\'"]/', $_dolibarr_conf_content, $_matches)) {
    $_dolibarr_instance_unique_id = $_matches[1];
}
// Extract dolibarr_main_db_encryption
if (preg_match('/\$dolibarr_main_db_encryption\s*=\s*[\'"]?(\d+)[\'"]?/', $_dolibarr_conf_content, $_matches)) {
    $_dolibarr_db_encryption = (int)$_matches[1];
}

// Create minimal global $conf object immediately
// This is needed because security.lib.php functions like dolDecrypt() are called
// during class loading (when CommonObject descendants are loaded)
global $conf;
$conf = new stdClass();
$conf->file = new stdClass();
$conf->file->instance_unique_id = $_dolibarr_instance_unique_id;
$conf->file->dolcrypt_key = $_dolibarr_instance_unique_id;
$conf->db = new stdClass();
$conf->db->dolibarr_main_db_encryption = $_dolibarr_db_encryption;
$conf->global = new stdClass();

// Clean up temp variables
unset($_dolibarr_autoload_init_path, $_dolibarr_conf_content, $_dolibarr_instance_unique_id, $_dolibarr_db_encryption, $_matches);
