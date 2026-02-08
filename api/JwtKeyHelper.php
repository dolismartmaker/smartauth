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

    // =========================================================================
    // RSA Key Management for OAuth2/OIDC (RS256)
    // =========================================================================

    /**
     * RSA key size in bits
     */
    const RSA_KEY_BITS = 2048;

    /**
     * Configuration key names for RSA keys
     */
    const RSA_PRIVATE_KEY_CONFIG = 'SMARTAUTH_OAUTH_RSA_PRIVATE_KEY';
    const RSA_PUBLIC_KEY_CONFIG = 'SMARTAUTH_OAUTH_RSA_PUBLIC_KEY';
    const RSA_KEY_ID_CONFIG = 'SMARTAUTH_OAUTH_RSA_KID';

    /**
     * Get or generate RSA private key for OAuth2/OIDC
     *
     * @return string PEM-encoded private key
     * @throws \RuntimeException If key generation fails
     */
    public static function getRsaPrivateKey(): string
    {
        global $db;

        $privateKey = getDolGlobalString(self::RSA_PRIVATE_KEY_CONFIG, '');

        if (empty($privateKey)) {
            // Generate new RSA key pair
            self::generateRsaKeyPair();
            $privateKey = getDolGlobalString(self::RSA_PRIVATE_KEY_CONFIG, '');

            if (empty($privateKey)) {
                throw new \RuntimeException('Failed to generate or retrieve RSA private key');
            }
        }

        return $privateKey;
    }

    /**
     * Get RSA public key for OAuth2/OIDC
     *
     * @return string PEM-encoded public key
     * @throws \RuntimeException If key retrieval fails
     */
    public static function getRsaPublicKey(): string
    {
        global $db;

        $publicKey = getDolGlobalString(self::RSA_PUBLIC_KEY_CONFIG, '');

        if (empty($publicKey)) {
            // Generate new RSA key pair
            self::generateRsaKeyPair();
            $publicKey = getDolGlobalString(self::RSA_PUBLIC_KEY_CONFIG, '');

            if (empty($publicKey)) {
                throw new \RuntimeException('Failed to generate or retrieve RSA public key');
            }
        }

        return $publicKey;
    }

    /**
     * Get the Key ID (kid) for the current RSA key
     *
     * @return string Key ID
     */
    public static function getRsaKeyId(): string
    {
        $kid = getDolGlobalString(self::RSA_KEY_ID_CONFIG, '');

        if (empty($kid)) {
            // Generate key pair which will also create kid
            self::generateRsaKeyPair();
            $kid = getDolGlobalString(self::RSA_KEY_ID_CONFIG, '');

            if (empty($kid)) {
                // Fallback kid if generation failed
                return 'smartauth-' . date('Y');
            }
        }

        return $kid;
    }

    /**
     * Generate a new RSA key pair and store in database
     *
     * @return bool Success
     * @throws \RuntimeException If OpenSSL extension is not available
     */
    public static function generateRsaKeyPair(): bool
    {
        global $db, $conf;

        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for RSA key generation');
        }

        // Generate RSA key pair
        $config = [
            'private_key_bits' => self::RSA_KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyResource = openssl_pkey_new($config);
        if ($keyResource === false) {
            dol_syslog('SmartAuth JwtKeyHelper: Failed to generate RSA key pair: ' . openssl_error_string(), LOG_ERR);
            return false;
        }

        // Extract private key
        $privateKeyPem = '';
        if (!openssl_pkey_export($keyResource, $privateKeyPem)) {
            dol_syslog('SmartAuth JwtKeyHelper: Failed to export RSA private key: ' . openssl_error_string(), LOG_ERR);
            return false;
        }

        // Extract public key
        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false) {
            dol_syslog('SmartAuth JwtKeyHelper: Failed to get RSA key details: ' . openssl_error_string(), LOG_ERR);
            return false;
        }
        $publicKeyPem = $keyDetails['key'];

        // Generate Key ID based on public key hash
        $kid = 'smartauth-' . substr(hash('sha256', $publicKeyPem), 0, 8);

        // Store keys in database
        $success = true;

        if (function_exists('dolibarr_set_const')) {
            $success = $success && (dolibarr_set_const($db, self::RSA_PRIVATE_KEY_CONFIG, $privateKeyPem, 'chaine', 0, 'RSA private key for OAuth2/OIDC', 0) > 0);
            $success = $success && (dolibarr_set_const($db, self::RSA_PUBLIC_KEY_CONFIG, $publicKeyPem, 'chaine', 0, 'RSA public key for OAuth2/OIDC', 0) > 0);
            $success = $success && (dolibarr_set_const($db, self::RSA_KEY_ID_CONFIG, $kid, 'chaine', 0, 'RSA Key ID for OAuth2/OIDC', 0) > 0);
        } else {
            $success = $success && self::storeKey($db, self::RSA_PRIVATE_KEY_CONFIG, $privateKeyPem);
            $success = $success && self::storeKey($db, self::RSA_PUBLIC_KEY_CONFIG, $publicKeyPem);
            $success = $success && self::storeKey($db, self::RSA_KEY_ID_CONFIG, $kid);
        }

        if ($success) {
            // Update conf cache
            if (isset($conf->global)) {
                $conf->global->{self::RSA_PRIVATE_KEY_CONFIG} = $privateKeyPem;
                $conf->global->{self::RSA_PUBLIC_KEY_CONFIG} = $publicKeyPem;
                $conf->global->{self::RSA_KEY_ID_CONFIG} = $kid;
            }

            dol_syslog('SmartAuth JwtKeyHelper: Generated new RSA key pair with kid=' . $kid, LOG_INFO);
        } else {
            dol_syslog('SmartAuth JwtKeyHelper: Failed to store RSA key pair', LOG_ERR);
        }

        return $success;
    }

    /**
     * Get JWKS (JSON Web Key Set) containing public key(s)
     *
     * @return array JWKS structure with 'keys' array
     */
    public static function getJwks(): array
    {
        $publicKeyPem = self::getRsaPublicKey();
        $kid = self::getRsaKeyId();

        // Parse public key to extract modulus and exponent
        $keyResource = openssl_pkey_get_public($publicKeyPem);
        if ($keyResource === false) {
            dol_syslog('SmartAuth JwtKeyHelper: Failed to parse public key for JWKS', LOG_ERR);
            return ['keys' => []];
        }

        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA) {
            dol_syslog('SmartAuth JwtKeyHelper: Invalid key type for JWKS', LOG_ERR);
            return ['keys' => []];
        }

        // Convert binary modulus and exponent to base64url
        $n = self::base64UrlEncode($keyDetails['rsa']['n']);
        $e = self::base64UrlEncode($keyDetails['rsa']['e']);

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => $kid,
                    'n' => $n,
                    'e' => $e
                ]
            ]
        ];
    }

    /**
     * Rotate RSA key pair
     *
     * WARNING: This will invalidate all existing OAuth2 tokens.
     * Use with caution, typically only for security incidents.
     *
     * @return bool Success
     */
    public static function rotateRsaKeyPair(): bool
    {
        global $db, $conf;

        // Clear existing keys from conf cache
        if (isset($conf->global)) {
            unset($conf->global->{self::RSA_PRIVATE_KEY_CONFIG});
            unset($conf->global->{self::RSA_PUBLIC_KEY_CONFIG});
            unset($conf->global->{self::RSA_KEY_ID_CONFIG});
        }

        // Delete from database
        if (function_exists('dolibarr_del_const')) {
            dolibarr_del_const($db, self::RSA_PRIVATE_KEY_CONFIG, 0);
            dolibarr_del_const($db, self::RSA_PUBLIC_KEY_CONFIG, 0);
            dolibarr_del_const($db, self::RSA_KEY_ID_CONFIG, 0);
        } else {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name IN ('" .
                $db->escape(self::RSA_PRIVATE_KEY_CONFIG) . "', '" .
                $db->escape(self::RSA_PUBLIC_KEY_CONFIG) . "', '" .
                $db->escape(self::RSA_KEY_ID_CONFIG) . "')";
            $db->query($sql);
        }

        // Generate new key pair
        $result = self::generateRsaKeyPair();

        if ($result) {
            dol_syslog('SmartAuth JwtKeyHelper: RSA key pair rotated successfully', LOG_WARNING);
        }

        return $result;
    }

    /**
     * Check if RSA key pair exists
     *
     * @return bool True if both private and public keys exist
     */
    public static function hasRsaKeyPair(): bool
    {
        $privateKey = getDolGlobalString(self::RSA_PRIVATE_KEY_CONFIG, '');
        $publicKey = getDolGlobalString(self::RSA_PUBLIC_KEY_CONFIG, '');

        return !empty($privateKey) && !empty($publicKey);
    }

    /**
     * Encode data to base64url format (RFC 4648)
     *
     * @param string $data Binary data to encode
     * @return string Base64url encoded string
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode base64url encoded data (RFC 4648)
     *
     * @param string $data Base64url encoded string
     * @return string Decoded binary data
     */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
