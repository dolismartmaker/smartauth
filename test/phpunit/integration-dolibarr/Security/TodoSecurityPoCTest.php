<?php

/**
 * Proof-of-concept / regression tests for the open findings of
 * todo-security.md (root of the repo).
 *
 * Each test is written to FAIL while the vulnerability is present (it asserts
 * the SECURE behaviour) and to PASS once the fix lands -- so it doubles as a
 * regression guard. A red run here is the proof the bug exists.
 *
 * Findings covered:
 *   - TODO-1: MAX_REFRESH_COUNT is inoperative. _generateToken() freezes the
 *             refresh_count JWT claim at 0 and _updateTokenFamily() overwrites
 *             the family counter to a constant 1, so neither the per-token nor
 *             the per-family bound ever trips. Sessions live forever.
 *   - TODO-3: First-party OAuth2 API has no audience check. RouteController
 *             calls TokenService::validateAccessToken() WITHOUT an expected
 *             audience, so a token minted for a third-party client (WordPress,
 *             etc.) bearing a usr: subject is accepted on SmartAuth's
 *             privileged API (confused deputy).
 *   - TODO-5: /sync/pull never checks hasRight(...,'read'). Any valid JWT can
 *             pull the whole entity's third parties / contacts / products,
 *             even when the authenticated user has no read permission.
 *
 * Findings TODO-2 (X-Forwarded-For trust model) and TODO-4 (client_credentials
 * fallback to SMARTAUTH_DEFAULT_USER) are intentionally NOT covered here: their
 * "secure" behaviour contradicts existing locked-in tests (HighFindingsPoCTest
 * H-1 and ClientCredentialsTest::testClientCredentialsFallbackToDefaultUser).
 * Fixing them is a design reversal that must be decided first.
 *
 * @group security-todo
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Security;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\SyncController;
use ReflectionClass;

dol_include_once('/smartauth/api/AuthController.php');
dol_include_once('/smartauth/api/SyncController.php');
dol_include_once('/smartauth/api/InputSanitizer.php');
dol_include_once('/smartauth/api/SmartTokenConfig.php');

class TodoSecurityPoCTest extends DolibarrRealTestCase
{
    /** @var array Backup of mutated $_SERVER keys. */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;

        // Token emission/decoding needs a stable device id (salt2) and app id.
        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        $_SERVER['HTTP_X_DEVICEID'] = '11112222-3333-4444-8555-666677778888';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // =================================================================
    //  TODO-1 - MAX_REFRESH_COUNT is dead, sessions never expire
    // =================================================================

    /**
     * Drive refresh() end-to-end several times and prove the refresh counter
     * never accumulates:
     *   - the refresh_count claim in the rotated JWT stays at 0
     *   - the token_family.refresh_count column stays at 1
     *
     * Because both are frozen, the MAX_REFRESH_COUNT guard at
     * AuthController.php:205 / 1996 can never fire: a refresh token can be
     * rotated indefinitely. The secure behaviour asserted below (the counter
     * grows with each refresh) currently FAILS, which is the bug.
     */
    public function testTodo1_RefreshCountAccumulatesAcrossRotations(): void
    {
        $controller = new AuthController();
        $ref = new ReflectionClass($controller);

        $createFamily = $ref->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($controller, $this->testUser->id);

        $createDevice = $ref->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($controller, $this->testUser->id);

        $genPair = $ref->getMethod('_generateTokenPair');
        $genPair->setAccessible(true);
        $tokens = $genPair->invoke(
            $controller,
            'user',
            $this->testUser->id,
            $this->testUser->id,
            $this->testUser->login,
            1,
            $familyId,
            $deviceId
        );

        $refreshToken = $tokens['refresh_token'];
        $this->assertNotEmpty($refreshToken, 'precondition: a refresh token was issued');

        $rounds = 3;
        for ($i = 1; $i <= $rounds; $i++) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;
            $resp = $controller->refresh();
            $this->assertSame(
                200,
                $resp[1],
                "precondition: refresh #$i must succeed, got: " . json_encode($resp[0])
            );
            $this->assertNotEmpty($resp[0]['refresh_token'], "refresh #$i must return a new refresh token");
            $refreshToken = $resp[0]['refresh_token'];
        }

        // (a) The rotated JWT must carry the accumulated count, not a frozen 0.
        $claims = $this->decodeJwtClaims($refreshToken);
        $this->assertArrayHasKey('refresh_count', $claims, 'JWT must carry a refresh_count claim');
        $this->assertGreaterThanOrEqual(
            $rounds,
            (int) $claims['refresh_count'],
            'TODO-1: refresh_count claim is frozen at 0 in _generateToken(), so the '
            . 'per-token MAX_REFRESH_COUNT check is dead and the session never expires'
        );

        // (b) The family counter must accumulate, not be overwritten to 1.
        $sql = 'SELECT refresh_count FROM ' . MAIN_DB_PREFIX . 'smartauth_token_family'
            . ' WHERE rowid = ' . (int) $familyId;
        $resql = $this->db->query($sql);
        $row = $this->db->fetch_object($resql);
        $this->assertSame(
            $rounds,
            (int) $row->refresh_count,
            'TODO-1: token_family.refresh_count is set to decoded(0)+1 on every refresh, '
            . 'so it stays at 1 and never bounds the family lifetime'
        );
    }

    // =================================================================
    //  TODO-3 - first-party API accepts tokens issued for other audiences
    // =================================================================

    /**
     * The privileged OAuth2 Bearer path (RouteController::handleOAuth2Authentication)
     * must validate the token audience, otherwise a token minted for a
     * third-party client but carrying a usr: subject is honoured on
     * SmartAuth's own API (confused deputy).
     *
     * The capability already exists in TokenService (validateAccessToken has an
     * $expectedAudience parameter); what is missing is the wiring. This test
     * fails while the route still calls validateAccessToken() with a single
     * argument.
     */
    public function testTodo3_FirstPartyApiPassesExpectedAudience(): void
    {
        $routeSrc = file_get_contents(dirname(__DIR__, 4) . '/api/RouteController.php');
        $tokenSrc = file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/TokenService.php');

        // Capability is in place: validateAccessToken can enforce an audience.
        $this->assertStringContainsString(
            '$expectedAudience',
            $tokenSrc,
            'precondition: TokenService::validateAccessToken supports an expected audience'
        );

        // Wiring is missing: the first-party Bearer path must pass an audience.
        $body = $this->extractFunctionBody($routeSrc, 'handleOAuth2Authentication');
        $this->assertNotEmpty($body, 'handleOAuth2Authentication must exist');

        $this->assertMatchesRegularExpression(
            '/validateAccessToken\s*\(\s*\$jwt\s*,/',
            $body,
            'TODO-3: handleOAuth2Authentication calls validateAccessToken($jwt) with no '
            . 'expected audience -> a token issued for any client is accepted on the '
            . 'first-party API (confused deputy)'
        );
    }

    // =================================================================
    //  TODO-5 - /sync/pull leaks data without a read permission check
    // =================================================================

    /**
     * pull() must enforce the same hasRight(...,'read') gate that push() applies
     * for writes. Here we revoke the user's "read third parties" right and prove
     * the third party is still returned by pull(), i.e. the data leaks.
     */
    public function testTodo5_SyncPullEnforcesReadRight(): void
    {
        global $user;

        $controller = new SyncController();

        // A third party that pull() would return.
        $soc = $this->createTestSociete(['name' => 'Secret Co ' . uniqid()]);

        // Register a sync client bound to the test user.
        $deviceId = $this->createSyncDevice();
        $clientUuid = '99990000-1111-2222-8333-444455556666';
        $reg = $controller->register([
            'user_id' => $this->testUser->id,
            'client_uuid' => $clientUuid,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0',
        ]);
        $this->assertSame(200, $reg[1], 'precondition: sync client registered');

        // Revoke the read permission the pull MUST check.
        $user->rights->societe->lire = 0;

        $resp = $controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $clientUuid,
            'object_type' => 'thirdparty',
        ]);

        $ids = [];
        if (isset($resp[0]['updated']) && is_array($resp[0]['updated'])) {
            foreach ($resp[0]['updated'] as $obj) {
                $ids[] = (int) ($obj['id'] ?? ($obj['rowid'] ?? 0));
            }
        }

        $this->assertNotContains(
            (int) $soc->id,
            $ids,
            'TODO-5: pull() returned a third party although the user lacks '
            . 'societe->lire -- /sync/pull performs no read-permission check'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Insert a minimal device row for a sync client and return its id.
     */
    private function createSyncDevice(): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_devices'
            . ' (ref, fk_user_creat, uuid, label, date_creation, status, entity) VALUES ('
            . "'TODO5-DEV-" . uniqid() . "', "
            . (int) $this->testUser->id . ', '
            . "'" . $this->db->escape('todo5-' . uniqid()) . "', "
            . "'Todo5 Device', "
            . "'" . $this->db->idate(time()) . "', "
            . '1, 1)';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \RuntimeException('Failed to insert sync device: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    /**
     * Decode the claims of a SmartAuth token in "id|jwt" format (no signature
     * verification -- we only read the payload).
     *
     * @param string $token
     * @return array<string,mixed>
     */
    private function decodeJwtClaims(string $token): array
    {
        $pos = strpos($token, '|');
        $jwt = $pos === false ? $token : substr($token, $pos + 1);
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * Crude PHP function body extractor (same approach as HighFindingsPoCTest).
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
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $c = $source[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        return substr($source, $brace);
    }
}
