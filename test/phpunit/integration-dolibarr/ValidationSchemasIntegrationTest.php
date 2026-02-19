<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ValidationSchemas.php';
require_once __DIR__ . '/../../../api/InputSanitizer.php';

use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\InputSanitizer;

/**
 * Integration tests for ValidationSchemas
 *
 * @covers \SmartAuth\Api\ValidationSchemas
 */
class ValidationSchemasIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache before each test
        ValidationSchemas::clearCache();
    }

    protected function tearDown(): void
    {
        ValidationSchemas::clearCache();
        parent::tearDown();
    }

    // =========================================================================
    // getSchema() tests
    // =========================================================================

    public function testGetSchemaReturnsLoginSchema(): void
    {
        $schema = ValidationSchemas::getSchema('login');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('email', $schema);
        $this->assertArrayHasKey('username', $schema);
        $this->assertArrayHasKey('password', $schema);
        $this->assertArrayHasKey('entity', $schema);
        $this->assertArrayHasKey('uuid', $schema);
        $this->assertArrayHasKey('device_label', $schema);
    }

    public function testGetSchemaReturnsDeviceSchema(): void
    {
        $schema = ValidationSchemas::getSchema('device');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('uuid', $schema);
        $this->assertArrayHasKey('label', $schema);
        $this->assertTrue($schema['uuid']['required']);
    }

    public function testGetSchemaReturnsRefreshSchema(): void
    {
        $schema = ValidationSchemas::getSchema('refresh');

        $this->assertIsArray($schema);
        $this->assertEmpty($schema); // No body params for refresh
    }

    public function testGetSchemaReturnsNullForUnknownEndpoint(): void
    {
        $schema = ValidationSchemas::getSchema('unknown_endpoint');

        $this->assertNull($schema);
    }

    public function testGetSchemaReturnsGetParamsSchema(): void
    {
        $schema = ValidationSchemas::getSchema('get_params');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('id', $schema);
        $this->assertArrayHasKey('limit', $schema);
        $this->assertArrayHasKey('offset', $schema);
        $this->assertArrayHasKey('sortfield', $schema);
        $this->assertArrayHasKey('sortorder', $schema);
    }

    // =========================================================================
    // Schema field validation rules tests
    // =========================================================================

    public function testLoginSchemaFieldTypes(): void
    {
        $schema = ValidationSchemas::getSchema('login');

        $this->assertEquals(InputSanitizer::TYPE_EMAIL, $schema['email']['type']);
        $this->assertEquals(InputSanitizer::TYPE_RAW, $schema['password']['type']);
        $this->assertEquals(InputSanitizer::TYPE_INT, $schema['entity']['type']);
        $this->assertEquals(InputSanitizer::TYPE_UUID, $schema['uuid']['type']);
        $this->assertEquals(InputSanitizer::TYPE_STRING, $schema['device_label']['type']);
    }

    public function testLoginSchemaRequiredFields(): void
    {
        $schema = ValidationSchemas::getSchema('login');

        $this->assertTrue($schema['password']['required']);
        $this->assertFalse($schema['email']['required']);
        $this->assertFalse($schema['username']['required']);
        $this->assertFalse($schema['uuid']['required']);
    }

    public function testDeviceSchemaFieldConstraints(): void
    {
        $schema = ValidationSchemas::getSchema('device');

        $this->assertTrue($schema['uuid']['required']);
        $this->assertEquals(InputSanitizer::TYPE_UUID, $schema['uuid']['type']);
        $this->assertFalse($schema['label']['required']);
        $this->assertEquals(100, $schema['label']['maxLen']);
    }

    public function testGetParamsSchemaConstraints(): void
    {
        $schema = ValidationSchemas::getSchema('get_params');

        // Limit constraints
        $this->assertEquals(50, $schema['limit']['default']);
        $this->assertEquals(1, $schema['limit']['min']);
        $this->assertEquals(500, $schema['limit']['max']);

        // Offset constraints
        $this->assertEquals(0, $schema['offset']['default']);
        $this->assertEquals(0, $schema['offset']['min']);

        // ID constraints
        $this->assertEquals(0, $schema['id']['min']);
    }

    // =========================================================================
    // getSchemaForModule() tests
    // =========================================================================

    public function testGetSchemaForModuleReturnsSmartauthSchemas(): void
    {
        $schema = ValidationSchemas::getSchemaForModule('smartauth', 'login');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('password', $schema);
    }

    public function testGetSchemaForModuleReturnsNullForUnknownModule(): void
    {
        $schema = ValidationSchemas::getSchemaForModule('unknownmodule', 'someendpoint');

        $this->assertNull($schema);
    }

    // =========================================================================
    // getAllSchemas() tests
    // =========================================================================

    public function testGetAllSchemasReturnsAllBuiltInSchemas(): void
    {
        $schemas = ValidationSchemas::getAllSchemas();

        $this->assertArrayHasKey('login', $schemas);
        $this->assertArrayHasKey('device', $schemas);
        $this->assertArrayHasKey('refresh', $schemas);
        $this->assertArrayHasKey('logout', $schemas);
        $this->assertArrayHasKey('index', $schemas);
        $this->assertArrayHasKey('ping', $schemas);
        $this->assertArrayHasKey('get_params', $schemas);
    }

    public function testGetAllSchemasWithoutExternalModules(): void
    {
        $schemas = ValidationSchemas::getAllSchemas(false);

        // Should only contain built-in schemas
        $this->assertCount(7, $schemas);
    }

    // =========================================================================
    // clearCache() tests
    // =========================================================================

    public function testClearCacheResetsExternalSchemas(): void
    {
        // Load external schemas (will be empty but cached)
        ValidationSchemas::loadExternalSchemas();

        // Clear cache
        ValidationSchemas::clearCache();

        // Verify by loading again - should work without error
        $schemas = ValidationSchemas::loadExternalSchemas();
        $this->assertIsArray($schemas);
    }

    // =========================================================================
    // loadExternalSchemas() tests
    // =========================================================================

    public function testLoadExternalSchemasReturnsEmptyArrayWithoutHookmanager(): void
    {
        global $hookmanager;
        $originalHookmanager = $hookmanager ?? null;
        $hookmanager = null;

        ValidationSchemas::clearCache();
        $schemas = ValidationSchemas::loadExternalSchemas();

        $this->assertIsArray($schemas);
        $this->assertEmpty($schemas);

        // Restore
        $hookmanager = $originalHookmanager;
    }

    public function testLoadExternalSchemasCachesResult(): void
    {
        // First load
        $schemas1 = ValidationSchemas::loadExternalSchemas();

        // Second load without force reload - should use cache
        $schemas2 = ValidationSchemas::loadExternalSchemas();

        $this->assertEquals($schemas1, $schemas2);
    }

    public function testLoadExternalSchemasForceReload(): void
    {
        // Load and cache
        ValidationSchemas::loadExternalSchemas();

        // Force reload
        $schemas = ValidationSchemas::loadExternalSchemas(true);

        $this->assertIsArray($schemas);
    }

    // =========================================================================
    // mapRouteToSchema() tests
    // =========================================================================

    public function testMapRouteToSchemaDirectMatch(): void
    {
        $this->assertEquals('login', ValidationSchemas::mapRouteToSchema('login'));
        $this->assertEquals('logout', ValidationSchemas::mapRouteToSchema('logout'));
        $this->assertEquals('device', ValidationSchemas::mapRouteToSchema('device'));
        $this->assertEquals('refresh', ValidationSchemas::mapRouteToSchema('refresh'));
        $this->assertEquals('index', ValidationSchemas::mapRouteToSchema('index'));
        $this->assertEquals('ping', ValidationSchemas::mapRouteToSchema('ping'));
    }

    public function testMapRouteToSchemaRemovesLeadingSlash(): void
    {
        $this->assertEquals('login', ValidationSchemas::mapRouteToSchema('/login'));
        $this->assertEquals('device', ValidationSchemas::mapRouteToSchema('/device'));
    }

    public function testMapRouteToSchemaExtractsBaseRoute(): void
    {
        $this->assertEquals('login', ValidationSchemas::mapRouteToSchema('login/extra'));
        $this->assertEquals('device', ValidationSchemas::mapRouteToSchema('/device/123'));
    }

    public function testMapRouteToSchemaReturnsDefaultForUnknownRoute(): void
    {
        $this->assertEquals('default', ValidationSchemas::mapRouteToSchema('unknown'));
        $this->assertEquals('default', ValidationSchemas::mapRouteToSchema('/some/random/path'));
    }

    // =========================================================================
    // getEnumWhitelists() tests
    // =========================================================================

    public function testGetEnumWhitelistsReturnsAllEnums(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertIsArray($whitelists);
        $this->assertArrayHasKey('auth_element', $whitelists);
        $this->assertArrayHasKey('token_type', $whitelists);
        $this->assertArrayHasKey('http_method', $whitelists);
        $this->assertArrayHasKey('content_type', $whitelists);
        $this->assertArrayHasKey('status', $whitelists);
    }

    public function testGetEnumWhitelistsAuthElementValues(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertContains('user', $whitelists['auth_element']);
        $this->assertContains('societe_account', $whitelists['auth_element']);
    }

    public function testGetEnumWhitelistsTokenTypeValues(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertContains('access', $whitelists['token_type']);
        $this->assertContains('refresh', $whitelists['token_type']);
    }

    public function testGetEnumWhitelistsHttpMethodValues(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertContains('GET', $whitelists['http_method']);
        $this->assertContains('POST', $whitelists['http_method']);
        $this->assertContains('PUT', $whitelists['http_method']);
        $this->assertContains('DELETE', $whitelists['http_method']);
        $this->assertContains('PATCH', $whitelists['http_method']);
    }

    public function testGetEnumWhitelistsStatusValues(): void
    {
        $whitelists = ValidationSchemas::getEnumWhitelists();

        $this->assertContains(0, $whitelists['status']); // draft
        $this->assertContains(1, $whitelists['status']); // valid
        $this->assertContains(9, $whitelists['status']); // logout
    }

    // =========================================================================
    // validateEnum() tests
    // =========================================================================

    public function testValidateEnumReturnsValueWhenValid(): void
    {
        $result = ValidationSchemas::validateEnum('token_type', 'access');
        $this->assertEquals('access', $result);

        $result = ValidationSchemas::validateEnum('http_method', 'POST');
        $this->assertEquals('POST', $result);
    }

    public function testValidateEnumReturnsDefaultWhenInvalid(): void
    {
        $result = ValidationSchemas::validateEnum('token_type', 'invalid', 'access');
        $this->assertEquals('access', $result);
    }

    public function testValidateEnumReturnsNullWhenInvalidNoDefault(): void
    {
        $result = ValidationSchemas::validateEnum('token_type', 'invalid');
        $this->assertNull($result);
    }

    public function testValidateEnumReturnsDefaultForUnknownEnumName(): void
    {
        $result = ValidationSchemas::validateEnum('unknown_enum', 'value', 'default');
        $this->assertEquals('default', $result);
    }

    public function testValidateEnumWithIntegerValues(): void
    {
        $result = ValidationSchemas::validateEnum('status', 1);
        $this->assertEquals(1, $result);

        $result = ValidationSchemas::validateEnum('status', 0);
        $this->assertEquals(0, $result);

        $result = ValidationSchemas::validateEnum('status', 999, 0);
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Schema completeness tests
    // =========================================================================

    public function testAllSchemasHaveValidTypes(): void
    {
        $validTypes = [
            InputSanitizer::TYPE_STRING,
            InputSanitizer::TYPE_INT,
            InputSanitizer::TYPE_FLOAT,
            InputSanitizer::TYPE_BOOL,
            InputSanitizer::TYPE_EMAIL,
            InputSanitizer::TYPE_UUID,
            InputSanitizer::TYPE_RAW,
            InputSanitizer::TYPE_ARRAY,
            InputSanitizer::TYPE_ALPHANUMERIC,
        ];

        $schemas = ValidationSchemas::getAllSchemas();

        foreach ($schemas as $endpointName => $schema) {
            foreach ($schema as $fieldName => $fieldDef) {
                if (isset($fieldDef['type'])) {
                    $this->assertContains(
                        $fieldDef['type'],
                        $validTypes,
                        "Field '$fieldName' in '$endpointName' has invalid type: {$fieldDef['type']}"
                    );
                }
            }
        }
    }

    public function testSchemaFieldsHaveRequiredAttribute(): void
    {
        $schemas = ValidationSchemas::getAllSchemas();
        $fieldsToCheck = ['login', 'device'];

        foreach ($fieldsToCheck as $endpoint) {
            if (!isset($schemas[$endpoint])) {
                continue;
            }

            foreach ($schemas[$endpoint] as $fieldName => $fieldDef) {
                $this->assertArrayHasKey(
                    'required',
                    $fieldDef,
                    "Field '$fieldName' in '$endpoint' should have 'required' attribute"
                );
            }
        }
    }

    public function testEntityFieldHasCorrectDefaults(): void
    {
        $schema = ValidationSchemas::getSchema('login');

        $this->assertEquals(0, $schema['entity']['default']);
        $this->assertEquals(0, $schema['entity']['min']);
    }
}
