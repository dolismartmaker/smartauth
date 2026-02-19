<?php

namespace SmartAuth\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for HTTP functional tests
 *
 * Launches PHP built-in server with dolibarr-integration-sqlite bootstrap
 * and makes real HTTP requests to test headers, status codes, and responses.
 *
 * @requires PHP >= 8.2
 */
abstract class HttpTestCase extends TestCase
{
    /** @var int Server port */
    protected static int $serverPort = 8899;

    /** @var int|null Server process ID */
    protected static ?int $serverPid = null;

    /** @var string Server base URL */
    protected static string $baseUrl;

    /** @var HttpClientInterface HTTP client */
    protected HttpClientInterface $client;

    /** @var string Path to router script */
    protected static string $routerPath;

    /** @var string Path to htdocs for server document root */
    protected static string $documentRoot;

    /**
     * Start the PHP built-in server before all tests in this class
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $projectRoot = dirname(__DIR__, 3);
        self::$routerPath = $projectRoot . '/test/http/router.php';
        self::$documentRoot = $projectRoot;

        // Find an available port
        self::$serverPort = self::findAvailablePort(8899);
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;

        // Start PHP built-in server
        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s %s > /tmp/php_http_test_%d.log 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg(self::$documentRoot),
            escapeshellarg(self::$routerPath),
            self::$serverPort
        );

        $output = [];
        exec($command, $output);
        self::$serverPid = (int)($output[0] ?? 0);

        if (self::$serverPid <= 0) {
            throw new \RuntimeException('Failed to start PHP built-in server');
        }

        // Wait for server to be ready
        $maxAttempts = 50;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $socket = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(100000); // 100ms
            $attempt++;
        }

        if ($attempt >= $maxAttempts) {
            self::stopServer();
            throw new \RuntimeException(
                'PHP server did not start in time. Check /tmp/php_http_test_' . self::$serverPort . '.log'
            );
        }
    }

    /**
     * Stop the PHP built-in server after all tests
     */
    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        parent::tearDownAfterClass();
    }

    /**
     * Stop the server process
     */
    protected static function stopServer(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            // Kill the server process and its children
            exec('kill ' . self::$serverPid . ' 2>/dev/null');
            exec('pkill -P ' . self::$serverPid . ' 2>/dev/null');
            self::$serverPid = null;
        }
    }

    /**
     * Find an available port starting from the given port
     */
    protected static function findAvailablePort(int $startPort): int
    {
        $port = $startPort;
        $maxPort = $startPort + 100;

        while ($port < $maxPort) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (!$socket) {
                return $port;
            }
            fclose($socket);
            $port++;
        }

        throw new \RuntimeException('Could not find available port in range ' . $startPort . '-' . $maxPort);
    }

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->client = HttpClient::create([
            'timeout' => 10,
            'max_redirects' => 0,
        ]);
    }

    /**
     * Make a GET request
     */
    protected function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * Make a POST request
     */
    protected function post(string $path, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /**
     * Make an HTTP request and return response data
     *
     * @return array{statusCode: int, headers: array, body: string, json: ?array}
     */
    protected function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $url = self::$baseUrl . $path;

        $options = ['headers' => $headers];
        if (!empty($body) && $method !== 'GET') {
            $options['body'] = $body;
        }

        $response = $this->client->request($method, $url, $options);

        $statusCode = $response->getStatusCode();
        $responseHeaders = $response->getHeaders(false);
        $responseBody = $response->getContent(false);

        // Try to decode JSON
        $json = null;
        $contentType = $responseHeaders['content-type'][0] ?? '';
        if (strpos($contentType, 'json') !== false) {
            $json = json_decode($responseBody, true);
        }

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'json' => $json,
        ];
    }

    /**
     * Assert that response has a specific status code
     */
    protected function assertStatusCode(int $expected, array $response): void
    {
        $this->assertEquals(
            $expected,
            $response['statusCode'],
            "Expected status code $expected, got {$response['statusCode']}. Body: " . substr($response['body'], 0, 500)
        );
    }

    /**
     * Assert that response has a specific header
     */
    protected function assertHeader(string $name, string $expectedValue, array $response): void
    {
        $name = strtolower($name);
        $this->assertArrayHasKey($name, $response['headers'], "Header '$name' not found");
        $this->assertEquals($expectedValue, $response['headers'][$name][0]);
    }

    /**
     * Assert that response header contains a value
     */
    protected function assertHeaderContains(string $name, string $needle, array $response): void
    {
        $name = strtolower($name);
        $this->assertArrayHasKey($name, $response['headers'], "Header '$name' not found");
        $this->assertStringContainsString($needle, $response['headers'][$name][0]);
    }

    /**
     * Assert that response body contains a string
     */
    protected function assertBodyContains(string $needle, array $response): void
    {
        $this->assertStringContainsString($needle, $response['body']);
    }

    /**
     * Assert that response is valid JSON
     */
    protected function assertJsonResponse(array $response): void
    {
        $this->assertHeaderContains('content-type', 'json', $response);
        $this->assertNotNull($response['json'], 'Response is not valid JSON');
    }

    /**
     * Assert JSON response has a key
     */
    protected function assertJsonHasKey(string $key, array $response): void
    {
        $this->assertJsonResponse($response);
        $this->assertArrayHasKey($key, $response['json']);
    }

    /**
     * Assert JSON response key equals value
     */
    protected function assertJsonEquals(string $key, $expected, array $response): void
    {
        $this->assertJsonHasKey($key, $response);
        $this->assertEquals($expected, $response['json'][$key]);
    }
}
