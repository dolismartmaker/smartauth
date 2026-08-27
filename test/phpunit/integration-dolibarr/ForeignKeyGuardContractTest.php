<?php

/**
 * REGISTRY CONTRACT for the write-side tenant guard of the objects/{objtype}
 * facade.
 *
 * WHY THIS TEST IS THE MAIN DELIVERABLE OF THE FIX
 * ------------------------------------------------
 * The facade validated the NAMES of the incoming fields and never their VALUES.
 * The three isolation guards of ObjectController (inEntityScope,
 * isolationDenies, visibilityDenies) all run on the row AS IT STANDS before the
 * write: they answer "is this object mine?", never "is what you send me mine?".
 * A PATCH carrying another tenant's socid was therefore written verbatim.
 *
 * Correcting the 19 affected mappers one by one would close today's defect and
 * leave tomorrow's open: the hole grows with every object type added to the
 * registry. So the real fix is this walk. It fails when a writable field whose
 * NAME looks like a foreign key is neither guarded (dmBase::$foreignKeyGuards)
 * nor exempted with a written reason (dmBase::$foreignKeyGuardExemptions and
 * its global dictionary list). Adding a type without deciding is impossible.
 *
 * The detector is deliberately OVER-inclusive (anything starting with 'fk_' or
 * ending in 'id'). A false positive costs one exemption line with its reason; a
 * false negative is the very defect being closed. The asymmetry is on purpose.
 *
 * WHAT ACTUALLY REFUSES, so the suite is not read as proving more than it does:
 * this file proves the DECLARATIONS are complete and well-formed. It executes
 * no HTTP call and no SQL. The runtime refusal is proven by
 * ForeignKeyCrossTenantTest, which plants rows in a foreign entity and checks
 * both the 404 and the untouched SQL column.
 *
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

namespace SmartAuth\Tests\IntegrationDolibarr;

use ReflectionProperty;
use SmartAuth\Api\ObjectRegistry;

/**
 * @covers \SmartAuth\DolibarrMapping\dmBase
 * @covers \SmartAuth\Api\ObjectFacadeTrait
 */
class ForeignKeyGuardContractTest extends DolibarrRealTestCase
{
    /**
     * Shape of a foreign-key field name. Intentionally broad: 'fk_*' catches
     * the SQL columns, the 'id' suffix catches the Dolibarr PROPERTY spellings
     * the mappers actually address (socid, typeid, userownerid, entrepot_id,
     * cond_reglement_id, commercial_suivi_id...). Property names are what
     * $writableFields carries, so a detector limited to 'fk_*' would miss the
     * majority of the real cases -- socid alone spans 14 types.
     */
    private const FK_SHAPE = '/^fk_|id$/';

    /**
     * Every writable field of foreign-key shape must be either guarded or
     * exempted with a reason. This is the walk that makes the defect
     * non-reproducible on the object types added later.
     */
    public function testEveryForeignKeyShapedWritableFieldIsGuardedOrArgued(): void
    {
        $inspected = 0;
        $undecided = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapper = $this->bootMapper($cfg, $type);
            if ($mapper === null) {
                continue;
            }

            $guards = $mapper->getForeignKeyGuards();
            $exemptions = $mapper->getForeignKeyGuardExemptions();

            $declaredFk = $this->fieldsDeclaredAsForeignKeys($cfg);

            foreach ($this->writableFieldsOf($mapper, $cfg) as $field) {
                // Two independent detectors, because either alone has a blind
                // spot: the NAME shape misses a field like Ticket::$resolution,
                // and the Dolibarr descriptor misses every mapper key that
                // addresses a PHP property rather than a column (socid, and it
                // spans 14 types).
                if (!preg_match(self::FK_SHAPE, $field) && !isset($declaredFk[$field])) {
                    continue;
                }
                $inspected++;

                if (isset($guards[$field])) {
                    continue;
                }
                $reason = isset($exemptions[$field]) ? trim((string) $exemptions[$field]) : '';
                if ($reason !== '') {
                    continue;
                }

                $undecided[] = $type . '.' . $field;
            }
        }

        $this->assertGreaterThan(
            0,
            $inspected,
            'no foreign-key-shaped writable field found across the whole registry: '
            . 'the contract test would be vacuously green (did the registry or the mappers change shape?)'
        );

