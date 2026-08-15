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

        dol_syslog("[SmartAuth] ObjectDocumentController::index");

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
            dol_syslog("[SmartAuth] ObjectDocumentController::index - No document directory: $docDir");
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

        dol_syslog("[SmartAuth] ObjectDocumentController::index - Found " . count($documents) . " documents for $type/$objectId");

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
        dol_syslog("[SmartAuth] ObjectDocumentController::download");

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
            dol_syslog("[SmartAuth] ObjectDocumentController::download - File too large: $filesize bytes", LOG_WARNING);
            return [['error' => 'File too large for base64 download, use binary mode'], 413];
        }

        $content = file_get_contents($fullPathEncoded);
        if ($content === false) {
            dol_syslog("[SmartAuth] ObjectDocumentController::download - Failed to read file", LOG_ERR);
            return [['error' => 'Failed to read file'], 500];
        }

        dol_syslog("[SmartAuth] ObjectDocumentController::download - Success: $filename ($filesize bytes)");

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

        dol_syslog("[SmartAuth] ObjectDocumentController::downloadBinary");

        // Resolve file path (share hash or legacy path)
        $resolved = $this->resolveDocumentPath($payload, 'downloadBinary');
        if (isset($resolved['error'])) {
            return [$resolved, $resolved['status']];
        }

        $fullPathEncoded = $resolved['full_path_encoded'];
        $filename = $resolved['filename'];

        $mimeType = dol_mimetype($filename);
        $filesize = filesize($fullPathEncoded);

        dol_syslog("[SmartAuth] ObjectDocumentController::downloadBinary - Streaming: $filename ($filesize bytes)");

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
            // Resolve in the caller's security context. The user must be
            // present (route is JWT-protected) and entity defaults to the
            // current Dolibarr entity. Without this, knowing the share hash
            // alone would grant read access to any document (CR-5 fix).
            $user = $payload['user'] ?? null;
            if (empty($user) || !is_object($user)) {
                dol_syslog("[SmartAuth] ObjectDocumentController::$caller - Authentication required for share hash mode", LOG_WARNING);
                return ['error' => 'Authentication required', 'status' => 401];
            }
            $entity = (int) ($payload['entity'] ?? $conf->entity);

            $resolved = $this->resolveShareHash($shareHash, $user, $entity);
            if ($resolved === null) {
                dol_syslog("[SmartAuth] ObjectDocumentController::$caller - Share hash not found or access denied: $shareHash", LOG_WARNING);
                return ['error' => 'Document not found', 'status' => 404];
            }

            $fullPath = DOL_DATA_ROOT . '/' . $resolved['filepath'] . '/' . $resolved['filename'];
            $fullPathEncoded = dol_osencode($fullPath);

            if (!file_exists($fullPathEncoded)) {
                dol_syslog("[SmartAuth] ObjectDocumentController::$caller - File from ecm not found on disk: $fullPath", LOG_WARNING);
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

        $relativePath = urldecode((string) $relativePath);

        // Layered path-traversal defence.
        // The original guard relied on a single preg_match for ".." which is
        // bypassable via:
        //   - URL-decoded forms (already addressed by urldecode above) but
        //     also encoded slash variants like "%2F..%2F" once urldecode hits
        //   - Null bytes that truncate the path on stat()
        //   - Absolute paths that ignore $docDir entirely
        // We now reject these explicitly, and do a realpath()-based boundary
        // check so any residual traversal is detected.
        if (
            strpos($relativePath, "\0") !== false
            || strpos($relativePath, '..') !== false
            || preg_match('#[<>|]#', $relativePath)
            || preg_match('#^[\\\\/]#', $relativePath)
            || preg_match('#^[a-zA-Z]:#', $relativePath)
        ) {
            dol_syslog("[SmartAuth] ObjectDocumentController::$caller - Path traversal attempt: " . substr($relativePath, 0, 200), LOG_WARNING);
            return ['error' => 'Invalid path', 'status' => 400];
        }

        $docDir = $this->getObjectDocumentDir($config, $object, $conf);
        $fullPath = $docDir . '/' . $relativePath;
        $fullPathEncoded = dol_osencode($fullPath);

        if (!file_exists($fullPathEncoded)) {
            dol_syslog("[SmartAuth] ObjectDocumentController::$caller - File not found: $fullPath", LOG_WARNING);
            return ['error' => 'File not found', 'status' => 404];
        }

        // realpath() boundary check: the resolved file must live inside the
        // document directory, not somewhere reachable via symlink or unicode
        // tricks.
        $docDirReal = realpath($docDir);
        $fullReal = realpath($fullPathEncoded);
        if ($docDirReal === false || $fullReal === false || strpos($fullReal . '/', $docDirReal . '/') !== 0) {
            dol_syslog("[SmartAuth] ObjectDocumentController::$caller - Path escapes docDir: " . $fullPath, LOG_WARNING);
            return ['error' => 'Invalid path', 'status' => 400];
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
        global $db, $conf;

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
            dol_syslog("[SmartAuth] ObjectDocumentController - Access denied for user {$user->id} on module {$config['module']}", LOG_WARNING);
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

        // Entity scope, fail-closed.
        //
        // The EXPECTED entity comes from the request payload only, where the
        // router puts the entity carried by the JWT. It used to fall back on
        // $object->entity, which turned the whole check into a comparison of a
        // value with itself -- a no-op. An absent or non-positive entity is now
        // a refusal, not a default of 1.
        //
        // The comparison is a STRICT integer equality: the previous loose one
        // matched "1" with 1 but also with true, and it additionally accepted
        // any object carrying entity = 0 (fail-open on rows shared across
        // tenants).
        //
        // Refusals answer 404, exactly like an unknown id: a distinct 403 would
        // be an existence oracle (enumerate rowids, tell "exists in another
        // tenant" apart from "does not exist").
        // When the payload carries no entity, fall back on the entity the server
        // is currently running as, NOT on the object's own value: $conf->entity
        // is set from the authenticated session, so comparing against it is the
        // same guard TenantGuardTrait applies. Falling back on $object->entity
        // is what made the check a no-op.
        $expectedEntity = isset($payload['entity']) ? (int) $payload['entity'] : 0;
        if ($expectedEntity <= 0) {
            $expectedEntity = isset($conf->entity) ? (int) $conf->entity : 0;
        }
        if ($expectedEntity <= 0) {
            dol_syslog("[SmartAuth] ObjectDocumentController - No entity in the request context for $type id=$objectId - refusing (fail-closed)", LOG_ERR);
            return ['error' => 'Object not found', 'status' => 404];
        }

        $objectEntity = isset($object->entity) ? (int) $object->entity : 0;
        if ($objectEntity <= 0) {
            // A few core fetch() implementations leave ->entity unhydrated; read
            // the column straight from the object's own table rather than trust
            // the object. Unresolvable means refused.
            $objectEntity = $this->entityFromObjectTable($config, $object, $objectId);
            if ($objectEntity === null) {
                dol_syslog("[SmartAuth] ObjectDocumentController - Cannot resolve entity for $type id=$objectId - refusing (fail-closed)", LOG_ERR);
                return ['error' => 'Object not found', 'status' => 404];
            }
        }

        if ($objectEntity !== $expectedEntity) {
            dol_syslog("[SmartAuth] ObjectDocumentController - Cross-entity access refused for $type id=$objectId (object entity $objectEntity, request entity $expectedEntity)", LOG_WARNING);
            return ['error' => 'Object not found', 'status' => 404];
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
     * Read the 'entity' column of a fetched object straight from its own table.
     *
     * Fallback of the entity check in validateObjectParams() for the classes
     * whose fetch() leaves ->entity unhydrated: trusting an unhydrated property
     * would read as entity 0 and let a guessed rowid escape its tenant.
     *
     * The table name comes from the type configuration (developer-controlled,
     * never request input) and is additionally restricted to [a-z0-9_] because a
     * third-party module can register a type through registerObjectType(); only
     * the id is user input and it is cast to int.
     *
     * @param  array  $config    Object type configuration
     * @param  object $object    The fetched Dolibarr object
     * @param  int    $objectId  Row id
     * @return int|null          The entity, or null when it cannot be resolved
     *                           (no table known, missing column, unknown row,
     *                           SQL error) -- the caller then refuses.
     */
    private function entityFromObjectTable($config, $object, $objectId)
    {
        global $db;

        $table = (string) ($config['table_element'] ?? '');
        if ($table === '' && is_object($object) && !empty($object->table_element)) {
            $table = (string) $object->table_element;
        }
        $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
        $objectId = (int) $objectId;

        if ($table === '' || $objectId <= 0) {
            dol_syslog("[SmartAuth] ObjectDocumentController - Entity lookup impossible (no table_element or no id) for id=$objectId", LOG_ERR);
            return null;
        }

        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . $table . " WHERE rowid = " . $objectId;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectDocumentController - Entity lookup failed on $table: " . $db->lasterror(), LOG_ERR);
            return null;
        }
        $row = $db->fetch_object($resql);
        $db->free($resql);
        if (!$row || !isset($row->entity)) {
            dol_syslog("[SmartAuth] ObjectDocumentController - No entity column value on $table for id=$objectId", LOG_ERR);
            return null;
        }

        return (int) $row->entity;
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
            dol_syslog("[SmartAuth] ObjectDocumentController::ensureEcmEntry - Created ecm_files entry id=" . $createResult . " for " . $ecmKey);
            $entry = [
                'ecm_id' => (int) $createResult,
                'share' => $ecmFile->share,
            ];
        } else {
            dol_syslog("[SmartAuth] ObjectDocumentController::ensureEcmEntry - Failed to create ecm_files for " . $ecmKey . ": " . implode(', ', $ecmFile->errors), LOG_WARNING);
            $entry = [
                'ecm_id' => 0,
                'share' => '',
            ];
        }
        $ecmIndexed[$ecmKey] = $entry;
        return $entry;
    }

    /**
     * Resolve a share hash to a file path via ecm_files.
     *
     * Applies the same gating as SmartFileController::_loadAndValidateFile
     * (the reference implementation cited by ~/docs/MODULE.md):
     *   - the file must belong to the caller's entity (or be entity 0);
     *   - the caller must pass dol_check_secure_access_document for the
     *     resolved modulepart with mode='read'.
     *
     * Without these checks, knowing the 32-character share hash would be
     * enough to download any document in any tenant.
     *
     * @param string $shareHash The share hash from ecm_files
     * @param \User $user Authenticated user (for permission checks)
     * @param int $entity Caller's entity id
     * @return array|null ['filepath' => string, 'filename' => string, 'ecm_id' => int] or null
     */
    private function resolveShareHash($shareHash, \User $user, $entity)
    {
        global $db;

        $entity = (int) $entity;

        $ecmFile = new \EcmFiles($db);
        $result = $ecmFile->fetch(0, '', '', '', $shareHash);

        if ($result <= 0 || empty($ecmFile->filepath) || empty($ecmFile->filename)) {
            return null;
        }

        // Entity check (Dolibarr core EcmFiles::fetch does not filter on
        // entity for share-hash lookups, so we must do it ourselves).
        if ((int) $ecmFile->entity !== $entity && (int) $ecmFile->entity !== 0) {
            dol_syslog('[SmartAuth] ObjectDocumentController::resolveShareHash - cross-entity access denied (file=' . $ecmFile->entity . ', user=' . $entity . ')', LOG_WARNING);
            return null;
        }

        if (!$this->shareHashAccessAllowed($ecmFile->filepath, $ecmFile->filename, $user, $entity)) {
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
     * Batch resolve multiple share hashes to file paths via ecm_files
     * (single SQL query).
     *
     * Applies the same entity + dol_check_secure_access_document gating
     * as resolveShareHash() - rows the caller cannot read are dropped
     * silently from the result map (CR-5 fix).
     *
     * @param array $shares Array of share hash strings
     * @param \User $user Authenticated user (for permission checks)
     * @param int $entity Caller's entity id
     * @return array Map of share => ['filepath', 'filename', 'ecm_id', 'src_object_type', 'src_object_id']
     */
    private function resolveShareHashes($shares, \User $user, $entity)
    {
        global $db;

        if (empty($shares)) {
            return [];
        }

        $entity = (int) $entity;

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
        // Entity filter (CR-5 fix). Use Dolibarr's getEntity() so that
        // multicompany "shared" entities behave correctly.
        $sql .= " AND entity IN (" . getEntity('ecmfiles') . ")";

        $results = [];
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                if (!$this->shareHashAccessAllowed($obj->filepath, $obj->filename, $user, $entity)) {
                    continue;
                }

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
     * Determine whether the caller may read the file behind a share hash.
     *
     * Mirrors SmartFileController::_loadAndValidateFile : extract modulepart
     * from the filepath (with multicompany prefix handling), then ask
     * dol_check_secure_access_document() to apply the per-module ACL.
     *
     * @param string $filepath ecm_files.filepath value
     * @param string $filename ecm_files.filename value
     * @param \User $user Authenticated user
     * @param int $entity Caller's entity id
     * @return bool
     */
    private function shareHashAccessAllowed($filepath, $filename, \User $user, $entity)
    {
        $tmp = explode('/', $filepath, 2);
        // Multicompany layout: when the first segment is numeric, it is the entity dir.
        if (isset($tmp[0]) && is_numeric($tmp[0])) {
            $tmp = explode('/', $tmp[1] ?? '', 2);
        }

        $modulepart = $tmp[0] ?? '';
        $original_file = (($tmp[1] ?? '') ? $tmp[1] . '/' : '') . $filename;

        if (empty($modulepart)) {
            dol_syslog('[SmartAuth] ObjectDocumentController::shareHashAccessAllowed - modulepart not derivable from filepath: ' . $filepath, LOG_WARNING);
            return false;
        }

        $check = dol_check_secure_access_document($modulepart, $original_file, (int) $entity, $user, '', 'read');
        if (empty($check['accessallowed'])) {
            dol_syslog('[SmartAuth] ObjectDocumentController::shareHashAccessAllowed - access denied for user ' . (int) $user->id . ' on modulepart=' . $modulepart, LOG_WARNING);
            return false;
        }

        return true;
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
     * Thumbnail mode: reduce a directory's image files to a single entry per
     * image -- the small thumbnail if present, else the mini thumbnail, else
     * the original. Non-image files pass through unchanged.
     *
     * Dolibarr generates thumbnails in a thumbs/ subdirectory named
     * "{base}_small.{ext}" and "{base}_mini.{ext}" alongside the original
     * "{base}.{ext}". Grouping by "{base}" lets a grid view download one small
     * file per image instead of the full-resolution original plus its variants.
     *
     * @param array $files dol_dir_list entries (each with a 'name' key)
     * @return array Filtered file entries
     */
    private function selectThumbnailFiles($files)
    {
        $groups = array();      // base name => ['small'=>file, 'mini'=>file, 'orig'=>file]
        $passthrough = array(); // non-image files, kept as-is

        foreach ($files as $file) {
            $mimeType = dol_mimetype($file['name']);
            if (strpos($mimeType, 'image/') !== 0) {
                $passthrough[] = $file;
                continue;
            }
            $name = $file['name'];
            if (preg_match('/^(.*)_small\.[^.]+$/', $name, $m)) {
                $groups[$m[1]]['small'] = $file;
            } elseif (preg_match('/^(.*)_mini\.[^.]+$/', $name, $m)) {
                $groups[$m[1]]['mini'] = $file;
            } else {
                $base = preg_replace('/\.[^.]+$/', '', $name);
                $groups[$base]['orig'] = $file;
            }
        }

        $kept = $passthrough;
        foreach ($groups as $variants) {
            if (isset($variants['small'])) {
                $kept[] = $variants['small'];
            } elseif (isset($variants['mini'])) {
                $kept[] = $variants['mini'];
            } elseif (isset($variants['orig'])) {
                $kept[] = $variants['orig'];
            }
        }

        return $kept;
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

        dol_syslog("[SmartAuth] ObjectDocumentController::batchIndex");

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
            dol_syslog("[SmartAuth] ObjectDocumentController::batchIndex - Access denied for user {$user->id} on module {$config['module']}", LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        // Parse document types from path segment (e.g., "image,pdf"). The
        // special "thumb" token requests image thumbnails only: each image is
        // collapsed to its small (or mini) variant so grid views download tiny
        // files instead of full-resolution originals. It is opt-in, so clients
        // still asking for "image" keep the original behaviour.
        $doctypesParam = $payload['doctypes'] ?? 'image,pdf,other';
        $doctypes = array_map('trim', explode(',', $doctypesParam));
        $thumbMode = in_array('thumb', $doctypes, true);
        if ($thumbMode) {
            $doctypes = array('image');
        }

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
            if ($thumbMode) {
                $files = $this->selectThumbnailFiles($files);
            }

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
        dol_syslog("[SmartAuth] ObjectDocumentController::batchIndex - Found $docCount documents for $objCount $type objects, $unavailCount unavailable");

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
        global $db, $conf;

        dol_syslog("[SmartAuth] ObjectDocumentController::bundle");

        // Authentication
        $user = $payload['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            return [['error' => 'Authentication required'], 401];
        }

        $entity = (int) ($payload['entity'] ?? $conf->entity);

        // Parse request body
        $shares = $payload['shares'] ?? [];
        if (!is_array($shares) || empty($shares)) {
            return [['error' => 'Missing or empty shares array'], 400];
        }
        // Count overflow is handled as soft pagination (like the total-size
        // limit below): process the first BUNDLE_MAX_SHARES and hand the rest
        // back in manifest.remaining so the client loop fetches them next round.
        // A hard 400 here would break sync for any object with >500 documents.
        $shareOverflow = [];
        if (count($shares) > self::BUNDLE_MAX_SHARES) {
            $shareOverflow = array_slice($shares, self::BUNDLE_MAX_SHARES);
            $shares = array_slice($shares, 0, self::BUNDLE_MAX_SHARES);
            dol_syslog('[SmartAuth] ObjectDocumentController::bundle - share count over limit, paginating ' . count($shareOverflow) . ' shares', LOG_NOTICE);
        }

        $maxFileSize = isset($payload['max_file_size'])
            ? min((int) $payload['max_file_size'], self::BUNDLE_MAX_FILE_SIZE)
            : self::BUNDLE_MAX_FILE_SIZE;

        // Batch resolve all share hashes in one SQL query.
        // Resolution is gated by the caller's entity + dol_check_secure_access_document
        // so an authenticated user cannot pull files they could not normally read
        // through the regular UI (CR-5 fix).
        $resolved = $this->resolveShareHashes($shares, $user, $entity);

        $included = [];
        $oversized = [];
        $remaining = $shareOverflow;
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

        // Create ZIP archive in a private per-request directory.
        // Using sys_get_temp_dir() with tempnam() is symlink-race-able when
        // /tmp is world-writable: an attacker who can create symlinks in
        // /tmp could redirect ZipArchive::OVERWRITE to overwrite arbitrary
        // files. We instead create a fresh dir
        // with mode 0700 and place the bundle inside it.
        $tmpDir = sys_get_temp_dir() . '/smartauth_bundle_' . bin2hex(random_bytes(16));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            dol_syslog('[SmartAuth] ObjectDocumentController::bundle - failed to create temp dir', LOG_ERR);
            return [['error' => 'Failed to create temp directory'], 500];
        }
        @chmod($tmpDir, 0700);
        $tmpFile = $tmpDir . '/bundle.zip';

        // Cleanup helper - called both on success and exception paths
        $cleanup = static function () use (&$tmpFile, &$tmpDir): void {
            if (is_string($tmpFile) && is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            if (is_string($tmpDir) && is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        };

        try {
            $zip = new \ZipArchive();
            // OVERWRITE on a path inside a freshly-created mode-0700 dir is
            // safe because we own the directory.
            if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $cleanup();
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
            $zip->setCompressionName('manifest.json', \ZipArchive::CM_STORE);

            foreach ($filesToAdd as $fileInfo) {
                $entryName = 'files/' . $fileInfo['share'];
                $zip->addFile($fileInfo['path'], $entryName);
                $zip->setCompressionName($entryName, \ZipArchive::CM_STORE);
            }

            $zip->close();

            $zipSize = filesize($tmpFile);
            $docCount = count($included);
            $overCount = count($oversized);
            $remCount = count($remaining);
            dol_syslog("[SmartAuth] ObjectDocumentController::bundle - ZIP: {$docCount} files, {$overCount} oversized, {$remCount} remaining, {$zipSize} bytes");

            if (is_object($db)) {
                $db->close();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="bundle.zip"');
            header('Content-Length: ' . $zipSize);
            header('Cache-Control: private, max-age=0, must-revalidate');

            readfileLowMemory($tmpFile);
            $cleanup();
            exit;
        } catch (\Throwable $e) {
            // Always remove the staging directory even if anything throws,
            // otherwise long-running servers leak temp files.
            $cleanup();
            dol_syslog('[SmartAuth] ObjectDocumentController::bundle - exception: ' . $e->getMessage(), LOG_ERR);
            return [['error' => 'Failed to build bundle'], 500];
        }
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
                dol_syslog("[SmartAuth] ObjectDocumentController::registerObjectType - Missing required key: $key for type: $type", LOG_WARNING);
                return false;
            }
        }

        self::$objectTypeConfig[$type] = $config;
        return true;
    }
}
