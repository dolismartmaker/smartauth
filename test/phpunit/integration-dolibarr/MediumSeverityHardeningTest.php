<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/AuthController.php';
require_once __DIR__ . '/../../../api/RouteController.php';
require_once __DIR__ . '/../../../api/SmartUpload.php';
require_once __DIR__ . '/../../../api/Account/EmailValidationToken.php';
require_once __DIR__ . '/../../../class/smartauthqrpairing.class.php';

use SmartAuth\Api\Account\EmailValidationToken;
use SmartAuth\Api\RouteController;
use SmartAuth\Api\SmartUpload;
use SmartAuthQrPairing;

/**
 * The medium-severity findings of the 2026-08-24 audit.
 *
 * Nothing here grants access on its own; each one either wears down a defence
 * (a rate-limit bucket that anyone can clear, an IP that anyone can forge) or
 * quietly does nothing at all (a cron statement that has never run because it
 * writes to a column that does not exist).
 *
 * @covers \SmartAuth\Api\RouteController::get_client_ip
 * @covers \SmartAuth\Api\Account\EmailValidationToken
 * @covers \SmartAuth\Api\SmartUpload::cleanup
 * @covers \SmartAuth
 */
class MediumSeverityHardeningTest extends DolibarrRealTestCase
{
    /** @var string[] Directories created by a test, removed in tearDown */
    private $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
    }

    protected function tearDown(): void
    {
        global $conf;

        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $conf->global->SMARTAUTH_TRUST_PROXY_HEADERS,
            $conf->global->SMARTAUTH_TOKEN_EOL_DAYS
        );

        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    // =========================================================================
    // Client IP resolution
    // =========================================================================

    /**
     * A malformed X-Real-IP used to be carried down to the final format check,
     * fail it, and return the constant '0.0.0.0' -- one shared bucket for
     * everybody. A single bad header was then enough to rate-limit the whole
     * instance.
     */
    public function testAGarbageForwardedHeaderFallsBackToThePeer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';   // trusted range, headers honoured
        $_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip-at-all';

        $ip = RouteController::get_client_ip();

        $this->assertNotSame('0.0.0.0', $ip, 'a bad header must not collapse everyone into one bucket');
        $this->assertSame('127.0.0.1', $ip);
    }

    public function testAValidForwardedHeaderIsStillHonoured(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.9';

        $this->assertSame('203.0.113.9', RouteController::get_client_ip());
    }

    /**
     * The escape hatch for installs where the front proxy is not known to
     * overwrite the header (or where smartauth is exposed directly).
     */
    public function testProxyHeadersCanBeDistrustedEntirely(): void
    {
        global $conf;

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.9';
        $conf->global->SMARTAUTH_TRUST_PROXY_HEADERS = 0;

        $this->assertSame('127.0.0.1', RouteController::get_client_ip());
    }

    // =========================================================================
    // Token EOL sweep
    // =========================================================================

    /**
     * The sweep wrote to a `token` column that llx_smartauth_auth does not
     * have, so the statement failed every time and no token has ever been
     * expired by it.
     */
    public function testTheTokenEolSweepActuallyCancelsExpiredTokens(): void
    {
        global $conf;

        $conf->global->SMARTAUTH_TOKEN_EOL_DAYS = 30;

        $expiredId = $this->insertAuthRow(dol_now() - 86400, 1);
        $liveId = $this->insertAuthRow(dol_now() + 86400, 1);

        $module = new \SmartAuth($this->db);
        $this->assertSame(0, $module->doScheduledJob(), 'the cron must report success');

        $this->assertSame(9, $this->authStatus($expiredId), 'a token past its EOL must be canceled');
        $this->assertSame(1, $this->authStatus($liveId), 'a live token must be left alone');
    }

    // =========================================================================
    // Email validation tokens and entities
    // =========================================================================

    /**
     * The DAO defaulted to the literal entity 1. On entity 2 that wrote the
     * registration token into another entity, where the properly-scoped
     * lookups never found it.
     */
    public function testEmailValidationTokensUseTheCurrentEntity(): void
    {
        global $conf;

        $saved = (int) $conf->entity;
        $conf->entity = 3;
        try {
            $tokens = new EmailValidationToken($this->db);
            $plain = EmailValidationToken::generatePlainToken();
            $rowId = $tokens->create(
                (int) $this->testUser->id,
                EmailValidationToken::PURPOSE_REGISTER,
                EmailValidationToken::hashToken($plain),
                3600
            );
            $this->assertGreaterThan(0, $rowId);

            $this->assertDatabaseHas('smartauth_email_validation', [
                'rowid' => $rowId,
                'entity' => 3,
            ]);

            // And it reads back in that same entity, without being told.
            $row = $tokens->findActive(EmailValidationToken::hashToken($plain), EmailValidationToken::PURPOSE_REGISTER);
            $this->assertNotNull($row, 'the token must be found in the entity it was written to');
            $this->assertSame($rowId, (int) $row['rowid']);
        } finally {
            $conf->entity = $saved;
        }
    }

    // =========================================================================
    // QR pairing across entities
    // =========================================================================

    /**
     * The mobile side of the QR flow is unauthenticated, so its $conf->entity
     * is always 1. Scoping the pairing lookup on it made every pairing created
     * from another entity impossible to claim: the feature was mono-entity by
     * accident. The pairing id is 32 random hex characters and carries its own
     * entity.
     */
    public function testAPairingOfAnotherEntityIsStillReachableById(): void
    {
        $repo = new SmartAuthQrPairing($this->db);
        $pairingId = SmartAuthQrPairing::generatePairingId();

        $this->assertGreaterThan(
            0,
            $repo->createPending($pairingId, (int) $this->testUser->id, '198.51.100.4', 7),
            'could not create the pairing'
        );

        $row = $repo->findByPairingId($pairingId);
        $this->assertNotNull($row, 'a pairing must be reachable by its id whatever the current entity');
        $this->assertSame(7, (int) $row['entity']);

        // Explicit scoping still works, and still excludes other entities.
        $this->assertNotNull($repo->findByPairingId($pairingId, 7));
        $this->assertNull($repo->findByPairingId($pairingId, 1));
    }

    // =========================================================================
    // Upload staging
    // =========================================================================

    /**
     * A staging directory whose meta.json is missing (process killed between
     * mkdir and the metadata write) was skipped by every cleanup pass and
     * stayed on disk for ever.
     */
    public function testCleanupCollectsAStagingDirectoryWithNoMetadata(): void
    {
        global $conf;

        $base = (!empty($conf->smartauth->dir_output) ? $conf->smartauth->dir_output : sys_get_temp_dir()) . '/upload-staging';
        $orphan = $base . '/' . ((int) $this->testUser->id) . '/upl_' . str_repeat('o', 60);
        $recent = $base . '/' . ((int) $this->testUser->id) . '/upl_' . str_repeat('r', 60);

        foreach ([$orphan, $recent] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $this->markTestSkipped('cannot create a staging directory here');
            }
            file_put_contents($dir . '/payload.bin', 'orphan bytes');
            $this->tempDirs[] = $dir;
        }

        // Old enough to be past any legitimate staging window.
        @touch($orphan, time() - (SmartUpload::MAX_TTL * 4));

        SmartUpload::cleanup();

        $this->assertDirectoryDoesNotExist($orphan, 'an old directory with no usable meta.json must be collected');
        $this->assertDirectoryExists($recent, 'a fresh directory must be left alone (an upload may be in flight)');
    }

    /**
     * A rejected file used to have its raw, client-supplied name echoed back.
     */
    public function testRejectedFileNamesAreSanitisedBeforeBeingEchoed(): void
    {
        $hostile = '<img src=x onerror=alert(1)>.jpg';

        $safe = SmartUpload::safeDisplayName($hostile);

        $this->assertStringNotContainsString('<', $safe);
        $this->assertStringNotContainsString('>', $safe);
        $this->assertNotSame($hostile, $safe);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function insertAuthRow(int $dateEol, int $status): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " (appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, auth_element,";
        $sql .= " token_type, fk_device_id, status, entity)";
        $sql .= " VALUES (1, 'saltvalue', '" . $this->db->idate(dol_now()) . "', '";
        $sql .= $this->db->idate($dateEol) . "', " . ((int) $this->testUser->id) . ", ";
        $sql .= ((int) $this->testUser->id) . ", 'user', 'access', " . ((int) $this->testDevice->id) . ", ";
        $sql .= $status . ", " . ((int) ($GLOBALS['conf']->entity ?? 1)) . ")";

        if (!$this->db->query($sql)) {
            $this->fail('could not insert the auth row: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_auth');
    }

    private function authStatus(int $rowid): int
    {
        $resql = $this->db->query("SELECT status FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE rowid = " . $rowid);
        if (!$resql) {
            $this->fail('could not read the auth row: ' . $this->db->lasterror());
        }
        $row = $this->db->fetch_object($resql);
        return $row === null ? -1 : (int) $row->status;
    }
}