        $this->assertSame(
            [],
            $undecided,
            "These writable fields look like foreign keys and are neither guarded nor argued.\n"
            . "Declare the target in the mapper's \$foreignKeyGuards (e.g. 'socid' => 'thirdparty'),\n"
            . "or, when the target is global reference data, add a REASON in \$foreignKeyGuardExemptions:\n  "
            . implode("\n  ", $undecided)
        );
    }

    /**
     * A guard whose target cannot be resolved is worse than no guard: the
     * runtime is fail-closed, so a typo would silently refuse every legitimate
     * write on that field. Resolve each declared target here, at build time.
     */
    public function testEveryDeclaredGuardResolvesToARealTarget(): void
    {
        $checked = 0;
        $broken = [];
        $builtins = ObjectRegistry::builtins();

        foreach ($builtins as $type => $cfg) {
            $mapper = $this->bootMapper($cfg, $type);
            if ($mapper === null) {
                continue;
            }

            foreach ($mapper->getForeignKeyGuards() as $field => $target) {
                $checked++;

                if (is_string($target)) {
                    if (!isset($builtins[$target])) {
                        $broken[] = $type . '.' . $field . ' -> unknown registry type "' . $target . '"';
                        continue;
                    }
                    // A target with no entity column is scoped by its own
                    // mapper's isolationWhereSql(); without it the guard would
                    // refuse every write on that field.
                    $targetCfg = $builtins[$target];
                    $noEntity = array_key_exists('has_entity', $targetCfg) && $targetCfg['has_entity'] === false;
                    if ($noEntity && !method_exists((string) $targetCfg['mapper'], 'isolationWhereSql')) {
                        $broken[] = $type . '.' . $field . ' -> "' . $target
                            . '" has no entity column and its mapper declares no isolationWhereSql()';
                    }
                    continue;
                }

                if (!is_array($target)) {
                    $broken[] = $type . '.' . $field . ' -> unusable guard declaration';
                    continue;
                }

                if (isset($target['polymorphic'])) {
                    // The sibling naming the target table must itself be a
                    // writable field, otherwise a payload can move the key
                    // while the guard reads a stale element type.
                    $sibling = (string) $target['polymorphic'];
                    if (!in_array($sibling, $this->writableFieldsOf($mapper, $cfg), true)) {
                        $broken[] = $type . '.' . $field . ' -> polymorphic sibling "' . $sibling
                            . '" is not a writable field of this mapper';
                    }
                    continue;
                }

                if (empty($target['table']) || empty($target['element'])) {
                    $broken[] = $type . '.' . $field . ' -> explicit spec must carry both table and element';
                    continue;
                }
                if (!$this->tableExists((string) $target['table'])) {
                    $broken[] = $type . '.' . $field . ' -> table llx_' . $target['table'] . ' does not exist';
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'no guard declared at all: the walk would prove nothing');
        $this->assertSame([], $broken, "Declared guards that cannot be honoured at runtime:\n  " . implode("\n  ", $broken));
    }

    /**
     * A guard on a field that is not writable is dead code -- usually a typo on
     * the Dolibarr-side name, which would leave the REAL field unguarded while
     * looking covered.
     */
    public function testNoGuardIsDeclaredOnANonWritableField(): void
    {
        $orphans = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapper = $this->bootMapper($cfg, $type);
            if ($mapper === null) {
                continue;
            }
            $writable = $this->writableFieldsOf($mapper, $cfg);
            foreach (array_keys($mapper->getForeignKeyGuards()) as $field) {
                if (!in_array((string) $field, $writable, true)) {
                    $orphans[] = $type . '.' . $field;
                }
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "Guards declared on fields that are not writable (typo on the Dolibarr-side name?):\n  "
            . implode("\n  ", $orphans)
        );
    }

    /**
     * The 19 types the audit identified as carrying at least one tenant-borne
     * foreign key must all declare a guard. Pinned by name so that emptying one
     * of these maps -- the exact way the fix would be undone -- fails here
     * rather than silently.
     */
    public function testTheAuditedTypesAllDeclareAGuard(): void
    {
        $expected = [
            'agenda_event', 'category', 'contact', 'contract', 'intervention',
            'invoice', 'member', 'order', 'project', 'proposal', 'reception',
            'shipment', 'subscription', 'supplier_invoice', 'supplier_order',
            'supplier_proposal', 'task', 'ticket', 'warehouse',
        ];

        $builtins = ObjectRegistry::builtins();
        foreach ($expected as $type) {
            $this->assertArrayHasKey($type, $builtins, "type $type disappeared from the registry");
            $mapper = $this->bootMapper($builtins[$type], $type);
            $this->assertNotNull($mapper, "mapper of type $type could not be booted");
            $this->assertNotEmpty(
                $mapper->getForeignKeyGuards(),
                "type $type carries at least one tenant-borne foreign key and MUST declare \$foreignKeyGuards"
            );
        }
    }

    /**
     * socid and fk_project concentrate half of the exposure (14 types each).
     * Pinned explicitly so a partial regression on the two commonest keys
     * cannot hide behind the generic walk above.
     */
    public function testTheTwoWidespreadKeysAreGuardedEverywhereTheyAreWritable(): void
    {
        $missing = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapper = $this->bootMapper($cfg, $type);
            if ($mapper === null) {
                continue;
            }
            $guards = $mapper->getForeignKeyGuards();
            $writable = $this->writableFieldsOf($mapper, $cfg);

            foreach (['socid' => 'thirdparty', 'fk_soc' => 'thirdparty', 'fk_project' => 'project'] as $field => $target) {
                if (!in_array($field, $writable, true)) {
                    continue;
                }
                if (($guards[$field] ?? null) !== $target) {
                    $missing[] = $type . '.' . $field . ' (expected guard "' . $target . '")';
                }
            }
        }

        $this->assertSame([], $missing, "Unguarded or mis-targeted widespread keys:\n  " . implode("\n  ", $missing));
    }

    /* -----------------------------------------------------------------
     * helpers
     * --------------------------------------------------------------- */

    /**
     * Instantiate the mapper of a registry entry, loading its Dolibarr class
     * first (the mapper constructor instantiates it to build the descriptor).
     *
     * @param  array  $cfg
     * @param  string $type
     * @return object|null  null when the type declares no usable mapper.
     */
    private function bootMapper($cfg, $type)
    {
        $mapperClass = (string) ($cfg['mapper'] ?? '');
        if ($mapperClass === '') {
            return null;
        }
        if (!empty($cfg['file']) && is_file($cfg['file'])) {
            require_once $cfg['file'];
        }
        if (!class_exists($mapperClass)) {
            $this->fail("registry type $type declares mapper $mapperClass, which cannot be loaded");
        }

        $mapper = new $mapperClass();
        $this->assertTrue(
            method_exists($mapper, 'getForeignKeyGuards'),
            "mapper of type $type does not expose getForeignKeyGuards(): it must derive from dmBase"
        );

        return $mapper;
    }

    /**
     * The mapper allowlist, $writableFields -- now the ONLY write allowlist of a
     * built-in type, read by both doors (the facade through importMappedData(),
     * the sync push through getWritableApiKeys()).
     *
     * This helper used to take the union with the registry's 'allowed_fields'
     * because a field writable through either path was still a way in. That
     * second list is gone from the built-ins: it was never reached at runtime
     * (all 26 declare a mapper) and had drifted from the mapper on 9 of them.
     * RegistryWriteContractTest keeps it from coming back.
     *
     * @param  object $mapper
     * @param  array  $cfg     Kept for the signature's sake: callers pass the
     *                         registry entry, and a later contract may need it.
     * @return array<int,string>
     */
    private function writableFieldsOf($mapper, $cfg)
    {
        $prop = new ReflectionProperty(get_class($mapper), 'writableFields');
        $prop->setAccessible(true);
        $declared = $prop->getValue($mapper);

        return is_array($declared) ? array_values(array_unique(array_map('strval', $declared))) : [];
    }

    /**
     * Fields the Dolibarr class ITSELF declares as foreign keys, through the
     * "integer:TargetClass:path/to/class.php" form of its $fields type.
     *
     * Second detector of the walk: the core sometimes says "this is an FK" on a
     * column whose name gives nothing away, and reading that beats guessing.
     *
     * @param  array $cfg
     * @return array<string,true>  set of Dolibarr-side field names.
     */
    private function fieldsDeclaredAsForeignKeys($cfg)
    {
        $class = (string) ($cfg['class'] ?? '');
        if ($class === '' || !class_exists($class)) {
            return [];
        }

        $object = new $class($this->db);
        if (!property_exists($object, 'fields') || !is_array($object->fields)) {
            return [];
        }

        $out = [];
        foreach ($object->fields as $name => $desc) {
            $type = (is_array($desc) && isset($desc['type'])) ? (string) $desc['type'] : '';
            if (strncmp($type, 'integer:', 8) === 0) {
                $out[(string) $name] = true;
            }
        }

        return $out;
    }

    /**
     * Whether llx_<table> exists in the test database.
     *
     * @param  string $table
     * @return bool
     */
    private function tableExists($table)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }
        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . $table . ' WHERE 1 = 0');

        return (bool) $resql;
    }
}
