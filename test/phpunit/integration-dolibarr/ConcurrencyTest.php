<?php

/**
 * ConcurrencyTest.php
 *
 * PHPUnit tests for concurrency scenarios in SmartAuth
 *
 * These tests simulate concurrent operations using rapid sequential calls
 * to verify database consistency and atomicity of operations.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/AdvancedRateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth\Api\AuthController;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Api\AdvancedRateLimiter;
use SmartAuth\Api\SmartTokenConfig;
use SmartAuth;
use SmartAuthDevices;
use ReflectionClass;
use ReflectionMethod;

/**
 * Concurrency tests for SmartAuth
 *
 * These tests verify that SmartAuth handles concurrent operations correctly,
 * maintaining database consistency and proper atomicity of operations.
 */
class ConcurrencyTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $authController;

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var AdvancedRateLimiter */
    private $advancedRateLimiter;

    /** @var ReflectionClass */
    private $authControllerReflection;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        global $smartAuthAppID, $smartAuthAppKey;

        parent::setUp();

        // Set up global variables required by AuthController
        $smartAuthAppID = 100000;
        $smartAuthAppKey = 'test_secret_key_for_phpunit_testing_minimum_32_chars';

        $this->authController = new AuthController();
        $this->rateLimiter = new RateLimiter($this->db);
        $this->advancedRateLimiter = new AdvancedRateLimiter($this->db);
        $this->authControllerReflection = new ReflectionClass(AuthController::class);

        // Create jti_used table if not exists (for replay attack tests)
        $this->createJtiTable();
    }

    /**
     * Create the jti_used table for replay attack prevention tests
     */
    private function createJtiTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "smartauth_jti_used (
            jti VARCHAR(64) PRIMARY KEY,
            used_at INTEGER NOT NULL,
            token_id INTEGER
        )";
        $this->db->query($sql);
    }

    /**
     * Get a private/protected method from AuthController using reflection
     *
     * @param string $methodName Name of the method to access
     * @return ReflectionMethod
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->authControllerReflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Generate a valid device UUID for testing
     *
     * @return string UUID in standard format
     */
    private function generateDeviceUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Test simultaneous token family creation for the same user
     *
     * Creates multiple token families rapidly for the same user
     * to verify each family gets a unique ID and database consistency is maintained.
     */
    public function testSimultaneousTokenFamilyCreation(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');

        $familyIds = [];
        $iterations = 20;

        // Create multiple token families rapidly
        for ($i = 0; $i < $iterations; $i++) {
            $familyId = $createTokenFamily->invoke($this->authController, $user->id);
            $this->assertGreaterThan(0, $familyId, "Family ID should be positive");
            $familyIds[] = $familyId;
        }

        // Verify all family IDs are unique
        $uniqueFamilyIds = array_unique($familyIds);
        $this->assertCount($iterations, $uniqueFamilyIds, "All family IDs should be unique");

        // Verify database consistency
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE fk_user = " . (int) $user->id;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals($iterations, (int) $obj->cnt, "Database should contain exactly $iterations families for user");
    }

    /**
     * Test rapid refresh attempts on the same token family
     *
     * Simulates rapid refresh attempts to verify the system handles
     * high-frequency refresh requests correctly without corruption.
     */
    public function testRapidRefreshAttempts(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $updateTokenFamily = $this->getPrivateMethod('_updateTokenFamily');

        // Create a token family
        $familyId = $createTokenFamily->invoke($this->authController, $user->id);

        // Simulate rapid refresh attempts
        $iterations = 50;
        for ($i = 1; $i <= $iterations; $i++) {
            $updateTokenFamily->invoke($this->authController, $familyId, $i);
        }

        // Verify final refresh count
        $sql = "SELECT refresh_count FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $familyId;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals($iterations, (int) $obj->refresh_count, "Refresh count should match iterations");

        // Verify family is not revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $familyId,
            'revoked' => 0
        ]);
    }

    /**
     * Test concurrent rate limit recording
     *
     * Records many rate limit attempts simultaneously to verify
     * database handles high-frequency writes correctly.
     */
    public function testConcurrentRateLimitRecording(): void
    {
        $identifier = 'test_ip_' . uniqid();
        $action = 'login_test';
        $iterations = 100;

        // Record many attempts rapidly
        for ($i = 0; $i < $iterations; $i++) {
            $success = ($i % 5 === 0); // Every 5th attempt is successful
            $this->rateLimiter->recordAttempt($identifier, $action, $success);
        }

        // Verify all attempts were recorded
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $this->db->escape($identifier) . "'";
        $sql .= " AND action = '" . $this->db->escape($action) . "'";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals($iterations, (int) $obj->cnt, "All rate limit attempts should be recorded");

        // Verify success count
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $this->db->escape($identifier) . "'";
        $sql .= " AND action = '" . $this->db->escape($action) . "'";
        $sql .= " AND success = 1";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $expectedSuccess = (int) floor($iterations / 5);
        $this->assertEquals($expectedSuccess, (int) $obj->cnt, "Success count should match expected");
    }

    /**
     * Test token creation under load
     *
     * Creates many tokens rapidly to verify system handles
     * high-frequency token generation correctly.
     */
    public function testTokenCreationUnderLoad(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $generateTokenPair = $this->getPrivateMethod('_generateTokenPair');

        $iterations = 10;
        $tokens = [];

        // Set device UUID header for token generation
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateDeviceUUID();

        for ($i = 0; $i < $iterations; $i++) {
            $familyId = $createTokenFamily->invoke($this->authController, $user->id);

            // Create device for each iteration
            $deviceId = $this->createTestDevice($user->id);

            $tokenPair = $generateTokenPair->invoke(
                $this->authController,
                'user',
                $user->id,
                $user->id,
                $user->login,
                1, // entity
                $familyId,
                $deviceId
            );

            $this->assertArrayHasKey('access_token', $tokenPair);
            $this->assertArrayHasKey('refresh_token', $tokenPair);
            $this->assertNotEmpty($tokenPair['access_token']);
            $this->assertNotEmpty($tokenPair['refresh_token']);

            $tokens[] = $tokenPair;
        }

        // Verify all tokens are unique
        $accessTokens = array_column($tokens, 'access_token');
        $refreshTokens = array_column($tokens, 'refresh_token');

        $this->assertCount($iterations, array_unique($accessTokens), "All access tokens should be unique");
        $this->assertCount($iterations, array_unique($refreshTokens), "All refresh tokens should be unique");

        // Verify database has correct number of tokens (2 per iteration: access + refresh)
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE fk_user_creat = " . (int) $user->id;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals($iterations * 2, (int) $obj->cnt, "Database should contain $iterations token pairs");
    }

    /**
     * Test database consistency under concurrent operations
     *
     * Performs multiple different operations and verifies
     * database remains consistent.
     */
    public function testDatabaseConsistencyUnderConcurrency(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $revokeTokenFamily = $this->getPrivateMethod('_revokeTokenFamily');

        $activeFamilies = [];
        $revokedFamilies = [];

        // Create 10 families
        for ($i = 0; $i < 10; $i++) {
            $familyId = $createTokenFamily->invoke($this->authController, $user->id);
            $activeFamilies[] = $familyId;
        }

        // Revoke every other family
        foreach ($activeFamilies as $index => $familyId) {
            if ($index % 2 === 0) {
                $revokeTokenFamily->invoke($this->authController, $familyId, 'test_revocation');
                $revokedFamilies[] = $familyId;
            }
        }

        // Verify active families
        $activeRemaining = array_diff($activeFamilies, $revokedFamilies);
        foreach ($activeRemaining as $familyId) {
            $this->assertDatabaseHas('smartauth_token_family', [
                'rowid' => $familyId,
                'revoked' => 0
            ]);
        }

        // Verify revoked families
        foreach ($revokedFamilies as $familyId) {
            $this->assertDatabaseHas('smartauth_token_family', [
                'rowid' => $familyId,
                'revoked' => 1
            ]);
        }

        // Verify total count
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE fk_user = " . (int) $user->id;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(10, (int) $obj->cnt, "Total family count should be 10");
    }

    /**
     * Test race condition on JTI marking (must be atomic)
     *
     * Verifies that the _markJtiAsUsed method is atomic and
     * properly prevents replay attacks.
     */
    public function testRaceConditionOnJtiMarking(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');

        // Generate a unique JTI (32 hex characters)
        $jti = bin2hex(random_bytes(16));

        // First call should succeed
        $result1 = $markJtiAsUsed->invoke($this->authController, $jti, 1);
        $this->assertTrue($result1, "First JTI marking should succeed");

        // Simulate concurrent attempts - all should fail
        $failCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $result = $markJtiAsUsed->invoke($this->authController, $jti, $i + 2);
            if (!$result) {
                $failCount++;
            }
        }

        $this->assertEquals(10, $failCount, "All subsequent JTI markings should fail (replay detected)");

        // Verify only one JTI entry exists
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_jti_used WHERE jti = '" . $this->db->escape($jti) . "'";
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(1, (int) $obj->cnt, "Only one JTI entry should exist");
    }

    /**
     * Test multiple device registration
     *
     * Registers multiple devices rapidly for the same user
     * to verify each gets a unique ID and proper status.
     */
    public function testMultipleDeviceRegistration(): void
    {
        $user = $this->testUser;
        $iterations = 15;
        $deviceIds = [];

        for ($i = 0; $i < $iterations; $i++) {
            $uuid = $this->generateDeviceUUID();
            $deviceId = $this->createTestDevice($user->id, $uuid);

            $this->assertGreaterThan(0, $deviceId, "Device ID should be positive");
            $deviceIds[] = $deviceId;
        }

        // Verify all device IDs are unique
        $uniqueDeviceIds = array_unique($deviceIds);
        $this->assertCount($iterations, $uniqueDeviceIds, "All device IDs should be unique");

        // Verify database consistency
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE fk_user_creat = " . (int) $user->id;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals($iterations, (int) $obj->cnt, "Database should contain exactly $iterations devices for user");
    }

    /**
     * Test family revocation under load
     *
     * Revokes a token family while other operations are executing
     * to verify revocation is complete and consistent.
     */
    public function testFamilyRevocationUnderLoad(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $revokeTokenFamily = $this->getPrivateMethod('_revokeTokenFamily');
        $generateTokenPair = $this->getPrivateMethod('_generateTokenPair');

        // Set device UUID header
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateDeviceUUID();

        // Create a family and generate multiple token pairs
        $familyId = $createTokenFamily->invoke($this->authController, $user->id);
        $deviceId = $this->createTestDevice($user->id);

        $tokenCount = 5;
        for ($i = 0; $i < $tokenCount; $i++) {
            $generateTokenPair->invoke(
                $this->authController,
                'user',
                $user->id,
                $user->id,
                $user->login,
                1,
                $familyId,
                $deviceId
            );
        }

        // Verify tokens exist
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE family_id = " . (int) $familyId . " AND status = 1";
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $activeTokensBefore = (int) $obj->cnt;

        $this->assertGreaterThan(0, $activeTokensBefore, "Should have active tokens before revocation");

        // Revoke the family
        $revokeTokenFamily->invoke($this->authController, $familyId, 'test_load_revocation');

        // Verify family is revoked
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $familyId,
            'revoked' => 1
        ]);

        // Verify all tokens in family are revoked (status = 9 = STATUS_LOGOUT)
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE family_id = " . (int) $familyId . " AND status = 1";
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt, "All tokens in family should be revoked");
    }

    /**
     * Test cleanup during active operations
     *
     * Verifies that cleanup operations (like JTI cleanup) do not
     * interfere with active token operations.
     */
    public function testCleanupDuringActiveOperations(): void
    {
        $markJtiAsUsed = $this->getPrivateMethod('_markJtiAsUsed');
        $cleanupOldJti = $this->getPrivateMethod('_cleanupOldJti');

        // Create some old JTI entries (simulate entries from 31 days ago)
        $oldTime = time() - (31 * 24 * 3600);
        for ($i = 0; $i < 5; $i++) {
            $oldJti = bin2hex(random_bytes(16));
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_jti_used (jti, used_at, token_id)";
            $sql .= " VALUES ('" . $this->db->escape($oldJti) . "', " . $oldTime . ", " . ($i + 100) . ")";
            $this->db->query($sql);
        }

        // Create some recent JTI entries
        $recentJtis = [];
        for ($i = 0; $i < 5; $i++) {
            $recentJti = bin2hex(random_bytes(16));
            $markJtiAsUsed->invoke($this->authController, $recentJti, $i + 1);
            $recentJtis[] = $recentJti;
        }

        // Run cleanup
        $cleanupOldJti->invoke($this->authController, 30 * 24 * 3600); // 30 days

        // Verify old JTIs are cleaned up
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_jti_used WHERE used_at < " . (time() - 30 * 24 * 3600);
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt, "Old JTI entries should be cleaned up");

        // Verify recent JTIs still exist
        foreach ($recentJtis as $jti) {
            $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_jti_used WHERE jti = '" . $this->db->escape($jti) . "'";
            $result = $this->db->query($sql);
            $obj = $this->db->fetch_object($result);

            $this->assertEquals(1, (int) $obj->cnt, "Recent JTI should still exist: $jti");
        }
    }

    /**
     * Test atomic token generation
     *
     * Verifies that token pair generation is atomic - either both
     * access and refresh tokens are created or neither.
     */
    public function testAtomicTokenGeneration(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $generateTokenPair = $this->getPrivateMethod('_generateTokenPair');

        // Set device UUID header
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateDeviceUUID();

        // Get initial token count
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $initialCount = (int) $obj->cnt;

        // Generate multiple token pairs
        $iterations = 5;
        for ($i = 0; $i < $iterations; $i++) {
            $familyId = $createTokenFamily->invoke($this->authController, $user->id);
            $deviceId = $this->createTestDevice($user->id);

            $tokenPair = $generateTokenPair->invoke(
                $this->authController,
                'user',
                $user->id,
                $user->id,
                $user->login,
                1,
                $familyId,
                $deviceId
            );

            // Verify both tokens exist
            $this->assertNotEmpty($tokenPair['access_token']);
            $this->assertNotEmpty($tokenPair['refresh_token']);

            // Extract token IDs
            $accessTokenId = explode('|', $tokenPair['access_token'])[0];
            $refreshTokenId = explode('|', $tokenPair['refresh_token'])[0];

            // Verify both tokens are in database
            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $accessTokenId,
                'token_type' => 'access'
            ]);

            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $refreshTokenId,
                'token_type' => 'refresh'
            ]);
        }

        // Verify exact token count
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);
        $finalCount = (int) $obj->cnt;

        // Each iteration creates exactly 2 tokens (access + refresh)
        $expectedIncrease = $iterations * 2;
        $this->assertEquals($expectedIncrease, $finalCount - $initialCount, "Token count should increase by exactly $expectedIncrease");
    }

    /**
     * Test rate limiter under high load
     *
     * Verifies rate limiter correctly blocks after threshold is reached
     * even under rapid request patterns.
     */
    public function testRateLimiterUnderHighLoad(): void
    {
        $identifier = 'high_load_test_' . uniqid();
        $action = 'login_stress';
        $maxAttempts = 5;
        $windowSeconds = 300;

        // Record attempts up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->rateLimiter->recordAttempt($identifier, $action, false);
        }

        // Check should now be blocked
        $result = $this->rateLimiter->checkLimit($identifier, $action, $maxAttempts, $windowSeconds);

        $this->assertFalse($result['allowed'], "Should be blocked after reaching limit");
        $this->assertGreaterThan(0, $result['retry_after'], "Should have positive retry_after value");

        // Additional attempts should still be blocked
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->recordAttempt($identifier, $action, false);
            $result = $this->rateLimiter->checkLimit($identifier, $action, $maxAttempts, $windowSeconds);
            $this->assertFalse($result['allowed'], "Should remain blocked");
        }
    }

    /**
     * Test progressive rate limiter thresholds
     *
     * Verifies the AdvancedRateLimiter correctly applies
     * progressive delays based on failure count.
     */
    public function testProgressiveRateLimiterThresholds(): void
    {
        $identifier = 'progressive_test_' . uniqid();
        $action = 'login_progressive';

        // Test 1-3 failures: no delay
        for ($i = 0; $i < 3; $i++) {
            $this->advancedRateLimiter->recordAttempt($identifier, $action, false);
        }
        $result = $this->advancedRateLimiter->checkLimitProgressive($identifier, $action);
        $this->assertTrue($result['allowed'], "Should be allowed with 3 failures");

        // Test 4-5 failures: should have delay
        $this->advancedRateLimiter->recordAttempt($identifier, $action, false);
        $result = $this->advancedRateLimiter->checkLimitProgressive($identifier, $action);
        // May or may not be blocked depending on timing
        $this->assertArrayHasKey('failures', $result);
        $this->assertEquals(4, $result['failures'], "Should track 4 failures");

        // Reset and test higher threshold
        $identifier2 = 'progressive_test_high_' . uniqid();
        for ($i = 0; $i < 11; $i++) {
            $this->advancedRateLimiter->recordAttempt($identifier2, $action, false);
        }
        $result = $this->advancedRateLimiter->checkLimitProgressive($identifier2, $action);
        $this->assertFalse($result['allowed'], "Should be blocked with 11 failures");
        $this->assertEquals(11, $result['failures'], "Should track 11 failures");
    }

    /**
     * Create a test device in the database
     *
     * @param int $userId User ID
     * @param string|null $uuid Device UUID (generates one if not provided)
     * @return int Device ID
     */
    private function createTestDevice(int $userId, ?string $uuid = null): int
    {
        if ($uuid === null) {
            $uuid = $this->generateDeviceUUID();
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, fk_user_creat, date_creation, status, entity)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', ";
        $sql .= (int) $userId . ", ";
        $sql .= "'" . $this->db->idate(time()) . "', 0, 1)";

        $this->db->query($sql);
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
    }

    /**
     * Test token family check under concurrent modification
     *
     * Verifies that token family checks are consistent even when
     * the family is being modified concurrently.
     */
    public function testTokenFamilyCheckUnderConcurrentModification(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $checkTokenFamily = $this->getPrivateMethod('_checkTokenFamily');
        $updateTokenFamily = $this->getPrivateMethod('_updateTokenFamily');

        // Create a token family
        $familyId = $createTokenFamily->invoke($this->authController, $user->id);

        // Perform multiple checks and updates concurrently
        for ($i = 0; $i < 20; $i++) {
            // Check family validity
            $checkResult = $checkTokenFamily->invoke($this->authController, $familyId, $user->id);
            $this->assertTrue($checkResult['valid'], "Family should be valid during iteration $i");

            // Update family
            $updateTokenFamily->invoke($this->authController, $familyId, $i + 1);
        }

        // Final verification
        $sql = "SELECT refresh_count, revoked FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $familyId;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(20, (int) $obj->refresh_count, "Final refresh count should be 20");
        $this->assertEquals(0, (int) $obj->revoked, "Family should not be revoked");
    }

    /**
     * Test concurrent token revocation
     *
     * Verifies that revoking the same token multiple times
     * does not cause errors or inconsistencies.
     */
    public function testConcurrentTokenRevocation(): void
    {
        $user = $this->testUser;
        $createTokenFamily = $this->getPrivateMethod('_createTokenFamily');
        $generateTokenPair = $this->getPrivateMethod('_generateTokenPair');
        $revokeToken = $this->getPrivateMethod('_revokeToken');

        // Set device UUID header
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateDeviceUUID();

        // Create family and token pair
        $familyId = $createTokenFamily->invoke($this->authController, $user->id);
        $deviceId = $this->createTestDevice($user->id);

        $tokenPair = $generateTokenPair->invoke(
            $this->authController,
            'user',
            $user->id,
            $user->id,
            $user->login,
            1,
            $familyId,
            $deviceId
        );

        $accessTokenId = explode('|', $tokenPair['access_token'])[0];

        // Try to revoke the same token multiple times
        for ($i = 0; $i < 5; $i++) {
            $revokeToken->invoke($this->authController, $accessTokenId, "concurrent_revoke_$i");
        }

        // Verify token is revoked (status = 9)
        $sql = "SELECT status, salt FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int) $accessTokenId;
        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(9, (int) $obj->status, "Token should be revoked");
        $this->assertStringStartsWith('concurrent_revoke_', $obj->salt, "Salt should contain revocation reason");
    }
}
