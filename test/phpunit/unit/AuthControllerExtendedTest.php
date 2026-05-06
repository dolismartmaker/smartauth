<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\SmartTokenConfig;
use SmartAuth\Tests\Mocks\MockDatabase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Extended unit tests for AuthController - testing more private methods
 *
 * @covers \SmartAuth\Api\AuthController
 */
class AuthControllerExtendedTest extends TestCase
{
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->controller = new AuthController();

        // Ensure global conf is set
        global $conf, $mysoc;
        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->cache = [];
        $conf->cache['smartmakers'] = [];

        // Mock mysoc for index() method
        if (!is_object($mysoc)) {
            $mysoc = new \stdClass();
        }
        $mysoc->name = 'Test Company';
    }

    /**
     * Helper to access private/protected methods
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(AuthController::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Helper to set private property
     */
    private function setPrivateProperty(object $object, string $propertyName, $value): void
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    // =============================================
    // Tests for _getSalt2 method
    // =============================================

    /**
     * Test _getSalt2 returns 16-character salt from device UUID
     */
    public function testGetSalt2ReturnsCorrectLengthFromUUID(): void
    {
        $method = $this->getPrivateMethod('_getSalt2');

        $deviceUuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = $method->invoke($this->controller, $deviceUuid);

        $this->assertEquals(16, strlen($result));
    }

    /**
     * Test _getSalt2 returns consistent salt for same UUID
     */
    public function testGetSalt2IsConsistentForSameUUID(): void
    {
        $method = $this->getPrivateMethod('_getSalt2');

        $deviceUuid = '550e8400-e29b-41d4-a716-446655440000';
        $result1 = $method->invoke($this->controller, $deviceUuid);
        $result2 = $method->invoke($this->controller, $deviceUuid);

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test _getSalt2 returns different salt for different UUIDs
     */
    public function testGetSalt2ReturnsDifferentSaltForDifferentUUIDs(): void
    {
        $method = $this->getPrivateMethod('_getSalt2');

        $result1 = $method->invoke($this->controller, 'uuid-1111');
        $result2 = $method->invoke($this->controller, 'uuid-2222');

        $this->assertNotEquals($result1, $result2);
    }

    /**
     * Test _getSalt2 uses HTTP header when no argument provided
     */
    public function testGetSalt2UsesHttpHeaderWhenNoArgument(): void
    {
        $method = $this->getPrivateMethod('_getSalt2');

        // Use a valid UUID format for the header
        $validUuid = '550e8400-e29b-41d4-a716-446655440000';
        $_SERVER['HTTP_X_DEVICEID'] = $validUuid;

        $result1 = $method->invoke($this->controller, '');
        $result2 = $method->invoke($this->controller, $validUuid);

        // Cleanup
        unset($_SERVER['HTTP_X_DEVICEID']);

        // Both should produce same result since they use same device id
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test _getSalt2 falls back to User-Agent when no device ID
     */
    public function testGetSalt2FallsBackToUserAgent(): void
    {
        $method = $this->getPrivateMethod('_getSalt2');

        // Backup and clear device ID header
        $backup = $_SERVER['HTTP_X_DEVICEID'] ?? null;
        $_SERVER['HTTP_X_DEVICEID'] = '';

        // Set User-Agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';

        $result = $method->invoke($this->controller, '');

        $this->assertEquals(16, strlen($result));

        // Cleanup
        if ($backup !== null) {
            $_SERVER['HTTP_X_DEVICEID'] = $backup;
        } else {
            unset($_SERVER['HTTP_X_DEVICEID']);
        }
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    // =============================================
    // Tests for _validateUUID method - extended
    // =============================================

    /**
     * Test _validateUUID with various edge cases
     */
    public function testValidateUUIDEdgeCases(): void
    {
        $method = $this->getPrivateMethod('_validateUUID');

        // Test null-like strings
        $this->assertFalse($method->invoke(null, 'null'));
        $this->assertFalse($method->invoke(null, 'undefined'));

        // Test with special characters
        $this->assertFalse($method->invoke(null, 'uuid-with-<script>'));
        $this->assertFalse($method->invoke(null, '../../../etc/passwd'));

        // Test valid but lowercase
        $this->assertTrue($method->invoke(null, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'));
    }

    // =============================================
    // Tests for get_client_ip - extended
    // =============================================

    /**
     * Test get_client_ip rejects CF-Connecting-IP from an untrusted source
     * (H-1 fix, TODO-SECURITY-01). Was previously a "prioritise" test which
     * encoded the very vulnerability we patched.
     */
    public function testGetClientIpPrioritizesCFConnectingIP(): void
    {
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);

        // Backup
        $backupCf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50'; // public, untrusted
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '104.16.132.229';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';

        $ip = AuthController::get_client_ip();

        // Restore
        if ($backupCf !== null) {
            $_SERVER['HTTP_CF_CONNECTING_IP'] = $backupCf;
        } else {
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        // H-1 fix: untrusted source -> headers ignored, REMOTE_ADDR is used
        $this->assertEquals('203.0.113.50', $ip);
    }

    /**
     * Test get_client_ip handles empty IP gracefully
     */
    public function testGetClientIpHandlesEmptyGracefully(): void
    {
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);

        // Clear all IP-related headers
        $backup = [];
        $headers = [
            'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            $backup[$header] = $_SERVER[$header] ?? null;
            unset($_SERVER[$header]);
        }

        $ip = AuthController::get_client_ip();

        // Restore
        foreach ($headers as $header) {
            if ($backup[$header] !== null) {
                $_SERVER[$header] = $backup[$header];
            }
        }

        $this->assertEquals('0.0.0.0', $ip);
    }

    // =============================================
    // Tests for getDeviceIDFromUUID
    // =============================================

    /**
     * Test getDeviceIDFromUUID caches result
     */
    public function testGetDeviceIDFromUUIDCachesResult(): void
    {
        global $conf, $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true, [['rowid' => 99]], 1);
        $db = $mockDb;

        $uuid = 'cache-test-uuid-' . time();

        // First call - should query DB
        $result1 = AuthController::getDeviceIDFromUUID($uuid);

        // Second call - should use cache
        $result2 = AuthController::getDeviceIDFromUUID($uuid);

        // Both should return same value
        $this->assertEquals($result1, $result2);

        // Verify cache was set
        $this->assertArrayHasKey('device-' . $uuid, $conf->cache['smartmakers']);
    }

    /**
     * Test getDeviceIDFromUUID returns -1 for non-existent device
     */
    public function testGetDeviceIDFromUUIDReturnsNegativeForNonExistent(): void
    {
        global $conf, $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true, [], 0); // Empty result
        $db = $mockDb;

        $uuid = 'non-existent-uuid-' . time();

        // Clear cache for this UUID
        unset($conf->cache['smartmakers']['device-' . $uuid]);

        $result = AuthController::getDeviceIDFromUUID($uuid);

        $this->assertEquals(-1, $result);
    }

    // =============================================
    // Tests for getDeviceName
    // =============================================

    /**
     * Test getDeviceName returns empty string when no parameters
     */
    public function testGetDeviceNameReturnsEmptyWithNoParams(): void
    {
        $result = AuthController::getDeviceName();

        $this->assertEquals('', $result);
    }

    /**
     * Test getDeviceName queries by ID
     */
    public function testGetDeviceNameQueriesByID(): void
    {
        global $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true, [['label' => 'My iPhone']], 1);
        $db = $mockDb;

        $result = AuthController::getDeviceName(42);

        $queries = $mockDb->getQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('rowid', $queries[0]);
        $this->assertStringContainsString('42', $queries[0]);
    }

    /**
     * Test getDeviceName queries by UUID
     */
    public function testGetDeviceNameQueriesByUUID(): void
    {
        global $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true, [['label' => 'My Android']], 1);
        $db = $mockDb;

        $uuid = 'test-device-uuid-12345';
        $result = AuthController::getDeviceName(null, $uuid);

        $queries = $mockDb->getQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('uuid', $queries[0]);
        $this->assertStringContainsString($uuid, $queries[0]);
    }

    /**
     * Test getDeviceName returns empty string for non-existent device
     */
    public function testGetDeviceNameReturnsEmptyForNonExistent(): void
    {
        global $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true, [], 0); // Empty result
        $db = $mockDb;

        $result = AuthController::getDeviceName(99999);

        $this->assertEquals('', $result);
    }

    // =============================================
    // Tests for index method
    // =============================================

    /**
     * Test index returns proper structure
     */
    public function testIndexReturnsProperStructure(): void
    {
        $result = $this->controller->index();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // First element is data, second is HTTP status
        $this->assertIsArray($result[0]);
        $this->assertEquals(200, $result[1]);

        // Should have 'entities' key
        $this->assertArrayHasKey('entities', $result[0]);
        $this->assertIsArray($result[0]['entities']);
    }

    /**
     * Test index with null parameter works
     */
    public function testIndexWithNullParameterWorks(): void
    {
        $result = $this->controller->index(null);

        $this->assertEquals(200, $result[1]);
    }

    // =============================================
    // Tests for logout method
    // =============================================

    /**
     * Test logout with family_id revokes family
     */
    public function testLogoutWithFamilyIdRevokesFamily(): void
    {
        global $db;

        $mockDb = new MockDatabase();
        $mockDb->setQueryResult(true); // For UPDATE queries
        $db = $mockDb;

        $mockUser = new class {
            public int $id = 123;
            public function call_trigger($trigger, $user) { return 1; }
        };

        $result = $this->controller->logout([
            'user' => $mockUser,
            'family_id' => 'test-family-456'
        ]);

        $this->assertEquals(200, $result[1]);

        // Verify UPDATE was called for token family
        $queries = $mockDb->getQueries();
        $hasRevokeQuery = false;
        foreach ($queries as $query) {
            if (strpos($query, 'smartauth_token_family') !== false && strpos($query, 'revoked') !== false) {
                $hasRevokeQuery = true;
                break;
            }
        }
        $this->assertTrue($hasRevokeQuery, 'Should have revoked token family');
    }

    /**
     * Test logout triggers USER_LOGOUT event
     */
    public function testLogoutTriggersUserLogoutEvent(): void
    {
        $triggerCalled = false;
        $triggerName = '';

        $mockUser = new class($triggerCalled, $triggerName) {
            public int $id = 1;
            private bool $called;
            private string $name;

            public function __construct(bool &$called, string &$name)
            {
                $this->called = &$called;
                $this->name = &$name;
            }

            public function call_trigger($trigger, $user)
            {
                $this->called = true;
                $this->name = $trigger;
                return 1;
            }
        };

        $this->controller->logout(['user' => $mockUser]);

        $this->assertTrue($triggerCalled);
        $this->assertEquals('USER_LOGOUT', $triggerName);
    }

    // =============================================
    // Tests for refresh method
    // =============================================

    /**
     * Test refresh with malformed token returns 401
     */
    public function testRefreshWithMalformedTokenReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer |only-pipe-no-id';

        try {
            $result = $this->controller->refresh();
            unset($_SERVER['HTTP_AUTHORIZATION']);
            $this->assertEquals(401, $result[1]);
        } catch (\SmartAuth\Tests\Mocks\JsonReplyException $e) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
            // Expected - json_reply throws exception in test environment
            $this->assertEquals(401, $e->getHttpCode());
        }
    }

    /**
     * Test refresh with non-numeric token ID returns error
     */
    public function testRefreshWithNonNumericTokenIdFails(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc|somejwt';

        // This should throw JsonReplyException or return error
        try {
            $result = $this->controller->refresh();
            // If we get here, check for error response
            $this->assertEquals(401, $result[1]);
        } catch (\SmartAuth\Tests\Mocks\JsonReplyException $e) {
            $this->assertEquals(401, $e->getHttpCode());
        }

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    // =============================================
    // Tests for Constants
    // =============================================

    /**
     * Test AuthController constants are defined correctly
     */
    public function testConstantsAreDefined(): void
    {
        $this->assertEquals(10, AuthController::SMARTAUTH_RATELIMIT_IP_MAX);
        $this->assertEquals(300, AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW);
        $this->assertEquals(5, AuthController::SMARTAUTH_RATELIMIT_USER_MAX);
        $this->assertEquals(900, AuthController::SMARTAUTH_RATELIMIT_USER_WINDOW);

        $this->assertEquals(0, AuthController::STATUS_DRAFT);
        $this->assertEquals(1, AuthController::STATUS_VALID);
        $this->assertEquals(9, AuthController::STATUS_LOGOUT);
    }
}
