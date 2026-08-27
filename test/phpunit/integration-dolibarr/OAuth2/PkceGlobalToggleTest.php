<?php

/**
 * Integration tests for the global PKCE toggle enforcement.
 *
 * SMARTAUTH_OAUTH_REQUIRE_PKCE is written by the admin OAuth setup screen
 * but used to be read by no runtime code: checking the box enforced
 * nothing (audit S-10). validatePKCE() must honor it on top of the
 * per-client flag.
 *
 * @covers \SmartAuth\Api\OAuth2\AuthorizationController::validatePKCE
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Api\OAuth2\AuthorizationController;
use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;

class PkceGlobalToggleTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $conf;
        if (!isset($conf->global) || !is_object($conf->global)) {
            $conf->global = new \stdClass();
        }
    }

    private function invokeValidatePkce($client, ?string $challenge): bool
    {
        $controller = new AuthorizationController($this->db);
        $method = new \ReflectionMethod(AuthorizationController::class, 'validatePKCE');
        $method->setAccessible(true);
        return (bool) $method->invoke($controller, $client, $challenge, 'S256');
    }

    private function makeClient(int $requirePkce): \SmartAuthOAuthClient
    {
        $client = new \SmartAuthOAuthClient($this->db);
        // A bare instance has is_confidential unset, which isConfidential()
        // reads as "public" -> structurally PKCE. The confidential case is
        // the one the global toggle is about.
        $client->is_confidential = 1;
        $client->require_pkce = $requirePkce;
        return $client;
    }

    /**
     * A confidential client that does not require PKCE itself must still
     * be refused a challenge-less flow when the instance toggle is ON.
     */
    public function testGlobalToggleForcesPkceOnNonPkceClient(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_REQUIRE_PKCE = 1;

        $this->assertFalse(
            $this->invokeValidatePkce($this->makeClient(0), null),
            'with the global toggle ON, a client without challenge must be refused'
        );
    }

    /**
     * Same client, toggle OFF: challenge-less flows stay allowed (default
     * behaviour for confidential clients opted out of PKCE).
     */
    public function testToggleOffKeepsNonPkceClientAllowed(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_REQUIRE_PKCE = 0;

        $this->assertTrue(
            $this->invokeValidatePkce($this->makeClient(0), null),
            'with the toggle OFF and the client not asking for PKCE, no challenge is required'
        );
    }

    /**
     * The per-client flag keeps precedence regardless of the toggle.
     */
    public function testClientFlagStillForcesPkceWithToggleOff(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_REQUIRE_PKCE = 0;

        $this->assertFalse(
            $this->invokeValidatePkce($this->makeClient(1), null),
            'a client flagged require_pkce must be refused without a challenge, toggle or not'
        );
    }
}
