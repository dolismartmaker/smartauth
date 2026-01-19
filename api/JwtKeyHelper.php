<?php

/**
 * JwtKeyHelper.php
 *
 * Helper for JWT key management in SmartAuth.
 * Provides automatic key generation and retrieval for modules using SmartAuth.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api;

class JwtKeyHelper
{
    /**
     * Minimum required key length
     */
    const MIN_KEY_LENGTH = 32;

    /**
     * Default key length for generation (64 hex chars = 32 bytes)
     */
    const DEFAULT_KEY_LENGTH = 64;

    /**
     * Get or auto-generate JWT key for a module
     *
     * This method provides lazy initialization of JWT keys.
     * If the key doesn't exist or is too short, a new secure key
     * is automatically generated and stored in Dolibarr configuration.
     *
     * Called internally by AuthController. Module name is auto-detected
     * from RouteCache::getModuleName() which is set by RouteCache::init().
     *
     * @param string|null $moduleName Module name (auto-detected from RouteCache if null)
     * @return string The JWT key (at least 32 characters)
     * @throws \InvalidArgumentException If module name cannot be determined
     */
    public static function getKey(?string $moduleName = null): string
    {
        global $db;

        // Auto-detect module name from RouteCache if not provided
        if ($moduleName === null || trim($moduleName) === '') {
            $moduleName = RouteCache::getModuleName();
            if (empty($moduleName)) {
                throw new \InvalidArgumentException(
                    'Module name cannot be determined. Either call RouteCache::init() first or provide module name explicitly.'
                );
            }
        }

        $moduleName = strtoupper(trim($moduleName));

        $configKey = $moduleName . '_JWT_KEY';
        $key = getDolGlobalString($configKey, '');

        // If key doesn't exist or is too short, generate one
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            $key = self::generateKey();

            // Store in database
            $result = self::storeKey($db, $configKey, $key);

            if ($result) {
                dol_syslog("SmartAuth JwtKeyHelper: Auto-generated JWT key for $moduleName", LOG_INFO);
            } else {
                dol_syslog("SmartAuth JwtKeyHelper: Failed to store JWT key for $moduleName", LOG_ERR);
                // Still return the generated key - it will work for this request
                // but will be regenerated on next request if storage failed
            }
        }

        return $key;
    }

    /**
     * Generate a cryptographically secure random key
     *
     * @param int $length Key length in characters (hex string)
     * @return string Hexadecimal key string
     */
    public static function generateKey(int $length = self::DEFAULT_KEY_LENGTH): string
    {
        // Ensure even length for hex conversion
        $byteLength = (int) ceil($length / 2);
        return bin2hex(random_bytes($byteLength));
    }

    /**
     * Store key in Dolibarr configuration
     *
     * @param object $db Database handler
     * @param string $configKey Configuration key name
     * @param string $key Key value to store
     * @return bool Success
     */
    private static function storeKey($db, string $configKey, string $key): bool
    {
        if (!$db) {
            return false;
        }

        // Use dolibarr_set_const if available
        if (function_exists('dolibarr_set_const')) {
            return dolibarr_set_const($db, $configKey, $key, 'chaine', 0, 'Auto-generated JWT key by SmartAuth', 0) > 0;
        }

        // Fallback to direct SQL
        $key = $db->escape($key);
        $configKey = $db->escape($configKey);

        // Try update first
        $sql = "UPDATE " . MAIN_DB_PREFIX . "const";
        $sql .= " SET value = '" . $key . "'";
        $sql .= " WHERE name = '" . $configKey . "'";
        $sql .= " AND entity = 0";

        $db->query($sql);

        if ($db->affected_rows($db) == 0) {
            // Insert if not exists
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const";
            $sql .= " (name, value, type, visible, note, entity)";
            $sql .= " VALUES ('" . $configKey . "', '" . $key . "', 'chaine', 0, 'Auto-generated JWT key by SmartAuth', 0)";
            return $db->query($sql) !== false;
        }

        return true;
    }

    /**
     * Check if a valid JWT key exists for a module
     *
     * @param string $moduleName Module name
     * @return bool True if a valid key exists
     */
    public static function hasValidKey(string $moduleName): bool
    {
        $moduleName = strtoupper(trim($moduleName));
        $configKey = $moduleName . '_JWT_KEY';
        $key = getDolGlobalString($configKey, '');

        return strlen($key) >= self::MIN_KEY_LENGTH;
    }

    /**
     * Force regeneration of JWT key for a module
     *
     * WARNING: This will invalidate all existing tokens for the module.
     * Use with caution, typically only for security incidents.
     *
     * @param string $moduleName Module name
     * @return string|false New key on success, false on failure
     */
    public static function rotateKey(string $moduleName)
    {
        global $db;

        $moduleName = strtoupper(trim($moduleName));
        if (empty($moduleName)) {
            return false;
        }

        $configKey = $moduleName . '_JWT_KEY';
        $newKey = self::generateKey();

        if (self::storeKey($db, $configKey, $newKey)) {
            dol_syslog("SmartAuth JwtKeyHelper: Rotated JWT key for $moduleName", LOG_WARNING);

            // Also update the global conf cache
            global $conf;
            if (isset($conf->global)) {
                $conf->global->$configKey = $newKey;
            }

            return $newKey;
        }

        return false;
    }

    /**
     * Get the configuration key name for a module
     *
     * @param string $moduleName Module name
     * @return string Configuration key (e.g., 'MYMODULE_JWT_KEY')
     */
    public static function getConfigKeyName(string $moduleName): string
    {
        return strtoupper(trim($moduleName)) . '_JWT_KEY';
    }
}
