<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SmartAuth\Api\AuthController;
use SmartAuth\Tests\Mocks\MockDatabase;

/**
 * A revocation that did not happen must never be reported as done.
 *
 * _revokeTokenFamily fired two UPDATEs and ignored both results, then logged an
 * unconditional success. Under contention (lock timeout, deadlock, a database
 * that went away mid-request) the family stayed alive while the journal said it
 * was revoked and /logout answered 200 -- so the client dropped its tokens
 * believing the session was dead, and the refresh token kept working
 * server-side. The one state nobody can recover from, because nothing signals
 * it.
 *
 * @covers \SmartAuth\Api\AuthController
 */
class RevocationFailureTest extends TestCase
{
    /** @var AuthController */
    private $controller;

    /** @var mixed */
    private $savedDb;

    protected function setUp(): void
    {
        global $conf, $db;
        smartauth_test_reset_conf();

        $this->savedDb = $db;
        $this->controller = new AuthController();
    }

    protected function tearDown(): void
    {
        global $db;
        $db = $this->savedDb;
        $GLOBALS['db'] = $this->savedDb;
        parent::tearDown();
    }

    private function useDatabase(MockDatabase $mock): void
    {
        global $db;
        $db = $mock;
        $GLOBALS['db'] = $mock;
    }

    private function callRevokeFamily($familyId, string $reason): bool
    {
        $reflection = new ReflectionClass(AuthController::class);
        $method = $reflection->getMethod('_revokeTokenFamily');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $familyId, $reason);
    }

    public function testRevokeTokenFamilyReportsSuccessWhenBothUpdatesGoThrough(): void
    {
        $mock = new MockDatabase();
        $mock->setQueryResult(true);
        $this->useDatabase($mock);

        $this->assertTrue($this->callRevokeFamily(42, 'logout'));

        // Both writes were actually attempted: the family flag and the tokens.
        $this->assertTrue($mock->hasQueryContaining('smartauth_token_family'));
        $this->assertTrue($mock->hasQueryContaining('smartauth_auth'));
    }

    public function testRevokeTokenFamilyReportsFailureWhenAnUpdateFails(): void
    {
        $mock = new MockDatabase();
        $mock->setQueryResult(false);
        $this->useDatabase($mock);

        $this->assertFalse(
            $this->callRevokeFamily(42, 'logout'),
            'a failed UPDATE must not be reported as a successful revocation'
        );
    }

    /**
     * And the failure has to reach the caller: answering 200 to /logout while
     * the session survives is exactly what makes this unrecoverable.
     */
    public function testLogoutAnswers500WhenTheRevocationFails(): void
    {
        $mock = new MockDatabase();
        $mock->setQueryResult(false);
        $this->useDatabase($mock);

        $user = new \stdClass();
        $user->id = 7;

        $result = $this->controller->logout([
            'user' => $user,
            'jwt_family_id' => 42,
        ]);

        $this->assertSame(500, $result[1], 'a failed logout must not answer 200');
        $this->assertArrayHasKey('error', $result[0]);
    }
}
