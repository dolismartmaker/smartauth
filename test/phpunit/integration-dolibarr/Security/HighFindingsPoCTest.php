<?php

/**
 * Regression / proof-of-concept tests for the HIGH findings of
 * docs/TODO-SECURITY-01.md.
 *
 * Each test starts as a fail-when-broken assertion that locks in the fix:
 * if a future commit reintroduces the vulnerability, the corresponding
 * test fails immediately.
 *
 * Findings covered (Lot A - quick wins):
 *   - H-1: get_client_ip() centralisation (rate-limit IP bypass)
 *   - H-2: empty login skips per-user rate limit
 *   - H-3: rate-limit key not normalised (case sensitivity)
 *   - H-15: verifySecret() returns true when stored hash is empty
 *   - H-19: open redirect on /logout?redirect=
 *
 * Findings covered (Lot B - JWT/OAuth2 BCP):
 *   - H-4: silent MD5 fallback in OAuth2 LoginController
 *   - H-5: account enumeration via timing on login() failure path
 *   - H-10: rotateRefreshToken not atomic
 *   - H-11: no refresh-token replay detection
 *   - H-12: issuer derived from HTTP_HOST without validation
 *   - H-14: mobile _decodeJWT does not validate iss/nbf/iat/typ
 *   - H-16: client_credentials does not verify the service user is active
 *
 * Findings covered (Lot C - web hardening):
 *   - H-6: CORS allow-list (no '*' default on JWT API)
 *   - H-7: OAuth2 preflight CORS hardening
 *   - H-8: baseline security headers (CSP/HSTS/XCTO/XFO/Referrer-Policy)
 *   - H-9: account session has explicit SameSite=Lax / HttpOnly
 *
 * Findings covered (Lot D - infra/files):
 *   - H-13: RSA private key out of llx_const, on-disk with chmod 0600
 *   - H-17: layered path-traversal defence with realpath() boundary
 *   - H-18: executable-extension denylist on uploads
 *
 * @group security-high
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Security;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\RouteController;
use SmartAuth\Api\AuthController;

dol_include_once('/smartauth/api/RouteController.php');
dol_include_once('/smartauth/api/AuthController.php');
dol_include_once('/smartauth/api/OAuth2/LoginController.php');
dol_include_once('/smartauth/api/Account/RegisterController.php');
dol_include_once('/smartauth/api/Account/AccountController.php');
dol_include_once('/smartauth/api/SmartUpload.php');
dol_include_once('/smartauth/class/smartauthoauthclient.class.php');

class HighFindingsPoCTest extends DolibarrRealTestCase
{
    /** Backup of $_SERVER keys we mutate, to restore after each test. */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    /**
     * Restrict the input we expose to RouteController::get_client_ip()
     * to a known set, then put it back after the assertion.
     */
    private function withServer(array $overrides, callable $assertion): void
    {
        $stripPrefix = ['HTTP_', 'REMOTE_'];
        foreach ($_SERVER as $key => $_) {
            foreach ($stripPrefix as $p) {
                if (strpos($key, $p) === 0) {
                    unset($_SERVER[$key]);
                    break;
                }
            }
        }
        foreach ($overrides as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $assertion();
    }

    // =================================================================
    //  H-1 - get_client_ip() trusts forwarding headers
    // =================================================================

    /**
     * Regression test for H-1.
     *
     * - When REMOTE_ADDR is a public IP not listed in SMARTAUTH_TRUSTED_PROXIES,
     *   forwarded headers MUST be ignored.
     * - When REMOTE_ADDR is private/loopback, forwarded headers ARE honoured
     *   (legitimate proxy chain).
     * - Each facade (AuthController + Login/Register/Account) must delegate
     *   to RouteController::get_client_ip and therefore yield the same value.
     */
    public function testH1_GetClientIpHonoursTrustedProxiesOnly(): void
    {
        global $conf;

        // Make sure no proxy is whitelisted by default
        $conf->global->SMARTAUTH_TRUSTED_PROXIES = '';

        // ---- Untrusted source: header is ignored ----
        $this->withServer([
            'REMOTE_ADDR' => '203.0.113.42', // public IP (TEST-NET-3)
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '1.2.3.4',
        ], function () {
            $this->assertSame(
                '203.0.113.42',
                RouteController::get_client_ip(),
                'H-1 fix: forwarded headers must be ignored from untrusted sources'
            );
            $this->assertSame(
                '203.0.113.42',
                AuthController::get_client_ip(),
                'H-1 fix: AuthController facade must delegate to the secure resolver'
            );
        });

        // ---- Private source: header IS honoured (typical reverse proxy) ----
        $this->withServer([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_REAL_IP' => '5.6.7.8',
        ], function () {
            $this->assertSame(
                '5.6.7.8',
                RouteController::get_client_ip(),
                'Private REMOTE_ADDR + X-Real-IP must yield the forwarded value'
            );
        });

        // ---- Explicit allow-list: header is honoured for that proxy ----
        $conf->global->SMARTAUTH_TRUSTED_PROXIES = '198.51.100.7';
        $this->withServer([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
        ], function () {
            $this->assertSame(
                '9.9.9.9',
                RouteController::get_client_ip(),
                'H-1: trusted proxy in allow-list must enable XFF parsing'
            );
        });

        // ---- Static check: facades delegate, no inline header parsing ----
        $files = [
            'api/AuthController.php' => 'get_client_ip',
            'api/OAuth2/LoginController.php' => 'getClientIp',
            'api/Account/RegisterController.php' => 'getClientIp',
            'api/Account/AccountController.php' => 'getClientIp',
        ];
        foreach ($files as $rel => $fn) {
            $source = file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
            $body = $this->extractFunctionBody($source, $fn);
            $this->assertNotEmpty($body, "$rel: function $fn not found");
            $this->assertStringContainsString(
                'RouteController::get_client_ip',
                $body,
                "H-1 fix: $rel:$fn must delegate to RouteController::get_client_ip"
            );
            $this->assertStringNotContainsString(
                'CF_CONNECTING_IP',
                $body,
                "H-1 fix: $rel:$fn must not parse CF-Connecting-IP locally"
            );
            $this->assertStringNotContainsString(
                'X_FORWARDED_FOR',
                $body,
                "H-1 fix: $rel:$fn must not parse X-Forwarded-For locally"
            );
        }
    }

    // =================================================================
    //  H-2 - empty login skips per-user rate limit
    // =================================================================

    /**
     * Regression test for H-2: an empty / unicode-stripped login is now
     * rejected with 401 *before* it can bypass the per-user rate limit.
     * The login() method also normalises the rate-limit key via strtolower
     * (H-3 fix), and we lock both invariants in source.
     */
    public function testH2_EmptyLoginIsRejectedFastBeforeRateLimit(): void
    {
        $controller = new AuthController();

        $response = $controller->login([
            'email' => '',
            'password' => 'whatever',
        ]);

        $this->assertSame(401, $response[1], 'empty login must yield 401, not pass through');
        $this->assertSame('Invalid credentials', $response[0]['error']);

        // Static check: the function fast-fails on empty $login
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/AuthController.php');
        $loginBody = $this->extractFunctionBody($source, 'login');
        $this->assertMatchesRegularExpression(
            "/empty\\(\\\$login\\)[^}]*'Invalid credentials'/s",
            $loginBody,
            'H-2 fix: login() must short-circuit when sanitised login is empty'
        );
    }

    // =================================================================
    //  H-3 - rate-limit key not normalised
    // =================================================================

    /**
     * Regression test for H-3: the rate-limit key used by login() must be
     * lower-cased so that "Admin", "ADMIN" and "aDmIn" share a single
     * counter. We assert this structurally because the runtime side
     * effects depend on the rate limiter's persisted state.
     */
    public function testH3_RateLimitKeyIsLowercased(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/AuthController.php');
        $loginBody = $this->extractFunctionBody($source, 'login');

        $this->assertMatchesRegularExpression(
            '/\$rateLimitKey\s*=\s*strtolower\(\$login\)/',
            $loginBody,
            'H-3 fix: login() must lowercase the username before using it as a rate-limit key'
        );

        // checkLimit / recordAttempt must use the normalised key, not raw $login
        $this->assertStringContainsString(
            "checkLimit(\n\t\t\t\$rateLimitKey,",
            $loginBody,
            'H-3 fix: checkLimit must receive the normalised key'
        );
    }

    // =================================================================
    //  H-15 - verifySecret returns true when stored hash is empty
    // =================================================================

    /**
     * Regression test for H-15: a SmartAuthOAuthClient whose stored
     * client_secret is empty must NOT validate any presented secret.
     * Previously it returned true, which meant a misconfigured confidential
     * client would accept arbitrary secrets.
     */
    public function testH15_VerifySecretRejectsEmptyStoredHash(): void
    {
        $client = new \SmartAuthOAuthClient($this->db);
        $client->client_id = 'h15-test';
        $client->client_secret = ''; // misconfigured / forgotten secret

        $this->assertFalse(
            $client->verifySecret(''),
            'H-15 fix: empty stored hash must reject empty input'
        );
        $this->assertFalse(
            $client->verifySecret('any-attacker-supplied-value'),
            'H-15 fix: empty stored hash must reject any input'
        );

        // With a real bcrypt hash, the function must still validate normally.
        $client->client_secret = password_hash('s3cret', PASSWORD_BCRYPT);
        $this->assertTrue($client->verifySecret('s3cret'));
        $this->assertFalse($client->verifySecret('wrong'));
    }

    // =================================================================
    //  H-19 - open redirect on /logout?redirect=
    // =================================================================

    /**
     * Regression test for H-19. The /logout handler in public/index.php
     * must validate the requested redirect target. We assert this via the
     * source (the runtime exits, not easy to drive in PHPUnit) and via a
     * direct unit test of the validation logic by re-running the same
     * conditional manually.
     */
    public function testH19_LogoutRedirectIsValidated(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        // The handler must have a whitelist concept and must reject
        // protocol-relative variants.
        $this->assertStringContainsString(
            'SMARTAUTH_LOGOUT_REDIRECT_WHITELIST',
            $source,
            'H-19 fix: /logout handler must consult an explicit host whitelist'
        );
        $this->assertStringContainsString(
            "rejected redirect to non-whitelisted host",
            $source,
            'H-19 fix: /logout handler must log rejected hosts'
        );

        // Re-implement the validation logic as a pure function and unit-test it.
        $validate = function (string $requested, array $whitelist = []): string {
            if ($requested === '') {
                return '/';
            }
            // Same-origin relative path
            if ($requested[0] === '/'
                && (!isset($requested[1]) || ($requested[1] !== '/' && $requested[1] !== '\\'))) {
                return $requested;
            }
            $parsed = parse_url($requested);
            if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host'])) {
                $isHttp = in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
                if ($isHttp && in_array(strtolower($parsed['host']), array_map('strtolower', $whitelist), true)) {
                    return $requested;
                }
            }
            return '/';
        };

        // Same-origin paths: accepted
        $this->assertSame('/', $validate('/'));
        $this->assertSame('/dashboard', $validate('/dashboard'));
        $this->assertSame('/some/path?x=1', $validate('/some/path?x=1'));

        // Open redirect attempts: rejected to '/'
        $this->assertSame('/', $validate('//evil.com'),  'protocol-relative must be rejected');
        $this->assertSame('/', $validate('/\\evil.com'), 'backslash-prefixed must be rejected');
        $this->assertSame('/', $validate('http://evil.com/path'));
        $this->assertSame('/', $validate('https://evil.com'));
        $this->assertSame('/', $validate('javascript:alert(1)'));

        // Whitelisted host: accepted
        $this->assertSame(
            'https://app.example.com/post-logout',
            $validate('https://app.example.com/post-logout', ['app.example.com'])
        );
        // Whitelist match is case-insensitive on host
        $this->assertSame(
            'https://APP.example.com/x',
            $validate('https://APP.example.com/x', ['app.example.com'])
        );
        // Different host is still rejected
        $this->assertSame(
            '/',
            $validate('https://other.example.com', ['app.example.com'])
        );
    }

    // =================================================================
    //  H-4 - silent MD5 fallback in OAuth2 LoginController
    // =================================================================

    /**
     * Regression test for H-4: the legacy MD5 fallback path no longer uses
     * the leaky '!==' comparison and logs a clear warning so operators can
     * plan the bcrypt migration.
     */
    public function testH4_LoginControllerMd5FallbackUsesHashEquals(): void
    {
        // The OAuth web-login credential check (incl. the legacy MD5 fallback)
        // moved from LoginController to SubjectAuthenticator (subject cutover).
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/SubjectAuthenticator.php');

        $this->assertStringContainsString(
            "hash_equals(\$storedHash, md5(\$password))",
            $source,
            'H-4 fix: MD5 fallback must use hash_equals (constant time) - no !==/=='
        );
        $this->assertStringNotContainsString(
            "\$storedHash !== md5(\$password)",
            $source,
            'H-4 fix: the timing-leaky !== comparison must be gone'
        );
        $this->assertStringContainsString(
            'legacy MD5 password matched',
            $source,
            'H-4 fix: an MD5 match must be logged so the admin is warned'
        );
    }

    // =================================================================
    //  H-5 - timing-based account enumeration on login()
    // =================================================================

    /**
     * Regression test for H-5: both AuthController::login and
     * LoginController authenticate paths now perform a dummy
     * password_verify against a *valid* bcrypt hash on the failure
     * branch, so user-enumeration via response time is mitigated.
     */
    public function testH5_FailedAuthDoesDummyPasswordVerify(): void
    {
        $authSrc = file_get_contents(dirname(__DIR__, 4) . '/api/AuthController.php');
        // The OAuth web-login credential check moved from LoginController to
        // SubjectAuthenticator (subject cutover); the dummy-hash guarantee
        // lives there now.
        $oauthSrc = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/SubjectAuthenticator.php');

        // AuthController must call its dummy hash helper on the failure branch
        $this->assertStringContainsString(
            'password_verify($pass, self::_getDummyBcryptHash())',
            $authSrc,
            'H-5 fix: AuthController::login must perform a dummy password_verify on empty-login path'
        );
        $this->assertStringContainsString(
            'private static function _getDummyBcryptHash',
            $authSrc,
            'H-5 fix: AuthController must expose a static dummy-hash helper'
        );

        // The dummy hash must be syntactically valid bcrypt (i.e. produced
        // by password_hash(..., PASSWORD_BCRYPT)) - otherwise password_verify
        // short-circuits to false instantly and the timing leak remains.
        $this->assertMatchesRegularExpression(
            '/password_hash\(.*PASSWORD_BCRYPT.*\)/',
            $authSrc,
            'H-5 fix: AuthController dummy hash must be a real bcrypt hash'
        );
        $this->assertMatchesRegularExpression(
            '/password_hash\(.*PASSWORD_BCRYPT.*\)/',
            $oauthSrc,
            'H-5 fix: OAuth SubjectAuthenticator dummy hash must be a real bcrypt hash'
        );

        // Old broken dummy must be gone
        $this->assertStringNotContainsString(
            '$2y$10$dummy.hash.to.prevent.timing.attacks.here',
            $oauthSrc,
            'H-5 fix: the syntactically invalid dummy bcrypt string must be removed'
        );
    }

    // =================================================================
    //  Two-silo admission - SSO door refuses internal users (usr:)
    //  (DECISION_2026-06-02_modele-identite-deux-silos.md)
    // =================================================================

    /**
     * The OAuth2/SSO login door must admit only external subjects (acc:/mbr:).
     * An internal Dolibarr user (usr:) belongs to the PWA/mobile JWT silo and
     * must be refused at the SSO portal, closed by default (with a documented
     * SMARTAUTH_SSO_ALLOW_INTERNAL_USER escape hatch).
     */
    public function testSsoDoorRefusesInternalUserSubject(): void
    {
        $src = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/LoginController.php');

        $this->assertStringContainsString(
            '$subject->isUser()',
            $src,
            'SSO door must test for an internal user subject'
        );
        $this->assertStringContainsString(
            "getDolGlobalInt('SMARTAUTH_SSO_ALLOW_INTERNAL_USER', 0)",
            $src,
            'SSO door must be closed by default to internal users (escape hatch defaults to 0)'
        );
        // The refusal must happen before the session is created.
        $refusePos = strpos($src, "refused at SSO door");
        $sessionPos = strpos($src, 'sessionManager->createSession');
        $this->assertNotFalse($refusePos);
        $this->assertNotFalse($sessionPos);
        $this->assertLessThan($sessionPos, $refusePos, 'Internal-user refusal must precede session creation');
    }

    // =================================================================
    //  H-10 + H-11 - atomic rotation + replay detection
    // =================================================================

    /**
     * Regression test for H-10/H-11.
     *
     * - rotateRefreshToken now wraps revoke+create in a transaction with a
     *   conditional UPDATE so two concurrent calls cannot both succeed.
     * - validateRefreshToken triggers full-family revocation when an
     *   already-revoked refresh token is presented (RFC 9700 §2.2.2).
     */
    public function testH10H11_RefreshRotationIsAtomicAndDetectsReplay(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/TokenService.php');

        $rotateBody = $this->extractFunctionBody($source, 'rotateRefreshToken');
        $validateBody = $this->extractFunctionBody($source, 'validateRefreshToken');

        // H-10: transactional + conditional revoke
        $this->assertStringContainsString(
            '$this->db->begin()',
            $rotateBody,
            'H-10 fix: rotateRefreshToken must run inside a DB transaction'
        );
        $this->assertStringContainsString(
            'AND revoked_at IS NULL',
            $rotateBody,
            'H-10 fix: rotateRefreshToken must use a conditional UPDATE for atomicity'
        );
        $this->assertStringContainsString(
            'affected_rows',
            $rotateBody,
            'H-10 fix: rotateRefreshToken must check affected_rows to detect concurrent reuse'
        );

        // H-11: replay -> family revocation
        $this->assertStringContainsString(
            '$this->revokeFamily(',
            $validateBody,
            'H-11 fix: validateRefreshToken must revoke the family on replay'
        );
        $this->assertStringContainsString(
            'replay detected',
            $validateBody,
            'H-11 fix: replay detection must be logged'
        );
        $this->assertNotEmpty(
            $this->extractFunctionBody($source, 'revokeFamily'),
            'H-11 fix: revokeFamily helper must exist'
        );
    }

    // =================================================================
    //  H-12 - issuer derived from HTTP_HOST without validation
    // =================================================================

    /**
     * Regression test for H-12.
     */
    public function testH12_IssuerHostInjectionIsBlocked(): void
    {
        global $conf;

        // Configure the allow-list to a known good host
        $conf->global->SMARTAUTH_OAUTH_ISSUER = '';
        $conf->global->SMARTAUTH_ISSUER_ALLOWED_HOSTS = 'auth.example.com';

        $this->withServer([
            'REMOTE_ADDR' => '203.0.113.1',
            'SERVER_NAME' => '',
            'HTTP_HOST' => 'evil.com', // attacker-controlled
        ], function () {
            $issuer = \SmartAuth\Api\OAuth2\OAuthConfig::getIssuer();
            $this->assertStringNotContainsString(
                'evil.com',
                $issuer,
                'H-12 fix: HTTP_HOST not in allow-list must not leak into the issuer'
            );
            $this->assertStringContainsString(
                'auth.example.com',
                $issuer,
                'H-12 fix: a host from the allow-list is used instead'
            );
        });

        // SERVER_NAME is preferred over HTTP_HOST when no allow-list is set
        $conf->global->SMARTAUTH_ISSUER_ALLOWED_HOSTS = '';
        $this->withServer([
            'REMOTE_ADDR' => '203.0.113.1',
            'SERVER_NAME' => 'auth.example.com',
            'HTTP_HOST' => 'evil.com',
        ], function () {
            $issuer = \SmartAuth\Api\OAuth2\OAuthConfig::getIssuer();
            $this->assertStringContainsString('auth.example.com', $issuer);
            $this->assertStringNotContainsString('evil.com', $issuer);
        });

        // X-Forwarded-Proto from a non-trusted proxy is ignored
        $this->withServer([
            'REMOTE_ADDR' => '203.0.113.5', // public, untrusted
            'SERVER_NAME' => 'auth.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'gopher', // attacker-supplied junk
        ], function () {
            $issuer = \SmartAuth\Api\OAuth2\OAuthConfig::getIssuer();
            $this->assertStringStartsWith('http://', $issuer); // not 'gopher://'
        });
    }

    // =================================================================
    //  H-14 - mobile _decodeJWT does not validate iss/iat/nbf/typ
    // =================================================================

    /**
     * Regression test for H-14.
     */
    public function testH14_DecodeJwtChecksIssNbfIatTyp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/AuthController.php');

        // _generateToken emits the new claims
        $genBody = $this->extractFunctionBody($source, '_generateToken');
        $this->assertStringContainsString('"iss"', $genBody, 'H-14 fix: tokens must carry iss');
        $this->assertStringContainsString('"iat"', $genBody, 'H-14 fix: tokens must carry iat');
        $this->assertStringContainsString('"nbf"', $genBody, 'H-14 fix: tokens must carry nbf');
        $this->assertStringContainsString(
            "'typ' => 'JWT'",
            $genBody,
            'H-14 fix: tokens must carry header.typ=JWT'
        );

        // _decodeJWT verifies them
        $decodeBody = $this->extractFunctionBody($source, '_decodeJWT');
        $this->assertStringContainsString(
            '$decoded->iss !== $expectedIss',
            $decodeBody,
            'H-14 fix: _decodeJWT must reject a wrong issuer when iss is present'
        );
        $this->assertStringContainsString(
            '$decoded->nbf',
            $decodeBody,
            'H-14 fix: _decodeJWT must check nbf'
        );
        $this->assertStringContainsString(
            '$decoded->iat',
            $decodeBody,
            'H-14 fix: _decodeJWT must check iat'
        );
        $this->assertStringContainsString(
            "header['typ']",
            $decodeBody,
            'H-14 fix: _decodeJWT must inspect the header.typ claim'
        );
    }

    // =================================================================
    //  H-16 - client_credentials does not verify the service user is active
    // =================================================================

    /**
     * Regression test for H-16.
     */
    public function testH16_ClientCredentialsRejectsInactiveServiceUser(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/TokenController.php');

        $this->assertStringContainsString(
            'service user not found',
            strtolower($source),
            'H-16 fix: missing service user must be detected and logged'
        );
        $this->assertStringContainsString(
            'service user disabled',
            strtolower($source),
            'H-16 fix: disabled service user must be detected and logged'
        );
        $this->assertMatchesRegularExpression(
            '/serviceUser->statut.*!==\s*1/',
            $source,
            'H-16 fix: TokenController must check serviceUser->statut === 1'
        );
    }

    // =================================================================
    //  H-6 - CORS allow-list
    // =================================================================

    public function testH6_CorsHonoursAllowListAndDefaultsToNoCors(): void
    {
        // No configuration -> no CORS at all
        $this->assertSame('', RouteController::resolveCorsOrigin('', 'https://anything.example'));

        // Wildcard is allowed but logged (we can't observe the log here)
        $this->assertSame('*', RouteController::resolveCorsOrigin('*', 'https://x'));

        // Allow-list with matching origin -> echoed back exactly
        $this->assertSame(
            'https://app.example.com',
            RouteController::resolveCorsOrigin('https://app.example.com,https://other.example', 'https://app.example.com')
        );

        // Allow-list without matching origin -> empty (no CORS)
        $this->assertSame(
            '',
            RouteController::resolveCorsOrigin('https://app.example.com', 'https://evil.example')
        );

        // Allow-list with no Origin header -> empty (same-origin request)
        $this->assertSame('', RouteController::resolveCorsOrigin('https://app.example.com', ''));

        // Static check: '*' is no longer the default
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/RouteController.php');
        $this->assertStringNotContainsString(
            "getDolGlobalString('SMARTAUTH_CORS_ORIGIN', '*')",
            $source,
            "H-6 fix: SMARTAUTH_CORS_ORIGIN must default to '' (no CORS)"
        );
    }

    // =================================================================
    //  H-7 - OAuth2 preflight allow-list
    // =================================================================

    public function testH7_OAuth2PreflightUsesAllowList(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        // The hardcoded '*' in the preflight is gone
        $this->assertStringNotContainsString(
            "header('Access-Control-Allow-Origin: *')",
            $source,
            'H-7 fix: preflight must not hardcode Access-Control-Allow-Origin: *'
        );

        // Replaced by the centralised resolver
        $this->assertStringContainsString(
            'RouteController::resolveCorsOrigin',
            $source,
            'H-7 fix: preflight must consult the central CORS resolver'
        );

        // Max-Age was 86400 (24h) - dropped to 600 (10 min)
        $this->assertStringNotContainsString(
            'Max-Age: 86400',
            $source,
            'H-7 fix: 24h preflight cache is too long'
        );
    }

    // =================================================================
    //  H-8 - baseline security headers
    // =================================================================

    public function testH8_SecurityHeadersHelperExists(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/RouteController.php');

        $emitBody = $this->extractFunctionBody($source, 'emitSecurityHeaders');
        $this->assertNotEmpty($emitBody, 'emitSecurityHeaders must exist');

        foreach ([
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Content-Security-Policy',
            'Strict-Transport-Security',
            'Cross-Origin-Opener-Policy',
            'Cross-Origin-Resource-Policy',
        ] as $header) {
            $this->assertStringContainsString(
                $header,
                $emitBody,
                "H-8 fix: emitSecurityHeaders must set $header"
            );
        }

        // dispatchInternal calls emitSecurityHeaders
        $dispatchBody = $this->extractFunctionBody($source, 'dispatchInternal');
        $this->assertStringContainsString(
            'emitSecurityHeaders',
            $dispatchBody,
            'H-8 fix: dispatchInternal must invoke emitSecurityHeaders'
        );

        // public/index.php (OAuth2 entry point) also emits the headers
        $oauthSrc = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $this->assertStringContainsString(
            'RouteController::emitSecurityHeaders()',
            $oauthSrc,
            'H-8 fix: OAuth2 entry must also emit baseline security headers'
        );
    }

    // =================================================================
    //  H-9 - account session has explicit SameSite=Lax / HttpOnly
    // =================================================================

    public function testH9_AccountSessionStartsWithSecureCookieParams(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/Account/AccountController.php');

        $body = $this->extractFunctionBody($source, 'ensureSecureSession');
        $this->assertNotEmpty($body, 'H-9 fix: AccountController must expose ensureSecureSession');

        $this->assertStringContainsString(
            'session_set_cookie_params',
            $body,
            'H-9 fix: must call session_set_cookie_params before session_start'
        );
        $this->assertStringContainsString(
            "'samesite' => 'Lax'",
            $body,
            'H-9 fix: cookie samesite must be Lax'
        );
        $this->assertStringContainsString(
            "'httponly' => true",
            $body,
            'H-9 fix: cookie httponly must be true'
        );
        $this->assertStringContainsString(
            'session_start',
            $body,
            'H-9 fix: must explicitly call session_start'
        );

        // handle() calls ensureSecureSession before reading $_SESSION
        $handleBody = $this->extractFunctionBody($source, 'handle');
        $this->assertStringContainsString(
            'ensureSecureSession',
            $handleBody,
            'H-9 fix: handle() must call ensureSecureSession before requireSession'
        );
    }

    // =================================================================
    //  H-13 - RSA private key on disk, not in llx_const
    // =================================================================

    public function testH13_RsaPrivateKeyStoredOnDisk(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/JwtKeyHelper.php');

        $writeBody = $this->extractFunctionBody($source, 'writeKeyFiles');
        $this->assertNotEmpty($writeBody, 'H-13 fix: JwtKeyHelper must expose writeKeyFiles');

        $this->assertStringContainsString(
            'chmod($privPath, 0600)',
            $writeBody,
            'H-13 fix: private.pem must be chmod 0600'
        );
        $this->assertStringContainsString(
            "'/private.pem'",
            $writeBody,
            'H-13 fix: key file naming convention'
        );

        // Migration helper exists and clears llx_const after a successful copy
        $migrateBody = $this->extractFunctionBody($source, 'maybeMigrateLegacyConst');
        $this->assertNotEmpty($migrateBody, 'H-13 fix: legacy const migration helper must exist');
        $this->assertStringContainsString(
            'dolibarr_del_const',
            $migrateBody,
            'H-13 fix: migration must scrub the private key from llx_const'
        );

        // generateRsaKeyPair tries the disk first
        $genBody = $this->extractFunctionBody($source, 'generateRsaKeyPair');
        $this->assertStringContainsString(
            'writeKeyFiles',
            $genBody,
            'H-13 fix: generation must write to disk first, llx_const is fallback only'
        );
    }

    // =================================================================
    //  H-17 - layered path-traversal defence
    // =================================================================

    public function testH17_PathTraversalIsBlockedAtMultipleLayers(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/ObjectDocumentController.php');

        $body = $this->extractFunctionBody($source, 'resolveDocumentPath');

        // Each individual blocker we expect
        $this->assertStringContainsString(
            "\\0",
            $body,
            'H-17 fix: must reject null bytes in the path'
        );
        $this->assertStringContainsString(
            "'..'",
            $body,
            'H-17 fix: must reject ".." traversal'
        );
        $this->assertStringContainsString(
            'realpath',
            $body,
            'H-17 fix: must use realpath() for boundary check'
        );

        $this->assertMatchesRegularExpression(
            '/realpath\(\$docDir\)/',
            $body,
            'H-17 fix: must compute realpath of the doc dir'
        );
        $this->assertMatchesRegularExpression(
            '/strpos\(\$fullReal\s*\.\s*[\'"]\/[\'"]\s*,\s*\$docDirReal/',
            $body,
            'H-17 fix: must verify resolved path stays inside docDir'
        );
    }

    // =================================================================
    //  H-18 - executable-extension denylist on uploads
    // =================================================================

    public function testH18_UploadFilenamesNeutraliseExecutableExtensions(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/SmartUpload.php');

        // The denylist constant exists and contains the headline cases
        $this->assertMatchesRegularExpression(
            "/executableExtensionDenylist[^]]*'php'/s",
            $source,
            'H-18 fix: denylist must include php'
        );
        $this->assertMatchesRegularExpression(
            "/executableExtensionDenylist[^]]*'svg'/s",
            $source,
            'H-18 fix: denylist must include svg'
        );
        $this->assertMatchesRegularExpression(
            "/executableExtensionDenylist[^]]*'phar'/s",
            $source,
            'H-18 fix: denylist must include phar'
        );
        $this->assertMatchesRegularExpression(
            "/executableExtensionDenylist[^]]*'html'/s",
            $source,
            'H-18 fix: denylist must include html'
        );

        // sanitizeFilename calls the neutraliser
        $sanitiseBody = $this->extractFunctionBody($source, 'sanitizeFilename');
        $this->assertStringContainsString(
            'neutraliseExecutableExtensions',
            $sanitiseBody,
            'H-18 fix: sanitizeFilename must call neutraliseExecutableExtensions'
        );

        // Runtime check: invoke the sanitiser on adversarial inputs
        $reflection = new \ReflectionClass(\SmartAuth\Api\SmartUpload::class);
        $method = $reflection->getMethod('sanitizeFilename');
        $method->setAccessible(true);

        $cases = [
            'evil.php' => '.php must be rewritten',
            'evil.PHP' => 'case-insensitive',
            'evil.php.jpg' => 'multi-extension shape',
            'evil.phar' => 'phar archive',
            'evil.svg' => 'svg can carry inline JS',
            'evil.html' => 'html allows XSS',
            '.htaccess' => 'apache override file',
        ];

        foreach ($cases as $input => $why) {
            $output = $method->invoke(null, $input);
            $this->assertDoesNotMatchRegularExpression(
                '/\.(php\d?|phtml|phar|svgz?|html?|htaccess|htpasswd|exe|bat|cmd|sh|jsp|aspx?|cgi|pl|py|rb)$/i',
                $output,
                "H-18 fix: $input -> $output ($why)"
            );
        }
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Crude PHP function body extractor.
     */
    private function extractFunctionBody(string $source, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        $i = $brace;
        $len = strlen($source);
        while ($i < $len) {
            $c = $source[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
            $i++;
        }
        return substr($source, $brace);
    }
}
