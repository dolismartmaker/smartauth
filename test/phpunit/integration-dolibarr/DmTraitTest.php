<?php

/**
 * Tests for dmTrait
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmBase;
use SmartAuth\DolibarrMapping\dmTrait;
use SmartAuth\DolibarrMapping\dmHelper;
use ReflectionClass;
use stdClass;

/**
 * Test class that uses dmTrait for testing
 */
class TestDmTraitClass extends dmBase
{
    use dmTrait;

    protected $type = "object";

    protected $listOfPublishedFields = [
        'rowid'       => 'id',
        'nom'         => 'name',
        'address'     => 'address',
        'fk_soc'      => 'thirdparty',
        'options_test' => 'test_extra',
    ];

    protected $parentFieldsOverride = [];

    protected $parentTableElementToUseForExtraFields = '';

    protected $parentClassNameForLines = '';

    protected $parentLabelForLines = '';

    protected $parentFieldsForLines = [];

    protected $listOfPublishedFieldsForLines = [];

    public function __construct($db)
    {
        $this->_db = $db;
        $this->_dolmapping = new dmHelper();
        $this->_dolmapclassname = static::class;
        $this->_dolobjectclassname = 'Societe';
        $this->_cacheDesc = new stdClass();
    }

    /**
     * Expose protected method for testing
     */
    public function exposeGetStoragePath($object, $relativepath = true)
    {
        return $this->getStoragePath($object, $relativepath);
    }

    /**
     * Expose protected method for testing
     */
    public function exposeExportMappedData($obj)
    {
        return $this->exportMappedData($obj);
    }

    /**
     * Test filter function for 'nom' field
     */
    public function fieldFilterValueNom($obj, $value)
    {
        return strtoupper($value);
    }
}

