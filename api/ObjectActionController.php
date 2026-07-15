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

/**
 * Generic REST facade for the WORKFLOW ACTIONS of core Dolibarr documents
 * (validate / setDraft / close / cancel / setPaid ...), driven by the
 * {objtype} and {action} route segments.
 *
 * Route:
 *   POST objects/{objtype}/{id}/actions/{action}   -> invoke
 *
 * Contract:
 *   - the type's registry config must declare the action in its 'actions' list
 *     (default-deny: an action not listed is a 400, never dispatched).
 *   - the concrete Dolibarr call is resolved by DocumentActionInvoker, which
 *     absorbs the divergent method names/signatures per class.
 *   - gated by the type's 'update' right (a state transition is an edit) and by
 *     entity scope, via ObjectFacadeTrait.
 *   - optional inputs (note, close_code, close_note) are read from the body.
 *   - the response is the full re-exported document (with lines) so the client
 *     sees the new status and recomputed fields.
 */
class ObjectActionController
{
    use ObjectFacadeTrait;

    /**
     * POST objects/{objtype}/{id}/actions/{action}.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function invoke($payload = null)
    {
        global $db, $user;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return $err;
        }

        $allowed = (isset($cfg['actions']) && is_array($cfg['actions'])) ? $cfg['actions'] : [];
        if (empty($allowed)) {
            dol_syslog("[SmartAuth] ObjectActionController: type '" . ($cfg['object_type'] ?? '?') . "' declares no workflow actions", LOG_WARNING);
            return [['error' => 'This object type has no workflow actions'], 400];
        }

        $auth = $this->authorize($cfg, 'update');
        if ($auth !== null) {
            return $auth;
        }

        $action = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['action'] ?? '')));
        if ($action === '') {
            return [['error' => 'Action is required'], 400];
        }
        if (!in_array($action, $allowed, true)) {
            dol_syslog("[SmartAuth] ObjectActionController: action '" . $action . "' not allowed for type '" . ($cfg['object_type'] ?? '?') . "'", LOG_WARNING);
            return [['error' => "Unsupported action '" . $action . "' for this object type"], 400];
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
            dol_syslog("[SmartAuth] ObjectActionController: cross-entity action refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [['error' => 'Access denied (entity)'], 403];
        }

        $params = is_array($payload) ? $payload : [];
        $res = DocumentActionInvoker::run($o, $user, $action, $params);

        if ($res === DocumentActionInvoker::UNKNOWN) {
            dol_syslog("[SmartAuth] ObjectActionController: no invoker mapping for " . get_class($o) . ":" . $action, LOG_ERR);
            return [['error' => "Action '" . $action . "' is not implemented for this object"], 400];
        }
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectActionController: action '" . $action . "' failed for " . ($cfg['object_type'] ?? '?') . " id=" . $id . ": " . $o->error, LOG_ERR);
            return [['error' => "Failed to run action '" . $action . "': " . $o->error], 400];
        }

        // Re-export the document (with lines) so the client sees the new state.
        $o->fetch($id);
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }

        return [$mapper->exportMappedData($o), 200];
    }
}
