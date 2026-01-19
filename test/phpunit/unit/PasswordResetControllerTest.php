<?php

/**
 * Unit tests for PasswordResetController
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for PasswordResetController token validation logic
 *
 * Note: These tests focus on the static validateToken method which doesn't
 * require Dolibarr dependencies. Tests for requestReset require
 * integration tests with mocked database and lang objects.
 */
class PasswordResetControllerTest extends TestCase
{
    /**
     * Load controller class without triggering require_once for Dolibarr files
     */
    public static function setUpBeforeClass(): void
    {
        // Define DOL_DOCUMENT_ROOT if not defined to prevent require_once errors
        if (!defined('DOL_DOCUMENT_ROOT')) {
            define('DOL_DOCUMENT_ROOT', '/tmp/dolibarr-mock');
        }

        // Create mock files to satisfy require_once
        @mkdir('/tmp/dolibarr-mock/user/class', 0777, true);
        @mkdir('/tmp/dolibarr-mock/core/lib', 0777, true);

        if (!file_exists('/tmp/dolibarr-mock/user/class/user.class.php')) {
            file_put_contents('/tmp/dolibarr-mock/user/class/user.class.php', '<?php class User {}');
        }
        if (!file_exists('/tmp/dolibarr-mock/core/lib/security2.lib.php')) {
            file_put_contents('/tmp/dolibarr-mock/core/lib/security2.lib.php', '<?php function getRandomPassword($a=false,$b=[],$c=8){return bin2hex(random_bytes($c/2));}');
        }
    }

    // ========== Token Validation Tests ==========

    /**
     * Test validateToken with valid non-expired token
     */
    public function testValidateTokenWithValidToken()
    {
        // Create a valid token (expires in 1 hour)
        $randomPart = 'abc123def456';
        $expiry = time() + 3600;
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertTrue($result['valid']);
        $this->assertEquals($randomPart, $result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with expired token
     */
    public function testValidateTokenWithExpiredToken()
    {
        // Create an expired token (expired 1 hour ago)
        $randomPart = 'abc123def456';
        $expiry = time() - 3600;
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertEquals($randomPart, $result['token']);
        $this->assertTrue($result['expired']);
    }

    /**
     * Test validateToken with invalid base64
     */
    public function testValidateTokenWithInvalidBase64()
    {
        $token = '!!!invalid-base64!!!';

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with malformed token (no separator)
     */
    public function testValidateTokenWithMalformedTokenNoSeparator()
    {
        $token = base64_encode('notokenwithoutseparator');

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with malformed token (too many parts)
     */
    public function testValidateTokenWithMalformedTokenTooManyParts()
    {
        $token = base64_encode('part1|part2|part3');

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with empty string
     */
    public function testValidateTokenWithEmptyString()
    {
        $result = \SmartAuth\Api\PasswordResetController::validateToken('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with token expiring exactly now
     */
    public function testValidateTokenExpiringNow()
    {
        // Token that expires exactly now (should be expired)
        $randomPart = 'abc123def456';
        $expiry = time() - 1; // 1 second ago
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['expired']);
    }

    /**
     * Test validateToken with token expiring in 1 second (still valid)
     */
    public function testValidateTokenExpiringInOneSecond()
    {
        $randomPart = 'abc123def456';
        $expiry = time() + 1;
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with non-numeric expiry
     */
    public function testValidateTokenWithNonNumericExpiry()
    {
        $token = base64_encode('randompart|notanumber');

        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);

        // (int) 'notanumber' = 0, which is < time(), so expired
        $this->assertFalse($result['valid']);
        $this->assertTrue($result['expired']);
    }

    // ========== Token Generation Tests ==========

    /**
     * Test that generated tokens can be validated
     */
    public function testGeneratedTokenFormat()
    {
        // Generate a token using reflection to access private method
        $controller = new \SmartAuth\Api\PasswordResetController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generateTokenWithExpiry');
        $method->setAccessible(true);

        $token = $method->invoke($controller);

        // Token should be base64 encoded
        $this->assertNotEmpty($token);
        $this->assertNotFalse(base64_decode($token, true));

        // Token should be valid when validated
        $result = \SmartAuth\Api\PasswordResetController::validateToken($token);
        $this->assertTrue($result['valid']);
        $this->assertFalse($result['expired']);
        $this->assertNotEmpty($result['token']);
    }

    /**
     * Test token contains expiry approximately 1 hour in future
     */
    public function testGeneratedTokenExpiryTime()
    {
        $controller = new \SmartAuth\Api\PasswordResetController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generateTokenWithExpiry');
        $method->setAccessible(true);

        $beforeGeneration = time();
        $token = $method->invoke($controller);
        $afterGeneration = time();

        // Decode and check expiry
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded);
        $expiry = (int) $parts[1];

        // Expiry should be approximately 1 hour (3600 seconds) from now
        $expectedMin = $beforeGeneration + 3600;
        $expectedMax = $afterGeneration + 3600;

        $this->assertGreaterThanOrEqual($expectedMin, $expiry);
        $this->assertLessThanOrEqual($expectedMax, $expiry);
    }

    /**
     * Test token random part has sufficient length
     */
    public function testGeneratedTokenRandomPartLength()
    {
        $controller = new \SmartAuth\Api\PasswordResetController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generateTokenWithExpiry');
        $method->setAccessible(true);

        $token = $method->invoke($controller);

        // Decode and check random part length
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded);
        $randomPart = $parts[0];

        // Random part should be at least 16 characters (32 hex chars from getRandomPassword)
        $this->assertGreaterThanOrEqual(16, strlen($randomPart));
    }

    /**
     * Test that two generated tokens are different
     */
    public function testGeneratedTokensAreUnique()
    {
        $controller = new \SmartAuth\Api\PasswordResetController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generateTokenWithExpiry');
        $method->setAccessible(true);

        $token1 = $method->invoke($controller);
        $token2 = $method->invoke($controller);

        $this->assertNotEquals($token1, $token2);
    }
}
