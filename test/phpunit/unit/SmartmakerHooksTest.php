<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\InputSanitizer;

/**
 * Unit tests for SmartMaker hooks system
 * Tests ValidationSchemas and InputSanitizer external extension capabilities
 */
class SmartmakerHooksTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear caches before each test
        ValidationSchemas::clearCache();
        InputSanitizer::clearCache();
    }

    protected function tearDown(): void
    {
        // Clear caches after each test
        ValidationSchemas::clearCache();
        InputSanitizer::clearCache();

        // Reset global hookmanager
        global $hookmanager;
        $hookmanager = null;
    }

    // =============================================
    // ValidationSchemas tests
    // =============================================

    /**
     * Test loadExternalSchemas returns empty array when no hookmanager
     */
    public function testLoadExternalSchemasReturnsEmptyWithoutHookmanager(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $result = ValidationSchemas::loadExternalSchemas();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test loadExternalSchemas uses cache on second call
     */
    public function testLoadExternalSchemasCachesResult(): void
    {
        global $hookmanager;
        $hookmanager = null;

        // First call populates cache
        $result1 = ValidationSchemas::loadExternalSchemas();

        // Set hookmanager after first call - should not affect cached result
        $hookmanager = $this->createMockHookManager();

        // Second call should return cached result
        $result2 = ValidationSchemas::loadExternalSchemas();

        $this->assertSame($result1, $result2);
    }

    /**
     * Test loadExternalSchemas forceReload bypasses cache
     */
    public function testLoadExternalSchemasForceReload(): void
    {
        global $hookmanager;
        $hookmanager = null;

        // First call with no hookmanager
        ValidationSchemas::loadExternalSchemas();

        // Set hookmanager and force reload
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addValidationSchemas') {
                $object['testmodule'] = [
                    'POST:/test' => ['field1' => ['type' => 'string']]
                ];
            }
            return 0;
        });

        $result = ValidationSchemas::loadExternalSchemas(true);

        $this->assertArrayHasKey('testmodule', $result);
    }

    /**
     * Test clearCache resets the cache
     */
    public function testValidationSchemasClearCache(): void
    {
        global $hookmanager;

        // Setup mock that adds schemas
        $callCount = 0;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) use (&$callCount) {
            if ($hook === 'smartmaker_addValidationSchemas') {
                $callCount++;
                $object['module' . $callCount] = [];
            }
            return 0;
        });

        // First load
        $result1 = ValidationSchemas::loadExternalSchemas();
        $this->assertArrayHasKey('module1', $result1);

        // Clear and reload
        ValidationSchemas::clearCache();
        $result2 = ValidationSchemas::loadExternalSchemas();

        // Should have called hook again
        $this->assertEquals(2, $callCount);
        $this->assertArrayHasKey('module2', $result2);
    }

    /**
     * Test getSchemaForModule returns smartauth schema for 'smartauth' module
     */
    public function testGetSchemaForModuleSmartauth(): void
    {
        $schema = ValidationSchemas::getSchemaForModule('smartauth', 'login');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('password', $schema);
    }

    /**
     * Test getSchemaForModule returns external schema for other modules
     */
    public function testGetSchemaForModuleExternal(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addValidationSchemas') {
                $object['interventions'] = [
                    'POST:/interventions' => [
                        'client_id' => ['type' => 'int', 'required' => true],
                        'date' => ['type' => 'string', 'required' => true],
                    ]
                ];
            }
            return 0;
        });

        $schema = ValidationSchemas::getSchemaForModule('interventions', 'POST:/interventions');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('client_id', $schema);
        $this->assertArrayHasKey('date', $schema);
    }

    /**
     * Test getSchemaForModule returns null for non-existent module
     */
    public function testGetSchemaForModuleReturnsNullForUnknown(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $schema = ValidationSchemas::getSchemaForModule('nonexistent', 'POST:/test');

        $this->assertNull($schema);
    }

    /**
     * Test getAllSchemas without external includes only internal schemas
     */
    public function testGetAllSchemasWithoutExternal(): void
    {
        $schemas = ValidationSchemas::getAllSchemas(false);

        $this->assertArrayHasKey('login', $schemas);
        $this->assertArrayNotHasKey('testmodule:POST:/test', $schemas);
    }

    /**
     * Test getAllSchemas with external includes external schemas
     */
    public function testGetAllSchemasWithExternal(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addValidationSchemas') {
                $object['mymodule'] = [
                    'POST:/endpoint' => ['field' => ['type' => 'string']]
                ];
            }
            return 0;
        });

        $schemas = ValidationSchemas::getAllSchemas(true);

        $this->assertArrayHasKey('login', $schemas);
        $this->assertArrayHasKey('mymodule:POST:/endpoint', $schemas);
    }

    // =============================================
    // InputSanitizer tests
    // =============================================

    /**
     * Test loadExternalSanitizers returns empty array when no hookmanager
     */
    public function testLoadExternalSanitizersReturnsEmptyWithoutHookmanager(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $result = InputSanitizer::loadExternalSanitizers();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test loadExternalSanitizers uses cache on second call
     */
    public function testLoadExternalSanitizersCachesResult(): void
    {
        global $hookmanager;
        $hookmanager = null;

        $result1 = InputSanitizer::loadExternalSanitizers();

        $hookmanager = $this->createMockHookManager();

        $result2 = InputSanitizer::loadExternalSanitizers();

        $this->assertSame($result1, $result2);
    }

    /**
     * Test loadExternalSanitizers forceReload bypasses cache
     */
    public function testLoadExternalSanitizersForceReload(): void
    {
        global $hookmanager;
        $hookmanager = null;

        InputSanitizer::loadExternalSanitizers();

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addSanitizers') {
                $object['custom_type'] = function ($value, $rules, $field) {
                    return strtoupper($value);
                };
            }
            return 0;
        });

        $result = InputSanitizer::loadExternalSanitizers(true);

        $this->assertArrayHasKey('custom_type', $result);
        $this->assertIsCallable($result['custom_type']);
    }

    /**
     * Test clearCache resets the sanitizers cache
     */
    public function testInputSanitizerClearCache(): void
    {
        global $hookmanager;

        $callCount = 0;
        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) use (&$callCount) {
            if ($hook === 'smartmaker_addSanitizers') {
                $callCount++;
            }
            return 0;
        });

        InputSanitizer::loadExternalSanitizers();
        InputSanitizer::clearCache();
        InputSanitizer::loadExternalSanitizers();

        $this->assertEquals(2, $callCount);
    }

    /**
     * Test external sanitizer is called for custom type
     */
    public function testExternalSanitizerIsUsed(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addSanitizers') {
                $object['phone_fr'] = function ($value, $rules, $field) {
                    $clean = preg_replace('/[^0-9+]/', '', $value);
                    if (preg_match('/^(?:\+33|0)[1-9][0-9]{8}$/', $clean)) {
                        return $clean;
                    }
                    return null;
                };
            }
            return 0;
        });

        // Use sanitize method with a schema containing custom type
        $data = ['phone' => '06 12 34 56 78'];
        $schema = [
            'phone' => ['type' => 'phone_fr', 'required' => false]
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertEquals('0612345678', $result['phone']);
    }

    /**
     * Test external sanitizer returns null for invalid data
     */
    public function testExternalSanitizerReturnsNullForInvalid(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addSanitizers') {
                $object['phone_fr'] = function ($value, $rules, $field) {
                    $clean = preg_replace('/[^0-9+]/', '', $value);
                    if (preg_match('/^(?:\+33|0)[1-9][0-9]{8}$/', $clean)) {
                        return $clean;
                    }
                    return null;
                };
            }
            return 0;
        });

        $data = ['phone' => 'invalid'];
        $schema = [
            'phone' => ['type' => 'phone_fr', 'required' => false]
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertNull($result['phone']);
    }

    /**
     * Test external sanitizer throws exception for required invalid field
     */
    public function testExternalSanitizerThrowsForRequiredInvalid(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addSanitizers') {
                $object['siret'] = function ($value, $rules, $field) {
                    $clean = preg_replace('/[\s\-]/', '', $value);
                    if (preg_match('/^[0-9]{14}$/', $clean)) {
                        return $clean;
                    }
                    if ($rules['required'] ?? false) {
                        throw new \InvalidArgumentException("Invalid SIRET format for field: $field");
                    }
                    return null;
                };
            }
            return 0;
        });

        $data = ['siret' => 'invalid'];
        $schema = [
            'siret' => ['type' => 'siret', 'required' => true]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SIRET format for field: siret');

        InputSanitizer::sanitize($data, $schema);
    }

    /**
     * Test builtin types still work when external sanitizers are loaded
     */
    public function testBuiltinTypesStillWork(): void
    {
        global $hookmanager;

        $hookmanager = $this->createMockHookManager(function ($hook, $params, &$object, $action) {
            if ($hook === 'smartmaker_addSanitizers') {
                $object['custom'] = function ($value, $rules, $field) {
                    return 'custom';
                };
            }
            return 0;
        });

        $data = [
            'email' => 'TEST@EXAMPLE.COM',
            'count' => '42',
            'custom_field' => 'test'
        ];
        $schema = [
            'email' => ['type' => InputSanitizer::TYPE_EMAIL],
            'count' => ['type' => InputSanitizer::TYPE_INT],
            'custom_field' => ['type' => 'custom']
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals(42, $result['count']);
        $this->assertEquals('custom', $result['custom_field']);
    }

    // =============================================
    // Helper methods
    // =============================================

    /**
     * Create a mock HookManager
     *
     * @param callable|null $hookCallback Callback for executeHooks
     * @return object Mock hookmanager
     */
    private function createMockHookManager(?callable $hookCallback = null): object
    {
        $mock = new class($hookCallback) {
            private $callback;
            public $hooks = [];

            public function __construct(?callable $callback)
            {
                $this->callback = $callback;
            }

            public function initHooks(array $contexts): void
            {
                $this->hooks = $contexts;
            }

            public function executeHooks(string $hook, array $parameters, &$object, string $action): int
            {
                if ($this->callback) {
                    return call_user_func_array($this->callback, [$hook, $parameters, &$object, $action]);
                }
                return 0;
            }
        };

        return $mock;
    }
}
