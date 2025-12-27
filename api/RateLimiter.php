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
        if (rand(1, 100) == 1) { // 1% chance to clean
            $this->cleanOldEntries($window_seconds);
        }

        // Count attempts in window
        $sql = "SELECT COUNT(*) as attempt_count, MAX(attempt_time) as last_attempt";
        $sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $identifier . "'";
        $sql .= " AND action = '" . $action . "'";
        $sql .= " AND attempt_time > " . $window_start;

        $resql = $this->db->query($sql);
        if (!$resql) {
            // If rate limit check fails, allow request (fail open)
            dol_syslog("Rate limit check failed: " . $this->db->lasterror(), LOG_WARNING);
            return ['allowed' => true, 'retry_after' => null];
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
     */
    private function cleanOldEntries($retention_seconds = 3600)
    {
        $cutoff = time() - $retention_seconds;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE attempt_time < " . $cutoff;

        $this->db->query($sql);
    }
}
