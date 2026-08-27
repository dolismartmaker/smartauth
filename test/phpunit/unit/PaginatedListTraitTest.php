<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Tests\Mocks\MockDatabase;

/**
 * Test double exposing the protected PaginatedListTrait methods.
 */
class PaginatedListTraitProbe
{
    use \SmartAuth\Api\PaginatedListTrait;

    public function callParse($arr)
    {
        return $this->parseListParams($arr);
    }

    public function callHasList($arr)
    {
        return $this->hasListParams($arr);
    }

    public function callSort(array $p, array $map, $default)
    {
        return $this->buildSortClause($p, $map, $default);
    }

    public function callFilters(array $p, array $map, array $search)
    {
        return $this->buildSqlFilters($p, $map, $search);
    }

    public function callSortFromCatalog(array $p, $mapper, $alias, $default)
    {
        return $this->buildSortClauseFromCatalog($p, $mapper, $alias, $default);
    }

    public function callFormat(array $items, $total, $page, $limit)
    {
        return $this->formatPaginatedResponse($items, $total, $page, $limit);
    }

    public function callNormTs($v)
    {
        return self::normalizeTimestamp($v);
    }
}

/**
 * Unit tests for the generic pagination/filter/sort trait. The SQL-building
 * paths are checked for correct escaping (injection safety) using a mock db.
 *
 * @covers \SmartAuth\Api\PaginatedListTrait
 */
/**
 * Minimal mapper stub for the computed-sort tests: one plain column (which
 * must be alias-prefixed), one expression (which must not be), and one
 * malformed entry (which must be skipped).
 */
class SortableExpressionMapperStub
{
    public function getColumnCatalog()
    {
        return [];
    }

    public function getSortableColumns()
    {
        return [
            'name' => 'nom',
            'last_activity' => [
                'expression' => 'COALESCE((SELECT MAX(a.datep) FROM llx_actioncomm a WHERE a.fk_soc = s.rowid), s.tms)',
            ],
            'broken' => ['expression' => '   '],
        ];
    }
}

class PaginatedListTraitTest extends TestCase
{
    /** @var PaginatedListTraitProbe */
    private $probe;

    protected function setUp(): void
    {
        global $db;
        $db = new MockDatabase();
        $this->probe = new PaginatedListTraitProbe();
    }

    public function testParseListParamsAppliesDefaults(): void
    {
        $p = $this->probe->callParse(null);
        $this->assertSame('', $p['search']);
        $this->assertSame([], $p['filter']);
        $this->assertSame('', $p['sort']);
        $this->assertSame('asc', $p['order']);
        $this->assertSame(1, $p['page']);
        $this->assertSame(50, $p['limit']);
        $this->assertSame(0, $p['offset']);
    }

    public function testParseListParamsClampsLimitAndPage(): void
    {
        $over = $this->probe->callParse(['limit' => 5000, 'page' => 3]);
        $this->assertSame(100, $over['limit']);
        $this->assertSame(3, $over['page']);
        $this->assertSame(200, $over['offset']); // (3-1)*100

        $under = $this->probe->callParse(['limit' => 0, 'page' => 0]);
        $this->assertSame(1, $under['limit']);
        $this->assertSame(1, $under['page']);
        $this->assertSame(0, $under['offset']);
    }

    public function testParseListParamsNormalizesOrder(): void
    {
        $this->assertSame('desc', $this->probe->callParse(['order' => 'DESC'])['order']);
        $this->assertSame('asc', $this->probe->callParse(['order' => 'weird'])['order']);
    }

    public function testHasListParamsDetection(): void
    {
        $this->assertFalse($this->probe->callHasList(null));
        $this->assertFalse($this->probe->callHasList([]));
        $this->assertTrue($this->probe->callHasList(['search' => 'x']));
        $this->assertTrue($this->probe->callHasList(['page' => 2]));
        $this->assertTrue($this->probe->callHasList(['filter' => ['a' => 1]]));
        $this->assertFalse($this->probe->callHasList(['search' => '']));
    }

    public function testBuildSortClauseHonorsWhitelistAndDefault(): void
    {
        $map = ['name' => 's.nom'];
        $ok = $this->probe->callSort(['sort' => 'name', 'order' => 'desc'], $map, 's.rowid ASC');
        $this->assertSame(' ORDER BY s.nom DESC', $ok);

        // Non-whitelisted sort falls back to the default (no user column injected).
        $bad = $this->probe->callSort(['sort' => 'nom; DROP TABLE x', 'order' => 'asc'], $map, 's.rowid ASC');
        $this->assertSame(' ORDER BY s.rowid ASC', $bad);
    }

