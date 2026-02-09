<?php

/**
 * Unit tests for OAuthConfig
 *
 * Tests OAuth2/OIDC configuration management.
 *
 * @covers \SmartAuth\Api\OAuth2\OAuthConfig
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\OAuthConfig;

class OAuthConfigTest extends TestCase
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

        // Set up mock conf with global object
        global $conf;
        $conf = new \stdClass();
        $conf->global = new \stdClass();
        $GLOBALS['conf'] = $conf;
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
     * Helper to set config value
     */
    private function setConfig(string $key, $value): void
    {
        global $conf;
        $conf->global->$key = $value;
    }

    /**
     * Test isEnabled returns false when not configured
     */
    public function testIsEnabledDefault(): void
    {
        $this->assertFalse(OAuthConfig::isEnabled());
    }

    /**
     * Test isEnabled returns true when enabled
     */
    public function testIsEnabledTrue(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ENABLED', 1);

        $this->assertTrue(OAuthConfig::isEnabled());
    }

    /**
     * Test getIssuer returns configured value
     */
    public function testGetIssuerConfigured(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $issuer = OAuthConfig::getIssuer();

        $this->assertEquals('https://auth.example.com', $issuer);
    }

    /**
     * Test getIssuer removes trailing slash
     */
    public function testGetIssuerTrimsSlash(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com/');

        $issuer = OAuthConfig::getIssuer();

        $this->assertEquals('https://auth.example.com', $issuer);
    }

    /**
     * Test getAccessTokenTTL returns default
     */
    public function testGetAccessTokenTTLDefault(): void
    {
        $ttl = OAuthConfig::getAccessTokenTTL();

        $this->assertEquals(OAuthConfig::DEFAULT_ACCESS_TOKEN_TTL, $ttl);
        $this->assertEquals(3600, $ttl);
    }

    /**
     * Test getAccessTokenTTL returns configured value
     */
    public function testGetAccessTokenTTLConfigured(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ACCESS_TTL', 7200);

        $ttl = OAuthConfig::getAccessTokenTTL();

        $this->assertEquals(7200, $ttl);
    }

    /**
     * Test getRefreshTokenTTL returns default
     */
    public function testGetRefreshTokenTTLDefault(): void
    {
        $ttl = OAuthConfig::getRefreshTokenTTL();

        $this->assertEquals(OAuthConfig::DEFAULT_REFRESH_TOKEN_TTL, $ttl);
        $this->assertEquals(2592000, $ttl); // 30 days
    }

    /**
     * Test getRefreshTokenTTL returns configured value
     */
    public function testGetRefreshTokenTTLConfigured(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_REFRESH_TTL', 1296000); // 15 days

        $ttl = OAuthConfig::getRefreshTokenTTL();

        $this->assertEquals(1296000, $ttl);
    }

    /**
     * Test getCodeTTL returns default
     */
    public function testGetCodeTTLDefault(): void
    {
        $ttl = OAuthConfig::getCodeTTL();

        $this->assertEquals(OAuthConfig::DEFAULT_CODE_TTL, $ttl);
        $this->assertEquals(600, $ttl); // 10 minutes
    }

    /**
     * Test getSessionTTL returns default
     */
    public function testGetSessionTTLDefault(): void
    {
        $ttl = OAuthConfig::getSessionTTL();

        $this->assertEquals(OAuthConfig::DEFAULT_SESSION_TTL, $ttl);
        $this->assertEquals(86400, $ttl); // 24 hours
    }

    /**
     * Test requirePkce returns default (true)
     */
    public function testRequirePkceDefault(): void
    {
        $this->assertTrue(OAuthConfig::requirePkce());
    }

    /**
     * Test requirePkce returns configured value
     */
    public function testRequirePkceConfigured(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_REQUIRE_PKCE', 0);

        $this->assertFalse(OAuthConfig::requirePkce());
    }

    /**
     * Test rememberConsent returns default (true)
     */
    public function testRememberConsentDefault(): void
    {
        $this->assertTrue(OAuthConfig::rememberConsent());
    }

    /**
     * Test rememberConsent returns configured value
     */
    public function testRememberConsentConfigured(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_CONSENT_REMEMBER', 0);

        $this->assertFalse(OAuthConfig::rememberConsent());
    }

    /**
     * Test isBypassMode returns default (false)
     */
    public function testIsBypassModeDefault(): void
    {
        $this->assertFalse(OAuthConfig::isBypassMode());
    }

    /**
     * Test isBypassMode returns true when enabled
     */
    public function testIsBypassModeEnabled(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_BYPASS', 1);

        $this->assertTrue(OAuthConfig::isBypassMode());
    }

    /**
     * Test getFallbackUsers returns empty array by default
     */
    public function testGetFallbackUsersDefault(): void
    {
        $users = OAuthConfig::getFallbackUsers();

        $this->assertIsArray($users);
        $this->assertEmpty($users);
    }

    /**
     * Test getFallbackUsers parses CSV
     */
    public function testGetFallbackUsersConfigured(): void
    {
        $this->setConfig('SMARTAUTH_FALLBACK_USERS', '1, 2, 3');

        $users = OAuthConfig::getFallbackUsers();

        $this->assertCount(3, $users);
        $this->assertContains(1, $users);
        $this->assertContains(2, $users);
        $this->assertContains(3, $users);
    }

    /**
     * Test getAuthorizationEndpoint
     */
    public function testGetAuthorizationEndpoint(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $endpoint = OAuthConfig::getAuthorizationEndpoint();

        $this->assertEquals('https://auth.example.com/oauth/authorize', $endpoint);
    }

    /**
     * Test getTokenEndpoint
     */
    public function testGetTokenEndpoint(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $endpoint = OAuthConfig::getTokenEndpoint();

        $this->assertEquals('https://auth.example.com/oauth/token', $endpoint);
    }

    /**
     * Test getUserinfoEndpoint
     */
    public function testGetUserinfoEndpoint(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $endpoint = OAuthConfig::getUserinfoEndpoint();

        $this->assertEquals('https://auth.example.com/oauth/userinfo', $endpoint);
    }

    /**
     * Test getRevocationEndpoint
     */
    public function testGetRevocationEndpoint(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $endpoint = OAuthConfig::getRevocationEndpoint();

        $this->assertEquals('https://auth.example.com/oauth/revoke', $endpoint);
    }

    /**
     * Test getJwksUri
     */
    public function testGetJwksUri(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $uri = OAuthConfig::getJwksUri();

        $this->assertEquals('https://auth.example.com/.well-known/jwks.json', $uri);
    }

    /**
     * Test getEndSessionEndpoint
     */
    public function testGetEndSessionEndpoint(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $endpoint = OAuthConfig::getEndSessionEndpoint();

        $this->assertEquals('https://auth.example.com/oauth/logout', $endpoint);
    }

    /**
     * Test getSupportedScopes contains required scopes
     */
    public function testGetSupportedScopes(): void
    {
        $scopes = OAuthConfig::getSupportedScopes();

        $this->assertIsArray($scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
        $this->assertContains('email', $scopes);
        $this->assertContains('groups', $scopes);
        $this->assertContains('roles', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    /**
     * Test getSupportedResponseTypes
     */
    public function testGetSupportedResponseTypes(): void
    {
        $types = OAuthConfig::getSupportedResponseTypes();

        $this->assertIsArray($types);
        $this->assertContains('code', $types);
    }

    /**
     * Test getSupportedGrantTypes
     */
    public function testGetSupportedGrantTypes(): void
    {
        $types = OAuthConfig::getSupportedGrantTypes();

        $this->assertIsArray($types);
        $this->assertContains('authorization_code', $types);
        $this->assertContains('refresh_token', $types);
    }

    /**
     * Test getTokenEndpointAuthMethods
     */
    public function testGetTokenEndpointAuthMethods(): void
    {
        $methods = OAuthConfig::getTokenEndpointAuthMethods();

        $this->assertIsArray($methods);
        $this->assertContains('client_secret_post', $methods);
        $this->assertContains('client_secret_basic', $methods);
        $this->assertContains('none', $methods);
    }

    /**
     * Test getCodeChallengeMethods
     */
    public function testGetCodeChallengeMethods(): void
    {
        $methods = OAuthConfig::getCodeChallengeMethods();

        $this->assertIsArray($methods);
        $this->assertContains('S256', $methods);
        $this->assertContains('plain', $methods);
    }

    /**
     * Test getSupportedClaims
     */
    public function testGetSupportedClaims(): void
    {
        $claims = OAuthConfig::getSupportedClaims();

        $this->assertIsArray($claims);
        $this->assertContains('sub', $claims);
        $this->assertContains('iss', $claims);
        $this->assertContains('aud', $claims);
        $this->assertContains('exp', $claims);
        $this->assertContains('name', $claims);
        $this->assertContains('email', $claims);
    }

    /**
     * Test getOpenIdConfiguration returns complete structure
     */
    public function testGetOpenIdConfiguration(): void
    {
        $this->setConfig('SMARTAUTH_OAUTH_ISSUER', 'https://auth.example.com');

        $config = OAuthConfig::getOpenIdConfiguration();

        $this->assertIsArray($config);

        // Required fields per OIDC Discovery spec
        $this->assertArrayHasKey('issuer', $config);
        $this->assertArrayHasKey('authorization_endpoint', $config);
        $this->assertArrayHasKey('token_endpoint', $config);
        $this->assertArrayHasKey('userinfo_endpoint', $config);
        $this->assertArrayHasKey('jwks_uri', $config);
        $this->assertArrayHasKey('scopes_supported', $config);
        $this->assertArrayHasKey('response_types_supported', $config);
        $this->assertArrayHasKey('grant_types_supported', $config);

        // Verify issuer matches
        $this->assertEquals('https://auth.example.com', $config['issuer']);
    }

    /**
     * Test default TTL constants are correct
     */
    public function testDefaultTTLConstants(): void
    {
        $this->assertEquals(3600, OAuthConfig::DEFAULT_ACCESS_TOKEN_TTL);      // 1 hour
        $this->assertEquals(2592000, OAuthConfig::DEFAULT_REFRESH_TOKEN_TTL);  // 30 days
        $this->assertEquals(600, OAuthConfig::DEFAULT_CODE_TTL);               // 10 minutes
        $this->assertEquals(86400, OAuthConfig::DEFAULT_SESSION_TTL);          // 24 hours
    }
}
