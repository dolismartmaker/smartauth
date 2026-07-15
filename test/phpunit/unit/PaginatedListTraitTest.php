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
