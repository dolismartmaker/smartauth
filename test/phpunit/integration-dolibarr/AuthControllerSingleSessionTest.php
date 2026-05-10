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
     * Label collapse: user logs in on a fresh UUID, then names the new
     * device with a label that already belongs to a previous device of
     * his (typical case = app reinstalled / cookies cleared, "same"
     * physical iPhone). The previous device row must be canceled and
     * ALL its sessions revoked across every app -- the user cannot have
     * "2 sessions on iPhone de Bertrand" anywhere.
     */
    public function testRenamingNewDeviceToExistingLabelCollapsesSibling(): void
    {
        global $smartAuthAppID, $user;

        // --- Phase 1: first device, app A, named "iPhone de Bertrand"
        $smartAuthAppID = 500001;
        $firstUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $firstUuid;
        $firstLogin = $this->login();

        $sql = "SELECT family_id, fk_device_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) explode('|', $firstLogin['access_token'])[0];
        $obj = $this->db->fetch_object($this->db->query($sql));
        $firstFamilyA = (int) $obj->family_id;
        $firstDeviceId = (int) $obj->fk_device_id;

        // Assign the label to the first device.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $firstLogin['access_token'];
        ob_start();
        $this->authController->device([
            'user' => $user,
            'uuid' => $firstUuid,
            'label' => 'iPhone de Bertrand',
            'entity' => 1,
        ]);
        ob_end_clean();

        // --- Phase 2: same user opens a separate session on a DIFFERENT app
        // (capCRM) from the same physical device -- this should survive
        // the regular per-(user,device,app) revocation logic.
        $smartAuthAppID = 500002;
        $firstLoginAppB = $this->login(); // same X-DeviceId still set
        $firstFamilyB = (int) $this->db->fetch_object($this->db->query(
            "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = "
            . (int) explode('|', $firstLoginAppB['access_token'])[0]
        ))->family_id;

        // --- Phase 3: fresh install -> brand new UUID, user logs in on app A
        $smartAuthAppID = 500001;
        $secondUuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $secondUuid;
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $secondLogin = $this->login();

        $sql = "SELECT family_id, fk_device_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE rowid = " . (int) explode('|', $secondLogin['access_token'])[0];
        $obj = $this->db->fetch_object($this->db->query($sql));
        $secondFamily = (int) $obj->family_id;
        $secondDeviceId = (int) $obj->fk_device_id;
        $this->assertNotSame($firstDeviceId, $secondDeviceId, 'new UUID must yield a new device row');

        // --- Phase 4: user picks the existing label "iPhone de Bertrand"
        // for this fresh device -> triggers the collapse.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secondLogin['access_token'];
        ob_start();
        $this->authController->device([
            'user' => $user,
            'uuid' => $secondUuid,
            'label' => 'iPhone de Bertrand',
            'entity' => 1,
        ]);
        ob_end_clean();

        // The previous device row must now be STATUS_CANCELED (=9).
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $firstDeviceId,
            'status' => \SmartAuthDevices::STATUS_CANCELED,
        ]);
        // The kept device must remain validated.
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $secondDeviceId,
            'status' => \SmartAuthDevices::STATUS_VALIDATED,
        ]);

        // BOTH previous families (app A + app B) must be revoked, even though
        // only app A triggered the collapse. This is the cross-app reach.
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyA,
            'revoked' => 1,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyB,
            'revoked' => 1,
        ]);
        // The newly created family on the kept device stays alive.
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $secondFamily,
            'revoked' => 0,
        ]);
    }

    /**
     * Devices owned by the user but carrying a DIFFERENT label must stay
     * untouched. Naming the new device "iPhone de Bertrand" must not
     * collapse "Mac de Bertrand".
     */
    public function testRenamingDoesNotTouchOtherLabels(): void
    {
        global $smartAuthAppID, $user;

        $smartAuthAppID = 500001;

        // Login + name device A "Mac de Bertrand".
        $uuidA = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $uuidA;
        $loginA = $this->login();
        $deviceA = (int) $this->db->fetch_object($this->db->query(
            "SELECT fk_device_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = "
            . (int) explode('|', $loginA['access_token'])[0]
        ))->fk_device_id;
        $familyA = (int) $this->db->fetch_object($this->db->query(
            "SELECT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = "
            . (int) explode('|', $loginA['access_token'])[0]
        ))->family_id;

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $loginA['access_token'];
        ob_start();
        $this->authController->device([
            'user' => $user,
            'uuid' => $uuidA,
            'label' => 'Mac de Bertrand',
            'entity' => 1,
        ]);
        ob_end_clean();

        // New device B, name it "iPhone de Bertrand" -- different label.
        $uuidB = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $uuidB;
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $loginB = $this->login();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $loginB['access_token'];
        ob_start();
        $this->authController->device([
            'user' => $user,
            'uuid' => $uuidB,
            'label' => 'iPhone de Bertrand',
            'entity' => 1,
        ]);
        ob_end_clean();

        // Device A must still be validated, its family still alive.
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $deviceA,
            'status' => \SmartAuthDevices::STATUS_VALIDATED,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $familyA,
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
