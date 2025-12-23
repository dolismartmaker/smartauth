<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\SmartTokenConfig;

/**
 * Unit tests for SmartTokenConfig
 */
class SmartTokenConfigTest extends TestCase
{
    /**
     * Test ACCESS_TOKEN_LIFETIME is defined and reasonable
     */
    public function testAccessTokenLifetimeIsDefined(): void
    {
        $this->assertEquals(3600, SmartTokenConfig::ACCESS_TOKEN_LIFETIME);
        $this->assertGreaterThanOrEqual(900, SmartTokenConfig::ACCESS_TOKEN_LIFETIME, 'Access token should be at least 15 minutes');
        $this->assertLessThanOrEqual(86400, SmartTokenConfig::ACCESS_TOKEN_LIFETIME, 'Access token should be at most 24 hours');
    }

    /**
     * Test REFRESH_TOKEN_LIFETIME is defined and reasonable
     */
    public function testRefreshTokenLifetimeIsDefined(): void
    {
        $this->assertEquals(2592000, SmartTokenConfig::REFRESH_TOKEN_LIFETIME);
        $this->assertGreaterThanOrEqual(604800, SmartTokenConfig::REFRESH_TOKEN_LIFETIME, 'Refresh token should be at least 7 days');
        $this->assertLessThanOrEqual(7776000, SmartTokenConfig::REFRESH_TOKEN_LIFETIME, 'Refresh token should be at most 90 days');
    }

    /**
     * Test refresh token is longer than access token
     */
    public function testRefreshTokenLongerThanAccessToken(): void
    {
        $this->assertGreaterThan(
            SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
            SmartTokenConfig::REFRESH_TOKEN_LIFETIME,
            'Refresh token should live longer than access token'
        );
    }

    /**
     * Test MAX_REFRESH_COUNT is defined and reasonable
     */
    public function testMaxRefreshCountIsDefined(): void
    {
        $this->assertEquals(100, SmartTokenConfig::MAX_REFRESH_COUNT);
        $this->assertGreaterThan(0, SmartTokenConfig::MAX_REFRESH_COUNT);
    }

    /**
     * Test token types are defined
     */
    public function testTokenTypesAreDefined(): void
    {
        $this->assertEquals('access', SmartTokenConfig::TYPE_ACCESS);
        $this->assertEquals('refresh', SmartTokenConfig::TYPE_REFRESH);
    }

    /**
     * Test token types are different
     */
    public function testTokenTypesAreDifferent(): void
    {
        $this->assertNotEquals(
            SmartTokenConfig::TYPE_ACCESS,
            SmartTokenConfig::TYPE_REFRESH,
            'Access and refresh token types should be different'
        );
    }
}
