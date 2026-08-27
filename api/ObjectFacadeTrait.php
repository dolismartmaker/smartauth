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
 * ObjectLineController, ObjectActionController, ObjectPaymentController): type
 * resolution against the ObjectRegistry, the fail-closed permission gate, and
 * entity-scope checking.
 *
 * Extracted so the CRUD, line, workflow-action and payment controllers apply
 * IDENTICAL authorization and scoping rules -- diverging copies would be a
 * security risk. They share the same registry and the same URL space, so a
 * refusal must also LOOK identical from the outside: every scope refusal is a
 * 404 'Object not found', never a 403, or the odd one out becomes an existence
 * oracle for the whole facade.
 */
trait ObjectFacadeTrait
{
    // "Does this row belong to my tenant?" -- the frontier itself
    // (entityIsReachable), its SQL forms (isolationWhereFragment /
    // isolationDenies) and the write-side foreign-key guard
    // (foreignKeyViolation) live in a trait of their own, because the
    // synchronous facade is not the only door writing these columns:
    // SyncController (POST sync/push) composes the SAME trait. Two copies of a
    // tenant frontier eventually disagree, and silently.
    use ForeignKeyGuardTrait;

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

        // Instance-level opt-in. The SAME answer as an unknown type on purpose:
        // a distinct code would tell a caller which types exist but are closed,
        // which is the existence oracle every refusal of this facade avoids.
        // The syslog line keeps the two apart server-side.
        if (!$this->facadeTypeIsExposed($type)) {
            dol_syslog(
                "[SmartAuth] ObjectFacade: type '" . $type . "' is registered but absent from "
                . "SMARTAUTH_FACADE_TYPES - refusing",
                LOG_WARNING
            );
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
     * Is this type exposed by the facade on this instance?
     *
     * WHAT THIS BOUNDS, and what it does not. The registry is global: 26 built-in
     * types plus whatever the hook adds, and the only granularity used to be
     * isModEnabled() plus the caller's Dolibarr rights. So installing a module
     * that needs thirdparties also opened invoices and members to its token,
     * whenever the user happened to hold those rights. Not a hole -- the rights
     * do apply -- but wider than the spec announced (TODO section 9.2).
     *
     * SMARTAUTH_FACADE_TYPES closes that gap where it belongs: at the INSTANCE
     * level. A CSV of registry type names, empty or unset meaning "every
     * registered type", which is the historical behaviour and stays the default
     * -- narrowing an existing install silently would break its consumers.
     *
     * It deliberately does NOT bound per token or per OAuth client: that is what
     * scopes are for, and duplicating it here would leave two authorization
     * mechanisms to keep in agreement (see the two-silo decision). It does not
     * bound the sync engine either, which already has its own per-client opt-in
     * (sync_scope, default_enabled, GET /sync/objects).
     *
     * Reading is one constant lookup: the parsed set is memoised against the raw
     * string, so an admin changing the value takes effect on the next request
     * without a cache to flush, and a test flipping it needs no reset hook.
     *
     * @param  string $type  Registry type name, already normalized by resolve().
     * @return bool
     */
    protected function facadeTypeIsExposed($type)
    {
        static $cache = ['raw' => null, 'allowed' => null, 'parsed' => false];

        $raw = trim((string) getDolGlobalString('SMARTAUTH_FACADE_TYPES', ''));

        if (!$cache['parsed'] || $cache['raw'] !== $raw) {
            $cache['raw'] = $raw;
            $cache['parsed'] = true;
            $cache['allowed'] = null;

            if ($raw !== '') {
                $allowed = [];
                foreach (explode(',', $raw) as $entry) {
                    // Same normalisation resolve() applies to the route segment,
                    // so 'Thirdparty' and ' thirdparty ' both name the type they
                    // obviously mean rather than silently closing it.
                    $entry = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $entry));
                    if ($entry !== '') {
                        $allowed[$entry] = true;
                    }
                }
                // A list that normalises to nothing (e.g. ",,,") is a typo, and
                // an allowlist reduced to the empty set closes the whole facade.
                // Say it once, loudly, instead of leaving an admin to wonder why
                // every route answers "unsupported object type".
                if (empty($allowed)) {
                    dol_syslog(
                        "[SmartAuth] ObjectFacade: SMARTAUTH_FACADE_TYPES is set to '" . $raw
                        . "' which names no valid type - the whole facade is closed",
                        LOG_ERR
                    );
                }
                $cache['allowed'] = $allowed;
            }
        }

        if ($cache['allowed'] === null) {
            return true;
        }

