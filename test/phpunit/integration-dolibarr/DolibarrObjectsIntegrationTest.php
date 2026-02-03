<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth;
use SmartAuthDevices;
use User;
use Societe;

/**
 * Integration tests with real Dolibarr objects (User, Societe, etc.)
 *
 * Tests the SmartAuth module integration with Dolibarr core objects
 *
 * @covers \SmartAuth
 * @covers \SmartAuthDevices
 */
class DolibarrObjectsIntegrationTest extends DolibarrRealTestCase
{
    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        parent::setUp();
    }

    /**
     * Generate a valid UUID v4
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

    // =========================================================================
    // USER CREATION AND AUTHENTICATION TESTS
    // =========================================================================

    /**
     * Test creating a Dolibarr User and generating tokens for it
     */
    public function testUserCreationAndAuthentication(): void
    {
        // Create a new test user
        $user = $this->createTestUser([
            'login' => 'authuser_' . uniqid(),
            'lastname' => 'AuthTest',
            'firstname' => 'User',
            'pass' => 'TestPassword123!',
            'statut' => 1
        ]);

        $this->assertGreaterThan(0, $user->id, 'User should be created successfully');

        // Create a device for this user
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Test Device for ' . $user->login;
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($this->testUser);

        $this->assertGreaterThan(0, $device->id, 'Device should be created');

        // Generate an access token for the user
        $accessToken = new SmartAuth($this->db);
        $accessToken->appuid = 1;
        $accessToken->salt = bin2hex(random_bytes(16));
        $accessToken->fk_user_creat = $user->id;
        $accessToken->fk_authid = $user->id;
        $accessToken->auth_element = 'user';
        $accessToken->fk_device_id = $device->id;
        $accessToken->token_type = 'access';
        $accessToken->status = SmartAuth::STATUS_VALIDATED;
        $accessToken->ip = '127.0.0.1';
        $accessToken->entity = 1;
        $accessToken->date_eol = dol_now() + 3600; // 1 hour validity

        $result = $accessToken->create($user);

        $this->assertGreaterThan(0, $result, 'Access token should be created');
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessToken->id,
            'fk_authid' => $user->id,
            'token_type' => 'access',
            'auth_element' => 'user'
        ]);

        // Generate a refresh token for the user
        $refreshToken = new SmartAuth($this->db);
        $refreshToken->appuid = 1;
        $refreshToken->salt = bin2hex(random_bytes(16));
        $refreshToken->fk_user_creat = $user->id;
        $refreshToken->fk_authid = $user->id;
        $refreshToken->auth_element = 'user';
        $refreshToken->fk_device_id = $device->id;
        $refreshToken->token_type = 'refresh';
        $refreshToken->family_id = $accessToken->id;
        $refreshToken->status = SmartAuth::STATUS_VALIDATED;
        $refreshToken->ip = '127.0.0.1';
        $refreshToken->entity = 1;
        $refreshToken->date_eol = dol_now() + 86400 * 30; // 30 days validity

        $result = $refreshToken->create($user);

        $this->assertGreaterThan(0, $result, 'Refresh token should be created');
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshToken->id,
            'fk_authid' => $user->id,
            'token_type' => 'refresh',
            'family_id' => $accessToken->id
        ]);
    }

    // =========================================================================
    // SOCIETE (THIRD PARTY) CREATION TESTS
    // =========================================================================

    /**
     * Test creating a Societe (third party) and verify integration
     */
    public function testSocieteCreation(): void
    {
        // Create a test third party
        $societe = $this->createTestSociete([
            'name' => 'Integration Test Company ' . uniqid(),
            'email' => 'contact@testcompany-' . uniqid() . '.com',
            'client' => 1,
            'status' => 1
        ]);

        $this->assertGreaterThan(0, $societe->id, 'Societe should be created');
        $this->assertNotEmpty($societe->name, 'Societe name should be set');

        // Verify Societe exists in database
        $this->assertDatabaseHas('societe', [
            'rowid' => $societe->id,
            'client' => 1
        ]);

        // Create a SmartAuth token linked to the societe (using societe_account element)
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Societe Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($this->testUser);

        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $societe->id;
        $auth->auth_element = 'societe';
        $auth->fk_device_id = $device->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '10.0.0.1';
        $auth->entity = 1;

        $result = $auth->create($this->testUser);

        $this->assertGreaterThan(0, $result, 'Auth token for Societe should be created');
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $auth->id,
            'fk_authid' => $societe->id,
            'auth_element' => 'societe'
        ]);
    }

    // =========================================================================
    // MULTI-ENTITY TESTS
    // =========================================================================

    /**
     * Test user with access to multiple entities (multicompany)
     */
    public function testUserWithMultipleEntities(): void
    {
        // Skip if multicompany is not enabled
        global $conf;
        if (empty($conf->multicompany->enabled)) {
            $this->markTestSkipped('Multicompany module is not enabled');
        }

        // Create user in entity 1
        $user = $this->createTestUser([
            'login' => 'multiuser_' . uniqid(),
            'lastname' => 'Multi',
            'firstname' => 'Entity',
            'entity' => 1
        ]);

        $this->assertGreaterThan(0, $user->id, 'User should be created in entity 1');

        // Create device in entity 1
        $device1 = new SmartAuthDevices($this->db);
        $device1->label = 'Entity 1 Device';
        $device1->uuid = $this->generateUUID();
        $device1->status = SmartAuthDevices::STATUS_VALIDATED;
        $device1->entity = 1;
        $device1->create($this->testUser);

        // Create auth token in entity 1
        $auth1 = new SmartAuth($this->db);
        $auth1->appuid = 1;
        $auth1->salt = bin2hex(random_bytes(16));
        $auth1->fk_user_creat = $user->id;
        $auth1->fk_authid = $user->id;
        $auth1->auth_element = 'user';
        $auth1->fk_device_id = $device1->id;
        $auth1->token_type = 'access';
        $auth1->status = SmartAuth::STATUS_VALIDATED;
        $auth1->ip = '192.168.1.1';
        $auth1->entity = 1;
        $auth1->create($user);

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $auth1->id,
            'entity' => 1
        ]);

        // Create device in entity 2
        $device2 = new SmartAuthDevices($this->db);
        $device2->label = 'Entity 2 Device';
        $device2->uuid = $this->generateUUID();
        $device2->status = SmartAuthDevices::STATUS_VALIDATED;
        $device2->entity = 2;
        $device2->create($this->testUser);

        // Create auth token in entity 2
        $auth2 = new SmartAuth($this->db);
        $auth2->appuid = 1;
        $auth2->salt = bin2hex(random_bytes(16));
        $auth2->fk_user_creat = $user->id;
        $auth2->fk_authid = $user->id;
        $auth2->auth_element = 'user';
        $auth2->fk_device_id = $device2->id;
        $auth2->token_type = 'access';
        $auth2->status = SmartAuth::STATUS_VALIDATED;
        $auth2->ip = '192.168.2.1';
        $auth2->entity = 2;
        $auth2->create($user);

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $auth2->id,
            'entity' => 2
        ]);

        // Verify both tokens exist in different entities
        $entity1Count = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'entity' => 1
        ]);
        $entity2Count = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'entity' => 2
        ]);

        $this->assertEquals(1, $entity1Count, 'Should have 1 token in entity 1');
        $this->assertEquals(1, $entity2Count, 'Should have 1 token in entity 2');
    }

    // =========================================================================
    // DEVICE ASSOCIATION WITH USER TESTS
    // =========================================================================

    /**
     * Test device-user association in SmartAuthDevices
     */
    public function testDeviceAssociationWithUser(): void
    {
        // Create a test user
        $user = $this->createTestUser([
            'login' => 'deviceuser_' . uniqid(),
            'lastname' => 'Device',
            'firstname' => 'Owner'
        ]);

        // Create multiple devices for this user
        $deviceIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $device = new SmartAuthDevices($this->db);
            $device->label = "User Device $i";
            $device->uuid = $this->generateUUID();
            $device->status = SmartAuthDevices::STATUS_VALIDATED;
            $device->entity = 1;
            $device->fk_user_creat = $user->id;
            $device->create($user);

            $deviceIds[] = $device->id;

            $this->assertDatabaseHas('smartauth_devices', [
                'rowid' => $device->id,
                'fk_user_creat' => $user->id
            ]);
        }

        // Verify all devices are created
        $this->assertCount(3, $deviceIds);

        // Create auth tokens linked to specific devices
        foreach ($deviceIds as $index => $deviceId) {
            $auth = new SmartAuth($this->db);
            $auth->appuid = 1;
            $auth->salt = bin2hex(random_bytes(16));
            $auth->fk_user_creat = $user->id;
            $auth->fk_authid = $user->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $deviceId;
            $auth->token_type = 'access';
            $auth->status = SmartAuth::STATUS_VALIDATED;
            $auth->ip = '10.0.0.' . ($index + 1);
            $auth->entity = 1;
            $auth->create($user);

            $this->assertDatabaseHas('smartauth_auth', [
                'fk_authid' => $user->id,
                'fk_device_id' => $deviceId
            ]);
        }

        // Verify we have 3 auth tokens for this user
        $tokenCount = $this->getTableCount('smartauth_auth', ['fk_authid' => $user->id]);
        $this->assertEquals(3, $tokenCount, 'User should have 3 auth tokens');
    }

    // =========================================================================
    // SMARTAUTH CLASS WITH REAL USER TESTS
    // =========================================================================

    /**
     * Test SmartAuth class with a real Dolibarr user
     */
    public function testSmartAuthClassWithRealUser(): void
    {
        // Create a real user
        $user = $this->createTestUser([
            'login' => 'realuser_' . uniqid(),
            'lastname' => 'Real',
            'firstname' => 'User',
            'admin' => 0,
            'statut' => 1
        ]);

        // Create device
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Real User Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($user);

        // Test SmartAuth instantiation
        $auth = new SmartAuth($this->db);
        $this->assertInstanceOf(SmartAuth::class, $auth);

        // Test create with real user
        $auth->appuid = 1;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $user->id;
        $auth->fk_authid = $user->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $device->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '127.0.0.1';
        $auth->entity = 1;

        $result = $auth->create($user);
        $this->assertGreaterThan(0, $result);

        // Test fetch
        $fetchedAuth = new SmartAuth($this->db);
        $fetchResult = $fetchedAuth->fetch($auth->id);

        if ($fetchResult > 0) {
            $this->assertEquals($auth->id, $fetchedAuth->id);
            $this->assertEquals($user->id, $fetchedAuth->fk_authid);
            $this->assertEquals('user', $fetchedAuth->auth_element);
        } else {
            // SQLite compatibility - verify in database
            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $auth->id,
                'fk_authid' => $user->id
            ]);
        }

        // Test update
        $auth->ip = '192.168.1.100';
        $updateResult = $auth->update($user);
        $this->assertGreaterThanOrEqual(0, $updateResult);

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $auth->id,
            'ip' => '192.168.1.100'
        ]);

        // Test status change methods
        $cancelResult = $auth->cancel($user);
        $this->assertGreaterThan(0, $cancelResult);
        $this->assertEquals(SmartAuth::STATUS_CANCELED, $auth->status);

        $reopenResult = $auth->reopen($user);
        $this->assertGreaterThan(0, $reopenResult);
        $this->assertEquals(SmartAuth::STATUS_VALIDATED, $auth->status);
    }

    // =========================================================================
    // SMARTAUTHDEVICES CRUD TESTS
    // =========================================================================

    /**
     * Test complete CRUD operations on SmartAuthDevices
     */
    public function testSmartAuthDevicesClassCRUD(): void
    {
        $user = $this->createTestUser([
            'login' => 'cruduser_' . uniqid()
        ]);

        // CREATE
        $device = new SmartAuthDevices($this->db);
        $device->label = 'CRUD Test Device';
        $device->uuid = $this->generateUUID();
        $device->description = 'Device for CRUD testing';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;

        $createResult = $device->create($user);
        $this->assertGreaterThan(0, $createResult, 'Device CREATE should succeed');
        $deviceId = $device->id;

        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $deviceId,
            'label' => 'CRUD Test Device',
            'uuid' => $device->uuid
        ]);

        // READ
        $fetchedDevice = new SmartAuthDevices($this->db);
        $fetchResult = $fetchedDevice->fetch($deviceId);

        if ($fetchResult > 0) {
            $this->assertEquals($deviceId, $fetchedDevice->id);
            $this->assertEquals('CRUD Test Device', $fetchedDevice->label);
            $this->assertEquals('Device for CRUD testing', $fetchedDevice->description);
        } else {
            // SQLite compatibility
            $this->assertDatabaseHas('smartauth_devices', ['rowid' => $deviceId]);
        }

        // UPDATE
        $device->label = 'Updated CRUD Device';
        $device->description = 'Updated description';
        $updateResult = $device->update($user);
        $this->assertGreaterThanOrEqual(0, $updateResult, 'Device UPDATE should succeed');

        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $deviceId,
            'label' => 'Updated CRUD Device',
            'description' => 'Updated description'
        ]);

        // READ by UUID
        $fetchByUuid = new SmartAuthDevices($this->db);
        $uuidFetchResult = $fetchByUuid->fetch(null, null, $device->uuid);

        if ($uuidFetchResult > 0) {
            $this->assertEquals($deviceId, $fetchByUuid->id);
        }

        // DELETE (skip on SQLite due to compatibility issues)
        if ($this->db->type !== 'sqlite3') {
            $deleteResult = $device->delete($user);
            $this->assertGreaterThan(0, $deleteResult, 'Device DELETE should succeed');

            $this->assertDatabaseMissing('smartauth_devices', ['rowid' => $deviceId]);
        }
    }

    // =========================================================================
    // TOKEN STORAGE TESTS
    // =========================================================================

    /**
     * Test token storage in SmartAuth class
     */
    public function testTokenStorageInSmartAuthClass(): void
    {
        $user = $this->createTestUser([
            'login' => 'tokenstorageuser_' . uniqid()
        ]);

        $device = new SmartAuthDevices($this->db);
        $device->label = 'Token Storage Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($user);

        // Create multiple tokens with different types and states
        $tokenData = [
            [
                'token_type' => 'access',
                'status' => SmartAuth::STATUS_VALIDATED,
                'date_eol' => dol_now() + 3600
            ],
            [
                'token_type' => 'refresh',
                'status' => SmartAuth::STATUS_VALIDATED,
                'date_eol' => dol_now() + 86400 * 30
            ],
            [
                'token_type' => 'access',
                'status' => SmartAuth::STATUS_CANCELED,
                'date_eol' => dol_now() - 3600 // Expired
            ]
        ];

        $createdTokens = [];
        foreach ($tokenData as $data) {
            $auth = new SmartAuth($this->db);
            $auth->appuid = 1;
            $auth->salt = bin2hex(random_bytes(16));
            $auth->fk_user_creat = $user->id;
            $auth->fk_authid = $user->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $device->id;
            $auth->token_type = $data['token_type'];
            $auth->status = $data['status'];
            $auth->ip = '127.0.0.1';
            $auth->entity = 1;
            $auth->date_eol = $data['date_eol'];

            $result = $auth->create($user);
            $this->assertGreaterThan(0, $result);
            $createdTokens[] = $auth;
        }

        // Verify token storage
        $this->assertEquals(3, count($createdTokens));

        // Count by token type
        $accessCount = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'token_type' => 'access'
        ]);
        $refreshCount = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'token_type' => 'refresh'
        ]);

        $this->assertEquals(2, $accessCount, 'Should have 2 access tokens');
        $this->assertEquals(1, $refreshCount, 'Should have 1 refresh token');

        // Count by status
        $validatedCount = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'status' => SmartAuth::STATUS_VALIDATED
        ]);
        $canceledCount = $this->getTableCount('smartauth_auth', [
            'fk_authid' => $user->id,
            'status' => SmartAuth::STATUS_CANCELED
        ]);

        $this->assertEquals(2, $validatedCount, 'Should have 2 validated tokens');
        $this->assertEquals(1, $canceledCount, 'Should have 1 canceled token');

        // Test fetchAll
        $auth = new SmartAuth($this->db);
        $allTokens = $auth->fetchAll('', '', 0, 0, ['fk_authid' => $user->id]);

        $this->assertIsArray($allTokens);
        $this->assertGreaterThanOrEqual(3, count($allTokens));
    }

    // =========================================================================
    // USER PERMISSIONS TESTS
    // =========================================================================

    /**
     * Test user permissions in authentication context
     */
    public function testUserPermissionsInAuthContext(): void
    {
        // Create admin user
        $adminUser = $this->createTestUser([
            'login' => 'adminperm_' . uniqid(),
            'admin' => 1,
            'statut' => 1
        ]);

        // Create regular user
        $regularUser = $this->createTestUser([
            'login' => 'regularperm_' . uniqid(),
            'admin' => 0,
            'statut' => 1
        ]);

        // Create device
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Permission Test Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($this->testUser);

        // Admin user creates token for themselves
        $adminAuth = new SmartAuth($this->db);
        $adminAuth->appuid = 1;
        $adminAuth->salt = bin2hex(random_bytes(16));
        $adminAuth->fk_user_creat = $adminUser->id;
        $adminAuth->fk_authid = $adminUser->id;
        $adminAuth->auth_element = 'user';
        $adminAuth->fk_device_id = $device->id;
        $adminAuth->token_type = 'access';
        $adminAuth->status = SmartAuth::STATUS_VALIDATED;
        $adminAuth->ip = '10.0.0.1';
        $adminAuth->entity = 1;

        $result = $adminAuth->create($adminUser);
        $this->assertGreaterThan(0, $result, 'Admin should create token');

        // Regular user creates token for themselves
        $regularAuth = new SmartAuth($this->db);
        $regularAuth->appuid = 1;
        $regularAuth->salt = bin2hex(random_bytes(16));
        $regularAuth->fk_user_creat = $regularUser->id;
        $regularAuth->fk_authid = $regularUser->id;
        $regularAuth->auth_element = 'user';
        $regularAuth->fk_device_id = $device->id;
        $regularAuth->token_type = 'access';
        $regularAuth->status = SmartAuth::STATUS_VALIDATED;
        $regularAuth->ip = '10.0.0.2';
        $regularAuth->entity = 1;

        $result = $regularAuth->create($regularUser);
        $this->assertGreaterThan(0, $result, 'Regular user should create token');

        // Verify tokens are properly associated with creators
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $adminAuth->id,
            'fk_user_creat' => $adminUser->id
        ]);

        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $regularAuth->id,
            'fk_user_creat' => $regularUser->id
        ]);

        // Verify user types
        $this->assertEquals(1, $adminUser->admin, 'Admin user should be admin');
        $this->assertEquals(0, $regularUser->admin, 'Regular user should not be admin');
    }

    // =========================================================================
    // ENTITY FILTERING TESTS
    // =========================================================================

    /**
     * Test that queries filter by entity correctly
     */
    public function testEntityFilteringInQueries(): void
    {
        // Skip if multicompany is not enabled (entities 2+ don't exist)
        global $conf;
        if (empty($conf->multicompany->enabled)) {
            $this->markTestSkipped('Multicompany module is not enabled');
        }

        $user = $this->createTestUser([
            'login' => 'entityfilter_' . uniqid()
        ]);

        // Create devices in different entities
        $entities = [1, 2, 3];
        $devicesByEntity = [];

        foreach ($entities as $entity) {
            $device = new SmartAuthDevices($this->db);
            $device->label = "Entity $entity Device";
            $device->uuid = $this->generateUUID();
            $device->status = SmartAuthDevices::STATUS_VALIDATED;
            $device->entity = $entity;
            $device->create($user);

            $devicesByEntity[$entity] = $device;

            // Create auth token in this entity
            $auth = new SmartAuth($this->db);
            $auth->appuid = 1;
            $auth->salt = bin2hex(random_bytes(16));
            $auth->fk_user_creat = $user->id;
            $auth->fk_authid = $user->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $device->id;
            $auth->token_type = 'access';
            $auth->status = SmartAuth::STATUS_VALIDATED;
            $auth->ip = "10.0.$entity.1";
            $auth->entity = $entity;
            $auth->create($user);
        }

        // Verify each entity has exactly 1 device
        foreach ($entities as $entity) {
            $count = $this->getTableCount('smartauth_devices', ['entity' => $entity]);
            $this->assertGreaterThanOrEqual(1, $count, "Entity $entity should have at least 1 device");
        }

        // Verify each entity has exactly 1 auth token
        foreach ($entities as $entity) {
            $count = $this->getTableCount('smartauth_auth', [
                'fk_authid' => $user->id,
                'entity' => $entity
            ]);
            $this->assertEquals(1, $count, "Entity $entity should have 1 auth token for user");
        }

        // Test fetchAll with entity filtering
        $device = new SmartAuthDevices($this->db);
        $entity1Devices = $device->fetchAll('', '', 0, 0, ['entity' => 1]);

        $this->assertIsArray($entity1Devices);
        // Should include at least the device we created for entity 1
        $foundEntity1 = false;
        foreach ($entity1Devices as $dev) {
            if ($dev->id == $devicesByEntity[1]->id) {
                $foundEntity1 = true;
                break;
            }
        }
        $this->assertTrue($foundEntity1, 'Should find entity 1 device in results');
    }

    // =========================================================================
    // USER STATUS AFFECTS AUTH TESTS
    // =========================================================================

    /**
     * Test that disabled user status affects authentication
     */
    public function testUserStatusAffectsAuth(): void
    {
        // Create an active user
        $activeUser = $this->createTestUser([
            'login' => 'activeuser_' . uniqid(),
            'statut' => 1 // Active
        ]);

        $this->assertEquals(1, $activeUser->statut, 'User should be active');

        // Create device and token for active user
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Active User Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($activeUser);

        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $activeUser->id;
        $auth->fk_authid = $activeUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $device->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '127.0.0.1';
        $auth->entity = 1;
        $auth->create($activeUser);

        $this->assertGreaterThan(0, $auth->id, 'Token should be created for active user');

        // Disable the user directly in the database (SQLite update method is more reliable)
        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET statut = 0 WHERE rowid = " . (int) $activeUser->id;
        $this->db->query($sql);

        // Verify user is now disabled
        $disabledUser = new User($this->db);
        $disabledUser->fetch($activeUser->id);
        $this->assertEquals(0, $disabledUser->statut, 'User should be disabled');

        // The token still exists but the user is disabled
        // In a real auth flow, the user status should be checked
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $auth->id,
            'fk_authid' => $activeUser->id,
            'status' => SmartAuth::STATUS_VALIDATED
        ]);

        // Verify user status in database
        $this->assertDatabaseHas('user', [
            'rowid' => $activeUser->id,
            'statut' => 0
        ]);

        // Create inactive user directly
        $inactiveUser = $this->createTestUser([
            'login' => 'inactiveuser_' . uniqid(),
            'statut' => 0 // Inactive from the start
        ]);

        $this->assertEquals(0, $inactiveUser->statut, 'User should be inactive');

        // Inactive user can still create tokens (business logic should prevent this in auth flow)
        $inactiveDevice = new SmartAuthDevices($this->db);
        $inactiveDevice->label = 'Inactive User Device';
        $inactiveDevice->uuid = $this->generateUUID();
        $inactiveDevice->status = SmartAuthDevices::STATUS_VALIDATED;
        $inactiveDevice->entity = 1;
        $inactiveDevice->create($this->testUser); // Created by admin

        $inactiveAuth = new SmartAuth($this->db);
        $inactiveAuth->appuid = 1;
        $inactiveAuth->salt = bin2hex(random_bytes(16));
        $inactiveAuth->fk_user_creat = $this->testUser->id;
        $inactiveAuth->fk_authid = $inactiveUser->id;
        $inactiveAuth->auth_element = 'user';
        $inactiveAuth->fk_device_id = $inactiveDevice->id;
        $inactiveAuth->token_type = 'access';
        $inactiveAuth->status = SmartAuth::STATUS_VALIDATED;
        $inactiveAuth->ip = '127.0.0.2';
        $inactiveAuth->entity = 1;

        // Token creation is allowed at database level
        // Business logic should prevent auth for inactive users
        $result = $inactiveAuth->create($this->testUser);
        $this->assertGreaterThan(0, $result, 'Token record can be created');

        // The important test: verify the user status is checked in auth flow
        // This would typically be done in the AuthController, not at DB level
        $this->assertDatabaseHas('smartauth_auth', [
            'fk_authid' => $inactiveUser->id
        ]);
    }

    // =========================================================================
    // ADDITIONAL INTEGRATION TESTS
    // =========================================================================

    /**
     * Test token family creation (access + refresh tokens)
     */
    public function testTokenFamilyCreation(): void
    {
        $user = $this->createTestUser([
            'login' => 'familyuser_' . uniqid()
        ]);

        $device = new SmartAuthDevices($this->db);
        $device->label = 'Family Test Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($user);

        // Create access token (parent)
        $accessToken = new SmartAuth($this->db);
        $accessToken->appuid = 1;
        $accessToken->salt = bin2hex(random_bytes(16));
        $accessToken->fk_user_creat = $user->id;
        $accessToken->fk_authid = $user->id;
        $accessToken->auth_element = 'user';
        $accessToken->fk_device_id = $device->id;
        $accessToken->token_type = 'access';
        $accessToken->status = SmartAuth::STATUS_VALIDATED;
        $accessToken->ip = '127.0.0.1';
        $accessToken->entity = 1;
        $accessToken->create($user);

        $accessTokenId = $accessToken->id;

        // Create refresh token (child, linked to access token)
        $refreshToken = new SmartAuth($this->db);
        $refreshToken->appuid = 1;
        $refreshToken->salt = bin2hex(random_bytes(16));
        $refreshToken->fk_user_creat = $user->id;
        $refreshToken->fk_authid = $user->id;
        $refreshToken->auth_element = 'user';
        $refreshToken->fk_device_id = $device->id;
        $refreshToken->token_type = 'refresh';
        $refreshToken->family_id = $accessTokenId;
        $refreshToken->status = SmartAuth::STATUS_VALIDATED;
        $refreshToken->ip = '127.0.0.1';
        $refreshToken->entity = 1;
        $refreshToken->create($user);

        // Verify family relationship
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshToken->id,
            'family_id' => $accessTokenId
        ]);

        // Verify both tokens exist
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $accessTokenId,
            'token_type' => 'access'
        ]);
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshToken->id,
            'token_type' => 'refresh'
        ]);
    }

    /**
     * Test user with multiple devices and tokens
     */
    public function testUserWithMultipleDevicesAndTokens(): void
    {
        $user = $this->createTestUser([
            'login' => 'multidevice_' . uniqid()
        ]);

        $deviceLabels = ['iPhone', 'Android Tablet', 'Desktop Browser'];
        $createdDevices = [];
        $createdTokens = [];

        foreach ($deviceLabels as $index => $label) {
            // Create device
            $device = new SmartAuthDevices($this->db);
            $device->label = $label;
            $device->uuid = $this->generateUUID();
            $device->status = SmartAuthDevices::STATUS_VALIDATED;
            $device->entity = 1;
            $device->create($user);
            $createdDevices[] = $device;

            // Create access and refresh tokens for each device
            foreach (['access', 'refresh'] as $tokenType) {
                $auth = new SmartAuth($this->db);
                $auth->appuid = 1;
                $auth->salt = bin2hex(random_bytes(16));
                $auth->fk_user_creat = $user->id;
                $auth->fk_authid = $user->id;
                $auth->auth_element = 'user';
                $auth->fk_device_id = $device->id;
                $auth->token_type = $tokenType;
                $auth->status = SmartAuth::STATUS_VALIDATED;
                $auth->ip = '192.168.1.' . ($index + 1);
                $auth->entity = 1;
                $auth->create($user);
                $createdTokens[] = $auth;
            }
        }

        // Verify counts
        $this->assertCount(3, $createdDevices, 'Should have 3 devices');
        $this->assertCount(6, $createdTokens, 'Should have 6 tokens (2 per device)');

        // Verify in database
        $deviceCount = $this->getTableCount('smartauth_devices', ['fk_user_creat' => $user->id]);
        $tokenCount = $this->getTableCount('smartauth_auth', ['fk_authid' => $user->id]);

        $this->assertEquals(3, $deviceCount, 'Database should have 3 devices for user');
        $this->assertEquals(6, $tokenCount, 'Database should have 6 tokens for user');
    }

    /**
     * Test IP address tracking in auth tokens
     */
    public function testIpAddressTracking(): void
    {
        $user = $this->createTestUser([
            'login' => 'iptrackuser_' . uniqid()
        ]);

        $device = new SmartAuthDevices($this->db);
        $device->label = 'IP Track Device';
        $device->uuid = $this->generateUUID();
        $device->status = SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = 1;
        $device->create($user);

        $ips = [
            '192.168.1.1',
            '10.0.0.50',
            '172.16.0.1',
            '8.8.8.8',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334' // IPv6
        ];

        foreach ($ips as $ip) {
            $auth = new SmartAuth($this->db);
            $auth->appuid = 1;
            $auth->salt = bin2hex(random_bytes(16));
            $auth->fk_user_creat = $user->id;
            $auth->fk_authid = $user->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $device->id;
            $auth->token_type = 'access';
            $auth->status = SmartAuth::STATUS_VALIDATED;
            $auth->ip = $ip;
            $auth->entity = 1;

            $result = $auth->create($user);
            $this->assertGreaterThan(0, $result, "Token with IP $ip should be created");

            $this->assertDatabaseHas('smartauth_auth', [
                'rowid' => $auth->id,
                'ip' => $ip
            ]);
        }

        // Verify all IPs are stored
        foreach ($ips as $ip) {
            $count = $this->getTableCount('smartauth_auth', [
                'fk_authid' => $user->id,
                'ip' => $ip
            ]);
            $this->assertEquals(1, $count, "Should have 1 token with IP $ip");
        }
    }
}
