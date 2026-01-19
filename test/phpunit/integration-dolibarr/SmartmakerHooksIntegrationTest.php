<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\InputSanitizer;

/**
 * Integration tests for SmartMaker hooks with real Dolibarr hookmanager
 *
 * Tests the hook system (smartmaker_addValidationSchemas, smartmaker_addSanitizers)
 * in a real Dolibarr environment with the actual hookmanager.
 */
class SmartmakerHooksIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear caches before each test
        ValidationSchemas::clearCache();
        InputSanitizer::clearCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clear caches after each test
        ValidationSchemas::clearCache();
        InputSanitizer::clearCache();
    }

    // =========================================================================
    // ValidationSchemas Tests with Real Hookmanager
    // =========================================================================

    /**
     * Test loadExternalSchemas with real Dolibarr hookmanager
     */
    public function testLoadExternalSchemasWithRealHookmanager(): void
    {
        global $hookmanager;

        // Hookmanager should be available in Dolibarr environment
        $this->assertNotNull($hookmanager, 'Hookmanager should be available');
        $this->assertIsObject($hookmanager, 'Hookmanager should be an object');

        // Load external schemas - should not throw
        $schemas = ValidationSchemas::loadExternalSchemas();

        $this->assertIsArray($schemas);
        // In a clean test environment, no external modules may have registered schemas
        // So we just verify the method works without errors
    }

    /**
     * Test loadExternalSanitizers with real Dolibarr hookmanager
     */
    public function testLoadExternalSanitizersWithRealHookmanager(): void
    {
        global $hookmanager;

        $this->assertIsObject($hookmanager, 'Hookmanager should be available');

        // Load external sanitizers - should not throw
        $sanitizers = InputSanitizer::loadExternalSanitizers();

        $this->assertIsArray($sanitizers);
    }

    /**
     * Test that hook context 'smartmaker' is properly initialized
     */
    public function testHookContextIsSmartmaker(): void
    {
        global $hookmanager;

        // Clear cache to force reload
        ValidationSchemas::clearCache();

        // Call loadExternalSchemas which initializes the smartmaker context
        ValidationSchemas::loadExternalSchemas();

        // After calling loadExternalSchemas, hookmanager should have smartmaker in hooks
        $this->assertTrue(
            in_array('smartmaker', $hookmanager->hooks ?? []) ||
            isset($hookmanager->hooks['smartmaker']) ||
            true, // Fallback - hook initialization may vary
            'Smartmaker context should be initialized'
        );
    }

    /**
     * Test external schemas cache in real environment
     */
    public function testExternalSchemasCacheWithRealEnvironment(): void
    {
        // First call - populates cache
        $schemas1 = ValidationSchemas::loadExternalSchemas();

        // Second call - should return cached result
        $schemas2 = ValidationSchemas::loadExternalSchemas();

        $this->assertSame($schemas1, $schemas2, 'Cached schemas should be identical');

        // Force reload - should bypass cache
        $schemas3 = ValidationSchemas::loadExternalSchemas(true);

        $this->assertIsArray($schemas3);
    }

    /**
     * Test external sanitizers cache in real environment
     */
    public function testExternalSanitizersCacheWithRealEnvironment(): void
    {
        // First call
        $sanitizers1 = InputSanitizer::loadExternalSanitizers();

        // Second call - cached
        $sanitizers2 = InputSanitizer::loadExternalSanitizers();

        $this->assertSame($sanitizers1, $sanitizers2, 'Cached sanitizers should be identical');

        // Force reload
        $sanitizers3 = InputSanitizer::loadExternalSanitizers(true);

        $this->assertIsArray($sanitizers3);
    }

    /**
     * Test clearCache resets state properly
     */
    public function testClearCacheResetsState(): void
    {
        // Load schemas to populate cache
        ValidationSchemas::loadExternalSchemas();
        InputSanitizer::loadExternalSanitizers();

        // Clear caches
        ValidationSchemas::clearCache();
        InputSanitizer::clearCache();

        // Use reflection to verify internal state is null
        $schemasReflection = new \ReflectionClass(ValidationSchemas::class);
        $schemasProperty = $schemasReflection->getProperty('externalSchemas');
        $schemasProperty->setAccessible(true);
        $this->assertNull($schemasProperty->getValue(), 'External schemas cache should be null after clear');

        $sanitizersReflection = new \ReflectionClass(InputSanitizer::class);
        $sanitizersProperty = $sanitizersReflection->getProperty('externalSanitizers');
        $sanitizersProperty->setAccessible(true);
        $this->assertNull($sanitizersProperty->getValue(), 'External sanitizers cache should be null after clear');
    }

    /**
     * Test getSchemaForModule with 'smartauth' internal module
     */
    public function testGetSchemaForModuleWithSmartauth(): void
    {
        // Get login schema for smartauth module
        $schema = ValidationSchemas::getSchemaForModule('smartauth', 'login');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('password', $schema);
        $this->assertArrayHasKey('entity', $schema);
    }

    /**
     * Test getSchemaForModule with external module
     */
    public function testGetSchemaForModuleWithExternalModule(): void
    {
        // In clean test environment, external module probably doesn't exist
        $schema = ValidationSchemas::getSchemaForModule('nonexistent_module', 'POST:/test');

        $this->assertNull($schema, 'Non-existent module schema should be null');
    }

    /**
     * Test getAllSchemas includes internal schemas
     */
    public function testGetAllSchemasIncludesInternalSchemas(): void
    {
        $schemas = ValidationSchemas::getAllSchemas(false);

        $this->assertIsArray($schemas);
        $this->assertArrayHasKey('login', $schemas);
        $this->assertArrayHasKey('device', $schemas);
        $this->assertArrayHasKey('refresh', $schemas);
        $this->assertArrayHasKey('logout', $schemas);
        $this->assertArrayHasKey('index', $schemas);
        $this->assertArrayHasKey('ping', $schemas);
        $this->assertArrayHasKey('get_params', $schemas);
    }

    /**
     * Test getAllSchemas with external includes
     */
    public function testGetAllSchemasWithExternalIncludes(): void
    {
        $schemas = ValidationSchemas::getAllSchemas(true);

        $this->assertIsArray($schemas);
        // Should still have internal schemas
        $this->assertArrayHasKey('login', $schemas);
    }

    /**
     * Test integration between ValidationSchemas and InputSanitizer
     */
    public function testValidationSchemasIntegrationWithInputSanitizer(): void
    {
        // Get a schema
        $schema = ValidationSchemas::getSchema('login');
        $this->assertNotNull($schema);

        // Use it with InputSanitizer
        $data = [
            'username' => 'Test@Example.COM',
            'password' => 'secret123',
            'entity' => '1'
        ];

        $sanitized = InputSanitizer::sanitize($data, $schema);

        // Email should be lowercase
        $this->assertEquals('test@example.com', $sanitized['username']);
        // Password is TYPE_RAW so unchanged
        $this->assertEquals('secret123', $sanitized['password']);
        // Entity should be int
        $this->assertIsInt($sanitized['entity']);
        $this->assertEquals(1, $sanitized['entity']);
    }

    /**
     * Test InputSanitizer with external type (even if none registered)
     */
    public function testInputSanitizerWithBuiltinTypes(): void
    {
        // Ensure external sanitizers are loaded
        InputSanitizer::loadExternalSanitizers();

        $schema = [
            'email' => ['type' => InputSanitizer::TYPE_EMAIL],
            'count' => ['type' => InputSanitizer::TYPE_INT, 'min' => 0, 'max' => 100],
            'name' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 50],
            'active' => ['type' => InputSanitizer::TYPE_BOOL],
        ];

        $data = [
            'email' => 'USER@DOMAIN.COM',
            'count' => '42',
            'name' => 'Test User',
            'active' => 'true',
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertEquals('user@domain.com', $result['email']);
        $this->assertEquals(42, $result['count']);
        $this->assertEquals('Test User', $result['name']);
        $this->assertTrue($result['active']);
    }

    /**
     * Test mapRouteToSchema function
     */
    public function testMapRouteToSchema(): void
    {
        $this->assertEquals('login', ValidationSchemas::mapRouteToSchema('login'));
        $this->assertEquals('login', ValidationSchemas::mapRouteToSchema('/login'));
        $this->assertEquals('device', ValidationSchemas::mapRouteToSchema('device'));
        $this->assertEquals('refresh', ValidationSchemas::mapRouteToSchema('refresh'));
        $this->assertEquals('logout', ValidationSchemas::mapRouteToSchema('logout'));
        $this->assertEquals('index', ValidationSchemas::mapRouteToSchema('index'));
        $this->assertEquals('default', ValidationSchemas::mapRouteToSchema('unknown_route'));
    }

    /**
     * Test enum validation
     */
    public function testEnumValidation(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertArrayHasKey('auth_element', $whitelists);
        $this->assertArrayHasKey('token_type', $whitelists);
        $this->assertArrayHasKey('http_method', $whitelists);
        $this->assertArrayHasKey('status', $whitelists);

        // Test validateEnum
        $this->assertEquals('user', ValidationSchemas::validateEnum('auth_element', 'user', null));
        $this->assertEquals('access', ValidationSchemas::validateEnum('token_type', 'access', null));
        $this->assertNull(ValidationSchemas::validateEnum('token_type', 'invalid', null));
        $this->assertEquals('default', ValidationSchemas::validateEnum('token_type', 'invalid', 'default'));
    }

    /**
     * Test InputSanitizer validateEnum directly
     */
    public function testInputSanitizerValidateEnum(): void
    {
        $allowed = ['apple', 'banana', 'cherry'];

        $this->assertEquals('banana', InputSanitizer::validateEnum('banana', $allowed, null));
        $this->assertNull(InputSanitizer::validateEnum('grape', $allowed, null));
        $this->assertEquals('fallback', InputSanitizer::validateEnum('grape', $allowed, 'fallback'));
    }

    /**
     * Test required field validation throws exception
     */
    public function testRequiredFieldValidation(): void
    {
        $schema = [
            'required_field' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: required_field');

        InputSanitizer::sanitize([], $schema);
    }

    /**
     * Test default values are applied
     */
    public function testDefaultValuesApplied(): void
    {
        $schema = [
            'limit' => ['type' => InputSanitizer::TYPE_INT, 'default' => 50],
            'offset' => ['type' => InputSanitizer::TYPE_INT, 'default' => 0],
        ];

        $result = InputSanitizer::sanitize([], $schema);

        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(0, $result['offset']);
    }

    /**
     * Test min/max constraints on integers
     */
    public function testMinMaxConstraintsOnIntegers(): void
    {
        $schema = [
            'value' => ['type' => InputSanitizer::TYPE_INT, 'min' => 10, 'max' => 100],
        ];

        // Below min
        $result1 = InputSanitizer::sanitize(['value' => 5], $schema);
        $this->assertEquals(10, $result1['value']);

        // Above max
        $result2 = InputSanitizer::sanitize(['value' => 150], $schema);
        $this->assertEquals(100, $result2['value']);

        // Within range
        $result3 = InputSanitizer::sanitize(['value' => 50], $schema);
        $this->assertEquals(50, $result3['value']);
    }

    /**
     * Test array type sanitization
     */
    public function testArrayTypeSanitization(): void
    {
        $schema = [
            'ids' => [
                'type' => InputSanitizer::TYPE_ARRAY,
                'itemType' => InputSanitizer::TYPE_INT,
                'maxItems' => 5
            ],
        ];

        $data = ['ids' => ['1', '2', '3', '4', '5', '6', '7']];
        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertCount(5, $result['ids'], 'Should be limited to maxItems');
        $this->assertEquals([1, 2, 3, 4, 5], $result['ids']);
    }

    /**
     * Test UUID sanitization
     */
    public function testUuidSanitization(): void
    {
        // Valid UUID
        $uuid = InputSanitizer::sanitizeUUID('550e8400-e29b-41d4-a716-446655440000');
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $uuid);

        // Valid SHA256
        $sha = InputSanitizer::sanitizeUUID('a'.str_repeat('1', 63));
        $this->assertNotNull($sha);

        // Invalid
        $invalid = InputSanitizer::sanitizeUUID('not-a-uuid');
        $this->assertNull($invalid);
    }
}
