<?php

namespace SmartAuth\Tests\Integration;

require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuthDevices;

/**
 * Integration tests for SmartAuthDevices class
 *
 * Tests CRUD operations with real SQLite database
 */
class SmartAuthDevicesIntegrationTest extends DolibarrTestCase
{
    /**
     * Test device creation
     */
    public function testCreateDevice(): void
    {
        $device = new SmartAuthDevices($this->db);

        $device->label = 'My iPhone';
        $device->uuid = '550e8400-e29b-41d4-a716-446655440000';
        $device->description = 'Test device description';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;

        $result = $device->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Device creation should return positive ID");
        $this->assertGreaterThan(0, $device->id, "Device ID should be set");

        // Verify in database
        $this->assertDatabaseHas('smartauth_devices', [
            'label' => 'My iPhone',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000'
        ]);
    }

    /**
     * Test device fetch by ID
     */
    public function testFetchDeviceById(): void
    {
        // First create a device
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Test Device';
        $device->uuid = 'fetch-test-uuid-12345';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);
        $createdId = $device->id;

        // Now fetch it
        $fetchedDevice = new SmartAuthDevices($this->db);
        $result = $fetchedDevice->fetch($createdId);

        $this->assertGreaterThan(0, $result, "Fetch should return positive value");
        $this->assertEquals($createdId, $fetchedDevice->id);
        $this->assertEquals('Test Device', $fetchedDevice->label);
        $this->assertEquals('fetch-test-uuid-12345', $fetchedDevice->uuid);
    }

    /**
     * Test device fetch by ref
     */
    public function testFetchDeviceByRef(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->ref = 'DEV-TEST-001';
        $device->label = 'Device by Ref';
        $device->uuid = 'ref-test-uuid';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $fetchedDevice = new SmartAuthDevices($this->db);
        $result = $fetchedDevice->fetch(0, 'DEV-TEST-001');

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('Device by Ref', $fetchedDevice->label);
    }

    /**
     * Test device fetch by UUID
     */
    public function testFetchDeviceByUuid(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'UUID Device';
        $device->uuid = 'unique-uuid-for-fetch-test';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $fetchedDevice = new SmartAuthDevices($this->db);
        $result = $fetchedDevice->fetch(null, null, 'unique-uuid-for-fetch-test');

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('UUID Device', $fetchedDevice->label);
    }

    /**
     * Test device update
     */
    public function testUpdateDevice(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Original Label';
        $device->uuid = 'update-test-uuid';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);
        $deviceId = $device->id;

        // Update
        $device->label = 'Updated Label';
        $device->description = 'Added description';
        $result = $device->update($this->testUser);

        $this->assertGreaterThan(0, $result, "Update should return positive value");

        // Verify by fetching again
        $verifyDevice = new SmartAuthDevices($this->db);
        $verifyDevice->fetch($deviceId);

        $this->assertEquals('Updated Label', $verifyDevice->label);
    }

    /**
     * Test device validation (status change)
     * Note: SmartAuthDevices::create() auto-validates, so status is VALIDATED after create
     */
    public function testValidateDevice(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Device to Validate';
        $device->uuid = 'validate-test-uuid';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        // SmartAuthDevices::create() auto-validates the device
        $this->assertEquals(SmartAuthDevices::STATUS_VALIDATED, $device->status);

        // Re-validation of already validated device returns 0 (no action taken)
        $result = $device->validate($this->testUser);
        $this->assertEquals(0, $result, "Re-validate of already validated device returns 0");

        // Status should remain validated
        $this->assertEquals(SmartAuthDevices::STATUS_VALIDATED, $device->status);

        // Verify in database
        $this->assertDatabaseHas('smartauth_devices', [
            'uuid' => 'validate-test-uuid',
            'status' => SmartAuthDevices::STATUS_VALIDATED
        ]);
    }

    /**
     * Test device deletion
     */
    public function testDeleteDevice(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = 'Device to Delete';
        $device->uuid = 'delete-test-uuid';
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);
        $deviceId = $device->id;

        // Verify it exists
        $this->assertDatabaseHas('smartauth_devices', ['uuid' => 'delete-test-uuid']);

        // Delete
        $result = $device->delete($this->testUser);

        $this->assertGreaterThan(0, $result, "Delete should return positive value");

        // Verify it's gone
        $this->assertDatabaseMissing('smartauth_devices', ['uuid' => 'delete-test-uuid']);
    }

    /**
     * Test fetch returns 0 for non-existent device
     */
    public function testFetchNonExistentDevice(): void
    {
        $device = new SmartAuthDevices($this->db);
        $result = $device->fetch(99999);

        $this->assertEquals(0, $result, "Fetch of non-existent device should return 0");
    }

    /**
     * Test multiple devices for same user
     * Note: SmartAuthDevices::create() auto-validates all devices
     */
    public function testMultipleDevicesPerUser(): void
    {
        $device1 = new SmartAuthDevices($this->db);
        $device1->label = 'Device 1';
        $device1->uuid = 'multi-device-uuid-1';
        $device1->status = SmartAuthDevices::STATUS_DRAFT;
        $device1->entity = 1;
        $device1->create($this->testUser);

        $device2 = new SmartAuthDevices($this->db);
        $device2->label = 'Device 2';
        $device2->uuid = 'multi-device-uuid-2';
        $device2->status = SmartAuthDevices::STATUS_DRAFT;
        $device2->entity = 1;
        $device2->create($this->testUser);

        $device3 = new SmartAuthDevices($this->db);
        $device3->label = 'Device 3';
        $device3->uuid = 'multi-device-uuid-3';
        $device3->status = SmartAuthDevices::STATUS_DRAFT;
        $device3->entity = 1;
        $device3->create($this->testUser);

        // Count devices for this user
        $count = $this->getTableCount('smartauth_devices', [
            'fk_user_creat' => $this->testUser->id
        ]);

        $this->assertEquals(3, $count);

        // All devices are auto-validated by create()
        $validatedCount = $this->getTableCount('smartauth_devices', [
            'fk_user_creat' => $this->testUser->id,
            'status' => SmartAuthDevices::STATUS_VALIDATED
        ]);

        $this->assertEquals(3, $validatedCount);
    }

    /**
     * Test device constants are correct
     */
    public function testDeviceConstants(): void
    {
        $this->assertEquals(0, SmartAuthDevices::STATUS_DRAFT);
        $this->assertEquals(1, SmartAuthDevices::STATUS_VALIDATED);
        $this->assertEquals(9, SmartAuthDevices::STATUS_CANCELED);
    }

    /**
     * Test device with special characters in label
     */
    public function testDeviceWithSpecialCharacters(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->label = "John's iPhone (Work) - été 2024";
        $device->uuid = 'special-chars-uuid';
        $device->description = "Description with <html> & special chars: éàü";
        $device->status = SmartAuthDevices::STATUS_DRAFT;
        $device->entity = 1;
        $device->create($this->testUser);

        $fetchedDevice = new SmartAuthDevices($this->db);
        $fetchedDevice->fetch($device->id);

        $this->assertEquals("John's iPhone (Work) - été 2024", $fetchedDevice->label);
    }

    /**
     * Test initAsSpecimen
     * Note: SmartAuthDevices::initAsSpecimen() doesn't return a value
     */
    public function testInitAsSpecimen(): void
    {
        $device = new SmartAuthDevices($this->db);
        $device->initAsSpecimen();

        // Specimen should have id = 0
        $this->assertEquals(0, $device->id);
        // Specimen should have some default values
        $this->assertNotEmpty($device->ref);
    }
}
