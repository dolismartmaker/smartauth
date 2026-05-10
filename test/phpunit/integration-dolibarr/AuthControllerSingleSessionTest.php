<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth\Api\AuthController;
use SmartAuth\Api\SmartTokenConfig;

/**
 * Integration tests for the single-session-per-(user,device,app) invariant:
 * relogging into the same SmartMaker app from the same physical device must
 * revoke the previous token family of that tuple, while leaving other apps
 * and other devices of the same user untouched.
 *
 * @covers \SmartAuth\Api\AuthController
 */
class AuthControllerSingleSessionTest extends DolibarrRealTestCase
{
    private AuthController $authController;
    private string $testDeviceUUID;

    protected function setUp(): void
    {
        parent::setUp();

        global $smartAuthAppID, $smartAuthAppKey;
        // Numeric appuid (int) -- mirrors prod where $tmpmodule->numero is an int.
        // Legacy string ids cast to 0, which would silently disable revocation.
        $smartAuthAppID = 500001;
        $smartAuthAppKey = 'test-secret-key-for-jwt-signing-min-32-chars';

        $this->authController = new AuthController();
        $this->testDeviceUUID = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $this->testDeviceUUID;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_X_DEVICEID']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REMOTE_ADDR']);
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

    private function login(): array
    {
        $payload = [
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 1,
            'rememberMe' => 0,
        ];
        $result = $this->authController->login($payload);
        $this->assertEquals(200, $result[1], 'login should succeed');
        return $result[0];
    }

    /**
     * Two logins on the same device for the same app:
     * the first family must end up revoked, the second alive.
     */
    public function testRelogOnSameDeviceSameAppRevokesPreviousFamily(): void
    {
        $first = $this->login();
        $firstAccessId = (int) explode('|', $first['access_token'])[0];

        // Pull the family id of the first session before relogging.
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $firstAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $firstFamilyId = (int) $obj->family_id;
        $this->assertGreaterThan(0, $firstFamilyId);

        // Same X-DeviceId, same $smartAuthAppID -> same (user, device, app, entity) tuple.
        $second = $this->login();
        $secondAccessId = (int) explode('|', $second['access_token'])[0];

        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $secondAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $secondFamilyId = (int) $obj->family_id;
        $this->assertGreaterThan(0, $secondFamilyId);
        $this->assertNotSame($firstFamilyId, $secondFamilyId, 'second login must mint a fresh family');

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyId,
            'revoked' => 1,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $secondFamilyId,
            'revoked' => 0,
        ]);

        // All tokens of the first family must be in STATUS_LOGOUT (= 9).
        $this->assertEquals(
            0,
            $this->getTableCount('smartauth_auth', [
                'family_id' => $firstFamilyId,
                'status' => AuthController::STATUS_VALID,
            ]),
            'first family must have no active tokens left'
        );
    }

    /**
     * Two logins on the same device but for DIFFERENT apps (different
     * $smartAuthAppID) must NOT revoke each other -- a user running capTodo
     * and capCRM on the same iPhone keeps both sessions alive.
     */
    public function testRelogOnSameDeviceDifferentAppKeepsBothFamilies(): void
    {
        global $smartAuthAppID;

        $smartAuthAppID = 500001; // app A
        $first = $this->login();
        $firstAccessId = (int) explode('|', $first['access_token'])[0];

        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $firstAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $firstFamilyId = (int) $obj->family_id;

        $smartAuthAppID = 500002; // app B -- different module/app
        $second = $this->login();
        $secondAccessId = (int) explode('|', $second['access_token'])[0];

        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $secondAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $secondFamilyId = (int) $obj->family_id;

        // Both families must remain alive: cross-app isolation.
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyId,
            'revoked' => 0,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $secondFamilyId,
            'revoked' => 0,
        ]);
    }

    /**
     * Two logins for the same app but from DIFFERENT physical devices
     * (different X-DeviceId) must NOT revoke each other -- a user logged
     * on his iPhone AND on his laptop keeps both sessions.
     */
    public function testRelogOnDifferentDeviceSameAppKeepsBothFamilies(): void
    {
        $first = $this->login();
        $firstAccessId = (int) explode('|', $first['access_token'])[0];
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $firstAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $firstFamilyId = (int) $obj->family_id;

        // Rotate X-DeviceId to simulate a second physical device.
        $_SERVER['HTTP_X_DEVICEID'] = $this->generateUUID();

        $second = $this->login();
        $secondAccessId = (int) explode('|', $second['access_token'])[0];
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $secondAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $secondFamilyId = (int) $obj->family_id;

        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyId,
            'revoked' => 0,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $secondFamilyId,
            'revoked' => 0,
        ]);
    }

    /**
     * Safety net: when $smartAuthAppID is missing (=0 after cast), the
     * revocation helper must bail out rather than killing every session
     * of the user on this device.
     */
    public function testEmptyAppIdBailsOutWithoutRevoking(): void
    {
        global $smartAuthAppID;

        // First login with a real app id, so we have a family to potentially mis-revoke.
        $smartAuthAppID = 500001;
        $first = $this->login();
        $firstAccessId = (int) explode('|', $first['access_token'])[0];
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = $firstAccessId";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $firstFamilyId = (int) $obj->family_id;

        // Now simulate a misconfigured caller (legacy string id -> casts to 0).
        $smartAuthAppID = 'misconfigured';
        $this->login();

        // First family must still be alive -- bail-out path is the safe one.
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyId,
            'revoked' => 0,
        ]);
    }
}
