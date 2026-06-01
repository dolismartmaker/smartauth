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
        // Serve from public/ to mirror the production vhost: the OAuth2
        // portal is meant to be exposed under a vhost whose document root
        // points at <smartauth>/public/. Same-origin asset references like
        // /assets/css/smartauth.css resolve to <project>/public/assets/...
        // exactly as they do on the deployed server.
        self::$documentRoot = $projectRoot . '/public';

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

    /**
     * /login serves the OAuth2 login HTML page. Catches missing includes
     * in LoginController, broken templates, and (most importantly) a CSP
     * that would block the same-origin CSS / logo that the page references.
     */
    public function testLoginPageRendersHtmlWithFriendlyCsp(): void
    {
        $response = $this->get('/login');
        $this->assertNoPhpFatal($response, '/login');
        $this->assertSame(200, $response['statusCode'], 'Login page not 200. Body: ' . substr($response['body'], 0, 500));
        $this->assertHeaderContains('content-type', 'text/html', $response);

        // The page references /assets/css/smartauth.css and /assets/img/logo.svg
        // via plain same-origin URLs. The CSP must allow style-src 'self'
        // and img-src 'self' otherwise the browser refuses to fetch them.
        $this->assertArrayHasKey('content-security-policy', $response['headers']);
        $csp = $response['headers']['content-security-policy'][0] ?? '';
        $this->assertStringContainsString("style-src 'self'", $csp, "CSP missing style-src 'self': $csp");
        $this->assertStringContainsString("img-src 'self'", $csp, "CSP missing img-src 'self': $csp");
        $this->assertStringNotContainsString("default-src 'none'", $csp, "CSP still set to JSON-API tight default: $csp");

        $this->assertStringContainsString('<form', $response['body'], 'Login HTML body missing the login <form>');
        // Branded credits footer must be on every public HTML view, not
        // only the landing. Asserted here as a representative sample.
        $this->assertStringContainsString('CAP-REL', $response['body'], 'Login page missing the CAP-REL credit');
        $this->assertStringContainsString('Portail SSO', $response['body'], 'Login page missing the "Portail SSO" eyebrow');
    }

    /**
     * The default stylesheet must be reachable as a same-origin static
     * asset under /assets/css/. The smoke server is configured with
     * documentRoot = public/, mirroring the production vhost.
     */
    public function testLoginStylesheetIsReachable(): void
    {
        $response = $this->get('/assets/css/smartauth.css');
        $this->assertSame(200, $response['statusCode'], 'CSS not served. Body: ' . substr($response['body'], 0, 200));
        $this->assertHeaderContains('content-type', 'css', $response);
    }

    /**
     * The default logo must be reachable. We do NOT assert on the SVG
     * payload itself - shipping a different default logo is fine - but
     * the file must exist under public/assets/img/ and be served.
     */
    public function testLoginLogoIsReachable(): void
    {
        $response = $this->get('/assets/img/logo.svg');
        $this->assertSame(200, $response['statusCode'], 'Logo not served. Body: ' . substr($response['body'], 0, 200));
        $this->assertHeaderContains('content-type', 'image', $response);
    }

    /**
     * The root URL must serve the public landing HTML, not the JSON 404
     * fallback. It must include the 3 action cards (login is always
     * shown, register/account default to enabled) and link to /login,
     * /register and /account. Catches a missing LandingController
     * include, a broken template, or a regression in the / dispatch.
     */
    public function testLandingPageRendersThreeCards(): void
    {
        $response = $this->get('/');
        $this->assertNoPhpFatal($response, '/');
        $this->assertSame(200, $response['statusCode'], 'Landing not 200. Body: ' . substr($response['body'], 0, 500));
        $this->assertHeaderContains('content-type', 'text/html', $response);

        $body = $response['body'];
        $this->assertStringContainsString('Portail SSO', $body, 'Landing missing the "Portail SSO" eyebrow');
        $this->assertStringContainsString('Se connecter', $body, 'Landing missing the login card label');
        $this->assertStringContainsString('href="/login"', $body, 'Landing missing the /login link');
        $this->assertStringContainsString('Créer un compte', $body, 'Landing missing the register card label');
        $this->assertStringContainsString('href="/register"', $body, 'Landing missing the /register link');
        $this->assertStringContainsString('Mon compte', $body, 'Landing missing the account card label');
        $this->assertStringContainsString('href="/account"', $body, 'Landing missing the /account link');

        // Credits footer: must surface the CAP-REL brand, the AGPL
        // license, the public source-code repo and the OIDC discovery
        // link for devs.
        $this->assertStringContainsString('CAP-REL', $body, 'Landing missing the CAP-REL credit');
        $this->assertStringContainsString('cap-rel.fr', $body, 'Landing missing the CAP-REL link target');
        $this->assertStringContainsString('inligit.fr/cap-rel/dolibarr/plugin-smartauth', $body, 'Landing missing the source-code link');
        $this->assertStringContainsString('agpl-3.0', $body, 'Landing missing the AGPL link');
        $this->assertStringContainsString('/.well-known/openid-configuration', $body, 'Landing missing the OIDC discovery link');
    }

    /**
     * /forgot-password renders the email-entry HTML form. Catches a
     * missing dispatch in public/index.php, a missing template, or a
     * load-time failure in PasswordHtmlController.
     */
    public function testForgotPasswordRendersEmailForm(): void
    {
        $response = $this->get('/forgot-password');
        $this->assertNoPhpFatal($response, '/forgot-password');
        $this->assertSame(200, $response['statusCode'], 'Forgot-password not 200. Body: ' . substr($response['body'], 0, 500));
        $this->assertHeaderContains('content-type', 'text/html', $response);
        $this->assertStringContainsString('<form', $response['body'], 'Forgot-password HTML missing the <form>');
        $this->assertStringContainsString('name="email"', $response['body'], 'Forgot-password form missing email input');
        $this->assertStringContainsString('CAP-REL', $response['body'], 'Forgot-password missing the credits footer');
        $this->assertStringContainsString('Portail SSO', $response['body'], 'Forgot-password missing the eyebrow');
    }

    /**
     * /reset-password renders the new-password HTML form, with the
     * token and email from the query string pre-filled in the form.
     */
    public function testResetPasswordRendersNewPasswordForm(): void
    {
        $response = $this->get('/reset-password?token=dummy123.4000000000&email=foo%40bar.fr');
        $this->assertNoPhpFatal($response, '/reset-password');
        $this->assertSame(200, $response['statusCode'], 'Reset-password not 200. Body: ' . substr($response['body'], 0, 500));
        $this->assertHeaderContains('content-type', 'text/html', $response);
        $this->assertStringContainsString('<form', $response['body'], 'Reset-password HTML missing the <form>');
        $this->assertStringContainsString('name="password"', $response['body'], 'Reset-password form missing password input');
        $this->assertStringContainsString('name="password_confirm"', $response['body'], 'Reset-password form missing password confirmation input');
        $this->assertStringContainsString('value="foo@bar.fr"', $response['body'], 'Reset-password form did not pre-fill email from the query string');
        $this->assertStringContainsString('value="dummy123.4000000000"', $response['body'], 'Reset-password form did not pre-fill token from the query string');
        $this->assertStringContainsString('CAP-REL', $response['body'], 'Reset-password missing the credits footer');
        $this->assertStringContainsString('Portail SSO', $response['body'], 'Reset-password missing the eyebrow');
    }
}