        return isset($cache['allowed'][$type]);
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
     * FAIL-CLOSED. Several core fetch() implementations drop the entity clause
     * when the object is loaded by rowid ("Don't use entity if you use rowid"),
     * and a few do not hydrate ->entity at all. Trusting the object was
     * therefore a hole: an unhydrated ->entity used to be accepted as local, so
     * any tenant could reach another tenant's row by guessing its id. When the
     * property is missing we now read the column straight from the registry
     * table, and a value we cannot resolve is a REFUSAL.
     *
     * entity = 0 is no longer accepted unconditionally either. It stays valid
     * only for the elements Dolibarr itself declares global -- getEntity()
     * prefixes "0," for user, usergroup, cronjob and the mail templates (see the
     * $addzero array in htdocs/core/lib/functions.lib.php) -- and only in READ.
     * Those entity-0 rows are SHARED across every tenant: a tenant admin holding
     * user->user->creer would otherwise rename, disable or delete the shared
     * superadmin through this facade. Writing and deleting therefore require the
     * row to carry a real, non-zero entity.
     *
     * DELIBERATE DIVERGENCE, do not "align" it. The consumer modules (Dolipocket
     * TenantGuardTrait) compare with a STRICT equality to $conf->entity, because
     * such a SaaS runs without Multicompany and shares strictly nothing. This
     * trait lives in smartauth, a library that also runs UNDER Multicompany where
     * an entity legitimately reads the entities it is shared with. It therefore
     * tests membership of getEntity() -- the very set Dolibarr builds, and that
     * Multicompany widens through its own getEntity(). Replacing this membership
     * by a strict equality would break every shared setup; replacing the
     * consumer's strict equality by this membership would reopen its tenant
     * isolation. Both are right in their own place.
     *
     * @param  object $object
     * @param  array  $cfg
     * @param  string $mode  read|write|delete. Defaults to 'read', which keeps
     *                       the historical behaviour for any caller not updated.
     * @return bool
     */
    protected function inEntityScope($object, $cfg, $mode = 'read')
    {
        $type = (string) ($cfg['object_type'] ?? '?');

        // Tables with no 'entity' column at all (llx_stock_mouvement,
        // llx_subscription): there is nothing to read here, their isolation is
        // enforced by isolationWhereSql()/isolationDenies(), which is MANDATORY
        // for such a type (see isolationDenies, fail-closed).
        if (array_key_exists('has_entity', $cfg) && $cfg['has_entity'] === false) {
            return true;
        }

        $oe = isset($object->entity) ? (int) $object->entity : 0;
        if ($oe <= 0) {
            $oe = $this->entityFromRegistryTable($object, $cfg);
            if ($oe === null) {
                dol_syslog("[SmartAuth] ObjectFacade: cannot resolve entity for " . $type . " - refusing (fail-closed)", LOG_ERR);
                return false;
            }
        }

        // Shared (entity 0) rows are readable but never writable/deletable here.
        if ($oe === 0 && $mode !== 'read') {
            dol_syslog("[SmartAuth] ObjectFacade: " . $mode . " refused on shared entity 0 row for " . $type . " id=" . ((is_object($object) && isset($object->id)) ? (int) $object->id : 0), LOG_WARNING);
            return false;
        }

        return $this->entityIsReachable($oe, (string) ($cfg['element'] ?? ''));
    }

    /**
     * Translate a facade permission action into the entity-scope mode.
     *
     * The permission vocabulary (read|create|update|delete) is finer than what
     * the scope check needs (read vs. any mutation vs. delete), and 'create'
     * never reaches inEntityScope() with a fetched object; anything that is not
     * an explicit read is treated as a mutation, fail-closed.
     *
     * @param  string $action  read|create|update|delete
     * @return string          read|write|delete
     */
    protected function entityScopeMode($action)
    {
        if ($action === 'read') {
            return 'read';
        }
        if ($action === 'delete') {
            return 'delete';
        }
        return 'write';
    }

