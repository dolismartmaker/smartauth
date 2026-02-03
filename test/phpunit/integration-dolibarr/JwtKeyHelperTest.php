<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/JwtKeyHelper.php';
require_once __DIR__ . '/../../../api/RouteCache.php';

use SmartAuth\Api\JwtKeyHelper;
use SmartAuth\Api\RouteCache;

/**
 * Integration tests for JwtKeyHelper
 */
class JwtKeyHelperTest extends DolibarrRealTestCase
{
    /**
     * Test generateKey creates a key of correct length
     */
    public function testGenerateKeyCreatesCorrectLength(): void
    {
        $key = JwtKeyHelper::generateKey();

        $this->assertEquals(JwtKeyHelper::DEFAULT_KEY_LENGTH, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/i', $key);
    }

    /**
     * Test generateKey with custom length
     */
    public function testGenerateKeyWithCustomLength(): void
    {
        $key = JwtKeyHelper::generateKey(32);

        $this->assertEquals(32, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/i', $key);
    }

    /**
     * Test generateKey creates unique keys
     */
    public function testGenerateKeyCreatesUniqueKeys(): void
    {
        $key1 = JwtKeyHelper::generateKey();
        $key2 = JwtKeyHelper::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    /**
     * Test getConfigKeyName formats module name correctly
     */
    public function testGetConfigKeyNameFormatsCorrectly(): void
    {
        $this->assertEquals('MYMODULE_JWT_KEY', JwtKeyHelper::getConfigKeyName('mymodule'));
        $this->assertEquals('MYMODULE_JWT_KEY', JwtKeyHelper::getConfigKeyName('MyModule'));
        $this->assertEquals('MYMODULE_JWT_KEY', JwtKeyHelper::getConfigKeyName('MYMODULE'));
        $this->assertEquals('MYMODULE_JWT_KEY', JwtKeyHelper::getConfigKeyName('  mymodule  '));
    }

    /**
     * Test hasValidKey returns false when no key exists
     */
    public function testHasValidKeyReturnsFalseWhenNoKey(): void
    {
        $moduleName = 'testmodule_' . uniqid();

        $this->assertFalse(JwtKeyHelper::hasValidKey($moduleName));
    }

    /**
     * Test hasValidKey returns false for short key
     */
    public function testHasValidKeyReturnsFalseForShortKey(): void
    {
        global $conf;

        $moduleName = 'testmodule_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a short key in global conf
        $conf->global->$configKey = 'short';

        $this->assertFalse(JwtKeyHelper::hasValidKey($moduleName));

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test hasValidKey returns true for valid key
     */
    public function testHasValidKeyReturnsTrueForValidKey(): void
    {
        global $conf;

        $moduleName = 'testmodule_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a valid key
        $validKey = JwtKeyHelper::generateKey();
        $conf->global->$configKey = $validKey;

        $this->assertTrue(JwtKeyHelper::hasValidKey($moduleName));

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test getKey auto-generates key when missing
     */
    public function testGetKeyAutoGeneratesWhenMissing(): void
    {
        global $conf;

        $moduleName = 'autogen_' . uniqid();

        // Initialize RouteCache with our test module name
        RouteCache::init($moduleName, dirname(__DIR__, 3));

        $key = JwtKeyHelper::getKey($moduleName);

        $this->assertNotEmpty($key);
        $this->assertGreaterThanOrEqual(JwtKeyHelper::MIN_KEY_LENGTH, strlen($key));

        // Cleanup
        $configKey = strtoupper($moduleName) . '_JWT_KEY';
        unset($conf->global->$configKey);
    }

    /**
     * Test getKey returns existing key
     */
    public function testGetKeyReturnsExistingKey(): void
    {
        global $conf;

        $moduleName = 'existing_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Pre-set a key
        $existingKey = JwtKeyHelper::generateKey();
        $conf->global->$configKey = $existingKey;

        $key = JwtKeyHelper::getKey($moduleName);

        $this->assertEquals($existingKey, $key);

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test getKey throws exception when module name empty and not auto-detectable
     */
    public function testGetKeyThrowsExceptionWhenNoModuleName(): void
    {
        // Reset RouteCache module name
        RouteCache::init('', dirname(__DIR__, 3));

        $this->expectException(\InvalidArgumentException::class);

        JwtKeyHelper::getKey('');
    }

    /**
     * Test rotateKey generates new key
     */
    public function testRotateKeyGeneratesNewKey(): void
    {
        global $conf;

        $moduleName = 'rotate_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set initial key
        $initialKey = JwtKeyHelper::generateKey();
        $conf->global->$configKey = $initialKey;

        // Rotate key
        $newKey = JwtKeyHelper::rotateKey($moduleName);

        $this->assertNotFalse($newKey);
        $this->assertNotEquals($initialKey, $newKey);
        $this->assertGreaterThanOrEqual(JwtKeyHelper::MIN_KEY_LENGTH, strlen($newKey));

        // Verify conf was updated
        $this->assertEquals($newKey, $conf->global->$configKey);

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test rotateKey returns false for empty module name
     */
    public function testRotateKeyReturnsFalseForEmptyModuleName(): void
    {
        $result = JwtKeyHelper::rotateKey('');

        $this->assertFalse($result);
    }

    /**
     * Test rotateKey returns false for whitespace-only module name
     */
    public function testRotateKeyReturnsFalseForWhitespaceModuleName(): void
    {
        $result = JwtKeyHelper::rotateKey('   ');

        $this->assertFalse($result);
    }

    /**
     * Test MIN_KEY_LENGTH constant value
     */
    public function testMinKeyLengthConstant(): void
    {
        $this->assertEquals(32, JwtKeyHelper::MIN_KEY_LENGTH);
    }

    /**
     * Test DEFAULT_KEY_LENGTH constant value
     */
    public function testDefaultKeyLengthConstant(): void
    {
        $this->assertEquals(64, JwtKeyHelper::DEFAULT_KEY_LENGTH);
    }

    /**
     * Test generateKey with odd length produces correct length key
     */
    public function testGenerateKeyWithOddLength(): void
    {
        $key = JwtKeyHelper::generateKey(33);

        // Due to hex conversion, odd lengths round up
        $this->assertGreaterThanOrEqual(33, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/i', $key);
    }
}
