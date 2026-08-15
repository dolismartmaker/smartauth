<?php

/**
 * Namespaced function shadow, declared FIRST so it is in place before any
 * SmartAuth\Api code runs.
 *
 * ObjectFacadeTrait lives in SmartAuth\Api and calls getEntity() unqualified,
 * so PHP resolves SmartAuth\Api\getEntity() before the global one. The unit
 * bootstrap's global mock always answers '1', which cannot express the "0,"
 * prefix Dolibarr really returns for the elements it declares global (user,
 * usergroup, cronjob, mail templates -- see the $addzero array in
 * htdocs/core/lib/functions.lib.php). Without that we could not tell the
 * read/write asymmetry on entity 0 apart from a plain out-of-scope refusal.
 *
 * PASSTHROUGH BY DEFAULT: with no entry in $GLOBALS['smartauth_test_get_entity_map']
 * this delegates verbatim to the global mock, so every other test of the suite
 * behaves exactly as before. Only a test that fills the map sees a difference,
 * and it clears it in tearDown().
 */

namespace SmartAuth\Api {
    if (!function_exists('SmartAuth\Api\getEntity')) {
        /**
         * @param  string $element
         * @param  int    $shared
         * @return string  Comma-separated list of entities, as Dolibarr returns.
         */
        function getEntity($element, $shared = 1)
        {
            if (isset($GLOBALS['smartauth_test_get_entity_map'][$element])) {
                return (string) $GLOBALS['smartauth_test_get_entity_map'][$element];
            }
            return \getEntity($element, $shared);
        }
    }
}

