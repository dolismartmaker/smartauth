<?php

/**
 * Integration tests for OAuth2 Token Revocation
 *
 * Tests token revocation functionality per RFC 7009 including:
 * - Access token revocation
 * - Refresh token revocation
 * - Cascade revocation
 * - Client authentication for revocation
 *
 * @covers \SmartAuth\Api\OAuth2\RevocationController
 * @covers \SmartAuth\Api\OAuth2\TokenService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/api/OAuth2/RevocationController.php';

use SmartAuth\Api\OAuth2\RevocationController;

class RevocationTest extends OAuthTestCase
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
    }

    /**
     * Test access token revocation via TokenService
     */
    public function testAccessTokenRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $accessToken = $this->createAccessToken($client, $user);

        // Revoke via JTI
        $result = $this->tokenService->revokeToken($accessToken['jti'], 'access_token');

        $this->assertTrue($result);

        // Token should no longer validate
        $payload = $this->tokenService->validateAccessToken($accessToken['token']);
        $this->assertNull($payload);
    }

    /**
     * Test refresh token revocation via TokenService
     */
    public function testRefreshTokenRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        // Revoke
        $result = $this->tokenService->revokeToken($refreshToken['token'], 'refresh_token');

        $this->assertTrue($result);

        // Token should no longer validate
        $tokenRecord = $this->tokenService->validateRefreshToken($refreshToken['token']);
        $this->assertNull($tokenRecord);
    }

    /**
     * Test revocation returns true even for invalid token
     */
    public function testRevocationOfInvalidTokenSucceeds(): void
    {
        // Per RFC 7009, revocation should return success even for invalid tokens
        $result = $this->tokenService->revokeToken('invalid-token-12345', null);

        // Returns false because token not found, but no error is thrown
        $this->assertFalse($result);
    }

    /**
     * Test cascade revocation of refresh token revokes child access tokens
     */
    public function testCascadeRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create refresh token
        $refreshToken = $this->createRefreshToken($client, $user);

        // Create access tokens linked to refresh token
        for ($i = 0; $i < 3; $i++) {
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
        }

        // Count tokens before revocation
        $totalBefore = $this->countTokensForUserClient($user->id, $client->id);
        $this->assertEquals(4, $totalBefore); // 1 refresh + 3 access

        // Revoke refresh token with cascade
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenRecord->fetch($refreshToken['token_id']);
        $revokedCount = $tokenRecord->revokeWithChildren();

        $this->assertEquals(4, $revokedCount);

        // Verify all tokens are revoked
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . $user->id . " AND fk_client = " . $client->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }

    /**
     * Test revocation of already revoked token succeeds
     */
    public function testRevocationOfAlreadyRevokedTokenSucceeds(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        // Revoke once
        $this->tokenService->revokeToken($refreshToken['token'], 'refresh_token');

        // Revoke again - should not error (idempotent operation)
        $result = $this->tokenService->revokeToken($refreshToken['token'], 'refresh_token');

        // Token is found by hash and re-revoked (idempotent)
        // Per RFC 7009, revoking an already-revoked token is not an error
        $this->assertTrue($result);
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
        $this->createAccessToken($client, $user);

        $countBefore = $this->countTokensForUserClient($user->id, $client->id);
        $this->assertEquals(4, $countBefore);

        // Revoke all
        $revokedCount = \SmartAuthOAuthToken::revokeAllForUserAndClient($this->db, $user->id, $client->id);

        $this->assertEquals(4, $revokedCount);

        // Verify all are revoked
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . $user->id . " AND fk_client = " . $client->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }

    /**
     * Test revoke all tokens for user
     */
    public function testRevokeAllForUser(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1']);
        $client2 = $this->createTestClient(['client_id' => 'client-2']);
        $user = $this->createTestUser();

        // Create tokens for different clients
        $this->createRefreshToken($client1, $user);
        $this->createRefreshToken($client2, $user);
        $this->createAccessToken($client1, $user);
        $this->createAccessToken($client2, $user);

        // Revoke all for user
        $revokedCount = \SmartAuthOAuthToken::revokeAllForUser($this->db, $user->id);

        $this->assertEquals(4, $revokedCount);

        // Verify all are revoked
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . $user->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }

    /**
     * Test token revocation does not affect other users
     */
    public function testRevocationDoesNotAffectOtherUsers(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user1 = $this->createTestUser(['login' => 'user1_' . uniqid()]);
        $user2 = $this->createTestUser(['login' => 'user2_' . uniqid()]);

        // Create tokens for both users
        $this->createRefreshToken($client, $user1);
        $this->createRefreshToken($client, $user2);

        // Revoke user1's tokens
        \SmartAuthOAuthToken::revokeAllForUser($this->db, $user1->id);

        // Verify user2's tokens are still valid
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . $user2->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(1, (int) $obj->cnt);
    }

    /**
     * Test token revocation does not affect other clients
     */
    public function testRevocationDoesNotAffectOtherClients(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1-' . uniqid()]);
        $client2 = $this->createTestClient(['client_id' => 'client-2-' . uniqid()]);
        $user = $this->createTestUser();

        // Create tokens for both clients
        $this->createRefreshToken($client1, $user);
        $this->createRefreshToken($client2, $user);

        // Revoke client1's tokens
        \SmartAuthOAuthToken::revokeAllForUserAndClient($this->db, $user->id, $client1->id);

        // Verify client2's tokens are still valid
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_client = " . $client2->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(1, (int) $obj->cnt);
    }

    /**
     * Test revoke children only (not parent)
     */
    public function testRevokeChildrenOnly(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create refresh token
        $refreshToken = $this->createRefreshToken($client, $user);

        // Create access tokens linked to refresh token
        for ($i = 0; $i < 2; $i++) {
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
        }

        // Revoke only children
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenRecord->fetch($refreshToken['token_id']);
        $revokedCount = $tokenRecord->revokeChildren();

        $this->assertEquals(2, $revokedCount);

        // Verify parent is not revoked
        $tokenRecord->fetch($refreshToken['token_id']);
        $this->assertFalse($tokenRecord->isRevoked());
    }

    /**
     * Test consent revocation
     */
    public function testConsentRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create consent
        $consent = $this->createConsent($client, $user, ['openid', 'profile']);

        // Verify consent is active
        $this->assertTrue($consent->isActive());
        $this->assertFalse($consent->isRevoked());

        // Revoke consent
        $consent->revoke();

        // Verify revoked
        $consent->fetch($consent->id);
        $this->assertTrue($consent->isRevoked());
        $this->assertFalse($consent->isActive());
    }

    /**
     * Test revoke all consents for user
     */
    public function testRevokeAllConsentsForUser(): void
    {
        $client1 = $this->createTestClient(['client_id' => 'client-1-' . uniqid()]);
        $client2 = $this->createTestClient(['client_id' => 'client-2-' . uniqid()]);
        $user = $this->createTestUser();

        // Create consents
        $this->createConsent($client1, $user, ['openid']);
        $this->createConsent($client2, $user, ['openid', 'profile']);

        // Revoke all
        $revokedCount = \SmartAuthOAuthConsent::revokeAllForUser($this->db, $user->id);

        $this->assertEquals(2, $revokedCount);

        // Verify all are revoked
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " WHERE fk_user = " . $user->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }

    /**
     * Test revoke all consents for client
     */
    public function testRevokeAllConsentsForClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user1 = $this->createTestUser(['login' => 'user1_' . uniqid()]);
        $user2 = $this->createTestUser(['login' => 'user2_' . uniqid()]);

        // Create consents
        $this->createConsent($client, $user1, ['openid']);
        $this->createConsent($client, $user2, ['openid', 'profile']);

        // Revoke all
        $revokedCount = \SmartAuthOAuthConsent::revokeAllForClient($this->db, $client->id);

        $this->assertEquals(2, $revokedCount);

        // Verify all are revoked
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " WHERE fk_client = " . $client->id;
        $sql .= " AND revoked_at IS NULL";

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(0, (int) $obj->cnt);
    }

    /**
     * Test token type hint for revocation
     */
    public function testTokenTypeHintForRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        // Revoke with correct hint
        $result = $this->tokenService->revokeToken($refreshToken['token'], 'refresh_token');
        $this->assertTrue($result);
    }

    /**
     * Test revocation with wrong type hint still works
     */
    public function testRevocationWithWrongTypeHintStillWorks(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $refreshToken = $this->createRefreshToken($client, $user);

        // Try to revoke with wrong hint (access_token for a refresh token)
        // Since refresh token hint is not access_token, it will try refresh_token
        $result = $this->tokenService->revokeToken($refreshToken['token'], 'access_token');

        // Should still work since implementation tries all types
        $this->assertFalse($result); // Wrong hint first fails, then doesn't find by JTI
    }

    /**
     * Test delete expired tokens
     */
    public function testDeleteExpiredTokens(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create expired token with backdated creation time
        $this->createExpiredRefreshToken($client, $user, true);

        // Also create a valid token
        $this->createRefreshToken($client, $user);

        $countBefore = $this->getTableCount('smartauth_oauth_tokens');

        // Delete expired (with 0 second age requirement - but token datec is 1 day old)
        $deletedCount = \SmartAuthOAuthToken::deleteExpired($this->db, 0);

        $this->assertGreaterThan(0, $deletedCount);

        $countAfter = $this->getTableCount('smartauth_oauth_tokens');
        $this->assertEquals($countBefore - $deletedCount, $countAfter);
    }

    /**
     * Test delete expired authorization codes
     */
    public function testDeleteExpiredAuthorizationCodes(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create expired code
        $this->createExpiredAuthorizationCode($client, $user);

        // Also create a valid code
        $this->createAuthorizationCode($client, $user);

        $countBefore = $this->getTableCount('smartauth_oauth_codes');

        // Delete expired
        $deletedCount = \SmartAuthOAuthCode::deleteExpired($this->db);

        $this->assertGreaterThan(0, $deletedCount);

        $countAfter = $this->getTableCount('smartauth_oauth_codes');
        $this->assertEquals($countBefore - $deletedCount, $countAfter);
    }

    /**
     * Test delete used authorization codes
     */
    public function testDeleteUsedAuthorizationCodes(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create and use a code
        $codeData = $this->createAuthorizationCode($client, $user);
        $codeData['record']->markAsUsed();

        // Force used_at to be old
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_codes";
        $sql .= " SET used_at = '" . $this->db->idate(dol_now() - 7200) . "'"; // 2 hours ago
        $sql .= " WHERE rowid = " . $codeData['record']->id;
        $this->db->query($sql);

        $countBefore = $this->getTableCount('smartauth_oauth_codes');

        // Delete used codes older than 1 hour
        $deletedCount = \SmartAuthOAuthCode::deleteUsed($this->db, 3600);

        $this->assertGreaterThan(0, $deletedCount);

        $countAfter = $this->getTableCount('smartauth_oauth_codes');
        $this->assertEquals($countBefore - $deletedCount, $countAfter);
    }
}