class DmTraitTest extends DolibarrRealTestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TestDmTraitClass($this->db);
    }

    /**
     * Test objectType returns correct type
     */
    public function testObjectTypeReturnsCorrectType(): void
    {
        $result = $this->mapper->objectType();
        $this->assertEquals('object', $result);
    }

    /**
     * Test objectDesc returns stdClass
     */
    public function testObjectDescReturnsStdClass(): void
    {
        $result = $this->mapper->objectDesc();
        $this->assertInstanceOf(stdClass::class, $result);
    }

    /**
     * Test exportMappedData with simple object
     */
    public function testExportMappedDataWithSimpleObject(): void
    {
        $obj = new stdClass();
        $obj->rowid = 123;
        $obj->id = 123;
        $obj->nom = 'Test Company';
        $obj->address = '123 Test Street';
        $obj->array_options = [];

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals(123, $result->id);
        $this->assertEquals('TEST COMPANY', $result->name);  // fieldFilterValueNom applies strtoupper
        $this->assertEquals('123 Test Street', $result->address);
    }

    /**
     * Test exportMappedData handles fk_soc to socid conversion
     */
    public function testExportMappedDataHandlesFkSocToSocid(): void
    {
        $obj = new stdClass();
        $obj->rowid = 1;
        $obj->id = 1;
        $obj->socid = 456;  // Dolibarr often changes fk_soc to socid
        $obj->nom = 'Test';
        $obj->address = '';
        $obj->array_options = [];

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    /**
     * Test exportMappedData handles rowid to id conversion
     */
    public function testExportMappedDataHandlesRowidToIdConversion(): void
    {
        $obj = new stdClass();
        $obj->id = 789;  // Only id, no rowid
        $obj->nom = 'Test';
        $obj->address = '';
        $obj->array_options = [];

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertEquals(789, $result->id);
    }

    /**
     * Test exportMappedData with lines
     */
    public function testExportMappedDataWithLines(): void
    {
        $obj = new stdClass();
        $obj->id = 1;
        $obj->nom = 'Test';
        $obj->address = '';
        $obj->array_options = [];

        $line1 = new stdClass();
        $line1->rowid = 1;
        $line1->description = 'Line 1';

        $line2 = new stdClass();
        $line2->rowid = 2;
        $line2->description = 'Line 2';

        $obj->lines = [$line1, $line2];

        // Set up mapper with lines config
        $reflection = new ReflectionClass($this->mapper);
        $linesProperty = $reflection->getProperty('listOfPublishedFieldsForLines');
        $linesProperty->setAccessible(true);
        $linesProperty->setValue($this->mapper, ['rowid' => 'id', 'description' => 'description']);

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertIsArray($result->lines);
        $this->assertCount(2, $result->lines);
        $this->assertEquals(1, $result->lines[0]->id);
        $this->assertEquals('Line 1', $result->lines[0]->description);
    }

    /**
     * Test getStoragePath with element
     */
    public function testGetStoragePathWithElement(): void
    {
        global $conf;

        $obj = new stdClass();
        $obj->parentElementToUseForExtraFields = '';
        $obj->element = 'societe';
        $obj->ref = 'SOC001';
        $obj->entity = 1;

        // Set up conf with proper multidir_output
        $conf->societe = new stdClass();
        $conf->societe->multidir_output = [1 => '/tmp/test_societe'];
        $conf->societe->dir_output = '/tmp/test_societe';

        $result = $this->mapper->exposeGetStoragePath($obj, true);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('societe', $result[1]);
    }

    /**
     * Test getStoragePath with parentElementToUseForExtraFields
     */
    public function testGetStoragePathWithParentElement(): void
    {
        global $conf;

        $obj = new stdClass();
        $obj->parentElementToUseForExtraFields = 'facture';
        $obj->element = 'invoice';
        $obj->ref = 'INV001';
        $obj->entity = 1;

        $conf->facture = new stdClass();
        $conf->facture->multidir_output = [1 => '/tmp/test_facture'];
        $conf->facture->dir_output = '/tmp/test_facture';

        $result = $this->mapper->exposeGetStoragePath($obj, true);

        $this->assertIsArray($result);
        $this->assertEquals('facture', $result[1]);
    }

    /**
     * Test getStoragePath with fichinter element (race condition)
     */
    public function testGetStoragePathWithFichinter(): void
    {
        global $conf;

        $obj = new stdClass();
        $obj->parentElementToUseForExtraFields = '';
        $obj->element = 'fichinter';
        $obj->ref = 'FI001';
        $obj->entity = 1;

        $conf->ficheinter = new stdClass();
        $conf->ficheinter->multidir_output = [1 => '/tmp/test_fichinter'];
        $conf->ficheinter->dir_output = '/tmp/test_fichinter';

        $result = $this->mapper->exposeGetStoragePath($obj, true);

        // The path should use 'ficheinter' not 'fichinter'
        $this->assertIsArray($result);
        $this->assertEquals('fichinter', $result[1]);
    }

    /**
     * Test getStoragePath with multidir_output
     */
    public function testGetStoragePathWithMultidirOutput(): void
    {
        global $conf;

        $obj = new stdClass();
        $obj->parentElementToUseForExtraFields = '';
        $obj->element = 'propal';
        $obj->ref = 'PR001';
        $obj->entity = 1;

        $conf->propal = new stdClass();
        $conf->propal->multidir_output = [1 => '/tmp/test_propal_multi'];
        $conf->propal->dir_output = '/tmp/test_propal';

        $result = $this->mapper->exposeGetStoragePath($obj, true);

        $this->assertIsArray($result);
        // Should use multidir_output when available
        $this->assertStringContainsString('propal_multi', $result[0]);
    }

    /**
     * Test getStoragePath with empty element
     */
    public function testGetStoragePathWithEmptyElement(): void
    {
        $obj = new stdClass();
        $obj->element = '';
        $obj->parentElementToUseForExtraFields = '';
        $obj->ref = 'TEST';
        $obj->entity = 1;

        $result = $this->mapper->exposeGetStoragePath($obj, true);

        $this->assertNull($result);
    }

    /**
     * Test getStoragePath returns full path when relativepath is false
     */
    public function testGetStoragePathReturnsFullPath(): void
    {
        global $conf;

        $obj = new stdClass();
        $obj->parentElementToUseForExtraFields = '';
        $obj->element = 'societe';
        $obj->ref = 'SOC001';
        $obj->entity = 1;

        $conf->societe = new stdClass();
        $conf->societe->multidir_output = [1 => DOL_DATA_ROOT . '/societe'];
        $conf->societe->dir_output = DOL_DATA_ROOT . '/societe';

        $result = $this->mapper->exposeGetStoragePath($obj, false);

        $this->assertIsArray($result);
        $this->assertStringContainsString('societe', $result[0]);
    }

    /**
     * Test exportMappedData with extrafield options
     */
    public function testExportMappedDataWithExtrafields(): void
    {
        $obj = new stdClass();
        $obj->id = 1;
        $obj->nom = 'Test';
        $obj->address = '';
        $obj->array_options = [
            'options_test' => 'extra_value'
        ];

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    /**
     * Test that mapper uses dmHelper
     */
    public function testMapperUsesDmHelper(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('_dolmapping');
        $property->setAccessible(true);
        $mapping = $property->getValue($this->mapper);

        $this->assertInstanceOf(dmHelper::class, $mapping);
    }

    /**
     * Test exportMappedData applies field filter function
     */
    public function testExportMappedDataAppliesFieldFilter(): void
    {
        $obj = new stdClass();
        $obj->id = 1;
        $obj->nom = 'lowercase company';
        $obj->address = '';
        $obj->array_options = [];

        $result = $this->mapper->exposeExportMappedData($obj);

        // fieldFilterValueNom applies strtoupper
        $this->assertEquals('LOWERCASE COMPANY', $result->name);
    }

    /**
     * Test exportMappedData with empty object
     */
    public function testExportMappedDataWithEmptyObject(): void
    {
        $obj = new stdClass();
        $obj->id = null;
        $obj->nom = null;
        $obj->address = null;
        $obj->array_options = [];

        $result = $this->mapper->exposeExportMappedData($obj);

        $this->assertInstanceOf(stdClass::class, $result);
    }
}
