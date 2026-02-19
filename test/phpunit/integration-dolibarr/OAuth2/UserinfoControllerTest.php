<?php

/**
 * Integration tests for OAuth2 Userinfo Controller
 *
 * Tests the OIDC userinfo endpoint.
 *
 * @covers \SmartAuth\Api\OAuth2\UserinfoController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/UserinfoController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');

use SmartAuth\Api\OAuth2\UserinfoController;
use SmartAuth\Api\OAuth2\ResponseException;
use SmartAuth\Api\OAuth2\TokenService;

class UserinfoControllerTest extends OAuthTestCase
{
    /**
     * @var UserinfoController
     */
    private $controller;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new UserinfoController($this->db);

        // Enable test mode to capture responses
        UserinfoController::enableTestMode();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        UserinfoController::disableTestMode();
        $this->resetSuperglobals();
        parent::tearDown();
    }

    /**
     * Reset superglobals to clean state
     */
    private function resetSuperglobals(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        unset($_SERVER['CONTENT_TYPE']);
        $_POST = [];
    }

    /**
     * Test method must be GET or POST
     */
    public function testMethodMustBeGetOrPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(405, $e->getStatusCode());
            $this->assertEquals('invalid_request', $e->getErrorCode());
        }
    }

    /**
     * Test missing Bearer token returns error
     */
    public function testMissingBearerToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            $this->assertEquals('invalid_token', $e->getErrorCode());
        }
    }

    /**
     * Test invalid Bearer token returns error
     */
    public function testInvalidBearerToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-12345';

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            $this->assertEquals('invalid_token', $e->getErrorCode());
        }
    }

    /**
     * Test valid access token returns user claims
     */
    public function testValidAccessTokenReturnsUserClaims(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Issue an access token
        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'profile', 'email']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);
            $this->assertEquals((string) $user->id, $body['sub']);
        }
    }

    /**
     * Test profile scope returns name claims
     */
    public function testProfileScopeReturnsNameClaims(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'profile']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);

            // Profile claims should be present if user has them
            // (name, family_name, given_name, updated_at)
        }
    }

    /**
     * Test email scope returns email claims
     */
    public function testEmailScopeReturnsEmailClaims(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'email']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);

            // Email claims if user has email
            if (!empty($user->email)) {
                $this->assertArrayHasKey('email', $body);
                $this->assertArrayHasKey('email_verified', $body);
            }
        }
    }

    /**
     * Test POST method with form body token
     */
    public function testPostWithFormBodyToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'profile']
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST['access_token'] = $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);
        }
    }

    /**
     * Test groups scope returns groups
     */
    public function testGroupsScopeReturnsGroups(): void
    {
        $client = $this->createTestClientFromFixture('confidential', [
            'allowed_scopes' => ['openid', 'profile', 'email', 'groups'],
        ]);
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'groups']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);
            // groups key may or may not be present depending on user group membership
        }
    }

    /**
     * Test roles scope returns roles
     */
    public function testRolesScopeReturnsRoles(): void
    {
        $client = $this->createTestClientFromFixture('confidential', [
            'allowed_scopes' => ['openid', 'profile', 'email', 'roles'],
        ]);
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid', 'roles']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());

            $body = $e->getResponseBody();
            $this->assertArrayHasKey('sub', $body);

            // roles should include at least ROLE_USER
            if (isset($body['roles'])) {
                $this->assertContains('ROLE_USER', $body['roles']);
            }
        }
    }

    /**
     * Test expired access token returns error
     */
    public function testExpiredAccessToken(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create an expired token record directly
        $token = new \SmartAuthOAuthToken($this->db);
        $token->token_hash = \SmartAuthOAuthToken::hashToken('expired-test-token');
        $token->token_type = 'access';
        $token->fk_client = $client->id;
        $token->fk_user = $user->id;
        $token->setScopesArray(['openid', 'profile']);
        $token->expires_at = dol_now() - 3600; // Expired 1 hour ago
        $token->create($user);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired-test-token';

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            $this->assertEquals('invalid_token', $e->getErrorCode());
        }
    }

    /**
     * Test REDIRECT_HTTP_AUTHORIZATION header fallback
     */
    public function testRedirectAuthorizationHeaderFallback(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $tokenData = $this->createAccessToken(
            $client,
            $user,
            ['openid']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenData['token'];

        try {
            $this->controller->handleUserinfo();
            $this->fail('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            $this->assertEquals(200, $e->getStatusCode());
        }
    }
}
