<?php

/**
 * HTTP smoke tests for public/index.php (the OAuth2 front controller).
 *
 * These tests exist to catch a bug class the integration-dolibarr PHPUnit
 * suite cannot see: a controller file that declares `use SomeTrait;`
 * inside its class body WITHOUT also `dol_include_once`-ing the trait
 * file at the top.
 *
 * Background:
 *   The integration suite scans test/phpunit/integration-dolibarr/ before
 *   running anything. Some test files require_once the trait files at
 *   file top-level for their own setup (TokenControllerIntegrationTest,
 *   UserinfoControllerIntegrationTest, RevocationControllerIntegrationTest).
 *   That warms the traits in memory before any single test runs. So if
 *   DiscoveryController forgets to require its trait, the trait is
 *   already there at load time -> no error. In production the same
 *   controller is loaded by public/index.php with NO test-side preload,
 *   and PHP fatals on `class X { use ResponseTrait; }`.
 *
 * This harness spawns a dedicated `php -S` whose router (oauth_smoke_router)
 * bootstraps Dolibarr ONLY and then `require`s public/index.php. Each
 * test hits a route handled by public/index.php; if the include chain
 * has a missing piece, the very first request fatals and the test fails
 * with a 500 + a "Fatal" string in the body.
 *
 * What this catches (non-exhaustive):
 *   - missing dol_include_once of a trait inside an OAuth2 controller
 *   - missing dol_include_once of a class referenced as a `use ... as ...`
 *     alias or instantiated with `new`
 *   - syntax error introduced in any file public/index.php loads
 *   - composer autoload mis-config that breaks a Bearer extraction helper
 *
 * What this does NOT catch:
 *   - business-logic regressions (covered by the integration suite)
 *   - bugs in the Dolibarr SQLite bootstrap (covered by router.php tests)
 *
 * @requires PHP >= 8.2
 */

namespace SmartAuth\Tests\Http;

