<?php

/**
 * Integration tests for GeoHelper.
 *
 * These exercise set() / get() / clear() against a real Dolibarr SQLite
 * database, including the geo columns that modSmartauth::init() adds to
 * llx_ecm_files when the underlying Dolibarr predates the 23.0 native
 * schema. The pure validate() function is covered by the unit suite.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/GeoHelper.php';

use EcmFiles;
use SmartAuth\Api\GeoHelper;

/**
 * @covers \SmartAuth\Api\GeoHelper
 */
class GeoHelperIntegrationTest extends DolibarrRealTestCase
{
    private function makeEcmFile(\User $owner): EcmFiles
    {
        require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

        $unique = uniqid('', true);
        $ecm = new EcmFiles($this->db);
        $ecm->ref = 'GEO' . $unique;
        $ecm->label = md5($unique);
        $ecm->entity = 1;
        $ecm->filename = 'photo-' . $unique . '.jpg';
        $ecm->filepath = 'smartauth/geo-test';
        $ecm->fullpath_orig = '';
        $ecm->description = 'GeoHelper test fixture';
        $ecm->keywords = '';
        $ecm->gen_or_uploaded = 'uploaded';
        $ecm->fk_user_c = $owner->id;

        $res = $ecm->create($owner);
        if ($res < 0) {
            throw new \Exception('Failed to create ECM fixture: ' . $ecm->error);
        }
        return $ecm;
    }

    public function testGeoColumnsExistAfterModuleInit(): void
    {
        // Pre-creation only happens on Dolibarr < 23. On >= 23 the columns
        // come from core. Either way they MUST be present.
        $expected = ['geolat', 'geolong', 'geopoint', 'georesultcode'];

        $sql = "PRAGMA table_info(" . MAIN_DB_PREFIX . "ecm_files)";
        $res = $this->db->query($sql);
        $this->assertNotFalse($res);

        $found = [];
        while ($obj = $this->db->fetch_object($res)) {
            if (isset($obj->name)) {
                $found[] = $obj->name;
            }
        }
        foreach ($expected as $col) {
            $this->assertContains($col, $found, "Column $col missing from llx_ecm_files");
        }
    }

    public function testSetThenGetRoundtrip(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        $this->assertTrue(GeoHelper::set($ecm->id, 48.8566, 2.3522, $this->testUser->id, 'OK'));

        $coords = GeoHelper::get($ecm->id, $this->testUser->id);
        $this->assertNotNull($coords);
        $this->assertSame(48.8566, $coords['lat']);
        $this->assertSame(2.3522, $coords['lon']);
        $this->assertSame('OK', $coords['resultcode']);
        // The WKT is generated as POINT(lon lat) per the OGC convention.
        $this->assertNotNull($coords['point']);
        $this->assertStringContainsString('POINT', $coords['point']);
        $this->assertStringContainsString('2.3522', $coords['point']);
        $this->assertStringContainsString('48.8566', $coords['point']);
    }

    public function testSetWithoutResultCode(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        $this->assertTrue(GeoHelper::set($ecm->id, 47.21, -1.55, $this->testUser->id));

        $coords = GeoHelper::get($ecm->id, $this->testUser->id);
        $this->assertNotNull($coords);
        $this->assertSame(47.21, $coords['lat']);
        $this->assertSame(-1.55, $coords['lon']);
        $this->assertNull($coords['resultcode']);
    }

    public function testGetReturnsNullWhenNothingStored(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);
        $this->assertNull(GeoHelper::get($ecm->id, $this->testUser->id));
    }

    public function testSetRejectsForeignOwner(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);
        $other = $this->createTestUser(['login' => 'foreign_geo_' . uniqid()]);

        $this->assertFalse(GeoHelper::set($ecm->id, 48.85, 2.35, $other->id));
        $this->assertNull(GeoHelper::get($ecm->id, $this->testUser->id));

        // Owner can write, foreign user cannot read.
        $this->assertTrue(GeoHelper::set($ecm->id, 48.85, 2.35, $this->testUser->id));
        $this->assertNull(GeoHelper::get($ecm->id, $other->id));
        $this->assertFalse(GeoHelper::clear($ecm->id, $other->id));

        // The owner's data is intact.
        $coords = GeoHelper::get($ecm->id, $this->testUser->id);
        $this->assertNotNull($coords);
        $this->assertSame(48.85, $coords['lat']);
    }

    public function testSetReturnsFalseForUnknownEcmFile(): void
    {
        $this->assertFalse(GeoHelper::set(999999, 48.85, 2.35, $this->testUser->id));
        $this->assertNull(GeoHelper::get(999999, $this->testUser->id));
        $this->assertFalse(GeoHelper::clear(999999, $this->testUser->id));
    }

    public function testSetRejectsOutOfRangeCoords(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);
        $this->assertFalse(GeoHelper::set($ecm->id, 91, 0, $this->testUser->id));
        $this->assertFalse(GeoHelper::set($ecm->id, 0, 200, $this->testUser->id));
        // Nothing was persisted from the failed attempts.
        $this->assertNull(GeoHelper::get($ecm->id, $this->testUser->id));
    }

    public function testClearResetsAllFields(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        $this->assertTrue(GeoHelper::set($ecm->id, 48.85, 2.35, $this->testUser->id, 'OK'));
        $this->assertNotNull(GeoHelper::get($ecm->id, $this->testUser->id));

        $this->assertTrue(GeoHelper::clear($ecm->id, $this->testUser->id));
        $this->assertNull(GeoHelper::get($ecm->id, $this->testUser->id));
    }

    public function testNegativeAndDecimalCoordsRoundtrip(): void
    {
        // Sydney: -33.8688, 151.2093 -- both signs and many decimals.
        $ecm = $this->makeEcmFile($this->testUser);
        $this->assertTrue(GeoHelper::set($ecm->id, -33.8688, 151.2093, $this->testUser->id));

        $coords = GeoHelper::get($ecm->id, $this->testUser->id);
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(-33.8688, $coords['lat'], 0.0001);
        $this->assertEqualsWithDelta(151.2093, $coords['lon'], 0.0001);
    }
}
