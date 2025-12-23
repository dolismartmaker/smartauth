<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../class/smartlogs.class.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartLogs;
use SmartAuth;
use SmartAuthDevices;

/**
 * Integration tests for SmartLogs with real Dolibarr database
 */
class SmartLogsRealTest extends DolibarrRealTestCase
{
    /** @var SmartAuthDevices */
    private $testDevice;

    /** @var SmartAuth */
    private $testAuth;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a device for testing
        $this->testDevice = new SmartAuthDevices($this->db);
        $this->testDevice->label = 'Test Device for Logs';
        $this->testDevice->uuid = 'logs-test-device-' . uniqid();
        $this->testDevice->status = SmartAuthDevices::STATUS_DRAFT;
        $this->testDevice->entity = 1;
        $this->testDevice->create($this->testUser);

        // Create an auth token for testing
        $this->testAuth = new SmartAuth($this->db);
        $this->testAuth->appuid = 1;
        $this->testAuth->salt = bin2hex(random_bytes(16));
        $this->testAuth->fk_user_creat = $this->testUser->id;
        $this->testAuth->fk_authid = $this->testUser->id;
        $this->testAuth->auth_element = 'user';
        $this->testAuth->fk_device_id = $this->testDevice->id;
        $this->testAuth->token_type = 'access';
        $this->testAuth->status = SmartAuth::STATUS_VALIDATED;
        $this->testAuth->ip = '127.0.0.1';
        $this->testAuth->entity = 1;
        $this->testAuth->create($this->testUser);
    }

    /**
     * Test SmartLogs create
     */
    public function testCreateLog(): void
    {
        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '1';
        $log->entity = 1;
        $log->dol_element = 'user';
        $log->ip = '192.168.1.100';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->bytes_sent = 1024;
        $log->content_type = 'application/json';
        $log->url_requested = '/api/users/1';
        $log->user_agent = 'Mozilla/5.0 Test';
        $log->fk_device_id = $this->testDevice->id;

        $result = $log->create($this->testUser);

        $this->assertGreaterThan(0, $result, "Log creation should succeed");
        $this->assertGreaterThan(0, $log->id, "Log should have an ID");
    }

    /**
     * Test SmartLogs fetch
     */
    public function testFetchLog(): void
    {
        // Create a log first
        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '2';
        $log->entity = 1;
        $log->dol_element = 'societe';
        $log->ip = '10.0.0.5';
        $log->method = 'POST';
        $log->http_status = 201;
        $log->bytes_sent = 2048;
        $log->content_type = 'application/json';
        $log->url_requested = '/api/thirdparties';
        $log->user_agent = 'Test Agent';
        $log->create($this->testUser);

        $logId = $log->id;

        // Fetch it
        $fetchedLog = new SmartLogs($this->db);
        $result = $fetchedLog->fetch($logId);

        $this->assertGreaterThan(0, $result, "Fetch should succeed");
        $this->assertEquals('societe', $fetchedLog->dol_element);
        $this->assertEquals('POST', $fetchedLog->method);
        $this->assertEquals(201, $fetchedLog->http_status);
        $this->assertEquals('10.0.0.5', $fetchedLog->ip);
    }

    /**
     * Test SmartLogs update
     */
    public function testUpdateLog(): void
    {
        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '3';
        $log->entity = 1;
        $log->ip = '127.0.0.1';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->create($this->testUser);

        $logId = $log->id;

        // Update
        $log->http_status = 404;
        $log->method = 'DELETE';
        $result = $log->update($this->testUser);

        $this->assertGreaterThan(0, $result, "Update should succeed");

        // Verify
        $verifyLog = new SmartLogs($this->db);
        $verifyLog->fetch($logId);
        $this->assertEquals(404, $verifyLog->http_status);
        $this->assertEquals('DELETE', $verifyLog->method);
    }

    /**
     * Test SmartLogs delete
     */
    public function testDeleteLog(): void
    {
        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '4';
        $log->entity = 1;
        $log->ip = '127.0.0.1';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->create($this->testUser);

        $logId = $log->id;

        // Delete
        $result = $log->delete($this->testUser);
        $this->assertGreaterThan(0, $result, "Delete should succeed");

        // Verify deleted
        $deletedLog = new SmartLogs($this->db);
        $fetchResult = $deletedLog->fetch($logId);
        $this->assertEquals(0, $fetchResult, "Fetch of deleted log should return 0");
    }

    /**
     * Test logging different HTTP methods
     */
    public function testLogDifferentHttpMethods(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        foreach ($methods as $method) {
            $log = new SmartLogs($this->db);
            $log->fk_key = $this->testAuth->id;
            $log->appuid = '5';
            $log->entity = 1;
            $log->ip = '127.0.0.1';
            $log->method = $method;
            $log->http_status = 200;
            $log->url_requested = '/api/test';

            $result = $log->create($this->testUser);
            $this->assertGreaterThan(0, $result, "Log for $method should be created");
        }

        // Verify all were created
        $count = $this->getTableCount('smartauth_logs', ['appuid' => '5']);
        $this->assertEquals(5, $count);
    }

    /**
     * Test logging different HTTP status codes
     */
    public function testLogDifferentStatusCodes(): void
    {
        $statusCodes = [200, 201, 400, 401, 403, 404, 500];

        foreach ($statusCodes as $status) {
            $log = new SmartLogs($this->db);
            $log->fk_key = $this->testAuth->id;
            $log->appuid = '6';
            $log->entity = 1;
            $log->ip = '127.0.0.1';
            $log->method = 'GET';
            $log->http_status = $status;

            $result = $log->create($this->testUser);
            $this->assertGreaterThan(0, $result, "Log for status $status should be created");
        }
    }

    /**
     * Test logging with device reference
     */
    public function testLogWithDeviceReference(): void
    {
        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '7';
        $log->entity = 1;
        $log->ip = '192.168.1.1';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->fk_device_id = $this->testDevice->id;

        $result = $log->create($this->testUser);
        $this->assertGreaterThan(0, $result);

        // Verify device link
        $this->assertDatabaseHas('smartauth_logs', [
            'rowid' => $log->id,
            'fk_device_id' => $this->testDevice->id
        ]);
    }

    /**
     * Test log with long URL
     */
    public function testLogWithLongUrl(): void
    {
        $longUrl = '/api/test?' . str_repeat('param=value&', 100);

        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '8';
        $log->entity = 1;
        $log->ip = '127.0.0.1';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->url_requested = $longUrl;

        $result = $log->create($this->testUser);
        $this->assertGreaterThan(0, $result, "Should handle long URLs");
    }

    /**
     * Test log with user agent
     */
    public function testLogWithUserAgent(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

        $log = new SmartLogs($this->db);
        $log->fk_key = $this->testAuth->id;
        $log->appuid = '9';
        $log->entity = 1;
        $log->ip = '127.0.0.1';
        $log->method = 'GET';
        $log->http_status = 200;
        $log->user_agent = $userAgent;

        $result = $log->create($this->testUser);
        $this->assertGreaterThan(0, $result);

        // Verify
        $fetchedLog = new SmartLogs($this->db);
        $fetchedLog->fetch($log->id);
        $this->assertEquals($userAgent, $fetchedLog->user_agent);
    }

    /**
     * Test multiple logs for same auth token
     */
    public function testMultipleLogsForSameAuth(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $log = new SmartLogs($this->db);
            $log->fk_key = $this->testAuth->id;
            $log->appuid = '10';
            $log->entity = 1;
            $log->ip = '127.0.0.1';
            $log->method = 'GET';
            $log->http_status = 200;
            $log->url_requested = "/api/endpoint/$i";

            $result = $log->create($this->testUser);
            $this->assertGreaterThan(0, $result);
        }

        $count = $this->getTableCount('smartauth_logs', ['fk_key' => $this->testAuth->id, 'appuid' => '10']);
        $this->assertEquals(10, $count);
    }
}
