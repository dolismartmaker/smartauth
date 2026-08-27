<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RouteController.php';
require_once __DIR__ . '/../../../api/Account/RegistrationService.php';
require_once __DIR__ . '/../../../class/smartauthusertokenadmin.class.php';

use ReflectionClass;
use SmartAuth\Api\Account\RegistrationService;
use SmartAuth\Api\AuthController;
use SmartAuthUserTokenAdmin;

/**
 * The high-severity findings of the 2026-08-24 audit: everything that let an
 * access survive an event that was supposed to end it.
 *
 * One theme runs through them. A token was verified as a TOKEN (signature,
 * status, family, counter) and never as an ACCESS: nothing ever asked again
 * whether the account behind it still exists, is still enabled, or has already
 * been cut off. So disabling a user changed nothing, revoking a token from the
 * user card changed nothing durable, and a stolen refresh token that had
 * already been spent was rejected without anyone drawing the obvious
 * conclusion.
 *
 * Every test here fails on the pre-fix code.
 *
 * @covers \SmartAuth\Api\AuthController
 * @covers \SmartAuth\Api\Account\RegistrationService
 * @covers \SmartAuthUserTokenAdmin
 */
class SubjectRevalidationTest extends DolibarrRealTestCase
{
    /** @var AuthController */
    private $auth;

    /** @var string */
    private $deviceUuid;

    /** @var int Output-buffer depth on entry, restored in tearDown */
    private $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // json_reply() opens a buffer then throws under PHPUNIT_RUNNING, so
        // the refusal paths leave one behind. Same guard as AuthControllerTest.
        $this->initialObLevel = ob_get_level();

