<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\DolibarrMapping\dmHelper;

/**
 * Unit tests for dmHelper
 */
class dmHelperTest extends TestCase
{
    private dmHelper $helper;

    protected function setUp(): void
    {
        global $conf, $langs;

        // Initialize $conf
        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->cache = [];
        $conf->cache['smartmakers'] = [];

        // Initialize mock $langs
        $langs = new class {
            public function loadLangs($arr)
            {
            }
            public function transnoentities($str)
            {
                return $str;
            }
        };

        $this->helper = new dmHelper();
    }

    /**
     * Test smartNewObjectsTypes contains expected types
     */
    public function testSmartNewObjectsTypesContainsExpectedTypes(): void
    {
        $expected = [
            'smartphoto_' => 'photos',
            'smartaudio_' => 'audios',
            'smartvideo_' => 'videos',
            'smartfile_' => 'files',
            'smartsignature_' => 'signature'
        ];

        $this->assertEquals($expected, $this->helper->smartNewObjectsTypes);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 0 (not visible)
     */
    public function testCustomFilterAttributeVisibleZero(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertEquals([], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 1 (full access)
     */
    public function testCustomFilterAttributeVisibleOne(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertEquals(['create', 'update', 'read'], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 2 (read only on list)
     */
    public function testCustomFilterAttributeVisibleTwo(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(2);

        $this->assertEquals(['read'], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 3 (form only)
     */
    public function testCustomFilterAttributeVisibleThree(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(3);

        $this->assertEquals(['create', 'update', 'read'], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 4 (update/read)
     */
    public function testCustomFilterAttributeVisibleFour(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(4);

        $this->assertEquals(['update', 'read'], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visibility 5 (read only)
     */
    public function testCustomFilterAttributeVisibleFive(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(5);

        $this->assertEquals(['read'], $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible handles negative values (absolute value)
     */
    public function testCustomFilterAttributeVisibleNegativeValue(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(-1);

        $this->assertEquals(['create', 'update', 'read'], $result['visible']);
    }

    /**
     * Test getListOfForeignKeys returns empty array initially
     */
    public function testGetListOfForeignKeysReturnsEmptyArrayInitially(): void
    {
        $result = $this->helper->getListOfForeignKeys();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test setGlobalMaxImageSize sets values correctly
     */
    public function testSetGlobalMaxImageSizeDefaultValues(): void
    {
        global $conf;

        $this->helper->setGlobalMaxImageSize(800);

        $this->assertEquals(800, $conf->cache['smartmakers']['photo']['maxWidth']);
        $this->assertEquals(-1, $conf->cache['smartmakers']['photo']['maxHeight']);
        $this->assertEquals(90, $conf->cache['smartmakers']['photo']['quality']);
    }

    /**
     * Test setGlobalMaxImageSize with all parameters
     */
    public function testSetGlobalMaxImageSizeAllParams(): void
    {
        global $conf;

        $this->helper->setGlobalMaxImageSize(1920, 1080, 85);

        $this->assertEquals(1920, $conf->cache['smartmakers']['photo']['maxWidth']);
        $this->assertEquals(1080, $conf->cache['smartmakers']['photo']['maxHeight']);
        $this->assertEquals(85, $conf->cache['smartmakers']['photo']['quality']);
    }

    /**
     * Test propertiesFilter with empty input
     */
    public function testPropertiesFilterWithEmptyInput(): void
    {
        $result = $this->helper->propertiesFilter([], 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test propertiesFilter with non-array input
     */
    public function testPropertiesFilterWithNonArrayInput(): void
    {
        $result = $this->helper->propertiesFilter('string', 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test propertiesFilter translates label
     */
    public function testPropertiesFilterTranslatesLabel(): void
    {
        $input = [
            'label' => 'TestLabel'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('label', $result);
        $this->assertEquals('TestLabel', $result['label']);
    }

    /**
     * Test propertiesFilter translates help
     */
    public function testPropertiesFilterTranslatesHelp(): void
    {
        $input = [
            'help' => 'TestHelp'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('help', $result);
        $this->assertEquals('TestHelp', $result['help']);
    }

    /**
     * Test propertiesFilter maps picto to icon
     */
    public function testPropertiesFilterMapsPictoToIcon(): void
    {
        $input = [
            'picto' => 'user'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('icon', $result);
        $this->assertEquals('user', $result['icon']);
    }

    /**
     * Test propertiesFilter maps default to defaultValue
     */
    public function testPropertiesFilterMapsDefaultToDefaultValue(): void
    {
        $input = [
            'default' => 'myDefault'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('defaultValue', $result);
        $this->assertEquals('myDefault', $result['defaultValue']);
    }

    /**
     * Test propertiesFilter maps notnull to required
     */
    public function testPropertiesFilterMapsNotnullToRequired(): void
    {
        $input = [
            'notnull' => 1
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('required', $result);
        $this->assertEquals(1, $result['required']);
    }

    /**
     * Test propertiesFilter maps noteditable to readOnly
     */
    public function testPropertiesFilterMapsNoteditableToReadOnly(): void
    {
        $input = [
            'noteditable' => true
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('readOnly', $result);
        $this->assertTrue($result['readOnly']);
    }

    /**
     * Test propertiesFilter maps length to max
     */
    public function testPropertiesFilterMapsLengthToMax(): void
    {
        $input = [
            'length' => 255
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('max', $result);
        $this->assertEquals(255, $result['max']);
    }

    /**
     * Test propertiesFilter maps position
     */
    public function testPropertiesFilterMapsPosition(): void
    {
        $input = [
            'position' => 10
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('position', $result);
        $this->assertEquals(10, $result['position']);
    }

    /**
     * Test propertiesFilter ignores unknown keys
     */
    public function testPropertiesFilterIgnoresUnknownKeys(): void
    {
        $input = [
            'unknownKey' => 'value',
            'anotherUnknown' => 123
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayNotHasKey('unknownKey', $result);
        $this->assertArrayNotHasKey('anotherUnknown', $result);
    }

    /**
     * Test propertiesFilter with parent override
     */
    public function testPropertiesFilterWithParentOverride(): void
    {
        $input = [
            'label' => 'OriginalLabel'
        ];
        $parentOverride = [
            'testkey' => [
                'label' => 'OverriddenLabel'
            ]
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey', $parentOverride);

        $this->assertEquals('OverriddenLabel', $result['label']);
    }

    /**
     * Test propertiesFilter parent override adds new keys
     */
    public function testPropertiesFilterParentOverrideAddsNewKeys(): void
    {
        $input = [
            'label' => 'TestLabel'
        ];
        $parentOverride = [
            'testkey' => [
                'customKey' => 'customValue'
            ]
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey', $parentOverride);

        $this->assertArrayHasKey('customKey', $result);
        $this->assertEquals('customValue', $result['customKey']);
    }

    /**
     * Test propertiesFilter with visibility calls custom filter
     */
    public function testPropertiesFilterWithVisibility(): void
    {
        $input = [
            'visible' => 1
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('visible', $result);
        $this->assertEquals(['create', 'update', 'read'], $result['visible']);
    }

    /**
     * Test propertiesFilter maps multiple attributes
     */
    public function testPropertiesFilterMapsMultipleAttributes(): void
    {
        $input = [
            'label' => 'MyLabel',
            'picto' => 'building',
            'default' => 'defaultVal',
            'position' => 5,
            'visible' => 2
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertEquals('MyLabel', $result['label']);
        $this->assertEquals('building', $result['icon']);
        $this->assertEquals('defaultVal', $result['defaultValue']);
        $this->assertEquals(5, $result['position']);
        $this->assertEquals(['read'], $result['visible']);
    }
}