namespace SmartAuth\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use SmartAuth\Api\ObjectFacadeTrait;
    use SmartAuth\Api\ObjectRegistry;
    use SmartAuth\Tests\Mocks\MockDatabase;

    /**
     * Tenant-isolation contract of the generic object facade.
     *
     * Two properties are pinned here:
     *
     *  1. REGISTRY CONTRACT -- a type declared has_entity=false has NO entity column
     *     to filter on, so its list degrades to "WHERE 1=1". Its mapper MUST declare
     *     isolationWhereSql() (the hook that re-derives the tenant from an
     *     entity-scoped neighbour table), otherwise the facade would serve every
     *     tenant's rows. The test walks the registry so a new such type cannot be
     *     added without the hook.
     *
     *  2. FAIL-CLOSED SCOPING -- inEntityScope() used to answer "in scope" whenever
     *     the fetched object carried no ->entity property, and used to accept
     *     entity=0 unconditionally. Both were fail-open. It now re-reads the column
     *     from the registry table and refuses anything it cannot resolve.
     *
     *  3. ENTITY 0 IS READ-ONLY -- getEntity() legitimately returns "0,N" for the
     *     elements Dolibarr declares global (user, usergroup, cronjob, mail
     *     templates), so a shared entity-0 row IS in scope. Reading it is fine;
     *     UPDATING or DELETING it from a tenant is not, since that row is shared
     *     by every tenant (a tenant admin holding user->user->creer would edit or
     *     delete the shared superadmin). The mode parameter pins that asymmetry,
     *     and defaults to 'read' so an un-updated caller keeps its behaviour.
     *
     * The mapper classes cannot be autoloaded here: their files require_once paths
     * under DOL_DOCUMENT_ROOT at load time, which needs a real Dolibarr. The
     * registry contract is therefore checked by tokenizing the mapper source, which
     * is exact (a mention inside a comment is a different token than a declaration).
     *
     * @covers \SmartAuth\Api\ObjectFacadeTrait
     */
    class ObjectFacadeIsolationTest extends TestCase
    {
        /** @var MockDatabase */
        private $db;

        protected function setUp(): void
        {
            parent::setUp();

            smartauth_test_reset_conf();
            $this->db = new MockDatabase();
            $GLOBALS['db'] = $this->db;
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['db']);
            // Leave the getEntity() shadow in passthrough mode for every other
            // test of the process.
            unset($GLOBALS['smartauth_test_get_entity_map']);
            parent::tearDown();
        }

        // ---------------------------------------------------- registry contract

        public function testEveryTypeWithoutEntityColumnDeclaresIsolationHook(): void
        {
            $checked = 0;
            foreach (ObjectRegistry::builtins() as $type => $cfg) {
                if (!array_key_exists('has_entity', $cfg) || $cfg['has_entity'] !== false) {
                    continue;
                }
                $checked++;
                $file = $this->mapperFile((string) $cfg['mapper']);
                $this->assertFileExists($file, "mapper file of type $type not found");
                $this->assertTrue(
                    $this->declaresMethod($file, 'isolationWhereSql'),
                    "type $type is has_entity=false: its mapper " . $cfg['mapper']
                    . " MUST declare isolationWhereSql(), otherwise the facade serves every tenant's rows"
                );
            }
            $this->assertGreaterThan(0, $checked, 'no has_entity=false type found: the contract test would be vacuous');
        }

        public function testKnownTablesWithoutEntityColumnAreFlagged(): void
        {
            // llx_stock_mouvement, llx_subscription and llx_bank have no entity
            // column in the Dolibarr schema. If one of them ever gains one, flip the
            // flag AND this expectation together -- never silently.
            $builtins = ObjectRegistry::builtins();
            foreach (['stock_movement', 'subscription', 'bank_transaction'] as $type) {
                $this->assertArrayHasKey($type, $builtins);
                $this->assertArrayHasKey('has_entity', $builtins[$type], "type $type must declare has_entity");
                $this->assertFalse($builtins[$type]['has_entity'], "type $type table has no entity column");
            }
        }

        // ------------------------------------------------------- inEntityScope()

        public function testHydratedEntityInsideScopeIsAccepted(): void
        {
            $harness = $this->harness();
            $object = (object) ['id' => 42, 'entity' => 1];

            $this->assertTrue($harness->scope($object, $this->cfg()));
            $this->assertSame([], $this->db->getExecutedQueries(), 'a hydrated entity needs no database read');
        }

        public function testHydratedForeignEntityIsRefused(): void
        {
            $harness = $this->harness();
            $object = (object) ['id' => 42, 'entity' => 2];

            $this->assertFalse($harness->scope($object, $this->cfg()));
        }

        public function testMissingEntityIsResolvedFromTheRegistryTable(): void
        {
            $this->db->setQueryResult(true, [['entity' => 1]]);
            $harness = $this->harness();
            $object = (object) ['id' => 42];

            $this->assertTrue($harness->scope($object, $this->cfg()));
            $this->assertTrue(
                $this->db->hasQueryContaining('SELECT entity FROM llx_facture WHERE rowid = 42'),
                'the fallback must read the column from the registry table/pk, got: ' . $this->db->getLastQuery()
            );
        }

        public function testMissingEntityResolvedToAForeignTenantIsRefused(): void
        {
            // The hole this closes: an unhydrated ->entity used to be accepted as
            // local, so guessing a rowid reached another tenant's row.
            $this->db->setQueryResult(true, [['entity' => 2]]);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 42], $this->cfg()));
        }

        public function testUnresolvableEntityIsRefused(): void
        {
            // Query failure (e.g. no entity column on that table at all).
            $this->db->setQueryResult(false);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 42], $this->cfg()));
        }

        public function testUnknownRowIsRefused(): void
        {
            $this->db->setQueryResult(true, []);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 42], $this->cfg()));
        }

        public function testObjectWithoutIdIsRefused(): void
        {
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['ref' => 'FA-1'], $this->cfg()));
            $this->assertSame([], $this->db->getExecutedQueries(), 'no id: nothing to look up');
        }

        public function testZeroEntityIsRefusedForABusinessObject(): void
        {
            // entity=0 used to be accepted unconditionally. It stays legitimate only
            // for the elements getEntity() itself prefixes with "0," (user, usergroup,
            // cronjob, mail templates), which the plain set membership covers.
            $this->db->setQueryResult(true, [['entity' => 0]]);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 42, 'entity' => 0], $this->cfg()));
        }

        // ------------------------------------------- entity 0 is read-only

        public function testSharedEntityZeroIsReadableForAGlobalElement(): void
        {
            // getEntity('user') really answers "0,N": the shared superadmin row
            // IS in scope for reading, and that must keep working.
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $this->db->setQueryResult(true, [['entity' => 0]]);
            $harness = $this->harness();

            $this->assertTrue($harness->scope((object) ['id' => 1, 'entity' => 0], $this->userCfg(), 'read'));
        }

        public function testSharedEntityZeroIsNotWritable(): void
        {
            // The hole this closes: a tenant admin with user->user->creer could
            // rename or disable the entity-0 superadmin through the facade.
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $this->db->setQueryResult(true, [['entity' => 0]]);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 1, 'entity' => 0], $this->userCfg(), 'write'));
        }

        public function testSharedEntityZeroIsNotDeletable(): void
        {
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $this->db->setQueryResult(true, [['entity' => 0]]);
            $harness = $this->harness();

            $this->assertFalse($harness->scope((object) ['id' => 1, 'entity' => 0], $this->userCfg(), 'delete'));
        }

        public function testModeDefaultsToReadForCallersNotUpdated(): void
        {
            // Non-regression: the parameter is optional and its default must not
            // change the verdict any caller used to get.
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $this->db->setQueryResult(true, [['entity' => 0]]);
            $harness = $this->harness();

            $this->assertTrue($harness->scope((object) ['id' => 1, 'entity' => 0], $this->userCfg()));
        }

        public function testLocalEntityStaysWritableForAGlobalElement(): void
        {
            // The restriction targets entity 0 only: a user of the caller's own
            // entity remains writable.
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $harness = $this->harness();

            $this->assertTrue($harness->scope((object) ['id' => 7, 'entity' => 1], $this->userCfg(), 'write'));
            $this->assertTrue($harness->scope((object) ['id' => 7, 'entity' => 1], $this->userCfg(), 'delete'));
        }

        public function testForeignEntityStaysRefusedInEveryMode(): void
        {
            $GLOBALS['smartauth_test_get_entity_map']['user'] = '0,1';
            $harness = $this->harness();

            foreach (['read', 'write', 'delete'] as $mode) {
                $this->assertFalse(
                    $harness->scope((object) ['id' => 7, 'entity' => 2], $this->userCfg(), $mode),
                    "another tenant's user must stay refused in $mode"
                );
            }
        }

        public function testPermissionActionsMapToScopeModes(): void
        {
            $harness = $this->harness();

            $this->assertSame('read', $harness->mode('read'));
            $this->assertSame('delete', $harness->mode('delete'));
            $this->assertSame('write', $harness->mode('update'));
            // Fail-closed: anything that is not an explicit read is a mutation.
            $this->assertSame('write', $harness->mode('create'));
            $this->assertSame('write', $harness->mode(''));
        }

        public function testGetEntityShadowIsPassthroughWithoutAnOverride(): void
        {
            // Guards the seam itself: with no entry in the map, SmartAuth\Api code
            // must see exactly what the global bootstrap mock returns, so no other
            // test of the suite is disturbed.
            $this->assertSame(\getEntity('user', 1), \SmartAuth\Api\getEntity('user', 1));
        }

        public function testTypeWithoutEntityColumnSkipsTheEntityCheck(): void
        {
            $harness = $this->harness();
            $cfg = $this->cfg(['table' => 'stock_mouvement', 'has_entity' => false, 'object_type' => 'stock_movement']);

            $this->assertTrue($harness->scope((object) ['id' => 7], $cfg), 'isolationWhereSql() scopes that type, not this check');
            $this->assertSame([], $this->db->getExecutedQueries());
        }

        // ----------------------------------------------------- isolationDenies()

        public function testMissingIsolationHookDeniesWhenTheTableHasNoEntityColumn(): void
        {
            $harness = $this->harness();
            $mapper = new \stdClass(); // declares no isolationWhereSql()
            $cfg = $this->cfg(['table' => 'stock_mouvement', 'has_entity' => false, 'object_type' => 'stock_movement']);

            $this->assertTrue($harness->denies($cfg, $mapper, 7), 'nothing would scope that row: it must be refused');
        }

        public function testMissingIsolationHookIsHarmlessOnAnEntityScopedTable(): void
        {
            $harness = $this->harness();
            $mapper = new \stdClass();

            $this->assertFalse($harness->denies($this->cfg(), $mapper, 42), 'the entity column already scopes that type');
        }

        // ------------------------------------------------ isolationWhereFragment()

        public function testListFragmentIsFailClosedWhenTheHookIsMissing(): void
        {
            $harness = $this->harness();
            $mapper = new \stdClass();
            $cfg = $this->cfg(['table' => 'stock_mouvement', 'has_entity' => false, 'object_type' => 'stock_movement']);

            $this->assertSame(' AND 1=0', $harness->fragment($mapper, 'sm', $cfg));
        }

        public function testListFragmentStaysEmptyOnAnEntityScopedTable(): void
        {
            $harness = $this->harness();
            $mapper = new \stdClass();

            $this->assertSame('', $harness->fragment($mapper, 'f', $this->cfg()));
        }

        // ------------------------------------------------------------- helpers

        /**
         * Registry-like config of an entity-scoped type (invoice), overridable.
         *
         * @param  array $overrides
         * @return array
         */
        private function cfg($overrides = [])
        {
            return array_merge([
                'object_type' => 'invoice',
                'table' => 'facture',
                'element' => 'invoice',
                'alias' => 'f',
                'pk' => 'rowid',
            ], $overrides);
        }

        /**
         * Registry-like config of the 'user' type, whose element getEntity()
         * prefixes with "0," (Dolibarr's $addzero list).
         *
         * @return array
         */
        private function userCfg()
        {
            return $this->cfg([
                'object_type' => 'user',
                'table' => 'user',
                'element' => 'user',
                'alias' => 'u',
            ]);
        }

        /**
         * Object exposing the protected trait methods under test.
         *
         * @return object
         */
        private function harness()
        {
            return new class {
                use ObjectFacadeTrait;

                public function scope($object, $cfg, $mode = null)
                {
                    // Passing no mode must exercise the trait's OWN default, not
                    // a default restated here.
                    if ($mode === null) {
                        return $this->inEntityScope($object, $cfg);
                    }
                    return $this->inEntityScope($object, $cfg, $mode);
                }

                public function mode($action)
                {
                    return $this->entityScopeMode($action);
                }

                public function denies($cfg, $mapper, $id)
                {
                    return $this->isolationDenies($cfg, $mapper, $id);
                }

                public function fragment($mapper, $alias, $cfg)
                {
                    return $this->isolationWhereFragment($mapper, $alias, $cfg);
                }
            };
        }

        /**
         * PSR-4 path of a dm* mapper class (SmartAuth\DolibarrMapping -> dolMapping/).
         *
         * @param  string $class
         * @return string
         */
        private function mapperFile($class)
        {
            $short = substr((string) strrchr('\\' . ltrim($class, '\\'), '\\'), 1);
            return dirname(__DIR__, 3) . '/dolMapping/' . $short . '.php';
        }

        /**
         * Whether a PHP file DECLARES a method of that name (token-exact: a mention
         * inside a comment or a string does not count).
         *
         * @param  string $file
         * @param  string $method
         * @return bool
         */
        private function declaresMethod($file, $method)
        {
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $method) {
                        return true;
                    }
                    break;
                }
            }
            return false;
        }
    }
}
