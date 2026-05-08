<?php

/**
 * Shared test helpers for SmartAuth.
 *
 * Loaded from both the unit bootstrap (test/phpunit/bootstrap.php) and the
 * Dolibarr integration bootstrap (test/phpunit/integration-dolibarr/bootstrap.php)
 * so any helper defined here is available to every test suite.
 */

if (!function_exists('smartauth_test_reset_conf')) {
    /**
     * Reset the global Dolibarr $conf to a "complete enough" stdClass that
     * does not trip Dolibarr core code reading $conf->entity, $conf->file,
     * $conf->modules_parts, etc.
     *
     * Many unit tests do `$conf = new \stdClass()` in their setUp to wipe
     * leftover module state, but doing so removes properties Dolibarr's
     * own libraries expect to find. Calling this helper from setUp (or
     * directly when a test needs a clean conf) avoids the "Undefined
     * property: stdClass::$xxx" PHP 8.2 errors that surface as PHPUnit
     * failures depending on test order.
     *
     * @return \stdClass The new $conf, also written into $GLOBALS.
     */
    function smartauth_test_reset_conf(): \stdClass
    {
        $fresh = new \stdClass();
        $fresh->entity = 1;
        $fresh->global = new \stdClass();
        $fresh->cache = ['smartmakers' => []];
        $fresh->file = new \stdClass();
        $fresh->file->dol_document_root = ['main' => defined('DOL_DOCUMENT_ROOT') ? DOL_DOCUMENT_ROOT : ''];
        $fresh->file->mailing_limit_sendbyweb = 0;
        $fresh->file->mailing_limit_sendbycli = 0;
        $fresh->modules_parts = ['hooks' => [], 'tabs' => []];
        $GLOBALS['conf'] = $fresh;
        return $fresh;
    }
}
