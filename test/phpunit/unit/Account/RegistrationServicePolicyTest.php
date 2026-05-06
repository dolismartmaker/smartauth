<?php

/**
 * Pure-policy tests for RegistrationService that don't need Dolibarr.
 *
 * Covers:
 *  - password policy (12 chars, mixed case, digits)
 *  - email-format validation
 *  - emailAlreadyKnown lookup behavior on the DB
 *
 * Tests that exercise thirdparty/contact/user creation against a real
 * Dolibarr install live in test/phpunit/integration-dolibarr/.
 *
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\Account\RegistrationService;
use SmartAuth\Tests\Mocks\MockDatabase;

class RegistrationServicePolicyTest extends TestCase
{
    public function testPasswordTooShort(): void
    {
        $this->assertFalse(RegistrationService::isPasswordStrongEnough('Aa1aa'));
    }

    public function testPasswordMissingDigit(): void
    {
        $this->assertFalse(RegistrationService::isPasswordStrongEnough('SuperLongPassword'));
    }

    public function testPasswordMissingUppercase(): void
    {
        $this->assertFalse(RegistrationService::isPasswordStrongEnough('superlong1pass'));
    }

    public function testPasswordMissingLowercase(): void
    {
        $this->assertFalse(RegistrationService::isPasswordStrongEnough('SUPERLONG1PASS'));
    }

    public function testPasswordValidWhenMixedAndLongEnough(): void
    {
        $this->assertTrue(RegistrationService::isPasswordStrongEnough('SuperLong1Pass'));
    }

    public function testStartRegistrationRejectsInvalidEmail(): void
    {
        $db = new MockDatabase();
        $service = new RegistrationService($db, function () {
            return true;
        });

        $result = $service->startRegistration(
            'not-an-email',
            'SuperLong1Pass',
            'Marie',
            'Dupont',
            null,
            '127.0.0.1'
        );

        $this->assertSame(['error' => RegistrationService::ERR_INVALID_EMAIL], $result);
    }

    public function testStartRegistrationRejectsWeakPassword(): void
    {
        $db = new MockDatabase();
        $service = new RegistrationService($db, function () {
            return true;
        });

        $result = $service->startRegistration(
            'marie@example.com',
            'too-short',
            'Marie',
            'Dupont',
            null,
            '127.0.0.1'
        );

        $this->assertSame(['error' => RegistrationService::ERR_WEAK_PASSWORD], $result);
    }

    public function testStartRegistrationRejectsWhenEmailAlreadyKnown(): void
    {
        $db = new MockDatabase();
        // First lookup (llx_user.email) returns a row -> taken
        $db->setQueryResult(true, [['1' => 1]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $result = $service->startRegistration(
            'marie@example.com',
            'SuperLong1Pass',
            'Marie',
            'Dupont',
            null,
            '127.0.0.1'
        );

        $this->assertSame(['error' => RegistrationService::ERR_EMAIL_TAKEN], $result);
    }

    public function testEmailAlreadyKnownChecksUserAndContactTables(): void
    {
        $db = new MockDatabase();
        // user lookup misses, contact lookup hits
        $db->setQueryResult(true, [])    // user table
            ->setQueryResult(true, [['1' => 1]]); // socpeople table

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertTrue($service->emailAlreadyKnown('marie@example.com'));

        $queries = $db->getExecutedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString(MAIN_DB_PREFIX . 'user', $queries[0]);
        $this->assertStringContainsString(MAIN_DB_PREFIX . 'socpeople', $queries[1] ?? '');
    }

    public function testEmailAlreadyKnownReturnsFalseWhenAbsentFromBothTables(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true, [])->setQueryResult(true, []);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->emailAlreadyKnown('marie@example.com'));
    }
}
