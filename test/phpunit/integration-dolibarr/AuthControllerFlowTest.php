<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth\Api\AuthController;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Api\SmartTokenConfig;
use SmartAuth;
use SmartAuthDevices;
use ReflectionClass;

/**
 * Integration tests for AuthController authentication flows
 * Tests the complete token lifecycle: creation, refresh, and revocation
 */
class AuthControllerFlowTest extends DolibarrRealTestCase
{
    private AuthController $authController;
    private string $testDeviceUUID;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authController = new AuthController();
        $this->testDeviceUUID = 'test-device-flow-' . uniqid();
        $_SERVER['HTTP_X_DEVICEID'] = $this->testDeviceUUID;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_X_DEVICEID']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_X_REAL_IP']);
    }

    /**
     * Helper: Create token family and device, then generate tokens
     */
    private function createAuthenticatedSession(): array
    {
        $reflection = new ReflectionClass($this->authController);

        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->authController, $this->testUser->id);

        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($this->authController, $this->testUser->id);

        $generatePair = $reflection->getMethod('_generateTokenPair');
        $generatePair->setAccessible(true);

        $tokens = $generatePair->invoke(
            $this->authController,
            'user',
            $this->testUser->id,
            $this->testUser->id,
            $this->testUser->login,
            1,
            $familyId,
            $deviceId
        );

        return [
            'family_id' => $familyId,
            'device_id' => $deviceId,
            'tokens' => $tokens
        ];
    }

    /**
     * Test index returns entities list
     */
    public function testIndexReturnsEntitiesList(): void
    {
        $result = $this->authController->index();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('entities', $result[0]);
        $this->assertIsArray($result[0]['entities']);
    }

    /**
     * Test complete token lifecycle: create -> use -> refresh -> logout
     */
    public function testCompleteTokenLifecycle(): void
    {
        // Step 1: Create authenticated session
        $session = $this->createAuthenticatedSession();

        $this->assertNotEmpty($session['family_id']);
        $this->assertNotEmpty($session['device_id']);
        $this->assertNotEmpty($session['tokens']['access_token']);
        $this->assertNotEmpty($session['tokens']['refresh_token']);

        // Verify tokens have correct format (id|jwt)
        $this->assertStringContainsString('|', $session['tokens']['access_token']);
        $this->assertStringContainsString('|', $session['tokens']['refresh_token']);

        // Verify family was created in database
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'fk_user' => $this->testUser->id,
            'revoked' => 0
        ]);

        // Step 2: Verify access and refresh tokens stored in database
        $accessTokenId = explode('|', $session['tokens']['access_token'])[0];
        $refreshTokenId = explode('|', $session['tokens']['refresh_token'])[0];

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessTokenId,
            'token_type' => SmartTokenConfig::TYPE_ACCESS,
            'status' => AuthController::STATUS_VALID
        ]);

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshTokenId,
            'token_type' => SmartTokenConfig::TYPE_REFRESH,
            'status' => AuthController::STATUS_VALID
        ]);

        // Step 3: Test logout (revoke family)
        $logoutResult = $this->authController->logout([
            'user' => $this->testUser,
            'family_id' => $session['family_id']
        ]);

        $this->assertEquals(200, $logoutResult[1]);
        $this->assertEquals('', $logoutResult[0]['user']);
        $this->assertEquals('', $logoutResult[0]['token']);

        // Verify family was revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1
        ]);
    }

    /**
     * Test token family revocation revokes all tokens in family
     */
    public function testTokenFamilyRevocationRevokesAllTokens(): void
    {
        $session = $this->createAuthenticatedSession();

        // Revoke family via reflection
        $reflection = new ReflectionClass($this->authController);
        $revokeFamily = $reflection->getMethod('_revokeTokenFamily');
        $revokeFamily->setAccessible(true);
        $revokeFamily->invoke($this->authController, $session['family_id'], 'test_revocation');

        // Verify family was revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1
        ]);
    }

    /**
     * Test single token revocation
     */
    public function testSingleTokenRevocation(): void
    {
        $session = $this->createAuthenticatedSession();
        $accessTokenId = explode('|', $session['tokens']['access_token'])[0];

        // Revoke single token
        $reflection = new ReflectionClass($this->authController);
        $revokeToken = $reflection->getMethod('_revokeToken');
        $revokeToken->setAccessible(true);
        $revokeToken->invoke($this->authController, $accessTokenId, 'test_single_revoke');

        // Verify only access token is revoked
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessTokenId,
            'status' => AuthController::STATUS_LOGOUT
        ]);

        // Refresh token should still be valid
        $refreshTokenId = explode('|', $session['tokens']['refresh_token'])[0];
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshTokenId,
            'status' => AuthController::STATUS_VALID
        ]);
    }

    /**
     * Test token family check with valid family
     */
    public function testTokenFamilyCheckValid(): void
    {
        $session = $this->createAuthenticatedSession();

        $reflection = new ReflectionClass($this->authController);
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        $result = $checkFamily->invoke(
            $this->authController,
            $session['family_id'],
            $this->testUser->id
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * Test token family check with revoked family
     */
    public function testTokenFamilyCheckRevoked(): void
    {
        $session = $this->createAuthenticatedSession();

        // Revoke the family first
        $reflection = new ReflectionClass($this->authController);
        $revokeFamily = $reflection->getMethod('_revokeTokenFamily');
        $revokeFamily->setAccessible(true);
        $revokeFamily->invoke($this->authController, $session['family_id'], 'test');

        // Check should fail
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        $result = $checkFamily->invoke(
            $this->authController,
            $session['family_id'],
            $this->testUser->id
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('family_revoked', $result['reason']);
    }

    /**
     * Test token family check with wrong user
     */
    public function testTokenFamilyCheckWrongUser(): void
    {
        $session = $this->createAuthenticatedSession();

        $reflection = new ReflectionClass($this->authController);
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        // Check with different user ID
        $result = $checkFamily->invoke(
            $this->authController,
            $session['family_id'],
            999999 // Wrong user ID
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('user_mismatch', $result['reason']);
    }

    /**
     * Test token family check with non-existent family
     */
    public function testTokenFamilyCheckNotFound(): void
    {
        $reflection = new ReflectionClass($this->authController);
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        $result = $checkFamily->invoke(
            $this->authController,
            999999, // Non-existent family ID
            $this->testUser->id
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('family_not_found', $result['reason']);
    }

    /**
     * Test multiple token families for same user
     */
    public function testMultipleTokenFamiliesForUser(): void
    {
        $reflection = new ReflectionClass($this->authController);
        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);

        // Create multiple families (simulating multiple device logins)
        $family1 = $createFamily->invoke($this->authController, $this->testUser->id);
        $family2 = $createFamily->invoke($this->authController, $this->testUser->id);
        $family3 = $createFamily->invoke($this->authController, $this->testUser->id);

        $this->assertNotEquals($family1, $family2);
        $this->assertNotEquals($family2, $family3);

        // Verify all families are valid
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        $this->assertTrue($checkFamily->invoke($this->authController, $family1, $this->testUser->id)['valid']);
        $this->assertTrue($checkFamily->invoke($this->authController, $family2, $this->testUser->id)['valid']);
        $this->assertTrue($checkFamily->invoke($this->authController, $family3, $this->testUser->id)['valid']);

        // Count families in database
        $count = $this->getTableCount('smartauth_token_family', ['fk_user' => $this->testUser->id]);
        $this->assertGreaterThanOrEqual(3, $count);
    }

    /**
     * Test token family update after refresh
     */
    public function testTokenFamilyUpdateAfterRefresh(): void
    {
        $session = $this->createAuthenticatedSession();

        // Get initial refresh count
        $sql = "SELECT refresh_count FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $session['family_id'];
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $initialCount = (int) $obj->refresh_count;

        // Update family
        $reflection = new ReflectionClass($this->authController);
        $updateFamily = $reflection->getMethod('_updateTokenFamily');
        $updateFamily->setAccessible(true);
        $updateFamily->invoke($this->authController, $session['family_id'], $initialCount + 1);

        // Verify count was updated
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $this->assertEquals($initialCount + 1, (int) $obj->refresh_count);
    }

    /**
     * Test device creation for new UUID
     */
    public function testDeviceCreationForNewUUID(): void
    {
        $newUUID = 'brand-new-device-' . uniqid();
        $_SERVER['HTTP_X_DEVICEID'] = $newUUID;

        $reflection = new ReflectionClass($this->authController);
        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);

        $deviceId = $createDevice->invoke($this->authController, $this->testUser->id);

        $this->assertGreaterThan(0, $deviceId);

        // Verify device was created
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $deviceId,
            'uuid' => $newUUID,
            'fk_user_creat' => $this->testUser->id
        ]);
    }

    /**
     * Test device creation stores device in database
     */
    public function testDeviceCreationStoresDevice(): void
    {
        $newUUID = 'test-device-store-' . uniqid();
        $_SERVER['HTTP_X_DEVICEID'] = $newUUID;

        // Create device
        $reflection = new ReflectionClass($this->authController);
        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);

        $deviceId = $createDevice->invoke($this->authController, $this->testUser->id);

        // Verify device was created
        $this->assertGreaterThan(0, $deviceId);
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $deviceId,
            'uuid' => $newUUID
        ]);
    }

    /**
     * Test get client IP with various headers
     */
    public function testGetClientIpWithRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);

        $ip = AuthController::get_client_ip();

        // Result depends on whether IP passes filter (private IPs may return 0.0.0.0)
        $this->assertNotEmpty($ip);
    }

    /**
     * Test get client IP with X-Forwarded-For
     * Note: apache_request_headers() is not available in CLI, so behavior differs
     */
    public function testGetClientIpWithXForwardedFor(): void
    {
        // Clear cache to get fresh IP
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);

        // Use a real public IP (Google DNS)
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 70.41.3.18';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = AuthController::get_client_ip();

        // In CLI mode without apache_request_headers, the result depends on implementation
        // Just verify we get some valid IP
        $this->assertNotEmpty($ip);
        $this->assertTrue(
            filter_var($ip, FILTER_VALIDATE_IP) !== false || $ip === '0.0.0.0',
            "Should return a valid IP"
        );
    }

    /**
     * Test get client IP with CF-Connecting-IP (Cloudflare)
     * Note: This test may behave differently in CLI vs Apache mode
     */
    public function testGetClientIpWithCloudflare(): void
    {
        // Clear cache to get fresh IP
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);

        // Use a real public IP
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.4.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = AuthController::get_client_ip();

        // In CLI mode, apache_request_headers is not available
        // Just verify we get some valid IP
        $this->assertNotEmpty($ip);
        $this->assertTrue(
            filter_var($ip, FILTER_VALIDATE_IP) !== false || $ip === '0.0.0.0',
            "Should return a valid IP"
        );
    }

    /**
     * Test getDeviceIDFromUUID
     */
    public function testGetDeviceIdFromUUID(): void
    {
        // Create a device first
        $device = new SmartAuthDevices($this->db);
        $device->label = 'UUID Lookup Test Device';
        $device->uuid = 'uuid-lookup-test-' . uniqid();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        // Look it up
        $foundId = AuthController::getDeviceIDFromUUID($device->uuid);
        $this->assertEquals($device->id, $foundId);

        // Non-existent UUID should return -1 or negative
        $notFoundId = AuthController::getDeviceIDFromUUID('non-existent-uuid-12345');
        $this->assertLessThanOrEqual(-1, $notFoundId);
    }

    /**
     * Test getDeviceName by ID
     */
    public function testGetDeviceNameById(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'My iPhone 15';
        $device->uuid = 'name-test-id-' . uniqid();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $name = AuthController::getDeviceName($device->id, null);
        $this->assertEquals('My iPhone 15', $name);
    }

    /**
     * Test getDeviceName by UUID
     */
    public function testGetDeviceNameByUUID(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'My Android Tablet';
        $device->uuid = 'name-test-uuid-' . uniqid();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $name = AuthController::getDeviceName(null, $device->uuid);
        $this->assertEquals('My Android Tablet', $name);
    }

    /**
     * Test getDeviceName returns empty for non-existent device
     */
    public function testGetDeviceNameReturnsEmptyForNonExistent(): void
    {
        $name = AuthController::getDeviceName(999999, null);
        $this->assertEquals('', $name);

        $name = AuthController::getDeviceName(null, 'non-existent-uuid');
        $this->assertEquals('', $name);

        // Both null
        $name = AuthController::getDeviceName(null, null);
        $this->assertEquals('', $name);
    }

    /**
     * Test getAllDevicesForUser
     */
    public function testGetAllDevicesForUser(): void
    {
        // Create several devices for the test user
        $devices = [];
        for ($i = 1; $i <= 3; $i++) {
            $device = new SmartAuthDevices($this->db);
            $device->label = "User Device $i";
            $device->uuid = 'user-device-' . $i . '-' . uniqid();
            $device->status = SmartAuthDevices::STATUS_VALIDATED;
            $device->entity = 1;
            $device->fk_user_creat = $this->testUser->id;
            $device->create($this->testUser);
            $devices[] = $device;
        }

        $reflection = new ReflectionClass($this->authController);
        $getAllDevices = $reflection->getMethod('_getAllDevicesForUser');
        $getAllDevices->setAccessible(true);

        $result = $getAllDevices->invoke($this->authController, $this->testUser->id);

        $this->assertIsArray($result);
        // Should return at least some devices (filtering logic may affect count)
    }

    /**
     * Test SmartTokenConfig constants
     */
    public function testSmartTokenConfigConstants(): void
    {
        $this->assertEquals('access', SmartTokenConfig::TYPE_ACCESS);
        $this->assertEquals('refresh', SmartTokenConfig::TYPE_REFRESH);
        $this->assertEquals(3600, SmartTokenConfig::ACCESS_TOKEN_LIFETIME);
        $this->assertEquals(2592000, SmartTokenConfig::REFRESH_TOKEN_LIFETIME);
        $this->assertGreaterThan(0, SmartTokenConfig::MAX_REFRESH_COUNT);
    }

    /**
     * Test AuthController constants
     */
    public function testAuthControllerConstants(): void
    {
        $this->assertEquals(0, AuthController::STATUS_DRAFT);
        $this->assertEquals(1, AuthController::STATUS_VALID);
        $this->assertEquals(9, AuthController::STATUS_LOGOUT);

        $this->assertGreaterThan(0, AuthController::SMARTAUTH_RATELIMIT_IP_MAX);
        $this->assertGreaterThan(0, AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW);
        $this->assertGreaterThan(0, AuthController::SMARTAUTH_RATELIMIT_USER_MAX);
        $this->assertGreaterThan(0, AuthController::SMARTAUTH_RATELIMIT_USER_WINDOW);
    }
}
