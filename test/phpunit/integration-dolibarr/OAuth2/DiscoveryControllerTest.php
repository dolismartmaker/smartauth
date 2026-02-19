<?php

/**
 * Integration tests for OAuth2 Discovery Controller
 *
 * Tests the OIDC discovery endpoints.
 *
 * @covers \SmartAuth\Api\OAuth2\DiscoveryController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/DiscoveryController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

use SmartAuth\Api\OAuth2\DiscoveryController;
use SmartAuth\Api\OAuth2\ResponseException;

class DiscoveryControllerTest extends OAuthTestCase
{
    /**
     * @var DiscoveryController
     */
    private $controller;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new DiscoveryController();

        // Enable test mode to capture responses
        DiscoveryController::enableTestMode();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        // Disable test mode
        DiscoveryController::disableTestMode();

        parent::tearDown();
    }

    /**
     * Test OpenID Configuration endpoint returns valid configuration
     */
    public function testOpenidConfigurationReturnsValidConfig(): void
    {
        try {
            $this->controller->handleOpenidConfiguration();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('issuer', $body);
            $this->assertArrayHasKey('authorization_endpoint', $body);
            $this->assertArrayHasKey('token_endpoint', $body);
            $this->assertArrayHasKey('userinfo_endpoint', $body);
            $this->assertArrayHasKey('jwks_uri', $body);
            $this->assertArrayHasKey('response_types_supported', $body);
            $this->assertArrayHasKey('grant_types_supported', $body);
            $this->assertArrayHasKey('scopes_supported', $body);
        }
    }

    /**
     * Test JWKS endpoint returns valid keys or error if no keys
     */
    public function testJwksReturnsValidKeysOrError(): void
    {
        try {
            $this->controller->handleJwks();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            // May return 200 (keys found) or 500 (no keys configured)
            $this->assertContains($e->getStatusCode(), [200, 500]);

            $body = $e->getResponseBody();
            if ($e->getStatusCode() === 200) {
                $this->assertArrayHasKey('keys', $body);
                $this->assertIsArray($body['keys']);

                if (count($body['keys']) > 0) {
                    $key = $body['keys'][0];
                    $this->assertArrayHasKey('kty', $key);
                    $this->assertArrayHasKey('kid', $key);
                    $this->assertArrayHasKey('use', $key);
                }
            } else {
                $this->assertArrayHasKey('error', $body);
            }
        }
    }

    /**
     * Test route method handles openid-configuration path
     */
    public function testRouteHandlesOpenidConfiguration(): void
    {
        try {
            $result = $this->controller->route('/.well-known/openid-configuration');
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertArrayHasKey('issuer', $body);
        }
    }

    /**
     * Test route method handles jwks.json path
     */
    public function testRouteHandlesJwks(): void
    {
        try {
            $result = $this->controller->route('/.well-known/jwks.json');
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            // May return 200 or 500 depending on key configuration
            $this->assertContains($e->getStatusCode(), [200, 500]);
        }
    }

    /**
     * Test route method returns false for unknown paths
     */
    public function testRouteReturnsFalseForUnknownPath(): void
    {
        $result = $this->controller->route('/unknown/path');
        $this->assertFalse($result);
    }

    /**
     * Test route normalizes paths without leading slash
     */
    public function testRouteNormalizesPathWithoutLeadingSlash(): void
    {
        try {
            $result = $this->controller->route('.well-known/openid-configuration');
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test OpenID configuration contains required OIDC fields
     */
    public function testOpenidConfigurationContainsRequiredFields(): void
    {
        try {
            $this->controller->handleOpenidConfiguration();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $body = $e->getResponseBody();

            // Required OIDC Discovery fields
            $this->assertArrayHasKey('id_token_signing_alg_values_supported', $body);
            $this->assertContains('RS256', $body['id_token_signing_alg_values_supported']);

            $this->assertArrayHasKey('subject_types_supported', $body);
            $this->assertContains('public', $body['subject_types_supported']);

            $this->assertArrayHasKey('token_endpoint_auth_methods_supported', $body);
        }
    }
}
