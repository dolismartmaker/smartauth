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
 *   - writes go through the mapper $writableFields allowlist; unknown fields ->
 *     400 with the MapperValidationException error list.
 *   - extrafields are opt-in writable: only options_* keys the mapper allowlists
 *     via $extrafieldsRW are accepted (persisted via insertExtraFields()); any
 *     other options_* key is rejected with a 400.
 */
class ObjectController
{
    use PaginatedListTrait;

    /**
     * Resolve the {type} into its registry config + a booted dm* mapper.
     *
     * @param  array|null $payload
     * @return array{0:?array,1:?object,2:?array}  [cfg, mapper, errorTuple]. On
     *         failure cfg/mapper are null and errorTuple is a [body,code] pair.
     */
    private function resolve($payload)
    {
        global $hookmanager;

        // The object kind comes from the {objtype} route segment (named so it
        // does not clash with an object's own 'type' field). Not concatenated
        // into SQL (registry lookup is the whitelist); we still strip to
        // [a-z0-9_] to keep logs/messages clean and allow future multi-word
        // types (e.g. supplier_order).
        $type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['objtype'] ?? '')));
        $type = substr($type, 0, 64);
        if ($type === '') {
            return [null, null, [['error' => 'Object type is required'], 400]];
        }

        $cfg = ObjectRegistry::get($type, is_object($hookmanager) ? $hookmanager : null);
        if ($cfg === null) {
            dol_syslog("[SmartAuth] ObjectController: unsupported object type '" . $type . "'", LOG_WARNING);
            return [null, null, [['error' => "Unsupported object type '" . $type . "'"], 400]];
        }

        $mapperClass = isset($cfg['mapper']) ? (string) $cfg['mapper'] : '';
        if ($mapperClass === '' || !class_exists($mapperClass)) {
            dol_syslog("[SmartAuth] ObjectController: no mapper class for type '" . $type . "'", LOG_ERR);
            return [null, null, [['error' => 'No mapper registered for this object type'], 500]];
        }

        // Load the Dolibarr class BEFORE booting the mapper (its constructor
        // instantiates that class to build the field descriptor).
        if (!empty($cfg['file'])) {
            require_once $cfg['file'];
        }
        if (empty($cfg['class']) || !class_exists($cfg['class'])) {
            dol_syslog("[SmartAuth] ObjectController: Dolibarr class unavailable for type '" . $type . "'", LOG_ERR);
            return [null, null, [['error' => 'Object class unavailable'], 500]];
        }

        $mapper = new $mapperClass();
        return [$cfg, $mapper, null];
    }

    /**
     * Fail-closed permission gate: module enabled + Dolibarr right for $action.
     *
     * @param  array  $cfg
     * @param  string $action  read|create|update|delete
     * @return array|null      An error [body,code] tuple, or null when allowed.
     */
    private function authorize($cfg, $action)
    {
        global $user;

        if (!is_object($user)) {
            dol_syslog("[SmartAuth] ObjectController: no authenticated user for " . $action, LOG_WARNING);
            return [['error' => 'Authentication required'], 401];
        }

        $type = $cfg['object_type'] ?? '?';

        if (!empty($cfg['module']) && !isModEnabled($cfg['module'])) {
            dol_syslog("[SmartAuth] ObjectController: module '" . $cfg['module'] . "' disabled for type " . $type, LOG_WARNING);
            return [['error' => 'Module not enabled'], 403];
        }

        $rights = (isset($cfg['rights'][$action]) && is_array($cfg['rights'][$action])) ? $cfg['rights'][$action] : null;
        if (empty($rights)) {
            dol_syslog("[SmartAuth] ObjectController: no " . $action . " right mapping for " . $type . " - refusing (fail-closed)", LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        if (!call_user_func_array([$user, 'hasRight'], $rights)) {
            dol_syslog("[SmartAuth] ObjectController: user " . ((int) $user->id) . " lacks " . implode('->', $rights) . " for " . $action . " on " . $type, LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        return null;
    }

    /**
     * Whether a fetched object is within the current user's entity scope.
     *
     * @param  object $object
     * @param  array  $cfg
     * @return bool
     */
    private function inEntityScope($object, $cfg)
    {
        if (!isset($object->entity)) {
            return true;
        }
        $element = (string) ($cfg['element'] ?? '');
        $allowed = array_map('intval', explode(',', getEntity($element, 1)));
        $oe = (int) $object->entity;
        return $oe === 0 || in_array($oe, $allowed, true);
    }

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
        $baseFrom = " FROM " . MAIN_DB_PREFIX . $cfg['table'] . " as " . $alias;
        $baseWhere = " WHERE " . $alias . ".entity IN (" . getEntity($element) . ")";
        list($filterWhere, ) = $this->buildSqlFiltersFromCatalog($params, $mapper, $alias);
        $where = $baseWhere . $filterWhere;

        $countSql = "SELECT COUNT(" . $alias . ".rowid) as nb" . $baseFrom . $where;
        $countRes = $db->query($countSql);
        if (!$countRes) {
            dol_syslog("[SmartAuth] ObjectController::index count SQL error: " . $db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }
        $countRow = $db->fetch_object($countRes);
        $total = $countRow ? (int) $countRow->nb : 0;
        $db->free($countRes);

        $defaultSort = (string) ($cfg['default_sort'] ?? ($alias . '.rowid ASC'));
        $orderBy = $this->buildSortClauseFromCatalog($params, $mapper, $alias, $defaultSort);
        $sql = "SELECT " . $alias . ".rowid" . $baseFrom . $where . $orderBy;
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
            if ($o->fetch((int) $obj->rowid) > 0) {
                if (method_exists($o, 'fetch_optionals')) {
                    $o->fetch_optionals();
                }
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
        list($filterWhere, ) = $this->buildSqlFiltersFromCatalog($params, $mapper, $alias);

        $sql = "SELECT COUNT(" . $alias . ".rowid) as nb FROM " . MAIN_DB_PREFIX . $cfg['table'] . " as " . $alias;
        $sql .= " WHERE " . $alias . ".entity IN (" . getEntity($element) . ")" . $filterWhere;

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
        if (!$this->inEntityScope($o, $cfg)) {
            dol_syslog("[SmartAuth] ObjectController::show cross-entity read refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Access denied (entity)'], 403];
        }
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }

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
        if (!$this->inEntityScope($o, $cfg)) {
            dol_syslog("[SmartAuth] ObjectController::update cross-entity write refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Access denied (entity)'], 403];
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

        $mapper->applyImportedFields($o, $sanitized);

        $res = CrudInvoker::update($o, $user);
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
        if (!$this->inEntityScope($o, $cfg)) {
            dol_syslog("[SmartAuth] ObjectController::destroy cross-entity delete refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Access denied (entity)'], 403];
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
            if (!$this->inEntityScope($o, $cfg)) {
                dol_syslog("[SmartAuth] ObjectController::deleteBulk cross-entity delete refused id=" . $id, LOG_WARNING);
                $errors[] = ['id' => $id, 'reason' => 'Access denied (entity)'];
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
