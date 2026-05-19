<?php

/**
 * Integration tests for the JTI-based revocation list (PERFS.md §3.4).
 *
 * Covers the three TokenService methods that drive the hybrid revocation:
 *   - addRevokedJti
 *   - listRevokedJtiActiveForUserAndClient
 *   - listRevokedJtiSince
 *   - purgeExpiredRevokedJti
 *
 * @covers \SmartAuth\Api\OAuth2\TokenService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

class RevokedJtiTest extends OAuthTestCase
{
    public function testAddRevokedJtiInsertsRowAndIsIdempotent(): void
    {
        $jti = bin2hex(random_bytes(16));
        $exp = dol_now() + 3600;

        $first = $this->tokenService->addRevokedJti($jti, $exp, 'contract_closed');
        $this->assertSame(1, $first, 'First insertion should return 1 (inserted)');

        $second = $this->tokenService->addRevokedJti($jti, $exp, 'contract_closed');
        $this->assertSame(0, $second, 'Re-adding the same jti must return 0 (no-op)');

        $this->assertSame(1, $this->getTableCount('smartauth_revoked_jti', ['jti' => $jti]));
    }

    public function testAddRevokedJtiRejectsEmptyJtiOrNonPositiveExpiry(): void
    {
        $this->assertSame(-1, $this->tokenService->addRevokedJti('', dol_now() + 3600, 'manual'));
        $this->assertSame(-1, $this->tokenService->addRevokedJti('jti-x', 0, 'manual'));
        $this->assertSame(-1, $this->tokenService->addRevokedJti('jti-x', -1, 'manual'));
    }

    public function testListActiveJtiReturnsOnlyAccessTokensOfTheCouple(): void
    {
        $clientA = $this->createTestClient(['ref' => 'A-' . uniqid(), 'client_id' => 'cid-a-' . uniqid()]);
        $clientB = $this->createTestClient(['ref' => 'B-' . uniqid(), 'client_id' => 'cid-b-' . uniqid()]);
        $userTarget = $this->createTestUser();
        $userOther = $this->createTestUser();

        $jtiAccessA1 = $this->insertAccessTokenRow($clientA, $userTarget, dol_now() + 3600);
        $jtiAccessA2 = $this->insertAccessTokenRow($clientA, $userTarget, dol_now() + 7200);
        $this->insertAccessTokenRow($clientB, $userTarget, dol_now() + 3600);   // wrong client
        $this->insertAccessTokenRow($clientA, $userOther, dol_now() + 3600);    // wrong user
        $this->insertRefreshTokenRow($clientA, $userTarget);                    // wrong type

        $list = $this->tokenService->listRevokedJtiActiveForUserAndClient($userTarget->id, $clientA->id);

        $returnedJtis = array_map(fn ($row) => $row['jti'], $list);
        sort($returnedJtis);
        $expected = [$jtiAccessA1, $jtiAccessA2];
        sort($expected);

        $this->assertSame($expected, $returnedJtis);
        foreach ($list as $row) {
            $this->assertIsInt($row['expires_at']);
            $this->assertGreaterThan(dol_now(), $row['expires_at']);
        }
    }

    public function testListActiveJtiForUserCoversAllClients(): void
    {
        $clientA = $this->createTestClient(['ref' => 'A-' . uniqid(), 'client_id' => 'cid-a-' . uniqid()]);
        $clientB = $this->createTestClient(['ref' => 'B-' . uniqid(), 'client_id' => 'cid-b-' . uniqid()]);
        $userTarget = $this->createTestUser();
        $userOther = $this->createTestUser();

        $jtiTargetA = $this->insertAccessTokenRow($clientA, $userTarget, dol_now() + 3600);
        $jtiTargetB = $this->insertAccessTokenRow($clientB, $userTarget, dol_now() + 7200);
        $this->insertAccessTokenRow($clientA, $userOther, dol_now() + 3600);   // wrong user
        $this->insertRefreshTokenRow($clientA, $userTarget);                   // wrong type

        $list = $this->tokenService->listRevokedJtiActiveForUser($userTarget->id);
        $returnedJtis = array_map(fn ($row) => $row['jti'], $list);
        sort($returnedJtis);
        $expected = [$jtiTargetA, $jtiTargetB];
        sort($expected);

        $this->assertSame($expected, $returnedJtis);
    }

    public function testListActiveJtiSkipsRevokedAndExpiredTokens(): void
    {
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        $jtiActive = $this->insertAccessTokenRow($client, $user, dol_now() + 3600);
        $jtiExpired = $this->insertAccessTokenRow($client, $user, dol_now() - 60);
        $jtiRevoked = $this->insertAccessTokenRow($client, $user, dol_now() + 3600);

        // Mark $jtiRevoked as revoked
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens"
            . " SET revoked_at = '" . $this->db->idate(dol_now()) . "'"
            . " WHERE jti = '" . $this->db->escape($jtiRevoked) . "'";
        $this->db->query($sql);

        $list = $this->tokenService->listRevokedJtiActiveForUserAndClient($user->id, $client->id);
        $returned = array_map(fn ($row) => $row['jti'], $list);

        $this->assertSame([$jtiActive], $returned);
        $this->assertNotContains($jtiExpired, $returned);
        $this->assertNotContains($jtiRevoked, $returned);
    }

    public function testListRevokedJtiSinceFiltersAndExcludesPastExpiry(): void
    {
        $now = dol_now();

        // Three entries, varying revoked_at. We backdate with direct SQL since
        // addRevokedJti always stamps NOW for revoked_at.
        $this->tokenService->addRevokedJti('jti-old', $now + 3600, 'manual');
        $this->backdateRevokedJti('jti-old', $now - 1800);

        $this->tokenService->addRevokedJti('jti-mid', $now + 3600, 'contract_closed');
        $this->backdateRevokedJti('jti-mid', $now - 300);

        $this->tokenService->addRevokedJti('jti-recent', $now + 3600, 'manual');

        // One row that is already past its expires_at: must be filtered out
        $this->tokenService->addRevokedJti('jti-past-exp', $now + 3600, 'manual');
        $this->backdateRevokedJtiAndExpire('jti-past-exp', $now - 60, $now - 30);

        // Since = now - 600 -> only mid and recent remain
        $list = $this->tokenService->listRevokedJtiSince($now - 600);
        $returnedJtis = array_map(fn ($row) => $row['jti'], $list);

        $this->assertContains('jti-mid', $returnedJtis);
        $this->assertContains('jti-recent', $returnedJtis);
        $this->assertNotContains('jti-old', $returnedJtis);
        $this->assertNotContains('jti-past-exp', $returnedJtis);

        // Order: ASC by revoked_at -> jti-mid before jti-recent
        $this->assertSame('jti-mid', $list[0]['jti']);
        $this->assertSame('jti-recent', $list[1]['jti']);

        // since = 0 returns everything not past expiry
        $listAll = $this->tokenService->listRevokedJtiSince(0);
        $returnedAll = array_map(fn ($row) => $row['jti'], $listAll);
        $this->assertContains('jti-old', $returnedAll);
        $this->assertNotContains('jti-past-exp', $returnedAll);
    }

    public function testPurgeExpiredRevokedJtiOnlyDeletesPastExpiry(): void
    {
        $now = dol_now();

        $this->tokenService->addRevokedJti('jti-active', $now + 3600, 'manual');
        $this->tokenService->addRevokedJti('jti-stale-a', $now + 3600, 'manual');
        $this->tokenService->addRevokedJti('jti-stale-b', $now + 3600, 'manual');

        // Backdate the two stale entries' expires_at into the past
        $this->backdateExpiresAt('jti-stale-a', $now - 10);
        $this->backdateExpiresAt('jti-stale-b', $now - 5);

        $purged = $this->tokenService->purgeExpiredRevokedJti();
        $this->assertSame(2, $purged);

        $this->assertSame(1, $this->getTableCount('smartauth_revoked_jti'));
        $this->assertSame(1, $this->getTableCount('smartauth_revoked_jti', ['jti' => 'jti-active']));
    }

    // ------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------

    /**
     * Insert an access token row and return its jti.
     */
    private function insertAccessTokenRow(\SmartAuthOAuthClient $client, \User $user, int $expiresAt): string
    {
        $plain = \SmartAuthOAuthToken::generateRefreshToken();
        $jti = bin2hex(random_bytes(16));

        $token = new \SmartAuthOAuthToken($this->db);
        $token->token_hash = \SmartAuthOAuthToken::hashToken($plain);
        $token->token_type = \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS;
        $token->fk_client = $client->id;
        $token->fk_user = $user->id;
        $token->setScopesArray(['openid']);
        $token->jti = $jti;
        $token->expires_at = $expiresAt;

        $result = $token->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to insert access token: ' . implode(', ', (array) $token->errors));
        }
        return $jti;
    }

    private function insertRefreshTokenRow(\SmartAuthOAuthClient $client, \User $user): void
    {
        $plain = \SmartAuthOAuthToken::generateRefreshToken();

        $token = new \SmartAuthOAuthToken($this->db);
        $token->token_hash = \SmartAuthOAuthToken::hashToken($plain);
        $token->token_type = \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH;
        $token->fk_client = $client->id;
        $token->fk_user = $user->id;
        $token->setScopesArray(['openid', 'offline_access']);
        $token->expires_at = dol_now() + 86400;

        $result = $token->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to insert refresh token: ' . implode(', ', (array) $token->errors));
        }
    }

    private function backdateRevokedJti(string $jti, int $newRevokedAtTs): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " SET revoked_at = '" . $this->db->idate($newRevokedAtTs) . "'"
            . " WHERE jti = '" . $this->db->escape($jti) . "'";
        $this->db->query($sql);
    }

    private function backdateRevokedJtiAndExpire(string $jti, int $revokedAtTs, int $expiresAtTs): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " SET revoked_at = '" . $this->db->idate($revokedAtTs) . "',"
            . " expires_at = '" . $this->db->idate($expiresAtTs) . "'"
            . " WHERE jti = '" . $this->db->escape($jti) . "'";
        $this->db->query($sql);
    }

    private function backdateExpiresAt(string $jti, int $expiresAtTs): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " SET expires_at = '" . $this->db->idate($expiresAtTs) . "'"
            . " WHERE jti = '" . $this->db->escape($jti) . "'";
        $this->db->query($sql);
    }
}
