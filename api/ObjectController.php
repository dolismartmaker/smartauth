<?php

/**
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
 */

namespace SmartAuth\Api;

use SmartAuth\DolibarrMapping\MapperValidationException;

/**
 * Generic synchronous REST facade for core Dolibarr objects, driven by the
 * {type} route segment and backed by the dm* mappers + ObjectRegistry.
 *
 * One controller serves every registered type instead of each PWA module
 * reimplementing the same CRUD/search/pagination. Routes:
 *
 *   GET    objects/{objtype}            -> index    (paginated list + filters/sort/search)
 *   GET    objects/{objtype}/count      -> count    (total for current filters)
 *   GET    objects/{objtype}/columns    -> columns  (dm* column catalog)
 *   GET    objects/{objtype}/describe   -> describe (objectDesc() field schema)
 *   GET    objects/{objtype}/{id}       -> show     (exportMappedData)
 *   POST   objects/{objtype}            -> create   (importMappedData + create)
 *   PATCH  objects/{objtype}/{id}       -> update   (importMappedData + update)
 *   DELETE objects/{objtype}/{id}       -> destroy  (delete one)
 *   DELETE objects/{objtype}            -> deleteBulk({ids:[...]})
 *
 * Contract:
 *   - every method returns a [mixed $body, int $httpCode] tuple; RouteController
 *     turns it into the JSON response (controllers never call json_reply()).
 *   - permissions are fail-closed via the registry 'rights' map.
 *   - entity scoping is systematic (list WHERE + per-object check on read/write).
 *   - a single-object route refused for scoping/isolation/visibility answers
 *     404 "Object not found", never 403: telling those apart from an unknown id
 *     would let a caller enumerate other tenants' rowids. The reason is logged.
 *   - writes go through the mapper $writableFields allowlist; unknown fields ->
 *     400 with the MapperValidationException error list.
 *   - extrafields are opt-in writable: only options_* keys the mapper allowlists
 *     via $extrafieldsRW are accepted (persisted via insertExtraFields()); any
 *     other options_* key is rejected with a 400.
 */
class ObjectController
{
    use ObjectFacadeTrait;
    use PaginatedListTrait;

