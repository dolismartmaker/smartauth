<?php

/**
 * Unit tests for RegistrationService methods that don't need a real
 * Dolibarr install (lookup, resend cooldown, confirm with invalid token,
 * enumeration mitigation).
 *
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\Account\RegistrationService;
use SmartAuth\Tests\Mocks\MockDatabase;

class RegistrationServiceFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $conf;
        $conf = new \stdClass();
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.example.com';
        $conf->global->SMARTAUTH_REGISTER_TOKEN_TTL = 86400;
        $conf->global->SMARTAUTH_REGISTER_RESEND_COOLDOWN = 300;
        $GLOBALS['conf'] = $conf;
    }

    // ---------------------------------------------------------------------
    // confirmRegistration
    // ---------------------------------------------------------------------

    public function testConfirmRegistrationRejectsEmptyToken(): void
    {
        $db = new MockDatabase();
        $service = new RegistrationService($db, function () {
            return true;
        });

        $result = $service->confirmRegistration('');

        $this->assertSame(['error' => RegistrationService::ERR_TOKEN_INVALID], $result);
    }

    public function testConfirmRegistrationRejectsUnknownToken(): void
    {
        $db = new MockDatabase();
        // findActive() => empty
        $db->setQueryResult(true, []);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $result = $service->confirmRegistration('opaque-token');

        $this->assertSame(['error' => RegistrationService::ERR_TOKEN_INVALID], $result);
    }

    // ---------------------------------------------------------------------
    // resendConfirmation - enumeration mitigation
    // ---------------------------------------------------------------------

    public function testResendConfirmationAlwaysReturnsTrueForUnknownEmail(): void
    {
        $db = new MockDatabase();
        // fetchInactiveUserByEmail -> nothing
        $db->setQueryResult(true, []);

        $sent = false;
        $service = new RegistrationService($db, function () use (&$sent) {
            $sent = true;
            return true;
        });

        $result = $service->resendConfirmation('unknown@example.com', '127.0.0.1');

        $this->assertTrue($result);
        $this->assertFalse($sent, 'No email should be sent for an unknown address');
    }

    public function testResendConfirmationAlwaysReturnsTrueForInvalidEmailFormat(): void
    {
        $db = new MockDatabase();
        $sent = false;
        $service = new RegistrationService($db, function () use (&$sent) {
            $sent = true;
            return true;
        });

        $this->assertTrue($service->resendConfirmation('not-an-email', '127.0.0.1'));
        $this->assertFalse($sent);
    }

    public function testResendConfirmationCooldownSkipsResend(): void
    {
        $db = new MockDatabase();
        // fetchInactiveUserByEmail -> match
        $db->setQueryResult(true, [[
            'rowid' => 7,
            'login' => 'marie',
            'firstname' => 'Marie',
            'lastname' => 'Dupont',
        ]]);
        // lastActiveDatec -> recent (within cooldown window)
        $db->setQueryResult(true, [['last_datec' => date('Y-m-d H:i:s', time() - 60)]]);

        $sent = false;
        $service = new RegistrationService($db, function () use (&$sent) {
            $sent = true;
            return true;
        });

        $this->assertTrue($service->resendConfirmation('marie@example.com', '127.0.0.1'));
        $this->assertFalse($sent, 'Cooldown should suppress the email');
    }

    public function testResendConfirmationOutsideCooldownIssuesNewToken(): void
    {
        $db = new MockDatabase();
        // fetchInactiveUserByEmail -> match
        $db->setQueryResult(true, [[
            'rowid' => 7,
            'login' => 'marie',
            'firstname' => 'Marie',
            'lastname' => 'Dupont',
        ]]);
        // lastActiveDatec -> old (cooldown elapsed)
        $db->setQueryResult(true, [['last_datec' => date('Y-m-d H:i:s', time() - 3600)]]);
        // invalidateActiveForUser -> success
        $db->setQueryResult(true)->setAffectedRows(1);
        // create() -> success
        $db->setQueryResult(true)->setLastInsertId(123);

        $sentTo = null;
        $service = new RegistrationService($db, function ($to, $subject, $text, $html) use (&$sentTo) {
            $sentTo = $to;
            return true;
        });

        $this->assertTrue($service->resendConfirmation('marie@example.com', '127.0.0.1'));
        $this->assertSame('marie@example.com', $sentTo);
    }

    // ---------------------------------------------------------------------
    // lookupByEmail - enumeration mitigation
    // ---------------------------------------------------------------------

    public function testLookupByEmailAlwaysReturnsTrueForUnknownEmail(): void
    {
        $db = new MockDatabase();
        // fetchActiveUserByEmail -> empty
        $db->setQueryResult(true, []);

        $sent = false;
        $service = new RegistrationService($db, function () use (&$sent) {
            $sent = true;
            return true;
        });

        $this->assertTrue($service->lookupByEmail('unknown@example.com', '127.0.0.1'));
        $this->assertFalse($sent, 'No email is sent if no active account matches');
    }

    public function testLookupByEmailAlwaysReturnsTrueForInvalidEmailFormat(): void
    {
        $db = new MockDatabase();
        $sent = false;
        $service = new RegistrationService($db, function () use (&$sent) {
            $sent = true;
            return true;
        });

        $this->assertTrue($service->lookupByEmail('not-an-email', '127.0.0.1'));
        $this->assertFalse($sent);
    }

    public function testLookupByEmailSendsEmailWhenAccountFound(): void
    {
        $db = new MockDatabase();
        // fetchActiveUserByEmail -> match
        $db->setQueryResult(true, [[
            'rowid' => 7,
            'login' => 'marie',
            'firstname' => 'Marie',
            'lastname' => 'Dupont',
        ]]);
        // EmailValidationToken::create -> success
        $db->setQueryResult(true)->setLastInsertId(456);

        $captured = [];
        $service = new RegistrationService($db, function ($to, $subject, $text, $html) use (&$captured) {
            $captured = compact('to', 'subject', 'text', 'html');
            return true;
        });

        $this->assertTrue($service->lookupByEmail('marie@example.com', '127.0.0.1'));
        $this->assertSame('marie@example.com', $captured['to'] ?? null);
        $this->assertStringContainsString('compte', strtolower((string) ($captured['subject'] ?? '')));
        $this->assertStringContainsString('marie', strtolower((string) ($captured['text'] ?? '')));
        // The "add as alternative" link must be present (token was created)
        $this->assertStringContainsString('/email-alternative/confirm', (string) ($captured['html'] ?? ''));
    }
}
