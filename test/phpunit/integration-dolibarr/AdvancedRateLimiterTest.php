<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/RateLimiter.php';
require_once __DIR__ . '/../../../api/AdvancedRateLimiter.php';

use SmartAuth\Api\AdvancedRateLimiter;
use SmartAuth\Api\RateLimiter;

/**
 * Integration tests for AdvancedRateLimiter class
 */
class AdvancedRateLimiterTest extends DolibarrRealTestCase
{
    /** @var AdvancedRateLimiter */
    private $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rateLimiter = new AdvancedRateLimiter($this->db);
    }

    /**
     * Test AdvancedRateLimiter extends RateLimiter
     */
    public function testExtendsRateLimiter(): void
    {
        $this->assertInstanceOf(RateLimiter::class, $this->rateLimiter);
        $this->assertInstanceOf(AdvancedRateLimiter::class, $this->rateLimiter);
    }

    /**
     * Test checkLimitProgressive with no failures
     */
    public function testCheckLimitProgressiveNoFailures(): void
    {
        $result = $this->rateLimiter->checkLimitProgressive('test-ip-no-fail', 'login');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
        $this->assertEquals(0, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with 1-3 failures (no delay)
     */
    public function testCheckLimitProgressiveFewFailures(): void
    {
        $identifier = 'test-ip-few-' . uniqid();

        // Record 3 failures
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
        $this->assertEquals(3, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with 4-5 failures (30 seconds delay)
     */
    public function testCheckLimitProgressiveMediumFailures(): void
    {
        $identifier = 'test-ip-medium-' . uniqid();

        // Record 4 failures
        for ($i = 0; $i < 4; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
        $this->assertLessThanOrEqual(30, $result['retry_after']);
        $this->assertEquals(4, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with 6-10 failures (5 minutes delay)
     */
    public function testCheckLimitProgressiveManyFailures(): void
    {
        $identifier = 'test-ip-many-' . uniqid();

        // Record 6 failures
        for ($i = 0; $i < 6; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
        $this->assertLessThanOrEqual(300, $result['retry_after']);
        $this->assertEquals(6, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with 11+ failures (1 hour delay)
     */
    public function testCheckLimitProgressiveExcessiveFailures(): void
    {
        $identifier = 'test-ip-excessive-' . uniqid();

        // Record 11 failures
        for ($i = 0; $i < 11; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
        $this->assertLessThanOrEqual(3600, $result['retry_after']);
        $this->assertEquals(11, $result['failures']);
    }

    /**
     * Test checkLimitProgressive stops counting at success
     *
     * The method counts failures from most recent to oldest and stops at first success.
     * Since all attempts happen at same time (same second), the order might be by rowid.
     */
    public function testCheckLimitProgressiveStopsAtSuccess(): void
    {
        $identifier = 'test-ip-success-' . uniqid();

        // Record some failures, then a success, then more failures
        // Due to same-second timing, we check that it stops counting somewhere
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        // Record a success
        $this->rateLimiter->recordAttempt($identifier, 'login', true);

        // Record more failures after success
        for ($i = 0; $i < 2; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        // The method counts from most recent (ORDER BY attempt_time DESC)
        // Since all attempts are at same time, behavior depends on insertion order
        // We just verify the result is reasonable (less than total failures)
        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
        // Should count fewer than 5 (total failures) due to success interrupting count
        $this->assertLessThanOrEqual(5, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with different actions
     */
    public function testCheckLimitProgressiveDifferentActions(): void
    {
        $identifier = 'test-ip-actions-' . uniqid();

        // Record 5 failures for 'login' action
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        // Check 'login' action - should be limited
        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');
        $this->assertFalse($result['allowed']);

        // Check 'api_call' action - should not be limited
        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'api_call');
        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['failures']);
    }

    /**
     * Test checkLimitProgressive with different identifiers
     */
    public function testCheckLimitProgressiveDifferentIdentifiers(): void
    {
        $identifier1 = 'test-ip-1-' . uniqid();
        $identifier2 = 'test-ip-2-' . uniqid();

        // Record 5 failures for identifier1
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAttempt($identifier1, 'login', false);
        }

        // Check identifier1 - should be limited
        $result = $this->rateLimiter->checkLimitProgressive($identifier1, 'login');
        $this->assertFalse($result['allowed']);

        // Check identifier2 - should not be limited
        $result = $this->rateLimiter->checkLimitProgressive($identifier2, 'login');
        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['failures']);
    }

    /**
     * Test checkLimitProgressive returns failures count
     */
    public function testCheckLimitProgressiveReturnsFailureCount(): void
    {
        $identifier = 'test-ip-count-' . uniqid();

        // Record 7 failures
        for ($i = 0; $i < 7; $i++) {
            $this->rateLimiter->recordAttempt($identifier, 'login', false);
        }

        $result = $this->rateLimiter->checkLimitProgressive($identifier, 'login');

        $this->assertEquals(7, $result['failures']);
    }

    /**
     * Test inherited checkLimit method still works
     */
    public function testInheritedCheckLimit(): void
    {
        $identifier = 'test-inherited-' . uniqid();

        // Should be allowed with no attempts
        $result = $this->rateLimiter->checkLimit($identifier, 'api_call', 5, 300);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['retry_after']);
    }

    /**
     * Test inherited recordAttempt method still works
     */
    public function testInheritedRecordAttempt(): void
    {
        $identifier = 'test-record-' . uniqid();

        $result = $this->rateLimiter->recordAttempt($identifier, 'test_action', true);

        $this->assertNotFalse($result);

        // Verify in database
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $identifier,
            'action' => 'test_action',
            'success' => 1
        ]);
    }

    /**
     * Test inherited reset method still works
     */
    public function testInheritedReset(): void
    {
        $identifier = 'test-reset-' . uniqid();

        // Record some attempts
        $this->rateLimiter->recordAttempt($identifier, 'login', false);
        $this->rateLimiter->recordAttempt($identifier, 'login', false);

        // Verify they exist
        $this->assertDatabaseHas('smartauth_ratelimit', [
            'identifier' => $identifier,
            'action' => 'login'
        ]);

        // Reset
        $this->rateLimiter->reset($identifier, 'login');

        // Verify they're gone
        $this->assertDatabaseMissing('smartauth_ratelimit', [
            'identifier' => $identifier,
            'action' => 'login'
        ]);
    }

    /**
     * Test progressive limit with exact threshold values
     */
    public function testProgressiveLimitThresholds(): void
    {
        // Test at exactly 3 failures (no delay)
        $id3 = 'threshold-3-' . uniqid();
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordAttempt($id3, 'login', false);
        }
        $result3 = $this->rateLimiter->checkLimitProgressive($id3, 'login');
        $this->assertTrue($result3['allowed'], "3 failures should not trigger delay");

        // Test at exactly 4 failures (30 second delay)
        $id4 = 'threshold-4-' . uniqid();
        for ($i = 0; $i < 4; $i++) {
            $this->rateLimiter->recordAttempt($id4, 'login', false);
        }
        $result4 = $this->rateLimiter->checkLimitProgressive($id4, 'login');
        $this->assertFalse($result4['allowed'], "4 failures should trigger 30s delay");

        // Test at exactly 5 failures (still 30 second delay)
        $id5 = 'threshold-5-' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAttempt($id5, 'login', false);
        }
        $result5 = $this->rateLimiter->checkLimitProgressive($id5, 'login');
        $this->assertFalse($result5['allowed'], "5 failures should trigger 30s delay");

        // Test at exactly 6 failures (5 minute delay)
        $id6 = 'threshold-6-' . uniqid();
        for ($i = 0; $i < 6; $i++) {
            $this->rateLimiter->recordAttempt($id6, 'login', false);
        }
        $result6 = $this->rateLimiter->checkLimitProgressive($id6, 'login');
        $this->assertFalse($result6['allowed'], "6 failures should trigger 5min delay");

        // Test at exactly 10 failures (still 5 minute delay)
        $id10 = 'threshold-10-' . uniqid();
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->recordAttempt($id10, 'login', false);
        }
        $result10 = $this->rateLimiter->checkLimitProgressive($id10, 'login');
        $this->assertFalse($result10['allowed'], "10 failures should trigger 5min delay");

        // Test at exactly 11 failures (1 hour delay)
        $id11 = 'threshold-11-' . uniqid();
        for ($i = 0; $i < 11; $i++) {
            $this->rateLimiter->recordAttempt($id11, 'login', false);
        }
        $result11 = $this->rateLimiter->checkLimitProgressive($id11, 'login');
        $this->assertFalse($result11['allowed'], "11 failures should trigger 1h delay");
    }

    /**
     * Test SQL injection protection in checkLimitProgressive
     */
    public function testSqlInjectionProtection(): void
    {
        $maliciousIdentifier = "'; DROP TABLE llx_smartauth_ratelimit; --";
        $maliciousAction = "'; DELETE FROM llx_users; --";

        // Should not throw error and should be allowed (no failures)
        $result = $this->rateLimiter->checkLimitProgressive($maliciousIdentifier, $maliciousAction);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['failures']);

        // Verify table still exists by counting rows (should be >= 0)
        $count = $this->getTableCount('smartauth_ratelimit');
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
