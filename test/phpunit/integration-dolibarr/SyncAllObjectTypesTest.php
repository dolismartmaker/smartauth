<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SyncController.php';
require_once __DIR__ . '/../../../api/ObjectRegistry.php';
require_once __DIR__ . '/../../../api/InputSanitizer.php';

use SmartAuth\Api\ObjectRegistry;
use SmartAuth\Api\SyncController;

/**
 * The offline sync engine against EVERY registered object type, not just the
 * four of the first wave (F05 of spec_sync_offline.md).
 *
 * The registry has grown to 26 types while the engine kept three assumptions
 * that only hold for those four:
 *   - the primary key is named 'rowid'   -> false for llx_actioncomm ('id')
 *   - the table has an 'entity' column   -> false for llx_stock_mouvement,
 *                                           llx_subscription, llx_bank
 *   - a tombstone belongs to everybody   -> leaks another tenant's object ids
 *
 * Each test below fails on the pre-fix engine: pull() returned a 500 on the
 * entity-less types (SQL error on "entity IN (...)"), served llx_actioncomm
 * rows through the raw-cast fallback with a cursor stuck at id 0, and pushed
 * cross-tenant writes through unchecked on the three tables with no entity
 * column.
 *
 * @covers \SmartAuth\Api\SyncController
 */
class SyncAllObjectTypesTest extends DolibarrRealTestCase
{
    /** @var SyncController */
    private $controller;

    /** @var string */
    private $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();

        global $user;
        $user = $this->testUser;

