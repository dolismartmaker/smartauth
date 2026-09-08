<?php

/**
 * Bootstrap for HTTP functional tests
 */

// Load composer autoload
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

// Load the base classes: PSR-4 maps SmartAuth\Tests\Http\ to test/phpunit/Http/,
// which does not match this lowercase directory, so they are required by hand.
require_once __DIR__ . '/HttpTestCase.php';
require_once __DIR__ . '/DolibarrPageTestCase.php';
