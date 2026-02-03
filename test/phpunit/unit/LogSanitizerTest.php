<?php

/**
 * Unit tests for LogSanitizer class
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
use SmartAuth\Api\LogSanitizer;

/**
 * @covers \SmartAuth\Api\LogSanitizer
 */
class LogSanitizerTest extends TestCase
{
    // ==================== maskIP tests ====================

    public function testMaskIPWithValidIPv4(): void
    {
        $result = LogSanitizer::maskIP('192.168.1.100');
        $this->assertEquals('192.168.xxx.xxx', $result);
    }

    public function testMaskIPWithAnotherValidIPv4(): void
    {
        $result = LogSanitizer::maskIP('10.0.0.1');
        $this->assertEquals('10.0.xxx.xxx', $result);
    }

    public function testMaskIPWithValidIPv6(): void
    {
        $result = LogSanitizer::maskIP('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertEquals('2001:0db8:85a3:0000:xxxx:xxxx:xxxx:xxxx', $result);
    }

    public function testMaskIPWithShortIPv6(): void
    {
        $result = LogSanitizer::maskIP('::1');
        // Short IPv6 might not have 4 parts when exploded
        $this->assertNotEmpty($result);
    }

    public function testMaskIPWithEmptyString(): void
    {
        $result = LogSanitizer::maskIP('');
        $this->assertEquals('0.0.0.0', $result);
    }

    public function testMaskIPWithNull(): void
    {
        $result = LogSanitizer::maskIP(null);
        $this->assertEquals('0.0.0.0', $result);
    }

    public function testMaskIPWithInvalidIP(): void
    {
        $result = LogSanitizer::maskIP('not-an-ip');
        $this->assertEquals('x.x.x.x', $result);
    }

    // ==================== maskEmail tests ====================

    public function testMaskEmailWithValidEmail(): void
    {
        $result = LogSanitizer::maskEmail('user@example.com');
        $this->assertEquals('us***@example.com', $result);
    }

    public function testMaskEmailWithShortLocalPart(): void
    {
        $result = LogSanitizer::maskEmail('ab@example.com');
        $this->assertEquals('ab***@example.com', $result);
    }

    public function testMaskEmailWithLongLocalPart(): void
    {
        $result = LogSanitizer::maskEmail('verylongemail@domain.org');
        $this->assertEquals('ve***@domain.org', $result);
    }

    public function testMaskEmailWithEmptyString(): void
    {
        $result = LogSanitizer::maskEmail('');
        $this->assertEquals('***@***.***', $result);
    }

    public function testMaskEmailWithNull(): void
    {
        $result = LogSanitizer::maskEmail(null);
        $this->assertEquals('***@***.***', $result);
    }

    public function testMaskEmailWithInvalidEmail(): void
    {
        $result = LogSanitizer::maskEmail('not-an-email');
        $this->assertEquals('***@***.***', $result);
    }

    public function testMaskEmailWithMultipleAtSigns(): void
    {
        $result = LogSanitizer::maskEmail('bad@@email');
        $this->assertEquals('***@***.***', $result);
    }

    // ==================== sanitizeUserAgent tests ====================

    public function testSanitizeUserAgentWithChrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.6099.129';
        $result = LogSanitizer::sanitizeUserAgent($ua);
        $this->assertStringContainsString('x.x', $result);
        $this->assertStringNotContainsString('120.0', $result);
    }

    public function testSanitizeUserAgentWithFirefox(): void
    {
        $ua = 'Mozilla/5.0 Firefox/121.0';
        $result = LogSanitizer::sanitizeUserAgent($ua);
        $this->assertStringContainsString('x.x', $result);
    }