    public function testBuildSqlFiltersTextFilterIsEscaped(): void
    {
        $map = ['name' => ['column' => 's.nom', 'kind' => 'text']];
        list($where, ) = $this->probe->callFilters(
            ['filter' => ['name' => "a' OR '1'='1"]],
            $map,
            []
        );
        // The single quotes in the injection attempt must be escaped, so the
        // clause stays a single LIKE literal (no free-standing OR).
        $this->assertStringContainsString("s.nom LIKE '%a\\' OR \\'1\\'=\\'1%'", $where);
    }

    public function testBuildSqlFiltersGlobalSearchEscapesAcrossFields(): void
    {
        list($where, ) = $this->probe->callFilters(
            ['search' => "x'y"],
            [],
            ['s.nom', 's.email']
        );
        $this->assertStringContainsString("s.nom LIKE '%x\\'y%'", $where);
        $this->assertStringContainsString("s.email LIKE '%x\\'y%'", $where);
        $this->assertStringContainsString(' OR ', $where);
    }

    public function testBuildSqlFiltersSelectBooleanNumberrange(): void
    {
        $map = [
            'client'  => ['column' => 's.client', 'kind' => 'select'],
            'active'  => ['column' => 's.status', 'kind' => 'boolean'],
            'price'   => ['column' => 's.price', 'kind' => 'numberrange'],
        ];
        list($where, ) = $this->probe->callFilters(
            ['filter' => ['client' => '2', 'active' => '1', 'price_min' => '10', 'price_max' => '99.5']],
            $map,
            []
        );
        $this->assertStringContainsString('s.client = 2', $where);
        $this->assertStringContainsString('s.status = 1', $where);
        $this->assertStringContainsString('s.price >= 10', $where);
        $this->assertStringContainsString('s.price <= 99.5', $where);
    }

    public function testBuildSqlFiltersIgnoresNonBooleanValue(): void
    {
        $map = ['active' => ['column' => 's.status', 'kind' => 'boolean']];
        list($where, ) = $this->probe->callFilters(['filter' => ['active' => 'maybe']], $map, []);
        $this->assertStringNotContainsString('s.status', $where);
    }

    // -----------------------------------------------------------------
    // `in` kind: set membership. Added so a consumer can express a
    // business category ("customers AND prospects" = client IN (1,2,3))
    // that `select` could only approximate with several requests.
    // -----------------------------------------------------------------

