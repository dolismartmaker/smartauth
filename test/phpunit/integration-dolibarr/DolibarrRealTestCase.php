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
 */
abstract class DolibarrRealTestCase extends TestCase
{
    /** @var DoliDB */
    protected $db;

    /** @var User */
    protected $testUser;

    /** @var object */
    protected $conf;

    /** @var string Path to SQLite vendor directory for cleanup */
    private static $sqliteVendorPath;

    /**
     * Reset SQLite vendor files after all tests complete
     * This prevents "uncommitted changes" errors in CI
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // Find vendor path relative to this file
        $vendorPath = dirname(__DIR__, 3) . '/vendor/cap-rel/dolibarr-integration-sqlite';

        if (is_dir($vendorPath)) {
            // Use git to restore the SQLite database files
            $currentDir = getcwd();
            chdir(dirname(__DIR__, 3)); // Go to project root

            // Restore only the database files that were modified
            exec('git checkout -- vendor/cap-rel/dolibarr-integration-sqlite/ 2>/dev/null');

            chdir($currentDir);
        }
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
    }

    /**
     * Clean SmartAuth tables between tests
     */
    protected function cleanSmartAuthTables(): void
    {
        $tables = [
            'smartauth_auth',
            'smartauth_devices',
            'smartauth_token_family',
            'smartauth_ratelimit',
            'smartauth_logs'
        ];

        foreach ($tables as $table) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table);
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
        $obj = $this->db->fetch_object($result);

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
