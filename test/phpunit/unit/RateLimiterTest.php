<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Tests\Mocks\MockDatabase;

/**
 * Unit tests for RateLimiter
 */
class RateLimiterTest extends TestCase
{
    private MockDatabase $db;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->db = new MockDatabase();
        $this->rateLimiter = new RateLimiter($this->db);
    }

    /**
     * Test that a request is allowed when under the limit
     */
    public function testCheckLimitAllowsRequestUnderLimit(): void
    {
        // Simulate 3 attempts in window (under default limit of 5)
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

        // Simulate 5 attempts (at limit)
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
        // Simulate 10 attempts (over limit)
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
        // No previous attempts
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $result = $this->rateLimiter->checkLimit('new-user@test.com', 'login_username', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }

    /**
     * Test that database failure results in fail-open (allow request)
     */
    public function testCheckLimitFailsOpenOnDatabaseError(): void
    {
        // Simulate database failure
        $this->db->setQueryResult(false);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);

        $this->assertTrue($result['allowed'], 'Should fail open on database error');
        $this->assertNull($result['retry_after']);
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
        $this->db->setQueryResult(true, [
            ['attempt_count' => 0, 'last_attempt' => 0]
        ]);

        $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, 300);
        $query1 = $this->db->getLastQuery();

        $this->db->clearQueries();
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

        $this->db->setQueryResult(true, [
            ['attempt_count' => 5, 'last_attempt' => $lastAttempt]
        ]);

        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login_ip', 5, $windowSeconds);

        // Should still be blocked but retry_after should be ~0
        $this->assertFalse($result['allowed']);
        $this->assertLessThanOrEqual(1, $result['retry_after']);
    }
}
