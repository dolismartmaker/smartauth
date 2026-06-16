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
 * Integration tests for AuthController login flow with real Dolibarr credentials
 *
 * Uses the default SQLite Dolibarr user: admin/adminadmin
 *
 * @covers \SmartAuth\Api\AuthController
 */
class AuthControllerLoginTest extends DolibarrRealTestCase
{
    private AuthController $authController;
    private string $testDeviceUUID;

    protected function setUp(): void
    {
        parent::setUp();

        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        $this->authController = new AuthController();
        $this->testDeviceUUID = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $this->testDeviceUUID;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
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
        unset($_SERVER['HTTP_X_DEVICEID']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REMOTE_ADDR']);
    }

    /**
     * Test successful login with valid credentials
     */
    public function testLoginWithValidCredentials(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1], 'Login should return HTTP 200');

        $response = $result[0];
        $this->assertArrayHasKey('user', $response);
        $this->assertArrayHasKey('userid', $response);
        $this->assertArrayHasKey('access_token', $response);
        $this->assertArrayHasKey('refresh_token', $response);
        $this->assertArrayHasKey('expires_in', $response);
        $this->assertArrayHasKey('token_type', $response);

        // Verify token format (id|jwt)
        $this->assertStringContainsString('|', $response['access_token']);
        $this->assertStringContainsString('|', $response['refresh_token']);

        // Verify token type
        $this->assertEquals('Bearer', $response['token_type']);

        // Verify expires_in matches config
        $this->assertEquals(SmartTokenConfig::ACCESS_TOKEN_LIFETIME, $response['expires_in']);

        // Verify userid is positive
        $this->assertGreaterThan(0, $response['userid']);

