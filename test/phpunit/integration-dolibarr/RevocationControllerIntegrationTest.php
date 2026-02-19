<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/OAuth2/RevocationController.php';
require_once __DIR__ . '/../../../api/OAuth2/TokenService.php';
require_once __DIR__ . '/../../../api/OAuth2/ResponseTrait.php';
require_once __DIR__ . '/../../../api/OAuth2/ResponseException.php';

use SmartAuth\Api\OAuth2\RevocationController;
use SmartAuth\Api\OAuth2\ResponseException;

/**
 * Integration tests for RevocationController
 *
 * @covers \SmartAuth\Api\OAuth2\RevocationController
 */
class RevocationControllerIntegrationTest extends DolibarrRealTestCase
{
    private RevocationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new RevocationController($this->db);

        // Enable test mode to throw ResponseException instead of exit
        RevocationController::enableTestMode();
    }

    protected function tearDown(): void
    {
        RevocationController::disableTestMode();
        // Clean up $_SERVER and $_POST
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['CONTENT_TYPE']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['PHP_AUTH_USER']);
        unset($_SERVER['PHP_AUTH_PW']);
        $_POST = [];
        parent::tearDown();
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Call handleRevoke with mocked request
     */
    private function callHandleRevoke(
        string $method = 'POST',
        array $postData = [],
        ?string $authHeader = null
    ): ResponseException {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $postData;

        if ($authHeader !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        }

        try {
            $this->controller->handleRevoke();
            throw new \RuntimeException('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            return $e;
        }
    }

    // =========================================================================
    // HTTP Method validation tests
    // =========================================================================

    public function testHandleRevokeRejectsGetMethod(): void
    {
        $response = $this->callHandleRevoke('GET');

        $this->assertEquals(405, $response->getStatusCode());
        $this->assertTrue($response->isError());
        $this->assertEquals('invalid_request', $response->getErrorCode());
    }

    public function testHandleRevokeRejectsPutMethod(): void
    {
        $response = $this->callHandleRevoke('PUT');

        $this->assertEquals(405, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
    }

    public function testHandleRevokeRejectsDeleteMethod(): void
    {
        $response = $this->callHandleRevoke('DELETE');

        $this->assertEquals(405, $response->getStatusCode());
    }

    // =========================================================================
    // Token parameter validation tests
    // =========================================================================

    public function testHandleRevokeRejectsMissingToken(): void
    {
        $response = $this->callHandleRevoke('POST', []);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $response->getErrorCode());
        $this->assertStringContainsString('token', $response->getErrorDescription());
    }

    public function testHandleRevokeRejectsEmptyToken(): void
    {
        $response = $this->callHandleRevoke('POST', ['token' => '']);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('token', $response->getErrorDescription());
    }

    public function testHandleRevokeRejectsWhitespaceOnlyToken(): void
    {
        $response = $this->callHandleRevoke('POST', ['token' => '   ']);

        $this->assertEquals(400, $response->getStatusCode());
    }

    // =========================================================================
    // RFC 7009 compliance tests - always returns 200 OK for valid tokens
    // =========================================================================

    public function testHandleRevokeReturns200ForInvalidToken(): void
    {
        // Per RFC 7009, revocation of invalid/unknown tokens returns 200 OK
        $response = $this->callHandleRevoke('POST', [
            'token' => 'nonexistent_token_value',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->isError());
    }

    public function testHandleRevokeReturns200ForExpiredToken(): void
    {
        $response = $this->callHandleRevoke('POST', [
            'token' => 'expired_token_that_no_longer_exists',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleRevokeReturns200WithTokenTypeHintAccessToken(): void
    {
        $response = $this->callHandleRevoke('POST', [
            'token' => 'some_token',
            'token_type_hint' => 'access_token',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleRevokeReturns200WithTokenTypeHintRefreshToken(): void
    {
        $response = $this->callHandleRevoke('POST', [
            'token' => 'some_token',
            'token_type_hint' => 'refresh_token',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleRevokeIgnoresInvalidTokenTypeHint(): void
    {
        // Per RFC 7009, invalid hints are ignored
        $response = $this->callHandleRevoke('POST', [
            'token' => 'some_token',
            'token_type_hint' => 'invalid_hint',
        ]);

        // Should still return 200, hint is just ignored
        $this->assertEquals(200, $response->getStatusCode());
    }

    // =========================================================================
    // Client authentication tests (optional for revocation)
    // =========================================================================

    public function testHandleRevokeWorksWithoutClientAuth(): void
    {
        // Per RFC 7009, client auth is optional
        $response = $this->callHandleRevoke('POST', [
            'token' => 'some_token',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleRevokeWorksWithInvalidClientCredentials(): void
    {
        // Per RFC 7009, invalid client credentials should not cause error
        $response = $this->callHandleRevoke('POST', [
            'token' => 'some_token',
            'client_id' => 'invalid_client',
            'client_secret' => 'wrong_secret',
        ]);

        // Should still return 200 - client auth is optional for revocation
        $this->assertEquals(200, $response->getStatusCode());
    }

    // =========================================================================
    // getClientCredentials() tests via reflection
    // =========================================================================

    public function testGetClientCredentialsFromBasicAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client_id:client_secret');
        $_POST = [];

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, []);

        $this->assertEquals('client_id', $result['client_id']);
        $this->assertEquals('client_secret', $result['client_secret']);
    }

    public function testGetClientCredentialsFromPhpAuthUser(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'php_client';
        $_SERVER['PHP_AUTH_PW'] = 'php_secret';

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, []);

        $this->assertEquals('php_client', $result['client_id']);
        $this->assertEquals('php_secret', $result['client_secret']);
    }

    public function testGetClientCredentialsFromPostBody(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['PHP_AUTH_USER']);

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, [
            'client_id' => 'post_client',
            'client_secret' => 'post_secret',
        ]);

        $this->assertEquals('post_client', $result['client_id']);
        $this->assertEquals('post_secret', $result['client_secret']);
    }

    public function testGetClientCredentialsPrioritizesBasicAuthOverPost(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('basic_client:basic_secret');

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, [
            'client_id' => 'post_client',
            'client_secret' => 'post_secret',
        ]);

        // Basic auth takes precedence
        $this->assertEquals('basic_client', $result['client_id']);
        $this->assertEquals('basic_secret', $result['client_secret']);
    }

    public function testGetClientCredentialsHandlesUrlEncodedBasicAuth(): void
    {
        // Client IDs may contain special chars that need URL encoding in Basic auth
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client%40example.com:secret%2Fvalue');

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, []);

        $this->assertEquals('client@example.com', $result['client_id']);
        $this->assertEquals('secret/value', $result['client_secret']);
    }

    public function testGetClientCredentialsReturnsNullsWhenNoneProvided(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['PHP_AUTH_USER']);

        $method = new \ReflectionMethod(RevocationController::class, 'getClientCredentials');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, []);

        $this->assertNull($result['client_id']);
        $this->assertNull($result['client_secret']);
    }

    // =========================================================================
    // parseRequestBody() tests via reflection
    // =========================================================================

    public function testParseRequestBodyReturnsPostData(): void
    {
        $_POST = ['token' => 'test_token', 'token_type_hint' => 'refresh_token'];

        $method = new \ReflectionMethod(RevocationController::class, 'parseRequestBody');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller);

        $this->assertEquals('test_token', $result['token']);
        $this->assertEquals('refresh_token', $result['token_type_hint']);
    }

    // =========================================================================
    // revokeTokenByValue() tests via reflection
    // =========================================================================

    public function testRevokeTokenByValueWithRefreshTokenHint(): void
    {
        $method = new \ReflectionMethod(RevocationController::class, 'revokeTokenByValue');
        $method->setAccessible(true);

        // Should try refresh token lookup
        $result = $method->invoke($this->controller, 'nonexistent_token', 'refresh_token');

        $this->assertFalse($result);
    }

    public function testRevokeTokenByValueWithAccessTokenHint(): void
    {
        $method = new \ReflectionMethod(RevocationController::class, 'revokeTokenByValue');
        $method->setAccessible(true);

        // Should try access token lookup
        $result = $method->invoke($this->controller, 'nonexistent_jwt', 'access_token');

        $this->assertFalse($result);
    }

    public function testRevokeTokenByValueWithoutHint(): void
    {
        $method = new \ReflectionMethod(RevocationController::class, 'revokeTokenByValue');
        $method->setAccessible(true);

        // Should try both
        $result = $method->invoke($this->controller, 'unknown_token', null);

        $this->assertFalse($result);
    }
}
