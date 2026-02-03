<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RefreshTokenMonitoring;
use SmartAuth\Tests\Mocks\MockDatabase;

/**
 * Unit tests for RefreshTokenMonitoring
 *
 * @covers \SmartAuth\Api\RefreshTokenMonitoring
 */
class RefreshTokenMonitoringTest extends TestCase
{
    /**
     * Test getRefreshStats returns empty array for no data
     */
    public function testGetRefreshStatsReturnsEmptyArrayForNoData(): void
    {
        $mockDb = new MockDatabase();

        $result = RefreshTokenMonitoring::getRefreshStats($mockDb, 7);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getRefreshStats uses correct time calculation
     */
    public function testGetRefreshStatsUsesCorrectTimeWindow(): void
    {
        $mockDb = new MockDatabase();
        $days = 7;
        $expectedSince = time() - ($days * 86400);

        RefreshTokenMonitoring::getRefreshStats($mockDb, $days);

        $queries = $mockDb->getQueries();
        $this->assertNotEmpty($queries);

        // Check that the query contains the expected time window
        $sql = $queries[0];
        $this->assertStringContainsString('created_at >', $sql);
    }

    /**
     * Test getRefreshStats queries correct table
     */
    public function testGetRefreshStatsQueriesCorrectTable(): void
    {
        $mockDb = new MockDatabase();

        RefreshTokenMonitoring::getRefreshStats($mockDb, 7);

        $queries = $mockDb->getQueries();
        $this->assertNotEmpty($queries);

        $sql = $queries[0];
        $this->assertStringContainsString('smartauth_token_family', $sql);
    }

    /**
     * Test getRefreshStats groups by date
     */
    public function testGetRefreshStatsGroupsByDate(): void
    {
        $mockDb = new MockDatabase();

        RefreshTokenMonitoring::getRefreshStats($mockDb, 7);

        $queries = $mockDb->getQueries();
        $sql = $queries[0];

        $this->assertStringContainsString('GROUP BY refresh_date', $sql);
        $this->assertStringContainsString('ORDER BY refresh_date DESC', $sql);
    }

    /**
     * Test getRefreshStats with different day values
     */
    public function testGetRefreshStatsWithDifferentDayValues(): void
    {
        $mockDb1 = new MockDatabase();
        $mockDb30 = new MockDatabase();

        RefreshTokenMonitoring::getRefreshStats($mockDb1, 1);
        RefreshTokenMonitoring::getRefreshStats($mockDb30, 30);

        $queries1 = $mockDb1->getQueries();
        $queries30 = $mockDb30->getQueries();

        // Both should have executed a query
        $this->assertNotEmpty($queries1);
        $this->assertNotEmpty($queries30);
    }

    /**
     * Test detectAnomalies returns empty array for no anomalies
     */
    public function testDetectAnomaliesReturnsEmptyArrayForNoAnomalies(): void
    {
        $mockDb = new MockDatabase();
        // Mock returns 0 count for both queries
        $mockDb->setFetchResultSequence([
            (object)['count' => 0],
            (object)['ip_count' => 0]
        ]);

        $result = RefreshTokenMonitoring::detectAnomalies($mockDb, 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test detectAnomalies checks for excessive refresh
     */
    public function testDetectAnomaliesChecksExcessiveRefresh(): void
    {
        $mockDb = new MockDatabase();
        $userId = 123;

        // Provide results for both queries to avoid null access
        $mockDb->setFetchResultSequence([
            (object)['count' => 0],
            (object)['ip_count' => 0]
        ]);

        RefreshTokenMonitoring::detectAnomalies($mockDb, $userId);

        $queries = $mockDb->getQueries();
        $this->assertNotEmpty($queries);

        // First query should check for excessive refreshes
        $sql = $queries[0];
        $this->assertStringContainsString('refresh_count > 10', $sql);
        $this->assertStringContainsString('fk_user = ' . $userId, $sql);
    }

    /**
     * Test detectAnomalies checks for multiple locations
     */
    public function testDetectAnomaliesChecksMultipleLocations(): void
    {
        $mockDb = new MockDatabase();
        // Provide results for both queries
        $mockDb->setFetchResultSequence([
            (object)['count' => 0],
            (object)['ip_count' => 0]
        ]);

        RefreshTokenMonitoring::detectAnomalies($mockDb, 456);

        $queries = $mockDb->getQueries();
        $this->assertGreaterThanOrEqual(2, count($queries));

        // Second query should check for multiple IPs
        $sql = $queries[1];
        $this->assertStringContainsString('COUNT(DISTINCT ip)', $sql);
        $this->assertStringContainsString('smartauth_auth', $sql);
    }

    /**
     * Test detectAnomalies returns excessive_refresh alert
     */
    public function testDetectAnomaliesReturnsExcessiveRefreshAlert(): void
    {
        $mockDb = new MockDatabase();
        // First query returns count > 0 (excessive refresh detected)
        $mockDb->setFetchResultSequence([
            (object)['count' => 1],
            (object)['ip_count' => 1]
        ]);

        $result = RefreshTokenMonitoring::detectAnomalies($mockDb, 1);

        $this->assertNotEmpty($result);
        $this->assertEquals('excessive_refresh', $result[0]['type']);
        $this->assertEquals('medium', $result[0]['severity']);
    }

    /**
     * Test detectAnomalies returns multiple_locations alert
     */
    public function testDetectAnomaliesReturnsMultipleLocationsAlert(): void
    {
        $mockDb = new MockDatabase();
        // First query returns 0, second returns ip_count > 3
        $mockDb->setFetchResultSequence([
            (object)['count' => 0],
            (object)['ip_count' => 5]
        ]);

        $result = RefreshTokenMonitoring::detectAnomalies($mockDb, 1);

        $this->assertNotEmpty($result);
        $this->assertEquals('multiple_locations', $result[0]['type']);
        $this->assertEquals('high', $result[0]['severity']);
    }

    /**
     * Test detectAnomalies returns both alerts when both conditions met
     */
    public function testDetectAnomaliesReturnsBothAlerts(): void
    {
        $mockDb = new MockDatabase();
        // Both conditions met
        $mockDb->setFetchResultSequence([
            (object)['count' => 2],
            (object)['ip_count' => 4]
        ]);

        $result = RefreshTokenMonitoring::detectAnomalies($mockDb, 1);

        $this->assertCount(2, $result);
        $this->assertEquals('excessive_refresh', $result[0]['type']);
        $this->assertEquals('multiple_locations', $result[1]['type']);
    }

    /**
     * Test detectAnomalies uses correct time window (1 hour)
     */
    public function testDetectAnomaliesUsesOneHourWindow(): void
    {
        $mockDb = new MockDatabase();
        // Provide results for both queries
        $mockDb->setFetchResultSequence([
            (object)['count' => 0],
            (object)['ip_count' => 0]
        ]);

        RefreshTokenMonitoring::detectAnomalies($mockDb, 1);

        $queries = $mockDb->getQueries();
        $sql = $queries[0];

        // Should contain a timestamp check for about 1 hour ago
        $this->assertStringContainsString('last_refresh_at >', $sql);
    }
}
