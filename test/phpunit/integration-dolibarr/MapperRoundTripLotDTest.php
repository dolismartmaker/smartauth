<?php

/**
 * Round-trip tests for Lot D mappers (Phase 5 of mappers centralisation).
 *
 * Covers the six commercial-document mappers, all of which use
 * dmLinesTrait to expose their lines collection :
 *
 *   - dmInvoice          (Facture)
 *   - dmOrder            (Commande)
 *   - dmProposal         (Propal)
 *   - dmSupplierInvoice  (FactureFournisseur)
 *   - dmSupplierOrder    (CommandeFournisseur)
 *   - dmSupplierProposal (SupplierProposal)
 *
 * For each mapper three methods :
 *   - HeaderExport  : create header only, assert top-level api keys
 *   - LinesExport   : create header + one line, fetch_lines(), assert lines
 *   - ImportRejects : send a non-writable field, expect MapperValidationException
 *
 * Quirks captured by the previous rounds and re-checked here :
 *   - dmTrait::exportMappedData filters !empty() : 0 / '' / null are stripped.
 *     We only assert on values > 0 / non-empty strings.
 *   - addline() signatures differ slightly between Facture / Commande / Propal
 *     and the supplier counterparts (txlocaltax positions, qty position).
 *     See the per-mapper comments below.
 *   - fetch_lines() is MANDATORY before exportMappedData() ; without it
 *     $obj->lines is empty and the payload has no 'lines' key.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmInvoice;
use SmartAuth\DolibarrMapping\dmOrder;
use SmartAuth\DolibarrMapping\dmProposal;
use SmartAuth\DolibarrMapping\dmSupplierInvoice;
use SmartAuth\DolibarrMapping\dmSupplierOrder;
use SmartAuth\DolibarrMapping\dmSupplierProposal;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/supplier_proposal/class/supplier_proposal.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';

/**
 * @covers \SmartAuth\DolibarrMapping\dmInvoice
 * @covers \SmartAuth\DolibarrMapping\dmOrder
 * @covers \SmartAuth\DolibarrMapping\dmProposal
 * @covers \SmartAuth\DolibarrMapping\dmSupplierInvoice
 * @covers \SmartAuth\DolibarrMapping\dmSupplierOrder
 * @covers \SmartAuth\DolibarrMapping\dmSupplierProposal
 * @covers \SmartAuth\DolibarrMapping\dmLinesTrait
 */
class MapperRoundTripLotDTest extends DolibarrRealTestCase
{
    // ----------------------------------------------------------------
    // dmInvoice (Facture)
    // ----------------------------------------------------------------

    public function testDmInvoiceRoundTripHeaderExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Invoice customer']);

        $invoice = new \Facture($this->db);
        $invoice->socid = $societe->id;
        $invoice->date = dol_now();
        $invoice->type = \Facture::TYPE_STANDARD;
        $invoice->ref_client = 'CUST-INV-' . uniqid();
        $invoice->note_public = 'Public note for invoice';
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create invoice: ' . $invoice->error);

        $fresh = new \Facture($this->db);
        $fresh->fetch($id);

        $mapper = new dmInvoice();
        $payload = $mapper->exportMappedData($fresh);

