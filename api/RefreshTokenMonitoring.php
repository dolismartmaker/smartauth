<?php

/**
 * RefreshTokenMonitoring.php
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

class RefreshTokenMonitoring
{
    /**
     * Get refresh statistics
     */
    public static function getRefreshStats($db, $days = 7)
    {
        $since = time() - ($days * 86400);

        $sql = "SELECT
                    DATE(FROM_UNIXTIME(last_refresh_at)) as refresh_date,
                    COUNT(*) as refresh_count,
                    COUNT(CASE WHEN revoked = 1 THEN 1 END) as revoked_count
                FROM " . MAIN_DB_PREFIX . "smartauth_token_family
                WHERE created_at > " . $since . "
                GROUP BY refresh_date
                ORDER BY refresh_date DESC";

        $resql = $db->query($sql);
        $stats = [];

        while ($obj = $db->fetch_object($resql)) {
            $stats[] = [
                'date' => $obj->refresh_date,
                'refreshes' => (int) $obj->refresh_count,
                'revocations' => (int) $obj->revoked_count
            ];
        }

        return $stats;
    }

    /**
     * Detect suspicious refresh patterns
     */
    public static function detectAnomalies($db, $user_id)
    {
        $alerts = [];

        // Alert 1: Too many refreshes in short time
        $sql = "SELECT COUNT(*) as count
                FROM " . MAIN_DB_PREFIX . "smartauth_token_family
                WHERE fk_user = " . (int) $user_id . "
                AND last_refresh_at > " . (time() - 3600) . "
                AND refresh_count > 10";

        $resql = $db->query($sql);
        $obj = $db->fetch_object($resql);
        if ($obj->count > 0) {
            $alerts[] = [
                'type' => 'excessive_refresh',
                'severity' => 'medium',
                'message' => 'Unusual refresh pattern detected'
            ];
        }

        // Alert 2: Multiple families from different IPs
        $sql = "SELECT COUNT(DISTINCT ip) as ip_count
                FROM " . MAIN_DB_PREFIX . "smartauth_auth
                WHERE fk_authid = " . (int) $user_id . "
                AND date_creation > " . (time() - 3600) . "
                AND token_type = 'refresh'";

        $resql = $db->query($sql);
        $obj = $db->fetch_object($resql);
        if ($obj->ip_count > 3) {
            $alerts[] = [
                'type' => 'multiple_locations',
                'severity' => 'high',
                'message' => 'Login from multiple locations simultaneously'
            ];
        }

        return $alerts;
    }
}
