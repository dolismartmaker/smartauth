<?php

/**
 * Integration tests for AccountService and self-service account deletion
 * (Lot 7 of the SSO spec).
 *
 * Covers:
 *   - changePassword end-to-end (Dolibarr setPassword + dol_verifyHash)
 *   - listActiveSessions joins with the OAuth2 clients table
 *   - revokeAllSessions / revokeSessionByRowId stamp revoked_at
 *   - deleteSelfServiceAccount anonymises and disables the user
 *
 * @covers \SmartAuth\Api\Account\AccountService
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\Account\AccountService;
use SmartAuth\Api\Account\RegistrationService;

dol_include_once('/smartauth/api/Account/AccountService.php');
dol_include_once('/smartauth/api/Account/RegistrationService.php');
dol_include_once('/smartauth/api/Account/EmailValidationToken.php');
dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');
dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');

class AccountServiceIntegrationTest extends DolibarrRealTestCase
{
    /** @var AccountService */
    private $accountService;

    /** @var RegistrationService */
    private $registrationService;

    protected function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_OAUTH_ACCESS_TTL = 3600;
        $conf->global->SMARTAUTH_OAUTH_REFRESH_TTL = 2592000;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_monkey';
        $conf->global->SOCIETE_CODEFOURNISSEUR_ADDON = 'mod_codefournisseur_panda';

        $this->accountService = new AccountService($this->db);
        $this->registrationService = new RegistrationService($this->db, function () {
            return true;
        });

        $this->ensureEmailValidationTable();
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_email_validation");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_consents");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_clients");
    }

    public function testChangePasswordSucceedsWithCorrectCurrent(): void
    {
        $oldPassword = 'OldStrongPass1';
        $newPassword = 'NewStrongPass2';

        $user = $this->createTestUser([
            'email' => 'chgpwd_' . uniqid() . '@example.com',
            'pass' => $oldPassword,
        ]);

        $result = $this->accountService->changePassword(
            (int) $user->id,
            $oldPassword,
            $newPassword,
            $newPassword
        );

        $this->assertSame((int) $user->id, $result, 'changePassword should return user id on success');

        // The new password must verify; the old one must not.
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $reloaded = new \User($this->db);
        $reloaded->fetch((int) $user->id);
        $this->assertTrue(dol_verifyHash($newPassword, (string) $reloaded->pass_indatabase_crypted));
        $this->assertFalse(dol_verifyHash($oldPassword, (string) $reloaded->pass_indatabase_crypted));
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $user = $this->createTestUser([
            'email' => 'wrongcur_' . uniqid() . '@example.com',
            'pass' => 'OldStrongPass1',
        ]);

        $result = $this->accountService->changePassword(
            (int) $user->id,
            'WrongPassword12',
            'NewStrongPass2',
            'NewStrongPass2'
        );

        $this->assertSame(AccountService::ERR_CURRENT_PASSWORD_WRONG, $result);
    }

    public function testListActiveSessionsAndRevoke(): void
    {
        $user = $this->createTestUser(['pass' => 'SuperLong1Password']);
        $client = $this->createOAuthClient();

        $tokenA = $this->insertActiveTokenRow($client, $user, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);
        $this->insertActiveTokenRow($client, $user, \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH, ['openid', 'offline_access']);

        $sessions = $this->accountService->listActiveSessions((int) $user->id);
        $this->assertCount(1, $sessions, 'One client group expected');
        $this->assertSame((int) $client->id, $sessions[0]['client_pk']);
        $this->assertCount(2, $sessions[0]['tokens']);

        // Revoke just one token by rowid
        $revoked = $this->accountService->revokeSessionByRowId((int) $user->id, (int) $tokenA->id);
        $this->assertSame(1, $revoked);

        $remaining = $this->accountService->listActiveSessions((int) $user->id);
        $this->assertCount(1, $remaining[0]['tokens'], 'Only one active token should remain');

        // Revoke all
        $count = $this->accountService->revokeAllSessions((int) $user->id);
        $this->assertSame(1, $count);
        $this->assertSame([], $this->accountService->listActiveSessions((int) $user->id));
    }

    public function testRevokeSessionByRowIdRefusesTokenOwnedByAnotherUser(): void
    {
        $userOwner = $this->createTestUser(['pass' => 'SuperLong1Password']);
        $userAttacker = $this->createTestUser(['pass' => 'SuperLong1Password']);
        $client = $this->createOAuthClient();

        $token = $this->insertActiveTokenRow($client, $userOwner, \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS, ['openid']);

        $result = $this->accountService->revokeSessionByRowId((int) $userAttacker->id, (int) $token->id);
        $this->assertSame(AccountService::ERR_TOKEN_NOT_FOUND, $result);

        // The token is still active
        $this->assertSame(0, $this->getTableCount('smartauth_oauth_tokens', [
            'rowid' => $token->id,
            'fk_user' => $userOwner->id,
        ]) === 1 ? $this->countRevokedRows((int) $token->id) : -1);
    }

    public function testDeleteSelfServiceAccountAnonymisesAndDisables(): void
    {
        // A self-service user linked to a prospect (no contract -> deletable).
        // deleteSelfServiceAccount is the legacy user-path flow, so the test
        // builds a user directly rather than via startRegistration (which now
        // provisions a societe_account portal subject instead).
        $email = 'todelete_' . uniqid() . '@example.com';
        $userId = $this->createSelfServiceUser($email);

        // Add an OAuth2 token + a consent + a pending email_validation row
        $client = $this->createOAuthClient();
        $token = $this->insertActiveTokenRow(
            $client,
            $this->reloadUser($userId),
            \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS,
            ['openid']
        );
        $this->insertConsent($client, $userId);

        // Run the deletion
        $result = $this->registrationService->deleteSelfServiceAccount($userId);
        $this->assertSame($userId, $result);

        // User is disabled and anonymised
        $row = $this->fetchUserRow($userId);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['statut'], 'User must be disabled');
        $this->assertNotSame($email, strtolower((string) $row['email']));
        $this->assertStringContainsString('@deleted.invalid', (string) $row['email']);
        $this->assertSame('Deleted', (string) $row['lastname']);

        // Token revoked
        $this->assertSame(1, $this->countRevokedRows((int) $token->id));

        // Consents removed
        $this->assertSame(0, $this->getTableCount('smartauth_oauth_consents', [
            'fk_user' => $userId,
        ]));

        // No pending email_validation rows for this user
        $this->assertSame(0, $this->getTableCount('smartauth_email_validation', [
            'fk_user' => $userId,
        ]));
    }

    public function testDeleteSelfServiceAccountRefusedWhenThirdpartyIsCustomer(): void
    {
        $email = 'customer_' . uniqid() . '@example.com';
        $userId = $this->createSelfServiceUser($email);

        // Promote the thirdparty to customer (bit 1 set) so deletion should be blocked.
        $row = $this->fetchUserRow($userId);
        $thirdpartyId = (int) $row['fk_soc'];
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET client = 3 WHERE rowid = " . $thirdpartyId);

        $result = $this->registrationService->deleteSelfServiceAccount($userId);
        $this->assertSame(RegistrationService::ERR_ACCOUNT_NOT_DELETABLE, $result);

        // User still exists with original email
        $reloaded = $this->fetchUserRow($userId);
        $this->assertSame($email, strtolower((string) $reloaded['email']));
    }

    private function createOAuthClient(): \SmartAuthOAuthClient
    {
        $client = new \SmartAuthOAuthClient($this->db);
        $client->ref = 'CLIENT-' . uniqid();
        $client->client_id = 'cid-' . uniqid();
        $client->name = 'Test Client';
        $client->setRedirectUrisArray(['https://app.example.com/callback']);
        $client->setAllowedScopesArray(['openid', 'profile', 'email', 'offline_access']);
        $client->setAllowedGrantsArray(['authorization_code', 'refresh_token']);
        $client->is_confidential = 1;
        $client->require_pkce = 0;
        $client->access_token_lifetime = 3600;
        $client->refresh_token_lifetime = 2592000;
        $client->status = 1;

        $result = $client->create($this->testUser);
        if ($result < 0) {
            throw new \Exception('Failed to create OAuth client: ' . implode(', ', (array) $client->errors));
        }
        return $client;
    }

    /**
     * Create an active self-service user (statut=1) linked to a fresh prospect
     * thirdparty (client=2). Mirrors the legacy self-service user shape that
     * deleteSelfServiceAccount operates on.
     */
    private function createSelfServiceUser(string $email): int
    {
        $soc = $this->createTestSociete(['email' => $email, 'client' => 2]);
        $user = $this->createTestUser(['email' => $email, 'pass' => 'SuperLong1Password', 'statut' => 1]);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "user SET fk_soc = " . (int) $soc->id
            . " WHERE rowid = " . (int) $user->id
        );
        return (int) $user->id;
    }

    private function insertActiveTokenRow(
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
            throw new \Exception('Failed to insert token: ' . implode(', ', (array) $token->errors));
        }
        return $token;
    }

    private function insertConsent(\SmartAuthOAuthClient $client, int $userId): void
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " (fk_client, fk_user, scopes, granted_at, entity)";
        $sql .= " VALUES (" . ((int) $client->id) . ", " . ((int) $userId) . ", '[\"openid\"]',";
        $sql .= " '" . $this->db->idate(dol_now()) . "', 1)";
        $this->db->query($sql);
    }

    private function reloadUser(int $userId): \User
    {
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $u = new \User($this->db);
        $u->fetch($userId);
        return $u;
    }

    private function fetchUserRow(int $userId): ?array
    {
        $sql = "SELECT rowid, login, email, firstname, lastname, statut, fk_soc";
        $sql .= " FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . ((int) $userId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'rowid' => (int) $obj->rowid,
            'login' => (string) $obj->login,
            'email' => (string) $obj->email,
            'firstname' => (string) $obj->firstname,
            'lastname' => (string) $obj->lastname,
            'statut' => (int) $obj->statut,
            'fk_soc' => (int) $obj->fk_soc,
        ];
    }

    private function countRevokedRows(int $tokenRowId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE rowid = " . ((int) $tokenRowId);
        $sql .= " AND revoked_at IS NOT NULL";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        return (int) ($obj->cnt ?? 0);
    }

    private function ensureEmailValidationTable(): void
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='" . MAIN_DB_PREFIX . "smartauth_email_validation'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return;
        }
        $createSql = file_get_contents(dirname(__DIR__, 4) . '/sql/llx_smartauth_email_validation.sql');
        if ($createSql !== false) {
            $this->db->query($createSql);
        }
    }
}