    /**
     * Parse the optional ?include=col1,col2 into a whitelist of appside keys.
     *
     * @param  array|null $payload
     * @return array<int,string>|null  null when absent (keep full export).
     */
    private function parseIncludeKeys($payload)
    {
        if (!is_array($payload) || empty($payload['include'])) {
            return null;
        }
        $keys = [];
        foreach (explode(',', (string) $payload['include']) as $k) {
            $k = trim($k);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
        return empty($keys) ? null : $keys;
    }

    /**
     * Whether the sanitized payload carries any extrafield (options_*), so the
     * controller knows to persist $object->array_options via insertExtraFields().
     *
     * @param  \stdClass $sanitized
     * @return bool
     */
    private function sanitizedHasExtrafields($sanitized)
    {
        foreach (get_object_vars($sanitized) as $key => $value) {
            if (strncmp((string) $key, 'options_', 8) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET objects/{objtype} -- paginated list.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function index($payload = null)
    {
        global $db;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'read');
        if ($auth !== null) {
            return $auth;
        }

        $params = $this->parseListParams($payload);
        $includeKeys = $this->parseIncludeKeys($payload);

        $alias = (string) ($cfg['alias'] ?? 't');
        $element = (string) ($cfg['element'] ?? '');
        // Primary key column: most Dolibarr tables use 'rowid', but a few (e.g.
        // llx_actioncomm) use 'id'. The registry declares it per type; default
        // 'rowid'. Aliased back to "rowid" in the SELECT so downstream code
        // (fetch loop, catalog) keeps reading $obj->rowid unchanged.
        $pk = (string) ($cfg['pk'] ?? 'rowid');
        $baseFrom = " FROM " . MAIN_DB_PREFIX . $cfg['table'] . " as " . $alias;
        // A few Dolibarr tables (llx_stock_mouvement, llx_subscription) have no
        // 'entity' column; the registry flags them has_entity=false so we do not
        // emit an entity filter that would be a SQL error.
        $hasEntity = !array_key_exists('has_entity', $cfg) || $cfg['has_entity'] !== false;
        $baseWhere = $hasEntity
            ? " WHERE " . $alias . ".entity IN (" . getEntity($element) . ")"
            : " WHERE 1=1";
        list($filterWhere, ) = $this->buildSqlFiltersFromCatalog($params, $mapper, $alias);
        $where = $baseWhere . $filterWhere
            . $this->isolationWhereFragment($mapper, $alias, $cfg)
            . $this->visibilitySqlFragment($mapper, $alias);

        $countSql = "SELECT COUNT(" . $alias . "." . $pk . ") as nb" . $baseFrom . $where;
        $countRes = $db->query($countSql);
        if (!$countRes) {
            dol_syslog("[SmartAuth] ObjectController::index count SQL error: " . $db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }
        $countRow = $db->fetch_object($countRes);
        $total = $countRow ? (int) $countRow->nb : 0;
        $db->free($countRes);

        $defaultSort = (string) ($cfg['default_sort'] ?? ($alias . '.' . $pk . ' ASC'));
        $orderBy = $this->buildSortClauseFromCatalog($params, $mapper, $alias, $defaultSort);

        // COMPACT PATH. When ?include= names only real columns, the page can be
        // served from the list query alone: no fetch() and no fetch_optionals()
        // per row, i.e. 1 query instead of 1 + 2N. The mapper decides, and it
        // fails closed on anything it cannot guarantee -- see
        // dmBase::supportsCompactProjection and
        // documentation/facade-list-performance.md for the measurement that
        // motivates this (x276 on a 50-row page).
        // SMARTAUTH_FACADE_COMPACT_LIST=0 forces every list back onto the full
        // path: an escape hatch if a mapper ever turns out to need the complete
        // fetch, without waiting for a release.
        $compact = getDolGlobalInt('SMARTAUTH_FACADE_COMPACT_LIST', 1) === 1
            && method_exists($mapper, 'supportsCompactProjection')
            && $mapper->supportsCompactProjection($includeKeys);

        $selectList = $compact
            ? $alias . ".*, " . $alias . "." . $pk . " as rowid"
            : $alias . "." . $pk . " as rowid";
        $sql = "SELECT " . $selectList . $baseFrom . $where . $orderBy;
        $sql .= $db->plimit((int) $params['limit'], (int) $params['offset']);

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectController::index page SQL error: " . $db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        $classname = $cfg['class'];
        $items = [];
        while ($obj = $db->fetch_object($resql)) {
            $o = new $classname($db);

            if ($compact) {
                // setVarsFromFetchObj applies the type conversions declared in
                // $fields (dates through jdate, ints, floats), which is exactly
                // what fetch() would have done for those columns.
                $o->setVarsFromFetchObj($obj);
                if (empty($o->id)) {
                    $o->id = (int) $obj->rowid;
                }
                $items[] = $mapper->exportMappedDataFiltered($o, $includeKeys);
                continue;
            }

            if ($o->fetch((int) $obj->rowid) > 0) {
                if (method_exists($o, 'fetch_optionals')) {
                    $o->fetch_optionals();
                }
                // Same shape as show() for a line-bearing type. Free for the
                // classes whose fetch() already loaded them (invoice, order,
                // proposal): only Contrat actually pays a query here, and it is
                // the query that makes its rows carry lines and totals like the
                // others already do.
                $this->loadLines($o, $cfg);
                $items[] = $mapper->exportMappedDataFiltered($o, $includeKeys);
            }
        }
        $db->free($resql);

        return [
            $this->formatPaginatedResponse($items, $total, (int) $params['page'], (int) $params['limit']),
            200,
        ];
    }

    /**
     * GET objects/{objtype}/count -- total matching the current filters/search.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function count($payload = null)
    {
        global $db;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'read');
        if ($auth !== null) {
            return $auth;
        }

        $params = $this->parseListParams($payload);
        $alias = (string) ($cfg['alias'] ?? 't');
        $element = (string) ($cfg['element'] ?? '');
        $pk = (string) ($cfg['pk'] ?? 'rowid');
        list($filterWhere, ) = $this->buildSqlFiltersFromCatalog($params, $mapper, $alias);
        $hasEntity = !array_key_exists('has_entity', $cfg) || $cfg['has_entity'] !== false;
        $baseWhere = $hasEntity
            ? " WHERE " . $alias . ".entity IN (" . getEntity($element) . ")"
            : " WHERE 1=1";

        $sql = "SELECT COUNT(" . $alias . "." . $pk . ") as nb FROM " . MAIN_DB_PREFIX . $cfg['table'] . " as " . $alias;
        $sql .= $baseWhere . $filterWhere
            . $this->isolationWhereFragment($mapper, $alias, $cfg)
            . $this->visibilitySqlFragment($mapper, $alias);

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectController::count SQL error: " . $db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }
        $row = $db->fetch_object($resql);
        $total = $row ? (int) $row->nb : 0;
        $db->free($resql);

        return [['total' => $total], 200];
    }

    /**
     * GET objects/{objtype}/columns -- normalized column catalog (single source of
     * truth for filter/sort whitelists).
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function columns($payload = null)
    {
        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'read');
        if ($auth !== null) {
            return $auth;
        }

        return [$mapper->getColumnCatalog(), 200];
    }

    /**
     * GET objects/{objtype}/describe -- per-field metadata (objectDesc) for forms.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function describe($payload = null)
    {
        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'read');
        if ($auth !== null) {
            return $auth;
        }

        return [$mapper->objectDesc(), 200];
    }

    /**
     * GET objects/{objtype}/{id} -- single object.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function show($payload = null)
    {
        global $db;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'read');
        if ($auth !== null) {
            return $auth;
        }

        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return [['error' => 'Object id is required'], 400];
        }

        $classname = $cfg['class'];
        $o = new $classname($db);
        if ($o->fetch($id) <= 0) {
            return [['error' => 'Object not found'], 404];
        }
        // Refusals answer 404, exactly like an unknown id: a distinct 403 would
        // be an existence oracle (enumerate rowids, tell "exists elsewhere"
        // apart from "does not exist"). The syslog lines below keep the real
        // reason server-side.
        if (!$this->inEntityScope($o, $cfg)) {
            dol_syslog("[SmartAuth] ObjectController::show cross-entity read refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->isolationDenies($cfg, $mapper, $id)) {
            dol_syslog("[SmartAuth] ObjectController::show isolation refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->visibilityDenies($mapper, $o, 'read')) {
            dol_syslog("[SmartAuth] ObjectController::show visibility refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id . " user=" . ((int) $GLOBALS['user']->id), LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }
        // Contrat::fetch() reads the header only, unlike Facture/Commande/Propal.
        $this->loadLines($o, $cfg);

        return [$mapper->exportMappedData($o), 200];
    }

    /**
     * POST objects/{objtype} -- create.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function create($payload = null)
    {
        global $db, $user;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'create');
        if ($auth !== null) {
            return $auth;
        }

        $body = is_array($payload) ? $payload : [];
        // Strip the facade routing keys before the mapper allowlist check.
        unset($body['objtype'], $body['id']);

        try {
            $sanitized = $mapper->importMappedData($body);
        } catch (MapperValidationException $e) {
            dol_syslog("[SmartAuth] ObjectController::create rejected payload for " . ($cfg['object_type'] ?? '?') . ": " . json_encode($e->getErrors()), LOG_WARNING);
            return [['errors' => $e->getErrors()], 400];
        }

        // Tenant guard on the VALUES, not just the field names: a payload may
        // not point a foreign key at another tenant's row. Same 404 as every
        // other scope refusal of the facade -- a 403 would confirm the id
        // exists somewhere else, which is the oracle we refuse to be.
        $fkField = $this->foreignKeyViolation($mapper, $sanitized, $cfg);
        if ($fkField !== null) {
            dol_syslog("[SmartAuth] ObjectController::create cross-tenant foreign key refused on " . ($cfg['object_type'] ?? '?') . "." . $fkField . " user=" . ((int) $user->id), LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }

        // Optional pre-create authorization a mapper may declare (mechanism 3.2,
        // create side): the entity right (authorize('create')) is coarse -- a
        // sub-object may need finer access on its PARENT (a task under a project
        // the user may not write to). Companion of canAccess() for the one route
        // with no target object yet. A mapper opts in with:
        //   public function canCreate($sanitized, $user): bool
        if (method_exists($mapper, 'canCreate') && $mapper->canCreate($sanitized, $user) !== true) {
            dol_syslog("[SmartAuth] ObjectController::create visibility refused for " . ($cfg['object_type'] ?? '?') . " user=" . ((int) $user->id), LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        $classname = $cfg['class'];
        $o = new $classname($db);
        $mapper->applyImportedFields($o, $sanitized);

        $res = CrudInvoker::create($o, $user);
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectController::create failed for " . ($cfg['object_type'] ?? '?') . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to create object: ' . $o->error], 400];
        }

        // Persist allowlisted extrafields (array_options set by applyImportedFields)
        // before the re-fetch wipes them. insertExtraFields() upserts, so a second
        // call after a class whose create() already did it stays harmless.
        if ($this->sanitizedHasExtrafields($sanitized) && $o->insertExtraFields() < 0) {
            dol_syslog("[SmartAuth] ObjectController::create insertExtraFields failed for " . ($cfg['object_type'] ?? '?') . ": " . $o->error, LOG_ERR);
            return [['error' => 'Object created but failed to persist extrafields: ' . $o->error], 500];
        }

        // Optional create side-effects a mapper may declare (mechanism 3.6):
        //   public function postCreate($object, $user): void
        // Some Dolibarr create() do NOT do everything the module does on the
        // "add" screen -- notably ref generation via the numbering addon and the
        // default internal contact (project -> PROJECTLEADER, task ->
        // TASKEXECUTIVE). The mapper replays those here, on the freshly created
        // object (its id is set), BEFORE the re-fetch that returns the payload.
        if (method_exists($mapper, 'postCreate')) {
            $mapper->postCreate($o, $user);
        }

        $o->fetch($res);
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }

        return [$mapper->exportMappedData($o), 201];
    }

    /**
     * PATCH objects/{objtype}/{id} -- update.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function update($payload = null)
    {
        global $db, $user;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'update');
        if ($auth !== null) {
            return $auth;
        }

        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return [['error' => 'Object id is required'], 400];
        }

        $classname = $cfg['class'];
        $o = new $classname($db);
        if ($o->fetch($id) <= 0) {
            return [['error' => 'Object not found'], 404];
        }
        // 404 on every refusal, cf show(): no existence oracle.
        if (!$this->inEntityScope($o, $cfg, 'write')) {
            dol_syslog("[SmartAuth] ObjectController::update cross-entity write refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->isolationDenies($cfg, $mapper, $id)) {
            dol_syslog("[SmartAuth] ObjectController::update isolation refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->visibilityDenies($mapper, $o, 'write')) {
            dol_syslog("[SmartAuth] ObjectController::update visibility refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id . " user=" . ((int) $GLOBALS['user']->id), LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }

        $body = is_array($payload) ? $payload : [];
        unset($body['objtype'], $body['id']);

        try {
            $sanitized = $mapper->importMappedData($body);
        } catch (MapperValidationException $e) {
            dol_syslog("[SmartAuth] ObjectController::update rejected payload for " . ($cfg['object_type'] ?? '?') . ": " . json_encode($e->getErrors()), LOG_WARNING);
            return [['errors' => $e->getErrors()], 400];
        }

        // Tenant guard on the VALUES. The three checks above all ran on the row
        // BEFORE the write -- they say "this invoice is mine", never "the socid
        // you send me is mine". Without this, a PATCH carrying another tenant's
        // socid was written verbatim. $o is passed so a partial payload can be
        // judged together with the sibling fields it does not restate.
        $fkField = $this->foreignKeyViolation($mapper, $sanitized, $cfg, $o);
        if ($fkField !== null) {
            dol_syslog("[SmartAuth] ObjectController::update cross-tenant foreign key refused on " . ($cfg['object_type'] ?? '?') . "." . $fkField . " id=" . $id . " user=" . ((int) $user->id), LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }

        // Most Dolibarr classes expose a generic update($user)/update($id,$user).
        // A few (notably SupplierProposal) do NOT -- their header is persisted
        // through dedicated setters (update_note, setPaymentTerms, ...). Such a
        // mapper may declare updateViaSetters() to replay those setters; we use it
        // as a fallback ONLY when there is no generic update(). Types that have
        // neither still get the historical clean 400 (no fatal).
        $hasGenericUpdate = method_exists($o, 'update');
        $hasSetterUpdate  = method_exists($mapper, 'updateViaSetters');
        if (!$hasGenericUpdate && !$hasSetterUpdate) {
            dol_syslog("[SmartAuth] ObjectController::update: type " . ($cfg['object_type'] ?? '?') . " (" . get_class($o) . ") has no generic update() and no setter fallback", LOG_WARNING);
            return [['error' => 'This object type does not support update'], 400];
        }

        $mapper->applyImportedFields($o, $sanitized);

        $res = $hasGenericUpdate
            ? CrudInvoker::update($o, $user)
            : $mapper->updateViaSetters($o, $sanitized, $user);
        if ($res < 0) {
            dol_syslog("[SmartAuth] ObjectController::update failed for " . ($cfg['object_type'] ?? '?') . " id=" . $id . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to update object: ' . $o->error], 400];
        }

        if ($this->sanitizedHasExtrafields($sanitized) && $o->insertExtraFields() < 0) {
            dol_syslog("[SmartAuth] ObjectController::update insertExtraFields failed for " . ($cfg['object_type'] ?? '?') . " id=" . $id . ": " . $o->error, LOG_ERR);
            return [['error' => 'Object updated but failed to persist extrafields: ' . $o->error], 500];
        }

        $o->fetch($id);
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }

        return [$mapper->exportMappedData($o), 200];
    }

    /**
     * DELETE objects/{objtype}/{id} -- delete one.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function destroy($payload = null)
    {
        global $db, $user;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'delete');
        if ($auth !== null) {
            return $auth;
        }

        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return [['error' => 'Object id is required'], 400];
        }

        $classname = $cfg['class'];
        $o = new $classname($db);
        if ($o->fetch($id) <= 0) {
            return [['error' => 'Object not found'], 404];
        }
        // 404 on every refusal, cf show(): no existence oracle.
        if (!$this->inEntityScope($o, $cfg, 'delete')) {
            dol_syslog("[SmartAuth] ObjectController::destroy cross-entity delete refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->isolationDenies($cfg, $mapper, $id)) {
            dol_syslog("[SmartAuth] ObjectController::destroy isolation refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($this->visibilityDenies($mapper, $o, 'delete')) {
            dol_syslog("[SmartAuth] ObjectController::destroy visibility refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id . " user=" . ((int) $user->id), LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }

        $res = CrudInvoker::delete($o, $user);
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectController::destroy failed for " . ($cfg['object_type'] ?? '?') . " id=" . $id . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to delete object: ' . $o->error], 400];
        }

        return [['message' => 'Object deleted', 'id' => $id], 200];
    }

    /**
     * DELETE objects/{objtype} -- bulk delete. Body: {ids:[...]}, max 100. Each id
     * is attempted independently; a partial failure does not roll back the
     * successful deletions.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function deleteBulk($payload = null)
    {
        global $db, $user;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }
        $auth = $this->authorize($cfg, 'delete');
        if ($auth !== null) {
            return $auth;
        }

        $rawIds = (is_array($payload) && isset($payload['ids']) && is_array($payload['ids'])) ? $payload['ids'] : null;
        if ($rawIds === null) {
            dol_syslog("[SmartAuth] ObjectController::deleteBulk missing 'ids' for " . ($cfg['object_type'] ?? '?'), LOG_WARNING);
            return [['error' => "Body must include an 'ids' array of integers"], 400];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            $idInt = (int) $rawId;
            if ($idInt > 0) {
                $ids[] = $idInt;
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return [['error' => "'ids' must contain at least one positive integer"], 400];
        }
        if (count($ids) > 100) {
            return [['error' => 'Too many ids (max 100)'], 400];
        }

        $classname = $cfg['class'];
        $success = [];
        $errors = [];

        foreach ($ids as $id) {
            $o = new $classname($db);
            if ($o->fetch($id) <= 0) {
                $errors[] = ['id' => $id, 'reason' => 'Object not found'];
                continue;
            }
            // Same reason string as an unknown id, cf show(): the per-id report
            // must not tell "exists in another tenant" apart from "does not
            // exist". The syslog lines keep the real reason server-side.
            if (!$this->inEntityScope($o, $cfg, 'delete')) {
                dol_syslog("[SmartAuth] ObjectController::deleteBulk cross-entity delete refused id=" . $id, LOG_WARNING);
                $errors[] = ['id' => $id, 'reason' => 'Object not found'];
                continue;
            }
            if ($this->isolationDenies($cfg, $mapper, $id)) {
                dol_syslog("[SmartAuth] ObjectController::deleteBulk isolation refused id=" . $id, LOG_WARNING);
                $errors[] = ['id' => $id, 'reason' => 'Object not found'];
                continue;
            }
            if ($this->visibilityDenies($mapper, $o, 'delete')) {
                dol_syslog("[SmartAuth] ObjectController::deleteBulk visibility refused id=" . $id, LOG_WARNING);
                $errors[] = ['id' => $id, 'reason' => 'Object not found'];
                continue;
            }
            $res = CrudInvoker::delete($o, $user);
            if ($res <= 0) {
                $reason = ($o->error !== '' && $o->error !== null) ? $o->error : 'Failed to delete';
                dol_syslog("[SmartAuth] ObjectController::deleteBulk failed id=" . $id . ": " . $reason, LOG_ERR);
                $errors[] = ['id' => $id, 'reason' => $reason];
                continue;
            }
            $success[] = $id;
        }

        return [['success' => $success, 'errors' => $errors], 200];
    }
}
