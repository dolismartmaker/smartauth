<?php

/**
 * Tests for all dmMapping classes
 *
 * This test file validates the structure and basic functionality of all
 * dmMapping classes without requiring full Dolibarr object instantiation.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use ReflectionClass;

class DmMappingClassesTest extends DolibarrRealTestCase
{
    /**
     * List of all dmMapping classes to test
     * Format: [className => [expectedType, hasLinesSupport, aliasName]]
     */
    private static $mappingClasses = [
        'dmAgendaEvent' => ['object', false, 'dmActionComm'],
        'dmBom' => ['object', true, null],
        'dmCactiontype' => ['dictionary', false, null],
        'dmCategory' => ['object', false, null],
        'dmCavailability' => ['dictionary', false, null],
        'dmCcivility' => ['dict', false, null],
        'dmCcountry' => ['dict', false, null],
        'dmCincoterm' => ['dictionary', false, null],
        'dmContact' => ['object', false, null],
        'dmContract' => ['object', true, 'dmContrat'],
        'dmCpaymentterm' => ['dictionary', false, null],
        'dmCpaymenttype' => ['dictionary', false, null],
        'dmCprospectstatus' => ['dictionary', false, null],
        'dmCshipmentmode' => ['dictionary', false, null],
        'dmCstate' => ['dict', false, null],
        'dmCstcomm' => ['dictionary', false, null],
        'dmCticketcategory' => ['dictionary', false, null],
        'dmCticketresolution' => ['dictionary', false, null],
        'dmCticketseverity' => ['dictionary', false, null],
        'dmCtickettype' => ['dictionary', false, null],
        'dmCtypecontact' => ['dictionary', false, null],
        'dmCtypent' => ['dictionary', false, null],
        'dmCunits' => ['dictionary', false, null],
        'dmDonation' => ['object', false, null],
        'dmExpenseReport' => ['object', true, null],
        'dmIntervention' => ['object', true, 'dmFichinter'],
        'dmInvoice' => ['object', true, 'dmFacture'],
        'dmMember' => ['object', false, 'dmAdherent'],
        'dmMemberType' => ['object', false, null],
        'dmMo' => ['object', true, null],
        'dmMulticurrency' => ['object', false, null],
        'dmOrder' => ['object', true, 'dmCommande'],
        'dmProduct' => ['object', false, null],
        'dmProject' => ['object', false, null],
        'dmProposal' => ['object', true, 'dmPropal'],
        'dmReception' => ['object', true, null],
        'dmShipment' => ['object', true, 'dmExpedition'],
        'dmSubscription' => ['object', false, null],
        'dmSupplierInvoice' => ['object', true, 'dmFactureFournisseur'],
        'dmSupplierOrder' => ['object', true, 'dmCommandeFournisseur'],
        'dmSupplierProposal' => ['object', true, null],
        'dmTask' => ['object', false, null],
        'dmThirdparty' => ['object', false, 'dmSociete'],
        'dmTicket' => ['object', false, null],
        'dmUser' => ['object', false, null],
        'dmWarehouse' => ['object', false, 'dmEntrepot'],
    ];

    /**
     * Test that all dmMapping classes exist
     *
     * @dataProvider mappingClassProvider
     */
    public function testClassExists(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $this->assertTrue(
            class_exists($fullClassName),
            "Class $fullClassName should exist"
        );
    }

    /**
     * Test that all dmMapping classes extend dmBase
     *
     * @dataProvider mappingClassProvider
     */
    public function testClassExtendsDmBase(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $this->assertTrue(
            $reflection->isSubclassOf('SmartAuth\\DolibarrMapping\\dmBase'),
            "$className should extend dmBase"
        );
    }

    /**
     * Test that all dmMapping classes use dmTrait
     *
     * @dataProvider mappingClassProvider
     */
    public function testClassUsesDmTrait(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $traits = class_uses($fullClassName);

        $this->assertContains(
            'SmartAuth\\DolibarrMapping\\dmTrait',
            $traits,
            "$className should use dmTrait"
        );
    }

    /**
     * Test that all dmMapping classes have type property
     *
     * @dataProvider mappingClassProvider
     */
    public function testClassHasTypeProperty(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $this->assertTrue(
            $reflection->hasProperty('type'),
            "$className should have type property"
        );

        $property = $reflection->getProperty('type');
        $property->setAccessible(true);

        // Create instance without calling constructor
        $instance = $reflection->newInstanceWithoutConstructor();
        $type = $property->getValue($instance);

        $expectedType = self::$mappingClasses[$className][0];
        $this->assertEquals(
            $expectedType,
            $type,
            "$className type should be '$expectedType'"
        );
    }

    /**
     * Test that all dmMapping classes have listOfPublishedFields property
     *
     * @dataProvider mappingClassProvider
     */
    public function testClassHasListOfPublishedFields(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $this->assertTrue(
            $reflection->hasProperty('listOfPublishedFields'),
            "$className should have listOfPublishedFields property"
        );

        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $fields = $property->getValue($instance);

        $this->assertIsArray($fields, "$className listOfPublishedFields should be array");
        $this->assertNotEmpty($fields, "$className listOfPublishedFields should not be empty");
    }

    /**
     * Test that object classes have id field mapping
     *
     * @dataProvider objectClassProvider
     */
    public function testObjectClassHasIdMapping(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $fields = $property->getValue($instance);

        // Object classes should map either 'rowid' or 'id' to 'id'
        $hasIdMapping = (isset($fields['rowid']) && $fields['rowid'] === 'id')
            || (isset($fields['id']) && $fields['id'] === 'id');

        $this->assertTrue($hasIdMapping, "$className should have rowid or id mapped to 'id'");
    }

    /**
     * Test that classes with lines support use dmLinesTrait
     *
     * @dataProvider linesClassProvider
     */
    public function testLinesClassUsesDmLinesTrait(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $traits = class_uses($fullClassName);

        $this->assertContains(
            'SmartAuth\\DolibarrMapping\\dmLinesTrait',
            $traits,
            "$className should use dmLinesTrait"
        );
    }

    /**
     * Test that classes with lines support have listOfPublishedFieldsForLines
     *
     * @dataProvider linesClassProvider
     */
    public function testLinesClassHasPublishedFieldsForLines(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $this->assertTrue(
            $reflection->hasProperty('listOfPublishedFieldsForLines'),
            "$className should have listOfPublishedFieldsForLines property"
        );
    }

    /**
     * Test that class aliases exist
     *
     * @dataProvider aliasClassProvider
     */
    public function testClassAliasExists(string $className, string $aliasName): void
    {
        $fullAliasName = 'SmartAuth\\DolibarrMapping\\' . $aliasName;
        $this->assertTrue(
            class_exists($fullAliasName),
            "Alias $fullAliasName should exist for $className"
        );
    }

    /**
     * Test that dictionary classes have code field
     *
     * @dataProvider dictionaryClassProvider
     */
    public function testDictionaryClassHasCodeField(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $fields = $property->getValue($instance);

        // Most dictionary classes have code field
        $hasCodeOrLabel = isset($fields['code']) || isset($fields['label']) || isset($fields['libelle']);
        $this->assertTrue(
            $hasCodeOrLabel,
            "$className dictionary should have code, label or libelle field"
        );
    }

    /**
     * Data provider for all mapping classes
     */
    public static function mappingClassProvider(): array
    {
        $data = [];
        foreach (array_keys(self::$mappingClasses) as $className) {
            $data[$className] = [$className];
        }
        return $data;
    }

    /**
     * Data provider for classes with lines support
     */
    public static function linesClassProvider(): array
    {
        $data = [];
        foreach (self::$mappingClasses as $className => $config) {
            if ($config[1] === true) {
                $data[$className] = [$className];
            }
        }
        return $data;
    }

    /**
     * Data provider for classes with aliases
     */
    public static function aliasClassProvider(): array
    {
        $data = [];
        foreach (self::$mappingClasses as $className => $config) {
            if ($config[2] !== null) {
                $data[$className] = [$className, $config[2]];
            }
        }
        return $data;
    }

    /**
     * Data provider for dictionary classes
     */
    public static function dictionaryClassProvider(): array
    {
        $data = [];
        foreach (self::$mappingClasses as $className => $config) {
            if ($config[0] === 'dictionary') {
                $data[$className] = [$className];
            }
        }
        return $data;
    }

    /**
     * Data provider for object classes (non-dictionary)
     */
    public static function objectClassProvider(): array
    {
        $data = [];
        foreach (self::$mappingClasses as $className => $config) {
            if ($config[0] === 'object') {
                $data[$className] = [$className];
            }
        }
        return $data;
    }
}
