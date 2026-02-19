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

            $documents[] = [
                'id' => $docId,
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
     * @api {get} /object/{type}/{id}/document/{path} Download a document
     * @apiName DownloadObjectDocument
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Downloads a document attached to a Dolibarr object.
     * Returns base64-encoded content by default.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiParam {String} type Object type (product, thirdparty, project, intervention)
     * @apiParam {Number} id Object ID (rowid)
     * @apiParam {String} path Relative path to the document (URL-encoded)
     *
     * @apiSuccess {String} filename File name
     * @apiSuccess {String} content-type MIME type
     * @apiSuccess {Number} filesize File size in bytes
     * @apiSuccess {String} content Base64-encoded file content
     * @apiSuccess {String} encoding Always "base64"
     */
    public function download($payload)
    {
        global $conf;

        dol_syslog("smartauth::ObjectDocumentController::download");

        // Validate parameters
        $validation = $this->validateObjectParams($payload);
        if (isset($validation['error'])) {
            return [$validation, $validation['status']];
        }

        $config = $validation['config'];
        $object = $validation['object'];

        // Get and validate path parameter
        $relativePath = $payload['path'] ?? '';
        if (empty($relativePath)) {
            return [['error' => 'Missing path parameter'], 400];
        }

        // URL decode the path
        $relativePath = urldecode($relativePath);

        // Security: prevent path traversal
        if (preg_match('/\.\./', $relativePath) || preg_match('/[<>|]/', $relativePath)) {
            dol_syslog("smartauth::ObjectDocumentController::download - Path traversal attempt: $relativePath", LOG_WARNING);
            return [['error' => 'Invalid path'], 400];
        }

        // Build full path
        $docDir = $this->getObjectDocumentDir($config, $object, $conf);
        $fullPath = $docDir . '/' . $relativePath;
        $fullPathEncoded = dol_osencode($fullPath);

        // Check file exists
        if (!file_exists($fullPathEncoded)) {
            dol_syslog("smartauth::ObjectDocumentController::download - File not found: $fullPath", LOG_WARNING);
            return [['error' => 'File not found'], 404];
        }

        // Check it's a file, not a directory
        if (!is_file($fullPathEncoded)) {
            return [['error' => 'Not a file'], 400];
        }

        // Get file info
        $filename = basename($fullPath);
        $filename = preg_replace('/\.noexe$/i', '', $filename);
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
            dol_syslog("smartauth::ObjectDocumentController::download - Failed to read file: $fullPath", LOG_ERR);
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
     * @api {get} /object/{type}/{id}/document/{path}/binary Download a document (binary)
     * @apiName DownloadObjectDocumentBinary
     * @apiGroup ObjectDocument
     * @apiVersion 1.0.0
     *
     * @apiDescription Downloads a document as binary stream.
     * More efficient for large files.
     */
    public function downloadBinary($payload)
    {
        global $db, $conf;

        dol_syslog("smartauth::ObjectDocumentController::downloadBinary");

        // Validate parameters
        $validation = $this->validateObjectParams($payload);
        if (isset($validation['error'])) {
            return [$validation, $validation['status']];
        }

        $config = $validation['config'];
        $object = $validation['object'];

        // Get and validate path parameter
        $relativePath = $payload['path'] ?? '';
        if (empty($relativePath)) {
            return [['error' => 'Missing path parameter'], 400];
        }

        // URL decode the path
        $relativePath = urldecode($relativePath);

        // Security: prevent path traversal
        if (preg_match('/\.\./', $relativePath) || preg_match('/[<>|]/', $relativePath)) {
            dol_syslog("smartauth::ObjectDocumentController::downloadBinary - Path traversal attempt: $relativePath", LOG_WARNING);
            return [['error' => 'Invalid path'], 400];
        }

        // Build full path
        $docDir = $this->getObjectDocumentDir($config, $object, $conf);
        $fullPath = $docDir . '/' . $relativePath;
        $fullPathEncoded = dol_osencode($fullPath);

        // Check file exists
        if (!file_exists($fullPathEncoded)) {
            dol_syslog("smartauth::ObjectDocumentController::downloadBinary - File not found: $fullPath", LOG_WARNING);
            return [['error' => 'File not found'], 404];
        }

        // Get file info
        $filename = basename($fullPath);
        $filename = preg_replace('/\.noexe$/i', '', $filename);
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
