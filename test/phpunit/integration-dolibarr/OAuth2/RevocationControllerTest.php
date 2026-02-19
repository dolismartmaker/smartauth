<?php

/**
 * Integration tests for OAuth2 Revocation Controller
 *
 * Tests the OAuth2 token revocation endpoint (RFC 7009).
 *
 * @covers \SmartAuth\Api\OAuth2\RevocationController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/RevocationController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

use SmartAuth\Api\OAuth2\RevocationController;
use SmartAuth\Api\OAuth2\ResponseException;

class RevocationControllerTest extends OAuthTestCase
{
    /**
     * @var RevocationController
     */
    private $controller;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new RevocationController($this->db);

        // Enable test mode to capture responses
        RevocationController::enableTestMode();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        RevocationController::disableTestMode();
        $this->resetSuperglobals();
        parent::tearDown();
    }

    /**
     * Reset superglobals to clean state
     */
    private function resetSuperglobals(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['PHP_AUTH_USER']);
        unset($_SERVER['PHP_AUTH_PW']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['CONTENT_TYPE']);
        $_POST = [];
    }

    /**
     * Test method must be POST
     */
    public function testMethodMustBePost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(405, $e->getStatusCode());
            $this->assertEquals('invalid_request', $e->getErrorCode());
        }
    }

    /**
     * Test missing token parameter returns error
     */
    public function testMissingTokenParameter(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertEquals('invalid_request', $e->getErrorCode());
            $this->assertStringContainsString('token', $e->getErrorDescription());
        }
    }

    /**
     * Test revocation of unknown token returns 200 OK (per RFC 7009)
     */
    public function testRevocationOfUnknownTokenReturns200(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'unknown-token-12345'];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            // Per RFC 7009, always return 200 OK
            $this->assertEquals(200, $e->getStatusCode());
            $this->assertFalse($e->isError());
        }
    }

    /**
     * Test revocation of valid refresh token
     */
    public function testRevocationOfValidRefreshToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Issue refresh token
        $tokenData = $this->createRefreshToken($client, $user, ['openid', 'profile', 'offline_access']);

        $refreshToken = $tokenData['token'];
        $this->assertNotEmpty($refreshToken);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => $refreshToken];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }

        // Verify token is revoked
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $result = $tokenRecord->fetchByToken($refreshToken);
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($tokenRecord->isRevoked());
    }

    /**
     * Test revocation with token_type_hint for refresh_token
     */
    public function testRevocationWithRefreshTokenHint(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createRefreshToken($client, $user, ['openid', 'offline_access']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'token' => $tokenData['token'],
            'token_type_hint' => 'refresh_token',
        ];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test revocation with token_type_hint for access_token
     */
    public function testRevocationWithAccessTokenHint(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken($client, $user, ['openid']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'token' => $tokenData['token'],
            'token_type_hint' => 'access_token',
        ];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test invalid token_type_hint is ignored
     */
    public function testInvalidTokenTypeHintIsIgnored(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'token' => 'some-token',
            'token_type_hint' => 'invalid_hint',
        ];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            // Should still return 200 (invalid hint is ignored per RFC)
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test revocation with client authentication via HTTP Basic
     */
    public function testRevocationWithClientAuthBasic(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createRefreshToken($client, $user, ['openid', 'offline_access']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['PHP_AUTH_USER'] = $client->client_id;
        $_SERVER['PHP_AUTH_PW'] = 'test-secret-confidential-12345';
        $_POST = ['token' => $tokenData['token']];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test revocation with client authentication via POST body
     */
    public function testRevocationWithClientAuthPost(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createRefreshToken($client, $user, ['openid', 'offline_access']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'token' => $tokenData['token'],
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret-confidential-12345',
        ];

        try {
            $this->controller->handleRevoke();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test already revoked token returns 200 OK
     */
    public function testAlreadyRevokedTokenReturns200(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createRefreshToken($client, $user, ['openid', 'offline_access']);

        // Revoke once
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => $tokenData['token']];

        try {
            $this->controller->handleRevoke();
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }

        // Revoke again - should still return 200
        try {
            $this->controller->handleRevoke();
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }

    /**
     * Test client cannot revoke another client's token
     */
    public function testClientCannotRevokeAnotherClientsToken(): void
    {
        $client1 = $this->createTestClientFromFixture('confidential');
        $client2 = $this->createTestClientFromFixture('confidential_pkce');
        $user = $this->createTestUser();

        // Issue token for client1
        $tokenData = $this->createRefreshToken($client1, $user, ['openid', 'offline_access']);

        // Try to revoke as client2
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['PHP_AUTH_USER'] = $client2->client_id;
        $_SERVER['PHP_AUTH_PW'] = 'test-secret-pkce-12345';
        $_POST = ['token' => $tokenData['token']];

        try {
            $this->controller->handleRevoke();
        } catch (ResponseException $e) {
            // Returns 200 but token should NOT be revoked
            $this->assertEquals(200, $e->getStatusCode());
        }

        // Token should still be valid (not revoked)
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $result = $tokenRecord->fetchByToken($tokenData['token']);
        $this->assertGreaterThan(0, $result);
        $this->assertFalse($tokenRecord->isRevoked());
    }
}
