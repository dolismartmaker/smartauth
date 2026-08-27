<?php

/**
 * The sync push door writes extrafields -- and only the ones a mapper opens.
 *
 * The gap this closes. The REST facade has written extrafields since vague 3,
 * the push door had not: its allowlist read $writableFields alone, its
 * assignment loop filtered on property_exists() (false for every 'options_*'
 * key) and it never called insertExtraFields(). A client pushing a custom field
 * got a 200 and nothing stored -- a silent loss, on the very door offline-first
 * clients use. TODO section 9.3.
 *
 * The trap that comes with it. CommonObject::insertExtraFields() DELETEs the
 * object's extrafield row and re-INSERTs it from array_options alone. Writing a
 * single custom field without loading the existing ones first therefore ERASES
 * every other one. testPartialUpdateKeepsTheExtrafieldsItDoesNotRestate() is
 * the test that fails if fetch_optionals() is dropped from processUpdate().
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SyncController.php';

use SmartAuth\Api\SyncController;
use SmartAuth\DolibarrMapping\dmBase;

/**
 * Test mapper on Societe: 'syncef' and 'synclink' are opened for write,
 * 'syncro' is published (readable) but stays read-only.
 */
class SyncExtrafieldTestMapper extends dmBase
{
    protected $type = 'object';
    protected $dolibarrClassName = 'Societe';
    protected $parentTableElementToUseForExtraFields = 'societe';
    protected $listOfPublishedFields = [
        'rowid'             => 'id',
        'name'              => 'name',
        'options_syncef'    => 'sync_ef',
        'options_syncro'    => 'sync_ro',
        'options_synclink'  => 'sync_link',
    ];
    protected $writableFields = ['name'];
    protected $extrafieldsRW = ['syncef', 'synclink'];
}

/**
 * A Dolibarr class whose fetch() does NOT load the extrafields.
 *
 * Every core class of the registry happens to call fetch_optionals() inside
 * fetch() (Societe l.1998, User l.583, Ticket l.711...), which hides the
 * hazard: without an explicit load, insertExtraFields() would rebuild the row
 * from an array_options holding only what the payload restated, and wipe the
 * rest. A hook-registered type is under no such obligation, so the situation is
 * reproduced here rather than assumed impossible -- that is what makes
 * SyncController::loadExistingExtrafields() falsifiable instead of decorative.
 */
class SyncExtrafieldNoOptionalsSociete extends \Societe
{
    /**
     * @param  int    $rowid
     * @param  string $ref
     * @param  string $ref_alias
     * @param  string $barcode
     * @param  string $idprof1
     * @param  string $idprof2
     * @param  string $idprof3
     * @param  string $idprof4
     * @param  string $idprof5
     * @param  string $idprof6
     * @param  string $email
     * @param  string $ref_ext
     * @return int
     */
    public function fetch($rowid, $ref = '', $ref_alias = '', $barcode = '', $idprof1 = '', $idprof2 = '', $idprof3 = '', $idprof4 = '', $idprof5 = '', $idprof6 = '', $email = '', $ref_ext = '')
    {
        $res = parent::fetch($rowid, $ref, $ref_alias, $barcode, $idprof1, $idprof2, $idprof3, $idprof4, $idprof5, $idprof6, $email, $ref_ext);
        // Undo what the parent did: this class is standing in for one that never
        // loaded them in the first place.
        $this->array_options = [];

        return $res;
    }
}

/**
 * Same mapping, on the class above.
 */
class SyncExtrafieldNoOptionalsMapper extends SyncExtrafieldTestMapper
{
    protected $dolibarrClassName = 'SmartAuth\\Tests\\IntegrationDolibarr\\SyncExtrafieldNoOptionalsSociete';
}

/**
 * @covers \SmartAuth\Api\SyncController
 * @covers \SmartAuth\Api\ForeignKeyGuardTrait
 */
class SyncExtrafieldWriteTest extends DolibarrRealTestCase
{
    /** Entity a planted victim company is moved to. Never the harness one. */
    private const FOREIGN_ENTITY = 94;

    /** @var SyncController */
    private $controller;

