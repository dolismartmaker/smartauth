<?php

/**
 * Integration tests for TokenService::revokeAllForUser and revokeAllForUserAndClient
 * (Lot 1 of the SSO spec).
 *
 * Asserts the SQL bulk-update sets revoked_at on every active token row
 * for the targeted (user, client) tuple, and that the methods return the
 * exact count of affected rows.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

/**
 * NOTE on token creation:
 *
 * The bulk-revoke methods we test here are pure SQL-level UPDATE. To
 * avoid coupling these tests to RSA key generation (JWT signing), we
 * insert token rows directly via SmartAuthOAuthToken instead of going
 * through TokenService::createAccessToken. This lets the tests pass
 * even on machines where openssl_pkey_new is constrained by openssl.cnf.
 */
class TokenServiceRevokeTest extends OAuthTestCase
{
    public function testRevokeAllForUserAndClientStampsRevokedAt(): void
    {
        $clientA = $this->createTestClient(['ref' => 'A-' . uniqid(), 'client_id' => 'cid-a-' . uniqid()]);
        $clientB = $this->createTestClient(['ref' => 'B-' . uniqid(), 'client_id' => 'cid-b-' . uniqid()]);
        $user = $this->createTestUser();

        $this->insertTokenRow($clientA, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $this->insertTokenRow($clientA, $user, \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH, ['openid', 'offline_access']);
        $this->insertTokenRow($clientB, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);

        $this->assertSame(3, $this->getTableCount('smartauth_oauth_tokens', ['fk_user' => $user->id]));

        $count = $this->tokenService->revokeAllForUserAndClient($user->id, $clientA->id);
        $this->assertSame(2, $count, 'Should revoke exactly the 2 tokens on client A');

        $this->assertSame(2, $this->countRevokedForUserClient($user->id, $clientA->id));
        $this->assertSame(0, $this->countRevokedForUserClient($user->id, $clientB->id));
    }

    public function testRevokeAllForUserAndClientReturnsZeroWhenNoTokens(): void
    {
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        $count = $this->tokenService->revokeAllForUserAndClient($user->id, $client->id);
        $this->assertSame(0, $count);
    }

    public function testRevokeAllForUserAndClientIsIdempotent(): void
    {
        $client = $this->createTestClient();
        $user = $this->createTestUser();
        $this->insertTokenRow($client, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);

        $first = $this->tokenService->revokeAllForUserAndClient($user->id, $client->id);
        $second = $this->tokenService->revokeAllForUserAndClient($user->id, $client->id);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Second invocation must not revoke already-revoked rows');
    }

    public function testRevokeAllForUserCoversAllClientsButLeavesOtherUsersUntouched(): void
    {
        $clientA = $this->createTestClient(['ref' => 'A-' . uniqid(), 'client_id' => 'cid-a-' . uniqid()]);
        $clientB = $this->createTestClient(['ref' => 'B-' . uniqid(), 'client_id' => 'cid-b-' . uniqid()]);
        $userTarget = $this->createTestUser();
        $userOther = $this->createTestUser();

        $this->insertTokenRow($clientA, $userTarget, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $this->insertTokenRow($clientA, $userTarget, \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH, ['openid', 'offline_access']);
        $this->insertTokenRow($clientB, $userTarget, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $this->insertTokenRow($clientA, $userOther, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);

        $count = $this->tokenService->revokeAllForUser($userTarget->id);
        $this->assertSame(3, $count);

        $this->assertSame(0, $this->countRevokedForUserClient($userOther->id, $clientA->id));
    }

    public function testRevokeAllForUserAndClientOnlyTouchesActiveTokens(): void
    {
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // 1 active, 1 already revoked (revoked_at NOT NULL)
        $this->insertTokenRow($client, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $alreadyRevoked = $this->insertTokenRow($client, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $alreadyRevoked->revoke();

        $count = $this->tokenService->revokeAllForUserAndClient($user->id, $client->id);
        $this->assertSame(1, $count, 'Should not double-revoke an already-revoked token');
    }

    /**
     * Insert a token row directly without invoking JWT signing.
     *
     * @param \SmartAuthOAuthClient $client
     * @param \User                 $user
     * @param string                $tokenType
     * @param array                 $scopes
     * @return \SmartAuthOAuthToken
     */
    private function insertTokenRow(
        \SmartAuthOAuthClient $client,
        \User $user,
        string $tokenType,
        array $scopes
    ): \SmartAuthOAuthToken {
        $plain = \SmartAuthOAuthToken::generateRefreshToken();

        $token = new \SmartAuthOAuthToken($this->db);
        $token->token_hash = \SmartAuthOAuthToken::hashToken($plain);
        $token->token_type = $tokenType;
        $token->fk_client = $client->id;
        $token->fk_user = $user->id;
        $token->setScopesArray($scopes);
        if ($tokenType === \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS) {
            $token->jti = bin2hex(random_bytes(16));
        }
        $token->expires_at = dol_now() + 3600;

        $result = $token->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to insert test token: ' . implode(', ', (array) $token->errors));
        }
        return $token;
    }

    /**
     * Count how many tokens for (user, client) have a non-null revoked_at.
     *
     * @param int $userId
     * @param int $clientId
     * @return int
     */
    private function countRevokedForUserClient(int $userId, int $clientId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE fk_user = " . ((int) $userId);
        $sql .= " AND fk_client = " . ((int) $clientId);
        $sql .= " AND revoked_at IS NOT NULL";

        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        return (int) ($obj->cnt ?? 0);
    }
}
