<?php

/**
 * ONE WRITE ALLOWLIST PER TYPE, and it is the mapper's.
 *
 * WHAT THIS REPLACES. Every built-in used to declare an 'allowed_fields' list
 * next to its mapper. It read as a second line of defence and was dead code:
 * SyncController::applyDataToObject() only reaches applyDataLegacy() -- the sole
 * runtime reader of that key -- when no mapper resolves, and all 26 built-ins
 * declare one that loads. The effective allowlist was $writableFields, always,
 * on both write doors.
 *
 * Two lists that nothing keeps in step do not add defence, they subtract trust.
 * 10 of the 26 had drifted, in BOTH directions: the registry opened 35 fields on
 * thirdparty where the mapper opens 25, opened 8 on project where the mapper
 * opens 16, and not one of the 12 it listed for order was even a valid API key.
 * Three per-type tests (member, ticket, subscription) pinned the two lists
 * together and stayed green only because those three entries happened to be
 * written in Dolibarr spelling.
 *
 * So the key is gone from the built-ins, and this walk keeps it gone. It also
 * pins the invariant that MAKES it removable -- every built-in resolves a mapper
 * -- because the day one does not, that type silently falls onto the legacy path
 * and is refused wholesale (applyDataLegacy is now fail-closed).
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
 * @covers \SmartAuth\Api\ObjectRegistry
 * @covers \SmartAuth\Api\SyncController
 */
class RegistryWriteContractTest extends DolibarrRealTestCase
{
    /**
     * No built-in may carry a second write allowlist. Reintroducing one is how
     * the drift started, and the drift is invisible: the key looks like it
     * governs writes and governs nothing.
     */
    public function testNoBuiltinDeclaresASecondWriteAllowlist(): void
    {
        $offenders = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            if (array_key_exists('allowed_fields', $cfg)) {
                $offenders[] = $type;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These built-ins declare an 'allowed_fields' list alongside their mapper.\n"
            . "It is never read (the mapper path always wins) and it WILL drift from \$writableFields.\n"
            . "The write allowlist of a built-in is its mapper's \$writableFields, and only that:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The invariant that makes the removal safe. A built-in without a loadable
     * mapper falls onto applyDataLegacy(), which -- having no allowlist either
     * -- now refuses every incoming field: a push on that type would answer
     * "rejected" for everything rather than write unchecked.
     */
    public function testEveryBuiltinResolvesAMapperThatLoads(): void
    {
        $broken = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapperClass = (string) ($cfg['mapper'] ?? '');
            if ($mapperClass === '') {
                $broken[] = $type . ' declares no mapper';
                continue;
            }
            if (!empty($cfg['file']) && is_file($cfg['file'])) {
                require_once $cfg['file'];
            }
            if (!class_exists($mapperClass)) {
                $broken[] = $type . ' declares mapper ' . $mapperClass . ', which cannot be loaded';
            }
        }

        $this->assertSame(
            [],
            $broken,
            "Built-ins whose mapper does not resolve. Their writes now fall onto the fail-closed legacy path:\n  "
            . implode("\n  ", $broken)
        );
    }

    /**
     * A write allowlist that is empty means "read-only", and that has to be a
     * decision rather than an oversight. Pinned by name: today only the two
     * derived, read-mostly types claim it.
     */
    public function testOnlyTheReadOnlyTypesDeclareAnEmptyWriteAllowlist(): void
    {
        $empty = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapperClass = (string) ($cfg['mapper'] ?? '');
            if ($mapperClass === '' || !class_exists($mapperClass)) {
                continue;
            }
            $prop = new ReflectionProperty($mapperClass, 'writableFields');
            $prop->setAccessible(true);
            $declared = $prop->getValue(new $mapperClass());
            if (!is_array($declared) || $declared === []) {
                $empty[] = $type;
            }
        }

        sort($empty);
        $this->assertSame(
            ['bank_transaction', 'stock_movement'],
            $empty,
            'a type became write-closed (or write-open) without the change being argued: ' . implode(', ', $empty)
        );
    }

    /**
     * Every built-in mapper must expose the guard accessors the shared trait
     * calls, for native foreign keys AND for the extrafields a mapper may open
     * for write. A mapper missing either is skipped by the guard -- silently.
     */
    public function testEveryBuiltinMapperExposesTheTenantGuardAccessors(): void
    {
        $missing = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapperClass = (string) ($cfg['mapper'] ?? '');
            if ($mapperClass === '' || !class_exists($mapperClass)) {
                continue;
            }
            $mapper = new $mapperClass();
            foreach (['getForeignKeyGuards', 'getExtrafieldWriteTargets'] as $accessor) {
                if (!method_exists($mapper, $accessor)) {
                    $missing[] = $type . '::' . $accessor . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Mappers the tenant guard would walk past without checking anything:\n  " . implode("\n  ", $missing)
        );
    }

    /**
     * No built-in mapper opens an extrafield for write today. When one does,
     * every opened field must resolve to a decided outcome -- guarded against a
     * registry type, or explicitly unguarded with a reason -- and never to the
     * fail-closed 'refuse', which would mean the declaration is broken.
     *
     * Born green on purpose: it is the guard rail for the first mapper that
     * declares $extrafieldsRW, not a proof about today's code.
     */
    public function testEveryWritableExtrafieldOfABuiltinResolvesToADecidedTarget(): void
    {
        $undecided = [];
        $inspected = 0;

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            $mapperClass = (string) ($cfg['mapper'] ?? '');
            if ($mapperClass === '' || !class_exists($mapperClass)) {
                continue;
            }
            $mapper = new $mapperClass();
            if (!method_exists($mapper, 'getExtrafieldWriteTargets')) {
                continue;
            }

            foreach ($mapper->getExtrafieldWriteTargets() as $key => $spec) {
                $inspected++;
                $mode = is_array($spec) ? (string) ($spec['mode'] ?? '') : '';
                if ($mode === 'refuse') {
                    $undecided[] = $type . '.' . $key . ' -- ' . (string) ($spec['reason'] ?? 'broken declaration');
                }
            }
        }

        $this->assertSame(
            [],
            $undecided,
            "Extrafields opened for write whose target cannot be resolved. Every write on them is refused:\n  "
            . implode("\n  ", $undecided)
        );
        // Not an assertion on $inspected: zero is the expected value today.
        $this->assertGreaterThanOrEqual(0, $inspected);
    }
}
