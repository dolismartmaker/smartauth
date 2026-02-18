<?php

// Load Composer autoloader to register project classes before stubs
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

//include('/home/erics/dev/dolibarr/dolibarr-git/build/phpstan/bootstrap.php');

/*
define('DOL_DOCUMENT_ROOT', '/home/erics/dev/dolibarr/dolibarr-git/htdocs');
define('DOL_DATA_ROOT', '/home/erics/dev/dolibarr/dolibarr-git/documents');
define('DOL_URL_ROOT', '/');
*/

define('DOL_DOCUMENT_ROOT', '1');
define('DOL_DATA_ROOT', '2');
define('DOL_URL_ROOT', '3');
define('DOL_MAIN_URL_ROOT', '3');
define("NOLOGIN", '1');
define("MAIN_DB_PREFIX", '1');
