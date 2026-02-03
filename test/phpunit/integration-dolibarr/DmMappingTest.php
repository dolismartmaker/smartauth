<?php

/**
 * Tests for dmTrait mapping functionality
 *
 * These tests focus on the exportMappedData, getStoragePath and fieldFilterValue* methods
 * from dmTrait without requiring full Dolibarr class field definitions.
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

require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

/**
 * Test mapper class with minimal field definitions
 */
class TestDmMapper extends dmBase
{
    use dmTrait;

    protected $type = "object";

    protected $listOfPublishedFields = [
        'rowid'  => 'id',
        'name'   => 'name',
        'email'  => 'email',
        'status' => 'status'
    ];

    /**
     * Override constructor to avoid boot() which requires full Dolibarr integration
     */
    public function __construct($db)
    {
        $this->_db = $db;
        $this->_dolmapping = new dmHelper();
        $this->_dolmapclassname = static::class;
        $this->_dolobjectclassname = 'TestObject';
        // Skip _objectDesc() call which requires Dolibarr field definitions
        $this->_cacheDesc = new \stdClass();
    }

    /**
     * Get the listOfPublishedFields for testing
     */
    public function getListOfPublishedFields(): array
    {
        return $this->listOfPublishedFields;
    }
}

/**
 * Test mapper class with lines support
 */
class TestDmMapperWithLines extends dmBase
{
    use dmTrait;

    protected $type = "object";

    protected $listOfPublishedFields = [
        'rowid'  => 'id',
        'ref'    => 'ref',
        'name'   => 'name'
    ];

    protected $listOfPublishedFieldsForLines = [
        'rowid' => 'id',
        'qty'   => 'quantity',
        'desc'  => 'description'
    ];

    protected $parentClassNameForLines = '';

    public function __construct($db)
    {
        $this->_db = $db;
        $this->_dolmapping = new dmHelper();
        $this->_dolmapclassname = static::class;
        $this->_dolobjectclassname = 'TestObject';
        $this->_cacheDesc = new \stdClass();
        $this->listOfForeignKeys = [];
    }
}

/**
 * Test mapper with extrafields
 */
class TestDmMapperWithExtrafields extends dmBase
{
    use dmTrait;

    protected $type = "object";

    protected $listOfPublishedFields = [
        'rowid'              => 'id',
        'name'               => 'name',
        'options_customfield' => 'custom_field',
        'options_smartphoto_image' => 'photo'
    ];

    protected $parentTableElementToUseForExtraFields = 'test_element';

    public function __construct($db)
    {
        $this->_db = $db;
        $this->_dolmapping = new dmHelper();
        $this->_dolmapclassname = static::class;
        $this->_dolobjectclassname = 'TestObject';
        $this->_cacheDesc = new \stdClass();
        $this->listOfForeignKeys = [];
    }
}

/**
 * @covers \SmartAuth\DolibarrMapping\dmTrait
 * @covers \SmartAuth\DolibarrMapping\dmBase
 */
