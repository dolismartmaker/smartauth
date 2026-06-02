<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/OAuth2/UserinfoController.php';
require_once __DIR__ . '/../../../api/OAuth2/TokenService.php';
require_once __DIR__ . '/../../../api/OAuth2/ScopeManager.php';
require_once __DIR__ . '/../../../api/OAuth2/ResponseTrait.php';
require_once __DIR__ . '/../../../api/OAuth2/ResponseException.php';

use SmartAuth\Api\OAuth2\UserinfoController;
use SmartAuth\Api\OAuth2\TokenService;
use SmartAuth\Api\OAuth2\ResponseException;

/**
 * Integration tests for UserinfoController
 *
 * @covers \SmartAuth\Api\OAuth2\UserinfoController
 */
class UserinfoControllerIntegrationTest extends DolibarrRealTestCase
{
    private UserinfoController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new UserinfoController($this->db);

        // Enable test mode to throw ResponseException instead of exit
        UserinfoController::enableTestMode();
    }

    protected function tearDown(): void
    {
        UserinfoController::disableTestMode();
        parent::tearDown();
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Call handleUserinfo with mocked $_SERVER variables
     */
    private function callHandleUserinfo(string $method = 'GET', ?string $authHeader = null): ResponseException
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        if ($authHeader !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        try {
            $this->controller->handleUserinfo();
            throw new \RuntimeException('Expected ResponseException was not thrown');
        } catch (ResponseException $e) {
            return $e;
        }
    }

    // =========================================================================
    // HTTP Method validation tests
    // =========================================================================

    public function testHandleUserinfoRejectsInvalidMethod(): void
    {
        $response = $this->callHandleUserinfo('PUT');

        $this->assertEquals(405, $response->getStatusCode());
        $this->assertTrue($response->isError());
        $this->assertEquals('invalid_request', $response->getErrorCode());
    }

    public function testHandleUserinfoAcceptsGetMethod(): void
    {
        // Will fail because no token, but should not reject the method
        $response = $this->callHandleUserinfo('GET');

        // Should fail with 401 (no token), not 405 (wrong method)
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_token', $response->getErrorCode());
    }

    public function testHandleUserinfoAcceptsPostMethod(): void
    {
        $response = $this->callHandleUserinfo('POST');

        // Should fail with 401 (no token), not 405 (wrong method)
        $this->assertEquals(401, $response->getStatusCode());
    }

    // =========================================================================
    // Token validation tests
    // =========================================================================

    public function testHandleUserinfoRejectsMissingToken(): void
    {
        $response = $this->callHandleUserinfo('GET', null);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_token', $response->getErrorCode());
        $this->assertStringContainsString('Missing', $response->getErrorDescription());
    }

    public function testHandleUserinfoRejectsInvalidBearerFormat(): void
    {
        $response = $this->callHandleUserinfo('GET', 'Basic dXNlcjpwYXNz');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_token', $response->getErrorCode());
    }

    public function testHandleUserinfoRejectsEmptyBearerToken(): void
    {
        $response = $this->callHandleUserinfo('GET', 'Bearer ');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_token', $response->getErrorCode());
    }

    public function testHandleUserinfoRejectsInvalidToken(): void
    {
        $response = $this->callHandleUserinfo('GET', 'Bearer invalid_token_value');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_token', $response->getErrorCode());
        $this->assertStringContainsString('invalid or expired', $response->getErrorDescription());
    }

    // =========================================================================
    // Identity claims assembly
    //
    // The identity claims (sub/name/email) moved from a private
    // UserinfoController::buildClaims() to TokenSubject::buildClaims(), covered
    // by TokenSubjectBuildClaimsTest with persisted data. The admin->ROLE_ADMIN
    // mapping (which stays in UserinfoController) is asserted here via the
    // still-present getUserRoles() helper.
    // =========================================================================

    public function testAdminUserHasRoleAdmin(): void
    {
        $method = new \ReflectionMethod(UserinfoController::class, 'getUserRoles');
        $method->setAccessible(true);

        $this->testUser->admin = 1;
        $roles = $method->invoke($this->controller, $this->testUser);

        $this->assertContains('ROLE_ADMIN', $roles);
    }

    // =========================================================================
    // getUserRoles() tests via reflection
    // =========================================================================

    public function testGetUserRolesReturnsAtLeastRoleUser(): void
    {
        $method = new \ReflectionMethod(UserinfoController::class, 'getUserRoles');
        $method->setAccessible(true);

        $roles = $method->invoke($this->controller, $this->testUser);

        $this->assertIsArray($roles);
        $this->assertContains('ROLE_USER', $roles);
    }

    public function testGetUserRolesDeduplicates(): void
    {
        $method = new \ReflectionMethod(UserinfoController::class, 'getUserRoles');
        $method->setAccessible(true);

        $roles = $method->invoke($this->controller, $this->testUser);

        // Check no duplicates
        $this->assertEquals($roles, array_values(array_unique($roles)));
    }

    // =========================================================================
    // getRoleMapping() tests via reflection
    // =========================================================================

    public function testGetRoleMappingReturnsDefaultMapping(): void
    {
        $method = new \ReflectionMethod(UserinfoController::class, 'getRoleMapping');
        $method->setAccessible(true);

        $mapping = $method->invoke($this->controller);

        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('Administrateurs', $mapping);
        $this->assertEquals('ROLE_ADMIN', $mapping['Administrateurs']);
    }

    // =========================================================================
    // extractBearerToken() tests via reflection
    // =========================================================================

    public function testExtractBearerTokenFromAuthorizationHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mytoken123';

        $method = new \ReflectionMethod(UserinfoController::class, 'extractBearerToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->controller);

        $this->assertEquals('mytoken123', $token);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testExtractBearerTokenFromRedirectHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirecttoken';

        $method = new \ReflectionMethod(UserinfoController::class, 'extractBearerToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->controller);

        $this->assertEquals('redirecttoken', $token);

        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    public function testExtractBearerTokenCaseInsensitive(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer lowercase';

        $method = new \ReflectionMethod(UserinfoController::class, 'extractBearerToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->controller);

        $this->assertEquals('lowercase', $token);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testExtractBearerTokenTrimsWhitespace(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer   spacedtoken   ';

        $method = new \ReflectionMethod(UserinfoController::class, 'extractBearerToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->controller);

        $this->assertEquals('spacedtoken', $token);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testExtractBearerTokenReturnsNullForBasicAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $method = new \ReflectionMethod(UserinfoController::class, 'extractBearerToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->controller);

        $this->assertNull($token);

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}