        $this->controller = new SyncController();
        $this->clientUuid = $this->uuid();
        $this->cleanSyncTables();
    }

    protected function tearDown(): void
    {
        $this->cleanSyncTables();
        parent::tearDown();
    }

    // =========================================================================
    // F05: every registered type is pullable
    // =========================================================================

    /**
     * The engine must answer for every type the registry declares, including
     * the ones whose table has no entity column. Before the fix, three types
     * returned a 500 because "WHERE entity IN (...)" is a SQL error there.
     */
    public function testPullAnswersForEverySyncableType(): void
    {
        $this->registerClient();

        $failures = [];
        $skipped = [];
        $checked = 0;
        foreach (ObjectRegistry::builtins() as $type => $config) {
            // The harness database is a minimal Dolibarr: a few optional-module
            // tables are simply absent (llx_ticket today). Skipping them is
            // reported, never silent, so the coverage of this test stays honest.
            if (!$this->tableExists($config['table'])) {
                $skipped[] = $type;
                continue;
            }

            $checked++;
            list($body, $code) = $this->controller->pull([
                'user_id' => $this->testUser->id,
                'client_uuid' => $this->clientUuid,
                'object_type' => $type,
                'limit' => 5,
            ]);

            if ($code !== 200) {
                $failures[] = $type . ' -> ' . $code . ' ' . json_encode($body);
                continue;
            }
            if (!array_key_exists('updated', $body) || !array_key_exists('deleted', $body)) {
                $failures[] = $type . ' -> malformed envelope ' . json_encode(array_keys($body));
            }
        }

        $this->assertSame([], $failures, "pull failed for:\n" . implode("\n", $failures));
        $this->assertGreaterThanOrEqual(
            20,
            $checked,
            'too many types skipped for this test to mean anything, skipped: ' . implode(', ', $skipped)
        );
    }

    /**
     * The discovery endpoint exists and describes the whole registry, so a
     * client can build its sync_scope instead of hardcoding four type names.
     */
    public function testObjectsEndpointDescribesTheWholeRegistry(): void
    {
        list($body, $code) = $this->controller->objects(['user_id' => $this->testUser->id]);

        $this->assertSame(200, $code);
        $this->assertArrayHasKey('objects', $body);
        $this->assertCount(count(ObjectRegistry::builtins()), $body['objects']);

        $byType = [];
        foreach ($body['objects'] as $entry) {
            $byType[$entry['type']] = $entry;
        }

        $this->assertArrayHasKey('thirdparty', $byType);
        $this->assertArrayHasKey('subscription', $byType);
        $this->assertTrue($byType['thirdparty']['default_enabled'], 'wave 1 types stay enabled by default');
        $this->assertFalse($byType['subscription']['default_enabled'], 'later waves are opt-in');
        $this->assertArrayHasKey('read', $byType['thirdparty']['rights']);
        $this->assertArrayHasKey('delete', $byType['thirdparty']['rights']);
    }

    // =========================================================================
    // Table keyed on 'id' instead of 'rowid' (llx_actioncomm)
    // =========================================================================

    /**
     * An agenda event must come back through its dm* mapper, with a usable id.
     * The engine read $row->rowid, which does not exist on llx_actioncomm: the
     * id landed at 0, the mapper was skipped and the raw SQL row was served.
     */
    public function testPullMapsIdKeyedRowsThroughTheirMapper(): void
    {
        $this->registerClient();
        $eventId = $this->createAgendaEvent('Sync id-keyed event');

        list($body, $code) = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'agenda_event',
            'limit' => 50,
        ]);

        $this->assertSame(200, $code, json_encode($body));

        $ids = array_map(static function ($item) {
            return (int) ($item['id'] ?? 0);
        }, $body['updated']);

        $this->assertContains($eventId, $ids, 'the created event must be part of the pull');

        $mine = null;
        foreach ($body['updated'] as $item) {
            if ((int) ($item['id'] ?? 0) === $eventId) {
                $mine = $item;
            }
        }
        $this->assertNotNull($mine);
        // The raw-cast fallback leaks internal SQL columns the mapper never
        // exposes; its absence is what proves the mapper path ran.
        $this->assertArrayNotHasKey('fk_user_author', $mine, 'raw SQL row served instead of the mapper output');
    }

    /**
     * The keyset cursor is built from the primary key. On llx_actioncomm it
     * used to encode id 0, so the next page restarted from the beginning: an
     * infinite loop for any client paging an agenda.
     */
    public function testPullCursorCarriesTheIdKeyedPrimaryKey(): void
    {
        $this->registerClient();
        $this->createAgendaEvent('Cursor page one');
        $this->createAgendaEvent('Cursor page two');

        list($body, $code) = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'agenda_event',
            'limit' => 1,
        ]);

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['has_more'], 'two events, limit 1: there must be a next page');
        $this->assertArrayHasKey('next_cursor', $body);
        $this->assertCount(1, $body['updated']);
        $firstPageId = (int) $body['updated'][0]['id'];

        $decoded = hex2bin($body['next_cursor']);
        $this->assertIsString($decoded);
        $separator = strrpos($decoded, '|');
        $this->assertNotFalse($separator);
        $cursorId = (int) substr($decoded, $separator + 1);

        $this->assertGreaterThan(0, $cursorId, 'the cursor must carry the row id, not 0');
        $this->assertSame($firstPageId, $cursorId, 'the cursor must point at the last delivered row');

        // What the id 0 cursor really broke: the second page replayed the first.
        list($next, $nextCode) = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'agenda_event',
            'limit' => 1,
            'cursor' => $body['next_cursor'],
        ]);

        $this->assertSame(200, $nextCode, json_encode($next));
        $this->assertCount(1, $next['updated']);
        $this->assertNotSame(
            $firstPageId,
            (int) $next['updated'][0]['id'],
            'paging with the cursor must move forward, not replay page one'
        );
    }

    /**
     * A push update on an id-keyed table used to answer "Object not found":
     * the lock query read "WHERE rowid = ..." on a table that has no such
     * column, so the row was never seen.
     */
    public function testPushUpdateFindsAnIdKeyedRow(): void
    {
        $this->registerClient();
        $eventId = $this->createAgendaEvent('Push update target');

        list($body, $code) = $this->controller->push([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'agenda_event',
            'changes' => [[
                'action' => 'update',
                'id' => $eventId,
                'data' => ['label' => 'Push update applied'],
            ]],
        ]);

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], $body['errors'], 'the row must be found: ' . json_encode($body['errors']));
        $this->assertContains($eventId, $body['success']);
    }

    // =========================================================================
    // Tables with no entity column
    // =========================================================================

    /**
     * llx_subscription has no entity column, so the tenant frontier comes from
     * dmSubscription::isolationWhereSql(), which walks up to the member. A
     * subscription whose member belongs to another entity must not be pulled.
     */
    public function testPullIsolatesEntitylessRowsThroughTheirMapper(): void
    {
        $this->registerClient();

        $mineMember = $this->createMember((int) $this->currentEntity());
        $foreignMember = $this->createMember(99);
        $mineSubscription = $this->createSubscription($mineMember);
        $foreignSubscription = $this->createSubscription($foreignMember);

        list($body, $code) = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'subscription',
            'limit' => 100,
        ]);

        $this->assertSame(200, $code, json_encode($body));

        $ids = array_map(static function ($item) {
            return (int) ($item['id'] ?? 0);
        }, $body['updated']);

        $this->assertContains($mineSubscription, $ids, 'own subscription must be pulled');
        $this->assertNotContains($foreignSubscription, $ids, 'another entity subscription must never be pulled');
    }

    /**
     * Same frontier on the write side. processUpdate only tested a row->entity
     * property; on a table that has none it simply let the write through, which
     * is fail-open on exactly the types that cannot be checked that way.
     */
    public function testPushRefusesToUpdateAnEntitylessRowOfAnotherTenant(): void
    {
        $this->registerClient();

        $foreignMember = $this->createMember(99);
        $foreignSubscription = $this->createSubscription($foreignMember);

        list($body, $code) = $this->controller->push([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'subscription',
            'changes' => [[
                'action' => 'update',
                'id' => $foreignSubscription,
                'data' => ['note_public' => 'crossed the tenant frontier'],
            ]],
        ]);

        $this->assertSame(200, $code);
        $this->assertSame([], $body['success'], 'a cross-tenant update must not succeed');
        $this->assertNotEmpty($body['errors']);
        $this->assertSame('Object not found', $body['errors'][0]['error'], 'and must not reveal the row exists');
    }

    // =========================================================================
    // Tombstones
    // =========================================================================

    /**
     * Tombstones were filtered on the table name alone, so a client of entity A
     * was told which object ids another entity had deleted.
     */
    public function testPullOnlyReturnsTombstonesOfReachableEntities(): void
    {
        $this->registerClient();

        $this->insertTombstone('societe', 4242, (int) $this->currentEntity());
        $this->insertTombstone('societe', 4243, 99);
        $this->insertTombstone('societe', 4244, null);

        list($body, $code) = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'thirdparty',
            'limit' => 5,
        ]);

        $this->assertSame(200, $code, json_encode($body));

        $deleted = array_map(static function ($row) {
            return (int) $row['id'];
        }, $body['deleted']);

        $this->assertContains(4242, $deleted, 'own entity tombstone must be served');
        $this->assertContains(4244, $deleted, 'legacy rows (entity NULL) stay visible');
        $this->assertNotContains(4243, $deleted, 'another entity tombstone must not leak');
    }

    /**
     * A deletion through push records the entity of the row it removed, which
     * is what makes the filter above possible.
     */
    public function testPushDeleteStampsTheEntityOnTheTombstone(): void
    {
        $this->registerClient();
        $societe = $this->createTestSociete(['name' => 'Tombstone entity ' . uniqid()]);

        list($body, $code) = $this->controller->push([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'thirdparty',
            'changes' => [[
                'action' => 'delete',
                'id' => (int) $societe->id,
            ]],
        ]);

        $this->assertSame(200, $code, json_encode($body));

        $this->assertDatabaseHas('smartauth_sync_tombstones', [
            'table_name' => 'societe',
            'object_id' => (int) $societe->id,
            'entity' => (int) $this->currentEntity(),
        ]);
    }

    // =========================================================================
    // Conflict resolution
    // =========================================================================

    /**
     * Conflict ids are small integers and the resolve route took one with no
     * ownership check: any authenticated client could resolve someone else's
     * conflict, and with resolution=merged write arbitrary data into the
     * underlying object.
     */
    public function testResolveConflictRefusesAConflictOfAnotherUser(): void
    {
        $otherUser = $this->createTestUser(['login' => 'syncowner' . uniqid()]);
        $otherDevice = $this->createDeviceFor((int) $otherUser->id);
        $otherClient = $this->createSyncClientRow($otherDevice, $this->uuid());

        $societe = $this->createTestSociete(['name' => 'Foreign conflict ' . uniqid()]);
        $conflictId = $this->insertConflict($otherClient, 'societe', (int) $societe->id);

        list($body, $code) = $this->controller->resolveConflict([
            'user_id' => $this->testUser->id,
            'id' => $conflictId,
            'resolution' => 'merged',
            'data' => ['nom' => 'Hijacked'],
        ]);

        $this->assertSame(404, $code, json_encode($body));

        $this->assertDatabaseHas('smartauth_sync_conflicts', [
            'rowid' => $conflictId,
            'status' => 'pending',
        ]);

        $unchanged = new \Societe($this->db);
        $unchanged->fetch((int) $societe->id);
        $this->assertNotSame('Hijacked', $unchanged->name, 'the object must not have been written');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function tableExists(string $table): bool
    {
        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . $table . ' LIMIT 1');
        return $resql !== false && $resql !== null;
    }

    private function currentEntity(): int
    {
        global $conf;
        return (int) ($conf->entity ?? 1);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function cleanSyncTables(): void
    {
        foreach ([
            'smartauth_sync_events',
            'smartauth_sync_conflicts',
            'smartauth_sync_tombstones',
            'smartauth_sync_idempotency',
            'smartauth_sync_clients',
        ] as $table) {
            $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . $table);
        }
    }

    private function createDeviceFor(int $userId): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_devices';
        $sql .= ' (ref, fk_user_creat, uuid, label, date_creation, status, entity)';
        $sql .= " VALUES ('TEST-DEV-" . uniqid() . "', " . $userId . ", '" . $this->db->escape($this->uuid()) . "',";
        $sql .= " 'Sync types device', '" . $this->db->idate(time()) . "', 1, " . $this->currentEntity() . ')';

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the test device: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    private function createSyncClientRow(int $deviceId, string $uuid): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_sync_clients';
        $sql .= ' (fk_device, client_uuid, app_version, sync_scope, date_creation, status)';
        $sql .= ' VALUES (' . $deviceId . ", '" . $this->db->escape($uuid) . "', '1.0.0', '{}', '";
        $sql .= $this->db->idate(time()) . "', 1)";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the sync client: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_sync_clients');
    }

    private function registerClient(): int
    {
        $deviceId = $this->createDeviceFor((int) $this->testUser->id);

        list($body, $code) = $this->controller->register([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0',
        ]);

        $this->assertSame(200, $code, 'client registration failed: ' . json_encode($body));
        return (int) $body['client_id'];
    }

    private function createAgendaEvent(string $label): int
    {
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        $event = new \ActionComm($this->db);
        $event->type_code = $this->anActionTypeCode();
        $event->label = $label . ' ' . uniqid();
        $event->datep = dol_now();
        $event->datef = dol_now();
        $event->userownerid = (int) $this->testUser->id;

        $id = $event->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the agenda event: ' . $event->error);

        return (int) $id;
    }

    /**
     * The harness database ships a minimal action dictionary; seed one entry
     * when it is empty so the event creation has a resolvable type.
     */
    private function anActionTypeCode(): string
    {
        $resql = $this->db->query('SELECT code FROM ' . MAIN_DB_PREFIX . 'c_actioncomm ORDER BY id ASC');
        if ($resql && ($row = $this->db->fetch_object($resql))) {
            return (string) $row->code;
        }

        $this->db->query(
            'INSERT INTO ' . MAIN_DB_PREFIX . 'c_actioncomm (id, code, type, libelle, active)'
            . " VALUES (91, 'AC_SYNC_A', 'system', 'AC_SYNC_A', 1)"
        );
        return 'AC_SYNC_A';
    }

    private function createMember(int $entity): int
    {
        $typeId = $this->aMemberType($entity);

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, lastname, firstname, login, statut, datec, tms)';
        $sql .= " VALUES ('M" . uniqid() . "', " . $entity . ', ' . $typeId . ", 'phy', 'Sync', 'Member', 'sync";
        $sql .= uniqid() . "', 1, '" . $this->db->idate(time()) . "', '" . $this->db->idate(time()) . "')";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the member: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }

    /**
     * A member type in the given entity, created on demand: llx_adherent has a
     * NOT NULL fk_adherent_type and the harness database ships no dictionary.
     */
    private function aMemberType(int $entity): int
    {
        $resql = $this->db->query(
            'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'adherent_type WHERE entity = ' . $entity . ' LIMIT 1'
        );
        if ($resql && ($row = $this->db->fetch_object($resql))) {
            return (int) $row->rowid;
        }

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent_type (entity, libelle, morphy, statut, subscription)';
        $sql .= " VALUES (" . $entity . ", 'Sync test type', 'phy', 1, 0)";
        if (!$this->db->query($sql)) {
            $this->fail('could not insert the member type: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent_type');
    }

    private function createSubscription(int $memberId): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'subscription';
        $sql .= ' (fk_adherent, dateadh, datef, subscription, datec, tms)';
        $sql .= ' VALUES (' . $memberId . ", '" . $this->db->idate(time()) . "', '";
        $sql .= $this->db->idate(time() + 86400) . "', 10, '" . $this->db->idate(time()) . "', '";
        $sql .= $this->db->idate(time()) . "')";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the subscription: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'subscription');
    }

    private function insertTombstone(string $table, int $objectId, $entity): void
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_sync_tombstones';
        $sql .= ' (table_name, object_id, deleted_at, deleted_by, entity)';
        $sql .= " VALUES ('" . $this->db->escape($table) . "', " . $objectId . ", '";
        $sql .= $this->db->idate(time()) . "', " . (int) $this->testUser->id . ', ';
        $sql .= ($entity === null ? 'NULL' : (int) $entity) . ')';

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the tombstone: ' . $this->db->lasterror());
        }
    }

    private function insertConflict(int $clientId, string $table, int $objectId): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_sync_conflicts';
        $sql .= ' (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, status, date_creation)';
        $sql .= ' VALUES (' . $clientId . ", '" . $this->db->escape($table) . "', " . $objectId . ',';
        $sql .= " '{\"nom\":\"Client\"}', '{\"nom\":\"Server\"}', '" . $this->db->idate(time() - 3600) . "', '";
        $sql .= $this->db->idate(time()) . "', 'pending', '" . $this->db->idate(time()) . "')";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the conflict: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_sync_conflicts');
    }
}
