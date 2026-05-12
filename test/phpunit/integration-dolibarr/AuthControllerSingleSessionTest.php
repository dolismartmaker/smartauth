<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/SmartTokenConfig.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';
require_once __DIR__ . '/../../../class/smartauthdevices.class.php';

use SmartAuth\Api\AuthController;

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
     * Logical-device grouping: a user names device A "iPhone de Bertrand",
     * later names a fresh device B with the same label, and both technical
     * rows now point at the SAME logical user_device parent. Cross-app
     * sessions are preserved -- the previous "winner takes all" behaviour
     * (revoke the sibling on rename) was the source of accidental
     * sign-outs whenever a user re-tagged a PWA, and is replaced by the
     * new smartauth_user_devices grouping. Revocation now only happens
     * when the user explicitly clicks "revoke this device" on the
     * logical row, which cascades through every attached technical row
     * in one shot (covered separately in UserDeviceControllerTest).
     */
    public function testRenamingNewDeviceToExistingLabelLinksToSameUserDevice(): void
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

        // Assign the label to the first device. This creates the logical
        // user_device row "iPhone de Bertrand" and links the technical
        // row to it.
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
        // (capCRM) from the same physical device -- this survives the
        // per-(user,device,app) revocation logic.
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
        // for this fresh device -> links to the same logical parent.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secondLogin['access_token'];
        ob_start();
        $this->authController->device([
            'user' => $user,
            'uuid' => $secondUuid,
            'label' => 'iPhone de Bertrand',
            'entity' => 1,
        ]);
        ob_end_clean();

        // Both technical device rows must stay validated. The old
        // "collapse" semantics is gone.
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $firstDeviceId,
            'status' => \SmartAuthDevices::STATUS_VALIDATED,
        ]);
        $this->assertDatabaseHas('smartauth_devices', [
            'rowid' => $secondDeviceId,
            'status' => \SmartAuthDevices::STATUS_VALIDATED,
        ]);

        // Every token family stays alive: PWAs on the same physical phone
        // coexist regardless of when they were tagged.
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyA,
            'revoked' => 0,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $firstFamilyB,
            'revoked' => 0,
        ]);
        $this->assertDatabaseHas('smartauth_token_family', [
            'rowid' => $secondFamily,
            'revoked' => 0,
        ]);

        // Both technical devices now share the same logical parent.
        $sql = "SELECT rowid, fk_user_device FROM " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " WHERE rowid IN (" . $firstDeviceId . "," . $secondDeviceId . ")";
        $resql = $this->db->query($sql);
        $parents = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $parents[(int) $obj->rowid] = (int) $obj->fk_user_device;
        }
        $this->assertCount(2, $parents);
        $this->assertGreaterThan(0, $parents[$firstDeviceId]);
        $this->assertSame(
            $parents[$firstDeviceId],
            $parents[$secondDeviceId],
            'Both technical devices must point at the same logical user_device'
        );

        // Exactly one logical user_device named "iPhone de Bertrand" exists.
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "smartauth_user_devices";
        $sql .= " WHERE fk_user = " . (int) $user->id . " AND label = 'iPhone de Bertrand'";
        $count = (int) $this->db->fetch_object($this->db->query($sql))->cnt;
        $this->assertSame(1, $count, 'a single logical user_device must back the shared label');
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
     * Multi-entity safety: a device created while the active entity is 2 must
     * be stamped entity=2 in the DB, NOT the table default of 1. Without this
     * stamping, every downstream "WHERE entity = X" filter (token issuance,
     * label collapse, device picker) silently misses the device.
     *
     * Note: AuthController::login() forces $conf->entity from the payload, so
     * we steer the test via payload['entity']=2.
     */
    public function testDeviceCreationStampsCurrentEntity(): void
    {
        global $smartAuthAppID;

        $smartAuthAppID = 500001;
        $uuid = $this->generateUUID();
        $_SERVER['HTTP_X_DEVICEID'] = $uuid;

        $result = $this->authController->login([
            'email' => 'admin',
            'password' => 'adminadmin',
            'entity' => 2,
            'rememberMe' => 0,
        ]);
        $this->assertEquals(200, $result[1], 'login should succeed');

        $this->assertDatabaseHas('smartauth_devices', [
            'uuid' => $uuid,
            'entity' => 2,
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
