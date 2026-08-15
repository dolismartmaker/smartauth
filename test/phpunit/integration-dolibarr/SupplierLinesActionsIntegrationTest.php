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
 * Integration tests for the supplier-document lines, workflow actions and
 * supplier payment (PaiementFourn) facades: supplier_order, supplier_invoice,
 * supplier_proposal.
 *
 * @covers \SmartAuth\Api\ObjectLineController
 * @covers \SmartAuth\Api\ObjectActionController
 * @covers \SmartAuth\Api\ObjectPaymentController
 * @covers \SmartAuth\Api\DocumentLineInvoker
 * @covers \SmartAuth\Api\DocumentActionInvoker
 */
class SupplierLinesActionsIntegrationTest extends DolibarrRealTestCase
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
        // Skip cheques: they additionally require an issuer for the bank line.
        $res = $this->db->query("SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 AND code <> 'CHQ' ORDER BY id LIMIT 1");
        $row = $res ? $this->db->fetch_object($res) : null;
        $this->paymentModeId = $row ? (int) $row->id : 6;
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

    private function createSupplier(): int
    {
        $soc = $this->createTestSociete();
        $id = (int) $soc->id;
        $this->track('societe', $id);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET fournisseur = 1 WHERE rowid = " . $id);
        return $id;
    }

    private function addLine(string $type, int $id, array $line)
    {
        $payload = array_merge(['objtype' => $type, 'id' => $id], $line);
        list($body, $code) = $this->lines->store($payload);
        $this->assertSame(201, $code, "addLine($type): " . json_encode($body));
        return $body;
    }

    private function act(string $type, int $id, string $action, array $extra = [])
    {
        return $this->actions->invoke(array_merge(['objtype' => $type, 'id' => $id, 'action' => $action], $extra));
    }

    // ------------------------------------------------------- supplier_order

    public function testSupplierOrderLinesAndWorkflow(): void
    {
        $socid = $this->createSupplier();
        list($body, ) = $this->objects->create(['objtype' => 'supplier_order', 'thirdparty' => $socid]);
        $id = (int) $body->id;
        $this->track('commande_fournisseur', $id);

        $lineBody = $this->addLine('supplier_order', $id, [
            'description' => 'Raw material', 'quantity' => 4, 'unit_price_excl_tax' => 25, 'vat_rate' => 0,
        ]);
        $this->assertCount(1, $lineBody->lines);
        $this->assertEquals(4, $lineBody->lines[0]->quantity);
        $this->assertEquals(100, $lineBody->total_excl_tax);
        $lineId = (int) $lineBody->lines[0]->id;

        list($upd, $uc) = $this->lines->update([
            'objtype' => 'supplier_order', 'id' => $id, 'lineid' => $lineId, 'quantity' => 6,
        ]);
        $this->assertSame(200, $uc, 'supplier_order line update: ' . json_encode($upd));
        $this->assertEquals(150, $upd->total_excl_tax);

        list($v, $vc) = $this->act('supplier_order', $id, 'validate');
        $this->assertSame(200, $vc, 'supplier_order validate: ' . json_encode($v));
        $this->assertGreaterThanOrEqual(1, (int) $v->status);

        list($a, $ac) = $this->act('supplier_order', $id, 'approve');
        $this->assertSame(200, $ac, 'supplier_order approve: ' . json_encode($a));
    }

    // ----------------------------------------------------- supplier_invoice

    public function testSupplierInvoiceLinesValidatePay(): void
    {
        $socid = $this->createSupplier();
        list($body, ) = $this->objects->create([
            'objtype' => 'supplier_invoice', 'thirdparty' => $socid,
            'supplier_ref' => 'SI-' . uniqid(), 'date_invoice' => dol_now(),
        ]);
        $id = (int) $body->id;
        $this->track('facture_fourn', $id);

        $lineBody = $this->addLine('supplier_invoice', $id, [
            'description' => 'Service', 'quantity' => 1, 'unit_price_excl_tax' => 200, 'vat_rate' => 0,
        ]);
        $this->assertCount(1, $lineBody->lines);
        $this->assertEquals(200, $lineBody->total_excl_tax);

        list($v, $vc) = $this->act('supplier_invoice', $id, 'validate');
        $this->assertSame(200, $vc, 'supplier_invoice validate: ' . json_encode($v));

        $account = $this->createTestBankAccount(['label' => 'Supplier payments account']);
        $this->track('bank_account', (int) $account->id);

        list($p, $pc) = $this->payments->store([
            'objtype' => 'supplier_invoice', 'id' => $id,
            'amount' => 200, 'payment_mode' => $this->paymentModeId,
            'fk_account' => (int) $account->id,
        ]);
        $this->assertSame(201, $pc, 'supplier payment: ' . json_encode($p));
        $this->assertEquals(200, $p['total_paid']);
        $this->assertEquals(0, $p['remain_to_pay']);
        $this->assertSame(1, (int) $p['paye']);
        $this->track('paiementfourn', (int) $p['payment_id']);

        // The SIGN is the whole point of the 'payment_supplier' bank mode: money
        // LEAVES the account, so the ledger line is negative. Getting this
        // backwards would silently inflate every tenant's balance.
        $bankLineId = (int) $p['bank_line_id'];
        $this->assertGreaterThan(0, $bankLineId, 'a supplier payment must post a bank line');
        $this->track('bank', $bankLineId);

        $line = new \AccountLine($this->db);
        $this->assertSame(1, $line->fetch($bankLineId));
        $this->assertEquals(-200, (float) $line->amount, 'a supplier payment must debit the account');
        $this->assertSame((int) $account->id, (int) $line->fk_account);
    }

    // ---------------------------------------------------- supplier_proposal

    public function testSupplierProposalLinesAndClose(): void
    {
        $socid = $this->createSupplier();
        list($body, ) = $this->objects->create(['objtype' => 'supplier_proposal', 'thirdparty' => $socid]);
        $id = (int) $body->id;
        $this->track('supplier_proposal', $id);

        $lineBody = $this->addLine('supplier_proposal', $id, [
            'description' => 'Quote item', 'quantity' => 2, 'unit_price_excl_tax' => 75, 'vat_rate' => 0,
        ]);
        $this->assertCount(1, $lineBody->lines);
        $this->assertEquals(150, $lineBody->total_excl_tax);

        list($v, $vc) = $this->act('supplier_proposal', $id, 'validate');
        $this->assertSame(200, $vc, 'supplier_proposal validate: ' . json_encode($v));

        list($s, $sc) = $this->act('supplier_proposal', $id, 'closesign', ['note' => 'ok']);
        $this->assertSame(200, $sc, 'supplier_proposal closesign: ' . json_encode($s));
        $this->assertEquals(2, (int) $s->status, 'signed supplier proposal must be status 2');
    }
}
