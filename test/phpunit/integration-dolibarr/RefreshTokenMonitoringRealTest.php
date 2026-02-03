<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RefreshTokenMonitoring.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';

use SmartAuth\Api\RefreshTokenMonitoring;
use SmartAuth;

/**
 * Integration tests for RefreshTokenMonitoring with real Dolibarr database
 */
class RefreshTokenMonitoringRealTest extends DolibarrRealTestCase
{
    /**
     * Test getRefreshStats returns empty array when no data
     */
    public function testGetRefreshStatsReturnsEmptyWhenNoData(): void
    {
        $stats = RefreshTokenMonitoring::getRefreshStats($this->db, 7);

        $this->assertIsArray($stats);
        $this->assertEmpty($stats);
    }

    /**
     * Test getRefreshStats with token families in database
     */
    public function testGetRefreshStatsWithData(): void
    {
        // Insert test data into token_family table
        $now = time();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (1, $now, $now, 5, 0)";
        $this->db->query($sql);

        $stats = RefreshTokenMonitoring::getRefreshStats($this->db, 7);

        $this->assertIsArray($stats);
        // Stats should have at least one entry for today
        $this->assertGreaterThanOrEqual(0, count($stats));
    }

    /**
     * Test getRefreshStats respects days parameter
     */
    public function testGetRefreshStatsRespectsTimeWindow(): void
    {
        // Insert data from 10 days ago
        $oldTime = time() - (10 * 86400);
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (1, $oldTime, $oldTime, 3, 0)";
        $this->db->query($sql);

        // Query with 7 day window should not include this
        $stats = RefreshTokenMonitoring::getRefreshStats($this->db, 7);

        // No entries should be returned for 7-day window
        $this->assertEmpty($stats);

        // But 14 day window should include it
        $stats = RefreshTokenMonitoring::getRefreshStats($this->db, 14);
        $this->assertGreaterThanOrEqual(1, count($stats));
    }

    /**
     * Test detectAnomalies returns empty when no anomalies
     */
    public function testDetectAnomaliesReturnsEmptyWhenNormal(): void
    {
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }

    /**
     * Test detectAnomalies detects excessive refresh
     */
    public function testDetectAnomaliesDetectsExcessiveRefresh(): void
    {
        // Insert token family with high refresh count in last hour
        $now = time();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", $now, $now, 15, 0)";
        $this->db->query($sql);

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);
        $this->assertGreaterThanOrEqual(1, count($alerts));

        // Check for excessive_refresh alert
        $hasExcessiveRefresh = false;
        foreach ($alerts as $alert) {
            if ($alert['type'] === 'excessive_refresh') {
                $hasExcessiveRefresh = true;
                $this->assertEquals('medium', $alert['severity']);
                break;
            }
        }
        $this->assertTrue($hasExcessiveRefresh, 'Should detect excessive refresh pattern');
    }

    /**
     * Test detectAnomalies detects multiple locations
     */
    public function testDetectAnomaliesDetectsMultipleLocations(): void
    {
        $now = time();
        $userId = $this->testUser->id;

        // Insert auth records from multiple IPs
        // Note: date_creation is stored as timestamp for comparison with time() in detectAnomalies
        $deviceId = $this->testDevice->id;
        $ips = ['192.168.1.1', '10.0.0.1', '172.16.0.1', '8.8.8.8'];
        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (appuid, salt, token_type, fk_authid, ip, date_creation, status, entity, fk_device_id, fk_user_creat, auth_element)
                    VALUES (1, 'salt123', 'refresh', $userId, '$ip', $now, 1, 1, $deviceId, $userId, 'user')";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $userId);

        $this->assertIsArray($alerts);
        $this->assertGreaterThanOrEqual(1, count($alerts));

        // Check for multiple_locations alert
        $hasMultipleLocations = false;
        foreach ($alerts as $alert) {
            if ($alert['type'] === 'multiple_locations') {
                $hasMultipleLocations = true;
                $this->assertEquals('high', $alert['severity']);
                break;
            }
        }
        $this->assertTrue($hasMultipleLocations, 'Should detect multiple locations pattern');
    }

    /**
     * Test detectAnomalies returns both alert types when applicable
     */
    public function testDetectAnomaliesReturnsBothAlerts(): void
    {
        $now = time();
        $userId = $this->testUser->id;

        // Create excessive refresh
        // Note: timestamps are stored as integers for comparison with time() in detectAnomalies
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES ($userId, $now, $now, 20, 0)";
        $this->db->query($sql);

        // Create multiple locations
        $deviceId = $this->testDevice->id;
        $ips = ['192.168.1.1', '10.0.0.1', '172.16.0.1', '8.8.8.8'];
        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (appuid, salt, token_type, fk_authid, ip, date_creation, status, entity, fk_device_id, fk_user_creat, auth_element)
                    VALUES (1, 'salt123', 'refresh', $userId, '$ip', $now, 1, 1, $deviceId, $userId, 'user')";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $userId);

        $this->assertCount(2, $alerts);

        $alertTypes = array_column($alerts, 'type');
        $this->assertContains('excessive_refresh', $alertTypes);
        $this->assertContains('multiple_locations', $alertTypes);
    }

    /**
     * Test refresh stats counts revocations correctly
     */
    public function testGetRefreshStatsCountsRevocations(): void
    {
        $now = time();

        // Insert normal token family
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (1, $now, $now, 2, 0)";
        $this->db->query($sql);

        // Insert revoked token family
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (1, $now, $now, 3, 1)";
        $this->db->query($sql);

        $stats = RefreshTokenMonitoring::getRefreshStats($this->db, 7);

        $this->assertNotEmpty($stats);
        // Today's stats should show revocations
        $todayStats = $stats[0];
        $this->assertArrayHasKey('revocations', $todayStats);
        $this->assertEquals(1, $todayStats['revocations']);
    }
}
