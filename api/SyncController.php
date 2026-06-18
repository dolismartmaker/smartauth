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

        // Built-in syncable objects (Phase 1: simple objects only).
        // The 'allowed_fields' key whitelists which payload keys may be
        // copied onto the Dolibarr object - any other key (and any key from
        // the universal denylist below) is rejected. See
        // applyDataToObject() and CR-6 of TODO-SECURITY-01.
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
                // Dolibarr permission required per write action. Arguments are
                // forwarded as-is to User::hasRight($module, $perm1[, $perm2]).
                // push() refuses any action whose right is not granted (CR: BFLA fix).
                'rights' => [
                    'read'   => ['societe', 'lire'],
                    'create' => ['societe', 'creer'],
                    'update' => ['societe', 'creer'],
                    'delete' => ['societe', 'supprimer'],
                ],
                'allowed_fields' => [
                    'name', 'name_alias',
                    'email', 'phone', 'fax', 'url',
                    'address', 'zip', 'town', 'country_id', 'state_id',
                    'client', 'fournisseur',
                    'code_client', 'code_fournisseur',
                    'note_public', 'note_private',
                    'siren', 'siret', 'ape',
                    'idprof4', 'idprof5', 'idprof6',
                    'capital', 'tva_assuj', 'tva_intra',
                    'gencod', 'barcode',
                    'effectif_id', 'forme_juridique_code', 'typent_id',
                    'outstanding_limit',
                    'mode_reglement_id', 'cond_reglement_id',
                    'status',
                ],
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
                // Contacts use the societe->contact sub-permission.
                'rights' => [
                    'read'   => ['societe', 'contact', 'lire'],
                    'create' => ['societe', 'contact', 'creer'],
                    'update' => ['societe', 'contact', 'creer'],
                    'delete' => ['societe', 'contact', 'supprimer'],
                ],
                'allowed_fields' => [
                    'lastname', 'firstname', 'civility_id',
                    'address', 'zip', 'town', 'country_id',
                    'email', 'phone_pro', 'phone_mobile', 'phone_perso', 'fax',
                    'fk_soc', 'socid',
                    'no_email',
                    'note_public', 'note_private',
                    'poste', 'birthday',
                ],
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
                // Product permissions live under the 'produit' rights class.
                'rights' => [
                    'read'   => ['produit', 'lire'],
                    'create' => ['produit', 'creer'],
                    'update' => ['produit', 'creer'],
                    'delete' => ['produit', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref', 'label', 'description',
                    'status', 'status_buy', 'status_batch',
                    'finished', 'type',
                    'customcode', 'country_id',
                    'weight', 'weight_units',
                    'length', 'length_units',
                    'surface', 'surface_units',
                    'volume', 'volume_units',
                    'price', 'price_ttc',
                    'price_min', 'price_min_ttc',
                    'price_label',
                    'tva_tx', 'barcode',
                    'note_public', 'note_private',
                ],
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
                'rights' => [
                    'read'   => ['categorie', 'lire'],
                    'create' => ['categorie', 'creer'],
                    'update' => ['categorie', 'creer'],
                    'delete' => ['categorie', 'supprimer'],
                ],
                'allowed_fields' => [
                    'label', 'description', 'color', 'type', 'fk_parent',
                ],
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

        // Self-stamp the object_type key onto each config so downstream
        // helpers (mapper resolution, FK validation) can recover the
        // type from a $config alone without having to thread an extra
        // parameter through every call site.
        foreach ($this->syncableObjects as $type => &$cfg) {
            if (is_array($cfg)) {
                $cfg['object_type'] = $type;
            }
        }
        unset($cfg);
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
        dol_syslog("[SmartAuth] SyncController::register");

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
                dol_syslog("[SmartAuth] SyncController::register - Insert failed: " . $this->db->lasterror(), LOG_ERR);
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
        global $user;
        dol_syslog("[SmartAuth] SyncController::pull");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $object_type = InputSanitizer::sanitizeAlphanumeric($payload['object_type'] ?? '', 64);
        if (empty($object_type) || !isset($this->syncableObjects[$object_type])) {
            return [['error' => 'Invalid or unsupported object_type'], 400];
        }

        // Get client info
        $client = $this->getClientByUUID($client_uuid, $this->payloadUserId($payload));
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

        // Same fail-closed permission gate as writes: a valid JWT is not enough,
        // the authenticated user must hold the object's read right. Without this
        // any token could pull the whole entity's records. userHasSyncRight logs
        // the denial reason.
        if (!$this->userHasSyncRight($config, 'read', $user)) {
            return [['error' => 'Permission denied'], 403];
        }

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
        dol_syslog("[SmartAuth] SyncController::push");

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
        $client = $this->getClientByUUID($client_uuid, $this->payloadUserId($payload));
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
                        if (!$this->userHasSyncRight($config, 'create', $user)) {
                            $result['errors'][] = [
                                'temp_id' => $temp_id,
                                'error' => 'Permission denied',
                            ];
                            break;
                        }
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
                        if (!$this->userHasSyncRight($config, 'update', $user)) {
                            $result['errors'][] = [
                                'id' => $id,
                                'error' => 'Permission denied',
                            ];
                            break;
                        }
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
                        if (!$this->userHasSyncRight($config, 'delete', $user)) {
                            $result['errors'][] = [
                                'id' => $id,
                                'error' => 'Permission denied',
                            ];
                            break;
                        }
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
                dol_syslog("[SmartAuth] SyncController::push - Exception: " . $e->getMessage(), LOG_ERR);
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
        dol_syslog("[SmartAuth] SyncController::status");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $client = $this->getClientByUUID($client_uuid, $this->payloadUserId($payload));
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
        dol_syslog("[SmartAuth] SyncController::conflicts");

        $client_uuid = InputSanitizer::sanitizeUUID($payload['client_uuid'] ?? '');
        if (empty($client_uuid)) {
            return [['error' => 'client_uuid is required'], 400];
        }

        $client = $this->getClientByUUID($client_uuid, $this->payloadUserId($payload));
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
        dol_syslog("[SmartAuth] SyncController::resolveConflict");

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
     * Get client by UUID, restricted to a given Dolibarr user.
     *
     * The fk_device link is joined with smartauth_devices to verify that
     * the device behind this sync client belongs to $userId. Without this
     * scope, any authenticated user who guessed (or harvested) another
     * user's client_uuid could pull/push their data (M-11).
     *
     * @param string $uuid Sync client UUID
     * @param int $userId Authenticated Dolibarr user id (mandatory)
     * @return object|null Sync client row, or null if not found / not owned
     */
    private function getClientByUUID($uuid, int $userId = 0)
    {
        if ($userId <= 0) {
            dol_syslog('[SmartAuth] SyncController::getClientByUUID called without userId - rejecting (M-11)', LOG_WARNING);
            return null;
        }

        // smartauth_devices.fk_user_creat is the column linking a device
        // to its owning Dolibarr user.
        $sql = "SELECT sc.* FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients sc";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_devices sd ON sc.fk_device = sd.rowid";
        $sql .= " WHERE sc.client_uuid = '" . $this->db->escape($uuid) . "'";
        $sql .= " AND sc.status = 1";
        $sql .= " AND sd.fk_user_creat = " . $userId;

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return $this->db->fetch_object($resql);
        }
        return null;
    }

    /**
     * Resolve the authenticated Dolibarr user id from the route payload.
     *
     * Resolution order:
     *   1. $payload['user']->id      (RouteController convention)
     *   2. $payload['user_id']
     *   3. fk_user resolved from $payload['jwt_device_id'] (also injected
     *      by RouteController after JWT validation)
     */
    private function payloadUserId(array $payload): int
    {
        if (!empty($payload['user']) && is_object($payload['user']) && !empty($payload['user']->id)) {
            return (int) $payload['user']->id;
        }
        $direct = (int) ($payload['user_id'] ?? 0);
        if ($direct > 0) {
            return $direct;
        }
        $deviceId = (int) ($payload['jwt_device_id'] ?? 0);
        if ($deviceId > 0) {
            $sql = "SELECT fk_user_creat FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE rowid = " . $deviceId;
            $resql = $this->db->query($sql);
            if ($resql && ($row = $this->db->fetch_object($resql))) {
                return (int) $row->fk_user_creat;
            }
        }
        return 0;
    }

    /**
     * Format object for sync response.
     *
     * Routes the raw SELECT * row through the matching dm* mapper so that
     * the API response only carries declared, named fields (Invariant I-1
     * in documentation/SPEC_SMARTAUTH_AUTHORIZATION.md section 8.2).
     *
     * The pull() SQL gives us a raw row (SQL column names, no fetch()
     * post-processing). We re-hydrate through the Dolibarr class so the
     * mapper sees the PHP property names it expects (eg Product::fetch
     * renames the SQL columns tosell/tobuy into $status/$status_buy).
     *
     * The per-object 'tms' field is preserved post-mapping because the
     * front (smartcommon SyncEngine.js) snapshots it as base_tms for the
     * conflict-detection branch of the next push. It is taken from the
     * raw row directly (the SELECT * already pulled it), independently
     * of whether the Dolibarr fetch() populates it on the PHP object.
     *
     * Performance note: this re-hydrates the object via fetch() once per
     * row, adding one SELECT per item. For a paginated pull of 1000
     * items that adds ~1000 queries. Acceptable for the current scope;
     * a future optimisation could batch-fetch or feed the mapper a
     * fetched-like object directly from the raw row.
     */
    private function formatObjectForSync($obj, $object_type, $withFiles = false)
    {
        $config = $this->syncableObjects[$object_type] ?? [];
        $rowid = isset($obj->rowid) ? (int) $obj->rowid : 0;
        $rawTms = $obj->tms ?? null;

        $data = $this->mapObjectThroughMapper($obj, $object_type, $config, $rowid);

        // Inject tms per-object in ISO format. SyncEngine.js snapshots
        // this value as base_tms for conflict detection on the next
        // push. Always sourced from the raw SELECT row, independent of
        // whether the Dolibarr class's fetch() populated $obj->tms on
        // the PHP object.
        if (!empty($rawTms)) {
            $data['tms'] = date('c', strtotime($rawTms));
        }

        // Defensive: the raw fallback path may not set 'id'.
        $objectId = (int) ($data['id'] ?? 0);
        if ($objectId === 0 && $rowid > 0) {
            $data['id'] = $rowid;
            $objectId = $rowid;
        }

        // Add linked files count from ECM
        $element = $config['element'] ?? '';
        if (!empty($element) && $objectId > 0) {
            if ($withFiles) {
                $files = $this->fetchLinkedFiles($objectId, $element);
                $data['nb_linked_files'] = count($files);
                $data['linked_files'] = $files;
            } else {
                $data['nb_linked_files'] = $this->countLinkedFiles($objectId, $element);
            }
        }

        // Add categories for all object types that support them
        if (!empty($element) && $objectId > 0) {
            $categories = $this->getObjectCategories($objectId, $element);
            if (!empty($categories)) {
                $data['categories'] = $categories;
            }
        }
        return $data;
    }

    /**
     * Map a raw SQL row through the dm* mapper for the given object
     * type, falling back to a raw (array) cast when no mapper is
     * registered or when the Dolibarr re-fetch fails. Every fallback
     * path logs a warning so production telemetry surfaces the gap.
     *
     * @param object $obj         Raw row from SELECT * (stdClass)
     * @param string $object_type Sync object type key
     * @param array  $config      Entry from $this->syncableObjects
     * @param int    $rowid       Resolved rowid from the raw row
     * @return array              Payload array (id renamed from rowid)
     */
    private function mapObjectThroughMapper($obj, $object_type, array $config, $rowid)
    {
        $mapperClass = $this->resolveMapperClass($object_type);
        if ($mapperClass === null || !class_exists($mapperClass) || $rowid <= 0) {
            dol_syslog(
                "[SmartAuth] SyncController::mapObjectThroughMapper: no dm* "
                . "mapper for object_type='" . $object_type . "', falling "
                . "back to raw (array) cast. Invariant I-1 not enforced "
                . "for this type.",
                LOG_WARNING
            );
            return $this->rawCastFallback($obj);
        }

        $doliClass = $config['class'] ?? '';
        $doliFile = $config['file'] ?? '';
        if (empty($doliClass) || empty($doliFile)) {
            dol_syslog(
                "[SmartAuth] SyncController::mapObjectThroughMapper: missing "
                . "'class' or 'file' in syncableObjects['" . $object_type
                . "'], falling back to raw cast.",
                LOG_WARNING
            );
            return $this->rawCastFallback($obj);
        }

        if (!class_exists($doliClass) && file_exists($doliFile)) {
            require_once $doliFile;
        }
        if (!class_exists($doliClass)) {
            dol_syslog(
                "[SmartAuth] SyncController::mapObjectThroughMapper: Dolibarr "
                . "class " . $doliClass . " could not be loaded (file="
                . $doliFile . "), falling back to raw cast.",
                LOG_WARNING
            );
            return $this->rawCastFallback($obj);
        }

        $fresh = new $doliClass($this->db);
        $fetchResult = $fresh->fetch($rowid);
        if ($fetchResult <= 0) {
            dol_syslog(
                "[SmartAuth] SyncController::mapObjectThroughMapper: fetch "
                . "failed for " . $doliClass . " id=" . $rowid . " ("
                . ($fresh->error ?? 'no error message') . "), falling back "
                . "to raw cast.",
                LOG_WARNING
            );
            return $this->rawCastFallback($obj);
        }

        $mapper = new $mapperClass();
        $exported = $mapper->exportMappedData($fresh);
        return (array) $exported;
    }

    /**
     * Legacy raw cast path: leaks Dolibarr internal fields. Used only
     * when the mapper path cannot run (no mapper registered, class
     * missing, fetch failed). Each call site logs a LOG_WARNING.
     *
     * @param object $obj Raw row from SELECT *
     * @return array     {id: int, ...other SQL columns}
     */
    private function rawCastFallback($obj)
    {
        $data = (array) $obj;
        if (isset($data['rowid'])) {
            $data['id'] = (int) $data['rowid'];
            unset($data['rowid']);
        }
        return $data;
    }

    /**
     * Resolve the fully qualified dm* mapper class name for a given
     * object_type. Returns null when no mapper is registered (the caller
     * then falls back to the raw cast path and logs a warning).
     *
     * Built-in mappings only for now. If a future module registers a
     * custom object_type via the smartmaker_registerSyncableObjects
     * hook, this resolver can be extended to read a 'mapper' key from
     * the $syncableObjects config -- the hook payload already supports
     * arbitrary keys so the contract extension is non-breaking.
     *
     * @param string $object_type Sync object type key
     * @return string|null        Fully qualified mapper class name
     */
    private function resolveMapperClass($object_type)
    {
        $map = [
            'thirdparty' => '\\SmartAuth\\DolibarrMapping\\dmThirdparty',
            'contact'    => '\\SmartAuth\\DolibarrMapping\\dmContact',
            'product'    => '\\SmartAuth\\DolibarrMapping\\dmProduct',
            'category'   => '\\SmartAuth\\DolibarrMapping\\dmCategory',
        ];
        if (isset($map[$object_type])) {
            return $map[$object_type];
        }
        // Honour an explicit mapper class declared by a hook-registered
        // syncable object: $syncableObjects['xxx']['mapper'] = '\\Ns\\dmXxx'.
        $cfgMapper = $this->syncableObjects[$object_type]['mapper'] ?? null;
        if (is_string($cfgMapper) && $cfgMapper !== '') {
            return $cfgMapper;
        }
        return null;
    }

    /**
     * Mapping from Dolibarr element to category configuration
     * - table: category link table suffix (llx_categorie_{table})
     * - fk: foreign key column name in the link table
     *
     * @var array
     */
    private static $categoryConfig = [
        'product' => ['table' => 'product', 'fk' => 'fk_product'],
        'societe' => ['table' => 'societe', 'fk' => 'fk_soc'],
        'contact' => ['table' => 'contact', 'fk' => 'fk_socpeople'],
        'projet' => ['table' => 'project', 'fk' => 'fk_project'],
        'project' => ['table' => 'project', 'fk' => 'fk_project'],
        'member' => ['table' => 'member', 'fk' => 'fk_member'],
        'user' => ['table' => 'user', 'fk' => 'fk_user'],
        'bank_account' => ['table' => 'account', 'fk' => 'fk_account'],
        'warehouse' => ['table' => 'warehouse', 'fk' => 'fk_warehouse'],
        'actioncomm' => ['table' => 'actioncomm', 'fk' => 'fk_actioncomm'],
        'ticket' => ['table' => 'ticket', 'fk' => 'fk_ticket'],
    ];

    /**
     * Get categories linked to any Dolibarr object
     *
     * @param int    $objectId Object ID
     * @param string $element  Dolibarr element type (product, societe, contact, etc.)
     * @return array Array of category objects with id, label, color
     */
    private function getObjectCategories($objectId, $element)
    {
        $categories = [];

        // Get the category config for this element
        $config = self::$categoryConfig[$element] ?? null;
        if (!$config) {
            return $categories;
        }

        $sql = "SELECT c.rowid, c.label, c.color";
        $sql .= " FROM " . MAIN_DB_PREFIX . "categorie_" . $config['table'] . " cp";
        $sql .= " JOIN " . MAIN_DB_PREFIX . "categorie c ON c.rowid = cp.fk_categorie";
        $sql .= " WHERE cp." . $config['fk'] . " = " . (int) $objectId;

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($cat = $this->db->fetch_object($resql)) {
                $categories[] = [
                    'id' => (int) $cat->rowid,
                    'label' => $cat->label,
                    'color' => $cat->color ?: null,
                ];
            }
            $this->db->free($resql);
        }

        return $categories;
    }

    /**
     * Universal denylist of property names that must never be writable
     * through /sync/push, regardless of object type. This is the second
     * layer of CR-6 defence (the first being the per-type allowed_fields
     * whitelist) and protects against:
     *   - cross-tenant writes (entity, ms*)
     *   - admin escalation when an external module exposes the User class
     *   - audit-trail forgery (datec, fk_user_creat, fk_user_modif)
     *   - password / authentication tampering (pass*, password*, salt, ...)
     *   - rowid spoofing
     */
    private static $sensitiveFieldsDenylist = [
        'rowid', 'id', 'tms', 'datec', 'date_creation', 'date_modification',
        'entity',
        'admin', 'employee', 'statut',
        'fk_user_creat', 'fk_user_modif', 'fk_user', 'fk_user_author',
        'import_key',
    ];

    /**
     * Universal denylist (regex form) for password / secret-shaped names.
     * Matches any property whose name starts with 'pass', equals 'password',
     * starts with 'salt', or equals 'api_key' / 'token'. Catches Dolibarr's
     * pass / pass_indatabase / pass_crypted / password / api_key conventions.
     */
    private static $sensitiveFieldsDenylistRegex = '/^(pass|password|salt|api_key|token|secret)/i';

    /**
     * Universal table for foreign-key existence validation in the push
     * path: when a dm* mapper accepts a fk_* assignment, the caller
     * MUST exist in the referenced table or we refuse the write to
     * avoid storing orphan rows.
     *
     * Only foreign keys actually writable through any dm*::writableFields
     * are listed here. fk_user_* are intentionally absent because they
     * sit on $sensitiveFieldsDenylist (audit-trail forgery prevention).
     *
     * If a hook-registered object_type exposes additional FKs, extend
     * this map or fall back to the legacy applyDataLegacy() path.
     */
    private static $fkValidationMap = [
        'fk_soc'         => 'societe',
        'fk_pays'        => 'c_country',
        'fk_country'     => 'c_country',
        'fk_departement' => 'c_departements',
        'fk_state'       => 'c_departements',
        'fk_product'     => 'product',
        'fk_project'     => 'projet',
        'fk_projet'      => 'projet',
        'fk_categorie'   => 'categorie',
        'fk_warehouse'   => 'entrepot',
    ];

    /**
     * Subset of $fkValidationMap target tables that carry an `entity` column,
     * mapped to the element code used by getEntity() for sharing resolution.
     * Only these get an entity filter in validateForeignKeyExists(); the
     * dictionary tables (c_country, c_departements) are entity-agnostic.
     *
     * @var array<string,string>
     */
    private static $fkEntityElementMap = [
        'societe'  => 'societe',
        'product'  => 'product',
        'projet'   => 'project',
        'categorie' => 'category',
        'entrepot' => 'stock',
    ];

    /**
     * Apply payload data to a Dolibarr object.
     *
     * Two paths:
     *  - Mapper path: when a dm* mapper is registered for the object_type
     *    (resolveMapperClass), the incoming data array is assumed to use
     *    API key names (see api-naming-convention.md). It is fed through
     *    $mapper->importMappedData() which:
     *      a) rejects keys not in $writableFields
     *      b) converts API keys to Dolibarr property names
     *      c) casts values to the declared field types
     *    Each fk_* field is then validated against $fkValidationMap so
     *    we never store an orphan reference.
     *  - Legacy path: kept for hook-registered object_types that lack
     *    a mapper. Uses the per-type 'allowed_fields' whitelist plus
     *    the universal denylist. Same behaviour as before the migration.
     *
     * In both paths the universal denylist is applied as defence in
     * depth (no silent failure -- every rejection is logged).
     *
     * @param object $object Dolibarr object
     * @param array $data Caller-provided data (api keys when mapper, else Dolibarr keys)
     * @param array $config Syncable object config (carries object_type stamped by loadSyncableObjects)
     * @return string[] Names of rejected keys (for caller-side logging or push error reporting)
     */
    private function applyDataToObject($object, array $data, array $config): array
    {
        $object_type = $config['object_type'] ?? null;
        $mapperClass = $object_type !== null ? $this->resolveMapperClass($object_type) : null;

        if ($mapperClass !== null && class_exists($mapperClass)) {
            return $this->applyDataViaMapper($object, $data, $config, $mapperClass);
        }

        return $this->applyDataLegacy($object, $data, $config);
    }

    /**
     * Mapper-based assignment path. See applyDataToObject() for the
     * contract.
     *
     * Filter strategy: dmTrait::importMappedData() is strict (throws on
     * the first unknown api key). The sync push contract is laxer: skip
     * unknown / denied keys silently with a LOG_WARNING and continue
     * with the rest. We therefore filter the input down to writable api
     * keys BEFORE feeding the mapper, so importMappedData() never sees
     * a key it would reject.
     *
     * @return string[] Names of rejected keys
     */
    private function applyDataViaMapper($object, array $data, array $config, $mapperClass): array
    {
        $writableApiKeys = $this->getWritableApiKeys($mapperClass);

        // Two-step filter: universal denylist first (CR-6 defence in
        // depth), then writable-api-keys whitelist from the mapper.
        $rejected = [];
        $clean = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveFieldsDenylist, true)
                || preg_match(self::$sensitiveFieldsDenylistRegex, (string) $key)) {
                $rejected[] = $key;
                continue;
            }
            if (!in_array($key, $writableApiKeys, true)) {
                $rejected[] = $key;
                continue;
            }
            $clean[$key] = $value;
        }
        if (!empty($rejected)) {
            dol_syslog(
                '[SmartAuth] SyncController::applyDataViaMapper: rejected '
                . 'mass-assignment keys for ' . ($config['class'] ?? '?')
                . ': ' . implode(',', $rejected),
                LOG_WARNING
            );
        }

        if (empty($clean)) {
            return $rejected;
        }

        $mapper = new $mapperClass();
        $mapped = $mapper->importMappedData($clean);

        $fkErrors = [];
        foreach ((array) $mapped as $field => $value) {
            if (isset(self::$fkValidationMap[$field]) && !empty($value)) {
                if (!$this->validateForeignKeyExists($field, (int) $value)) {
                    $fkErrors[] = $field;
                    continue;
                }
            }
            if (property_exists($object, $field)) {
                $object->$field = $value;
            }
        }
        if (!empty($fkErrors)) {
            dol_syslog(
                '[SmartAuth] SyncController::applyDataViaMapper: foreign '
                . 'key validation failed for ' . get_class($mapper) . ': '
                . implode(',', $fkErrors),
                LOG_WARNING
            );
        }

        return array_merge($rejected, $fkErrors);
    }

    /**
     * List the API key names that a given mapper accepts via
     * importMappedData(): every entry of $listOfPublishedFields whose
     * Dolibarr-side key is also in $writableFields.
     *
     * Read via reflection on default properties to stay cheap (no
     * mapper instantiation, no boot()).
     *
     * @param string $mapperClass Fully qualified dm* class name
     * @return string[]           Allowed API key names
     */
    private function getWritableApiKeys($mapperClass)
    {
        $ref = new \ReflectionClass($mapperClass);
        $defaults = $ref->getDefaultProperties();
        $listOfPublishedFields = $defaults['listOfPublishedFields'] ?? [];
        $writableSet = array_flip($defaults['writableFields'] ?? []);

        $apiKeys = [];
        foreach ($listOfPublishedFields as $doliSide => $appSide) {
            if (isset($writableSet[$doliSide])) {
                $apiKeys[] = $appSide;
            }
        }
        return $apiKeys;
    }

    /**
     * Legacy assignment path: per-type allowed_fields + universal
     * denylist, with Dolibarr-side key names assumed. Used when no
     * dm* mapper is registered for the object_type (typically the
     * hook-registered ones).
     *
     * @return string[] Names of rejected keys
     */
    private function applyDataLegacy($object, array $data, array $config): array
    {
        $allowedFields = $config['allowed_fields'] ?? null;
        $rejected = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveFieldsDenylist, true)
                || preg_match(self::$sensitiveFieldsDenylistRegex, (string) $key)) {
                $rejected[] = $key;
                continue;
            }

            if (is_array($allowedFields) && !in_array($key, $allowedFields, true)) {
                $rejected[] = $key;
                continue;
            }

            if (property_exists($object, $key)) {
                $object->$key = $value;
            }
        }

        if (!empty($rejected)) {
            dol_syslog('[SmartAuth] SyncController::applyDataLegacy: rejected mass-assignment keys for ' . ($config['class'] ?? '?') . ': ' . implode(',', $rejected), LOG_WARNING);
        }
        if (!is_array($allowedFields)) {
            dol_syslog('[SmartAuth] SyncController::applyDataLegacy: no allowed_fields whitelist for ' . ($config['class'] ?? '?') . ' - denylist-only mode', LOG_WARNING);
        }

        return $rejected;
    }

    /**
     * Verify that a foreign-key target row exists in the referenced
     * Dolibarr table. Returns false on missing row, missing FK mapping,
     * or SQL error (conservative: better refuse a write than store an
     * orphan).
     *
     * @param string $field Dolibarr field name (eg 'fk_soc')
     * @param int    $id    Target rowid to check
     * @return bool         True only when the row exists
     */
    private function validateForeignKeyExists($field, $id)
    {
        $table = self::$fkValidationMap[$field] ?? null;
        if ($table === null) {
            return true;
        }
        if ($id <= 0) {
            return false;
        }
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . $table
            . ' WHERE rowid = ' . (int) $id;
        // Entity-scoped FK targets must belong to an accessible entity, so a
        // cross-entity row cannot be referenced (eg attaching a contact to a
        // societe of another entity). Dictionary tables (c_*) have no entity
        // column and are left unfiltered.
        if (isset(self::$fkEntityElementMap[$table])) {
            $sql .= ' AND entity IN (' . getEntity(self::$fkEntityElementMap[$table]) . ')';
        }
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(
                '[SmartAuth] SyncController::validateForeignKeyExists: SQL '
                . 'error checking ' . $field . ' -> ' . $table
                . '.rowid=' . $id . ': ' . $this->db->lasterror(),
                LOG_WARNING
            );
            return false;
        }
        $count = $this->db->num_rows($resql);
        $this->db->free($resql);
        return $count > 0;
    }

    /**
     * Check that the authenticated Dolibarr user holds the permission
     * required to perform $action ('create'|'update'|'delete'|'read') on
     * the given syncable object.
     *
     * Fail-closed: an object whose config declares no 'rights' mapping for
     * the action is refused. Hook-registered syncable objects must therefore
     * publish a 'rights' key to allow writes.
     *
     * @param array  $config Syncable object config
     * @param string $action Logical action
     * @param \User  $user   Authenticated user
     * @return bool          True only when the right is granted
     */
    private function userHasSyncRight($config, $action, $user)
    {
        $type = $config['object_type'] ?? '?';
        if (empty($config['rights'][$action]) || !is_array($config['rights'][$action])) {
            dol_syslog(
                '[SmartAuth] SyncController: no ' . $action . ' right mapping for '
                . 'object_type ' . $type . ' - refusing write (fail-closed)',
                LOG_WARNING
            );
            return false;
        }

        $args = $config['rights'][$action];
        $granted = (bool) call_user_func_array([$user, 'hasRight'], $args);
        if (!$granted) {
            dol_syslog(
                '[SmartAuth] SyncController: user ' . ((int) $user->id)
                . ' lacks right ' . implode('->', $args) . ' for ' . $action
                . ' on ' . $type . ' - denied',
                LOG_WARNING
            );
        }
        return $granted;
    }

    /**
     * Whether the given entity id is within the set the current user may
     * access for $element (current entity + shared entities). getEntity()
     * is safe-by-default: an unknown element falls back to the current
     * entity only, never broader.
     *
     * @param int|string $entity  Entity id carried by the target row
     * @param string     $element Dolibarr element code (eg 'societe')
     * @return bool               True when the row is in scope
     */
    private function isEntityAllowed($entity, $element)
    {
        $allowed = array_map('intval', explode(',', getEntity($element, 1)));
        return in_array((int) $entity, $allowed, true);
    }

    /**
     * Process a CREATE operation
     */
    private function processCreate($config, $data, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        // Map data to object properties (whitelist + denylist gated, CR-6 fix)
        $this->applyDataToObject($object, $data, $config);

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

        // Entity isolation: refuse to touch (or even leak via a conflict
        // record) a row that belongs to another entity than the token's.
        // Checked before detectRealConflict/createConflictRecord so a
        // cross-entity rowid never exfiltrates its full row.
        if (isset($server_obj->entity)
            && !$this->isEntityAllowed($server_obj->entity, $config['element'])) {
            $this->db->rollback();
            dol_syslog(
                '[SmartAuth] SyncController::processUpdate: cross-entity write '
                . 'refused for ' . ($config['object_type'] ?? '?') . ' rowid=' . (int) $id
                . ' (row entity ' . (int) $server_obj->entity . ')',
                LOG_WARNING
            );
            // Generic message: do not reveal the row exists in another entity.
            return ['success' => false, 'error' => 'Object not found'];
        }

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

        // Apply update (whitelist + denylist gated, CR-6 fix)
        $object->fetch($id);
        $this->applyDataToObject($object, $data, $config);

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

        // Entity isolation: refuse deleting a row from another entity.
        if (isset($object->entity)
            && !$this->isEntityAllowed($object->entity, $config['element'])) {
            dol_syslog(
                '[SmartAuth] SyncController::processDelete: cross-entity delete '
                . 'refused for ' . ($config['object_type'] ?? '?') . ' rowid=' . (int) $id
                . ' (row entity ' . (int) $object->entity . ')',
                LOG_WARNING
            );
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

        // Whitelist + denylist gated (CR-6 fix)
        $this->applyDataToObject($object, $data, $config);

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
