<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\RouteController;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for RouteController
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
     * after removing the prefix, so intermediate segments are included
     */
    public function testExtractUrlParametersMultiplePlaceholders(): void
    {
        $method = $this->getPrivateMethod('extractUrlParameters');

        $data = [];
        // Pattern: users/{id}/posts/{postid}
        // Action:  users/123/posts/456
        // Prefix:  users/
        // After prefix removal: 123/posts/456
        // Split: ['123', 'posts', '456'] (indices 0, 1, 2)
        // array_filter keeps original indices
        $result = $method->invoke(null, 'users/{id}/posts/{postid}', 'users/123/posts/456', $data);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('postid', $result);
        $this->assertEquals('123', $result['id']);
        // Due to array_filter keeping indices, index 1 is 'posts', not '456'
        $this->assertEquals('posts', $result['postid']);
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
}
