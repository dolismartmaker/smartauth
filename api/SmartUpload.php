<?php

/**
 * SmartUpload.php
 *
 * Reusable service for ingesting binary file uploads from PWA modules.
 *
 * The PWA POSTs a multipart/form-data request to /upload with one or more
 * files. SmartUpload validates each file (MIME, size), stores it in a
 * per-user staging area, and returns an opaque upload id. The business
 * module then uses UploadHelper::consumeUpload() in its own controller to
 * move the staged file into its final location.
 *
 * Storage layout: <smartauth_dir_output>/uploads/<user_id>/<upload_id>/
 * with a sibling .json metadata file. Files expire after a TTL and are
 * cleaned up probabilistically on every store() call.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class SmartUpload
{
    /**
     * Default TTL for a staged upload (1 hour). The business module is
     * expected to consume the upload as part of the immediate next API
     * call, so a short TTL is enough.
     */
    const DEFAULT_TTL = 3600;

    /**
     * Hard maximum TTL (24 hours).
     */
    const MAX_TTL = 86400;

    /**
     * Default max upload size (10 MB) - tweakable via Dolibarr global
     * SMARTAUTH_UPLOAD_MAX_BYTES.
     */
    const DEFAULT_MAX_BYTES = 10485760;

    /**
     * 1 in N stores triggers an expired-files cleanup pass.
     */
    const CLEANUP_PROBABILITY = 20;

    /**
     * Default whitelist of accepted MIME types. Modules can override via
     * SMARTAUTH_UPLOAD_ALLOWED_MIME (comma-separated list).
     */
    const DEFAULT_ALLOWED_MIME = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    /**
     * Validate a single $_FILES entry. Returns null if valid, or an
     * error string describing why it was rejected.
     *
     * @param array $file       Single $_FILES entry (name, type, tmp_name, error, size)
     * @param array|null $options Optional overrides:
     *                             - 'maxBytes' (int)
     *                             - 'allowedMime' (array of MIME strings)
     * @return string|null      null on success, error message otherwise
     */
    public static function validate($file, $options = null)
    {
        if (!is_array($file)) {
            return 'Invalid file payload';
        }

        // PHP upload error code first: it is more reliable than checking
        // tmp_name because the file may not exist on disk if PHP rejected it.
        $err = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            return self::translateUploadError($err);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            // Allow tests to bypass is_uploaded_file() by storing files
            // through SmartUpload::storeFromPath() directly. For the
            // multipart entry path, always require a real upload.
            return 'Not a valid uploaded file';
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        $maxBytes = self::resolveMaxBytes($options);
        if ($size <= 0) {
            return 'Empty file';
        }
        if ($size > $maxBytes) {
            return 'File too large (max ' . $maxBytes . ' bytes)';
        }

        // Re-detect MIME from disk: the client-supplied $file['type'] is
        // user-controlled and cannot be trusted.
        $detectedMime = self::detectMime($file['tmp_name'], $file['name'] ?? '');
        $allowedMime = self::resolveAllowedMime($options);
        if (!in_array($detectedMime, $allowedMime, true)) {
            return 'MIME type not allowed: ' . $detectedMime;
        }

        return null;
    }

    /**
     * Move a validated $_FILES entry into the staging area and return an
     * upload id. Throws on filesystem failures.
     *
     * @param array $file        Single $_FILES entry, already validated
     * @param int   $userId      Owning user id (for access control on consume)
     * @param int|null $entity   Dolibarr entity (defaults to $conf->entity)
     * @param int|null $ttl      Time-to-live in seconds
     * @param array|null $options Forwarded to validate() for MIME/size overrides
     * @return array             { upload_id, filename, mime, size, sha256 }
     */
    public static function store($file, $userId, $entity = null, $ttl = null, $options = null)
    {
        global $conf;

        if ($userId <= 0) {
            throw new \InvalidArgumentException('SmartUpload::store requires a valid user id');
        }

        // Re-validate defensively. Cheap, and protects callers that forget.
        $error = self::validate($file, $options);
        if ($error !== null) {
            throw new \RuntimeException('Upload validation failed: ' . $error);
        }

        $entity = $entity ?? ($conf->entity ?? 1);
        $ttl = self::clampTtl($ttl);
        $uploadId = self::generateId();

        $stagingDir = self::getUserStagingDir($userId, $uploadId);
        if (!$stagingDir) {
            throw new \RuntimeException('Failed to create staging directory');
        }

        $safeName = self::sanitizeFilename($file['name'] ?? 'upload.bin');
        $destPath = $stagingDir . '/' . $safeName;

        if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
            // Cleanup partial directory before throwing.
            @rmdir($stagingDir);
            throw new \RuntimeException('Failed to move uploaded file to staging');
        }

        $size = filesize($destPath);
        $sha = hash_file('sha256', $destPath);
        $detectedMime = self::detectMime($destPath, $safeName);

        $metadata = [
            'upload_id' => $uploadId,
            'filename' => $safeName,
            'mime' => $detectedMime,
            'size' => $size,
            'sha256' => $sha,
            'user_id' => (int) $userId,
            'entity' => (int) $entity,
            'created' => time(),
            'expires' => time() + $ttl,
            'consumed' => 0,
        ];

        $metaPath = $stagingDir . '/meta.json';
        if (file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
            @unlink($destPath);
            @rmdir($stagingDir);
            throw new \RuntimeException('Failed to write upload metadata');
        }

        dol_syslog("SmartAuth SmartUpload::store - Staged upload $uploadId for user $userId: $safeName ($size bytes, $detectedMime)");

        // Probabilistic cleanup of expired entries.
        if (mt_rand(1, self::CLEANUP_PROBABILITY) === 1) {
            self::cleanup();
        }

        return [
            'upload_id' => $uploadId,
            'filename' => $safeName,
            'mime' => $detectedMime,
            'size' => $size,
            'sha256' => $sha,
        ];
    }

    /**
     * Look up a staged upload by id, scoped to the given user.
     *
     * @param string $uploadId
     * @param int    $userId
     * @return array|null  Same shape as store() result + 'filepath', or null
     */
    public static function get($uploadId, $userId)
    {
        global $conf;

        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $uploadId);
        if (strlen($uploadId) < 32) {
            return null;
        }

        $stagingDir = self::getUserStagingDir($userId, $uploadId, false);
        if (!$stagingDir || !is_dir($stagingDir)) {
            return null;
        }

        $metaPath = $stagingDir . '/meta.json';
        if (!is_file($metaPath)) {
            return null;
        }

        $metadata = json_decode(@file_get_contents($metaPath), true);
        if (!is_array($metadata)) {
            return null;
        }

        // Owner check: critical, the per-user directory layout already
        // makes cross-user reads impossible but defense-in-depth is cheap.
        if ((int) ($metadata['user_id'] ?? -1) !== (int) $userId) {
            dol_syslog("SmartAuth SmartUpload::get - Owner mismatch for $uploadId", LOG_WARNING);
            return null;
        }

        // Entity check.
        $currentEntity = $conf->entity ?? 1;
        if ((int) ($metadata['entity'] ?? 0) !== (int) $currentEntity) {
            dol_syslog("SmartAuth SmartUpload::get - Entity mismatch for $uploadId", LOG_WARNING);
            return null;
        }

        if (time() > (int) ($metadata['expires'] ?? 0)) {
            self::delete($uploadId, $userId);
            return null;
        }

        $filepath = $stagingDir . '/' . $metadata['filename'];
        if (!is_file($filepath)) {
            return null;
        }

        $metadata['filepath'] = $filepath;
        return $metadata;
    }

    /**
     * Delete a staged upload (idempotent).
     *
     * @param string $uploadId
     * @param int    $userId
     * @return bool
     */
    public static function delete($uploadId, $userId)
    {
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $uploadId);
        if (strlen($uploadId) < 32) {
            return false;
        }

        $stagingDir = self::getUserStagingDir($userId, $uploadId, false);
        if (!$stagingDir || !is_dir($stagingDir)) {
            return true;
        }

        // Wipe the whole upload subdir.
        $files = glob($stagingDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($stagingDir);
        return true;
    }

    /**
     * Sweep expired uploads across all users.
     *
     * @return int Number of uploads removed
     */
    public static function cleanup()
    {
        $base = self::getBaseStagingDir();
        if (!$base || !is_dir($base)) {
            return 0;
        }

        $now = time();
        $cleaned = 0;

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $userDir) {
            foreach (glob($userDir . '/*', GLOB_ONLYDIR) ?: [] as $uploadDir) {
                $metaPath = $uploadDir . '/meta.json';
                if (!is_file($metaPath)) {
                    continue;
                }
                $metadata = json_decode(@file_get_contents($metaPath), true);
                if (!is_array($metadata) || !isset($metadata['expires'])) {
                    continue;
                }
                if ($now > (int) $metadata['expires']) {
                    foreach (glob($uploadDir . '/*') ?: [] as $f) {
                        @unlink($f);
                    }
                    @rmdir($uploadDir);
                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0) {
            dol_syslog("SmartAuth SmartUpload::cleanup - Removed $cleaned expired uploads");
        }
        return $cleaned;
    }

    /**
     * Detect MIME from disk content. Prefers finfo when available, falls
     * back to Dolibarr's dol_mimetype on the filename.
     */
    private static function detectMime($path, $filename)
    {
        if (is_file($path) && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $path);
                @finfo_close($finfo);
                if (!empty($mime) && is_string($mime)) {
                    return strtolower($mime);
                }
            }
        }
        if (function_exists('dol_mimetype')) {
            $mime = dol_mimetype($filename);
            if (!empty($mime)) {
                return strtolower($mime);
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Resolve max upload size, with config override.
     */
    private static function resolveMaxBytes($options)
    {
        if (is_array($options) && isset($options['maxBytes'])) {
            return (int) $options['maxBytes'];
        }
        if (function_exists('getDolGlobalString')) {
            $configured = (int) getDolGlobalString('SMARTAUTH_UPLOAD_MAX_BYTES', '0');
            if ($configured > 0) {
                return $configured;
            }
        }
        return self::DEFAULT_MAX_BYTES;
    }

    /**
     * Resolve allowed MIME whitelist, with config override.
     */
    private static function resolveAllowedMime($options)
    {
        if (is_array($options) && !empty($options['allowedMime']) && is_array($options['allowedMime'])) {
            return array_map('strtolower', $options['allowedMime']);
        }
        if (function_exists('getDolGlobalString')) {
            $raw = (string) getDolGlobalString('SMARTAUTH_UPLOAD_ALLOWED_MIME', '');
            if ($raw !== '') {
                $list = array_filter(array_map('trim', explode(',', strtolower($raw))));
                if (!empty($list)) {
                    return $list;
                }
            }
        }
        return self::DEFAULT_ALLOWED_MIME;
    }

    /**
     * Clamp a TTL into the supported [60s, MAX_TTL] range.
     */
    private static function clampTtl($ttl)
    {
        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }
        return min(max((int) $ttl, 60), self::MAX_TTL);
    }

    /**
     * Server-executable / handler-bound extensions that must NEVER survive
     * an upload, regardless of the configured MIME whitelist (H-18 of
     * TODO-SECURITY-01). Each occurrence in the filename - including in
     * multi-extension shapes like "evil.php.jpg" - is rewritten to ".bin"
     * so a misconfigured webserver cannot execute the staged file.
     */
    private static $executableExtensionDenylist = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'pl', 'cgi', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so',
        'jsp', 'jspx', 'asp', 'aspx', 'cer',
        'htaccess', 'htpasswd',
        // Vector / markup formats that can host inline scripts when served
        // from the same origin as the API.
        'svg', 'svgz', 'htm', 'html', 'xhtml',
    ];

    /**
     * Sanitize a filename to a safe ASCII-ish form.
     */
    private static function sanitizeFilename($name)
    {
        if (function_exists('dol_sanitizeFileName')) {
            $clean = dol_sanitizeFileName((string) $name);
        } else {
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $name);
        }
        if ($clean === '' || $clean === null) {
            $clean = 'upload.bin';
        }

        $clean = self::neutraliseExecutableExtensions($clean);

        // Reject hidden-file shapes ("evil" -> stays, ".htaccess" -> "htaccess.bin").
        if (isset($clean[0]) && $clean[0] === '.') {
            $clean = ltrim($clean, '.') . '.bin';
        }

        // Hard cap to keep paths reasonable.
        if (strlen($clean) > 200) {
            $ext = pathinfo($clean, PATHINFO_EXTENSION);
            $base = pathinfo($clean, PATHINFO_FILENAME);
            $clean = substr($base, 0, 180) . ($ext !== '' ? '.' . $ext : '');
        }
        return $clean;
    }

    /**
     * Walk every '.'-delimited segment of the filename and rewrite any
     * server-executable extension to ".bin" (H-18).
     */
    private static function neutraliseExecutableExtensions(string $name): string
    {
        if (strpos($name, '.') === false) {
            return $name;
        }

        $parts = explode('.', $name);
        $base = array_shift($parts);
        $changed = false;
        foreach ($parts as $i => $segment) {
            $segLower = strtolower($segment);
            if (in_array($segLower, self::$executableExtensionDenylist, true)) {
                $parts[$i] = 'bin';
                $changed = true;
            }
        }
        if ($changed) {
            dol_syslog('SmartAuth SmartUpload: neutralised executable extension in: ' . substr($name, 0, 200), LOG_WARNING);
        }
        return $base . (count($parts) > 0 ? '.' . implode('.', $parts) : '');
    }

    /**
     * Translate a PHP UPLOAD_ERR_* code into a human string.
     */
    private static function translateUploadError($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds server limit';
            case UPLOAD_ERR_PARTIAL:
                return 'File only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server has no temp directory';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server failed to write file';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the upload';
            default:
                return 'Unknown upload error (' . $code . ')';
        }
    }

    /**
     * Build a unique upload id (64 hex chars).
     */
    private static function generateId()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Resolve the base staging directory and create it lazily.
     */
    private static function getBaseStagingDir()
    {
        global $conf;

        $base = !empty($conf->smartauth->dir_output)
            ? $conf->smartauth->dir_output
            : sys_get_temp_dir();

        $dir = $base . '/upload-staging';

        if (!is_dir($dir)) {
            $created = false;
            if (function_exists('dol_mkdir')) {
                $created = dol_mkdir($dir);
            } else {
                $created = @mkdir($dir, 0700, true);
            }
            if (!$created && !is_dir($dir)) {
                dol_syslog("SmartAuth SmartUpload::getBaseStagingDir - Failed to create $dir", LOG_ERR);
                return null;
            }
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            $index = $dir . '/index.html';
            if (!file_exists($index)) {
                @file_put_contents($index, '');
            }
        }

        return $dir;
    }

    /**
     * Resolve (and optionally create) the per-user staging subdir.
     *
     * @param int    $userId
     * @param string $uploadId
     * @param bool   $create   If true, create the dir on the fly
     * @return string|null     Full path or null on failure
     */
    private static function getUserStagingDir($userId, $uploadId, $create = true)
    {
        $base = self::getBaseStagingDir();
        if (!$base) {
            return null;
        }
        $dir = $base . '/' . (int) $userId . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);

        if ($create && !is_dir($dir)) {
            $created = false;
            if (function_exists('dol_mkdir')) {
                $created = dol_mkdir($dir);
            } else {
                $created = @mkdir($dir, 0700, true);
            }
            if (!$created && !is_dir($dir)) {
                return null;
            }
        }

        return $dir;
    }
}
