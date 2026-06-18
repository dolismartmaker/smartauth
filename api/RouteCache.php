<?php

/**
 * RouteCache.php
 *
 * Route caching system for optimized API routing.
 * Stores compiled routes in a PHP file for fast loading.
 * Each module using smartboot has its own cache.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class RouteCache
{
    /**
     * Current module name (set via init())
     * @var string
     */
    private static $moduleName = '';

    /**
     * Routes being registered (before cache generation)
     * @var array
     */
    private static $registeredRoutes = [];

    /**
     * Cached routes loaded from file
     * @var array|null
     */
    private static $cachedRoutes = null;

    /**
     * Whether we are in registration mode
     * @var bool
     */
    private static $registrationMode = false;

    /**
     * Source file that defines routes (detected via backtrace)
     * @var string
     */
    private static $sourceFile = '';

    /**
     * Whether plugin API autoloaders have already been registered this request
     * @var bool
     */
    private static $pluginAutoloadRegistered = false;

    /**
     * Whether the legacy filesystem-scan fallback warning was already logged
     * this request (avoids spamming the log on every discover call).
     * @var bool
     */
    private static $legacyScanWarned = false;

    /**
     * Initialize the cache for a specific module
     *
     * Must be called before any other method.
     * Example: RouteCache::init('smartmaker');
     *
     * @param string $moduleName Module name (lowercase, e.g., 'smartmaker')
     * @return void
     */
    public static function init(string $moduleName): void
    {
        self::$moduleName = strtolower($moduleName);
        self::$cachedRoutes = null;

        // Register PSR-4 autoloaders for plugin API controllers BEFORE any
        // dispatch happens. On the cache-hit fast path, LocalRoutes.php files
        // are NOT re-included, so a cached route pointing at e.g.
        // Capmail\Api\MailController would otherwise fail with "class not
        // found". This makes any module exposing api/LocalRoutes.php loadable.
        self::registerPluginAutoloaders();

        self::checkCurrentModuleDeclaration();

        SmartAuthLogger::debug("RouteCache: Initialized for module " . self::$moduleName);
    }

    /**
     * Request-time guard: if the module currently being served exposes its own
     * api/LocalRoutes.php, is enabled, but is NOT in the declarative registry
     * (because another module declared module_parts['smartauth'], activating the
     * declarative path and excluding this one), log an ERROR naming the module.
     *
     * This is the fast-diagnosis signal for "I'm serving smartinterventions but
     * its routes 404/403 because its declaration is missing": the log says
     * exactly which module and how to fix it. No-op when the legacy fallback is
     * active (nobody declared -> the scan still loads everyone).
     *
     * @return void
     */
    private static function checkCurrentModuleDeclaration(): void
    {
        global $conf;

        $module = self::$moduleName;
        if ($module === '') {
            return;
        }

        // Declarative path inactive (legacy fallback loads every module) -> fine.
        $declared = ModulePathHelper::activeRouteModules();
        if (empty($declared)) {
            return;
        }
        if (in_array($module, $declared, true)) {
            return; // this module is properly declared
        }

        // Only a problem if this module actually exposes routes and is enabled.
        if (ModulePathHelper::localRoutesFile($module) === '') {
            return;
        }
        if (empty($conf->global->{'MAIN_MODULE_' . strtoupper($module)})) {
            return;
        }

        dol_syslog(
            "[SmartAuth] RouteCache: serving module '" . $module . "' but its api/LocalRoutes.php is NOT loaded:"
            . " the declarative route registry is active (declared modules=[" . implode(',', $declared) . "])"
            . " and '" . $module . "' does not declare module_parts['smartauth']."
            . " Its API routes will return 403/404. Fix: add 'smartauth' => array('routes' => 1) to "
            . $module . "'s module descriptor and re-enable the module (see ~/docs/MODULE.md section 7a).",
            LOG_ERR
        );
    }

    /**
     * Register PSR-4 autoloaders for the API namespace of every custom module
     * that exposes api/LocalRoutes.php.
     *
     * Convention: a module living in custom/<module>/ exposing
     * custom/<module>/api/LocalRoutes.php gets its "<Module>\Api\" namespace
     * (Ucfirst of the directory name) mapped to custom/<module>/api/. So
     * Capmail\Api\MailController resolves to custom/capmail/api/MailController.php.
     *
     * Only opt-in modules (those declaring API routes) are wired, so this does
     * not turn into a filesystem probe for unrelated "Foo\Api\Bar" class names.
     * Runs once per request (guarded), from init(), so it covers both the
     * cache-hit and cache-miss code paths.
     *
     * @return void
     */
    private static function registerPluginAutoloaders(): void
    {
        if (self::$pluginAutoloadRegistered) {
            return;
        }
        self::$pluginAutoloadRegistered = true;

        if (!defined('DOL_DOCUMENT_ROOT')) {
            return;
        }

        $apiModules = [];
        foreach (self::discoverLocalRoutesFiles() as $module => $localRoutesFile) {
            $apiModules[ucfirst($module) . '\\Api\\'] = dirname($localRoutesFile) . '/';
        }
        if (empty($apiModules)) {
            return;
        }

        spl_autoload_register(function ($class) use ($apiModules) {
            foreach ($apiModules as $prefix => $baseDir) {
                $len = strlen($prefix);
                if (strncmp($class, $prefix, $len) !== 0) {
                    continue;
                }
                $relative = substr($class, $len);
                $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }
        });
    }

    /**
     * Get the cache file path for current module
     *
     * @return string
     */
    public static function getCacheFilePath(): string
    {
        if (empty(self::$moduleName)) {
            dol_syslog("[SmartAuth] RouteCache: Module not initialized, call init() first", LOG_ERR);
            return '';
        }
        return DOL_DATA_ROOT . '/' . self::$moduleName . '/cache/routes.php';
    }

    /**
     * Delete every PWA route cache file (DOL_DATA_ROOT/<module>/cache/routes.php).
     *
     * Meant to be called from a SmartMaker route-module's init()/remove() so that
     * enabling, disabling or upgrading such a module invalidates the cached
     * routes of every consuming PWA at once. The caches regenerate lazily on the
     * next API request (re-including the current api/LocalRoutes.php), so a newly
     * added route is picked up immediately -- without a version bump or dev mode.
     *
     * @return int Number of cache files removed
     */
    public static function flushAll(): int
    {
        if (!defined('DOL_DATA_ROOT')) {
            return 0;
        }
        $deleted = 0;
        $files = glob(DOL_DATA_ROOT . '/*/cache/routes.php');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file) && @unlink($file)) {
                    $deleted++;
                }
            }
        }
        dol_syslog("[SmartAuth] RouteCache::flushAll removed " . $deleted . " route cache file(s)", LOG_INFO);
        return $deleted;
    }

    /**
     * Get the version config key for current module
     *
     * @return string Config key like 'SMARTMAKER_VERSION'
     */
    private static function getVersionConfigKey(): string
    {
        return strtoupper(self::$moduleName) . '_VERSION';
    }

    /**
     * Get the current module version
     *
     * @return string
     */
    public static function getCurrentVersion(): string
    {
        if (empty(self::$moduleName)) {
            return '0.0.0';
        }
        return getDolGlobalString(self::getVersionConfigKey(), '0.0.0');
    }

    /**
     * Check if cache is valid (exists and version matches)
     *
     * @return bool
     */
    public static function isCacheValid(): bool
    {
        if (empty(self::$moduleName)) {
            dol_syslog("[SmartAuth] RouteCache: Module not initialized", LOG_ERR);
            return false;
        }

        $cacheFile = self::getCacheFilePath();

        if (empty($cacheFile) || !file_exists($cacheFile)) {
            SmartAuthLogger::debug("RouteCache: Cache file does not exist for " . self::$moduleName);
            return false;
        }

        $cached = self::loadCacheFile();
        if ($cached === null) {
            return false;
        }

        $cachedVersion = $cached['version'] ?? '0.0.0';
        $currentVersion = self::getCurrentVersion();

        if ($cachedVersion !== $currentVersion) {
            SmartAuthLogger::debug("RouteCache: Version mismatch (cached=$cachedVersion, current=$currentVersion)");
            return false;
        }

        // Hot path: the active route-module set/version signature. Detects a
        // consumer module being enabled, disabled or upgraded with an in-memory
        // const comparison -- no filesystem access.
        $cachedSignature = $cached['modules_signature'] ?? '';
        $currentSignature = self::computeModulesSignature();
        if ($cachedSignature !== $currentSignature) {
            SmartAuthLogger::debug("RouteCache: Active route modules changed, cache invalidated");
            return false;
        }

        // Check if source file has been modified since cache generation
        $sourceFile = $cached['source_file'] ?? '';
        if (!empty($sourceFile) && file_exists($sourceFile)) {
            if (filemtime($sourceFile) > filemtime($cacheFile)) {
                SmartAuthLogger::debug("RouteCache: Source file modified, cache invalidated");
                return false;
            }
        }

        // LocalRoutes.php mtime check. Only needed in developer mode (pick up a
        // route edit without a version bump) or in the legacy filesystem-scan
        // fallback (no module declares the part yet). In a migrated production
        // install the signature above already covers every real change, so we
        // skip the filesystem stats entirely on the hot path.
        if (self::isDevMode()) {
            $cachedLocalFiles = $cached['local_routes_files'] ?? [];
            $currentLocalFiles = self::scanLocalRoutesFiles();

            // If the list of files changed, invalidate
            if (array_keys($cachedLocalFiles) !== array_keys($currentLocalFiles)) {
                SmartAuthLogger::debug("RouteCache: Local routes files list changed, cache invalidated");
                return false;
            }

            // If any file was modified, invalidate
            foreach ($currentLocalFiles as $file => $mtime) {
                if (!isset($cachedLocalFiles[$file]) || $cachedLocalFiles[$file] < $mtime) {
                    SmartAuthLogger::debug("RouteCache: Local routes file modified: $file");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Map of active LocalRoutes.php files to their modification time.
     *
     * Used only for the dev-mode / legacy mtime check; the hot-path cache
     * validation in production relies on the modules signature instead, so this
     * is not called on every request once modules are migrated.
     *
     * @return array Map of file path => modification time
     */
    private static function scanLocalRoutesFiles(): array
    {
        $files = [];
        foreach (self::discoverLocalRoutesFiles() as $localRoutesFile) {
            $files[$localRoutesFile] = filemtime($localRoutesFile);
        }
        return $files;
    }

    /**
     * Discover the api/LocalRoutes.php files to load, keyed by module name.
     *
     * Primary source (declarative): modules that declared
     * module_parts['smartauth'] and are enabled -- resolved in-memory from
     * $conf->modules_parts['smartauth'] via ModulePathHelper, no filesystem
     * scan. A disabled module is absent and its routes are NOT loaded:
     * enable/disable is the single source of truth.
     *
     * Legacy fallback: when no module declares the part yet (install upgraded
     * but modules not re-enabled), scan every configured module root for
     * api/LocalRoutes.php so routing keeps working during migration.
     *
     * IMPORTANT: every module exposing api/LocalRoutes.php MUST declare
     * module_parts['smartauth'] => array('routes' => 1) in its descriptor (and be
     * re-enabled so the constant is written). Otherwise, as soon as ANY other
     * module declares it, this module is excluded from the declarative set and
     * its routes silently disappear. See ~/docs/MODULE.md section 7a.
     *
     * @return array<string,string> [moduleName => absolute LocalRoutes.php path]
     */
    private static function discoverLocalRoutesFiles(): array
    {
        $result = [];

        $declared = ModulePathHelper::activeRouteModules();
        if (!empty($declared)) {
            $missing = [];
            foreach ($declared as $module) {
                $file = ModulePathHelper::localRoutesFile($module);
                if ($file !== '') {
                    $result[$module] = $file;
                } else {
                    $missing[] = $module;
                }
            }

            // SmartAuth's OWN api/LocalRoutes.php carries the core auth routes
            // (login, logout, refresh, device, file, sync). It MUST always load,
            // even though SmartAuth does not declare module_parts['smartauth'] for
            // itself: it is the IdP, not a plugin. Without this, enabling any
            // declared consumer module activates the declarative path and silently
            // drops the core auth routes -> /login falls through to 403
            // ("Access denied (end)"). This was the smartinterventions outage.
            if (!isset($result['smartauth'])) {
                $own = ModulePathHelper::localRoutesFile('smartauth');
                if ($own !== '') {
                    $result['smartauth'] = $own;
                }
            }

            dol_syslog("[SmartAuth] RouteCache::discoverLocalRoutesFiles declarative:"
                . " declared=[" . implode(',', $declared) . "]"
                . " included=[" . implode(',', array_keys($result)) . "]"
                . ($missing ? " declared-but-no-file=[" . implode(',', $missing) . "]" : ""));
            return $result;
        }

        // Legacy fallback (pre-migration installs only).
        if (!self::$legacyScanWarned) {
            self::$legacyScanWarned = true;
            dol_syslog(
                "[SmartAuth] RouteCache: no module declares module_parts['smartauth'], "
                . "falling back to filesystem scan (legacy). Re-enable your SmartMaker "
                . "modules to migrate to the declarative route registry.",
                LOG_WARNING
            );
        }
        foreach (ModulePathHelper::moduleRootDirs() as $customDir) {
            $modules = scandir($customDir);
            if ($modules === false) {
                continue;
            }
            foreach ($modules as $module) {
                if ($module === '.' || $module === '..') {
                    continue;
                }
                $localRoutesFile = $customDir . '/' . $module . '/api/LocalRoutes.php';
                if (is_file($localRoutesFile)) {
                    $result[strtolower($module)] = $localRoutesFile;
                }
            }
        }
        dol_syslog("[SmartAuth] RouteCache::discoverLocalRoutesFiles legacy scan included=["
            . implode(',', array_keys($result)) . "]");

        return $result;
    }

    /**
     * Compute a signature of the active route-exposing modules and their
     * versions. Changes when a module is enabled, disabled or upgraded -- the
     * exact moments the cached route set becomes stale. Pure in-memory
     * computation (const reads), so it is cheap enough to run on every request.
     *
     * @return string md5 of the sorted (module => version) map
     */
    private static function computeModulesSignature(): string
    {
        $sig = [];
        foreach (ModulePathHelper::activeRouteModules() as $module) {
            $sig[$module] = getDolGlobalString(strtoupper($module) . '_VERSION', '');
        }
        ksort($sig);
        return md5(serialize($sig));
    }

    /**
     * Whether Dolibarr runs in developer mode (MAIN_FEATURES_LEVEL >= 2). In
     * that mode we also check LocalRoutes.php mtimes so a route edit is picked
     * up immediately, without waiting for a module version bump.
     *
     * @return bool
     */
    private static function isDevMode(): bool
    {
        return function_exists('getDolGlobalInt') && getDolGlobalInt('MAIN_FEATURES_LEVEL') >= 2;
    }

    /**
     * Load routes from cache file
     *
     * @return array|null
     */
    private static function loadCacheFile(): ?array
    {
        $cacheFile = self::getCacheFilePath();

        if (!file_exists($cacheFile)) {
            return null;
        }

        try {
            $cached = include $cacheFile;
            if (is_array($cached) && isset($cached['routes'])) {
                return $cached;
            }
        } catch (\Exception $e) {
            dol_syslog("[SmartAuth] RouteCache: Error loading cache: " . $e->getMessage(), LOG_WARNING);
        }

        return null;
    }

    /**
     * Start registration mode - routes will be collected for caching
     *
     * @return void
     */
    public static function startRegistration(): void
    {
        self::$registrationMode = true;
        self::$registeredRoutes = [];

        // Capture the source file that defines routes
        self::$sourceFile = $_SERVER['SCRIPT_FILENAME'];

        SmartAuthLogger::debug("RouteCache: Started registration mode");
    }

    /**
     * Register a route (called during registration mode)
     *
     * @param string $method HTTP method
     * @param string $action URL pattern
     * @param string $class Controller class
     * @param string $function Controller method
     * @param bool|string $protected false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
     * @return void
     */
    public static function register(string $method, string $action, string $class, string $function, $protected): void
    {
        if (!self::$registrationMode) {
            return;
        }

        self::$registeredRoutes[] = [
            'method' => $method,
            'action' => $action,
            'class' => $class,
            'function' => $function,
            'protected' => $protected,
        ];
    }

    /**
     * End registration mode and save cache
     *
     * @return bool True if cache was saved successfully
     */
    public static function endRegistration(): bool
    {
        if (!self::$registrationMode) {
            return false;
        }

        // Include local routes from modules before ending registration
        self::includeLocalRoutes();

        self::$registrationMode = false;

        $result = self::saveCache(self::$registeredRoutes);
        self::$registeredRoutes = [];

        return $result;
    }

    /**
     * Include LocalRoutes.php files from modules during registration
     *
     * This allows modules to define routes using the same Route::get(), Route::post()
     * syntax as the main api.php file.
     *
     * @return void
     */
    private static function includeLocalRoutes(): void
    {
        self::warnUndeclaredRouteModules();

        $files = self::scanLocalRoutesFiles();

        foreach ($files as $file => $mtime) {
            try {
                SmartAuthLogger::debug("RouteCache: Including local routes from $file");
                include_once $file;
            } catch (\Exception $e) {
                dol_syslog("[SmartAuth] RouteCache: Error including $file: " . $e->getMessage(), LOG_ERR);
            }
        }
    }

    /**
     * Warn (loudly) about modules that expose api/LocalRoutes.php and are ENABLED
     * but do NOT declare module_parts['smartauth']. With the declarative registry,
     * as soon as one module declares the part, any enabled-but-undeclared module
     * is excluded and its API routes silently disappear (the exact failure that
     * broke smartinterventions login). This makes the misconfiguration obvious in
     * the logs. Runs only at cache (re)build time, never on the hot path.
     *
     * @return void
     */
    private static function warnUndeclaredRouteModules(): void
    {
        global $conf;

        $declared = array_flip(ModulePathHelper::activeRouteModules());

        foreach (ModulePathHelper::moduleRootDirs() as $customDir) {
            $modules = scandir($customDir);
            if ($modules === false) {
                continue;
            }
            foreach ($modules as $module) {
                if ($module === '.' || $module === '..') {
                    continue;
                }
                if (!is_file($customDir . '/' . $module . '/api/LocalRoutes.php')) {
                    continue;
                }
                $mod = strtolower($module);
                if (isset($declared[$mod])) {
                    continue; // properly declared
                }
                // Disabled module -> intentionally off, not a misconfiguration.
                if (empty($conf->global->{'MAIN_MODULE_' . strtoupper($module)})) {
                    continue;
                }
                dol_syslog(
                    "[SmartAuth] RouteCache: module '" . $mod . "' is ENABLED and exposes api/LocalRoutes.php"
                    . " but does NOT declare module_parts['smartauth'] => array('routes' => 1) in its descriptor."
                    . " Its API routes are NOT loaded. Add the declaration and re-enable the module"
                    . " (see ~/docs/MODULE.md section 7a).",
                    LOG_WARNING
                );
            }
        }
    }

    /**
     * Save routes to cache file
     *
     * @param array $routes Routes to cache
     * @return bool
     */
    private static function saveCache(array $routes): bool
    {
        $cacheFile = self::getCacheFilePath();
        $cacheDir = dirname($cacheFile);

        dol_syslog("[SmartAuth] RouteCache: save to dir=$cacheDir filename=$cacheFile", LOG_ERR);

        // Create cache directory if needed
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                dol_syslog("[SmartAuth] RouteCache: Failed to create cache directory: $cacheDir", LOG_ERR);
                return false;
            }
        }

        // Build optimized route structure
        // Note: local routes are already included via includeLocalRoutes() during registration
        $optimized = self::optimizeRoutes($routes);

        // Get local routes files for cache validation
        $localRoutesFiles = self::scanLocalRoutesFiles();

        // Generate PHP cache file
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * Route cache - Auto-generated, do not edit\n";
        $content .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n";
        $content .= "return " . var_export([
            'version' => self::getCurrentVersion(),
            'generated' => time(),
            'source_file' => self::$sourceFile,
            'local_routes_files' => $localRoutesFiles,
            'modules_signature' => self::computeModulesSignature(),
            'routes' => $optimized,
        ], true) . ";\n";

        $result = file_put_contents($cacheFile, $content, LOCK_EX);

        if ($result === false) {
            dol_syslog("[SmartAuth] RouteCache: Failed to write cache file: $cacheFile", LOG_ERR);
            return false;
        }

        // Clear opcache for this file if available
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFile, true);
        }

        dol_syslog("[SmartAuth] RouteCache: Cache saved with " . count($routes) . " routes", LOG_INFO);
        return true;
    }

    /**
     * Optimize routes for fast lookup
     *
     * Separates static routes (hash lookup) from dynamic routes (regex matching)
     *
     * @param array $routes Raw routes
     * @return array Optimized structure
     */
    private static function optimizeRoutes(array $routes): array
    {
        $static = [];
        $dynamic = [];

        foreach ($routes as $route) {
            $key = $route['method'] . ':' . $route['action'];

            if (strpos($route['action'], '{') === false) {
                // Static route - direct hash lookup
                $static[$key] = [
                    'class' => $route['class'],
                    'function' => $route['function'],
                    'protected' => $route['protected'],
                ];
            } else {
                // Dynamic route with placeholders - needs regex
                $pattern = self::actionToRegex($route['action']);
                $dynamic[$route['method']][] = [
                    'pattern' => $pattern,
                    'action' => $route['action'],
                    'class' => $route['class'],
                    'function' => $route['function'],
                    'protected' => $route['protected'],
                ];
            }
        }

        return [
            'static' => $static,
            'dynamic' => $dynamic,
        ];
    }

    /**
     * Convert action pattern to regex
     *
     * @param string $action Action pattern like 'users/{id}/posts/{postId}'
     * @return string Regex pattern
     */
    private static function actionToRegex(string $action): string
    {
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $action);
        $pattern = str_replace('/', '\\/', $pattern);
        return '/^' . $pattern . '$/';
    }

    /**
     * Load cached routes into memory
     *
     * @return bool True if cache was loaded
     */
    public static function loadCache(): bool
    {
        if (self::$cachedRoutes !== null) {
            return true;
        }

        $cached = self::loadCacheFile();
        if ($cached === null) {
            return false;
        }

        self::$cachedRoutes = $cached['routes'];
        SmartAuthLogger::debug("RouteCache: Loaded cache with version " . $cached['version']);
        return true;
    }

    /**
     * Find a matching route in cache
     *
     * @param string $method HTTP method
     * @param string $action Request action/path
     * @return array|null Route info or null if not found
     */
    public static function findRoute(string $method, string $action): ?array
    {
        if (self::$cachedRoutes === null) {
            return null;
        }

        // Try static lookup first (O(1))
        $key = $method . ':' . $action;
        if (isset(self::$cachedRoutes['static'][$key])) {
            $route = self::$cachedRoutes['static'][$key];
            $route['action'] = $action;
            $route['params'] = [];
            return $route;
        }

        // Try dynamic routes for this method
        if (isset(self::$cachedRoutes['dynamic'][$method])) {
            foreach (self::$cachedRoutes['dynamic'][$method] as $route) {
                if (preg_match($route['pattern'], $action, $matches)) {
                    // Extract parameters
                    $params = self::extractParams($route['action'], $action);
                    return [
                        'action' => $route['action'],
                        'class' => $route['class'],
                        'function' => $route['function'],
                        'protected' => $route['protected'],
                        'params' => $params,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Extract URL parameters from action
     *
     * @param string $pattern Pattern like 'users/{id}'
     * @param string $action Actual path like 'users/123'
     * @return array Parameters ['id' => '123']
     */
    private static function extractParams(string $pattern, string $action): array
    {
        $params = [];
        $patternParts = explode('/', $pattern);
        $actionParts = explode('/', $action);

        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $match)) {
                $params[$match[1]] = $actionParts[$i] ?? '';
            }
        }

        return $params;
    }

    /**
     * Check if we're in registration mode
     *
     * @return bool
     */
    public static function isRegistrationMode(): bool
    {
        return self::$registrationMode;
    }

    /**
     * Get the current module name
     *
     * @return string Module name (lowercase) or empty string if not initialized
     */
    public static function getModuleName(): string
    {
        return self::$moduleName;
    }

    /**
     * Get all cached routes (for debugging)
     *
     * @return array|null
     */
    public static function getCachedRoutes(): ?array
    {
        return self::$cachedRoutes;
    }

    /**
     * Clear the cache file
     *
     * @return bool
     */
    public static function clearCache(): bool
    {
        $cacheFile = self::getCacheFilePath();

        if (file_exists($cacheFile)) {
            $result = unlink($cacheFile);
            if ($result) {
                dol_syslog("[SmartAuth] RouteCache: Cache cleared", LOG_INFO);
                self::$cachedRoutes = null;
            }
            return $result;
        }

        return true;
    }
}
