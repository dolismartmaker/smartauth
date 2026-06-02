<?php

/**
 * Integration tests for TokenSubject::buildClaims() against the real Dolibarr
 * SQLite database (llx_user, llx_societe_account, llx_societe).
 *
 * @covers \SmartAuth\Api\OAuth2\TokenSubject
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\OAuth2\TokenSubject;

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');

class TokenSubjectBuildClaimsTest extends DolibarrRealTestCase
{
    public function testUserClaimsWithProfileAndEmail(): void
    {
        $user = $this->createTestUser([
            'firstname' => 'Alice',
            'lastname' => 'Martin',
            'email' => 'alice.martin@example.com',
            'statut' => 1,
        ]);

        $subject = TokenSubject::user((int) $user->id);
        $claims = $subject->buildClaims($this->db, ['openid', 'profile', 'email']);

        $this->assertSame('usr:' . (int) $user->id, $claims['sub']);
        $this->assertSame('Alice Martin', $claims['name']);
        $this->assertSame('Martin', $claims['family_name']);
        $this->assertSame('Alice', $claims['given_name']);
        $this->assertSame('alice.martin@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function testUserClaimsOpenidOnlyYieldsOnlySub(): void
    {
        $user = $this->createTestUser(['statut' => 1]);

        $claims = TokenSubject::user((int) $user->id)->buildClaims($this->db, ['openid']);

        $this->assertSame(['sub'], array_keys($claims));
        $this->assertSame('usr:' . (int) $user->id, $claims['sub']);
    }

    public function testAccountClaimsEmailFromLoginNameFromSociete(): void
    {
        $soc = $this->createTestSociete(['name' => 'ACME Corporation']);
        $accountId = $this->createPortalAccount('portal@acme.example', (int) $soc->id);

        $subject = TokenSubject::account($accountId, (int) $soc->id);
        $claims = $subject->buildClaims($this->db, ['openid', 'profile', 'email']);

        $this->assertSame('acc:' . $accountId, $claims['sub']);
        $this->assertSame('portal@acme.example', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertSame('ACME Corporation', $claims['name']);
        // A company portal account has no personal given/family name.
        $this->assertArrayNotHasKey('given_name', $claims);
        $this->assertArrayNotHasKey('family_name', $claims);
    }

    public function testAccountClaimsOpenidOnlyYieldsOnlySub(): void
    {
        $soc = $this->createTestSociete();
        $accountId = $this->createPortalAccount('portal2@acme.example', (int) $soc->id);

        $claims = TokenSubject::account($accountId, (int) $soc->id)->buildClaims($this->db, ['openid']);

        $this->assertSame(['sub'], array_keys($claims));
        $this->assertSame('acc:' . $accountId, $claims['sub']);
    }

    public function testMemberClaimsExposePersonalNameAndEmail(): void
    {
        $adherentId = $this->createAdherent('Bob', 'Durand', 'bob.durand@example.com');

        $subject = TokenSubject::member($adherentId, 0);
        $claims = $subject->buildClaims($this->db, ['openid', 'profile', 'email']);

        $this->assertSame('mbr:' . $adherentId, $claims['sub']);
        $this->assertSame('Bob Durand', $claims['name']);
        // Unlike a company account, a member carries a personal name.
        $this->assertSame('Bob', $claims['given_name']);
        $this->assertSame('Durand', $claims['family_name']);
        $this->assertSame('bob.durand@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function testMemberClaimsOpenidOnlyYieldsOnlySub(): void
    {
        $adherentId = $this->createAdherent('X', 'Y', 'xy@example.com');

        $claims = TokenSubject::member($adherentId, 0)->buildClaims($this->db, ['openid']);

        $this->assertSame(['sub'], array_keys($claims));
        $this->assertSame('mbr:' . $adherentId, $claims['sub']);
    }

    public function testMemberClaimsEmailFallsBackToLogin(): void
    {
        // Adherent with an empty email -> email claim falls back to login.
        $adherentId = $this->createAdherent('No', 'Email', '');

        $claims = TokenSubject::member($adherentId, 0)->buildClaims($this->db, ['openid', 'email']);

        $this->assertArrayHasKey('email', $claims);
        $this->assertStringStartsWith('member_', $claims['email']);
    }

    /**
     * Insert a minimal llx_adherent row. Returns the rowid. The login is always
     * set (used as the email fallback test).
     */
    private function createAdherent(string $firstname, string $lastname, string $email): int
    {
        $uniq = uniqid();
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, statut, login, email, firstname, lastname, datec)';
        $sql .= " VALUES ('MBR_" . $uniq . "', 1, 1, 'phy', 1,";
        $sql .= " 'member_" . $uniq . "', '" . $this->db->escape($email) . "',";
        $sql .= " '" . $this->db->escape($firstname) . "', '" . $this->db->escape($lastname) . "', '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert adherent: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }

    /**
     * Insert a minimal societe_account row (raw SQL: the SQLite CommonObject
     * stub trips on createCommon for this table). Returns the rowid.
     */
    private function createPortalAccount(string $login, int $fkSoc): int
    {
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, fk_soc, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, '" . $this->db->escape($login) . "', " . (int) $fkSoc . ",";
        $sql .= " 'smartauth', 1, " . (int) $this->testUser->id . ", '" . $now . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
    }
}
