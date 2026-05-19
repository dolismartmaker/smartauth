<?php

/**
 * Round-trip tests for Lot C mappers (Phase 5 of mappers centralisation).
 *
 * Covers the six mappers added in the current session, all linked to
 * supplier / bank / stock-movement / delivery-note domains:
 *
 *   - dmSupplier             (Fournisseur)
 *   - dmCompanyBankAccount   (CompanyBankAccount)
 *   - dmStockMovement        (MouvementStock, audit-only)
 *   - dmDeliveryNote         (Delivery)
 *   - dmBank                 (AccountLine)
 *   - dmBankAccount          (Account)
 *
 * Same 30-line pattern as MapperRoundTripPilotTest:
 *   1. create a real Dolibarr object via inline fixture
 *   2. instantiate the mapper, call exportMappedData()
 *   3. assert the expected API-side keys are present with sensible values
 *   4. send an unauthorized payload through importMappedData() and
 *      verify the strict rejection (MapperValidationException)
 *
 * dmStockMovement is audit-only (writableFields = []) so its import
 * test asserts that ANY payload is rejected, not a writable subset.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmSupplier;
use SmartAuth\DolibarrMapping\dmCompanyBankAccount;
use SmartAuth\DolibarrMapping\dmStockMovement;
use SmartAuth\DolibarrMapping\dmDeliveryNote;
use SmartAuth\DolibarrMapping\dmBank;
use SmartAuth\DolibarrMapping\dmBankAccount;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/companybankaccount.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/delivery/class/delivery.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

/**
 * @covers \SmartAuth\DolibarrMapping\dmSupplier
 * @covers \SmartAuth\DolibarrMapping\dmCompanyBankAccount
 * @covers \SmartAuth\DolibarrMapping\dmStockMovement
 * @covers \SmartAuth\DolibarrMapping\dmDeliveryNote
 * @covers \SmartAuth\DolibarrMapping\dmBank
 * @covers \SmartAuth\DolibarrMapping\dmBankAccount
 */
class MapperRoundTripLotCTest extends DolibarrRealTestCase
{
    // ----------------------------------------------------------------
    // dmSupplier (Fournisseur)
    // ----------------------------------------------------------------

