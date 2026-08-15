<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';

use SmartAuth\Api\ObjectController;

/**
 * Integration tests for the Vague 3 supplier documents of the objects/{type}
 * facade: supplier_order, supplier_invoice, supplier_proposal (CRUD). Lines,
 * workflow actions and supplier payments are a later lot.
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class SupplierDocObjectControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    /** @var array<int,array{0:string,1:int}> */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
        $this->created = [];
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

    /** A thirdparty flagged as a supplier (fournisseur = 1). */
    private function createSupplier(): int
    {
        $soc = $this->createTestSociete();
        $id = (int) $soc->id;
        $this->track('societe', $id);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET fournisseur = 1 WHERE rowid = " . $id);
        return $id;
    }

    public function testColumnsForSupplierDocs(): void
    {
        foreach (['supplier_order', 'supplier_invoice', 'supplier_proposal'] as $type) {
            list($body, $code) = $this->controller->columns(['objtype' => $type]);
            $this->assertSame(200, $code, "columns($type): " . json_encode($body));
            $this->assertNotEmpty($body);
        }
    }

    public function testSupplierOrderCrud(): void
    {
        $socid = $this->createSupplier();

        list($body, $code) = $this->controller->create([
            'objtype' => 'supplier_order', 'thirdparty' => $socid,
        ]);
        $this->assertSame(201, $code, 'supplier_order create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('commande_fournisseur', $id);
        $this->assertSame($socid, (int) $body->thirdparty);

        list($u, $uc) = $this->controller->update(['objtype' => 'supplier_order', 'id' => $id, 'public_note' => 'po note']);
        $this->assertSame(200, $uc, 'supplier_order update: ' . json_encode($u));
        $this->assertSame('po note', $u->public_note);

        list(, $dc) = $this->controller->destroy(['objtype' => 'supplier_order', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('commande_fournisseur', ['rowid' => $id]);
    }

    public function testSupplierInvoiceCrud(): void
    {
        $socid = $this->createSupplier();

        list($body, $code) = $this->controller->create([
            'objtype' => 'supplier_invoice', 'thirdparty' => $socid,
            'supplier_ref' => 'SUPINV-' . uniqid(), 'date_invoice' => dol_now(),
        ]);
        $this->assertSame(201, $code, 'supplier_invoice create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('facture_fourn', $id);
        $this->assertSame($socid, (int) $body->thirdparty);

        list($u, $uc) = $this->controller->update(['objtype' => 'supplier_invoice', 'id' => $id, 'public_note' => 'inv note']);
        $this->assertSame(200, $uc, 'supplier_invoice update: ' . json_encode($u));
        $this->assertSame('inv note', $u->public_note);

        list(, $dc) = $this->controller->destroy(['objtype' => 'supplier_invoice', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('facture_fourn', ['rowid' => $id]);
    }

    public function testSupplierProposalCrud(): void
    {
        $socid = $this->createSupplier();

        list($body, $code) = $this->controller->create([
            'objtype' => 'supplier_proposal', 'thirdparty' => $socid,
        ]);
        $this->assertSame(201, $code, 'supplier_proposal create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('supplier_proposal', $id);
        $this->assertSame($socid, (int) $body->thirdparty);

        // SupplierProposal exposes no generic update(); the facade now persists
        // the header through the mapper's setter fallback (dmSupplierProposal::
        // updateViaSetters -> update_note/setPaymentTerms/... like card.php).
        list($u, $uc) = $this->controller->update(['objtype' => 'supplier_proposal', 'id' => $id, 'public_note' => 'sp note']);
        $this->assertSame(200, $uc, 'supplier_proposal update should persist via setters: ' . json_encode($u));
        $this->assertSame('sp note', $u->public_note, 'public_note must persist through the setter fallback');

        list(, $dc) = $this->controller->destroy(['objtype' => 'supplier_proposal', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('supplier_proposal', ['rowid' => $id]);
    }
}
