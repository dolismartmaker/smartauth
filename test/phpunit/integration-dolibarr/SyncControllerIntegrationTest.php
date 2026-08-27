<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SyncController.php';
require_once __DIR__ . '/../../../api/InputSanitizer.php';

use SmartAuth\Api\SyncController;

/**
 * Integration tests for SyncController with real Dolibarr database
 *
 * @covers \SmartAuth\Api\SyncController
 */
class SyncControllerIntegrationTest extends DolibarrRealTestCase
{
    private SyncController $controller;
    private string $testClientUUID;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new SyncController();
        $this->testClientUUID = $this->generateUUID();

        // Clean sync tables
        $this->cleanSyncTables();
    }

    protected function tearDown(): void
    {
        $this->cleanSyncTables();
        parent::tearDown();
    }

    /**
     * Clean sync-specific tables
     */
    private function cleanSyncTables(): void
    {
        $tables = [
            'smartauth_sync_events',
            'smartauth_sync_conflicts',
            'smartauth_sync_tombstones',
            'smartauth_sync_idempotency',
            'smartauth_sync_clients'
        ];

        foreach ($tables as $table) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table);
        }
    }

    /**
     * Generate a valid UUID
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Create a test device for sync client
     */
    private function createSyncTestDevice(): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (ref, fk_user_creat, uuid, label, date_creation, status, entity)";
        $sql .= " VALUES (";
        $sql .= "'TEST-DEV-" . uniqid() . "', ";
        $sql .= (int) $this->testUser->id . ", ";
        $sql .= "'" . $this->db->escape($this->generateUUID()) . "', ";
        $sql .= "'Test Device', ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= "1, ";
        $sql .= "1)";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \RuntimeException('Failed to insert sync test device: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    /**
     * Register a sync client and return its ID
     */
    private function registerSyncClient(int $deviceId): int
    {
        $result = $this->controller->register([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0'
        ]);

        $this->assertEquals(200, $result[1], 'Failed to register sync client');
        return $result[0]['client_id'];
    }

    // =========================================================================
    // Register endpoint tests
    // =========================================================================

    /**
     * Test register creates a new sync client
     */
    public function testRegisterCreatesNewSyncClient(): void
    {
        $deviceId = $this->createSyncTestDevice();

        $result = $this->controller->register([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0',
            'sync_scope' => ['thirdparty', 'contact']
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('client_id', $result[0]);
        $this->assertEquals($this->testClientUUID, $result[0]['client_uuid']);

        // Verify in database
        $this->assertDatabaseHas('smartauth_sync_clients', [
            'client_uuid' => $this->testClientUUID,
            'fk_device' => $deviceId
        ]);

        // Verify event was logged
        $this->assertDatabaseHas('smartauth_sync_events', [
            'fk_client' => $result[0]['client_id'],
            'event_type' => 'register'
        ]);
    }

    /**
     * Test register updates existing client
     */
    public function testRegisterUpdatesExistingClient(): void
    {
        $deviceId = $this->createSyncTestDevice();

        // First registration
        $result1 = $this->controller->register([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0'
        ]);

        $clientId = $result1[0]['client_id'];

        // Second registration with same UUID
        $result2 = $this->controller->register([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '2.0.0'
        ]);

        $this->assertEquals(200, $result2[1]);
        $this->assertEquals($clientId, $result2[0]['client_id']);

        // Verify only one client exists
        $count = $this->getTableCount('smartauth_sync_clients', [
            'client_uuid' => $this->testClientUUID
        ]);
        $this->assertEquals(1, $count);
    }

    /**
     * A client_uuid owned by ANOTHER user must not be re-bindable at
     * registration time: the attacker gets a 409, the victim's row keeps
     * its device and the attacker inherits nothing (audit S-5).
     */
    public function testRegisterRefusesForeignClientUuid(): void
    {
        $victimDeviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($victimDeviceId);

        $attacker = $this->createTestUser(['login' => 'syncattacker_' . uniqid()]);
        $attackerDeviceId = $this->createSyncTestDeviceForUser((int) $attacker->id);

        $result = $this->controller->register([
            'user_id' => (int) $attacker->id,
            'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $attackerDeviceId,
            'app_version' => '1.0.0',
        ]);

        $this->assertEquals(409, $result[1], 'registering another user client_uuid must be refused');
        $this->assertArrayHasKey('error', $result[0]);

        // The victim's row is untouched: same client, same device binding.
        $sql = "SELECT fk_device, status FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients"
            . " WHERE rowid = " . (int) $clientId;
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql);
        $row = $this->db->fetch_object($resql);
        $this->assertEquals($victimDeviceId, (int) $row->fk_device, 'victim device binding must not be stolen');
        $this->assertEquals(1, (int) $row->status);
    }

    /**
     * Re-registering one's OWN client from a NEW device of the same user
     * stays allowed (device upgrade flow): the row follows the user's new
     * device.
     */
    public function testRegisterRebindsOwnClientToNewOwnDevice(): void
    {
        $oldDeviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($oldDeviceId);

        $newDeviceId = $this->createSyncTestDevice();

        $result = $this->controller->register([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $newDeviceId,
            'app_version' => '2.0.0',
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals($clientId, $result[0]['client_id']);

        $sql = "SELECT fk_device FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients"
            . " WHERE rowid = " . (int) $clientId;
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql);
        $this->assertEquals($newDeviceId, (int) $this->db->fetch_object($resql)->fk_device);
    }

    /**
     * Create a smartauth_devices row owned by an arbitrary user (the shared
     * helper is hardwired to the test admin).
     */
    private function createSyncTestDeviceForUser(int $userId): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (ref, fk_user_creat, uuid, label, date_creation, status, entity)";
        $sql .= " VALUES (";
        $sql .= "'TEST-DEV-" . uniqid() . "', ";
        $sql .= (int) $userId . ", ";
        $sql .= "'" . $this->db->escape($this->generateUUID()) . "', ";
        $sql .= "'Attacker Device', ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= "1, ";
        $sql .= "1)";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \RuntimeException('Failed to insert sync test device: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    // =========================================================================
    // Push endpoint tests
    // =========================================================================

    /**
     * A replayed 'create' (the client retried after a lost 2xx) must return
     * the original server_id and must NOT create a duplicate object.
     */
    public function testPushCreateIsIdempotentOnReplay(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $tempId = 'tmp-' . uniqid();
        $name = 'Idempotent Co ' . uniqid();
        $payload = [
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes' => [
                ['action' => 'create', 'temp_id' => $tempId, 'data' => ['name' => $name]],
            ],
        ];

        // First push creates the object and maps temp_id -> server_id
        $r1 = $this->controller->push($payload);
        $this->assertEquals(200, $r1[1]);
        $serverId = $r1[0]['id_mapping'][$tempId] ?? null;
        $this->assertNotNull($serverId, 'First push must map temp_id to a server id');

        // Replay the exact same change (lost-response retry)
        $r2 = $this->controller->push($payload);
        $this->assertEquals(200, $r2[1]);
        $this->assertEmpty($r2[0]['errors'] ?? [], 'Replay must not error');
        $this->assertEquals(
            $serverId,
            $r2[0]['id_mapping'][$tempId] ?? null,
            'Replay must return the original server id'
        );

        // No duplicate object: exactly one thirdparty with this unique name
        $count = $this->getTableCount('societe', ['nom' => $name]);
        $this->assertEquals(1, $count, 'Replay must not create a duplicate thirdparty');
    }

    // =========================================================================
    // Pull endpoint tests
    // =========================================================================

    /**
     * Test pull returns empty arrays for fresh client
     */
    public function testPullReturnsEmptyForFreshClient(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('updated', $result[0]);
        $this->assertArrayHasKey('deleted', $result[0]);
        $this->assertArrayHasKey('server_time', $result[0]);
    }

    /**
     * Test pull returns updated thirdparties
     */
    public function testPullReturnsUpdatedThirdparties(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // Create a thirdparty
        $societe = $this->createTestSociete([
            'name' => 'Sync Test Company'
        ]);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['updated']);

        // Find our created company
        $found = false;
        foreach ($result[0]['updated'] as $item) {
            if (($item['nom'] ?? $item['name'] ?? '') === 'Sync Test Company') {
                $found = true;
                $this->assertEquals($societe->id, $item['id']);
                break;
            }
        }
        $this->assertTrue($found, 'Created thirdparty not found in pull results');
    }

    /**
     * Test pull with last_sync_at filters results
     */
    public function testPullWithLastSyncAtFiltersResults(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // Create a thirdparty
        $societe = $this->createTestSociete([
            'name' => 'Old Company'
        ]);

        // Set last_sync_at to future
        $futureTime = date('c', strtotime('+1 hour'));

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'last_sync_at' => $futureTime
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEmpty($result[0]['updated']);
    }

    /**
     * Test pull returns deleted objects from tombstones
     */
    public function testPullReturnsTombstones(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create a tombstone manually
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
        $sql .= " (table_name, object_id, deleted_at, deleted_by)";
        $sql .= " VALUES ('societe', 999, '" . $this->db->idate(time()) . "', " . $this->testUser->id . ")";
        $this->db->query($sql);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['deleted']);
        $this->assertEquals(999, $result[0]['deleted'][0]['id']);
    }

    // =========================================================================
    // Status endpoint tests
    // =========================================================================

    /**
     * Test status returns correct client info
     */
    public function testStatusReturnsClientInfo(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $result = $this->controller->status([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals($this->testClientUUID, $result[0]['client_uuid']);
        $this->assertEquals(0, $result[0]['pending_conflicts']);
        $this->assertArrayHasKey('server_time', $result[0]);
        $this->assertArrayHasKey('sync_scope', $result[0]);
    }

    /**
     * Test status returns correct pending conflicts count
     */
    public function testStatusReturnsPendingConflictsCount(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create some conflicts
        for ($i = 0; $i < 3; $i++) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
            $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, status, date_creation)";
            $sql .= " VALUES (";
            $sql .= $clientId . ", 'societe', " . ($i + 1) . ", ";
            $sql .= "'{\"nom\":\"Client\"}', '{\"nom\":\"Server\"}', ";
            $sql .= "'" . $this->db->idate(time()) . "', '" . $this->db->idate(time()) . "', ";
            $sql .= "'pending', '" . $this->db->idate(time()) . "')";
            $this->db->query($sql);
        }

        $result = $this->controller->status([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(3, $result[0]['pending_conflicts']);
    }

    // =========================================================================
    // Conflicts endpoint tests
    // =========================================================================

    /**
     * Test conflicts returns empty list when no conflicts
     */
    public function testConflictsReturnsEmptyWhenNone(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $result = $this->controller->conflicts([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEmpty($result[0]['conflicts']);
    }

    /**
     * Test conflicts returns pending conflicts
     */
    public function testConflictsReturnsPendingConflicts(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create a conflict
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, field_conflicts, status, date_creation)";
        $sql .= " VALUES (";
        $sql .= $clientId . ", 'societe', 42, ";
        $sql .= "'{\"nom\":\"Client Version\"}', '{\"nom\":\"Server Version\"}', ";
        $sql .= "'" . $this->db->idate(time() - 3600) . "', '" . $this->db->idate(time()) . "', ";
        $sql .= "'{\"nom\":{\"client\":\"Client Version\",\"server\":\"Server Version\"}}', ";
        $sql .= "'pending', '" . $this->db->idate(time()) . "')";
        $this->db->query($sql);

        $result = $this->controller->conflicts([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertCount(1, $result[0]['conflicts']);
        $this->assertEquals(42, $result[0]['conflicts'][0]['object_id']);
        $this->assertEquals('societe', $result[0]['conflicts'][0]['table_name']);
        $this->assertArrayHasKey('field_conflicts', $result[0]['conflicts'][0]);
    }

    // =========================================================================
    // Resolve conflict endpoint tests
    // =========================================================================

    /**
     * Test resolveConflict with server resolution
     */
    public function testResolveConflictWithServerResolution(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create a real thirdparty
        $societe = $this->createTestSociete([
            'name' => 'Original Name'
        ]);

        // Create a conflict for this thirdparty
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, status, date_creation)";
        $sql .= " VALUES (";
        $sql .= $clientId . ", 'societe', " . $societe->id . ", ";
        $sql .= "'{\"nom\":\"Client Name\"}', '{\"nom\":\"Original Name\"}', ";
        $sql .= "'" . $this->db->idate(time() - 3600) . "', '" . $this->db->idate(time()) . "', ";
        $sql .= "'pending', '" . $this->db->idate(time()) . "')";
        $this->db->query($sql);
        $conflictId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_sync_conflicts');

        // Resolve with server version
        $result = $this->controller->resolveConflict([
            'user_id' => $this->testUser->id,
            'id' => $conflictId,
            'resolution' => 'server'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertTrue($result[0]['success']);

        // Verify conflict is marked as resolved
        $this->assertDatabaseHas('smartauth_sync_conflicts', [
            'rowid' => $conflictId,
            'status' => 'resolved',
            'resolution' => 'server'
        ]);
    }

    /**
     * Test resolveConflict with client resolution
     */
    public function testResolveConflictWithClientResolution(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create a real thirdparty
        $societe = $this->createTestSociete([
            'name' => 'Original Name'
        ]);

        // Create a conflict
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, status, date_creation)";
        $sql .= " VALUES (";
        $sql .= $clientId . ", 'societe', " . $societe->id . ", ";
        $sql .= "'{\"name\":\"Client Name\"}', '{\"name\":\"Original Name\"}', ";
        $sql .= "'" . $this->db->idate(time() - 3600) . "', '" . $this->db->idate(time()) . "', ";
        $sql .= "'pending', '" . $this->db->idate(time()) . "')";
        $this->db->query($sql);
        $conflictId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_sync_conflicts');

        // Resolve with client version
        $result = $this->controller->resolveConflict([
            'user_id' => $this->testUser->id,
            'id' => $conflictId,
            'resolution' => 'client'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertTrue($result[0]['success']);

        // Verify thirdparty was updated
        $updatedSociete = new \Societe($this->db);
        $updatedSociete->fetch($societe->id);
        $this->assertEquals('Client Name', $updatedSociete->name);
    }

    /**
     * Test resolveConflict with merged resolution
     */
    public function testResolveConflictWithMergedResolution(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Create a real thirdparty
        $societe = $this->createTestSociete([
            'name' => 'Original Name'
        ]);

        // Create a conflict
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, status, date_creation)";
        $sql .= " VALUES (";
        $sql .= $clientId . ", 'societe', " . $societe->id . ", ";
        $sql .= "'{\"name\":\"Client Name\"}', '{\"name\":\"Original Name\"}', ";
        $sql .= "'" . $this->db->idate(time() - 3600) . "', '" . $this->db->idate(time()) . "', ";
        $sql .= "'pending', '" . $this->db->idate(time()) . "')";
        $this->db->query($sql);
        $conflictId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_sync_conflicts');

        // Resolve with merged data
        $result = $this->controller->resolveConflict([
            'user_id' => $this->testUser->id,
            'id' => $conflictId,
            'resolution' => 'merged',
            'data' => [
                'name' => 'Merged Name'
            ]
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertTrue($result[0]['success']);

        // Verify thirdparty was updated with merged data
        $updatedSociete = new \Societe($this->db);
        $updatedSociete->fetch($societe->id);
        $this->assertEquals('Merged Name', $updatedSociete->name);

        // Verify conflict status
        $this->assertDatabaseHas('smartauth_sync_conflicts', [
            'rowid' => $conflictId,
            'status' => 'resolved',
            'resolution' => 'merged'
        ]);
    }

    // =========================================================================
    // Event logging tests
    // =========================================================================

    /**
     * Test that events are logged for all operations
     */
    public function testEventsAreLoggedForOperations(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $clientId = $this->registerSyncClient($deviceId);

        // Initial count (register event)
        $initialCount = $this->getTableCount('smartauth_sync_events', [
            'fk_client' => $clientId
        ]);
        $this->assertEquals(1, $initialCount); // register event

        // Pull operation
        $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $pullCount = $this->getTableCount('smartauth_sync_events', [
            'fk_client' => $clientId,
            'event_type' => 'pull'
        ]);
        $this->assertEquals(1, $pullCount);

        // Status operation (no event logged for status)
        $this->controller->status([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID
        ]);

        // Total should be 2 (register + pull)
        $totalCount = $this->getTableCount('smartauth_sync_events', [
            'fk_client' => $clientId
        ]);
        $this->assertEquals(2, $totalCount);
    }

    // =========================================================================
    // Edge cases and error handling
    // =========================================================================

    /**
     * Test pull with invalid object type
     */
    public function testPullWithInvalidObjectType(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'invalid_type'
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('object_type', $result[0]['error']);
    }

    /**
     * Test operations with non-existent client
     */
    public function testOperationsWithNonExistentClient(): void
    {
        $fakeUUID = $this->generateUUID();

        // Pull
        $pullResult = $this->controller->pull([
            'client_uuid' => $fakeUUID,
            'object_type' => 'thirdparty'
        ]);
        $this->assertEquals(404, $pullResult[1]);

        // Status
        $statusResult = $this->controller->status([
            'user_id' => $this->testUser->id, 'client_uuid' => $fakeUUID
        ]);
        $this->assertEquals(404, $statusResult[1]);

        // Conflicts
        $conflictsResult = $this->controller->conflicts([
            'user_id' => $this->testUser->id, 'client_uuid' => $fakeUUID
        ]);
        $this->assertEquals(404, $conflictsResult[1]);
    }

    /**
     * Test resolve non-existent conflict
     */
    public function testResolveNonExistentConflict(): void
    {
        $result = $this->controller->resolveConflict([
            'id' => 99999,
            'resolution' => 'server'
        ]);

        $this->assertEquals(404, $result[1]);
    }

    // =========================================================================
    // Linked files (ECM) in pull tests
    // =========================================================================

    /**
     * Insert a test ECM file linked to an object
     */
    private function insertEcmFile(int $objectId, string $element, array $data = []): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " (label, entity, filename, filepath, src_object_type, src_object_id,";
        $sql .= " date_c, gen_or_uploaded, share, description, keywords, position)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($data['label'] ?? md5(uniqid())) . "', ";
        $sql .= (int) ($data['entity'] ?? 1) . ", ";
        $sql .= "'" . $this->db->escape($data['filename'] ?? 'test_' . uniqid() . '.pdf') . "', ";
        $sql .= "'" . $this->db->escape($data['filepath'] ?? $element . '/' . $objectId) . "', ";
        $sql .= "'" . $this->db->escape($element) . "', ";
        $sql .= (int) $objectId . ", ";
        $sql .= "'" . $this->db->escape($data['date_c'] ?? date('Y-m-d H:i:s')) . "', ";
        $sql .= "'" . $this->db->escape($data['gen_or_uploaded'] ?? 'uploaded') . "', ";
        $sql .= isset($data['share']) ? "'" . $this->db->escape($data['share']) . "'" : "NULL";
        $sql .= ", ";
        $sql .= isset($data['description']) ? "'" . $this->db->escape($data['description']) . "'" : "NULL";
        $sql .= ", ";
        $sql .= isset($data['keywords']) ? "'" . $this->db->escape($data['keywords']) . "'" : "NULL";
        $sql .= ", ";
        $sql .= (int) ($data['position'] ?? 0);
        $sql .= ")";

        $result = $this->db->query($sql);
        $this->assertNotFalse($result, "Failed to insert ECM file: " . $this->db->lasterror());

        return $this->db->last_insert_id(MAIN_DB_PREFIX . 'ecm_files');
    }

    private function cleanEcmFiles(): void
    {
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "ecm_files");
    }

    /**
     * Test that pull response includes nb_linked_files for each object
     */
    public function testPullIncludesNbLinkedFiles(): void
    {
        $this->cleanEcmFiles();

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // Create a societe with linked files
        $societe = $this->createTestSociete(['name' => 'Company With Files']);
        $this->insertEcmFile($societe->id, 'societe', ['filename' => 'doc1.pdf']);
        $this->insertEcmFile($societe->id, 'societe', ['filename' => 'doc2.pdf']);

        // Create another societe without files
        $societe2 = $this->createTestSociete(['name' => 'Company Without Files']);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['updated']);

        // Find both companies in results
        $withFiles = null;
        $withoutFiles = null;
        foreach ($result[0]['updated'] as $obj) {
            if (($obj['id'] ?? null) == $societe->id) {
                $withFiles = $obj;
            }
            if (($obj['id'] ?? null) == $societe2->id) {
                $withoutFiles = $obj;
            }
        }

        $this->assertNotNull($withFiles, 'Company with files should be in pull results');
        $this->assertArrayHasKey('nb_linked_files', $withFiles);
        $this->assertEquals(2, $withFiles['nb_linked_files']);
        $this->assertArrayNotHasKey('linked_files', $withFiles, 'linked_files should not be present without with_files param');

        $this->assertNotNull($withoutFiles, 'Company without files should be in pull results');
        $this->assertArrayHasKey('nb_linked_files', $withoutFiles);
        $this->assertEquals(0, $withoutFiles['nb_linked_files']);
    }

    /**
     * Test that pull with with_files=1 includes the full file list
     */
    public function testPullWithFilesIncludesLinkedFilesList(): void
    {
        $this->cleanEcmFiles();

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $societe = $this->createTestSociete(['name' => 'Company For File List']);
        $this->insertEcmFile($societe->id, 'societe', [
            'filename' => 'invoice.pdf',
            'share' => 'token123',
            'gen_or_uploaded' => 'generated',
        ]);

        $result = $this->controller->pull([
            'user_id' => $this->testUser->id, 'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'with_files' => '1',
        ]);

        $this->assertEquals(200, $result[1]);

        $found = null;
        foreach ($result[0]['updated'] as $obj) {
            if (($obj['id'] ?? null) == $societe->id) {
                $found = $obj;
                break;
            }
        }

        $this->assertNotNull($found, 'Company should be in pull results');
        $this->assertArrayHasKey('nb_linked_files', $found);
        $this->assertEquals(1, $found['nb_linked_files']);
        $this->assertArrayHasKey('linked_files', $found);
        $this->assertIsArray($found['linked_files']);
        $this->assertCount(1, $found['linked_files']);
        $this->assertEquals('invoice.pdf', $found['linked_files'][0]['filename']);
        $this->assertEquals('token123', $found['linked_files'][0]['share']);
        $this->assertEquals('generated', $found['linked_files'][0]['type']);
    }

    /**
     * Test that syncableObjects all have element key
     */
    public function testSyncableObjectsAllHaveElementKey(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $prop = $reflection->getProperty('syncableObjects');
        $prop->setAccessible(true);
        $objects = $prop->getValue($this->controller);

        foreach ($objects as $type => $config) {
            $this->assertArrayHasKey('element', $config, "syncableObject '$type' should have 'element' key");
            $this->assertNotEmpty($config['element'], "syncableObject '$type' element should not be empty");
        }
    }

    // =========================================================================
    //  Push contract: api keys, FK validation, unknown-key filtering
    //  These tests pin down the contract established by the
    //  SyncController -> dm* migration (see SPEC_SMARTAUTH_AUTHORIZATION
    //  section 8.2 invariant I-1).
    // =========================================================================

    /**
     * The client sends API key names (eg 'name'), not Dolibarr column
     * names ('nom'). Push must route them through dmThirdparty to
     * resolve to the right PHP property; Societe::create then writes
     * the SQL column 'nom'.
     */
    public function testPushCreateRespectsApiKeyNames(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $apiName = 'API-Push-' . uniqid();
        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [
                [
                    'action' => 'create',
                    'temp_id' => 'tmp-1',
                    'data'   => [
                        'name'  => $apiName,
                        'email' => 'push-' . uniqid() . '@example.test',
                    ],
                ],
            ],
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['success'], 'create should succeed');
        $newId = (int) $result[0]['success'][0];
        $this->assertGreaterThan(0, $newId);

        // Verify the SQL column 'nom' was written from the api key 'name'.
        $resql = $this->db->query(
            'SELECT nom, email FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid = ' . $newId
        );
        $this->assertNotFalse($resql);
        $row = $this->db->fetch_object($resql);
        $this->assertNotNull($row, 'created row must exist');
        $this->assertSame(
            $apiName,
            $row->nom,
            'api key "name" must end up in SQL column "nom" (via $this->name)'
        );
    }

    /**
     * The mapper accepts 'country' (-> fk_pays) but the FK target must
     * exist in c_country. A push pointing at a non-existent country id
     * must NOT store the orphan FK on the resulting row.
     */
    public function testPushCreateRejectsForeignKeyToMissingTarget(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // Pick a FK target that does not exist in c_country.
        $orphanCountryId = 999999;
        $resql = $this->db->query(
            'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'c_country WHERE rowid = ' . $orphanCountryId
        );
        $this->assertNotFalse($resql);
        $this->assertSame(0, (int) $this->db->num_rows($resql), 'precondition: orphan id must be absent');

        $apiName = 'FK-Reject-' . uniqid();
        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [
                [
                    'action' => 'create',
                    'temp_id' => 'tmp-1',
                    'data'   => [
                        'name'    => $apiName,
                        'country' => $orphanCountryId,
                    ],
                ],
            ],
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty(
            $result[0]['success'],
            'create still succeeds; only the FK assignment is dropped'
        );
        $newId = (int) $result[0]['success'][0];

        // The created row must NOT carry the orphan fk_pays.
        $resql = $this->db->query(
            'SELECT nom, fk_pays FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid = ' . $newId
        );
        $row = $this->db->fetch_object($resql);
        $this->assertSame($apiName, $row->nom);
        $this->assertNotEquals(
            $orphanCountryId,
            (int) $row->fk_pays,
            'fk_pays must not point at a non-existent c_country row'
        );
    }

    /**
     * Unknown api keys must be silently dropped (with a LOG_WARNING),
     * not propagated to the Dolibarr object. The current writableFields
     * of dmThirdparty does not declare 'reputation', so it must be
     * filtered out without aborting the create.
     */
    public function testPushCreateFiltersUnknownApiKeys(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $apiName = 'Unknown-Key-' . uniqid();
        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [
                [
                    'action' => 'create',
                    'temp_id' => 'tmp-1',
                    'data'   => [
                        'name'             => $apiName,
                        'reputation'       => 'champion',
                        'arbitrary_secret' => 'should never reach Societe',
                    ],
                ],
            ],
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['success']);
        $newId = (int) $result[0]['success'][0];

        // Defensive: 'reputation' is also a Dolibarr column on
        // llx_societe in some versions; make sure the unknown api key
        // did not bleed through.
        $resql = $this->db->query(
            'SELECT * FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid = ' . $newId
        );
        $row = $this->db->fetch_object($resql);
        $this->assertSame($apiName, $row->nom);
        // The exact set of columns depends on the schema, so we only
        // assert that no value our payload tried to inject ever shows
        // up. 'champion' came in via 'reputation', 'arbitrary_secret'
        // via a wholly fictitious key.
        foreach ((array) $row as $colName => $colValue) {
            if ($colName === 'nom') {
                continue;
            }
            $this->assertNotSame(
                'champion',
                (string) $colValue,
                "Unknown api key 'reputation' value leaked into column '$colName'"
            );
            $this->assertNotSame(
                'should never reach Societe',
                (string) $colValue,
                "Wholly-fictitious api key value leaked into column '$colName'"
            );
        }
    }

    // =========================================================================
    // Pull business filter (pull_where) and pagination tests
    // =========================================================================

    /**
     * Ref prefix identifying products created by these tests, cleaned in
     * tearDown so reruns and sibling tests never see leftover rows.
     */
    private const PULL_TEST_REF_PREFIX = 'SYNCPULL-';

    /**
     * All pull filter/pagination test rows carry a tms in 2030+ and every
     * pull scopes with this last_sync_at, so rows created by other tests
     * (tms = now) can never pollute the assertions.
     */
    private const PULL_TEST_LAST_SYNC = '2029-12-31 23:59:59';

    /**
     * Insert a raw product row with full control over tosell/tms/entity.
     */
    private function createSyncTestProduct(int $tosell, string $tms, int $entity = 1): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "product";
        $sql .= " (ref, label, entity, tosell, tobuy, fk_product_type, datec, tms)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape(self::PULL_TEST_REF_PREFIX . uniqid()) . "', ";
        $sql .= "'Sync pull test product', ";
        $sql .= (int) $entity . ", ";
        $sql .= (int) $tosell . ", 0, 0, ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= "'" . $this->db->escape($tms) . "')";

        if (!$this->db->query($sql)) {
            throw new \RuntimeException('Failed to insert test product: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'product');
    }

    private function cleanSyncTestProducts(): void
    {
        $this->db->query(
            "DELETE FROM " . MAIN_DB_PREFIX . "product"
            . " WHERE ref LIKE '" . $this->db->escape(self::PULL_TEST_REF_PREFIX) . "%'"
        );
    }

    /**
     * Inject a pull_where clause on an object type, as a module hook would
     * declare it. Reflection keeps the test independent from a hook fixture.
     */
    private function injectPullWhere(string $objectType, string $clause): void
    {
        $prop = new \ReflectionProperty(SyncController::class, 'syncableObjects');
        $prop->setAccessible(true);
        $objects = $prop->getValue($this->controller);
        $objects[$objectType]['pull_where'] = $clause;
        $prop->setValue($this->controller, $objects);
    }

    private function pullProducts(array $extra = []): array
    {
        return $this->controller->pull(array_merge([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'product',
            'last_sync_at' => self::PULL_TEST_LAST_SYNC,
        ], $extra));
    }

    private function updatedIds(array $result): array
    {
        return array_map(static function ($item) {
            return (int) $item['id'];
        }, $result[0]['updated']);
    }

    private function deletedIds(array $result): array
    {
        return array_map(static function ($item) {
            return (int) $item['id'];
        }, $result[0]['deleted']);
    }

    /**
     * pull_where must keep matching rows in 'updated' and surface
     * non-matching changed rows as exclusions in 'deleted'.
     */
    public function testPullWhereFiltersNonMatchingRows(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);
        $this->injectPullWhere('product', 'tosell = 1');

        try {
            $sellableId = $this->createSyncTestProduct(1, '2030-01-01 10:00:01');
            $hiddenId = $this->createSyncTestProduct(0, '2030-01-01 10:00:02');

            $result = $this->pullProducts();

            $this->assertEquals(200, $result[1]);
            $this->assertContains($sellableId, $this->updatedIds($result));
            $this->assertNotContains($hiddenId, $this->updatedIds($result));
            // The non-matching changed row is an exclusion: the offline
            // client must prune it.
            $this->assertContains($hiddenId, $this->deletedIds($result));
            $this->assertNotContains($sellableId, $this->deletedIds($result));
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * A row leaving the filter (tosell 1 -> 0, tms bumped) must show up in
     * 'deleted' on the next delta pull so clients drop it.
     */
    public function testPullWhereEmitsExclusionForRowLeavingFilter(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);
        $this->injectPullWhere('product', 'tosell = 1');

        try {
            $productId = $this->createSyncTestProduct(1, '2030-01-01 10:00:01');

            $first = $this->pullProducts();
            $this->assertContains($productId, $this->updatedIds($first));

            // Product becomes non-sellable; tms bumps (as Product::update
            // and setStatus do on a live instance).
            $this->db->query(
                "UPDATE " . MAIN_DB_PREFIX . "product"
                . " SET tosell = 0, tms = '2030-01-02 10:00:00'"
                . " WHERE rowid = " . $productId
            );

            $second = $this->pullProducts(['last_sync_at' => '2030-01-01 12:00:00']);
            $this->assertEquals(200, $second[1]);
            $this->assertNotContains($productId, $this->updatedIds($second));
            $this->assertContains($productId, $this->deletedIds($second));
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Exclusions and updated rows must stay scoped to the caller's entity:
     * a filtered-out row of another entity must never leak its rowid.
     */
    public function testPullWhereExclusionsRespectEntityIsolation(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);
        $this->injectPullWhere('product', 'tosell = 1');

        try {
            $sameEntityId = $this->createSyncTestProduct(0, '2030-01-01 10:00:01', 1);
            $otherEntityId = $this->createSyncTestProduct(0, '2030-01-01 10:00:02', 2);

            $result = $this->pullProducts();

            $this->assertEquals(200, $result[1]);
            $this->assertContains($sameEntityId, $this->deletedIds($result));
            $this->assertNotContains($otherEntityId, $this->deletedIds($result));
            $this->assertNotContains($otherEntityId, $this->updatedIds($result));
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Paging with limit/offset must cover the full set exactly once and
     * flag has_more on every page but the last.
     */
    public function testPullPaginationPagesAreCompleteAndDisjoint(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            $created = [];
            for ($i = 1; $i <= 5; $i++) {
                $created[] = $this->createSyncTestProduct(1, '2030-01-01 10:00:0' . $i);
            }

            $collected = [];
            $page0 = $this->pullProducts(['limit' => 2, 'offset' => 0]);
            $this->assertEquals(200, $page0[1]);
            $this->assertCount(2, $page0[0]['updated']);
            $this->assertTrue($page0[0]['has_more']);
            $collected = array_merge($collected, $this->updatedIds($page0));

            $page1 = $this->pullProducts(['limit' => 2, 'offset' => 2]);
            $this->assertCount(2, $page1[0]['updated']);
            $this->assertTrue($page1[0]['has_more']);
            $collected = array_merge($collected, $this->updatedIds($page1));

            $page2 = $this->pullProducts(['limit' => 2, 'offset' => 4]);
            $this->assertCount(1, $page2[0]['updated']);
            $this->assertFalse($page2[0]['has_more']);
            $collected = array_merge($collected, $this->updatedIds($page2));

            sort($collected);
            sort($created);
            $this->assertSame($created, $collected, 'Pages must union to the full set, no dup, no hole');
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * A legacy call (no limit/offset/pull_where) keeps its historic shape
     * and behavior; has_more is additive and false under the cap.
     */
    public function testPullLegacyCallShapeUnchanged(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            $productId = $this->createSyncTestProduct(1, '2030-01-01 10:00:01');

            $result = $this->pullProducts();

            $this->assertEquals(200, $result[1]);
            $this->assertArrayHasKey('updated', $result[0]);
            $this->assertArrayHasKey('deleted', $result[0]);
            $this->assertArrayHasKey('server_time', $result[0]);
            $this->assertArrayHasKey('has_more', $result[0]);
            $this->assertFalse($result[0]['has_more']);
            $this->assertContains($productId, $this->updatedIds($result));
            $this->assertEmpty($result[0]['deleted']);
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Tombstones (and exclusions) are first-page-only: a paginating client
     * must not receive N copies of the same deletion list.
     */
    public function testPullTombstonesOnlyOnFirstPage(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            for ($i = 1; $i <= 3; $i++) {
                $this->createSyncTestProduct(1, '2030-01-01 10:00:0' . $i);
            }

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
            $sql .= " (table_name, object_id, deleted_at, deleted_by)";
            $sql .= " VALUES ('product', 424242, '2030-01-05 00:00:00', " . (int) $this->testUser->id . ")";
            $this->db->query($sql);

            $page0 = $this->pullProducts(['limit' => 2, 'offset' => 0]);
            $this->assertEquals(200, $page0[1]);
            $this->assertContains(424242, $this->deletedIds($page0));

            $page1 = $this->pullProducts(['limit' => 2, 'offset' => 2]);
            $this->assertEquals(200, $page1[1]);
            $this->assertEmpty($page1[0]['deleted'], 'Tombstones must only be sent on the first page');
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Malformed last_sync_at must be rejected with an explicit 400, never
     * silently fed into the tms comparison.
     */
    public function testPullRejectsMalformedLastSyncAt(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        foreach (["x'; DROP TABLE llx_product; --", 'not-a-date', '2030-13-45', ['array']] as $bad) {
            $result = $this->pullProducts(['last_sync_at' => $bad]);
            $this->assertEquals(400, $result[1], 'Malformed last_sync_at must yield 400');
        }

        // Both accepted shapes still pass: SQL datetime and ISO 8601.
        foreach (['2030-01-01 00:00:00', '2030-01-01T00:00:00+00:00', '2030-01-01T00:00:00Z'] as $good) {
            $result = $this->pullProducts(['last_sync_at' => $good]);
            $this->assertEquals(200, $result[1], 'Valid datetime shape must be accepted: ' . $good);
        }
    }

    /**
     * Out-of-range or non-integer limit/offset must be rejected with 400
     * (clamping would desync the client's offset arithmetic).
     */
    public function testPullRejectsOutOfRangePagination(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        foreach ([0, -1, 1001, 'abc', 2.5] as $badLimit) {
            $result = $this->pullProducts(['limit' => $badLimit]);
            $this->assertEquals(400, $result[1], 'Invalid limit must yield 400: ' . var_export($badLimit, true));
        }

        foreach ([-1, 1000001, 'abc'] as $badOffset) {
            $result = $this->pullProducts(['offset' => $badOffset]);
            $this->assertEquals(400, $result[1], 'Invalid offset must yield 400: ' . var_export($badOffset, true));
        }

        // Boundary values remain accepted.
        $result = $this->pullProducts(['limit' => 1000, 'offset' => 0]);
        $this->assertEquals(200, $result[1]);
        $result = $this->pullProducts(['limit' => '10', 'offset' => '5']);
        $this->assertEquals(200, $result[1], 'Digit strings (JSON via form data) must be accepted');
    }

    /**
     * Non-regression CR-7-style: the read permission gate on pull() must
     * survive the filter/pagination changes (fail-closed).
     */
    public function testPullReadRightGateStillEnforced(): void
    {
        global $user;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $saved = $user->rights->produit->lire;
        $user->rights->produit->lire = 0;
        try {
            $result = $this->pullProducts();
            $this->assertEquals(403, $result[1], 'pull without the read right must be refused');
        } finally {
            $user->rights->produit->lire = $saved;
        }
    }

    /**
     * Keyset (cursor) paging must cover the full set exactly once, expose a
     * next_cursor while more pages remain, and drop it on the last page.
     */
    public function testPullCursorPaginationPagesAreCompleteAndDisjoint(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            $created = [];
            for ($i = 1; $i <= 5; $i++) {
                $created[] = $this->createSyncTestProduct(1, '2030-01-01 10:00:0' . $i);
            }

            $collected = [];

            $page0 = $this->pullProducts(['limit' => 2]);
            $this->assertEquals(200, $page0[1]);
            $this->assertCount(2, $page0[0]['updated']);
            $this->assertTrue($page0[0]['has_more']);
            $this->assertArrayHasKey('next_cursor', $page0[0]);
            $collected = array_merge($collected, $this->updatedIds($page0));

            $page1 = $this->pullProducts(['limit' => 2, 'cursor' => $page0[0]['next_cursor']]);
            $this->assertCount(2, $page1[0]['updated']);
            $this->assertTrue($page1[0]['has_more']);
            $this->assertArrayHasKey('next_cursor', $page1[0]);
            $collected = array_merge($collected, $this->updatedIds($page1));

            $page2 = $this->pullProducts(['limit' => 2, 'cursor' => $page1[0]['next_cursor']]);
            $this->assertCount(1, $page2[0]['updated']);
            $this->assertFalse($page2[0]['has_more']);
            $this->assertArrayNotHasKey('next_cursor', $page2[0], 'No next_cursor on the last page');
            $collected = array_merge($collected, $this->updatedIds($page2));

            sort($collected);
            sort($created);
            $this->assertSame($created, $collected, 'Keyset pages must union to the full set, no dup, no hole');
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Robustness: a row inserted mid-pass (fresh tms, sorts after the cursor)
     * is picked up on the next page, and already-paged rows never reappear.
     * This is the property offset pagination cannot guarantee.
     */
    public function testPullCursorPicksUpRowsInsertedDuringPassWithoutDuplicates(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            $r1 = $this->createSyncTestProduct(1, '2030-01-01 10:00:01');
            $r2 = $this->createSyncTestProduct(1, '2030-01-01 10:00:02');
            $r3 = $this->createSyncTestProduct(1, '2030-01-01 10:00:05');
            $r4 = $this->createSyncTestProduct(1, '2030-01-01 10:00:06');

            $page0 = $this->pullProducts(['limit' => 2]);
            $this->assertSame([$r1, $r2], $this->updatedIds($page0));
            $this->assertTrue($page0[0]['has_more']);

            // New row appears between the cursor (10:00:02) and r3 (10:00:05).
            $r5 = $this->createSyncTestProduct(1, '2030-01-01 10:00:03');

            $page1 = $this->pullProducts(['limit' => 2, 'cursor' => $page0[0]['next_cursor']]);
            $ids1 = $this->updatedIds($page1);
            $this->assertContains($r5, $ids1, 'Mid-pass insert after the cursor must be delivered');
            $this->assertNotContains($r1, $ids1, 'Already-paged rows must not reappear');
            $this->assertNotContains($r2, $ids1, 'Already-paged rows must not reappear');

            // Drain the rest and check the union has every row exactly once.
            $collected = array_merge($this->updatedIds($page0), $ids1);
            $cursor = $page1[0]['next_cursor'] ?? null;
            while ($cursor !== null) {
                $page = $this->pullProducts(['limit' => 2, 'cursor' => $cursor]);
                $collected = array_merge($collected, $this->updatedIds($page));
                $cursor = $page[0]['has_more'] ? $page[0]['next_cursor'] : null;
            }

            $expected = [$r1, $r2, $r3, $r4, $r5];
            sort($expected);
            sort($collected);
            $this->assertSame($expected, $collected, 'Every row delivered exactly once despite the mid-pass insert');
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * Tombstones/exclusions stay first-page-only in keyset mode too: a page
     * requested with a cursor must not repeat the deletion list.
     */
    public function testPullCursorTombstonesOnlyOnFirstPage(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        try {
            for ($i = 1; $i <= 3; $i++) {
                $this->createSyncTestProduct(1, '2030-01-01 10:00:0' . $i);
            }

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
            $sql .= " (table_name, object_id, deleted_at, deleted_by)";
            $sql .= " VALUES ('product', 424243, '2030-01-05 00:00:00', " . (int) $this->testUser->id . ")";
            $this->db->query($sql);

            $page0 = $this->pullProducts(['limit' => 2]);
            $this->assertEquals(200, $page0[1]);
            $this->assertContains(424243, $this->deletedIds($page0));

            $page1 = $this->pullProducts(['limit' => 2, 'cursor' => $page0[0]['next_cursor']]);
            $this->assertEquals(200, $page1[1]);
            $this->assertEmpty($page1[0]['deleted'], 'Tombstones must only be sent on the first page (no cursor)');
        } finally {
            $this->cleanSyncTestProducts();
        }
    }

    /**
     * A malformed or tampered cursor must be rejected with 400, never fed
     * into the WHERE clause. A well-formed cursor round-trips to 200.
     */
    public function testPullRejectsMalformedCursor(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $bad = [
            'not-hex-!!',                                       // non-hex chars
            'abc',                                              // odd-length hex
            bin2hex('nopipe'),                                  // valid hex, no separator
            bin2hex('not-a-date|5'),                            // bad tms
            bin2hex('2030-01-01 10:00:00|x'),                   // non-digit rowid
            str_repeat('a', 130),                               // over length cap
        ];
        foreach ($bad as $cursor) {
            $result = $this->pullProducts(['cursor' => $cursor]);
            $this->assertEquals(400, $result[1], 'Malformed cursor must yield 400: ' . $cursor);
        }

        // A well-formed cursor is accepted (points before every test row).
        $good = bin2hex('2030-01-01 00:00:00|0');
        $result = $this->pullProducts(['cursor' => $good]);
        $this->assertEquals(200, $result[1], 'Well-formed cursor must be accepted');
    }

    // =========================================================================
    // Conflict round-trip: a real conflict must be persisted, listed, counted
    // and resolvable. Regression guard for the bug where createConflictRecord's
    // INSERT was rolled back together with the rejected business update, so the
    // conflict silently vanished (sync/conflicts empty, pending_conflicts = 0).
    // =========================================================================

    /**
     * Force a known email + tms on a societe row so base_tms / server_tms are
     * deterministic for the conflict-detection assertions.
     *
     * @param int    $id    Societe rowid
     * @param string $email Value to set on the pushed field
     * @param string $tms   Timestamp to set (drives the tms comparison)
     * @return void
     */
    private function forceSocieteState(int $id, string $email, string $tms): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "societe";
        $sql .= " SET email = '" . $this->db->escape($email) . "',";
        $sql .= " tms = '" . $this->db->escape($tms) . "'";
        $sql .= " WHERE rowid = " . (int) $id;
        if (!$this->db->query($sql)) {
            throw new \RuntimeException('Failed to force societe state: ' . $this->db->lasterror());
        }
    }

    /**
     * A real conflict (stale base_tms + genuinely different data) must be
     * rolled back on the business row yet PERSISTED as a pending conflict, so
     * it surfaces in sync/conflicts and sync/status and can be resolved.
     */
    public function testRealConflictIsPersistedListedAndResolvable(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // State the client saw at pull time -> the base_tms it will send back.
        $societe = $this->createTestSociete(['name' => 'Conflict Co ' . uniqid()]);
        $baseTms = '2020-01-01 00:00:00';
        $this->forceSocieteState($societe->id, 'original@example.test', $baseTms);

        // Concurrent server-side edit: the pushed field changed, tms bumped.
        $this->forceSocieteState($societe->id, 'server@example.test', '2020-06-15 12:00:00');

        // Client pushes its own value with the now-stale base_tms -> conflict.
        $push = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [[
                'action'   => 'update',
                'id'       => $societe->id,
                'base_tms' => $baseTms,
                'data'     => ['email' => 'client@example.test'],
            ]],
        ]);

        $this->assertEquals(200, $push[1]);
        $this->assertNotEmpty($push[0]['conflicts'], 'push must report the conflict to the client');
        $this->assertEmpty($push[0]['success'], 'the conflicting update must not be applied');

        // Business row keeps the server value (data rollback is intended).
        $reloaded = new \Societe($this->db);
        $reloaded->fetch($societe->id);
        $this->assertEquals('server@example.test', $reloaded->email, 'client data must not overwrite the server row on conflict');

        // Regression core: the conflict must survive server-side (it used to be
        // eaten by the update rollback).
        $conflicts = $this->controller->conflicts([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEquals(200, $conflicts[1]);
        $this->assertCount(1, $conflicts[0]['conflicts'], 'the conflict must be persisted and listable');
        $this->assertEquals($societe->id, $conflicts[0]['conflicts'][0]['object_id']);
        $conflictId = $conflicts[0]['conflicts'][0]['id'];

        $status = $this->controller->status([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEquals(1, $status[0]['pending_conflicts'], 'status must count the pending conflict');

        // The resolution workflow now has a row to act on.
        $resolve = $this->controller->resolveConflict([
            'user_id'    => $this->testUser->id,
            'id'         => $conflictId,
            'resolution' => 'client',
        ]);
        $this->assertEquals(200, $resolve[1]);
        $this->assertTrue($resolve[0]['success']);

        // Client resolution applied, and the conflict left the pending list.
        $afterResolve = new \Societe($this->db);
        $afterResolve->fetch($societe->id);
        $this->assertEquals('client@example.test', $afterResolve->email, 'client resolution must write the client value');

        $after = $this->controller->conflicts([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEmpty($after[0]['conflicts'], 'resolved conflict must leave the pending list');
    }

    /**
     * Force a known name + tms on a societe row (the email twin of
     * forceSocieteState, for the 'nom' column).
     */
    private function forceSocieteNameState(int $id, string $name, string $tms): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "societe";
        $sql .= " SET nom = '" . $this->db->escape($name) . "',";
        $sql .= " tms = '" . $this->db->escape($tms) . "'";
        $sql .= " WHERE rowid = " . (int) $id;
        if (!$this->db->query($sql)) {
            throw new \RuntimeException('Failed to force societe name state: ' . $this->db->lasterror());
        }
    }

    /**
     * 'name' is the API key of a field whose SQL column is 'nom' and whose
     * Dolibarr property is 'name' (Societe::fetch selects "s.nom as name").
     * The historical raw SQL comparison could never match it, so a real
     * conflict on the thirdparty NAME was silently overwritten. The mapper
     * path must detect it (audit S-6).
     */
    public function testRealConflictIsDetectedOnMappedApiKeyField(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $societe = $this->createTestSociete(['name' => 'Mapped Co ' . uniqid()]);
        $baseTms = '2020-01-01 00:00:00';
        $this->forceSocieteNameState($societe->id, 'Server Name', $baseTms);
        $this->forceSocieteNameState($societe->id, 'Server Name', '2020-06-15 12:00:00');

        $push = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [[
                'action'   => 'update',
                'id'       => $societe->id,
                'base_tms' => $baseTms,
                'data'     => ['name' => 'Client Name'],
            ]],
        ]);

        $this->assertEquals(200, $push[1]);
        $this->assertNotEmpty($push[0]['conflicts'], 'a real conflict on a mapped API-key field must be reported');
        $this->assertEmpty($push[0]['success'], 'the conflicting update must not be applied');

        // The conflict is expressed in API-key space, under 'name'.
        $this->assertArrayHasKey(
            'name',
            $push[0]['conflicts'][0]['field_conflicts'] ?? [],
            'field_conflicts must key the conflicting field by its API name'
        );

        // Business row keeps the server value.
        $reloaded = new \Societe($this->db);
        $reloaded->fetch($societe->id);
        $this->assertEquals('Server Name', $reloaded->name, 'client data must not overwrite the server row on conflict');
    }

    /**
     * Offline-first clients retry the same push after a lost 2xx. A replayed
     * conflicting update must refresh the SAME pending conflict row, never pile
     * up duplicates in sync/conflicts / sync/status.
     */
    public function testReplayedConflictDoesNotDuplicatePendingRows(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $societe = $this->createTestSociete(['name' => 'Retry Co ' . uniqid()]);
        $baseTms = '2020-01-01 00:00:00';
        $this->forceSocieteState($societe->id, 'original@example.test', $baseTms);
        $this->forceSocieteState($societe->id, 'server@example.test', '2020-06-15 12:00:00');

        $payload = [
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [[
                'action'   => 'update',
                'id'       => $societe->id,
                'base_tms' => $baseTms,
                'data'     => ['email' => 'client@example.test'],
            ]],
        ];

        // Same conflicting push three times (lost-response retries).
        $this->controller->push($payload);
        $this->controller->push($payload);
        $this->controller->push($payload);

        $status = $this->controller->status([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEquals(1, $status[0]['pending_conflicts'], 'retries must not multiply the pending conflict');

        $conflicts = $this->controller->conflicts([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertCount(1, $conflicts[0]['conflicts'], 'a single pending row must represent the repeated conflict');
    }

    /**
     * tms drift alone must NOT raise a conflict: when the data the client
     * pushes is identical to the server's, detectRealConflict lets the update
     * proceed (no false conflict, nothing persisted).
     */
    public function testCleanUpdateWithStaleTmsButSameDataIsNotAConflict(): void
    {
        global $user;
        $user = $this->testUser;

        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        $societe = $this->createTestSociete(['name' => 'Clean Co ' . uniqid()]);
        $baseTms = '2020-01-01 00:00:00';
        $this->forceSocieteState($societe->id, 'stable@example.test', $baseTms);

        // Server bumped tms only (an unrelated writer); the field is unchanged.
        $this->forceSocieteState($societe->id, 'stable@example.test', '2020-06-15 12:00:00');

        // Client pushes the same value it already holds, with a stale base_tms.
        $push = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [[
                'action'   => 'update',
                'id'       => $societe->id,
                'base_tms' => $baseTms,
                'data'     => ['email' => 'stable@example.test'],
            ]],
        ]);

        $this->assertEquals(200, $push[1]);
        $this->assertEmpty($push[0]['conflicts'], 'identical data must not raise a false conflict on tms drift');
        $this->assertContains($societe->id, $push[0]['success'], 'the clean update must be applied');

        $status = $this->controller->status([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEquals(0, $status[0]['pending_conflicts'], 'no conflict must be persisted');
    }

    /**
     * A second authenticated user must not reach another user's sync client on
     * the same physical device (M-11): getClientByUUID scopes on the device's
     * owning user, so status / conflicts / push all fail closed with 404.
     */
    public function testForeignUserCannotUsePeerSyncClientOnSharedDevice(): void
    {
        $deviceId = $this->createSyncTestDevice();
        $this->registerSyncClient($deviceId);

        // Owner (the user who created the device) reaches its own client.
        $ownerStatus = $this->controller->status([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
        ]);
        $this->assertEquals(200, $ownerStatus[1], 'owner must reach its own sync client');

        // A different authenticated user, same client_uuid / device: refused.
        $otherUser = $this->createTestUser();

        foreach (['status', 'conflicts'] as $endpoint) {
            $res = $this->controller->$endpoint([
                'user_id'     => $otherUser->id,
                'client_uuid' => $this->testClientUUID,
            ]);
            $this->assertEquals(404, $res[1], "foreign user must not reach peer client via $endpoint");
        }

        // Write path is refused before any object is touched.
        $push = $this->controller->push([
            'user_id'     => $otherUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty',
            'changes'     => [[
                'action'  => 'create',
                'temp_id' => 'tmp-x',
                'data'    => ['name' => 'Should not be created'],
            ]],
        ]);
        $this->assertEquals(404, $push[1], 'foreign user must not push through peer client');
    }
}
