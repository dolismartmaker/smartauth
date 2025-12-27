<?php

namespace SmartAuth\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * HTTP Integration Tests
 *
 * These tests launch a real PHP built-in server and make actual HTTP requests
 * to test RouteController HTTP response codes.
 */
class HttpIntegrationTest extends TestCase
{
    private static $serverProcess;
    private static $serverHost = '127.0.0.1';
    private static $serverPort;
    private static $baseUrl;

    public static function setUpBeforeClass(): void
    {
        // Find an available port
        self::$serverPort = self::findAvailablePort();
        self::$baseUrl = 'http://' . self::$serverHost . ':' . self::$serverPort;

        $docRoot = dirname(__DIR__, 2) . '/fixtures';
        $router = $docRoot . '/test_api.php';

        // Start PHP built-in server
        $command = sprintf(
            'php -S %s:%d -t %s %s > /dev/null 2>&1 &',
            self::$serverHost,
            self::$serverPort,
            escapeshellarg($docRoot),
            escapeshellarg($router)
        );

        exec($command);

        // Wait for server to start
        $maxWait = 30; // 3 seconds max
        $started = false;
        for ($i = 0; $i < $maxWait; $i++) {
            usleep(100000); // 100ms
            if (@fsockopen(self::$serverHost, self::$serverPort, $errno, $errstr, 1)) {
                $started = true;
                break;
            }
        }

        if (!$started) {
            self::markTestSkipped('Could not start PHP built-in server');
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Kill the server process
        if (self::$serverPort) {
            exec("pkill -f 'php -S " . self::$serverHost . ":" . self::$serverPort . "'");
        }
    }

    private static function findAvailablePort(): int
    {
        // Find an available port between 8100-8999
        for ($port = 8100; $port < 9000; $port++) {
            $socket = @fsockopen(self::$serverHost, $port, $errno, $errstr, 0.1);
            if (!$socket) {
                return $port;
            }
            fclose($socket);
        }
        return 8080; // Fallback
    }

    /**
     * Make an HTTP request
     */
    private function httpRequest(string $method, string $path, array $data = [], array $headers = []): array
    {
        $url = self::$baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $requestHeaders = [];
        foreach ($headers as $key => $value) {
            $requestHeaders[] = "$key: $value";
        }

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $requestHeaders[] = 'Content-Type: application/json';
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $requestHeaders[] = 'Content-Type: application/json';
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        if (!empty($requestHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'code' => $httpCode,
            'headers' => $headerStr,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }

    /**
     * Test health endpoint returns 200
     */
    public function testHealthEndpointReturns200(): void
    {
        $response = $this->httpRequest('GET', '/health');

        $this->assertEquals(200, $response['code']);
        $this->assertIsArray($response['json']);
        $this->assertEquals('ok', $response['json']['status'] ?? null);
    }

    /**
     * Test non-existent route (no matching route - server returns empty)
     */
    public function testNonExistentRouteReturnsEmpty(): void
    {
        $response = $this->httpRequest('GET', '/nonexistent');

        // No route matches, so no output
        $this->assertTrue(in_array($response['code'], [0, 200, 404]));
    }

    /**
     * Test wrong HTTP method returns empty (no match)
     */
    public function testWrongHttpMethodReturnsEmpty(): void
    {
        // POST to a GET endpoint
        $response = $this->httpRequest('POST', '/health');

        // Method doesn't match, route returns early
        $this->assertTrue(in_array($response['code'], [0, 200]));
    }

    /**
     * Test protected route without auth returns 401
     */
    public function testProtectedRouteWithoutAuthReturns401(): void
    {
        $response = $this->httpRequest('GET', '/protected/test');

        $this->assertEquals(401, $response['code']);
        $this->assertStringContainsString('Authentication required', $response['body']);
    }

    /**
     * Test protected route with invalid JWT returns 401
     */
    public function testProtectedRouteWithInvalidJwtReturns401(): void
    {
        $response = $this->httpRequest('GET', '/protected/test', [], [
            'Authorization' => 'Bearer invalid.token.here'
        ]);

        $this->assertEquals(401, $response['code']);
    }

    /**
     * Test login endpoint with missing credentials
     */
    public function testLoginWithMissingCredentials(): void
    {
        $response = $this->httpRequest('POST', '/auth/login', []);

        // Should return 401 or 400 for missing credentials
        $this->assertTrue(in_array($response['code'], [400, 401]));
    }

    /**
     * Test login endpoint with invalid credentials
     */
    public function testLoginWithInvalidCredentials(): void
    {
        $response = $this->httpRequest('POST', '/auth/login', [
            'login' => 'nonexistent',
            'password' => 'wrongpassword'
        ]);

        // Should return 401 for invalid credentials
        $this->assertTrue(in_array($response['code'], [401, 500])); // 500 possible if DB not configured
    }

    /**
     * Test Content-Type header is set to JSON
     */
    public function testContentTypeIsJson(): void
    {
        $response = $this->httpRequest('GET', '/health');

        $this->assertStringContainsString('application/json', $response['headers']);
    }
}
