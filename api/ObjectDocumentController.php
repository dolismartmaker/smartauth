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
    // Bundle size limits
    private const BUNDLE_MAX_FILE_SIZE = 5242880;       // 5 MB per individual file
    private const BUNDLE_MAX_TOTAL_SIZE = 104857600;    // 100 MB total per bundle
    private const BUNDLE_MAX_SHARES = 500;              // Max shares per request

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
            'table_element' => 'product',
            'subdir_method' => 'getProductSubdir',
        ],
        'thirdparty' => [
            'class' => 'Societe',
            'file' => '/societe/class/societe.class.php',
            'module' => 'societe',
            'modulepart' => 'societe',
            'table_element' => 'societe',
            'subdir_method' => 'getThirdpartySubdir',
        ],
        'project' => [
            'class' => 'Project',
            'file' => '/projet/class/project.class.php',
            'module' => 'projet',
            'modulepart' => 'projet',
            'table_element' => 'projet',
            'subdir_method' => 'getProjectSubdir',
        ],
        'intervention' => [
            'class' => 'Fichinter',
            'file' => '/fichinter/class/fichinter.class.php',
            'module' => 'ficheinter',
            'modulepart' => 'ficheinter',
            'table_element' => 'fichinter',
            'subdir_method' => 'getInterventionSubdir',
        ],
        'category' => [
            'class' => 'Categorie',
            'file' => '/categories/class/categorie.class.php',
            'module' => 'categorie',
            'modulepart' => 'categorie',
            'table_element' => 'categorie',
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
     * Batch resolve multiple share hashes to file paths via ecm_files (single SQL query).
     *
     * @param array $shares Array of share hash strings
     * @return array Map of share => ['filepath', 'filename', 'ecm_id', 'src_object_type', 'src_object_id']
     */
    private function resolveShareHashes($shares)
    {
        global $db;

        if (empty($shares)) {
            return [];
        }

        $placeholders = [];
        foreach ($shares as $s) {
            $s = trim((string) $s);
            if (!empty($s)) {
                $placeholders[] = "'" . $db->escape($s) . "'";
            }
        }
        if (empty($placeholders)) {
            return [];
        }

        $sql = "SELECT rowid, share, filepath, filename, src_object_type, src_object_id";
        $sql .= " FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE share IN (" . implode(',', $placeholders) . ")";

        $results = [];
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $results[$obj->share] = [
                    'filepath' => $obj->filepath,
                    'filename' => $obj->filename,
                    'ecm_id' => (int) $obj->rowid,
                    'src_object_type' => $obj->src_object_type ?? '',
                    'src_object_id' => (int) ($obj->src_object_id ?? 0),
                ];
            }
            $db->free($resql);
        }

        return $results;
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
     * @api {get} /object/documents/{type}/{doctypes} Batch list documents for all objects of a type
     * @api {get} /object/documents/{type}/{doctypes}/since/{timestamp} Batch list with incremental sync
     * @apiName BatchListObjectDocuments
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Lists all documents across ALL objects of a given type in a single call.
     * Used by offline sync to efficiently pull document metadata without N individual requests.
     * Path-only parameters (no query strings) for WAF compatibility.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiParam {String} type Object type (product, thirdparty, project, intervention, category)
     * @apiParam {String} doctypes Comma-separated document types to include (image,pdf,other)
     * @apiParam {Number} [timestamp] Unix timestamp for incremental sync (only files modified after)
     *
     * @apiSuccess {Object[]} documents List of documents across all objects
     * @apiSuccess {Number} documents.object_id Parent object ID
     * @apiSuccess {String} documents.id Stable document ID (hash)
     * @apiSuccess {String} documents.share ECM share hash for download
     * @apiSuccess {String} documents.filename File name
     * @apiSuccess {String} documents.relative_path Path relative to object directory
     * @apiSuccess {String} documents.mime_type MIME type
     * @apiSuccess {Number} documents.size File size in bytes
     * @apiSuccess {Number} documents.updated_at Last modification unix timestamp
     * @apiSuccess {String} documents.type image|pdf|other
     * @apiSuccess {Number[]} unavailable_ids Object IDs no longer accessible (for cleanup)
     * @apiSuccess {Number} server_time Current server unix timestamp
     */
    public function batchIndex($payload)
    {
        global $db, $conf;

        dol_syslog("smartauth::ObjectDocumentController::batchIndex");

        // Authentication
        $user = $payload['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            return [['error' => 'Authentication required'], 401];
        }

        // Validate object type
        $type = InputSanitizer::sanitizeAlphanumeric($payload['type'] ?? '', 32);
        if (empty($type) || !isset(self::$objectTypeConfig[$type])) {
            return [['error' => 'Invalid object type. Supported: ' . implode(', ', array_keys(self::$objectTypeConfig))], 400];
        }

        $config = self::$objectTypeConfig[$type];

        // Check module is enabled
        if (!isModEnabled($config['module'])) {
            return [['error' => 'Module not enabled: ' . $config['module']], 403];
        }

        // Check permissions
        if (!$user->hasRight($config['module'], 'read') && !$user->hasRight($config['module'], 'lire')) {
            dol_syslog("smartauth::ObjectDocumentController::batchIndex - Access denied for user {$user->id} on module {$config['module']}", LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        // Parse document types from path segment (e.g., "image,pdf")
        $doctypesParam = $payload['doctypes'] ?? 'image,pdf,other';
        $doctypes = array_map('trim', explode(',', $doctypesParam));

        // Parse optional since timestamp from path segment
        $since = isset($payload['timestamp']) ? (int) $payload['timestamp'] : 0;

        // Resolve table_element for ECM queries
        $tableElement = $config['table_element'] ?? $this->resolveTableElement($config, $db);

        // 1. Get accessible objects with their subdirectories (optimized SQL per type)
        $objects = $this->getBatchAccessibleObjects($type, $config, $db, $conf);

        // 2. Load all ECM entries for this object type in one query
        $ecmIndexed = $this->loadEcmFilesForObjectType($tableElement, $db, $conf);

        // 3. Get base document directory for this object type
        $baseDir = $this->getBatchBaseDir($config, $conf);

        $documents = [];
        $accessibleIds = [];

        foreach ($objects as $obj) {
            $accessibleIds[] = $obj['id'];

            $docDir = $baseDir . '/' . $obj['subdir'];
            if (!is_dir($docDir)) {
                continue;
            }

            $files = dol_dir_list($docDir, 'files', 1, '', array('(\.meta|_preview.*\.png)$', '^\.'), 'date', SORT_DESC, 1);

            foreach ($files as $file) {
                // Skip files not modified since last sync
                if ($since > 0 && $file['date'] <= $since) {
                    continue;
                }

                $relativePath = str_replace($docDir . '/', '', $file['fullname']);
                $docId = substr(md5($type . '_' . $obj['id'] . '_' . $relativePath), 0, 8);

                $mimeType = dol_mimetype($file['name']);
                $docType = $this->getDocumentType($mimeType);

                // Filter by requested document types
                if (!in_array($docType, $doctypes)) {
                    continue;
                }

                // Create a lightweight proxy for ensureEcmEntry (only needs table_element and id)
                $objProxy = new \stdClass();
                $objProxy->table_element = $tableElement;
                $objProxy->id = $obj['id'];

                $ecmData = $this->ensureEcmEntry(
                    $file, $relativePath, $docDir, $objProxy, $user, $ecmIndexed
                );

                $documents[] = [
                    'object_id' => $obj['id'],
                    'id' => $docId,
                    'share' => $ecmData['share'],
                    'filename' => $file['name'],
                    'relative_path' => $relativePath,
                    'mime_type' => $mimeType,
                    'size' => (int) $file['size'],
                    'updated_at' => (int) $file['date'],
                    'type' => $docType,
                ];
            }
        }

        // 4. For incremental sync, identify objects that are no longer accessible
        $unavailableIds = [];
        if ($since > 0) {
            $unavailableIds = $this->getBatchUnavailableIds($type, $since, $db, $conf);
        }

        $docCount = count($documents);
        $objCount = count($accessibleIds);
        $unavailCount = count($unavailableIds);
        dol_syslog("smartauth::ObjectDocumentController::batchIndex - Found $docCount documents for $objCount $type objects, $unavailCount unavailable");

        return [[
            'documents' => $documents,
            'unavailable_ids' => $unavailableIds,
            'server_time' => time(),
        ], 200];
    }

    /**
     * @api {post} /object/documents/bundle Download multiple documents as a ZIP bundle
     * @apiName BundleDownloadDocuments
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Downloads multiple documents in a single ZIP archive (uncompressed STORE).
     * Authorization is based on ECM share hashes: if the client has the share hash
     * (obtained from batchIndex or index), it can download the file.
     *
     * Files exceeding max_file_size are listed as oversized (download individually).
     * If total size exceeds the bundle limit, remaining shares are returned for pagination.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiBody {String[]} shares Array of ECM share hashes to include
     * @apiBody {Number} [max_file_size] Max individual file size in bytes (capped at server limit)
     *
     * @apiSuccess {File} ZIP archive containing manifest.json + files/{share}
     */
    public function bundle($payload)
    {
        global $db;

        dol_syslog("smartauth::ObjectDocumentController::bundle");

        // Authentication
        $user = $payload['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            return [['error' => 'Authentication required'], 401];
        }

        // Parse request body
        $shares = $payload['shares'] ?? [];
        if (!is_array($shares) || empty($shares)) {
            return [['error' => 'Missing or empty shares array'], 400];
        }
        if (count($shares) > self::BUNDLE_MAX_SHARES) {
            return [['error' => 'Too many shares (max ' . self::BUNDLE_MAX_SHARES . ')'], 400];
        }

        $maxFileSize = isset($payload['max_file_size'])
            ? min((int) $payload['max_file_size'], self::BUNDLE_MAX_FILE_SIZE)
            : self::BUNDLE_MAX_FILE_SIZE;

        // Batch resolve all share hashes in one SQL query
        $resolved = $this->resolveShareHashes($shares);

        $included = [];
        $oversized = [];
        $remaining = [];
        $errors = [];
        $filesToAdd = [];
        $totalSize = 0;

        foreach ($shares as $share) {
            $share = trim((string) $share);
            if (empty($share)) {
                continue;
            }

            if (!isset($resolved[$share])) {
                $errors[] = ['share' => $share, 'error' => 'not_found'];
                continue;
            }

            $ecm = $resolved[$share];
            $fullPath = DOL_DATA_ROOT . '/' . $ecm['filepath'] . '/' . $ecm['filename'];
            $fullPathEncoded = dol_osencode($fullPath);

            if (!file_exists($fullPathEncoded)) {
                $errors[] = ['share' => $share, 'error' => 'file_missing'];
                continue;
            }

            $filesize = filesize($fullPathEncoded);
            $filename = preg_replace('/\.noexe$/i', '', $ecm['filename']);
            $mimeType = dol_mimetype($filename);

            $meta = [
                'share' => $share,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => (int) $filesize,
            ];

            // Skip oversized files
            if ($filesize > $maxFileSize) {
                $oversized[] = $meta;
                continue;
            }

            // Check total bundle size limit
            if ($totalSize + $filesize > self::BUNDLE_MAX_TOTAL_SIZE) {
                $remaining[] = $share;
                continue;
            }

            $totalSize += $filesize;
            $included[] = $meta;
            $filesToAdd[] = [
                'share' => $share,
                'path' => $fullPathEncoded,
            ];
        }

        // Create ZIP archive
        $tmpFile = tempnam(sys_get_temp_dir(), 'smartauth_bundle_');
        if ($tmpFile === false) {
            return [['error' => 'Failed to create temp file'], 500];
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            return [['error' => 'Failed to create ZIP archive'], 500];
        }

        // Add manifest
        $manifest = [
            'included' => $included,
            'oversized' => $oversized,
            'remaining' => $remaining,
            'errors' => $errors,
            'server_time' => time(),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));

        // Add files with STORE method (no compression - images/PDFs are already compressed)
        foreach ($filesToAdd as $fileInfo) {
            $entryName = 'files/' . $fileInfo['share'];
            $zip->addFile($fileInfo['path'], $entryName);
            $zip->setCompressionName($entryName, \ZipArchive::CM_STORE);
        }

        $zip->close();

        // Stream ZIP response
        $zipSize = filesize($tmpFile);
        $docCount = count($included);
        $overCount = count($oversized);
        $remCount = count($remaining);
        dol_syslog("smartauth::ObjectDocumentController::bundle - ZIP: {$docCount} files, {$overCount} oversized, {$remCount} remaining, {$zipSize} bytes");

        // Close DB before streaming
        if (is_object($db)) {
            $db->close();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="bundle.zip"');
        header('Content-Length: ' . $zipSize);
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfileLowMemory($tmpFile);
        @unlink($tmpFile);

        exit;
    }

    /**
     * Get all accessible objects of a type with their document subdirectories.
     * Uses optimized SQL per type to avoid N individual fetches.
     *
     * @param string $type Object type key
     * @param array $config Object type configuration
     * @param object $db Database handler
     * @param object $conf Dolibarr configuration
     * @return array List of ['id' => int, 'subdir' => string]
     */
    private function getBatchAccessibleObjects($type, $config, $db, $conf)
    {
        $objects = [];

        switch ($type) {
            case 'product':
                $sql = "SELECT p.rowid, p.ref";
                $sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
                $sql .= " WHERE p.tosell = 1";
                $sql .= " AND p.fk_product_type IN (0, 1)";
                $sql .= " AND p.entity IN (" . getEntity('product') . ")";
                $sql .= " ORDER BY p.rowid ASC";
                $resql = $db->query($sql);
                if ($resql) {
                    while ($obj = $db->fetch_object($resql)) {
                        $objects[] = [
                            'id' => (int) $obj->rowid,
                            'subdir' => dol_sanitizeFileName($obj->ref),
                        ];
                    }
                    $db->free($resql);
                }
                break;

            case 'category':
                $sql = "SELECT c.rowid";
                $sql .= " FROM " . MAIN_DB_PREFIX . "categorie as c";
                $sql .= " WHERE c.entity IN (" . getEntity('categorie') . ")";
                $sql .= " ORDER BY c.rowid ASC";
                $resql = $db->query($sql);
                if ($resql) {
                    while ($obj = $db->fetch_object($resql)) {
                        $id = (int) $obj->rowid;
                        $objects[] = [
                            'id' => $id,
                            'subdir' => $this->computeCategorySubdir($id),
                        ];
                    }
                    $db->free($resql);
                }
                break;

            case 'thirdparty':
                $sql = "SELECT s.rowid, s.nom";
                $sql .= " FROM " . MAIN_DB_PREFIX . "societe as s";
                $sql .= " WHERE s.status = 1";
                $sql .= " AND s.entity IN (" . getEntity('societe') . ")";
                $sql .= " ORDER BY s.rowid ASC";
                $resql = $db->query($sql);
                if ($resql) {
                    while ($obj = $db->fetch_object($resql)) {
                        $objects[] = [
                            'id' => (int) $obj->rowid,
                            'subdir' => dol_sanitizeFileName($obj->nom),
                        ];
                    }
                    $db->free($resql);
                }
                break;

            case 'project':
            case 'intervention':
                require_once DOL_DOCUMENT_ROOT . $config['file'];
                $className = $config['class'];
                $tmpObj = new $className($db);
                $tableName = $tmpObj->table_element;

                $sql = "SELECT rowid, ref";
                $sql .= " FROM " . MAIN_DB_PREFIX . $db->escape($tableName);
                $sql .= " WHERE entity IN (" . getEntity($tableName) . ")";
                $sql .= " ORDER BY rowid ASC";
                $resql = $db->query($sql);
                if ($resql) {
                    while ($obj = $db->fetch_object($resql)) {
                        $objects[] = [
                            'id' => (int) $obj->rowid,
                            'subdir' => dol_sanitizeFileName($obj->ref),
                        ];
                    }
                    $db->free($resql);
                }
                break;

            default:
                // External types registered via registerObjectType(): fetch individually
                require_once DOL_DOCUMENT_ROOT . $config['file'];
                $className = $config['class'];
                $tmpObj = new $className($db);
                $tableName = $tmpObj->table_element;

                $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $db->escape($tableName);
                $sql .= " WHERE entity IN (" . getEntity($tableName) . ")";
                $sql .= " ORDER BY rowid ASC";
                $resql = $db->query($sql);
                if ($resql) {
                    while ($objRow = $db->fetch_object($resql)) {
                        $fetchObj = new $className($db);
                        if ($fetchObj->fetch($objRow->rowid) > 0) {
                            $subdirMethod = $config['subdir_method'];
                            $objects[] = [
                                'id' => (int) $objRow->rowid,
                                'subdir' => $this->$subdirMethod($fetchObj),
                            ];
                        }
                    }
                    $db->free($resql);
                }
                break;
        }

        return $objects;
    }

    /**
     * Get object IDs that are no longer accessible (for incremental sync cleanup).
     * Type-specific logic: products with tosell=0, etc.
     *
     * @param string $type Object type key
     * @param int $since Unix timestamp
     * @param object $db Database handler
     * @param object $conf Dolibarr configuration
     * @return array List of object IDs
     */
    private function getBatchUnavailableIds($type, $since, $db, $conf)
    {
        $ids = [];

        switch ($type) {
            case 'product':
                $sql = "SELECT p.rowid";
                $sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
                $sql .= " WHERE p.tosell = 0";
                $sql .= " AND p.fk_product_type IN (0, 1)";
                $sql .= " AND p.entity IN (" . getEntity('product') . ")";
                $sql .= " AND UNIX_TIMESTAMP(p.tms) > " . (int) $since;
                $resql = $db->query($sql);
                if ($resql) {
                    while ($obj = $db->fetch_object($resql)) {
                        $ids[] = (int) $obj->rowid;
                    }
                    $db->free($resql);
                }
                break;
        }

        return $ids;
    }

    /**
     * Load all ecm_files entries for an object type in batch (single query).
     *
     * @param string $tableElement Dolibarr table_element value
     * @param object $db Database handler
     * @param object $conf Dolibarr configuration
     * @return array Map of "filepath/filename" => ['ecm_id' => int, 'share' => string]
     */
    private function loadEcmFilesForObjectType($tableElement, $db, $conf)
    {
        $indexed = [];

        $sql = "SELECT rowid, share, filename, filepath";
        $sql .= " FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE src_object_type = '" . $db->escape($tableElement) . "'";
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
            $db->free($resql);
        }

        return $indexed;
    }

    /**
     * Get base document directory for an object type (without subdir).
     *
     * @param array $config Object type configuration
     * @param object $conf Dolibarr configuration
     * @return string Base directory path
     */
    private function getBatchBaseDir($config, $conf)
    {
        $modulepart = $config['modulepart'];
        $entity = $conf->entity ?? 1;

        if (!empty($conf->$modulepart->multidir_output[$entity])) {
            return $conf->$modulepart->multidir_output[$entity];
        }
        if (!empty($conf->$modulepart->dir_output)) {
            return $conf->$modulepart->dir_output;
        }
        return DOL_DATA_ROOT . '/' . $modulepart;
    }

    /**
     * Compute category subdirectory from ID (without needing full object).
     * Replicates get_exdir($id, 2, 0, 0, $object, 'category') behavior.
     *
     * @param int $categoryId Category ID
     * @return string Subdirectory path (e.g., "5/0/15")
     */
    private function computeCategorySubdir($categoryId)
    {
        $num = substr("000" . $categoryId, -2);
        return substr($num, 1, 1) . '/' . substr($num, 0, 1) . '/' . $categoryId;
    }

    /**
     * Resolve table_element for an object type by instantiating its class.
     * Used as fallback when table_element is not in the config.
     *
     * @param array $config Object type configuration
     * @param object $db Database handler
     * @return string The table_element value
     */
    private function resolveTableElement($config, $db)
    {
        require_once DOL_DOCUMENT_ROOT . $config['file'];
        $className = $config['class'];
        $tmpObj = new $className($db);
        return $tmpObj->table_element ?? '';
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
