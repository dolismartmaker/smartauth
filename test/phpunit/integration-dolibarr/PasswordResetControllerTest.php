<?php

/**
 * Integration tests for PasswordResetController
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\PasswordResetController;

/**
 * @covers \SmartAuth\Api\PasswordResetController
 */
class PasswordResetControllerTest extends DolibarrRealTestCase
{
    private PasswordResetController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new PasswordResetController();

        // Clean rate limit table for password reset tests
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE action = 'password_reset'");
    }

    // ==================== requestReset() tests ====================

    /**
     * Test requestReset with empty email returns 400
     */
    public function testRequestResetWithEmptyEmailReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => '']);

        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    /**
     * Test requestReset with null email returns 400
     */
    public function testRequestResetWithNullEmailReturns400(): void
    {
        $result = $this->controller->requestReset([]);

        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    /**
     * Test requestReset with invalid email format returns 400
     */
    public function testRequestResetWithInvalidEmailFormatReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => 'not-an-email']);

        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Invalid email format', $result[0]['message']);
    }

    /**
     * Test requestReset with invalid email format (missing domain) returns 400
     */
    public function testRequestResetWithMissingDomainReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => 'test@']);

        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Invalid email format', $result[0]['message']);
    }

    /**
     * Test requestReset with non-existent email returns 200 (anti-enumeration)
     */
    public function testRequestResetWithNonExistentEmailReturns200(): void
    {
        $result = $this->controller->requestReset(['email' => 'nonexistent@example.com']);

        $this->assertEquals(200, $result[1]);
        $this->assertStringContainsString('If this email exists', $result[0]['message']);
    }

    /**
     * Test requestReset with existing active user creates token
     */
    public function testRequestResetWithExistingUserCreatesToken(): void
    {
        // Create active test user
        $testUser = $this->createTestUser([
            'email' => 'resettest@example.com',
            'statut' => 1
        ]);

        $result = $this->controller->requestReset(['email' => 'resettest@example.com']);

        $this->assertEquals(200, $result[1]);

        // Verify token was stored in pass_temp
        $sql = "SELECT pass_temp FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $testUser->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertNotEmpty($obj->pass_temp);

        // Verify token is valid format
        $tokenValidation = PasswordResetController::validateToken($obj->pass_temp);
        $this->assertTrue($tokenValidation['valid']);
    }

    /**
     * Test requestReset with inactive user does not create token
     */
    public function testRequestResetWithInactiveUserDoesNotCreateToken(): void
    {
        // Create inactive test user
        $testUser = $this->createTestUser([
            'email' => 'inactive@example.com',
            'statut' => 0
        ]);

        $result = $this->controller->requestReset(['email' => 'inactive@example.com']);

        // Should still return 200 (anti-enumeration)
        $this->assertEquals(200, $result[1]);

        // Verify no token was stored
        $sql = "SELECT pass_temp FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $testUser->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertEmpty($obj->pass_temp);
    }

    /**
     * Test requestReset rate limiting blocks after max attempts
     */
    public function testRequestResetRateLimitingBlocks(): void
    {
        $email = 'ratelimit@example.com';

        // Make 3 requests (the limit)
        for ($i = 0; $i < 3; $i++) {
            $result = $this->controller->requestReset(['email' => $email]);
            $this->assertEquals(200, $result[1], "Request $i should succeed");
        }

        // 4th request should be blocked
        $result = $this->controller->requestReset(['email' => $email]);

        $this->assertEquals(429, $result[1]);
        $this->assertStringContainsString('Too many requests', $result[0]['message']);
        $this->assertArrayHasKey('retry_after', $result[0]);
    }

    /**
     * Test requestReset records attempt in rate limiter
     */
    public function testRequestResetRecordsAttempt(): void
    {
        $email = 'record@example.com';

        $this->controller->requestReset(['email' => $email]);

        // Check rate limit table
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $this->db->escape($email) . "'";
        $sql .= " AND action = 'password_reset'";

        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertGreaterThan(0, (int) $obj->cnt);
    }

    /**
     * Test requestReset with whitespace-only email returns 400
     */
    public function testRequestResetWithWhitespaceEmailReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => '   ']);

        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    /**
     * Test requestReset trims email whitespace
     */
    public function testRequestResetTrimsEmailWhitespace(): void
    {
        // Create test user
        $testUser = $this->createTestUser([
            'email' => 'trimtest@example.com',
            'statut' => 1
        ]);

        // Request with whitespace around email
        $result = $this->controller->requestReset(['email' => '  trimtest@example.com  ']);

        $this->assertEquals(200, $result[1]);

        // Verify token was created (email was trimmed and matched)
        $sql = "SELECT pass_temp FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $testUser->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $this->assertNotEmpty($obj->pass_temp);
    }

    // ==================== validateToken() static method tests ====================

    /**
     * Test validateToken with valid token
     */
    public function testValidateTokenWithValidToken(): void
    {
        $randomPart = 'abc123def456';
        $expiry = time() + 3600;
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = PasswordResetController::validateToken($token);

        $this->assertTrue($result['valid']);
        $this->assertEquals($randomPart, $result['token']);
        $this->assertFalse($result['expired']);
    }

    /**
     * Test validateToken with expired token
     */
    public function testValidateTokenWithExpiredToken(): void
    {
        $randomPart = 'abc123def456';
        $expiry = time() - 3600;
        $token = base64_encode($randomPart . '|' . $expiry);

        $result = PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertEquals($randomPart, $result['token']);
        $this->assertTrue($result['expired']);
    }

    /**
     * Test validateToken with invalid base64
     */
    public function testValidateTokenWithInvalidBase64(): void
    {
        $result = PasswordResetController::validateToken('!!!invalid!!!');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
    }

    /**
     * Test validateToken with malformed token (no separator)
     */
    public function testValidateTokenWithMalformedToken(): void
    {
        $token = base64_encode('noseparator');

        $result = PasswordResetController::validateToken($token);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['token']);
    }
}
