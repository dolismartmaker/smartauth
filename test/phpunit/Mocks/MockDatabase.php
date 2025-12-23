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
    private ?object $singleFetchResult = null;
    private array $fetchResultSequence = [];
    private int $fetchSequenceIndex = 0;

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

        // If using queryResults (from setQueryResult), use that system
        if (!empty($this->queryResults)) {
            $result = array_shift($this->queryResults);
            $this->fetchData = $result['fetchData'];
            $this->numRows = $result['numRows'];
            // Clear sequence results to avoid interference
            $this->fetchResultSequence = [];
            $this->fetchSequenceIndex = 0;
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

    /**
     * Get executed queries (alias for getExecutedQueries)
     */
    public function getQueries(): array
    {
        return $this->executedQueries;
    }

    /**
     * Set a single fetch result for all queries
     */
    public function setFetchResult(object $result): self
    {
        $this->singleFetchResult = $result;
        return $this;
    }

    /**
     * Set a sequence of fetch results (one per query)
     */
    public function setFetchResultSequence(array $results): self
    {
        $this->fetchResultSequence = $results;
        $this->fetchSequenceIndex = 0;
        return $this;
    }

    /**
     * Fetch object from result - with support for single and sequence results
     */
    public function fetch_object($result = null): ?object
    {
        // Check for sequence results first
        if (!empty($this->fetchResultSequence)) {
            if ($this->fetchSequenceIndex < count($this->fetchResultSequence)) {
                return $this->fetchResultSequence[$this->fetchSequenceIndex++];
            }
            return null;
        }

        // Check for single result
        if ($this->singleFetchResult !== null) {
            $result = $this->singleFetchResult;
            $this->singleFetchResult = null; // Clear after use
            return $result;
        }

        // Original behavior
        if ($this->fetchIndex < count($this->fetchData)) {
            $data = $this->fetchData[$this->fetchIndex++];
            return (object) $data;
        }
        return null;
    }

    /**
     * Get database table prefix
     */
    public function prefix(): string
    {
        return MAIN_DB_PREFIX;
    }

    /**
     * Free result (no-op for mock)
     */
    public function free($result = null): void
    {
        // No-op
    }
}
