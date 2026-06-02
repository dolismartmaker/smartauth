<?php

/**
 * Integration tests for OAuth2 Client Credentials Grant
 *
 * Tests the client_credentials grant type in TokenController::handleToken().
 * Uses ResponseTrait test mode to capture responses without exit.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenController::handleClientCredentials
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/TokenController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');

use SmartAuth\Api\OAuth2\TokenController;
use SmartAuth\Api\OAuth2\TokenService;
use SmartAuth\Api\OAuth2\ResponseException;

class ClientCredentialsTest extends OAuthTestCase
{
    /**
     * @var TokenController
     */
    private $controller;

    /**
     * @var \User Service user for M2M testing
     */
    private $serviceUser;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new TokenController($this->db);
        TokenController::enableTestMode();

        // Create a dedicated service user for M2M tests
        $this->serviceUser = $this->createTestUser([
            'login' => 'service_m2m_' . uniqid(),
            'lastname' => 'Service',
            'firstname' => 'M2M',
            'email' => 'service_m2m_' . uniqid() . '@example.com',
        ]);
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        TokenController::disableTestMode();
        $this->resetSuperglobals();

        // Reset global default user config
        global $conf;
        unset($conf->global->SMARTAUTH_DEFAULT_USER);

        parent::tearDown();
    }

    /**
     * Reset superglobals to default state
     */
    private function resetSuperglobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE'] = '';
        $_SERVER['PHP_AUTH_USER'] = '';
        $_SERVER['PHP_AUTH_PW'] = '';
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        $_POST = [];
    }

    /**
     * Simulate a POST request to the token endpoint
     */
    private function simulateTokenRequest(array $params, ?string $clientId = null, ?string $clientSecret = null): ResponseException
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        if ($clientId !== null) {
            $_SERVER['PHP_AUTH_USER'] = $clientId;
            $_SERVER['PHP_AUTH_PW'] = $clientSecret ?? '';
        }

        $_POST = $params;

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            return $e;
        }
    }

    /**
     * Create a M2M client with a service user
     */
    private function createM2MClient(array $overrides = []): \SmartAuthOAuthClient
    {
        $defaults = [
            'fk_service_user' => $this->serviceUser->id,
        ];

        return $this->createTestClientFromFixture('client_credentials', array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Success cases
    // =========================================================================

    /**
     * Test client_credentials grant - success with all client scopes (no scope param)
     */
    public function testClientCredentialsSuccess(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('token_type', $body);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertArrayHasKey('scope', $body);
        $this->assertEquals('Bearer', $body['token_type']);
        $this->assertEquals(3600, $body['expires_in']);
    }

    /**
     * Test client_credentials grant - success with explicit scope subset
     */
    public function testClientCredentialsWithScopeSubset(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'client_credentials',
                'scope' => 'openid profile',
            ],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertStringContainsString('openid', $body['scope']);
        $this->assertStringContainsString('profile', $body['scope']);
        $this->assertStringNotContainsString('email', $body['scope']);
    }

    /**
     * Test client_credentials grant - all client scopes returned when no scope param
     */
    public function testClientCredentialsDefaultScopes(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        // Fixture has allowed_scopes: openid, profile, email
        $scopeList = explode(' ', $body['scope']);
        $this->assertContains('openid', $scopeList);
        $this->assertContains('profile', $scopeList);
        $this->assertContains('email', $scopeList);
    }

    /**
     * Test client_credentials grant - access token stored in database
     */
    public function testClientCredentialsTokenStoredInDatabase(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Verify access token record exists in database
        $this->assertTokenExists('access', $client->id, $this->serviceUser->id);
    }

    /**
     * Test client_credentials grant - JWT contains grant_type claim
     */
    public function testClientCredentialsJwtContainsGrantType(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $accessToken = $body['access_token'];

        // Decode the JWT payload (without signature verification, just read claims)
        $parts = explode('.', $accessToken);
        $this->assertCount(3, $parts, 'Access token should be a valid JWT with 3 parts');

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('grant_type', $payload);
        $this->assertEquals('client_credentials', $payload['grant_type']);
        $this->assertEquals($client->client_id, $payload['client_id']);
        $this->assertEquals('usr:' . $this->serviceUser->id, $payload['sub']);
    }

    /**
     * Test client_credentials grant - JWT validates correctly via TokenService
     */
    public function testClientCredentialsJwtValidatesCorrectly(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();

        // Validate the token using TokenService
        $payload = $this->tokenService->validateAccessToken($body['access_token']);
        $this->assertNotNull($payload, 'Access token should be valid');
        $this->assertEquals('client_credentials', $payload['grant_type']);
        $this->assertEquals($client->client_id, $payload['client_id']);
    }

    // =========================================================================
    // Response format: no refresh_token, no id_token (RFC 6749 Section 4.4.3)
    // =========================================================================

    /**
     * Test client_credentials grant - response does NOT contain refresh_token
     */
    public function testClientCredentialsNoRefreshToken(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayNotHasKey('refresh_token', $body, 'client_credentials response must NOT contain refresh_token');
    }

    /**
     * Test client_credentials grant - response does NOT contain id_token
     */
    public function testClientCredentialsNoIdToken(): void
    {
        $client = $this->createM2MClient([
            'allowed_scopes' => ['openid', 'profile', 'email'],
        ]);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'client_credentials',
                'scope' => 'openid profile',
            ],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayNotHasKey('id_token', $body, 'client_credentials response must NOT contain id_token');
    }

    /**
     * Test client_credentials grant - no refresh token stored in database
     */
    public function testClientCredentialsNoRefreshTokenInDatabase(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $refreshCount = $this->countTokensForUserClient($this->serviceUser->id, $client->id, 'refresh');
        $this->assertEquals(0, $refreshCount, 'No refresh token should be stored for client_credentials');
    }

    // =========================================================================
    // Service user resolution
    // =========================================================================

    /**
     * Test client_credentials grant - uses fk_service_user from client
     */
    public function testClientCredentialsUsesClientServiceUser(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Verify token was created for the service user
        $accessCount = $this->countTokensForUserClient($this->serviceUser->id, $client->id, 'access');
        $this->assertEquals(1, $accessCount);
    }

    /**
     * Test client_credentials grant - falls back to SMARTAUTH_DEFAULT_USER
     */
    public function testClientCredentialsFallbackToDefaultUser(): void
    {
        global $conf;

        // Create client WITHOUT fk_service_user
        $client = $this->createTestClientFromFixture('client_credentials_no_user');

        // Set global default user
        $conf->global->SMARTAUTH_DEFAULT_USER = $this->serviceUser->id;

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-nouser-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Verify token was created for the default user
        $accessCount = $this->countTokensForUserClient($this->serviceUser->id, $client->id, 'access');
        $this->assertEquals(1, $accessCount);
    }

    /**
     * Test client_credentials grant - no service user configured returns server_error
     */
    public function testClientCredentialsNoServiceUserConfigured(): void
    {
        global $conf;

        // Create client WITHOUT fk_service_user
        $client = $this->createTestClientFromFixture('client_credentials_no_user');

        // Ensure no global default
        unset($conf->global->SMARTAUTH_DEFAULT_USER);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-nouser-12345'
        );

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('server_error', $response->getErrorCode());
        $this->assertStringContainsString('service user', $response->getErrorDescription());
    }

    // =========================================================================
    // Error cases
    // =========================================================================

    /**
     * Test client_credentials grant - public client rejected
     */
    public function testClientCredentialsPublicClientRejected(): void
    {
        // Create a public client that allows client_credentials
        $client = $this->createTestClient([
            'client_id' => 'public-m2m-client',
            'allowed_grants' => ['client_credentials'],
            'allowed_scopes' => ['openid', 'profile'],
            'is_confidential' => 0,
            'fk_service_user' => $this->serviceUser->id,
        ]);

        // Public client authenticates via POST body (no secret)
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
        ];

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertEquals('unauthorized_client', $e->getErrorCode());
            $this->assertStringContainsString('Public clients', $e->getErrorDescription());
        }
    }

    /**
     * Test client_credentials grant - grant type not allowed for client
     */
    public function testClientCredentialsGrantNotAllowed(): void
    {
        // Confidential client that does NOT allow client_credentials
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('unauthorized_client', $response->getErrorCode());
    }

    /**
     * Test client_credentials grant - invalid scope requested
     */
    public function testClientCredentialsInvalidScope(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'client_credentials',
                'scope' => 'openid profile admin_all',
            ],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_scope', $response->getErrorCode());
        $this->assertStringContainsString('admin_all', $response->getErrorDescription());
    }

    /**
     * Test client_credentials grant - scope not in client's allowed scopes
     */
    public function testClientCredentialsScopeNotAllowed(): void
    {
        $client = $this->createM2MClient();

        // groups is a valid scope but not in the client's allowed_scopes (openid, profile, email)
        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'client_credentials',
                'scope' => 'openid groups',
            ],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_scope', $response->getErrorCode());
        $this->assertStringContainsString('groups', $response->getErrorDescription());
    }

    /**
     * Test client_credentials grant - disabled client rejected
     */
    public function testClientCredentialsDisabledClient(): void
    {
        $client = $this->createM2MClient([
            'status' => 0,
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_client', $response->getErrorCode());
        $this->assertStringContainsString('disabled', $response->getErrorDescription());
    }

    /**
     * Test client_credentials grant - wrong secret
     */
    public function testClientCredentialsWrongSecret(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'wrong-secret'
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_client', $response->getErrorCode());
    }

    // =========================================================================
    // Client authentication methods
    // =========================================================================

    /**
     * Test client_credentials grant - authentication via POST body
     */
    public function testClientCredentialsAuthViaPostBody(): void
    {
        $client = $this->createM2MClient();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['PHP_AUTH_USER'] = '';
        $_SERVER['PHP_AUTH_PW'] = '';

        $_POST = [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret-m2m-12345',
        ];

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertArrayHasKey('access_token', $body);
            $this->assertEquals('Bearer', $body['token_type']);
        }
    }

    /**
     * Test client_credentials grant - authentication via HTTP Basic Auth
     */
    public function testClientCredentialsAuthViaBasicAuth(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayHasKey('access_token', $response->getResponseBody());
    }

    // =========================================================================
    // Token revocation
    // =========================================================================

    /**
     * Test client_credentials token can be revoked via JTI
     */
    public function testClientCredentialsTokenRevocation(): void
    {
        $client = $this->createM2MClient();

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $accessToken = $body['access_token'];

        // Extract JTI from JWT
        $parts = explode('.', $accessToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $jti = $payload['jti'];

        // Revoke using the token service
        $revoked = $this->tokenService->revokeToken($jti, 'access_token');
        $this->assertTrue($revoked, 'Token should be revoked successfully');

        // Validate should now fail
        $validated = $this->tokenService->validateAccessToken($accessToken);
        $this->assertNull($validated, 'Revoked token should not validate');
    }

    // =========================================================================
    // Multiple token issuance
    // =========================================================================

    /**
     * Test client_credentials grant - multiple tokens can be issued for same client
     */
    public function testClientCredentialsMultipleTokens(): void
    {
        $client = $this->createM2MClient();

        // Issue first token
        $response1 = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );
        $this->assertEquals(200, $response1->getStatusCode());

        // Issue second token
        $response2 = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );
        $this->assertEquals(200, $response2->getStatusCode());

        // Both tokens should be different
        $body1 = $response1->getResponseBody();
        $body2 = $response2->getResponseBody();
        $this->assertNotEquals($body1['access_token'], $body2['access_token']);

        // Both should be stored in database
        $accessCount = $this->countTokensForUserClient($this->serviceUser->id, $client->id, 'access');
        $this->assertEquals(2, $accessCount);
    }

    /**
     * Test client_credentials grant - custom token lifetime from client config
     */
    public function testClientCredentialsCustomLifetime(): void
    {
        $client = $this->createM2MClient([
            'access_token_lifetime' => 7200, // 2 hours
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-m2m-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertEquals(7200, $body['expires_in']);
    }
}
