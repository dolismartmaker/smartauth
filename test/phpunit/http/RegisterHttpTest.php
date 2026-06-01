<?php

/**
 * HTTP functional tests for /register (Lot 5 of the SSO spec).
 *
 * Runs against the PHP built-in server with the dolibarr-integration-sqlite
 * bootstrap. Validates:
 *   - GET /register renders the form HTML
 *   - GET /register?client_id=... injects branding (client name) when
 *     a known OAuth2 client is passed
 *   - POST /register without a CSRF token (cookie-less request) is
 *     rejected and re-renders the form with an error
 *
 * @covers \SmartAuth\Api\Account\RegisterController
 */

namespace SmartAuth\Tests\Http;

class RegisterHttpTest extends HttpTestCase
{
    /**
     * Client primary key created in setUpBeforeClass and reused in tests.
     * @var int|null
     */
    private static ?int $brandedClientPk = null;

    /**
     * Public client_id of the branded test client.
     * @var string
     */
    private static string $brandedClientId = '';

    /**
     * Branded display name used for assertion.
     */
    private const BRANDED_CLIENT_NAME = 'CapTodo Test Branding';

    /**
     * Seed a branded OAuth client via the test-only /_test/seed-oauth-client
     * route so the /register branding test has a real client to point at.
     * The seed runs within the same PHP server process so it shares the
     * RAM-mapped SQLite database used by the live HTTP requests.
     */
    public function seedBrandedClient(): void
    {
        if (self::$brandedClientPk !== null) {
            return;
        }
        self::$brandedClientId = 'branded-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $url = '/_test/seed-oauth-client?client_id=' . urlencode(self::$brandedClientId)
            . '&name=' . urlencode(self::BRANDED_CLIENT_NAME);
        $response = $this->get($url);
        if ($response['statusCode'] === 200 && isset($response['json']['client_pk'])) {
            self::$brandedClientPk = (int) $response['json']['client_pk'];
        } else {
            self::$brandedClientPk = -1;
        }
    }

    public function testGetRegisterReturnsHtmlForm(): void
    {
        $response = $this->get('/register');

        $this->assertStatusCode(200, $response);
        $this->assertHeaderContains('content-type', 'text/html', $response);
        $this->assertBodyContains('Créer un compte', $response);
        $this->assertBodyContains('name="email"', $response);
        $this->assertBodyContains('name="password"', $response);
        $this->assertBodyContains('name="csrf_token"', $response);
        $this->assertBodyContains('action="/register"', $response);
    }

    public function testGetRegisterWithUnknownClientFallsBackToGenericBranding(): void
    {
        $response = $this->get('/register?client_id=does-not-exist');

        $this->assertStatusCode(200, $response);
        $this->assertBodyContains('Créer un compte', $response);
        // No branded client name should appear
        $this->assertStringNotContainsString(self::BRANDED_CLIENT_NAME, $response['body']);
    }

    public function testGetRegisterWithKnownClientShowsBrandingName(): void
    {
        $this->seedBrandedClient();
        $this->assertNotNull(self::$brandedClientPk, 'seed must run');
        $this->assertGreaterThan(0, self::$brandedClientPk, 'Seed must succeed (got: ' . var_export(self::$brandedClientPk, true) . ')');

        $response = $this->get('/register?client_id=' . self::$brandedClientId);

        $this->assertStatusCode(200, $response);
        // The hidden client_id field must reflect the requested client
        $this->assertBodyContains('value="' . self::$brandedClientId . '"', $response);
        $this->assertBodyContains(self::BRANDED_CLIENT_NAME, $response);
    }

    public function testPostRegisterWithoutCsrfTokenIsRejected(): void
    {
        // Cookieless POST -> the server has no session-stored CSRF token,
        // so any submitted CSRF must fail to validate. The form is rendered
        // again with the global error message instead of creating an account.
        $response = $this->post('/register', [
            'email' => 'csrftest_' . uniqid() . '@example.com',
            'password' => 'SuperLong1Password',
            'password_confirm' => 'SuperLong1Password',
            'firstname' => 'Csrf',
            'lastname' => 'Test',
            'accept_cgu' => '1',
            'csrf_token' => 'forged-token',
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertStatusCode(200, $response);
        $this->assertBodyContains('Session expiree', $response);
        // Should NOT redirect to /register/sent equivalent (i.e. NOT show
        // the "verify your inbox" success page).
        $this->assertStringNotContainsString('Verifiez votre boite mail', $response['body']);
    }
}
