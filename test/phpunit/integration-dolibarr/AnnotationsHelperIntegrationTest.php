<?php

/**
 * Integration tests for AnnotationsHelper.
 *
 * These exercise set() / get() against a real Dolibarr SQLite database,
 * including the smartmaker_annotations extrafield that modSmartauth::init()
 * creates on llx_ecm_files. The pure sanitize() function is covered
 * separately by the unit suite.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AnnotationsHelper.php';

use EcmFiles;
use SmartAuth\Api\AnnotationsHelper;

/**
 * @covers \SmartAuth\Api\AnnotationsHelper
 */
class AnnotationsHelperIntegrationTest extends DolibarrRealTestCase
{
    /**
     * Create an ECM file row owned by $owner. fk_user_c drives the owner
     * check inside AnnotationsHelper.
     */
    private function makeEcmFile(\User $owner): EcmFiles
    {
        require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

        $unique = uniqid('', true);
        $ecm = new EcmFiles($this->db);
        $ecm->ref = 'ANN' . $unique;
        $ecm->label = md5($unique);
        $ecm->entity = 1;
        // Unique (filepath, filename, entity) is enforced by llx_ecm_files,
        // so each fixture must vary the filename or filepath.
        $ecm->filename = 'photo-' . $unique . '.jpg';
        $ecm->filepath = 'smartauth/annotations-test';
        $ecm->fullpath_orig = '';
        $ecm->description = 'AnnotationsHelper test fixture';
        $ecm->keywords = '';
        $ecm->gen_or_uploaded = 'uploaded';
        $ecm->fk_user_c = $owner->id;

        $res = $ecm->create($owner);
        if ($res < 0) {
            throw new \Exception('Failed to create ECM fixture: ' . $ecm->error);
        }
        return $ecm;
    }

    public function testSetThenGetRoundtrip(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        $annotations = [];
        for ($i = 0; $i < 5; $i++) {
            $annotations[] = [
                'id' => 'mark-' . $i,
                'type' => 'note',
                'x' => 10.0 * $i,
                'y' => 5.0 * $i,
                'payload' => ['description' => "marker $i"],
            ];
        }

        $this->assertTrue(AnnotationsHelper::set($ecm->id, $annotations, $this->testUser->id));

        $read = AnnotationsHelper::get($ecm->id, $this->testUser->id);
        $this->assertCount(5, $read);

        $byId = [];
        foreach ($read as $a) {
            $byId[$a['id']] = $a;
        }
        $this->assertSame('marker 2', $byId['mark-2']['payload']['description']);
        $this->assertSame(20.0, $byId['mark-2']['x']);
        $this->assertSame(10.0, $byId['mark-2']['y']);
    }

    public function testGetReturnsEmptyWhenNothingStored(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);
        $this->assertSame([], AnnotationsHelper::get($ecm->id, $this->testUser->id));
    }

    public function testSetRejectsForeignOwner(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);
        $other = $this->createTestUser(['login' => 'foreign_' . uniqid()]);

        $annotations = [
            ['id' => 'x', 'type' => 'note', 'x' => 1, 'y' => 1],
        ];
        $this->assertFalse(AnnotationsHelper::set($ecm->id, $annotations, $other->id));

        // get() must also refuse and return [].
        // Seed something legitimate first to confirm the foreign read returns [].
        AnnotationsHelper::set($ecm->id, $annotations, $this->testUser->id);
        $this->assertSame([], AnnotationsHelper::get($ecm->id, $other->id));

        // Owner can still read their own data.
        $own = AnnotationsHelper::get($ecm->id, $this->testUser->id);
        $this->assertCount(1, $own);
    }

    public function testSetReturnsFalseForUnknownEcmFile(): void
    {
        $this->assertFalse(AnnotationsHelper::set(999999, [
            ['id' => 'x', 'type' => 'note', 'x' => 0, 'y' => 0],
        ], $this->testUser->id));
        $this->assertSame([], AnnotationsHelper::get(999999, $this->testUser->id));
    }

    public function testSetCapsAtMaxAnnotations(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        $annotations = [];
        for ($i = 0; $i < 250; $i++) {
            $annotations[] = [
                'id' => 'm-' . $i,
                'type' => 'note',
                'x' => 0,
                'y' => 0,
            ];
        }
        $this->assertTrue(AnnotationsHelper::set($ecm->id, $annotations, $this->testUser->id));

        $read = AnnotationsHelper::get($ecm->id, $this->testUser->id);
        $this->assertCount(AnnotationsHelper::MAX_ANNOTATIONS_PER_FILE, $read);
        // The first 200 entries are kept (truncation, not random sampling).
        $this->assertSame('m-0', $read[0]['id']);
        $this->assertSame('m-199', $read[AnnotationsHelper::MAX_ANNOTATIONS_PER_FILE - 1]['id']);
    }

    public function testGetReturnsEmptyOnCorruptedJson(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        // Bypass the helper to plant garbage directly in the extrafield row.
        // First seed the row with a valid value so the column has a record
        // for this fk_object, then UPDATE it with garbage.
        $this->assertTrue(AnnotationsHelper::set($ecm->id, [
            ['id' => 'seed', 'type' => 'note', 'x' => 0, 'y' => 0],
        ], $this->testUser->id));

        $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files_extrafields";
        $sql .= " SET smartmaker_annotations = '{not valid json'";
        $sql .= " WHERE fk_object = " . (int) $ecm->id;
        $this->assertNotFalse($this->db->query($sql), 'Failed to plant corrupted JSON');

        $this->assertSame([], AnnotationsHelper::get($ecm->id, $this->testUser->id));
    }

    public function testSetRejectsOversizedPayload(): void
    {
        $ecm = $this->makeEcmFile($this->testUser);

        // One annotation whose payload alone serializes past the 1 MB cap.
        $bigDescription = str_repeat('x', AnnotationsHelper::MAX_PAYLOAD_BYTES + 1024);
        $annotations = [
            [
                'id' => 'huge',
                'type' => 'note',
                'x' => 0,
                'y' => 0,
                'payload' => ['description' => $bigDescription],
            ],
        ];

        $this->assertFalse(AnnotationsHelper::set($ecm->id, $annotations, $this->testUser->id));
        // Nothing was persisted.
        $this->assertSame([], AnnotationsHelper::get($ecm->id, $this->testUser->id));
    }

    public function testExtrafieldColumnExistsAfterModuleInit(): void
    {
        // Defensive: confirms modSmartauth::init() declared the extrafield
        // and the SQLite column actually exists. If this fails, every other
        // test in this file is meaningless.
        $sql = "PRAGMA table_info(" . MAIN_DB_PREFIX . "ecm_files_extrafields)";
        $res = $this->db->query($sql);
        $this->assertNotFalse($res);

        $found = false;
        while ($obj = $this->db->fetch_object($res)) {
            if (isset($obj->name) && $obj->name === 'smartmaker_annotations') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'smartmaker_annotations column missing from llx_ecm_files_extrafields');
    }
}
