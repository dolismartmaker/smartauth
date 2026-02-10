<?php

/**
 * SmartTempFile.php
 *
 * Utility class for storing and retrieving temporary files.
 * Files are stored on disk with metadata and auto-cleaned after expiry.
 *
 * Usage:
 *   // Store a file (returns token)
 *   $token = SmartTempFile::store($binaryContent, 'export.xlsx', 3600);
 *
 *   // Retrieve file info
 *   $info = SmartTempFile::get($token);
 *   // Returns: ['filename' => ..., 'mimetype' => ..., 'filepath' => ..., 'filesize' => ...]
 *
 *   // Delete a file
 *   SmartTempFile::delete($token);
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class SmartTempFile
{
    /**
     * Default TTL in seconds (1 hour)
     */
    const DEFAULT_TTL = 3600;

    /**
     * Maximum TTL in seconds (24 hours)
     */
    const MAX_TTL = 86400;

    /**
     * Cleanup probability (1 in N requests triggers cleanup)
     */
    const CLEANUP_PROBABILITY = 10;

    /**
     * Store a temporary file
     *
     * @param string $content Binary content of the file
     * @param string $filename Original filename (for download)
     * @param int $ttl Time to live in seconds (default: 3600, max: 86400)
     * @param int|null $userId Optional user ID for access control
     * @return string Token to retrieve the file
     */
    public static function store($content, $filename, $ttl = null, $userId = null)
    {
        global $conf, $user;

        // Validate TTL
        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }
        $ttl = min(max((int) $ttl, 60), self::MAX_TTL);

        // Use current user if not specified
        if ($userId === null && is_object($user)) {
            $userId = $user->id;
        }

        // Generate unique token
        $token = self::generateToken();

        // Get storage directory
        $storageDir = self::getStorageDir();
        if (!$storageDir) {
            throw new \Exception('Cannot create temp file storage directory');
        }

        // File paths
        $dataFile = $storageDir . '/' . $token . '.dat';
        $metaFile = $storageDir . '/' . $token . '.json';

        // Write content
        if (file_put_contents($dataFile, $content) === false) {
            throw new \Exception('Failed to write temp file');
        }

        // Determine mimetype
        $mimetype = dol_mimetype($filename);

        // Write metadata
        $metadata = [
            'filename' => $filename,
            'mimetype' => $mimetype,
            'filesize' => strlen($content),
            'created' => time(),
            'expires' => time() + $ttl,
            'user_id' => $userId,
            'entity' => $conf->entity ?? 1,
        ];

        if (file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
            unlink($dataFile);
            throw new \Exception('Failed to write temp file metadata');
        }

        dol_syslog("SmartTempFile::store - Created temp file: $filename (token: $token, ttl: {$ttl}s, user: $userId)");

        // Probabilistic cleanup of expired files
        if (mt_rand(1, self::CLEANUP_PROBABILITY) === 1) {
            self::cleanup();
        }

        return $token;
    }

    /**
     * Get temporary file info
     *
     * @param string $token File token
     * @param int|null $userId Optional user ID for access control
     * @return array|null File info or null if not found/expired/unauthorized
     */
    public static function get($token, $userId = null)
    {
        global $conf, $user;

        // Sanitize token
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        if (strlen($token) < 32) {
            return null;
        }

        $storageDir = self::getStorageDir();
        if (!$storageDir) {
            return null;
        }

        $dataFile = $storageDir . '/' . $token . '.dat';
        $metaFile = $storageDir . '/' . $token . '.json';

        // Check files exist
        if (!file_exists($dataFile) || !file_exists($metaFile)) {
            return null;
        }

        // Read metadata
        $metadata = json_decode(file_get_contents($metaFile), true);
        if (!$metadata) {
            return null;
        }

        // Check expiry
        if (time() > $metadata['expires']) {
            // Clean up expired file
            self::delete($token);
            return null;
        }

        // Check user access if user_id was set
        if (!empty($metadata['user_id'])) {
            $checkUserId = $userId ?? ($user->id ?? null);
            if ($checkUserId !== null && (int) $metadata['user_id'] !== (int) $checkUserId) {
                dol_syslog("SmartTempFile::get - Access denied: user $checkUserId tried to access file owned by {$metadata['user_id']}", LOG_WARNING);
                return null;
            }
        }

        // Check entity
        $currentEntity = $conf->entity ?? 1;
        if (!empty($metadata['entity']) && (int) $metadata['entity'] !== (int) $currentEntity) {
            dol_syslog("SmartTempFile::get - Entity mismatch: file entity {$metadata['entity']}, current $currentEntity", LOG_WARNING);
            return null;
        }

        return [
            'filename' => $metadata['filename'],
            'mimetype' => $metadata['mimetype'],
            'filesize' => $metadata['filesize'],
            'filepath' => $dataFile,
            'expires' => $metadata['expires'],
            'user_id' => $metadata['user_id'],
        ];
    }

    /**
     * Delete a temporary file
     *
     * @param string $token File token
     * @return bool Success
     */
    public static function delete($token)
    {
        // Sanitize token
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        if (strlen($token) < 32) {
            return false;
        }

        $storageDir = self::getStorageDir();
        if (!$storageDir) {
            return false;
        }

        $dataFile = $storageDir . '/' . $token . '.dat';
        $metaFile = $storageDir . '/' . $token . '.json';

        $success = true;
        if (file_exists($dataFile)) {
            $success = unlink($dataFile) && $success;
        }
        if (file_exists($metaFile)) {
            $success = unlink($metaFile) && $success;
        }

        return $success;
    }

    /**
     * Clean up expired files
     *
     * @return int Number of files cleaned
     */
    public static function cleanup()
    {
        $storageDir = self::getStorageDir();
        if (!$storageDir || !is_dir($storageDir)) {
            return 0;
        }

        $cleaned = 0;
        $now = time();

        // Find all .json metadata files
        $files = glob($storageDir . '/*.json');
        if (!$files) {
            return 0;
        }

        foreach ($files as $metaFile) {
            $metadata = json_decode(file_get_contents($metaFile), true);
            if (!$metadata || !isset($metadata['expires'])) {
                continue;
            }

            if ($now > $metadata['expires']) {
                $token = basename($metaFile, '.json');
                self::delete($token);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            dol_syslog("SmartTempFile::cleanup - Cleaned $cleaned expired files");
        }

        return $cleaned;
    }

    /**
     * Generate a unique token
     *
     * @return string 64-character hex token
     */
    private static function generateToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get storage directory, creating it if needed
     *
     * @return string|null Directory path or null on failure
     */
    private static function getStorageDir()
    {
        global $conf;

        // Use smartauth output dir if available, otherwise use system temp
        if (!empty($conf->smartauth->dir_output)) {
            $baseDir = $conf->smartauth->dir_output;
        } else {
            $baseDir = sys_get_temp_dir();
        }

        $storageDir = $baseDir . '/tempfiles';

        if (!is_dir($storageDir)) {
            if (!dol_mkdir($storageDir)) {
                dol_syslog("SmartTempFile::getStorageDir - Failed to create directory: $storageDir", LOG_ERR);
                return null;
            }

            // Create .htaccess to prevent direct access
            $htaccess = $storageDir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }

            // Create index.html as extra protection
            $index = $storageDir . '/index.html';
            if (!file_exists($index)) {
                file_put_contents($index, '');
            }
        }

        return $storageDir;
    }
}
