<?php

/**
 * Integration tests for RegistrationService::startRegistration
 * (Lot 5 of the SSO spec).
 *
 * Asserts that a successful registration creates:
 *   - a thirdparty in prospect mode (client=0, prospect=1)
 *   - a contact attached to that thirdparty
 *   - an external user (statut=0, fk_soc set)
 *   - a row in llx_smartauth_email_validation with the right purpose
 *
 * Uses an injected emailSender callable so no real SMTP is reached.
 *
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\Account\RegistrationService;

dol_include_once('/smartauth/api/Account/EmailValidationToken.php');
dol_include_once('/smartauth/api/Account/RegistrationService.php');
dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');

class RegistrationServiceTest extends DolibarrRealTestCase
{
    /** @var RegistrationService */
    private $service;

    /** @var array<int, array{to:string,subject:string,text:string,html:string}> */
    private $sentEmails = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_REGISTER_TOKEN_TTL = 86400;
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        // The default leopard module ships incomplete in the SQLite test
        // package (missing prefixIsRequired). Use monkey which works fine.
        $conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_monkey';
        $conf->global->SOCIETE_CODEFOURNISSEUR_ADDON = 'mod_codefournisseur_panda';

        $this->sentEmails = [];
        $this->service = new RegistrationService($this->db, function ($to, $subject, $text, $html) {
            $this->sentEmails[] = compact('to', 'subject', 'text', 'html');
            return true;
        });

        // Make sure the email_validation table exists. The bootstrap already
        // runs the module init, but be defensive in case the SQLite test DB
        // is reused without the new schema.
        $this->ensureEmailValidationTable();

        // Clean rows possibly left by previous tests
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_email_validation");
    }

    public function testStartRegistrationCreatesProspectContactAndInactiveUser(): void
    {
        $email = 'newuser_' . uniqid() . '@example.com';
        $result = $this->service->startRegistration(
            $email,
            'SuperLong1Password',
            'Marie',
            'Dupont',
            null,
            '203.0.113.7'
        );

        $this->assertArrayNotHasKey('error', $result, 'Registration should succeed');
        $this->assertArrayHasKey('user_id', $result);
        $this->assertSame($email, $result['token_sent_to_email']);

        $userId = (int) $result['user_id'];
        $this->assertGreaterThan(0, $userId);

        // user external, inactive, fk_soc set
        $row = $this->fetchUserRow($userId);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['statut'], 'New user must be inactive (statut=0)');
        $this->assertSame($email, strtolower((string) $row['email']));
        $this->assertGreaterThan(0, (int) $row['fk_soc'], 'External user must have fk_soc set');

        // thirdparty in prospect mode (Dolibarr encodes that as client=2)
        $thirdpartyId = (int) $row['fk_soc'];
        $thirdparty = $this->fetchSocieteRow($thirdpartyId);
        $this->assertNotNull($thirdparty);
        $this->assertSame(2, (int) $thirdparty['client'], 'Thirdparty must be flagged as prospect (client=2)');

        // a contact exists for that thirdparty with the registered email
        $contactCount = $this->getTableCount('socpeople', [
            'fk_soc' => $thirdpartyId,
            'email' => $email,
        ]);
        $this->assertGreaterThanOrEqual(1, $contactCount, 'Contact must be attached to the thirdparty');

        // a register token row exists
        $tokenRows = $this->getTableCount('smartauth_email_validation', [
            'fk_user' => $userId,
            'purpose' => 'register',
        ]);
        $this->assertSame(1, $tokenRows, 'Exactly one register token must be stored');

        // email was dispatched (via the injected sender)
        $this->assertCount(1, $this->sentEmails);
        $this->assertSame($email, $this->sentEmails[0]['to']);
        $this->assertStringContainsString('/register/confirm', (string) $this->sentEmails[0]['text']);
    }

    public function testStartRegistrationDoesNotCreateUserOnTokenFailure(): void
    {
        // Force the email sender to fail; the service must rollback so no
        // partial thirdparty / user remains.
        $this->sentEmails = [];
        $service = new RegistrationService($this->db, function () {
            return false;
        });

        $email = 'failuser_' . uniqid() . '@example.com';
        $result = $service->startRegistration(
            $email,
            'SuperLong1Password',
            'Bob',
            'Failed',
            null,
            '127.0.0.1'
        );

        $this->assertSame(['error' => RegistrationService::ERR_EMAIL_FAILED], $result);

        // No user matches that email after rollback
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "user WHERE email = '" . $this->db->escape($email) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertSame(0, (int) $obj->cnt, 'Failed registration must not leave a user row behind');
    }

    public function testStartRegistrationRefusesAlreadyKnownEmail(): void
    {
        // Pre-existing active user with the email
        $email = 'taken_' . uniqid() . '@example.com';
        $this->createTestUser(['email' => $email, 'pass' => 'SuperLong1Password']);

        $result = $this->service->startRegistration(
            $email,
            'SuperLong1Password',
            'Marie',
            'Dupont',
            null,
            '127.0.0.1'
        );

        $this->assertSame(['error' => RegistrationService::ERR_EMAIL_TAKEN], $result);
        $this->assertCount(0, $this->sentEmails);
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

    private function fetchSocieteRow(int $thirdpartyId): ?array
    {
        // The Dolibarr SQLite test schema only stores client/prospect status
        // in the `client` column (bit 1 = customer, bit 2 = prospect).
        $sql = "SELECT rowid, nom, client FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . ((int) $thirdpartyId);
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
            'nom' => (string) $obj->nom,
            'client' => (int) $obj->client,
        ];
    }

    private function ensureEmailValidationTable(): void
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='" . MAIN_DB_PREFIX . "smartauth_email_validation'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return;
        }
        // Fallback: create the table from the install SQL file. The bootstrap
        // normally does this via _load_tables, but be defensive.
        $createSql = file_get_contents(dirname(__DIR__, 4) . '/sql/llx_smartauth_email_validation.sql');
        if ($createSql !== false) {
            $this->db->query($createSql);
        }
    }
}
