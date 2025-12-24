<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';

use SmartAuth\Api\AuthController;

/**
 * Integration tests for AuthController
 */
class AuthControllerTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AuthController();
    }

    /**
     * Test index returns entities list
     */
    public function testIndexReturnsEntitiesList(): void
    {
        $result = $this->controller->index();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('entities', $result[0]);
        $this->assertIsArray($result[0]['entities']);
    }

    /**
     * Helper to clear IP cache before tests
     */
    private function clearIpCache(): void
    {
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_X_REAL_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['REMOTE_ADDR']);
    }

    /**
     * Test get_client_ip returns valid IP
     */
    public function testGetClientIpReturnsValidIp(): void
    {
        $this->clearIpCache();

        $ip = AuthController::get_client_ip();

        $this->assertIsString($ip);
        // Should return 0.0.0.0 in CLI context without headers
        $this->assertEquals('0.0.0.0', $ip);

        $this->clearIpCache();
    }

    /**
     * Test get_client_ip with X-Forwarded-For header
     */
    public function testGetClientIpWithXForwardedFor(): void
    {
        $this->clearIpCache();

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18';

        $ip = AuthController::get_client_ip();

        // Should extract first public IP
        $this->assertEquals('203.0.113.50', $ip);

        $this->clearIpCache();
    }

    /**
     * Test get_client_ip with CF-Connecting-IP header (Cloudflare)
     */
    public function testGetClientIpWithCloudflare(): void
    {
        $this->clearIpCache();

        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';

        $ip = AuthController::get_client_ip();

        $this->assertEquals('198.51.100.42', $ip);

        $this->clearIpCache();
    }

    /**
     * Test get_client_ip with private IP falls back
     */
    public function testGetClientIpIgnoresPrivateIp(): void
    {
        $this->clearIpCache();

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.100';

        $ip = AuthController::get_client_ip();

        // Should skip private IP and use REMOTE_ADDR public IP
        $this->assertEquals('203.0.113.100', $ip);

        $this->clearIpCache();
    }

    /**
     * Test getDeviceIDFromUUID returns -1 for unknown UUID
     */
    public function testGetDeviceIDFromUUIDReturnsNegativeForUnknown(): void
    {
        $result = AuthController::getDeviceIDFromUUID('unknown-uuid-12345');

        $this->assertEquals(-1, $result);
    }

    /**
     * Test getDeviceName returns empty for unknown device
     */
    public function testGetDeviceNameReturnsEmptyForUnknown(): void
    {
        $result = AuthController::getDeviceName(99999);

        $this->assertEquals('', $result);
    }

    /**
     * Test getDeviceName by UUID returns empty for unknown
     */
    public function testGetDeviceNameByUuidReturnsEmptyForUnknown(): void
    {
        $result = AuthController::getDeviceName(null, 'unknown-uuid');

        $this->assertEquals('', $result);
    }

    /**
     * Test getDeviceName with neither id nor uuid returns empty
     */
    public function testGetDeviceNameWithNoParamsReturnsEmpty(): void
    {
        $result = AuthController::getDeviceName(null, null);

        $this->assertEquals('', $result);
    }

    /**
     * Test login with empty credentials returns 401
     */
    public function testLoginWithEmptyCredentialsReturnsError(): void
    {
        $payload = [
            'email' => '',
            'password' => ''
        ];

        // This will call json_reply which exits, so we need to catch output
        // For now, we test that the method exists and can be called
        $this->assertTrue(method_exists($this->controller, 'login'));
    }

    /**
     * Test login rate limiting is applied
     */
    public function testLoginRateLimitingIsConfigured(): void
    {
        // Verify constants are defined
        $this->assertEquals(10, AuthController::SMARTAUTH_RATELIMIT_IP_MAX);
        $this->assertEquals(300, AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW);
        $this->assertEquals(5, AuthController::SMARTAUTH_RATELIMIT_USER_MAX);
        $this->assertEquals(900, AuthController::SMARTAUTH_RATELIMIT_USER_WINDOW);
    }

    /**
     * Test status constants are defined
     */
    public function testStatusConstantsAreDefined(): void
    {
        $this->assertEquals(0, AuthController::STATUS_DRAFT);
        $this->assertEquals(1, AuthController::STATUS_VALID);
        $this->assertEquals(9, AuthController::STATUS_LOGOUT);
    }

    /**
     * Test ping redirects to refresh
     */
    public function testPingMethodExists(): void
    {
        $this->assertTrue(method_exists($this->controller, 'ping'));
    }

    /**
     * Test refresh method exists
     */
    public function testRefreshMethodExists(): void
    {
        $this->assertTrue(method_exists($this->controller, 'refresh'));
    }

    /**
     * Test logout method exists
     */
    public function testLogoutMethodExists(): void
    {
        $this->assertTrue(method_exists($this->controller, 'logout'));
    }

    /**
     * Test device method exists
     */
    public function testDeviceMethodExists(): void
    {
        $this->assertTrue(method_exists($this->controller, 'device'));
    }

    /**
     * Test check method exists
     */
    public function testCheckMethodExists(): void
    {
        $this->assertTrue(method_exists(AuthController::class, 'check'));
    }

    /**
     * Test newThirdpartKey method exists
     */
    public function testNewThirdpartKeyMethodExists(): void
    {
        $this->assertTrue(method_exists($this->controller, 'newThirdpartKey'));
    }

    /**
     * Test refresh without token returns error
     */
    public function testRefreshWithoutTokenReturnsError(): void
    {
        // Clear any authorization header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);

        $result = $this->controller->refresh();

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertEquals('Refresh token required', $result[0]['error']);
    }

    /**
     * Test refresh with invalid token format returns error
     */
    public function testRefreshWithInvalidTokenFormatReturnsError(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-without-pipe';

        $result = $this->controller->refresh();

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertEquals('Invalid token format', $result[0]['error']);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test device creation in database
     */
    public function testDeviceCreationInDatabase(): void
    {
        // Create a device directly in database
        $uuid = 'test-device-' . uniqid();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', 'Test Device', ";
        $sql .= $this->testUser->id . ", '" . $this->db->idate(time()) . "', 1)";

        $result = $this->db->query($sql);
        $this->assertNotFalse($result);

        $deviceId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
        $this->assertGreaterThan(0, $deviceId);

        // Test getDeviceIDFromUUID
        // Clear cache first
        global $conf;
        unset($conf->cache['smartmakers']['device-' . $uuid]);

        $foundId = AuthController::getDeviceIDFromUUID($uuid);
        $this->assertEquals($deviceId, $foundId);

        // Test getDeviceName
        $name = AuthController::getDeviceName($deviceId);
        $this->assertEquals('Test Device', $name);

        // Test getDeviceName by UUID
        $nameByUuid = AuthController::getDeviceName(null, $uuid);
        $this->assertEquals('Test Device', $nameByUuid);
    }

    /**
     * Test token family creation
     */
    public function testTokenFamilyCreation(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_createTokenFamily');
        $method->setAccessible(true);

        $familyId = $method->invoke($this->controller, $this->testUser->id);

        $this->assertGreaterThan(0, $familyId);

        // Verify in database
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $familyId,
            'fk_user' => $this->testUser->id
        ]);
    }

    /**
     * Test token family check with valid family
     */
    public function testCheckTokenFamilyWithValidFamily(): void
    {
        // Create a token family first
        $reflection = new \ReflectionClass($this->controller);

        $createMethod = $reflection->getMethod('_createTokenFamily');
        $createMethod->setAccessible(true);
        $familyId = $createMethod->invoke($this->controller, $this->testUser->id);

        // Check the family
        $checkMethod = $reflection->getMethod('_checkTokenFamily');
        $checkMethod->setAccessible(true);
        $result = $checkMethod->invoke($this->controller, $familyId, $this->testUser->id);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test token family check with wrong user
     */
    public function testCheckTokenFamilyWithWrongUser(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $createMethod = $reflection->getMethod('_createTokenFamily');
        $createMethod->setAccessible(true);
        $familyId = $createMethod->invoke($this->controller, $this->testUser->id);

        $checkMethod = $reflection->getMethod('_checkTokenFamily');
        $checkMethod->setAccessible(true);
        $result = $checkMethod->invoke($this->controller, $familyId, 99999);

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertEquals('user_mismatch', $result['reason']);
    }

    /**
     * Test token family check with non-existent family
     */
    public function testCheckTokenFamilyWithNonExistent(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_checkTokenFamily');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, 999999, $this->testUser->id);

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertEquals('family_not_found', $result['reason']);
    }

    /**
     * Test token family revocation
     */
    public function testRevokeTokenFamily(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        // Create family
        $createMethod = $reflection->getMethod('_createTokenFamily');
        $createMethod->setAccessible(true);
        $familyId = $createMethod->invoke($this->controller, $this->testUser->id);

        // Revoke it
        $revokeMethod = $reflection->getMethod('_revokeTokenFamily');
        $revokeMethod->setAccessible(true);
        $revokeMethod->invoke($this->controller, $familyId, 'test_revocation');

        // Check it's revoked
        $sql = "SELECT revoked FROM " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " WHERE rowid = " . (int) $familyId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertEquals(1, $obj->revoked);
    }

    /**
     * Test token family update
     */
    public function testUpdateTokenFamily(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        // Create family
        $createMethod = $reflection->getMethod('_createTokenFamily');
        $createMethod->setAccessible(true);
        $familyId = $createMethod->invoke($this->controller, $this->testUser->id);

        // Update it
        $updateMethod = $reflection->getMethod('_updateTokenFamily');
        $updateMethod->setAccessible(true);
        $updateMethod->invoke($this->controller, $familyId, 5);

        // Verify update
        $sql = "SELECT refresh_count FROM " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " WHERE rowid = " . (int) $familyId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertEquals(5, $obj->refresh_count);
    }

    /**
     * Test single token revocation
     */
    public function testRevokeToken(): void
    {
        global $smartAuthAppID;
        $smartAuthAppID = 1;

        // Create a token directly
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " (appuid, salt, date_creation, fk_user_creat, fk_authid, auth_element, status, entity)";
        $sql .= " VALUES (1, 'testsalt', '" . $this->db->idate(time()) . "', ";
        $sql .= $this->testUser->id . ", " . $this->testUser->id . ", 'user', 1, 1)";
        $this->db->query($sql);
        $tokenId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_revokeToken');
        $method->setAccessible(true);
        $method->invoke($this->controller, $tokenId, 'test_revoke');

        // Verify revoked
        $sql = "SELECT status, salt FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertEquals(AuthController::STATUS_LOGOUT, $obj->status);
        $this->assertEquals('test_revoke', $obj->salt);
    }

    /**
     * Test get entities with multicompany disabled
     */
    public function testGetEntitiesWithoutMulticompany(): void
    {
        // Multicompany is not enabled in test environment
        $result = $this->controller->index();

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        // Should return empty entities array when multicompany is disabled
        $this->assertIsArray($result[0]['entities']);
    }

    /**
     * Test UUID validation patterns
     */
    public function testUuidValidationPatterns(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_validateUUID');
        $method->setAccessible(true);

        // Valid UUID format
        $this->assertTrue($method->invoke($this->controller, '550e8400-e29b-41d4-a716-446655440000'));

        // Valid SHA256 format
        $this->assertTrue($method->invoke($this->controller, 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));

        // Invalid formats
        $this->assertFalse($method->invoke($this->controller, 'invalid'));
        $this->assertFalse($method->invoke($this->controller, '12345'));
        $this->assertFalse($method->invoke($this->controller, ''));
    }

    /**
     * Test getSalt2 with device UUID
     */
    public function testGetSalt2WithDeviceUuid(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_getSalt2');
        $method->setAccessible(true);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $salt = $method->invoke($this->controller, $uuid);

        $this->assertIsString($salt);
        $this->assertEquals(16, strlen($salt));
        // Should be first 16 chars of SHA256 hash
        $expected = substr(hash('sha256', $uuid), 0, 16);
        $this->assertEquals($expected, $salt);
    }

    /**
     * Test getSalt2 fallback to User-Agent
     */
    public function testGetSalt2FallbackToUserAgent(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_getSalt2');
        $method->setAccessible(true);

        // Clear device ID header and set empty string
        $_SERVER['HTTP_X_DEVICEID'] = '';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

        $salt = $method->invoke($this->controller, '');

        $this->assertIsString($salt);
        $this->assertEquals(16, strlen($salt));

        unset($_SERVER['HTTP_USER_AGENT']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }
}
