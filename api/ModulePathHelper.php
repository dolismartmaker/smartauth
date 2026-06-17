<?php

/**
 * ModulePathHelper.php
 *
 * Resolves the filesystem directories that may contain Dolibarr custom modules.
 *
 * Dolibarr does not force custom modules to live under htdocs/custom. The admin
 * declares one or more alternative roots in conf.php via
 * $dolibarr_main_document_root_alt (default: htdocs/custom, but it can be a
 * comma-separated list pointing anywhere). At runtime these are exposed as
 * $conf->file->dol_document_root, an array keyed 'main', 'alt0', 'alt1', ...
 * Hardcoding DOL_DOCUMENT_ROOT . '/custom' therefore misses modules installed in
 * a non-default or secondary alternative root.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class ModulePathHelper
{
    /**
     * Per-request memo of resolved module root directories.
     * @var string[]|null
     */
    private static $cachedRootDirs = null;

    /**
     * Return the filesystem directories that may host Dolibarr custom modules.
     *
     * Reads $conf->file->dol_document_root and returns every entry except 'main'
     * (the core htdocs, which never holds custom modules). Falls back to the
     * historical DOL_DOCUMENT_ROOT . '/custom' when conf is not populated yet
     * (early boot, CLI without full env). Only existing directories are returned,
     * de-duplicated and without trailing slash.
     *
     * Result is memoized for the request: dol_document_root is fixed once
     * conf.php is loaded, so there is no benefit in re-scanning per call.
     *
     * @return string[] Absolute directory paths (existing dirs only)
     */
    public static function moduleRootDirs(): array
    {
        if (self::$cachedRootDirs !== null) {
            return self::$cachedRootDirs;
        }

        global $conf;

        $dirs = [];
        if (isset($conf->file->dol_document_root) && is_array($conf->file->dol_document_root)) {
            foreach ($conf->file->dol_document_root as $key => $dirroot) {
                if ($key === 'main') {
                    continue;
                }
                if (is_string($dirroot) && $dirroot !== '') {
                    $dirs[] = rtrim($dirroot, '/');
                }
            }
        }

        // Fallback for contexts where $conf->file is not (fully) populated.
        if (empty($dirs) && defined('DOL_DOCUMENT_ROOT')) {
            $dirs[] = DOL_DOCUMENT_ROOT . '/custom';
        }

        $dirs = array_values(array_unique($dirs));
        $dirs = array_values(array_filter($dirs, 'is_dir'));

        self::$cachedRootDirs = $dirs;
        return $dirs;
    }

    /**
     * Return the URL path prefix under which a given module is served, including
     * the module name. E.g. '/custom/smartauth' on a default install, but
     * '/extensions1/smartauth' if the admin moved the alternative root.
     *
     * dol_document_root and dol_url_root are parallel arrays keyed identically
     * ('main', 'alt0', ...): the alt root whose filesystem dir contains the
     * module gives, through the same key, the matching URL prefix. Returns the
     * host-relative path only (no scheme/host) so callers compose it with their
     * own host logic. Falls back to '/custom/<module>' when conf is unavailable
     * or the module is not found in any alternative root.
     *
     * @param string $moduleName Module directory name (e.g. 'smartauth')
     * @return string Host-relative URL prefix, no trailing slash
     */
    public static function moduleUrlPrefix(string $moduleName): string
    {
        global $conf;

        $moduleName = strtolower($moduleName);

        if (isset($conf->file->dol_document_root, $conf->file->dol_url_root)
            && is_array($conf->file->dol_document_root)
            && is_array($conf->file->dol_url_root)) {
            foreach ($conf->file->dol_document_root as $key => $dirroot) {
                if ($key === 'main' || !is_string($dirroot) || $dirroot === '') {
                    continue;
                }
                if (!isset($conf->file->dol_url_root[$key]) || !is_string($conf->file->dol_url_root[$key])) {
                    continue;
                }
                if (is_dir(rtrim($dirroot, '/') . '/' . $moduleName)) {
                    $urlRoot = $conf->file->dol_url_root[$key];
                    // Old conf syntax allowed a full http(s) URL here; in that
                    // case the caller's host prefix would be redundant, so we
                    // skip it and let the fallback handle host composition.
                    if (preg_match('/^https?:/i', $urlRoot)) {
                        continue;
                    }
                    return rtrim($urlRoot, '/') . '/' . $moduleName;
                }
            }
        }

        return '/custom/' . $moduleName;
    }

    /**
     * Return the lowercase names of modules that declared they expose SmartAuth
     * API routes (module_parts['smartauth'] in their descriptor) AND are
     * currently enabled.
     *
     * Dolibarr writes each enabled module's MAIN_MODULE_<NAME>_SMARTAUTH const
     * on activation and removes it on deactivation, then aggregates it into
     * $conf->modules_parts['smartauth'] keyed by module name. Reading those keys
     * is therefore an in-memory lookup (no filesystem scan) that already
     * reflects the enable/disable state: a disabled module simply disappears
     * from the list, so its routes are no longer loaded.
     *
     * Returns an empty array when no module declares the part (e.g. an install
     * upgraded but whose modules have not been re-enabled yet); callers fall
     * back to a filesystem scan in that transitional case.
     *
     * @return string[] Module names (lowercase), empty if none declared
     */
    public static function activeRouteModules(): array
    {
        global $conf;

        if (isset($conf->modules_parts['smartauth']) && is_array($conf->modules_parts['smartauth'])) {
            $names = [];
            foreach (array_keys($conf->modules_parts['smartauth']) as $name) {
                $names[] = strtolower((string) $name);
            }
            return array_values(array_unique($names));
        }

        return [];
    }

    /**
     * Resolve the absolute path of a module's api/LocalRoutes.php across every
     * configured module root, or '' when the module exposes no such file.
     *
     * @param string $moduleName Module directory name (e.g. 'capmail')
     * @return string Absolute path, or '' if not found
     */
    public static function localRoutesFile(string $moduleName): string
    {
        $moduleName = strtolower($moduleName);
        foreach (self::moduleRootDirs() as $root) {
            $file = $root . '/' . $moduleName . '/api/LocalRoutes.php';
            if (is_file($file)) {
                return $file;
            }
        }
        return '';
    }

    /**
     * Reset the per-request memo. Test-only helper (dol_document_root changes
     * between test cases that simulate different conf.php setups).
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$cachedRootDirs = null;
    }
}
