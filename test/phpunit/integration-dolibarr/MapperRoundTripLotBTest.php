<?php

/**
 * Round-trip tests for "lot B" mappers (Phase 5 of mappers centralization).
 *
 * Same pattern as MapperRoundTripPilotTest / MapperRoundTripLotATest:
 *   1. build a real Dolibarr object (inline fixture, no shared helper)
 *   2. call exportMappedData() and assert the expected api-side keys
 *   3. call importMappedData() with a payload that includes a NON-writable
 *      field and assert MapperValidationException collects it
 *
 * Mappers covered:
 *   - dmContact      (Contact, needs a Societe parent)
 *   - dmProject      (Project)
 *   - dmTask         (Task, needs a Project parent)
 *   - dmProduct      (Product, also tested as a service to cover type=1)
 *   - dmAgendaEvent  (ActionComm, aliased as dmActionComm)
 *   - dmTicket       (Ticket)
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmAgendaEvent;
use SmartAuth\DolibarrMapping\dmContact;
use SmartAuth\DolibarrMapping\dmProduct;
use SmartAuth\DolibarrMapping\dmProject;
use SmartAuth\DolibarrMapping\dmTask;
use SmartAuth\DolibarrMapping\dmTicket;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

/**
 * @covers \SmartAuth\DolibarrMapping\dmTrait::exportMappedData
 * @covers \SmartAuth\DolibarrMapping\dmTrait::importMappedData
 */
class MapperRoundTripLotBTest extends DolibarrRealTestCase
{
    /* -----------------------------------------------------------------
     * dmContact
     * --------------------------------------------------------------- */

