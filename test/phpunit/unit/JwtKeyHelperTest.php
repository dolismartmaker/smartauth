<?php

/**
 * Unit tests for JwtKeyHelper
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
use SmartAuth\Api\JwtKeyHelper;

/**
 * Tests for JwtKeyHelper key generation and validation
 *
 * Note: These tests focus on the static methods that don't require
 * database access. Tests for getKey() with auto-generation require
 * integration tests with a real database connection.
 *
 * @covers \SmartAuth\Api\JwtKeyHelper
 */
class JwtKeyHelperTest extends TestCase
{
    // ========== Key Generation Tests ==========

    /**
     * Test generateKey returns correct length
     */
    public function testGenerateKeyDefaultLength()
    {
        $key = JwtKeyHelper::generateKey();

        $this->assertEquals(JwtKeyHelper::DEFAULT_KEY_LENGTH, strlen($key));
    }

    /**
     * Test generateKey with custom length
     */
    public function testGenerateKeyCustomLength()
    {
        $key = JwtKeyHelper::generateKey(32);

        $this->assertEquals(32, strlen($key));
    }

    /**
     * Test generateKey returns hexadecimal string
     */
    public function testGenerateKeyIsHexadecimal()
    {
        $key = JwtKeyHelper::generateKey();

        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $key);
    }

    /**
     * Test generateKey returns unique values
     */
    public function testGenerateKeyIsUnique()
    {
        $key1 = JwtKeyHelper::generateKey();
        $key2 = JwtKeyHelper::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    /**
     * Test generateKey with minimum length
     */
    public function testGenerateKeyMinimumLength()
    {
        $key = JwtKeyHelper::generateKey(JwtKeyHelper::MIN_KEY_LENGTH);

        $this->assertGreaterThanOrEqual(JwtKeyHelper::MIN_KEY_LENGTH, strlen($key));
    }

    /**
     * Test generateKey with odd length rounds up
     */
    public function testGenerateKeyOddLengthRoundsUp()
    {
        $key = JwtKeyHelper::generateKey(33);

        // 33 chars needs 17 bytes = 34 hex chars
        $this->assertEquals(34, strlen($key));
    }

    // ========== Config Key Name Tests ==========

    /**
     * Test getConfigKeyName formats correctly
     */
    public function testGetConfigKeyNameFormat()
    {
        $result = JwtKeyHelper::getConfigKeyName('mymodule');

        $this->assertEquals('MYMODULE_JWT_KEY', $result);
    }

    /**
     * Test getConfigKeyName handles uppercase input
     */
    public function testGetConfigKeyNameUppercase()
    {
        $result = JwtKeyHelper::getConfigKeyName('MYMODULE');

        $this->assertEquals('MYMODULE_JWT_KEY', $result);
    }

    /**
     * Test getConfigKeyName handles mixed case
     */
    public function testGetConfigKeyNameMixedCase()
    {
        $result = JwtKeyHelper::getConfigKeyName('MyModule');

        $this->assertEquals('MYMODULE_JWT_KEY', $result);
    }

    /**
     * Test getConfigKeyName trims whitespace
     */
    public function testGetConfigKeyNameTrimsWhitespace()
    {
        $result = JwtKeyHelper::getConfigKeyName('  mymodule  ');

        $this->assertEquals('MYMODULE_JWT_KEY', $result);
    }

    // ========== Constant Values Tests ==========

    /**
     * Test MIN_KEY_LENGTH is at least 32
     */
    public function testMinKeyLengthIsSecure()
    {
        $this->assertGreaterThanOrEqual(32, JwtKeyHelper::MIN_KEY_LENGTH);
    }

    /**
     * Test DEFAULT_KEY_LENGTH is greater than MIN_KEY_LENGTH
     */
    public function testDefaultKeyLengthIsSecure()
    {
        $this->assertGreaterThanOrEqual(
            JwtKeyHelper::MIN_KEY_LENGTH,
            JwtKeyHelper::DEFAULT_KEY_LENGTH
        );
    }

    // ========== Edge Cases ==========

    /**
     * Test generateKey with very large length
     */
    public function testGenerateKeyLargeLength()
    {
        $key = JwtKeyHelper::generateKey(256);

        $this->assertEquals(256, strlen($key));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $key);
    }

    /**
     * Test generateKey with length of 2
     */
    public function testGenerateKeySmallLength()
    {
        $key = JwtKeyHelper::generateKey(2);

        $this->assertEquals(2, strlen($key));
    }

    /**
     * Test multiple keys are all unique
     */
    public function testGenerateKeyMultipleUnique()
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $keys[] = JwtKeyHelper::generateKey();
        }

        $uniqueKeys = array_unique($keys);
        $this->assertCount(100, $uniqueKeys, 'All generated keys should be unique');
    }
}
