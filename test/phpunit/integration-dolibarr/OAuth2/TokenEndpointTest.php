<?php

/**
 * Integration tests for OAuth2 Token Endpoint
 *
 * Tests the token exchange functionality including:
 * - Authorization code grant
 * - Refresh token grant
 * - Client authentication (Basic and POST body)
 * - Token generation and storage
 * - Refresh token rotation
 *
 * @covers \SmartAuth\Api\OAuth2\TokenController
 * @covers \SmartAuth\Api\OAuth2\TokenService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/api/OAuth2/TokenController.php';

use SmartAuth\Api\OAuth2\TokenController;
use SmartAuth\Api\OAuth2\ScopeManager;

class TokenEndpointTest extends OAuthTestCase
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
    }

    /**
     * Test access token creation
     */
    public function testAccessTokenCreation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user, ['openid', 'profile']);

        $this->assertNotEmpty($accessToken['token']);
        $this->assertNotEmpty($accessToken['jti']);
        $this->assertGreaterThan(0, $accessToken['expires_in']);
    }

    /**
     * Test access token is JWT format
     */
    public function testAccessTokenIsJWT(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user);

        // JWT has 3 parts separated by dots
        $parts = explode('.', $accessToken['token']);
        $this->assertCount(3, $parts);

        // Each part should be base64url encoded
        foreach ($parts as $part) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $part);
        }
    }

    /**
     * Test access token validation
     */
    public function testAccessTokenValidation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user, ['openid', 'profile']);

        // Validate the token
        $payload = $this->tokenService->validateAccessToken($accessToken['token']);

        $this->assertNotNull($payload);
        $this->assertEquals((string) $user->id, $payload['sub']);
        $this->assertEquals($client->client_id, $payload['client_id']);
        $this->assertStringContainsString('openid', $payload['scope']);
    }

    /**
     * Test access token with invalid signature fails validation
     */
    public function testAccessTokenInvalidSignature(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user);

        // Tamper with the token
        $tamperedToken = $accessToken['token'] . 'tampered';

        $payload = $this->tokenService->validateAccessToken($tamperedToken);

        $this->assertNull($payload);
    }

    /**
     * Test refresh token creation
     */
    public function testRefreshTokenCreation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user, ['openid', 'profile', 'offline_access']);

        $this->assertNotEmpty($refreshToken['token']);
        $this->assertStringStartsWith('smartauth_rt_', $refreshToken['token']);
        $this->assertGreaterThan(0, $refreshToken['token_id']);
    }

    /**
     * Test refresh token validation
     */
    public function testRefreshTokenValidation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        $tokenRecord = $this->tokenService->validateRefreshToken($refreshToken['token']);

        $this->assertNotNull($tokenRecord);
        $this->assertEquals($user->id, $tokenRecord->fk_user);
        $this->assertEquals($client->id, $tokenRecord->fk_client);
        $this->assertTrue($tokenRecord->isRefreshToken());
    }

    /**
     * Test invalid refresh token fails validation
     */
    public function testInvalidRefreshTokenValidation(): void
    {
        $tokenRecord = $this->tokenService->validateRefreshToken('invalid-token');

        $this->assertNull($tokenRecord);
    }

    /**
     * Test expired refresh token fails validation
     */
    public function testExpiredRefreshTokenValidation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $expiredToken = $this->createExpiredRefreshToken($client, $user);

        $tokenRecord = $this->tokenService->validateRefreshToken($expiredToken['token']);

        $this->assertNull($tokenRecord);
    }

    /**
     * Test refresh token rotation
     */
    public function testRefreshTokenRotation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create initial refresh token
        $originalToken = $this->createRefreshToken($client, $user);

        // Validate and get token record
        $tokenRecord = $this->tokenService->validateRefreshToken($originalToken['token']);

        // Rotate the token
        $newToken = $this->tokenService->rotateRefreshToken($tokenRecord);

        // New token should be different
        $this->assertNotEquals($originalToken['token'], $newToken['token']);

        // Old token should be revoked
        $oldTokenRecord = new \SmartAuthOAuthToken($this->db);
        $oldTokenRecord->fetch($originalToken['token_id']);
        $this->assertTrue($oldTokenRecord->isRevoked());

        // New token should be valid
        $newTokenRecord = $this->tokenService->validateRefreshToken($newToken['token']);
        $this->assertNotNull($newTokenRecord);
    }

    /**
     * Test authorization code exchange creates tokens
     */
    public function testAuthorizationCodeExchangeCreatesTokens(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create authorization code
        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid', 'profile', 'offline_access'],
        ]);

        // Verify code is not used
        $this->assertFalse($codeData['record']->isUsed());

        // Exchange code for tokens (simulated)
        $accessToken = $this->createAccessToken($client, $user, ['openid', 'profile', 'offline_access']);
        $refreshToken = $this->createRefreshToken($client, $user, ['openid', 'profile', 'offline_access']);

        // Mark code as used
        $codeData['record']->markAsUsed();

        // Verify tokens exist
        $this->assertNotEmpty($accessToken['token']);
        $this->assertNotEmpty($refreshToken['token']);

        // Verify code is now used
        $codeData['record']->fetch($codeData['record']->id);
        $this->assertTrue($codeData['record']->isUsed());
    }

    /**
     * Test expired code cannot be exchanged
     */
    public function testExpiredCodeCannotBeExchanged(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $expiredCode = $this->createExpiredAuthorizationCode($client, $user);

        $this->assertTrue($expiredCode['record']->isExpired());
        $this->assertFalse($expiredCode['record']->isValid());
    }

    /**
     * Test used code cannot be reused
     */
    public function testUsedCodeCannotBeReused(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);

        // Mark code as used
        $codeData['record']->markAsUsed();

        // Verify code is invalid
        $this->assertTrue($codeData['record']->isUsed());
        $this->assertFalse($codeData['record']->isValid());
    }

    /**
     * Test code belongs to correct client
     */
    public function testCodeBelongsToCorrectClient(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1']);
        $client2 = $this->createTestClient(['client_id' => 'client-2']);
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client1, $user);

        $this->assertEquals($client1->id, $codeData['record']->fk_client);
        $this->assertNotEquals($client2->id, $codeData['record']->fk_client);
    }

    /**
     * Test refresh token maintains scopes
     */
    public function testRefreshTokenMaintainsScopes(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $scopes = ['openid', 'profile', 'email', 'offline_access'];
        $refreshToken = $this->createRefreshToken($client, $user, $scopes);

        $tokenRecord = $this->tokenService->validateRefreshToken($refreshToken['token']);

        $storedScopes = $tokenRecord->getScopesArray();
        foreach ($scopes as $scope) {
            $this->assertContains($scope, $storedScopes);
        }
    }

    /**
     * Test scope reduction on refresh
     */
    public function testScopeReductionOnRefresh(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $originalScopes = ['openid', 'profile', 'email', 'offline_access'];
        $refreshToken = $this->createRefreshToken($client, $user, $originalScopes);

        // Validate original token
        $tokenRecord = $this->tokenService->validateRefreshToken($refreshToken['token']);

        // Rotate with reduced scopes
        $reducedScopes = ['openid', 'profile'];
        $newToken = $this->tokenService->rotateRefreshToken($tokenRecord, $reducedScopes);

        // Verify new token has reduced scopes
        $newTokenRecord = $this->tokenService->validateRefreshToken($newToken['token']);
        $newScopes = $newTokenRecord->getScopesArray();

        $this->assertContains('openid', $newScopes);
        $this->assertContains('profile', $newScopes);
        $this->assertNotContains('email', $newScopes);
    }

    /**
     * Test token stores are created in database
     */
    public function testTokenStoresAreCreatedInDatabase(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user);

        $this->assertTokenExists('access', $client->id, $user->id);
    }

    /**
     * Test multiple tokens can exist for same user/client
     */
    public function testMultipleTokensForSameUserClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create multiple tokens
        $this->createAccessToken($client, $user);
        $this->createAccessToken($client, $user);
        $this->createRefreshToken($client, $user);

        $accessCount = $this->countTokensForUserClient($user->id, $client->id, 'access');
        $refreshCount = $this->countTokensForUserClient($user->id, $client->id, 'refresh');

        $this->assertEquals(2, $accessCount);
        $this->assertEquals(1, $refreshCount);
    }

    /**
     * Test ID token is generated for openid scope
     */
    public function testIdTokenGeneratedForOpenidScope(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser([
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        // Create ID token
        $idToken = $this->tokenService->createIdToken(
            $user->id,
            $client->client_id,
            ['openid', 'profile', 'email'],
            'test-nonce-123',
            time(),
            null
        );

        $this->assertNotEmpty($idToken);

        // ID token should be JWT
        $parts = explode('.', $idToken);
        $this->assertCount(3, $parts);
    }

    /**
     * Test client authentication with basic auth
     */
    public function testClientAuthenticationBasic(): void
    {
        $client = $this->createTestClient([
            'client_secret' => 'test-secret-123',
        ]);

        // Verify secret
        $this->assertTrue($client->verifySecret('test-secret-123'));
        $this->assertFalse($client->verifySecret('wrong-secret'));
    }

    /**
     * Test public client has no secret
     */
    public function testPublicClientNoSecret(): void
    {
        $client = $this->createTestClientFromFixture('public');

        $this->assertFalse($client->isConfidential());
        // Public client should accept any secret (returns true for empty check)
        $this->assertTrue($client->verifySecret(''));
    }

    /**
     * Test grant type validation
     */
    public function testGrantTypeValidation(): void
    {
        $client = $this->createTestClientFromFixture('limited_scopes');

        $this->assertTrue($client->isGrantAllowed('authorization_code'));
        $this->assertFalse($client->isGrantAllowed('refresh_token'));
        $this->assertFalse($client->isGrantAllowed('client_credentials'));
    }

    /**
     * Test token revocation
     */
    public function testTokenRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenRecord->fetch($refreshToken['token_id']);

        // Revoke token
        $tokenRecord->revoke();

        // Verify revoked
        $tokenRecord->fetch($refreshToken['token_id']);
        $this->assertTrue($tokenRecord->isRevoked());

        // Validation should fail
        $validatedToken = $this->tokenService->validateRefreshToken($refreshToken['token']);
        $this->assertNull($validatedToken);
    }

    /**
     * Test cascade revocation (refresh + children)
     */
    public function testCascadeRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create refresh token
        $refreshToken = $this->createRefreshToken($client, $user);

        // Create access token linked to refresh token
        $accessToken = $this->tokenService->createAccessToken(
            $user->id,
            $client->client_id,
            ['openid'],
            $client->access_token_lifetime
        );

        $this->tokenService->storeAccessToken(
            $accessToken['jti'],
            $client->id,
            $user->id,
            ['openid'],
            $accessToken['expires_at'],
            $refreshToken['token_id']
        );

        // Revoke refresh token with cascade
        $refreshTokenRecord = new \SmartAuthOAuthToken($this->db);
        $refreshTokenRecord->fetch($refreshToken['token_id']);
        $count = $refreshTokenRecord->revokeWithChildren();

        $this->assertGreaterThan(0, $count);
    }

    /**
     * Test token cleanup of expired tokens
     */
    public function testExpiredTokenCleanup(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create expired token with backdated creation time
        $this->createExpiredRefreshToken($client, $user, true);

        // Count tokens before cleanup
        $countBefore = $this->getTableCount('smartauth_oauth_tokens');

        // Delete expired tokens
        $deleted = \SmartAuthOAuthToken::deleteExpired($this->db, 0);

        // Count after cleanup
        $countAfter = $this->getTableCount('smartauth_oauth_tokens');

        $this->assertGreaterThan(0, $deleted);
        $this->assertEquals($countBefore - $deleted, $countAfter);
    }

    /**
     * Test revoke all tokens for user and client
     */
    public function testRevokeAllForUserAndClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create multiple tokens
        $this->createRefreshToken($client, $user);
        $this->createRefreshToken($client, $user);
        $this->createAccessToken($client, $user);

        // Revoke all
        $count = \SmartAuthOAuthToken::revokeAllForUserAndClient($this->db, $user->id, $client->id);

        $this->assertGreaterThan(0, $count);

        // Verify all are revoked by checking if any non-revoked tokens exist
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . $user->id . " AND fk_client = " . $client->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }
}
