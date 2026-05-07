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
}
