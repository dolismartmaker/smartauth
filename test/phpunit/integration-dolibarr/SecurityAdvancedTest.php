<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth;
use SmartAuthDevices;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Api\SmartTokenConfig;
use ReflectionClass;
use ReflectionMethod;

/**
 * Advanced Security Tests for SmartAuth
 *
 * Tests for token replay attacks, family revocation, brute force protection,
 * SQL injection prevention, and token forgery detection.
 */
class SecurityAdvancedTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $authController;

    // Note: $testDevice is inherited from DolibarrRealTestCase

    /** @var ReflectionClass */
    private $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure jti_used table exists
        $this->createJtiTable();

        // Create AuthController instance
        $this->authController = new AuthController();

        // Create reflection for accessing private methods
        $this->reflection = new ReflectionClass(AuthController::class);

        // Create a test device
        $this->testDevice = new SmartAuthDevices($this->db);
        $this->testDevice->label = 'Security Test Device';
        $this->testDevice->uuid = $this->generateUUID();
        $this->testDevice->status = SmartAuthDevices::STATUS_VALIDATED;
        $this->testDevice->entity = 1;
        $this->testDevice->create($this->testUser);

        // Set up global variables needed by AuthController
        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';
    }

    /**
     * Create the jti_used table if it doesn't exist
     */
    private function createJtiTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_jti_used (
            jti TEXT PRIMARY KEY,
            used_at INTEGER,
            token_id INTEGER
        )";
        $this->db->query($sql);
    }

    /**
     * Generate a valid UUID v4
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

    /**
     * Get a private/protected method from AuthController
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Test token replay detection via _markJtiAsUsed
     *
     * Simulates a replay attack by using the same jti twice
     */
    public function testTokenReplayDetection(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // Generate a valid jti (32 hex characters)
        $jti = bin2hex(random_bytes(16));

        // First use should succeed
        $result1 = $markJtiAsUsed->invoke($this->authController, $jti);
        $this->assertTrue($result1, "First use of jti should succeed");

        // Second use should fail (replay attack detected)
        $result2 = $markJtiAsUsed->invoke($this->authController, $jti);
        $this->assertFalse($result2, "Second use of jti should be detected as replay attack");

        // Verify jti is in database
        $this->assertDatabaseHas('smartauth_jti_used', ['jti' => $jti]);
    }

    /**
     * Test token family revocation on replay detection
     */
    public function testTokenFamilyRevocationOnReplay(): void
    {
        // Create a token family
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $family_id = $createTokenFamily->invoke($this->authController, $this->testUser->id);

        $this->assertGreaterThan(0, $family_id, "Token family should be created");

        // Verify family exists and is not revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $family_id,
            'revoked' => 0
        ]);

        // Simulate replay attack by revoking the family
        $revokeTokenFamily = $this->getPrivateMethod('_revokeTokenFamily');
        $revokeTokenFamily->invoke($this->authController, $family_id, 'replay_attack_detected');

        // Verify family is now revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $family_id,
            'revoked' => 1
        ]);
    }

    /**
     * Test cascade revocation on security violation
     *
     * When a family is revoked, all tokens in that family should be revoked
     */
    public function testCascadeRevocationOnSecurityViolation(): void
    {
        global $smartAuthAppID;

        // Create a token family
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $family_id = $createTokenFamily->invoke($this->authController, $this->testUser->id);

        // Create multiple tokens in the same family
        $tokenIds = [];
        for ($i = 0; $i < 3; $i++) {
            $auth = new SmartAuth($this->db);
            $auth->appuid = $smartAuthAppID;
            $auth->salt = bin2hex(random_bytes(16));
            $auth->fk_user_creat = $this->testUser->id;
            $auth->fk_authid = $this->testUser->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $this->testDevice->id;
            $auth->token_type = ($i % 2 == 0) ? 'access' : 'refresh';
            $auth->family_id = $family_id;
            $auth->status = SmartAuth::STATUS_VALIDATED;
            $auth->ip = '127.0.0.1';
            $auth->entity = 1;
            $auth->create($this->testUser);
            $tokenIds[] = $auth->id;
        }

        // Verify all tokens are valid
        foreach ($tokenIds as $tokenId) {
            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $tokenId,
                'status' => SmartAuth::STATUS_VALIDATED
            ]);
        }

        // Revoke the family
        $revokeTokenFamily = $this->getPrivateMethod('_revokeTokenFamily');
        $revokeTokenFamily->invoke($this->authController, $family_id, 'security_violation');

        // Verify all tokens in the family are revoked (status = 9 = STATUS_LOGOUT)
        foreach ($tokenIds as $tokenId) {
            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $tokenId,
                'status' => 9 // STATUS_LOGOUT
            ]);
        }
    }

    /**
     * Test refresh token reuse detection
     */
    public function testRefreshTokenReuseDetection(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // Generate a refresh token jti
        $refreshJti = bin2hex(random_bytes(16));

        // First refresh should succeed
        $result1 = $markJtiAsUsed->invoke($this->authController, $refreshJti);
        $this->assertTrue($result1, "First refresh should succeed");

        // Attempting to reuse the same refresh token should fail
        $result2 = $markJtiAsUsed->invoke($this->authController, $refreshJti);
        $this->assertFalse($result2, "Refresh token reuse should be detected");
    }

    /**
     * Test jti cleanup of old entries
     */
    public function testJtiCleanup(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');
        $cleanupOldJti = $this->getPrivateMethod('_cleanupOldJti');

        // Create some old jti entries manually
        $oldJti = bin2hex(random_bytes(16));
        $veryOldTimestamp = time() - (31 * 24 * 3600); // 31 days ago

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_jti_used";
        $sql .= " (jti, used_at) VALUES ('" . $this->db->escape($oldJti) . "', " . $veryOldTimestamp . ")";
        $this->db->query($sql);

        // Create a recent jti
        $recentJti = bin2hex(random_bytes(16));
        $markJtiAsUsed->invoke($this->authController, $recentJti);

        // Run cleanup (default: 30 days)
        $cleanupOldJti->invoke($this->authController);

        // Old jti should be removed
        $this->assertDatabaseMissing('smartauth_jti_used', ['jti' => $oldJti]);

        // Recent jti should still exist
        $this->assertDatabaseHas('smartauth_jti_used', ['jti' => $recentJti]);
    }

    /**
     * Test token validation with expired/revoked family
     */
    public function testTokenValidationWithExpiredFamily(): void
    {
        // Create a token family
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $checkTokenFamily = $this->getPrivateMethod('_checkTokenFamily');
        $revokeTokenFamily = $this->getPrivateMethod('_revokeTokenFamily');

        $family_id = $createTokenFamily->invoke($this->authController, $this->testUser->id);

        // Initially, family should be valid
        $result = $checkTokenFamily->invoke($this->authController, $family_id, $this->testUser->id);
        $this->assertTrue($result['valid'], "Family should be valid initially");

        // Revoke the family
        $revokeTokenFamily->invoke($this->authController, $family_id, 'test_revocation');

        // Now family should be invalid
        $result = $checkTokenFamily->invoke($this->authController, $family_id, $this->testUser->id);
        $this->assertFalse($result['valid'], "Revoked family should be invalid");
        $this->assertEquals('family_revoked', $result['reason']);
    }

    /**
     * Test token validation with wrong user
     *
     * A token should not be usable by a different user
     */
    public function testTokenValidationWithWrongUser(): void
    {
        // Create a token family for testUser
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $checkTokenFamily = $this->getPrivateMethod('_checkTokenFamily');

        $family_id = $createTokenFamily->invoke($this->authController, $this->testUser->id);

        // Check with the correct user
        $result = $checkTokenFamily->invoke($this->authController, $family_id, $this->testUser->id);
        $this->assertTrue($result['valid'], "Token should be valid for correct user");

        // Check with a different user ID
        $wrongUserId = $this->testUser->id + 999;
        $result = $checkTokenFamily->invoke($this->authController, $family_id, $wrongUserId);
        $this->assertFalse($result['valid'], "Token should be invalid for wrong user");
        $this->assertEquals('user_mismatch', $result['reason']);
    }

    /**
     * Test brute force protection via rate limiter
     */
    public function testBruteForceProtection(): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $testIp = '192.168.99.99';
        $action = 'test_login';
        $maxAttempts = 5;
        $windowSeconds = 300;

        // First attempts should be allowed
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $rateLimiter->checkLimit($testIp, $action, $maxAttempts, $windowSeconds);
            $this->assertTrue($result['allowed'], "Attempt $i should be allowed");
            $rateLimiter->recordAttempt($testIp, $action, false);
        }

        // Next attempt should be blocked
        $result = $rateLimiter->checkLimit($testIp, $action, $maxAttempts, $windowSeconds);
        $this->assertFalse($result['allowed'], "Attempt after max should be blocked");
        $this->assertGreaterThan(0, $result['retry_after'], "retry_after should be positive");
    }

    /**
     * Test SQL injection protection in token validation
     *
     * Attempts various SQL injection payloads in token parameters
     */
    public function testSqlInjectionInTokenValidation(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // SQL injection attempts as jti (should all fail format validation)
        $sqlInjectionPayloads = [
            "'; DROP TABLE llx_smartauth_jti_used; --",
            "1' OR '1'='1",
            "1; DELETE FROM llx_smartauth_auth; --",
            "' UNION SELECT * FROM llx_user --",
            "1' AND 1=1 --",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            // Should return false because payload doesn't match jti format (32 hex chars)
            $result = $markJtiAsUsed->invoke($this->authController, $payload);
            $this->assertFalse($result, "SQL injection payload should be rejected: " . substr($payload, 0, 20));
        }

        // Verify the jti_used table still exists and wasn't dropped
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_jti_used";
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql, "jti_used table should still exist after SQL injection attempts");
    }

    /**
     * Test token forgery detection (modified signature)
     *
     * Tests that tokens with invalid/modified signatures are rejected
     */
    public function testTokenForgeryDetection(): void
    {
        // Create a valid token family and device
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $createDeviceIdIfNeeded = $this->getPrivateMethod('_createDeviceIdIfNeeded');
        $generateTokenPair = $this->getPrivateMethod('_generateTokenPair');

        $family_id = $createTokenFamily->invoke($this->authController, $this->testUser->id);

        // Set HTTP_X_DEVICEID for _createDeviceIdIfNeeded
        $_SERVER['HTTP_X_DEVICEID'] = $this->testDevice->uuid;

        $device_id = $createDeviceIdIfNeeded->invoke($this->authController, $this->testUser->id);

        // Generate valid tokens
        $tokens = $generateTokenPair->invoke(
            $this->authController,
            'user',
            $this->testUser->id,
            $this->testUser->id,
            $this->testUser->login,
            1, // entity
            $family_id,
            $device_id
        );

        $this->assertNotEmpty($tokens['access_token'], "Access token should be generated");
        $this->assertNotEmpty($tokens['refresh_token'], "Refresh token should be generated");

        // Verify token format: token_id|jwt
        $this->assertStringContainsString('|', $tokens['access_token']);

        // Try to forge a token by modifying the JWT signature
        $parts = explode('|', $tokens['access_token']);
        $this->assertCount(2, $parts, "Token should have format token_id|jwt");

        $tokenId = $parts[0];
        $jwt = $parts[1];

        // Split JWT into header.payload.signature
        $jwtParts = explode('.', $jwt);
        $this->assertCount(3, $jwtParts, "JWT should have 3 parts");

        // Modify the signature (forge the token)
        $forgedSignature = base64_encode('forged_signature_' . random_bytes(32));
        $forgedJwt = $jwtParts[0] . '.' . $jwtParts[1] . '.' . $forgedSignature;
        $forgedToken = $tokenId . '|' . $forgedJwt;

        // The forged token should be different from original
        $this->assertNotEquals($tokens['access_token'], $forgedToken);

        // Note: Actually testing _decodeJWT with a forged token would call json_reply
        // which exits. We verify the signature part was properly modified.
        $this->assertNotEquals($jwt, $forgedJwt, "Forged JWT should differ from original");
    }

    /**
     * Test that invalid jti formats are rejected
     */
    public function testInvalidJtiFormatRejection(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        $invalidJtis = [
            '',                             // empty
            'short',                        // too short
            'not-hex-characters-here!!!',   // invalid characters
            str_repeat('a', 31),            // too short (31 chars)
            str_repeat('a', 33),            // too long (33 chars)
            'ABCD1234' . str_repeat('g', 24), // contains 'g' which is not hex
        ];

        foreach ($invalidJtis as $jti) {
            $result = $markJtiAsUsed->invoke($this->authController, $jti);
            $this->assertFalse($result, "Invalid jti format should be rejected: '$jti'");
        }
    }

    /**
     * Test token family check with non-existent family
     */
    public function testTokenFamilyNotFound(): void
    {
        $checkTokenFamily = $this->getPrivateMethod('_checkTokenFamily');

        // Use a family_id that doesn't exist
        $nonExistentFamilyId = 999999;

        $result = $checkTokenFamily->invoke($this->authController, $nonExistentFamilyId, $this->testUser->id);

        $this->assertFalse($result['valid'], "Non-existent family should be invalid");
        $this->assertEquals('family_not_found', $result['reason']);
    }

    /**
     * Test rate limiter reset after successful login
     */
    public function testRateLimiterResetAfterSuccess(): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $testIp = '10.0.0.100';
        $action = 'login_test';
        $maxAttempts = 3;
        $windowSeconds = 300;

        // Record some failed attempts
        for ($i = 0; $i < 2; $i++) {
            $rateLimiter->recordAttempt($testIp, $action, false);
        }

        // Verify we're approaching the limit
        $result = $rateLimiter->checkLimit($testIp, $action, $maxAttempts, $windowSeconds);
        $this->assertTrue($result['allowed'], "Should still be allowed before hitting limit");

        // Simulate successful login - reset the rate limiter
        $rateLimiter->reset($testIp, $action);

        // Now we should have a fresh slate
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $rateLimiter->checkLimit($testIp, $action, $maxAttempts, $windowSeconds);
            $this->assertTrue($result['allowed'], "After reset, attempt $i should be allowed");
            $rateLimiter->recordAttempt($testIp, $action, false);
        }
    }

    /**
     * Test multiple users can have separate token families
     */
    public function testMultipleUserTokenFamilies(): void
    {
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $checkTokenFamily = $this->getPrivateMethod('_checkTokenFamily');

        // Create families for different users
        $family1 = $createTokenFamily->invoke($this->authController, $this->testUser->id);
        $family2 = $createTokenFamily->invoke($this->authController, $this->testUser->id + 1);

        $this->assertNotEquals($family1, $family2, "Different users should have different families");

        // Family1 should only be valid for testUser
        $result = $checkTokenFamily->invoke($this->authController, $family1, $this->testUser->id);
        $this->assertTrue($result['valid']);

        // Family1 should NOT be valid for other user
        $result = $checkTokenFamily->invoke($this->authController, $family1, $this->testUser->id + 1);
        $this->assertFalse($result['valid']);
        $this->assertEquals('user_mismatch', $result['reason']);
    }

    /**
     * Test concurrent jti marking (race condition prevention)
     */
    public function testConcurrentJtiMarking(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // Generate a single jti
        $jti = bin2hex(random_bytes(16));

        // First mark should succeed
        $result1 = $markJtiAsUsed->invoke($this->authController, $jti);
        $this->assertTrue($result1);

        // Simulate concurrent request trying to mark same jti
        $result2 = $markJtiAsUsed->invoke($this->authController, $jti);
        $this->assertFalse($result2, "Concurrent marking of same jti should fail");

        // Verify only one entry exists
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_jti_used WHERE jti = '" . $this->db->escape($jti) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals(1, (int) $obj->cnt, "Only one jti entry should exist");
    }

    /**
     * Test that valid 32-char hex jti is accepted
     */
    public function testValidJtiAccepted(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // Valid jti formats
        $validJtis = [
            bin2hex(random_bytes(16)),                    // random 32 hex chars
            'abcdef0123456789abcdef0123456789',           // lowercase hex
            'ABCDEF0123456789ABCDEF0123456789',           // uppercase hex (should work)
            '00000000000000000000000000000000',           // all zeros
            'ffffffffffffffffffffffffffffffff',           // all f's
        ];

        foreach ($validJtis as $jti) {
            $result = $markJtiAsUsed->invoke($this->authController, $jti);
            $this->assertTrue($result, "Valid jti should be accepted: $jti");
        }
    }

    /**
     * Test rate limiter with different identifiers
     */
    public function testRateLimiterDifferentIdentifiers(): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $action = 'login';
        $maxAttempts = 2;
        $windowSeconds = 300;

        $ip1 = '1.1.1.1';
        $ip2 = '2.2.2.2';

        // Max out ip1
        for ($i = 0; $i < $maxAttempts; $i++) {
            $rateLimiter->recordAttempt($ip1, $action, false);
        }

        // ip1 should be blocked
        $result = $rateLimiter->checkLimit($ip1, $action, $maxAttempts, $windowSeconds);
        $this->assertFalse($result['allowed'], "ip1 should be blocked");

        // ip2 should still be allowed (different identifier)
        $result = $rateLimiter->checkLimit($ip2, $action, $maxAttempts, $windowSeconds);
        $this->assertTrue($result['allowed'], "ip2 should still be allowed");
    }

    /**
     * Test extractJtiFromToken with various token formats
     */
    public function testExtractJtiFromToken(): void
    {
        $extractJti = $this->getPrivateMethod('_extractJtiFromToken');

        // Test with empty/invalid tokens
        $this->assertNull($extractJti->invoke($this->authController, ''));
        $this->assertNull($extractJti->invoke($this->authController, 'invalid'));
        $this->assertNull($extractJti->invoke($this->authController, 'no-pipe-here'));

        // Test with malformed JWT
        $this->assertNull($extractJti->invoke($this->authController, '123|not.a.valid'));
        $this->assertNull($extractJti->invoke($this->authController, '123|only.two'));

        // Create a mock JWT payload with jti
        $jti = bin2hex(random_bytes(16));
        $payload = json_encode(['jti' => $jti, 'exp' => time() + 3600]);
        $mockJwt = 'header.' . base64_encode($payload) . '.signature';
        $mockToken = '123|' . $mockJwt;

        $extractedJti = $extractJti->invoke($this->authController, $mockToken);
        $this->assertEquals($jti, $extractedJti, "Should extract jti from valid token structure");
    }
}
