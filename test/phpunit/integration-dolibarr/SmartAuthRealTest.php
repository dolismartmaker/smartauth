<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth;
use SmartAuthDevices;

/**
 * Integration tests for SmartAuth with real Dolibarr
 */
class SmartAuthRealTest extends DolibarrRealTestCase
{
    /** @var SmartAuthDevices */
    protected $testDevice;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a device for testing
        $this->testDevice = new SmartAuthDevices($this->db);
        $this->testDevice->label = 'Test Device';
        $this->testDevice->uuid = 'test-device-' . uniqid();
        $this->testDevice->status = SmartAuthDevices::STATUS_DRAFT;
        $this->testDevice->entity = 1;
        $this->testDevice->create($this->testUser);
    }

    /**
     * Test that Dolibarr is properly loaded
     */
    public function testDolibarrLoaded(): void
    {
        $this->assertTrue(defined('DOL_DOCUMENT_ROOT'), 'DOL_DOCUMENT_ROOT should be defined');
        $this->assertInstanceOf(\DoliDB::class, $this->db, 'Database should be DoliDB instance');
        $this->assertGreaterThan(0, $this->testUser->id, 'Test user should have an ID');
    }

    /**
     * Test SmartAuthDevices with real CommonObject
     */
    public function testSmartAuthDevicesCreate(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Real Dolibarr Device';
        $device->uuid = 'real-uuid-' . uniqid();
        $device->description = 'Test with real Dolibarr';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;

        $result = $device->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Device creation should succeed");
        $this->assertGreaterThan(0, $device->id, "Device ID should be set");

        // Verify fetch works
        $fetchedDevice = new SmartAuthDevices($this->db);
        $fetchResult = $fetchedDevice->fetch($device->id);

        $this->assertGreaterThan(0, $fetchResult, "Fetch should succeed");
        $this->assertEquals('Real Dolibarr Device', $fetchedDevice->label);
    }

    /**
     * Test SmartAuth with real CommonObject
     */
    public function testSmartAuthCreate(): void
    {
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->ip = '127.0.0.1';
        $auth->entity = 1;

        $result = $auth->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Auth creation should succeed");
        $this->assertGreaterThan(0, $auth->id, "Auth ID should be set");

        // Verify fetch works
        $fetchedAuth = new SmartAuth($this->db);
        $fetchResult = $fetchedAuth->fetch($auth->id);

        $this->assertGreaterThan(0, $fetchResult, "Fetch should succeed");
        $this->assertEquals('user', $fetchedAuth->auth_element);
        $this->assertEquals($this->testUser->id, $fetchedAuth->fk_authid);
    }

    /**
     * Test creating a Societe with real Dolibarr
     */
    public function testCreateSociete(): void
    {
        $soc = $this->createTestSociete([
            'name' => 'Test Company Real'
        ]);

        $this->assertGreaterThan(0, $soc->id, "Societe should have an ID");

        // Now create auth for societe
        $auth = new SmartAuth($this->db);
        $auth->appuid = 2;
        $auth->salt = bin2hex(random_bytes(16));
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $soc->id;
        $auth->auth_element = 'societe_account';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->entity = 1;

        $result = $auth->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Auth for societe should succeed");
    }

    /**
     * Test update and delete operations
     */
    public function testUpdateAndDelete(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'To Update';
        $device->uuid = 'update-uuid-' . uniqid();
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $deviceId = $device->id;

        // Update
        $device->label = 'Updated Label';
        $updateResult = $device->update($this->testUser);
        $this->assertGreaterThan(0, $updateResult, "Update should succeed");

        // Verify update
        $verifyDevice = new SmartAuthDevices($this->db);
        $verifyDevice->fetch($deviceId);
        $this->assertEquals('Updated Label', $verifyDevice->label);

        // Delete
        $deleteResult = $device->delete($this->testUser);
        $this->assertGreaterThan(0, $deleteResult, "Delete should succeed");

        // Verify deleted
        $deletedDevice = new SmartAuthDevices($this->db);
        $fetchResult = $deletedDevice->fetch($deviceId);
        $this->assertEquals(0, $fetchResult, "Fetch of deleted device should return 0");
    }
}