    /**
     * Read the 'entity' column of a fetched object straight from its own table.
     *
     * Fallback of inEntityScope() for the classes whose fetch() leaves ->entity
     * unhydrated. The table and primary key come from the registry (developer
     * -controlled configuration, never request input); only the id is user
     * input and it is cast to int.
     *
     * The registry key 'table' is therefore MANDATORY, including for a type
     * registered through the hook: without it this fallback cannot run and the
     * caller refuses the object (a 404 on the single-object routes).
     *
     * @param  object $object
     * @param  array  $cfg
     * @return int|null  The entity, or null when it cannot be resolved (missing
     *                   column, unknown row, SQL error) -- the caller refuses.
     */
    protected function entityFromRegistryTable($object, $cfg)
    {
        global $db;

        $type = (string) ($cfg['object_type'] ?? '?');
        $table = (string) ($cfg['table'] ?? '');
        $pk = (string) ($cfg['pk'] ?? 'rowid');
        $id = (is_object($object) && isset($object->id)) ? (int) $object->id : 0;

        if ($table === '' || $pk === '' || $id <= 0) {
            dol_syslog("[SmartAuth] ObjectFacade: entity lookup impossible for " . $type . " (table/pk/id missing)", LOG_ERR);
            return null;
        }

        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . $table . " WHERE " . $pk . " = " . $id;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectFacade: entity lookup failed for " . $type . " on " . $table . ": " . $db->lasterror(), LOG_ERR);
            return null;
        }
        $row = $db->fetch_object($resql);
        $db->free($resql);
        if (!$row || !isset($row->entity)) {
            dol_syslog("[SmartAuth] ObjectFacade: no entity column value for " . $type . " id=" . $id, LOG_ERR);
            return null;
        }

        return (int) $row->entity;
    }

    /**
     * Load the lines of a fetched document when its type declares them.
     *
     * Facture, Commande and Propal load their lines inside fetch(), so the read
     * paths never had to ask. Contrat does not: its fetch() reads the header
     * only. Without this, 'contract' would answer supports_lines=true and still
     * serve line-less documents on show and list, while the /lines routes -- which
     * call fetch_lines() themselves -- returned them: the same object in two
     * shapes depending on the URL.
     *
     * No-op for the types whose fetch() already filled ->lines, so it costs
     * nothing where it is not needed.
     *
     * @param  object $object
     * @param  array  $cfg
     * @return void
     */
    protected function loadLines($object, $cfg)
    {
        if (empty($cfg['supports_lines'])) {
            return;
        }
        if (!empty($object->lines) && is_array($object->lines)) {
            return;
        }
        if (!method_exists($object, 'fetch_lines')) {
            dol_syslog("[SmartAuth] ObjectFacade: type " . ($cfg['object_type'] ?? '?') . " declares supports_lines but its class has no fetch_lines()", LOG_ERR);
            return;
        }
        $object->fetch_lines();
    }

    /**
     * Optional per-row INTRA-tenant visibility restriction for list/count.
     *
     * The generic entity scope (inEntityScope / the WHERE entity IN ...) only
     * separates tenants. A few Dolibarr objects add a finer authorization the
     * user's OWN entity: a project is visible only if the user is authorized on
     * it (getProjectsAuthorizedForUser), an agenda event only if owned/assigned
     * when the user lacks agenda.allactions.read, etc. A mapper opts in by
     * declaring:
     *
     *     public function visibilitySqlFilter($user, $alias, $db): string
     *
     * returning a SQL fragment appended to the list/count WHERE. Return:
     *   - ''                          -> no restriction (e.g. admin / "all" right)
     *   - " AND {$alias}.rowid IN (1,3)" -> restrict to those rows
     *   - " AND {$alias}.rowid IN (0)"   -> deny all (user authorized on nothing)
     *
     * Mappers that do not declare it keep the current behaviour unchanged.
     *
     * @param  object $mapper
     * @param  string $alias
     * @return string  SQL fragment (starts with " AND ") or ''.
     */
    protected function visibilitySqlFragment($mapper, $alias)
    {
        global $db, $user;

        if (!method_exists($mapper, 'visibilitySqlFilter')) {
            return '';
        }
        $frag = $mapper->visibilitySqlFilter($user, $alias, $db);
        return is_string($frag) ? $frag : '';
    }

    /**
     * Optional per-object INTRA-tenant visibility check for show/update/delete.
     *
     * Companion of visibilitySqlFragment() for single-object routes. A mapper
     * opts in by declaring:
     *
     *     public function canAccess($object, $user, string $mode): bool
     *
     * with $mode in {read, write, delete}. Returns false to REFUSE (the caller
     * emits a 403). Mappers that do not declare it are always allowed (only the
     * entity scope + Dolibarr right apply, as before).
     *
     * @param  object $mapper
     * @param  object $object
     * @param  string $mode    read|write|delete
     * @return bool            true when access is DENIED.
     */
    protected function visibilityDenies($mapper, $object, $mode)
    {
        global $user;

        if (!method_exists($mapper, 'canAccess')) {
            return false;
        }
        return $mapper->canAccess($object, $user, $mode) !== true;
    }
}
