<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Tests\Mocks\MockDatabase;

/**
 * Unit tests for RateLimiter
 *
 * @covers \SmartAuth\Api\RateLimiter
 */
class RateLimiterTest extends TestCase
{
    private MockDatabase $db;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        global $conf;
        // Reset the cleanup cache to ensure consistent test behavior
        if (isset($conf->cache['smartmakers'])) {
            unset($conf->cache['smartmakers']);
        }

        $this->db = new MockDatabase();
        $this->rateLimiter = new RateLimiter($this->db);
    }

    /**
     * Test that a request is allowed when under the limit
     */
    public function testCheckLimitAllowsRequestUnderLimit(): void
    {
        // First query is for cleanup check (getLastCleanupTime), second is actual limit check
        $this->db->setQueryResult(true, [['value' => time()]]); // cleanup: recent, skip
        $this->db->setQueryResult(true, [
            ['attempt_count' => 3, 'last_attempt' => time() - 60]
        ]);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }

    /**
     * Test that a request is blocked when at the limit
     */
    public function testCheckLimitBlocksRequestAtLimit(): void
    {
        $lastAttempt = time() - 60;
        $windowSeconds = 300;

        // First query is for cleanup check, second is actual limit check
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 5, 'last_attempt' => $lastAttempt]
        ]);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, $windowSeconds);

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
        // retry_after should be approximately windowSeconds - time elapsed
        $expectedRetry = ($lastAttempt + $windowSeconds) - time();
        $this->assertEquals($expectedRetry, $result['retry_after']);
    }

    /**
     * Test that a request is blocked when over the limit
     */
    public function testCheckLimitBlocksRequestOverLimit(): void
    {
        // First query is for cleanup check, second is actual limit check
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 10, 'last_attempt' => time() - 30]
        ]);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);

        $this->assertFalse($result['allowed']);
    }

    /**
     * Test that zero attempts allows request
     */
    public function testCheckLimitAllowsFirstRequest(): void
    {
        // First query is for cleanup check, second is actual limit check
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $result = $this->rateLimiter->checkLimit('new-user@test.com', 'login_username', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }

    /**
     * Test that database failure results in fail-closed (block request)
     */
    public function testCheckLimitFailsClosedOnDatabaseError(): void
    {
        // First query for cleanup check, then fail the actual limit query
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(false);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);

        $this->assertFalse($result['allowed'], 'Should fail closed on database error');
        $this->assertEquals(60, $result['retry_after']);
    }

    /**
     * Test recordAttempt inserts into database
     */
    public function testRecordAttemptInsertsRecord(): void
    {
        $this->db->setQueryResult(true);

        $result = $this->rateLimiter->recordAttempt('192.168.1.1', 'login_ip', false);

        $this->assertTrue($result);
        $this->assertTrue($this->db->hasQueryContaining('INSERT INTO'));
        $this->assertTrue($this->db->hasQueryContaining('smartauth_ratelimit'));
        $this->assertTrue($this->db->hasQueryContaining('192.168.1.1'));
    }

    /**
     * Test recordAttempt with success flag
     */
    public function testRecordAttemptWithSuccessFlag(): void
    {
        $this->db->setQueryResult(true);

        $this->rateLimiter->recordAttempt('user@test.com', 'login_username', true);

        $query = $this->db->getLastQuery();
        $this->assertStringContainsString(', 1)', $query, 'Success should be 1');
    }

    /**
     * Test recordAttempt with failure flag
     */
    public function testRecordAttemptWithFailureFlag(): void
    {
        $this->db->setQueryResult(true);

        $this->rateLimiter->recordAttempt('user@test.com', 'login_username', false);

        $query = $this->db->getLastQuery();
        $this->assertStringContainsString(', 0)', $query, 'Success should be 0');
    }

    /**
     * Test reset deletes records for identifier
     */
    public function testResetDeletesRecords(): void
    {
        $this->db->setQueryResult(true);

        $result = $this->rateLimiter->reset('192.168.1.1', 'login_ip');

        $this->assertTrue($result);
        $this->assertTrue($this->db->hasQueryContaining('DELETE FROM'));
        $this->assertTrue($this->db->hasQueryContaining('192.168.1.1'));
        $this->assertTrue($this->db->hasQueryContaining('login_ip'));
    }

    /**
     * Test that identifiers are properly escaped
     */
    public function testIdentifiersAreEscaped(): void
    {
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $maliciousInput = "'; DROP TABLE users; --";
        $this->rateLimiter->checkLimit($maliciousInput, 'login_ip', 5, 300);

        $query = $this->db->getLastQuery();
        // The escape function should have added slashes
        $this->assertStringContainsString("\\'", $query);
    }

    /**
     * Test different actions are tracked separately
     */
    public function testDifferentActionsTrackedSeparately(): void
    {
        global $conf;

        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);
        $query1 = $this->db->getLastQuery();

        $this->db->clearQueries();
        // Reset cache to force cleanup check again
        unset($conf->cache['smartmakers']);
        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $this->rateLimiter->checkLimit('192.168.1.1', 'api_call', 100, 60);
        $query2 = $this->db->getLastQuery();

        $this->assertStringContainsString('login_ip', $query1);
        $this->assertStringContainsString('api_call', $query2);
    }

    /**
     * Test retry_after is 0 when window has expired
     */
    public function testRetryAfterIsZeroWhenWindowExpired(): void
    {
        $windowSeconds = 300;
        // Last attempt was exactly at window boundary
        $lastAttempt = time() - $windowSeconds;

        $this->db->setQueryResult(true, [['value' => time()]]);
        $this->db->setQueryResult(true, [
            ['attempt_count' => 5, 'last_attempt' => $lastAttempt]
        ]);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, $windowSeconds);

        // Should still be blocked but retry_after should be ~0
        $this->assertFalse($result['allowed']);
        $this->assertLessThanOrEqual(1, $result['retry_after']);
    }

    // =============================================
    // Tests for cleanOldEntries method
    // =============================================

    /**
     * Test cleanOldEntries with default retention
     */
    public function testCleanOldEntriesWithDefaultRetention(): void
    {
        $this->db->setQueryResult(true);
        $this->db->setAffectedRows(10);

        $deleted = $this->rateLimiter->cleanOldEntries();

        $this->assertEquals(10, $deleted);
        $this->assertTrue($this->db->hasQueryContaining('DELETE FROM'));
        $this->assertTrue($this->db->hasQueryContaining('smartauth_ratelimit'));
        $this->assertTrue($this->db->hasQueryContaining('attempt_time <'));
    }

    /**
     * Test cleanOldEntries with custom retention
     */
    public function testCleanOldEntriesWithCustomRetention(): void
    {
        $this->db->setQueryResult(true);
        $this->db->setAffectedRows(5);

        $deleted = $this->rateLimiter->cleanOldEntries(3600); // 1 hour

        $this->assertEquals(5, $deleted);
        $query = $this->db->getLastQuery();
        // The cutoff should be time() - 3600
        $expectedCutoff = time() - 3600;
        $this->assertStringContainsString((string)$expectedCutoff, $query);
    }

    /**
     * Test cleanOldEntries returns zero when no entries deleted
     */
    public function testCleanOldEntriesReturnsZeroWhenNoneDeleted(): void
    {
        $this->db->setQueryResult(true);
        $this->db->setAffectedRows(0);

        $deleted = $this->rateLimiter->cleanOldEntries();

        $this->assertEquals(0, $deleted);
    }

    /**
     * Test cleanOldEntries returns -1 on database error
     */
    public function testCleanOldEntriesReturnsMinusOneOnError(): void
    {
        $this->db->setQueryResult(false);

        $deleted = $this->rateLimiter->cleanOldEntries();

        $this->assertEquals(-1, $deleted);
    }

    // =============================================
    // Tests for forceCleanup method
    // =============================================

    /**
     * Test forceCleanup executes cleanup immediately
     */
    public function testForceCleanupExecutesImmediately(): void
    {
        global $conf;

        // Setup: cleanup query succeeds, update const succeeds
        $this->db->setQueryResult(true); // DELETE query
        $this->db->setAffectedRows(15);

        $deleted = $this->rateLimiter->forceCleanup();

        // Verify DELETE was executed
        $this->assertTrue($this->db->hasQueryContaining('DELETE FROM'));
        $this->assertEquals(15, $deleted);
        // Should have updated cache
        $this->assertEquals(time(), $conf->cache['smartmakers'][RateLimiter::CLEANUP_CACHE_KEY], '', 1);
    }

    /**
     * Test forceCleanup with custom retention
     */
    public function testForceCleanupWithCustomRetention(): void
    {
        $this->db->setQueryResult(true);
        $this->db->setAffectedRows(20);

        $deleted = $this->rateLimiter->forceCleanup(7200); // 2 hours

        // Verify the cutoff time in the query (time() - 7200)
        $queries = $this->db->getQueries();
        $deleteQuery = $queries[0];
        $this->assertStringContainsString('DELETE FROM', $deleteQuery);
        $this->assertEquals(20, $deleted);
    }

    /**
     * Test forceCleanup updates last cleanup time even on zero deletions
     */
    public function testForceCleanupUpdatesTimeEvenOnZeroDeletions(): void
    {
        global $conf;

        $this->db->setQueryResult(true);
        $this->db->setAffectedRows(0);

        $deleted = $this->rateLimiter->forceCleanup();

        $this->assertEquals(0, $deleted);
        // Should still update the cache
        $this->assertArrayHasKey(RateLimiter::CLEANUP_CACHE_KEY, $conf->cache['smartmakers']);
    }

    // =============================================
    // Tests for getStats method
    // =============================================

    /**
     * Test getStats returns statistics
     */
    public function testGetStatsReturnsStatistics(): void
    {
        // First query for stats, second for getLastCleanupTime
        $now = time();
        $this->db->setFetchResultSequence([
            (object)['total' => 100, 'oldest' => $now - 86400, 'newest' => $now - 60],
            (object)['value' => $now - 3600]
        ]);

        $stats = $this->rateLimiter->getStats();

        $this->assertIsArray($stats);
        $this->assertEquals(100, $stats['total_entries']);
        $this->assertEquals($now - 86400, $stats['oldest_entry']);
        $this->assertEquals($now - 60, $stats['newest_entry']);
        $this->assertEquals($now - 3600, $stats['last_cleanup']);
    }

    /**
     * Test getStats returns zeros on empty table
     */
    public function testGetStatsReturnsZerosOnEmptyTable(): void
    {
        $this->db->setFetchResultSequence([
            (object)['total' => 0, 'oldest' => null, 'newest' => null],
            null // No cleanup time record
        ]);

        $stats = $this->rateLimiter->getStats();

        $this->assertEquals(0, $stats['total_entries']);
        $this->assertEquals(0, $stats['oldest_entry']);
        $this->assertEquals(0, $stats['newest_entry']);
        $this->assertEquals(0, $stats['last_cleanup']);
    }

    /**
     * Test getStats returns defaults on database error
     */
    public function testGetStatsReturnsDefaultsOnError(): void
    {
        $this->db->setQueryResult(false);

        $stats = $this->rateLimiter->getStats();

        $this->assertEquals(0, $stats['total_entries']);
        $this->assertEquals(0, $stats['oldest_entry']);
        $this->assertEquals(0, $stats['newest_entry']);
        $this->assertEquals(0, $stats['last_cleanup']);
    }

    /**
     * Test getStats queries correct table
     */
    public function testGetStatsQueriesCorrectTable(): void
    {
        $this->db->setFetchResultSequence([
            (object)['total' => 0, 'oldest' => 0, 'newest' => 0],
            null
        ]);

        $this->rateLimiter->getStats();

        $this->assertTrue($this->db->hasQueryContaining('smartauth_ratelimit'));
        $this->assertTrue($this->db->hasQueryContaining('COUNT(*)'));
        $this->assertTrue($this->db->hasQueryContaining('MIN(attempt_time)'));
        $this->assertTrue($this->db->hasQueryContaining('MAX(attempt_time)'));
    }

    // =============================================
    // Tests for constants
    // =============================================

    /**
     * Test class constants are defined
     */
    public function testConstantsAreDefined(): void
    {
        $this->assertEquals('smartauth_ratelimit_last_cleanup', RateLimiter::CLEANUP_CACHE_KEY);
        $this->assertEquals(3600, RateLimiter::CLEANUP_INTERVAL);
        $this->assertEquals(86400, RateLimiter::MAX_ENTRY_AGE);
    }

    // =============================================
    // Tests for checkLimit with no result from DB
    // =============================================

    /**
     * Test checkLimit allows when DB returns null object
     */
    public function testCheckLimitAllowsWhenNoResult(): void
    {
        $this->db->setQueryResult(true, [['value' => time()]]);
        // Second query returns empty result (no previous attempts)
        $this->db->setQueryResult(true);
        $this->db->setFetchResultSequence([null]);

        $result = $this->rateLimiter->checkLimit('new-ip', 'login_ip', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }
}
