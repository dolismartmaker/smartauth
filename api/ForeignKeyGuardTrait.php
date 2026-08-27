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
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api;

/**
 * "Does this row belong to my tenant?" -- the primitives, and the write-side
 * guard built on them.
 *
 * Extracted from ObjectFacadeTrait for ONE reason: the synchronous REST facade
 * is not the only door writing these columns. POST sync/push
 * (SyncController::processCreate / processUpdate) writes the very same objects
 * through the very same mappers, and it carried the very same defect -- it
 * checked the tenant of the row being modified (processUpdate, isEntityAllowed)
 * and never the tenant of the VALUES being written. Its own foreign-key
 * validation, $fkValidationMap, is keyed on SQL column names (fk_soc,
 * fk_project) while the mappers write PHP property names (socid), so it missed
 * the very field that spans 14 types.
 *
 * Two copies of a tenant frontier eventually disagree, and the disagreement is
 * silent. So there is now one implementation, used by ObjectFacadeTrait (hence
 * by the four facade controllers) and by SyncController.
 *
 * What lives here:
 *   - entityIsReachable()        : the frontier itself, for a plain entity value
 *   - isolationWhereFragment()   : the frontier as SQL, for a table with no
 *                                  entity column
 *   - isolationDenies()          : the same predicate as a single-row probe
 *   - foreignKeyViolation()      : the write-side guard, driven by the mapper's
 *                                  declarative $foreignKeyGuards
 *
 * @see dmBase::$foreignKeyGuards for the declaration format.
 * @see documentation/MAPPERS_API.md section 6.5.
 */
trait ForeignKeyGuardTrait
{
    /**
     * Ceiling on the number of distinct ids a list-shaped extrafield may carry.
     *
     * A 'chkbxlst' is stored in a varchar(255) (extrafields.class.php l.235-237),
     * so roughly 85 ids of average length are all that survives the column
     * anyway. The cap is not about storage though: each id costs one SELECT in
     * the tenant probe below, so an uncapped list turns a single authenticated
     * request into as many queries as the payload has commas.
     *
     * A property rather than a const: trait constants need PHP 8.2 and this code
     * targets 7.4.
     *
     * @var int
     */
    private static $EXTRAFIELD_LIST_MAX_IDS = 100;

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

        $extrafield = $this->extrafieldTargetViolation($mapper, $sanitized, $cfg);
        if ($extrafield !== null) {
            return $extrafield;
        }

