<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RateLimiter.php';

use SmartAuth\Api\RateLimiter;

/**
 * Integration tests for RateLimiter with real Dolibarr database
 *
 * @covers \SmartAuth\Api\RateLimiter
 */
class RateLimiterRealTest extends DolibarrRealTestCase
{
    /** @var RateLimiter */
    private $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rateLimiter = new RateLimiter($this->db);
    }

    /**
     * Test that first request is always allowed
     */
    public function testFirstRequestIsAllowed(): void
    {
        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }

    /**
     * Test recording attempts in database
     */
    public function testRecordAttemptStoresInDatabase(): void
    {
        $ip = '10.0.0.1';
        $action = 'login_ip';

        $this->rateLimiter->recordAttempt($ip, $action, false);

        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action,
            'success' => 0
        ]);
    }

    /**
     * Test successful attempt is recorded correctly
     */
    public function testRecordSuccessfulAttempt(): void
    {
        $ip = '10.0.0.2';
        $action = 'login_ip';

        $this->rateLimiter->recordAttempt($ip, $action, true);

        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action,
            'success' => 1
        ]);
    }

    /**
     * Test rate limiting blocks after max attempts
     */
    public function testBlocksAfterMaxAttempts(): void
    {
        $ip = '172.16.0.1';
        $action = 'login_ip';
        $maxAttempts = 3;
        $window = 300;

        // Record max attempts
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->rateLimiter->recordAttempt($ip, $action, false);
        }

        // Next request should be blocked
        $result = $this->rateLimiter->checkLimit($ip, $action, $maxAttempts, $window);

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    /**
     * Test requests under limit are allowed
     */
    public function testAllowsRequestsUnderLimit(): void
    {
        $ip = '172.16.0.2';
        $action = 'login_ip';
        $maxAttempts = 5;

        // Record 4 attempts (under limit of 5)
        for ($i = 0; $i < 4; $i++) {
            $this->rateLimiter->recordAttempt($ip, $action, false);
        }

        $result = $this->rateLimiter->checkLimit($ip, $action, $maxAttempts, 300);

        $this->assertTrue($result['allowed']);
    }

    /**
     * Test reset clears rate limit for identifier
     */
    public function testResetClearsRateLimit(): void
    {
        $ip = '172.16.0.3';
        $action = 'login_ip';

        // Fill up rate limit
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAttempt($ip, $action, false);
        }

        // Verify blocked
        $result = $this->rateLimiter->checkLimit($ip, $action, 5, 300);
        $this->assertFalse($result['allowed']);

        // Reset
        $this->rateLimiter->reset($ip, $action);

        // Should be allowed again
        $result = $this->rateLimiter->checkLimit($ip, $action, 5, 300);
        $this->assertTrue($result['allowed']);
    }

    /**
     * Test different actions are tracked separately
     */
    public function testDifferentActionsTrackedSeparately(): void
    {
        $ip = '172.16.0.4';

        // Fill up login_ip
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAttempt($ip, 'login_ip', false);
        }

        // login_ip should be blocked
        $result = $this->rateLimiter->checkLimit($ip, 'login_ip', 5, 300);
        $this->assertFalse($result['allowed']);

        // But login_username for same IP should be allowed (different action)
        $result = $this->rateLimiter->checkLimit($ip, 'login_username', 5, 300);
        $this->assertTrue($result['allowed']);
    }

    /**
     * Test username rate limiting
     */
    public function testUsernameRateLimiting(): void
    {
        $username = 'testuser@example.com';
        $action = 'login_username';
        $maxAttempts = 3;

        // Fill up attempts
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->rateLimiter->recordAttempt($username, $action, false);
        }

        $result = $this->rateLimiter->checkLimit($username, $action, $maxAttempts, 900);

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    /**
     * Test SQL injection is escaped
     */
    public function testSqlInjectionIsEscaped(): void
    {
        // Use unique suffix to avoid interference from previous test runs
        $uniqueSuffix = uniqid();
        // Test that SQL injection characters are properly escaped
        // Note: Using "DELETE FROM" instead of "DROP TABLE" because Dolibarr's SQLite
        // converter incorrectly detects "DROP TABLE" even inside string literals and
        // applies DDL transformations that break the query
        $maliciousInput = "test'; DELETE FROM llx_smartauth_ratelimit WHERE '1'='1" . $uniqueSuffix;
        $action = 'login_ip_sqli_' . $uniqueSuffix;

        // Table should exist before test
        $countBefore = $this->getTableCount('smartauth_ratelimit');
        $this->assertGreaterThanOrEqual(0, $countBefore, "Table should exist before test");

        // Record an attempt - should not throw exception and should not execute injection
        $this->rateLimiter->recordAttempt($maliciousInput, $action, false);

        // Table should still exist after recordAttempt (injection was escaped)
        $countAfter = $this->getTableCount('smartauth_ratelimit');
        $this->assertGreaterThanOrEqual(1, $countAfter, "Table should exist and have at least one record");

        // checkLimit with 1 failure should allow (max is 5)
        $result = $this->rateLimiter->checkLimit($maliciousInput, $action, 5, 300);

        // Should be allowed since we only have 1 attempt, not 5
        $this->assertTrue($result['allowed'], "Should be allowed with only 1 failure attempt");
    }

    /**
     * Test retry_after calculation
     */
    public function testRetryAfterCalculation(): void
    {
        $ip = '172.16.0.5';
        $action = 'login_ip';
        $window = 60; // 1 minute window

        // Fill up rate limit
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($ip, $action, false);
        }

        $result = $this->rateLimiter->checkLimit($ip, $action, 3, $window);

        $this->assertFalse($result['allowed']);
        // retry_after should be <= window
        $this->assertLessThanOrEqual($window, $result['retry_after']);
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    /**
     * Test cleanOldEntries removes old records
     */
    public function testCleanOldEntriesRemovesOldRecords(): void
    {
        $ip = '172.16.0.6';
        $action = 'old_entries_test';

        // Insert old entries directly with old timestamps
        $oldTime = time() - 100000; // ~27 hours ago
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $this->db->escape($ip) . "', '" . $this->db->escape($action) . "', " . $oldTime . ", 0)";
        $this->db->query($sql);

        // Verify entry exists
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action
        ]);

        // Clean entries older than 1 hour
        $deleted = $this->rateLimiter->cleanOldEntries(3600);

        $this->assertGreaterThanOrEqual(1, $deleted);

        // Old entry should be gone
        $this->assertDatabaseMissing('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action
        ]);
    }

    /**
     * Test cleanOldEntries keeps recent records
     */
    public function testCleanOldEntriesKeepsRecentRecords(): void
    {
        $ip = '172.16.0.7';
        $action = 'recent_entries_test';

        // Record a recent attempt
        $this->rateLimiter->recordAttempt($ip, $action, false);

        // Verify entry exists
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action
        ]);

        // Clean entries older than 24 hours (should not delete recent entries)
        $this->rateLimiter->cleanOldEntries(86400);

        // Recent entry should still exist
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action
        ]);
    }

    /**
     * Test forceCleanup performs immediate cleanup
     */
    public function testForceCleanupPerformsImmediateCleanup(): void
    {
        $ip = '172.16.0.8';
        $action = 'force_cleanup_test';

        // Insert old entry
        $oldTime = time() - 200000;
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " (identifier, action, attempt_time, success)";
        $sql .= " VALUES ('" . $this->db->escape($ip) . "', '" . $this->db->escape($action) . "', " . $oldTime . ", 0)";
        $this->db->query($sql);

        // Force cleanup with short retention
        $deleted = $this->rateLimiter->forceCleanup(3600);

        $this->assertGreaterThanOrEqual(1, $deleted);

        // Old entry should be removed
        $this->assertDatabaseMissing('smartauth_ratelimit', [
            'identifier' => $ip,
            'action' => $action
        ]);
    }

    /**
     * Test getStats returns correct structure
     */
    public function testGetStatsReturnsCorrectStructure(): void
    {
        $stats = $this->rateLimiter->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('oldest_entry', $stats);
        $this->assertArrayHasKey('newest_entry', $stats);
        $this->assertArrayHasKey('last_cleanup', $stats);
    }

    /**
     * Test getStats reflects actual data
     */
    public function testGetStatsReflectsActualData(): void
    {
        // Record some attempts
        $this->rateLimiter->recordAttempt('stats_test_1', 'test_action', false);
        $this->rateLimiter->recordAttempt('stats_test_2', 'test_action', true);
        $this->rateLimiter->recordAttempt('stats_test_3', 'test_action', false);

        $stats = $this->rateLimiter->getStats();

        $this->assertGreaterThanOrEqual(3, $stats['total_entries']);
        $this->assertGreaterThan(0, $stats['newest_entry']);
    }

    /**
     * Test getStats on empty table
     */
    public function testGetStatsOnEmptyTable(): void
    {
        // Clean the table first
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit");

        $stats = $this->rateLimiter->getStats();

        $this->assertEquals(0, $stats['total_entries']);
        $this->assertEquals(0, $stats['oldest_entry']);
        $this->assertEquals(0, $stats['newest_entry']);
    }

    /**
     * Test cleanOldEntries with null retention uses default
     */
    public function testCleanOldEntriesWithNullUsesDefault(): void
    {
        // Should not throw exception
        $deleted = $this->rateLimiter->cleanOldEntries(null);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    /**
     * Test forceCleanup updates last cleanup time
     */
    public function testForceCleanupUpdatesLastCleanupTime(): void
    {
        $this->rateLimiter->forceCleanup();

        $stats = $this->rateLimiter->getStats();

        // last_cleanup should be recent (within last minute)
        $this->assertGreaterThan(time() - 60, $stats['last_cleanup']);
    }
}
