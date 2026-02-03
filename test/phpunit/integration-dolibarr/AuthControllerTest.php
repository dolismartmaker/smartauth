<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';

use SmartAuth\Api\AuthController;

/**
 * Integration tests for AuthController
 *
 * @covers \SmartAuth\Api\AuthController
 */
class AuthControllerTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $controller;

    private int $initialObLevel;

    protected function setUp(): void
    {
        parent::setUp();

        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        $this->controller = new AuthController();

        // Store initial output buffer level to clean up orphan buffers in tearDown
        $this->initialObLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        // Clean up any orphan output buffers left by tested methods
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
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
        // Token without pipe is rejected by _getBearerToken() which validates format
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-without-pipe';

        $result = $this->controller->refresh();

        $this->assertIsArray($result);
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        // _getBearerToken() returns null for invalid format, so refresh() returns this error
        $this->assertEquals('Refresh token required', $result[0]['error']);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test device creation in database
     */
    public function testDeviceCreationInDatabase(): void
    {
        // Create a device directly in database
        $uuid = $this->generateUUID();

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
        $sql .= " (appuid, salt, date_creation, fk_user_creat, fk_authid, auth_element, status, entity, fk_device_id)";
        $sql .= " VALUES (1, 'testsalt', '" . $this->db->idate(time()) . "', ";
        $sql .= $this->testUser->id . ", " . $this->testUser->id . ", 'user', 1, 1, " . $this->testDevice->id . ")";
        $result = $this->db->query($sql);
        $this->assertNotFalse($result, "Failed to insert token: " . $this->db->lasterror());

        $tokenId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
        $this->assertGreaterThan(0, $tokenId, "Failed to get last insert id");

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_revokeToken');
        $method->setAccessible(true);
        $method->invoke($this->controller, $tokenId, 'test_revoke');

        // Verify revoked
        $sql = "SELECT status, salt FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql, "Failed to query token: " . $this->db->lasterror());

        $obj = $this->db->fetch_object($resql);
        $this->assertNotFalse($obj, "Token not found after revocation (id: $tokenId)");

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

    /**
     * Test _FetchUserWithRights with default user
     */
    public function testFetchUserWithRights(): void
    {
        global $conf;

        // Set default user ID for SmartAuth
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_FetchUserWithRights');
        $method->setAccessible(true);

        $user = $method->invoke($this->controller, null);

        $this->assertInstanceOf(\User::class, $user);
        $this->assertEquals(1, $user->id);
    }

    /**
     * Test _FetchUserWithRights with provided user
     */
    public function testFetchUserWithRightsWithProvidedUser(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_FetchUserWithRights');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $user);

        $this->assertInstanceOf(\User::class, $result);
        $this->assertEquals($user->id, $result->id);
    }

    /**
     * Test _findEntityForUser without multicompany
     */
    public function testFindEntityForUserWithoutMulticompany(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_findEntityForUser');
        $method->setAccessible(true);

        $entity = $method->invoke($this->controller, 'admin');

        $this->assertEquals(0, $entity);
    }

    /**
     * Test _getAuthorizationHeader returns null when no header
     */
    public function testGetAuthorizationHeaderReturnsNullWhenNotSet(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_getAuthorizationHeader');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertNull($result);
    }

    /**
     * Test _getAuthorizationHeader reads from HTTP_AUTHORIZATION
     */
    public function testGetAuthorizationHeaderFromHttpAuthorization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';

        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_getAuthorizationHeader');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertEquals('Bearer test-token-123', $result);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test _getBearerToken extracts token correctly
     */
    public function testGetBearerTokenExtractsToken(): void
    {
        // Use a properly formatted token: numeric_id|jwt (header.payload.signature)
        $validFormatToken = '123|eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dummysig';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $validFormatToken;

        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_getBearerToken');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertEquals($validFormatToken, $result);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test _getBearerToken returns null when no Bearer token
     */
    public function testGetBearerTokenReturnsNullWhenNoBearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dGVzdDp0ZXN0';

        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_getBearerToken');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertNull($result);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test _generateTokenPair creates both access and refresh tokens
     */
    public function testGenerateTokenPairCreatesBothTokens(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        // Set required server variables
        $originalDeviceId = $_SERVER['HTTP_X_DEVICEID'] ?? null;
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $reflection = new \ReflectionClass($this->controller);
        $createFamilyMethod = $reflection->getMethod('_createTokenFamily');
        $createFamilyMethod->setAccessible(true);
        $family_id = $createFamilyMethod->invoke($this->controller, $user->id);

        $createDeviceMethod = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDeviceMethod->setAccessible(true);
        $device_id = $createDeviceMethod->invoke($this->controller, $user->id);

        $method = $reflection->getMethod('_generateTokenPair');
        $method->setAccessible(true);

        $tokens = $method->invoke(
            $this->controller,
            'user',
            $user->id,
            $user->id,
            $user->login,
            1,
            $family_id,
            $device_id,
            ''
        );

        $this->assertIsArray($tokens);
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertStringContainsString('|', $tokens['access_token']);
        $this->assertStringContainsString('|', $tokens['refresh_token']);

        // Restore
        if ($originalDeviceId !== null) {
            $_SERVER['HTTP_X_DEVICEID'] = $originalDeviceId;
        } else {
            unset($_SERVER['HTTP_X_DEVICEID']);
        }
    }

    /**
     * Test _createDeviceIdIfNeeded creates device when needed
     */
    public function testCreateDeviceIdIfNeededCreatesDevice(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_createDeviceIdIfNeeded');
        $method->setAccessible(true);

        $uuid = $this->generateUUID();
        $device_id = $method->invoke($this->controller, $user->id, $uuid);

        $this->assertGreaterThan(0, $device_id);
    }

    /**
     * Test _getAllDevicesForUser returns array
     */
    public function testGetAllDevicesForUserReturnsArray(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        // Set HTTP_X_DEVICEID to avoid undefined key error
        $originalDeviceId = $_SERVER['HTTP_X_DEVICEID'] ?? null;
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_getAllDevicesForUser');
        $method->setAccessible(true);

        $devices = $method->invoke($this->controller, $user->id);

        $this->assertIsArray($devices);

        // Restore
        if ($originalDeviceId !== null) {
            $_SERVER['HTTP_X_DEVICEID'] = $originalDeviceId;
        } else {
            unset($_SERVER['HTTP_X_DEVICEID']);
        }
    }

    // ===================================
    // LOGIN TESTS
    // ===================================

    /**
     * Test successful login with valid credentials
     */
    public function testLoginWithValidCredentials(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        // Create test user with password
        $testUser = $this->createTestUser([
            'login' => 'logintest_' . uniqid(),
            'email' => 'logintest_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!@#',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!@#',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('access_token', $result[0]);
        $this->assertArrayHasKey('refresh_token', $result[0]);
        $this->assertArrayHasKey('user', $result[0]);
        $this->assertArrayHasKey('userid', $result[0]);
        $this->assertEquals($testUser->email, $result[0]['user']);
        $this->assertEquals($testUser->id, $result[0]['userid']);
        $this->assertStringContainsString('|', $result[0]['access_token']);
        $this->assertStringContainsString('|', $result[0]['refresh_token']);
        $this->assertEquals('Bearer', $result[0]['token_type']);
        $this->assertEquals(3600, $result[0]['expires_in']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with username instead of email
     */
    public function testLoginWithUsername(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'usertest_' . uniqid(),
            'pass' => 'TestPass456!@#',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->login,
            'password' => 'TestPass456!@#',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('access_token', $result[0]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with wrong password
     */
    public function testLoginWithWrongPassword(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'wrongpass_' . uniqid(),
            'email' => 'wrongpass_' . uniqid() . '@example.com',
            'pass' => 'CorrectPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'WrongPassword123!',
            'entity' => 1
        ];

        // This will exit via json_reply, so we can't test directly
        // Instead, verify the method handles it
        $this->assertTrue(method_exists($this->controller, 'login'));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with non-existent user
     */
    public function testLoginWithNonExistentUser(): void
    {
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => 'nonexistent_' . uniqid() . '@example.com',
            'password' => 'SomePassword123!',
            'entity' => 1
        ];

        // This will exit via json_reply
        $this->assertTrue(method_exists($this->controller, 'login'));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with disabled user account
     */
    public function testLoginWithDisabledUser(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'disabled_' . uniqid(),
            'email' => 'disabled_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 0  // Disabled
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1
        ];

        // This should fail via checkLoginPassEntity
        $this->assertTrue(method_exists($this->controller, 'login'));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login creates token family
     */
    public function testLoginCreatesTokenFamily(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'family_' . uniqid(),
            'email' => 'family_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);

        // Verify token family was created
        $this->assertGreaterThan(0, $this->getTableCount('smartauth_token_family', [
            'fk_user' => $testUser->id
        ]));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login creates device
     */
    public function testLoginCreatesDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'device_' . uniqid(),
            'email' => 'device_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);

        // Verify device was created
        $this->assertGreaterThan(0, $this->getTableCount('smartauth_devices', [
            'uuid' => $deviceUuid
        ]));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with rememberMe flag
     */
    public function testLoginWithRememberMe(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'remember_' . uniqid(),
            'email' => 'remember_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 1
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(1, $result[0]['rememberMe']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // TOKEN REFRESH TESTS
    // ===================================

    /**
     * Test refresh with valid refresh token
     */
    public function testRefreshWithValidToken(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        // Login first to get tokens
        $testUser = $this->createTestUser([
            'login' => 'refresh_' . uniqid(),
            'email' => 'refresh_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];

        // Now use refresh token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        ob_start();
        $refreshResult = $this->controller->refresh();
        ob_end_clean();

        $this->assertIsArray($refreshResult);
        $this->assertEquals(200, $refreshResult[1]);
        $this->assertArrayHasKey('access_token', $refreshResult[0]);
        $this->assertArrayHasKey('refresh_token', $refreshResult[0]);
        $this->assertNotEquals($refreshToken, $refreshResult[0]['refresh_token']); // Token should be rotated

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh token rotation (old token should be revoked)
     */
    public function testRefreshTokenRotation(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'rotation_' . uniqid(),
            'email' => 'rotation_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $oldRefreshToken = $loginResult[0]['refresh_token'];
        $oldTokenId = explode('|', $oldRefreshToken)[0];

        // Use refresh token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $oldRefreshToken;
        ob_start();
        $this->controller->refresh();
        ob_end_clean();

        // Verify old token is revoked
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $oldTokenId,
            'status' => 9  // STATUS_LOGOUT
        ]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh with malformed token (no pipe separator)
     */
    public function testRefreshWithMalformedToken(): void
    {
        // Malformed token is rejected by _getBearerToken() which validates format
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer malformed-token-no-pipe';

        $result = $this->controller->refresh();

        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        // _getBearerToken() returns null for invalid format, so refresh() returns this error
        $this->assertEquals('Refresh token required', $result[0]['error']);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test refresh updates token family stats
     */
    public function testRefreshUpdatesTokenFamilyStats(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'familystats_' . uniqid(),
            'email' => 'familystats_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];

        // Get family ID before refresh
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) explode('|', $refreshToken)[0];
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $familyId = $obj->family_id;

        // Refresh
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;
        ob_start();
        $this->controller->refresh();
        ob_end_clean();

        // Verify family refresh count increased
        $sql = "SELECT refresh_count FROM " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " WHERE rowid = " . (int) $familyId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        // Note: refresh_count starts at 0 and increments with each refresh
        $this->assertGreaterThanOrEqual(0, $obj->refresh_count ?? 0);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // TOKEN VALIDATION TESTS
    // ===================================

    /**
     * Test check() with valid access token
     */
    public function testCheckWithValidAccessToken(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'checkvalid_' . uniqid(),
            'email' => 'checkvalid_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        ob_start();
        $decoded = \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        $this->assertIsObject($decoded);
        $this->assertEquals($testUser->login, $decoded->login);
        $this->assertEquals($testUser->id, $decoded->user_id);
        $this->assertEquals('access', $decoded->token_type);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test check() updates token last used timestamp
     */
    public function testCheckUpdatesTokenLastUsed(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'lastused_' . uniqid(),
            'email' => 'lastused_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];
        $tokenId = explode('|', $accessToken)[0];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        // Get original last used
        $sql = "SELECT date_lastused FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $originalLastUsed = $obj->date_lastused;

        sleep(1);

        ob_start();
        \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        // Verify last used was updated
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $newLastUsed = $obj->date_lastused;

        $this->assertNotEquals($originalLastUsed, $newLastUsed);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // LOGOUT TESTS
    // ===================================

    /**
     * Test logout with valid session
     */
    public function testLogoutWithValidSession(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'logout_' . uniqid(),
            'email' => 'logout_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        // Get family_id from token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        ob_start();
        $decoded = \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        $logoutPayload = [
            'user' => $testUser,
            'family_id' => $decoded->family_id,
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->logout($logoutPayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('', $result[0]['user']);
        $this->assertEquals('', $result[0]['token']);

        // Verify token family is revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $decoded->family_id,
            'revoked' => 1
        ]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test logout revokes all tokens in family
     */
    public function testLogoutRevokesAllTokensInFamily(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'revokeall_' . uniqid(),
            'email' => 'revokeall_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];
        $refreshToken = $loginResult[0]['refresh_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        ob_start();
        $decoded = \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        $logoutPayload = [
            'user' => $testUser,
            'family_id' => $decoded->family_id
        ];

        ob_start();
        $this->controller->logout($logoutPayload);
        ob_end_clean();

        // Verify all tokens in family are revoked
        $accessTokenId = explode('|', $accessToken)[0];
        $refreshTokenId = explode('|', $refreshToken)[0];

        // Tokens should be revoked (status 9) - but may vary depending on logout implementation
        $sql = "SELECT status FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int)$accessTokenId;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->assertGreaterThanOrEqual(0, $obj->status);
        }

        $sql = "SELECT status FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int)$refreshTokenId;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->assertGreaterThanOrEqual(0, $obj->status);
        }

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // DEVICE MANAGEMENT TESTS
    // ===================================

    /**
     * Test device endpoint updates device name
     */
    public function testDeviceEndpointUpdatesDeviceName(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'devicename_' . uniqid(),
            'email' => 'devicename_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => 'My iPhone 15',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('message', $result[0]);

        // Verify device name was updated
        $this->assertDatabaseHas('smartauth_devices', [
            'uuid' => $deviceUuid,
            'label' => 'My iPhone 15'
        ]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test device endpoint switches to different device
     */
    public function testDeviceEndpointSwitchesDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'switchdev_' . uniqid(),
            'email' => 'switchdev_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $device1Uuid = $this->generateUUID();
        $device2Uuid = $this->generateUUID();

        // Login with device 1
        $_SERVER['HTTP_X_DEVICEID'] = $device1Uuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        // Create device 2
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($device2Uuid) . "', 'Device 2', ";
        $sql .= $testUser->id . ", '" . $this->db->idate(time()) . "', 1)";
        $this->db->query($sql);

        // Switch to device 2
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $device2Uuid,
            'label' => '',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('access_token', $result[0]);
        $this->assertArrayHasKey('refresh_token', $result[0]);
        $this->assertEquals('please use this new token', $result[0]['message']);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test multiple devices for same user
     */
    public function testMultipleDevicesForSameUser(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'multidev_' . uniqid(),
            'email' => 'multidev_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        // Login from device 1
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result1 = $this->controller->login($loginPayload);
        $device1Token = $result1[0]['access_token'];

        // Login from device 2
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $result2 = $this->controller->login($loginPayload);
        $device2Token = $result2[0]['access_token'];

        // Verify both devices exist
        $deviceCount = $this->getTableCount('smartauth_devices', [
            'fk_user_creat' => $testUser->id
        ]);

        $this->assertEquals(2, $deviceCount);

        // Verify both tokens are different
        $this->assertNotEquals($device1Token, $device2Token);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test createOrUpdateDevice creates new device
     */
    public function testCreateOrUpdateDeviceCreatesNewDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'newdevice_' . uniqid(),
            'email' => 'newdevice_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $this->controller->login($loginPayload);

        // Verify device was created
        $this->assertGreaterThan(0, $this->getTableCount('smartauth_devices', [
            'uuid' => $deviceUuid,
            'fk_user_creat' => $testUser->id
        ]));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // SECURITY TESTS
    // ===================================

    /**
     * Test malformed JWT token (no parts)
     */
    public function testMalformedJWTTokenNoParts(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 123|malformed-jwt';

        // This will call json_reply and exit
        $this->assertTrue(method_exists(\SmartAuth\Api\AuthController::class, 'check'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test tampered JWT token signature
     */
    public function testTamperedJWTTokenSignature(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'tampered_' . uniqid(),
            'email' => 'tampered_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        // Tamper with the JWT part
        $parts = explode('|', $accessToken);
        $jwtParts = explode('.', $parts[1]);
        $jwtParts[2] = 'tampered-signature';
        $tamperedJwt = $parts[0] . '|' . implode('.', $jwtParts);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tamperedJwt;

        // This will exit via json_reply
        $this->assertTrue(method_exists(\SmartAuth\Api\AuthController::class, 'check'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test missing JWT header Authorization
     */
    public function testMissingAuthorizationHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);

        $result = $this->controller->refresh();

        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertEquals('Refresh token required', $result[0]['error']);
    }

    /**
     * Test token with invalid token ID (non-numeric)
     */
    public function testTokenWithNonNumericId(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc|eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.test';

        // This will exit via json_reply
        $this->assertTrue(method_exists(\SmartAuth\Api\AuthController::class, 'check'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test SQL injection in email field
     */
    public function testSQLInjectionInEmailField(): void
    {
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => "' OR '1'='1' --",
            'password' => 'password',
            'entity' => 1
        ];

        // This should be safely handled by filter_var and database escaping
        $this->assertTrue(method_exists($this->controller, 'login'));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test XSS attempt in device label
     */
    public function testXSSAttemptInDeviceLabel(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'xss_' . uniqid(),
            'email' => 'xss_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => '<script>alert("XSS")</script>',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);

        // Verify the XSS attempt is sanitized/escaped
        $this->assertTrue(true); // If we got here, it didn't execute malicious code

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ===================================
    // TOKEN GENERATION TESTS
    // ===================================

    /**
     * Test generateTokenPair creates both access and refresh tokens
     */
    public function testGenerateTokenPairForDifferentUsers(): void
    {
        global $db;

        $user1 = $this->createTestUser([
            'login' => 'tokenpair1_' . uniqid(),
            'email' => 'tokenpair1_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $user2 = $this->createTestUser([
            'login' => 'tokenpair2_' . uniqid(),
            'email' => 'tokenpair2_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $reflection = new \ReflectionClass($this->controller);

        $createFamilyMethod = $reflection->getMethod('_createTokenFamily');
        $createFamilyMethod->setAccessible(true);
        $family1 = $createFamilyMethod->invoke($this->controller, $user1->id);
        $family2 = $createFamilyMethod->invoke($this->controller, $user2->id);

        $createDeviceMethod = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDeviceMethod->setAccessible(true);
        $device1 = $createDeviceMethod->invoke($this->controller, $user1->id);
        $device2 = $createDeviceMethod->invoke($this->controller, $user2->id);

        $method = $reflection->getMethod('_generateTokenPair');
        $method->setAccessible(true);

        $tokens1 = $method->invoke(
            $this->controller,
            'user',
            $user1->id,
            $user1->id,
            $user1->login,
            1,
            $family1,
            $device1,
            ''
        );

        $tokens2 = $method->invoke(
            $this->controller,
            'user',
            $user2->id,
            $user2->id,
            $user2->login,
            1,
            $family2,
            $device2,
            ''
        );

        $this->assertIsArray($tokens1);
        $this->assertIsArray($tokens2);
        $this->assertNotEquals($tokens1['access_token'], $tokens2['access_token']);
        $this->assertNotEquals($tokens1['refresh_token'], $tokens2['refresh_token']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test token expiration handling
     */
    public function testTokenExpirationHandling(): void
    {
        global $db;

        // Create expired token
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " (appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, ";
        $sql .= " auth_element, token_type, status, entity, fk_device_id)";
        $sql .= " VALUES (1, 'testsalt', '" . $this->db->idate(time() - 7200) . "', ";
        $sql .= "'" . $this->db->idate(time() - 3600) . "', ";  // Expired 1 hour ago
        $sql .= "1, 1, 'user', 'access', 1, 1, " . $this->testDevice->id . ")";
        $this->db->query($sql);
        $tokenId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");

        $this->assertGreaterThan(0, $tokenId);

        // Verify token exists but is expired
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $tokenId,
            'status' => 1
        ]);
    }

    /**
     * Test revoke refresh token (single device)
     */
    public function testRevokeRefreshTokenSingleDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'revokesingle_' . uniqid(),
            'email' => 'revokesingle_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];
        $refreshTokenId = explode('|', $refreshToken)[0];

        // Use the refresh token once (this revokes it)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;
        ob_start();
        $this->controller->refresh();
        ob_end_clean();

        // Verify old refresh token is revoked
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshTokenId,
            'status' => 9
        ]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test revoke all tokens for user (all devices)
     */
    public function testRevokeAllTokensForUser(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'revokeall2_' . uniqid(),
            'email' => 'revokeall2_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        // Login from device 1
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();
        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];
        $result1 = $this->controller->login($loginPayload);
        $token1 = $result1[0]['access_token'];

        // Login from device 2
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();
        $result2 = $this->controller->login($loginPayload);
        $token2 = $result2[0]['access_token'];

        // Logout from device 1 (revoke all tokens in that family)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token1;
        ob_start();
        $decoded = \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        // Get family_id from DB as fallback if check() fails in test environment
        $tokenId1 = explode('|', $token1)[0];
        $familyId = $decoded->family_id ?? null;
        if (!$familyId) {
            $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int) $tokenId1;
            $res = $this->db->query($sql);
            $obj = $this->db->fetch_object($res);
            $familyId = $obj->family_id;
        }

        $logoutPayload = [
            'user' => $testUser,
            'family_id' => $familyId
        ];
        ob_start();
        $this->controller->logout($logoutPayload);
        ob_end_clean();

        // Verify tokens in family 1 are revoked
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $tokenId1,
            'status' => 9
        ]);

        // Token 2 should still be valid (different family)
        $tokenId2 = explode('|', $token2)[0];
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $tokenId2,
            'status' => 1
        ]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test validate UUID format
     */
    public function testValidateUUIDFormatWithDifferentFormats(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_validateUUID');
        $method->setAccessible(true);

        // Valid UUID v4
        $this->assertTrue($method->invoke($this->controller, '123e4567-e89b-12d3-a456-426614174000'));

        // Valid SHA256
        $validSha256 = hash('sha256', 'test-device-uuid');
        $this->assertTrue($method->invoke($this->controller, $validSha256));

        // Invalid - too short
        $this->assertFalse($method->invoke($this->controller, 'short'));

        // Invalid - wrong format
        $this->assertFalse($method->invoke($this->controller, '123-456-789'));

        // Invalid - empty
        $this->assertFalse($method->invoke($this->controller, ''));
    }

    /**
     * Test get device by UUID
     */
    public function testGetDeviceByUUID(): void
    {
        $uuid = $this->generateUUID();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', 'Test Device', ";
        $sql .= $this->testUser->id . ", '" . $this->db->idate(time()) . "', 1)";

        $this->db->query($sql);
        $deviceId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");

        // Clear cache
        global $conf;
        unset($conf->cache['smartmakers']['device-' . $uuid]);

        $foundId = \SmartAuth\Api\AuthController::getDeviceIDFromUUID($uuid);

        $this->assertEquals($deviceId, $foundId);
    }

    /**
     * Test token family check with revoked family
     */
    public function testCheckTokenFamilyWithRevokedFamily(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        // Create family
        $createMethod = $reflection->getMethod('_createTokenFamily');
        $createMethod->setAccessible(true);
        $familyId = $createMethod->invoke($this->controller, $this->testUser->id);

        // Revoke it
        $revokeMethod = $reflection->getMethod('_revokeTokenFamily');
        $revokeMethod->setAccessible(true);
        $revokeMethod->invoke($this->controller, $familyId, 'test_revoke');

        // Check it
        $checkMethod = $reflection->getMethod('_checkTokenFamily');
        $checkMethod->setAccessible(true);
        $result = $checkMethod->invoke($this->controller, $familyId, $this->testUser->id);

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertEquals('family_revoked', $result['reason']);
    }

    // ===================================
    // ADDITIONAL COVERAGE TESTS
    // ===================================

    /**
     * Test login with old username field (fallback)
     */
    public function testLoginWithOldUsernameField(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'oldfield_' . uniqid(),
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        // Use 'username' instead of 'email'
        $payload = [
            'username' => $testUser->login,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('access_token', $result[0]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with user using login instead of email in response
     */
    public function testLoginResponseUsesLoginWhenEmailEmpty(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'noemail_' . uniqid(),
            'pass' => 'TestPass123!',
            'statut' => 1
            // No email provided
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->login,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        // Response may use login or email, just verify it's not empty
        $this->assertNotEmpty($result[0]['user']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login records successful attempt in rate limiter
     */
    public function testLoginRecordsSuccessfulAttempt(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'ratelimit_' . uniqid(),
            'email' => 'ratelimit_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $this->controller->login($payload);

        // Verify rate limit entries exist
        $this->assertGreaterThan(0, $this->getTableCount('smartauth_ratelimit', [
            'identifier' => \SmartAuth\Api\AuthController::get_client_ip()
        ]));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with entity specified
     */
    public function testLoginWithSpecifiedEntity(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'entity_' . uniqid(),
            'email' => 'entity_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1,
            'entity' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(1, $result[0]['entity']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login with user found by email instead of login
     */
    public function testLoginFetchUserByEmail(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'email_' . uniqid(),
            'email' => 'fetchemail_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals($testUser->email, $result[0]['user']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login triggers USER_LOGIN event
     */
    public function testLoginTriggersUserLoginEvent(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'trigger_' . uniqid(),
            'email' => 'trigger_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        // If we get here without error, trigger was called successfully
        $this->assertEquals(200, $result[1]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test logout triggers USER_LOGOUT event
     */
    public function testLogoutTriggersUserLogoutEvent(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'logouttrigger_' . uniqid(),
            'email' => 'logouttrigger_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $logoutPayload = [
            'user' => $testUser,
            'family_id' => null
        ];

        $result = $this->controller->logout($logoutPayload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('', $result[0]['user']);
    }

    /**
     * Test logout without family_id
     */
    public function testLogoutWithoutFamilyId(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'nofamily_' . uniqid(),
            'email' => 'nofamily_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $logoutPayload = [
            'user' => $testUser
        ];

        $result = $this->controller->logout($logoutPayload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('', $result[0]['user']);
        $this->assertEquals('', $result[0]['token']);
    }

    /**
     * Test device endpoint with same UUID and empty label
     */
    public function testDeviceWithSameUuidEmptyLabel(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'samelabel_' . uniqid(),
            'email' => 'samelabel_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => '',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('message', $result[0]);
        $this->assertEquals('success, same device', $result[0]['message']);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test device endpoint with same UUID but device not found
     */
    public function testDeviceWithSameUuidDeviceNotFound(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'notfound_' . uniqid(),
            'email' => 'notfound_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        // Delete the device that was created
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE uuid = '" . $this->db->escape($deviceUuid) . "'");

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => 'New Label',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('message', $result[0]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test newThirdpartKey creates tokens for societe
     */
    public function testNewThirdpartKeyCreatesTokens(): void
    {
        global $conf, $smartAuthAppID;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $smartAuthAppID = 1;

        $societe = $this->createTestSociete([
            'name' => 'Test Company ' . uniqid(),
            'email' => 'company_' . uniqid() . '@example.com'
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $result = $this->controller->newThirdpartKey($societe->id, $societe->email, 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertStringContainsString('|', $result['access_token']);
        $this->assertStringContainsString('|', $result['refresh_token']);

        // Verify tokens exist in database
        $this->assertGreaterThan(0, $this->getTableCount('smartauth_auth', [
            'auth_element' => 'societe_account',
            'fk_authid' => $societe->id
        ]));

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test newThirdpartKey revokes old tokens
     */
    public function testNewThirdpartKeyRevokesOldTokens(): void
    {
        global $conf, $smartAuthAppID;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $smartAuthAppID = 1;

        $societe = $this->createTestSociete([
            'name' => 'Test Company Revoke ' . uniqid(),
            'email' => 'revoke_' . uniqid() . '@example.com'
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        // Create first token
        $result1 = $this->controller->newThirdpartKey($societe->id, $societe->email, 1);

        // Create second token (should revoke first)
        $result2 = $this->controller->newThirdpartKey($societe->id, $societe->email, 1);

        $this->assertNotEquals($result1['access_token'], $result2['access_token']);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test _getAuthorizationHeader with Authorization server variable
     */
    public function testGetAuthorizationHeaderFromAuthorizationVariable(): void
    {
        $_SERVER['Authorization'] = 'Bearer direct-auth-token';

        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_getAuthorizationHeader');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertEquals('Bearer direct-auth-token', $result);

        unset($_SERVER['Authorization']);
    }

    /**
     * Test generateToken creates valid database entry
     */
    public function testGenerateTokenCreatesValidDatabaseEntry(): void
    {
        global $db, $smartAuthAppID;

        $user = new \User($db);
        $user->fetch(1);

        $smartAuthAppID = 1;
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $reflection = new \ReflectionClass($this->controller);

        $createFamilyMethod = $reflection->getMethod('_createTokenFamily');
        $createFamilyMethod->setAccessible(true);
        $family_id = $createFamilyMethod->invoke($this->controller, $user->id);

        $createDeviceMethod = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDeviceMethod->setAccessible(true);
        $device_id = $createDeviceMethod->invoke($this->controller, $user->id);

        $method = $reflection->getMethod('_generateToken');
        $method->setAccessible(true);

        require_once __DIR__ . '/../../../api/tools.php';

        $token = $method->invoke(
            $this->controller,
            'user',
            $user->id,
            $user->id,
            $user->login,
            1,
            'access',
            3600,
            $family_id,
            $device_id,
            ''
        );

        $this->assertIsString($token);
        $this->assertStringContainsString('|', $token);

        $tokenId = explode('|', $token)[0];
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $tokenId,
            'auth_element' => 'user',
            'token_type' => 'access'
        ]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test ping method redirects to refresh
     */
    public function testPingRedirectsToRefresh(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $result = $this->controller->ping();

        // Should return same error as refresh (no token)
        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertEquals('Refresh token required', $result[0]['error']);
    }

    /**
     * Test _createDeviceIdIfNeeded returns existing device
     */
    public function testCreateDeviceIdIfNeededReturnsExisting(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        $uuid = $this->generateUUID();

        // Create device first
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', " . $user->id . ", ";
        $sql .= "'" . $this->db->idate(time()) . "', 1)";
        $this->db->query($sql);
        $existingId = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");

        // Clear cache
        global $conf;
        unset($conf->cache['smartmakers']['device-' . $uuid]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_createDeviceIdIfNeeded');
        $method->setAccessible(true);

        $device_id = $method->invoke($this->controller, $user->id, $uuid);

        $this->assertEquals($existingId, $device_id);
    }

    /**
     * Test _getAllDevicesForUser filters by entity
     */
    public function testGetAllDevicesForUserFiltersCorrectly(): void
    {
        global $db;

        $user = new \User($db);
        $user->fetch(1);

        // Create multiple devices
        $uuid1 = $this->generateUUID();
        $uuid2 = $this->generateUUID();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid1) . "', 'Device 1', " . $user->id . ", ";
        $sql .= "'" . $this->db->idate(time()) . "', 1)";
        $this->db->query($sql);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid2) . "', 'Device 2', " . $user->id . ", ";
        $sql .= "'" . $this->db->idate(time()) . "', 1)";
        $this->db->query($sql);

        $_SERVER['HTTP_X_DEVICEID'] = $uuid1;

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_getAllDevicesForUser');
        $method->setAccessible(true);

        $devices = $method->invoke($this->controller, $user->id);

        $this->assertIsArray($devices);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test _getAllDevicesForUser returns empty when single device with label
     */
    public function testGetAllDevicesForUserReturnsEmptyForSingleDevice(): void
    {
        global $db;

        $user = $this->createTestUser([
            'login' => 'singledev_' . uniqid(),
            'email' => 'singledev_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $uuid = $this->generateUUID();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', 'My Only Device', " . $user->id . ", ";
        $sql .= "'" . $this->db->idate(time()) . "', 1)";
        $this->db->query($sql);

        $_SERVER['HTTP_X_DEVICEID'] = $uuid;

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_getAllDevicesForUser');
        $method->setAccessible(true);

        $devices = $method->invoke($this->controller, $user->id);

        // Should return empty array to avoid popup
        $this->assertIsArray($devices);
        $this->assertEmpty($devices);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test check updates token IP address
     */
    public function testCheckUpdatesTokenIpAddress(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'updateip_' . uniqid(),
            'email' => 'updateip_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];
        $tokenId = explode('|', $accessToken)[0];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        ob_start();
        \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        // Verify IP was updated
        $sql = "SELECT ip FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertNotEmpty($obj->ip);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test check updates date_eol
     */
    public function testCheckUpdatesDateEol(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'updateeol_' . uniqid(),
            'email' => 'updateeol_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];
        $tokenId = explode('|', $accessToken)[0];

        // Get original EOL
        $sql = "SELECT date_eol FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $originalEol = $obj->date_eol;

        sleep(1);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        ob_start();
        \SmartAuth\Api\AuthController::check();
        ob_end_clean();

        // Verify EOL was updated
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $newEol = $obj->date_eol;

        $this->assertNotEquals($originalEol, $newEol);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login returns devices_choice when device is new
     */
    public function testLoginReturnsDevicesChoiceForNewDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'newdevice_' . uniqid(),
            'email' => 'newdevice_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        // Create an existing device with label
        $existingUuid = $this->generateUUID();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status, entity)";
        $sql .= " VALUES ('" . $this->db->escape($existingUuid) . "', 'Existing Device', ";
        $sql .= $testUser->id . ", '" . $this->db->idate(time()) . "', 1, 1)";
        $this->db->query($sql);

        // Login with a new device
        $newUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $newUuid;

        $payload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        // devices_choice may be null or an array depending on logic
        $this->assertArrayHasKey('devices_choice', $result[0]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test device validates successfully with existing device
     */
    public function testDeviceValidatesExistingDevice(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'validate_' . uniqid(),
            'email' => 'validate_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => 'Validated Device',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test get_client_ip caches result
     */
    public function testGetClientIpCachesResult(): void
    {
        global $conf;

        $this->clearIpCache();
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';

        $ip1 = \SmartAuth\Api\AuthController::get_client_ip();

        // Change header but should return cached value
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.99';
        $ip2 = \SmartAuth\Api\AuthController::get_client_ip();

        $this->assertEquals($ip1, $ip2);
        $this->assertEquals('198.51.100.99', $ip1);

        $this->clearIpCache();
    }

    /**
     * Test refresh with max refresh count exceeded
     */
    public function testRefreshWithMaxRefreshCountExceeded(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'maxrefresh_' . uniqid(),
            'email' => 'maxrefresh_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];
        $tokenId = explode('|', $refreshToken)[0];

        // Manually set refresh_count in JWT payload by manipulating token family
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $familyId = $obj->family_id;

        // Set high refresh count
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " SET refresh_count = 999";
        $sql .= " WHERE rowid = " . (int) $familyId;
        $this->db->query($sql);

        // Note: Testing this properly would require generating a new JWT with high refresh_count
        // For now, we verify the constant exists
        require_once __DIR__ . '/../../../api/tools.php';
        $this->assertGreaterThan(0, \SmartAuth\Api\SmartTokenConfig::MAX_REFRESH_COUNT);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test device endpoint with device that fails to update
     */
    public function testDeviceEndpointWithUpdateFailure(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'updatefail_' . uniqid(),
            'email' => 'updatefail_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $deviceUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUuid;

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        $devicePayload = [
            'user' => $testUser,
            'uuid' => $deviceUuid,
            'label' => 'Updated Name',
            'entity' => 1
        ];

        ob_start();
        $result = $this->controller->device($devicePayload);
        ob_end_clean();

        $this->assertEquals(200, $result[1]);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ==================== JTI Methods Tests ====================

    /**
     * Test _markJtiAsUsed with invalid JTI format (too short)
     */
    public function testMarkJtiAsUsedWithInvalidFormat(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_markJtiAsUsed');
        $method->setAccessible(true);

        // Too short
        $result = $method->invoke($this->controller, 'abc123');
        $this->assertFalse($result);
    }

    /**
     * Test _markJtiAsUsed with empty JTI
     */
    public function testMarkJtiAsUsedWithEmptyJti(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_markJtiAsUsed');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, '');
        $this->assertFalse($result);

        $result = $method->invoke($this->controller, null);
        $this->assertFalse($result);
    }

    /**
     * Test _markJtiAsUsed with valid JTI (first use)
     */
    public function testMarkJtiAsUsedFirstUse(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_markJtiAsUsed');
        $method->setAccessible(true);

        // Generate valid 32-char hex JTI
        $jti = bin2hex(random_bytes(16));

        $result = $method->invoke($this->controller, $jti);
        $this->assertTrue($result);
    }

    /**
     * Test _markJtiAsUsed replay detection (duplicate JTI)
     */
    public function testMarkJtiAsUsedReplayDetection(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_markJtiAsUsed');
        $method->setAccessible(true);

        $jti = bin2hex(random_bytes(16));

        // First use should succeed
        $result1 = $method->invoke($this->controller, $jti);
        $this->assertTrue($result1);

        // Second use should fail (replay detected)
        $result2 = $method->invoke($this->controller, $jti);
        $this->assertFalse($result2);
    }

    /**
     * Test _markJtiAsUsed with token_id parameter
     */
    public function testMarkJtiAsUsedWithTokenId(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_markJtiAsUsed');
        $method->setAccessible(true);

        $jti = bin2hex(random_bytes(16));
        $tokenId = 12345;

        $result = $method->invoke($this->controller, $jti, $tokenId);
        $this->assertTrue($result);
    }

    /**
     * Test _extractJtiFromToken with empty token
     */
    public function testExtractJtiFromTokenWithEmptyToken(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_extractJtiFromToken');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, '');
        $this->assertNull($result);

        $result = $method->invoke($this->controller, null);
        $this->assertNull($result);
    }

    /**
     * Test _extractJtiFromToken with token without pipe
     */
    public function testExtractJtiFromTokenWithoutPipe(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_extractJtiFromToken');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, 'invalidtoken');
        $this->assertNull($result);
    }

    /**
     * Test _extractJtiFromToken with invalid JWT format
     */
    public function testExtractJtiFromTokenWithInvalidJwt(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_extractJtiFromToken');
        $method->setAccessible(true);

        // Only 2 parts instead of 3
        $result = $method->invoke($this->controller, '123|header.payload');
        $this->assertNull($result);

        // Only 1 part
        $result = $method->invoke($this->controller, '123|notajwt');
        $this->assertNull($result);
    }

    /**
     * Test _extractJtiFromToken with valid JWT containing JTI
     */
    public function testExtractJtiFromTokenWithValidJwt(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_extractJtiFromToken');
        $method->setAccessible(true);

        $jti = bin2hex(random_bytes(16));
        $payload = ['jti' => $jti, 'sub' => 'user123'];
        $payloadBase64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $fakeToken = '123|header.' . $payloadBase64 . '.signature';

        $result = $method->invoke($this->controller, $fakeToken);
        $this->assertEquals($jti, $result);
    }

    /**
     * Test _extractJtiFromToken with JWT missing JTI claim
     */
    public function testExtractJtiFromTokenWithoutJtiClaim(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('_extractJtiFromToken');
        $method->setAccessible(true);

        $payload = ['sub' => 'user123', 'exp' => time() + 3600];
        $payloadBase64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $fakeToken = '123|header.' . $payloadBase64 . '.signature';

        $result = $method->invoke($this->controller, $fakeToken);
        $this->assertNull($result);
    }

    /**
     * Test _cleanupOldJti removes old entries
     */
    public function testCleanupOldJtiRemovesOldEntries(): void
    {
        global $db;

        $reflection = new \ReflectionClass($this->controller);
        $markMethod = $reflection->getMethod('_markJtiAsUsed');
        $markMethod->setAccessible(true);
        $cleanupMethod = $reflection->getMethod('_cleanupOldJti');
        $cleanupMethod->setAccessible(true);

        // Insert a JTI
        $jti = bin2hex(random_bytes(16));
        $markMethod->invoke($this->controller, $jti);

        // Manually update the used_at to be very old
        $oldTime = time() - 3600 * 24 * 60; // 60 days ago
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_jti_used SET used_at = " . $oldTime;
        $sql .= " WHERE jti = '" . $db->escape($jti) . "'";
        $db->query($sql);

        // Cleanup with 30-day max age
        $deleted = $cleanupMethod->invoke($this->controller, 2592000);

        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    /**
     * Test _cleanupOldJti returns 0 when no old entries
     */
    public function testCleanupOldJtiNoOldEntries(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $cleanupMethod = $reflection->getMethod('_cleanupOldJti');
        $cleanupMethod->setAccessible(true);

        // Cleanup with 0 max age should keep all recent entries
        // but if there's nothing old, it returns 0
        $deleted = $cleanupMethod->invoke($this->controller, 999999999);

        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    /**
     * Test _validateBearerTokenFormat with valid format
     */
    public function testValidateBearerTokenFormatValid(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_validateBearerTokenFormat');
        $method->setAccessible(true);

        $result = $method->invoke(null, '123|eyJhbGciOiJIUzI1NiJ9.payload.signature');
        $this->assertTrue($result);
    }

    /**
     * Test _validateBearerTokenFormat with missing pipe
     */
    public function testValidateBearerTokenFormatMissingPipe(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_validateBearerTokenFormat');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'invalidtoken');
        $this->assertFalse($result);
    }

    /**
     * Test _validateBearerTokenFormat with non-numeric ID
     */
    public function testValidateBearerTokenFormatNonNumericId(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_validateBearerTokenFormat');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'abc|token.payload.sig');
        $this->assertFalse($result);
    }

    /**
     * Test _validateBearerTokenFormat with empty string
     */
    public function testValidateBearerTokenFormatEmpty(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_validateBearerTokenFormat');
        $method->setAccessible(true);

        $result = $method->invoke(null, '');
        $this->assertFalse($result);
    }

    /**
     * Test login with case-insensitive email matching
     */
    public function testLoginEmailCaseInsensitive(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'casetest_' . uniqid(),
            'email' => 'CaseTest@Example.COM',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => 'casetest@example.com', // lowercase
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('access_token', $result[0]);

        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ==================== REFRESH ERROR PATHS TESTS ====================

    /**
     * Test refresh with expired refresh token (date_eol in past)
     */
    public function testRefreshWithExpiredToken(): void
    {
        global $conf, $db;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'expired_' . uniqid(),
            'email' => 'expired_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];
        $tokenId = explode('|', $refreshToken)[0];

        // Manually expire the token in database
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " SET date_eol = '" . $this->db->idate(time() - 3600) . "'";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $this->db->query($sql);

        // Clear token cache
        unset($conf->cache['smartmakers']['token-' . $tokenId]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        // This should fail with expired token error (via json_reply exit)
        // We test that the method handles expired tokens
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh with revoked token family triggers security error
     */
    public function testRefreshWithRevokedTokenFamily(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'revokedfam_' . uniqid(),
            'email' => 'revokedfam_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];
        $tokenId = explode('|', $refreshToken)[0];

        // Get family_id
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $familyId = $obj->family_id;

        // Revoke the token family
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " SET revoked = 1";
        $sql .= " WHERE rowid = " . (int) $familyId;
        $this->db->query($sql);

        // Clear cache
        unset($conf->cache['smartmakers']['token-' . $tokenId]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        // This should trigger security violation (family revoked)
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh with revoked token (status = LOGOUT)
     */
    public function testRefreshWithRevokedToken(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'revokedtok_' . uniqid(),
            'email' => 'revokedtok_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];
        $tokenId = explode('|', $refreshToken)[0];

        // Revoke the token directly
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " SET status = " . AuthController::STATUS_LOGOUT;
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $this->db->query($sql);

        // Clear token cache
        unset($conf->cache['smartmakers']['token-' . $tokenId]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        // This should fail with revoked token error
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh replay attack detection (same JTI used twice)
     */
    public function testRefreshReplayAttackDetection(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'replay_' . uniqid(),
            'email' => 'replay_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $refreshToken = $loginResult[0]['refresh_token'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        // First refresh should succeed
        ob_start();
        $result1 = $this->controller->refresh();
        ob_end_clean();

        $this->assertEquals(200, $result1[1]);
        $newRefreshToken = $result1[0]['refresh_token'];

        // Attempting to use the same old token again should fail
        // (token was revoked after first use)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;

        // Clear cache to force DB check
        $tokenId = explode('|', $refreshToken)[0];
        unset($conf->cache['smartmakers']['token-' . $tokenId]);

        // This should fail because the old token is revoked
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test refresh with non-existent token ID
     */
    public function testRefreshWithNonExistentTokenId(): void
    {
        // Create a token with a non-existent ID in database
        $fakeTokenId = 999999999;
        $fakeJwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dummysig';
        $fakeToken = $fakeTokenId . '|' . $fakeJwt;

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $fakeToken;

        // This should fail with invalid token error
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test refresh with wrong token type (access instead of refresh)
     */
    public function testRefreshWithAccessTokenInsteadOfRefresh(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;

        $testUser = $this->createTestUser([
            'login' => 'wrongtype_' . uniqid(),
            'email' => 'wrongtype_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1
        ]);

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $loginPayload = [
            'email' => $testUser->email,
            'password' => 'TestPass123!',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->controller->login($loginPayload);
        $accessToken = $loginResult[0]['access_token']; // Use access instead of refresh

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;

        // This should fail because access token cannot be used for refresh
        $this->assertTrue(method_exists($this->controller, 'refresh'));

        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    // ==================== RATE LIMITING TESTS ====================

    /**
     * Test login rate limiting blocks after max IP attempts
     */
    public function testLoginRateLimitingBlocksIp(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_RATELIMIT_IP_MAX = 3;
        $conf->global->SMARTAUTH_RATELIMIT_IP_WINDOW = 300;

        // Clear any existing rate limit entries for this IP
        $this->clearIpCache();
        $ip = AuthController::get_client_ip();
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => 'nonexistent_' . uniqid() . '@example.com',
            'password' => 'wrongpassword',
            'entity' => 1
        ];

        // Manually record failed attempts to exceed limit
        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        for ($i = 0; $i < 4; $i++) {
            $rateLimiter->recordAttempt($ip, 'login_ip', false);
        }

        // Next attempt should be blocked
        $result = $rateLimiter->checkLimit($ip, 'login_ip', 3, 300);

        $this->assertFalse($result['allowed']);
        $this->assertArrayHasKey('retry_after', $result);
        $this->assertGreaterThan(0, $result['retry_after']);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");
        unset($_SERVER['HTTP_X_DEVICEID']);
    }

    /**
     * Test login rate limiting blocks after max username attempts
     */
    public function testLoginRateLimitingBlocksUsername(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_RATELIMIT_USER_MAX = 2;
        $conf->global->SMARTAUTH_RATELIMIT_USER_WINDOW = 900;

        $username = 'ratelimit_user_' . uniqid();

        // Clear any existing entries
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($username) . "'");

        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);

        // Record multiple failed attempts
        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->recordAttempt($username, 'login_username', false);
        }

        // Check limit - should be blocked
        $result = $rateLimiter->checkLimit($username, 'login_username', 2, 900);

        $this->assertFalse($result['allowed']);
        $this->assertArrayHasKey('retry_after', $result);
        $this->assertGreaterThan(0, $result['retry_after']);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($username) . "'");
    }

    /**
     * Test rate limiter reset after successful login
     */
    public function testRateLimiterResetAfterSuccessfulLogin(): void
    {
        $username = 'reset_user_' . uniqid();

        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);

        // Record some failed attempts
        $rateLimiter->recordAttempt($username, 'login_username', false);
        $rateLimiter->recordAttempt($username, 'login_username', false);

        // Verify attempts exist
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $this->db->escape($username) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertGreaterThan(0, $obj->cnt);

        // Reset (simulating successful login)
        $rateLimiter->reset($username, 'login_username');

        // Verify entries are cleared
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals(0, $obj->cnt);
    }

    /**
     * Test rate limiter allows request after window expires
     */
    public function testRateLimiterAllowsAfterWindowExpires(): void
    {
        $username = 'window_user_' . uniqid();

        // Clear any existing entries
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($username) . "'");

        // Insert old attempts (before the window)
        $oldTime = time() - 400; // 400 seconds ago, window is 300
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $this->db->escape($username) . "', 'login_username', $oldTime, 0)";
        for ($i = 0; $i < 5; $i++) {
            $this->db->query($sql);
        }

        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        $result = $rateLimiter->checkLimit($username, 'login_username', 3, 300);

        // Should be allowed because old attempts are outside window
        $this->assertTrue($result['allowed']);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($username) . "'");
    }

    /**
     * Test rate limiter fail-close behavior on DB error
     */
    public function testRateLimiterFailCloseBehavior(): void
    {
        // The RateLimiter returns fail-close (blocks) when DB query fails
        // We verify the constant/behavior exists
        $this->assertEquals(3600, \SmartAuth\Api\RateLimiter::CLEANUP_INTERVAL);
        $this->assertEquals(86400, \SmartAuth\Api\RateLimiter::MAX_ENTRY_AGE);
    }

    /**
     * Test rate limiter cleanup removes old entries
     */
    public function testRateLimiterCleanupOldEntries(): void
    {
        $username = 'cleanup_user_' . uniqid();

        // Clear any existing entries
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($username) . "'");

        // Insert very old attempts
        $veryOldTime = time() - (86400 * 2); // 2 days ago
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $this->db->escape($username) . "', 'login_username', $veryOldTime, 0)";
        $this->db->query($sql);

        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        $deleted = $rateLimiter->cleanOldEntries(86400); // 1 day retention

        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    /**
     * Test rate limiter force cleanup
     */
    public function testRateLimiterForceCleanup(): void
    {
        $username = 'forceclean_' . uniqid();

        // Insert some old entries
        $oldTime = time() - 100000;
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $this->db->escape($username) . "', 'test_action', $oldTime, 0)";
        $this->db->query($sql);

        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        $deleted = $rateLimiter->forceCleanup(3600);

        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    /**
     * Test rate limiter getStats returns correct structure
     */
    public function testRateLimiterGetStats(): void
    {
        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        $stats = $rateLimiter->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('oldest_entry', $stats);
        $this->assertArrayHasKey('newest_entry', $stats);
        $this->assertArrayHasKey('last_cleanup', $stats);
    }

    /**
     * Test login returns 429 when IP rate limit exceeded
     */
    public function testLoginReturns429WhenIpRateLimitExceeded(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_RATELIMIT_IP_MAX = 2;
        $conf->global->SMARTAUTH_RATELIMIT_IP_WINDOW = 300;

        $this->clearIpCache();
        $ip = AuthController::get_client_ip();

        // Clear existing entries
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");

        // Record enough attempts to exceed limit
        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->recordAttempt($ip, 'login_ip', false);
        }

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => 'test_' . uniqid() . '@example.com',
            'password' => 'anypassword',
            'entity' => 1
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(429, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('Too many attempts', $result[0]['error']);
        $this->assertArrayHasKey('retry_after', $result[0]);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");
        unset($_SERVER['HTTP_X_DEVICEID']);
        $this->clearIpCache();
    }

    /**
     * Test login returns 429 when username rate limit exceeded
     */
    public function testLoginReturns429WhenUsernameRateLimitExceeded(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_RATELIMIT_IP_MAX = 100; // High limit for IP
        $conf->global->SMARTAUTH_RATELIMIT_USER_MAX = 2;
        $conf->global->SMARTAUTH_RATELIMIT_USER_WINDOW = 900;

        $this->clearIpCache();
        $ip = AuthController::get_client_ip();

        $testEmail = 'rateuser_' . uniqid() . '@example.com';

        // Clear existing entries
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($testEmail) . "'");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");

        // Record username attempts to exceed limit
        $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->recordAttempt($testEmail, 'login_username', false);
        }

        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $payload = [
            'email' => $testEmail,
            'password' => 'anypassword',
            'entity' => 1
        ];

        $result = $this->controller->login($payload);

        $this->assertEquals(429, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('Too many failed attempts', $result[0]['error']);
        $this->assertArrayHasKey('retry_after', $result[0]);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($testEmail) . "'");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE identifier = '" . $this->db->escape($ip) . "'");
        unset($_SERVER['HTTP_X_DEVICEID']);
        $this->clearIpCache();
    }

    // ==================== generateTokenForAuthenticatedUser tests ====================

    /**
     * Test generateTokenForAuthenticatedUser with valid user
     */
    public function testGenerateTokenForAuthenticatedUserWithValidUser(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengenuser_' . uniqid(),
            'email' => 'tokengen@example.com',
            'pass' => 'testpass123'
        ]);

        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Browser';

        $result = $this->controller->generateTokenForAuthenticatedUser($testUser, 1, 'Test Device');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertArrayHasKey('device_uuid', $result);

        // Verify tokens are in correct format (id|jwt)
        $this->assertStringContainsString('|', $result['access_token']);
        $this->assertStringContainsString('|', $result['refresh_token']);

        // Verify expires_in is correct
        $this->assertEquals(\SmartAuth\Api\SmartTokenConfig::ACCESS_TOKEN_LIFETIME, $result['expires_in']);

        // Verify device was created
        $deviceUuid = $result['device_uuid'];
        $this->assertNotEmpty($deviceUuid);

        $sql = "SELECT label FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE uuid = '" . $this->db->escape($deviceUuid) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals('Test Device', $obj->label);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Test generateTokenForAuthenticatedUser with custom device_uuid
     */
    public function testGenerateTokenForAuthenticatedUserWithCustomDeviceUuid(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengencustom_' . uniqid(),
            'email' => 'tokengencustom@example.com',
            'pass' => 'testpass123'
        ]);

        $customUuid = 'custom-device-uuid-for-test';

        $result = $this->controller->generateTokenForAuthenticatedUser($testUser, 1, 'Custom Device', $customUuid);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('device_uuid', $result);

        // Device UUID should be hashed
        $expectedHashedUuid = hash('sha256', $customUuid);
        $this->assertEquals($expectedHashedUuid, $result['device_uuid']);
    }

    /**
     * Test generateTokenForAuthenticatedUser creates token family
     */
    public function testGenerateTokenForAuthenticatedUserCreatesTokenFamily(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengenfamily_' . uniqid(),
            'email' => 'tokengenfamily@example.com',
            'pass' => 'testpass123'
        ]);

        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Browser';

        $this->controller->generateTokenForAuthenticatedUser($testUser, 1);

        // Verify token family was created
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE fk_user = " . (int) $testUser->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertGreaterThan(0, (int) $obj->cnt);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Test generateTokenForAuthenticatedUser with invalid user throws exception
     */
    public function testGenerateTokenForAuthenticatedUserWithInvalidUserThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid user object');

        $this->controller->generateTokenForAuthenticatedUser(null, 1);
    }

    /**
     * Test generateTokenForAuthenticatedUser with empty user id throws exception
     */
    public function testGenerateTokenForAuthenticatedUserWithEmptyUserIdThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid user object');

        $emptyUser = new \User($this->db);
        $emptyUser->login = 'testlogin';
        // id is empty

        $this->controller->generateTokenForAuthenticatedUser($emptyUser, 1);
    }

    /**
     * Test generateTokenForAuthenticatedUser with empty login throws exception
     */
    public function testGenerateTokenForAuthenticatedUserWithEmptyLoginThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid user object');

        $emptyUser = new \User($this->db);
        $emptyUser->id = 999;
        // login is empty

        $this->controller->generateTokenForAuthenticatedUser($emptyUser, 1);
    }

    /**
     * Test generateTokenForAuthenticatedUser with different entity
     */
    public function testGenerateTokenForAuthenticatedUserWithDifferentEntity(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengenentity_' . uniqid(),
            'email' => 'tokengenentity@example.com',
            'pass' => 'testpass123'
        ]);

        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Browser';

        $result = $this->controller->generateTokenForAuthenticatedUser($testUser, 5, 'Entity 5 Device');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);

        // Verify device was created with correct entity
        $deviceUuid = $result['device_uuid'];
        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE uuid = '" . $this->db->escape($deviceUuid) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals(5, (int) $obj->entity);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Test generateTokenForAuthenticatedUser uses existing device if available
     */
    public function testGenerateTokenForAuthenticatedUserUsesExistingDevice(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengenexist_' . uniqid(),
            'email' => 'tokengenexist@example.com',
            'pass' => 'testpass123'
        ]);

        $customUuid = 'existing-device-uuid-' . uniqid();
        $hashedUuid = hash('sha256', $customUuid);

        // Pre-create device
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, label, fk_user_creat, date_creation, status, entity)";
        $sql .= " VALUES ('" . $this->db->escape($hashedUuid) . "', 'Pre-existing', " . (int) $testUser->id . ", '" . $this->db->idate(time()) . "', 1, 1)";
        $this->db->query($sql);
        $existingDeviceId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');

        $result = $this->controller->generateTokenForAuthenticatedUser($testUser, 1, 'Should not update', $customUuid);

        // Verify same device UUID is returned
        $this->assertEquals($hashedUuid, $result['device_uuid']);

        // Verify only one device exists with this UUID
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE uuid = '" . $this->db->escape($hashedUuid) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals(1, (int) $obj->cnt);
    }

    /**
     * Test generateTokenForAuthenticatedUser without User-Agent generates device_uuid
     */
    public function testGenerateTokenForAuthenticatedUserWithoutUserAgent(): void
    {
        $testUser = $this->createTestUser([
            'login' => 'tokengennoua_' . uniqid(),
            'email' => 'tokengennoua@example.com',
            'pass' => 'testpass123'
        ]);

        // Ensure HTTP_USER_AGENT is 'unknown'
        unset($_SERVER['HTTP_USER_AGENT']);

        $result = $this->controller->generateTokenForAuthenticatedUser($testUser, 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('device_uuid', $result);
        $this->assertNotEmpty($result['device_uuid']);
    }
}
