<?php

/**
 * Integration tests for the logical "user device" controller.
 *
 * Covers the full lifecycle of a smartauth_user_devices row:
 *   - empty list initially
 *   - create + auto-link of the current JWT device
 *   - listing reflects session_count across attached technical devices
 *   - link an existing user_device to a second technical device
 *   - rename + label conflict detection
 *   - cascade revoke: every attached technical row and its tokens go down
 *   - cross-user isolation: a user cannot read or revoke another user's device
 *
 * @covers \SmartAuth\Api\Account\UserDeviceController
 * @covers \SmartAuthUserDevice
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

require_once __DIR__ . '/../../../../api/Account/UserDeviceController.php';
require_once __DIR__ . '/../../../../class/smartauthuserdevice.class.php';
require_once __DIR__ . '/../DolibarrRealTestCase.php';

use SmartAuth\Api\Account\UserDeviceController;
use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuthUserDevice;

class UserDeviceControllerTest extends DolibarrRealTestCase
{
    /** @var UserDeviceController */
    private $controller;

    /** @var SmartAuthUserDevice */
    private $repo;

    /** @var int */
    private $adminUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Make sure user_devices is empty between tests; the base class
        // cleans the technical tables but not this new one.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_user_devices");

        $this->repo = new SmartAuthUserDevice($this->db);
        $this->controller = new UserDeviceController($this->db, $this->repo);
    }

    public function testListEmptyByDefault(): void
    {
        $resp = $this->controller->index($this->makePayload());
        $this->assertSame(200, $resp[1]);
        $this->assertSame([], $resp[0]['devices']);
    }

    public function testCreateAutoLinksCurrentJwtDevice(): void
    {
        $techId = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');

        $resp = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $techId]));

        $this->assertSame(201, $resp[1]);
        $this->assertSame('mon iPhone', $resp[0]['label']);
        $this->assertSame('phone', $resp[0]['icon']);
        $this->assertTrue($resp[0]['linked']);
        $userDeviceId = (int) $resp[0]['id'];
        $this->assertGreaterThan(0, $userDeviceId);

        // Technical device must now reference the new logical row and
        // carry the new label so legacy queries see it.
        $row = $this->fetchTechnical($techId);
        $this->assertSame($userDeviceId, (int) $row->fk_user_device);
        $this->assertSame('mon iPhone', (string) $row->label);
    }

    public function testListReflectsSessionCount(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $createResp = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $userDeviceId = (int) $createResp[0]['id'];

        // Second PWA on the "same phone": create a second technical row
        // and link it to the same user_device through /link.
        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $linkResp = $this->controller->link($this->makePayload([
            'id' => $userDeviceId,
            'jwt_device_id' => $tech2,
        ]));
        $this->assertSame(200, $linkResp[1]);
        $this->assertTrue($linkResp[0]['linked']);

        $list = $this->controller->index($this->makePayload());
        $this->assertSame(200, $list[1]);
        $this->assertCount(1, $list[0]['devices']);
        $this->assertSame($userDeviceId, $list[0]['devices'][0]['id']);
        $this->assertSame(2, $list[0]['devices'][0]['session_count']);
    }

    public function testCreateRejectsDuplicateLabel(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));

        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $resp = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech2]));
        $this->assertSame(409, $resp[1]);
        $this->assertSame('label_already_used', $resp[0]['error']);
    }

    public function testCreateRejectsEmptyLabel(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload(['label' => '   ', 'jwt_device_id' => $tech1]));
        $this->assertSame(400, $resp[1]);
        $this->assertSame('invalid_label', $resp[0]['error']);
    }

    public function testRenamePropagatesToTechnicalDevices(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $created = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $userDeviceId = (int) $created[0]['id'];
        $this->controller->link($this->makePayload(['id' => $userDeviceId, 'jwt_device_id' => $tech2]));

        $resp = $this->controller->rename($this->makePayload([
            'id' => $userDeviceId,
            'label' => 'iPhone Max',
        ]));
        $this->assertSame(200, $resp[1]);
        $this->assertSame('iPhone Max', $resp[0]['label']);

        $row1 = $this->fetchTechnical($tech1);
        $row2 = $this->fetchTechnical($tech2);
        $this->assertSame('iPhone Max', (string) $row1->label);
        $this->assertSame('iPhone Max', (string) $row2->label);
    }

    public function testRenameRejectsConflict(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $b = $this->controller->create($this->makePayload(['label' => 'MacBook', 'jwt_device_id' => $tech2]));

        $resp = $this->controller->rename($this->makePayload([
            'id' => (int) $b[0]['id'],
            'label' => 'mon iPhone',
        ]));
        $this->assertSame(409, $resp[1]);
        $this->assertSame('label_already_used', $resp[0]['error']);
    }

    public function testRevokeCascadesEverywhere(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');

        $created = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $userDeviceId = (int) $created[0]['id'];
        $this->controller->link($this->makePayload(['id' => $userDeviceId, 'jwt_device_id' => $tech2]));

        // Two active families simulating two PWA sessions on the same phone.
        $fam1 = $this->insertTokenFamily($this->adminUserId);
        $fam2 = $this->insertTokenFamily($this->adminUserId);
        $this->insertAuthToken($this->adminUserId, $tech1, $fam1);
        $this->insertAuthToken($this->adminUserId, $tech2, $fam2);

        $resp = $this->controller->revoke($this->makePayload(['id' => $userDeviceId]));
        $this->assertSame(200, $resp[1]);
        $this->assertTrue($resp[0]['revoked']);
        $this->assertSame(2, $resp[0]['sessions_revoked']);

        // Both technical devices are now cancelled (status=9).
        $this->assertSame(9, (int) $this->fetchTechnical($tech1)->status);
        $this->assertSame(9, (int) $this->fetchTechnical($tech2)->status);

        // Both auth tokens are now logged out (status=9).
        $this->assertSame(9, (int) $this->fetchAuthByFamily($fam1)->status);
        $this->assertSame(9, (int) $this->fetchAuthByFamily($fam2)->status);

        // The user_device itself disappears from the active list.
        $list = $this->controller->index($this->makePayload());
        $this->assertSame([], $list[0]['devices']);
    }

    public function testCrossUserAccessIsForbidden(): void
    {
        $otherUser = $this->createTestUser(['login' => 'other_' . uniqid()]);

        // admin creates a device
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $userDeviceId = (int) $created[0]['id'];

        // otherUser tries to rename it
        $resp = $this->controller->rename([
            'user' => $otherUser,
            'user_id' => (int) $otherUser->id,
            'entity' => 1,
            'jwt_device_id' => 0,
            'id' => $userDeviceId,
            'label' => 'pwn',
        ]);
        $this->assertSame(404, $resp[1]);

        // otherUser tries to revoke it
        $resp = $this->controller->revoke([
            'user' => $otherUser,
            'user_id' => (int) $otherUser->id,
            'entity' => 1,
            'jwt_device_id' => 0,
            'id' => $userDeviceId,
        ]);
        $this->assertSame(404, $resp[1]);

        // admin's device still active
        $this->assertSame(1, (int) $this->repo->findById($userDeviceId, 1)['status']);
    }

    public function testLinkRejectsRevokedTarget(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload(['label' => 'mon iPhone', 'jwt_device_id' => $tech1]));
        $userDeviceId = (int) $created[0]['id'];
        $this->repo->revoke($userDeviceId, $this->adminUserId, 1);

        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $resp = $this->controller->link($this->makePayload(['id' => $userDeviceId, 'jwt_device_id' => $tech2]));
        $this->assertSame(410, $resp[1]);
    }

    public function testCreateDerivesViewportModeFromIconPhone(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'phone-device',
            'icon' => 'phone',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(201, $resp[1]);
        $this->assertSame('mobile', $resp[0]['viewport_mode']);
    }

    public function testCreateDerivesViewportModeFromIconTablet(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'tablet-device',
            'icon' => 'tablet',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(201, $resp[1]);
        $this->assertSame('tablet', $resp[0]['viewport_mode']);
    }

    public function testCreateDerivesViewportModeFromIconDesktop(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'desktop-device',
            'icon' => 'desktop',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(201, $resp[1]);
        $this->assertSame('desktop', $resp[0]['viewport_mode']);
    }

    public function testCreateExplicitViewportModeOverridesIconDefault(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'docked-ipad',
            'icon' => 'tablet',
            'viewport_mode' => 'desktop',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(201, $resp[1]);
        $this->assertSame('tablet', $resp[0]['icon']);
        $this->assertSame('desktop', $resp[0]['viewport_mode']);
    }

    public function testCreateAcceptsAutoViewportMode(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'auto-device',
            'icon' => 'phone',
            'viewport_mode' => 'auto',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(201, $resp[1]);
        $this->assertSame('auto', $resp[0]['viewport_mode']);
    }

    public function testCreateRejectsInvalidViewportMode(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $resp = $this->controller->create($this->makePayload([
            'label' => 'bad-device',
            'icon' => 'phone',
            'viewport_mode' => 'foo',
            'jwt_device_id' => $tech,
        ]));
        $this->assertSame(400, $resp[1]);
        $this->assertSame('invalid_viewport_mode', $resp[0]['error']);
    }

    public function testSetViewportModeHappyPath(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload([
            'label' => 'switch-device',
            'icon' => 'phone',
            'jwt_device_id' => $tech,
        ]));
        $userDeviceId = (int) $created[0]['id'];

        $resp = $this->controller->setViewportMode($this->makePayload([
            'id' => $userDeviceId,
            'viewport_mode' => 'tablet',
        ]));
        $this->assertSame(200, $resp[1]);
        $this->assertSame('tablet', $resp[0]['viewport_mode']);

        $row = $this->repo->findById($userDeviceId, 1);
        $this->assertSame('tablet', $row['viewport_mode']);
    }

    public function testSetViewportModeRejectsInvalidValue(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload([
            'label' => 'invalid-mode-device',
            'icon' => 'phone',
            'jwt_device_id' => $tech,
        ]));
        $userDeviceId = (int) $created[0]['id'];

        $resp = $this->controller->setViewportMode($this->makePayload([
            'id' => $userDeviceId,
            'viewport_mode' => 'phone',
        ]));
        $this->assertSame(400, $resp[1]);
        $this->assertSame('invalid_viewport_mode', $resp[0]['error']);

        // Original value preserved.
        $row = $this->repo->findById($userDeviceId, 1);
        $this->assertSame('mobile', $row['viewport_mode']);
    }

    public function testSetViewportModeEmptyClearsToNull(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload([
            'label' => 'clear-device',
            'icon' => 'phone',
            'jwt_device_id' => $tech,
        ]));
        $userDeviceId = (int) $created[0]['id'];

        $resp = $this->controller->setViewportMode($this->makePayload([
            'id' => $userDeviceId,
            'viewport_mode' => '',
        ]));
        $this->assertSame(200, $resp[1]);
        $this->assertNull($resp[0]['viewport_mode']);

        $row = $this->repo->findById($userDeviceId, 1);
        $this->assertNull($row['viewport_mode']);
    }

    public function testSetViewportModeRejectsOtherUserDevice(): void
    {
        $tech = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload([
            'label' => 'owned-device',
            'icon' => 'phone',
            'jwt_device_id' => $tech,
        ]));
        $userDeviceId = (int) $created[0]['id'];

        $otherUser = $this->createTestUser(['login' => 'other_' . uniqid()]);
        $resp = $this->controller->setViewportMode([
            'user' => $otherUser,
            'user_id' => (int) $otherUser->id,
            'entity' => 1,
            'jwt_device_id' => 0,
            'id' => $userDeviceId,
            'viewport_mode' => 'desktop',
        ]);
        $this->assertSame(404, $resp[1]);

        // Owner's value untouched.
        $row = $this->repo->findById($userDeviceId, 1);
        $this->assertSame('mobile', $row['viewport_mode']);
    }

    public function testIndexIncludesViewportMode(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $this->controller->create($this->makePayload([
            'label' => 'phone-A',
            'icon' => 'phone',
            'jwt_device_id' => $tech1,
        ]));
        $this->controller->create($this->makePayload([
            'label' => 'tablet-B',
            'icon' => 'tablet',
            'jwt_device_id' => $tech2,
        ]));

        $list = $this->controller->index($this->makePayload());
        $this->assertSame(200, $list[1]);
        $this->assertCount(2, $list[0]['devices']);
        $byLabel = [];
        foreach ($list[0]['devices'] as $d) {
            $byLabel[$d['label']] = $d;
        }
        $this->assertArrayHasKey('viewport_mode', $byLabel['phone-A']);
        $this->assertArrayHasKey('viewport_mode', $byLabel['tablet-B']);
        $this->assertSame('mobile', $byLabel['phone-A']['viewport_mode']);
        $this->assertSame('tablet', $byLabel['tablet-B']['viewport_mode']);
    }

    public function testLinkReturnsViewportMode(): void
    {
        $tech1 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-1');
        $created = $this->controller->create($this->makePayload([
            'label' => 'linked-device',
            'icon' => 'tablet',
            'jwt_device_id' => $tech1,
        ]));
        $userDeviceId = (int) $created[0]['id'];

        $tech2 = $this->insertTechnicalDevice($this->adminUserId, 'uuid-pwa-2');
        $resp = $this->controller->link($this->makePayload([
            'id' => $userDeviceId,
            'jwt_device_id' => $tech2,
        ]));
        $this->assertSame(200, $resp[1]);
        $this->assertSame('tablet', $resp[0]['viewport_mode']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makePayload(array $overrides = []): array
    {
        return array_merge([
            'user' => $this->testUser,
            'user_id' => $this->adminUserId,
            'entity' => 1,
            'jwt_device_id' => 0,
        ], $overrides);
    }

    private function insertTechnicalDevice(int $userId, string $uuid): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " (uuid, fk_user_creat, date_creation, status, entity)";
        $sql .= " VALUES ('" . $this->db->escape($uuid) . "', " . $userId . ",";
        $sql .= " '" . $this->db->idate(dol_now()) . "', 1, 1)";
        $this->db->query($sql);
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
    }

    private function fetchTechnical(int $rowid): object
    {
        $sql = "SELECT rowid, fk_user_device, label, status FROM " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " WHERE rowid = " . $rowid;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertNotFalse($obj, "Technical device $rowid not found");
        return $obj;
    }

    private function insertTokenFamily(int $userId): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family";
        $sql .= " (fk_user, created_at, last_refresh_at, revoked)";
        $sql .= " VALUES (" . $userId . ", " . time() . ", " . time() . ", 0)";
        $this->db->query($sql);
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_token_family");
    }

    private function insertAuthToken(int $userId, int $deviceId, int $familyId): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " (appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, fk_device_id, family_id, auth_element, status, entity)";
        $sql .= " VALUES ('test-app', 'salt', '" . $this->db->idate(dol_now()) . "', '" . $this->db->idate(dol_now() + 3600) . "',";
        $sql .= " " . $userId . ", " . $userId . ", " . $deviceId . ", " . $familyId . ", 'user', 1, 1)";
        $this->db->query($sql);
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
    }

    private function fetchAuthByFamily(int $familyId): object
    {
        $sql = "SELECT status FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE family_id = " . $familyId;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertNotFalse($obj, "Auth token for family $familyId not found");
        return $obj;
    }
}
