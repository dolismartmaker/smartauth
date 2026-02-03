<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../dolMapping/dmHelper.php';
require_once __DIR__ . '/../../../dolMapping/dmBase.php';
require_once __DIR__ . '/../../../dolMapping/dmTrait.php';

use SmartAuth\DolibarrMapping\dmHelper;

/**
 * Integration tests for Dolibarr Mapping classes
 * Tests the field mapping system between Dolibarr objects and API responses
 *
 * @covers \SmartAuth\DolibarrMapping\dmHelper
 */
class DolibarrMappingTest extends DolibarrRealTestCase
{
    /**
     * Test dmHelper instantiation
     */
    public function testDmHelperInstantiation(): void
    {
        $helper = new dmHelper();
        $this->assertInstanceOf(dmHelper::class, $helper);
    }

    /**
     * Test dmHelper has required properties
     */
    public function testDmHelperHasSmartNewObjectsTypes(): void
    {
        $helper = new dmHelper();
        $this->assertIsArray($helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartphoto_', $helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartaudio_', $helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartvideo_', $helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartfile_', $helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartsignature_', $helper->smartNewObjectsTypes);
    }

    /**
     * Test dmHelper propertiesFilter with basic input
     */
    public function testDmHelperPropertiesFilterBasic(): void
    {
        $helper = new dmHelper();

        $input = [
            'type' => 'varchar(100)',
            'label' => 'Test Label',
            'visible' => 1,
            'notnull' => 1
        ];

        $result = $helper->propertiesFilter($input, 'test_field', 'testField');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('varchar', $result['type']);
        $this->assertArrayHasKey('max', $result);
        $this->assertEquals('100', $result['max']);
    }

    /**
     * Test dmHelper propertiesFilter type conversions
     */
    public function testDmHelperPropertiesFilterTypeConversions(): void
    {
        $helper = new dmHelper();

        // Test integer conversion
        $result = $helper->propertiesFilter(['type' => 'integer'], 'field', 'field');
        $this->assertEquals('int', $result['type']);

        // Test double conversion
        $result = $helper->propertiesFilter(['type' => 'double'], 'field', 'field');
        $this->assertEquals('float', $result['type']);

        // Test price conversion
        $result = $helper->propertiesFilter(['type' => 'price'], 'field', 'field');
        $this->assertEquals('float', $result['type']);

        // Test checkbox conversion
        $result = $helper->propertiesFilter(['type' => 'checkbox'], 'field', 'field');
        $this->assertEquals('boolean', $result['type']);

        // Test mail conversion
        $result = $helper->propertiesFilter(['type' => 'mail'], 'field', 'field');
        $this->assertEquals('email', $result['type']);

        // Test phone conversion
        $result = $helper->propertiesFilter(['type' => 'phone'], 'field', 'field');
        $this->assertEquals('phoneNumber', $result['type']);
    }

    /**
     * Test dmHelper visible attribute conversion
     */
    public function testDmHelperVisibleAttributeConversion(): void
    {
        $helper = new dmHelper();

        // Visible = 0 should return empty array
        $result = $helper->_customFilterAttributeVisible(0);
        $this->assertEquals([], $result['visible']);

        // Visible = 1 should return create, update, read
        $result = $helper->_customFilterAttributeVisible(1);
        $this->assertEquals(['create', 'update', 'read'], $result['visible']);

        // Visible = 2 should return read only
        $result = $helper->_customFilterAttributeVisible(2);
        $this->assertEquals(['read'], $result['visible']);

        // Visible = 4 should return update, read
        $result = $helper->_customFilterAttributeVisible(4);
        $this->assertEquals(['update', 'read'], $result['visible']);
    }

    /**
     * Test dmHelper getListOfForeignKeys returns array
     */
    public function testDmHelperGetListOfForeignKeys(): void
    {
        $helper = new dmHelper();
        $keys = $helper->getListOfForeignKeys();
        $this->assertIsArray($keys);
    }

    /**
     * Test dmHelper setGlobalMaxImageSize
     */
    public function testDmHelperSetGlobalMaxImageSize(): void
    {
        global $conf;

        $helper = new dmHelper();
        $helper->setGlobalMaxImageSize(800, 600, 85);

        $this->assertEquals(800, $conf->cache['smartmakers']['photo']['maxWidth']);
        $this->assertEquals(600, $conf->cache['smartmakers']['photo']['maxHeight']);
        $this->assertEquals(85, $conf->cache['smartmakers']['photo']['quality']);
    }

    /**
     * Test dmHelper propertiesFilter with double(x,y) type
     */
    public function testDmHelperPropertiesFilterDoubleType(): void
    {
        $helper = new dmHelper();

        // Test double(x) format - single value
        $result = $helper->propertiesFilter(['type' => 'double(10)'], 'price', 'price');
        $this->assertEquals('double', $result['type']);
        $this->assertEquals('10', $result['max']);
    }

    /**
     * Test dmHelper propertiesFilter with radio type
     */
    public function testDmHelperPropertiesFilterRadioType(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter(['type' => 'radio'], 'field', 'field');
        $this->assertEquals('boolean', $result['type']);
        $this->assertEquals('radio', $result['typeVariant']);
    }

    /**
     * Test dmHelper propertiesFilter with chkbxlst type
     */
    public function testDmHelperPropertiesFilterChkbxlstType(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter(['type' => 'chkbxlst'], 'field', 'field');
        $this->assertEquals('check', $result['type']);
        $this->assertEquals('checkbox', $result['typeVariant']);
        $this->assertTrue($result['multiple']);
    }

    /**
     * Test dmHelper propertiesFilter with sellist type
     */
    public function testDmHelperPropertiesFilterSellistType(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter(['type' => 'sellist'], 'field', 'field');
        $this->assertEquals('select', $result['type']);
    }

    /**
     * Test dmHelper propertiesFilter with text type
     */
    public function testDmHelperPropertiesFilterTextType(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter(['type' => 'text'], 'field', 'field');
        $this->assertEquals('text', $result['type']);
    }

    /**
     * Test dmHelper propertiesFilter with IP type
     */
    public function testDmHelperPropertiesFilterIpType(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter(['type' => 'ip'], 'field', 'field');
        $this->assertEquals('varchar', $result['type']);
    }

    /**
     * Test dmHelper propertiesFilter with label translation
     */
    public function testDmHelperPropertiesFilterLabelTranslation(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'label' => 'Company'  // Common Dolibarr label
        ], 'field', 'field');

