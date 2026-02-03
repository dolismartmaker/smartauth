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

/**
 * Integration tests for AuthController with real Dolibarr database
 *
 * @covers \SmartAuth\Api\AuthController
 */
class AuthControllerRealTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $authController;

    protected function setUp(): void
    {
        parent::setUp();

        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        $this->authController = new AuthController();

        // Setup device ID header for tests
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();
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

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up global state
        unset($_SERVER['HTTP_X_DEVICEID']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Test index returns entities list
     */
    public function testIndexReturnsEntities(): void
    {
        $result = $this->authController->index();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('entities', $result[0]);
    }

    /**
     * Test RateLimiter integration during login flow
     */
    public function testRateLimiterIntegration(): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $ip = '192.168.100.1';

        // First few attempts should be allowed
        for ($i = 0; $i < 3; $i++) {
            $result = $rateLimiter->checkLimit($ip, 'login_ip', 10, 300);
            $this->assertTrue($result['allowed'], "Attempt $i should be allowed");
            $rateLimiter->recordAttempt($ip, 'login_ip', false);
        }

        // After 10 attempts, should be blocked
        for ($i = 3; $i < 10; $i++) {
            $rateLimiter->recordAttempt($ip, 'login_ip', false);
        }

        $result = $rateLimiter->checkLimit($ip, 'login_ip', 10, 300);
        $this->assertFalse($result['allowed']);
    }

    /**
     * Test token family creation
     */
    public function testTokenFamilyCreation(): void
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('_createTokenFamily');
        $method->setAccessible(true);

        $familyId = $method->invoke($this->authController, $this->testUser->id);

        $this->assertNotEmpty($familyId);

        // Verify in database
        $this->assertDatabaseHas('smartauth_token_family', [
            'fk_user' => $this->testUser->id
        ]);
    }

    /**
     * Test device creation for user
     */
    public function testDeviceCreation(): void
    {
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('_createDeviceIdIfNeeded');
        $method->setAccessible(true);

        $deviceId = $method->invoke($this->authController, $this->testUser->id);

        $this->assertGreaterThan(0, $deviceId);

        // Verify device was created
        $device = new SmartAuthDevices($this->db);
        $result = $device->fetch($deviceId);
        $this->assertGreaterThan(0, $result);
        $this->assertEquals($_SERVER['HTTP_X_DEVICEID'], $device->uuid);
    }

    /**
     * Test token pair generation
     */
    public function testTokenPairGeneration(): void
    {
        // First create a token family
        $reflection = new \ReflectionClass($this->authController);

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

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);

        // Tokens should contain pipe separator (id|jwt)
        $this->assertStringContainsString('|', $tokens['access_token']);
        $this->assertStringContainsString('|', $tokens['refresh_token']);
    }

    /**
     * Test token revocation
     */
    public function testTokenRevocation(): void
    {
        // Create a token first
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '127.0.0.1';
        $auth->entity = 1;
        $auth->fk_device_id = $this->testDevice->id;
        $auth->create($this->testUser);

        $tokenId = $auth->id;

        // Revoke it
        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('_revokeToken');
        $method->setAccessible(true);
        $method->invoke($this->authController, $tokenId, 'test_revocation');

        // Verify revoked
        $revokedAuth = new SmartAuth($this->db);
        $revokedAuth->fetch($tokenId);
        $this->assertEquals(SmartAuth::STATUS_CANCELED, $revokedAuth->status);
    }

    /**
     * Test token family revocation
     */
    public function testTokenFamilyRevocation(): void
    {
        // Create family
        $reflection = new \ReflectionClass($this->authController);
        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->authController, $this->testUser->id);

        // Revoke it
        $revokeFamily = $reflection->getMethod('_revokeTokenFamily');
        $revokeFamily->setAccessible(true);
        $revokeFamily->invoke($this->authController, $familyId, 'test_revocation');

        // Verify revoked in database
        $sql = "SELECT revoked FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $familyId;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $this->assertEquals(1, $obj->revoked);
    }

    /**
     * Test get all devices for user
     */
    public function testGetAllDevicesForUser(): void
    {
        // Create some devices for user
        for ($i = 0; $i < 3; $i++) {
            $device = new SmartAuthDevices($this->db);
            $device->label = "Test Device $i";
            $device->uuid = $this->generateUUID();
            $device->status = SmartAuthDevices::STATUS_VALIDATED;
            $device->entity = 1;
            $device->fk_user_creat = $this->testUser->id;
            $device->create($this->testUser);
        }

        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('_getAllDevicesForUser');
        $method->setAccessible(true);

        $devices = $method->invoke($this->authController, $this->testUser->id);

        $this->assertIsArray($devices);
        $this->assertGreaterThanOrEqual(3, count($devices));
    }

    /**
     * Test client IP detection
     */
    public function testGetClientIp(): void
    {
        // Clear cache to get fresh IP
        global $conf;
        unset($conf->cache['smartmakers']['clientIP']);

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $ip = AuthController::get_client_ip();

        // Private IPs are filtered, so result may be different from input
        // The method should return some valid IP or 0.0.0.0 for private ranges
        $this->assertNotEmpty($ip);
        $this->assertTrue(
            filter_var($ip, FILTER_VALIDATE_IP) !== false || $ip === '0.0.0.0',
            "Should return a valid IP or 0.0.0.0"
        );
    }

    /**
     * Test client IP with X-Forwarded-For
     */
    public function testGetClientIpWithForwardedFor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.3.18';

        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('get_client_ip');
        $method->setAccessible(true);

        $ip = $method->invoke($this->authController);

        // Should return first IP in X-Forwarded-For or REMOTE_ADDR depending on implementation
        $this->assertNotEmpty($ip);

        // Cleanup
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    /**
     * Test device ID from UUID caching
     */
    public function testGetDeviceIdFromUUID(): void
    {
        // Create a device
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Cache Test Device';
        $uuid = $this->generateUUID();
        $device->uuid = $uuid;
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('getDeviceIDFromUUID');
        $method->setAccessible(true);

        $deviceId = $method->invoke($this->authController, $uuid);

        $this->assertEquals($device->id, $deviceId);

        // Second call should use cache (same result)
        $deviceIdCached = $method->invoke($this->authController, $uuid);
        $this->assertEquals($deviceId, $deviceIdCached);
    }

    /**
     * Test device name retrieval
     */
    public function testGetDeviceName(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Named Test Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $reflection = new \ReflectionClass($this->authController);
        $method = $reflection->getMethod('getDeviceName');
        $method->setAccessible(true);

        // Get by ID
        $name = $method->invoke($this->authController, $device->id, null);
        $this->assertEquals('Named Test Device', $name);

        // Get by UUID
        $name = $method->invoke($this->authController, null, $device->uuid);
        $this->assertEquals('Named Test Device', $name);
    }

    /**
     * Test SmartAuth database storage
     */
    public function testSmartAuthDatabaseStorage(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Storage Test Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $auth = new SmartAuth($this->db);
        $auth->appuid = 100;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $device->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '10.0.0.1';
        $auth->entity = 1;
        $auth->create($this->testUser);

        $this->assertDatabaseHas('smartauth_auth', [
            'appuid' => 100,
            'fk_authid' => $this->testUser->id,
            'auth_element' => 'user',
            'token_type' => 'access'
        ]);
    }

    /**
     * Test token type constants
     */
    public function testTokenTypeConstants(): void
    {
        $this->assertEquals('access', SmartTokenConfig::TYPE_ACCESS);
        $this->assertEquals('refresh', SmartTokenConfig::TYPE_REFRESH);
    }

    /**
     * Test token lifetime constants
     */
    public function testTokenLifetimeConstants(): void
    {
        $this->assertEquals(3600, SmartTokenConfig::ACCESS_TOKEN_LIFETIME);
        $this->assertEquals(2592000, SmartTokenConfig::REFRESH_TOKEN_LIFETIME);
    }
}
