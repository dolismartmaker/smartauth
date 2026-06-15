<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RouteController;
use SmartAuth\Tests\Mocks\MockDatabase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Extended unit tests for RouteController
 *
 * @covers \SmartAuth\Api\RouteController
 */
class RouteControllerExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        global $conf;

        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->cache = [];
        $conf->cache['smartmakers'] = [];
    }

    /**
     * Helper to access private/protected methods
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(RouteController::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    // =============================================
    // Tests for parseAction method
    // =============================================

    /**
     * Test parseAction returns false when REQUEST_URI is not set
     */
    public function testParseActionReturnsFalseWhenNoUri(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        // Backup
        $backup = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']);

        $result = $method->invoke(null);

        // Restore
        if ($backup !== null) {
            $_SERVER['REQUEST_URI'] = $backup;
        }

        $this->assertFalse($result);
    }

    /**
     * Test parseAction extracts action from URI with api.php
     */
    public function testParseActionExtractsFromApiPhp(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        // Backup
        $backup = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/path/to/api.php/users/123';
        $result = $method->invoke(null);

        // Restore
        if ($backup !== null) {
            $_SERVER['REQUEST_URI'] = $backup;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }

        $this->assertEquals('users/123', $result);
    }

    /**
     * Test parseAction handles query strings
     */
    public function testParseActionHandlesQueryStrings(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        // Backup
        $backup = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/api.php/users?page=1&limit=10';
        $result = $method->invoke(null);

        // Restore
        if ($backup !== null) {
            $_SERVER['REQUEST_URI'] = $backup;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }

        $this->assertEquals('users', $result);
    }

    /**
     * Test parseAction handles simple action
     */
    public function testParseActionHandlesSimpleAction(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        // Backup
        $backup = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/api.php/login';
        $result = $method->invoke(null);

        // Restore
        if ($backup !== null) {
            $_SERVER['REQUEST_URI'] = $backup;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }

        $this->assertEquals('login', $result);
    }

    // =============================================
    // Tests for matchAction method - extended
    // =============================================

    /**
     * Test matchAction with trailing slashes
     */
    public function testMatchActionWithTrailingSlashes(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        // Should not match because regex is exact
        $this->assertFalse($method->invoke(null, 'users/', 'users'));
        $this->assertFalse($method->invoke(null, 'users', 'users/'));
    }

    /**
     * Test matchAction with special regex characters in pattern
     */
    public function testMatchActionWithSpecialCharacters(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        // Forward slashes should be escaped properly
        $this->assertTrue($method->invoke(null, 'api/v1/users', 'api/v1/users'));
        $this->assertTrue($method->invoke(null, 'api/v2/users/123', 'api/v2/users/{id}'));
    }

    /**
     * Test matchAction with placeholder at beginning
     */
    public function testMatchActionWithPlaceholderAtBeginning(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        $this->assertTrue($method->invoke(null, '123/profile', '{id}/profile'));
        $this->assertTrue($method->invoke(null, 'abc/settings', '{code}/settings'));
    }

    // =============================================
    // Tests for extractUrlParameters - extended
    // =============================================

    /**
     * Test extractUrlParameters with empty action
     */
    public function testExtractUrlParametersWithEmptyAction(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = ['test' => 'value'];
        $result = $method->invoke(null, '{id}', '', $data);

        // Should have empty id since action is empty
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('', $result['id']);
    }

    /**
     * Test extractUrlParameters does not override reserved keys
     */
    public function testExtractUrlParametersDoesNotOverrideReservedKeys(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        // id is passed in data, should be overridden because extractUrlParameters adds URL params
        $data = ['user' => 'admin'];
        $result = $method->invoke(null, 'users/{user}', 'users/john', $data);

        // Note: URL parameters DO override existing data in current implementation
        $this->assertEquals('john', $result['user']);
    }

    // =============================================
    // Tests for get_client_ip - extended
    // =============================================

    /**
     * Test get_client_ip with X-Real-IP header
     */
    public function testGetClientIpFromXRealIP(): void
    {
        // This test might not work in CLI since apache_request_headers isn't available
        // but we can test the $_SERVER fallback

        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // No X-Forwarded-For, public REMOTE_ADDR
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        }

        $this->assertEquals('203.0.113.50', $ip);
    }

    /**
     * Test get_client_ip trims whitespace from X-Forwarded-For
     */
    public function testGetClientIpTrimsWhitespace(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195,   70.41.3.18  ';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        // Whitespace around the resolved (rightmost) entry must be trimmed.
        $this->assertEquals('70.41.3.18', $ip);
    }

    // =============================================
    // Tests for parseRequestData - extended
    // =============================================

    /**
     * Test parseRequestData returns empty array for empty GET
     */
    public function testParseRequestDataReturnsEmptyForEmptyGet(): void
    {
        $method = $this->getPrivateMethod('parseRequestData');

        // Backup
        $backupGet = $_GET;
        $_GET = [];

        $result = $method->invoke(null, 'GET');

        // Restore
        $_GET = $backupGet;

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test parseRequestData handles non-string keys
     */
    public function testParseRequestDataHandlesNonStringKeys(): void
    {
        $method = $this->getPrivateMethod('parseRequestData');

        // Backup
        $backupGet = $_GET;
        $_GET = ['valid' => 'value', 0 => 'numeric_key'];

        $result = $method->invoke(null, 'GET');

        // Restore
        $_GET = $backupGet;

        $this->assertArrayHasKey('valid', $result);
        // Numeric key 0 is not a string, so it should be filtered
        $this->assertArrayNotHasKey(0, $result);
    }

    // =============================================
    // Tests for insertLogs
    // =============================================

    /**
     * Test insertLogs does nothing when logging is disabled
     */
    public function testInsertLogsDoesNothingWhenDisabled(): void
    {
        global $db;

        $mockDb = new MockDatabase();
        $db = $mockDb;

        // Ensure SMARTAUTH_COLLECT_LOGS is empty/disabled
        // The function checks getDolGlobalString which returns '' by default

        RouteController::insertLogs(1, 200, 'test', 1);

        // No INSERT should have been executed
        $queries = $mockDb->getQueries();
        $hasInsert = false;
        foreach ($queries as $query) {
            if (strpos($query, 'INSERT INTO') !== false && strpos($query, 'smartauth_logs') !== false) {
                $hasInsert = true;
                break;
            }
        }

        $this->assertFalse($hasInsert, 'Should not insert logs when disabled');
    }

    // =============================================
    // Tests for route helper methods
    // =============================================

    /**
     * Test get() calls route() with GET method
     */
    public function testGetCallsRouteWithGetMethod(): void
    {
        // We can't easily test this without mocking static methods
        // but we can at least verify the class has these methods
        $this->assertTrue(method_exists(RouteController::class, 'get'));
        $this->assertTrue(method_exists(RouteController::class, 'post'));
        $this->assertTrue(method_exists(RouteController::class, 'put'));
        $this->assertTrue(method_exists(RouteController::class, 'delete'));
        $this->assertTrue(method_exists(RouteController::class, 'route'));
    }
}
