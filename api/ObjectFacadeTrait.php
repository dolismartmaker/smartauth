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
     * Whether an entity value is one the current request may reach.
     *
     * Single definition of the tenant frontier, shared by inEntityScope() (the
     * object being acted on) and foreignKeyTargetDenies() (the object being
     * referenced). Keeping ONE implementation is the point: two copies would
     * eventually disagree, and an object you may write while being unable to
     * reference the very companies you can read is a bug in either direction.
     *
     * Membership of getEntity(), NOT a strict equality with $conf->entity --
     * for the reason spelled out at length on inEntityScope(): smartauth also
     * runs under Multicompany, where an entity legitimately reads the entities
     * it is shared with. On an install without Multicompany getEntity() returns
     * the current entity alone, so this IS the strict equality the consumer
     * modules apply on their side.
     *
     * @param  int    $entity
     * @param  string $element  Dolibarr element code fed to getEntity().
     * @return bool
     */
    protected function entityIsReachable($entity, $element)
    {
        $allowed = array_map('intval', explode(',', getEntity((string) $element, 1)));

        return in_array((int) $entity, $allowed, true);
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
     * Optional TENANT ISOLATION for a table with NO 'entity' column.
     *
     * The generic scoping relies on an 'entity' column (list WHERE + per-object
     * inEntityScope). A few Dolibarr tables have none -- llx_stock_mouvement is
     * the canonical case -- so the registry flags them has_entity=false and the
     * list query degrades to "WHERE 1=1": without this hook it would serve EVERY
     * tenant's rows. Such a table is always reachable from an entity-scoped one
     * (a stock movement has a warehouse AND a product, both entity-scoped), so a
     * mapper opts in by declaring:
     *
     *     public function isolationWhereSql($alias, $db): string
     *
     * returning a SQL fragment appended to the list/count WHERE, e.g.
     *
     *     " AND EXISTS (SELECT 1 FROM llx_entrepot e
     *                    WHERE e.rowid = {$alias}.fk_entrepot
     *                      AND e.entity IN (getEntity('stock')))"
     *
     * EXISTS rather than a JOIN so the same fragment composes verbatim into the
     * list, the count AND the single-row probe below, without any FROM surgery.
     *
     * Mappers of a type that HAS an entity column and does not declare the hook
     * keep the current behaviour unchanged (empty fragment: the entity WHERE
     * already scopes them). But a type flagged has_entity=false whose mapper
     * gives us no usable fragment is a misconfiguration that would serve every
     * tenant's rows: the list is then forced to return nothing (" AND 1=0"),
     * fail-closed, mirroring isolationDenies() on the single-row routes.
     *
     * @param  object     $mapper
     * @param  string     $alias
     * @param  array|null $cfg    Registry config, to know about has_entity.
     * @return string  SQL fragment (starts with " AND ") or ''.
     */
    protected function isolationWhereFragment($mapper, $alias, $cfg = null)
    {
        global $db;

        $noEntityColumn = is_array($cfg) && array_key_exists('has_entity', $cfg) && $cfg['has_entity'] === false;

        $frag = '';
        if (method_exists($mapper, 'isolationWhereSql')) {
            $raw = $mapper->isolationWhereSql($alias, $db);
            $frag = is_string($raw) ? $raw : '';
        }

        if ($frag === '' && $noEntityColumn) {
            dol_syslog("[SmartAuth] ObjectFacade: type " . (is_array($cfg) ? ($cfg['object_type'] ?? '?') : '?') . " has no entity column and no usable isolationWhereSql() - listing nothing (fail-closed)", LOG_ERR);
            return " AND 1=0";
        }

        return $frag;
    }

    /**
     * Row-level companion of isolationWhereFragment() for the single-object
     * routes (show/update/destroy/deleteBulk).
     *
     * inEntityScope() cannot help here: the fetched object carries no ->entity
     * property at all, so it always passes. We re-run the very same isolation
     * predicate as a targeted probe on the row's primary key: no row back means
     * the row belongs to another tenant.
     *
     * Fail-closed: a mapper that declares the hook but returns an empty fragment
     * is treated as a denial (serving an unscoped row would be the leak this
     * mechanism exists to prevent). Same verdict when a type declared
     * has_entity=false does not declare the hook at ALL: nothing would scope it,
     * so it must be refused rather than served to every tenant. Types that DO
     * have an entity column and no hook are unaffected (they are scoped by
     * inEntityScope / the WHERE entity IN ... of the list).
     *
     * @param  array  $cfg
     * @param  object $mapper
     * @param  int    $id
     * @return bool   true when access is DENIED.
     */
    protected function isolationDenies($cfg, $mapper, $id)
    {
        global $db;

        if (!method_exists($mapper, 'isolationWhereSql')) {
            if (array_key_exists('has_entity', $cfg) && $cfg['has_entity'] === false) {
                dol_syslog("[SmartAuth] ObjectFacade: type " . ($cfg['object_type'] ?? '?') . " has no entity column and its mapper declares no isolationWhereSql() - refusing (fail-closed)", LOG_ERR);
                return true;
            }
            return false;
        }

        $alias = (string) ($cfg['alias'] ?? 't');
        $pk = (string) ($cfg['pk'] ?? 'rowid');
        $frag = $this->isolationWhereFragment($mapper, $alias);
        if ($frag === '') {
            dol_syslog("[SmartAuth] ObjectFacade: empty isolation fragment for " . ($cfg['object_type'] ?? '?') . " - refusing (fail-closed)", LOG_ERR);
            return true;
        }

        $sql = "SELECT " . $alias . "." . $pk . " as rowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . $cfg['table'] . " as " . $alias;
        $sql .= " WHERE " . $alias . "." . $pk . " = " . ((int) $id) . $frag;

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectFacade: isolation probe SQL error for " . ($cfg['object_type'] ?? '?') . ": " . $db->lasterror(), LOG_ERR);
            return true;
        }
        $found = ((int) $db->num_rows($resql)) > 0;
        $db->free($resql);

        return !$found;
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

    /**
     * TENANT GUARD ON THE INCOMING VALUES: refuse a payload that points one of
     * this object's foreign keys at another tenant's row.
     *
     * The three guards above (inEntityScope, isolationDenies, visibilityDenies)
     * all run on the row as it stands BEFORE the write. They answer "is this
     * object mine?". None of them ever sees the request body, and
     * importMappedData() only knows field NAMES. So a PATCH carrying a foreign
     * socid used to be written verbatim: the invoice stayed in its own entity
     * while pointing at a company that does not exist for it -- a reparenting,
     * and a thirdparty name that silently comes back empty.
     *
     * Driven by the mapper's declarative $foreignKeyGuards map (see dmBase), so
     * a type added later is covered by declaring one line rather than by
     * writing a hook -- and the contract test fails if that line is missing.
     *
     * A value <= 0 is NEVER a violation: detaching an object from its project
     * is legitimate, and refusing 0 would break that on the 14 types carrying
     * fk_project.
     *
     * SIDE EFFECT, and it is the point: every guarded value is REWRITTEN as the
     * integer that was probed. A guarded foreign key is an integer by
     * construction, so the value that reaches the database must be the value
     * this method validated -- not the raw string the client sent.
     *
     * Without that normalisation the guard would be a decoration on several
     * types. importMappedData() only casts a field whose Dolibarr-side name is
     * a key of the class $fields (dmTrait::_getFieldDefinition, called with
     * $applyNameHeuristics = false); `socid` and `fk_project` are PHP property
     * names, while the $fields entries are `fk_soc` and `fk_projet`, so both
     * come out of the sanitizer as STRINGS. And the core interpolates them raw:
     * Commande::update() l.3389 writes " fk_soc=".(isset($this->socid) ? ...),
     * with no quote, no cast and no escape -- same at l.3400 for fk_projet, at
     * Propal::update() l.1774/1788, at Task::update() l.463/465. A payload of
     * "0, entity=97" therefore passed the probe (it casts to 0, a legitimate
     * detach) and then injected a second assignment into the UPDATE. Casting
     * here closes that on every guarded field at once, including the <= 0 ones,
     * which is why the cast happens BEFORE the detach short-circuit.
     *
     * @param  object      $mapper
     * @param  \stdClass   $sanitized  Output of importMappedData().
     * @param  array       $cfg        Registry config of the object being written.
     * @param  object|null $object     Fetched object on update (used to read a
     *                                 sibling field a partial payload omits),
     *                                 null on create.
     * @return string|null  Offending field name, or null when the payload is clean.
     */
    protected function foreignKeyViolation($mapper, $sanitized, $cfg, $object = null)
    {
        if (!is_object($sanitized) || !method_exists($mapper, 'getForeignKeyGuards')) {
            // A consumer mapper that does not derive from dmBase declares no
            // guard: nothing to enforce, historical behaviour unchanged.
            return null;
        }

        $guards = $mapper->getForeignKeyGuards();
        if (!is_array($guards)) {
            $guards = [];
        }

        // Fields that must reach SQL as integers: the guarded keys AND the
        // dictionary keys the mapper is exempted from probing. Exempting a key
        // means "do not check the target's tenant", never "let a string through
        // into an integer column" -- see getForeignKeyIntegerFields(). A mapper
        // may declare no guard at all and still own such keys (dmBankAccount,
        // dmUser, dmExpenseReport), which is why this runs before any
        // early return on an empty guard map.
        $integerFields = method_exists($mapper, 'getForeignKeyIntegerFields')
            ? array_fill_keys($mapper->getForeignKeyIntegerFields(), true)
            : array_fill_keys(array_keys($guards), true);

        foreach (get_object_vars($sanitized) as $field => $value) {
            if ($value === null || !isset($integerFields[$field])) {
                continue;
            }
            // Normalise FIRST, unconditionally: the value written must be the
            // value probed, and a detach ("0, entity=97") must be neutralised
            // even though it short-circuits the probe below. See the docblock.
            $id = (int) $value;
            $sanitized->{$field} = $id;
            if (!isset($guards[$field]) || $id <= 0) {
                continue;
            }

            $target = $guards[$field];
            if (is_array($target) && isset($target['polymorphic'])) {
                $target = $this->resolvePolymorphicTarget($target, $sanitized, $object, $cfg, $field);
                if ($target === null) {
                    // Unknown element type: logged by the resolver, left
                    // unguarded on purpose so a module linking its OWN objects
                    // is not broken by this mechanism.
                    continue;
                }
            }

            if ($this->foreignKeyTargetDenies($target, $id, $cfg, $field)) {
                return (string) $field;
            }
        }

        return $this->polymorphicSiblingViolation($guards, $sanitized, $cfg, $object);
    }

    /**
     * Second pass for the polymorphic guards: a payload that moves ONLY the
     * sibling naming the target table.
     *
     * The loop above walks the fields the payload carries, so a PATCH sending
     * just {"elementtype": "societe"} never re-examined the fk_element already
     * stored. That id had been validated against the PREVIOUS element type, so
     * the pair (id, type) as it ends up in database was never checked as a
     * whole: linking an event to local project 1, then flipping the type alone,
     * left it pointing at company 1 -- possibly another tenant's.
     *
     * @param  array       $guards
     * @param  \stdClass   $sanitized
     * @param  array       $cfg
     * @param  object|null $object
     * @return string|null  Offending field name, or null.
     */
    private function polymorphicSiblingViolation($guards, $sanitized, $cfg, $object)
    {
        foreach ($guards as $field => $target) {
            if (!is_array($target) || !isset($target['polymorphic'])) {
                continue;
            }
            // Already judged by the main loop when the payload carries the key.
            if (property_exists($sanitized, $field)) {
                continue;
            }
            $sibling = (string) $target['polymorphic'];
            if (!isset($sanitized->{$sibling})) {
                continue;
            }

            $current = (is_object($object) && isset($object->{$field})) ? (int) $object->{$field} : 0;
            if ($current <= 0) {
                continue;
            }

            $resolved = $this->resolvePolymorphicTarget($target, $sanitized, $object, $cfg, $field);
            if ($resolved === null) {
                continue;
            }
            if ($this->foreignKeyTargetDenies($resolved, $current, $cfg, $field)) {
                return (string) $field;
            }
        }

        return null;
    }

    /**
     * Whether a single foreign-key value points outside the current tenant.
     *
     * DO NOT reimplement this with the target class's own fetch(). Verified on
     * Dolibarr 18.0.8: Project::fetch() and Entrepot::fetch() drop the entity
     * clause as soon as they are given a rowid ("if ($id) WHERE rowid = X; else
     * WHERE entity IN (...)"), as do Facture, Propal, Commande and five others,
     * while Societe::fetch() keeps it. Trusting the target would produce a
     * guard that works for some types and silently not for others. We read the
     * entity column of the target table straight instead.
     *
     * Fail-closed: a target that cannot be resolved (unknown registry type,
     * missing table, SQL error, unknown row) is a REFUSAL. A misdeclared guard
     * must break the write loudly, never degrade into a hole.
     *
     * @param  string|array $target  Registry type key, or an explicit
     *                               ['table' =>, 'element' =>, 'pk' =>] spec.
     * @param  int          $id      Value being written (already > 0).
     * @param  array        $cfg     Registry config of the object being written
     *                               (for the log line only).
     * @param  string       $field   Field name (for the log line only).
     * @return bool  true when the write must be REFUSED.
     */
    protected function foreignKeyTargetDenies($target, $id, $cfg, $field)
    {
        global $db, $hookmanager;

        $type = (string) ($cfg['object_type'] ?? '?');

        if (is_string($target) && $target !== '') {
            $targetCfg = ObjectRegistry::get($target, is_object($hookmanager) ? $hookmanager : null);
            if ($targetCfg === null) {
                dol_syslog("[SmartAuth] ObjectFacade: foreign key guard of " . $type . "." . $field . " names unknown registry type '" . $target . "' - refusing (fail-closed)", LOG_ERR);
                return true;
            }
        } elseif (is_array($target) && !empty($target['table'])) {
            $targetCfg = $target;
        } else {
            dol_syslog("[SmartAuth] ObjectFacade: unusable foreign key guard declared on " . $type . "." . $field . " - refusing (fail-closed)", LOG_ERR);
            return true;
        }

        $table = (string) ($targetCfg['table'] ?? '');
        $pk = (string) ($targetCfg['pk'] ?? 'rowid');
        if ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $pk)) {
            dol_syslog("[SmartAuth] ObjectFacade: foreign key guard of " . $type . "." . $field . " has an unusable table/pk - refusing (fail-closed)", LOG_ERR);
            return true;
        }

        // Target table with NO entity column (llx_bank): its tenant is derived
        // from a parent, which is exactly what its own mapper's
        // isolationWhereSql() expresses. Replaying that predicate here keeps ONE
        // definition of "this row belongs to my tenant" per table, instead of a
        // second one drifting on the write side.
        if (array_key_exists('has_entity', $targetCfg) && $targetCfg['has_entity'] === false) {
            return $this->foreignKeyIsolatedTargetDenies($targetCfg, $id, $type, $field);
        }

        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . $table . " WHERE " . $pk . " = " . ((int) $id);
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("[SmartAuth] ObjectFacade: foreign key probe failed for " . $type . "." . $field . " on " . $table . ": " . $db->lasterror(), LOG_ERR);
            return true;
        }
        $row = $db->fetch_object($resql);
        $db->free($resql);
        if (!$row || !isset($row->entity)) {
            dol_syslog("[SmartAuth] ObjectFacade: " . $type . "." . $field . " references unknown " . $table . " row " . ((int) $id) . " - refusing", LOG_WARNING);
            return true;
        }

        if ($this->entityIsReachable((int) $row->entity, (string) ($targetCfg['element'] ?? ''))) {
            return false;
        }

        dol_syslog("[SmartAuth] ObjectFacade: cross-tenant foreign key refused, " . $type . "." . $field . " -> " . $table . " row " . ((int) $id) . " lives in entity " . ((int) $row->entity), LOG_WARNING);
        return true;
    }

    /**
     * Companion of foreignKeyTargetDenies() for a target table with no entity
     * column: probe the row through its own mapper's isolationWhereSql().
     *
     * @param  array  $targetCfg  Registry config of the TARGET type.
     * @param  int    $id
     * @param  string $type       Type being written (log only).
     * @param  string $field      Field name (log only).
     * @return bool   true when the write must be REFUSED.
     */
    private function foreignKeyIsolatedTargetDenies($targetCfg, $id, $type, $field)
    {
        $mapperClass = (string) ($targetCfg['mapper'] ?? '');
        if ($mapperClass === '' || !class_exists($mapperClass)) {
            dol_syslog("[SmartAuth] ObjectFacade: foreign key guard of " . $type . "." . $field . " targets a table with no entity column and no loadable mapper - refusing (fail-closed)", LOG_ERR);
            return true;
        }
        if (!empty($targetCfg['file']) && !empty($targetCfg['class']) && !class_exists($targetCfg['class'])) {
            require_once $targetCfg['file'];
        }

        $targetMapper = new $mapperClass();
        // isolationDenies() already refuses when the hook is missing or gives an
        // empty fragment, which is the fail-closed verdict we want here too.
        if ($this->isolationDenies($targetCfg, $targetMapper, (int) $id)) {
            dol_syslog("[SmartAuth] ObjectFacade: cross-tenant foreign key refused, " . $type . "." . $field . " -> " . ((string) $targetCfg['table']) . " row " . ((int) $id) . " is outside the tenant", LOG_WARNING);
            return true;
        }

        return false;
    }

    /**
     * Resolve a polymorphic guard (llx_actioncomm.fk_element, whose target
     * table is named by the sibling `elementtype` column) into a registry type.
     *
     * The sibling is read from the payload first, then from the fetched object:
     * a PATCH may move fk_element while leaving elementtype untouched, and the
     * pair must be judged as it will END UP in database.
     *
     * ActionComm::create() rewrites three values on the way in
     * (facture -> invoice, commande -> order, contrat -> contract, cf
     * actioncomm.class.php l.470-478), so the stored vocabulary mixes Dolibarr
     * element codes ('societe', 'project') with those aliases -- which happen to
     * be the registry KEYS. Both spellings are therefore looked up.
     *
     * Returns null when the element type is empty or unknown: a module linking
     * an agenda event to its OWN object is legitimate and must not be refused by
     * a mechanism that simply does not know that table. The case is logged.
     *
     * @param  array       $spec
     * @param  \stdClass   $sanitized
     * @param  object|null $object
     * @param  array       $cfg
     * @param  string      $field
     * @return string|null  Registry type key, or null to leave the field unguarded.
     */
    private function resolvePolymorphicTarget($spec, $sanitized, $object, $cfg, $field)
    {
        global $hookmanager;

        $sibling = (string) $spec['polymorphic'];
        $raw = null;
        if (isset($sanitized->{$sibling})) {
            $raw = $sanitized->{$sibling};
        } elseif (is_object($object) && isset($object->{$sibling})) {
            $raw = $object->{$sibling};
        }

        $elementType = strtolower(trim((string) $raw));
        // Dolibarr suffixes module elements with '@module'; the table is named
        // by the part before it.
        $elementType = preg_replace('/@.*$/', '', $elementType);

        $type = (string) ($cfg['object_type'] ?? '?');
        if ($elementType === '') {
            dol_syslog("[SmartAuth] ObjectFacade: " . $type . "." . $field . " has no " . $sibling . " to resolve its target - left unguarded", LOG_WARNING);
            return null;
        }

        $all = ObjectRegistry::resolveWithHooks(is_object($hookmanager) ? $hookmanager : null);
        if (isset($all[$elementType])) {
            return $elementType;
        }
        foreach ($all as $candidate => $candidateCfg) {
            if (is_array($candidateCfg) && (string) ($candidateCfg['element'] ?? '') === $elementType) {
                return $candidate;
            }
        }

        dol_syslog("[SmartAuth] ObjectFacade: " . $type . "." . $field . " targets unregistered element type '" . $elementType . "' - left unguarded", LOG_WARNING);
        return null;
    }
}
