<?php

/**
 * DiscoveryControllerTest.php
 *
 * Integration tests for OAuth2 Discovery endpoints.
 *
 * Tests:
 * - OpenID Configuration endpoint returns valid JSON
 * - JWKS endpoint returns valid RSA public keys
 * - Key structure matches OIDC specifications
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Api\OAuth2\DiscoveryController;
use SmartAuth\Api\JwtKeyHelper;

class DiscoveryControllerTest extends TestCase
{
    /**
     * Test OAuthConfig::getOpenIdConfiguration returns valid structure
     */
    public function testGetOpenIdConfigurationReturnsValidStructure(): void
    {
        $config = OAuthConfig::getOpenIdConfiguration();

        // Check required OIDC fields
        $this->assertArrayHasKey('issuer', $config);
        $this->assertArrayHasKey('authorization_endpoint', $config);
        $this->assertArrayHasKey('token_endpoint', $config);
        $this->assertArrayHasKey('userinfo_endpoint', $config);
        $this->assertArrayHasKey('jwks_uri', $config);
        $this->assertArrayHasKey('scopes_supported', $config);
        $this->assertArrayHasKey('response_types_supported', $config);
        $this->assertArrayHasKey('grant_types_supported', $config);
        $this->assertArrayHasKey('id_token_signing_alg_values_supported', $config);

        // Check types
        $this->assertIsString($config['issuer']);
        $this->assertIsArray($config['scopes_supported']);
        $this->assertContains('openid', $config['scopes_supported']);
    }

    /**
     * Test OAuthConfig::getOpenIdConfiguration returns valid URLs
     */
    public function testGetOpenIdConfigurationReturnsValidUrls(): void
    {
        $config = OAuthConfig::getOpenIdConfiguration();

        // All endpoints should be valid URLs starting with issuer
        $issuer = $config['issuer'];

        $this->assertStringStartsWith($issuer, $config['authorization_endpoint']);
        $this->assertStringStartsWith($issuer, $config['token_endpoint']);
        $this->assertStringStartsWith($issuer, $config['userinfo_endpoint']);
        $this->assertStringStartsWith($issuer, $config['jwks_uri']);
    }

    /**
     * Test OAuthConfig::getOpenIdConfiguration returns serializable JSON
     */
    public function testGetOpenIdConfigurationIsJsonSerializable(): void
    {
        $config = OAuthConfig::getOpenIdConfiguration();

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse($json, 'Failed to encode OpenID configuration to JSON');
        $this->assertJson($json);

        // Verify we can decode it back
        $decoded = json_decode($json, true);
        $this->assertEquals($config, $decoded);
    }

    /**
     * Test JwtKeyHelper generates valid RSA key pair
     */
    public function testJwtKeyHelperGeneratesRsaKeyPair(): void
    {
        // Force generation of new keys
        $privateKey = JwtKeyHelper::getRsaPrivateKey();
        $publicKey = JwtKeyHelper::getRsaPublicKey();
        $kid = JwtKeyHelper::getRsaKeyId();

        // Check private key format
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $privateKey);
        $this->assertStringContainsString('-----END PRIVATE KEY-----', $privateKey);

        // Check public key format
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $publicKey);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $publicKey);

        // Check kid format
        $this->assertStringStartsWith('smartauth-', $kid);
        $this->assertGreaterThan(10, strlen($kid));
    }

    /**
     * Test JwtKeyHelper::getJwks returns valid JWKS structure
     */
    public function testGetJwksReturnsValidStructure(): void
    {
        $jwks = JwtKeyHelper::getJwks();

        // Check structure
        $this->assertArrayHasKey('keys', $jwks);
        $this->assertIsArray($jwks['keys']);
        $this->assertNotEmpty($jwks['keys']);

        // Check first key
        $key = $jwks['keys'][0];

        $this->assertArrayHasKey('kty', $key);
        $this->assertEquals('RSA', $key['kty']);

        $this->assertArrayHasKey('alg', $key);
        $this->assertEquals('RS256', $key['alg']);

        $this->assertArrayHasKey('use', $key);
        $this->assertEquals('sig', $key['use']);

        $this->assertArrayHasKey('kid', $key);
        $this->assertIsString($key['kid']);

        $this->assertArrayHasKey('n', $key);
        $this->assertIsString($key['n']);

        $this->assertArrayHasKey('e', $key);
        $this->assertIsString($key['e']);
    }

    /**
     * Test JWKS is JSON serializable
     */
    public function testGetJwksIsJsonSerializable(): void
    {
        $jwks = JwtKeyHelper::getJwks();

        $json = json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse($json, 'Failed to encode JWKS to JSON');
        $this->assertJson($json);

        // Verify we can decode it back
        $decoded = json_decode($json, true);
        $this->assertEquals($jwks, $decoded);
    }

    /**
     * Test JWKS modulus and exponent are base64url encoded
     */
    public function testJwksModulusAndExponentAreBase64UrlEncoded(): void
    {
        $jwks = JwtKeyHelper::getJwks();
        $key = $jwks['keys'][0];

        // Base64url should not contain + or / or =
        $this->assertStringNotContainsString('+', $key['n']);
        $this->assertStringNotContainsString('/', $key['n']);

        $this->assertStringNotContainsString('+', $key['e']);
        $this->assertStringNotContainsString('/', $key['e']);

        // Exponent should typically be AQAB (65537 in base64url)
        $this->assertEquals('AQAB', $key['e']);
    }

    /**
     * Test OAuthConfig helper methods
     */
    public function testOAuthConfigHelperMethods(): void
    {
        // Test getIssuer
        $issuer = OAuthConfig::getIssuer();
        $this->assertIsString($issuer);
        $this->assertNotEmpty($issuer);

        // Test endpoint getters
        $this->assertStringContainsString('/oauth/authorize', OAuthConfig::getAuthorizationEndpoint());
        $this->assertStringContainsString('/oauth/token', OAuthConfig::getTokenEndpoint());
        $this->assertStringContainsString('/oauth/userinfo', OAuthConfig::getUserinfoEndpoint());
        $this->assertStringContainsString('/.well-known/jwks.json', OAuthConfig::getJwksUri());

        // Test supported values
        $this->assertContains('openid', OAuthConfig::getSupportedScopes());
        $this->assertContains('code', OAuthConfig::getSupportedResponseTypes());
        $this->assertContains('authorization_code', OAuthConfig::getSupportedGrantTypes());
        $this->assertContains('S256', OAuthConfig::getCodeChallengeMethods());
    }

    /**
     * Test OAuthConfig default TTL values
     */
    public function testOAuthConfigDefaultTtlValues(): void
    {
        // Access token: 1 hour
        $this->assertEquals(3600, OAuthConfig::getAccessTokenTTL());

        // Refresh token: 30 days
        $this->assertEquals(2592000, OAuthConfig::getRefreshTokenTTL());

        // Code: 10 minutes
        $this->assertEquals(600, OAuthConfig::getCodeTTL());

        // Session: 24 hours
        $this->assertEquals(86400, OAuthConfig::getSessionTTL());
    }

    /**
     * Test DiscoveryController route method
     */
    public function testDiscoveryControllerRouteMethod(): void
    {
        $controller = new DiscoveryController();

        // Valid routes should return true (but we can't test output in unit test)
        // Invalid routes should return false
        $this->assertFalse($controller->route('/invalid/path'));
        $this->assertFalse($controller->route('/oauth/authorize'));
        $this->assertFalse($controller->route('/'));
    }

    /**
     * Test RSA key consistency
     */
    public function testRsaKeyConsistency(): void
    {
        // Get keys multiple times - should return same keys
        $privateKey1 = JwtKeyHelper::getRsaPrivateKey();
        $publicKey1 = JwtKeyHelper::getRsaPublicKey();
        $kid1 = JwtKeyHelper::getRsaKeyId();

        $privateKey2 = JwtKeyHelper::getRsaPrivateKey();
        $publicKey2 = JwtKeyHelper::getRsaPublicKey();
        $kid2 = JwtKeyHelper::getRsaKeyId();

        $this->assertEquals($privateKey1, $privateKey2);
        $this->assertEquals($publicKey1, $publicKey2);
        $this->assertEquals($kid1, $kid2);
    }

    /**
     * Test hasRsaKeyPair returns true after key generation
     */
    public function testHasRsaKeyPairAfterGeneration(): void
    {
        // Force key generation
        JwtKeyHelper::getRsaPrivateKey();

        $this->assertTrue(JwtKeyHelper::hasRsaKeyPair());
    }

    /**
     * Test base64UrlEncode and base64UrlDecode are inverse operations
     */
    public function testBase64UrlEncodeDecodeAreInverse(): void
    {
        $testData = random_bytes(32);

        $encoded = JwtKeyHelper::base64UrlEncode($testData);
        $decoded = JwtKeyHelper::base64UrlDecode($encoded);

        $this->assertEquals($testData, $decoded);

        // Verify no padding in encoded
        $this->assertStringNotContainsString('=', $encoded);
    }
}
