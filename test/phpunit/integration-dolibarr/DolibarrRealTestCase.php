<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

use PHPUnit\Framework\TestCase;
use DoliDB;
use User;
use Societe;

/**
 * Base class for integration tests with real Dolibarr environment
 *
 * Uses cap-rel/dolibarr-integration-sqlite for a complete Dolibarr installation
 *
 * @requires PHP >= 8.2
 */
abstract class DolibarrRealTestCase extends TestCase
{
    /** @var DoliDB */
    protected $db;

    /** @var User */
    protected $testUser;

    /** @var object */
    protected $conf;

    /** @var \SmartAuthDevices Default test device for FK constraints */
    protected $testDevice;

    /** @var string Path to SQLite vendor directory for cleanup */
    private static $sqliteVendorPath;

    /**
     * Reset SQLite vendor files after all tests complete
     * This prevents "uncommitted changes" errors in CI
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // do not reset after each class, only after / before all tests
        // // Find vendor path relative to this file
        // $vendorPath = dirname(__DIR__, 3) . '/vendor/cap-rel/dolibarr-integration-sqlite';
        // if (is_dir($vendorPath . '/.git')) {
        //     // The sqlite package has its own git repo, reset it directly
        //     exec('cd ' . escapeshellarg($vendorPath) . ' && git reset --hard HEAD 2>/dev/null');
        // } elseif (is_file($vendorPath . '/documents/database_dolibarr.sdb_save')) {
        //     copy($vendorPath . '/documents/database_dolibarr.sdb_save', $vendorPath . '/documents/database_dolibarr.sdb');
        // }
    }

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        global $db, $conf, $user;

        $this->db = $db;
        $this->conf = $conf;

        // Ensure user is properly loaded
        if ($user === null || !is_object($user) || empty($user->id)) {
            // Try to reload user if not initialized
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $user = new User($db);
            $user->fetch(1);
        }

        $this->testUser = $user;

        // Clean SmartAuth tables before each test
        $this->cleanSmartAuthTables();

        // Create a default test device for FK constraints (fk_device_id is NOT NULL in many tables)
        $this->testDevice = $this->createTestDevice();
    }

    /**
     * Create a test device for FK constraints
     */
    protected function createTestDevice(array $data = []): \SmartAuthDevices
    {
        require_once dirname(__DIR__, 3) . '/class/smartauthdevices.class.php';

        $device = new \SmartAuthDevices($this->db);
        $device->label = $data['label'] ?? 'Default Test Device';
        $device->uuid = $data['uuid'] ?? 'test-device-' . uniqid();
        $device->status = $data['status'] ?? \SmartAuthDevices::STATUS_VALIDATED;
        $device->entity = $data['entity'] ?? 1;

        $result = $device->create($this->testUser);
        if ($result < 0) {
            throw new \Exception("Failed to create test device: " . $device->error);
        }

        return $device;
    }

