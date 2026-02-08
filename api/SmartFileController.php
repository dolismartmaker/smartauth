<?php

/**
 * SmartFileController.php
 *
 * Controller for secure file downloads via ECM hash.
 * Uses Dolibarr's dol_check_secure_access_document for permission checks.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
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

require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

use EcmFiles;

class SmartFileController
{
    /**
     * Download a file by its ECM share hash (base64 JSON response)
     *
     * @param array $payload Contains 'hash', 'user', 'entity' from router
     * @return array [response_data, http_status_code]
     */
    public function download($payload = null)
    {
        dol_syslog("smartauth::SmartFileController::download");

        // Load and validate file
        $fileInfo = $this->_loadAndValidateFile($payload);

        // If error, return it
        if (isset($fileInfo['error'])) {
            return [$fileInfo, $fileInfo['status']];
        }

        // Limit file size for base64 encoding (50MB max)
        $maxsize = 50 * 1024 * 1024;
        if ($fileInfo['filesize'] > $maxsize) {
            dol_syslog("smartauth::SmartFileController::download - File too large: {$fileInfo['filesize']} bytes", LOG_WARNING);
            return [['error' => 'File too large for base64 download, use binary mode'], 413];
        }

        $content = file_get_contents($fileInfo['fullpath_osencoded']);
        if ($content === false) {
            dol_syslog("smartauth::SmartFileController::download - Failed to read file: {$fileInfo['fullpath']}", LOG_ERR);
            return [['error' => 'Failed to read file'], 500];
        }

        dol_syslog("smartauth::SmartFileController::download - Success: {$fileInfo['filename']} ({$fileInfo['filesize']} bytes) for user {$fileInfo['user_id']}");

        return [[
            'filename' => $fileInfo['filename'],
            'content-type' => $fileInfo['mimetype'],
            'filesize' => $fileInfo['filesize'],
            'content' => base64_encode($content),
            'encoding' => 'base64'
        ], 200];
    }

    /**
     * Download a file by its ECM share hash (binary stream)
     *
     * Streams the file directly to the client with appropriate headers.
     * Does not return - exits after sending file.
     *
     * @param array $payload Contains 'hash', 'user', 'entity' from router
     * @return array [response_data, http_status_code] Only on error
     */
    public function downloadBinary($payload = null)
    {
        global $db;

        dol_syslog("smartauth::SmartFileController::downloadBinary");

        // Load and validate file
        $fileInfo = $this->_loadAndValidateFile($payload);

        // If error, return it as JSON
        if (isset($fileInfo['error'])) {
            return [$fileInfo, $fileInfo['status']];
        }

        dol_syslog("smartauth::SmartFileController::downloadBinary - Streaming: {$fileInfo['filename']} ({$fileInfo['filesize']} bytes) for user {$fileInfo['user_id']}");

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

        // Stream file with low memory usage
        readfileLowMemory($fileInfo['fullpath_osencoded']);

        exit;
    }

    /**
     * Load and validate file from ECM hash
     *
     * Security checks:
     * - Hash is non-predictable (no sequential ID enumeration)
     * - User permissions verified via dol_check_secure_access_document()
     * - Entity isolation enforced
     * - Path traversal protection
     *
     * @param array $payload Contains 'hash', 'user', 'entity' from router
     * @return array File info on success, or ['error' => message, 'status' => code] on failure
     */
    private function _loadAndValidateFile($payload)
    {
        global $db, $conf;

        // Validate hash parameter
        $hash = $payload['hash'] ?? '';
        if (empty($hash)) {
            dol_syslog("smartauth::SmartFileController - Missing hash parameter", LOG_WARNING);
            return ['error' => 'Missing file hash', 'status' => 400];
        }

        // Sanitize hash (alphanumeric only)
        $hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
        if (strlen($hash) < 8) {
            dol_syslog("smartauth::SmartFileController - Invalid hash format", LOG_WARNING);
            return ['error' => 'Invalid file hash', 'status' => 400];
        }

        // Get authenticated user
        $user = $payload['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            dol_syslog("smartauth::SmartFileController - No authenticated user", LOG_WARNING);
            return ['error' => 'Authentication required', 'status' => 401];
        }

        // Fetch ECM file by share hash
        /** @var \DoliDB $db */
        $ecmfile = new EcmFiles($db);
        $result = $ecmfile->fetch(0, '', '', '', $hash);

        if ($result <= 0) {
            dol_syslog("smartauth::SmartFileController - File not found for hash: $hash", LOG_WARNING);
            return ['error' => 'File not found', 'status' => 404];
        }

        // Entity check
        $entity = (int) ($payload['entity'] ?? $conf->entity);
        if ($ecmfile->entity != $entity && $ecmfile->entity != 0) {
            dol_syslog("smartauth::SmartFileController - Entity mismatch: file={$ecmfile->entity}, user=$entity", LOG_WARNING);
            return ['error' => 'Access denied', 'status' => 403];
        }

        // Extract modulepart from filepath (e.g., "facture/FA2024-001" -> "facture")
        $filepath = $ecmfile->filepath;
        $tmp = explode('/', $filepath, 2);

        // Handle multicompany: if first part is numeric, it's the entity subdir
        if (is_numeric($tmp[0])) {
            $tmp = explode('/', $tmp[1] ?? '', 2);
        }

        $modulepart = $tmp[0];
        $original_file = (($tmp[1] ?? '') ? $tmp[1] . '/' : '') . $ecmfile->filename;

        if (empty($modulepart)) {
            dol_syslog("smartauth::SmartFileController - Cannot determine modulepart from filepath: $filepath", LOG_ERR);
            return ['error' => 'Invalid file path', 'status' => 500];
        }

        // Check access permissions using Dolibarr's security function
        $check_access = dol_check_secure_access_document($modulepart, $original_file, $entity, $user, '', 'read');
        $accessallowed = $check_access['accessallowed'];
        $fullpath = $check_access['original_file'];

        if (!$accessallowed) {
            dol_syslog("smartauth::SmartFileController - Access denied for user {$user->id} on modulepart=$modulepart, file=$original_file", LOG_WARNING);
            return ['error' => 'Access denied', 'status' => 403];
        }

        // Path traversal protection
        if (preg_match('/\.\./', $fullpath) || preg_match('/[<>|]/', $fullpath)) {
            dol_syslog("smartauth::SmartFileController - Path traversal attempt detected: $fullpath", LOG_WARNING);
            return ['error' => 'Invalid file path', 'status' => 400];
        }

        // Encode path for filesystem
        $fullpath_osencoded = dol_osencode($fullpath);

        // Check file exists
        if (!file_exists($fullpath_osencoded)) {
            dol_syslog("smartauth::SmartFileController - File does not exist on disk: $fullpath", LOG_WARNING);
            return ['error' => 'File not found on disk', 'status' => 404];
        }

        // Get file info
        $filename = basename($fullpath);
        $filename = preg_replace('/\.noexe$/i', '', $filename);
        $mimetype = dol_mimetype($filename);
        $filesize = filesize($fullpath_osencoded);

        return [
            'filename' => $filename,
            'mimetype' => $mimetype,
            'filesize' => $filesize,
            'fullpath' => $fullpath,
            'fullpath_osencoded' => $fullpath_osencoded,
            'user_id' => $user->id
        ];
    }
}
