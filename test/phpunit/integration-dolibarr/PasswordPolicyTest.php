<?php

/**
 * Integration tests for PasswordPolicy.
 *
 * Proves the module applies the password rules CONFIGURED IN DOLIBARR
 * (Home > Setup > Security, constant USER_PASSWORD_GENERATED) rather than a
 * module-local policy, and falls back to a sane baseline when no generator is
 * configured. Requires a real Dolibarr because it loads the core generator
 * modules (modGeneratePass*).
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\PasswordPolicy;

/**
 * @covers \SmartAuth\Api\PasswordPolicy
 */
class PasswordPolicyTest extends DolibarrRealTestCase
{
    protected function tearDown(): void
    {
        global $conf;
        unset($conf->global->USER_PASSWORD_GENERATED);
        parent::tearDown();
    }

    /**
     * With USER_PASSWORD_GENERATED=standard, Dolibarr enforces a 12-char
     * minimum WITHOUT a complexity requirement. A 12-char all-lowercase
     * password must therefore be accepted - which the module baseline (needs
     * upper + digit) would reject. This is the proof the policy delegates to
     * the Dolibarr-configured generator, not to its own rules.
     */
    public function testHonorsDolibarrStandardGenerator(): void
    {
        global $conf;
        $conf->global->USER_PASSWORD_GENERATED = 'standard';

        // 12 lowercase chars: passes the standard generator (length only),
        // but would fail the baseline (no uppercase, no digit).
        $accepted = PasswordPolicy::validate('abcdefghijkl');
        $this->assertTrue(
            $accepted['valid'],
            'standard generator (length>=12 only) must accept a 12-char lowercase password'
        );

        // Too short: rejected by the standard generator.
        $rejected = PasswordPolicy::validate('Short1');
        $this->assertFalse($rejected['valid'], 'standard generator must reject a 6-char password');
        $this->assertNotSame('', $rejected['message']);
    }

    /**
     * When no generator is configured, the built-in baseline applies: at least
     * 12 chars mixing upper/lower/digit.
     */
    public function testFallsBackToBaselineWhenNoGeneratorConfigured(): void
    {
        global $conf;
        unset($conf->global->USER_PASSWORD_GENERATED);

        // Accepted by 'standard' (length only) but the baseline requires
        // upper + digit, so this must be rejected when no generator is set.
        $this->assertFalse(
            PasswordPolicy::validate('abcdefghijkl')['valid'],
            'baseline must reject a 12-char password without uppercase/digit'
        );

        // Too short for the baseline.
        $this->assertFalse(PasswordPolicy::validate('Aa1aaaa')['valid']);

        // Long enough and mixed: accepted by the baseline.
        $this->assertTrue(PasswordPolicy::validate('SuperLong1Pass')['valid']);
    }

    /**
     * A reset that goes through PasswordResetController must enforce the same
     * configured policy: a too-weak password is rejected end-to-end.
     */
    public function testConfirmResetRejectsPasswordBelowConfiguredPolicy(): void
    {
        global $conf;
        $conf->global->USER_PASSWORD_GENERATED = 'standard';

        $controller = new \SmartAuth\Api\PasswordResetController();
        // Bogus token: validation order rejects the weak password first (400)
        // before any token lookup, which is exactly the policy gate we assert.
        $result = $controller->confirmReset([
            'token' => 'irrelevant-token',
            'email' => 'someone@example.test',
            'password' => 'short',
        ]);

        $this->assertSame(400, $result[1], 'a sub-policy password must be refused at reset');
    }
}
