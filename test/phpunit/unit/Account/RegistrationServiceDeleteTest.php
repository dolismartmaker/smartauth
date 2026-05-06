<?php

/**
 * Unit tests for RegistrationService::deleteSelfServiceAccount and
 * isThirdpartyDeletableProspect (the parts that don't need Dolibarr).
 *
 * @covers \SmartAuth\Api\Account\RegistrationService
 */

namespace SmartAuth\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\Account\RegistrationService;
use SmartAuth\Tests\Mocks\MockDatabase;

class RegistrationServiceDeleteTest extends TestCase
{
    public function testDeleteRefusedForInvalidUserId(): void
    {
        $db = new MockDatabase();
        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertSame(
            RegistrationService::ERR_USER_NOT_FOUND,
            $service->deleteSelfServiceAccount(0)
        );
    }

    public function testIsThirdpartyDeletableProspectFalseWhenIdInvalid(): void
    {
        $db = new MockDatabase();
        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->isThirdpartyDeletableProspect(0));
        $this->assertFalse($service->isThirdpartyDeletableProspect(-1));
    }

    public function testIsThirdpartyDeletableProspectFalseWhenClient(): void
    {
        $db = new MockDatabase();
        // SELECT client FROM societe -> client=1
        $db->setQueryResult(true, [['client' => 1]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->isThirdpartyDeletableProspect(99));
    }

    public function testIsThirdpartyDeletableProspectFalseWhenContractsExist(): void
    {
        $db = new MockDatabase();
        // societe: client=0
        $db->setQueryResult(true, [['client' => 0]]);
        // contrat count: > 0
        $db->setQueryResult(true, [['n' => 2]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->isThirdpartyDeletableProspect(99));
    }

    public function testIsThirdpartyDeletableProspectFalseWhenClientAndProspect(): void
    {
        $db = new MockDatabase();
        // Dolibarr client=3 = customer + prospect (bit 1 is set -> blocked)
        $db->setQueryResult(true, [['client' => 3]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->isThirdpartyDeletableProspect(99));
    }

    public function testIsThirdpartyDeletableProspectTrueWhenProspectOnly(): void
    {
        $db = new MockDatabase();
        // Dolibarr client=2 = prospect only (no customer bit)
        $db->setQueryResult(true, [['client' => 2]]);
        // contrat count: 0
        $db->setQueryResult(true, [['n' => 0]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertTrue($service->isThirdpartyDeletableProspect(99));
    }

    public function testIsThirdpartyDeletableProspectTrueWhenProspectAndNoContract(): void
    {
        $db = new MockDatabase();
        // societe: client=0
        $db->setQueryResult(true, [['client' => 0]]);
        // contrat count: 0
        $db->setQueryResult(true, [['n' => 0]]);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertTrue($service->isThirdpartyDeletableProspect(99));
    }

    public function testIsThirdpartyDeletableProspectFalseOnSqlFailure(): void
    {
        $db = new MockDatabase();
        // societe: query fails
        $db->setQueryResult(false);

        $service = new RegistrationService($db, function () {
            return true;
        });

        $this->assertFalse($service->isThirdpartyDeletableProspect(99));
    }
}
