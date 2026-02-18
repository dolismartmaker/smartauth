<?php

/**
 * Integration tests for OAuth2 Token Controller
 *
 * Tests the HTTP layer of TokenController::handleToken() method.
 * Uses ResponseTrait test mode to capture responses without exit.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/TokenController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

use SmartAuth\Api\OAuth2\TokenController;
use SmartAuth\Api\OAuth2\ResponseException;

class TokenControllerTest extends OAuthTestCase
{
    /**
     * @var TokenController
     */
    private $controller;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new TokenController($this->db);

        // Enable test mode to capture responses
        TokenController::enableTestMode();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        // Disable test mode
        TokenController::disableTestMode();

        // Restore superglobals
        $this->resetSuperglobals();

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
     * Test method must be POST
     */
    public function testMethodMustBePost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(405, $e->getStatusCode());
            $this->assertEquals('invalid_request', $e->getErrorCode());
            $this->assertStringContainsString('POST', $e->getErrorDescription());
        }
    }

    /**
     * Test content type must be form-urlencoded
     */
    public function testContentTypeMustBeFormUrlencoded(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertEquals('invalid_request', $e->getErrorCode());
            $this->assertStringContainsString('Content-Type', $e->getErrorDescription());
        }
    }

    /**
     * Test missing grant_type parameter
     */
    public function testMissingGrantType(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            [], // No grant_type
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
        $this->assertStringContainsString('grant_type', $response->getErrorDescription());
    }

    /**
     * Test invalid client authentication
     */
    public function testInvalidClientAuthentication(): void
    {
        $response = $this->simulateTokenRequest(
            ['grant_type' => 'authorization_code'],
            'non-existent-client',
            'wrong-secret'
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_client', $response->getErrorCode());
    }

    /**
     * Test wrong client secret
     */
    public function testWrongClientSecret(): void
    {
        $client = $this->createTestClient([
            'client_secret' => 'correct-secret',
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'authorization_code'],
            $client->client_id,
            'wrong-secret'
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_client', $response->getErrorCode());
    }

    /**
     * Test disabled client
     */
    public function testDisabledClient(): void
    {
        $client = $this->createTestClient([
            'status' => 0, // Disabled
            'client_secret' => 'test-secret-confidential-12345',
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'authorization_code'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_client', $response->getErrorCode());
        $this->assertStringContainsString('disabled', $response->getErrorDescription());
    }

    /**
     * Test grant type not allowed for client
     */
    public function testGrantTypeNotAllowed(): void
    {
        // Create client that only allows authorization_code
        $client = $this->createTestClient([
            'allowed_grants' => ['authorization_code'],
            'client_secret' => 'test-secret-confidential-12345',
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'refresh_token'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('unauthorized_client', $response->getErrorCode());
    }

    /**
     * Test unsupported grant type (grant allowed by client but not supported by server)
     */
    public function testUnsupportedGrantType(): void
    {
        // Create client that allows client_credentials (not supported by server)
        $client = $this->createTestClientFromFixture('confidential', [
            'allowed_grants' => ['authorization_code', 'refresh_token', 'client_credentials'],
        ]);

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('unsupported_grant_type', $response->getErrorCode());
    }

    /**
     * Test unauthorized client (grant type not allowed for this client)
     */
    public function testUnauthorizedClientGrantType(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        // client_credentials is not in client's allowed_grants
        $response = $this->simulateTokenRequest(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('unauthorized_client', $response->getErrorCode());
    }

    /**
     * Test authorization_code grant - missing code parameter
     */
    public function testAuthorizationCodeMissingCode(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'redirect_uri' => 'https://app.example.com/callback',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
        $this->assertStringContainsString('code', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - missing redirect_uri parameter
     */
    public function testAuthorizationCodeMissingRedirectUri(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => 'some-code',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
        $this->assertStringContainsString('redirect_uri', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - invalid code
     */
    public function testAuthorizationCodeInvalidCode(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => 'invalid-code-that-does-not-exist',
                'redirect_uri' => 'https://app.example.com/callback',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
    }

    /**
     * Test authorization_code grant - expired code
     */
    public function testAuthorizationCodeExpiredCode(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $expiredCode = $this->createExpiredAuthorizationCode($client, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $expiredCode['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
        $this->assertStringContainsString('expired', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - code belongs to different client
     */
    public function testAuthorizationCodeClientMismatch(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1', 'client_secret' => 'secret-1']);
        $client2 = $this->createTestClient(['client_id' => 'client-2', 'client_secret' => 'secret-2']);
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client1, $user);

        // Try to use code with different client
        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client1->getRedirectUrisArray()[0],
            ],
            $client2->client_id,
            'secret-2'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
    }

    /**
     * Test authorization_code grant - redirect_uri mismatch
     */
    public function testAuthorizationCodeRedirectUriMismatch(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => 'https://different-app.example.com/callback',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
        $this->assertStringContainsString('Redirect URI', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - code already used
     */
    public function testAuthorizationCodeAlreadyUsed(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);

        // Mark code as used
        $codeData['record']->markAsUsed();

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
        $this->assertStringContainsString('already been used', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - PKCE required but missing verifier
     */
    public function testAuthorizationCodePkceMissingVerifier(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create code with PKCE challenge
        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
                // Missing code_verifier
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
        $this->assertStringContainsString('code_verifier', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - PKCE invalid verifier
     */
    public function testAuthorizationCodePkceInvalidVerifier(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
                'code_verifier' => 'wrong-verifier-that-does-not-match',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
        $this->assertStringContainsString('verifier', $response->getErrorDescription());
    }

    /**
     * Test authorization_code grant - success
     */
    public function testAuthorizationCodeSuccess(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid', 'profile', 'offline_access'],
        ]);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('token_type', $body);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertArrayHasKey('scope', $body);
        $this->assertArrayHasKey('id_token', $body);
        $this->assertEquals('Bearer', $body['token_type']);
    }

    /**
     * Test authorization_code grant with PKCE - success
     */
    public function testAuthorizationCodeWithPkceSuccess(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user, [
            'scopes' => ['openid', 'profile'],
        ]);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'authorization_code',
                'code' => $codeData['code'],
                'redirect_uri' => $client->getRedirectUrisArray()[0],
                'code_verifier' => $codeData['verifier'],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayHasKey('access_token', $body);
        $this->assertEquals('Bearer', $body['token_type']);
    }

    /**
     * Test refresh_token grant - missing refresh_token parameter
     */
    public function testRefreshTokenMissingToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            ['grant_type' => 'refresh_token'],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
        $this->assertStringContainsString('refresh_token', $response->getErrorDescription());
    }

    /**
     * Test refresh_token grant - invalid token
     */
    public function testRefreshTokenInvalidToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => 'invalid-token',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
    }

    /**
     * Test refresh_token grant - expired token
     */
    public function testRefreshTokenExpiredToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $expiredToken = $this->createExpiredRefreshToken($client, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $expiredToken['token'],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
    }

    /**
     * Test refresh_token grant - token belongs to different client
     */
    public function testRefreshTokenClientMismatch(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1', 'client_secret' => 'secret-1']);
        $client2 = $this->createTestClient(['client_id' => 'client-2', 'client_secret' => 'secret-2']);
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client1, $user);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken['token'],
            ],
            $client2->client_id,
            'secret-2'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $response->getErrorCode());
    }

    /**
     * Test refresh_token grant - success
     */
    public function testRefreshTokenSuccess(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user, ['openid', 'profile', 'offline_access']);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken['token'],
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertEquals('Bearer', $body['token_type']);

        // New refresh token should be different
        $this->assertNotEquals($refreshToken['token'], $body['refresh_token']);
    }

    /**
     * Test refresh_token grant - scope reduction
     */
    public function testRefreshTokenScopeReduction(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user, ['openid', 'profile', 'email', 'offline_access']);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken['token'],
                'scope' => 'openid profile',
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = $response->getResponseBody();
        $this->assertStringContainsString('openid', $body['scope']);
        $this->assertStringContainsString('profile', $body['scope']);
        $this->assertStringNotContainsString('email', $body['scope']);
    }

    /**
     * Test refresh_token grant - scope expansion not allowed
     */
    public function testRefreshTokenScopeExpansionNotAllowed(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user, ['openid', 'profile', 'offline_access']);

        $response = $this->simulateTokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken['token'],
                'scope' => 'openid profile email', // email was not in original grant
            ],
            $client->client_id,
            'test-secret-confidential-12345'
        );

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_scope', $response->getErrorCode());
    }

    /**
     * Test client authentication via POST body
     */
    public function testClientAuthenticationViaPostBody(): void
    {
        $client = $this->createTestClient([
            'client_secret' => 'test-secret-confidential-12345',
        ]);
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['PHP_AUTH_USER'] = '';
        $_SERVER['PHP_AUTH_PW'] = '';

        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $codeData['code'],
            'redirect_uri' => $client->getRedirectUrisArray()[0],
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret-confidential-12345',
        ];

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
            $this->assertArrayHasKey('access_token', $e->getResponseBody());
        }
    }

    /**
     * Test public client (no secret required)
     */
    public function testPublicClientNoSecretRequired(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $codeData['code'],
            'redirect_uri' => $client->getRedirectUrisArray()[0],
            'client_id' => $client->client_id,
            'code_verifier' => $codeData['verifier'],
        ];

        try {
            $this->controller->handleToken();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
            $this->assertArrayHasKey('access_token', $e->getResponseBody());
        }
    }
}
