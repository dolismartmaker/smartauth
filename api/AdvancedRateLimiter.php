<?php
/**
 * AdvancedRateLimiter.php
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


class AdvancedRateLimiter extends RateLimiter
{
    /**
     * Progressive delay based on number of failures
     * - 1-3 failures: no delay
     * - 4-5 failures: 30 seconds
     * - 6-10 failures: 5 minutes
     * - 11+ failures: 1 hour
     */
    public function checkLimitProgressive($identifier, $action)
    {
        $identifier = $this->db->escape($identifier);
        $action = $this->db->escape($action);

        // Get recent failures
        $sql = "SELECT attempt_time, success FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $identifier . "'";
        $sql .= " AND action = '" . $action . "'";
        $sql .= " AND attempt_time > " . (time() - 3600); // Last hour
        $sql .= " ORDER BY attempt_time DESC";
        $sql .= " LIMIT 20";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return ['allowed' => true, 'retry_after' => null];
        }

        $failures = 0;
        $last_attempt = 0;

        while ($obj = $this->db->fetch_object($resql)) {
            if ($obj->success == 0) {
                $failures++;
                if ($last_attempt == 0) {
                    $last_attempt = (int) $obj->attempt_time;
                }
            } else {
                // Stop counting after first success
                break;
            }
        }

        // Calculate delay based on failure count
        $delay = 0;
        if ($failures >= 11) {
            $delay = 3600; // 1 hour
        } elseif ($failures >= 6) {
            $delay = 300; // 5 minutes
        } elseif ($failures >= 4) {
            $delay = 30; // 30 seconds
        }

        if ($delay > 0) {
            $time_since_last = time() - $last_attempt;
            if ($time_since_last < $delay) {
                return [
                    'allowed' => false,
                    'retry_after' => $delay - $time_since_last,
                    'failures' => $failures
                ];
            }
        }

        return ['allowed' => true, 'retry_after' => null, 'failures' => $failures];
    }
}