require_once __DIR__ . '/HttpTestCase.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OAuthFrontControllerSmokeTest extends HttpTestCase
{
    /** @var int Reserved start port for the OAuth smoke server. */
    private const SMOKE_PORT_START = 8950;

    public static function setUpBeforeClass(): void
    {
        // We deliberately do NOT call parent::setUpBeforeClass() because
        // the parent uses router.php (which pre-loads SmartAuth helpers,
        // defeating the point of this smoke). Instead, spawn our own
        // server pointing at oauth_smoke_router.php.
        \PHPUnit\Framework\TestCase::setUpBeforeClass();

        $projectRoot = dirname(__DIR__, 3);
        self::$routerPath = $projectRoot . '/test/http/oauth_smoke_router.php';
        self::$documentRoot = $projectRoot;

        self::$serverPort = self::findAvailablePort(self::SMOKE_PORT_START);
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;

        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s %s > /tmp/php_oauth_smoke_%d.log 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg(self::$documentRoot),
            escapeshellarg(self::$routerPath),
            self::$serverPort
        );

        $output = [];
        exec($command, $output);
        self::$serverPid = (int) ($output[0] ?? 0);

        if (self::$serverPid <= 0) {
            throw new \RuntimeException('Failed to start OAuth smoke server');
        }

        $maxAttempts = 50;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $socket = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(100000);
            $attempt++;
        }

        if ($attempt >= $maxAttempts) {
            self::stopServer();
            throw new \RuntimeException(
                'OAuth smoke server did not start in time. Check /tmp/php_oauth_smoke_' . self::$serverPort . '.log'
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        // Clean up RAM DB scoped to this smoke server's port.
        $ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $ramDbPath = $ramDiskPath . '/smartauth_oauth_smoke_port_' . self::$serverPort . '.sdb';
        $markerPath = $ramDbPath . '.ready';

        if (file_exists($markerPath)) {
            @unlink($markerPath);
        }
        if (file_exists($ramDbPath)) {
            @unlink($ramDbPath);
        }

        $projectRoot = dirname(__DIR__, 3);
        $originalDbPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/documents/database_dolibarr.sdb';
        if (is_link($originalDbPath)) {
            @unlink($originalDbPath);
        }
        if (file_exists($originalDbPath . '.backup')) {
            @copy($originalDbPath . '.backup', $originalDbPath);
            @unlink($originalDbPath . '.backup');
        }

        \PHPUnit\Framework\TestCase::tearDownAfterClass();
    }

    /**
     * Common assertion: the response body must not contain any PHP fatal
     * marker. We deliberately accept any HTTP status here -- the smoke
     * is about LOAD-TIME health, not endpoint semantics. A 4xx response
     * with a clean JSON body is perfectly valid; a 500 with "Trait X
     * not found" in the body is the failure we want to detect.
     */
    private function assertNoPhpFatal(array $response, string $context): void
    {
        $body = $response['body'];
        $fatalMarkers = [
            'Fatal error',
            'Parse error',
            'Trait ',          // "Trait XXX not found"
            'Class ',          // "Class XXX not found" (false positives on legit text are unlikely on JSON OAuth responses)
            'Uncaught Error',
            'Uncaught TypeError',
        ];
        foreach ($fatalMarkers as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                "$context: response body contains '$marker' which suggests a PHP load-time failure. Body excerpt: "
                    . substr($body, 0, 500)
            );
        }
    }

    public function testDiscoveryOpenidConfigurationLoadsCleanly(): void
    {
        $response = $this->get('/.well-known/openid-configuration');
        $this->assertNoPhpFatal($response, '/.well-known/openid-configuration');
        $this->assertNotSame(500, $response['statusCode'], 'Unexpected 500 on openid-configuration. Body: ' . substr($response['body'], 0, 500));
        $this->assertJsonResponse($response);
        $this->assertJsonHasKey('issuer', $response);
    }

    public function testDiscoveryJwksLoadsCleanly(): void
    {
        $response = $this->get('/.well-known/jwks.json');
        $this->assertNoPhpFatal($response, '/.well-known/jwks.json');
        $this->assertNotSame(500, $response['statusCode'], 'Unexpected 500 on jwks.json. Body: ' . substr($response['body'], 0, 500));
        $this->assertJsonResponse($response);
        $this->assertJsonHasKey('keys', $response);
    }

    /**
     * /oauth/token without credentials must respond with a 4xx JSON error
     * (invalid_request / unsupported_grant_type / invalid_client). The
     * exact status depends on parameter parsing order; we only care that
     * the controller LOADED and reached its body.
     */
    public function testOauthTokenEndpointReachableWithoutFatal(): void
    {
        $response = $this->post('/oauth/token', []);
        $this->assertNoPhpFatal($response, '/oauth/token');
        $this->assertGreaterThanOrEqual(400, $response['statusCode']);
        $this->assertLessThan(500, $response['statusCode'], 'Unexpected 5xx on /oauth/token. Body: ' . substr($response['body'], 0, 500));
    }

    /**
     * /oauth/userinfo without a Bearer must respond 401. Catches a
     * missing include in UserinfoController.
     */
    public function testOauthUserinfoReachableWithoutFatal(): void
    {
        $response = $this->get('/oauth/userinfo');
        $this->assertNoPhpFatal($response, '/oauth/userinfo');
        $this->assertNotSame(500, $response['statusCode'], 'Unexpected 500 on /oauth/userinfo. Body: ' . substr($response['body'], 0, 500));
    }

    /**
     * /oauth/revoke without a token still has to parse the request body
     * and reply 4xx; catches a missing include in RevocationController.
     */
    public function testOauthRevokeReachableWithoutFatal(): void
    {
        $response = $this->post('/oauth/revoke', []);
        $this->assertNoPhpFatal($response, '/oauth/revoke');
        $this->assertNotSame(500, $response['statusCode'], 'Unexpected 500 on /oauth/revoke. Body: ' . substr($response['body'], 0, 500));
    }

    /**
     * /oauth/authorize without parameters must reply 4xx (invalid_request
     * or similar); catches a missing include in AuthorizationController.
     */
    public function testOauthAuthorizeReachableWithoutFatal(): void
    {
        $response = $this->get('/oauth/authorize');
        $this->assertNoPhpFatal($response, '/oauth/authorize');
        $this->assertNotSame(500, $response['statusCode'], 'Unexpected 500 on /oauth/authorize. Body: ' . substr($response['body'], 0, 500));
    }
}
