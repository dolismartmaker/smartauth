<?php

/**
 * Integration tests for the new-login email alert (NewLoginNotifier).
 *
 * @covers \SmartAuth\Api\Account\NewLoginNotifier
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\Account\NewLoginNotifier;

require_once __DIR__ . '/../../../../api/Account/NewLoginNotifier.php';

class NewLoginNotifierTest extends DolibarrRealTestCase
{
    /** @var array<int, array{to:string,subject:string,text:string,html:string}> */
    private $sentEmails = [];

    /** @var NewLoginNotifier */
    private $notifier;

    /** @var \User */
    private $notifyUser;

    /** @var int */
    private $deviceA;

    /** @var int */
    private $deviceB;

    protected function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf->global->SMARTAUTH_NEW_LOGIN_NOTIFY = '1';
        $conf->global->SMARTAUTH_NEW_LOGIN_NOTIFY_LOOKBACK_DAYS = '30';
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';

        $this->sentEmails = [];
        $this->notifier = new NewLoginNotifier($this->db, function ($to, $subject, $text, $html) {
            $this->sentEmails[] = compact('to', 'subject', 'text', 'html');
            return true;
        });

        // Build a fresh user with a non-empty email so the notifier
        // accepts it. Picking an arbitrary high id to avoid colliding
        // with the admin row that other tests reuse.
        $this->notifyUser = $this->createTestUser([
            'login' => 'newlogin_' . uniqid(),
            'email' => 'newlogin_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1,
        ]);

        // Two devices for this user, so we can play known-vs-new.
        $this->deviceA = $this->insertDevice('deviceA-' . uniqid());
        $this->deviceB = $this->insertDevice('deviceB-' . uniqid());

        // Pre-existing token rows so we are NOT on the "first login"
        // path (which is intentionally skipped).
        $this->insertAuthRow((int) $this->notifyUser->id, '203.0.113.10', $this->deviceA, dol_now() - 3600);
        $this->insertAuthRow((int) $this->notifyUser->id, '203.0.113.10', $this->deviceA, dol_now() - 60);
    }

    public function testNotifyDoesNothingWhenSwitchIsOff(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_NEW_LOGIN_NOTIFY = '0';

        $reason = $this->notifier->notifyIfNewLogin($this->notifyUser, '198.51.100.7', $this->deviceB);

        $this->assertSame(NewLoginNotifier::REASON_DISABLED, $reason);
        $this->assertCount(0, $this->sentEmails);
    }

    public function testNotifySkipsFirstLoginEvenIfNotifyEnabled(): void
    {
        // Brand-new user with no auth rows at all -> first login path.
        $virginUser = $this->createTestUser([
            'login' => 'virgin_' . uniqid(),
            'email' => 'virgin_' . uniqid() . '@example.com',
            'pass' => 'TestPass123!',
            'statut' => 1,
        ]);

        $reason = $this->notifier->notifyIfNewLogin($virginUser, '203.0.113.99', $this->deviceA);

        $this->assertSame(NewLoginNotifier::REASON_FIRST_LOGIN_SKIPPED, $reason);
        $this->assertCount(0, $this->sentEmails);
    }

    public function testKnownIpAndDeviceDoNotTriggerEmail(): void
    {
        $reason = $this->notifier->notifyIfNewLogin($this->notifyUser, '203.0.113.10', $this->deviceA);

        $this->assertSame(NewLoginNotifier::REASON_KNOWN, $reason);
        $this->assertCount(0, $this->sentEmails);
    }

    public function testNewIpTriggersEmail(): void
    {
        $reason = $this->notifier->notifyIfNewLogin($this->notifyUser, '198.51.100.42', $this->deviceA);

        $this->assertSame(NewLoginNotifier::REASON_SENT, $reason);
        $this->assertCount(1, $this->sentEmails);
        $sent = $this->sentEmails[0];
        $this->assertSame($this->notifyUser->email, $sent['to']);
        $this->assertNotEmpty($sent['subject']);
        $this->assertNotEmpty($sent['html']);
        $this->assertStringContainsString('198.51.100.42', $sent['html']);
    }

    public function testNewDeviceTriggersEmail(): void
    {
        // Same IP as a known row, but a never-seen device.
        $reason = $this->notifier->notifyIfNewLogin($this->notifyUser, '203.0.113.10', $this->deviceB);

        $this->assertSame(NewLoginNotifier::REASON_SENT, $reason);
        $this->assertCount(1, $this->sentEmails);
        $this->assertStringContainsString('203.0.113.10', $this->sentEmails[0]['html']);
    }

    public function testIpOlderThanLookbackWindowCountsAsNew(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_NEW_LOGIN_NOTIFY_LOOKBACK_DAYS = '7';

        // 60-day-old row: out of the 7-day window.
        $oldIp = '198.51.100.200';
        $this->insertAuthRow((int) $this->notifyUser->id, $oldIp, $this->deviceA, dol_now() - (60 * 24 * 3600));

        // Same device (known), same IP but only seen long ago -> alert.
        $reason = $this->notifier->notifyIfNewLogin($this->notifyUser, $oldIp, $this->deviceA);

        $this->assertSame(NewLoginNotifier::REASON_SENT, $reason);
        $this->assertCount(1, $this->sentEmails);
    }

    public function testEmailSenderFailureIsReportedSafely(): void
    {
        $failingNotifier = new NewLoginNotifier($this->db, function () {
            return false;
        });

        $reason = $failingNotifier->notifyIfNewLogin($this->notifyUser, '198.51.100.99', $this->deviceB);

        $this->assertSame(NewLoginNotifier::REASON_SEND_FAILED, $reason);
    }

    public function testNotifyHandlesUserWithoutEmailGracefully(): void
    {
        $u = clone $this->notifyUser;
        $u->email = '';

        $reason = $this->notifier->notifyIfNewLogin($u, '198.51.100.55', $this->deviceB);

        $this->assertSame(NewLoginNotifier::REASON_DISABLED, $reason);
        $this->assertCount(0, $this->sentEmails);
    }

    private function insertDevice(string $uuid): int
    {
        global $user;
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices"
            . " (uuid, fk_user_creat, label, date_creation, status, entity)"
            . " VALUES ('" . $this->db->escape($uuid) . "',"
            . " " . (int) ($user->id ?? 1) . ","
            . " 'test-device',"
            . " '" . $this->db->idate(dol_now()) . "',"
            . " 1, 1)";
        $this->db->query($sql);
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    private function insertAuthRow(int $fkAuthid, string $ip, int $deviceId, int $whenTs): void
    {
        global $user;
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth"
            . " (appuid, salt, date_creation, fk_user_creat, fk_authid, auth_element, ip, fk_device_id, status, entity)"
            . " VALUES ("
            . " 1,"
            . " '" . $this->db->escape('test-salt-' . random_int(1, 1000000)) . "',"
            . " '" . $this->db->idate($whenTs) . "',"
            . " " . (int) ($user->id ?? 1) . ","
            . " " . $fkAuthid . ","
            . " 'user',"
            . " '" . $this->db->escape($ip) . "',"
            . " " . $deviceId . ","
            . " 1, 1)";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->fail('insertAuthRow failed: ' . $this->db->lasterror());
        }
    }
}
