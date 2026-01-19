<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\AuthController;
use SmartAuth\Tests\Mocks\MockDatabase;
use SmartAuth\Tests\Mocks\JsonReplyException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for AuthController
 */
class AuthControllerTest extends TestCase
{
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->controller = new AuthController();

        // Mock mysoc for index() method
        global $mysoc;
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
     * Test _validateUUID accepts valid UUID format
     */
    public function testValidateUUIDAcceptsValidUUID(): void
    {
        $method = $this->getPrivateMethod('_validateUUID');

        // Standard UUID v4 format
        $this->assertTrue($method->invoke(null, '550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue($method->invoke(null, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'));
    }

    /**
     * Test _validateUUID accepts valid SHA256 hash format
     */
    public function testValidateUUIDAcceptsValidSHA256(): void
    {
        $method = $this->getPrivateMethod('_validateUUID');

        // SHA256 hash (64 hex characters)
        $hash = hash('sha256', 'test-device-id');
        $this->assertTrue($method->invoke(null, $hash));
        $this->assertTrue($method->invoke(null, 'a1b2c3d4e5f67890abcdef1234567890a1b2c3d4e5f67890abcdef1234567890'));
    }

    /**
     * Test _validateUUID rejects invalid formats
     */
    public function testValidateUUIDRejectsInvalidFormats(): void
    {
        $method = $this->getPrivateMethod('_validateUUID');

        // Too short
        $this->assertFalse($method->invoke(null, 'abc123'));
        // Invalid characters
        $this->assertFalse($method->invoke(null, 'gggggggg-gggg-gggg-gggg-gggggggggggg'));
        // Wrong format
        $this->assertFalse($method->invoke(null, '550e8400e29b41d4a716446655440000')); // No dashes (32 chars, not 64)
        // Empty
        $this->assertFalse($method->invoke(null, ''));
        // SQL injection attempt
        $this->assertFalse($method->invoke(null, "'; DROP TABLE users; --"));
    }

    /**
     * Test _validateUUID is case insensitive
     */
    public function testValidateUUIDIsCaseInsensitive(): void
    {
        $method = $this->getPrivateMethod('_validateUUID');

        $this->assertTrue($method->invoke(null, '550E8400-E29B-41D4-A716-446655440000'));
        $this->assertTrue($method->invoke(null, 'A1B2C3D4E5F67890ABCDEF1234567890A1B2C3D4E5F67890ABCDEF1234567890'));
    }

    /**
     * Test get_client_ip returns valid IP from REMOTE_ADDR
     */
    public function testGetClientIpFromRemoteAddr(): void
    {
        // Ensure $conf is initialized
        global $conf;
        if (!is_object($conf)) {
            $conf = new \stdClass();
            $conf->cache = [];
            $conf->cache['smartmakers'] = [];
        }

        // Backup and set server variable
        $backup = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.195';

        // Clear cache
        unset($conf->cache['smartmakers']['clientIP']);

        $ip = AuthController::get_client_ip();

        // Restore
        if ($backup !== null) {
            $_SERVER['REMOTE_ADDR'] = $backup;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        $this->assertEquals('203.0.113.195', $ip);
    }

    /**
     * Test get_client_ip returns cached value
     */
    public function testGetClientIpReturnsCachedValue(): void
    {
        global $conf;
        if (!is_object($conf)) {
            $conf = new \stdClass();
            $conf->cache = [];
            $conf->cache['smartmakers'] = [];
        }
        $conf->cache['smartmakers']['clientIP'] = '10.20.30.40';

        $ip = AuthController::get_client_ip();

        $this->assertEquals('10.20.30.40', $ip);

        // Cleanup
        unset($conf->cache['smartmakers']['clientIP']);
    }

    /**
     * Test get_client_ip handles X-Forwarded-For header
     */
    public function testGetClientIpFromXForwardedFor(): void
    {
        global $conf;
        if (!is_object($conf)) {
            $conf = new \stdClass();
            $conf->cache = [];
            $conf->cache['smartmakers'] = [];
        }

        // Backup
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;

        // Clear cache
        unset($conf->cache['smartmakers']['clientIP']);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195, 70.41.3.18, 150.172.238.178';
        unset($_SERVER['REMOTE_ADDR']);

        $ip = AuthController::get_client_ip();

        // Restore
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        }

        $this->assertEquals('203.0.113.195', $ip);
    }

    /**
     * Test get_client_ip ignores private IP ranges in X-Forwarded-For
     */
    public function testGetClientIpIgnoresPrivateRanges(): void
    {
        global $conf;
        if (!is_object($conf)) {
            $conf = new \stdClass();
            $conf->cache = [];
            $conf->cache['smartmakers'] = [];
        }

        // Clear cache
        unset($conf->cache['smartmakers']['clientIP']);

        // Backup
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;

        // Private IP followed by public
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = AuthController::get_client_ip();

        // Restore
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        }

        // Should return fallback since no valid public IP
        $this->assertEquals('0.0.0.0', $ip);
    }

    /**
     * Test index method returns entities array
     */
    public function testIndexReturnsEntitiesArray(): void
    {
        $result = $this->controller->index();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('entities', $result[0]);
    }

    /**
     * Test ping redirects to refresh
     */
    public function testPingCallsRefresh(): void
    {
        // ping() should call refresh(), which needs a token
        // Without a token, it should return an error (401)
        $result = $this->controller->ping();

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    /**
     * Test refresh without token returns 401
     */
    public function testRefreshWithoutTokenReturns401(): void
    {
        // Clear Authorization header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);

        $result = $this->controller->refresh();

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('Refresh token required', $result[0]['error']);
    }

    /**
     * Test refresh with invalid token format returns 401
     */
    public function testRefreshWithInvalidTokenFormatReturns401(): void
    {
        // Set invalid token (no pipe separator)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalidtokenwithoutpipe';

        $result = $this->controller->refresh();

        // Restore
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    /**
     * Test getDeviceIDFromUUID returns cached value
     */
    public function testGetDeviceIDFromUUIDReturnsCachedValue(): void
    {
        global $conf;
        if (!is_object($conf)) {
            $conf = new \stdClass();
            $conf->cache = [];
            $conf->cache['smartmakers'] = [];
        }
        $uuid = 'test-uuid-12345';
        $conf->cache['smartmakers']['device-' . $uuid] = 42;

        $result = AuthController::getDeviceIDFromUUID($uuid);

        $this->assertEquals(42, $result);

        // Cleanup
        unset($conf->cache['smartmakers']['device-' . $uuid]);
    }

    /**
     * Test logout returns empty user and token
     */
    public function testLogoutReturnsEmptyCredentials(): void
    {
        // Create a mock user object
        $mockUser = new class {
            public int $id = 1;
            public function call_trigger($trigger, $user)
            {
                return 1;
            }
        };

        $result = $this->controller->logout(['user' => $mockUser]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertEquals('', $result[0]['user']);
        $this->assertEquals('', $result[0]['token']);
    }
}
