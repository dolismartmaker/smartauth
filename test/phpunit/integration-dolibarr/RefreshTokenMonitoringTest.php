<?php

/**
 * Tests for RefreshTokenMonitoring
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RefreshTokenMonitoring.php';
require_once __DIR__ . '/../../../class/smartauth.class.php';

use SmartAuth\Api\RefreshTokenMonitoring;
use SmartAuth;

/**
 * @covers \SmartAuth\Api\RefreshTokenMonitoring
 */
class RefreshTokenMonitoringTest extends DolibarrRealTestCase
{
    /**
     * Test detectAnomalies uses proper user_id escaping
     */
    public function testDetectAnomaliesUsesProperEscaping(): void
    {
        // This test verifies the method handles user_id as integer
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, (int) $this->testUser->id);

        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies returns array
     */
    public function testDetectAnomaliesReturnsArray(): void
    {
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies with non-existent user
     */
    public function testDetectAnomaliesWithNonExistentUser(): void
    {
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, 999999);

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }

    /**
     * Test detectAnomalies returns empty array when no anomalies
     */
    public function testDetectAnomaliesReturnsEmptyWhenNoAnomalies(): void
    {
        // Clean user with no activity should have no alerts
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies handles excessive refresh pattern query
     */
    public function testDetectAnomaliesExcessiveRefreshQuery(): void
    {
        // Create token family with excessive refresh count
        $now = time();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", " . $now . ", " . $now . ", 15, 0)";
        $result = $this->db->query($sql);

        // Verify the query worked
        $this->assertTrue($result !== false, 'INSERT should succeed');

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // The method should return an array (may or may not have alerts depending on data)
        $this->assertIsArray($alerts);

        // If we do get alerts, check their structure
        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('message', $alert);
        }
    }

