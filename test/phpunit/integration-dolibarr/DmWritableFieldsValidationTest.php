<?php

/**
 * Boot-time validation of $writableFields against $listOfPublishedFields.
 *
 * Locks down the recurring bug pattern observed on 3 Dolipocket mappers
 * (dmThirdParty.nom, dmContact.civility_code, dmAgenda 4 entries) where
 * $writableFields was declared with API-side names instead of
 * Dolibarr-side keys, making importMappedData() silently reject every
 * input. The validation lives in dmTrait::_validateDeclaration() and
 * runs at every mapper boot, so it protects all consumers (smartauth
 * core + module-local mappers in dolipocket, smartpos, etc.).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmBase;
use SmartAuth\DolibarrMapping\dmTrait;

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

/**
 * Fixture: every writable entry points to a doliside key. Must boot clean.
 */
class FixtureMapperWritableOk extends dmBase
{
    use dmTrait;

    protected $type = "object";
    protected $dolibarrClassName = 'Societe';

    protected $listOfPublishedFields = [
        'rowid' => 'id',
        'nom'   => 'name',
        'email' => 'email',
    ];

    protected $writableFields = [
        'nom',
        'email',
    ];
}

/**
 * Fixture: writable entry uses an api-side name ('name' instead of 'nom').
 * Must throw at boot.
 */
class FixtureMapperWritableBugApiSide extends dmBase
{
    use dmTrait;

    protected $type = "object";
    protected $dolibarrClassName = 'Societe';

    protected $listOfPublishedFields = [
        'rowid' => 'id',
        'nom'   => 'name',
        'email' => 'email',
    ];

    protected $writableFields = [
        'name',
        'email',
    ];
}

/**
 * Fixture: writable entry is just unknown (typo / dead field).
 * Must throw at boot too -- same root cause from the consumer's view.
 */
class FixtureMapperWritableUnknown extends dmBase
{
    use dmTrait;

    protected $type = "object";
    protected $dolibarrClassName = 'Societe';

    protected $listOfPublishedFields = [
        'rowid' => 'id',
        'nom'   => 'name',
    ];

    protected $writableFields = [
        'nom',
        'completely_unknown_field',
    ];
}

/**
 * Fixture: several invalid entries at once. Used to assert that the
 * LogicException message lists ALL offenders, not just the first one.
 */
class FixtureMapperWritableMultipleBugs extends dmBase
{
    use dmTrait;

    protected $type = "object";
    protected $dolibarrClassName = 'Societe';

    protected $listOfPublishedFields = [
        'rowid' => 'id',
        'nom'   => 'name',
        'email' => 'email',
    ];

    protected $writableFields = [
        'name',
        'email_addr',
        'phone_number',
    ];
}

/**
 * @covers \SmartAuth\DolibarrMapping\dmTrait::_validateDeclaration
 */
class DmWritableFieldsValidationTest extends DolibarrRealTestCase
{
    /**
     * Every object mapper shipped in smartauth/dolMapping/ must boot
     * without throwing. If a future commit introduces the bug pattern
     * on any shipped mapper, this test reports the offending class name
     * and the LogicException message verbatim.
     *
     * @dataProvider shippedObjectMapperProvider
     */
    public function testShippedMapperBootsWithoutThrowing(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;

        try {
            new $fullClassName();
        } catch (\LogicException $e) {
            $this->fail(
                "$className failed to boot with LogicException: " . $e->getMessage()
            );
        }

        $this->assertTrue(true, "$className booted successfully");
    }

    public function shippedObjectMapperProvider(): array
    {
        // Object mappers from DmMappingClassesTest::$mappingClasses.
        // Dictionary mappers are excluded (no writable fields, no
        // Dolibarr CommonObject backing them).
        $names = [
            'dmAgendaEvent',
            'dmBom',
            'dmCategory',
            'dmContact',
            'dmContract',
            'dmDonation',
            'dmExpenseReport',
            'dmIntervention',
            'dmInvoice',
            'dmMember',
            'dmMemberType',
            'dmMo',
            'dmMulticurrency',
            'dmOrder',
            'dmProduct',
            'dmProject',
            'dmProposal',
            'dmReception',
            'dmShipment',
            'dmSubscription',
            'dmSupplierInvoice',
            'dmSupplierOrder',
            'dmSupplierProposal',
            'dmTask',
            'dmThirdparty',
            'dmTicket',
            'dmUser',
            'dmWarehouse',
        ];

        return array_map(fn($n) => [$n], $names);
    }

    public function testFixtureWithCoherentWritableFieldsBootsCleanly(): void
    {
        $mapper = new FixtureMapperWritableOk();
        $this->assertInstanceOf(FixtureMapperWritableOk::class, $mapper);
    }

    public function testFixtureWithApiSideWritableFieldThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\$writableFields contains entries that are not Dolibarr-side keys/');
        $this->expectExceptionMessageMatches("/'name'/");

        new FixtureMapperWritableBugApiSide();
    }

    public function testFixtureWithUnknownWritableFieldThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches("/'completely_unknown_field'/");

        new FixtureMapperWritableUnknown();
    }

    public function testExceptionMessageListsAllInvalidEntries(): void
    {
        try {
            new FixtureMapperWritableMultipleBugs();
            $this->fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("'name'", $message);
            $this->assertStringContainsString("'email_addr'", $message);
            $this->assertStringContainsString("'phone_number'", $message);
        }
    }
}
