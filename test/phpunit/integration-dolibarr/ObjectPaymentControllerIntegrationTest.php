<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';
require_once __DIR__ . '/../../../api/ObjectLineController.php';
require_once __DIR__ . '/../../../api/ObjectActionController.php';
require_once __DIR__ . '/../../../api/ObjectPaymentController.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;
use SmartAuth\Api\ObjectPaymentController;

/**
 * Integration tests for the invoice payment facade (ObjectPaymentController):
 * record a full payment (invoice flips to paid), a partial payment (remain
 * tracked), list payments, and the input/permission guards.
 *
 * @covers \SmartAuth\Api\ObjectPaymentController
 */
class ObjectPaymentControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $objects;

    /** @var ObjectLineController */
    private $lines;

    /** @var ObjectActionController */
    private $actions;

    /** @var ObjectPaymentController */
    private $payments;

    /** @var array<int,array{0:string,1:int}> */
    private $created = [];

    /** @var int */
    private $paymentModeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new ObjectController();
        $this->lines = new ObjectLineController();
        $this->actions = new ObjectActionController();
        $this->payments = new ObjectPaymentController();
        $this->created = [];
        $this->paymentModeId = $this->resolvePaymentModeId();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            list($table, $id) = $row;
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table . " WHERE rowid = " . (int) $id);
        }
        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->created[] = [$table, $id];
    }

    /** An active payment-mode id from the dictionary, seeding one if needed. */
    private function resolvePaymentModeId(): int
    {
        $res = $this->db->query("SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 ORDER BY id LIMIT 1");
        if ($res && ($row = $this->db->fetch_object($res))) {
            return (int) $row->id;
        }
        // Seed a minimal mode if the dictionary is empty in this schema.
        $this->db->query("INSERT INTO " . MAIN_DB_PREFIX . "c_paiement (id, code, libelle, type, active, entity) VALUES (2, 'CHQ', 'Cheque', 0, 1, 1)");
        return 2;
    }

    /** Create a validated invoice with a single 100 HT / 0% VAT line. Returns its id. */
    private function createValidatedInvoice(): int
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->objects->create([
            'objtype' => 'invoice', 'thirdparty' => (int) $soc->id, 'date_invoice' => dol_now(),
        ]);
        $this->assertSame(201, $code, 'invoice create failed: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('facture', $id);

        list($lBody, $lCode) = $this->lines->store([
            'objtype' => 'invoice', 'id' => $id,
            'description' => 'Service', 'quantity' => 1, 'unit_price_excl_tax' => 100, 'vat_rate' => 0,
        ]);
        $this->assertSame(201, $lCode, 'invoice line failed: ' . json_encode($lBody));

        list($vBody, $vCode) = $this->actions->invoke(['objtype' => 'invoice', 'id' => $id, 'action' => 'validate']);
        $this->assertSame(200, $vCode, 'invoice validate failed: ' . json_encode($vBody));

        return $id;
    }

    // ------------------------------------------------------------- full payment

    public function testFullPaymentMarksInvoicePaid(): void
    {
        $id = $this->createValidatedInvoice();

        list($body, $code) = $this->payments->store([
            'objtype' => 'invoice', 'id' => $id,
            'amount' => 100, 'payment_mode' => $this->paymentModeId, 'ref' => 'CHQ-001',
        ]);
        $this->assertSame(201, $code, 'payment failed: ' . json_encode($body));
        $this->assertGreaterThan(0, (int) $body['payment_id']);
        $this->assertEquals(100, $body['total_paid']);
        $this->assertEquals(0, $body['remain_to_pay']);
        $this->assertSame(1, (int) $body['paye'], 'invoice must be flagged paid');
        $this->track('paiement', (int) $body['payment_id']);
    }

    // ---------------------------------------------------------- partial payment

    public function testPartialPaymentLeavesRemainder(): void
    {
        $id = $this->createValidatedInvoice();

        list($body, $code) = $this->payments->store([
            'objtype' => 'invoice', 'id' => $id,
            'amount' => 40, 'payment_mode' => $this->paymentModeId,
        ]);
        $this->assertSame(201, $code, 'partial payment failed: ' . json_encode($body));
        $this->assertEquals(40, $body['total_paid']);
        $this->assertEquals(60, $body['remain_to_pay']);
        $this->assertSame(0, (int) $body['paye'], 'partially paid invoice must not be flagged paid');
        $this->track('paiement', (int) $body['payment_id']);
    }

    // ------------------------------------------------------------- list payments

    public function testListPaymentsReturnsRecordedPayment(): void
    {
        $id = $this->createValidatedInvoice();
        list($pay, ) = $this->payments->store([
            'objtype' => 'invoice', 'id' => $id,
            'amount' => 100, 'payment_mode' => $this->paymentModeId,
        ]);
        $this->track('paiement', (int) $pay['payment_id']);

        list($body, $code) = $this->payments->index(['objtype' => 'invoice', 'id' => $id]);
        $this->assertSame(200, $code, 'list payments failed: ' . json_encode($body));
        $this->assertEquals(100, $body['total_paid']);
        $this->assertEquals(0, $body['remain_to_pay']);
        $this->assertNotEmpty($body['payments'], 'at least one payment must be listed');
    }

    // ------------------------------------------------------------------ guards

    public function testMissingAmountRejected(): void
    {
        $id = $this->createValidatedInvoice();
        list($body, $code) = $this->payments->store([
            'objtype' => 'invoice', 'id' => $id, 'payment_mode' => $this->paymentModeId,
        ]);
        $this->assertSame(400, $code, 'missing amount must be rejected: ' . json_encode($body));
    }

    public function testMissingPaymentModeRejected(): void
    {
        $id = $this->createValidatedInvoice();
        list($body, $code) = $this->payments->store([
            'objtype' => 'invoice', 'id' => $id, 'amount' => 100,
        ]);
        $this->assertSame(400, $code, 'missing payment_mode must be rejected: ' . json_encode($body));
    }

    public function testPaymentOnTypeWithoutPaymentSupportRejected(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($oBody, $oCode) = $this->objects->create([
            'objtype' => 'order', 'thirdparty' => (int) $soc->id, 'date_order' => dol_now(),
        ]);
        $this->assertSame(201, $oCode);
        $orderId = (int) $oBody->id;
        $this->track('commande', $orderId);

        list($body, $code) = $this->payments->store([
            'objtype' => 'order', 'id' => $orderId, 'amount' => 10, 'payment_mode' => $this->paymentModeId,
        ]);
        $this->assertSame(400, $code, 'order has no payment support: ' . json_encode($body));
    }

    public function testPaymentFailClosedWhenUpdateRightMissing(): void
    {
        global $user;
        $id = $this->createValidatedInvoice();

        $saved = $user->rights->facture->creer;
        $user->rights->facture->creer = 0;
        try {
            list($body, $code) = $this->payments->store([
                'objtype' => 'invoice', 'id' => $id, 'amount' => 100, 'payment_mode' => $this->paymentModeId,
            ]);
            $this->assertSame(403, $code, 'payment must fail closed without creer: ' . json_encode($body));
        } finally {
            $user->rights->facture->creer = $saved;
        }
    }
}
