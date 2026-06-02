<?php

/**
 * Integration tests for TokenSubject::isActive() against the real Dolibarr
 * SQLite database (llx_user and llx_societe_account).
 *
 * The pure encoding/predicate logic is covered by the fast unit suite
 * (test/phpunit/unit/OAuth2/TokenSubjectTest.php).
 *
 * @covers \SmartAuth\Api\OAuth2\TokenSubject
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\OAuth2\TokenSubject;

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');

class TokenSubjectIsActiveTest extends DolibarrRealTestCase
{
    public function testUserSubjectActiveWhenStatutOne(): void
    {
        $user = $this->createTestUser(['statut' => 1]);

        $subject = TokenSubject::user((int) $user->id);
        $this->assertTrue($subject->isActive($this->db));
    }

    public function testUserSubjectInactiveWhenStatutZero(): void
    {
        $user = $this->createTestUser(['statut' => 0]);

        $subject = TokenSubject::user((int) $user->id);
        $this->assertFalse($subject->isActive($this->db));
    }

    public function testUserSubjectInactiveWhenUnknownId(): void
    {
        $subject = TokenSubject::user(987654321);
        $this->assertFalse($subject->isActive($this->db));
    }

    public function testAccountSubjectActiveWhenStatusOne(): void
    {
        $accountId = $this->createSocieteAccount(1);

        $subject = TokenSubject::account($accountId, 0);
        $this->assertTrue($subject->isActive($this->db));
    }

    public function testAccountSubjectInactiveWhenStatusZero(): void
    {
        $accountId = $this->createSocieteAccount(0);

        $subject = TokenSubject::account($accountId, 0);
        $this->assertFalse($subject->isActive($this->db));
    }

    public function testAccountSubjectInactiveWhenUnknownId(): void
    {
        $subject = TokenSubject::account(987654321, 0);
        $this->assertFalse($subject->isActive($this->db));
    }

    public function testMemberSubjectActiveWhenStatutOne(): void
    {
        $adherentId = $this->createAdherent(1);

        $subject = TokenSubject::member($adherentId, 0);
        $this->assertTrue($subject->isActive($this->db));
    }

    public function testMemberSubjectInactiveWhenStatutZero(): void
    {
        $adherentId = $this->createAdherent(0);

        $subject = TokenSubject::member($adherentId, 0);
        $this->assertFalse($subject->isActive($this->db));
    }

    public function testMemberSubjectInactiveWhenUnknownId(): void
    {
        $subject = TokenSubject::member(987654321, 0);
        $this->assertFalse($subject->isActive($this->db));
    }

    /**
     * Create an llx_adherent row with the given statut (1 = validated member).
     * Raw minimal INSERT (same rationale as createSocieteAccount): isActive()
     * only reads the statut column.
     */
    private function createAdherent(int $statut): int
    {
        $uniq = uniqid();
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, statut, login, email, firstname, lastname, datec)';
        $sql .= " VALUES ('MBR_" . $uniq . "', 1, 1, 'phy', " . (int) $statut . ",";
        $sql .= " 'member_" . $uniq . "', 'member_" . $uniq . "@example.com', 'Test', 'Member', '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert adherent: ' . $this->db->lasterror());
        }

        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }

    /**
     * Create a societe_account portal row (site='smartauth') tied to a fresh
     * test societe, with the given status. Returns its rowid.
     *
     * Inserted with raw SQL on purpose: the dolibarr-integration-sqlite stub
     * of CommonObject::createCommon trips on an "Undefined array key default"
     * for this table, and isActive() only reads the status column, so a
     * minimal INSERT is both sufficient and more robust here.
     */
    private function createSocieteAccount(int $status): int
    {
        $soc = $this->createTestSociete();

        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, fk_soc, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, 'portal_" . uniqid() . "', " . (int) $soc->id . ",";
        $sql .= " 'smartauth', " . (int) $status . ", " . (int) $this->testUser->id . ", '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }

        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
    }
}
