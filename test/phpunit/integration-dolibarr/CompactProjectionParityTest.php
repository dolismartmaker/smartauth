<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';
require_once __DIR__ . '/../../../api/ObjectRegistry.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectRegistry;

/**
 * The compact list path must return EXACTLY what the full path returns.
 *
 * ObjectController::index() can now serve a page from the list query alone when
 * ?include= names only real columns, skipping the per-row fetch() that made a
 * 50-row page cost x276 a compact SELECT
 * (documentation/facade-list-performance.md). That is only defensible if the
 * two paths are indistinguishable from outside: a consumer must never see two
 * contracts depending on how many columns it asked for.
 *
 * This suite is the guard. It runs the same request twice -- once compact, once
 * forced through the full path -- and compares the payloads field by field, on
 * every type of the registry that has rows and qualifies. It is what makes the
 * optimisation safe to keep, and what will fail the day a mapper gains a field
 * the compact path cannot reproduce.
 *
 * @covers \SmartAuth\Api\ObjectController::index
 * @covers \SmartAuth\DolibarrMapping\dmBase::supportsCompactProjection
 */
class CompactProjectionParityTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    /** @var array<string,int> rows actually compared, per type */
    private $comparedRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
    }

    /**
     * Thirdparty is the type the whole exercise was for: the autocomplete and
     * the offline pre-cache of smartInterventions both serve id/name/zip/town.
     */
    public function testThirdpartyCompactPageMatchesTheFullPage(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createTestSociete(['name' => 'Parity ' . $i . ' ' . uniqid()]);
        }

        $include = 'id,zip,city,status';
        $compact = $this->listWith('thirdparty', $include);
        $full = $this->listWithFullPath('thirdparty', $include);

        $this->assertNotEmpty($compact['items'], 'the fixture must produce rows');
        $this->assertSame($full['total'], $compact['total']);
        $this->assertPayloadsIdentical($full['items'], $compact['items'], 'thirdparty');
    }

    /**
     * The compact path must actually be taken for that request, otherwise this
     * suite would be comparing the full path with itself and prove nothing.
     */
    public function testThirdpartyCompactPathIsActuallySelected(): void
    {
        $mapper = new \SmartAuth\DolibarrMapping\dmThirdparty();

        $this->assertTrue(
            $mapper->supportsCompactProjection(['id', 'zip', 'city', 'status']),
            'a request for plain columns must take the compact path'
        );
    }

    /**
     * And it must refuse everything it cannot reproduce identically.
     */
    public function testCompactPathRefusesWhatItCannotReproduce(): void
    {
        $mapper = new \SmartAuth\DolibarrMapping\dmThirdparty();

        $this->assertFalse(
            $mapper->supportsCompactProjection(null),
            'no ?include= means everything, including extrafields'
        );
        $this->assertFalse(
            $mapper->supportsCompactProjection([]),
            'an empty include is not a compact projection'
        );
        $this->assertFalse(
            $mapper->supportsCompactProjection(['id', 'name']),
            'name maps to the SQL column nom: not a real column under that name'
        );
        $this->assertFalse(
            $mapper->supportsCompactProjection(['id', 'no_such_field_at_all']),
            'an unknown key must fail closed'
        );
    }

    /**
     * Parity across the whole registry, not just the type that motivated the
     * change: every type that has rows and qualifies is compared.
     */
    public function testParityHoldsForEveryQualifyingType(): void
    {
        $checked = [];
        $skipped = [];

        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            if (!$this->tableExists($cfg['table'])) {
                $skipped[$type] = 'table absent from the harness';
                continue;
            }

            $mapperClass = $cfg['mapper'] ?? null;
            if (!$mapperClass || !class_exists($mapperClass)) {
                $skipped[$type] = 'no mapper';
                continue;
            }

            $include = $this->compactIncludeFor(new $mapperClass());
            if ($include === null) {
                $skipped[$type] = 'compact path not enabled for this mapper';
                continue;
            }

            $compact = $this->listWith($type, $include);
            if ($compact === null) {
                $skipped[$type] = 'list refused (rights or module disabled)';
                continue;
            }
            if (empty($compact['items'])) {
                $skipped[$type] = 'no row';
                continue;
            }

            $full = $this->listWithFullPath($type, $include);
            $this->assertPayloadsIdentical($full['items'], $compact['items'], $type);
            if (empty($this->comparedRows[$type])) {
                $skipped[$type] = 'no row survives fetch() on the full path';
                continue;
            }
            $checked[] = $type;
        }

        // No silent coverage: say what was actually compared.
        $this->assertNotEmpty(
            $checked,
            'nothing was compared, so this test proves nothing. Skipped: ' . json_encode($skipped)
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run the list through the controller, compact path allowed.
     *
     * @return array{items:array,total:int}|null
     */
    private function listWith(string $type, string $include): ?array
    {
        list($body, $code) = $this->controller->index([
            'objtype' => $type,
            'include' => $include,
            'limit' => 5,
        ]);

        if ($code !== 200) {
            return null;
        }
        return ['items' => $body['items'], 'total' => (int) $body['total']];
    }

    /**
     * The exact same request, forced through the full path by the production
     * escape hatch (SMARTAUTH_FACADE_COMPACT_LIST=0). Same include, same
     * filters, same sort: the only difference is the code path, which is what
     * makes the comparison meaningful.
     *
     * @return array{items:array,total:int}
     */
    private function listWithFullPath(string $type, string $include): array
    {
        global $conf;

        $saved = $conf->global->SMARTAUTH_FACADE_COMPACT_LIST ?? null;
        $conf->global->SMARTAUTH_FACADE_COMPACT_LIST = 0;
        try {
            list($body, $code) = $this->controller->index([
                'objtype' => $type,
                'include' => $include,
                'limit' => 5,
            ]);
        } finally {
            if ($saved === null) {
                unset($conf->global->SMARTAUTH_FACADE_COMPACT_LIST);
            } else {
                $conf->global->SMARTAUTH_FACADE_COMPACT_LIST = $saved;
            }
        }

        $this->assertSame(200, $code, "full list($type): " . json_encode($body));

        return ['items' => $body['items'], 'total' => (int) $body['total']];
    }

    /**
     * Compare two payload lists key by key, on the keys the caller asked for.
     * Companion fields (status_label, FK labels) are compared too when both
     * sides carry them: they are part of what a consumer sees.
     */
    private function assertPayloadsIdentical(array $full, array $compact, string $type): void
    {
        $fullById = $this->indexById($full);
        $compactById = $this->indexById($compact);

        // The compact path may legitimately return MORE rows: the full path
        // silently drops any row whose fetch() fails (while `total` still counts
        // it), which is a pre-existing defect of the full path, not of the
        // projection. The reverse would be a real loss and must fail.
        $lostByCompact = array_diff(array_keys($fullById), array_keys($compactById));
        $this->assertSame(
            [],
            array_values($lostByCompact),
            "the compact path lost rows the full path returned, on $type"
        );

        $comparable = array_intersect_key($compactById, $fullById);
        if (empty($comparable)) {
            // Every row of the fixture failed fetch() on the full path (the
            // harness seeds some tables with raw SQL that the Dolibarr class
            // refuses to load). Nothing to compare, and nothing to conclude.
            $this->comparedRows[$type] = 0;
            return;
        }
        $this->comparedRows[$type] = count($comparable);

        foreach ($comparable as $id => $compactRow) {
            $fullRow = $fullById[$id];

            foreach ($compactRow as $key => $value) {
                if (!array_key_exists($key, $fullRow)) {
                    $this->fail("compact path invented the key '$key' on $type#$id");
                }
                $this->assertSame(
                    $this->normalise($fullRow[$key]),
                    $this->normalise($value),
                    "value of '$key' differs on $type#$id (full vs compact)"
                );
            }

            foreach ($fullRow as $key => $value) {
                $this->assertArrayHasKey($key, $compactRow, "compact path dropped '$key' on $type#$id");
            }
        }
    }

    /**
     * The smallest include this mapper accepts as a compact projection, or null
     * when it accepts none.
     */
    private function compactIncludeFor($mapper): ?string
    {
        if (!method_exists($mapper, 'supportsCompactProjection')) {
            return null;
        }

        $reflection = new \ReflectionClass($mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $published = (array) $property->getValue($mapper);

        $keys = ['id'];
        foreach ($published as $appside) {
            $candidate = array_merge($keys, [(string) $appside]);
            if ($mapper->supportsCompactProjection($candidate)) {
                $keys = $candidate;
            }
            if (count($keys) >= 4) {
                break;
            }
        }

        if (count($keys) < 2) {
            return null;
        }
        return implode(',', $keys);
    }

    private function indexById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[(string) ($row['id'] ?? '?')] = $row;
        }
        return $out;
    }

    /**
     * Objects and arrays are compared by value; everything else as-is. Numeric
     * strings and integers are NOT collapsed on purpose: a type change between
     * the two paths is exactly the kind of divergence this test exists to
     * catch.
     */
    private function normalise($value)
    {
        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }
        return $value;
    }

    private function keepKeysOf($item, array $keys): array
    {
        $item = (array) $item;
        $out = [];
        foreach ($item as $k => $v) {
            // Companions ride along with their source field, like the facade
            // does through exportMappedDataFiltered's structural keys.
            if (in_array($k, $keys, true) || $k === 'id' || $this->isCompanionKey($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function isCompanionKey(string $key): bool
    {
        return $key === 'status_label'
            || $key === 'categories'
            || $key === 'nb_linked_files'
            || $key === 'linked_files'
            || substr($key, -4) === 'Name'
            || substr($key, -5) === 'Email';
    }

    private function tableExists(string $table): bool
    {
        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . $table . ' LIMIT 1');
        return $resql !== false && $resql !== null;
    }
}