        $this->assertArrayHasKey('label', $result);
    }

    /**
     * Test dmHelper propertiesFilter with help translation
     */
    public function testDmHelperPropertiesFilterHelpTranslation(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'help' => 'Some help text'
        ], 'field', 'field');

        $this->assertArrayHasKey('help', $result);
    }

    /**
     * Test dmHelper propertiesFilter with parentOverride
     */
    public function testDmHelperPropertiesFilterWithParentOverride(): void
    {
        $helper = new dmHelper();

        $input = [
            'type' => 'varchar',
            'visible' => 1
        ];

        $parentOverride = [
            'test_field' => [
                'visible' => 3,
                'required' => true
            ]
        ];

        $result = $helper->propertiesFilter($input, 'test_field', 'testField', $parentOverride);

        // The override should be applied
        $this->assertIsArray($result);
    }

    /**
     * Test that dmBase class exists and has expected properties
     */
    public function testDmBaseClassExists(): void
    {
        $this->assertTrue(class_exists('SmartAuth\DolibarrMapping\dmBase'));

        $reflection = new \ReflectionClass('SmartAuth\DolibarrMapping\dmBase');

        // Check protected properties exist
        $this->assertTrue($reflection->hasProperty('type'));
        $this->assertTrue($reflection->hasProperty('listOfPublishedFields'));
        $this->assertTrue($reflection->hasProperty('parentClassName'));
    }

    /**
     * Test that dmTrait trait exists
     */
    public function testDmTraitExists(): void
    {
        $this->assertTrue(trait_exists('SmartAuth\DolibarrMapping\dmTrait'));
    }

    /**
     * Test dmHelper extrafieldsFilter with basic input
     */
    public function testDmHelperExtrafieldsFilterBasic(): void
    {
        global $db;

        $helper = new dmHelper();

        // Create mock extrafields object with all required attributes
        $extrafields = new \ExtraFields($db);
        $extrafields->attributes['test_element'] = [
            'type' => ['test_field' => 'varchar'],
            'label' => ['test_field' => 'Test Field'],
            'pos' => ['test_field' => 10],
            'size' => ['test_field' => 100],
            'required' => ['test_field' => 1],
            'visible' => ['test_field' => 1],
            'help' => ['test_field' => 'Help text'],
            'placeholder' => ['test_field' => ''],
            'picto' => ['test_field' => ''],
            'default' => ['test_field' => ''],
            'copytoclipboard' => ['test_field' => 0],
            'noteditable' => ['test_field' => 0],
            'options' => ['test_field' => []]
        ];

        $result = $helper->extrafieldsFilter('test_element', 'test_field', 'testField', $extrafields);

        $this->assertIsArray($result);
        $this->assertTrue($result['is_extrafield']);
    }

    /**
     * Test dmHelper extrafieldsFilter marks fields as extrafield
     */
    public function testDmHelperExtrafieldsFilterMarksAsExtrafield(): void
    {
        global $db;

        $helper = new dmHelper();

        // ExtraFields need all standard attributes initialized
        $extrafields = new \ExtraFields($db);
        $extrafields->attributes['element'] = [
            'type' => ['field' => 'varchar'],
            'label' => ['field' => 'Field'],
            'pos' => ['field' => 1],
            'size' => ['field' => 100],
            'required' => ['field' => 0],
            'visible' => ['field' => 1],
            'help' => ['field' => ''],
            'placeholder' => ['field' => ''],
            'picto' => ['field' => ''],
            'default' => ['field' => ''],
            'copytoclipboard' => ['field' => 0],
            'noteditable' => ['field' => 0],
            'options' => ['field' => []]
        ];

        $result = $helper->extrafieldsFilter('element', 'field', 'field', $extrafields);

        $this->assertTrue($result['is_extrafield']);
    }

    /**
     * Test dmHelper propertiesFilter with position attribute
     */
    public function testDmHelperPropertiesFilterPosition(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'position' => 100
        ], 'field', 'field');

        $this->assertArrayHasKey('position', $result);
        $this->assertEquals(100, $result['position']);
    }

    /**
     * Test dmHelper propertiesFilter with default value
     */
    public function testDmHelperPropertiesFilterDefault(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'default' => 'default value'
        ], 'field', 'field');

        $this->assertArrayHasKey('defaultValue', $result);
        $this->assertEquals('default value', $result['defaultValue']);
    }

    /**
     * Test dmHelper propertiesFilter with notnull (required)
     */
    public function testDmHelperPropertiesFilterNotnull(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'notnull' => 1
        ], 'field', 'field');

        $this->assertArrayHasKey('required', $result);
        $this->assertEquals(1, $result['required']);
    }

    /**
     * Test dmHelper propertiesFilter with rows
     */
    public function testDmHelperPropertiesFilterRows(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'text',
            'rows' => 5
        ], 'field', 'field');

        $this->assertArrayHasKey('rows', $result);
        $this->assertEquals(5, $result['rows']);
    }

    /**
     * Test dmHelper propertiesFilter with noteditable (readOnly)
     */
    public function testDmHelperPropertiesFilterNoteditable(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'noteditable' => 1
        ], 'field', 'field');

        $this->assertArrayHasKey('readOnly', $result);
        $this->assertEquals(1, $result['readOnly']);
    }

    /**
     * Test dmHelper propertiesFilter with copytoclipboard
     */
    public function testDmHelperPropertiesFilterCopytoclipboard(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'copytoclipboard' => 1
        ], 'field', 'field');

        $this->assertArrayHasKey('hasCopyButton', $result);
        $this->assertEquals(1, $result['hasCopyButton']);
    }

    /**
     * Test dmHelper propertiesFilter with picto (icon)
     */
    public function testDmHelperPropertiesFilterPicto(): void
    {
        $helper = new dmHelper();

        $result = $helper->propertiesFilter([
            'type' => 'varchar',
            'picto' => 'user'
        ], 'field', 'field');

        $this->assertArrayHasKey('icon', $result);
        $this->assertEquals('user', $result['icon']);
    }
}