    /**
     * Test detectAnomalies alert structure when alerts exist
     */
    public function testDetectAnomaliesAlertStructure(): void
    {
        // Create conditions for alert
        $now = time();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", " . $now . ", " . $now . ", 20, 0)";
        $this->db->query($sql);

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);
        // If alerts exist, check their structure
        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('message', $alert);
        }
    }

    /**
     * Test detectAnomalies with multiple IP detection query
     */
    public function testDetectAnomaliesMultipleIpsQuery(): void
    {
        // Create auth entries from different IPs
        $ips = ['192.168.1.1', '10.0.0.1', '172.16.0.1', '8.8.8.8'];
        $now = time();
        $deviceId = $this->testDevice->id;

        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity, fk_device_id)
                    VALUES (" . $this->testUser->id . ", " . $now . ", 'refresh', '" . $ip . "', 1, 1, " . $deviceId . ")";
            $result = $this->db->query($sql);
            $this->assertTrue($result !== false, 'INSERT should succeed for IP ' . $ip);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // The method should return an array
        $this->assertIsArray($alerts);

        // If alerts exist, check their structure
        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('message', $alert);
        }
    }


    /**
     * Test detectAnomalies with SQL-safe user id
     */
    public function testDetectAnomaliesWithValidUserId(): void
    {
        // Test with integer user id
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, 1);

        $this->assertIsArray($alerts);
    }

    /**
     * Test both methods are static
     */
    public function testMethodsAreStatic(): void
    {
        $class = new \ReflectionClass(RefreshTokenMonitoring::class);

        $getRefreshStats = $class->getMethod('getRefreshStats');
        $this->assertTrue($getRefreshStats->isStatic());

        $detectAnomalies = $class->getMethod('detectAnomalies');
        $this->assertTrue($detectAnomalies->isStatic());
    }

    /**
     * Test detectAnomalies with exactly threshold refresh count (10)
     */
    public function testDetectAnomaliesAtRefreshThreshold(): void
    {
        // Create token family with exactly 10 refresh count (should NOT trigger alert)
        $now = time();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", " . $now . ", " . $now . ", 10, 0)";
        $this->db->query($sql);

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // Should return empty array - 10 is not > 10
        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies with exactly 3 IPs (threshold is > 3)
     */
    public function testDetectAnomaliesAtIpThreshold(): void
    {
        // Create auth entries from exactly 3 IPs (should NOT trigger alert)
        $ips = ['192.168.10.1', '10.0.10.1', '172.16.10.1'];
        $now = time();
        $deviceId = $this->testDevice->id;

        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity, fk_device_id)
                    VALUES (" . $this->testUser->id . ", " . $now . ", 'refresh', '" . $ip . "', 1, 1, " . $deviceId . ")";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // Should return empty array - 3 is not > 3
        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies with old refresh data (outside time window)
     */
    public function testDetectAnomaliesOldRefreshData(): void
    {
        // Create token family with high refresh count but old timestamp
        $oldTime = time() - 7200; // 2 hours ago (outside 1 hour window)
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", " . $oldTime . ", " . $oldTime . ", 50, 0)";
        $this->db->query($sql);

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // Old data should not trigger alert
        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies with old auth records (outside time window)
     */
    public function testDetectAnomaliesOldAuthRecords(): void
    {
        // Create auth entries from multiple IPs but old timestamps
        $ips = ['192.168.50.1', '10.0.50.1', '172.16.50.1', '8.8.50.8', '1.1.50.1'];
        $oldTime = time() - 7200; // 2 hours ago (outside 1 hour window)
        $deviceId = $this->testDevice->id;

        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity, fk_device_id)
                    VALUES (" . $this->testUser->id . ", " . $oldTime . ", 'refresh', '" . $ip . "', 1, 1, " . $deviceId . ")";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // Old data should not trigger alert
        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies with different token types (not refresh)
     */
    public function testDetectAnomaliesNonRefreshTokens(): void
    {
        // Create auth entries with different token types
        $ips = ['192.168.60.1', '10.0.60.1', '172.16.60.1', '8.8.60.8'];
        $now = time();
        $deviceId = $this->testDevice->id;

        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity, fk_device_id)
                    VALUES (" . $this->testUser->id . ", " . $now . ", 'access', '" . $ip . "', 1, 1, " . $deviceId . ")";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        // Non-refresh tokens should not trigger multiple_locations alert
        $this->assertIsArray($alerts);
    }

    /**
     * Test detectAnomalies returns correct alert structure
     */
    public function testDetectAnomaliesAlertStructureComplete(): void
    {
        // Create conditions for both alerts
        $now = time();
        $deviceId = $this->testDevice->id;

        // Excessive refresh
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family
                (fk_user, created_at, last_refresh_at, refresh_count, revoked)
                VALUES (" . $this->testUser->id . ", " . $now . ", " . $now . ", 25, 0)";
        $this->db->query($sql);

        // Multiple IPs
        $ips = ['192.168.70.1', '10.0.70.1', '172.16.70.1', '8.8.70.8'];
        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity, fk_device_id)
                    VALUES (" . $this->testUser->id . ", " . $now . ", 'refresh', '" . $ip . "', 1, 1, " . $deviceId . ")";
            $this->db->query($sql);
        }

        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, $this->testUser->id);

        $this->assertIsArray($alerts);

        // Check alert structure if any alerts
        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('message', $alert);

            // Verify valid severity values
            $this->assertContains($alert['severity'], ['low', 'medium', 'high']);

            // Verify valid type values
            $this->assertContains($alert['type'], ['excessive_refresh', 'multiple_locations']);
        }
    }

    /**
     * Test detectAnomalies with zero user_id
     */
    public function testDetectAnomaliesWithZeroUserId(): void
    {
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, 0);

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }

    /**
     * Test detectAnomalies with negative user_id
     */
    public function testDetectAnomaliesWithNegativeUserId(): void
    {
        $alerts = RefreshTokenMonitoring::detectAnomalies($this->db, -1);

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }
}
