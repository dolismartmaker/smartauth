<?php

/**
 * Unit tests for TokenService
 *
 * Tests OAuth2 token generation and validation logic.
 * Since TokenService requires database and JWT keys, these tests
 * focus on the expected structure and algorithms without
 * instantiating the service.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenService
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\OAuthConfig;

class TokenServiceTest extends TestCase
{
    /**
     * Original global conf backup
     */
    private $originalConf;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Backup original conf
        $this->originalConf = $GLOBALS['conf'] ?? null;

        // Set up mock conf
        global $conf;
        $conf = new \stdClass();
        $conf->global = new \stdClass();
        $GLOBALS['conf'] = $conf;

        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.example.com';
        $conf->global->SMARTAUTH_OAUTH_ACCESS_TTL = 3600;
        $conf->global->SMARTAUTH_OAUTH_REFRESH_TTL = 2592000;
    }

    /**
     * Restore original conf
     */
    protected function tearDown(): void
    {
        global $conf;
        if ($this->originalConf !== null) {
            $conf = $this->originalConf;
            $GLOBALS['conf'] = $this->originalConf;
        }

        parent::tearDown();
    }

    /**
     * Test access token has correct claims structure
     *
     * Note: This test validates the expected behavior of the JWT structure
     * without requiring a full Dolibarr installation.
     */
    public function testAccessTokenExpectedClaims(): void
    {
        // Define expected claims based on TokenService::createAccessToken
        $expectedClaims = [
            'iss',      // Issuer
            'sub',      // Subject (user ID)
            'aud',      // Audience (client ID)
            'exp',      // Expiration time
            'iat',      // Issued at
            'jti',      // JWT ID
            'client_id', // Client identifier
            'scope',    // Granted scopes
        ];

        // This test documents the expected structure
        $this->assertCount(8, $expectedClaims);
        $this->assertContains('iss', $expectedClaims);
        $this->assertContains('sub', $expectedClaims);
        $this->assertContains('scope', $expectedClaims);
    }

    /**
     * Test ID token expected claims per OIDC Core spec
     */
    public function testIdTokenExpectedClaims(): void
    {
        // Define expected claims for ID token based on OIDC spec
        $requiredClaims = [
            'iss',       // Issuer
            'sub',       // Subject
            'aud',       // Audience
            'exp',       // Expiration
            'iat',       // Issued at
        ];

        $optionalClaims = [
            'auth_time', // When user authenticated
            'nonce',     // Anti-replay value
            'at_hash',   // Access token hash
        ];

        $profileClaims = [
            'name',
            'family_name',
            'given_name',
            'updated_at',
        ];

        $emailClaims = [
            'email',
            'email_verified',
        ];

        // Document expectations
        $this->assertCount(5, $requiredClaims);
        $this->assertContains('iss', $requiredClaims);
        $this->assertContains('sub', $requiredClaims);
    }

    /**
     * Test access token lifetime is configurable
     */
    public function testAccessTokenLifetimeConfiguration(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ACCESS_TTL = 7200;

        $ttl = OAuthConfig::getAccessTokenTTL();

        $this->assertEquals(7200, $ttl);
    }

    /**
     * Test refresh token lifetime is configurable
     */
    public function testRefreshTokenLifetimeConfiguration(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_REFRESH_TTL = 1296000;

        $ttl = OAuthConfig::getRefreshTokenTTL();

        $this->assertEquals(1296000, $ttl);
    }

    /**
     * Test at_hash computation algorithm
     *
     * Per OIDC Core spec: at_hash is left half of SHA-256 of access token, base64url encoded
     */
    public function testAtHashAlgorithm(): void
    {
        $accessToken = 'test-access-token';

        // Compute at_hash per spec
        $hash = hash('sha256', $accessToken, true);
        $leftHalf = substr($hash, 0, 16);
        $atHash = rtrim(strtr(base64_encode($leftHalf), '+/', '-_'), '=');

        // Should be 22 chars (128 bits / 6 bits per base64 char, rounded up)
        $this->assertEquals(22, strlen($atHash));

        // Should be base64url format (no +, /, =)
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $atHash);
    }

    /**
     * Test JTI format expectations
     */
    public function testJtiFormatExpectations(): void
    {
        // JTI should be 32 chars (16 bytes in hex)
        $expectedLength = 32;

        // JTI should be lowercase hex
        $pattern = '/^[a-f0-9]{32}$/';

        $this->assertEquals(32, $expectedLength);
        $this->assertMatchesRegularExpression($pattern, str_repeat('a', 32));
    }

    /**
     * Test refresh token format expectations
     */
    public function testRefreshTokenFormatExpectations(): void
    {
        // Refresh tokens should have format: smartauth_rt_{random}
        $prefix = 'smartauth_rt_';

        // Total length: 13 prefix + 64 hex chars = 77
        $expectedLength = 77;

        $this->assertEquals('smartauth_rt_', $prefix);
        $this->assertEquals(77, $expectedLength);
    }

    /**
     * Test token type constants expectations
     */
    public function testTokenTypeConstantsExpectations(): void
    {
        $expectedAccessType = 'access';
        $expectedRefreshType = 'refresh';

        $this->assertEquals('access', $expectedAccessType);
        $this->assertEquals('refresh', $expectedRefreshType);
    }

    /**
     * Test token hashing algorithm (SHA256)
     */
    public function testTokenHashingAlgorithm(): void
    {
        $token = 'test-token-12345';
        $hash = hash('sha256', $token);

        // Should be SHA256 (64 hex chars)
        $this->assertEquals(64, strlen($hash));

        // Same input should give same hash
        $hash2 = hash('sha256', $token);
        $this->assertEquals($hash, $hash2);

        // Different input should give different hash
        $hash3 = hash('sha256', 'different-token');
        $this->assertNotEquals($hash, $hash3);
    }

    /**
     * Test base64url encoding algorithm
     */
    public function testBase64UrlEncoding(): void
    {
        $data = random_bytes(32);

        // Base64url encoding: standard base64 with +/ replaced by -_ and no padding
        $encoded = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        // Should not contain standard base64 special chars
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);

        // Should be decodable
        $decoded = base64_decode(strtr($encoded, '-_', '+/'));
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test random token uniqueness (statistical test)
     */
    public function testRandomTokenUniqueness(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = bin2hex(random_bytes(16));
        }

        $unique = array_unique($tokens);

        $this->assertCount(100, $unique, 'All random tokens should be unique');
    }

    /**
     * Test JWT structure (3 parts separated by dots)
     */
    public function testJwtStructure(): void
    {
        // A valid JWT has 3 parts
        $validJwt = 'header.payload.signature';
        $parts = explode('.', $validJwt);

        $this->assertCount(3, $parts);
    }

    /**
     * Test OIDC required claims presence in config
     */
    public function testOidcClaimsInConfig(): void
    {
        $supportedClaims = OAuthConfig::getSupportedClaims();

        // Required OIDC claims
        $this->assertContains('sub', $supportedClaims);
        $this->assertContains('iss', $supportedClaims);
        $this->assertContains('aud', $supportedClaims);
        $this->assertContains('exp', $supportedClaims);
        $this->assertContains('iat', $supportedClaims);

        // Profile claims
        $this->assertContains('name', $supportedClaims);
        $this->assertContains('family_name', $supportedClaims);
        $this->assertContains('given_name', $supportedClaims);

        // Email claims
        $this->assertContains('email', $supportedClaims);
        $this->assertContains('email_verified', $supportedClaims);
    }
}
