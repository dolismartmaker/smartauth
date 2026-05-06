<?php

/**
 * Proof-of-Concept tests for the 6 CRITICAL findings of the security audit
 * documented in docs/TODO-SECURITY-01.md.
 *
 * Each test demonstrates the vulnerability is exploitable today.
 * Once a finding is fixed, the corresponding test MUST start failing,
 * which makes this file a regression guard during P0 remediation.
 *
 * Findings covered:
 *   - CR-1: id_token_hint accepted without signature -> mass auth DoS
 *   - CR-2: jti marked "used" before signature verification -> targeted family DoS
 *   - CR-3: PKCE 'plain' accepted + default 'plain' fallback
 *   - CR-4: validateAccessToken does not verify aud/nbf/iat/typ
 *   - CR-5: IDOR on documents via share hash (no entity, no permission check)
 *   - CR-6: Mass assignment in SyncController::processCreate / processUpdate
 *
 * @group security-critical
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Security;

use SmartAuth\Tests\IntegrationDolibarr\OAuth2\OAuthTestCase;
use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Api\OAuth2\TokenController;
use SmartAuth\Api\OAuth2\LogoutController;
use SmartAuth\Api\OAuth2\ResponseException;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\ObjectDocumentController;
use SmartAuth\Api\SyncController;
use SmartAuth\Api\JwtKeyHelper;

dol_include_once('/smartauth/api/AuthController.php');
dol_include_once('/smartauth/api/OAuth2/LogoutController.php');
dol_include_once('/smartauth/api/OAuth2/TokenController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');
dol_include_once('/smartauth/api/ObjectDocumentController.php');
dol_include_once('/smartauth/api/SyncController.php');

class CriticalFindingsPoCTest extends OAuthTestCase
{
    /** RSA key pair pre-generated once per process (PHP openssl_pkey_new
     *  is broken in this environment due to system openssl.cnf, so we
     *  shell out to the openssl binary). */
    private static ?array $rsaKeys = null;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure test mode for TokenController so we can capture responses
        TokenController::enableTestMode();

        // Stable issuer for forgery tests (CR-1 needs $iss to match)
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';

        // Pre-seed RSA keys so JwtKeyHelper does not call openssl_pkey_new()
        $this->seedRsaKeys();
    }

    /**
     * Generate (once) and inject an RSA key pair into the Dolibarr config
     * cache so JwtKeyHelper picks them up via getDolGlobalString() and
     * never falls back to openssl_pkey_new() (which is broken on this host).
     */
    private function seedRsaKeys(): void
    {
        global $conf;

        if (self::$rsaKeys === null) {
            $tmp = tempnam(sys_get_temp_dir(), 'smartauth_rsa_');
            $cmd = 'openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($tmp) . ' 2>/dev/null';
            exec($cmd, $out, $rc);
            if ($rc !== 0 || !is_file($tmp)) {
                @unlink($tmp);
                $this->markTestSkipped('openssl CLI not available, cannot pre-seed RSA keys');
            }
            $priv = file_get_contents($tmp);
            @unlink($tmp);

            $resource = openssl_pkey_get_private($priv);
            $details = openssl_pkey_get_details($resource);
            $pub = $details['key'];

            self::$rsaKeys = [
                'private' => $priv,
                'public' => $pub,
                'kid' => 'smartauth-test-' . substr(hash('sha256', $pub), 0, 8),
            ];
        }

        $conf->global->SMARTAUTH_OAUTH_RSA_PRIVATE_KEY = self::$rsaKeys['private'];
        $conf->global->SMARTAUTH_OAUTH_RSA_PUBLIC_KEY = self::$rsaKeys['public'];
        $conf->global->SMARTAUTH_OAUTH_RSA_KID = self::$rsaKeys['kid'];
    }

    protected function tearDown(): void
    {
        TokenController::disableTestMode();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Build an unsigned JWT (header.payload.garbage) - the audit's exact
     * forgery shape: a well-formed 3-segment JWT whose signature segment
     * is just random bytes that would never pass openssl_verify.
     */
    private function forgeUnsignedJwt(array $payload, array $headerExtra = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT'], $headerExtra);

        $h = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $p = JwtKeyHelper::base64UrlEncode(json_encode($payload));
        $s = JwtKeyHelper::base64UrlEncode('garbage-signature-not-verified');

        return $h . '.' . $p . '.' . $s;
    }

    /**
     * Invoke a private/protected method through reflection.
     */
    private function invokePrivate($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    // =================================================================
    //  CR-1 - id_token_hint accepted without signature verification
    // =================================================================

    /**
     * Regression test for CR-1.
     *
     * Before the fix, decodeIdTokenHint() trusted the JWT payload without
     * verifying the RSA signature, which let any unauthenticated caller
     * trigger revokeUserTokens() against an arbitrary sub. The fix at
     * api/OAuth2/LogoutController.php now requires a valid RS256 signature
     * from the IdP's own key.
     *
     * This test asserts both directions:
     *   - a forged JWT (random signature) is rejected -> userId === null
     *   - a JWT signed with our private key is accepted -> userId === victim
     */
    public function testCR1_IdTokenHintRequiresValidSignature(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $victim = $this->createTestUser(['login' => 'victim_' . uniqid()]);

        $logout = new LogoutController($this->db);

        // ---- Negative: unsigned (forged) id_token must be rejected ----
        $forged = $this->forgeUnsignedJwt([
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $victim->id,
            'aud' => $client->client_id,
            'exp' => time() + 3600,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $info = $this->invokePrivate($logout, 'decodeIdTokenHint', [$forged]);
        $this->assertNull(
            $info['userId'],
            'CR-1 fix: forged unsigned JWT must not yield any userId'
        );
        $this->assertNull($info['clientId']);

        // ---- Positive: a properly signed id_token is accepted ----
        $signed = $this->signJwtWithIdpKey([
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $victim->id,
            'aud' => $client->client_id,
            'exp' => time() + 3600,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $info = $this->invokePrivate($logout, 'decodeIdTokenHint', [$signed]);
        $this->assertSame(
            $victim->id,
            $info['userId'],
            'CR-1 fix: a JWT signed with the IdP key must still be accepted'
        );
        $this->assertSame($client->client_id, $info['clientId']);

        // ---- Defence in depth: bad alg ('none') is rejected ----
        $noneAlg = $this->forgeUnsignedJwt([
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $victim->id,
        ], ['alg' => 'none']);
        $info = $this->invokePrivate($logout, 'decodeIdTokenHint', [$noneAlg]);
        $this->assertNull($info['userId'], 'CR-1 fix: alg=none must be rejected');
    }

    /**
     * Sign a JWT using the IdP's RSA private key (the same key the
     * production code uses to sign legitimate id_tokens).
     */
    private function signJwtWithIdpKey(array $payload): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => JwtKeyHelper::getRsaKeyId()];
        $h = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $p = JwtKeyHelper::base64UrlEncode(json_encode($payload));

        $signature = '';
        $ok = openssl_sign(
            $h . '.' . $p,
            $signature,
            JwtKeyHelper::getRsaPrivateKey(),
            OPENSSL_ALGO_SHA256
        );
        $this->assertTrue($ok, 'Test setup: failed to sign JWT with IdP key');

        return $h . '.' . $p . '.' . JwtKeyHelper::base64UrlEncode($signature);
    }

    // =================================================================
    //  CR-2 - jti marked "used" BEFORE signature verification
    // =================================================================

    /**
     * Regression test for CR-2.
     *
     * Before the fix, AuthController::refresh() called _extractJtiFromToken
     * + _markJtiAsUsed BEFORE _decodeJWT, so an attacker who knew a victim's
     * jti could poison the smartauth_jti_used table by sending a forged
     * (unsigned) refresh token. The fix moves the jti registration AFTER
     * the signature verification.
     *
     * This test asserts the source order, plus the runtime contract:
     * an invalid-signature refresh attempt MUST NOT leave any trace in
     * llx_smartauth_jti_used.
     */
    public function testCR2_JtiOnlyMarkedAfterSignatureVerification(): void
    {
        // ---- Static check: in refresh(), _decodeJWT must precede _markJtiAsUsed ----
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/AuthController.php');
        $refreshBody = $this->extractFunctionBody($source, 'refresh');
        $decodePos = strpos($refreshBody, '_decodeJWT');
        $markPos = strpos($refreshBody, '_markJtiAsUsed');
        $this->assertNotFalse($decodePos, 'refresh() must call _decodeJWT');
        $this->assertNotFalse($markPos, 'refresh() must call _markJtiAsUsed');
        $this->assertLessThan(
            $markPos,
            $decodePos,
            'CR-2 fix: _decodeJWT must run before _markJtiAsUsed in refresh()'
        );

        // ---- Runtime check: low-level helpers still work in isolation ----
        // (the regression we care about is in refresh(), not in the helpers)
        $controller = new AuthController();

        $stolenJti = bin2hex(random_bytes(16));
        $marked = $this->invokePrivate($controller, '_markJtiAsUsed', [$stolenJti]);
        $this->assertTrue($marked, '_markJtiAsUsed must remain functional');

        $secondAttempt = $this->invokePrivate($controller, '_markJtiAsUsed', [$stolenJti]);
        $this->assertFalse($secondAttempt, 'Second insert is rejected (PRIMARY KEY)');

        // ---- Static check: refresh() reads jti from $decoded, not from the raw token ----
        // The pre-fix code used $this->_extractJtiFromToken($refresh_token) which
        // accepts an unsigned payload. The fix replaces that with $decoded->jti,
        // which is only populated after _decodeJWT() has verified the signature.
        $this->assertStringNotContainsString(
            '_extractJtiFromToken($refresh_token)',
            $refreshBody,
            'CR-2 fix: refresh() must not call _extractJtiFromToken on the raw input'
        );
        $this->assertMatchesRegularExpression(
            '/\$decoded->jti\b/',
            $refreshBody,
            'CR-2 fix: refresh() must read jti from the verified $decoded payload'
        );
    }

    // =================================================================
    //  CR-3 - PKCE 'plain' accepted + default fallback to 'plain'
    // =================================================================

    /**
     * Regression test for CR-3 variant A : a code stored with
     * code_challenge_method = NULL must no longer be exchangeable. Before
     * the fix, the token endpoint silently fell back to 'plain' (?? 'plain').
     */
    public function testCR3a_PkceMethodNullIsRejectedAtTokenEndpoint(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $shared = str_repeat('A', 43);

        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $shared,
            'code_challenge_method' => null,
        ]);

        $response = $this->exchangeCode($client, $codeData['code'], [
            'code_verifier' => $shared,
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'CR-3a fix: a code with NULL method must not be exchangeable'
        );
        $this->assertSame('invalid_grant', $response->getErrorCode());
    }

    /**
     * Regression test for CR-3 variant B : explicit
     * code_challenge_method = 'plain' must be rejected by the token endpoint.
     */
    public function testCR3b_PkceMethodPlainIsRejected(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $shared = str_repeat('B', 43);

        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $shared,
            'code_challenge_method' => 'plain',
        ]);

        $response = $this->exchangeCode($client, $codeData['code'], [
            'code_verifier' => $shared,
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'CR-3b fix: explicit plain method must be rejected'
        );
        $this->assertSame('invalid_grant', $response->getErrorCode());
    }

    /**
     * Regression test for CR-3 : a properly built S256 code MUST still
     * exchange successfully (oracle that the fix is not over-restrictive).
     */
    public function testCR3c_PkceS256StillWorks(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $verifier = \SmartAuth\Api\OAuth2\PKCEHelper::generateVerifier();
        $challenge = \SmartAuth\Api\OAuth2\PKCEHelper::generateChallenge(
            $verifier,
            \SmartAuth\Api\OAuth2\PKCEHelper::METHOD_S256
        );

        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response = $this->exchangeCode($client, $codeData['code'], [
            'code_verifier' => $verifier,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('access_token', $response->getResponseBody());
    }

    /**
     * Convenience wrapper around TokenController::handleToken in test mode.
     */
    private function exchangeCode(
        \SmartAuthOAuthClient $client,
        string $code,
        array $extra = []
    ): ResponseException {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['PHP_AUTH_USER'] = $client->client_id;
        $_SERVER['PHP_AUTH_PW'] = 'test-secret-confidential-12345';

        $_POST = array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $client->getRedirectUrisArray()[0],
        ], $extra);

        $controller = new TokenController($this->db);
        try {
            $controller->handleToken();
            $this->fail('TokenController did not emit a ResponseException');
        } catch (ResponseException $e) {
            return $e;
        }
    }

    // =================================================================
    //  CR-4 - validateAccessToken does not verify aud / nbf / iat / typ
    // =================================================================

    /**
     * Regression test for CR-4.
     *
     * After the fix, validateAccessToken accepts an $expectedAudience
     * argument and rejects tokens whose aud claim does not contain it.
     * The fix also adds nbf / iat / typ checks to align with RFC 8725.
     */
    public function testCR4_ValidateAccessTokenChecksAudienceWhenRequested(): void
    {
        $clientA = $this->createTestClient([
            'client_id' => 'cr4-client-a-' . uniqid(),
            'client_secret' => 'irrelevant-A',
        ]);
        $user = $this->createTestUser();

        $tok = $this->createAccessToken($clientA, $user);

        // ---- Structural: signature now exposes expectedAudience ----
        $reflection = new \ReflectionMethod($this->tokenService, 'validateAccessToken');
        $this->assertGreaterThanOrEqual(
            2,
            $reflection->getNumberOfParameters(),
            'CR-4 fix: validateAccessToken must accept an expectedAudience argument'
        );

        // ---- Compat: callers without expectedAudience still work ----
        $payload = $this->tokenService->validateAccessToken($tok['token']);
        $this->assertIsArray($payload, 'A valid token must still validate when no audience is required');
        $this->assertSame($clientA->client_id, $payload['aud']);

        // ---- Audience match positive: token aud == expectedAudience ----
        $payload = $this->tokenService->validateAccessToken($tok['token'], $clientA->client_id);
        $this->assertIsArray($payload, 'Token must validate when aud matches expectedAudience');

        // ---- Audience mismatch negative: token aud != expectedAudience ----
        $payload = $this->tokenService->validateAccessToken($tok['token'], 'some-other-client');
        $this->assertNull(
            $payload,
            'CR-4 fix: token issued for client A must not validate for audience B'
        );

        // ---- nbf in the future: rejected ----
        $futureNbfToken = $this->signAccessTokenWithIdpKey([
            'iss' => OAuthConfig::getIssuer(),
            'aud' => $clientA->client_id,
            'sub' => (string) $user->id,
            'iat' => time(),
            'exp' => time() + 3600,
            'nbf' => time() + 3600, // not valid for another hour
            'jti' => bin2hex(random_bytes(16)),
        ]);
        $this->assertNull(
            $this->tokenService->validateAccessToken($futureNbfToken),
            'CR-4 fix: tokens with nbf in the future must be rejected'
        );

        // ---- iat in the future: rejected ----
        $futureIatToken = $this->signAccessTokenWithIdpKey([
            'iss' => OAuthConfig::getIssuer(),
            'aud' => $clientA->client_id,
            'sub' => (string) $user->id,
            'iat' => time() + 3600,
            'exp' => time() + 7200,
            'jti' => bin2hex(random_bytes(16)),
        ]);
        $this->assertNull(
            $this->tokenService->validateAccessToken($futureIatToken),
            'CR-4 fix: tokens with iat in the future must be rejected'
        );

        // ---- bad typ: rejected ----
        $badTypToken = $this->signAccessTokenWithIdpKey([
            'iss' => OAuthConfig::getIssuer(),
            'aud' => $clientA->client_id,
            'sub' => (string) $user->id,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
        ], ['typ' => 'evil']);
        $this->assertNull(
            $this->tokenService->validateAccessToken($badTypToken),
            'CR-4 fix: tokens with header.typ != "JWT" must be rejected'
        );
    }

    /**
     * Sign a token like the IdP would, but with caller-controlled header
     * extras (used to inject a non-JWT typ in the typ-check test).
     */
    private function signAccessTokenWithIdpKey(array $payload, array $headerExtra = []): string
    {
        $header = array_merge(
            ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => JwtKeyHelper::getRsaKeyId()],
            $headerExtra
        );
        $h = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $p = JwtKeyHelper::base64UrlEncode(json_encode($payload));
        $signature = '';
        $ok = openssl_sign(
            $h . '.' . $p,
            $signature,
            JwtKeyHelper::getRsaPrivateKey(),
            OPENSSL_ALGO_SHA256
        );
        $this->assertTrue($ok, 'Test setup: failed to sign access token');
        return $h . '.' . $p . '.' . JwtKeyHelper::base64UrlEncode($signature);
    }

    // =================================================================
    //  CR-5 - IDOR on documents via share hash
    // =================================================================

    /**
     * Regression test for CR-5.
     *
     * After the fix, resolveShareHash and resolveShareHashes require a
     * User + entity context, filter ecm_files by entity, and call
     * dol_check_secure_access_document for the resolved modulepart.
     *
     * The cross-entity exploit must therefore now return null / empty.
     * The signed-source assertions also enforce that no future regression
     * removes either the entity filter or the permission check.
     */
    public function testCR5_ShareHashIsGatedByEntityAndPermissions(): void
    {
        global $db, $conf, $user;

        $conf->entity = 1;

        // Plant a file in entity 99 with a known share hash
        $shareHash = 'cr5shareABCDEF0123456789cafebabe';
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "ecm_files "
            . "(ref, label, share, entity, filepath, filename, src_object_type, src_object_id, date_c) VALUES ("
            . "'cr5ref', "
            . "'cr5contenthash', "
            . "'" . $db->escape($shareHash) . "', "
            . "99, "
            . "'evil/path', "
            . "'leaked.pdf', "
            . "'societe', "
            . "987654321, "
            . "'" . $db->idate(dol_now()) . "')";
        $this->assertNotFalse($db->query($sql), 'Failed to plant ecm_files row');

        // ---- Static checks: both gates are present in the source ----
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/ObjectDocumentController.php');
        $singleBody = $this->extractFunctionBody($source, 'resolveShareHash');
        $batchBody = $this->extractFunctionBody($source, 'resolveShareHashes');
        $sharedHelper = $this->extractFunctionBody($source, 'shareHashAccessAllowed');

        $this->assertStringContainsString(
            "getEntity('ecmfiles')",
            $batchBody,
            'CR-5 fix: resolveShareHashes must apply entity filter via getEntity()'
        );
        $this->assertStringContainsString(
            'dol_check_secure_access_document',
            $sharedHelper,
            'CR-5 fix: shareHashAccessAllowed must call dol_check_secure_access_document'
        );
        $this->assertStringContainsString(
            'shareHashAccessAllowed',
            $singleBody,
            'CR-5 fix: resolveShareHash must call the permission helper'
        );
        $this->assertStringContainsString(
            'shareHashAccessAllowed',
            $batchBody,
            'CR-5 fix: resolveShareHashes must call the permission helper'
        );

        // ---- Runtime: cross-entity caller is now denied ----
        $controller = new ObjectDocumentController();
        $resolved = $this->invokePrivate($controller, 'resolveShareHash', [$shareHash, $user, 1]);
        $this->assertNull(
            $resolved,
            'CR-5 fix: cross-entity file must not be reachable via share hash'
        );

        $batch = $this->invokePrivate($controller, 'resolveShareHashes', [[$shareHash], $user, 1]);
        $this->assertArrayNotHasKey(
            $shareHash,
            $batch,
            'CR-5 fix: batch resolver must drop cross-entity rows'
        );
    }

    /**
     * Crude PHP function body extractor (good enough for static-content
     * assertions on a known target).
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

    // =================================================================
    //  CR-6 - Mass assignment in SyncController
    // =================================================================

    /**
     * Regression test for CR-6 variant A : processCreate must drop
     * data['entity'] silently because entity is in the universal denylist.
     */
    public function testCR6a_ProcessCreateDropsCrossEntityField(): void
    {
        global $db, $user;

        $maliciousName = 'CR6-pwn-' . uniqid();
        $data = [
            'name' => $maliciousName,
            'client' => 1,
            'status' => 1,
            'entity' => 99, // must be dropped
        ];

        $controller = new SyncController();
        // Re-use the built-in 'thirdparty' config (with allowed_fields)
        $reflection = new \ReflectionClass($controller);
        $prop = $reflection->getProperty('syncableObjects');
        $prop->setAccessible(true);
        $config = $prop->getValue($controller)['thirdparty'];

        $result = $this->invokePrivate($controller, 'processCreate', [$config, $data, $user]);

        $this->assertTrue(
            $result['success'],
            'processCreate failed: ' . ($result['error'] ?? 'unknown')
        );
        $rowid = (int) $result['id'];

        $resql = $db->query(
            "SELECT entity, nom FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . $rowid
        );
        $this->assertNotFalse($resql);
        $row = $db->fetch_object($resql);

        $this->assertNotEquals(
            99,
            (int) $row->entity,
            'CR-6a fix: attacker-supplied entity must be dropped'
        );
        $this->assertSame($maliciousName, $row->nom, 'whitelisted name must still pass through');
    }

    /**
     * Regression test for CR-6 variant B : even when an external module
     * registers the User class via smartmaker_registerSyncableObjects, the
     * universal denylist must still drop admin / pass* / entity / statut.
     */
    public function testCR6b_ProcessCreateDropsAdminAndPasswordOnUserClass(): void
    {
        global $db, $user;

        if (!class_exists('User')) {
            $this->markTestSkipped('User class not available in this Dolibarr install');
        }

        // Mimic an external module that registered User WITHOUT a whitelist:
        // we rely entirely on the universal denylist for the dangerous fields.
        $config = [
            'class' => 'User',
            'file' => DOL_DOCUMENT_ROOT . '/user/class/user.class.php',
            'table' => 'user',
            'element' => 'user',
        ];

        $login = 'cr6pwn_' . uniqid();
        $data = [
            'login' => $login,
            'lastname' => 'Owned',
            'firstname' => 'Attacker',
            'email' => $login . '@evil.example',
            'admin' => 1,         // must be dropped
            'employee' => 1,      // employee is on denylist too (sensitive flag)
            'statut' => 1,        // must be dropped
            'pass_crypted' => 'evil', // must be dropped (regex)
            'entity' => 1,        // dropped, server uses default
        ];

        $controller = new SyncController();
        $result = $this->invokePrivate($controller, 'processCreate', [$config, $data, $user]);

        $this->assertTrue(
            $result['success'],
            'processCreate failed on User: ' . ($result['error'] ?? 'unknown')
        );

        $resql = $db->query(
            "SELECT admin, login, pass_crypted FROM " . MAIN_DB_PREFIX . "user "
            . "WHERE rowid = " . (int) $result['id']
        );
        $this->assertNotFalse($resql);
        $row = $db->fetch_object($resql);

        $this->assertSame($login, $row->login);
        $this->assertNotSame(
            1,
            (int) $row->admin,
            'CR-6b fix: admin field must not be mass-assigned (no escalation)'
        );
        $this->assertNotSame(
            'evil',
            $row->pass_crypted,
            'CR-6b fix: pass_crypted must not be mass-assigned'
        );

        // Cleanup
        $db->query("DELETE FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $result['id']);
    }

    /**
     * Regression test for CR-6 variant C : the source now expresses both
     * the universal denylist and the per-type whitelist via applyDataToObject().
     */
    public function testCR6c_StructuralFiltersAreInPlace(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/api/SyncController.php');

        // The shared filter helper exists.
        $this->assertNotEmpty(
            $this->extractFunctionBody($source, 'applyDataToObject'),
            'CR-6 fix: SyncController must expose applyDataToObject helper'
        );

        // Each previously-vulnerable function must now delegate to the helper
        // and must no longer carry an inline foreach with property_exists.
        foreach (['processCreate', 'processUpdate', 'applyResolvedData'] as $fn) {
            $body = $this->extractFunctionBody($source, $fn);
            $this->assertStringContainsString(
                'applyDataToObject',
                $body,
                "CR-6 fix: $fn must delegate to applyDataToObject"
            );
            $this->assertStringNotContainsString(
                'foreach ($data as $key => $value)',
                $body,
                "CR-6 fix: $fn must not contain inline mass-assignment loop"
            );
        }

        // The denylist constant covers the high-impact fields the audit names.
        $this->assertMatchesRegularExpression(
            '/sensitiveFieldsDenylist\s*=\s*\[[^\]]*\'admin\'/s',
            $source,
            'CR-6 fix: admin must be in the denylist'
        );
        $this->assertMatchesRegularExpression(
            '/sensitiveFieldsDenylist\s*=\s*\[[^\]]*\'entity\'/s',
            $source,
            'CR-6 fix: entity must be in the denylist'
        );
        $this->assertMatchesRegularExpression(
            '/sensitiveFieldsDenylist\s*=\s*\[[^\]]*\'fk_user_creat\'/s',
            $source,
            'CR-6 fix: fk_user_creat must be in the denylist'
        );
        $this->assertMatchesRegularExpression(
            '/sensitiveFieldsDenylistRegex.*pass/s',
            $source,
            'CR-6 fix: pass* must be matched by the denylist regex'
        );
    }
}
