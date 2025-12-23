<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RateLimiter.php';

use SmartAuth\Api\RateLimiter;

/**
 * Integration tests for RateLimiter with real Dolibarr database
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
        $maliciousInput = "'; DROP TABLE llx_smartauth_ratelimit; --";
        $action = 'login_ip';

        // Should not throw exception
        $this->rateLimiter->recordAttempt($maliciousInput, $action, false);
        $result = $this->rateLimiter->checkLimit($maliciousInput, $action, 5, 300);

        $this->assertTrue($result['allowed']);

        // Table should still exist
        $count = $this->getTableCount('smartauth_ratelimit');
        $this->assertGreaterThanOrEqual(1, $count);
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
}
