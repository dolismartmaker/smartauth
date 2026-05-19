<?php

/**
 * Round-trip pilot tests for core mappers.
 *
 * Demonstrates the canonical pattern for testing a mapper end-to-end:
 *   1. create a real Dolibarr object (fetch / factory)
 *   2. instantiate the mapper, call exportMappedData()
 *   3. assert the expected API-side keys are present with sensible types
 *   4. for writable mappers, send a payload through importMappedData()
 *      and check the doliside object that comes back
 *
 * This file covers three representative cases:
 *   - dmThirdparty : object with many fields, FK resolution, no lines
 *   - dmUser       : object with sensitive fields excluded from writableFields
 *   - dmWarehouse  : simple object with status state-machine excluded
 *
 * The same 30-line pattern is replicated for every core mapper in
 * MapperRoundTripLot{A,B,C,D,E}Test. When extending this suite, mirror
 * the structure: one method per mapper, helpers shared via the
 * protected methods at the bottom of the class.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmThirdparty;
use SmartAuth\DolibarrMapping\dmUser;
use SmartAuth\DolibarrMapping\dmWarehouse;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';

/**
 * @covers \SmartAuth\DolibarrMapping\dmTrait::exportMappedData
 * @covers \SmartAuth\DolibarrMapping\dmTrait::importMappedData
 */
class MapperRoundTripPilotTest extends DolibarrRealTestCase
{
    public function testDmThirdpartyRoundTripExport(): void
    {
        $societe = $this->createTestSociete([
            'name'  => 'Round-trip Inc.',
            'email' => 'pilot+thirdparty@example.com',
        ]);

        $mapper = new dmThirdparty();
        $payload = $mapper->exportMappedData($societe);

        $this->assertApiKeyEquals($payload, 'id', $societe->id);
        $this->assertApiKeyEquals($payload, 'name', 'Round-trip Inc.');
        $this->assertApiKeyEquals($payload, 'email', 'pilot+thirdparty@example.com');
        $this->assertObjectHasProperty('nb_linked_files', $payload);
    }

    public function testDmThirdpartyImportRejectsDoliSideKey(): void
    {
        $mapper = new dmThirdparty();

        try {
            // The mapper expects api-side keys (e.g. 'name'). Sending
            // the doliside key 'nom' directly must be rejected -- the
            // reverse map is keyed by api-side names.
            $mapper->importMappedData(['nom' => 'Should not pass']);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('nom', $e->getErrors());
        }
    }

    public function testDmThirdpartyImportRejectsReadOnlyId(): void
    {
        $mapper = new dmThirdparty();

        try {
            // 'id' is published (mapped from 'rowid') but not writable.
            // Any payload trying to overwrite the rowid must be rejected.
            $mapper->importMappedData(['id' => 999]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDmThirdpartyImportAcceptsWritableField(): void
    {
        $mapper = new dmThirdparty();
        $sanitized = $mapper->importMappedData([
            'name'  => 'Updated Co.',
            'email' => 'updated@example.com',
        ]);

        // Mapper writes to the PHP property 'name' (which Societe::update
        // reads to write the SQL column 'nom'), not the raw column.
        $this->assertSame('Updated Co.', $sanitized->name);
        $this->assertSame('updated@example.com', $sanitized->email);
        $this->assertObjectNotHasProperty('nom', $sanitized);
        $this->assertObjectNotHasProperty('id', $sanitized);
    }

    public function testDmUserRoundTripExportExcludesSensitiveFields(): void
    {
        $user = $this->createTestUser([
            'login'    => 'pilot_' . uniqid(),
            'lastname' => 'Pilot',
        ]);

        $mapper = new dmUser();
        $payload = $mapper->exportMappedData($user);

        $this->assertApiKeyEquals($payload, 'id', $user->id);

        // Sensitive fields must NEVER bleed into the export, even
        // accidentally. The mapper documents them as excluded; this
        // assertion is the safety net.
        $this->assertObjectNotHasProperty('pass', $payload);
        $this->assertObjectNotHasProperty('pass_crypted', $payload);
        $this->assertObjectNotHasProperty('rights', $payload);
    }

    public function testDmUserImportRejectsAdminEscalation(): void
    {
        $mapper = new dmUser();

        // 'admin' is on User but intentionally absent from writableFields.
        // A malicious payload trying to flip admin=1 must be rejected.
        try {
            $mapper->importMappedData(['admin' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('admin', $errors);
        }
    }

    public function testDmWarehouseRoundTripExport(): void
    {
        $warehouse = new \Entrepot($this->db);
        $warehouse->label = 'Pilot warehouse ' . uniqid();
        $warehouse->statut = 1;
        $id = $warehouse->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test warehouse: ' . $warehouse->error);

        $fresh = new \Entrepot($this->db);
        $fresh->fetch($id);

        $mapper = new dmWarehouse();
        $payload = $mapper->exportMappedData($fresh);

        // Entrepot is a quirky object: its 'ref' column is populated
        // from $this->label at create time (see Entrepot::create), so
        // both api keys end up holding the label value. We assert on
        // id + label only -- ref equals label here, not a separate field.
        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'label', $warehouse->label);
    }

    public function testDmWarehouseImportRejectsStatusChange(): void
    {
        $mapper = new dmWarehouse();

        // 'statut' is a state machine; closing a warehouse goes through
        // Entrepot::setStatut(). A payload trying to flip statut must
        // be rejected. The api-side name is 'status'.
        try {
            $mapper->importMappedData(['status' => 0]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('status', $errors);
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