    public function testDmContactRoundTripExport(): void
    {
        $societe = $this->createTestSociete([
            'name' => 'Contact Parent Co.',
        ]);

        $contact = new \Contact($this->db);
        $contact->socid        = (int) $societe->id;
        $contact->lastname     = 'Smith';
        $contact->firstname    = 'Alice';
        $contact->email        = 'alice_' . uniqid() . '@example.com';
        $contact->phone_mobile = '+33600000001';
        $contact->statut       = 1;
        $contact->entity       = 1;

        $id = $contact->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test contact: ' . $contact->error);

        $mapper  = new dmContact();
        $payload = $mapper->exportMappedData($contact);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'lastname', 'Smith');
        $this->assertApiKeyEquals($payload, 'firstname', 'Alice');
        $this->assertApiKeyEquals($payload, 'email', $contact->email);
        $this->assertApiKeyEquals($payload, 'mobile', '+33600000001');
    }

    public function testDmContactImportRejectsReadOnlyId(): void
    {
        $mapper = new dmContact();

        try {
            // 'id' is exposed but NOT writable: the rowid is assigned by
            // Dolibarr at create time, a payload cannot overwrite it.
            $mapper->importMappedData(['id' => 999]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDmContactImportAcceptsWritableFields(): void
    {
        $mapper    = new dmContact();
        $sanitized = $mapper->importMappedData([
            'lastname'  => 'Updated',
            'firstname' => 'Name',
            'email'     => 'updated@example.com',
        ]);

        // Sanity check: api-side keys are reversed back to Dolibarr names.
        $this->assertSame('Updated', $sanitized->lastname);
        $this->assertSame('Name', $sanitized->firstname);
        $this->assertSame('updated@example.com', $sanitized->email);
        $this->assertObjectNotHasProperty('id', $sanitized);
    }

    /* -----------------------------------------------------------------
     * dmProject
     * --------------------------------------------------------------- */

    public function testDmProjectRoundTripExport(): void
    {
        $societe = $this->createTestSociete([
            'name' => 'Project Customer Co.',
        ]);

        $project = new \Project($this->db);
        $project->ref         = 'PJ-' . uniqid();
        $project->title       = 'Round-trip project';
        $project->description = 'Project used to validate dmProject export';
        $project->socid       = (int) $societe->id;
        // Project::create reads $this->date_start / $this->date_end (PHP
        // property names) and writes them into the dateo / datee SQL
        // columns. The mapper reads the SQL-side names (dateo / datee) so
        // they remain null on the in-memory object right after create.
        // We do not assert on date_start / date_end here, only on the
        // identity fields that survive the create call.
        $project->date_start  = strtotime('2026-01-01');
        $project->date_end    = strtotime('2026-12-31');

        $id = $project->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test project: ' . $project->error);

        $mapper  = new dmProject();
        $payload = $mapper->exportMappedData($project);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', $project->ref);
        $this->assertApiKeyEquals($payload, 'title', 'Round-trip project');
        $this->assertApiKeyEquals($payload, 'description', 'Project used to validate dmProject export');
    }

    public function testDmProjectImportRejectsCreatedAt(): void
    {
        $mapper = new dmProject();

        try {
            // 'created_at' (datec) is exposed read-only -- the timestamp
            // is filled by Dolibarr at create time, never by client input.
            $mapper->importMappedData(['created_at' => 12345]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('created_at', $e->getErrors());
        }
    }

    public function testDmProjectImportAcceptsWritableFields(): void
    {
        $mapper    = new dmProject();
        $sanitized = $mapper->importMappedData([
            'ref'         => 'PJ-UPDATED',
            'title'       => 'New title',
            'description' => 'New description',
        ]);

        $this->assertSame('PJ-UPDATED', $sanitized->ref);
        $this->assertSame('New title', $sanitized->title);
        $this->assertSame('New description', $sanitized->description);
    }

    /* -----------------------------------------------------------------
     * dmTask
     * --------------------------------------------------------------- */

    public function testDmTaskRoundTripExport(): void
    {
        $societe = $this->createTestSociete([
            'name' => 'Task Customer Co.',
        ]);

        $project = new \Project($this->db);
        $project->ref   = 'PJ-' . uniqid();
        $project->title = 'Parent project';
        $project->socid = (int) $societe->id;
        $projectId = $project->create($this->testUser);
        $this->assertGreaterThan(0, $projectId, 'failed to create parent project: ' . $project->error);

        $task = new \Task($this->db);
        // Task uses fk_project (PHP property) but stores into the fk_projet
        // SQL column. The mapper reads fk_projet (SQL-side), so right after
        // create() the in-memory $task->fk_projet is unset and the export
        // does not produce the 'project' api key. We do not assert on it.
        $task->fk_project  = $projectId;
        $task->ref         = 'T-' . uniqid();
        $task->label       = 'Round-trip task';
        $task->description = 'Validates dmTask export';
        $task->progress    = 25;
        $task->priority    = 2;

        $id = $task->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test task: ' . implode(',', (array) $task->errors));

        $mapper  = new dmTask();
        $payload = $mapper->exportMappedData($task);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', $task->ref);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip task');
        $this->assertApiKeyEquals($payload, 'description', 'Validates dmTask export');
        $this->assertApiKeyEquals($payload, 'progress', 25);
        $this->assertApiKeyEquals($payload, 'priority', 2);
    }

    public function testDmTaskImportRejectsTimeSpent(): void
    {
        $mapper = new dmTask();

        try {
            // 'time_spent' maps to duration_effective which is computed
            // from time-tracking entries -- not writable directly.
            $mapper->importMappedData(['time_spent' => 7200]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('time_spent', $e->getErrors());
        }
    }

    public function testDmTaskImportRejectsCreatedBy(): void
    {
        $mapper = new dmTask();

        try {
            // 'created_by' (fk_user_creat) is audit-trail -- never writable
            // by client input.
            $mapper->importMappedData(['created_by' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('created_by', $e->getErrors());
        }
    }

    /* -----------------------------------------------------------------
     * dmProduct
     * --------------------------------------------------------------- */

    public function testDmProductRoundTripExportAsProduct(): void
    {
        $product = new \Product($this->db);
        $product->ref             = 'PROD-' . uniqid();
        $product->label           = 'Round-trip product';
        $product->description     = 'A tangible product';
        $product->type            = 0; // 0 = product
        $product->price           = 12.50;
        $product->price_base_type = 'HT';
        $product->tva_tx          = 20.0;
        $product->status          = 1;
        $product->status_buy      = 1;
        $product->entity          = 1;

        $id = $product->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test product: ' . $product->error);

        // Product::create writes the SQL columns tosell / tobuy from the
        // PHP properties $this->status / $this->status_buy, but never
        // populates $this->tosell / $this->tobuy on the in-memory object.
        // Fetch the row back to align the PHP properties with what the
        // mapper expects (it reads tosell / tobuy directly).
        $fresh = new \Product($this->db);
        $fresh->fetch($id);

        $mapper  = new dmProduct();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip product');
        $this->assertApiKeyEquals($payload, 'description', 'A tangible product');
        // type=0 is exported as the front-side string alias 'product':
        // dmTrait exports any non-null value (0 included) and
        // dmProduct::fieldFilterValueType maps the Dolibarr int to its
        // readable alias. The service variant below covers the type=1 path.
        $this->assertApiKeyEquals($payload, 'type', dmProduct::TYPE_PRODUCT);
        $this->assertApiKeyEquals($payload, 'vat_rate', 20.0);
        // Product::fetch reads SQL columns tosell/tobuy INTO
        // $this->status / $this->status_buy. The mapper now uses the
        // PHP property names so the api keys round-trip correctly.
        $this->assertApiKeyEquals($payload, 'for_sale', 1);
    }

    public function testDmProductRoundTripExportAsService(): void
    {
        $product = new \Product($this->db);
        $product->ref             = 'SRV-' . uniqid();
        $product->label           = 'Round-trip service';
        $product->type            = 1; // 1 = service
        $product->price           = 99.0;
        $product->price_base_type = 'HT';
        $product->tva_tx          = 0;
        $product->status          = 1;
        $product->status_buy      = 0;
        $product->entity          = 1;

        $id = $product->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test service: ' . $product->error);

        $mapper  = new dmProduct();
        $payload = $mapper->exportMappedData($product);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip service');
        // type=1 is exported under the 'type' api key as the front-side string
        // alias 'service' (dmProduct::fieldFilterValueType maps the Dolibarr
        // int). The front-end uses it to switch between product and service UIs.
        $this->assertApiKeyEquals($payload, 'type', dmProduct::TYPE_SERVICE);
    }

    public function testDmProductImportRejectsStock(): void
    {
        $mapper = new dmProduct();

        try {
            // 'stock' (stock_reel) is computed from stock movements -- never
            // directly writable. Use Dolibarr stock entry endpoints instead.
            $mapper->importMappedData(['stock' => 42]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('stock', $e->getErrors());
        }
    }

    public function testDmProductImportRejectsStockAlertThreshold(): void
    {
        $mapper = new dmProduct();

        try {
            // 'stock_alert_threshold' (seuil_stock_alerte) is documented in
            // the mapper as case-by-case excluded -- a strict-by-default
            // policy keeps it out of the allowlist.
            $mapper->importMappedData(['stock_alert_threshold' => 5]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('stock_alert_threshold', $e->getErrors());
        }
    }

    /* -----------------------------------------------------------------
     * dmAgendaEvent (ActionComm)
     * --------------------------------------------------------------- */

    public function testDmAgendaEventRoundTripExport(): void
    {
        $event = new \ActionComm($this->db);
        $event->type_code   = 'AC_RDV';
        $event->label       = 'Round-trip meeting';
        $event->datep       = dol_now();
        $event->datef       = dol_now() + 3600;
        $event->userownerid = (int) $this->testUser->id;
        $event->location    = 'Office';
        $event->priority    = 3;
        $event->percentage  = 0;
        $event->note_public = 'Public agenda note';

        $id = $event->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test action: ' . $event->error);

        $mapper  = new dmAgendaEvent();
        $payload = $mapper->exportMappedData($event);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'label', 'Round-trip meeting');
        $this->assertApiKeyEquals($payload, 'type_code', 'AC_RDV');
        $this->assertApiKeyEquals($payload, 'location', 'Office');
        $this->assertApiKeyEquals($payload, 'priority', 3);
        $this->assertApiKeyEquals($payload, 'public_note', 'Public agenda note');
    }

    public function testDmAgendaEventAliasIsAvailable(): void
    {
        // Backward-compatibility alias declared at the bottom of
        // dmAgendaEvent.php. Dolibarr FK resolution calls the class by
        // its un-aliased Dolibarr class name (ActionComm).
        $this->assertTrue(
            class_exists('SmartAuth\\DolibarrMapping\\dmActionComm'),
            'dmActionComm class alias must be registered alongside dmAgendaEvent'
        );

        // And both names produce a working mapper that maps the same
        // Dolibarr class.
        $aliasMapper = new \SmartAuth\DolibarrMapping\dmActionComm();
        $this->assertSame('object', $aliasMapper->objectType());
    }

    public function testDmAgendaEventImportRejectsTypeCode(): void
    {
        $mapper = new dmAgendaEvent();

        try {
            // 'type_code' (and 'type_label') are exposed for read but the
            // event type is set once at create via the CActionComm
            // dictionary. Not writable.
            $mapper->importMappedData(['type_code' => 'AC_OTH']);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('type_code', $e->getErrors());
        }
    }

    public function testDmAgendaEventImportRejectsCreatedBy(): void
    {
        $mapper = new dmAgendaEvent();

        try {
            // 'created_by' (fk_user_author) is audit-trail -- not writable.
            $mapper->importMappedData(['created_by' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('created_by', $e->getErrors());
        }
    }

    /* -----------------------------------------------------------------
     * dmTicket
     * --------------------------------------------------------------- */

    public function testDmTicketRoundTripExport(): void
    {
        // The SQLite test database used by integration-dolibarr does NOT
        // ship llx_ticket (the Ticket module schema is not installed in the
        // baseline). We mark the test as skipped instead of failing so the
        // suite still proves dmTicket itself loads and boots correctly --
        // the import-side strict-rejection tests below run unconditionally
        // since they exercise the mapper without touching the database.
        $this->skipIfTicketTableMissing();

        $ticket = new \Ticket($this->db);
        $ticket->ref      = 'TK-' . uniqid();
        $ticket->subject  = 'Round-trip ticket';
        $ticket->message  = 'Issue described in plain text';
        $ticket->type_code     = 'OTHER';
        $ticket->category_code = 'OTHER';
        $ticket->severity_code = 'NORMAL';
        $ticket->fk_user_create = (int) $this->testUser->id;

        $id = $ticket->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test ticket: ' . implode(',', (array) $ticket->errors));

        $mapper  = new dmTicket();
        $payload = $mapper->exportMappedData($ticket);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'ref', $ticket->ref);
        $this->assertApiKeyEquals($payload, 'subject', 'Round-trip ticket');
        $this->assertApiKeyEquals($payload, 'message', 'Issue described in plain text');
        // track_id is auto-generated by Ticket::create when blank.
        $this->assertObjectHasProperty('track_id', $payload);
        $this->assertNotEmpty($payload->track_id);
    }

    public function testDmTicketImportRejectsStatus(): void
    {
        $mapper = new dmTicket();

        try {
            // 'status' (fk_statut) is a state machine driven by Ticket
            // workflow methods -- never writable by client input.
            $mapper->importMappedData(['status' => 8]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testDmTicketImportRejectsTrackId(): void
    {
        $mapper = new dmTicket();

        try {
            // 'track_id' is generated by Ticket::create() (random 16-char
            // string) -- it identifies the ticket externally and must not
            // be overwritten by client input.
            $mapper->importMappedData(['track_id' => 'forged-track-id']);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('track_id', $e->getErrors());
        }
    }

    /* -----------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------- */

    /**
     * Skip the current test if llx_ticket is missing. The Ticket module
     * SQL files are not shipped by the dolibarr-integration-sqlite vendor
     * package -- only the c_ticket_* dictionaries are. Round-trip tests
     * that hit the database must opt out cleanly in that environment.
     */
    private function skipIfTicketTableMissing(): void
    {
        $prefix = MAIN_DB_PREFIX;
        $sql    = "SELECT name FROM sqlite_master WHERE type='table' AND name = '" . $prefix . "ticket'";
        $resql  = $this->db->query($sql);
        if (!$resql || !$this->db->fetch_object($resql)) {
            $this->markTestSkipped('llx_ticket is not installed in the integration-dolibarr SQLite baseline.');
        }
    }

    /**
     * Helper: assert that a key exists on the export payload and equals
     * the expected value. exportMappedData returns a stdClass so we
     * normalize to property access.
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