    /**
     * Wipe SmartAuth data tables between tests.
     *
     * Discovers tables at runtime by listing everything that matches
     * MAIN_DB_PREFIX . 'smartauth_%' in the live schema, which is
     * authoritative since modSmartauth::init() loaded it. We never
     * hard-code a list here: any new SQL file picked up by init() is
     * automatically wiped without touching this method.
     *
     * Skips:
     *   - *_extrafields tables: these hold extrafield DEFINITIONS, not
     *     runtime data, and are populated by init() itself.
     */
    protected function cleanSmartAuthTables(): void
    {
        $prefix = MAIN_DB_PREFIX;
        $like   = $prefix . 'smartauth_%';
        $isSqlite = (isset($this->db->type) && in_array($this->db->type, ['sqlite', 'sqlite3'], true));

        if ($isSqlite) {
            $resql = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '" . $this->db->escape($like) . "'");
        } else {
            $resql = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($like) . "'");
        }
        if (!$resql) {
            return;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // sqlite_master returns the column as "name"; SHOW TABLES
            // returns a single column whose name varies per server. Read
            // the first column whatever it is called.
            $row   = (array) $obj;
            $table = (string) reset($row);
            if ($table === '' || strpos($table, $prefix) !== 0) {
                continue;
            }
            if (substr($table, -strlen('_extrafields')) === '_extrafields') {
                continue;
            }
            $this->db->query("DELETE FROM " . $table);
        }
    }

    /**
     * Create a test user in the database
     */
    protected function createTestUser(array $data = []): User
    {
        $user = new User($this->db);

        $user->login = $data['login'] ?? 'testuser_' . uniqid();
        $user->lastname = $data['lastname'] ?? 'Test';
        $user->firstname = $data['firstname'] ?? 'User';
        $user->email = $data['email'] ?? 'test_' . uniqid() . '@example.com';
        $user->admin = $data['admin'] ?? 0;
        $user->employee = $data['employee'] ?? 1;
        $user->statut = $data['statut'] ?? 1;
        $user->entity = $data['entity'] ?? 1;

        $result = $user->create($this->testUser);

        if ($result < 0) {
            throw new \Exception("Failed to create test user: " . $user->error);
        }

        // Set password
        if (!empty($data['pass'])) {
            $user->setPassword($this->testUser, $data['pass']);
        }

        // Apply statut if different from default (1)
        // Dolibarr's create() doesn't include statut in INSERT, so we must update it separately
        $requestedStatut = $data['statut'] ?? 1;
        if ($requestedStatut != 1) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET statut = " . (int) $requestedStatut;
            $sql .= " WHERE rowid = " . (int) $user->id;
            $this->db->query($sql);
            $user->statut = $requestedStatut;
        }

        return $user;
    }

    /**
     * Create a test third party in the database
     */
    protected function createTestSociete(array $data = []): Societe
    {
        $soc = new Societe($this->db);

        $soc->name = $data['name'] ?? 'Test Company ' . uniqid();
        $soc->email = $data['email'] ?? 'contact_' . uniqid() . '@testcompany.com';
        $soc->client = $data['client'] ?? 1;
        $soc->status = $data['status'] ?? 1;
        $soc->entity = $data['entity'] ?? 1;

        $result = $soc->create($this->testUser);

        if ($result <= 0) {
            throw new \Exception("Failed to create test societe: " . $soc->error);
        }

        return $soc;
    }

    /**
     * Assert that a database table contains a row matching conditions
     */
    protected function assertDatabaseHas(string $table, array $conditions): void
    {
        $where = [];
        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $where[] = "$column IS NULL";
            } else {
                $where[] = "$column = '" . $this->db->escape($value) . "'";
            }
        }

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . $table;
        $sql .= " WHERE " . implode(' AND ', $where);

        $result = $this->db->query($sql);

        $this->assertNotFalse($result, "SQL query failed: " . $this->db->lasterror() . " - Query: " . $sql);

        $obj = $this->db->fetch_object($result);

        $this->assertNotNull($obj, "Failed to fetch result from query: " . $sql);

        $this->assertGreaterThan(
            0,
            (int) $obj->cnt,
            "Failed asserting that table '$table' contains a row matching: " . json_encode($conditions)
        );
    }

    /**
     * Assert that a database table does NOT contain a row matching conditions
     */
    protected function assertDatabaseMissing(string $table, array $conditions): void
    {
        $where = [];
        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $where[] = "$column IS NULL";
            } else {
                $where[] = "$column = '" . $this->db->escape($value) . "'";
            }
        }

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . $table;
        $sql .= " WHERE " . implode(' AND ', $where);

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(
            0,
            (int) $obj->cnt,
            "Failed asserting that table '$table' does NOT contain a row matching: " . json_encode($conditions)
        );
    }

    /**
     * Get count of rows in a table
     */
    protected function getTableCount(string $table, array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . $table;

        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $column => $value) {
                if ($value === null) {
                    $where[] = "$column IS NULL";
                } else {
                    $where[] = "$column = '" . $this->db->escape($value) . "'";
                }
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        return (int) $obj->cnt;
    }
}