    /** @var int[] */
    private $createdSocIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $ef = new \ExtraFields($this->db);
        $ef->addExtraField('syncef', 'Sync EF', 'varchar', 1600, 64, 'societe');
        $ef->addExtraField('syncro', 'Sync RO', 'varchar', 1601, 64, 'societe');
        // 'link' -> int(11) column holding a rowid of llx_societe: the type the
        // tenant guard has to vet, exactly like a native fk_ column.
        $ef->addExtraField(
            'synclink',
            'Sync link',
            'link',
            1602,
            0,
            'societe',
            0,
            0,
            '',
            ['options' => ['Societe:societe/class/societe.class.php' => null]]
        );

        // An earlier test in the process may have cached the 'societe'
        // extrafields BEFORE these attributes existed; fetch_optionals() reuses
        // that global, so force a reload or the round-trips below read nothing.
        global $extrafields;
        if (!isset($extrafields) || !is_object($extrafields)) {
            $extrafields = new \ExtraFields($this->db);
        }
        $extrafields->fetch_name_optionals_label('societe', true);

        $this->controller = new SyncController();
        $this->createdSocIds = [];

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSocIds as $id) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $id);
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_extrafields WHERE fk_object = " . (int) $id);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    /**
     * The built-in 'thirdparty' sync config, with its mapper swapped for the
     * test one so a real push flow runs against extrafields we control.
     */
    private function syncConfig(array $overrides = []): array
    {
        $ref = new \ReflectionClass($this->controller);
        $prop = $ref->getProperty('syncableObjects');
        $prop->setAccessible(true);

        $all = $prop->getValue($this->controller);
        $config = array_merge($all['thirdparty'], ['mapper' => SyncExtrafieldTestMapper::class], $overrides);

        // resolveMapperClass() reads the instance property, not the argument.
        $all['thirdparty'] = $config;
        $prop->setValue($this->controller, $all);

        return $config;
    }

    /**
     * @return mixed
     */
    private function invokePrivate(string $method, array $args)
    {
        $ref = new \ReflectionClass($this->controller);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->controller, $args);
    }

    private function pushCreate(array $data): int
    {
        $config = $this->syncConfig();
        $result = $this->invokePrivate('processCreate', [$config, $data, $this->testUser]);
        $this->assertTrue($result['success'] ?? false, 'processCreate failed: ' . ($result['error'] ?? 'unknown'));

        $id = (int) $result['id'];
        $this->createdSocIds[] = $id;
        return $id;
    }

    private function pushUpdate(int $id, array $data, array $configOverrides = []): array
    {
        $config = $this->syncConfig($configOverrides);

        // base_tms null: no conflict detection, which is not what is under test
        // here. client_id 0: only used to record a conflict.
        return $this->invokePrivate('processUpdate', [$config, $id, $data, null, 0, $this->testUser]);
    }

    /** Raw read of an extrafield column, bypassing every cache. */
    private function rawExtrafield(int $socId, string $column)
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . 'societe_extrafields WHERE fk_object = ' . $socId
        );
        if (!$resql) {
            $this->fail('SQL error reading societe_extrafields.' . $column . ': ' . $this->db->lasterror());
        }
        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $row === null ? null : $row->val;
    }

    private function plantForeignSociete(): int
    {
        $soc = $this->createTestSociete(['name' => 'Foreign EF target ' . uniqid()]);
        $id = (int) $soc->id;
        $this->createdSocIds[] = $id;

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'societe SET entity = ' . self::FOREIGN_ENTITY . ' WHERE rowid = ' . $id;
        if (!$this->db->query($sql)) {
            $this->fail('could not plant the foreign company: ' . $this->db->lasterror());
        }

        return $id;
    }

    // ---------------------------------------------------------------- create

    public function testPushCreateWritesAnAllowlistedExtrafield(): void
    {
        $id = $this->pushCreate([
            'name'    => 'SyncEf Co ' . uniqid(),
            'sync_ef' => 'pushed-value',
        ]);

        $this->assertSame(
            'pushed-value',
            $this->rawExtrafield($id, 'syncef'),
            'the pushed extrafield was not persisted'
        );
    }

    /**
     * A key the mapper does not open must be dropped by the upstream filter,
     * never written -- default-deny, same rule as $writableFields.
     */
    public function testPushRefusesAnExtrafieldTheMapperDoesNotOpen(): void
    {
        $id = $this->pushCreate([
            'name'    => 'SyncRo Co ' . uniqid(),
            'sync_ef' => 'kept',
            'sync_ro' => 'must-not-be-written',
        ]);

        $this->assertSame('kept', $this->rawExtrafield($id, 'syncef'));
        $this->assertEmpty(
            $this->rawExtrafield($id, 'syncro'),
            'a read-only extrafield was written by the push door'
        );
    }

    // ---------------------------------------------------------------- update

    public function testPushUpdateWritesAnAllowlistedExtrafield(): void
    {
        $id = $this->pushCreate(['name' => 'SyncEf Co ' . uniqid(), 'sync_ef' => 'first']);

        $result = $this->pushUpdate($id, ['sync_ef' => 'second']);
        $this->assertTrue($result['success'] ?? false, 'processUpdate failed: ' . ($result['error'] ?? 'unknown'));
        $this->assertSame('second', $this->rawExtrafield($id, 'syncef'));
    }

    /**
     * THE regression test of this lot. insertExtraFields() rebuilds the whole
     * extrafield row from array_options, so a push that restates one custom
     * field must not take the others down with it.
     */
    public function testPartialUpdateKeepsTheExtrafieldsItDoesNotRestate(): void
    {
        $id = $this->pushCreate(['name' => 'SyncEf Co ' . uniqid(), 'sync_ef' => 'written-by-push']);

        // Plant a second custom field the push will never mention -- filled the
        // way anything else in the instance would fill it (a screen, an import).
        $soc = new \Societe($this->db);
        $this->assertGreaterThan(0, $soc->fetch($id));
        $soc->fetch_optionals();
        $soc->array_options['options_syncro'] = 'set-elsewhere';
        $this->assertGreaterThanOrEqual(0, $soc->insertExtraFields());
        $this->assertSame('set-elsewhere', $this->rawExtrafield($id, 'syncro'));

        $result = $this->pushUpdate($id, ['sync_ef' => 'updated-by-push']);
        $this->assertTrue($result['success'] ?? false, 'processUpdate failed: ' . ($result['error'] ?? 'unknown'));

        $this->assertSame('updated-by-push', $this->rawExtrafield($id, 'syncef'));
        $this->assertSame(
            'set-elsewhere',
            $this->rawExtrafield($id, 'syncro'),
            'the push erased an extrafield it never mentioned'
        );
    }

    /**
     * The same partial update, on a class whose fetch() does NOT load the
     * extrafields. This is the one that fails when loadExistingExtrafields() is
     * removed from processUpdate(): every core class hides the hazard by
     * calling fetch_optionals() itself, a hook-registered one need not.
     */
    public function testPartialUpdateSurvivesAClassThatDoesNotLoadExtrafields(): void
    {
        $id = $this->pushCreate(['name' => 'SyncEf Co ' . uniqid(), 'sync_ef' => 'written-by-push']);

        $soc = new \Societe($this->db);
        $this->assertGreaterThan(0, $soc->fetch($id));
        $soc->fetch_optionals();
        $soc->array_options['options_syncro'] = 'set-elsewhere';
        $this->assertGreaterThanOrEqual(0, $soc->insertExtraFields());

        $result = $this->pushUpdate(
            $id,
            ['sync_ef' => 'updated-by-push'],
            [
                'class'  => SyncExtrafieldNoOptionalsSociete::class,
                'mapper' => SyncExtrafieldNoOptionalsMapper::class,
            ]
        );
        $this->assertTrue($result['success'] ?? false, 'processUpdate failed: ' . ($result['error'] ?? 'unknown'));

        $this->assertSame('updated-by-push', $this->rawExtrafield($id, 'syncef'));
        $this->assertSame(
            'set-elsewhere',
            $this->rawExtrafield($id, 'syncro'),
            'the push erased an extrafield the fetched class had not loaded'
        );
    }

    /**
     * A push carrying no extrafield at all must not touch the ones stored.
     */
    public function testUpdateWithoutExtrafieldsLeavesThemAlone(): void
    {
        $id = $this->pushCreate(['name' => 'SyncEf Co ' . uniqid(), 'sync_ef' => 'untouched']);

        $result = $this->pushUpdate($id, ['name' => 'Renamed ' . uniqid()]);
        $this->assertTrue($result['success'] ?? false, 'processUpdate failed: ' . ($result['error'] ?? 'unknown'));
        $this->assertSame('untouched', $this->rawExtrafield($id, 'syncef'));
    }

    // ----------------------------------------------------------- tenant guard

    /**
     * The guard that vets a native fk_ column also vets an extrafield of type
     * 'link' -- and it now does so on THIS door too, not just the REST facade.
     */
    public function testExtrafieldLinkCannotPointAtAnotherTenant(): void
    {
        $foreignId = $this->plantForeignSociete();

        $id = $this->pushCreate([
            'name'      => 'SyncLink Co ' . uniqid(),
            'sync_ef'   => 'kept',
            'sync_link' => $foreignId,
        ]);

        $this->assertSame('kept', $this->rawExtrafield($id, 'syncef'), 'the clean field must still be written');
        $this->assertEmpty(
            $this->rawExtrafield($id, 'synclink'),
            'a link extrafield pointing at another tenant was written'
        );
    }

    public function testExtrafieldLinkAcceptsALocalTarget(): void
    {
        $local = $this->createTestSociete(['name' => 'Local EF target ' . uniqid()]);
        $localId = (int) $local->id;
        $this->createdSocIds[] = $localId;

        $id = $this->pushCreate([
            'name'      => 'SyncLink Co ' . uniqid(),
            'sync_link' => $localId,
        ]);

        $this->assertSame(
            $localId,
            (int) $this->rawExtrafield($id, 'synclink'),
            'a link to a company of the caller tenant must be accepted'
        );
    }

    // ---------------------------------------------------------------- legacy

    /**
     * A hook-registered type WITHOUT mapper has no getExtrafieldWriteTargets(),
     * so nothing can vet what its extrafield points at: the legacy path refuses
     * 'options_*' outright, even allowlisted. Fail-closed, and logged.
     */
    public function testLegacyPathRefusesExtrafieldsEvenWhenAllowlisted(): void
    {
        $config = [
            'object_type'    => 'hook_type_without_mapper',
            'class'          => 'Societe',
            'table'          => 'societe',
            'element'        => 'societe',
            'allowed_fields' => ['name', 'options_syncef'],
        ];

        $soc = new \Societe($this->db);
        $extrafieldsApplied = true;
        $rejected = $this->invokePrivate('applyDataToObject', [
            $soc,
            ['name' => 'Legacy Co', 'options_syncef' => 'nope'],
            $config,
            &$extrafieldsApplied,
        ]);

        $this->assertContains('options_syncef', $rejected, 'the extrafield must be reported as rejected');
        $this->assertFalse($extrafieldsApplied, 'the legacy path must never flag an extrafield as applied');
        $this->assertSame('Legacy Co', $soc->name, 'the native field must still be applied');
        $this->assertArrayNotHasKey(
            'options_syncef',
            is_array($soc->array_options ?? null) ? $soc->array_options : [],
            'the legacy path routed an extrafield into array_options'
        );
    }

    // ------------------------------------------------------------------ pull

    /**
     * Symmetry: what the push writes, the pull returns. Without the explicit
     * fetch_optionals() the client could never read back what it just sent.
     */
    public function testPullReturnsTheExtrafieldThePushWrote(): void
    {
        $id = $this->pushCreate(['name' => 'SyncEf Co ' . uniqid(), 'sync_ef' => 'round-trip']);
        $config = $this->syncConfig();

        $row = (object) ['rowid' => $id];
        $mapped = $this->invokePrivate('mapObjectThroughMapper', [$row, 'thirdparty', $config, $id]);

        $this->assertIsArray($mapped);
        $this->assertSame('round-trip', $mapped['sync_ef'] ?? null, 'the pull does not carry the extrafield');
    }
}
