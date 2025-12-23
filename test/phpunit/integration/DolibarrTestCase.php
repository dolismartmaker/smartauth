<?php

namespace SmartAuth\Tests\Integration;

use PHPUnit\Framework\TestCase;
use DoliDB;
use User;
use Societe;

/**
 * Base class for integration tests with Dolibarr environment
 *
 * Provides:
 * - Fresh SQLite database for each test
 * - Pre-created test user
 * - Helper methods for common operations
 */
abstract class DolibarrTestCase extends TestCase
{
    /** @var DoliDB */
    protected $db;

    /** @var User */
    protected $testUser;

    /** @var object */
    protected $conf;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        global $db, $conf, $user, $smartAuthAppID, $smartAuthAppKey;

        // Create fresh database connection for isolation
        $this->db = new DoliDB('sqlite', '', '', '', ':memory:');
        $db = $this->db;

        // Recreate schema
        $this->createSchema();

        // Set up conf
        $this->conf = $conf;

        // Create a test user
        $this->testUser = $this->createTestUser();
        $user = $this->testUser;

        // Ensure app credentials are set
        $smartAuthAppID = 1;
        $smartAuthAppKey = 'test-secret-key-for-integration-tests-12345';

        // Clear cache
        $conf->cache = [];
        $conf->cache['smartmakers'] = [];
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // Nothing to clean up - SQLite in-memory is destroyed automatically
    }

    /**
     * Create database schema
     */
    protected function createSchema(): void
    {
        createSmartAuthSchema($this->db);
    }

    /**
     * Create a test user in the database
     */
    protected function createTestUser(array $data = []): User
    {
        $user = new User($this->db);

        $user->login = $data['login'] ?? 'testuser';
        $user->lastname = $data['lastname'] ?? 'Test';
        $user->firstname = $data['firstname'] ?? 'User';
        $user->email = $data['email'] ?? 'testuser@example.com';
        $user->admin = $data['admin'] ?? 0;
        $user->employee = $data['employee'] ?? 1;
        $user->statut = $data['statut'] ?? 1;
        $user->entity = $data['entity'] ?? 1;
        $user->pass = $data['pass'] ?? 'testpassword123';

        // Insert directly to avoid needing another user for creation
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "user ";
        $sql .= "(login, pass_crypted, lastname, firstname, email, admin, employee, statut, entity, date_creation) ";
        $sql .= "VALUES (";
        $sql .= "'" . $this->db->escape($user->login) . "', ";
        $sql .= "'" . $this->db->escape(password_hash($user->pass, PASSWORD_DEFAULT)) . "', ";
        $sql .= "'" . $this->db->escape($user->lastname) . "', ";
        $sql .= "'" . $this->db->escape($user->firstname) . "', ";
        $sql .= "'" . $this->db->escape($user->email) . "', ";
        $sql .= (int) $user->admin . ", ";
        $sql .= (int) $user->employee . ", ";
        $sql .= (int) $user->statut . ", ";
        $sql .= (int) $user->entity . ", ";
        $sql .= "'" . $this->db->idate(time()) . "')";

        $this->db->query($sql);
        $user->id = $this->db->last_insert_id();
        $user->rowid = $user->id;

        return $user;
    }

    /**
     * Create a test third party in the database
     */
    protected function createTestSociete(array $data = []): Societe
    {
        $soc = new Societe($this->db);

        $soc->name = $data['name'] ?? 'Test Company';
        $soc->nom = $soc->name;
        $soc->email = $data['email'] ?? 'contact@testcompany.com';
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

    /**
     * Set a global config value for tests
     */
    protected function setGlobalConfig(string $key, $value): void
    {
        global $conf;
        $conf->global->$key = $value;
    }

    /**
     * Simulate HTTP headers for API tests
     */
    protected function setHttpHeaders(array $headers): void
    {
        foreach ($headers as $key => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $_SERVER[$serverKey] = $value;
        }
    }

    /**
     * Clear HTTP headers after test
     */
    protected function clearHttpHeaders(array $headers): void
    {
        foreach ($headers as $key => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            unset($_SERVER[$serverKey]);
        }
    }
}
