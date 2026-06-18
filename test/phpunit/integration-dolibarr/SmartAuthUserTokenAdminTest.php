<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once dirname(__DIR__, 3) . '/class/smartauthusertokenadmin.class.php';

use SmartAuthUserTokenAdmin;
use User;

/**
 * Integration tests for SmartAuthUserTokenAdmin, the token operations exposed
 * on the SmartAuth tab of the Dolibarr user card (user_tab.php).
 *
 * Locks the SMA-005 / SI-126 contract: a real "Delete" (row removal) is
 * distinct from "Revoke" (disable, status=9), both in unit and in bulk, and
 * every operation is ownership-checked (fk_authid).
 *
 * @covers \SmartAuthUserTokenAdmin
 */
class SmartAuthUserTokenAdminTest extends DolibarrRealTestCase
{
    /** @var SmartAuthUserTokenAdmin */
    private $tokenAdmin;

    /** @var int second user, used as the "other owner" in ownership checks */
    private $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenAdmin = new SmartAuthUserTokenAdmin($this->db);

        $other = $this->createTestUser(['login' => 'tokadmin_other_' . uniqid()]);
        $this->otherUserId = (int) $other->id;
    }

    /**
     * Insert one token row owned by $fkAuthid and return its rowid.
     */
    private function seedToken(int $fkAuthid, int $status = 1): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " (appuid, salt, date_creation, fk_user_creat, fk_authid, auth_element, status, entity, fk_device_id)";
        $sql .= " VALUES (1, 'seedsalt', '" . $this->db->idate(time()) . "', ";
        $sql .= (int) $fkAuthid . ", " . (int) $fkAuthid . ", 'user', " . (int) $status . ", 1, " . (int) $this->testDevice->id . ")";
        $result = $this->db->query($sql);
        $this->assertNotFalse($result, "Failed to seed token: " . $this->db->lasterror());

        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
    }

    /**
     * Read the current status of a token, or null when the row is gone.
     */
    private function tokenStatus(int $tokenId): ?int
    {
        $resql = $this->db->query("SELECT status FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . (int) $tokenId);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        return $obj ? (int) $obj->status : null;
    }

    public function testRevokeDisablesTokenButKeepsRow(): void
    {
        $tokenId = $this->seedToken((int) $this->testUser->id);

        $res = $this->tokenAdmin->revoke($tokenId, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_OK, $res);
        // Row still there, but disabled and salt-marked.
        $this->assertSame(SmartAuthUserTokenAdmin::STATUS_REVOKED, $this->tokenStatus($tokenId));
        $this->assertDatabaseHas('smartauth_auth', ['rowid' => $tokenId, 'salt' => SmartAuthUserTokenAdmin::SALT_REVOKED]);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $tokenId = $this->seedToken((int) $this->testUser->id);

        $res = $this->tokenAdmin->delete($tokenId, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_OK, $res);
        // Hard delete: the row is gone, not merely disabled.
        $this->assertNull($this->tokenStatus($tokenId));
        $this->assertDatabaseMissing('smartauth_auth', ['rowid' => $tokenId]);
    }

    public function testRevokeRefusesForeignToken(): void
    {
        $tokenId = $this->seedToken($this->otherUserId);

        $res = $this->tokenAdmin->revoke($tokenId, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_NOT_FOUND, $res);
        // Untouched: still active, still owned by the other user.
        $this->assertSame(1, $this->tokenStatus($tokenId));
    }

    public function testDeleteRefusesForeignToken(): void
    {
        $tokenId = $this->seedToken($this->otherUserId);

        $res = $this->tokenAdmin->delete($tokenId, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_NOT_FOUND, $res);
        // Untouched: the foreign row is still present.
        $this->assertDatabaseHas('smartauth_auth', ['rowid' => $tokenId]);
    }

    public function testRevokeReturnsNotFoundOnAbsentToken(): void
    {
        $res = $this->tokenAdmin->revoke(999999, (int) $this->testUser->id);
        $this->assertSame(SmartAuthUserTokenAdmin::RES_NOT_FOUND, $res);
    }

    public function testMassRevokeRevokesOnlyTheSelection(): void
    {
        $a = $this->seedToken((int) $this->testUser->id);
        $b = $this->seedToken((int) $this->testUser->id);
        $kept = $this->seedToken((int) $this->testUser->id);

        $done = $this->tokenAdmin->massRevoke([$a, $b], (int) $this->testUser->id);

        $this->assertSame(2, $done);
        $this->assertSame(SmartAuthUserTokenAdmin::STATUS_REVOKED, $this->tokenStatus($a));
        $this->assertSame(SmartAuthUserTokenAdmin::STATUS_REVOKED, $this->tokenStatus($b));
        // The unselected token is left alone.
        $this->assertSame(1, $this->tokenStatus($kept));
    }

    public function testMassDeleteDeletesOnlyTheSelection(): void
    {
        $a = $this->seedToken((int) $this->testUser->id);
        $b = $this->seedToken((int) $this->testUser->id);
        $kept = $this->seedToken((int) $this->testUser->id);

        $done = $this->tokenAdmin->massDelete([$a, $b], (int) $this->testUser->id);

        $this->assertSame(2, $done);
        $this->assertNull($this->tokenStatus($a));
        $this->assertNull($this->tokenStatus($b));
        // The unselected token still exists and stays active.
        $this->assertSame(1, $this->tokenStatus($kept));
    }

    public function testMassActionSkipsForeignAndBogusIds(): void
    {
        $mine = $this->seedToken((int) $this->testUser->id);
        $foreign = $this->seedToken($this->otherUserId);

        // Mix: one owned id, one foreign id, one bogus (<=0) id.
        $done = $this->tokenAdmin->massDelete([$mine, $foreign, 0, -5], (int) $this->testUser->id);

        $this->assertSame(1, $done, 'Only the owned token must be deleted');
        $this->assertNull($this->tokenStatus($mine));
        // The foreign token is never touched by another user's mass action.
        $this->assertSame(1, $this->tokenStatus($foreign));
    }

    public function testRevokeIsDistinctFromDelete(): void
    {
        // Core SMA-005 contract: revoke disables (row survives), delete removes.
        $toRevoke = $this->seedToken((int) $this->testUser->id);
        $toDelete = $this->seedToken((int) $this->testUser->id);

        $this->tokenAdmin->revoke($toRevoke, (int) $this->testUser->id);
        $this->tokenAdmin->delete($toDelete, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::STATUS_REVOKED, $this->tokenStatus($toRevoke));
        $this->assertNull($this->tokenStatus($toDelete));
    }
}
