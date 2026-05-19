<?php

/**
 * End-to-end test that the extrafield exposure mechanism works.
 *
 * The `dmTrait::_objectDesc()` and `exportMappedData()` pipeline supports
 * extrafields opt-in by mapper: declare `options_<name>` in
 * `$listOfPublishedFields` and set `$parentTableElementToUseForExtraFields`
 * to the matching Dolibarr element type (e.g. 'societe' for Societe).
 *
 * This test pins the contract end-to-end using an anonymous mapper class
 * (no core mapper currently opts in -- cf TODO-mappers-centralisation.md
 * Phase 2). It is the canonical model for enriching mappers later.
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

class MapperExtrafieldsExposureTest extends DolibarrRealTestCase
{
    public const EXTRAFIELD_NAME = 'phase5_test_extra';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropExtrafield();
        $this->createExtrafield();
    }

    protected function tearDown(): void
    {
        $this->dropExtrafield();
        parent::tearDown();
    }

    public function testExtrafieldExposedInExport(): void
    {
        $societe = $this->createTestSociete([
            'name' => 'Extrafield exposure ' . uniqid(),
        ]);

        // Set the extrafield value in-memory. We do not need to persist
        // it: exportMappedData reads $obj->array_options directly.
        $societe->array_options['options_' . self::EXTRAFIELD_NAME] = 'visible value';

        $mapper = new class extends dmThirdparty {
            protected $parentTableElementToUseForExtraFields = 'societe';

            public function __construct()
            {
                $this->listOfPublishedFields['options_' . MapperExtrafieldsExposureTest::EXTRAFIELD_NAME] = 'exposed_extra';
                parent::__construct();
            }
        };

        $payload = $mapper->exportMappedData($societe);

        $this->assertObjectHasProperty(
            'exposed_extra',
            $payload,
            'Mapper declared options_' . self::EXTRAFIELD_NAME
                . ' in listOfPublishedFields but the key is missing from export.'
        );
        $this->assertSame('visible value', $payload->exposed_extra);
    }

    public function testExtrafieldNotDeclaredIsNotExposed(): void
    {
        $societe = $this->createTestSociete([
            'name' => 'Extrafield silent ' . uniqid(),
        ]);
        $societe->array_options['options_' . self::EXTRAFIELD_NAME] = 'hidden value';

        // Stock dmThirdparty does NOT declare any options_* and does
        // not set $parentTableElementToUseForExtraFields. The value
        // must NOT bleed into the payload.
        $mapper = new dmThirdparty();
        $payload = $mapper->exportMappedData($societe);

        $this->assertObjectNotHasProperty(self::EXTRAFIELD_NAME, $payload);
        $this->assertObjectNotHasProperty('exposed_extra', $payload);
    }

    private function createExtrafield(): void
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "extrafields"
            . " (name, entity, elementtype, label, type, pos, list)"
            . " VALUES ('" . self::EXTRAFIELD_NAME . "', 1, 'societe',"
            . " 'Phase 5 test', 'varchar', 100, '1')";
        $res = $this->db->query($sql);
        $this->assertNotFalse(
            $res,
            'Failed to insert extrafield row: ' . $this->db->lasterror()
        );
    }

    private function dropExtrafield(): void
    {
        $this->db->query(
            "DELETE FROM " . MAIN_DB_PREFIX . "extrafields"
            . " WHERE name='" . self::EXTRAFIELD_NAME . "'"
            . " AND elementtype='societe'"
        );
    }
}
