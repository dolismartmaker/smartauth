<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\SyncController;
use SmartAuth\Tests\Mocks\MockDatabase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for SyncController
 */
class SyncControllerTest extends TestCase
{
    private MockDatabase $mockDb;
    private SyncController $controller;

    protected function setUp(): void
    {
        global $db, $hookmanager;

        $this->mockDb = new MockDatabase();
        $db = $this->mockDb;

        // Mock hookmanager
        $hookmanager = null;

        // Reset mock state
        $this->mockDb->reset();

        $this->controller = new SyncController();
    }

    protected function tearDown(): void
    {
        global $db;
        $db = null;
    }

    /**
     * Helper to access private/protected methods
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(SyncController::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Helper to set private property
     */
    private function setPrivateProperty(object $object, string $propertyName, $value): void
    {
        $reflection = new ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    // =========================================================================
    // Tests for register endpoint
    // =========================================================================

    /**
     * Test register without client_uuid returns 400
     */
    public function testRegisterWithoutClientUuidReturns400(): void
    {
        $result = $this->controller->register([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('client_uuid', $result[0]['error']);
    }

    /**
     * Test register with invalid client_uuid returns 400
     */
    public function testRegisterWithInvalidClientUuidReturns400(): void
    {
        $result = $this->controller->register([
            'client_uuid' => 'invalid-uuid-format!'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test register without device_id in token returns 400
     */
    public function testRegisterWithoutDeviceIdReturns400(): void
    {
        $result = $this->controller->register([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Device ID', $result[0]['error']);
    }

    /**
     * Test register creates new client successfully
     */
    public function testRegisterCreatesNewClient(): void
    {
        // Setup: no existing client, insert succeeds
        $this->mockDb
            ->setQueryResult(true, [], 0)  // SELECT - no existing client
            ->setQueryResult(true)          // INSERT
            ->setLastInsertId(123)
            ->setQueryResult(true);         // INSERT event log

        $result = $this->controller->register([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'jwt_device_id' => 42,
            'app_version' => '1.0.0'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertEquals(123, $result[0]['client_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $result[0]['client_uuid']);
        $this->assertArrayHasKey('server_time', $result[0]);
        $this->assertArrayHasKey('sync_scope', $result[0]);
    }

    /**
     * Test register updates existing client
     */
    public function testRegisterUpdatesExistingClient(): void
    {
        // Setup: existing client found
        $existingClient = (object) [
            'rowid' => 456,
            'status' => 1
        ];

        $this->mockDb
            ->setQueryResult(true, [(array) $existingClient], 1)  // SELECT - existing client
            ->setQueryResult(true)   // UPDATE
            ->setQueryResult(true);  // INSERT event log

        $result = $this->controller->register([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'jwt_device_id' => 42,
            'app_version' => '2.0.0'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertEquals(456, $result[0]['client_id']);
    }

    // =========================================================================
    // Tests for pull endpoint
    // =========================================================================

    /**
     * Test pull without client_uuid returns 400
     */
    public function testPullWithoutClientUuidReturns400(): void
    {
        $result = $this->controller->pull([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('client_uuid', $result[0]['error']);
    }

    /**
     * Test pull with invalid object_type returns 400
     */
    public function testPullWithInvalidObjectTypeReturns400(): void
    {
        $result = $this->controller->pull([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'invalid_type'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('object_type', $result[0]['error']);
    }

    /**
     * Test pull with unregistered client returns 404
     */
    public function testPullWithUnregisteredClientReturns404(): void
    {
        // No client found
        $this->mockDb->setQueryResult(true, [], 0);

        $result = $this->controller->pull([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'thirdparty'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(404, $result[1]);
        $this->assertStringContainsString('not registered', $result[0]['error']);
    }

    /**
     * Test pull returns updated and deleted objects
     */
    public function testPullReturnsUpdatedAndDeleted(): void
    {
        // Client exists
        $client = (object) [
            'rowid' => 1,
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'last_sync_at' => '2025-01-01 00:00:00',
            'status' => 1
        ];

        // Updated thirdparties
        $thirdparty1 = [
            'rowid' => 10,
            'nom' => 'Company A',
            'tms' => '2025-01-19 10:00:00'
        ];
        $thirdparty2 = [
            'rowid' => 11,
            'nom' => 'Company B',
            'tms' => '2025-01-19 11:00:00'
        ];

        // Deleted objects
        $tombstone = [
            'object_id' => 5,
            'deleted_at' => '2025-01-18 09:00:00'
        ];

        $this->mockDb
            ->setQueryResult(true, [(array) $client], 1)     // Client lookup
            ->setQueryResult(true, [$thirdparty1, $thirdparty2], 2)  // Updated objects
            ->setQueryResult(true, [$tombstone], 1)          // Tombstones
            ->setQueryResult(true);                          // Event log

        $result = $this->controller->pull([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'thirdparty'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('updated', $result[0]);
        $this->assertArrayHasKey('deleted', $result[0]);
        $this->assertArrayHasKey('server_time', $result[0]);
        $this->assertCount(2, $result[0]['updated']);
        $this->assertCount(1, $result[0]['deleted']);
    }

    // =========================================================================
    // Tests for push endpoint
    // =========================================================================

    /**
     * Test push without client_uuid returns 400
     */
    public function testPushWithoutClientUuidReturns400(): void
    {
        $result = $this->controller->push([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test push without changes returns 400
     */
    public function testPushWithoutChangesReturns400(): void
    {
        $result = $this->controller->push([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'thirdparty',
            'changes' => []
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('changes', $result[0]['error']);
    }

    /**
     * Test push with unregistered client returns 404
     */
    public function testPushWithUnregisteredClientReturns404(): void
    {
        $this->mockDb->setQueryResult(true, [], 0);

        $result = $this->controller->push([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'thirdparty',
            'changes' => [
                ['action' => 'update', 'id' => 1, 'data' => ['nom' => 'Test']]
            ]
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(404, $result[1]);
    }

    /**
     * Test push with unknown action returns error
     */
    public function testPushWithUnknownActionReturnsError(): void
    {
        $client = (object) [
            'rowid' => 1,
            'status' => 1
        ];

        $this->mockDb
            ->setQueryResult(true, [(array) $client], 1)  // Client lookup
            ->setQueryResult(true);                        // Event log

        $result = $this->controller->push([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'object_type' => 'thirdparty',
            'changes' => [
                ['action' => 'unknown_action', 'id' => 1, 'data' => []]
            ]
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['errors']);
        $this->assertStringContainsString('Unknown action', $result[0]['errors'][0]['error']);
    }

    // =========================================================================
    // Tests for status endpoint
    // =========================================================================

    /**
     * Test status without client_uuid returns 400
     */
    public function testStatusWithoutClientUuidReturns400(): void
    {
        $result = $this->controller->status([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test status with unregistered client returns 404
     */
    public function testStatusWithUnregisteredClientReturns404(): void
    {
        $this->mockDb->setQueryResult(true, [], 0);

        $result = $this->controller->status([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(404, $result[1]);
    }

    /**
     * Test status returns correct data
     */
    public function testStatusReturnsCorrectData(): void
    {
        $client = [
            'rowid' => 1,
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'last_sync_at' => '2025-01-19 10:00:00',
            'sync_scope' => '{"thirdparty":true,"contact":true}',
            'status' => 1
        ];

        $conflictCount = ['nb' => 3];

        $this->mockDb
            ->setQueryResult(true, [$client], 1)
            ->setQueryResult(true, [$conflictCount], 1);

        $result = $this->controller->status([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $result[0]['client_uuid']);
        $this->assertEquals(3, $result[0]['pending_conflicts']);
        $this->assertArrayHasKey('server_time', $result[0]);
        $this->assertArrayHasKey('sync_scope', $result[0]);
    }

    // =========================================================================
    // Tests for conflicts endpoint
    // =========================================================================

    /**
     * Test conflicts without client_uuid returns 400
     */
    public function testConflictsWithoutClientUuidReturns400(): void
    {
        $result = $this->controller->conflicts([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test conflicts returns pending conflicts list
     */
    public function testConflictsReturnsPendingList(): void
    {
        $client = (object) [
            'rowid' => 1,
            'status' => 1
        ];

        $conflict = [
            'rowid' => 100,
            'table_name' => 'societe',
            'object_id' => 50,
            'client_data' => '{"nom":"Client Version"}',
            'server_data' => '{"nom":"Server Version"}',
            'client_tms' => '2025-01-19 09:00:00',
            'server_tms' => '2025-01-19 09:30:00',
            'field_conflicts' => '{"nom":{"client":"Client Version","server":"Server Version"}}',
            'date_creation' => '2025-01-19 10:00:00'
        ];

        $this->mockDb
            ->setQueryResult(true, [(array) $client], 1)
            ->setQueryResult(true, [$conflict], 1);

        $result = $this->controller->conflicts([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('conflicts', $result[0]);
        $this->assertCount(1, $result[0]['conflicts']);
        $this->assertEquals(100, $result[0]['conflicts'][0]['id']);
        $this->assertEquals(50, $result[0]['conflicts'][0]['object_id']);
    }

    // =========================================================================
    // Tests for resolveConflict endpoint
    // =========================================================================

    /**
     * Test resolveConflict without conflict ID returns 400
     */
    public function testResolveConflictWithoutIdReturns400(): void
    {
        $result = $this->controller->resolveConflict([]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test resolveConflict with invalid resolution returns 400
     */
    public function testResolveConflictWithInvalidResolutionReturns400(): void
    {
        $result = $this->controller->resolveConflict([
            'id' => 1,
            'resolution' => 'invalid'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid resolution', $result[0]['error']);
    }

    /**
     * Test resolveConflict with merged but no data returns 400
     */
    public function testResolveConflictMergedWithoutDataReturns400(): void
    {
        $conflict = (object) [
            'rowid' => 1,
            'table_name' => 'societe',
            'object_id' => 50,
            'client_data' => '{"nom":"Client"}',
            'server_data' => '{"nom":"Server"}',
            'fk_client' => 1,
            'status' => 'pending'
        ];

        $this->mockDb
            ->setQueryResult(true, [(array) $conflict], 1);

        $result = $this->controller->resolveConflict([
            'id' => 1,
            'resolution' => 'merged'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Merged data is required', $result[0]['error']);
    }

    /**
     * Test resolveConflict with non-existent conflict returns 404
     */
    public function testResolveConflictNotFoundReturns404(): void
    {
        $this->mockDb->setQueryResult(true, [], 0);

        $result = $this->controller->resolveConflict([
            'id' => 999,
            'resolution' => 'server'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(404, $result[1]);
    }

    // =========================================================================
    // Tests for private helper methods
    // =========================================================================

    /**
     * Test determineSyncScope with default values
     */
    public function testDetermineSyncScopeWithDefaults(): void
    {
        $method = $this->getPrivateMethod('determineSyncScope');
        $result = $method->invoke($this->controller, null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('thirdparty', $result);
        $this->assertArrayHasKey('contact', $result);
        $this->assertArrayHasKey('product', $result);
        // Default enabled should be true for these
        $this->assertTrue($result['thirdparty']);
        $this->assertTrue($result['contact']);
        $this->assertTrue($result['product']);
    }

    /**
     * Test determineSyncScope with custom scope
     */
    public function testDetermineSyncScopeWithCustomScope(): void
    {
        $method = $this->getPrivateMethod('determineSyncScope');
        $result = $method->invoke($this->controller, ['thirdparty']);

        $this->assertTrue($result['thirdparty']);
        $this->assertFalse($result['contact']);
        $this->assertFalse($result['product']);
    }

    /**
     * Test normalizeValue handles various types
     */
    public function testNormalizeValueHandlesVariousTypes(): void
    {
        $method = $this->getPrivateMethod('normalizeValue');

        // Null and empty
        $this->assertNull($method->invoke($this->controller, null));
        $this->assertNull($method->invoke($this->controller, ''));

        // Numeric
        $this->assertEquals('123', $method->invoke($this->controller, 123));
        $this->assertEquals('45.67', $method->invoke($this->controller, 45.67));

        // String with whitespace
        $this->assertEquals('test', $method->invoke($this->controller, '  test  '));
    }

    /**
     * Test detectRealConflict finds actual differences
     */
    public function testDetectRealConflictFindsActualDifferences(): void
    {
        $method = $this->getPrivateMethod('detectRealConflict');

        $clientData = [
            'nom' => 'Client Name',
            'email' => 'client@test.fr',
            'phone' => '0123456789'
        ];

        $serverObj = (object) [
            'rowid' => 1,
            'nom' => 'Server Name',
            'email' => 'client@test.fr',  // Same
            'phone' => '0987654321',
            'tms' => '2025-01-19 10:00:00'
        ];

        $config = ['table' => 'societe'];

        $result = $method->invoke($this->controller, $clientData, $serverObj, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nom', $result);
        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayNotHasKey('email', $result);  // Same value, no conflict
    }

    /**
     * Test detectRealConflict returns null when no real differences
     */
    public function testDetectRealConflictReturnsNullWhenNoRealDifferences(): void
    {
        $method = $this->getPrivateMethod('detectRealConflict');

        $clientData = [
            'nom' => 'Same Name',
            'email' => 'same@test.fr'
        ];

        $serverObj = (object) [
            'rowid' => 1,
            'nom' => 'Same Name',
            'email' => 'same@test.fr',
            'tms' => '2025-01-19 10:00:00'
        ];

        $config = ['table' => 'societe'];

        $result = $method->invoke($this->controller, $clientData, $serverObj, $config);

        $this->assertNull($result);
    }

    /**
     * Test formatObjectForSync transforms data correctly
     */
    public function testFormatObjectForSyncTransformsData(): void
    {
        $method = $this->getPrivateMethod('formatObjectForSync');

        $obj = (object) [
            'rowid' => 123,
            'nom' => 'Test Company',
            'email' => 'test@example.fr',
            'tms' => '2025-01-19 10:00:00'
        ];

        $result = $method->invoke($this->controller, $obj, 'thirdparty');

        $this->assertIsArray($result);
        $this->assertEquals(123, $result['id']);
        $this->assertArrayNotHasKey('rowid', $result);
        $this->assertEquals('Test Company', $result['nom']);
        // tms should be in ISO format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result['tms']);
    }

    /**
     * Test getObjectTypeFromTable returns correct type
     */
    public function testGetObjectTypeFromTableReturnsCorrectType(): void
    {
        $method = $this->getPrivateMethod('getObjectTypeFromTable');

        $this->assertEquals('thirdparty', $method->invoke($this->controller, 'societe'));
        $this->assertEquals('contact', $method->invoke($this->controller, 'socpeople'));
        $this->assertEquals('product', $method->invoke($this->controller, 'product'));
        $this->assertNull($method->invoke($this->controller, 'unknown_table'));
    }
}
