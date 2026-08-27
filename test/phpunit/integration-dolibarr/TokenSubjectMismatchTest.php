<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RouteController.php';
require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth\Api\AuthController;
use SmartAuth\Api\RouteController;
use ReflectionClass;

/**
 * Guards against the login-reassignment takeover: a JWT resolves its subject
 * through the signed login claim, so once an admin renames a departed account
 * and hands the login to a replacement, a token minted for the old owner must
 * NOT operate as the new owner. handleAuthentication() cross-checks the signed
 * user_id claim against the user the login resolves to and kills the family on
 * mismatch.
 *
 * @covers \SmartAuth\Api\RouteController::handleAuthentication
 */
class TokenSubjectMismatchTest extends DolibarrRealTestCase
{
    private AuthController $authController;
    private string $testDeviceUUID;
    private int $initialObLevel;

    protected function setUp(): void
    {
        parent::setUp();
        // json_reply() opens an output buffer before throwing under
        // PHPUNIT_RUNNING; track the level so tearDown can drop orphans.
        $this->initialObLevel = ob_get_level();
        $this->authController = new AuthController();
        $this->testDeviceUUID = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $this->testDeviceUUID;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        global $smartAuthAppID, $smartAuthAppKey;
        $smartAuthAppID = 'test-app-id';
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        parent::tearDown();
        unset($_SERVER['HTTP_X_DEVICEID'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);
    }

    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Mint a full access+refresh pair bound to the given user row (family,
     * device, salt chain identical to a production /login).
     */
    private function mintPairForUser(int $userId, string $login): array
    {
        $reflection = new ReflectionClass($this->authController);

        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->authController, $userId);

        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($this->authController, $userId);

        $generatePair = $reflection->getMethod('_generateTokenPair');
        $generatePair->setAccessible(true);

        $tokens = $generatePair->invoke(
            $this->authController,
            'user',
            $userId,
            $userId,
            $login,
            1,
            $familyId,
            $deviceId
        );

        return [
            'family_id' => $familyId,
            'device_id' => $deviceId,
            'tokens' => $tokens,
        ];
    }

    /**
     * Invoke the private router gate the way api.php does on every protected
     * route. json_reply() throws JsonReplyEmittedError under PHPUNIT_RUNNING,
     * so a rejection surfaces as the exception and a pass as the context array.
     */
    private function handleAuthentication()
    {
        global $db, $conf, $mysoc;
        $method = new \ReflectionMethod(RouteController::class, 'handleAuthentication');
        $method->setAccessible(true);
        return $method->invoke(null, true, $db, $conf, $mysoc);
    }

    public function testReassignedLoginTokenIsRejectedAndFamilyRevoked(): void
    {
        $login = 'reassign_' . uniqid();
        $victim = $this->createTestUser(['login' => $login]);

        $session = $this->mintPairForUser((int) $victim->id, $login);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];

        // The admin renames the departed account and hands the login to the
        // replacement: the token's login claim now resolves to another row.
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "user SET login = '" . $this->db->escape($login . '.old') . "'"
            . " WHERE rowid = " . (int) $victim->id
        );
        $replacement = $this->createTestUser(['login' => $login]);

        try {
            $this->handleAuthentication();
            $this->fail('handleAuthentication must reject a token whose login resolves to another user');
        } catch (\JsonReplyEmittedError $e) {
            // Expected 401 path.
        }

        // The whole family is dead: the refresh token must not be able to
        // rotate new pairs that resolve to the wrong subject.
        $sql = "SELECT revoked FROM " . MAIN_DB_PREFIX . "smartauth_token_family WHERE rowid = " . (int) $session['family_id'];
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql);
        $this->assertEquals(1, (int) $this->db->fetch_object($resql)->revoked);

        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "smartauth_auth"
            . " WHERE family_id = " . (int) $session['family_id'] . " AND status <> 9";
        $resql = $this->db->query($sql);
        $this->assertNotFalse($resql);
        $this->assertEquals(0, (int) $this->db->fetch_object($resql)->n);
    }

    public function testUnchangedLoginStillAuthenticates(): void
    {
        $login = 'stable_' . uniqid();
        $user = $this->createTestUser(['login' => $login]);

        $session = $this->mintPairForUser((int) $user->id, $login);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];

        $context = $this->handleAuthentication();

        $this->assertIsArray($context);
        $this->assertEquals((int) $user->id, (int) $context[0]->id);
    }

    public function testTokenWithoutUserBindingStaysAccepted(): void
    {
        // Tokens minted before the user_id claim existed (or machine tokens
        // with no user binding) must not be locked out by the cross-check:
        // the guard only fires on a positive mismatching claim.
        $login = 'unbound_' . uniqid();
        $user = $this->createTestUser(['login' => $login]);

        $reflection = new ReflectionClass($this->authController);
        $createFamily = $reflection->getMethod('_createTokenFamily');
        $createFamily->setAccessible(true);
        $familyId = $createFamily->invoke($this->authController, (int) $user->id);

        $createDevice = $reflection->getMethod('_createDeviceIdIfNeeded');
        $createDevice->setAccessible(true);
        $deviceId = $createDevice->invoke($this->authController, (int) $user->id);

        $generate = $reflection->getMethod('_generateToken');
        $generate->setAccessible(true);
        $access = $generate->invoke(
            $this->authController,
            'user',
            (int) $user->id,
            0,
            $login,
            1,
            \SmartAuth\Api\SmartTokenConfig::TYPE_ACCESS,
            \SmartAuth\Api\SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
            $familyId,
            $deviceId,
            ''
        );

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $context = $this->handleAuthentication();

        $this->assertIsArray($context);
        $this->assertEquals((int) $user->id, (int) $context[0]->id);
    }
}
