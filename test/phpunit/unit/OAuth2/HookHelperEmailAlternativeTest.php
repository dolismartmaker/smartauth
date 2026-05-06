<?php

/**
 * Unit tests for HookHelper::runEmailAlternativePersistHook (Lot 9 SmartAuth).
 *
 * @covers \SmartAuth\Api\OAuth2\HookHelper
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\HookHelper;

class HookHelperEmailAlternativeTest extends TestCase
{
    protected function tearDown(): void
    {
        global $hookmanager;
        $hookmanager = null;
        parent::tearDown();
    }

    public function testNoHookmanagerReturnsNotHandled(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $result = HookHelper::runEmailAlternativePersistHook([
            'user_id' => 7,
            'client_pk' => 42,
            'client_id' => 'captodo',
            'email' => 'alt@example.com',
        ]);

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['internal_error']);
        $this->assertNull($result['service']);
    }

    public function testHandlerNotClaimingReturnsNotHandled(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$obj, $action) {
            return 0;
        });

        $result = HookHelper::runEmailAlternativePersistHook([
            'user_id' => 7,
            'client_pk' => 42,
            'client_id' => 'captodo',
            'email' => 'alt@example.com',
        ]);

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['internal_error']);
    }

    public function testHandlerClaimsAndReturnsServiceLabel(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$obj, $action) use (&$hookmanager) {
            if ($hook === 'smartmaker_email_alternative_persist') {
                $hookmanager->resArray = ['service' => 'CapTodo'];
                return 1;
            }
            return 0;
        });

        $result = HookHelper::runEmailAlternativePersistHook([
            'user_id' => 7,
            'client_pk' => 42,
            'client_id' => 'captodo',
            'email' => 'alt@example.com',
        ]);

        $this->assertTrue($result['handled']);
        $this->assertSame('CapTodo', $result['service']);
        $this->assertFalse($result['internal_error']);
    }

    public function testHandlerInternalError(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$obj, $action) {
            return -2;
        });

        $result = HookHelper::runEmailAlternativePersistHook([
            'user_id' => 7,
            'client_pk' => 42,
            'client_id' => 'captodo',
            'email' => 'alt@example.com',
        ]);

        $this->assertTrue($result['internal_error']);
        $this->assertFalse($result['handled']);
    }

    public function testParametersArePropagatedToHook(): void
    {
        global $hookmanager;
        $captured = [];
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$obj, $action) use (&$captured) {
            $captured = $params;
            return 1;
        });

        HookHelper::runEmailAlternativePersistHook([
            'user_id' => 9,
            'client_pk' => 12,
            'client_id' => 'capcrm',
            'email' => 'team@example.com',
        ]);

        $this->assertSame(9, $captured['user_id']);
        $this->assertSame(12, $captured['client_pk']);
        $this->assertSame('capcrm', $captured['client_id']);
        $this->assertSame('team@example.com', $captured['email']);
    }

    private function createMockHookManager(callable $callback): object
    {
        return new class($callback) {
            public $resArray = [];
            public $hooks = [];
            private $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function initHooks(array $contexts): void
            {
                $this->hooks = $contexts;
            }

            public function executeHooks(string $hook, array $parameters, &$object, string $action): int
            {
                return call_user_func_array($this->callback, [$hook, $parameters, &$object, $action]);
            }
        };
    }
}
