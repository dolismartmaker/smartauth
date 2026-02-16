<?php

/**
 * RateLimiter.class.php
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace SmartAuth\Api;

class RateLimiter
{
    protected $db;

    /**
     * Cache key for last cleanup timestamp
     */
    const CLEANUP_CACHE_KEY = 'smartauth_ratelimit_last_cleanup';

    /**
     * Minimum interval between cleanups (in seconds)
     * Default: 1 hour
     */
    const CLEANUP_INTERVAL = 3600;

    /**
     * Maximum age of entries to keep (in seconds)
     * Default: 24 hours
     */
    const MAX_ENTRY_AGE = 86400;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Check if request should be rate limited
     *
     * @param string $identifier IP address or username
     * @param string $action Type of action (login, api_call, etc.)
     * @param int $max_attempts Maximum attempts allowed
     * @param int $window_seconds Time window in seconds
     * @return array ['allowed' => bool, 'retry_after' => int|null]
     */
    public function checkLimit($identifier, $action, $max_attempts = 5, $window_seconds = 300)
    {
        $identifier = $this->db->escape($identifier);
        $action = $this->db->escape($action);
        $window_start = time() - $window_seconds;

        // Clean old entries (optional, for maintenance)
        $this->maybeCleanup();

        // Count attempts in window
        $sql = "SELECT COUNT(*) as attempt_count, MAX(attempt_time) as last_attempt";
        $sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $identifier . "'";
        $sql .= " AND action = '" . $action . "'";
        $sql .= " AND attempt_time > " . $window_start;

        $resql = $this->db->query($sql);
        if (!$resql) {
            // If rate limit check fails, allow request (fail open)
            dol_syslog("Rate limit check failed (fail-close): " . $this->db->lasterror(), LOG_WARNING);
            return ['allowed' => false, 'retry_after' => 60];
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return ['allowed' => true, 'retry_after' => null];
        }
        $attempt_count = (int) $obj->attempt_count;
        $last_attempt = (int) $obj->last_attempt;

        if ($attempt_count >= $max_attempts) {
            $retry_after = ($last_attempt + $window_seconds) - time();
            dol_syslog("Rate limit exceeded for $identifier on $action", LOG_WARNING);
            return ['allowed' => false, 'retry_after' => max(0, $retry_after)];
        }

        return ['allowed' => true, 'retry_after' => null];
    }

    /**
     * Record an attempt
     */
    public function recordAttempt($identifier, $action, $success = false)
    {
        $identifier = $this->db->escape($identifier);
        $action = $this->db->escape($action);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $identifier . "', '" . $action . "', " . time() . ", " . ($success ? 1 : 0) . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog("Failed to record rate limit attempt: " . $this->db->lasterror(), LOG_WARNING);
        }

        return $resql;
    }

    /**
     * Reset rate limit for identifier (e.g., after successful login)
     */
    public function reset($identifier, $action)
    {
        $identifier = $this->db->escape($identifier);
        $action = $this->db->escape($action);

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $identifier . "'";
        $sql .= " AND action = '" . $action . "'";

        return $this->db->query($sql);
    }

    /**
     * Clean entries older than retention period
     *
     * @param int $retention_seconds Maximum age of entries to keep
     * @return int Number of deleted rows, or -1 on error
     */
    public function cleanOldEntries($retention_seconds = null)
    {
        if ($retention_seconds === null) {
            $retention_seconds = self::MAX_ENTRY_AGE;
        }

        $cutoff = time() - $retention_seconds;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE attempt_time < " . (int) $cutoff;

        $resql = $this->db->query($sql);
        if ($resql) {
            $deleted = $this->db->affected_rows($resql);
            if ($deleted > 0) {
                SmartAuthLogger::debug("RateLimiter: cleaned $deleted old entries");
            }
            return $deleted;
        }

        dol_syslog("RateLimiter: cleanup failed: " . $this->db->lasterror(), LOG_WARNING);
        return -1;
    }

    /**
     * Perform cleanup if enough time has passed since last cleanup
     *
     * Uses database-based timestamp to ensure cleanup happens even
     * across multiple PHP processes/servers.
     *
     * @return bool True if cleanup was performed
     */
    private function maybeCleanup()
    {
        global $conf;

        // Check in-memory cache first (fast path)
        $cacheKey = self::CLEANUP_CACHE_KEY;
        if (isset($conf->cache['smartmakers'][$cacheKey])) {
            $lastCleanup = (int) $conf->cache['smartmakers'][$cacheKey];
            if ((time() - $lastCleanup) < self::CLEANUP_INTERVAL) {
                return false; // Too soon, skip
            }
        }

        // Check database for last cleanup time (cross-process coordination)
        $lastCleanup = $this->getLastCleanupTime();

        if ((time() - $lastCleanup) < self::CLEANUP_INTERVAL) {
            // Update local cache
            $conf->cache['smartmakers'][$cacheKey] = $lastCleanup;
            return false;
        }

        // Perform cleanup
        $this->cleanOldEntries();

        // Update last cleanup time
        $this->setLastCleanupTime();
        $conf->cache['smartmakers'][$cacheKey] = time();

        return true;
    }

    /**
     * Get last cleanup timestamp from database
     *
     * @return int Unix timestamp of last cleanup, or 0 if never run
     */
    private function getLastCleanupTime()
    {
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = 'SMARTAUTH_RATELIMIT_LAST_CLEANUP'";
        $sql .= " AND entity = 0";

        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            return (int) $obj->value;
        }

        return 0;
    }

    /**
     * Update last cleanup timestamp in database
     */
    private function setLastCleanupTime()
    {
        $now = time();

        // Try update first
        $sql = "UPDATE " . MAIN_DB_PREFIX . "const";
        $sql .= " SET value = '" . $now . "'";
        $sql .= " WHERE name = 'SMARTAUTH_RATELIMIT_LAST_CLEANUP'";
        $sql .= " AND entity = 0";

        $this->db->query($sql);

        if ($this->db->affected_rows($this->db) == 0) {
            // Insert if not exists
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const";
            $sql .= " (name, value, type, visible, entity)";
            $sql .= " VALUES ('SMARTAUTH_RATELIMIT_LAST_CLEANUP', '" . $now . "', 'chaine', 0, 0)";
            $this->db->query($sql);
        }
    }

    /**
     * Force cleanup now (for cron jobs or admin actions)
     *
     * @param int $retention_seconds Maximum age of entries to keep
     * @return int Number of deleted rows
     */
    public function forceCleanup($retention_seconds = null)
    {
        $deleted = $this->cleanOldEntries($retention_seconds);
        $this->setLastCleanupTime();

        global $conf;
        $conf->cache['smartmakers'][self::CLEANUP_CACHE_KEY] = time();

        return $deleted;
    }

    /**
     * Get statistics about the rate limit table
     *
     * @return array ['total_entries' => int, 'oldest_entry' => int, 'newest_entry' => int]
     */
    public function getStats()
    {
        $sql = "SELECT COUNT(*) as total, MIN(attempt_time) as oldest, MAX(attempt_time) as newest";
        $sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";

        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            return [
                'total_entries' => (int) $obj->total,
                'oldest_entry' => (int) $obj->oldest,
                'newest_entry' => (int) $obj->newest,
                'last_cleanup' => $this->getLastCleanupTime()
            ];
        }

        return [
            'total_entries' => 0,
            'oldest_entry' => 0,
            'newest_entry' => 0,
            'last_cleanup' => 0
        ];
    }
}