    public function testDmSupplierRoundTripExport(): void
    {
        // Fournisseur extends Societe: same llx_societe table, same
        // columns. We create through the Fournisseur class to exercise
        // the supplier-specific code path; the mapper inherits all
        // dmThirdparty publishedFields so the API contract is identical.
        $supplier = new \Fournisseur($this->db);
        $supplier->name = 'Round-trip Supplier ' . uniqid();
        $supplier->email = 'supplier+' . uniqid() . '@example.com';
        $supplier->fournisseur = 1;
        $supplier->status = 1;
        $supplier->entity = 1;
        $id = $supplier->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create supplier: ' . $supplier->error);

        $fresh = new \Fournisseur($this->db);
        $fresh->fetch($id);

        $mapper = new dmSupplier();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'name', $supplier->name);
        $this->assertApiKeyEquals($payload, 'email', $supplier->email);
    }

    public function testDmSupplierImportRejectsReadOnlyId(): void
    {
        $mapper = new dmSupplier();

        try {
            // 'id' is exposed (rowid -> id) but not in writableFields
            // (inherited from dmThirdparty). Must be rejected.
            $mapper->importMappedData(['id' => 999]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDmSupplierImportAcceptsWritableField(): void
    {
        $mapper = new dmSupplier();
        $sanitized = $mapper->importMappedData([
            'name'  => 'Updated supplier',
            'email' => 'sup-updated@example.com',
        ]);

        // dmSupplier inherits writableFields from dmThirdparty: 'nom' is
        // the Dolibarr column name behind the 'name' api key.
        $this->assertSame('Updated supplier', $sanitized->nom);
        $this->assertSame('sup-updated@example.com', $sanitized->email);
    }

    // ----------------------------------------------------------------
    // dmCompanyBankAccount (CompanyBankAccount)
    // ----------------------------------------------------------------

    public function testDmCompanyBankAccountRoundTripExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'RIB Holder Inc.']);

        // CompanyBankAccount::create() inserts only fk_soc/type/datec;
        // bank, iban, label etc. are persisted by ::update(). We mirror
        // that workflow to get a fully populated row.
        $rib = new \CompanyBankAccount($this->db);
        $rib->socid = $societe->id;
        $rib->label = 'Main RIB';
        $rib->bank  = 'Test Bank';
        $rib->iban  = 'FR7630006000011234567890189';
        $rib->bic   = 'AGRIFRPPXXX';
        $rib->number = '00012345678';
        $rib->code_banque = '30006';
        $rib->code_guichet = '00001';
        $rib->cle_rib = '89';
        $rib->proprio = 'RIB Holder Inc.';
        $rib->default_rib = 1;
        $id = $rib->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create CompanyBankAccount: ' . $rib->error);

        $upd = $rib->update($this->testUser);
        $this->assertGreaterThan(0, $upd, 'failed to update CompanyBankAccount: ' . $rib->error);

        $fresh = new \CompanyBankAccount($this->db);
        // CompanyBankAccount::fetch requires the rowid via $id or a
        // socid+default combo. We pass the id explicitly.
        $fresh->fetch($id);

        $mapper = new dmCompanyBankAccount();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'thirdparty_id', (int) $societe->id);
        $this->assertApiKeyEquals($payload, 'label', 'Main RIB');
        $this->assertApiKeyEquals($payload, 'bank', 'Test Bank');
        $this->assertApiKeyEquals($payload, 'iban', 'FR7630006000011234567890189');
        $this->assertApiKeyEquals($payload, 'bic', 'AGRIFRPPXXX');
    }

    public function testDmCompanyBankAccountImportRejectsAuditField(): void
    {
        $mapper = new dmCompanyBankAccount();

        try {
            // 'datec' is exposed but not writable: audit-only column.
            $mapper->importMappedData(['datec' => 1700000000]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('datec', $e->getErrors());
        }
    }

    public function testDmCompanyBankAccountImportAcceptsWritableField(): void
    {
        $mapper = new dmCompanyBankAccount();
        $sanitized = $mapper->importMappedData([
            'label' => 'Updated RIB label',
            'iban'  => 'FR7611111000010000000000123',
        ]);

        $this->assertSame('Updated RIB label', $sanitized->label);
        $this->assertSame('FR7611111000010000000000123', $sanitized->iban);
    }

    // ----------------------------------------------------------------
    // dmStockMovement (MouvementStock) -- audit-only, writableFields = []
    // ----------------------------------------------------------------

    public function testDmStockMovementRoundTripExport(): void
    {
        // Need a Product and an Entrepot to materialise a stock movement.
        $product = new \Product($this->db);
        $product->ref = 'PRD-' . uniqid();
        $product->label = 'Test product for stock movement';
        $product->status = 1;
        $product->type = 0; // product, not service
        $product->price = 10;
        $product->price_base_type = 'HT';
        $product->tva_tx = 0;
        $product->status_buy = 1;
        $productId = $product->create($this->testUser);
        $this->assertGreaterThan(0, $productId, 'failed to create product: ' . $product->error);

        $warehouse = new \Entrepot($this->db);
        $warehouse->label = 'WH-stockmove-' . uniqid();
        $warehouse->statut = 1;
        $warehouseId = $warehouse->create($this->testUser);
        $this->assertGreaterThan(0, $warehouseId, 'failed to create warehouse: ' . $warehouse->error);

        // MouvementStock::_create is the underlying primitive used by
        // Product::correct_stock. We call it directly to avoid the extra
        // accounting / sub-product logic of correct_stock, which is not
        // relevant to the mapper test.
        $movement = new \MouvementStock($this->db);
        $movementId = $movement->_create(
            $this->testUser,
            $productId,
            $warehouseId,
            10,           // qty
            0,            // type: 0 = entry / receipt
            5.0,          // price
            'Round-trip stock movement',
            'INV-RT-' . uniqid()
        );
        $this->assertGreaterThan(0, $movementId, 'failed to create stock movement: ' . $movement->error);

        $fresh = new \MouvementStock($this->db);
        $fresh->fetch($movementId);

        $mapper = new dmStockMovement();
        $payload = $mapper->exportMappedData($fresh);

        // MouvementStock::fetch remaps BDD columns onto PHP properties
        // (fk_product -> product_id, fk_entrepot -> warehouse_id,
        // value -> qty, type_mouvement -> type). The mapper uses the
        // PHP property names so the API payload exposes them directly.
        $this->assertApiKeyEquals($payload, 'id', $movementId);
        $this->assertApiKeyEquals($payload, 'product_id', $productId);
        $this->assertApiKeyEquals($payload, 'warehouse_id', $warehouseId);
        $this->assertApiKeyEquals($payload, 'qty', 10);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip stock movement');
    }

    public function testDmStockMovementImportAlwaysRejects(): void
    {
        $mapper = new dmStockMovement();

        // writableFields = [] -> every input field must be rejected, no
        // exception. We send a field that LOOKS legitimate (label is
        // writable on other mappers) to prove the audit-only contract
        // is enforced.
        try {
            $mapper->importMappedData(['label' => 'attempt']);
            $this->fail('Expected MapperValidationException for audit-only mapper');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('label', $errors);
        }
    }

    public function testDmStockMovementImportRejectsEverythingAtOnce(): void
    {
        $mapper = new dmStockMovement();

        // Confirm that multiple fields are reported in a single pass --
        // strict mapping must collect ALL offenders, not bail at the
        // first one.
        try {
            $mapper->importMappedData([
                'qty'   => 99,
                'label' => 'tamper',
                'price' => 1.0,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('qty', $errors);
            $this->assertArrayHasKey('label', $errors);
            $this->assertArrayHasKey('price', $errors);
        }
    }

    // ----------------------------------------------------------------
    // dmDeliveryNote (Delivery)
    // ----------------------------------------------------------------

    public function testDmDeliveryNoteRoundTripExport(): void
    {
        $societe = $this->createTestSociete(['name' => 'Delivery customer']);

        $delivery = new \Delivery($this->db);
        $delivery->socid = $societe->id;
        $delivery->ref_customer = 'CUST-REF-' . uniqid();
        $delivery->date_delivery = dol_now();
        $delivery->model_pdf = 'storm';
        $id = $delivery->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create delivery: ' . $delivery->error);

        $fresh = new \Delivery($this->db);
        $fresh->fetch($id);

        $mapper = new dmDeliveryNote();
        $payload = $mapper->exportMappedData($fresh);

        // Delivery::create() forces ref = '(PROV<id>)' until validation.
        // We assert on the dynamic value rather than a fixed string.
        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', '(PROV' . $id . ')');
        $this->assertApiKeyEquals($payload, 'customer_ref', $delivery->ref_customer);
        $this->assertApiKeyEquals($payload, 'thirdparty', (int) $societe->id);
    }

    public function testDmDeliveryNoteImportRejectsStatusChange(): void
    {
        $mapper = new dmDeliveryNote();

        // 'status' is exposed but excluded from writableFields by design
        // (state machine, goes through Delivery::valid()).
        try {
            $mapper->importMappedData(['status' => 2]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testDmDeliveryNoteImportAcceptsWritableField(): void
    {
        $mapper = new dmDeliveryNote();
        $sanitized = $mapper->importMappedData([
            'customer_ref' => 'NEW-CUSTREF',
            'model_pdf'    => 'typhon',
        ]);

        $this->assertSame('NEW-CUSTREF', $sanitized->ref_customer);
        $this->assertSame('typhon', $sanitized->model_pdf);
    }

    // ----------------------------------------------------------------
    // dmBank (AccountLine)
    // ----------------------------------------------------------------

    public function testDmBankRoundTripExport(): void
    {
        // A bank line is only meaningful with a parent Account, so we
        // create the account first, then use Account::addline() to push
        // a transaction.
        $account = $this->createTestBankAccount('BankLines test');
        $lineId  = $account->addline(
            dol_now(),
            'LIQ',
            'Round-trip bank line',
            42.50,
            'CHK-' . uniqid(),
            0,
            $this->testUser,
            'John Doe',
            'BNP'
        );
        $this->assertGreaterThan(0, $lineId, 'failed to create bank line: ' . $account->error);

        $line = new \AccountLine($this->db);
        $line->fetch($lineId);

        $mapper = new dmBank();
        $payload = $mapper->exportMappedData($line);

        // AccountLine::fetch sets $this->ref = $obj->rowid, so the 'ref'
        // api key holds the rowid value, not a separate ref column.
        // The mapper does NOT expose 'emetteur' nor 'numero_compte': both
        // columns exist in llx_bank but Dolibarr's AccountLine::fetch has
        // a latent bug (emetteur SELECTed but never assigned, numero_compte
        // not even SELECTed). Until that fetch is fixed upstream, the
        // mapper omits them to avoid emitting always-null api keys.
        $this->assertApiKeyEquals($payload, 'id', $lineId);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip bank line');
        $this->assertApiKeyEquals($payload, 'amount', 42.5);
        $this->assertApiKeyEquals($payload, 'fk_account', (int) $account->id);
        $this->assertApiKeyEquals($payload, 'bank_chq', 'BNP');
        $this->assertObjectNotHasProperty('emetteur', $payload);
        $this->assertObjectNotHasProperty('numero_compte', $payload);
    }

    public function testDmBankImportRejectsAmountChange(): void
    {
        $mapper = new dmBank();

        // 'amount' is exposed but NOT writable: a bank line is created
        // by Account::addline() or a payment workflow, never overwritten
        // through the generic mapper path.
        try {
            $mapper->importMappedData(['amount' => 999.99]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('amount', $e->getErrors());
        }
    }

    public function testDmBankImportAcceptsWritableField(): void
    {
        $mapper = new dmBank();
        $sanitized = $mapper->importMappedData([
            'label' => 'Edited label',
            'note'  => 'Some reconciliation note',
        ]);

        $this->assertSame('Edited label', $sanitized->label);
        $this->assertSame('Some reconciliation note', $sanitized->note);
    }

    // ----------------------------------------------------------------
    // dmBankAccount (Account)
    // ----------------------------------------------------------------

    public function testDmBankAccountRoundTripExport(): void
    {
        $account = $this->createTestBankAccount('Round-trip account');

        $fresh = new \Account($this->db);
        $fresh->fetch($account->id);

        $mapper = new dmBankAccount();
        $payload = $mapper->exportMappedData($fresh);

        // Account::fetch sets BOTH $this->courant and $this->type to the
        // same value (courant column). The mapper exposes them as
        // separate api keys, both end up holding 1 (current account).
        $this->assertApiKeyEquals($payload, 'id', (int) $account->id);
        $this->assertApiKeyEquals($payload, 'ref', $account->ref);
        $this->assertApiKeyEquals($payload, 'label', $account->label);
        $this->assertApiKeyEquals($payload, 'bank', 'Test Bank');
        $this->assertApiKeyEquals($payload, 'iban', 'FR7630006000011234567890189');
        $this->assertApiKeyEquals($payload, 'currency_code', 'EUR');
        $this->assertApiKeyEquals($payload, 'courant', 1);
        $this->assertApiKeyEquals($payload, 'type', 1);
    }

    public function testDmBankAccountImportRejectsStatusChange(): void
    {
        $mapper = new dmBankAccount();

        // 'status' (api side) -> 'clos' (doli side). Closing an account
        // is a state machine handled by Account::setStatut(), so the
        // field is exposed read-only.
        try {
            $mapper->importMappedData(['status' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testDmBankAccountImportAcceptsWritableField(): void
    {
        $mapper = new dmBankAccount();
        $sanitized = $mapper->importMappedData([
            'label' => 'Renamed account',
            'iban'  => 'FR7611111000010000000000123',
        ]);

        $this->assertSame('Renamed account', $sanitized->label);
        $this->assertSame('FR7611111000010000000000123', $sanitized->iban);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Create a Dolibarr bank Account ready for testing. Required because
     * Account::create() enforces country_id + date_solde + ref non-empty,
     * so plain Dolibarr fixtures from the base class aren't enough.
     */
    private function createTestBankAccount(string $label): \Account
    {
        $account = new \Account($this->db);
        $account->ref = 'BA-' . uniqid();
        $account->label = $label;
        $account->bank = 'Test Bank';
        $account->iban = 'FR7630006000011234567890189';
        $account->bic = 'AGRIFRPPXXX';
        $account->number = '00012345678';
        $account->code_banque = '30006';
        $account->code_guichet = '00001';
        $account->cle_rib = '89';
        $account->courant = 1;        // 1 = current account, 2 = cash, 0 = savings
        $account->type = 1;
        $account->currency_code = 'EUR';
        $account->country_id = 1;     // required by Account::create
        $account->date_solde = dol_now();
        $account->solde = 0;
        $account->entity = 1;

        $id = $account->create($this->testUser);
        if ($id <= 0) {
            throw new \Exception('Failed to create test bank account: ' . $account->error);
        }
        $account->id = $id;
        return $account;
    }

    /**
     * Helper mirroring MapperRoundTripPilotTest: assert that a key
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