        return $this->polymorphicSiblingViolation($guards, $sanitized, $cfg, $object);
    }

    /**
     * SAME GUARD, FOR THE EXTRAFIELDS a mapper opens for write.
     *
     * The loop above is driven by $foreignKeyGuards, which only ever names
     * native columns. Extrafields travel through the very same payload as
     * 'options_*' keys, and a Dolibarr extrafield of type 'link' holds exactly
     * what a native fk_ column holds: another object's rowid. So the door
     * $foreignKeyGuards closes on invoice.socid stood open on a custom field
     * pointing at the same table -- the write side of the extrafields opened by
     * $extrafieldsRW was never tenant-checked at all.
     *
     * Kept as a separate pass rather than folded into $foreignKeyGuards because
     * the two need different value handling: a guarded native key is cast with
     * (int) unconditionally (l.271), which is right for a 'link' (int column)
     * and destructive for a 'sellist' or a 'chkbxlst' (varchar column that may
     * hold a comma-separated list).
     *
     * The classification itself lives in dmBase::getExtrafieldWriteTargets(),
     * which reads the Dolibarr descriptors; this method only enforces it.
     *
     * Fail-open, deliberately, on ONE case: a mapper that does not derive from
     * dmBase exposes no getExtrafieldWriteTargets(), exactly as it exposes no
     * getForeignKeyGuards() at l.242-246. Consumer mappers keep their historical
     * behaviour rather than being broken by a mechanism they never opted into.
     *
     * NOTE ON REACH: both write doors reach this pass, and both now write
     * extrafields. The sync push could not until the 2026-08-27 lot -- its
     * allowlist read $writableFields alone, its assignment loop filtered on
     * property_exists() (false for 'options_*') and it never called
     * insertExtraFields(). Those three are fixed; this pass, written in advance,
     * needed no change to cover the second door.
     *
     * One door stays deliberately shut: SyncController::applyDataLegacy(), the
     * path for a hook-registered type WITHOUT mapper, refuses 'options_*' keys
     * outright. Such a type exposes no getExtrafieldWriteTargets(), so nothing
     * here could vet what its extrafield points at.
     *
     * @param  object    $mapper
     * @param  \stdClass $sanitized  Output of importMappedData(), mutated for
     *                               'link' values (integer normalisation).
     * @param  array     $cfg        Registry config of the object being written.
     * @return string|null  Offending 'options_*' key, or null when clean.
     */
    private function extrafieldTargetViolation($mapper, $sanitized, $cfg)
    {
        if (!method_exists($mapper, 'getExtrafieldWriteTargets')) {
            return null;
        }

        $type = (string) ($cfg['object_type'] ?? '?');
        $targets = $mapper->getExtrafieldWriteTargets();
        if (!is_array($targets) || empty($targets)) {
            return null;
        }

        foreach (get_object_vars($sanitized) as $key => $value) {
            if (strncmp((string) $key, 'options_', 8) !== 0) {
                continue;
            }
            // Clearing a custom field is always legitimate, whatever its type.
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            // An extrafield the mapper does not open for write cannot be here:
            // importMappedData() rejects it with a 400 before we are called.
            if (!isset($targets[$key]) || !is_array($targets[$key])) {
                continue;
            }

            $spec = $targets[$key];
            $mode = (string) ($spec['mode'] ?? 'unguarded');

            if ($mode === 'unguarded') {
                continue;
            }
            if ($mode === 'refuse') {
                dol_syslog(
                    "[SmartAuth] ObjectFacade: " . $type . "." . $key . " cannot be guarded - "
                    . ((string) ($spec['reason'] ?? 'unusable extrafield declaration')) . " - refusing (fail-closed)",
                    LOG_ERR
                );
                return (string) $key;
            }

            // A guarded extrafield holds an id, or a list of ids: never an array
            // and never an object. Casting one would emit a PHP warning and
            // produce 0 or 1 -- a value nobody sent, silently written.
            if (!is_scalar($value)) {
                dol_syslog(
                    "[SmartAuth] ObjectFacade: " . $type . "." . $key . " references an object but carries a "
                    . gettype($value) . " - refusing (fail-closed)",
                    LOG_WARNING
                );
                return (string) $key;
            }

            $target = $spec['target'] ?? '';
            // One shape rule for the three modes: what is written must be a row
            // id. 'int' additionally normalises, because its column is an
            // int(11) (extrafields.class.php l.238-240) and the value written
            // must be the value probed -- same rule as the native keys.
            $rawIds = ($mode === 'csv') ? explode(',', (string) $value) : [(string) $value];

            // A 'chkbxlst' stores its list in a varchar(255), so ~85 ids at the
            // very most survive the column. Probing an unbounded list would mean
            // one SELECT per element: a 400 kB payload of "1,1,1,..." would buy
            // 200 000 queries for the price of one authenticated request. Cap
            // and dedupe BEFORE probing.
            if ($mode === 'csv') {
                $rawIds = array_unique(array_map('trim', $rawIds));
                if (count($rawIds) > self::$EXTRAFIELD_LIST_MAX_IDS) {
                    dol_syslog(
                        "[SmartAuth] ObjectFacade: " . $type . "." . $key . " carries " . count($rawIds)
                        . " distinct ids, over the " . self::$EXTRAFIELD_LIST_MAX_IDS . " a varchar(255) list can hold"
                        . " - refusing (fail-closed)",
                        LOG_WARNING
                    );
                    return (string) $key;
                }
            }

            foreach ($rawIds as $rawId) {
                $rawId = trim((string) $rawId);
                if ($rawId === '') {
                    continue;
                }
                if (!preg_match('/^\d+$/', $rawId)) {
                    // Refused rather than cast. On the 'id' / 'csv' modes the
                    // column is a varchar, so "12abc" would be probed as row 12
                    // and then stored verbatim. On 'int' the cast would turn it
                    // into 0, which reads as a legitimate detach: a client
                    // sending a REFERENCE instead of a row id would silently
                    // unlink the field and get a 200.
                    dol_syslog(
                        "[SmartAuth] ObjectFacade: " . $type . "." . $key . " references a row id but carries the non-numeric value '"
                        . $rawId . "' - refusing (fail-closed)",
                        LOG_WARNING
                    );
                    return (string) $key;
                }
                if ($mode === 'int') {
                    $sanitized->{$key} = (int) $rawId;
                }
                if ((int) $rawId <= 0) {
                    continue;
                }
                if ($this->foreignKeyTargetDenies($target, (int) $rawId, $cfg, (string) $key)) {
                    return (string) $key;
                }
            }
        }

        return null;
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
