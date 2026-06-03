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

use Minishlink\WebPush\VAPID;

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
            dol_syslog('VapidKeyHelper::ensureKeys key generation failed: '.$e->getMessage(), LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        if (!self::storeKeys($db, $keys)) {
            dol_syslog('VapidKeyHelper::ensureKeys failed to store generated VAPID keys', LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        dol_syslog('VapidKeyHelper::ensureKeys generated new VAPID key pair (entity '.self::KEY_ENTITY.')', LOG_NOTICE);
        return $keys;
    }

    /**
     * Generate a new VAPID key pair.
     *
     * @return array{publicKey:string, privateKey:string}
     */
    public static function generateKeys()
    {
        self::ensureWebPushLoaded();
        // minishlink/web-push provides this method.
        return VAPID::createVapidKeys();
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
            dol_syslog('VapidKeyHelper::regenerateKeys failed to store new VAPID keys', LOG_ERR);
            return ['publicKey' => '', 'privateKey' => ''];
        }

        // VAPID keys are global (KEY_ENTITY = 0), so regenerating invalidates
        // EVERY subscription across ALL entities, not just the current one.
        // No entity filter here on purpose.
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET status = 9";
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('VapidKeyHelper::regenerateKeys failed to expire subscriptions: '.$db->lasterror(), LOG_ERR);
        }

        dol_syslog('VapidKeyHelper::regenerateKeys rotated VAPID key pair', LOG_WARNING);
        return $keys;
    }

    /**
     * Make sure the minishlink/web-push classes are autoloadable.
     *
     * VAPID generation can run in contexts that do not boot the SmartAuth API
     * front controller (module install, cron, admin page), where the composer
     * autoloader may not be registered yet. Load it defensively.
     *
     * @return void
     */
    private static function ensureWebPushLoaded()
    {
        if (class_exists('Minishlink\\WebPush\\VAPID')) {
            return;
        }
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
