<?php

/**
 * Minimal DoliDB implementation using SQLite for integration tests
 * Simulates Dolibarr's database interface
 */
class DoliDB
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    public $type = 'sqlite';

    /** @var bool */
    public $connected = false;

    /** @var string */
    public $database_name = ':memory:';

    /** @var string|null */
    private $lastError = null;

    /** @var int */
    private $lastInsertId = 0;

    /** @var PDOStatement|null */
    private $lastResult = null;

    /** @var string */
    public $prefix = 'llx_';

    /** @var array Cache of fetched rows for num_rows support */
    private $resultCache = [];

    /**
     * Constructor - creates SQLite in-memory database
     */
    public function __construct($type = 'sqlite', $host = '', $user = '', $pass = '', $name = ':memory:', $port = 0)
    {
        try {
            $this->pdo = new PDO('sqlite:' . $name);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
            $this->connected = true;
            $this->database_name = $name;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->connected = false;
        }
    }

    /**
     * Execute a SQL query
     */
    public function query($sql, $usesavepoint = 0, $type = 'auto', $result_mode = 0)
    {
        // Convert MySQL syntax to SQLite
        $sql = $this->convertToSQLite($sql);

        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return false;
            }
            $this->lastResult = $stmt;

            // For SELECT queries, fetch all rows to enable num_rows
            $trimmedSql = ltrim($sql);
            if (stripos($trimmedSql, 'SELECT') === 0) {
                $resultId = spl_object_id($stmt);
                $this->resultCache[$resultId] = [
                    'rows' => $stmt->fetchAll(PDO::FETCH_OBJ),
                    'position' => 0
                ];
            }

            return $stmt;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Convert MySQL-specific syntax to SQLite
     */
    private function convertToSQLite($sql)
    {
        // Replace MAIN_DB_PREFIX with actual prefix
        $sql = str_replace('llx_', $this->prefix, $sql);

        // MySQL NOW() -> SQLite datetime('now')
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql);

        // MySQL UNIX_TIMESTAMP() -> SQLite strftime('%s', 'now')
        $sql = preg_replace('/\bUNIX_TIMESTAMP\(\)/i', "strftime('%s', 'now')", $sql);

        // MySQL AUTO_INCREMENT -> SQLite AUTOINCREMENT
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $sql);

        // MySQL ENGINE=xxx -> remove (SQLite doesn't use it)
        $sql = preg_replace('/\bENGINE\s*=\s*\w+/i', '', $sql);

        // MySQL DEFAULT CHARSET=xxx -> remove
        $sql = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);

        // MySQL int(11) -> INTEGER
        $sql = preg_replace('/\bint\(\d+\)/i', 'INTEGER', $sql);

        // MySQL varchar(x) -> TEXT (SQLite is flexible)
        // Keep varchar for compatibility but SQLite treats all as TEXT
        $sql = preg_replace('/\bvarchar\(\d+\)/i', 'TEXT', $sql);

        // MySQL datetime -> TEXT (SQLite stores as text)
        $sql = preg_replace('/\bdatetime\b/i', 'TEXT', $sql);

        // MySQL tinyint -> INTEGER
        $sql = preg_replace('/\btinyint\(\d+\)/i', 'INTEGER', $sql);

        return $sql;
    }

    /**
     * Get number of rows from last query
     */
    public function num_rows($result = null)
    {
        if ($result === null) {
            $result = $this->lastResult;
        }
        if ($result instanceof PDOStatement) {
            $resultId = spl_object_id($result);
            if (isset($this->resultCache[$resultId])) {
                return count($this->resultCache[$resultId]['rows']);
            }
            return $result->rowCount();
        }
        return 0;
    }

    /**
     * Fetch object from result
     */
    public function fetch_object($result = null)
    {
        if ($result === null) {
            $result = $this->lastResult;
        }
        if ($result instanceof PDOStatement) {
            $resultId = spl_object_id($result);
            if (isset($this->resultCache[$resultId])) {
                $cache = &$this->resultCache[$resultId];
                if ($cache['position'] < count($cache['rows'])) {
                    return $cache['rows'][$cache['position']++];
                }
                return false;
            }
            return $result->fetch(PDO::FETCH_OBJ);
        }
        return null;
    }

    /**
     * Fetch array from result
     */
    public function fetch_array($result = null)
    {
        if ($result === null) {
            $result = $this->lastResult;
        }
        if ($result instanceof PDOStatement) {
            $resultId = spl_object_id($result);
            if (isset($this->resultCache[$resultId])) {
                $cache = &$this->resultCache[$resultId];
                if ($cache['position'] < count($cache['rows'])) {
                    $obj = $cache['rows'][$cache['position']++];
                    return (array) $obj;
                }
                return false;
            }
            return $result->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }

    /**
     * Get last insert ID
     */
    public function last_insert_id($table = '', $fieldid = 'rowid')
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Escape string for SQL
     */
    public function escape($value)
    {
        if ($value === null) {
            return '';
        }
        // PDO::quote adds quotes, we just want escaping
        $quoted = $this->pdo->quote((string) $value);
        // Remove surrounding quotes
        return substr($quoted, 1, -1);
    }

    /**
     * Get last error message
     */
    public function lasterror()
    {
        return $this->lastError ?? '';
    }

    /**
     * Get last error number
     */
    public function lasterrno()
    {
        $info = $this->pdo->errorInfo();
        return $info[1] ?? 0;
    }

    /**
     * Format date for SQL
     */
    public function idate($timestamp)
    {
        if (empty($timestamp)) {
            return 'NULL';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Parse SQL date to timestamp
     */
    public function jdate($date)
    {
        if (empty($date) || $date === 'NULL') {
            return 0;
        }
        return strtotime($date);
    }

    /**
     * Get database table prefix
     */
    public function prefix()
    {
        return $this->prefix;
    }

    /**
     * Free result
     */
    public function free($result = null)
    {
        if ($result instanceof PDOStatement) {
            $resultId = spl_object_id($result);
            unset($this->resultCache[$resultId]);
            $result->closeCursor();
        }
    }

    /**
     * Begin transaction
     */
    public function begin($textinlog = '')
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit($log = '')
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback($log = '')
    {
        return $this->pdo->rollBack();
    }

    /**
     * Get affected rows from last query
     */
    public function affected_rows($result = null)
    {
        if ($result === null) {
            $result = $this->lastResult;
        }
        if ($result instanceof PDOStatement) {
            return $result->rowCount();
        }
        return 0;
    }

    /**
     * Execute multiple SQL statements (for schema creation)
     */
    public function multi_query($sql)
    {
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                if ($this->query($statement) === false) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Get PDO instance for direct access if needed
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * Check if connected
     */
    public function connected()
    {
        return $this->connected;
    }

    /**
     * DDL helper: Create table
     */
    public function DDLCreateTable($table, $fields, $primary_key, $type = '', $unique_keys = [], $fulltext_keys = [], $keys = [])
    {
        $sql = "CREATE TABLE IF NOT EXISTS " . $table . " (";
        $fieldDefs = [];

        foreach ($fields as $name => $def) {
            $fieldDef = $name . ' ' . ($def['type'] ?? 'TEXT');
            if (!empty($def['notnull'])) {
                $fieldDef .= ' NOT NULL';
            }
            if (isset($def['default'])) {
                $fieldDef .= " DEFAULT '" . $this->escape($def['default']) . "'";
            }
            $fieldDefs[] = $fieldDef;
        }

        $sql .= implode(', ', $fieldDefs);

        if (!empty($primary_key)) {
            $sql .= ", PRIMARY KEY (" . implode(',', (array)$primary_key) . ")";
        }

        $sql .= ")";

        return $this->query($sql);
    }

    /**
     * Encrypt password (simplified for tests)
     */
    public function encrypt($value, $type = 0)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * Decrypt (not really used in tests, just return as-is)
     */
    public function decrypt($value)
    {
        return $value;
    }
}
