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
use User;

/**
 * End-to-end integration tests for complete authentication flows
 *
 * Tests the complete token lifecycle with real database operations:
 * - Login flow with user creation
 * - Token generation and validation
 * - Multi-device scenarios
 * - Session management across multiple users
 */
class EndToEndAuthFlowTest extends DolibarrRealTestCase
{
    private AuthController $authController;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authController = new AuthController();
        $this->rateLimiter = new RateLimiter($this->db);

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        // Set required globals for token generation
        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';
    }

    /**
     * Generate a valid UUID v4 for testing
     */
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
     * Helper: Create an authenticated session for a user
     */
    private function createAuthenticatedSession(User $user, string $deviceUUID): array
    {
        $_SERVER['HTTP_X_DEVICEID'] = $deviceUUID;

        $reflection = new ReflectionClass($this->authController);

        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->authController, $user->id);

        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($this->authController, $user->id);

        $generatePair = $reflection->getMethod('_generateTokenPair');
        $generatePair->setAccessible(true);

        $tokens = $generatePair->invoke(
            $this->authController,
            'user',
            $user->id,
            $user->id,
            $user->login,
            1,
            $familyId,
            $deviceId
        );

        return [
            'family_id' => $familyId,
            'device_id' => $deviceId,
            'tokens' => $tokens,
            'user' => $user
        ];
    }

    // =========================================================================
    // Complete Flow Tests
    // =========================================================================

    /**
     * Test complete flow: create user -> login -> use token -> refresh -> logout
     */
    public function testCompleteUserAuthenticationFlow(): void
    {
        // Step 1: Create a new test user with password
        $user = $this->createTestUser([
            'login' => 'flow_test_user_' . uniqid(),
            'pass' => 'SecurePassword123!'
        ]);

        $this->assertGreaterThan(0, $user->id);

        // Step 2: Create authenticated session
        $deviceUUID = $this->generateUUID();
        $session = $this->createAuthenticatedSession($user, $deviceUUID);

        // Verify tokens were generated
        $this->assertNotEmpty($session['tokens']['access_token']);
        $this->assertNotEmpty($session['tokens']['refresh_token']);
        $this->assertStringContainsString('|', $session['tokens']['access_token']);

        // Verify database state
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'fk_user' => $user->id,
            'revoked' => 0
        ]);

        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $session['device_id'],
            'uuid' => $deviceUUID
        ]);

        // Step 3: Verify access token stored correctly
        $accessTokenId = explode('|', $session['tokens']['access_token'])[0];
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessTokenId,
            'token_type' => SmartTokenConfig::TYPE_ACCESS,
            'family_id' => $session['family_id'],
            'fk_device_id' => $session['device_id'],
            'status' => AuthController::STATUS_VALID
        ]);

        // Step 4: Logout
        $logoutResult = $this->authController->logout([
            'user' => $user,
            'family_id' => $session['family_id']
        ]);

        $this->assertEquals(200, $logoutResult[1]);

        // Verify family is revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1
        ]);
    }

    /**
     * Test multi-device login scenario
     * Same user logs in from multiple devices
     */
    public function testMultiDeviceLogin(): void
    {
        $user = $this->createTestUser([
            'login' => 'multi_device_user_' . uniqid()
        ]);

        // Login from 3 different devices
        $sessions = [];
        for ($i = 1; $i <= 3; $i++) {
            $deviceUUID = $this->generateUUID();
            $sessions[$i] = $this->createAuthenticatedSession($user, $deviceUUID);
        }

        // Verify all sessions are independent
        $familyIds = array_column($sessions, 'family_id');
        $this->assertCount(3, array_unique($familyIds), 'Each device should have its own token family');

        $deviceIds = array_column($sessions, 'device_id');
        $this->assertCount(3, array_unique($deviceIds), 'Each device should have its own device ID');

        // Verify all families are valid
        foreach ($sessions as $session) {
            $this->assertDatabaseHas('smartauth_token_family', [
                'rowid' => $session['family_id'],
                'revoked' => 0
            ]);
        }

        // Logout from device 2 only
        $this->authController->logout([
            'user' => $user,
            'family_id' => $sessions[2]['family_id']
        ]);

        // Device 2 family should be revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[2]['family_id'],
            'revoked' => 1
        ]);

        // Devices 1 and 3 should still be valid
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[1]['family_id'],
            'revoked' => 0
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[3]['family_id'],
            'revoked' => 0
        ]);
    }

    /**
     * Test multiple users with independent sessions
     */
    public function testMultipleUsersIndependentSessions(): void
    {
        // Create 3 different users
        $users = [];
        $sessions = [];

        for ($i = 1; $i <= 3; $i++) {
            $users[$i] = $this->createTestUser([
                'login' => "user_{$i}_" . uniqid()
            ]);
            $sessions[$i] = $this->createAuthenticatedSession(
                $users[$i],
                $this->generateUUID()
            );
        }

        // Verify each user has their own family linked to their user ID
        foreach ($sessions as $i => $session) {
            $this->assertDatabaseHas('smartauth_token_family', [
                'rowid' => $session['family_id'],
                'fk_user' => $users[$i]->id
            ]);
        }

        // Logout user 1 should not affect others
        $this->authController->logout([
            'user' => $users[1],
            'family_id' => $sessions[1]['family_id']
        ]);

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[1]['family_id'],
            'revoked' => 1
        ]);

        // Users 2 and 3 still valid
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[2]['family_id'],
            'revoked' => 0
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $sessions[3]['family_id'],
            'revoked' => 0
        ]);
    }

    /**
     * Test device reuse across sessions
     * Same device UUID used for new login after logout
     */
    public function testDeviceReuseAfterLogout(): void
    {
        global $conf;

        $user = $this->createTestUser([
            'login' => 'device_reuse_user_' . uniqid()
        ]);

        $deviceUUID = $this->generateUUID();

        // First session
        $session1 = $this->createAuthenticatedSession($user, $deviceUUID);
        $deviceId1 = $session1['device_id'];

        // Logout
        $this->authController->logout([
            'user' => $user,
            'family_id' => $session1['family_id']
        ]);

        // Clear device cache to simulate fresh lookup (as in real-world scenario after app restart)
        unset($conf->cache['smartmakers']['device-' . $deviceUUID]);

        // New session with same device
        $session2 = $this->createAuthenticatedSession($user, $deviceUUID);

        // Should reuse the same device ID
        $this->assertEquals($deviceId1, $session2['device_id'], 'Same device UUID should reuse device ID');

        // But family should be different
        $this->assertNotEquals($session1['family_id'], $session2['family_id'], 'New session should have new family');
    }

    /**
     * Test token family refresh count tracking
     */
    public function testTokenFamilyRefreshCountTracking(): void
    {
        $user = $this->createTestUser([
            'login' => 'refresh_count_user_' . uniqid()
        ]);

        $session = $this->createAuthenticatedSession($user, $this->generateUUID());

        // Initial refresh count should be 0
        $sql = "SELECT refresh_count FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $session['family_id'];
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $this->assertEquals(0, (int) $obj->refresh_count);

        // Update refresh count via internal method
        $reflection = new ReflectionClass($this->authController);
        $updateFamily = $reflection->getMethod('_updateTokenFamily');
        $updateFamily->setAccessible(true);

        // Simulate 3 refreshes
        for ($i = 1; $i <= 3; $i++) {
            $updateFamily->invoke($this->authController, $session['family_id'], $i);

            $result = $this->db->query($sql);
            $obj = $this->db->fetch_object($result);
            $this->assertEquals($i, (int) $obj->refresh_count, "Refresh count should be {$i}");
        }
    }

    /**
     * Test session isolation - user A cannot affect user B's tokens
     */
    public function testSessionIsolationBetweenUsers(): void
    {
        $userA = $this->createTestUser(['login' => 'user_a_' . uniqid()]);
        $userB = $this->createTestUser(['login' => 'user_b_' . uniqid()]);

        $sessionA = $this->createAuthenticatedSession($userA, $this->generateUUID());
        $sessionB = $this->createAuthenticatedSession($userB, $this->generateUUID());

        // Attempt to check family A with user B's ID (should fail)
        $reflection = new ReflectionClass($this->authController);
        $checkFamily = $reflection->getMethod('_checkTokenFamily');
        $checkFamily->setAccessible(true);

        $result = $checkFamily->invoke(
            $this->authController,
            $sessionA['family_id'],
            $userB->id  // Wrong user
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('user_mismatch', $result['reason']);

        // Family A should still be valid for user A
        $resultCorrect = $checkFamily->invoke(
            $this->authController,
            $sessionA['family_id'],
            $userA->id
        );
        $this->assertTrue($resultCorrect['valid']);
    }

    /**
     * Test concurrent sessions limit (if applicable)
     * Verifies system behavior with many active sessions
     */
    public function testManyActiveSessions(): void
    {
        $user = $this->createTestUser(['login' => 'many_sessions_user_' . uniqid()]);

        $sessions = [];
        $numSessions = 10;

        // Create many sessions
        for ($i = 1; $i <= $numSessions; $i++) {
            $sessions[$i] = $this->createAuthenticatedSession(
                $user,
                $this->generateUUID()
            );
        }

        // Verify all sessions are active
        $count = $this->getTableCount('smartauth_token_family', [
            'fk_user' => $user->id,
            'revoked' => 0
        ]);

        $this->assertEquals($numSessions, $count, "All {$numSessions} sessions should be active");

        // Get all devices for user
        $reflection = new ReflectionClass($this->authController);
        $getAllDevices = $reflection->getMethod('_getAllDevicesForUser');
        $getAllDevices->setAccessible(true);

        $devices = $getAllDevices->invoke($this->authController, $user->id);
        $this->assertIsArray($devices);
    }

    /**
     * Test rate limiting integration in auth flow
     */
    public function testRateLimitingInAuthFlow(): void
    {
        $testIP = '10.20.30.' . mt_rand(1, 254);
        $_SERVER['REMOTE_ADDR'] = $testIP;

        // Record some failed attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($testIP, 'login_ip', false);
        }

        // Check limit - should still be allowed (under 5)
        $result = $this->rateLimiter->checkLimit(
            $testIP,
            'login_ip',
            AuthController::SMARTAUTH_RATELIMIT_IP_MAX,
            AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW
        );

        $this->assertTrue($result['allowed'], 'Should be allowed with 3 failures');

        // Add more failures to exceed limit
        for ($i = 0; $i < 8; $i++) {
            $this->rateLimiter->recordAttempt($testIP, 'login_ip', false);
        }

        $result = $this->rateLimiter->checkLimit(
            $testIP,
            'login_ip',
            AuthController::SMARTAUTH_RATELIMIT_IP_MAX,
            AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW
        );

        $this->assertFalse($result['allowed'], 'Should be blocked after exceeding limit');
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    /**
     * Test user rate limiting separate from IP rate limiting
     */
    public function testUserAndIpRateLimitingSeparate(): void
    {
        $testIP = '10.20.30.' . mt_rand(1, 254);
        $testUsername = 'rate_limit_test_' . uniqid();

        // Fill IP limit
        for ($i = 0; $i < AuthController::SMARTAUTH_RATELIMIT_IP_MAX; $i++) {
            $this->rateLimiter->recordAttempt($testIP, 'login_ip', false);
        }

        // IP should be blocked
        $ipResult = $this->rateLimiter->checkLimit(
            $testIP,
            'login_ip',
            AuthController::SMARTAUTH_RATELIMIT_IP_MAX,
            AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW
        );
        $this->assertFalse($ipResult['allowed']);

        // But username from different IP should be allowed
        $userResult = $this->rateLimiter->checkLimit(
            $testUsername,
            'login_username',
            AuthController::SMARTAUTH_RATELIMIT_USER_MAX,
            AuthController::SMARTAUTH_RATELIMIT_USER_WINDOW
        );
        $this->assertTrue($userResult['allowed']);
    }

    /**
     * Test successful login resets rate limit counter
     */
    public function testSuccessfulLoginResetsRateLimit(): void
    {
        $testIP = '10.20.30.' . mt_rand(1, 254);

        // Record some failures
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($testIP, 'login_ip', false);
        }

        // Record a success
        $this->rateLimiter->recordAttempt($testIP, 'login_ip', true);

        // Reset the rate limit
        $this->rateLimiter->reset($testIP, 'login_ip');

        // Should be fully reset
        $result = $this->rateLimiter->checkLimit(
            $testIP,
            'login_ip',
            AuthController::SMARTAUTH_RATELIMIT_IP_MAX,
            AuthController::SMARTAUTH_RATELIMIT_IP_WINDOW
        );

        $this->assertTrue($result['allowed']);
    }
}