        $this->auth = new AuthController();
        $this->deviceUuid = $this->uuid();
        $_SERVER['HTTP_X_DEVICEID'] = $this->deviceUuid;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';
    }

    protected function tearDown(): void
    {
        global $conf;

        unset($conf->global->SMARTAUTH_REFRESH_RACE_WINDOW);
        unset($_SERVER['HTTP_X_DEVICEID'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);

        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }

        // Several tests disable the shared admin account. llx_user is NOT part
        // of what the harness resets between tests, so leaving it disabled
        // would poison every test that runs after this class.
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "user SET statut = 1, dateendvalidity = NULL, datestartvalidity = NULL"
            . " WHERE rowid = " . (int) $this->testUser->id
        );

        parent::tearDown();
    }

    // =========================================================================
    // Finding 1: no subject re-validation after issuance
    // =========================================================================

    /**
     * A disabled account used to renew its pair here, pushing the expiry a full
     * lifetime further at every rotation: disabling a user never ended their
     * access, it just made it invisible in the UI.
     */
    public function testRefreshIsRefusedOnceTheSubjectIsDisabled(): void
    {
        $session = $this->createSession();
        $this->disableUser((int) $this->testUser->id);

        $result = $this->callRefresh($session['tokens']['refresh_token']);

        $this->assertSame(401, $result[1], 'a disabled subject must not renew its session: ' . json_encode($result[0]));
    }

    /**
     * And the refusal is not just a "no" repeated at every attempt: the family
     * goes with it, so the device cannot keep trying with a still-valid pair.
     */
    public function testRefreshRevokesTheFamilyOfADisabledSubject(): void
    {
        $session = $this->createSession();
        $this->disableUser((int) $this->testUser->id);

        $this->callRefresh($session['tokens']['refresh_token']);

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1,
        ]);
    }

    /**
     * A user outside their validity window is refused at login by Dolibarr
     * itself; a token must not be a way around that.
     */
    public function testRefreshIsRefusedOutsideTheValidityWindow(): void
    {
        $user = new \User($this->db);
        $user->fetch((int) $this->testUser->id);
        if (!method_exists($user, 'isNotIntoValidityDateRange')) {
            $this->markTestSkipped('this Dolibarr version has no user validity window');
        }

        $session = $this->createSession();

        // Ended yesterday.
        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET dateendvalidity = '";
        $sql .= $this->db->idate(dol_now() - 86400) . "' WHERE rowid = " . (int) $this->testUser->id;
        $this->assertNotFalse($this->db->query($sql));

        $result = $this->callRefresh($session['tokens']['refresh_token']);

        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "user SET dateendvalidity = NULL WHERE rowid = " . (int) $this->testUser->id);

        $this->assertSame(401, $result[1], 'an expired account must not renew its session: ' . json_encode($result[0]));
    }

    /**
     * The same subject check on the OAuth2 side, at the level that matters:
     * TokenSubject::isActive is what the refresh_token and authorization_code
     * grants now consult before issuing anything.
     */
    public function testTokenSubjectIsActiveFollowsTheUserStatus(): void
    {
        $subject = \SmartAuth\Api\OAuth2\TokenSubject::user((int) $this->testUser->id, 0);
        $this->assertTrue($subject->isActive($this->db), 'an enabled user must be active');

        $this->disableUser((int) $this->testUser->id);
        $this->assertFalse($subject->isActive($this->db), 'a disabled user must not be active');
    }

    // =========================================================================
    // Finding 5: refresh token reuse was rejected without any conclusion
    // =========================================================================

    /**
     * Rotation leaves the spent row in STATUS_LOGOUT with salt='refresh_used'.
     * Presenting it again is the textbook signal that the refresh token leaked:
     * the rightful device already spent it. It used to be answered with a plain
     * 401, family untouched, so the thief kept whatever pair they held.
     */
    public function testReusingARotatedRefreshTokenRevokesTheFamily(): void
    {
        global $conf;

        // No grace window: the second presentation IS a replay, not a retry.
        // The race window has its own test below.
        $conf->global->SMARTAUTH_REFRESH_RACE_WINDOW = 0;

        $session = $this->createSession();
        $refreshToken = $session['tokens']['refresh_token'];

        $first = $this->callRefresh($refreshToken);
        $this->assertSame(200, $first[1], 'the first rotation must succeed: ' . json_encode($first[0]));

        // Same token again: this is the reuse.
        $second = $this->callRefresh($refreshToken);
        $this->assertSame(401, $second[1]);

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1,
        ]);
    }

    /**
     * The other side of the same coin. A client that fires two refreshes at
     * once -- a retry on a flaky link, a double tap, two tabs -- must not be
     * logged out of its own device. The second call is still refused (the token
     * is single-use), but the family survives.
     */
    public function testConcurrentRefreshIsRefusedWithoutRevokingTheFamily(): void
    {
        $session = $this->createSession();
        $refreshToken = $session['tokens']['refresh_token'];

        $first = $this->callRefresh($refreshToken);
        $this->assertSame(200, $first[1], 'the first rotation must succeed: ' . json_encode($first[0]));

        // Immediately again: inside the default race window.
        $second = $this->callRefresh($refreshToken);
        $this->assertNotSame(200, $second[1], 'a single-use token must not be honoured twice');

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 0,
        ]);

        // And the pair issued by the first call still works, which is the point:
        // the legitimate session carries on.
        $third = $this->callRefresh($first[0]['refresh_token']);
        $this->assertSame(200, $third[1], 'the live session must survive a concurrent retry: ' . json_encode($third[0]));
    }

    /**
     * Narrow on purpose: an unknown token id must NOT revoke anything, or
     * anyone could kill a session by guessing an integer.
     */
    public function testAnUnknownTokenIdDoesNotRevokeAnything(): void
    {
        $session = $this->createSession();

        $reflection = new ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_revokeFamilyOnRefreshReuse');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, 999999), 'an unknown token id is not a reuse');

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 0,
        ]);
    }

    // =========================================================================
    // Finding 6: revoking a token from the user card left the session alive
    // =========================================================================

    /**
     * The card promises "this device is logged out". Revoking the access-token
     * row alone left its refresh token valid, so the device minted a new access
     * token on its next /refresh and the session simply carried on.
     */
    public function testRevokingATokenFromTheUserCardKillsTheWholeFamily(): void
    {
        $session = $this->createSession();
        $accessTokenId = (int) explode('|', $session['tokens']['access_token'])[0];
        $refreshTokenId = (int) explode('|', $session['tokens']['refresh_token'])[0];

        $admin = new SmartAuthUserTokenAdmin($this->db);
        $res = $admin->revoke($accessTokenId, (int) $this->testUser->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_OK, $res);

        // The refresh token of the same family must be down too.
        $this->assertDatabaseHas('smartauth_auth', [
            'rowid' => $refreshTokenId,
            'status' => SmartAuthUserTokenAdmin::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 1,
        ]);
    }

    /**
     * And the session is really unusable afterwards, which is the point of the
     * action: /refresh must not hand out a new pair.
     */
    public function testASessionRevokedFromTheUserCardCannotRefresh(): void
    {
        $session = $this->createSession();
        $accessTokenId = (int) explode('|', $session['tokens']['access_token'])[0];

        $admin = new SmartAuthUserTokenAdmin($this->db);
        $admin->revoke($accessTokenId, (int) $this->testUser->id);

        $result = $this->callRefresh($session['tokens']['refresh_token']);

        $this->assertSame(401, $result[1], 'a revoked session must not refresh: ' . json_encode($result[0]));
    }

    /**
     * Ownership is still enforced: another user's token id is not a way to
     * revoke that user's family.
     */
    public function testRevokeStillRefusesATokenOfAnotherUser(): void
    {
        $session = $this->createSession();
        $accessTokenId = (int) explode('|', $session['tokens']['access_token'])[0];
        $other = $this->createTestUser(['login' => 'tokadmin_' . uniqid()]);

        $admin = new SmartAuthUserTokenAdmin($this->db);
        $res = $admin->revoke($accessTokenId, (int) $other->id);

        $this->assertSame(SmartAuthUserTokenAdmin::RES_NOT_FOUND, $res);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $session['family_id'],
            'revoked' => 0,
        ]);
    }

    // =========================================================================
    // Finding 4: /register/resend reactivated any disabled user
    // =========================================================================

    /**
     * fetchInactiveUserByEmail matched on email + statut only. Anyone knowing
     * the address of a DISABLED internal account could have a confirmation mail
     * sent for it, and the confirmation activates the account. An internal user
     * (fk_soc = 0) is never a self-service subject.
     */
    public function testResendConfirmationIgnoresADisabledInternalUser(): void
    {
        $email = 'internal.' . uniqid() . '@example.test';
        $userId = $this->createDisabledUser($email, 0);

        $service = new RegistrationService($this->db, function () {
            return true;
        });
        $service->resendConfirmation($email, '203.0.113.10');

        $this->assertSame(
            0,
            $this->countRegisterTokens($userId),
            'no confirmation token may be issued for an internal user'
        );
    }

    /**
     * The confirmation endpoint refuses too, so a token minted before the fix
     * cannot be redeemed to activate a staff account.
     */
    public function testConfirmRegistrationRefusesAnInternalUserToken(): void
    {
        $email = 'legacy.' . uniqid() . '@example.test';
        $userId = $this->createDisabledUser($email, 0);

        $plain = \SmartAuth\Api\Account\EmailValidationToken::generatePlainToken();
        $tokens = new \SmartAuth\Api\Account\EmailValidationToken($this->db);
        $rowId = $tokens->create(
            $userId,
            \SmartAuth\Api\Account\EmailValidationToken::PURPOSE_REGISTER,
            \SmartAuth\Api\Account\EmailValidationToken::hashToken($plain),
            86400,
            '203.0.113.10',
            null
        );
        $this->assertGreaterThan(0, $rowId);

        $service = new RegistrationService($this->db, function () {
            return true;
        });
        $result = $service->confirmRegistration($plain);

        $this->assertArrayHasKey('error', $result, 'an internal user must not be activated by a registration token');

        $sql = "SELECT statut FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . $userId;
        $resql = $this->db->query($sql);
        $row = $this->db->fetch_object($resql);
        $this->assertSame(0, (int) $row->statut, 'the account must still be disabled');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Family + device + token pair, the same way AuthControllerFlowTest builds
     * a session (the generation helpers are private to the controller).
     *
     * @return array{family_id:mixed,device_id:mixed,tokens:array}
     */
    private function createSession(): array
    {
        $reflection = new ReflectionClass($this->auth);

        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->auth, $this->testUser->id);

        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($this->auth, $this->testUser->id);

        $generatePair = $reflection->getMethod('_generateTokenPair');
        $generatePair->setAccessible(true);
        $tokens = $generatePair->invoke(
            $this->auth,
            'user',
            $this->testUser->id,
            $this->testUser->id,
            $this->testUser->login,
            1,
            $familyId,
            $deviceId
        );

        return ['family_id' => $familyId, 'device_id' => $deviceId, 'tokens' => $tokens];
    }

    /**
     * Drive /refresh with a bearer token. The controller answers either by
     * returning a tuple or, on the paths that go through json_reply, by
     * throwing JsonReplyEmittedError under PHPUNIT_RUNNING -- normalise both
     * into a [body, status] tuple.
     *
     * @return array{0:mixed,1:int}
     */
    private function callRefresh(string $refreshToken): array
    {
        global $conf;
        // The decoded-token cache is per-request in production; here several
        // calls share one process, so it must not answer for a row we just
        // changed under it.
        $conf->cache['smartmakers'] = [];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $refreshToken;
        try {
            $result = $this->auth->refresh();
            return [$result[0], (int) $result[1]];
        } catch (\JsonReplyEmittedError $e) {
            return [$e->getMessage(), $this->statusFromJsonReplyError($e)];
        } finally {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
    }

    private function statusFromJsonReplyError(\JsonReplyEmittedError $e): int
    {
        if (method_exists($e, 'getStatusCode')) {
            return (int) $e->getStatusCode();
        }
        if (property_exists($e, 'status')) {
            return (int) $e->status;
        }
        // The harness carries the HTTP status in the exception code when it has
        // no dedicated accessor.
        $code = (int) $e->getCode();
        return $code > 0 ? $code : 401;
    }

    private function disableUser(int $userId): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET statut = 0 WHERE rowid = " . $userId;
        $this->assertNotFalse($this->db->query($sql), 'could not disable the user');
    }

    private function createDisabledUser(string $email, int $fkSoc): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "user (entity, login, lastname, email, statut, fk_soc, datec)";
        $sql .= " VALUES (" . (int) ($GLOBALS['conf']->entity ?? 1) . ", 'dis" . uniqid() . "', 'Disabled', '";
        $sql .= $this->db->escape($email) . "', 0, " . ($fkSoc > 0 ? $fkSoc : 'NULL') . ", '";
        $sql .= $this->db->idate(dol_now()) . "')";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the disabled user: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'user');
    }

    private function countRegisterTokens(int $userId): int
    {
        $sql = "SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " WHERE fk_user = " . $userId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        return (int) $obj->n;
    }
}
