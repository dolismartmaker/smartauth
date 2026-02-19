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
        $uuid = $this->generateUUID();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (fk_user, uuid_device, device_name, app_version, last_used, date_creation, status)";
        $sql .= " VALUES (";
        $sql .= (int) $this->testUser->id . ", ";
        $sql .= "'" . $this->db->escape($uuid) . "', ";
        $sql .= "'Test Device', ";
        $sql .= "'1.0.0', ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= "1)";

        $this->db->query($sql);
        return $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    /**
     * Register a sync client and return its ID
     */
    private function registerSyncClient(int $deviceId): int
    {
        $result = $this->controller->register([
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0'
        ]);

        $clientId = $result1[0]['client_id'];

        // Second registration with same UUID
        $result2 = $this->controller->register([
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID
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
            'client_uuid' => $this->testClientUUID
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
            'client_uuid' => $this->testClientUUID
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
            'client_uuid' => $this->testClientUUID
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
            'client_uuid' => $this->testClientUUID,
            'object_type' => 'thirdparty'
        ]);

        $pullCount = $this->getTableCount('smartauth_sync_events', [
            'fk_client' => $clientId,
            'event_type' => 'pull'
        ]);
        $this->assertEquals(1, $pullCount);

        // Status operation (no event logged for status)
        $this->controller->status([
            'client_uuid' => $this->testClientUUID
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $fakeUUID
        ]);
        $this->assertEquals(404, $statusResult[1]);

        // Conflicts
        $conflictsResult = $this->controller->conflicts([
            'client_uuid' => $fakeUUID
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
            'client_uuid' => $this->testClientUUID,
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
            'client_uuid' => $this->testClientUUID,
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
}