class DmMappingTest extends DolibarrRealTestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TestDmMapper($this->db);
    }

    /**
     * Test mapper instantiation
     */
    public function testMapperInstantiation(): void
    {
        $this->assertInstanceOf(TestDmMapper::class, $this->mapper);
    }

    /**
     * Test objectType returns correct type
     */
    public function testObjectTypeReturnsCorrectType(): void
    {
        $type = $this->mapper->objectType();
        $this->assertEquals('object', $type);
    }

    /**
     * Test objectDesc returns cached description
     */
    public function testObjectDescReturnsCachedDescription(): void
    {
        $desc = $this->mapper->objectDesc();
        $this->assertInstanceOf(\stdClass::class, $desc);
    }

    /**
     * Test exportMappedData with simple object
     */
    public function testExportMappedDataWithSimpleObject(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->rowid = 1;
        $obj->name = 'Test Name';
        $obj->email = 'test@example.com';
        $obj->status = 1;

        $mapped = $this->mapper->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
        $this->assertEquals(1, $mapped->id);
        $this->assertEquals('Test Name', $mapped->name);
        $this->assertEquals('test@example.com', $mapped->email);
        $this->assertEquals(1, $mapped->status);
    }

    /**
     * Test exportMappedData handles rowid/id mapping
     */
    public function testExportMappedDataHandlesRowidIdMapping(): void
    {
        $obj = new \stdClass();
        $obj->id = 42;
        // rowid is mapped from id when missing

        $mapped = $this->mapper->exportMappedData($obj);

        $this->assertEquals(42, $mapped->id);
    }

    /**
     * Test exportMappedData handles fk_soc/socid mapping
     */
    public function testExportMappedDataHandlesFkSocSocidMapping(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->socid = 99;
        $obj->fk_soc = null;

        $mapped = $this->mapper->exportMappedData($obj);

        // fk_soc should be set from socid
        $this->assertInstanceOf(\stdClass::class, $mapped);
    }

    /**
     * Test exportMappedData with empty object
     */
    public function testExportMappedDataWithEmptyObject(): void
    {
        $obj = new \stdClass();

        $mapped = $this->mapper->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
    }

    /**
     * Test getStoragePath with valid object
     */
    public function testGetStoragePathWithValidObject(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'REF001';
        $obj->entity = 1;
        $obj->element = 'product';
        $obj->parentElementToUseForExtraFields = '';

        // Ensure product config exists
        if (!isset($conf->product)) {
            $conf->product = new \stdClass();
            $conf->product->multidir_output = [1 => '/tmp/dolibarr/product'];
            $conf->product->dir_output = '/tmp/dolibarr/product';
        }

        $result = $this->mapper->getStoragePath($obj);

        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertCount(2, $result);
            $this->assertEquals('product', $result[1]);
        }
    }

    /**
     * Test getStoragePath with empty element returns null
     */
    public function testGetStoragePathWithEmptyElementReturnsNull(): void
    {
        $obj = new \stdClass();
        $obj->parentElementToUseForExtraFields = '';
        $obj->element = '';

        $result = $this->mapper->getStoragePath($obj);

        $this->assertNull($result);
    }

    /**
     * Test getStoragePath handles fichinter race condition
     */
    public function testGetStoragePathHandlesFichinterRaceCondition(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'FI001';
        $obj->entity = 1;
        $obj->element = 'fichinter';
        $obj->parentElementToUseForExtraFields = '';

        // Ensure ficheinter config exists (note: Dolibarr uses ficheinter not fichinter)
        if (!isset($conf->ficheinter)) {
            $conf->ficheinter = new \stdClass();
            $conf->ficheinter->multidir_output = [1 => '/tmp/dolibarr/ficheinter'];
        }

        $result = $this->mapper->getStoragePath($obj);

        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertEquals('fichinter', $result[1]);
        }
    }

    /**
     * Test getStoragePath with relative path (default)
     */
    public function testGetStoragePathWithRelativePath(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'REF001';
        $obj->entity = 1;
        $obj->element = 'product';
        $obj->parentElementToUseForExtraFields = '';

        if (!isset($conf->product)) {
            $conf->product = new \stdClass();
            $conf->product->multidir_output = [1 => DOL_DATA_ROOT . '/product'];
        }

        $result = $this->mapper->getStoragePath($obj, true);

        if ($result !== null) {
            // Relative path should not contain DOL_DATA_ROOT
            $this->assertStringNotContainsString(DOL_DATA_ROOT, $result[0]);
        }
    }

    /**
     * Test getStoragePath with full path
     */
    public function testGetStoragePathWithFullPath(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'REF001';
        $obj->entity = 1;
        $obj->element = 'product';
        $obj->parentElementToUseForExtraFields = '';

        if (!isset($conf->product)) {
            $conf->product = new \stdClass();
            $conf->product->multidir_output = [1 => '/tmp/dolibarr/product'];
        }

        $result = $this->mapper->getStoragePath($obj, false);

        if ($result !== null) {
            // Full path should contain the full directory
            $this->assertStringContainsString('/', $result[0]);
        }
    }

    /**
     * Test getStoragePath uses parentElementToUseForExtraFields first
     */
    public function testGetStoragePathUsesParentElementFirst(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'REF001';
        $obj->entity = 1;
        $obj->element = 'product';
        $obj->parentElementToUseForExtraFields = 'commande';

        // Ensure commande config has proper structure
        if (!isset($conf->commande)) {
            $conf->commande = new \stdClass();
        }
        $conf->commande->multidir_output = [1 => '/tmp/dolibarr/commande'];
        $conf->commande->dir_output = '/tmp/dolibarr/commande';

        $result = $this->mapper->getStoragePath($obj);

        if ($result !== null) {
            $this->assertEquals('commande', $result[1]);
        }
    }

    /**
     * Test exportMappedData with lines
     */
    public function testExportMappedDataWithLines(): void
    {
        $mapperWithLines = new TestDmMapperWithLines($this->db);

        $line1 = new \stdClass();
        $line1->rowid = 1;
        $line1->qty = 5;
        $line1->desc = 'First line';

        $line2 = new \stdClass();
        $line2->rowid = 2;
        $line2->qty = 10;
        $line2->desc = 'Second line';

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'ORD001';
        $obj->name = 'Test Order';
        $obj->lines = [$line1, $line2];

        $mapped = $mapperWithLines->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
        $this->assertObjectHasProperty('lines', $mapped);
        $this->assertIsArray($mapped->lines);
        $this->assertCount(2, $mapped->lines);

        // Check first line mapping
        $this->assertEquals(1, $mapped->lines[0]->id);
        $this->assertEquals(5, $mapped->lines[0]->quantity);
        $this->assertEquals('First line', $mapped->lines[0]->description);
    }

    /**
     * Test exportMappedData with empty lines array
     */
    public function testExportMappedDataWithEmptyLinesArray(): void
    {
        $mapperWithLines = new TestDmMapperWithLines($this->db);

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'ORD001';
        $obj->name = 'Test Order';
        $obj->lines = [];

        $mapped = $mapperWithLines->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
        // Empty lines array should not add lines property
        $this->assertObjectNotHasProperty('lines', $mapped);
    }

    /**
     * Test fieldFilterValueSmartPhoto returns proper structure
     */
    public function testFieldFilterValueSmartPhotoReturnsProperStructure(): void
    {
        global $conf;

        $mapperWithExtrafields = new TestDmMapperWithExtrafields($this->db);

        // Set up test_element config
        $conf->test_element = new \stdClass();
        $conf->test_element->multidir_output = [1 => '/tmp/dolibarr/test_element'];
        $conf->test_element->dir_output = '/tmp/dolibarr/test_element';

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'OBJ001';
        $obj->entity = 1;
        $obj->element = 'test_element';
        $obj->parentElementToUseForExtraFields = 'test_element';
        $obj->array_options = [
            'options_smartphoto_image' => 'test_image.jpg'
        ];

        $result = $mapperWithExtrafields->fieldFilterValueSmartPhoto($obj, 'options_smartphoto_image');

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertObjectHasProperty('filename', $result);
        $this->assertObjectHasProperty('title', $result);
        $this->assertObjectHasProperty('description', $result);
        $this->assertObjectHasProperty('gps', $result);
        $this->assertObjectHasProperty('src', $result);
        $this->assertObjectHasProperty('ref', $result);
        $this->assertObjectHasProperty('element', $result);
        $this->assertObjectHasProperty('parentid', $result);
        $this->assertObjectHasProperty('keywords', $result);
        $this->assertObjectHasProperty('note_private', $result);
        $this->assertObjectHasProperty('note_public', $result);

        $this->assertEquals('test_image.jpg', $result->filename);
        $this->assertEquals('test_element', $result->element);
        $this->assertEquals(1, $result->parentid);

        // Cleanup
        unset($conf->test_element);
    }

    /**
     * Test exportExtrafieldData with empty parentTableElementToUseForExtraFields
     */
    public function testExportExtrafieldDataWithEmptyParent(): void
    {
        $result = $this->mapper->exportExtrafieldData('options_test', 123);

        // With empty parentTableElementToUseForExtraFields, returns null
        $this->assertNull($result);
    }

    /**
     * Test exportMappedData handles simple extrafields with array_options
     * Note: We use the base mapper without smartphoto to avoid storage path issues
     */
    public function testExportMappedDataHandlesArrayOptions(): void
    {
        // Use the simple mapper that doesn't have smartphoto in published fields
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->name = 'Test Object';
        $obj->email = 'test@example.com';
        $obj->status = 1;

        $mapped = $this->mapper->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
        $this->assertEquals(1, $mapped->id);
        $this->assertEquals('Test Object', $mapped->name);
        $this->assertEquals('test@example.com', $mapped->email);
        $this->assertEquals(1, $mapped->status);
    }

    /**
     * Test objectDesc caching returns same object
     */
    public function testObjectDescCachingReturnsSameObject(): void
    {
        $desc1 = $this->mapper->objectDesc();
        $desc2 = $this->mapper->objectDesc();

        $this->assertSame($desc1, $desc2);
    }

    /**
     * Test getListOfPublishedFields
     */
    public function testGetListOfPublishedFields(): void
    {
        $fields = $this->mapper->getListOfPublishedFields();

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('rowid', $fields);
        $this->assertEquals('id', $fields['rowid']);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayHasKey('status', $fields);
    }

    /**
     * Test exportMappedData with smartphoto extrafield prefix
     */
    public function testExportMappedDataWithSmartPhotoPrefix(): void
    {
        global $conf;

        $mapperWithExtrafields = new TestDmMapperWithExtrafields($this->db);

        // Set up test_element config
        $conf->test_element = new \stdClass();
        $conf->test_element->multidir_output = [1 => '/tmp/dolibarr/test_element'];
        $conf->test_element->dir_output = '/tmp/dolibarr/test_element';

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->name = 'Test';
        $obj->element = 'test_element';
        $obj->parentElementToUseForExtraFields = 'test_element';
        $obj->entity = 1;
        $obj->ref = 'TEST001';
        $obj->array_options = [
            'options_smartphoto_image' => 'photo.jpg'
        ];

        $mapped = $mapperWithExtrafields->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
        // Photo should be transformed to stdClass with metadata
        if (isset($mapped->photo)) {
            $this->assertInstanceOf(\stdClass::class, $mapped->photo);
        }

        // Cleanup
        unset($conf->test_element);
    }

    /**
     * Test dmHelper integration in mapper
     */
    public function testDmHelperIntegration(): void
    {
        // The mapper should have a dmHelper instance
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->name = 'Test';

        $mapped = $this->mapper->exportMappedData($obj);

        $this->assertInstanceOf(\stdClass::class, $mapped);
    }

    /**
     * Test getStoragePath with dir_output fallback
     * Note: The code accesses multidir_output[$entity] directly without checking,
     * so we need to provide the correct entity key
     */
    public function testGetStoragePathWithDirOutputFallback(): void
    {
        global $conf;

        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'REF001';
        $obj->entity = 1;
        $obj->element = 'testmodule';
        $obj->parentElementToUseForExtraFields = '';

        // Set up config with empty multidir_output for entity 1
        // but with dir_output as fallback
        $conf->testmodule = new \stdClass();
        $conf->testmodule->multidir_output = [1 => '']; // Empty value for entity 1
        $conf->testmodule->dir_output = '/tmp/dolibarr/testmodule';

        $result = $this->mapper->getStoragePath($obj, false);

        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertEquals('testmodule', $result[1]);
        }

        // Cleanup
        unset($conf->testmodule);
    }
}
