<?php

namespace SmartAuth\Tests\Mocks;

/**
 * Mock database class for unit tests
 * Simulates Dolibarr's database interface without requiring a real connection
 */
class MockDatabase
{
    private array $queryResults = [];
    private array $executedQueries = [];
    private int $lastInsertId = 0;
    private int $numRows = 0;
    private array $fetchData = [];
    private int $fetchIndex = 0;
    private ?string $lastError = null;

    /**
     * Set the result for the next query
     */
    public function setQueryResult(bool $success, array $fetchData = [], int $numRows = -1): self
    {
        $this->queryResults[] = [
            'success' => $success,
            'fetchData' => $fetchData,
            'numRows' => $numRows >= 0 ? $numRows : count($fetchData)
        ];
        return $this;
    }

    /**
     * Set the last insert ID
     */
    public function setLastInsertId(int $id): self
    {
        $this->lastInsertId = $id;
        return $this;
    }

    /**
     * Execute a query
     */
    public function query(string $sql)
    {
        $this->executedQueries[] = $sql;
        $this->fetchIndex = 0;

        if (!empty($this->queryResults)) {
            $result = array_shift($this->queryResults);
            $this->fetchData = $result['fetchData'];
            $this->numRows = $result['numRows'];
            if (!$result['success']) {
                $this->lastError = 'Mock query failed';
                return false;
            }
            return true;
        }

        // Default: success with no data
        $this->fetchData = [];
        $this->numRows = 0;
        return true;
    }

    /**
     * Fetch object from result
     */
    public function fetch_object($result = null): ?object
    {
        if ($this->fetchIndex < count($this->fetchData)) {
            $data = $this->fetchData[$this->fetchIndex++];
            return (object) $data;
        }
        return null;
    }

    /**
     * Get number of rows
     */
    public function num_rows($result = null): int
    {
        return $this->numRows;
    }

    /**
     * Get last insert ID
     */
    public function last_insert_id(string $table = ''): int
    {
        return $this->lastInsertId;
    }

    /**
     * Escape string
     */
    public function escape(string $value): string
    {
        return addslashes($value);
    }

    /**
     * Get last error
     */
    public function lasterror(): string
    {
        return $this->lastError ?? '';
    }

    /**
     * Format date for SQL
     */
    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Parse SQL date to timestamp
     */
    public function jdate(string $date): int
    {
        return strtotime($date);
    }

    /**
     * Get all executed queries (for assertions)
     */
    public function getExecutedQueries(): array
    {
        return $this->executedQueries;
    }

    /**
     * Get last executed query
     */
    public function getLastQuery(): ?string
    {
        return end($this->executedQueries) ?: null;
    }

    /**
     * Clear executed queries history
     */
    public function clearQueries(): self
    {
        $this->executedQueries = [];
        return $this;
    }

    /**
     * Check if a query containing a specific string was executed
     */
    public function hasQueryContaining(string $needle): bool
    {
        foreach ($this->executedQueries as $query) {
            if (strpos($query, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
