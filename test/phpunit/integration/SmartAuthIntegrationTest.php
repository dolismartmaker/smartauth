<?php

namespace SmartAuth\Tests\Integration;

require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth;
use SmartAuthDevices;

/**
 * Integration tests for SmartAuth class
 *
 * Tests CRUD operations with real SQLite database
 */
class SmartAuthIntegrationTest extends DolibarrTestCase
{
    /** @var SmartAuthDevices */
    protected $testDevice;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a device for testing
        $this->testDevice = new SmartAuthDevices($this->db);
        $this->testDevice->label = 'Test Device for Auth';
        $this->testDevice->uuid = 'auth-test-device-uuid';
        $this->testDevice->status = SmartAuthDevices::STATUS_DRAFT;
        $this->testDevice->entity = 1;
        $this->testDevice->create($this->testUser);
    }

    /**
     * Test auth token creation
     */
    public function testCreateAuthToken(): void
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
        $auth->ip = '192.168.1.1';
        $auth->entity = 1;

        $result = $auth->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Auth creation should return positive ID");
        $this->assertGreaterThan(0, $auth->id, "Auth ID should be set");

        // Verify in database
        $this->assertDatabaseHas('smartauth_auth', [
            'fk_user_creat' => $this->testUser->id,
            'auth_element' => 'user'
        ]);
    }

    /**
     * Test auth token fetch by ID
     */
    public function testFetchAuthById(): void
    {
        // Create an auth token
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = 'test-salt-12345678';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->entity = 1;
        $auth->create($this->testUser);
        $createdId = $auth->id;

        // Fetch it
        $fetchedAuth = new SmartAuth($this->db);
        $result = $fetchedAuth->fetch($createdId);

        $this->assertGreaterThan(0, $result, "Fetch should return positive value");
        $this->assertEquals($createdId, $fetchedAuth->id);
        $this->assertEquals('test-salt-12345678', $fetchedAuth->salt);
        $this->assertEquals('user', $fetchedAuth->auth_element);
    }

    /**
     * Test auth token update
     */
    public function testUpdateAuth(): void
    {
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = 'original-salt';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->refresh_count = 0;
        $auth->entity = 1;
        $auth->create($this->testUser);
        $authId = $auth->id;

        // Update
        $auth->refresh_count = 5;
        $auth->ip = '10.0.0.1';
        $result = $auth->update($this->testUser);

        $this->assertGreaterThan(0, $result, "Update should return positive value");

        // Verify by fetching again
        $verifyAuth = new SmartAuth($this->db);
        $verifyAuth->fetch($authId);

        $this->assertEquals(5, $verifyAuth->refresh_count);
        $this->assertEquals('10.0.0.1', $verifyAuth->ip);
    }

    /**
     * Test auth token deletion
     */
    public function testDeleteAuth(): void
    {
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = 'delete-test-salt';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->entity = 1;
        $auth->create($this->testUser);

        // Verify it exists
        $this->assertDatabaseHas('smartauth_auth', ['salt' => 'delete-test-salt']);

        // Delete
        $result = $auth->delete($this->testUser);

        $this->assertGreaterThan(0, $result, "Delete should return positive value");

        // Verify it's gone
        $this->assertDatabaseMissing('smartauth_auth', ['salt' => 'delete-test-salt']);
    }

    /**
     * Test fetch returns 0 for non-existent auth
     */
    public function testFetchNonExistentAuth(): void
    {
        $auth = new SmartAuth($this->db);
        $result = $auth->fetch(99999);

        $this->assertEquals(0, $result, "Fetch of non-existent auth should return 0");
    }

    /**
     * Test auth token with refresh token type
     */
    public function testRefreshTokenType(): void
    {
        $accessAuth = new SmartAuth($this->db);
        $accessAuth->appuid = 1;
        $accessAuth->salt = 'access-token-salt';
        $accessAuth->fk_user_creat = $this->testUser->id;
        $accessAuth->fk_authid = $this->testUser->id;
        $accessAuth->auth_element = 'user';
        $accessAuth->fk_device_id = $this->testDevice->id;
        $accessAuth->token_type = 'access';
        $accessAuth->status = SmartAuth::STATUS_VALIDATED;
        $accessAuth->entity = 1;
        $accessAuth->create($this->testUser);

        $refreshAuth = new SmartAuth($this->db);
        $refreshAuth->appuid = 1;
        $refreshAuth->salt = 'refresh-token-salt';
        $refreshAuth->fk_user_creat = $this->testUser->id;
        $refreshAuth->fk_authid = $this->testUser->id;
        $refreshAuth->auth_element = 'user';
        $refreshAuth->fk_device_id = $this->testDevice->id;
        $refreshAuth->token_type = 'refresh';
        $refreshAuth->family_id = $accessAuth->id;
        $refreshAuth->status = SmartAuth::STATUS_VALIDATED;
        $refreshAuth->entity = 1;
        $refreshAuth->create($this->testUser);

        // Verify both tokens
        $fetchedRefresh = new SmartAuth($this->db);
        $fetchedRefresh->fetch($refreshAuth->id);

        $this->assertEquals('refresh', $fetchedRefresh->token_type);
        $this->assertEquals($accessAuth->id, $fetchedRefresh->family_id);
    }

    /**
     * Test multiple auth tokens for same user
     */
    public function testMultipleAuthsForUser(): void
    {
        // Create multiple devices first
        $device2 = new SmartAuthDevices($this->db);
        $device2->label = 'Device 2';
        $device2->uuid = 'multi-auth-device-2';
        $device2->status = SmartAuthDevices::STATUS_DRAFT;
        $device2->entity = 1;
        $device2->create($this->testUser);

        // Create auth tokens for different devices
        $auth1 = new SmartAuth($this->db);
        $auth1->appuid = 1;
        $auth1->salt = 'multi-auth-salt-1';
        $auth1->fk_user_creat = $this->testUser->id;
        $auth1->fk_authid = $this->testUser->id;
        $auth1->auth_element = 'user';
        $auth1->fk_device_id = $this->testDevice->id;
        $auth1->token_type = 'access';
        $auth1->status = SmartAuth::STATUS_VALIDATED;
        $auth1->entity = 1;
        $auth1->create($this->testUser);

        $auth2 = new SmartAuth($this->db);
        $auth2->appuid = 1;
        $auth2->salt = 'multi-auth-salt-2';
        $auth2->fk_user_creat = $this->testUser->id;
        $auth2->fk_authid = $this->testUser->id;
        $auth2->auth_element = 'user';
        $auth2->fk_device_id = $device2->id;
        $auth2->token_type = 'access';
        $auth2->status = SmartAuth::STATUS_VALIDATED;
        $auth2->entity = 1;
        $auth2->create($this->testUser);

        // Count auth tokens for this user
        $count = $this->getTableCount('smartauth_auth', [
            'fk_user_creat' => $this->testUser->id,
            'status' => SmartAuth::STATUS_VALIDATED
        ]);

        $this->assertEquals(2, $count);
    }

    /**
     * Test auth status constants
     */
    public function testAuthConstants(): void
    {
        $this->assertEquals(0, SmartAuth::STATUS_DRAFT);
        $this->assertEquals(1, SmartAuth::STATUS_VALIDATED);
        $this->assertEquals(9, SmartAuth::STATUS_CANCELED);
        $this->assertEquals(10, SmartAuth::STATUS_DISABLED);
    }

    /**
     * Test disable auth token
     */
    public function testDisableAuth(): void
    {
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = 'disable-test-salt';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->entity = 1;
        $auth->create($this->testUser);

        $this->assertEquals(SmartAuth::STATUS_VALIDATED, $auth->status);

        // Disable the auth
        $result = $auth->setDisabled($this->testUser);

        $this->assertGreaterThan(0, $result, "setDisabled should return positive value");
        $this->assertEquals(SmartAuth::STATUS_DISABLED, $auth->status);

        // Verify in database
        $this->assertDatabaseHas('smartauth_auth', [
            'salt' => 'disable-test-salt',
            'status' => SmartAuth::STATUS_DISABLED
        ]);
    }

    /**
     * Test auth with societe_account element
     */
    public function testAuthWithSocieteAccount(): void
    {
        // Create a third party first
        $soc = $this->createTestSociete(['name' => 'Test Client Company']);

        $auth = new SmartAuth($this->db);
        $auth->appuid = 2;
        $auth->salt = 'societe-auth-salt';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $soc->id;
        $auth->auth_element = 'societe_account';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->entity = 1;
        $auth->create($this->testUser);

        // Verify
        $fetchedAuth = new SmartAuth($this->db);
        $fetchedAuth->fetch($auth->id);

        $this->assertEquals('societe_account', $fetchedAuth->auth_element);
        $this->assertEquals($soc->id, $fetchedAuth->fk_authid);
    }

    /**
     * Test auth refresh count increment
     */
    public function testRefreshCountIncrement(): void
    {
        $auth = new SmartAuth($this->db);
        $auth->appuid = 1;
        $auth->salt = 'refresh-count-salt';
        $auth->fk_user_creat = $this->testUser->id;
        $auth->fk_authid = $this->testUser->id;
        $auth->auth_element = 'user';
        $auth->fk_device_id = $this->testDevice->id;
        $auth->token_type = 'access';
        $auth->status = SmartAuth::STATUS_VALIDATED;
        $auth->refresh_count = 0;
        $auth->entity = 1;
        $auth->create($this->testUser);

        // Simulate refresh increments
        for ($i = 1; $i <= 5; $i++) {
            $auth->refresh_count = $i;
            $auth->update($this->testUser);
        }

        // Verify
        $fetchedAuth = new SmartAuth($this->db);
        $fetchedAuth->fetch($auth->id);

        $this->assertEquals(5, $fetchedAuth->refresh_count);
    }

    /**
     * Test IP address storage
     */
    public function testIpAddressStorage(): void
    {
        $ipAddresses = [
            '192.168.1.1',
            '10.0.0.1',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334', // IPv6
            '::1', // localhost IPv6
        ];

        foreach ($ipAddresses as $ip) {
            $auth = new SmartAuth($this->db);
            $auth->appuid = 1;
            $auth->salt = 'ip-test-' . md5($ip);
            $auth->fk_user_creat = $this->testUser->id;
            $auth->fk_authid = $this->testUser->id;
            $auth->auth_element = 'user';
            $auth->fk_device_id = $this->testDevice->id;
            $auth->token_type = 'access';
            $auth->status = SmartAuth::STATUS_VALIDATED;
            $auth->ip = $ip;
            $auth->entity = 1;
            $auth->create($this->testUser);

            $fetchedAuth = new SmartAuth($this->db);
            $fetchedAuth->fetch($auth->id);

            $this->assertEquals($ip, $fetchedAuth->ip, "IP address should be stored correctly: $ip");
        }
    }
}