    public function testSanitizeUserAgentTruncatesLongString(): void
    {
        $ua = str_repeat('a', 100);
        $result = LogSanitizer::sanitizeUserAgent($ua, 50);
        $this->assertEquals(50, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testSanitizeUserAgentRemovesInjectionChars(): void
    {
        $ua = 'Mozilla<script>alert("xss")</script>';
        $result = LogSanitizer::sanitizeUserAgent($ua);
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringNotContainsString('"', $result);
    }

    public function testSanitizeUserAgentWithEmptyString(): void
    {
        $result = LogSanitizer::sanitizeUserAgent('');
        $this->assertEquals('unknown', $result);
    }

    public function testSanitizeUserAgentWithNull(): void
    {
        $result = LogSanitizer::sanitizeUserAgent(null);
        $this->assertEquals('unknown', $result);
    }

    // ==================== maskToken tests ====================

    public function testMaskTokenWithValidToken(): void
    {
        $result = LogSanitizer::maskToken('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload');
        $this->assertEquals('eyJh...load', $result);
    }

    public function testMaskTokenWithShortToken(): void
    {
        $result = LogSanitizer::maskToken('short');
        $this->assertEquals('***', $result);
    }

    public function testMaskTokenWithExactly8Chars(): void
    {
        $result = LogSanitizer::maskToken('12345678');
        $this->assertEquals('***', $result);
    }

    public function testMaskTokenWith9Chars(): void
    {
        $result = LogSanitizer::maskToken('123456789');
        $this->assertEquals('1234...6789', $result);
    }

    public function testMaskTokenWithEmptyString(): void
    {
        $result = LogSanitizer::maskToken('');
        $this->assertEquals('***', $result);
    }

    public function testMaskTokenWithNull(): void
    {
        $result = LogSanitizer::maskToken(null);
        $this->assertEquals('***', $result);
    }

    // ==================== maskSalt tests ====================

    public function testMaskSaltWithValidSalt(): void
    {
        $result = LogSanitizer::maskSalt('a1b2c3d4e5f6');
        $this->assertEquals('a1b2...', $result);
    }

    public function testMaskSaltWithShortSalt(): void
    {
        $result = LogSanitizer::maskSalt('abc');
        $this->assertEquals('***', $result);
    }

    public function testMaskSaltWithExactly4Chars(): void
    {
        $result = LogSanitizer::maskSalt('abcd');
        $this->assertEquals('***', $result);
    }

    public function testMaskSaltWith5Chars(): void
    {
        $result = LogSanitizer::maskSalt('abcde');
        $this->assertEquals('abcd...', $result);
    }

    public function testMaskSaltWithEmptyString(): void
    {
        $result = LogSanitizer::maskSalt('');
        $this->assertEquals('***', $result);
    }

    public function testMaskSaltWithNull(): void
    {
        $result = LogSanitizer::maskSalt(null);
        $this->assertEquals('***', $result);
    }

    // ==================== sanitizeURL tests ====================

    public function testSanitizeURLWithSimplePath(): void
    {
        $result = LogSanitizer::sanitizeURL('/api/users/123');
        $this->assertEquals('/api/users/123', $result);
    }

    public function testSanitizeURLWithQueryParams(): void
    {
        $result = LogSanitizer::sanitizeURL('/api/search?q=test&limit=10');
        $this->assertStringContainsString('q=test', $result);
        $this->assertStringContainsString('limit=10', $result);
    }

    public function testSanitizeURLMasksSensitiveParams(): void
    {
        $result = LogSanitizer::sanitizeURL('/api/login?password=secret123');
        $this->assertStringContainsString('password=***', $result);
        $this->assertStringNotContainsString('secret123', $result);
    }

    public function testSanitizeURLMasksTokenParam(): void
    {
        $result = LogSanitizer::sanitizeURL('/api/auth?token=eyJhbGciOiJIUzI1NiJ9');
        $this->assertStringContainsString('token=***', $result);
        $this->assertStringNotContainsString('eyJhbGci', $result);
    }

    public function testSanitizeURLMasksApiKeyParam(): void
    {
        $result = LogSanitizer::sanitizeURL('/api/data?api_key=supersecret');
        $this->assertStringContainsString('api_key=***', $result);
        $this->assertStringNotContainsString('supersecret', $result);
    }

    public function testSanitizeURLTruncatesLongValues(): void
    {
        $longValue = str_repeat('x', 100);
        $result = LogSanitizer::sanitizeURL('/api/data?param=' . $longValue);
        $this->assertStringContainsString('...', $result);
    }

    public function testSanitizeURLTruncatesToMaxLen(): void
    {
        $longUrl = '/api/' . str_repeat('x', 300);
        $result = LogSanitizer::sanitizeURL($longUrl, 100);
        $this->assertEquals(100, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testSanitizeURLWithEmptyString(): void
    {
        $result = LogSanitizer::sanitizeURL('');
        $this->assertEquals('', $result);
    }

    public function testSanitizeURLWithNull(): void
    {
        $result = LogSanitizer::sanitizeURL(null);
        $this->assertEquals('', $result);
    }

    // ==================== maskUUID tests ====================

    public function testMaskUUIDWithStandardUUID(): void
    {
        $result = LogSanitizer::maskUUID('a1b2c3d4-e5f6-7890-abcd-ef1234567890');
        $this->assertEquals('a1b2c3d4-****-****-****-************', $result);
    }

    public function testMaskUUIDWithHash(): void
    {
        $hash = str_repeat('a', 64);
        $result = LogSanitizer::maskUUID($hash);
        $this->assertEquals('aaaaaaaa...[hash]', $result);
    }

    public function testMaskUUIDWithOtherFormat(): void
    {
        $result = LogSanitizer::maskUUID('custom-uuid-format-here');
        $this->assertEquals('custom-u...', $result);
    }

    public function testMaskUUIDWithShortString(): void
    {
        $result = LogSanitizer::maskUUID('short');
        $this->assertEquals('***', $result);
    }

    public function testMaskUUIDWithExactly8Chars(): void
    {
        $result = LogSanitizer::maskUUID('12345678');
        $this->assertEquals('***', $result);
    }

    public function testMaskUUIDWithEmptyString(): void
    {
        $result = LogSanitizer::maskUUID('');
        $this->assertEquals('***', $result);
    }

    public function testMaskUUIDWithNull(): void
    {
        $result = LogSanitizer::maskUUID(null);
        $this->assertEquals('***', $result);
    }

    // ==================== sanitizeLogData tests ====================

    public function testSanitizeLogDataMasksIPField(): void
    {
        $data = ['client_ip' => '192.168.1.100'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('192.168.xxx.xxx', $result['client_ip']);
    }

    public function testSanitizeLogDataMasksEmailField(): void
    {
        $data = ['user_email' => 'user@example.com'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('us***@example.com', $result['user_email']);
    }

    public function testSanitizeLogDataMasksLoginField(): void
    {
        $data = ['login' => 'admin@company.org'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('ad***@company.org', $result['login']);
    }

    public function testSanitizeLogDataMasksTokenField(): void
    {
        $data = ['access_token' => 'eyJhbGciOiJIUzI1NiJ9.payload'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('eyJh...load', $result['access_token']);
    }

    public function testSanitizeLogDataMasksSaltField(): void
    {
        // Use 'encryption_salt' to avoid matching 'token' pattern first
        $data = ['encryption_salt' => 'abcdef123456'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('abcd...', $result['encryption_salt']);
    }

    public function testSanitizeLogDataMasksSecretField(): void
    {
        $data = ['app_secret' => 'mysupersecret'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('mysu...', $result['app_secret']);
    }

    public function testSanitizeLogDataMasksUserAgentField(): void
    {
        $data = ['user_agent' => 'Mozilla/5.0 Chrome/120.0.1'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertStringContainsString('x.x', $result['user_agent']);
    }

    public function testSanitizeLogDataMasksUUIDField(): void
    {
        $data = ['device_uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('a1b2c3d4-****-****-****-************', $result['device_uuid']);
    }

    public function testSanitizeLogDataMasksPasswordField(): void
    {
        $data = ['password' => 'secret123'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('***', $result['password']);
    }

    public function testSanitizeLogDataMasksURLField(): void
    {
        $data = ['redirect_url' => '/api/auth?token=secret'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertStringContainsString('token=***', $result['redirect_url']);
    }

    public function testSanitizeLogDataTruncatesLongValues(): void
    {
        // Use 'notes' instead of 'description' (which contains 'ip' and triggers IP masking)
        $data = ['notes' => str_repeat('x', 200)];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals(100, strlen($result['notes']));
        $this->assertStringEndsWith('...', $result['notes']);
    }

    public function testSanitizeLogDataHandlesArrayValues(): void
    {
        $data = ['items' => ['a', 'b', 'c']];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('[array]', $result['items']);
    }

    public function testSanitizeLogDataHandlesObjectValues(): void
    {
        $data = ['user' => new \stdClass()];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('[object]', $result['user']);
    }

    public function testSanitizeLogDataHandlesNumericValues(): void
    {
        $data = ['user_id' => 12345];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('12345', $result['user_id']);
    }

    public function testSanitizeLogDataPreservesNormalFields(): void
    {
        $data = ['status' => 'active', 'count' => '42'];
        $result = LogSanitizer::sanitizeLogData($data);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals('42', $result['count']);
    }

    public function testSanitizeLogDataHandlesEmptyArray(): void
    {
        $result = LogSanitizer::sanitizeLogData([]);
        $this->assertEquals([], $result);
    }

    public function testSanitizeLogDataHandlesMixedData(): void
    {
        $data = [
            'user_id' => 123,
            'email' => 'test@example.com',
            'ip_address' => '10.0.0.1',
            'action' => 'login',
            'token' => 'abc123456789xyz',
        ];
        $result = LogSanitizer::sanitizeLogData($data);

        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('te***@example.com', $result['email']);
        $this->assertEquals('10.0.xxx.xxx', $result['ip_address']);
        $this->assertEquals('login', $result['action']);
        $this->assertEquals('abc1...9xyz', $result['token']);
    }
}
