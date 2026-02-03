<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/JwtKeyHelper.php';
require_once __DIR__ . '/../../../api/RouteCache.php';

use SmartAuth\Api\JwtKeyHelper;
use SmartAuth\Api\RouteCache;

/**
 * Integration tests for JwtKeyHelper
 *
 * @covers \SmartAuth\Api\JwtKeyHelper
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

    /**
     * Test getKey stores key in database
     */
    public function testGetKeyStoresKeyInDatabase(): void
    {
        global $conf;

        $moduleName = 'dbstore_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Ensure key doesn't exist
        unset($conf->global->$configKey);

        // Initialize RouteCache
        RouteCache::init($moduleName, dirname(__DIR__, 3));

        $key = JwtKeyHelper::getKey($moduleName);

        // Verify key was stored in database
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'";
        $resql = $this->db->query($sql);

        $this->assertNotFalse($resql);
        $obj = $this->db->fetch_object($resql);

        // Key should be stored (may be encrypted with dolcrypt)
        if ($obj) {
            $storedValue = $obj->value;
            // Dolibarr encrypts sensitive values with dolcrypt:AES-256-CTR:...
            if (strpos($storedValue, 'dolcrypt:') === 0) {
                $storedValue = dolDecrypt($storedValue);
            }
            $this->assertEquals($key, $storedValue);
        }

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'");
        unset($conf->global->$configKey);
    }

    /**
     * Test rotateKey stores new key in database
     */
    public function testRotateKeyStoresInDatabase(): void
    {
        global $conf;

        $moduleName = 'rotatedb_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Rotate key (will create new one)
        $newKey = JwtKeyHelper::rotateKey($moduleName);

        $this->assertNotFalse($newKey);

        // Verify key was stored in database
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'";
        $resql = $this->db->query($sql);

        $this->assertNotFalse($resql);
        $obj = $this->db->fetch_object($resql);

        if ($obj) {
            $storedValue = $obj->value;
            if (strpos($storedValue, 'dolcrypt:') === 0) {
                $storedValue = dolDecrypt($storedValue);
            }
            $this->assertEquals($newKey, $storedValue);
        }

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'");
        unset($conf->global->$configKey);
    }

    /**
     * Test rotateKey updates existing key in database
     */
    public function testRotateKeyUpdatesExistingKeyInDatabase(): void
    {
        global $conf;

        $moduleName = 'rotateupdate_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Insert initial key
        $initialKey = 'initial_key_value_' . bin2hex(random_bytes(16));
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, value, type, visible, entity)";
        $sql .= " VALUES ('" . $this->db->escape($configKey) . "', '" . $this->db->escape($initialKey) . "', 'chaine', 0, 0)";
        $this->db->query($sql);

        $conf->global->$configKey = $initialKey;

        // Rotate key
        $newKey = JwtKeyHelper::rotateKey($moduleName);

        $this->assertNotFalse($newKey);
        $this->assertNotEquals($initialKey, $newKey);

        // Verify key was updated in database
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);

        $storedValue = $obj->value;
        if (strpos($storedValue, 'dolcrypt:') === 0) {
            $storedValue = dolDecrypt($storedValue);
        }
        $this->assertEquals($newKey, $storedValue);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'");
        unset($conf->global->$configKey);
    }

    /**
     * Test getKey uses auto-detected module name from RouteCache
     */
    public function testGetKeyUsesAutoDetectedModuleName(): void
    {
        global $conf;

        $moduleName = 'autodetect_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a key for this module
        $existingKey = JwtKeyHelper::generateKey();
        $conf->global->$configKey = $existingKey;

        // Initialize RouteCache with module name
        RouteCache::init($moduleName, dirname(__DIR__, 3));

        // Call getKey without explicit module name
        $key = JwtKeyHelper::getKey();

        $this->assertEquals($existingKey, $key);

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test hasValidKey with exactly MIN_KEY_LENGTH characters
     */
    public function testHasValidKeyWithExactMinLength(): void
    {
        global $conf;

        $moduleName = 'exactmin_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a key with exactly MIN_KEY_LENGTH characters
        $exactKey = str_repeat('a', JwtKeyHelper::MIN_KEY_LENGTH);
        $conf->global->$configKey = $exactKey;

        $this->assertTrue(JwtKeyHelper::hasValidKey($moduleName));

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test hasValidKey with MIN_KEY_LENGTH - 1 characters returns false
     */
    public function testHasValidKeyWithOneLessThanMinReturnsfalse(): void
    {
        global $conf;

        $moduleName = 'lessmin_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a key with MIN_KEY_LENGTH - 1 characters
        $shortKey = str_repeat('a', JwtKeyHelper::MIN_KEY_LENGTH - 1);
        $conf->global->$configKey = $shortKey;

        $this->assertFalse(JwtKeyHelper::hasValidKey($moduleName));

        // Cleanup
        unset($conf->global->$configKey);
    }

    /**
     * Test getKey regenerates when existing key is too short
     */
    public function testGetKeyRegeneratesWhenKeyTooShort(): void
    {
        global $conf;

        $moduleName = 'regenshort_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set a short key
        $shortKey = 'tooshort';
        $conf->global->$configKey = $shortKey;

        RouteCache::init($moduleName, dirname(__DIR__, 3));

        $key = JwtKeyHelper::getKey($moduleName);

        // Should have regenerated
        $this->assertNotEquals($shortKey, $key);
        $this->assertGreaterThanOrEqual(JwtKeyHelper::MIN_KEY_LENGTH, strlen($key));

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'");
        unset($conf->global->$configKey);
    }

    /**
     * Test rotateKey updates global conf cache
     */
    public function testRotateKeyUpdatesGlobalConfCache(): void
    {
        global $conf;

        $moduleName = 'confcache_' . uniqid();
        $configKey = strtoupper($moduleName) . '_JWT_KEY';

        // Set initial value
        $conf->global->$configKey = 'initial_value';

        $newKey = JwtKeyHelper::rotateKey($moduleName);

        $this->assertNotFalse($newKey);
        $this->assertEquals($newKey, $conf->global->$configKey);

        // Cleanup
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($configKey) . "'");
        unset($conf->global->$configKey);
    }
}
