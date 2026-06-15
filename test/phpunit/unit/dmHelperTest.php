<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\DolibarrMapping\dmHelper;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for dmHelper
 *
 * @covers \SmartAuth\DolibarrMapping\dmHelper
 */
class dmHelperTest extends TestCase
{
    private dmHelper $helper;

    /**
     * Helper to access private/protected methods
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(dmHelper::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    protected function setUp(): void
    {
        global $conf, $langs, $db;

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

        // Reset $db to a minimal mock so unit tests do not inherit the
        // anonymous mocks installed by other suites (e.g. RouteControllerTest)
        // which omit methods like fetch_object/free that this helper uses.
        $db = new class {
            public $type = 'mysqli';
            public function escape($val)
            {
                return $val;
            }
            public function query($sql)
            {
                return false;
            }
            public function fetch_object($r)
            {
                return false;
            }
            public function free($r)
            {
            }
            public function lasterror()
            {
                return 'mock db: no real connection';
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

    // =========================================================================
    // Tests for private _customFilterAttributeType method
    // =========================================================================

    /**
     * Test _customFilterAttributeType with simple varchar
     */
    public function testCustomFilterAttributeTypeVarchar(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'varchar');

        $this->assertIsArray($result);
        $this->assertEquals('varchar', $result['type']);
    }

    /**
     * Test _customFilterAttributeType with varchar(30)
     */
    public function testCustomFilterAttributeTypeVarcharWithLength(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'varchar(30)');

        $this->assertEquals('varchar', $result['type']);
        $this->assertEquals('30', $result['max']);
    }

    /**
     * Test _customFilterAttributeType converts integer to int
     */
    public function testCustomFilterAttributeTypeInteger(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'integer');

        $this->assertEquals('int', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts double to float
     */
    public function testCustomFilterAttributeTypeDouble(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'double');

        $this->assertEquals('float', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts real to float
     */
    public function testCustomFilterAttributeTypeReal(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'real');

        $this->assertEquals('float', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts price to float
     */
    public function testCustomFilterAttributeTypePrice(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'price');

        $this->assertEquals('float', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts checkbox to boolean with switch variant
     */
    public function testCustomFilterAttributeTypeCheckbox(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'checkbox');

        $this->assertEquals('boolean', $result['type']);
        $this->assertEquals('switch', $result['typeVariant']);
    }

    /**
     * Test _customFilterAttributeType converts radio to boolean with radio variant
     */
    public function testCustomFilterAttributeTypeRadio(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'radio');

        $this->assertEquals('boolean', $result['type']);
        $this->assertEquals('radio', $result['typeVariant']);
    }

    /**
     * Test _customFilterAttributeType converts mail to email
     */
    public function testCustomFilterAttributeTypeMail(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'mail');

        $this->assertEquals('email', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts phone to phoneNumber
     */
    public function testCustomFilterAttributeTypePhone(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'phone');

        $this->assertEquals('phoneNumber', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts sellist to select
     */
    public function testCustomFilterAttributeTypeSellist(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'sellist');

        $this->assertEquals('select', $result['type']);
    }

    /**
     * Test _customFilterAttributeType converts chkbxlst to check with multiple
     */
    public function testCustomFilterAttributeTypeChkbxlst(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'chkbxlst');

        $this->assertEquals('check', $result['type']);
        $this->assertEquals('checkbox', $result['typeVariant']);
        $this->assertTrue($result['multiple']);
    }

    /**
     * Test _customFilterAttributeType converts ip to varchar
     */
    public function testCustomFilterAttributeTypeIp(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'ip');

        $this->assertEquals('varchar', $result['type']);
    }

    /**
     * Test _customFilterAttributeType keeps text as text
     */
    public function testCustomFilterAttributeTypeText(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'text');

        $this->assertEquals('text', $result['type']);
    }

    /**
     * Test _customFilterAttributeType with double(x,y) format
     */
    public function testCustomFilterAttributeTypeDoubleWithPrecision(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeType');

        $result = $method->invoke($this->helper, 'double(10)');

        $this->assertEquals('double', $result['type']);
        $this->assertEquals('10', $result['max']);
    }

    // =========================================================================
    // Tests for private _customFilterAttributeOptions method
    // =========================================================================

    /**
     * Test _customFilterAttributeOptions with array input
     */
    public function testCustomFilterAttributeOptionsWithArray(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeOptions');

        $input = [
            '1' => 'Option One',
            '2' => 'Option Two',
            '3' => 'Option Three'
        ];

        $result = $method->invoke($this->helper, $input);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('options', $result);
        $this->assertCount(3, $result['options']);
        $this->assertEquals('Option One', $result['options'][0]['label']);
        $this->assertEquals('1', $result['options'][0]['value']);
    }

    /**
     * Test _customFilterAttributeOptions with non-array input
     */
    public function testCustomFilterAttributeOptionsWithNonArray(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeOptions');

        $result = $method->invoke($this->helper, 'not an array');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // Tests for private _customFilterAttributeTypeSellist method
    // =========================================================================

    /**
     * Test _customFilterAttributeTypeSellist returns the {select, options}
     * shape now that the resolver replaces the historical TODO placeholder.
     * `sometable` is alphanumeric so identifier validation passes; the SQL
     * itself fails (table missing) and the resolver degrades gracefully to
     * an empty options list -- exactly what the FkPicker expects.
     */
    public function testCustomFilterAttributeTypeSellistReturnsSelectShape(): void
    {
        $method = $this->getPrivateMethod('_customFilterAttributeTypeSellist');

        $result = $method->invoke($this->helper, 'sellist:sometable:somefield');

        $this->assertIsArray($result);
        $this->assertSame('select', $result['type']);
        $this->assertArrayHasKey('options', $result);
        $this->assertSame([], $result['options']);
    }

    // =========================================================================
    // Tests for private _getCacheValue method
    // =========================================================================

    /**
     * Test _getCacheValue returns default when no cache
     */
    public function testGetCacheValueReturnsDefault(): void
    {
        global $conf;
        $conf->cache['smartmakers'] = [];

        $method = $this->getPrivateMethod('_getCacheValue');

        $result = $method->invoke($this->helper, 'photo', 'width', 1024);

        $this->assertEquals(1024, $result);
    }

    /**
     * Test _getCacheValue returns cached value when available
     */
    public function testGetCacheValueReturnsCachedValue(): void
    {
        global $conf;
        $conf->cache['smartmakers']['photo']['width'] = 800;

        $method = $this->getPrivateMethod('_getCacheValue');

        $result = $method->invoke($this->helper, 'photo', 'width', 1024);

        $this->assertEquals(800, $result);
    }

    // =========================================================================
    // Tests for extrafieldsFilter method
    // =========================================================================

    /**
     * Create a complete extrafields mock object
     */
    private function createExtrafieldsMock(string $element, string $fieldName, array $attributes): \stdClass
    {
        $extrafields = new \stdClass();
        $extrafields->attributes = [
            $element => []
        ];

        // Initialize all expected attributes with empty defaults
        $allAttributes = [
            'type', 'label', 'placeholder', 'help', 'picto', 'default',
            'copytoclipboard', 'required', 'noteditable', 'visible', 'size', 'pos', 'options'
        ];

        foreach ($allAttributes as $attr) {
            $extrafields->attributes[$element][$attr] = [];
            $extrafields->attributes[$element][$attr][$fieldName] = $attributes[$attr] ?? null;
        }

        return $extrafields;
    }

    /**
     * Test extrafieldsFilter returns is_extrafield flag
     */
    public function testExtrafieldsFilterReturnsIsExtrafieldFlag(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'test_field', [
            'type' => 'varchar',
            'label' => 'Test Label',
            'visible' => 1
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'options_test_field', 'frontkey', $extrafields);

        $this->assertIsArray($result);
        $this->assertTrue($result['is_extrafield']);
    }

    /**
     * Test extrafieldsFilter translates label
     */
    public function testExtrafieldsFilterTranslatesLabel(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'test_field', [
            'type' => 'varchar',
            'label' => 'My Label',
            'help' => 'My Help'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'options_test_field', 'frontkey', $extrafields);

        $this->assertEquals('My Label', $result['label']);
        $this->assertEquals('My Help', $result['help']);
    }

    /**
     * Test extrafieldsFilter handles required field
     */
    public function testExtrafieldsFilterHandlesRequired(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'test_field', [
            'type' => 'varchar',
            'required' => 1
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'options_test_field', 'frontkey', $extrafields);

        $this->assertTrue($result['required']);
    }

    /**
     * Test extrafieldsFilter with smartphoto_ prefix
     */
    public function testExtrafieldsFilterWithSmartphotoPrefix(): void
    {
        global $conf;
        $conf->cache['smartmakers'] = [];

        $extrafields = $this->createExtrafieldsMock('societe', 'smartphoto_image', [
            'type' => 'varchar',
            'label' => 'Photo'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartphoto_image', 'frontkey', $extrafields);

        $this->assertEquals('photos', $result['type']);
        $this->assertEquals(['create', 'update', 'read'], $result['visible']);
        $this->assertArrayHasKey('compressOptions', $result);
    }

    /**
     * Regression (todo l.31): a smartphoto_ field explicitly set to "not visible"
     * (Dolibarr visible code 0) must stay hidden on the app. The special-type
     * branch used to force visible to all contexts, ignoring the admin setting.
     */
    public function testExtrafieldsFilterSmartphotoRespectsHiddenVisibility(): void
    {
        global $conf;
        $conf->cache['smartmakers'] = [];

        $extrafields = $this->createExtrafieldsMock('societe', 'smartphoto_image', [
            'type' => 'varchar',
            'label' => 'Photo',
            'visible' => 0
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartphoto_image', 'frontkey', $extrafields);

        $this->assertEquals('photos', $result['type']);
        $this->assertEquals([], $result['visible']);
    }

    /**
     * Test extrafieldsFilter with smartaudio_ prefix
     */
    public function testExtrafieldsFilterWithSmartaudioPrefix(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'smartaudio_recording', [
            'type' => 'varchar',
            'label' => 'Recording'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartaudio_recording', 'frontkey', $extrafields);

        $this->assertEquals('audios', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smartvideo_ prefix
     */
    public function testExtrafieldsFilterWithSmartvideoPrefix(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'smartvideo_clip', [
            'type' => 'varchar',
            'label' => 'Video'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartvideo_clip', 'frontkey', $extrafields);

        $this->assertEquals('videos', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smartfile_ prefix
     */
    public function testExtrafieldsFilterWithSmartfilePrefix(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'smartfile_document', [
            'type' => 'varchar',
            'label' => 'Document'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartfile_document', 'frontkey', $extrafields);

        $this->assertEquals('files', $result['type']);
    }

    /**
     * Test extrafieldsFilter with smartsignature_ prefix
     */
    public function testExtrafieldsFilterWithSmartsignaturePrefix(): void
    {
        $extrafields = $this->createExtrafieldsMock('societe', 'smartsignature_sig', [
            'type' => 'varchar',
            'label' => 'Signature'
        ]);

        $result = $this->helper->extrafieldsFilter('societe', 'smartsignature_sig', 'frontkey', $extrafields);

        $this->assertEquals('signature', $result['type']);
    }

    /**
     * Test _customFilterAttributeContacts is an intentional empty-array
     * extension point. The contract is:
     *   1. The method MUST exist (so is_callable() returns true in
     *      propertiesFilter / extrafieldsFilter and the default
     *      $ret[$key] = $val branch is bypassed). This prevents raw
     *      Dolibarr contact lists from leaking into the API payload.
     *   2. The method MUST return [] (not null): callers use foreach on
     *      the result, and foreach(null) raises a TypeError on PHP 8+.
     *   3. Subclasses may override to expose a well-shaped contacts
     *      payload; the base class returns an empty array on purpose.
     */
    public function testCustomFilterAttributeContactsReturnsEmptyArray(): void
    {
        $this->assertTrue(
            is_callable([$this->helper, '_customFilterAttributeContacts']),
            'Stub must remain callable to suppress default mapping for the contacts attribute'
        );

        $result = $this->helper->_customFilterAttributeContacts('test');

        $this->assertSame(
            [],
            $result,
            'Stub must return [] (not null) so foreach() in callers stays type-safe on PHP 8+'
        );

        // Defensive: simulate the foreach loop the callers perform.
        // This proves the contract is type-safe even if a future change
        // accidentally reintroduces an implicit null return.
        $consumed = [];
        foreach ($result as $k => $v) {
            $consumed[$k] = $v;
        }
        $this->assertSame([], $consumed);
    }

    /**
     * Test propertiesFilter with type field
     */
    public function testPropertiesFilterWithTypeField(): void
    {
        $input = [
            'type' => 'varchar(50)'
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('varchar', $result['type']);
        $this->assertEquals('50', $result['max']);
    }

    /**
     * Test propertiesFilter with options array
     */
    public function testPropertiesFilterWithOptionsArray(): void
    {
        $input = [
            'options' => [
                'opt1' => 'Value 1',
                'opt2' => 'Value 2'
            ]
        ];

        $result = $this->helper->propertiesFilter($input, 'testkey', 'frontkey');

        $this->assertArrayHasKey('options', $result);
        $this->assertIsArray($result['options']);
        $this->assertCount(2, $result['options']);
    }
}
