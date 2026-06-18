<?php

/**
 * VapidKeyHelper.php
 *
 * Helper for VAPID key management (Web Push). Handles automatic generation and
 * storage of the VAPID key pair. Pattern similar to JwtKeyHelper.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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

class VapidKeyHelper
{
    const PUBLIC_KEY_CONFIG = 'SMARTAUTH_VAPID_PUBLIC_KEY';
    const PRIVATE_KEY_CONFIG = 'SMARTAUTH_VAPID_PRIVATE_KEY';

    // VAPID keys are GLOBAL (entity 0), not per-tenant. VAPID identifies the
    // push application server, not the Dolibarr entity. Storing at entity 0
    // guarantees that the public route, subscribe and PushSender all read the
    // same key regardless of the entity context they run in -- getDolGlobalString
    // sees entity-0 consts from every entity, so read always matches write.
    const KEY_ENTITY = 0;

    /**
     * Read the VAPID public key WITHOUT generating it.
     *
     * Used by the public GET /push/vapid-public-key route and by PushSender:
     * neither must trigger a DB write. Keys are created at install
     * (modSmartauth::init -> ensureKeys) or via the admin button.
     *
     * @param \DoliDB $db Database connection (unused, kept for signature symmetry)
     * @return string|null Base64url-encoded public key, or null if absent
     */
    public static function readPublicKey($db)
    {
        $key = getDolGlobalString(self::PUBLIC_KEY_CONFIG, '');
        return $key !== '' ? $key : null;
    }

    /**
     * Read both VAPID keys WITHOUT generating them.
     *
     * @param \DoliDB $db Database connection (unused, kept for signature symmetry)
     * @return array{publicKey:string, privateKey:string} (values may be '')
     */
    public static function readKeys($db)
    {
        return [
            'publicKey'  => getDolGlobalString(self::PUBLIC_KEY_CONFIG, ''),
            'privateKey' => getDolGlobalString(self::PRIVATE_KEY_CONFIG, ''),
        ];
    }

    /**
     * Ensure VAPID keys exist, generating+storing them once if missing.
     *
     * Call this from modSmartauth::init() (install/enable) and from the admin
     * page. Do NOT call it from a public/unauthenticated request path: key
     * generation is a write and must not be triggerable anonymously.
     *
     * Re-entrant: if keys already exist it does nothing (regenerating would
     * invalidate every subscription). Key generation is wrapped so a broken
     * OpenSSL configuration never aborts module install -- push simply stays
     * unconfigured (GET /push/vapid-public-key returns 500) until keys exist.
     *
     * @param \DoliDB $db Database connection
     * @return array{publicKey:string, privateKey:string}
     */
    public static function ensureKeys($db)
    {
        $keys = self::readKeys($db);
        if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
            return $keys;
        }

        try {
            $keys = self::generateKeys();
        } catch (\Throwable $e) {
            // Do not abort module install: log and leave keys unconfigured.
            dol_syslog('[SmartAuth] VapidKeyHelper::ensureKeys key generation failed: '.$e->getMessage(), LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        if (!self::storeKeys($db, $keys)) {
            dol_syslog('[SmartAuth] VapidKeyHelper::ensureKeys failed to store generated VAPID keys', LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        dol_syslog('[SmartAuth] VapidKeyHelper::ensureKeys generated new VAPID key pair (entity '.self::KEY_ENTITY.')', LOG_NOTICE);
        return $keys;
    }

    /**
     * Generate a new VAPID key pair.
     *
     * @return array{publicKey:string, privateKey:string}
     */
    public static function generateKeys()
    {
        // Generate the P-256 key pair ourselves (no external library): public =
        // base64url(0x04 || X || Y), private = base64url(d), the VAPID format.
        $res = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($res === false) {
            // Some hosts ship a broken or templated openssl.cnf (unresolved
            // placeholders, missing sections), which makes openssl_pkey_new()
            // fail with no fault of ours. Retry with a minimal, self-contained
            // OpenSSL config so EC key generation does not depend on the host file.
            dol_syslog('[SmartAuth] VapidKeyHelper::generateKeys default OpenSSL config failed, retrying with a minimal config', LOG_WARNING);
            return self::generateKeysWithFallbackOpensslConf();
        }
        return self::extractVapidKeys($res);
    }

    /**
     * Extract VAPID-format keys (base64url) from an OpenSSL EC key resource.
     *
     * @param \OpenSSLAsymmetricKey|resource $res
     * @return array{publicKey:string, privateKey:string}
     */
    private static function extractVapidKeys($res)
    {
        $details = openssl_pkey_get_details($res);
        if ($details === false
            || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
            throw new \RuntimeException('Unable to read EC key details for VAPID');
        }

        // P-256 field elements are 32 bytes; left-pad defensively.
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);

        return [
            'publicKey'  => self::base64urlEncode("\x04".$x.$y),
            'privateKey' => self::base64urlEncode($d),
        ];
    }

    /**
     * Generate the VAPID key pair ourselves, passing an explicit minimal
     * OpenSSL config to openssl_pkey_new(), bypassing a broken/templated host
     * openssl.cnf.
     *
     * Note: OpenSSL 3.x caches its config on first use, so a process-wide
     * putenv('OPENSSL_CONF=...') set late has no effect (and Dolibarr has
     * already used OpenSSL by then). The reliable lever is the per-call
     * 'config' argument, hence we build the P-256 key here and format it as
     * VAPID expects: public = base64url(0x04 || X || Y), private = base64url(d).
     *
     * @return array{publicKey:string, privateKey:string}
     */
    private static function generateKeysWithFallbackOpensslConf()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'satvapid_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary OpenSSL config for VAPID key generation');
        }
        // Just enough for EC key generation; independent of the host file.
        file_put_contents($tmp, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");

        try {
            $res = openssl_pkey_new([
                'curve_name'       => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'config'           => $tmp,
            ]);
            if ($res === false) {
                throw new \RuntimeException('openssl_pkey_new failed for EC P-256 with fallback config');
            }
            $keys = self::extractVapidKeys($res);

            // Drain any queued OpenSSL error so it does not surface elsewhere.
            while (openssl_error_string() !== false) {
                // no-op
            }

            return $keys;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Base64url encode (no padding), as expected for VAPID keys.
     *
     * @param string $bin
     * @return string
     */
    private static function base64urlEncode($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Store VAPID keys in Dolibarr configuration (global, entity 0).
     *
     * @param \DoliDB $db Database connection
     * @param array   $keys ['publicKey' => '...', 'privateKey' => '...']
     * @return bool Success
     */
    public static function storeKeys($db, $keys)
    {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        // entity = self::KEY_ENTITY (0) -> global key pair, see const above.
        $result1 = dolibarr_set_const(
            $db,
            self::PUBLIC_KEY_CONFIG,
            $keys['publicKey'],
            'chaine',
            0,
            'VAPID public key for Web Push',
            self::KEY_ENTITY
        );

        $result2 = dolibarr_set_const(
            $db,
            self::PRIVATE_KEY_CONFIG,
            $keys['privateKey'],
            'chaine',
            0,
            'VAPID private key for Web Push',
            self::KEY_ENTITY
        );

        return ($result1 > 0 && $result2 > 0);
    }

    /**
     * Regenerate VAPID keys (invalidates ALL existing subscriptions!).
     *
     * WARNING: this invalidates every push subscription across ALL entities
     * (the key pair is global). Users will need to re-subscribe.
     *
     * @param \DoliDB $db Database connection
     * @return array{publicKey:string, privateKey:string} New keys
     */
    public static function regenerateKeys($db)
    {
        $keys = self::generateKeys();

        if (!self::storeKeys($db, $keys)) {
            dol_syslog('[SmartAuth] VapidKeyHelper::regenerateKeys failed to store new VAPID keys', LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        // VAPID keys are global (KEY_ENTITY = 0), so regenerating invalidates
        // EVERY subscription across ALL entities, not just the current one.
        // No entity filter here on purpose.
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET status = 9";
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] VapidKeyHelper::regenerateKeys failed to expire subscriptions: '.$db->lasterror(), LOG_ERR);
        }

        dol_syslog('[SmartAuth] VapidKeyHelper::regenerateKeys rotated VAPID key pair', LOG_WARNING);
        return $keys;
    }

}