        // Verify tokens are stored in database
        $accessTokenId = explode('|', $response['access_token'])[0];
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessTokenId,
            'token_type' => SmartTokenConfig::TYPE_ACCESS,
            'status' => AuthController::STATUS_VALID
        ]);
    }

    /**
     * Test login with old 'username' field (backward compatibility)
     */
    public function testLoginWithUsernameField(): void
    {
        $payload = [
            'username' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);

        $this->assertEquals(200, $result[1], 'Login with username field should succeed');
        $this->assertArrayHasKey('access_token', $result[0]);
    }

    /**
     * Test login creates token family
     */
    public function testLoginCreatesTokenFamily(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);
        $this->assertEquals(200, $result[1]);

        $userId = $result[0]['userid'];

        // Verify token family was created
        $this->assertDatabaseHas('smartauth_token_family', [
            'fk_user' => $userId,
            'revoked' => 0
        ]);
    }

    /**
     * Test login creates device
     */
    public function testLoginCreatesDevice(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);
        $this->assertEquals(200, $result[1]);

        // Verify device was created
        $this->assertDatabaseHas('smartauth_devices', [
            'uuid' => $this->testDeviceUUID
        ]);
    }

    /**
     * Test login with rememberMe flag
     */
    public function testLoginWithRememberMe(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 1
        ];

        $result = $this->authController->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(1, $result[0]['rememberMe']);
    }

    /**
     * Test login returns legacy 'token' field for compatibility
     */
    public function testLoginReturnsLegacyTokenField(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('token', $result[0]);
        // Legacy token should match access_token
        $this->assertEquals($result[0]['access_token'], $result[0]['token']);
    }

    /**
     * Test multiple logins create separate token families
     */
    public function testMultipleLoginsCreateSeparateFamilies(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        // First login
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();
        $result1 = $this->authController->login($payload);

        // Second login (different device)
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();
        $result2 = $this->authController->login($payload);

        $this->assertEquals(200, $result1[1]);
        $this->assertEquals(200, $result2[1]);

        // Should have at least 2 token families
        $userId = $result1[0]['userid'];
        $count = $this->getTableCount('smartauth_token_family', ['fk_user' => $userId]);
        $this->assertGreaterThanOrEqual(2, $count);
    }

    /**
     * Test rate limiter integration during login
     */
    public function testRateLimiterRecordsAttempts(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        // Successful login
        $result = $this->authController->login($payload);
        $this->assertEquals(200, $result[1]);

        // Verify rate limiter recorded successful attempt
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => 'admin',
            'action' => 'login_username',
            'success' => 1
        ]);
    }

    /**
     * Test complete login -> logout flow
     */
    public function testCompleteLoginLogoutFlow(): void
    {
        // Login
        $loginPayload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $loginResult = $this->authController->login($loginPayload);
        $this->assertEquals(200, $loginResult[1]);

        $userId = $loginResult[0]['userid'];
        $accessToken = $loginResult[0]['access_token'];

        // Get family ID from token in database
        $accessTokenId = explode('|', $accessToken)[0];
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int) $accessTokenId;
        $res = $this->db->query($sql);
        $obj = $this->db->fetch_object($res);
        $familyId = $obj->family_id;

        // Fetch the user object for logout
        $user = new \User($this->db);
        $user->fetch($userId);

        // Logout
        $logoutPayload = [
            'user' => $user,
            'family_id' => $familyId
        ];

        $logoutResult = $this->authController->logout($logoutPayload);
        $this->assertEquals(200, $logoutResult[1]);

        // Verify family is revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $familyId,
            'revoked' => 1
        ]);
    }

    /**
     * Test login returns devices_choice for new device
     */
    public function testLoginReturnsDevicesChoiceForNewDevice(): void
    {
        // First, create a named device for the user
        $user = new \User($this->db);
        $user->fetch(0, 'admin');

        $existingDevice = new SmartAuthDevices($this->db);
        $existingDevice->label = 'My Known Device';
        $existingDevice->uuid = $this->generateUUID();
        $existingDevice->status = SmartAuthDevices::STATUS_VALIDATED;
        $existingDevice->entity = 1;
        $existingDevice->fk_user_creat = $user->id;
        $existingDevice->create($user);

        // Login with new device UUID
        $newDeviceUUID = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $newDeviceUUID;

        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('devices_choice', $result[0]);
        // Regression (todo l.12/l.19): logging in from a NEW device (different
        // uuid) while the user already owns exactly one named device must still
        // propose that device in the picker. The old count==1 filter wrongly
        // emptied the list, so "smartphone eric" never reappeared on a second
        // browser of the same phone.
        $this->assertIsArray($result[0]['devices_choice']);
        $labels = array_map(
            static function ($d) {
                return is_object($d) ? $d->label : $d['label'];
            },
            $result[0]['devices_choice']
        );
        $this->assertContains('My Known Device', $labels);
    }

    /**
     * Regression (todo l.12/l.19): a browser is a single technical uuid, so two
     * users on the SAME browser share it. getDeviceName() scoped to a user must
     * NOT leak another user's device name -- otherwise user B would silently
     * inherit user A's "smartphone eric" at login.
     */
    public function testGetDeviceNameIsScopedToUser(): void
    {
        $user = new \User($this->db);
        $user->fetch(0, 'admin');

        $uuid = $this->generateUUID();
        $dev = new SmartAuthDevices($this->db);
        $dev->label = 'smartphone eric';
        $dev->uuid = $uuid;
        $dev->status = SmartAuthDevices::STATUS_VALIDATED;
        $dev->entity = 1;
        $dev->fk_user_creat = $user->id;
        $dev->create($user);

        // The owner still sees the name (scoped lookup).
        $this->assertSame('smartphone eric', AuthController::getDeviceName(null, $uuid, (int) $user->id));
        // A different user on the SAME browser (same uuid) gets nothing.
        $this->assertSame('', AuthController::getDeviceName(null, $uuid, 999999));
        // Unscoped lookup keeps the legacy behaviour (backward compatible).
        $this->assertSame('smartphone eric', AuthController::getDeviceName(null, $uuid));
    }

    /**
     * Test entity is returned correctly
     */
    public function testLoginReturnsCorrectEntity(): void
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0
        ];

        $result = $this->authController->login($payload);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(1, $result[0]['entity']);
    }
}
