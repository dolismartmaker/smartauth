<?php

/**
 * ObjectDocumentController.php
 *
 * Controller for listing and downloading documents attached to Dolibarr objects.
 * Supports product datasheets, photos, and other files stored in Dolibarr's
 * document directory structure.
 *
 * Used by offline sync to pull document metadata and download files.
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';

class ObjectDocumentController
{
    /**
     * Mapping of object types to their document configuration.
     *
     * IMPORTANT: This is NOT a closed list. External modules can register additional
     * object types using the registerObjectType() method. This is typically done
     * via a Dolibarr hook (e.g., smartmaker_document_types) at module initialization.
     *
     * Example in your module's hooks:
     * ```php
     * public function smartmakerDocumentTypes($parameters, &$object, &$action, $hookmanager)
     * {
     *     ObjectDocumentController::registerObjectType('myobject', [
     *         'class' => 'MyObject',
     *         'file' => '/mymodule/class/myobject.class.php',
     *         'module' => 'mymodule',
     *         'modulepart' => 'mymodule',
     *         'subdir_method' => 'getMyObjectSubdir',
     *     ]);
     *     return 0;
     * }
     * ```
     *
     * @see registerObjectType()
     * @var array
     */
    private static $objectTypeConfig = [
        'product' => [
            'class' => 'Product',
            'file' => '/product/class/product.class.php',
            'module' => 'product',
            'modulepart' => 'produit',
            'subdir_method' => 'getProductSubdir',
        ],
        'thirdparty' => [
            'class' => 'Societe',
            'file' => '/societe/class/societe.class.php',
            'module' => 'societe',
            'modulepart' => 'societe',
            'subdir_method' => 'getThirdpartySubdir',
        ],
        'project' => [
            'class' => 'Project',
            'file' => '/projet/class/project.class.php',
            'module' => 'projet',
            'modulepart' => 'projet',
            'subdir_method' => 'getProjectSubdir',
        ],
        'intervention' => [
            'class' => 'Fichinter',
            'file' => '/fichinter/class/fichinter.class.php',
            'module' => 'ficheinter',
            'modulepart' => 'ficheinter',
            'subdir_method' => 'getInterventionSubdir',
        ],
        'category' => [
            'class' => 'Categorie',
            'file' => '/categories/class/categorie.class.php',
            'module' => 'categorie',
            'modulepart' => 'categorie',
            'subdir_method' => 'getCategorySubdir',
        ],
    ];

    /**
     * @api {get} /object/{type}/{id}/documents List documents for an object
     * @apiName ListObjectDocuments
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Lists all documents attached to a Dolibarr object.
     * Returns metadata only (no file content).
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiParam {String} type Object type (product, thirdparty, project, intervention)
     * @apiParam {Number} id Object ID (rowid)
     *
     * @apiQuery {String} [since] ISO timestamp - only return files modified after this date
     *
     * @apiSuccess {Object[]} documents List of documents
     * @apiSuccess {Number} documents.id Document ID (hash of path)
     * @apiSuccess {Number} documents.object_id Parent object ID
     * @apiSuccess {String} documents.filename File name
     * @apiSuccess {String} documents.relative_path Path relative to object directory
     * @apiSuccess {String} documents.mime_type MIME type
     * @apiSuccess {Number} documents.size File size in bytes
     * @apiSuccess {String} documents.updated_at Last modification date (ISO)
     * @apiSuccess {String} documents.type image|pdf|other
     *
     * @apiSuccessExample {json} Success-Response:
     * HTTP/1.1 200 OK
     * {
     *     "documents": [
     *         {
     *             "id": "a1b2c3d4",
     *             "object_id": 15,
     *             "filename": "notice_technique.pdf",
     *             "relative_path": "notice_technique.pdf",
     *             "mime_type": "application/pdf",
     *             "size": 245000,
     *             "updated_at": "2026-02-18T10:30:00+00:00",
     *             "type": "pdf"
     *         }
     *     ],
     *     "server_time": "2026-02-18T14:00:00+00:00"
     * }
     */
    public function index($payload)
    {
        global $conf;

        dol_syslog("smartauth::ObjectDocumentController::index");

        // Validate parameters
        $validation = $this->validateObjectParams($payload);
        if (isset($validation['error'])) {
            return [$validation, $validation['status']];
        }

        $type = $validation['type'];
        $objectId = $validation['object_id'];
        $user = $validation['user'];
        $config = $validation['config'];
        $object = $validation['object'];

        // Get document directory
        $docDir = $this->getObjectDocumentDir($config, $object, $conf);
        if (!$docDir || !is_dir($docDir)) {
            dol_syslog("smartauth::ObjectDocumentController::index - No document directory: $docDir");
            return [['documents' => [], 'server_time' => date('c')], 200];
        }

        // Optional filter by modification date
        $since = null;
        if (!empty($payload['since'])) {
            $since = strtotime($payload['since']);
        }

        // List files recursively
        $files = dol_dir_list($docDir, 'files', 1, '', array('(\.meta|_preview.*\.png)$', '^\.'), 'date', SORT_DESC, 1);

        // Load existing ecm_files entries for this object
        $ecmIndexed = $this->loadEcmFilesForObject($object);

        $documents = [];
        foreach ($files as $file) {
            // Skip if filtered by date
            if ($since && $file['date'] <= $since) {
                continue;
            }

            // Build relative path from object directory
            $relativePath = str_replace($docDir . '/', '', $file['fullname']);

            // Generate stable ID from relative path
            $docId = substr(md5($type . '_' . $objectId . '_' . $relativePath), 0, 8);

            $mimeType = dol_mimetype($file['name']);

            // Find or create ecm_files entry
            $ecmData = $this->ensureEcmEntry(
                $file,
                $relativePath,
                $docDir,
                $object,
                $user,
                $ecmIndexed
            );

            $documents[] = [
                'id' => $docId,
                'ecm_id' => $ecmData['ecm_id'],
                'share' => $ecmData['share'],
                'object_id' => $objectId,
                'filename' => $file['name'],
                'relative_path' => $relativePath,
                'mime_type' => $mimeType,
                'size' => (int) $file['size'],
                'updated_at' => date('c', $file['date']),
                'type' => $this->getDocumentType($mimeType),
            ];
        }

        dol_syslog("smartauth::ObjectDocumentController::index - Found " . count($documents) . " documents for $type/$objectId");

        return [[
            'documents' => $documents,
            'server_time' => date('c'),
        ], 200];
    }

    /**
     * @api {get} /object/{type}/{id}/document/{path} Download a document (legacy path mode)
     * @api {get} /object/{type}/{id}/document?q={share} Download a document (share hash mode)
     * @apiName DownloadObjectDocument
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Downloads a document attached to a Dolibarr object.
     * Returns base64-encoded content.
     * Two modes:
     * - Legacy: path in URL segment (for simple filenames without subdirectories)
     * - Share hash: ?q=share_hash (recommended, avoids URL encoding issues)
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiParam {String} type Object type (product, thirdparty, project, intervention, category)
     * @apiParam {Number} id Object ID (rowid)
     * @apiParam {String} [path] Relative path to the document (URL segment, legacy mode)
     * @apiQuery {String} [q] Share hash from ecm_files (recommended mode)
     *
     * @apiSuccess {String} filename File name
     * @apiSuccess {String} content-type MIME type
     * @apiSuccess {Number} filesize File size in bytes
     * @apiSuccess {String} content Base64-encoded file content
     * @apiSuccess {String} encoding Always "base64"
     */
    public function download($payload)
    {
        dol_syslog("smartauth::ObjectDocumentController::download");

        // Resolve file path (share hash or legacy path)
        $resolved = $this->resolveDocumentPath($payload, 'download');
        if (isset($resolved['error'])) {
            return [$resolved, $resolved['status']];
        }

        $fullPathEncoded = $resolved['full_path_encoded'];
        $filename = $resolved['filename'];

        // Check it's a file, not a directory
        if (!is_file($fullPathEncoded)) {
            return [['error' => 'Not a file'], 400];
        }

        $mimeType = dol_mimetype($filename);
        $filesize = filesize($fullPathEncoded);

        // Limit file size for base64 encoding (50MB max)
        $maxsize = 50 * 1024 * 1024;
        if ($filesize > $maxsize) {
            dol_syslog("smartauth::ObjectDocumentController::download - File too large: $filesize bytes", LOG_WARNING);
            return [['error' => 'File too large for base64 download, use binary mode'], 413];
        }

        $content = file_get_contents($fullPathEncoded);
        if ($content === false) {
            dol_syslog("smartauth::ObjectDocumentController::download - Failed to read file", LOG_ERR);
            return [['error' => 'Failed to read file'], 500];
        }

        dol_syslog("smartauth::ObjectDocumentController::download - Success: $filename ($filesize bytes)");

        return [[
            'filename' => $filename,
            'content-type' => $mimeType,
            'filesize' => $filesize,
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ], 200];
    }

    /**
     * @api {get} /object/{type}/{id}/document/{path}/binary Download a document binary (legacy)
     * @api {get} /object/{type}/{id}/document/binary?q={share} Download a document binary (share hash)
     * @apiName DownloadObjectDocumentBinary
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Downloads a document as binary stream.
     * More efficient for large files.
     * Supports both legacy path mode and share hash mode (see download endpoint).
     */
    public function downloadBinary($payload)
    {
        global $db;

        dol_syslog("smartauth::ObjectDocumentController::downloadBinary");

        // Resolve file path (share hash or legacy path)
        $resolved = $this->resolveDocumentPath($payload, 'downloadBinary');
        if (isset($resolved['error'])) {
            return [$resolved, $resolved['status']];
        }

        $fullPathEncoded = $resolved['full_path_encoded'];
        $filename = $resolved['filename'];

        $mimeType = dol_mimetype($filename);
        $filesize = filesize($fullPathEncoded);

        dol_syslog("smartauth::ObjectDocumentController::downloadBinary - Streaming: $filename ($filesize bytes)");

        // Close database connection before streaming
        if (is_object($db)) {
            $db->close();
        }

        // Send headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Stream file with low memory usage
        readfileLowMemory($fullPathEncoded);

        exit;
    }

    /**
     * Resolve a document file path from payload.
     *
     * Supports two modes:
     * 1. Share hash mode: ?q=<share_hash> - resolves via ecm_files table
     * 2. Legacy path mode: {path} URL segment - resolves via object document directory
     *
     * @param array $payload Request payload
     * @param string $caller Calling method name (for logs)
     * @return array ['full_path_encoded' => string, 'filename' => string] or ['error' => string, 'status' => int]
     */
    private function resolveDocumentPath($payload, $caller)
    {
        global $conf;

        // Mode 1: Share hash via ?q= query parameter
        $shareHash = $payload['q'] ?? '';
        if (!empty($shareHash)) {
            $resolved = $this->resolveShareHash($shareHash);
            if ($resolved === null) {
                dol_syslog("smartauth::ObjectDocumentController::$caller - Share hash not found: $shareHash", LOG_WARNING);
                return ['error' => 'Document not found', 'status' => 404];
            }

            $fullPath = DOL_DATA_ROOT . '/' . $resolved['filepath'] . '/' . $resolved['filename'];
            $fullPathEncoded = dol_osencode($fullPath);

            if (!file_exists($fullPathEncoded)) {
                dol_syslog("smartauth::ObjectDocumentController::$caller - File from ecm not found on disk: $fullPath", LOG_WARNING);
                return ['error' => 'File not found', 'status' => 404];
            }

            $filename = $resolved['filename'];
            $filename = preg_replace('/\.noexe$/i', '', $filename);

            return [
                'full_path_encoded' => $fullPathEncoded,
                'filename' => $filename,
            ];
        }

        // Mode 2: Legacy path from URL segment
        $validation = $this->validateObjectParams($payload);
        if (isset($validation['error'])) {
            return $validation;
        }

        $config = $validation['config'];
        $object = $validation['object'];

        $relativePath = $payload['path'] ?? '';
        if (empty($relativePath)) {
            return ['error' => 'Missing path or q parameter', 'status' => 400];
        }

        $relativePath = urldecode($relativePath);

        // Security: prevent path traversal
        if (preg_match('/\.\./', $relativePath) || preg_match('/[<>|]/', $relativePath)) {
            dol_syslog("smartauth::ObjectDocumentController::$caller - Path traversal attempt: $relativePath", LOG_WARNING);
            return ['error' => 'Invalid path', 'status' => 400];
        }

        $docDir = $this->getObjectDocumentDir($config, $object, $conf);
        $fullPath = $docDir . '/' . $relativePath;
        $fullPathEncoded = dol_osencode($fullPath);

        if (!file_exists($fullPathEncoded)) {
            dol_syslog("smartauth::ObjectDocumentController::$caller - File not found: $fullPath", LOG_WARNING);
            return ['error' => 'File not found', 'status' => 404];
        }

        $filename = basename($fullPath);
        $filename = preg_replace('/\.noexe$/i', '', $filename);

        return [
            'full_path_encoded' => $fullPathEncoded,
            'filename' => $filename,
        ];
    }

    /**
     * Validate object type and ID, load the object, check permissions
     *
     * @param array $payload Request payload
     * @return array Validated data or error
     */
    private function validateObjectParams($payload)
    {
        global $db;

        // Get authenticated user
        $user = $payload['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            return ['error' => 'Authentication required', 'status' => 401];
        }

        // Validate object type
        $type = InputSanitizer::sanitizeAlphanumeric($payload['type'] ?? '', 32);
        if (empty($type) || !isset(self::$objectTypeConfig[$type])) {
            return ['error' => 'Invalid object type. Supported: ' . implode(', ', array_keys(self::$objectTypeConfig)), 'status' => 400];
        }

        $config = self::$objectTypeConfig[$type];

        // Validate object ID
        $objectId = (int) ($payload['id'] ?? 0);
        if ($objectId <= 0) {
            return ['error' => 'Invalid object ID', 'status' => 400];
        }

        // Check module is enabled
        if (!isModEnabled($config['module'])) {
            return ['error' => 'Module not enabled: ' . $config['module'], 'status' => 403];
        }

        // Check user has read permission
        if (!$user->hasRight($config['module'], 'read') && !$user->hasRight($config['module'], 'lire')) {
            dol_syslog("smartauth::ObjectDocumentController - Access denied for user {$user->id} on module {$config['module']}", LOG_WARNING);
            return ['error' => 'Access denied', 'status' => 403];
        }

        // Load the object
        require_once DOL_DOCUMENT_ROOT . $config['file'];
        $className = $config['class'];
        $object = new $className($db);

        $result = $object->fetch($objectId);
        if ($result <= 0) {
            return ['error' => 'Object not found', 'status' => 404];
        }

        // Check entity
        $entity = (int) ($payload['entity'] ?? $object->entity ?? 1);
        if (property_exists($object, 'entity') && $object->entity != $entity && $object->entity != 0) {
            return ['error' => 'Access denied (entity)', 'status' => 403];
        }

        return [
            'type' => $type,
            'object_id' => $objectId,
            'user' => $user,
            'config' => $config,
            'object' => $object,
        ];
    }

    /**
     * Get the document directory for an object
     *
     * @param array $config Object type configuration
     * @param object $object The Dolibarr object
     * @param object $conf Dolibarr configuration
     * @return string|null Document directory path
     */
    private function getObjectDocumentDir($config, $object, $conf)
    {
        $method = $config['subdir_method'];
        $subdir = $this->$method($object);

        if (empty($subdir)) {
            return null;
        }

        // Build document directory path
        $modulepart = $config['modulepart'];

        // Try multidir_output first (multi-entity)
        if (!empty($conf->$modulepart->multidir_output[$object->entity ?? 1])) {
            return $conf->$modulepart->multidir_output[$object->entity ?? 1] . '/' . $subdir;
        }

        // Fallback to dir_output
        if (!empty($conf->$modulepart->dir_output)) {
            return $conf->$modulepart->dir_output . '/' . $subdir;
        }

        // Last resort: DOL_DATA_ROOT
        return DOL_DATA_ROOT . '/' . $modulepart . '/' . $subdir;
    }

    /**
     * Get subdirectory for a product
     * Products use ref as subdirectory
     *
     * @param object $product Product object
     * @return string Subdirectory name
     */
    private function getProductSubdir($product)
    {
        return dol_sanitizeFileName($product->ref);
    }

    /**
     * Get subdirectory for a thirdparty
     * Thirdparties use name as subdirectory
     *
     * @param object $societe Societe object
     * @return string Subdirectory name
     */
    private function getThirdpartySubdir($societe)
    {
        return dol_sanitizeFileName($societe->name);
    }

    /**
     * Get subdirectory for a project
     * Projects use ref as subdirectory
     *
     * @param object $project Project object
     * @return string Subdirectory name
     */
    private function getProjectSubdir($project)
    {
        return dol_sanitizeFileName($project->ref);
    }

    /**
     * Get subdirectory for an intervention
     * Interventions use ref as subdirectory
     *
     * @param object $fichinter Fichinter object
     * @return string Subdirectory name
     */
    private function getInterventionSubdir($fichinter)
    {
        return dol_sanitizeFileName($fichinter->ref);
    }

    /**
     * Get subdirectory for a category
     * Categories use get_exdir() pattern with level=2
     * Path format: X/Y/ID where X and Y are based on ID digits
     *
     * @param object $category Categorie object
     * @return string Subdirectory path
     */
    private function getCategorySubdir($category)
    {
        // Replicate get_exdir($id, 2, 0, 0, $object, 'category') behavior
        $id = (int) $category->id;
        $num = substr("000" . $id, -2);
        $path = substr($num, 1, 1) . '/' . substr($num, 0, 1);
        return $path . '/' . $id;
    }

    /**
     * Load existing ecm_files entries for an object, indexed by filename
     *
     * @param object $object The Dolibarr object
     * @return array Map of filename => EcmFiles data
     */
    private function loadEcmFilesForObject($object)
    {
        global $db, $conf;

        $indexed = [];
        $objectType = $object->table_element ?? '';
        if (empty($objectType)) {
            return $indexed;
        }

        $sql = "SELECT rowid, share, filename, filepath";
        $sql .= " FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE src_object_type = '" . $db->escape($objectType) . "'";
        $sql .= " AND src_object_id = " . (int) $object->id;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $key = $obj->filepath . '/' . $obj->filename;
                $indexed[$key] = [
                    'ecm_id' => (int) $obj->rowid,
                    'share' => $obj->share ?? '',
                ];
            }
        }

        return $indexed;
    }

    /**
     * Find or create an ecm_files entry for a document file.
     *
     * If the file already has an ecm_files record, returns its data.
     * Otherwise creates a new record with a share hash for download.
     *
     * @param array $file File info from dol_dir_list
     * @param string $relativePath Path relative to object document directory
     * @param string $docDir Object document directory
     * @param object $object The Dolibarr object
     * @param object $user Authenticated user
     * @param array $ecmIndexed Existing ecm_files entries (by reference, updated on create)
     * @return array ['ecm_id' => int, 'share' => string]
     */
    private function ensureEcmEntry($file, $relativePath, $docDir, $object, $user, &$ecmIndexed)
    {
        global $db, $conf;

        // Build the filepath as Dolibarr stores it: relative to DOL_DATA_ROOT
        $fullRelative = str_replace(DOL_DATA_ROOT . '/', '', $docDir . '/' . $relativePath);
        $ecmFilepath = dirname($fullRelative);
        $ecmFilename = basename($fullRelative);
        $ecmKey = $ecmFilepath . '/' . $ecmFilename;

        // Check if already loaded from object-based query
        if (isset($ecmIndexed[$ecmKey])) {
            $entry = $ecmIndexed[$ecmKey];
            // Ensure share hash exists
            if (empty($entry['share'])) {
                $share = getRandomPassword(true);
                $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files";
                $sql .= " SET share = '" . $db->escape($share) . "'";
                $sql .= " WHERE rowid = " . (int) $entry['ecm_id'];
                $db->query($sql);
                $entry['share'] = $share;
                $ecmIndexed[$ecmKey]['share'] = $share;
            }
            return $entry;
        }

        // Try to find by filepath/filename (may exist without src_object link)
        $ecmFile = new \EcmFiles($db);
        $result = $ecmFile->fetch(0, '', $ecmFilepath . '/' . $ecmFilename);

        if ($result > 0) {
            // Found by path, update src_object if missing
            if (empty($ecmFile->src_object_type) || empty($ecmFile->src_object_id)) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files";
                $sql .= " SET src_object_type = '" . $db->escape($object->table_element) . "'";
                $sql .= ", src_object_id = " . (int) $object->id;
                $sql .= " WHERE rowid = " . (int) $ecmFile->id;
                $db->query($sql);
            }
            // Ensure share hash exists
            if (empty($ecmFile->share)) {
                $ecmFile->share = getRandomPassword(true);
                $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files";
                $sql .= " SET share = '" . $db->escape($ecmFile->share) . "'";
                $sql .= " WHERE rowid = " . (int) $ecmFile->id;
                $db->query($sql);
            }
            $entry = [
                'ecm_id' => (int) $ecmFile->id,
                'share' => $ecmFile->share,
            ];
            $ecmIndexed[$ecmKey] = $entry;
            return $entry;
        }

        // Create new ecm_files entry
        $ecmFile = new \EcmFiles($db);
        $ecmFile->filename = $ecmFilename;
        $ecmFile->filepath = $ecmFilepath;
        $ecmFile->fullpath_orig = $file['fullname'];
        $ecmFile->entity = $conf->entity;
        $ecmFile->src_object_type = $object->table_element;
        $ecmFile->src_object_id = (int) $object->id;
        $ecmFile->gen_or_uploaded = 'uploaded';
        $ecmFile->share = getRandomPassword(true);
        $ecmFile->date_c = dol_now();

        // label = md5 hash of file content
        $fullPathEncoded = dol_osencode($file['fullname']);
        if (file_exists($fullPathEncoded)) {
            $ecmFile->label = md5_file($fullPathEncoded);
        }

        $createResult = $ecmFile->create($user);
        if ($createResult > 0) {
            dol_syslog("smartauth::ObjectDocumentController::ensureEcmEntry - Created ecm_files entry id=" . $createResult . " for " . $ecmKey);
            $entry = [
                'ecm_id' => (int) $createResult,
                'share' => $ecmFile->share,
            ];
        } else {
            dol_syslog("smartauth::ObjectDocumentController::ensureEcmEntry - Failed to create ecm_files for " . $ecmKey . ": " . implode(', ', $ecmFile->errors), LOG_WARNING);
            $entry = [
                'ecm_id' => 0,
                'share' => '',
            ];
        }
        $ecmIndexed[$ecmKey] = $entry;
        return $entry;
    }

    /**
     * Resolve a share hash to a file path via ecm_files
     *
     * @param string $shareHash The share hash from ecm_files
     * @return array|null ['filepath' => string, 'filename' => string, 'ecm_id' => int] or null
     */
    private function resolveShareHash($shareHash)
    {
        global $db;

        $ecmFile = new \EcmFiles($db);
        $result = $ecmFile->fetch(0, '', '', '', $shareHash);

        if ($result <= 0 || empty($ecmFile->filepath) || empty($ecmFile->filename)) {
            return null;
        }

        return [
            'filepath' => $ecmFile->filepath,
            'filename' => $ecmFile->filename,
            'ecm_id' => (int) $ecmFile->id,
            'src_object_type' => $ecmFile->src_object_type,
            'src_object_id' => (int) $ecmFile->src_object_id,
        ];
    }

    /**
     * Determine document type from MIME type
     *
     * @param string $mimeType MIME type
     * @return string Document type: image, pdf, or other
     */
    private function getDocumentType($mimeType)
    {
        if (strpos($mimeType, 'image/') === 0) {
            return 'image';
        }
        if ($mimeType === 'application/pdf') {
            return 'pdf';
        }
        return 'other';
    }

    /**
     * Get supported object types (for documentation/discovery)
     *
     * @return array List of supported object types with their configuration
     */
    public static function getSupportedTypes()
    {
        $types = [];
        foreach (self::$objectTypeConfig as $type => $config) {
            $types[$type] = [
                'module' => $config['module'],
                'modulepart' => $config['modulepart'],
            ];
        }
        return $types;
    }

    /**
     * Register additional object types for document handling.
     *
     * Use this method to extend the list of supported object types. External modules
     * should call this during initialization (e.g., via a Dolibarr hook) to add their
     * custom object types.
     *
     * Required configuration keys:
     * - class: The Dolibarr class name (e.g., 'MyObject')
     * - file: Path to the class file relative to DOL_DOCUMENT_ROOT (e.g., '/mymodule/class/myobject.class.php')
     * - module: Module code for permission check (e.g., 'mymodule')
     * - modulepart: Directory name in DOL_DATA_ROOT (e.g., 'mymodule')
     * - subdir_method: Method name in this controller to get the subdirectory (must be added separately)
     *
     * Note: You must also add the corresponding getXxxSubdir() method to this controller,
     * or use a callable in subdir_method (future enhancement).
     *
     * @param string $type Object type key (e.g., 'myobject')
     * @param array $config Configuration array with required keys
     * @return bool True if registered, false if missing required keys
     */
    public static function registerObjectType($type, $config)
    {
        $required = ['class', 'file', 'module', 'modulepart', 'subdir_method'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                dol_syslog("ObjectDocumentController::registerObjectType - Missing required key: $key for type: $type", LOG_WARNING);
                return false;
            }
        }

        self::$objectTypeConfig[$type] = $config;
        return true;
    }
}
