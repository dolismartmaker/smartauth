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
     * Running smartauth version, for clients that want to display
     * "smartauth X.Y.Z" in their About screen.
     *
     * Primary source: llx_const, written by modSmartauth::init() at every
     * (re)activation/upgrade as SMARTAUTH_MODULE_VERSION (same convention
     * as PEPPOL_MODULE_VERSION etc.). Read is a cheap getDolGlobalString().
     *
     * Fallback: if the constant is missing (fresh code update before the
     * admin re-activates the module), parse the descriptor with the exact
     * same regex buildzip.php uses to extract the version literal, so the
     * two stay aligned by construction.
     */
    public static function smartauthVersion(): string
    {
        $v = (string) getDolGlobalString('SMARTAUTH_MODULE_VERSION');
        if ($v !== '') {
            return $v;
        }
        $descriptor = __DIR__ . '/../core/modules/modSmartauth.class.php';
        if (is_readable($descriptor)) {
            $content = @file_get_contents($descriptor);
            if ($content !== false
                && preg_match("/^.*this->version\s*=\s*'(?<version>[^']+)'\s*;/m", $content, $m)
            ) {
                return $m['version'];
            }
        }
        return 'unknown';
    }
}
