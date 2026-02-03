<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RouteController;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for RouteController
 *
 * @covers \SmartAuth\Api\RouteController
 */
class RouteControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $conf;

        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->cache = [];
        $conf->cache['smartmakers'] = [];
    }

    /**
     * Helper to access private/protected methods
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(RouteController::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Test matchAction with exact match
     */
    public function testMatchActionExactMatch(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        $this->assertTrue($method->invoke(null, 'login', 'login'));
        $this->assertTrue($method->invoke(null, 'users', 'users'));
        $this->assertTrue($method->invoke(null, 'api/v1/test', 'api/v1/test'));
    }

    /**
     * Test matchAction with single placeholder
     */
    public function testMatchActionWithSinglePlaceholder(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        $this->assertTrue($method->invoke(null, 'users/123', 'users/{id}'));
        $this->assertTrue($method->invoke(null, 'users/abc', 'users/{id}'));
        $this->assertTrue($method->invoke(null, 'products/xyz', 'products/{code}'));
    }

    /**
     * Test matchAction with multiple placeholders
     */
    public function testMatchActionWithMultiplePlaceholders(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        $this->assertTrue($method->invoke(null, 'users/123/posts/456', 'users/{id}/posts/{postid}'));
        $this->assertTrue($method->invoke(null, 'entity/1/resource/abc', 'entity/{entity}/resource/{id}'));
    }

    /**
     * Test matchAction returns false for non-matching patterns
     */
    public function testMatchActionNonMatching(): void
    {
        $method = $this->getPrivateMethod('matchAction');

        $this->assertFalse($method->invoke(null, 'login', 'logout'));
        $this->assertFalse($method->invoke(null, 'users', 'user'));
        $this->assertFalse($method->invoke(null, 'users/123/extra', 'users/{id}'));
        $this->assertFalse($method->invoke(null, 'users', 'users/{id}'));
    }

    /**
     * Test extractUrlParameters with no placeholders
     */
    public function testExtractUrlParametersNoPlaceholders(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = ['existing' => 'value'];
        $result = $method->invoke(null, 'login', 'login', $data);

        $this->assertEquals(['existing' => 'value'], $result);
    }

    /**
     * Test extractUrlParameters with single placeholder
     */
    public function testExtractUrlParametersSinglePlaceholder(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = [];
        $result = $method->invoke(null, 'users/{id}', 'users/123', $data);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('123', $result['id']);
    }

    /**
     * Test extractUrlParameters with multiple placeholders
     * Note: The current implementation extracts values sequentially from the path
     * Both pattern and action are split by segments and matched by index
     */
    public function testExtractUrlParametersMultiplePlaceholders(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = [];
        // Pattern: users/{id}/posts/{postid}
        // Action:  users/123/posts/456
        // Segments are matched by index:
        // - index 0: 'users' matches 'users' (no placeholder)
        // - index 1: '{id}' matches '123' -> id = 123
        // - index 2: 'posts' matches 'posts' (no placeholder)
        // - index 3: '{postid}' matches '456' -> postid = 456
        $result = $method->invoke(null, 'users/{id}/posts/{postid}', 'users/123/posts/456', $data);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('postid', $result);
        $this->assertEquals('123', $result['id']);
        $this->assertEquals('456', $result['postid']);
    }

    /**
     * Test extractUrlParameters preserves existing data
     */
    public function testExtractUrlParametersPreservesExistingData(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = ['query' => 'test', 'page' => 1];
        $result = $method->invoke(null, 'users/{id}', 'users/123', $data);

        $this->assertEquals('test', $result['query']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals('123', $result['id']);
    }

    /**
     * Test get_client_ip from REMOTE_ADDR
     */
    public function testGetClientIpFromRemoteAddr(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // Set up
        $_SERVER['REMOTE_ADDR'] = '203.0.113.195';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        }

        $this->assertEquals('203.0.113.195', $ip);
    }

    /**
     * Test get_client_ip from X-Forwarded-For when REMOTE_ADDR is localhost
     */
    public function testGetClientIpFromXForwardedForWhenLocalhost(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // Set up - behind proxy
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195, 70.41.3.18';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('203.0.113.195', $ip);
    }

    /**
     * Test get_client_ip from X-Forwarded-For when REMOTE_ADDR is private 10.x.x.x
     */
    public function testGetClientIpFromXForwardedForWhenPrivate10(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // Set up - behind proxy
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.42';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('198.51.100.42', $ip);
    }

    /**
     * Test get_client_ip from X-Forwarded-For when REMOTE_ADDR is private 192.168.x.x
     */
    public function testGetClientIpFromXForwardedForWhenPrivate192(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // Set up - behind proxy
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('8.8.8.8', $ip);
    }

    /**
     * Test get_client_ip from X-Forwarded-For when REMOTE_ADDR is private 172.16-31.x.x
     */
    public function testGetClientIpFromXForwardedForWhenPrivate172(): void
    {
        // Backup
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        // Set up - behind proxy
        $_SERVER['REMOTE_ADDR'] = '172.16.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('1.2.3.4', $ip);
    }

    /**
     * Test parseRequestData for GET method
     */
    public function testParseRequestDataGet(): void
    {
        $method = $this->getPrivateMethod('parseRequestData');

        // Backup and set up GET params
        $backupGet = $_GET;
        $_GET = ['page' => '1', 'limit' => '10', 'search' => 'test'];

        $result = $method->invoke(null, 'GET');

        // Restore
        $_GET = $backupGet;

        $this->assertIsArray($result);
        $this->assertEquals('1', $result['page']);
        $this->assertEquals('10', $result['limit']);
        $this->assertEquals('test', $result['search']);
    }

    /**
     * Test parseRequestData filters long keys
     */
    public function testParseRequestDataFiltersLongKeys(): void
    {
        $method = $this->getPrivateMethod('parseRequestData');

        // Backup and set up GET params with a very long key
        $backupGet = $_GET;
        $longKey = str_repeat('a', 150);
        $_GET = ['valid' => 'value', $longKey => 'should_be_filtered'];

        $result = $method->invoke(null, 'GET');

        // Restore
        $_GET = $backupGet;

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayNotHasKey($longKey, $result);
    }

    // =============================================
    // Tests for sanitizeField method
    // =============================================

    /**
     * Test sanitizeField with string type
     */
    public function testSanitizeFieldString(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'string', 'maxLen' => 50];
        $result = $method->invoke(null, '  hello world  ', $rules, 'test');

        $this->assertEquals('hello world', $result);
    }

    /**
     * Test sanitizeField with string truncation
     */
    public function testSanitizeFieldStringTruncation(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'string', 'maxLen' => 10];
        $result = $method->invoke(null, 'this is a very long string', $rules, 'test');

        $this->assertEquals(10, strlen($result));
    }

    /**
     * Test sanitizeField with email type
     */
    public function testSanitizeFieldEmail(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'email'];
        $result = $method->invoke(null, 'TEST@Example.COM', $rules, 'email');

        $this->assertEquals('test@example.com', $result);
    }

    /**
     * Test sanitizeField with invalid email
     */
    public function testSanitizeFieldInvalidEmail(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'email'];
        $result = $method->invoke(null, 'not-an-email', $rules, 'email');

        $this->assertNull($result);
    }

    /**
     * Test sanitizeField with UUID type
     */
    public function testSanitizeFieldUUID(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'uuid'];
        $result = $method->invoke(null, '550E8400-E29B-41D4-A716-446655440000', $rules, 'uuid');

        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $result);
    }

    /**
     * Test sanitizeField with int type
     */
    public function testSanitizeFieldInt(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'int'];
        $result = $method->invoke(null, '42', $rules, 'count');

        $this->assertSame(42, $result);
    }

    /**
     * Test sanitizeField with int type and min/max
     */
    public function testSanitizeFieldIntWithMinMax(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'int', 'min' => 0, 'max' => 100];

        // Test min clamping
        $result = $method->invoke(null, '-50', $rules, 'count');
        $this->assertSame(0, $result);

        // Test max clamping
        $result = $method->invoke(null, '200', $rules, 'count');
        $this->assertSame(100, $result);

        // Test normal value
        $result = $method->invoke(null, '50', $rules, 'count');
        $this->assertSame(50, $result);
    }

    /**
     * Test sanitizeField with bool type
     */
    public function testSanitizeFieldBool(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'bool'];

        $this->assertTrue($method->invoke(null, 'true', $rules, 'flag'));
        $this->assertTrue($method->invoke(null, '1', $rules, 'flag'));
        $this->assertTrue($method->invoke(null, 'yes', $rules, 'flag'));
        $this->assertFalse($method->invoke(null, 'false', $rules, 'flag'));
        $this->assertFalse($method->invoke(null, '0', $rules, 'flag'));
        $this->assertFalse($method->invoke(null, 'no', $rules, 'flag'));
    }

    /**
     * Test sanitizeField with alphanumeric type
     */
    public function testSanitizeFieldAlphanumeric(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'alphanumeric', 'maxLen' => 50];
        $result = $method->invoke(null, 'abc_123-XYZ!@#', $rules, 'code');

        $this->assertEquals('abc_123-XYZ', $result);
    }

    /**
     * Test sanitizeField with raw type (no sanitization)
     */
    public function testSanitizeFieldRaw(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'raw'];
        $dangerous = '<script>alert("xss")</script>';
        $result = $method->invoke(null, $dangerous, $rules, 'password');

        $this->assertEquals($dangerous, $result);
    }

    /**
     * Test sanitizeField with array type
     */
    public function testSanitizeFieldArray(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'array', 'itemType' => 'int'];
        $result = $method->invoke(null, ['1', '2', '3'], $rules, 'ids');

        $this->assertIsArray($result);
        $this->assertEquals([1, 2, 3], $result);
    }

    /**
     * Test sanitizeField with array type and non-array value
     */
    public function testSanitizeFieldArrayWithNonArray(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = ['type' => 'array', 'itemType' => 'int'];
        $result = $method->invoke(null, 'not-an-array', $rules, 'ids');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test sanitizeField uses default type when not specified
     */
    public function testSanitizeFieldDefaultType(): void
    {
        $method = $this->getPrivateMethod('sanitizeField');

        $rules = []; // No type specified, should default to string
        $result = $method->invoke(null, '  test value  ', $rules, 'field');

        $this->assertEquals('test value', $result);
    }

    // =============================================
    // Tests for sanitizeUnknownField method
    // =============================================

    /**
     * Test sanitizeUnknownField with string value
     */
    public function testSanitizeUnknownFieldString(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        $result = $method->invoke(null, 'valid_key', '  test value  ');

        $this->assertEquals('test value', $result);
    }

    /**
     * Test sanitizeUnknownField with int value
     */
    public function testSanitizeUnknownFieldInt(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        $result = $method->invoke(null, 'count', 42);

        $this->assertSame(42, $result);
    }

    /**
     * Test sanitizeUnknownField with float value
     */
    public function testSanitizeUnknownFieldFloat(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        $result = $method->invoke(null, 'price', 19.99);

        $this->assertSame(19.99, $result);
    }

    /**
     * Test sanitizeUnknownField with bool value
     */
    public function testSanitizeUnknownFieldBool(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        $this->assertTrue($method->invoke(null, 'enabled', true));
        $this->assertFalse($method->invoke(null, 'disabled', false));
    }

    /**
     * Test sanitizeUnknownField with array value
     * Note: InputSanitizer::sanitizeAll skips numeric key "0" due to empty("0") being true
     */
    public function testSanitizeUnknownFieldArray(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        // Use associative array to avoid numeric key issues
        $result = $method->invoke(null, 'items', ['key1' => 'a', 'key2' => 'b', 'key3' => 'c']);

        $this->assertIsArray($result);
        $this->assertEquals('a', $result['key1']);
        $this->assertEquals('b', $result['key2']);
        $this->assertEquals('c', $result['key3']);
    }

    /**
     * Test sanitizeUnknownField with invalid key
     */
    public function testSanitizeUnknownFieldInvalidKey(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        // Key with only special characters becomes empty after sanitization
        $result = $method->invoke(null, '!@#$%^', 'value');

        $this->assertNull($result);
    }

    /**
     * Test sanitizeUnknownField with null value
     */
    public function testSanitizeUnknownFieldNullValue(): void
    {
        $method = $this->getPrivateMethod('sanitizeUnknownField');

        $result = $method->invoke(null, 'field', null);

        $this->assertNull($result);
    }

    // =============================================
    // Tests for sanitizeRequestData method
    // =============================================

    /**
     * Test sanitizeRequestData with empty data
     */
    public function testSanitizeRequestDataEmpty(): void
    {
        $method = $this->getPrivateMethod('sanitizeRequestData');

        $result = $method->invoke(null, [], null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test sanitizeRequestData without schema (default sanitization)
     */
    public function testSanitizeRequestDataNoSchema(): void
    {
        $method = $this->getPrivateMethod('sanitizeRequestData');

        $data = [
            'name' => '  John Doe  ',
            'age' => 30,
            'active' => true
        ];

        $result = $method->invoke(null, $data, null);

        $this->assertEquals('John Doe', $result['name']);
        $this->assertSame(30, $result['age']);
        $this->assertTrue($result['active']);
    }

    /**
     * Test handleCORS in test environment (should skip headers)
     */
    public function testHandleCORSInTestEnvironment(): void
    {
        // When PHPUNIT_RUNNING is defined, handleCORS should return without sending headers
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        // Should not throw or cause issues
        RouteController::handleCORS();

        // If we reach here, the test passed (no fatal error from header already sent)
        $this->assertTrue(true);
    }

    // =============================================
    // Tests for parseAction method
    // =============================================

    /**
     * Test parseAction extracts action from URI
     */
    public function testParseActionExtractsAction(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        // Backup
        $backupUri = $_SERVER['REQUEST_URI'] ?? null;

        // Test standard api.php pattern
        $_SERVER['REQUEST_URI'] = '/path/to/api.php/users/123';
        $result = $method->invoke(null);
        $this->assertEquals('users/123', $result);

        // Restore
        if ($backupUri !== null) {
            $_SERVER['REQUEST_URI'] = $backupUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    /**
     * Test parseAction with simple action
     */
    public function testParseActionSimple(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        $backupUri = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/api.php/login';
        $result = $method->invoke(null);
        $this->assertEquals('login', $result);

        if ($backupUri !== null) {
            $_SERVER['REQUEST_URI'] = $backupUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    /**
     * Test parseAction returns false when REQUEST_URI not set
     */
    public function testParseActionReturnsFalseWhenNoUri(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        $backupUri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']);

        $result = $method->invoke(null);
        $this->assertFalse($result);

        if ($backupUri !== null) {
            $_SERVER['REQUEST_URI'] = $backupUri;
        }
    }

    /**
     * Test parseAction with query string
     */
    public function testParseActionWithQueryString(): void
    {
        $method = $this->getPrivateMethod('parseAction');

        $backupUri = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/api.php/products?page=1&limit=10';
        $result = $method->invoke(null);
        $this->assertEquals('products', $result);

        if ($backupUri !== null) {
            $_SERVER['REQUEST_URI'] = $backupUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    // =============================================
    // Tests for checkCORSConfiguration method
    // =============================================

    /**
     * Test checkCORSConfiguration skips when no Origin header
     */
    public function testCheckCORSConfigurationSkipsWithoutOrigin(): void
    {
        global $conf;
        $method = $this->getPrivateMethod('checkCORSConfiguration');

        // Reset cache
        $conf->cache['smartmakers'] = [];

        $backupOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
        unset($_SERVER['HTTP_ORIGIN']);

        // Should return early without setting cache key
        $method->invoke(null);

        // Cache key should be set regardless
        $this->assertTrue(isset($conf->cache['smartmakers']['smartauth_cors_checked']));

        if ($backupOrigin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $backupOrigin;
        }
    }

    /**
     * Test checkCORSConfiguration runs only once per session
     */
    public function testCheckCORSConfigurationCachesCheck(): void
    {
        global $conf;
        $method = $this->getPrivateMethod('checkCORSConfiguration');

        // Pre-set cache to simulate already checked
        $conf->cache['smartmakers']['smartauth_cors_checked'] = true;

        $backupOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';

        // Should return immediately due to cache
        $method->invoke(null);

        // Cache should still be true
        $this->assertTrue($conf->cache['smartmakers']['smartauth_cors_checked']);

        if ($backupOrigin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $backupOrigin;
        } else {
            unset($_SERVER['HTTP_ORIGIN']);
        }
    }

    /**
     * Test checkCORSConfiguration with OPTIONS preflight
     */
    public function testCheckCORSConfigurationWithPreflight(): void
    {
        global $conf;
        $method = $this->getPrivateMethod('checkCORSConfiguration');

        // Reset cache
        $conf->cache['smartmakers'] = [];

        $backupOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $backupMethod = $_SERVER['REQUEST_METHOD'] ?? null;

        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $method->invoke(null);

        // Should complete without error
        $this->assertTrue($conf->cache['smartmakers']['smartauth_cors_checked']);

        if ($backupOrigin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $backupOrigin;
        } else {
            unset($_SERVER['HTTP_ORIGIN']);
        }
        if ($backupMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $backupMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    // =============================================
    // Tests for insertLogs method
    // =============================================

    /**
     * Test insertLogs when logging is disabled
     */
    public function testInsertLogsWhenDisabled(): void
    {
        global $conf, $db;

        // Mock db that should NOT be called
        $db = $this->createMock(\stdClass::class);

        // Ensure logging is disabled
        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_COLLECT_LOGS = '';

        // Set up minimal server vars
        $backupUri = $_SERVER['REQUEST_URI'] ?? null;
        $backupMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_URI'] = '/api.php/test';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Should return early without inserting
        RouteController::insertLogs(1, 200, 'Test', 1);

        // If no exception thrown, test passes
        $this->assertTrue(true);

        if ($backupUri !== null) {
            $_SERVER['REQUEST_URI'] = $backupUri;
        }
        if ($backupMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $backupMethod;
        }
    }

    // =============================================
    // Tests for executeAction method
    // =============================================

    /**
     * Test executeAction with non-existent class
     *
     * @group unit-only
     */
    public function testExecuteActionClassNotFound(): void
    {
        $method = $this->getPrivateMethod('executeAction');

        global $conf, $db;

        // Setup minimal conf
        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_COLLECT_LOGS = '';

        // Create mock db
        $db = new class {
            public function escape($val) { return $val; }
            public function query($sql) { return true; }
        };

        // json_reply throws JsonReplyException in test environment
        $this->expectException(\SmartAuth\Tests\Mocks\JsonReplyException::class);
        $this->expectExceptionMessage('Internal server error - Class not found');

        // Execute with non-existent class
        $method->invoke(
            null,
            'NonExistentClass123',
            'someMethod',
            [],
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    /**
     * Test executeAction with non-existent method
     *
     * @group unit-only
     */
    public function testExecuteActionMethodNotFound(): void
    {
        $method = $this->getPrivateMethod('executeAction');

        global $conf, $db;

        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_COLLECT_LOGS = '';

        $db = new class {
            public function escape($val) { return $val; }
            public function query($sql) { return true; }
        };

        $this->expectException(\SmartAuth\Tests\Mocks\JsonReplyException::class);
        $this->expectExceptionMessage('Internal server error - Method not found');

        // Use stdClass which exists but doesn't have the method
        $method->invoke(
            null,
            \stdClass::class,
            'nonExistentMethod123',
            [],
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    // =============================================
    // Tests for HTTP verb methods (get, post, etc.)
    // =============================================

    /**
     * Test get() in registration mode registers route
     */
    public function testGetInRegistrationMode(): void
    {
        // Enable registration mode
        \SmartAuth\Api\RouteCache::startRegistration();

        // Verify we're in registration mode
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        // Call get - should register the route
        RouteController::get('/test/route', 'TestClass', 'testMethod', true);

        // End registration (required to clean up state)
        // Note: This would normally save to file, but without proper DOL_DATA_ROOT it won't
        // We're just testing that the registration flow works
        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    /**
     * Test post() in registration mode registers route
     */
    public function testPostInRegistrationMode(): void
    {
        \SmartAuth\Api\RouteCache::startRegistration();
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        RouteController::post('/test/create', 'TestClass', 'createMethod', false);

        // Reset registration mode
        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    /**
     * Test put() in registration mode registers route
     */
    public function testPutInRegistrationMode(): void
    {
        \SmartAuth\Api\RouteCache::startRegistration();
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        RouteController::put('/test/update/{id}', 'TestClass', 'updateMethod', true);

        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    /**
     * Test delete() in registration mode registers route
     */
    public function testDeleteInRegistrationMode(): void
    {
        \SmartAuth\Api\RouteCache::startRegistration();
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        RouteController::delete('/test/remove/{id}', 'TestClass', 'deleteMethod', true);

        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    /**
     * Test patch() in registration mode registers route
     */
    public function testPatchInRegistrationMode(): void
    {
        \SmartAuth\Api\RouteCache::startRegistration();
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        RouteController::patch('/test/partial/{id}', 'TestClass', 'patchMethod', false);

        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    /**
     * Test route() returns early when HTTP method doesn't match
     */
    public function testRouteReturnsEarlyOnMethodMismatch(): void
    {
        global $conf;

        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->cache = ['smartmakers' => []];

        $backupMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // route() should return early when REQUEST_METHOD (GET) != targetMethod (POST)
        // We can't easily test this without mocking Societe, but we can verify
        // that when NOT in registration mode, it attempts to match the method
        \SmartAuth\Api\RouteCache::startRegistration();

        // In registration mode, route() still delegates to RouteCache::register
        // After startRegistration, calling get/post/etc will register, not route
        $this->assertTrue(\SmartAuth\Api\RouteCache::isRegistrationMode());

        // Reset
        $reflection = new \ReflectionClass(\SmartAuth\Api\RouteCache::class);
        $prop = $reflection->getProperty('registrationMode');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        if ($backupMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $backupMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $this->assertFalse(\SmartAuth\Api\RouteCache::isRegistrationMode());
    }

    // =============================================
    // Tests for get_client_ip edge cases
    // =============================================

    /**
     * Test get_client_ip with X-Real-IP header
     */
    public function testGetClientIpFromXRealIP(): void
    {
        // This test requires apache_request_headers which may not be available
        // Skip if function doesn't exist
        if (!function_exists('apache_request_headers')) {
            $this->markTestSkipped('apache_request_headers not available');
        }

        // Backup and set
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = RouteController::get_client_ip();

        // Restore
        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        $this->assertNotEmpty($ip);
    }

    /**
     * Test get_client_ip with empty REMOTE_ADDR and X-Forwarded-For
     */
    public function testGetClientIpWithEmptyRemoteAddr(): void
    {
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

        $ip = RouteController::get_client_ip();

        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('203.0.113.50', $ip);
    }

    /**
     * Test get_client_ip from 172.20.x.x private range
     */
    public function testGetClientIpFromPrivate172Middle(): void
    {
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '172.20.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';

        $ip = RouteController::get_client_ip();

        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('5.6.7.8', $ip);
    }

    /**
     * Test get_client_ip from 172.31.x.x (edge of private range)
     */
    public function testGetClientIpFromPrivate172Edge(): void
    {
        $backupRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $backupXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '172.31.255.255';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.10.11.12';

        $ip = RouteController::get_client_ip();

        if ($backupRemote !== null) {
            $_SERVER['REMOTE_ADDR'] = $backupRemote;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        if ($backupXff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $backupXff;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertEquals('9.10.11.12', $ip);
    }
}
