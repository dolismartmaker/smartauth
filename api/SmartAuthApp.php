<?php

/**
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
 */

namespace SmartAuth\Api;

/**
 * Resolves identity / version of the host module that wires smartauth into
 * its smartmaker-api/ entrypoint. Modules pass their DolibarrModules instance
 * via the $smartAuthApp global (preferred) and fall back to the legacy
 * $smartAuthAppID / $smartAuthAppKey scalars for backward compatibility.
 *
 * Usage:
 *   $appId = SmartAuthApp::id();
 *   $appVersion = SmartAuthApp::version();
 *   $appName = SmartAuthApp::name();
 *
 * Migrating modules:
 *   In smartmaker-api-prepend.php replace
 *       $smartAuthAppID = $tmpmodule->numero;
 *   with
 *       $smartAuthApp = $tmpmodule;
 *       $smartAuthAppID = $tmpmodule->numero;  // legacy, will be removed
 */
class SmartAuthApp
{
    /**
     * Numeric id of the host module (DolibarrModules->numero).
     * Falls back to legacy $smartAuthAppID for non-migrated modules.
     */
    public static function id(): int
    {
        global $smartAuthApp, $smartAuthAppID;
        if (is_object($smartAuthApp) && isset($smartAuthApp->numero)) {
            return (int) $smartAuthApp->numero;
        }
        return (int) ($smartAuthAppID ?? 0);
    }

    /**
     * Module version string (e.g. "2.0.42") or empty when the host module
     * hasn't been migrated yet (legacy $smartAuthAppID-only callers).
     */
    public static function version(): string
    {
        global $smartAuthApp;
        if (is_object($smartAuthApp) && isset($smartAuthApp->version)) {
            return (string) $smartAuthApp->version;
        }
        return '';
    }

    /**
     * Human-readable module name (e.g. "SmartInterventions").
     * Useful for telemetry and the PWA's About modal.
     */
    public static function name(): string
    {
        global $smartAuthApp;
        if (is_object($smartAuthApp) && isset($smartAuthApp->name)) {
            return (string) $smartAuthApp->name;
        }
        return '';
    }

    /**
     * Pinned smartauth version. Single source of truth for clients that
     * want to display "smartauth X.Y.Z" in their About screen. Kept in sync
     * with core/modules/modSmartauth.class.php::$version on every release.
     */
    public static function smartauthVersion(): string
    {
        return '2.0.15';
    }
}
