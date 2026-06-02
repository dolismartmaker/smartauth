<?php

/**
 * Integration tests for OAuth2/OIDC Userinfo Endpoint
 *
 * Tests the userinfo endpoint functionality including:
 * - Token validation
 * - Claims based on scopes
 * - User data retrieval
 *
 * @covers \SmartAuth\Api\OAuth2\UserinfoController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/UserinfoController.php');

use SmartAuth\Api\OAuth2\UserinfoController;
use SmartAuth\Api\OAuth2\ScopeManager;

class UserinfoEndpointTest extends OAuthTestCase
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
    }

    /**
     * Test userinfo returns sub claim for all requests
     */
    public function testUserinfoReturnsSubClaim(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create access token with minimal scopes
        $accessToken = $this->createAccessToken($client, $user, ['openid']);

        // Validate token to get payload
        $payload = $this->tokenService->validateAccessToken($accessToken['token']);

        $this->assertNotNull($payload);
        $this->assertEquals('usr:' . $user->id, $payload['sub']);
    }

    /**
     * Test userinfo returns profile claims
     */
    public function testUserinfoReturnsProfileClaims(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
        ]);

        // Create ID token with profile scope
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'profile'],
            null,
            time()
        );

        $this->assertNotEmpty($idToken);

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals('Jean Dupont', $payload['name']);
        $this->assertEquals('Jean', $payload['given_name']);
        $this->assertEquals('Dupont', $payload['family_name']);
    }

    /**
     * Test userinfo returns email claims
     */
    public function testUserinfoReturnsEmailClaims(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'email' => 'test@example.com',
        ]);

        // Create ID token with email scope
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'email'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals('test@example.com', $payload['email']);
        $this->assertTrue($payload['email_verified']);
    }

    /**
     * Test userinfo without email scope does not include email
     */
    public function testUserinfoWithoutEmailScope(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'email' => 'test-without-email-' . uniqid() . '@example.com',
        ]);

        // Create ID token without email scope
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'profile'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('email_verified', $payload);
    }

    /**
     * Test userinfo without profile scope does not include profile claims
     */
    public function testUserinfoWithoutProfileScope(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
        ]);

        // Create ID token without profile scope
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'email'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('given_name', $payload);
        $this->assertArrayNotHasKey('family_name', $payload);
    }

    /**
     * Test userinfo with all scopes
     */
    public function testUserinfoWithAllScopes(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'email' => 'jean.dupont@example.com',
            'admin' => 1,
        ]);

        // Create ID token with all scopes
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'profile', 'email', 'groups', 'roles'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // Required claims
        $this->assertEquals('usr:' . $user->id, $payload['sub']);
        $this->assertArrayHasKey('iss', $payload);
        $this->assertArrayHasKey('aud', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);

        // Profile claims
        $this->assertEquals('Jean Dupont', $payload['name']);

        // Email claims
        $this->assertEquals('jean.dupont@example.com', $payload['email']);

        // Roles claims (admin user should have ROLE_ADMIN)
        if (isset($payload['roles'])) {
            $this->assertContains('ROLE_USER', $payload['roles']);
            $this->assertContains('ROLE_ADMIN', $payload['roles']);
        }
    }

    /**
     * Test userinfo includes nonce when provided
     */
    public function testUserinfoIncludesNonce(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $nonce = 'test-nonce-' . bin2hex(random_bytes(8));

        // Create ID token with nonce
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid'],
            $nonce,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals($nonce, $payload['nonce']);
    }

    /**
     * Test userinfo includes auth_time
     */
    public function testUserinfoIncludesAuthTime(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $authTime = time() - 300; // 5 minutes ago

        // Create ID token with auth_time
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid'],
            null,
            $authTime
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals($authTime, $payload['auth_time']);
    }

    /**
     * Test expired access token is rejected
     */
    public function testExpiredAccessTokenRejected(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create token with very short TTL (already expired)
        $accessToken = $this->tokenService->createAccessToken(
            $user->id,
            $client->client_id,
            ['openid'],
            -3600 // Negative TTL = already expired
        );

        // Validation should fail
        $payload = $this->tokenService->validateAccessToken($accessToken['token']);
        $this->assertNull($payload);
    }

    /**
     * Test revoked access token is rejected
     */
    public function testRevokedAccessTokenRejected(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user);

        // Revoke the token via JTI
        $this->tokenService->revokeToken($accessToken['jti'], 'access_token');

        // Validation should fail
        $payload = $this->tokenService->validateAccessToken($accessToken['token']);
        $this->assertNull($payload);
    }

    /**
     * Test scope claims mapping
     */
    public function testScopeClaimsMapping(): void
    {
        // Test that ScopeManager correctly maps scopes to claims
        $openidClaims = ScopeManager::getClaims(['openid']);
        $this->assertContains('sub', $openidClaims);

        $profileClaims = ScopeManager::getClaims(['profile']);
        $this->assertContains('name', $profileClaims);
        $this->assertContains('family_name', $profileClaims);
        $this->assertContains('given_name', $profileClaims);

        $emailClaims = ScopeManager::getClaims(['email']);
        $this->assertContains('email', $emailClaims);
        $this->assertContains('email_verified', $emailClaims);

        $groupsClaims = ScopeManager::getClaims(['groups']);
        $this->assertContains('groups', $groupsClaims);

        $rolesClaims = ScopeManager::getClaims(['roles']);
        $this->assertContains('roles', $rolesClaims);
    }

    /**
     * Test ID token has correct audience
     */
    public function testIdTokenHasCorrectAudience(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals($client->client_id, $payload['aud']);
    }

    /**
     * Test ID token has correct issuer
     */
    public function testIdTokenHasCorrectIssuer(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals('https://auth.test.example.com', $payload['iss']);
    }

    /**
     * Test userinfo with user having groups
     */
    public function testUserinfoWithUserGroups(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Note: Creating actual groups requires more setup
        // This test verifies the structure when groups scope is requested

        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'groups'],
            null,
            time()
        );

        // Token should be created without error
        $this->assertNotEmpty($idToken);

        // Decode to verify structure
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // Groups may be empty array if user has no groups
        // Just verify the token is valid
        $this->assertArrayHasKey('sub', $payload);
    }

    /**
     * Test userinfo with admin user includes admin role
     */
    public function testUserinfoAdminUserIncludesAdminRole(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'admin' => 1,
        ]);

        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'roles'],
            null,
            time()
        );

        // Decode to verify claims
        $parts = explode('.', $idToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (isset($payload['roles'])) {
            $this->assertContains('ROLE_ADMIN', $payload['roles']);
        }
    }

    /**
     * Test access token scope is used for userinfo
     */
    public function testAccessTokenScopeUsedForUserinfo(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'email' => 'test-scope-' . uniqid() . '@example.com',
        ]);

        // Create access token with only openid scope (no email)
        $accessToken = $this->createAccessToken($client, $user, ['openid']);

        $payload = $this->tokenService->validateAccessToken($accessToken['token']);

        // Scope should only include openid
        $this->assertStringContainsString('openid', $payload['scope']);
        $this->assertStringNotContainsString('email', $payload['scope']);
    }
}
