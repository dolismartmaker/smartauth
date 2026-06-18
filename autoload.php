<?php
/**
 * autoload.php
 *
 * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__.'/api/tools.php';

// vendor/ may be absent on a deployment where 'composer install' was not run.
// Hard-requiring it would fatal every Dolibarr page that dol_include_once's
// this autoload (e.g. modules consuming only the dm* mappers, which do not need
// firebase/php-jwt or web-push). Degrade gracefully: log the cause, keep the
// SmartAuth class autoloader below registered. Flows that truly need the vendor
// libs (OAuth/JWT/push) will still fail, but with an explicit reason logged.
$smartauthVendorAutoload = __DIR__."/vendor/autoload.php";
if (is_file($smartauthVendorAutoload)) {
    require_once $smartauthVendorAutoload;
} elseif (function_exists('dol_syslog')) {
    dol_syslog("[SmartAuth] autoload: vendor/autoload.php introuvable (" . $smartauthVendorAutoload . ") - dependances composer (JWT, web-push) indisponibles, lancer 'composer install --no-dev'. L'autoloader de classes SmartAuth reste actif.", LOG_WARNING);
} else {
    error_log("SmartAuth autoload: vendor/autoload.php introuvable (" . $smartauthVendorAutoload . "), lancer 'composer install --no-dev'.");
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
    // you want to allow, and if so:
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        // may also be using PUT, PATCH, HEAD etc
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

/**
 * An example of a project-specific implementation.
 *
 * After registering this autoload function with SPL, the following line
 * would cause the function to attempt to load the \Foo\Bar\Baz\Qux class
 * from /path/to/project/src/Baz/Qux.php:
 *
 *      new \Foo\Bar\Baz\Qux;
 *
 * @param string $class The fully-qualified class name.
 * @return void
 */
spl_autoload_register(function ($class) {
	dol_syslog("[SmartAuth] use spl_autoload from smartAuth for $class");
    $prefix = $base_dir = "";

    $map = [
        'SmartAuth\\Api\\' => __DIR__ . '/api/',
        'SmartAuth\\DolibarrMapping\\' => __DIR__ . '/dolMapping/'
    ];

    foreach($map as $key => $value) {
        if(substr($class,0,strlen($key)) != $key) {
            continue;
        }
        $prefix = $key;
        $base_dir = $value;
    }

    if($prefix == '' || $base_dir == '') {
        return;
    }

    // does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // get the relative class name
    $relative_class = substr($class, $len);

    // replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // if the file exists, require it
    if (file_exists($file)) {
		dol_syslog("[SmartAuth] use spl_autoload from smartAuth::require $file");
        require $file;
    }
});

?>
