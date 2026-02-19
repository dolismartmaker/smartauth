<?php

/**
 * SyncController.php
 *
 * Offline synchronization controller for SmartAuth
 * Handles register, pull, push, and status endpoints for sync clients
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

use SmartAuth\Api\InputSanitizer;
use SmartAuth\Api\AuthController;

class SyncController
{
    /**
     * @var \DoliDB Database connection
     */
    private $db;

    /**
     * Mapping of object types to their configuration
     * Keys: object type names used in API
     * Values: configuration arrays with class, table, module info
     *
     * @var array
     */
    private $syncableObjects = [];

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->loadSyncableObjects();
    }

    /**
     * Load syncable objects configuration
     * Includes built-in objects and those registered via hooks
     */
    private function loadSyncableObjects()
    {
        global $hookmanager;

        // Built-in syncable objects (Phase 1: simple objects only)
        $this->syncableObjects = [
            'thirdparty' => [
                'class' => 'Societe',
                'file' => DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php',
                'table' => 'societe',
                'element' => 'societe',
                'label' => 'ThirdParties',
                'module' => 'societe',
                'priority' => 'high',
                'default_enabled' => true,
            ],
            'contact' => [
                'class' => 'Contact',
                'file' => DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php',
                'table' => 'socpeople',
                'element' => 'contact',
                'label' => 'Contacts',
                'module' => 'societe',
                'priority' => 'high',
                'default_enabled' => true,
            ],
            'product' => [
                'class' => 'Product',
                'file' => DOL_DOCUMENT_ROOT . '/product/class/product.class.php',
                'table' => 'product',
                'element' => 'product',
                'label' => 'Products',
                'module' => 'product',
                'priority' => 'medium',
                'default_enabled' => true,
            ],
            'category' => [
                'class' => 'Categorie',
                'file' => DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php',
                'table' => 'categorie',
                'element' => 'categorie',
                'label' => 'Categories',
                'module' => 'categorie',
                'priority' => 'low',
                'default_enabled' => true,
            ],
        ];

        // Load additional objects via hooks
        if (is_object($hookmanager)) {
            $parameters = [];
            $objects = [];
            $action = '';

            $hookmanager->initHooks(['smartmaker']);
            $reshook = $hookmanager->executeHooks(
                'smartmaker_registerSyncableObjects',
                $parameters,
                $objects,
                $action
            );

            if ($reshook >= 0 && is_array($objects) && !empty($objects)) {
                $this->syncableObjects = array_merge($this->syncableObjects, $objects);
            }
        }
    }

    /**
     * @api {post} /sync/register Register sync client
     * @apiName RegisterSyncClient
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Register a new sync client for offline synchronization.
     * The client UUID should be unique per device.
     *
     * @apiHeader {String} Authorization Bearer access_token
     * @apiHeader {String} X-DeviceId Device UUID
     *
     * @apiBody {String} client_uuid Unique client identifier (UUID format)
     * @apiBody {String} [app_version] Application version
     * @apiBody {String[]} [sync_scope] List of object types to sync (default: all enabled)
     *
     * @apiSuccess {Number} client_id Internal client ID
     * @apiSuccess {String} client_uuid Client UUID
     * @apiSuccess {String} server_time Current server timestamp
     * @apiSuccess {Object} sync_scope Enabled sync object types
     *
     * @apiSuccessExample {json} Success-Response:
     * HTTP/1.1 200 OK
     * {
     *     "client_id": 123,
     *     "client_uuid": "abc-123-def",
     *     "server_time": "2025-01-19T10:30:00+00:00",
     *     "sync_scope": {
     *         "thirdparty": true,
     *         "contact": true,
     *         "product": true
     *     }
     * }
     */
    public function register($payload)
    {
        dol_syslog("SyncController::register");

        // Validate required fields
        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required and must be a valid UUID'], 400];
        }

        $device_id = (int) ($payload['jwt_device_id'] ?? 0);
        if ($device_id <= 0) {
            return [['error' => 'Device ID not found in token'], 400];
        }

        $app_version = InputSanitizer::sanitizeAlphanumeric($payload['app_version'] ?? '', 32);

        // Determine sync scope
        $sync_scope = $this->determineSyncScope($payload['sync_scope'] ?? null);

        // Check if client already exists
        $sql = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients";
        $sql .= " WHERE client_uuid = '" . $this->db->escape($client_uuid) . "'";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            // Update existing client
            $existing = $this->db->fetch_object($resql);
            $client_id = $existing->rowid;

            $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_sync_clients SET";
            $sql .= " fk_device = " . (int) $device_id;
            $sql .= ", app_version = '" . $this->db->escape($app_version) . "'";
            $sql .= ", sync_scope = '" . $this->db->escape(json_encode($sync_scope)) . "'";
            $sql .= ", status = 1";
            $sql .= " WHERE rowid = " . (int) $client_id;

            $this->db->query($sql);
        } else {
            // Create new client
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_clients";
            $sql .= " (fk_device, client_uuid, app_version, sync_scope, date_creation, status)";
            $sql .= " VALUES (";
            $sql .= (int) $device_id . ", ";
            $sql .= "'" . $this->db->escape($client_uuid) . "', ";
            $sql .= "'" . $this->db->escape($app_version) . "', ";
            $sql .= "'" . $this->db->escape(json_encode($sync_scope)) . "', ";
            $sql .= "'" . $this->db->idate(dol_now()) . "', ";
            $sql .= "1)";

            if (!$this->db->query($sql)) {
                dol_syslog("SyncController::register - Insert failed: " . $this->db->lasterror(), LOG_ERR);
                return [['error' => 'Failed to register client'], 500];
            }

            $client_id = $this->db->last_insert_id(MAIN_DB_PREFIX . "smartauth_sync_clients");
        }

        // Log the event
        $this->logSyncEvent($client_id, 'register', null, null, [
            'app_version' => $app_version,
        ]);

        return [[
            'client_id' => $client_id,
            'client_uuid' => $client_uuid,
            'server_time' => date('c'),
            'sync_scope' => $sync_scope,
        ], 200];
    }

    /**
     * @api {get} /sync/pull Pull changes from server
     * @apiName PullChanges
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Get all changes since last sync for a specific object type.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiQuery {String} client_uuid Client UUID
     * @apiQuery {String} object_type Object type to pull (thirdparty, contact, product...)
     * @apiQuery {String} [last_sync_at] ISO timestamp of last sync (optional, uses stored value if not provided)
     *
     * @apiSuccess {Object[]} updated List of updated/created objects
     * @apiSuccess {Object[]} deleted List of deleted object IDs with timestamps
     * @apiSuccess {String} server_time Current server timestamp for next sync
     *
     * @apiSuccessExample {json} Success-Response:
     * HTTP/1.1 200 OK
     * {
     *     "updated": [
     *         {"id": 1, "name": "Company A", "tms": "2025-01-19T10:00:00+00:00"},
     *         {"id": 2, "name": "Company B", "tms": "2025-01-19T10:15:00+00:00"}
     *     ],
     *     "deleted": [
     *         {"id": 5, "deleted_at": "2025-01-19T09:00:00+00:00"}
     *     ],
     *     "server_time": "2025-01-19T10:30:00+00:00"
     * }
     */
    public function pull($payload)
    {
        dol_syslog("SyncController::pull");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $object_type = InputSanitizer::sanitizeAlphanumeric($payload['object_type'] ?? '', 64);
        if (empty($object_type) || !isset($this->syncableObjects[$object_type])) {
            return [['error' => 'Invalid or unsupported object_type'], 400];
        }

        // Get client info
        $client = $this->getClientByUUID($client_uuid);
        if (!$client) {
            return [['error' => 'Client not registered'], 404];
        }

        // Determine last sync timestamp
        $last_sync_at = null;
        if (!empty($payload['last_sync_at'])) {
            $last_sync_at = $payload['last_sync_at'];
        } elseif (!empty($client->last_sync_at)) {
            $last_sync_at = $client->last_sync_at;
        }

        $config = $this->syncableObjects[$object_type];
        $table = $config['table'];

        $result = [
            'updated' => [],
            'deleted' => [],
            'server_time' => date('c'),
        ];

        // Get updated records
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $table;
        $sql .= " WHERE entity IN (" . getEntity($config['module']) . ")";
        if ($last_sync_at) {
            $sql .= " AND tms > '" . $this->db->escape($last_sync_at) . "'";
        }
        $sql .= " ORDER BY tms ASC";
        $sql .= " LIMIT 1000"; // Pagination for large datasets

        $withFiles = !empty($payload['with_files']);

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $result['updated'][] = $this->formatObjectForSync($obj, $object_type, $withFiles);
            }
        }

        // Get tombstones (deleted records)
        $sql = "SELECT object_id, deleted_at FROM " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
        $sql .= " WHERE table_name = '" . $this->db->escape($table) . "'";
        if ($last_sync_at) {
            $sql .= " AND deleted_at > '" . $this->db->escape($last_sync_at) . "'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $result['deleted'][] = [
                    'id' => (int) $obj->object_id,
                    'deleted_at' => $obj->deleted_at,
                ];
            }
        }

        // Log the event
        $this->logSyncEvent($client->rowid, 'pull', $table, null, [
            'updated_count' => count($result['updated']),
            'deleted_count' => count($result['deleted']),
        ]);

        return [$result, 200];
    }

    /**
     * @api {post} /sync/push Push changes to server
     * @apiName PushChanges
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Push local changes to the server. Uses tms-based conflict detection.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiBody {String} client_uuid Client UUID
     * @apiBody {String} object_type Object type being pushed
     * @apiBody {Object[]} changes Array of changes to push
     * @apiBody {Number} changes.id Object ID (0 for new objects)
     * @apiBody {String} changes.action Action: create, update, delete
     * @apiBody {Object} changes.data Object data
     * @apiBody {String} changes.base_tms Base tms when client fetched the object
     *
     * @apiSuccess {Number[]} success IDs of successfully applied changes
     * @apiSuccess {Object[]} conflicts Changes that resulted in conflicts
     * @apiSuccess {Object[]} errors Changes that failed
     * @apiSuccess {Object} id_mapping Mapping of temp_id to server_id for creates
     * @apiSuccess {String} server_time Current server timestamp
     */
    public function push($payload)
    {
        global $user;
        dol_syslog("SyncController::push");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $object_type = InputSanitizer::sanitizeAlphanumeric($payload['object_type'] ?? '', 64);
        if (empty($object_type) || !isset($this->syncableObjects[$object_type])) {
            return [['error' => 'Invalid or unsupported object_type'], 400];
        }

        $changes = $payload['changes'] ?? [];
        if (!is_array($changes) || empty($changes)) {
            return [['error' => 'changes array is required and must not be empty'], 400];
        }

        // Get client info
        $client = $this->getClientByUUID($client_uuid);
        if (!$client) {
            return [['error' => 'Client not registered'], 404];
        }

        $config = $this->syncableObjects[$object_type];

        $result = [
            'success' => [],
            'conflicts' => [],
            'errors' => [],
            'id_mapping' => [],
            'server_time' => date('c'),
        ];

        // Process changes
        foreach ($changes as $change) {
            $action = $change['action'] ?? '';
            $id = (int) ($change['id'] ?? 0);
            $data = $change['data'] ?? [];
            $base_tms = $change['base_tms'] ?? null;
            $temp_id = $change['temp_id'] ?? null;

            try {
                switch ($action) {
                    case 'create':
                        $createResult = $this->processCreate($config, $data, $user);
                        if ($createResult['success']) {
                            $result['success'][] = $createResult['id'];
                            if ($temp_id) {
                                $result['id_mapping'][$temp_id] = $createResult['id'];
                            }
                        } else {
                            $result['errors'][] = [
                                'temp_id' => $temp_id,
                                'error' => $createResult['error'],
                            ];
                        }
                        break;

                    case 'update':
                        $updateResult = $this->processUpdate($config, $id, $data, $base_tms, $client->rowid, $user);
                        if ($updateResult['success']) {
                            $result['success'][] = $id;
                        } elseif ($updateResult['conflict']) {
                            $result['conflicts'][] = $updateResult['conflict'];
                        } else {
                            $result['errors'][] = [
                                'id' => $id,
                                'error' => $updateResult['error'],
                            ];
                        }
                        break;

                    case 'delete':
                        $deleteResult = $this->processDelete($config, $id, $base_tms, $user);
                        if ($deleteResult['success']) {
                            $result['success'][] = $id;
                        } else {
                            $result['errors'][] = [
                                'id' => $id,
                                'error' => $deleteResult['error'],
                            ];
                        }
                        break;

                    default:
                        $result['errors'][] = [
                            'id' => $id,
                            'error' => 'Unknown action: ' . $action,
                        ];
                }
            } catch (\Exception $e) {
                dol_syslog("SyncController::push - Exception: " . $e->getMessage(), LOG_ERR);
                $result['errors'][] = [
                    'id' => $id ?: $temp_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Update last sync timestamp if any success
        if (!empty($result['success'])) {
            $this->updateClientSyncTimestamp($client->rowid);
        }

        // Log the event
        $this->logSyncEvent($client->rowid, 'push', $config['table'], null, [
            'success_count' => count($result['success']),
            'conflict_count' => count($result['conflicts']),
            'error_count' => count($result['errors']),
        ]);

        return [$result, 200];
    }

    /**
     * @api {get} /sync/status Get sync status
     * @apiName SyncStatus
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Get synchronization status for a client.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiQuery {String} client_uuid Client UUID
     *
     * @apiSuccess {String} client_uuid Client UUID
     * @apiSuccess {String} last_sync_at Last successful sync timestamp
     * @apiSuccess {Number} pending_conflicts Number of unresolved conflicts
     * @apiSuccess {String} server_time Current server time
     * @apiSuccess {Object} sync_scope Enabled sync types
     */
    public function status($payload)
    {
        dol_syslog("SyncController::status");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $client = $this->getClientByUUID($client_uuid);
        if (!$client) {
            return [['error' => 'Client not registered'], 404];
        }

        // Count pending conflicts
        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " WHERE fk_client = " . (int) $client->rowid;
        $sql .= " AND status = 'pending'";

        $pending_conflicts = 0;
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $pending_conflicts = (int) $obj->nb;
        }

        $sync_scope = json_decode($client->sync_scope, true) ?: [];

        return [[
            'client_uuid' => $client_uuid,
            'last_sync_at' => $client->last_sync_at,
            'pending_conflicts' => $pending_conflicts,
            'server_time' => date('c'),
            'sync_scope' => $sync_scope,
        ], 200];
    }

    /**
     * @api {get} /sync/conflicts List pending conflicts
     * @apiName ListConflicts
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Get list of unresolved conflicts for a client.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiQuery {String} client_uuid Client UUID
     *
     * @apiSuccess {Object[]} conflicts List of pending conflicts
     */
    public function conflicts($payload)
    {
        dol_syslog("SyncController::conflicts");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $client = $this->getClientByUUID($client_uuid);
        if (!$client) {
            return [['error' => 'Client not registered'], 404];
        }

        $conflicts = [];
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " WHERE fk_client = " . (int) $client->rowid;
        $sql .= " AND status = 'pending'";
        $sql .= " ORDER BY date_creation DESC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $conflicts[] = [
                    'id' => (int) $obj->rowid,
                    'table_name' => $obj->table_name,
                    'object_id' => (int) $obj->object_id,
                    'client_data' => json_decode($obj->client_data, true),
                    'server_data' => json_decode($obj->server_data, true),
                    'client_tms' => $obj->client_tms,
                    'server_tms' => $obj->server_tms,
                    'field_conflicts' => json_decode($obj->field_conflicts, true),
                    'date_creation' => $obj->date_creation,
                ];
            }
        }

        return [['conflicts' => $conflicts, 'server_time' => date('c')], 200];
    }

    /**
     * @api {post} /sync/conflicts/{id}/resolve Resolve a conflict
     * @apiName ResolveConflict
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Resolve a sync conflict.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiParam {Number} id Conflict ID
     *
     * @apiBody {String} resolution Resolution strategy: client, server, or merged
     * @apiBody {Object} [data] Merged data (required if resolution=merged)
     *
     * @apiSuccess {Boolean} success Whether resolution was applied
     * @apiSuccess {String} message Result message
     */
    public function resolveConflict($payload)
    {
        global $user;
        dol_syslog("SyncController::resolveConflict");

        $conflict_id = (int) ($payload['id'] ?? 0);
        if ($conflict_id <= 0) {
            return [['error' => 'Conflict ID is required'], 400];
        }

        $resolution = InputSanitizer::sanitizeAlphanumeric($payload['resolution'] ?? '', 16);
        if (!in_array($resolution, ['client', 'server', 'merged'])) {
            return [['error' => 'Invalid resolution. Must be: client, server, or merged'], 400];
        }

        // Fetch the conflict
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " WHERE rowid = " . (int) $conflict_id;
        $sql .= " AND status = 'pending'";

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            return [['error' => 'Conflict not found or already resolved'], 404];
        }

        $conflict = $this->db->fetch_object($resql);

        // Determine final data based on resolution
        $final_data = null;
        switch ($resolution) {
            case 'client':
                $final_data = json_decode($conflict->client_data, true);
                break;
            case 'server':
                $final_data = json_decode($conflict->server_data, true);
                break;
            case 'merged':
                $final_data = $payload['data'] ?? null;
                if (empty($final_data)) {
                    return [['error' => 'Merged data is required for merged resolution'], 400];
                }
                break;
        }

        // Apply the resolution
        $object_type = $this->getObjectTypeFromTable($conflict->table_name);
        if (!$object_type) {
            return [['error' => 'Unknown table type'], 500];
        }

        $config = $this->syncableObjects[$object_type];
        $applyResult = $this->applyResolvedData($config, (int) $conflict->object_id, $final_data, $user);

        if (!$applyResult['success']) {
            return [['error' => 'Failed to apply resolution: ' . $applyResult['error']], 500];
        }

        // Update conflict status
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_sync_conflicts SET";
        $sql .= " status = 'resolved'";
        $sql .= ", resolution = '" . $this->db->escape($resolution) . "'";
        $sql .= ", resolved_data = '" . $this->db->escape(json_encode($final_data)) . "'";
        $sql .= ", resolved_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= ", resolved_by = " . (int) $user->id;
        $sql .= " WHERE rowid = " . (int) $conflict_id;

        $this->db->query($sql);

        // Log the event
        $this->logSyncEvent($conflict->fk_client, 'resolve', $conflict->table_name, $conflict->object_id, [
            'resolution' => $resolution,
        ]);

        return [[
            'success' => true,
            'message' => 'Conflict resolved successfully',
        ], 200];
    }

    // =====================================================================
    // Private helper methods
    // =====================================================================

    /**
     * Determine sync scope from request or defaults
     */
    private function determineSyncScope($requested_scope)
    {
        $scope = [];

        foreach ($this->syncableObjects as $type => $config) {
            // If scope was specified, use it; otherwise use default
            if (is_array($requested_scope)) {
                $scope[$type] = in_array($type, $requested_scope);
            } else {
                $scope[$type] = $config['default_enabled'] ?? false;
            }
        }

        return $scope;
    }

    /**
     * Get client by UUID
     */
    private function getClientByUUID($uuid)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients";
        $sql .= " WHERE client_uuid = '" . $this->db->escape($uuid) . "'";
        $sql .= " AND status = 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return $this->db->fetch_object($resql);
        }
        return null;
    }

    /**
     * Format object for sync response
     */
    private function formatObjectForSync($obj, $object_type, $withFiles = false)
    {
        // Convert to array and include tms
        $data = (array) $obj;

        // Ensure tms is in ISO format
        if (isset($data['tms'])) {
            $data['tms'] = date('c', strtotime($data['tms']));
        }

        // Rename rowid to id for consistency
        if (isset($data['rowid'])) {
            $data['id'] = (int) $data['rowid'];
            unset($data['rowid']);
        }

        // Add linked files count from ECM
        $config = $this->syncableObjects[$object_type] ?? [];
        $element = $config['element'] ?? '';
        $objectId = $data['id'] ?? 0;
        if (!empty($element) && $objectId > 0) {
            if ($withFiles) {
                $files = $this->fetchLinkedFiles($objectId, $element);
                $data['nb_linked_files'] = count($files);
                $data['linked_files'] = $files;
            } else {
                $data['nb_linked_files'] = $this->countLinkedFiles($objectId, $element);
            }
        }

        return $data;
    }

    /**
     * Process a CREATE operation
     */
    private function processCreate($config, $data, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        // Map data to object properties
        foreach ($data as $key => $value) {
            if (property_exists($object, $key)) {
                $object->$key = $value;
            }
        }

        $result = $object->create($user);
        if ($result > 0) {
            return ['success' => true, 'id' => $result];
        }

        return ['success' => false, 'error' => $object->error ?: 'Create failed'];
    }

    /**
     * Process an UPDATE operation with conflict detection
     */
    private function processUpdate($config, $id, $data, $base_tms, $client_id, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        // Fetch current object with lock
        $this->db->begin();

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $config['table'];
        $sql .= " WHERE rowid = " . (int) $id;
        $sql .= " FOR UPDATE";

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Object not found'];
        }

        $server_obj = $this->db->fetch_object($resql);
        $server_tms = $server_obj->tms;

        // Conflict detection: compare tms
        if ($base_tms && $server_tms != $base_tms) {
            // Potential conflict - compare data field by field
            $conflict = $this->detectRealConflict($data, $server_obj, $config);

            if ($conflict) {
                // Real conflict - create conflict record
                $this->createConflictRecord($client_id, $config['table'], $id, $data, $server_obj, $base_tms, $server_tms, $conflict);
                $this->db->rollback();
                return [
                    'success' => false,
                    'conflict' => [
                        'object_id' => $id,
                        'client_tms' => $base_tms,
                        'server_tms' => $server_tms,
                        'field_conflicts' => $conflict,
                    ],
                ];
            }
            // False conflict - tms differs but data is same, proceed
        }

        // Apply update
        $object->fetch($id);
        foreach ($data as $key => $value) {
            if (property_exists($object, $key) && !in_array($key, ['rowid', 'id', 'tms'])) {
                $object->$key = $value;
            }
        }

        $result = $object->update($user);
        if ($result > 0) {
            $this->db->commit();
            return ['success' => true];
        }

        $this->db->rollback();
        return ['success' => false, 'error' => $object->error ?: 'Update failed'];
    }

    /**
     * Detect if there's a real data conflict (not just tms mismatch)
     * Returns array of conflicting fields or null if no real conflict
     */
    private function detectRealConflict($client_data, $server_obj, $config)
    {
        $conflicts = [];
        $server_data = (array) $server_obj;

        foreach ($client_data as $field => $client_value) {
            // Skip metadata fields
            if (in_array($field, ['rowid', 'id', 'tms', 'date_creation', 'date_modification'])) {
                continue;
            }

            if (isset($server_data[$field])) {
                $server_value = $server_data[$field];

                // Normalize values for comparison
                $client_normalized = $this->normalizeValue($client_value);
                $server_normalized = $this->normalizeValue($server_value);

                if ($client_normalized !== $server_normalized) {
                    $conflicts[$field] = [
                        'client' => $client_value,
                        'server' => $server_value,
                    ];
                }
            }
        }

        return empty($conflicts) ? null : $conflicts;
    }

    /**
     * Normalize value for comparison
     */
    private function normalizeValue($value)
    {
        if (is_null($value) || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    /**
     * Create a conflict record in the database
     */
    private function createConflictRecord($client_id, $table, $object_id, $client_data, $server_obj, $client_tms, $server_tms, $field_conflicts)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, field_conflicts, status, date_creation)";
        $sql .= " VALUES (";
        $sql .= (int) $client_id . ", ";
        $sql .= "'" . $this->db->escape($table) . "', ";
        $sql .= (int) $object_id . ", ";
        $sql .= "'" . $this->db->escape(json_encode($client_data)) . "', ";
        $sql .= "'" . $this->db->escape(json_encode((array) $server_obj)) . "', ";
        $sql .= "'" . $this->db->escape($client_tms) . "', ";
        $sql .= "'" . $this->db->escape($server_tms) . "', ";
        $sql .= "'" . $this->db->escape(json_encode($field_conflicts)) . "', ";
        $sql .= "'pending', ";
        $sql .= "'" . $this->db->idate(dol_now()) . "')";

        $this->db->query($sql);
    }

    /**
     * Process a DELETE operation
     */
    private function processDelete($config, $id, $base_tms, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        $result = $object->fetch($id);
        if ($result <= 0) {
            return ['success' => false, 'error' => 'Object not found'];
        }

        // Create tombstone before delete
        $this->createTombstone($config['table'], $id, $user->id);

        $result = $object->delete($user);
        if ($result > 0) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $object->error ?: 'Delete failed'];
    }

    /**
     * Create a tombstone record for a deleted object
     */
    private function createTombstone($table, $object_id, $user_id)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
        $sql .= " (table_name, object_id, deleted_at, deleted_by)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($table) . "', ";
        $sql .= (int) $object_id . ", ";
        $sql .= "'" . $this->db->idate(dol_now()) . "', ";
        $sql .= (int) $user_id . ")";

        $this->db->query($sql);
    }

    /**
     * Apply resolved conflict data to database
     */
    private function applyResolvedData($config, $id, $data, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        $result = $object->fetch($id);
        if ($result <= 0) {
            return ['success' => false, 'error' => 'Object not found'];
        }

        foreach ($data as $key => $value) {
            if (property_exists($object, $key) && !in_array($key, ['rowid', 'id', 'tms'])) {
                $object->$key = $value;
            }
        }

        // Dolibarr classes have different update() signatures:
        // - Societe, Product, Contact: update($id, $user, ...)
        // - User, Facture: update($user, ...)
        // Use reflection to detect the correct signature
        $result = $this->callUpdateMethod($object, $user);
        if ($result > 0) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $object->error ?: 'Update failed'];
    }

    /**
     * Call the update method with the correct signature using reflection
     *
     * Dolibarr classes have different update() signatures:
     * - Societe:  update($id, $user = '', $call_trigger = 1, ...)
     * - Product:  update($id, $user, $notrigger = false, ...)
     * - Contact:  update($id, $user = null, $notrigger = 0, ...)
     * - User:     update($user, $notrigger = 0, ...)
     * - Facture:  update(User $user, $notrigger = 0)
     *
     * @param object $object The Dolibarr object to update
     * @param User $user The user performing the update
     * @return int Result of the update operation
     */
    private function callUpdateMethod($object, $user)
    {
        $reflection = new \ReflectionMethod($object, 'update');
        $params = $reflection->getParameters();

        if (empty($params)) {
            return $object->update();
        }

        // Analyze first parameter to detect signature type
        $firstParam = $params[0];
        $firstParamName = $firstParam->getName();
        $firstParamType = $firstParam->getType();

        $isIdFirst = ($firstParamName === 'id')
            || ($firstParamType && in_array($firstParamType->getName(), ['int', 'integer']));

        // Analyze trigger parameter (2nd for user-first, 3rd for id-first)
        $triggerParamIndex = $isIdFirst ? 2 : 1;
        $triggerParam = $params[$triggerParamIndex] ?? null;

        // Determine trigger value: we want triggers enabled
        // - $call_trigger: 1 = enabled (Societe)
        // - $notrigger: 0 = enabled, false = enabled (Product, Contact, User, Facture)
        $triggerValue = null;
        if ($triggerParam) {
            $triggerParamName = $triggerParam->getName();
            if ($triggerParamName === 'call_trigger') {
                $triggerValue = 1; // Enable trigger
            } elseif (in_array($triggerParamName, ['notrigger', 'noTrigger'])) {
                $triggerValue = 0; // Enable trigger (notrigger=0 means triggers ARE called)
            }
        }

        // Call with appropriate signature
        if ($isIdFirst) {
            // Signature: update($id, $user, $trigger?, ...)
            if ($triggerValue !== null) {
                return $object->update($object->id, $user, $triggerValue);
            }
            return $object->update($object->id, $user);
        } else {
            // Signature: update($user, $trigger?, ...)
            if ($triggerValue !== null) {
                return $object->update($user, $triggerValue);
            }
            return $object->update($user);
        }
    }

    /**
     * Get object type from table name
     */
    private function getObjectTypeFromTable($table)
    {
        foreach ($this->syncableObjects as $type => $config) {
            if ($config['table'] === $table) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Update client's last sync timestamp
     */
    private function updateClientSyncTimestamp($client_id)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_sync_clients";
        $sql .= " SET last_sync_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE rowid = " . (int) $client_id;

        $this->db->query($sql);
    }

    /**
     * Log a sync event for audit
     */
    private function logSyncEvent($client_id, $event_type, $table_name = null, $object_id = null, $event_data = null)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_events";
        $sql .= " (fk_client, event_type, table_name, object_id, event_data, date_creation)";
        $sql .= " VALUES (";
        $sql .= (int) $client_id . ", ";
        $sql .= "'" . $this->db->escape($event_type) . "', ";
        $sql .= ($table_name ? "'" . $this->db->escape($table_name) . "'" : "NULL") . ", ";
        $sql .= ($object_id ? (int) $object_id : "NULL") . ", ";
        $sql .= ($event_data ? "'" . $this->db->escape(json_encode($event_data)) . "'" : "NULL") . ", ";
        $sql .= "'" . $this->db->idate(dol_now()) . "')";

        $this->db->query($sql);
    }

    /**
     * Count files linked to an object via ECM
     *
     * @param int    $objectId Object ID
     * @param string $element  Dolibarr table_element value
     * @return int
     */
    private function countLinkedFiles($objectId, $element)
    {
        global $conf;

        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE src_object_type = '" . $this->db->escape($element) . "'";
        $sql .= " AND src_object_id = " . (int) $objectId;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if ($resql && $row = $this->db->fetch_object($resql)) {
            return (int) $row->nb;
        }
        return 0;
    }

    /**
     * Fetch linked files metadata from ECM
     *
     * @param int    $objectId Object ID
     * @param string $element  Dolibarr table_element value
     * @return array
     */
    private function fetchLinkedFiles($objectId, $element)
    {
        global $conf;

        $files = [];
        $sql = "SELECT rowid, filename, filepath, date_c, gen_or_uploaded, share, description, keywords";
        $sql .= " FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE src_object_type = '" . $this->db->escape($element) . "'";
        $sql .= " AND src_object_id = " . (int) $objectId;
        $sql .= " AND entity = " . (int) $conf->entity;
        $sql .= " ORDER BY position ASC, date_c ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($fileObj = $this->db->fetch_object($resql)) {
                $file = [
                    'id' => (int) $fileObj->rowid,
                    'filename' => $fileObj->filename,
                    'path' => $fileObj->filepath,
                    'date' => $fileObj->date_c,
                    'type' => $fileObj->gen_or_uploaded,
                    'share' => $fileObj->share ?: null,
                ];
                if (!empty($fileObj->description)) {
                    $file['description'] = $fileObj->description;
                }
                if (!empty($fileObj->keywords)) {
                    $file['keywords'] = $fileObj->keywords;
                }
                $files[] = $file;
            }
            $this->db->free($resql);
        }

        return $files;
    }
}