        // Facture::create() forces ref = '(PROV<id>)' until validation, so
        // we assert on the dynamic value rather than a fixed string.
        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'customer_ref', $invoice->ref_client);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $societe->id);
        $this->assertApiKeyEquals($payload, 'public_note', 'Public note for invoice');
    }

    public function testDmInvoiceRoundTripLinesExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Invoice with lines']);
        $productId = $this->createTestProduct('INV-PROD-');

        $invoice = new \Facture($this->db);
        $invoice->socid = $societe->id;
        $invoice->date = dol_now();
        $invoice->type = \Facture::TYPE_STANDARD;
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create invoice: ' . $invoice->error);

        // Facture::addline($desc, $pu_ht, $qty, $txtva, ...)
        $lineId = $invoice->addline('Test invoice line', 100.0, 2, 20.0, 0, 0, $productId);
        $this->assertGreaterThan(0, $lineId, 'failed to add invoice line: ' . $invoice->error);

        $fresh = new \Facture($this->db);
        $fresh->fetch($id);
        $fresh->fetch_lines();

        $mapper = new dmInvoice();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload, 'invoice payload missing lines key');
        $this->assertIsArray($payload->lines, 'lines must be array');
        $this->assertNotEmpty($payload->lines, 'invoice has at least one line');

        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertObjectHasProperty('vat_rate', $line);
        $this->assertEquals('Test invoice line', $line->description);
        $this->assertEquals(2, $line->quantity);
        $this->assertEquals(100.0, $line->unit_price_excl_tax);
        $this->assertEquals(20.0, $line->vat_rate);
    }

    public function testDmInvoiceImportRejectsStatusField(): void
    {
        $mapper = new dmInvoice();

        // 'status' (api side) is exposed read-only ; statut is a state
        // machine driven by Facture::validate(), not the generic mapper.
        try {
            $mapper->importMappedData(['status' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // dmOrder (Commande)
    // ----------------------------------------------------------------

    public function testDmOrderRoundTripHeaderExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Order customer']);

        $order = new \Commande($this->db);
        $order->socid = $societe->id;
        $order->date = dol_now();
        $order->ref_client = 'CUST-ORD-' . uniqid();
        $order->note_private = 'Order private note';
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create order: ' . $order->error);

        $fresh = new \Commande($this->db);
        $fresh->fetch($id);

        $mapper = new dmOrder();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'customer_ref', $order->ref_client);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $societe->id);
        $this->assertApiKeyEquals($payload, 'private_note', 'Order private note');
    }

    public function testDmOrderRoundTripLinesExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Order with lines']);
        $productId = $this->createTestProduct('ORD-PROD-');

        $order = new \Commande($this->db);
        $order->socid = $societe->id;
        $order->date = dol_now();
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create order: ' . $order->error);

        // Commande::addline($desc, $pu_ht, $qty, $txtva, ...)
        $lineId = $order->addline('Test order line', 50.0, 3, 20.0, 0, 0, $productId);
        $this->assertGreaterThan(0, $lineId, 'failed to add order line: ' . $order->error);

        $fresh = new \Commande($this->db);
        $fresh->fetch($id);
        $fresh->fetch_lines();

        $mapper = new dmOrder();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload);
        $this->assertIsArray($payload->lines);
        $this->assertNotEmpty($payload->lines);

        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertEquals('Test order line', $line->description);
        $this->assertEquals(3, $line->quantity);
        $this->assertEquals(50.0, $line->unit_price_excl_tax);
    }

    public function testDmOrderImportRejectsStatusField(): void
    {
        $mapper = new dmOrder();

        try {
            $mapper->importMappedData(['status' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // dmProposal (Propal)
    // ----------------------------------------------------------------

    public function testDmProposalRoundTripHeaderExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Proposal customer']);

        $proposal = new \Propal($this->db);
        $proposal->socid = $societe->id;
        $proposal->date = dol_now();
        $proposal->ref_client = 'CUST-PROP-' . uniqid();
        $proposal->note_public = 'Proposal note';
        $id = $proposal->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create proposal: ' . $proposal->error);

        $fresh = new \Propal($this->db);
        $fresh->fetch($id);

        $mapper = new dmProposal();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        // Propal::create() also generates a (PROV<id>) ref before validation.
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'customer_ref', $proposal->ref_client);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $societe->id);
        $this->assertApiKeyEquals($payload, 'public_note', 'Proposal note');
    }

    public function testDmProposalRoundTripLinesExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Proposal with lines']);
        $productId = $this->createTestProduct('PROP-PROD-');

        $proposal = new \Propal($this->db);
        $proposal->socid = $societe->id;
        $proposal->date = dol_now();
        $id = $proposal->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create proposal: ' . $proposal->error);

        // Propal::addline($desc, $pu_ht, $qty, $txtva, ...)
        $lineId = $proposal->addline('Test proposal line', 75.0, 4, 20.0, 0.0, 0.0, $productId);
        $this->assertGreaterThan(0, $lineId, 'failed to add proposal line: ' . $proposal->error);

        $fresh = new \Propal($this->db);
        $fresh->fetch($id);
        $fresh->fetch_lines();

        $mapper = new dmProposal();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload);
        $this->assertIsArray($payload->lines);
        $this->assertNotEmpty($payload->lines);

        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertEquals('Test proposal line', $line->description);
        $this->assertEquals(4, $line->quantity);
        $this->assertEquals(75.0, $line->unit_price_excl_tax);
    }

    public function testDmProposalImportRejectsStatusField(): void
    {
        $mapper = new dmProposal();

        try {
            $mapper->importMappedData(['status' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // dmSupplierInvoice (FactureFournisseur)
    // ----------------------------------------------------------------

    public function testDmSupplierInvoiceRoundTripHeaderExport(): void
    {
        $supplier = $this->createTestSupplier('SI-Supplier-');

        $invoice = new \FactureFournisseur($this->db);
        $invoice->socid = $supplier->id;
        $invoice->date = dol_now();
        $invoice->ref_supplier = 'SUP-INV-' . uniqid();
        $invoice->note_public = 'Supplier invoice note';
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier invoice: ' . $invoice->error);

        $fresh = new \FactureFournisseur($this->db);
        $fresh->fetch($id);

        $mapper = new dmSupplierInvoice();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        // FactureFournisseur::create() also produces (PROV<id>) for ref.
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'supplier_ref', $invoice->ref_supplier);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $supplier->id);
        $this->assertApiKeyEquals($payload, 'public_note', 'Supplier invoice note');
    }

    public function testDmSupplierInvoiceRoundTripLinesExport(): void
    {
        $supplier = $this->createTestSupplier('SI-LineSupplier-');
        $productId = $this->createTestProduct('SI-PROD-');

        $invoice = new \FactureFournisseur($this->db);
        $invoice->socid = $supplier->id;
        $invoice->date = dol_now();
        $invoice->ref_supplier = 'SUP-INV-LN-' . uniqid();
        // FactureFournisseur::addline() reads $this->special_code as a
        // fallback (line 2228 in vendor). The property is never initialised
        // by create() so PHP 8.2 throws "Undefined property" warning that
        // PHPUnit promotes to an error. We zero it explicitly.
        $invoice->special_code = 0;
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier invoice: ' . $invoice->error);

        // FactureFournisseur::addline($desc, $pu, $txtva, $localtax1, $localtax2, $qty, ...)
        // Note the qty position : 6th argument (not 3rd like the customer side).
        // We also pass special_code explicitly (22nd arg) to avoid the
        // $this->special_code fallback path entirely.
        $lineId = $invoice->addline(
            'Test supplier invoice line',
            120.0,   // pu
            20.0,    // txtva
            0,       // localtax1
            0,       // localtax2
            5,       // qty
            $productId,
            0,       // remise_percent
            '',      // date_start
            '',      // date_end
            0,       // ventil
            '',      // info_bits
            'HT',    // price_base_type
            0,       // type
            -1,      // rang
            false,   // notrigger
            0,       // array_options
            null,    // fk_unit
            0,       // origin_id
            0,       // pu_devise
            '',      // ref_supplier
            '0'      // special_code (string per signature)
        );
        $this->assertGreaterThan(0, $lineId, 'failed to add supplier invoice line: ' . $invoice->error);

        $fresh = new \FactureFournisseur($this->db);
        $fresh->fetch($id);
        $fresh->fetch_lines();

        $mapper = new dmSupplierInvoice();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload);
        $this->assertIsArray($payload->lines);
        $this->assertNotEmpty($payload->lines);

        // SupplierInvoiceLine::fetch populates $line->description (not
        // $line->desc). The mapper now uses 'description' on the doli
        // side so the api 'description' key round-trips correctly.
        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertEquals('Test supplier invoice line', $line->description);
        $this->assertEquals(5, $line->quantity);
        $this->assertEquals(120.0, $line->unit_price_excl_tax);
    }

    public function testDmSupplierInvoiceImportRejectsPaidField(): void
    {
        $mapper = new dmSupplierInvoice();

        // 'paid' (api side, doli 'paye') is exposed read-only ; paid is a
        // state machine driven by FactureFournisseur::setPaid(), never
        // through the generic mapper path.
        try {
            $mapper->importMappedData(['paid' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('paid', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // dmSupplierOrder (CommandeFournisseur)
    // ----------------------------------------------------------------

    public function testDmSupplierOrderRoundTripHeaderExport(): void
    {
        $supplier = $this->createTestSupplier('SO-Supplier-');

        $order = new \CommandeFournisseur($this->db);
        $order->socid = $supplier->id;
        $order->date = dol_now();
        $order->ref_supplier = 'SUP-ORD-' . uniqid();
        $order->note_public = 'Supplier order note';
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier order: ' . $order->error);

        $fresh = new \CommandeFournisseur($this->db);
        $fresh->fetch($id);

        $mapper = new dmSupplierOrder();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'supplier_ref', $order->ref_supplier);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $supplier->id);
        $this->assertApiKeyEquals($payload, 'public_note', 'Supplier order note');
    }

    public function testDmSupplierOrderRoundTripLinesExport(): void
    {
        $supplier = $this->createTestSupplier('SO-LineSupplier-');
        $productId = $this->createTestProduct('SO-PROD-');

        $order = new \CommandeFournisseur($this->db);
        $order->socid = $supplier->id;
        $order->date = dol_now();
        $order->ref_supplier = 'SUP-ORD-LN-' . uniqid();
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier order: ' . $order->error);

        // CommandeFournisseur::addline($desc, $pu_ht, $qty, $txtva, ...).
        // Same shape as Commande on the customer side.
        $lineId = $order->addline(
            'Test supplier order line',
            80.0,    // pu_ht
            6,       // qty
            20.0,    // txtva
            0.0,     // txlocaltax1
            0.0,     // txlocaltax2
            $productId
        );
        $this->assertGreaterThan(0, $lineId, 'failed to add supplier order line: ' . $order->error);

        $fresh = new \CommandeFournisseur($this->db);
        $fresh->fetch($id);
        $fresh->fetch_lines();

        $mapper = new dmSupplierOrder();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload);
        $this->assertIsArray($payload->lines);
        $this->assertNotEmpty($payload->lines);

        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertEquals('Test supplier order line', $line->description);
        $this->assertEquals(6, $line->quantity);
        $this->assertEquals(80.0, $line->unit_price_excl_tax);
    }

    public function testDmSupplierOrderImportRejectsBilledField(): void
    {
        $mapper = new dmSupplierOrder();

        // 'billed' (api side) is exposed read-only ; it flips through the
        // supplier order workflow, not through importMappedData().
        try {
            $mapper->importMappedData(['billed' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('billed', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // dmSupplierProposal (SupplierProposal)
    // ----------------------------------------------------------------

    public function testDmSupplierProposalRoundTripHeaderExport(): void
    {
        $supplier = $this->createTestSupplier('SP-Supplier-');

        $proposal = new \SupplierProposal($this->db);
        $proposal->socid = $supplier->id;
        $proposal->date = dol_now();
        $proposal->note_private = 'Supplier proposal private note';
        $id = $proposal->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier proposal: ' . $proposal->error);

        $fresh = new \SupplierProposal($this->db);
        $fresh->fetch($id);

        $mapper = new dmSupplierProposal();
        $payload = $mapper->exportMappedData($fresh);

        // dmSupplierProposal does NOT expose a supplier_ref key on the
        // header: the underlying llx_supplier_proposal table has no
        // ref_supplier column (the concept lives on the line rows via
        // ref_fourn). The mapper was cleaned to drop this dead mapping.
        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $supplier->id);
        $this->assertApiKeyEquals($payload, 'private_note', 'Supplier proposal private note');
        $this->assertObjectNotHasProperty('supplier_ref', $payload);
    }

    public function testDmSupplierProposalRoundTripLinesExport(): void
    {
        $supplier = $this->createTestSupplier('SP-LineSupplier-');
        $productId = $this->createTestProduct('SP-PROD-');

        $proposal = new \SupplierProposal($this->db);
        $proposal->socid = $supplier->id;
        $proposal->date = dol_now();
        $id = $proposal->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier proposal: ' . $proposal->error);

        // SupplierProposal::addline($desc, $pu_ht, $qty, $txtva, ...).
        $lineId = $proposal->addline(
            'Test supplier proposal line',
            60.0,    // pu_ht
            7,       // qty
            20.0,    // txtva
            0,       // txlocaltax1
            0,       // txlocaltax2
            $productId
        );
        $this->assertGreaterThan(0, $lineId, 'failed to add supplier proposal line: ' . $proposal->error);

        // Quirk : SupplierProposal has NO fetch_lines() method. Its
        // fetch() loads $this->lines inline (cf. supplier_proposal.class.php
        // line 1304 and the explicit comment around getLinesArray()).
        // We just fetch() and let it populate everything.
        $fresh = new \SupplierProposal($this->db);
        $fresh->fetch($id);

        $mapper = new dmSupplierProposal();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertObjectHasProperty('lines', $payload);
        $this->assertIsArray($payload->lines);
        $this->assertNotEmpty($payload->lines);

        $line = $payload->lines[0];
        $this->assertObjectHasProperty('description', $line);
        $this->assertObjectHasProperty('quantity', $line);
        $this->assertObjectHasProperty('unit_price_excl_tax', $line);
        $this->assertEquals('Test supplier proposal line', $line->description);
        $this->assertEquals(7, $line->quantity);
        $this->assertEquals(60.0, $line->unit_price_excl_tax);
    }

    public function testDmSupplierProposalImportRejectsStatusField(): void
    {
        $mapper = new dmSupplierProposal();

        try {
            $mapper->importMappedData(['status' => 2]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Create a generic product to be used as line target. Lines do not
     * actually require a product (fk_product can be 0) but having one
     * exercises the product FK code path on the lines payload.
     */
    private function createTestProduct(string $prefix): int
    {
        $product = new \Product($this->db);
        $product->ref = $prefix . uniqid();
        $product->label = 'Lot D product ' . $product->ref;
        $product->status = 1;
        $product->status_buy = 1;
        $product->type = 0;            // 0 = product, 1 = service
        $product->price = 100.0;
        $product->price_base_type = 'HT';
        $product->tva_tx = 20.0;
        $id = $product->create($this->testUser);
        if ($id <= 0) {
            throw new \Exception('Failed to create test product: ' . $product->error);
        }
        return (int) $id;
    }

    /**
     * Create a supplier (Fournisseur extends Societe, fournisseur=1). The
     * default createTestSociete() fixture builds a customer-only third
     * party, which would be rejected by CommandeFournisseur::create().
     */
    private function createTestSupplier(string $prefix): \Fournisseur
    {
        $supplier = new \Fournisseur($this->db);
        $supplier->name = $prefix . uniqid();
        $supplier->email = 'sup_' . uniqid() . '@example.com';
        $supplier->fournisseur = 1;
        $supplier->client = 0;
        $supplier->status = 1;
        $supplier->entity = 1;
        $id = $supplier->create($this->testUser);
        if ($id <= 0) {
            throw new \Exception('Failed to create test supplier: ' . $supplier->error);
        }
        $supplier->id = $id;
        return $supplier;
    }

    /**
     * Helper mirroring MapperRoundTripPilotTest : assert that a key
     * exists on the export payload and equals the expected value.
     * exportMappedData returns a stdClass so we normalize to property
     * access.
     */
    private function assertApiKeyEquals(\stdClass $payload, string $apiKey, $expected): void
    {
        $this->assertObjectHasProperty(
            $apiKey,
            $payload,
            "Export payload missing api key '$apiKey'. Got keys: "
                . implode(',', array_keys((array) $payload))
        );

        if ($expected !== null) {
            $this->assertEquals($expected, $payload->{$apiKey});
        }
    }
}
