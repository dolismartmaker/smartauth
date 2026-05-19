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

/**
 * @covers \SmartAuth\DolibarrMapping\dmBase
 */
class DmMappingClassesTest extends DolibarrRealTestCase
{
    /**
     * List of all dmMapping classes to test
     * Format: [className => [expectedType, hasLinesSupport, aliasName]]
     */
    private static $mappingClasses = [
        'dmAgendaEvent' => ['object', false, 'dmActionComm'],
        'dmBank' => ['object', false, 'dmAccountLine'],
        'dmBankAccount' => ['object', false, 'dmAccount'],
        'dmBom' => ['object', true, null],
        'dmCactiontype' => ['dict', false, null],
        'dmCategory' => ['object', false, null],
        'dmCavailability' => ['dict', false, null],
        'dmCcivility' => ['dict', false, null],
        'dmCcountry' => ['dict', false, null],
        'dmCincoterm' => ['dict', false, null],
        'dmCompanyBankAccount' => ['object', false, null],
        'dmContact' => ['object', false, null],
        'dmContract' => ['object', true, 'dmContrat'],
        'dmCpaymentterm' => ['dict', false, null],
        'dmCpaymenttype' => ['dict', false, null],
        'dmCprospectstatus' => ['dict', false, null],
        'dmCshipmentmode' => ['dict', false, null],
        'dmCstate' => ['dict', false, null],
        'dmCstcomm' => ['dict', false, null],
        'dmCticketcategory' => ['dict', false, null],
        'dmCticketresolution' => ['dict', false, null],
        'dmCticketseverity' => ['dict', false, null],
        'dmCtickettype' => ['dict', false, null],
        'dmCtypecontact' => ['dict', false, null],
        'dmCtypent' => ['dict', false, null],
        'dmCunits' => ['dict', false, null],
        'dmDeliveryNote' => ['object', false, 'dmLivraison'],
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
        'dmStockMovement' => ['object', false, 'dmMouvementStock'],
        'dmSubscription' => ['object', false, null],
        'dmSupplier' => ['object', false, 'dmFournisseur'],
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

        // class_uses() returns only traits used DIRECTLY by the class, not
        // those used by its parents. dmSupplier extends dmThirdparty and
        // inherits dmTrait via the parent; walking the inheritance chain
        // makes the check honest for sub-classed mappers.
        $traits = [];
        $cursor = $fullClassName;
        while ($cursor) {
            $traits = array_merge($traits, class_uses($cursor) ?: []);
            $cursor = get_parent_class($cursor);
        }

        $this->assertContains(
            'SmartAuth\\DolibarrMapping\\dmTrait',
            $traits,
            "$className should use dmTrait (directly or via inheritance)"
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
     * Test that object mappers explicitly declare $dolibarrClassName.
     *
     * Implicit deduction from the mapper class name (dmXxx -> Xxx) is no
     * longer supported because it masks bugs when the Dolibarr class name
     * differs (e.g. dmThirdparty should map to Societe, not Thirdparty).
     * See documentation/MAPPERS_CONVENTIONS.md.
     *
     * @dataProvider objectClassProvider
     */
    public function testObjectClassDeclaresDolibarrClassNameExplicitly(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $this->assertTrue(
            $reflection->hasProperty('dolibarrClassName'),
            "$className must declare 'protected \$dolibarrClassName = \"XxxDolibarrClass\";'"
        );

        $prop = $reflection->getProperty('dolibarrClassName');
        $prop->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $declaredValue = $prop->getValue($instance);

        $this->assertNotEmpty(
            $declaredValue,
            "$className declares \$dolibarrClassName but it is empty/null. "
            . "Set it explicitly (e.g. protected \$dolibarrClassName = 'Facture';)."
        );

        $this->assertIsString(
            $declaredValue,
            "$className: \$dolibarrClassName must be a string, got " . gettype($declaredValue)
        );
    }

    /**
     * Test that the Dolibarr class declared by an object mapper actually exists.
     *
     * Catches missing require_once or typos in $dolibarrClassName. Would have
     * caught the original dmThirdparty -> Thirdparty bug (Thirdparty class
     * does not exist in Dolibarr; the real class is Societe).
     *
     * @dataProvider objectClassProvider
     */
    public function testObjectClassReferencesValidDolibarrClass(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        $prop = $reflection->getProperty('dolibarrClassName');
        $prop->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $declaredValue = $prop->getValue($instance);

        $this->assertTrue(
            class_exists($declaredValue),
            "$className declares \$dolibarrClassName = '$declaredValue' but this class does not exist. "
            . "Add the matching 'require_once DOL_DOCUMENT_ROOT . \"/.../xxx.class.php\";' at the top of the mapper file."
        );
    }

    /**
     * Test that no mapper misuses $parentClassName as a substitute for
     * $dolibarrClassName.
     *
     * $parentClassName is only meaningful for sub-objects / lines
     * (e.g. a hypothetical dmFichinterLigne with parentClassName='Fichinter').
     * Declaring $parentClassName equal to $dolibarrClassName is meaningless
     * (an object cannot be its own parent) and indicates the SmartPOS-style
     * misuse where the author put the Dolibarr class name in the wrong field.
     *
     * @dataProvider mappingClassProvider
     */
    public function testParentClassNameNotEqualToDolibarrClassName(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;
        $reflection = new ReflectionClass($fullClassName);

        if (!$reflection->hasProperty('parentClassName')) {
            $this->assertTrue(true);
            return;
        }

        $parentProp = $reflection->getProperty('parentClassName');
        $parentProp->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $parentValue = $parentProp->getValue($instance);

        if (empty($parentValue)) {
            $this->assertTrue(true);
            return;
        }

        $dolibarrProp = $reflection->getProperty('dolibarrClassName');
        $dolibarrProp->setAccessible(true);
        $dolibarrValue = $dolibarrProp->getValue($instance);

        $this->assertNotEquals(
            $dolibarrValue,
            $parentValue,
            "$className: \$parentClassName ('$parentValue') cannot equal \$dolibarrClassName. "
            . "\$parentClassName is reserved for sub-objects (e.g. FichinterLigne -> Fichinter). "
            . "For a top-level mapper, remove \$parentClassName entirely."
        );
    }

    /**
     * Test that dmMapping object classes can be instantiated
     *
     * This test verifies that object classes can be instantiated without errors.
     * Recursion depth limiting in dmTrait::exportData() prevents segfaults
     * from circular FK references.
     *
     * @dataProvider objectClassProvider
     * @group slow
     */
    public function testObjectClassCanBeInstantiated(string $className): void
    {
        $fullClassName = 'SmartAuth\\DolibarrMapping\\' . $className;

        $instance = new $fullClassName();

        $this->assertInstanceOf(
            'SmartAuth\\DolibarrMapping\\dmBase',
            $instance,
            "$className should be an instance of dmBase"
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
            if ($config[0] === 'dict') {
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
