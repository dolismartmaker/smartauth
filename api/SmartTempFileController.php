<?php

/**
 * SmartTempFileController.php
 *
 * Controller for temporary file downloads.
 * Uses SmartTempFile for storage management.
 *
 * Routes:
 *   GET /temp-file/{token}        - Download as base64 JSON
 *   GET /temp-file/{token}/binary - Download as binary stream
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class SmartTempFileController
{
    /**
     * Download a temporary file (base64 JSON response)
     *
     * @param array $payload Contains 'token', 'user' from router
     * @return array [response_data, http_status_code]
     */
    public function download($payload = null)
    {
        dol_syslog("[SmartAuth] SmartTempFileController::download");

        // Get user ID for access control
        $user = $payload['user'] ?? null;
        $userId = is_object($user) ? $user->id : null;

        // Get token
        $token = $payload['token'] ?? '';
        if (empty($token)) {
            return [['error' => 'Missing token'], 400];
        }

        // Fetch file info
        $fileInfo = SmartTempFile::get($token, $userId);
        if (!$fileInfo) {
            dol_syslog("[SmartAuth] SmartTempFileController::download - File not found or expired: $token", LOG_WARNING);
            return [['error' => 'File not found or expired'], 404];
        }

        // Limit file size for base64 encoding (50MB max)
        $maxsize = 50 * 1024 * 1024;
        if ($fileInfo['filesize'] > $maxsize) {
            dol_syslog("[SmartAuth] SmartTempFileController::download - File too large: {$fileInfo['filesize']} bytes", LOG_WARNING);
            return [['error' => 'File too large for base64 download, use binary mode'], 413];
        }

        // Read content
        $content = file_get_contents($fileInfo['filepath']);
        if ($content === false) {
            dol_syslog("[SmartAuth] SmartTempFileController::download - Failed to read file", LOG_ERR);
            return [['error' => 'Failed to read file'], 500];
        }

        dol_syslog("[SmartAuth] SmartTempFileController::download - Success: {$fileInfo['filename']} ({$fileInfo['filesize']} bytes)");

        return [[
            'filename' => $fileInfo['filename'],
            'content-type' => $fileInfo['mimetype'],
            'filesize' => $fileInfo['filesize'],
            'content' => base64_encode($content),
            'encoding' => 'base64'
        ], 200];
    }

    /**
     * Download a temporary file (binary stream)
     *
     * Streams the file directly to the client with appropriate headers.
     * Does not return - exits after sending file.
     *
     * @param array $payload Contains 'token', 'user' from router
     * @return array [response_data, http_status_code] Only on error
     */
    public function downloadBinary($payload = null)
    {
        global $db;

        dol_syslog("[SmartAuth] SmartTempFileController::downloadBinary");

        // Get user ID for access control
        $user = $payload['user'] ?? null;
        $userId = is_object($user) ? $user->id : null;

        // Get token
        $token = $payload['token'] ?? '';
        if (empty($token)) {
            return [['error' => 'Missing token'], 400];
        }

        // Fetch file info
        $fileInfo = SmartTempFile::get($token, $userId);
        if (!$fileInfo) {
            dol_syslog("[SmartAuth] SmartTempFileController::downloadBinary - File not found or expired: $token", LOG_WARNING);
            return [['error' => 'File not found or expired'], 404];
        }

        dol_syslog("[SmartAuth] SmartTempFileController::downloadBinary - Streaming: {$fileInfo['filename']} ({$fileInfo['filesize']} bytes)");

        // Close database connection before streaming
        if (is_object($db)) {
            $db->close();
        }

        // Send headers
        header('Content-Type: ' . $fileInfo['mimetype']);
        header('Content-Disposition: attachment; filename="' . $fileInfo['filename'] . '"');
        header('Content-Length: ' . $fileInfo['filesize']);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Stream file
        readfile($fileInfo['filepath']);

        exit;
    }

    /**
     * Delete a temporary file
     *
     * @param array $payload Contains 'token', 'user' from router
     * @return array [response_data, http_status_code]
     */
    public function delete($payload = null)
    {
        dol_syslog("[SmartAuth] SmartTempFileController::delete");

        // Get user ID for access control
        $user = $payload['user'] ?? null;
        $userId = is_object($user) ? $user->id : null;

        // Get token
        $token = $payload['token'] ?? '';
        if (empty($token)) {
            return [['error' => 'Missing token'], 400];
        }

        // Check file exists and user has access
        $fileInfo = SmartTempFile::get($token, $userId);
        if (!$fileInfo) {
            return [['error' => 'File not found or access denied'], 404];
        }

        // Delete
        if (SmartTempFile::delete($token)) {
            dol_syslog("[SmartAuth] SmartTempFileController::delete - Deleted: $token");
            return [['message' => 'File deleted'], 200];
        }

        return [['error' => 'Failed to delete file'], 500];
    }
}
