<?php

/**
 * Integration tests for RegistrationGate, the self-registration kill switch.
 *
 * RegistrationGate is the single source of truth shared by the front
 * controller (public/index.php routing guard) and the landing page. These
 * tests pin down its decision logic against the real Dolibarr conf so the
 * SMARTAUTH_REGISTRATION_ENABLED constant reliably opens/closes the
 * /register* surface.
 *
 * @covers \SmartAuth\Api\Account\RegistrationGate
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\Account\RegistrationGate;

dol_include_once('/smartauth/api/Account/RegistrationGate.php');

class RegistrationGateTest extends DolibarrRealTestCase
{
    protected function tearDown(): void
    {
        // Never leak the toggle into sibling tests sharing the conf.
        global $conf;
        unset($conf->global->SMARTAUTH_REGISTRATION_ENABLED);
        parent::tearDown();
    }

    public function testDisabledByDefaultWhenConstantUnset(): void
    {
        global $conf;
        unset($conf->global->SMARTAUTH_REGISTRATION_ENABLED);

        $this->assertFalse(
            RegistrationGate::isEnabled(),
            'Self-registration is opt-in: it must default to disabled until an admin enables it'
        );
    }

    public function testDisabledWhenConstantZero(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_REGISTRATION_ENABLED = 0;

        $this->assertFalse(RegistrationGate::isEnabled());
    }

    public function testEnabledWhenConstantOne(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_REGISTRATION_ENABLED = 1;

        $this->assertTrue(RegistrationGate::isEnabled());
    }

    /**
     * @dataProvider registrationPathProvider
     */
    public function testIsRegistrationPathMatchesTheGuardedSurface(string $path, bool $expected): void
    {
        $this->assertSame($expected, RegistrationGate::isRegistrationPath($path));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function registrationPathProvider(): array
    {
        return [
            '/register'          => ['/register', true],
            '/register/confirm'  => ['/register/confirm', true],
            '/register/resend'   => ['/register/resend', true],
            '/lookup-by-email'   => ['/lookup-by-email', true],
            '/login is not gated' => ['/login', false],
            '/account is not gated' => ['/account', false],
            'root is not gated'  => ['/', false],
            'unknown path'       => ['/register/nope', false],
        ];
    }
}
