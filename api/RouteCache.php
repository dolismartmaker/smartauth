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
        dol_syslog("RouteCache: Initialized for module " . self::$moduleName, LOG_DEBUG);
    }

    /**
     * Get the cache file path for current module
     *
     * @return string
     */
    public static function getCacheFilePath(): string
    {
        if (empty(self::$moduleName)) {
            dol_syslog("RouteCache: Module not initialized, call init() first", LOG_ERR);
            return '';
        }
        return DOL_DATA_ROOT . '/' . self::$moduleName . '/cache/routes.php';
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
            dol_syslog("RouteCache: Module not initialized", LOG_ERR);
            return false;
        }

        $cacheFile = self::getCacheFilePath();

        if (empty($cacheFile) || !file_exists($cacheFile)) {
            dol_syslog("RouteCache: Cache file does not exist for " . self::$moduleName, LOG_DEBUG);
            return false;
        }

        $cached = self::loadCacheFile();
        if ($cached === null) {
            return false;
        }

        $cachedVersion = $cached['version'] ?? '0.0.0';
        $currentVersion = self::getCurrentVersion();

        if ($cachedVersion !== $currentVersion) {
            dol_syslog("RouteCache: Version mismatch (cached=$cachedVersion, current=$currentVersion)", LOG_DEBUG);
            return false;
        }

        // Check if source file has been modified since cache generation
        $sourceFile = $cached['source_file'] ?? '';
        if (!empty($sourceFile) && file_exists($sourceFile)) {
            if (filemtime($sourceFile) > filemtime($cacheFile)) {
                dol_syslog("RouteCache: Source file modified, cache invalidated", LOG_DEBUG);
                return false;
            }
        }

        // Check if any LocalRoutes.php files have been modified
        $cachedLocalFiles = $cached['local_routes_files'] ?? [];
        $currentLocalFiles = self::scanLocalRoutesFiles();

        // If the list of files changed, invalidate
        if (array_keys($cachedLocalFiles) !== array_keys($currentLocalFiles)) {
            dol_syslog("RouteCache: Local routes files list changed, cache invalidated", LOG_DEBUG);
            return false;
        }

        // If any file was modified, invalidate
        foreach ($currentLocalFiles as $file => $mtime) {
            if (!isset($cachedLocalFiles[$file]) || $cachedLocalFiles[$file] < $mtime) {
                dol_syslog("RouteCache: Local routes file modified: $file", LOG_DEBUG);
                return false;
            }
        }

        return true;
    }

    /**
     * Scan for LocalRoutes.php files in active modules
     *
     * @return array Map of file path => modification time
     */
    private static function scanLocalRoutesFiles(): array
    {
        $files = [];

        // Scan custom modules directory
        $customDir = DOL_DOCUMENT_ROOT . '/custom';
        if (!is_dir($customDir)) {
            return $files;
        }

        $modules = scandir($customDir);
        foreach ($modules as $module) {
            if ($module === '.' || $module === '..') {
                continue;
            }

            $localRoutesFile = $customDir . '/' . $module . '/api/LocalRoutes.php';
            if (file_exists($localRoutesFile)) {
                $files[$localRoutesFile] = filemtime($localRoutesFile);
            }
        }

        return $files;
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
            dol_syslog("RouteCache: Error loading cache: " . $e->getMessage(), LOG_WARNING);
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

        dol_syslog("RouteCache: Started registration mode", LOG_DEBUG);
    }

    /**
     * Register a route (called during registration mode)
     *
     * @param string $method HTTP method
     * @param string $action URL pattern
     * @param string $class Controller class
     * @param string $function Controller method
     * @param bool $protected Whether route requires auth
     * @return void
     */
    public static function register(string $method, string $action, string $class, string $function, bool $protected): void
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
        $files = self::scanLocalRoutesFiles();

        foreach ($files as $file => $mtime) {
            try {
                dol_syslog("RouteCache: Including local routes from $file", LOG_DEBUG);
                include_once $file;
            } catch (\Exception $e) {
                dol_syslog("RouteCache: Error including $file: " . $e->getMessage(), LOG_ERR);
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

        dol_syslog("RouteCache: save to dir=$cacheDir filename=$cacheFile", LOG_ERR);

        // Create cache directory if needed
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                dol_syslog("RouteCache: Failed to create cache directory: $cacheDir", LOG_ERR);
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
            'routes' => $optimized,
        ], true) . ";\n";

        $result = file_put_contents($cacheFile, $content, LOCK_EX);

        if ($result === false) {
            dol_syslog("RouteCache: Failed to write cache file: $cacheFile", LOG_ERR);
            return false;
        }

        // Clear opcache for this file if available
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFile, true);
        }

        dol_syslog("RouteCache: Cache saved with " . count($routes) . " routes", LOG_INFO);
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
        dol_syslog("RouteCache: Loaded cache with version " . $cached['version'], LOG_DEBUG);
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
                dol_syslog("RouteCache: Cache cleared", LOG_INFO);
                self::$cachedRoutes = null;
            }
            return $result;
        }

        return true;
    }
}
