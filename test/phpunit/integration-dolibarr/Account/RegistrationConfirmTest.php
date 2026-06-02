<?php

/**
 * Integration tests for RegistrationService::confirmRegistration
 * (Lot 6 of the SSO spec).
 *
 * Asserts that consuming a valid 'register' token activates the user
 * (statut: 0 -> 1), marks the token as used (single-use guarantee), and
 * returns the optional `continue` URL stored at registration time.
 *
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\Account\RegistrationService;
use SmartAuth\Api\Account\EmailValidationToken;

dol_include_once('/smartauth/api/Account/EmailValidationToken.php');
dol_include_once('/smartauth/api/Account/RegistrationService.php');
dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');

class RegistrationConfirmTest extends DolibarrRealTestCase
{
    /** @var RegistrationService */
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_REGISTER_TOKEN_TTL = 86400;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_monkey';
        $conf->global->SOCIETE_CODEFOURNISSEUR_ADDON = 'mod_codefournisseur_panda';

        $this->service = new RegistrationService($this->db, function () {
            return true;
        });

        $this->ensureEmailValidationTable();
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_email_validation");
    }

    public function testConfirmRegistrationActivatesAccountAndConsumesToken(): void
    {
        $email = 'tobeactivated_' . uniqid() . '@example.com';
        $start = $this->service->startRegistration(
            $email,
            'SuperLong1Password',
            'Marie',
            'Dupont',
            null,
            '127.0.0.1',
            'https://app.example.com/cb?session=abc'
        );

        $this->assertArrayNotHasKey('error', $start);
        $accountId = (int) $start['account_id'];
        $this->assertSame('account', $start['subject_type']);
        $this->assertSame(0, $this->fetchAccountStatus($accountId), 'Account must be inactive before confirmation');

        // The plain token is not returned by startRegistration (it goes via
        // email). Mint a fresh account-subject token to drive confirmation.
        $tokens = new EmailValidationToken($this->db);
        $plain = EmailValidationToken::generatePlainToken();
        $tokenRowId = $tokens->create(
            0,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plain),
            86400,
            '127.0.0.1',
            ['continue' => 'https://app.example.com/cb?session=abc'],
            1,
            'account',
            $accountId,
            null
        );
        $this->assertGreaterThan(0, $tokenRowId);

        $confirm = $this->service->confirmRegistration($plain);

        $this->assertArrayNotHasKey('error', $confirm, 'Confirmation should succeed');
        $this->assertSame($accountId, (int) $confirm['account_id']);
        $this->assertSame('https://app.example.com/cb?session=abc', $confirm['continue']);

        // Account is now active
        $this->assertSame(1, $this->fetchAccountStatus($accountId), 'Account must be activated (status=1) after confirmation');

        // Token row must now be marked used (used_at not null)
        $this->assertSame(0, $this->getTableCount('smartauth_email_validation', [
            'rowid' => $tokenRowId,
            'used_at' => null,
        ]), 'Token row must be marked used');

        // Replaying the same token must fail (single-use)
        $replay = $this->service->confirmRegistration($plain);
        $this->assertSame(['error' => RegistrationService::ERR_TOKEN_INVALID], $replay);
    }

    public function testConfirmRegistrationRejectsExpiredToken(): void
    {
        $accountId = $this->createInactivePortalAccountRow('expired_' . uniqid() . '@example.com');

        // Insert an account-subject token, then backdate it into the past.
        $tokens = new EmailValidationToken($this->db);
        $plain = EmailValidationToken::generatePlainToken();
        $rowId = $tokens->create(
            0,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plain),
            60,
            '127.0.0.1',
            null,
            1,
            'account',
            $accountId,
            null
        );
        $this->assertGreaterThan(0, $rowId);

        $past = $this->db->idate(time() - 7200);
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " SET expires_at = '" . $past . "'";
        $sql .= " WHERE rowid = " . ((int) $rowId);
        $this->assertNotFalse($this->db->query($sql));

        $result = $this->service->confirmRegistration($plain);

        $this->assertSame(['error' => RegistrationService::ERR_TOKEN_INVALID], $result);
        $this->assertSame(0, $this->fetchAccountStatus($accountId), 'Expired confirmation must NOT activate the account');
    }

    private function fetchAccountStatus(int $accountId): int
    {
        $sql = "SELECT status FROM " . MAIN_DB_PREFIX . "societe_account WHERE rowid = " . ((int) $accountId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj ? (int) $obj->status : -1;
    }

    private function createInactivePortalAccountRow(string $login): int
    {
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, '" . $this->db->escape($login) . "', 'smartauth', 0, " . (int) $this->testUser->id . ", '" . $now . "')";
        if (!$this->db->query($sql)) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
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
