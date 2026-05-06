<?php

/**
 * Unit tests for the OAuth2 HookHelper.
 *
 * Validates the central hook invocation logic used by AuthorizationController,
 * TokenController, UserinfoController, TokenService and the future /account
 * route.
 *
 * @covers \SmartAuth\Api\OAuth2\HookHelper
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\HookHelper;

class HookHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        global $hookmanager;
        $hookmanager = null;
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // runBlockingHook (covers smartmaker_oauth_pre_authorize and pre_token)
    // ---------------------------------------------------------------------

    /**
     * No hookmanager registered: helper must not block anything.
     */
    public function testBlockingHookNoHookmanagerReturnsAllowed(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_authorize', [
            'user_id' => 1,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid'],
            'redirect_uri' => 'https://example.com/cb',
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['internal_error']);
        $this->assertNull($result['error']);
    }

    /**
     * pre_authorize blocking case (return 1) must propagate error and description
     * from $hookmanager->resArray.
     */
    public function testPreAuthorizeBlockingPropagatesError(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) use (&$hookmanager) {
            if ($hook === 'smartmaker_oauth_pre_authorize') {
                $hookmanager->resArray = [
                    'error' => 'access_denied',
                    'error_description' => 'Aucun abonnement actif pour ce service.',
                ];
                return 1;
            }
            return 0;
        });

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_authorize', [
            'user_id' => 7,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid'],
            'redirect_uri' => 'https://app.example.com/cb',
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame('access_denied', $result['error']);
        $this->assertSame('Aucun abonnement actif pour ce service.', $result['error_description']);
        $this->assertFalse($result['internal_error']);
    }

    /**
     * pre_authorize non-blocking case (return 0) must let the flow continue.
     */
    public function testPreAuthorizeNonBlockingAllowsFlow(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            return 0;
        });

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_authorize', [
            'user_id' => 1,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid'],
            'redirect_uri' => 'https://app.example.com/cb',
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['internal_error']);
    }

    /**
     * pre_token blocking case must produce a result that the controller
     * will translate into 400 invalid_grant.
     */
    public function testPreTokenBlockingProducesInvalidGrantPayload(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) use (&$hookmanager) {
            if ($hook === 'smartmaker_oauth_pre_token') {
                $hookmanager->resArray = [
                    'error' => 'invalid_grant',
                    'error_description' => 'Votre abonnement a expire.',
                ];
                return 1;
            }
            return 0;
        });

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_token', [
            'user_id' => 7,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid', 'offline_access'],
            'grant_type' => 'refresh_token',
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame('invalid_grant', $result['error']);
        $this->assertSame('Votre abonnement a expire.', $result['error_description']);
    }

    /**
     * Negative return value from a hook is treated as an internal error;
     * callers should respond with 500.
     */
    public function testBlockingHookInternalError(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            return -1;
        });

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_authorize', [
            'user_id' => 1,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid'],
            'redirect_uri' => 'https://app.example.com/cb',
        ]);

        $this->assertTrue($result['internal_error']);
        $this->assertFalse($result['blocked']);
    }

    /**
     * When a module blocks but forgets to set resArray, the helper falls back
     * to access_denied so the OAuth2 response remains spec-compliant.
     */
    public function testBlockingHookFallbackErrorWhenResArrayMissing(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            return 1;
        });

        $result = HookHelper::runBlockingHook('smartmaker_oauth_pre_authorize', [
            'user_id' => 1,
            'client_id' => 'captodo',
            'client_pk' => 42,
            'scopes' => ['openid'],
            'redirect_uri' => 'https://app.example.com/cb',
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame('access_denied', $result['error']);
    }

    // ---------------------------------------------------------------------
    // runClaimsHook (covers smartmaker_oauth_userinfo_claims)
    // ---------------------------------------------------------------------

    /**
     * Without a hookmanager, claims are returned untouched.
     */
    public function testClaimsHookNoHookmanagerReturnsClaimsUnchanged(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $original = ['sub' => '7', 'email' => 'marie@example.com'];
        $result = HookHelper::runClaimsHook(
            ['user_id' => 7, 'client_id' => 'captodo', 'client_pk' => 42, 'scopes' => ['openid', 'email'], 'context' => 'userinfo'],
            $original
        );

        $this->assertSame($original, $result);
    }

    /**
     * A module overrides the email claim. The helper must propagate the
     * mutation back to the caller.
     */
    public function testClaimsHookOverridesEmail(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$claims, $action) {
            if ($hook === 'smartmaker_oauth_userinfo_claims') {
                $claims['email'] = 'equipe-todo@example.com';
                $claims['email_verified'] = true;
            }
            return 0;
        });

        $original = ['sub' => '7', 'email' => 'marie@example.com', 'email_verified' => true];
        $result = HookHelper::runClaimsHook(
            ['user_id' => 7, 'client_id' => 'captodo', 'client_pk' => 42, 'scopes' => ['openid', 'email'], 'context' => 'userinfo'],
            $original
        );

        $this->assertSame('equipe-todo@example.com', $result['email']);
        $this->assertTrue($result['email_verified']);
        // sub must not be touched by SmartAuth, but the hook itself can; this
        // test simply asserts the mutation propagates.
        $this->assertSame('7', $result['sub']);
    }

    /**
     * A module that errors out (return < 0) must NOT corrupt the response:
     * the original claims are preserved.
     */
    public function testClaimsHookErrorPreservesStandardClaims(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$claims, $action) {
            $claims['email'] = 'broken-by-hook@example.com';
            return -1;
        });

        $original = ['sub' => '7', 'email' => 'marie@example.com'];
        $result = HookHelper::runClaimsHook(
            ['user_id' => 7, 'client_id' => 'captodo', 'client_pk' => 42, 'scopes' => ['openid', 'email'], 'context' => 'id_token'],
            $original
        );

        $this->assertSame($original, $result);
    }

    // ---------------------------------------------------------------------
    // runAccountSectionsHook (covers smartmaker_account_sections)
    // ---------------------------------------------------------------------

    /**
     * Sections are sorted by ascending priority.
     */
    public function testAccountSectionsHookSortsByPriority(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$sections, $action) {
            if ($hook === 'smartmaker_account_sections') {
                $sections[] = ['title' => 'Late', 'html' => '<p>Late</p>', 'priority' => 200];
                $sections[] = ['title' => 'Early', 'html' => '<p>Early</p>', 'priority' => 10];
                $sections[] = ['title' => 'Mid', 'html' => '<p>Mid</p>', 'priority' => 100];
            }
            return 0;
        });

        $sections = HookHelper::runAccountSectionsHook(['user_id' => 7]);

        $this->assertCount(3, $sections);
        $this->assertSame('Early', $sections[0]['title']);
        $this->assertSame('Mid', $sections[1]['title']);
        $this->assertSame('Late', $sections[2]['title']);
    }

    /**
     * Missing priority defaults to 100; missing title/html default to ''.
     */
    public function testAccountSectionsHookNormalizesMissingFields(): void
    {
        global $hookmanager;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$sections, $action) {
            if ($hook === 'smartmaker_account_sections') {
                $sections[] = ['title' => 'Plain'];
                $sections[] = ['html' => '<p>HtmlOnly</p>', 'priority' => 50];
            }
            return 0;
        });

        $sections = HookHelper::runAccountSectionsHook(['user_id' => 7]);

        $this->assertCount(2, $sections);
        $this->assertSame(50, $sections[0]['priority']);
        $this->assertSame('<p>HtmlOnly</p>', $sections[0]['html']);
        $this->assertSame('', $sections[0]['title']);
        $this->assertSame(100, $sections[1]['priority']);
        $this->assertSame('Plain', $sections[1]['title']);
        $this->assertSame('', $sections[1]['html']);
    }

    /**
     * Helper must not error out when no hookmanager is set.
     */
    public function testAccountSectionsHookWithoutHookmanagerIsEmpty(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $this->assertSame([], HookHelper::runAccountSectionsHook(['user_id' => 7]));
    }

    // ---------------------------------------------------------------------
    // Test helpers
    // ---------------------------------------------------------------------

    /**
     * Build a minimal hookmanager double matching the interface SmartAuth uses.
     *
     * @param callable $callback Receives ($hook, $params, &$object, $action) and returns int
     * @return object
     */
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
