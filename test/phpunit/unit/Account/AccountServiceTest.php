<?php

/**
 * Unit tests for AccountService methods that don't need a real Dolibarr.
 *
 * Covers session listing (SQL grouping by client), per-token revocation
 * scope (cannot revoke a token belonging to another user), password
 * policy guards on changePassword.
 *
 * @covers \SmartAuth\Api\Account\AccountService
 */

namespace SmartAuth\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\Account\AccountService;
use SmartAuth\Tests\Mocks\MockDatabase;

class AccountServiceTest extends TestCase
{
    public function testChangePasswordRejectsMismatch(): void
    {
        $db = new MockDatabase();
        $service = new AccountService($db);

        $result = $service->changePassword(7, 'OldPassword12', 'NewSuperLong1', 'NewSuperLong2');

        $this->assertSame(AccountService::ERR_PASSWORD_MISMATCH, $result);
    }

    public function testChangePasswordRejectsWeakNew(): void
    {
        $db = new MockDatabase();
        $service = new AccountService($db);

        $result = $service->changePassword(7, 'OldPassword12', 'short', 'short');

        $this->assertSame(AccountService::ERR_WEAK_PASSWORD, $result);
    }

    public function testRevokeSessionByRowIdReturnsErrorWhenInvalidArgs(): void
    {
        $db = new MockDatabase();
        $service = new AccountService($db);

        $this->assertSame(AccountService::ERR_TOKEN_NOT_FOUND, $service->revokeSessionByRowId(0, 5));
        $this->assertSame(AccountService::ERR_TOKEN_NOT_FOUND, $service->revokeSessionByRowId(5, 0));
    }

    public function testRevokeSessionByRowIdReturnsNotFoundWhenTokenAbsent(): void
    {
        $db = new MockDatabase();
        // Lookup -> nothing
        $db->setQueryResult(true, []);

        $service = new AccountService($db);
        $result = $service->revokeSessionByRowId(7, 42);

        $this->assertSame(AccountService::ERR_TOKEN_NOT_FOUND, $result);
    }

    public function testRevokeSessionByRowIdSucceedsForOwnedToken(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true, [['rowid' => 42]]); // ownership lookup
        $db->setQueryResult(true);                    // UPDATE
        $service = new AccountService($db);

        $result = $service->revokeSessionByRowId(7, 42);

        $this->assertSame(1, $result);
        $sqlList = $db->getExecutedQueries();
        $this->assertNotEmpty($sqlList);
        $this->assertStringContainsString('rowid = 42', end($sqlList));
        $this->assertStringContainsString('fk_user = 7', end($sqlList));
    }

    public function testListActiveSessionsGroupsTokensByClient(): void
    {
        $db = new MockDatabase();
        $now = time();
        $db->setQueryResult(true, [
            [
                'rowid' => 1,
                'jti' => 'jti-1',
                'token_type' => 'access',
                'datec' => date('Y-m-d H:i:s', $now - 60),
                'expires_at' => date('Y-m-d H:i:s', $now + 3600),
                'token_hash' => 'h1',
                'fk_client' => 10,
                'oauth_client_id' => 'captodo',
                'oauth_name' => 'CapTodo',
                'oauth_logo' => 'https://example.com/logo.png',
            ],
            [
                'rowid' => 2,
                'jti' => null,
                'token_type' => 'refresh',
                'datec' => date('Y-m-d H:i:s', $now - 30),
                'expires_at' => date('Y-m-d H:i:s', $now + 2592000),
                'token_hash' => 'h2',
                'fk_client' => 10,
                'oauth_client_id' => 'captodo',
                'oauth_name' => 'CapTodo',
                'oauth_logo' => 'https://example.com/logo.png',
            ],
            [
                'rowid' => 3,
                'jti' => 'jti-3',
                'token_type' => 'access',
                'datec' => date('Y-m-d H:i:s', $now - 10),
                'expires_at' => date('Y-m-d H:i:s', $now + 3600),
                'token_hash' => 'h3',
                'fk_client' => 20,
                'oauth_client_id' => 'capcrm',
                'oauth_name' => 'CapCRM',
                'oauth_logo' => '',
            ],
        ], 3);

        $service = new AccountService($db);
        $sessions = $service->listActiveSessions(7);

        $this->assertCount(2, $sessions);
        // Find the captodo group regardless of order
        $captodoIndex = null;
        $capcrmIndex = null;
        foreach ($sessions as $i => $session) {
            if ($session['client_id'] === 'captodo') {
                $captodoIndex = $i;
            }
            if ($session['client_id'] === 'capcrm') {
                $capcrmIndex = $i;
            }
        }
        $this->assertNotNull($captodoIndex);
        $this->assertNotNull($capcrmIndex);
        $this->assertCount(2, $sessions[$captodoIndex]['tokens']);
        $this->assertCount(1, $sessions[$capcrmIndex]['tokens']);
        $this->assertSame('CapTodo', $sessions[$captodoIndex]['client_name']);
    }

    public function testListActiveSessionsReturnsEmptyArrayWhenNoUser(): void
    {
        $db = new MockDatabase();
        $service = new AccountService($db);

        $this->assertSame([], $service->listActiveSessions(0));
    }

    public function testUpdateIdentityReturnsErrorWhenUserNotFound(): void
    {
        $db = new MockDatabase();
        $service = new AccountService($db);

        // Without Dolibarr stubs, fetchUser will instantiate \User which is
        // unavailable in unit tests. So we just assert the entry-point
        // contract: id <= 0 -> short-circuit error.
        $this->assertSame(AccountService::ERR_USER_NOT_FOUND, $service->updateIdentity(0, 'a', 'b'));
    }
}
