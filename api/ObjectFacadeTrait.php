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
 * Shared plumbing for the generic object facade controllers (ObjectController,
 * ObjectLineController, ObjectActionController): type resolution against the
 * ObjectRegistry, the fail-closed permission gate, and entity-scope checking.
 *
 * Extracted so the CRUD, line and workflow-action controllers apply IDENTICAL
 * authorization and scoping rules -- diverging copies would be a security risk.
 */
trait ObjectFacadeTrait
{
    /**
     * Resolve the {objtype} route segment into its registry config + a booted
     * dm* mapper.
     *
     * @param  array|null $payload
     * @return array{0:?array,1:?object,2:?array}  [cfg, mapper, errorTuple]. On
     *         failure cfg/mapper are null and errorTuple is a [body,code] pair.
     */
    protected function resolve($payload)
    {
        global $hookmanager;

        // The object kind comes from the {objtype} route segment (named so it
        // does not clash with an object's own 'type' field). Not concatenated
        // into SQL (registry lookup is the whitelist); we still strip to
        // [a-z0-9_] to keep logs/messages clean and allow multi-word types.
        $type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['objtype'] ?? '')));
        $type = substr($type, 0, 64);
        if ($type === '') {
            return [null, null, [['error' => 'Object type is required'], 400]];
        }

        $cfg = ObjectRegistry::get($type, is_object($hookmanager) ? $hookmanager : null);
        if ($cfg === null) {
            dol_syslog("[SmartAuth] ObjectFacade: unsupported object type '" . $type . "'", LOG_WARNING);
            return [null, null, [['error' => "Unsupported object type '" . $type . "'"], 400]];
        }

        $mapperClass = isset($cfg['mapper']) ? (string) $cfg['mapper'] : '';
        if ($mapperClass === '' || !class_exists($mapperClass)) {
            dol_syslog("[SmartAuth] ObjectFacade: no mapper class for type '" . $type . "'", LOG_ERR);
            return [null, null, [['error' => 'No mapper registered for this object type'], 500]];
        }

        // Load the Dolibarr class BEFORE booting the mapper (its constructor
        // instantiates that class to build the field descriptor).
        if (!empty($cfg['file'])) {
            require_once $cfg['file'];
        }
        if (empty($cfg['class']) || !class_exists($cfg['class'])) {
            dol_syslog("[SmartAuth] ObjectFacade: Dolibarr class unavailable for type '" . $type . "'", LOG_ERR);
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
    protected function authorize($cfg, $action)
    {
        global $user;

        if (!is_object($user)) {
            dol_syslog("[SmartAuth] ObjectFacade: no authenticated user for " . $action, LOG_WARNING);
            return [['error' => 'Authentication required'], 401];
        }

        $type = $cfg['object_type'] ?? '?';

        if (!empty($cfg['module']) && !isModEnabled($cfg['module'])) {
            dol_syslog("[SmartAuth] ObjectFacade: module '" . $cfg['module'] . "' disabled for type " . $type, LOG_WARNING);
            return [['error' => 'Module not enabled'], 403];
        }

        $rights = (isset($cfg['rights'][$action]) && is_array($cfg['rights'][$action])) ? $cfg['rights'][$action] : null;
        if (empty($rights)) {
            dol_syslog("[SmartAuth] ObjectFacade: no " . $action . " right mapping for " . $type . " - refusing (fail-closed)", LOG_WARNING);
            return [['error' => 'Access denied'], 403];
        }

        if (!call_user_func_array([$user, 'hasRight'], $rights)) {
            dol_syslog("[SmartAuth] ObjectFacade: user " . ((int) $user->id) . " lacks " . implode('->', $rights) . " for " . $action . " on " . $type, LOG_WARNING);
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
    protected function inEntityScope($object, $cfg)
    {
        if (!isset($object->entity)) {
            return true;
        }
        $element = (string) ($cfg['element'] ?? '');
        $allowed = array_map('intval', explode(',', getEntity($element, 1)));
        $oe = (int) $object->entity;
        return $oe === 0 || in_array($oe, $allowed, true);
    }
}
