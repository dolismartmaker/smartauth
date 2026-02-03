<?php

/**
 * Integration tests for RouteCache class
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\RouteCache;

/**
 * @covers \SmartAuth\Api\RouteCache
 */
class RouteCacheTest extends DolibarrRealTestCase
{
    private string $testCacheDir;
    private string $testCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize RouteCache with a test module name
        RouteCache::init('testmodule');

        $this->testCacheDir = DOL_DATA_ROOT . '/testmodule/cache';
        $this->testCacheFile = $this->testCacheDir . '/routes.php';

        // Clean up any existing test cache
        $this->cleanupTestCache();
    }

    protected function tearDown(): void
    {
        // Clean up test cache after each test
        $this->cleanupTestCache();

        parent::tearDown();
    }

    private function cleanupTestCache(): void
    {
        if (file_exists($this->testCacheFile)) {
            unlink($this->testCacheFile);
        }
        if (is_dir($this->testCacheDir)) {
            @rmdir($this->testCacheDir);
        }
        $moduleDir = DOL_DATA_ROOT . '/testmodule';
        if (is_dir($moduleDir)) {
            @rmdir($moduleDir);
        }
    }

    // ==================== init tests ====================

    public function testInitSetsModuleName(): void
    {
        RouteCache::init('mymodule');
        $this->assertEquals('mymodule', RouteCache::getModuleName());
    }

    public function testInitConvertsToLowercase(): void
    {
        RouteCache::init('MyModule');
        $this->assertEquals('mymodule', RouteCache::getModuleName());
    }

    public function testInitResetsCache(): void
    {
        // First init and load some routes
        RouteCache::init('module1');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'testMethod', false);
        RouteCache::endRegistration();

        // Re-init with different module
        RouteCache::init('module2');

        // Cached routes should be null after re-init
        $this->assertNull(RouteCache::getCachedRoutes());
    }

    // ==================== getCacheFilePath tests ====================

    public function testGetCacheFilePathReturnsCorrectPath(): void
    {
        RouteCache::init('smartauth');
        $path = RouteCache::getCacheFilePath();
        $this->assertEquals(DOL_DATA_ROOT . '/smartauth/cache/routes.php', $path);
    }

    public function testGetCacheFilePathReturnsEmptyWhenNotInitialized(): void
    {
        // Reset state by using reflection
        $reflection = new \ReflectionClass(RouteCache::class);
        $prop = $reflection->getProperty('moduleName');
        $prop->setAccessible(true);
        $prop->setValue(null, '');

        $path = RouteCache::getCacheFilePath();
        $this->assertEquals('', $path);

        // Restore init
        RouteCache::init('testmodule');
    }

    // ==================== getCurrentVersion tests ====================

    public function testGetCurrentVersionReturnsDefault(): void
    {
        RouteCache::init('nonexistentmodule');
        $version = RouteCache::getCurrentVersion();
        $this->assertEquals('0.0.0', $version);
    }

    // ==================== isCacheValid tests ====================

    public function testIsCacheValidReturnsFalseWhenNoCache(): void
    {
        RouteCache::init('testmodule');
        $this->assertFalse(RouteCache::isCacheValid());
    }

    public function testIsCacheValidReturnsFalseWhenNotInitialized(): void
    {
        // Reset state
        $reflection = new \ReflectionClass(RouteCache::class);
        $prop = $reflection->getProperty('moduleName');
        $prop->setAccessible(true);
        $prop->setValue(null, '');

        $this->assertFalse(RouteCache::isCacheValid());

        // Restore
        RouteCache::init('testmodule');
    }

    // ==================== registration mode tests ====================

    public function testStartRegistrationEntersRegistrationMode(): void
    {
        RouteCache::init('testmodule');
        $this->assertFalse(RouteCache::isRegistrationMode());

        RouteCache::startRegistration();
        $this->assertTrue(RouteCache::isRegistrationMode());

        // Clean up
        RouteCache::endRegistration();
    }

    public function testEndRegistrationExitsRegistrationMode(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        $this->assertTrue(RouteCache::isRegistrationMode());

        RouteCache::endRegistration();
        $this->assertFalse(RouteCache::isRegistrationMode());
    }

    public function testEndRegistrationWithoutStartReturnsFalse(): void
    {
        RouteCache::init('testmodule');
        $result = RouteCache::endRegistration();
        $this->assertFalse($result);
    }

    // ==================== register tests ====================

    public function testRegisterDoesNothingOutsideRegistrationMode(): void
    {
        RouteCache::init('testmodule');
        // Not in registration mode
        RouteCache::register('GET', '/test', 'TestClass', 'testMethod', false);

        // Start registration to check no routes were registered before
        RouteCache::startRegistration();
        RouteCache::endRegistration();

        // Cache should exist but be empty of the earlier route
        // (the earlier register call should have been ignored)
        $this->assertFalse(RouteCache::isRegistrationMode());
    }

    public function testRegisterAddsRouteInRegistrationMode(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users', 'UserController', 'index', false);
        RouteCache::register('POST', '/users', 'UserController', 'create', true);
        $result = RouteCache::endRegistration();

        $this->assertTrue($result);

        // Verify cache file was created
        $this->assertFileExists($this->testCacheFile);
    }

    // ==================== loadCache tests ====================

    public function testLoadCacheReturnsFalseWhenNoCacheFile(): void
    {
        RouteCache::init('testmodule');
        $result = RouteCache::loadCache();
        $this->assertFalse($result);
    }

    public function testLoadCacheReturnsTrueAfterRegistration(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'test', false);
        RouteCache::endRegistration();

        // Re-init to clear cached routes from memory
        RouteCache::init('testmodule');

        $result = RouteCache::loadCache();
        $this->assertTrue($result);
    }

    public function testLoadCacheReturnsTrueWhenAlreadyLoaded(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'test', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        // Second call should return true immediately
        $result = RouteCache::loadCache();
        $this->assertTrue($result);
    }

    // ==================== findRoute tests ====================

    public function testFindRouteReturnsNullWhenCacheNotLoaded(): void
    {
        RouteCache::init('testmodule');
        $result = RouteCache::findRoute('GET', '/test');
        $this->assertNull($result);
    }

    public function testFindRouteFindsStaticRoute(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users', 'UserController', 'index', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $route = RouteCache::findRoute('GET', '/users');

        $this->assertNotNull($route);
        $this->assertEquals('UserController', $route['class']);
        $this->assertEquals('index', $route['function']);
        $this->assertFalse($route['protected']);
        $this->assertEquals([], $route['params']);
    }

    public function testFindRouteReturnNullForNonExistentRoute(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users', 'UserController', 'index', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $route = RouteCache::findRoute('GET', '/nonexistent');
        $this->assertNull($route);
    }

    public function testFindRouteReturnNullForWrongMethod(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users', 'UserController', 'index', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $route = RouteCache::findRoute('POST', '/users');
        $this->assertNull($route);
    }

    public function testFindRouteFindsDynamicRoute(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users/{id}', 'UserController', 'show', true);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $route = RouteCache::findRoute('GET', '/users/123');

        $this->assertNotNull($route);
        $this->assertEquals('UserController', $route['class']);
        $this->assertEquals('show', $route['function']);
        $this->assertTrue($route['protected']);
        $this->assertEquals(['id' => '123'], $route['params']);
    }

    public function testFindRouteFindsDynamicRouteWithMultipleParams(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users/{userId}/posts/{postId}', 'PostController', 'show', true);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $route = RouteCache::findRoute('GET', '/users/42/posts/99');

        $this->assertNotNull($route);
        $this->assertEquals('PostController', $route['class']);
        $this->assertEquals(['userId' => '42', 'postId' => '99'], $route['params']);
    }

    // ==================== clearCache tests ====================

    public function testClearCacheDeletesCacheFile(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'test', false);
        RouteCache::endRegistration();

        $this->assertFileExists($this->testCacheFile);

        $result = RouteCache::clearCache();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->testCacheFile);
    }

    public function testClearCacheReturnsTrueWhenNoCacheFile(): void
    {
        RouteCache::init('testmodule');
        $result = RouteCache::clearCache();
        $this->assertTrue($result);
    }

    public function testClearCacheResetsCachedRoutesInMemory(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'test', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $this->assertNotNull(RouteCache::getCachedRoutes());

        RouteCache::clearCache();

        $this->assertNull(RouteCache::getCachedRoutes());
    }

    // ==================== getCachedRoutes tests ====================

    public function testGetCachedRoutesReturnsNullBeforeLoad(): void
    {
        RouteCache::init('testmodule');
        $this->assertNull(RouteCache::getCachedRoutes());
    }

    public function testGetCachedRoutesReturnsArrayAfterLoad(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/test', 'TestClass', 'test', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $routes = RouteCache::getCachedRoutes();
        $this->assertIsArray($routes);
        $this->assertArrayHasKey('static', $routes);
        $this->assertArrayHasKey('dynamic', $routes);
    }

    // ==================== route optimization tests ====================

    public function testStaticRoutesUseHashLookup(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/api/health', 'HealthController', 'check', false);
        RouteCache::register('POST', '/api/login', 'AuthController', 'login', false);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $routes = RouteCache::getCachedRoutes();

        $this->assertArrayHasKey('GET:/api/health', $routes['static']);
        $this->assertArrayHasKey('POST:/api/login', $routes['static']);
    }

    public function testDynamicRoutesUseRegexMatching(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/users/{id}', 'UserController', 'show', true);
        RouteCache::register('DELETE', '/users/{id}', 'UserController', 'delete', true);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $routes = RouteCache::getCachedRoutes();

        $this->assertArrayHasKey('GET', $routes['dynamic']);
        $this->assertArrayHasKey('DELETE', $routes['dynamic']);
        $this->assertCount(1, $routes['dynamic']['GET']);
        $this->assertCount(1, $routes['dynamic']['DELETE']);
    }

    // ==================== protected route tests ====================

    public function testProtectedFlagIsPreserved(): void
    {
        RouteCache::init('testmodule');
        RouteCache::startRegistration();
        RouteCache::register('GET', '/public', 'Controller', 'publicMethod', false);
        RouteCache::register('GET', '/private', 'Controller', 'privateMethod', true);
        RouteCache::endRegistration();

        RouteCache::init('testmodule');
        RouteCache::loadCache();

        $publicRoute = RouteCache::findRoute('GET', '/public');
        $privateRoute = RouteCache::findRoute('GET', '/private');

        $this->assertFalse($publicRoute['protected']);
        $this->assertTrue($privateRoute['protected']);
    }
}
