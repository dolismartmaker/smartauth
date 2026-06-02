<?php

/**
 * Integration tests for SubjectAuthenticator against the real Dolibarr SQLite
 * database (llx_societe_account + llx_user).
 *
 * Covers source selection (SMARTAUTH_AUTH_SOURCE), site filtering
 * (SMARTAUTH_AUTH_SITES), and credential verification for both subject kinds.
 *
 * @covers \SmartAuth\Api\OAuth2\SubjectAuthenticator
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\OAuth2\SubjectAuthenticator;
use SmartAuth\Api\OAuth2\TokenSubject;

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
dol_include_once('/smartauth/api/OAuth2/SubjectAuthenticator.php');

class SubjectAuthenticatorTest extends DolibarrRealTestCase
{
    /** @var SubjectAuthenticator */
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;
        // Start each test from the documented defaults.
        unset($conf->global->SMARTAUTH_AUTH_SOURCE);
        unset($conf->global->SMARTAUTH_AUTH_SITES);
        $this->auth = new SubjectAuthenticator($this->db);
    }

    protected function tearDown(): void
    {
        global $conf;
        unset($conf->global->SMARTAUTH_AUTH_SOURCE);
        unset($conf->global->SMARTAUTH_AUTH_SITES);
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT);
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT);
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_USER);
        parent::tearDown();
    }

    public function testDefaultsSourceBothSitesSmartauth(): void
    {
        $this->assertSame(SubjectAuthenticator::SOURCE_BOTH, $this->auth->authSource());
        $this->assertSame(['smartauth'], $this->auth->authSites());
    }

    public function testSourceFallsBackToBothOnGarbage(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE = 'nonsense';
        $this->assertSame(SubjectAuthenticator::SOURCE_BOTH, $this->auth->authSource());
    }

    public function testAuthSitesParsesCsv(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SITES = ' smartauth , onepagebasket ,, ';
        $this->assertSame(['smartauth', 'onepagebasket'], $this->auth->authSites());
    }

    public function testAccountLoginSucceedsWithBothSource(): void
    {
        $soc = $this->createTestSociete();
        $accountId = $this->createPortalAccount('client@example.com', 'Sup3rSecret!', 1, 'smartauth', (int) $soc->id);

        $subject = $this->auth->authenticate('client@example.com', 'Sup3rSecret!');

        $this->assertInstanceOf(TokenSubject::class, $subject);
        $this->assertTrue($subject->isAccount());
        $this->assertSame($accountId, $subject->getId());
        $this->assertSame((int) $soc->id, $subject->getFkSoc());
    }

    public function testAccountLoginFailsWithWrongPassword(): void
    {
        $this->createPortalAccount('client2@example.com', 'GoodPassword1', 1, 'smartauth', 0);
        $this->assertNull($this->auth->authenticate('client2@example.com', 'WrongPassword'));
    }

    public function testDisabledAccountCannotLogin(): void
    {
        $this->createPortalAccount('client3@example.com', 'GoodPassword1', 0, 'smartauth', 0);
        $this->assertNull($this->auth->authenticate('client3@example.com', 'GoodPassword1'));
    }

    public function testAccountWithSiteOutsideAllowedListIsRejected(): void
    {
        $this->createPortalAccount('client4@example.com', 'GoodPassword1', 1, 'someothersite', 0);
        // Default sites = ['smartauth'] -> the 'someothersite' row is invisible.
        $this->assertNull($this->auth->authenticate('client4@example.com', 'GoodPassword1'));
    }

    public function testAccountSiteAcceptedWhenListedInAuthSites(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SITES = 'smartauth,onepagebasket';
        $this->createPortalAccount('client5@example.com', 'GoodPassword1', 1, 'onepagebasket', 0);

        $subject = $this->auth->authenticate('client5@example.com', 'GoodPassword1');
        $this->assertInstanceOf(TokenSubject::class, $subject);
        $this->assertTrue($subject->isAccount());
    }

    public function testUserLoginSucceedsAsUserSubject(): void
    {
        $login = 'agent_' . uniqid();
        $user = $this->createTestUser(['login' => $login, 'pass' => 'AgentPass123', 'statut' => 1]);

        $subject = $this->auth->authenticate($login, 'AgentPass123');

        $this->assertInstanceOf(TokenSubject::class, $subject);
        $this->assertTrue($subject->isUser());
        $this->assertSame((int) $user->id, $subject->getId());
        // Internal staff (no societe) -> internal user, bypasses capsso gating.
        $this->assertTrue($subject->isInternalUser());
    }

    public function testSourceUserOnlyRejectsAccountCredentials(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE = SubjectAuthenticator::SOURCE_USER;
        $this->createPortalAccount('client6@example.com', 'GoodPassword1', 1, 'smartauth', 0);

        $this->assertNull($this->auth->authenticate('client6@example.com', 'GoodPassword1'));
    }

    public function testSourceAccountOnlyRejectsUserCredentials(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE = SubjectAuthenticator::SOURCE_ACCOUNT;
        $login = 'agent_' . uniqid();
        $this->createTestUser(['login' => $login, 'pass' => 'AgentPass123', 'statut' => 1]);

        $this->assertNull($this->auth->authenticate($login, 'AgentPass123'));
    }

    public function testCompatLegacyBothEnablesAccountAndUserNotAdherent(): void
    {
        // No new toggles saved -> falls back to legacy enum (default both).
        $this->assertSame(
            [SubjectAuthenticator::SOURCE_ACCOUNT, SubjectAuthenticator::SOURCE_USER],
            $this->auth->enabledSources()
        );
    }

    public function testTogglesOverrideLegacyEnum(): void
    {
        global $conf;
        // Legacy says user-only, but the new toggles enable adherent only.
        $conf->global->SMARTAUTH_AUTH_SOURCE = SubjectAuthenticator::SOURCE_USER;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT = '0';
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_USER = '0';

        $this->assertSame([SubjectAuthenticator::SOURCE_ADHERENT], $this->auth->enabledSources());
    }

    public function testFixedPriorityOrderAccountBeforeAdherentBeforeUser(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_USER = '1';

        $this->assertSame(
            [
                SubjectAuthenticator::SOURCE_ACCOUNT,
                SubjectAuthenticator::SOURCE_ADHERENT,
                SubjectAuthenticator::SOURCE_USER,
            ],
            $this->auth->enabledSources()
        );
    }

    public function testAdherentLoginSucceedsAsMemberSubject(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';
        $adherentId = $this->createAdherent('member7', 'MemberPass123', 1, 0);

        $subject = $this->auth->authenticate('member7', 'MemberPass123');

        $this->assertInstanceOf(TokenSubject::class, $subject);
        $this->assertTrue($subject->isMember());
        $this->assertSame($adherentId, $subject->getId());
    }

    public function testAdherentLoginIgnoredWhenToggleOff(): void
    {
        global $conf;
        // Only account+user enabled; adherent explicitly off.
        $conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '0';
        $conf->global->SMARTAUTH_AUTH_SOURCE_USER = '1';
        $this->createAdherent('member8', 'MemberPass123', 1, 0);

        $this->assertNull($this->auth->authenticate('member8', 'MemberPass123'));
    }

    public function testDisabledAdherentCannotLogin(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';
        $this->createAdherent('member9', 'MemberPass123', 0, 0);

        $this->assertNull($this->auth->authenticate('member9', 'MemberPass123'));
    }

    public function testAccountWinsOverAdherentOnSameLogin(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_USER = '0';

        $login = 'shared_' . uniqid();
        $this->createPortalAccount($login, 'SharedPass123', 1, 'smartauth', 0);
        $this->createAdherent($login, 'SharedPass123', 1, 0);

        $subject = $this->auth->authenticate($login, 'SharedPass123');
        $this->assertInstanceOf(TokenSubject::class, $subject);
        // Fixed priority account > adherent.
        $this->assertTrue($subject->isAccount());
    }

    /**
     * Insert an llx_adherent row with a bcrypt-hashed password. Returns rowid.
     */
    private function createAdherent(string $login, string $password, int $statut, int $fkSoc): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = $this->db->idate(dol_now());
        $uniq = uniqid();

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, statut, login, pass_crypted, email, firstname, lastname, fk_soc, datec)';
        $sql .= " VALUES ('MBR_" . $uniq . "', 1, 1, 'phy', " . (int) $statut . ",";
        $sql .= " '" . $this->db->escape($login) . "', '" . $this->db->escape($hash) . "',";
        $sql .= " 'adh_" . $uniq . "@example.com', 'Test', 'Member',";
        $sql .= ' ' . ($fkSoc > 0 ? (int) $fkSoc : 'NULL') . ", '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert adherent: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }

    /**
     * Insert a societe_account portal row with a bcrypt-hashed password.
     * Raw SQL on purpose (the dolibarr-integration-sqlite CommonObject stub
     * trips on createCommon for this table). Returns the rowid.
     */
    private function createPortalAccount(string $login, string $password, int $status, string $site, int $fkSoc): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = $this->db->idate(dol_now());

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, pass_crypted, fk_soc, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, '" . $this->db->escape($login) . "',";
        $sql .= " '" . $this->db->escape($hash) . "', " . ($fkSoc > 0 ? (int) $fkSoc : 'NULL') . ",";
        $sql .= " '" . $this->db->escape($site) . "', " . (int) $status . ",";
        $sql .= ' ' . (int) $this->testUser->id . ", '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }

        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
    }
}
