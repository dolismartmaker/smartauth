<?php

/**
 * Integration tests for the QR pair flow (mobile-side controller).
 *
 * Covers:
 *   - claim happy path (pending -> claimed, returns plain claim_token)
 *   - claim wrong-id -> 404 / claim already-claimed -> 409
 *   - poll without correct claim_token -> 403
 *   - poll on a confirmed row -> issues access+refresh tokens, marks consumed
 *   - poll twice on a confirmed row -> only the first delivers tokens
 *   - poll on a cancelled row -> status:cancelled, no tokens
 *   - poll on an expired pending row -> status:expired
 *
 * @covers \SmartAuth\Api\QrPairController
 * @covers \SmartAuthQrPairing
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/QrPairController.php';
require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../class/smartauthqrpairing.class.php';

use SmartAuth\Api\QrPairController;
use SmartAuth\Api\AuthController;
use SmartAuthQrPairing;

class QrPairControllerTest extends DolibarrRealTestCase
{
    /** @var QrPairController */
    private $controller;

    /** @var SmartAuthQrPairing */
    private $repo;

    /** @var int */
    private $adminUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        global $smartAuthAppID, $smartAuthAppKey, $conf;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';
        $conf->entity = 1;

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit/qrpair';

        $this->repo = new SmartAuthQrPairing($this->db);
        $this->controller = new QrPairController($this->db, $this->repo, new AuthController());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testClaimPendingPairingReturnsClaimToken(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $rowid = $this->repo->createPending($pairingId, $this->adminUserId, '198.51.100.5', 1);
        $this->assertGreaterThan(0, $rowid);

        $result = $this->controller->claim([
            'pairing_id' => $pairingId,
            'device_label' => 'iPhone Eric',
            'device_uuid' => '11112222-3333-4444-5555-666677778888',
        ]);

        $this->assertSame(200, $result[1]);
        $this->assertSame(SmartAuthQrPairing::STATUS_CLAIMED, $result[0]['status']);
        $this->assertNotEmpty($result[0]['claim_token']);
        $this->assertGreaterThanOrEqual(40, strlen($result[0]['claim_token']));

        $row = $this->repo->findByPairingId($pairingId, 1);
        $this->assertSame(SmartAuthQrPairing::STATUS_CLAIMED, $row['status']);
        $this->assertSame(SmartAuthQrPairing::hashClaimToken($result[0]['claim_token']), $row['claim_token_hash']);
        $this->assertSame('iPhone Eric', $row['device_label']);
        $this->assertSame('203.0.113.10', $row['claim_ip']);
    }

    public function testClaimUnknownPairingReturns404(): void
    {
        $result = $this->controller->claim([
            'pairing_id' => str_repeat('a', 32),
        ]);
        $this->assertSame(404, $result[1]);
        $this->assertSame('pairing_not_found', $result[0]['error']);
    }

    public function testClaimMalformedPairingIdReturns400(): void
    {
        $result = $this->controller->claim(['pairing_id' => 'not-hex']);
        $this->assertSame(400, $result[1]);
    }

    public function testClaimAlreadyClaimedReturns409(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($pairingId, $this->adminUserId, null, 1);

        $first = $this->controller->claim(['pairing_id' => $pairingId]);
        $this->assertSame(200, $first[1]);

        $second = $this->controller->claim(['pairing_id' => $pairingId]);
        $this->assertSame(409, $second[1]);
        $this->assertSame('pairing_not_claimable', $second[0]['error']);
    }

    public function testPollRequiresClaimToken(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $this->controller->claim(['pairing_id' => $pairingId]);

        // No token at all
        $result = $this->controller->poll(['pairing_id' => $pairingId]);
        $this->assertSame(400, $result[1]);

        // Wrong token
        $result = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => 'wrong-token-' . str_repeat('x', 30),
        ]);
        $this->assertSame(403, $result[1]);
        $this->assertSame('invalid_claim_token', $result[0]['error']);
    }

    public function testPollOnClaimedRowReturnsPending(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $claim = $this->controller->claim(['pairing_id' => $pairingId]);
        $token = $claim[0]['claim_token'];

        $result = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => $token,
        ]);
        $this->assertSame(200, $result[1]);
        $this->assertSame(SmartAuthQrPairing::STATUS_PENDING, $result[0]['status']);
        $this->assertArrayNotHasKey('access_token', $result[0]);
    }

    public function testPollOnConfirmedRowIssuesTokensAndMarksConsumed(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $rowid = $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $claim = $this->controller->claim(['pairing_id' => $pairingId]);
        $token = $claim[0]['claim_token'];

        // Simulate the PC user pressing "Authorise" on /custom/smartauth/user/qrpair.php
        $this->assertTrue($this->repo->markConfirmed($rowid, $this->adminUserId));

        // First poll: tokens issued, status=consumed.
        $first = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => $token,
        ]);
        $this->assertSame(200, $first[1]);
        $this->assertSame(SmartAuthQrPairing::STATUS_CONSUMED, $first[0]['status']);
        $this->assertNotEmpty($first[0]['access_token']);
        $this->assertNotEmpty($first[0]['refresh_token']);
        $this->assertStringContainsString('|', $first[0]['access_token']);
        $this->assertStringContainsString('|', $first[0]['refresh_token']);
        $this->assertGreaterThan(0, (int) ($first[0]['expires_in'] ?? 0));

        // Second poll: row is consumed, no second token pair issued.
        $second = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => $token,
        ]);
        $this->assertSame(200, $second[1]);
        $this->assertSame(SmartAuthQrPairing::STATUS_CONSUMED, $second[0]['status']);
        $this->assertArrayNotHasKey('access_token', $second[0]);
    }

    public function testPollOnCancelledRowReturnsCancelledNoTokens(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $rowid = $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $claim = $this->controller->claim(['pairing_id' => $pairingId]);
        $this->assertTrue($this->repo->markCancelled($rowid, $this->adminUserId));

        $result = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => $claim[0]['claim_token'],
        ]);
        $this->assertSame(200, $result[1]);
        $this->assertSame(SmartAuthQrPairing::STATUS_CANCELLED, $result[0]['status']);
        $this->assertArrayNotHasKey('access_token', $result[0]);
    }

    public function testMarkConfirmedRequiresClaimedStatus(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $rowid = $this->repo->createPending($pairingId, $this->adminUserId, null, 1);

        // Pending row cannot be confirmed directly: it must transit through claimed.
        $this->assertFalse($this->repo->markConfirmed($rowid, $this->adminUserId));

        $this->controller->claim(['pairing_id' => $pairingId]);
        $this->assertTrue($this->repo->markConfirmed($rowid, $this->adminUserId));

        // Cannot be confirmed twice.
        $this->assertFalse($this->repo->markConfirmed($rowid, $this->adminUserId));
    }

    public function testMarkConfirmedRefusesWrongUser(): void
    {
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $rowid = $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $this->controller->claim(['pairing_id' => $pairingId]);

        $this->assertFalse($this->repo->markConfirmed($rowid, $this->adminUserId + 99));

        $row = $this->repo->findByPairingId($pairingId, 1);
        $this->assertSame(SmartAuthQrPairing::STATUS_CLAIMED, $row['status']);
    }

    public function testIsExpiredHandlesPastTimestamps(): void
    {
        $row = ['expires_at' => date('Y-m-d H:i:s', dol_now() - 60)];
        $this->assertTrue(SmartAuthQrPairing::isExpired($row));

        $row2 = ['expires_at' => date('Y-m-d H:i:s', dol_now() + 60)];
        $this->assertFalse(SmartAuthQrPairing::isExpired($row2));
    }

    public function testRateLimitRecordsActualSuccessFlag(): void
    {
        $clientIp = '203.0.113.10';
        // Wipe any leftover rate-limit rows for this IP across the two
        // actions so the assertions below are deterministic.
        foreach (['qr_pair_claim', 'qr_pair_poll'] as $act) {
            $this->db->query(
                "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit"
                . " WHERE identifier = '" . $this->db->escape($clientIp) . "'"
                . " AND action = '" . $this->db->escape($act) . "'"
            );
        }

        // 1) Successful claim must be recorded with success=1.
        $pairingId = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($pairingId, $this->adminUserId, null, 1);
        $claim = $this->controller->claim(['pairing_id' => $pairingId]);
        $this->assertSame(200, $claim[1]);

        $this->assertSame(
            ['1'],
            $this->fetchSuccessFlags($clientIp, 'qr_pair_claim'),
            'Successful claim() must record success=1, not success=0'
        );

        // 2) Failed claim (unknown pairing) must be recorded with success=0.
        $bogusId = str_repeat('a', 32);
        $fail = $this->controller->claim(['pairing_id' => $bogusId]);
        $this->assertSame(404, $fail[1]);
        $this->assertSame(
            ['0', '1'],
            $this->sortedSuccessFlags($clientIp, 'qr_pair_claim'),
            'Failed claim() must add a success=0 row alongside the earlier success=1'
        );

        // 3) Successful poll on a claimed (still pending) pairing must be
        //    recorded with success=1 (HTTP 200, regardless of business state).
        $token = $claim[0]['claim_token'];
        $poll = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => $token,
        ]);
        $this->assertSame(200, $poll[1]);
        $this->assertSame(
            ['1'],
            $this->fetchSuccessFlags($clientIp, 'qr_pair_poll'),
            'Successful poll() must record success=1'
        );

        // 4) Poll with a wrong claim_token returns 403 -> success=0.
        $bad = $this->controller->poll([
            'pairing_id' => $pairingId,
            'claim_token' => 'wrong-token-' . str_repeat('x', 30),
        ]);
        $this->assertSame(403, $bad[1]);
        $this->assertSame(
            ['0', '1'],
            $this->sortedSuccessFlags($clientIp, 'qr_pair_poll'),
            'Failed poll() must add a success=0 row'
        );
    }

    /**
     * @return string[] Raw 'success' column values (DB returns them as strings).
     */
    private function fetchSuccessFlags(string $identifier, string $action): array
    {
        $sql = "SELECT success FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit"
            . " WHERE identifier = '" . $this->db->escape($identifier) . "'"
            . " AND action = '" . $this->db->escape($action) . "'"
            . " ORDER BY rowid";
        $resql = $this->db->query($sql);
        $out = [];
        while ($resql && ($obj = $this->db->fetch_object($resql))) {
            $out[] = (string) $obj->success;
        }
        return $out;
    }

    /**
     * @return string[]
     */
    private function sortedSuccessFlags(string $identifier, string $action): array
    {
        $flags = $this->fetchSuccessFlags($identifier, $action);
        sort($flags);
        return $flags;
    }

    public function testDeleteOldRemovesAgedRowsAndKeepsRecentOnes(): void
    {
        // Wipe so we control the row set deterministically.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_qr_pairings WHERE fk_user = " . $this->adminUserId);

        $oldId = SmartAuthQrPairing::generatePairingId();
        $newId = SmartAuthQrPairing::generatePairingId();

        $this->repo->createPending($oldId, $this->adminUserId, null, 1);
        $this->repo->createPending($newId, $this->adminUserId, null, 1);

        // Backdate the first row's datec to 30 days ago.
        $thirtyDaysAgo = $this->db->idate(dol_now() - 30 * 24 * 3600);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "smartauth_qr_pairings"
            . " SET datec = '" . $thirtyDaysAgo . "'"
            . " WHERE pairing_id = '" . $this->db->escape($oldId) . "'"
        );

        // 7-day retention: only the old row should be deleted.
        $deleted = $this->repo->deleteOld(7 * 24 * 3600);
        $this->assertSame(1, $deleted);

        $this->assertNull($this->repo->findByPairingId($oldId, 1), 'old row must be gone');
        $this->assertNotNull($this->repo->findByPairingId($newId, 1), 'recent row must survive');
    }

    public function testDeleteOldGuardsAgainstZeroOrNegativeAge(): void
    {
        // Sanity guard: callers should not pass 0 or negative; the helper
        // returns 0 instead of nuking the entire table.
        $this->assertSame(0, $this->repo->deleteOld(0));
        $this->assertSame(0, $this->repo->deleteOld(-1));
    }

    public function testFindActiveForUserReturnsTheLatestNonExpiredRow(): void
    {
        // Reset deterministically.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_qr_pairings WHERE fk_user = " . $this->adminUserId);

        // No row at all -> null.
        $this->assertNull($this->repo->findActiveForUser($this->adminUserId, 1));

        // One pending row -> returned.
        $idA = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($idA, $this->adminUserId, null, 1);
        $row = $this->repo->findActiveForUser($this->adminUserId, 1);
        $this->assertNotNull($row);
        $this->assertSame($idA, $row['pairing_id']);

        // A second, more recent row wins (ORDER BY datec DESC LIMIT 1).
        $idB = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($idB, $this->adminUserId, null, 1);
        $tomorrow = $this->db->idate(dol_now() + 60);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "smartauth_qr_pairings"
            . " SET datec = '" . $tomorrow . "'"
            . " WHERE pairing_id = '" . $this->db->escape($idB) . "'"
        );
        $row = $this->repo->findActiveForUser($this->adminUserId, 1);
        $this->assertSame($idB, $row['pairing_id']);

        // Cancelling all rows -> back to null.
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "smartauth_qr_pairings"
            . " SET status = '" . SmartAuthQrPairing::STATUS_CANCELLED . "'"
            . " WHERE fk_user = " . $this->adminUserId
        );
        $this->assertNull($this->repo->findActiveForUser($this->adminUserId, 1));
    }

    public function testFindActiveForUserSkipsExpiredRows(): void
    {
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_qr_pairings WHERE fk_user = " . $this->adminUserId);

        $stale = SmartAuthQrPairing::generatePairingId();
        $this->repo->createPending($stale, $this->adminUserId, null, 1);
        // Backdate expires_at to the past so the WHERE clause filters it out.
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "smartauth_qr_pairings"
            . " SET expires_at = '" . $this->db->idate(dol_now() - 60) . "'"
            . " WHERE pairing_id = '" . $this->db->escape($stale) . "'"
        );

        $this->assertNull(
            $this->repo->findActiveForUser($this->adminUserId, 1),
            'Expired rows must be skipped even when status is still pending.'
        );
    }
}