    public function testBuildSqlFiltersInAcceptsACommaSeparatedString(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['client' => '1,2,3']], $map, []);

        $this->assertStringContainsString('s.client IN (1, 2, 3)', $where);
    }

    public function testBuildSqlFiltersInAcceptsAnArray(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['client' => [1, 2, 3]]], $map, []);

        $this->assertStringContainsString('s.client IN (1, 2, 3)', $where);
    }

    public function testBuildSqlFiltersInTrimsAndDeduplicates(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['client' => ' 1 , 2,1, ,2 ']], $map, []);

        $this->assertStringContainsString('s.client IN (1, 2)', $where);
    }

    public function testBuildSqlFiltersInQuotesAndEscapesNonNumericSets(): void
    {
        $map = ['code' => ['column' => 's.code_client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['code' => "CU01,x' OR '1'='1"]], $map, []);

        // The injection attempt must stay a single quoted literal.
        $this->assertStringContainsString("s.code_client IN ('CU01', 'x\\' OR \\'1\\'=\\'1')", $where);
    }

    /**
     * A single non-numeric value makes the whole set quoted: emitting a mix
     * would compare a numeric column against a string.
     */
    public function testBuildSqlFiltersInQuotesTheWholeSetWhenOneValueIsNotNumeric(): void
    {
        $map = ['code' => ['column' => 's.code_client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['code' => '1,ABC,3']], $map, []);

        $this->assertStringContainsString("s.code_client IN ('1', 'ABC', '3')", $where);
    }

    public function testBuildSqlFiltersInKeepsDecimals(): void
    {
        $map = ['rate' => ['column' => 's.tva_tx', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['rate' => '5.5,20']], $map, []);

        $this->assertStringContainsString('s.tva_tx IN (5.5, 20)', $where);
    }

    /**
     * `IN ()` is a syntax error on every backend, so an empty set must emit
     * no clause at all rather than a broken one.
     */
    public function testBuildSqlFiltersInEmitsNothingForAnEmptySet(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];

        foreach (['', ' , , ', null] as $value) {
            list($where, ) = $this->probe->callFilters(['filter' => ['client' => $value]], $map, []);
            $this->assertStringNotContainsString('IN (', $where, 'no IN () may be emitted');
            $this->assertStringNotContainsString('s.client', $where);
        }

        list($where, ) = $this->probe->callFilters(['filter' => ['client' => []]], $map, []);
        $this->assertStringNotContainsString('IN (', $where);
    }

    public function testBuildSqlFiltersInSkipsNonScalarMembers(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => ['client' => [1, ['nested'], 2]]], $map, []);

        $this->assertStringContainsString('s.client IN (1, 2)', $where);
    }

    /**
     * A set filter expresses a category, not a smuggled id dump.
     */
    public function testBuildSqlFiltersInIsCappedAtOneHundredValues(): void
    {
        $map = ['id' => ['column' => 's.rowid', 'kind' => 'in']];
        $values = range(1, 150);
        list($where, ) = $this->probe->callFilters(['filter' => ['id' => $values]], $map, []);

        preg_match('/IN \(([^)]*)\)/', $where, $m);
        $this->assertNotEmpty($m, 'an IN clause must still be emitted');
        $this->assertCount(100, explode(',', $m[1]), 'the set is capped at 100 values');
    }

    public function testBuildSqlFiltersInIsAbsentWhenTheFilterIsNotSent(): void
    {
        $map = ['client' => ['column' => 's.client', 'kind' => 'in']];
        list($where, ) = $this->probe->callFilters(['filter' => []], $map, []);

        $this->assertSame('', $where);
    }

    // -----------------------------------------------------------------
    // Computed sort: an ordering that is not a stored column (e.g. "most
    // recently active"), declared by a mapper as a scalar expression.
    // -----------------------------------------------------------------

    public function testBuildSortClauseAcceptsAComputedExpression(): void
    {
        $expression = 'COALESCE((SELECT MAX(a.datep) FROM llx_actioncomm a WHERE a.fk_soc = s.rowid), s.tms)';
        $map = ['last_activity' => $expression];

        $out = $this->probe->callSort(['sort' => 'last_activity', 'order' => 'desc'], $map, 's.rowid ASC');

        $this->assertSame(' ORDER BY '.$expression.' DESC', $out);
    }

    /**
     * The expression must NOT be alias-prefixed: it carries its own qualified
     * names, and "s.COALESCE(...)" would be a syntax error.
     */
    public function testComputedExpressionIsNotAliasPrefixed(): void
    {
        $probe = new PaginatedListTraitProbe();
        $mapper = new SortableExpressionMapperStub();

        $out = $probe->callSortFromCatalog(['sort' => 'last_activity', 'order' => 'desc'], $mapper, 's', 's.rowid ASC');

        $this->assertStringContainsString('COALESCE(', $out);
        $this->assertStringNotContainsString('s.COALESCE', $out);
    }

    public function testPlainColumnEntriesAreStillAliasPrefixed(): void
    {
        $probe = new PaginatedListTraitProbe();
        $mapper = new SortableExpressionMapperStub();

        $out = $probe->callSortFromCatalog(['sort' => 'name', 'order' => 'asc'], $mapper, 's', 's.rowid ASC');

        $this->assertSame(' ORDER BY s.nom ASC', $out);
    }

    /**
     * A malformed entry must be skipped, not turned into a broken ORDER BY.
     */
    public function testEmptyExpressionEntryIsSkipped(): void
    {
        $probe = new PaginatedListTraitProbe();
        $mapper = new SortableExpressionMapperStub();

        $out = $probe->callSortFromCatalog(['sort' => 'broken', 'order' => 'asc'], $mapper, 's', 's.rowid ASC');

        $this->assertSame(' ORDER BY s.rowid ASC', $out, 'falls back to the default sort');
    }

    public function testFormatPaginatedResponseShapeAndCasts(): void
    {
        $env = $this->probe->callFormat([['a' => 1]], '7', '2', '25');
        $this->assertSame([['a' => 1]], $env['items']);
        $this->assertSame(7, $env['total']);
        $this->assertSame(2, $env['page']);
        $this->assertSame(25, $env['limit']);
    }

    public function testNormalizeTimestampHandlesSecondsMsAndIso(): void
    {
        $this->assertSame(1777939200, $this->probe->callNormTs(1777939200));       // seconds
        $this->assertSame(1777939200, $this->probe->callNormTs(1777939200000));    // ms -> seconds
        $this->assertNull($this->probe->callNormTs(''));
        $this->assertNull($this->probe->callNormTs(null));
        $this->assertSame(strtotime('2026-06-15'), $this->probe->callNormTs('2026-06-15'));
    }
}
