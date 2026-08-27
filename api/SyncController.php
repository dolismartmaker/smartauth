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
    // Same tenant frontier as the synchronous facade: sync/push writes the same
    // objects through the same mappers, so it must apply the same write-side
    // foreign-key guard. See the trait's header for why there is only one copy.
    use ForeignKeyGuardTrait;

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

    /**
     * Pull pagination bounds. The default equals the historic hard cap so a
     * client sending no pagination params keeps the exact legacy behavior.
     * MAX_OFFSET bounds the O(offset) scan cost of offset pagination on the
     * database side (authenticated clients only, but still abusable).
     */
    const PULL_DEFAULT_LIMIT = 1000;
    const PULL_MAX_LIMIT = 1000;
    const PULL_MAX_OFFSET = 1000000;

    /**
     * Upper bound on the opaque keyset cursor string (hex of "tms|rowid").
     * A well-formed cursor is ~60 hex chars; anything longer is malformed
     * input and rejected before hex-decoding.
     */
    const PULL_MAX_CURSOR_LENGTH = 128;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->loadSyncableObjects();
    }

    /**
     * Load syncable objects configuration
     * Includes built-in objects and those registered via hooks
     *
     * Optional per-object key 'pull_where': trusted SQL fragment appended to
     * the WHERE clause of the pull() queries (eg 'tosell = 1'). Rules:
     * - MUST be a hardcoded string in module code. NEVER build it from
     *   request input or any user-controlled value: it is concatenated into
     *   SQL as-is (same trust model as the 'table' key).
     * - Business/volume filter ONLY, never an access-control mechanism: row
     *   ids that stop matching the filter are exposed to every authorized
     *   client as exclusions in the 'deleted' list. Access control stays
     *   with the 'rights' mapping and entity scoping.
     * - Reference bare column names (the pull queries use no table alias).
     */
    private function loadSyncableObjects()
    {
        global $hookmanager;

        // Single source of truth: the built-in core-object definitions, the
        // smartmaker_registerSyncableObjects hook merge and the object_type
        // self-stamp now live in ObjectRegistry, shared with the synchronous
        // REST facade (ObjectController). What may be copied onto the Dolibarr
        // object is decided by the mapper's $writableFields, and for a
        // hook-registered type that declares no mapper by its 'allowed_fields'
        // key; any other key (and any key from the universal denylist) is
        // rejected. See applyDataToObject() and CR-6 of TODO-SECURITY-01.
        $this->syncableObjects = ObjectRegistry::resolveWithHooks($hookmanager);
    }

    /**
     * Primary key column of a syncable type's table.
     *
     * Most Dolibarr tables use 'rowid', a few use 'id' (llx_actioncomm is the
     * canonical case). Assuming 'rowid' everywhere is not a cosmetic defect:
     * the keyset cursor is built from that column, so a wrong name yields
     * rowid=0 in the continuation token and the client re-reads page 1 forever.
     *
     * @param  array $config Syncable object config
     * @return string        Column name, never empty
     */
    private function syncPk(array $config)
    {
        $pk = (string) ($config['pk'] ?? 'rowid');
        return $pk !== '' ? $pk : 'rowid';
    }

    /**
     * SQL table alias used by the pull queries. Taken from the registry so the
     * fragment returned by a mapper's isolationWhereSql() lands on the same
     * alias the facade uses.
     *
     * @param  array $config Syncable object config
     * @return string
     */
    private function syncAlias(array $config)
    {
        $alias = (string) ($config['alias'] ?? 't');
        return $alias !== '' ? $alias : 't';
    }

    /**
     * Instantiate the dm* mapper of a syncable type, or null when the type
     * declares none (hook-registered types may not).
     *
     * @param  string $object_type
     * @return object|null
     */
    private function syncMapperInstance($object_type)
    {
        $mapperClass = $this->resolveMapperClass($object_type);
        if ($mapperClass === null || !class_exists($mapperClass)) {
            return null;
        }
        return new $mapperClass();
    }

    /**
     * Element code fed to getEntity() for a syncable type.
     *
     * 'module' when the type belongs to one, else 'element': the 'user' type
     * has no module key at all (it is core), and getEntity(null) emitted a PHP
     * warning then scoped on nothing recognisable.
     *
     * @param  array $config Syncable object config
     * @return string
     */
    private function syncEntityElement(array $config)
    {
        $element = (string) ($config['module'] ?? '');
        if ($element === '') {
            $element = (string) ($config['element'] ?? '');
        }
        return $element;
    }

    /**
     * TENANT SCOPING of the pull queries, for ANY syncable type.
     *
     * Two shapes, decided by the registry:
     * - table WITH an entity column (the common case): "AND t.entity IN (...)",
     *   the historical behaviour, unchanged.
     * - table WITHOUT one (llx_stock_mouvement, llx_subscription, llx_bank):
     *   the entity predicate would be a SQL error, so the scoping comes from the
     *   mapper's isolationWhereSql(), exactly as in the synchronous facade. A
     *   type that declares neither is refused (" AND 1=0"), fail-closed --
     *   serving it unscoped would hand every tenant's rows to any client.
     *
     * @param  array  $config      Syncable object config
     * @param  string $object_type Sync object type key
     * @param  string $alias       SQL alias of the main table
     * @return string              Fragment starting with " AND ", or " AND 1=0"
     */
    private function syncScopeSql(array $config, $object_type, $alias)
    {
        $hasEntity = !(array_key_exists('has_entity', $config) && $config['has_entity'] === false);
        if ($hasEntity) {
            return " AND " . $alias . ".entity IN (" . getEntity($this->syncEntityElement($config)) . ")";
        }

        $mapper = $this->syncMapperInstance($object_type);
        if ($mapper === null) {
            dol_syslog(
                "[SmartAuth] SyncController: type " . $object_type . " has no entity column "
                . "and no mapper to isolate it - pulling nothing (fail-closed)",
                LOG_ERR
            );
            return " AND 1=0";
        }

        // Shared with the facade: logs and falls back to " AND 1=0" when the
        // mapper of an entity-less type declares no usable fragment.
        return $this->isolationWhereFragment($mapper, $alias, $config + ['object_type' => $object_type]);
    }

    /**
     * Row-level tenant check for the write paths, for ANY syncable type.
     *
     * processUpdate/processDelete used to test $row->entity and let anything
     * without that property through. That is fail-OPEN precisely on the three
     * types that have no entity column, i.e. the ones that need a check the
     * most. This routes them to the same single-row isolation probe the facade
     * uses, and keeps the plain entity comparison for everyone else.
     *
     * @param  array      $config      Syncable object config
     * @param  string     $object_type Sync object type key
     * @param  int        $id          Row primary key
     * @param  mixed      $entityValue Row's entity column, null when absent
     * @return bool                    True when access must be REFUSED
     */
    private function syncTenantDenies(array $config, $object_type, $id, $entityValue)
    {
        $hasEntity = !(array_key_exists('has_entity', $config) && $config['has_entity'] === false);

        if ($hasEntity) {
            if ($entityValue === null) {
                // Registry says the column exists but the row does not carry it:
                // a misconfigured hook type. Refuse rather than guess.
                dol_syslog(
                    "[SmartAuth] SyncController: no entity value on " . $object_type
                    . " id=" . (int) $id . " while the config declares one - refusing (fail-closed)",
                    LOG_WARNING
                );
                return true;
            }
            return !$this->entityIsReachable($entityValue, $config['element']);
        }

        $mapper = $this->syncMapperInstance($object_type);
        if ($mapper === null) {
            dol_syslog(
                "[SmartAuth] SyncController: type " . $object_type . " has no entity column "
                . "and no mapper to isolate it - refusing write (fail-closed)",
                LOG_ERR
            );
            return true;
        }

        return $this->isolationDenies($config + ['object_type' => $object_type], $mapper, (int) $id);
    }

    /**
     * @api {get} /sync/objects List syncable object types
     * @apiName SyncObjects
     * @apiGroup Sync
     * @apiVersion 1.0.0
     *
     * @apiDescription Discovery endpoint: which object types this instance can
     * synchronise, and for each one whether the caller actually holds the
     * Dolibarr rights behind it. Without it a client had to hardcode the list to
     * build its sync_scope, which is exactly how the engine stayed stuck on the
     * four types of the first wave while the registry grew to cover them all.
     *
     * @apiHeader {String} Authorization Bearer access_token
     *
     * @apiSuccess {Object[]} objects           One entry per syncable type
     * @apiSuccess {String}   objects.type      Type key to pass as object_type
     * @apiSuccess {String}   objects.label     Human label
     * @apiSuccess {String}   objects.priority  Sync hint (high/medium/low)
     * @apiSuccess {Boolean}  objects.default_enabled Included in the default scope
     * @apiSuccess {Boolean}  objects.module_enabled  Dolibarr module active
     * @apiSuccess {Object}   objects.rights    Per-action permission of the caller
     * @apiSuccess {String}   server_time       Current server timestamp
     */
    public function objects($payload)
    {
        global $user;
        dol_syslog("[SmartAuth] SyncController::objects");

        $list = [];
        foreach ($this->syncableObjects as $type => $config) {
            $moduleEnabled = true;
            if (!empty($config['module'])) {
                $moduleEnabled = (bool) isModEnabled($config['module']);
            }

            $rights = [];
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                // Silent: this endpoint probes 4 actions on every type, so the
                // denial log of the enforcement path would emit ~100 warnings
                // per call and drown the real denials.
                $rights[$action] = $moduleEnabled && $this->userHasSyncRight($config, $action, $user, false);
            }

            $list[] = [
                'type' => $type,
                'label' => $config['label'] ?? $type,
                'priority' => $config['priority'] ?? 'low',
                'default_enabled' => !empty($config['default_enabled']),
                'module_enabled' => $moduleEnabled,
                'rights' => $rights,
            ];
        }

        return [[
            'objects' => $list,
            'server_time' => date('c'),
        ], 200];
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

        // Resolve the caller: re-pointing an existing sync client at another
        // device is an ownership change, so the lookup carries the same
        // device-owner scope as getClientByUUID() (M-11). Without it, anyone
        // holding another user's client_uuid (it travels in every pull/push
        // query string, so it lands in server and proxy logs) could hijack
        // that client at registration time: the victim's pulls would 404 and
        // the attacker would inherit their pending sync conflicts.
        $userId = $this->payloadUserId($payload);
        $ownerJoin = ""
            . " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_devices sd"
            . " ON sc.fk_device = sd.rowid AND sd.fk_user_creat = " . (int) $userId;

        // A row with this UUID that belongs to someone else must not be
        // visible here (no oracle) and must not be updatable: detect it so
        // the INSERT below fails with a clear error instead of relying on
        // the unique index alone.
        $sql = "SELECT sc.rowid FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients sc";
        $sql .= " WHERE sc.client_uuid = '" . $this->db->escape($client_uuid) . "'";
        $resql = $this->db->query($sql);
        $uuidTakenByOther = ($resql && $this->db->num_rows($resql) > 0);

        // Check if client already exists AND is owned by the caller
        $sql = "SELECT sc.rowid, sc.status FROM " . MAIN_DB_PREFIX . "smartauth_sync_clients sc" . $ownerJoin;
        $sql .= " WHERE sc.client_uuid = '" . $this->db->escape($client_uuid) . "'";

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
            if ($uuidTakenByOther) {
                // Generic-enough refusal: the caller cannot hijack the row,
                // and a legitimate collision (restored device profile reusing
                // a UUID) gets a clear signal to generate a fresh UUID.
                dol_syslog('[SmartAuth] SyncController::register - client_uuid already owned by another user, refused', LOG_WARNING);
                return [['error' => 'client_uuid is already registered by another user'], 409];
            }
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
     * @apiQuery {Number} [limit=1000] Page size, 1..1000
     * @apiQuery {Number} [offset=0] Page offset. Keep last_sync_at FIXED while
     *   paginating (offset 0, limit, 2*limit...) until has_more is false, then
     *   store server_time from the last page only.
     * @apiQuery {String} [cursor] Opaque keyset continuation token. Preferred
     *   over offset: pass back the previous page's next_cursor to fetch the
     *   next page. Robust to rows inserted during the pass (no dup, no skip).
     *   When set, offset is ignored. Keep last_sync_at FIXED across pages.
     *
     * @apiSuccess {Object[]} updated List of updated/created objects (current page)
     * @apiSuccess {Object[]} deleted List of deleted object IDs with timestamps.
     *   Only sent on the first page (no cursor, offset 0); includes tombstones
     *   and, for object types declaring a pull_where filter, rows that no
     *   longer match it.
     * @apiSuccess {Boolean} has_more True when more pages are available
     * @apiSuccess {String} [next_cursor] Present only when has_more is true:
     *   opaque token to pass as cursor on the next request.
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
     *     "has_more": false,
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

        // last_sync_at feeds string comparisons against tms/deleted_at. It is
        // escaped (no injection) but a garbage value would silently corrupt
        // the delta filters, so reject anything that is not a datetime.
        if ($last_sync_at !== null && !$this->isValidSyncTimestamp($last_sync_at)) {
            dol_syslog(
                "[SmartAuth] SyncController::pull - malformed last_sync_at rejected: "
                . $this->describeForLog($last_sync_at, 64),
                LOG_WARNING
            );
            return [['error' => 'Invalid last_sync_at, expected ISO 8601 datetime'], 400];
        }

        // Pagination bounds. Out-of-range values are rejected, not clamped: a
        // silently reduced limit would desync the client's offset arithmetic
        // (it advances by the limit IT requested) and skip rows.
        $limit = self::PULL_DEFAULT_LIMIT;
        if (isset($payload['limit'])) {
            $limit = $this->parseBoundedInt($payload['limit'], 1, self::PULL_MAX_LIMIT);
            if ($limit === null) {
                dol_syslog(
                    "[SmartAuth] SyncController::pull - invalid limit rejected: "
                    . $this->describeForLog($payload['limit'], 32),
                    LOG_WARNING
                );
                return [['error' => 'limit must be an integer between 1 and ' . self::PULL_MAX_LIMIT], 400];
            }
        }
        $offset = 0;
        if (isset($payload['offset'])) {
            $offset = $this->parseBoundedInt($payload['offset'], 0, self::PULL_MAX_OFFSET);
            if ($offset === null) {
                dol_syslog(
                    "[SmartAuth] SyncController::pull - invalid offset rejected: "
                    . $this->describeForLog($payload['offset'], 32),
                    LOG_WARNING
                );
                return [['error' => 'offset must be an integer between 0 and ' . self::PULL_MAX_OFFSET], 400];
            }
        }

        // Keyset (cursor) pagination. Opaque continuation token encoding the
        // last row's (tms, rowid). When present it drives paging instead of
        // offset (offset is ignored) and is robust to rows inserted mid-pass:
        // a fresh row always carries tms=now > any earlier cursor, so it is
        // picked up without duplicating or skipping already-paged rows.
        $cursor = null;
        if (isset($payload['cursor']) && $payload['cursor'] !== '') {
            $cursor = $this->decodeSyncCursor($payload['cursor']);
            if ($cursor === null) {
                dol_syslog(
                    "[SmartAuth] SyncController::pull - malformed cursor rejected: "
                    . $this->describeForLog($payload['cursor'], 64),
                    LOG_WARNING
                );
                return [['error' => 'Invalid cursor'], 400];
            }
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
            'has_more' => false,
            'server_time' => date('c'),
        ];

        // Primary key and alias come from the registry: llx_actioncomm keys on
        // 'id', and the entity-less tables need an alias for the isolation
        // fragment. A module-declared pull_where references bare column names,
        // which stay valid under an alias.
        $pk = $this->syncPk($config);
        $alias = $this->syncAlias($config);

        // Get updated records
        $sql = "SELECT " . $alias . ".* FROM " . MAIN_DB_PREFIX . $table . " as " . $alias;
        $sql .= " WHERE 1 = 1";
        $sql .= $this->syncScopeSql($config, $object_type, $alias);
        if ($last_sync_at) {
            $sql .= " AND " . $alias . ".tms > '" . $this->db->escape($last_sync_at) . "'";
        }
        if ($cursor !== null) {
            // Keyset predicate on the (tms, pk) total order. The primary key
            // breaks ties when several rows share a tms, so no row is seen twice
            // or skipped across pages. Values are validated/escaped in decode.
            $sql .= " AND (" . $alias . ".tms > '" . $this->db->escape($cursor['tms']) . "'";
            $sql .= " OR (" . $alias . ".tms = '" . $this->db->escape($cursor['tms']) . "'";
            $sql .= " AND " . $alias . "." . $pk . " > " . (int) $cursor['rowid'] . "))";
        }
        if (!empty($config['pull_where'])) {
            // Trusted, module-declared SQL fragment (see loadSyncableObjects
            // PHPDoc). NEVER built from request input.
            $sql .= " AND (" . $config['pull_where'] . ")";
        }
        // Primary-key tiebreaker gives a stable total order: required for
        // keyset, and makes offset pages deterministic too.
        $sql .= " ORDER BY " . $alias . ".tms ASC, " . $alias . "." . $pk . " ASC";
        // Fetch one extra row to detect a next page without a COUNT query.
        $sql .= " LIMIT " . ($limit + 1);
        // Keyset mode carries its position in the cursor, so no OFFSET.
        if ($cursor === null) {
            $sql .= " OFFSET " . $offset;
        }

        $withFiles = !empty($payload['with_files']);

        $resql = $this->db->query($sql);
        if (!$resql) {
            // A broken pull_where fragment would otherwise look like a
            // permanently empty sync on the client - fail loudly instead.
            dol_syslog(
                "[SmartAuth] SyncController::pull - updated query failed for "
                . $object_type . ": " . $this->db->lasterror(),
                LOG_ERR
            );
            return [['error' => 'Database error during pull'], 500];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        if (count($rows) > $limit) {
            $result['has_more'] = true;
            array_pop($rows); // drop the probe row
            // Continuation token for keyset clients: position of the last
            // delivered row. Additive - offset clients ignore it. Read through
            // the registry's primary key: on llx_actioncomm ->rowid is null,
            // which used to encode a cursor at id 0 and replay page 1 forever.
            $lastRow = end($rows);
            $result['next_cursor'] = $this->encodeSyncCursor($lastRow->tms, (int) ($lastRow->{$pk} ?? 0));
        }
        foreach ($rows as $obj) {
            $result['updated'][] = $this->formatObjectForSync($obj, $object_type, $withFiles);
        }

        // Tombstones and filter exclusions are page-independent lists: emit
        // them on the first page only so a paginating client does not receive
        // N identical copies. First page = neither a cursor nor an offset.
        if ($cursor === null && $offset === 0) {
            // Get tombstones (deleted records). Scoped to the entities the
            // caller may reach: the object ids of another tenant's deletions
            // have no business leaking here. Rows written before the entity
            // column existed carry NULL and stay visible, otherwise an upgrade
            // would silently hide past deletions from every client.
            $sql = "SELECT object_id, deleted_at FROM " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
            $sql .= " WHERE table_name = '" . $this->db->escape($table) . "'";
            $sql .= " AND (entity IS NULL OR entity IN (" . getEntity($this->syncEntityElement($config)) . "))";
            if ($last_sync_at) {
                $sql .= " AND deleted_at > '" . $this->db->escape($last_sync_at) . "'";
            }

            $resql = $this->db->query($sql);
            if (!$resql) {
                dol_syslog(
                    "[SmartAuth] SyncController::pull - tombstones query failed for "
                    . $object_type . ": " . $this->db->lasterror(),
                    LOG_ERR
                );
                return [['error' => 'Database error during pull'], 500];
            }
            while ($obj = $this->db->fetch_object($resql)) {
                $result['deleted'][] = [
                    'id' => (int) $obj->object_id,
                    'deleted_at' => $obj->deleted_at,
                ];
            }

            // Filter exclusions: rows updated since last sync that no longer
            // match the business filter are surfaced as deletions so offline
            // clients prune them (eg a product flipped to tosell=0). Only
            // meaningful on delta pulls: a full sync simply omits them.
            if ($last_sync_at && !empty($config['pull_where'])) {
                $sql = "SELECT " . $alias . "." . $pk . " as pkval, " . $alias . ".tms as tms";
                $sql .= " FROM " . MAIN_DB_PREFIX . $table . " as " . $alias;
                $sql .= " WHERE 1 = 1";
                $sql .= $this->syncScopeSql($config, $object_type, $alias);
                $sql .= " AND " . $alias . ".tms > '" . $this->db->escape($last_sync_at) . "'";
                $sql .= " AND NOT (" . $config['pull_where'] . ")";

                $resql = $this->db->query($sql);
                if (!$resql) {
                    dol_syslog(
                        "[SmartAuth] SyncController::pull - exclusions query failed for "
                        . $object_type . ": " . $this->db->lasterror(),
                        LOG_ERR
                    );
                    return [['error' => 'Database error during pull'], 500];
                }
                while ($obj = $this->db->fetch_object($resql)) {
                    $result['deleted'][] = [
                        'id' => (int) $obj->pkval,
                        'deleted_at' => $obj->tms,
                    ];
                }
            }
        }

        // Log the event
        $this->logSyncEvent($client->rowid, 'pull', $table, null, [
            'updated_count' => count($result['updated']),
            'deleted_count' => count($result['deleted']),
            'offset' => $offset,
            'has_more' => $result['has_more'],
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
        global $user, $conf;
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
                        // Idempotency: a replayed create (the original 2xx was
                        // lost on the wire) returns the original server_id
                        // instead of duplicating the object. Keyed on
                        // (client_uuid, temp_id, object_type); needs a temp_id.
                        $idem = null;
                        if (!empty($temp_id)) {
                            dol_include_once('/smartauth/class/smartauthsyncidempotency.class.php');
                            $idem = new \SmartAuthSyncIdempotency($this->db);
                            $replayId = $idem->findServerId($client_uuid, (string) $temp_id, $object_type, (int) $conf->entity);
                            if ($replayId !== null) {
                                dol_syslog("[SmartAuth] SyncController::push - idempotent create replay temp_id=" . $temp_id . " -> " . $replayId);
                                $result['success'][] = $replayId;
                                $result['id_mapping'][$temp_id] = $replayId;
                                break;
                            }
                        }

                        $createResult = $this->processCreate($config, $data, $user);
                        if ($createResult['success']) {
                            if ($idem !== null) {
                                $idem->record($client_uuid, (string) $temp_id, $object_type, (int) $createResult['id'], (int) $user->id, (int) $conf->entity);
                            }
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
                        } elseif (!empty($updateResult['conflict'])) {
                            // Guarded: every refusal path (permission, tenant,
                            // missing row) returns success=false with no
                            // 'conflict' key, and reading it raw turned a clean
                            // "Object not found" into an undefined-key notice.
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

        // Payload validation before touching the database: a merged resolution
        // without data is a malformed request (400), whatever the conflict is.
        // Checked here rather than inside the switch below so it is not masked
        // by the 404 of the ownership lookup.
        if ($resolution === 'merged' && empty($payload['data'])) {
            return [['error' => 'Merged data is required for merged resolution'], 400];
        }

        // Fetch the conflict, scoped to the caller. A conflict id is a small
        // integer: without the ownership join, enumerating them let any
        // authenticated client resolve someone else's conflict -- and with
        // resolution=merged, write arbitrary data into the underlying object.
        // Same ownership chain as getClientByUUID (M-11): sync client -> device
        // -> owning Dolibarr user.
        $callerId = $this->payloadUserId($payload);
        if ($callerId <= 0) {
            dol_syslog('[SmartAuth] SyncController::resolveConflict - no authenticated user in payload', LOG_WARNING);
            return [['error' => 'Conflict not found or already resolved'], 404];
        }

        $sql = "SELECT sco.* FROM " . MAIN_DB_PREFIX . "smartauth_sync_conflicts sco";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_sync_clients sc ON sco.fk_client = sc.rowid";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_devices sd ON sc.fk_device = sd.rowid";
        $sql .= " WHERE sco.rowid = " . (int) $conflict_id;
        $sql .= " AND sco.status = 'pending'";
        $sql .= " AND sd.fk_user_creat = " . $callerId;

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            dol_syslog(
                '[SmartAuth] SyncController::resolveConflict - conflict ' . (int) $conflict_id
                . ' not pending or not owned by user ' . $callerId,
                LOG_WARNING
            );
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
                // Presence already enforced above, before the DB lookup.
                $final_data = $payload['data'];
                break;
        }

        // Apply the resolution
        $object_type = $this->getObjectTypeFromTable($conflict->table_name);
        if (!$object_type) {
            return [['error' => 'Unknown table type'], 500];
        }

        $config = $this->syncableObjects[$object_type];

        // Resolving a conflict IS a write on the business object: same
        // permission gate as push/update, which this path used to skip entirely.
        if (!$this->userHasSyncRight($config, 'update', $user)) {
            return [['error' => 'Permission denied'], 403];
        }

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
        // Read the id through the registry's primary key: llx_actioncomm has no
        // 'rowid' column, so the old isset($obj->rowid) left $rowid at 0, which
        // skipped the mapper entirely and served the raw SQL row instead.
        $pk = $this->syncPk($config);
        $rowid = isset($obj->{$pk}) ? (int) $obj->{$pk} : 0;
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
            return $this->rawCastFallback($obj, $this->syncPk($config), $config);
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
            return $this->rawCastFallback($obj, $this->syncPk($config), $config);
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
            return $this->rawCastFallback($obj, $this->syncPk($config), $config);
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
            return $this->rawCastFallback($obj, $this->syncPk($config), $config);
        }

        // Symmetry with the push door, which now writes the extrafields a
        // mapper opens: a client that cannot READ them back could not do a
        // round-trip. Several core fetch() load them already, a few do not, and
        // insertExtraFields() upserts -- so ask explicitly, like the REST facade
        // does on show() and on every list row.
        if (method_exists($fresh, 'fetch_optionals')) {
            $fresh->fetch_optionals();
        }

        $mapper = new $mapperClass();
        $exported = $mapper->exportMappedData($fresh);
        return (array) $exported;
    }

    /**
     * Legacy raw cast path, used only when the mapper path cannot run (no
     * mapper registered, class missing, fetch failed). Each call site logs
     * a LOG_WARNING.
     *
     * Bounded projection (audit S-8): the raw SELECT * row used to leave
     * here in full -- every column of the table, including columns the
     * registering module never meant to publish. Now:
     *  - a type that declares 'allowed_fields' (hook-registered types)
     *    serves exactly those columns, plus the identity keys;
     *  - a type that declares nothing serves only {id, tms}: cursors and
     *    pagination keep working, but no business column leaks. That
     *    mirrors the write side, where applyDataLegacy() already refuses
     *    every payload for a type without an allowlist.
     *
     * @param object $obj    Raw row from SELECT *
     * @param string $pk     Primary key column of the table (registry 'pk')
     * @param array  $config Entry from $this->syncableObjects
     * @return array         Bounded projection of the row
     */
    private function rawCastFallback($obj, $pk = 'rowid', array $config = [])
    {
        $data = (array) $obj;

        $out = [];
        if ($pk !== 'id' && isset($data[$pk])) {
            $out['id'] = (int) $data[$pk];
        } elseif (isset($data['id'])) {
            $out['id'] = (int) $data['id'];
        }
        if (isset($data['tms'])) {
            // Injected in ISO form by formatObjectForSync(); kept raw here
            // so the caller knows a tms exists at all.
            $out['tms'] = $data['tms'];
        }

        $allowed = $config['allowed_fields'] ?? null;
        if (is_array($allowed) && $allowed !== []) {
            foreach ($allowed as $field) {
                $field = (string) $field;
                if ($field !== '' && $field !== $pk && isset($data[$field])) {
                    $out[$field] = $data[$field];
                }
            }
        } else {
            dol_syslog(
                '[SmartAuth] SyncController::rawCastFallback: type declares '
                . 'neither a mapper nor allowed_fields, serving identity keys '
                . 'only (write side is fail-closed for such types too).',
                LOG_NOTICE
            );
        }

        return $out;
    }

    /**
     * Resolve the fully qualified dm* mapper class name for a given
     * object_type. Returns null when no mapper is registered (the caller
     * then falls back to the raw cast path and logs a warning).
     *
     * The mapper class is read straight from the (single-source-of-truth)
     * config carried by $syncableObjects, which ObjectRegistry seeds with a
     * 'mapper' key for every built-in type. A hook-registered custom object
     * type provides its own mapper the same way
     * ($syncableObjects['xxx']['mapper'] = '\\Ns\\dmXxx').
     *
     * @param string $object_type Sync object type key
     * @return string|null        Fully qualified mapper class name
     */
    private function resolveMapperClass($object_type)
    {
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
     * layer of CR-6 defence (the first being the write allowlist: the
     * mapper's $writableFields, or 'allowed_fields' for a hook-registered
     * type with no mapper) and protects against:
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
     *    the universal denylist, and refuses everything when that
     *    whitelist is missing too. Unreachable for the built-in types:
     *    all 26 declare a mapper (see the ObjectRegistry docblock).
     *
     * In both paths the universal denylist is applied as defence in
     * depth (no silent failure -- every rejection is logged).
     *
     * EXTRAFIELDS. A key the mapper opens through $extrafieldsRW arrives as
     * 'options_<name>' and goes into $object->array_options, NOT into a
     * property -- which is why $extrafieldsApplied comes back by reference:
     * only the caller can persist them, with insertExtraFields(), and only
     * after create()/update() has given the row its id.
     *
     * @param object $object Dolibarr object
     * @param array $data Caller-provided data (api keys when mapper, else Dolibarr keys)
     * @param array $config Syncable object config (carries object_type stamped by loadSyncableObjects)
     * @param bool $extrafieldsApplied Out: true when at least one extrafield was set on the object
     * @return string[] Names of rejected keys (for caller-side logging or push error reporting)
     */
    private function applyDataToObject($object, array $data, array $config, &$extrafieldsApplied = false): array
    {
        $extrafieldsApplied = false;
        $object_type = $config['object_type'] ?? null;
        $mapperClass = $object_type !== null ? $this->resolveMapperClass($object_type) : null;

        if ($mapperClass !== null && class_exists($mapperClass)) {
            return $this->applyDataViaMapper($object, $data, $config, $mapperClass, $extrafieldsApplied);
        }

        // The legacy path never sets one: it refuses extrafields outright.
        return $this->applyDataLegacy($object, $data, $config);
    }

    /**
     * Route one already-vetted field onto the object: an 'options_*' key into
     * array_options, anything else onto the property of the same name.
     *
     * The property branch keeps the push-specific `property_exists` guard: the
     * sync contract is laxer than the facade's (skip what the class does not
     * carry rather than reject the whole payload), and assigning blindly would
     * create a dynamic property PHP 8.2 deprecates. That guard is exactly what
     * used to swallow every extrafield, 'options_*' never being a property.
     *
     * @param  object $object
     * @param  string $field  Dolibarr-side field name.
     * @param  mixed  $value
     * @return bool           True when the field was an extrafield.
     */
    private function assignFieldToObject($object, $field, $value)
    {
        if (strncmp((string) $field, 'options_', 8) === 0) {
            if (!isset($object->array_options) || !is_array($object->array_options)) {
                $object->array_options = [];
            }
            $object->array_options[$field] = $value;
            return true;
        }

        if (property_exists($object, $field)) {
            $object->$field = $value;
        }

        return false;
    }

    /**
     * Persist the extrafields set on $object by applyDataToObject().
     *
     * PRECONDITION, and it is the whole reason this is a separate step: the
     * caller must have loaded the EXISTING extrafields (fetch_optionals())
     * before applying the payload. insertExtraFields() DELETEs the object's
     * extrafield row and re-INSERTs it from array_options alone
     * (commonobject.class.php), so a partial push against a half-filled
     * array_options would silently wipe every custom field the payload did not
     * restate.
     *
     * @param  object $object
     * @param  array  $config
     * @param  string $context  Caller name, for the log line.
     * @return bool             False when persisting failed (already logged).
     */
    private function persistExtrafields($object, array $config, $context)
    {
        if (!method_exists($object, 'insertExtraFields')) {
            dol_syslog(
                '[SmartAuth] SyncController::' . $context . ': ' . ($config['class'] ?? '?')
                . ' has no insertExtraFields() - extrafields not persisted',
                LOG_ERR
            );
            return false;
        }

        if ($object->insertExtraFields() < 0) {
            dol_syslog(
                '[SmartAuth] SyncController::' . $context . ': insertExtraFields failed for '
                . ($config['object_type'] ?? '?') . ' id=' . ((int) ($object->id ?? 0)) . ': ' . $object->error,
                LOG_ERR
            );
            return false;
        }

        return true;
    }

    /**
     * Load the existing extrafields of a fetched object, so a partial push
     * cannot erase the ones it does not restate (see persistExtrafields).
     *
     * @param  object $object
     * @param  array  $config
     * @param  string $context  Caller name, for the log line.
     * @return void
     */
    private function loadExistingExtrafields($object, array $config, $context)
    {
        if (!method_exists($object, 'fetch_optionals')) {
            dol_syslog(
                '[SmartAuth] SyncController::' . $context . ': ' . ($config['class'] ?? '?')
                . ' has no fetch_optionals() - existing extrafields cannot be preserved',
                LOG_WARNING
            );
            return;
        }

        $object->fetch_optionals();
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
     * @param bool $extrafieldsApplied Out: true when at least one extrafield was set
     * @return string[] Names of rejected keys
     */
    private function applyDataViaMapper($object, array $data, array $config, $mapperClass, &$extrafieldsApplied = false): array
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

        // Tenant guard on the VALUES, shared verbatim with the synchronous
        // facade (ForeignKeyGuardTrait, driven by the mapper's declarative
        // $foreignKeyGuards). processUpdate() already refuses to touch a row of
        // another entity, but it says nothing about what the payload POINTS AT:
        // a push carrying another tenant's socid was written as-is, exactly as
        // it was on PATCH objects/{type}/{id} before the guard existed.
        //
        // $fkValidationMap below stays: it is an EXISTENCE check that also
        // covers the dictionary tables (c_country, c_departements), which the
        // tenant guard deliberately does not probe. The two are complementary,
        // and the map alone was never enough -- it is keyed on SQL column names
        // (fk_soc, fk_project) while the mappers write PHP property names, so
        // `socid`, the key that spans 14 types, was never validated by it.
        //
        // A rejected field is dropped and reported, exactly like an $fkErrors
        // one: the rest of the payload still applies. The loop terminates
        // because each pass removes one field.
        $guardRejected = [];
        while (($badFk = $this->foreignKeyViolation($mapper, $mapped, $config, $object)) !== null) {
            unset($mapped->{$badFk});
            $guardRejected[] = $badFk;
        }
        if (!empty($guardRejected)) {
            dol_syslog(
                '[SmartAuth] SyncController::applyDataViaMapper: cross-tenant foreign key '
                . 'refused for ' . ($config['object_type'] ?? '?') . ': '
                . implode(',', $guardRejected),
                LOG_WARNING
            );
            $fkErrors = $guardRejected;
        }
        foreach ((array) $mapped as $field => $value) {
            if (isset(self::$fkValidationMap[$field]) && !empty($value)) {
                if (!$this->validateForeignKeyExists($field, (int) $value)) {
                    $fkErrors[] = $field;
                    continue;
                }
            }
            if ($this->assignFieldToObject($object, $field, $value)) {
                $extrafieldsApplied = true;
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

        // Extrafields the mapper opens for write, read EXACTLY as
        // dmTrait::importMappedData() reads them (an attribute name with or
        // without the 'options_' prefix), because this filter runs upstream of
        // it: a key missing here never reaches the mapper, and that alone is
        // why the push door wrote no extrafield at all until now. The second
        // declaration path -- an 'options_*' entry placed directly in
        // $writableFields, cf dmBase::writableExtrafieldNames() -- already
        // lands in $writableSet above and needs nothing here.
        $writableExtraSet = [];
        $extrafieldsRW = $defaults['extrafieldsRW'] ?? null;
        if (is_array($extrafieldsRW)) {
            foreach ($extrafieldsRW as $ef) {
                $ef = (string) $ef;
                $writableExtraSet[(strncmp($ef, 'options_', 8) === 0) ? $ef : ('options_' . $ef)] = true;
            }
        }

        $apiKeys = [];
        foreach ($listOfPublishedFields as $doliSide => $appSide) {
            if (isset($writableSet[$doliSide]) || isset($writableExtraSet[$doliSide])) {
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
     * Fail-closed when the type declares no allowlist either: see the
     * comment on the early return below.
     *
     * Extrafields are refused on this path, whatever the allowlist says: see
     * the 'options_' branch below.
     *
     * @return string[] Names of rejected keys
     */
    private function applyDataLegacy($object, array $data, array $config): array
    {
        $allowedFields = $config['allowed_fields'] ?? null;
        $rejected = [];

        // No mapper AND no allowlist: nothing describes what may be written on
        // this type, so nothing is. This used to fall back to "denylist only",
        // which is fail-OPEN -- every property of the Dolibarr class was
        // writable as long as its name dodged the denylist, on a type smartauth
        // knows nothing about. Refusing is the same verdict the rest of the
        // facade reaches when a scoping rule cannot be resolved
        // (ForeignKeyGuardTrait::isolationDenies and friends).
        if (!is_array($allowedFields)) {
            dol_syslog(
                '[SmartAuth] SyncController::applyDataLegacy: type ' . ($config['object_type'] ?? '?')
                . ' (' . ($config['class'] ?? '?') . ') declares neither a mapper nor an allowed_fields'
                . ' allowlist - refusing every incoming field (fail-closed). Declare a dm* mapper'
                . ' (recommended) or an allowed_fields list in the smartmaker_registerSyncableObjects hook.',
                LOG_ERR
            );

            return array_keys($data);
        }

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveFieldsDenylist, true)
                || preg_match(self::$sensitiveFieldsDenylistRegex, (string) $key)) {
                $rejected[] = $key;
                continue;
            }

            if (!in_array($key, $allowedFields, true)) {
                $rejected[] = $key;
                continue;
            }

            // Extrafields are NOT opened on this path, even allowlisted. The
            // tenant guard that vets an extrafield of type 'link' is driven by
            // dmBase::getExtrafieldWriteTargets(), which a type without mapper
            // does not have -- writing one here would be the very fail-open the
            // guard was added to close, on a type smartauth knows nothing about.
            // Fail-closed and say so: declare a dm* mapper with $extrafieldsRW.
            if (strncmp((string) $key, 'options_', 8) === 0) {
                dol_syslog(
                    '[SmartAuth] SyncController::applyDataLegacy: extrafield ' . $key . ' refused on '
                    . ($config['object_type'] ?? '?') . ' - a type without a dm* mapper has no tenant guard'
                    . ' for extrafield targets. Declare a mapper with $extrafieldsRW to write it.',
                    LOG_WARNING
                );
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
     * Describe a rejected payload value for logging without risking a PHP
     * warning on non-scalars (an array cast to string) or log injection.
     *
     * @param mixed $value  Raw payload value
     * @param int   $maxLen Maximum logged length
     * @return string       Loggable description
     */
    private function describeForLog($value, int $maxLen): string
    {
        if (!is_scalar($value)) {
            return '(' . gettype($value) . ')';
        }
        return InputSanitizer::sanitizeForLog($value, $maxLen, $this->db);
    }

    /**
     * Validate a sync timestamp coming from the client (or stored).
     *
     * Accepts ISO 8601 (2025-01-19T10:30:00+00:00, trailing Z, optional
     * fractional seconds) and SQL datetime (2025-01-19 10:30:00). The value
     * is compared as a string against tms/deleted_at columns, so any other
     * shape would corrupt the comparison without failing.
     *
     * @param mixed $value Raw timestamp
     * @return bool        True when the shape is a usable datetime
     */
    private function isValidSyncTimestamp($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/',
            $value
        );
    }

    /**
     * Parse a payload value as a strict integer within [$min, $max].
     *
     * Stricter than InputSanitizer::sanitizeInt (which casts anything):
     * floats, negative strings and garbage return null so the caller can
     * reject the request instead of silently reinterpreting it.
     *
     * @param mixed $value Raw payload value (int or digit-only string)
     * @param int   $min   Lower bound (inclusive)
     * @param int   $max   Upper bound (inclusive)
     * @return int|null    Parsed value, or null when invalid/out of range
     */
    private function parseBoundedInt($value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $n = (int) $value;
        } else {
            return null;
        }

        return ($n >= $min && $n <= $max) ? $n : null;
    }

    /**
     * Encode a keyset pagination cursor from a row's (tms, rowid).
     *
     * The token is opaque hex of "tms|rowid". Hex keeps it purely
     * alphanumeric so it survives the generic payload sanitizer untouched
     * (sync/pull has no dedicated schema, cf. sanitizeAll).
     *
     * @param string $tms   Row modification timestamp (SQL datetime string)
     * @param int    $rowid Row primary key
     * @return string       Opaque cursor token
     */
    private function encodeSyncCursor($tms, int $rowid): string
    {
        return bin2hex(((string) $tms) . '|' . $rowid);
    }

    /**
     * Decode and validate a client-supplied keyset cursor.
     *
     * The token is untrusted input: it is length-bounded, hex-decoded, split
     * on the last '|' (a tms never contains one), and its tms is validated
     * with the same datetime shape as last_sync_at. Any deviation returns
     * null so pull() rejects the request with 400 instead of feeding garbage
     * into the WHERE clause.
     *
     * @param mixed $value Raw payload cursor value
     * @return array|null  ['tms' => string, 'rowid' => int] or null if invalid
     */
    private function decodeSyncCursor($value): ?array
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::PULL_MAX_CURSOR_LENGTH) {
            return null;
        }
        // Strict hex: ctype_xdigit rejects any non-hex byte, and an odd
        // length cannot be a valid bin2hex output.
        if (strlen($value) % 2 !== 0 || !ctype_xdigit($value)) {
            return null;
        }
        $decoded = hex2bin($value);
        if ($decoded === false) {
            return null;
        }
        $sep = strrpos($decoded, '|');
        if ($sep === false) {
            return null;
        }
        $tms = substr($decoded, 0, $sep);
        $rowidPart = substr($decoded, $sep + 1);
        if (!$this->isValidSyncTimestamp($tms) || !ctype_digit($rowidPart)) {
            return null;
        }
        return ['tms' => $tms, 'rowid' => (int) $rowidPart];
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
     * @param array  $config    Syncable object config
     * @param string $action    Logical action
     * @param \User  $user      Authenticated user
     * @param bool   $logDenial Emit the denial log (false for the discovery
     *                          endpoint, which probes every type x action)
     * @return bool          True only when the right is granted
     */
    private function userHasSyncRight($config, $action, $user, $logDenial = true)
    {
        $type = $config['object_type'] ?? '?';
        if (empty($config['rights'][$action]) || !is_array($config['rights'][$action])) {
            if ($logDenial) {
                dol_syslog(
                    '[SmartAuth] SyncController: no ' . $action . ' right mapping for '
                    . 'object_type ' . $type . ' - refusing write (fail-closed)',
                    LOG_WARNING
                );
            }
            return false;
        }

        $args = $config['rights'][$action];
        $granted = (bool) call_user_func_array([$user, 'hasRight'], $args);
        if (!$granted && $logDenial) {
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
     * Process a CREATE operation
     */
    private function processCreate($config, $data, $user)
    {
        require_once $config['file'];
        $classname = $config['class'];
        $object = new $classname($this->db);

        // Map data to object properties (whitelist + denylist gated, CR-6 fix)
        $extrafieldsApplied = false;
        $this->applyDataToObject($object, $data, $config, $extrafieldsApplied);

        $result = $object->create($user);
        if ($result <= 0) {
            return ['success' => false, 'error' => $object->error ?: 'Create failed'];
        }

        // Extrafields need the row's id, so they are written after create().
        // insertExtraFields() upserts, so a second call after a class whose
        // create() already did it stays harmless. No fetch_optionals() to do
        // here: a brand-new row has nothing to preserve.
        if ($extrafieldsApplied && !$this->persistExtrafields($object, $config, 'processCreate')) {
            return [
                'success' => false,
                'id' => $result,
                'error' => 'Object created but failed to persist extrafields: ' . ($object->error ?: 'unknown error'),
            ];
        }

        return ['success' => true, 'id' => $result];
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

        $pk = $this->syncPk($config);
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $config['table'];
        $sql .= " WHERE " . $pk . " = " . (int) $id;
        $sql .= " FOR UPDATE";

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Object not found'];
        }

        $server_obj = $this->db->fetch_object($resql);
        $server_tms = $server_obj->tms;

        // Tenant isolation: refuse to touch (or even leak via a conflict
        // record) a row that belongs to another entity than the token's.
        // Checked before detectRealConflict/createConflictRecord so a
        // cross-entity rowid never exfiltrates its full row.
        //
        // Routed through syncTenantDenies so the three entity-less tables
        // (llx_stock_mouvement, llx_subscription, llx_bank) are covered by their
        // mapper's isolationWhereSql(). The previous isset($server_obj->entity)
        // test simply skipped them: fail-OPEN on exactly the types that carry no
        // column to check.
        $objectTypeForLog = $config['object_type'] ?? '?';
        if ($this->syncTenantDenies($config, $objectTypeForLog, (int) $id, $server_obj->entity ?? null)) {
            $this->db->rollback();
            dol_syslog(
                '[SmartAuth] SyncController::processUpdate: cross-entity write '
                . 'refused for ' . $objectTypeForLog . ' id=' . (int) $id,
                LOG_WARNING
            );
            // Generic message: do not reveal the row exists in another entity.
            return ['success' => false, 'error' => 'Object not found'];
        }

        // Load the business object BEFORE conflict detection: the mapper
        // contract lives in Dolibarr property space (listOfPublishedFields
        // maps PHP properties to API keys), and the locked SQL row above is
        // NOT that space (Societe: property 'name' vs column 'nom').
        // Comparing API keys against raw columns made detectRealConflict()
        // miss nearly every mapped field, so real conflicts were written
        // silently (audit S-6).
        $fetchOk = $object->fetch($id);
        if ($fetchOk <= 0) {
            $this->db->rollback();
            dol_syslog('[SmartAuth] SyncController::processUpdate: fetch failed for ' . $objectTypeForLog . ' id=' . (int) $id, LOG_WARNING);
            return ['success' => false, 'error' => 'Object not found'];
        }

        // Conflict detection: compare tms
        if ($base_tms && $server_tms != $base_tms) {
            // Potential conflict - compare data field by field
            $conflict = $this->detectRealConflict($data, $server_obj, $config, $object);

            if ($conflict) {
                // Real conflict. Roll back the unwanted business-data write
                // FIRST, then persist the conflict record. Recording it before
                // the rollback would enrol the INSERT in the very transaction
                // being rolled back, so the conflict row would be discarded --
                // it would then never surface in sync/conflicts nor
                // sync/status, and the resolve workflow would have nothing to
                // act on. The data rollback is intended; only the conflict
                // record must survive.
                $this->db->rollback();
                $this->createConflictRecord($client_id, $config['table'], $id, $data, $server_obj, $base_tms, $server_tms, $conflict);
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

        // Apply update (whitelist + denylist gated, CR-6 fix).
        // The object was already fetched (and its liveness checked) before
        // conflict detection above.
        // BEFORE applying the payload: insertExtraFields() rebuilds the whole
        // extrafield row from array_options, so the existing values have to be
        // in there or a partial push erases the ones it does not restate.
        $this->loadExistingExtrafields($object, $config, 'processUpdate');
        $extrafieldsApplied = false;
        $this->applyDataToObject($object, $data, $config, $extrafieldsApplied);

        // Dolibarr update() signatures differ (Societe/Product/Contact take
        // $id first, User/Facture take $user first). Use the reflection-based
        // dispatcher, same as applyResolvedData -- calling update($user)
        // directly puts the User object into $id on Societe et al.
        $result = $this->callUpdateMethod($object, $user);
        if ($result <= 0) {
            $this->db->rollback();
            return ['success' => false, 'error' => $object->error ?: 'Update failed'];
        }

        // Inside the transaction on purpose: a failed extrafield write must
        // take the header update down with it, not leave the row half-applied.
        if ($extrafieldsApplied && !$this->persistExtrafields($object, $config, 'processUpdate')) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Failed to persist extrafields: ' . ($object->error ?: 'unknown error')];
        }

        $this->db->commit();
        return ['success' => true];
    }

    /**
     * Detect if there's a real data conflict (not just tms mismatch)
     * Returns array of conflicting fields or null if no real conflict
     *
     * With a dm* mapper registered for the type, the comparison happens in
     * Dolibarr property space on the loaded $object: the client payload
     * speaks API keys, the mapper contract maps Dolibarr PHP properties to
     * API keys, and only that intersection is comparable. Without a mapper
     * (hook-registered types), the legacy raw comparison against the SQL
     * row applies (their apply path, applyDataLegacy(), is Dolibarr-key
     * based too).
     *
     * @param array    $client_data Payload keys (API space)
     * @param object   $server_obj  Locked raw SQL row (legacy space)
     * @param array    $config      Registry config of the object type
     * @param object|null $object   Loaded Dolibarr business object (mapper path)
     * @return array|null
     */
    private function detectRealConflict($client_data, $server_obj, $config, $object = null)
    {
        $conflicts = [];
        $server_data = (array) $server_obj;
        $reverseMap = $this->reversePublishedFieldMap($config);

        foreach ($client_data as $field => $client_value) {
            // Skip metadata fields
            if (in_array($field, ['rowid', 'id', 'tms', 'date_creation', 'date_modification'])) {
                continue;
            }

            if ($reverseMap !== null && $object !== null) {
                // Mapper path: API key -> Dolibarr property on the object.
                if (!isset($reverseMap[$field])) {
                    // Not a published key: the server state is not expressed
                    // in this space, nothing to compare against.
                    continue;
                }
                $property = $reverseMap[$field];
                if (!isset($object->{$property})) {
                    continue;
                }
                $server_value = $object->{$property};
            } else {
                // Legacy path: raw SQL row, Dolibarr-side key names.
                if (!isset($server_data[$field])) {
                    continue;
                }
                $server_value = $server_data[$field];
            }

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

        return empty($conflicts) ? null : $conflicts;
    }

    /**
     * Reverse the mapper's published-fields map (Dolibarr property => API
     * key) into API key => Dolibarr property, read via reflection on
     * default properties to stay cheap (same trick as getWritableApiKeys).
     *
     * @param array $config Registry config of the object type
     * @return array|null null when the type has no mapper (hook-registered)
     */
    private function reversePublishedFieldMap($config)
    {
        $objectType = $config['object_type'] ?? null;
        if ($objectType === null) {
            return null;
        }
        $mapperClass = $this->resolveMapperClass($objectType);
        if ($mapperClass === null || !class_exists($mapperClass)) {
            return null;
        }
        $ref = new \ReflectionClass($mapperClass);
        $defaults = $ref->getDefaultProperties();
        $published = $defaults['listOfPublishedFields'] ?? [];
        if (!is_array($published) || $published === []) {
            return null;
        }

        $reverse = [];
        foreach ($published as $doliSide => $appSide) {
            $reverse[(string) $appSide] = (string) $doliSide;
        }
        return $reverse;
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
     * Create (or refresh) a pending conflict record in the database.
     *
     * Must be called OUTSIDE the update transaction that was rolled back:
     * otherwise the INSERT is discarded with that rollback (see processUpdate).
     *
     * @param int    $client_id      Sync client rowid
     * @param string $table          Business table name (eg 'societe')
     * @param int    $object_id      Conflicting object rowid
     * @param array  $client_data    Data the client tried to push
     * @param object $server_obj     Current server row
     * @param string $client_tms     Base tms the client held
     * @param string $server_tms     Current server tms
     * @param array  $field_conflicts Per-field client/server diff
     * @return bool True if the row was persisted, false on SQL error
     */
    private function createConflictRecord($client_id, $table, $object_id, $client_data, $server_obj, $client_tms, $server_tms, $field_conflicts)
    {
        $clientJson = json_encode($client_data);
        $serverJson = json_encode((array) $server_obj);
        $fieldsJson = json_encode($field_conflicts);
        $now = $this->db->idate(dol_now());

        // Offline-first clients retry the same push until they observe a 2xx,
        // so the same (client, table, object) can conflict repeatedly. Refresh
        // the existing pending row instead of piling up duplicates that would
        // inflate sync/conflicts and sync/status.
        $existingId = 0;
        $sqlSel = "SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
        $sqlSel .= " WHERE fk_client = " . (int) $client_id;
        $sqlSel .= " AND table_name = '" . $this->db->escape($table) . "'";
        $sqlSel .= " AND object_id = " . (int) $object_id;
        $sqlSel .= " AND status = 'pending'";
        $resql = $this->db->query($sqlSel);
        if ($resql && ($row = $this->db->fetch_object($resql))) {
            $existingId = (int) $row->rowid;
        }

        if ($existingId > 0) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_sync_conflicts SET";
            $sql .= " client_data = '" . $this->db->escape($clientJson) . "'";
            $sql .= ", server_data = '" . $this->db->escape($serverJson) . "'";
            $sql .= ", client_tms = '" . $this->db->escape($client_tms) . "'";
            $sql .= ", server_tms = '" . $this->db->escape($server_tms) . "'";
            $sql .= ", field_conflicts = '" . $this->db->escape($fieldsJson) . "'";
            $sql .= ", date_creation = '" . $now . "'";
            $sql .= " WHERE rowid = " . $existingId;
        } else {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_conflicts";
            $sql .= " (fk_client, table_name, object_id, client_data, server_data, client_tms, server_tms, field_conflicts, status, date_creation)";
            $sql .= " VALUES (";
            $sql .= (int) $client_id . ", ";
            $sql .= "'" . $this->db->escape($table) . "', ";
            $sql .= (int) $object_id . ", ";
            $sql .= "'" . $this->db->escape($clientJson) . "', ";
            $sql .= "'" . $this->db->escape($serverJson) . "', ";
            $sql .= "'" . $this->db->escape($client_tms) . "', ";
            $sql .= "'" . $this->db->escape($server_tms) . "', ";
            $sql .= "'" . $this->db->escape($fieldsJson) . "', ";
            $sql .= "'pending', ";
            $sql .= "'" . $now . "')";
        }

        if (!$this->db->query($sql)) {
            dol_syslog(
                '[SmartAuth] SyncController::createConflictRecord: failed to persist conflict for '
                . $table . ' rowid=' . (int) $object_id . ' (client ' . (int) $client_id . ') - '
                . $this->db->lasterror(),
                LOG_ERR
            );
            return false;
        }
        return true;
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

        // Tenant isolation: refuse deleting a row from another entity. Same
        // routing as processUpdate, for the same fail-open reason on the tables
        // that have no entity column.
        $objectTypeForLog = $config['object_type'] ?? '?';
        if ($this->syncTenantDenies($config, $objectTypeForLog, (int) $id, $object->entity ?? null)) {
            dol_syslog(
                '[SmartAuth] SyncController::processDelete: cross-entity delete '
                . 'refused for ' . $objectTypeForLog . ' id=' . (int) $id,
                LOG_WARNING
            );
            return ['success' => false, 'error' => 'Object not found'];
        }

        // Delete first, tombstone after success only. A tombstone created
        // before the delete and left behind by a failed delete would tell
        // every client of the tenant to drop a row that still exists
        // server-side (audit S-7).
        //
        // delete() signatures differ across Dolibarr classes (Societe takes
        // $id first, Product/Contact/User take $user first): the direct
        // $object->delete($user) call made every id-first class fail with
        // "Object of class User could not be converted to int".
        $result = $this->callDeleteMethod($object, $user);
        if ($result > 0) {
            // The row's own entity is recorded so a client of another tenant
            // is not told about this deletion.
            $this->createTombstone($config['table'], $id, $user->id, $object->entity ?? null);
            return ['success' => true];
        }

        return ['success' => false, 'error' => $object->error ?: 'Delete failed'];
    }

    /**
     * Create a tombstone record for a deleted object.
     *
     * @param string     $table      Business table name (no prefix)
     * @param int        $object_id  Deleted row primary key
     * @param int        $user_id    Author of the deletion
     * @param mixed|null $entity     Entity of the deleted row. Null for a table
     *                               with no entity column: the tombstone stays
     *                               visible to every tenant, which is the safe
     *                               side (a missed deletion leaves a ghost row
     *                               in an offline cache).
     * @return bool                  False when the insert failed
     */
    private function createTombstone($table, $object_id, $user_id, $entity = null)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_sync_tombstones";
        $sql .= " (table_name, object_id, deleted_at, deleted_by, entity)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($table) . "', ";
        $sql .= (int) $object_id . ", ";
        $sql .= "'" . $this->db->idate(dol_now()) . "', ";
        $sql .= (int) $user_id . ", ";
        $sql .= ($entity === null ? "NULL" : (int) $entity) . ")";

        if (!$this->db->query($sql)) {
            // A lost tombstone means the deletion never reaches offline clients:
            // the row lives on in their cache for good. Never silent.
            dol_syslog(
                '[SmartAuth] SyncController::createTombstone: insert failed for '
                . $table . ' id=' . (int) $object_id . ' - ' . $this->db->lasterror(),
                LOG_ERR
            );
            return false;
        }
        return true;
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

        // Same tenant frontier as processUpdate: a conflict row records a table
        // name and an object id, nothing that proves the object is the caller's.
        $objectTypeForLog = $config['object_type'] ?? '?';
        if ($this->syncTenantDenies($config, $objectTypeForLog, (int) $id, $object->entity ?? null)) {
            dol_syslog(
                '[SmartAuth] SyncController::applyResolvedData: cross-entity write refused for '
                . $objectTypeForLog . ' id=' . (int) $id,
                LOG_WARNING
            );
            return ['success' => false, 'error' => 'Object not found'];
        }

        // Whitelist + denylist gated (CR-6 fix)
        $this->loadExistingExtrafields($object, $config, 'applyResolvedData');
        $extrafieldsApplied = false;
        $this->applyDataToObject($object, $data, $config, $extrafieldsApplied);

        // Dolibarr classes have different update() signatures:
        // - Societe, Product, Contact: update($id, $user, ...)
        // - User, Facture: update($user, ...)
        // Use reflection to detect the correct signature
        $result = $this->callUpdateMethod($object, $user);
        if ($result <= 0) {
            return ['success' => false, 'error' => $object->error ?: 'Update failed'];
        }

        if ($extrafieldsApplied && !$this->persistExtrafields($object, $config, 'applyResolvedData')) {
            return ['success' => false, 'error' => 'Failed to persist extrafields: ' . ($object->error ?: 'unknown error')];
        }

        return ['success' => true];
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
     * Call $object->delete() with the signature the class expects.
     *
     * Dolibarr core is not consistent: Societe::delete($id, User $fuser,
     * $call_trigger) takes the row id first, while Product/Contact/User take
     * the User first. Same reflection trick as callUpdateMethod().
     *
     * @param object $object Loaded Dolibarr business object
     * @param User   $user   Acting Dolibarr user
     * @return int|int<0,negative> delete() return value
     */
    private function callDeleteMethod($object, $user)
    {
        $reflection = new \ReflectionMethod($object, 'delete');
        $params = $reflection->getParameters();

        if (empty($params)) {
            return $object->delete();
        }

        $firstParam = $params[0];
        $firstParamName = $firstParam->getName();
        $firstParamType = $firstParam->getType();

        $isIdFirst = ($firstParamName === 'id')
            || ($firstParamType && in_array($firstParamType->getName(), ['int', 'integer']));

        if ($isIdFirst) {
            // Signature: delete($id, User $fuser?, $call_trigger?)
            $args = [$object->id];
            if (count($params) >= 2) {
                $args[] = $user;
            }
            return call_user_func_array([$object, 'delete'], $args);
        }

        // Signature: delete(User $user, $notrigger?)
        return $object->delete($user);
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
