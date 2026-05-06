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

    public function testConfirmRegistrationActivatesUserAndConsumesToken(): void
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
        $userId = (int) $start['user_id'];
        $this->assertSame(0, $this->fetchUserStatut($userId), 'User must be inactive before confirmation');

        // The plain token is not returned by startRegistration (it goes via
        // email). Recover it by directly minting a fresh one (mirrors what
        // resendConfirmation does internally).
        $tokens = new EmailValidationToken($this->db);
        $plain = EmailValidationToken::generatePlainToken();
        $tokens->invalidateActiveForUser($userId, EmailValidationToken::PURPOSE_REGISTER);
        $tokenRowId = $tokens->create(
            $userId,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plain),
            86400,
            '127.0.0.1',
            ['continue' => 'https://app.example.com/cb?session=abc']
        );
        $this->assertGreaterThan(0, $tokenRowId);

        $confirm = $this->service->confirmRegistration($plain);

        $this->assertArrayNotHasKey('error', $confirm, 'Confirmation should succeed');
        $this->assertSame($userId, (int) $confirm['user_id']);
        $this->assertSame('https://app.example.com/cb?session=abc', $confirm['continue']);

        // User is now active
        $this->assertSame(1, $this->fetchUserStatut($userId), 'User must be activated (statut=1) after confirmation');

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
        $user = $this->createTestUser([
            'pass' => 'SuperLong1Password',
            'statut' => 0,
        ]);

        // Insert an already-expired token directly (TTL 60s only, then
        // backdate datec/expires_at via SQL UPDATE).
        $tokens = new EmailValidationToken($this->db);
        $plain = EmailValidationToken::generatePlainToken();
        $rowId = $tokens->create(
            $user->id,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plain),
            60,
            '127.0.0.1'
        );
        $this->assertGreaterThan(0, $rowId);

        // Backdate expires_at into the past
        $past = $this->db->idate(time() - 7200);
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " SET expires_at = '" . $past . "'";
        $sql .= " WHERE rowid = " . ((int) $rowId);
        $this->assertNotFalse($this->db->query($sql));

        $result = $this->service->confirmRegistration($plain);

        $this->assertSame(['error' => RegistrationService::ERR_TOKEN_INVALID], $result);
        $this->assertSame(0, $this->fetchUserStatut($user->id), 'Expired confirmation must NOT activate the user');
    }

    private function fetchUserStatut(int $userId): int
    {
        $sql = "SELECT statut FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . ((int) $userId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj ? (int) $obj->statut : -1;
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
