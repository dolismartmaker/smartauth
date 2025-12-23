<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../dolMapping/dmHelper.php';

use SmartAuth\DolibarrMapping\dmHelper;

/**
 * Integration tests for dmHelper class (Dolibarr mapping helper)
 */
class DmHelperTest extends DolibarrRealTestCase
{
    /** @var dmHelper */
    private $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new dmHelper();
    }

    /**
     * Test dmHelper instantiation
     */
    public function testDmHelperInstantiation(): void
    {
        $this->assertInstanceOf(dmHelper::class, $this->helper);
    }

    /**
     * Test smartNewObjectsTypes property
     */
    public function testSmartNewObjectsTypesProperty(): void
    {
        $this->assertIsArray($this->helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartphoto_', $this->helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartaudio_', $this->helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartvideo_', $this->helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartfile_', $this->helper->smartNewObjectsTypes);
        $this->assertArrayHasKey('smartsignature_', $this->helper->smartNewObjectsTypes);

        $this->assertEquals('photos', $this->helper->smartNewObjectsTypes['smartphoto_']);
        $this->assertEquals('audios', $this->helper->smartNewObjectsTypes['smartaudio_']);
        $this->assertEquals('videos', $this->helper->smartNewObjectsTypes['smartvideo_']);
        $this->assertEquals('files', $this->helper->smartNewObjectsTypes['smartfile_']);
        $this->assertEquals('signature', $this->helper->smartNewObjectsTypes['smartsignature_']);
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
     * Test propertiesFilter with null input
     */
    public function testPropertiesFilterWithNullInput(): void
    {
        $result = $this->helper->propertiesFilter(null, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test propertiesFilter with label field
     */
    public function testPropertiesFilterWithLabel(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
            $langs->loadLangs(['companies']);
        }

        $input = [
            'label' => 'TestLabel'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('label', $result);
    }

    /**
     * Test propertiesFilter with help field
     */
    public function testPropertiesFilterWithHelp(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
            $langs->loadLangs(['companies']);
        }

        $input = [
            'help' => 'TestHelp'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('help', $result);
    }

    /**
     * Test propertiesFilter with type field - varchar
     */
    public function testPropertiesFilterWithTypeVarchar(): void
    {
        $input = [
            'type' => 'varchar(255)'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('varchar', $result['type']);
        $this->assertArrayHasKey('max', $result);
        $this->assertEquals('255', $result['max']);
    }

    /**
     * Test propertiesFilter with type field - integer
     */
    public function testPropertiesFilterWithTypeInteger(): void
    {
        $input = [
            'type' => 'integer'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('int', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - double
     */
    public function testPropertiesFilterWithTypeDouble(): void
    {
        $input = [
            'type' => 'double'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('float', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - price
     */
    public function testPropertiesFilterWithTypePrice(): void
    {
        $input = [
            'type' => 'price'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('float', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - checkbox
     */
    public function testPropertiesFilterWithTypeCheckbox(): void
    {
        $input = [
            'type' => 'checkbox'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('boolean', $result['type']);
        $this->assertArrayHasKey('typeVariant', $result);
        $this->assertEquals('switch', $result['typeVariant']);
    }

    /**
     * Test propertiesFilter with type field - radio
     */
    public function testPropertiesFilterWithTypeRadio(): void
    {
        $input = [
            'type' => 'radio'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('boolean', $result['type']);
        $this->assertArrayHasKey('typeVariant', $result);
        $this->assertEquals('radio', $result['typeVariant']);
    }

    /**
     * Test propertiesFilter with type field - mail
     */
    public function testPropertiesFilterWithTypeMail(): void
    {
        $input = [
            'type' => 'mail'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('email', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - phone
     */
    public function testPropertiesFilterWithTypePhone(): void
    {
        $input = [
            'type' => 'phone'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('phoneNumber', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - sellist
     */
    public function testPropertiesFilterWithTypeSellist(): void
    {
        $input = [
            'type' => 'sellist'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('select', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - chkbxlst
     */
    public function testPropertiesFilterWithTypeChkbxlst(): void
    {
        $input = [
            'type' => 'chkbxlst'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('check', $result['type']);
        $this->assertArrayHasKey('typeVariant', $result);
        $this->assertEquals('checkbox', $result['typeVariant']);
        $this->assertArrayHasKey('multiple', $result);
        $this->assertTrue($result['multiple']);
    }

    /**
     * Test propertiesFilter with type field - ip
     */
    public function testPropertiesFilterWithTypeIp(): void
    {
        $input = [
            'type' => 'ip'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('varchar', $result['type']);
    }

    /**
     * Test propertiesFilter with type field - text
     */
    public function testPropertiesFilterWithTypeText(): void
    {
        $input = [
            'type' => 'text'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('text', $result['type']);
    }

    /**
     * Test propertiesFilter with type double(x) format (single precision value)
     * Note: The code regex only supports single values in parentheses, not double(x,y)
     */
    public function testPropertiesFilterWithTypeDoubleWithPrecision(): void
    {
        $input = [
            'type' => 'double(24)'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('double', $result['type']);
        $this->assertEquals('24', $result['max']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 0
     */
    public function testCustomFilterAttributeVisibleZero(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertEmpty($result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 1
     */
    public function testCustomFilterAttributeVisibleOne(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('create', $result['visible']);
        $this->assertContains('update', $result['visible']);
        $this->assertContains('read', $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 2
     */
    public function testCustomFilterAttributeVisibleTwo(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(2);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('read', $result['visible']);
        $this->assertNotContains('create', $result['visible']);
        $this->assertNotContains('update', $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 3
     */
    public function testCustomFilterAttributeVisibleThree(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(3);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('create', $result['visible']);
        $this->assertContains('update', $result['visible']);
        $this->assertContains('read', $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 4
     */
    public function testCustomFilterAttributeVisibleFour(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(4);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('update', $result['visible']);
        $this->assertContains('read', $result['visible']);
        $this->assertNotContains('create', $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with visible = 5
     */
    public function testCustomFilterAttributeVisibleFive(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('read', $result['visible']);
    }

    /**
     * Test _customFilterAttributeVisible with negative value (uses absolute)
     */
    public function testCustomFilterAttributeVisibleNegative(): void
    {
        $result = $this->helper->_customFilterAttributeVisible(-1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        // abs(-1) = 1, so should have create, update, read
        $this->assertContains('create', $result['visible']);
        $this->assertContains('update', $result['visible']);
        $this->assertContains('read', $result['visible']);
    }

    /**
     * Test getListOfForeignKeys returns array
     */
    public function testGetListOfForeignKeysReturnsArray(): void
    {
        $result = $this->helper->getListOfForeignKeys();

        $this->assertIsArray($result);
    }

    /**
     * Test setGlobalMaxImageSize
     */
    public function testSetGlobalMaxImageSize(): void
    {
        global $conf;

        $this->helper->setGlobalMaxImageSize(800, 600, 85);

        $this->assertEquals(800, $conf->cache['smartmakers']['photo']['maxWidth']);
        $this->assertEquals(600, $conf->cache['smartmakers']['photo']['maxHeight']);
        $this->assertEquals(85, $conf->cache['smartmakers']['photo']['quality']);
    }

    /**
     * Test setGlobalMaxImageSize with default quality
     */
    public function testSetGlobalMaxImageSizeDefaultQuality(): void
    {
        global $conf;

        $this->helper->setGlobalMaxImageSize(1024, 768);

        $this->assertEquals(1024, $conf->cache['smartmakers']['photo']['maxWidth']);
        $this->assertEquals(768, $conf->cache['smartmakers']['photo']['maxHeight']);
        $this->assertEquals(90, $conf->cache['smartmakers']['photo']['quality']);
    }

    /**
     * Test propertiesFilter with multiple attributes
     */
    public function testPropertiesFilterWithMultipleAttributes(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
            $langs->loadLangs(['companies']);
        }

        $input = [
            'name' => 'testfield',
            'type' => 'varchar(100)',
            'label' => 'TestField',
            'notnull' => 1,
            'visible' => 1,
            'position' => 10
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('label', $result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertArrayHasKey('position', $result);
    }

    /**
     * Test propertiesFilter with parent override
     */
    public function testPropertiesFilterWithParentOverride(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
            $langs->loadLangs(['companies']);
        }

        $input = [
            'type' => 'varchar(50)',
            'label' => 'OriginalLabel',
            'visible' => 1
        ];

        $parentOverride = [
            'testkey' => [
                'type' => 'text',
                'visible' => 0
            ]
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey', $parentOverride);

        $this->assertIsArray($result);
        // Parent override should have changed the type
        $this->assertArrayHasKey('type', $result);
    }

    /**
     * Test propertiesFilter ignores unknown attributes
     */
    public function testPropertiesFilterIgnoresUnknownAttributes(): void
    {
        $input = [
            'unknownattr' => 'somevalue',
            'anotherunk' => 123
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Helper to create a complete mock extrafields object
     */
    private function createMockExtrafields(string $element, string $fieldName, array $overrides = []): \stdClass
    {
        $extrafields = new \stdClass();
        $defaults = [
            'type' => 'varchar(255)',
            'label' => 'Test Field',
            'pos' => 10,
            'required' => 0,
            'visible' => 1,
            'help' => '',
            'size' => 255,
            'placeholder' => '',
            'picto' => '',
            'default' => '',
            'copytoclipboard' => 0,
            'noteditable' => 0,
            'options' => []
        ];

        $extrafields->attributes = [$element => []];
        foreach (array_merge($defaults, $overrides) as $key => $value) {
            $extrafields->attributes[$element][$key] = [$fieldName => $value];
        }

        return $extrafields;
    }

    /**
     * Test extrafieldsFilter returns array with is_extrafield flag
     */
    public function testExtrafieldsFilterReturnsIsExtrafield(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'testfield', [
            'label' => 'Test Label',
            'required' => 1
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'options_testfield', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('is_extrafield', $result);
        $this->assertTrue($result['is_extrafield']);
    }

    /**
     * Test extrafieldsFilter with smart photo prefix
     */
    public function testExtrafieldsFilterWithSmartPhotoPrefix(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'smartphoto_test', [
            'label' => 'Photo Field'
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'smartphoto_test', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('photos', $result['type']);
        $this->assertArrayHasKey('visible', $result);
        $this->assertContains('create', $result['visible']);
        $this->assertContains('update', $result['visible']);
        $this->assertContains('read', $result['visible']);
        $this->assertArrayHasKey('compressOptions', $result);
    }

    /**
     * Test extrafieldsFilter with smart audio prefix
     */
    public function testExtrafieldsFilterWithSmartAudioPrefix(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'smartaudio_test', [
            'label' => 'Audio Field'
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'smartaudio_test', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('audios', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smart video prefix
     */
    public function testExtrafieldsFilterWithSmartVideoPrefix(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'smartvideo_test', [
            'label' => 'Video Field'
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'smartvideo_test', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('videos', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smart file prefix
     */
    public function testExtrafieldsFilterWithSmartFilePrefix(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'smartfile_test', [
            'label' => 'File Field'
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'smartfile_test', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('files', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smart signature prefix
     */
    public function testExtrafieldsFilterWithSmartSignaturePrefix(): void
    {
        global $langs;

        // Ensure langs is available
        if (!isset($langs) || !is_object($langs)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $extrafields = $this->createMockExtrafields('test_element', 'smartsignature_test', [
            'label' => 'Signature Field'
        ]);

        $result = $this->helper->extrafieldsFilter('test_element', 'smartsignature_test', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('signature', $result['type']);
    }

    /**
     * Test propertiesFilter with visible attribute triggers custom filter
     */
    public function testPropertiesFilterWithVisibleAttribute(): void
    {
        $input = [
            'visible' => 1
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('visible', $result);
        $this->assertIsArray($result['visible']);
    }

    /**
     * Test propertiesFilter with options attribute
     */
    public function testPropertiesFilterWithOptionsAttribute(): void
    {
        $input = [
            'options' => [
                'opt1' => 'Option 1',
                'opt2' => 'Option 2'
            ]
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('options', $result);
    }
}
