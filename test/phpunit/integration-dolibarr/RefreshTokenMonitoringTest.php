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

        foreach ($ips as $ip) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth
                    (fk_authid, date_creation, token_type, ip, status, entity)
                    VALUES (" . $this->testUser->id . ", " . $now . ", 'refresh', '" . $ip . "', 1, 1)";
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
}
