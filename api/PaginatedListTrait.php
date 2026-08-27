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
 * Maximum number of values accepted in a single `in` filter.
 *
 * A set filter is meant to express a business category ("customers and
 * prospects"), not to smuggle a thousand ids through the query string.
 * The cap matches the one the facade already applies to bulk delete.
 *
 * Declared at namespace level (not inside the trait) because the module
 * still targets PHP 7.4, where constants in traits are a parse error
 * (PHP 8.2 feature).
 */
const PAGINATED_LIST_MAX_IN_VALUES = 100;

/**
 * Generic helper for paginated/filtered/sortable list endpoints.
 *
 * Hoisted into SmartAuth from the (formerly Dolipocket-local) trait so the
 * generic objects/{type} facade and every consumer share ONE pagination
 * implementation:
 *
 *  - Parses query-string parameters: search, filter[col], sort, order, page, limit.
 *  - Builds a safe SQL WHERE clause from a filter map (text/select/daterange/
 *    numberrange/boolean) plus a global LIKE search across a whitelist of fields.
 *  - Builds an ORDER BY clause from a sortable map (whitelist).
 *  - Catalog-driven variants derive the filter/sort whitelists straight from a
 *    dm* mapper's getColumnCatalog() / getSearchFields() (single source of truth).
 *
 * Every value injected into SQL goes through DoliDB::escape() (or is cast to int),
 * so this trait does not concatenate raw user input into the query.
 *
 * Consumers must already have a $db variable (DoliDB) in scope.
 */
trait PaginatedListTrait
{
    /**
     * Normalise an incoming date/timestamp value to a Unix timestamp in
     * SECONDS (Dolibarr's expected format for $object->date / ->datep / ...).
     *
     * Accepted inputs:
     *   - int/numeric string in seconds  -> returned as-is (cast to int)
     *   - int/numeric string in milliseconds (13+ digits) -> divided by 1000
     *   - ISO-ish string ("2026-06-15", "2026-06-15T10:30") -> strtotime()
     *   - empty / null / false -> null (caller decides on a default)
     *
     * @param   mixed  $value
     * @return  int|null  Unix timestamp in seconds, or null when input is empty.
     */
    protected static function normalizeTimestamp($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;
            // 12+ digits -> milliseconds (kept generous so a future Date.now()
            // still matches as ms while seconds-since-epoch stays under it).
            if ($n > 99999999999) {
                return intdiv($n, 1000);
            }
            return $n;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts === false ? null : $ts;
        }
        return null;
    }

    /**
     * Parse pagination/filter parameters from the input array.
     *
     * Normalizes: search (trimmed string), filter (assoc array), sort (string),
     * order ('asc'|'desc', default 'asc'), page (int >= 1), limit (int clamped
     * to [1,100], default 50), and derives offset.
     *
     * @param array<string,mixed>|null $arr Raw query parameters.
     * @return array{search:string,filter:array<string,mixed>,sort:string,order:string,page:int,limit:int,offset:int}
     */
    protected function parseListParams($arr)
    {
        $arr = is_array($arr) ? $arr : [];

        $search = isset($arr['search']) ? trim((string) $arr['search']) : '';

        $filter = [];
        if (isset($arr['filter']) && is_array($arr['filter'])) {
            foreach ($arr['filter'] as $k => $v) {
                $filter[(string) $k] = $v;
            }
        }

        $sort = isset($arr['sort']) ? trim((string) $arr['sort']) : '';

        $order = 'asc';
        if (isset($arr['order'])) {
            $candidate = strtolower(trim((string) $arr['order']));
            if ($candidate === 'desc') {
                $order = 'desc';
            } elseif ($candidate === 'asc') {
                $order = 'asc';
            }
            // any other value falls back to default 'asc'
        }

        $page = isset($arr['page']) ? (int) $arr['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $limit = isset($arr['limit']) ? (int) $arr['limit'] : 50;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        $offset = ($page - 1) * $limit;

        return [
            'search' => $search,
            'filter' => $filter,
            'sort'   => $sort,
            'order'  => $order,
            'page'   => $page,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Detect whether the request carries any list/pagination parameter.
     *
     * @param array<string,mixed>|null $arr Raw input.
     * @return bool True when at least one of search/filter/sort/page/limit is set.
     */
    protected function hasListParams($arr)
    {
        if (!is_array($arr)) {
            return false;
        }
        if (isset($arr['search']) && (string) $arr['search'] !== '') {
            return true;
        }
        if (isset($arr['filter']) && is_array($arr['filter']) && !empty($arr['filter'])) {
            return true;
        }
        if (isset($arr['sort']) && (string) $arr['sort'] !== '') {
            return true;
        }
        if (isset($arr['page'])) {
            return true;
        }
        if (isset($arr['limit'])) {
            return true;
        }
        return false;
    }

    /**
     * Build a SQL WHERE fragment from the parsed filters and global search.
     *
     * The returned fragment starts with " AND ..." (or '' if no condition) and
     * is meant to be appended to a query that already has a base WHERE clause.
     *
     * Filter kinds: text (LIKE), select (equality), in (set membership),
     * daterange (col_from/col_to), numberrange (col_min/col_max), boolean
     * (0/1). Filters absent from $filterMap are ignored and logged at LOG_INFO.
     *
     * @param array<string,mixed>                              $params      Output of parseListParams().
     * @param array<string,array{column:string,kind:string}>  $filterMap   Filter whitelist.
     * @param array<int,string>                                $searchFields SQL columns for the global LIKE.
     * @return array{0:string,1:array<int,mixed>}                            Tuple [whereSql, sqlParams].
     */
    protected function buildSqlFilters(array $params, array $filterMap, array $searchFields)
    {
        global $db;

        $where = '';
        $sqlParams = [];

        // Global multi-field search (LIKE OR ...).
        $search = isset($params['search']) ? (string) $params['search'] : '';
        if ($search !== '' && !empty($searchFields)) {
            $likeEscaped = $db->escape($search);
            $orParts = [];
            foreach ($searchFields as $col) {
                // Column names are developer-controlled, not user-controlled.
                $orParts[] = (string) $col." LIKE '%".$likeEscaped."%'";
            }
            if (!empty($orParts)) {
                $where .= " AND (".implode(' OR ', $orParts).")";
            }
        }

        // Per-column filters.
        $filters = isset($params['filter']) && is_array($params['filter']) ? $params['filter'] : [];

        // Detect filters whose key is not whitelisted so we can log them once.
        // Range filters use suffixes _from/_to/_min/_max, so we tolerate those.
        foreach ($filters as $key => $val) {
            $base = (string) $key;
            $known = isset($filterMap[$base]);
            if (!$known) {
                foreach (['_from', '_to', '_min', '_max'] as $suf) {
                    $sufLen = strlen($suf);
                    if (strlen($base) > $sufLen && substr($base, -$sufLen) === $suf) {
                        $stripped = substr($base, 0, -$sufLen);
                        if (isset($filterMap[$stripped])) {
                            $known = true;
                            break;
                        }
                    }
                }
            }
            if (!$known) {
                dol_syslog(
                    "[SmartAuth] PaginatedListTrait::buildSqlFilters ignoring unknown filter key '".$base."'",
                    LOG_INFO
                );
            }
        }

        foreach ($filterMap as $apiCol => $def) {
            if (!is_array($def) || !isset($def['column'], $def['kind'])) {
                dol_syslog(
                    "[SmartAuth] PaginatedListTrait::buildSqlFilters skipping malformed filter map entry for '".(string) $apiCol."'",
                    LOG_WARNING
                );
                continue;
            }
            $sqlCol = (string) $def['column'];
            $kind = strtolower((string) $def['kind']);

            switch ($kind) {
                case 'text':
                    if (isset($filters[$apiCol]) && (string) $filters[$apiCol] !== '') {
                        $val = $db->escape((string) $filters[$apiCol]);
                        $where .= " AND ".$sqlCol." LIKE '%".$val."%'";
                    }
                    break;

                case 'select':
                    if (isset($filters[$apiCol]) && (string) $filters[$apiCol] !== '') {
                        $raw = (string) $filters[$apiCol];
                        if (is_numeric($raw)) {
                            if (strpos($raw, '.') !== false) {
                                $where .= " AND ".$sqlCol." = ".(float) $raw;
                            } else {
                                $where .= " AND ".$sqlCol." = ".(int) $raw;
                            }
                        } else {
                            $val = $db->escape($raw);
                            $where .= " AND ".$sqlCol." = '".$val."'";
                        }
                    }
                    break;

                case 'in':
                    $inSql = $this->buildInClause($sqlCol, isset($filters[$apiCol]) ? $filters[$apiCol] : null, (string) $apiCol);
                    if ($inSql !== '') {
                        $where .= $inSql;
                    }
                    break;

                case 'daterange':
                    $from = isset($filters[$apiCol.'_from']) ? (string) $filters[$apiCol.'_from'] : '';
                    $to = isset($filters[$apiCol.'_to']) ? (string) $filters[$apiCol.'_to'] : '';
                    if ($from !== '' && self::isIsoDate($from)) {
                        $where .= " AND ".$sqlCol." >= '".$db->escape($from." 00:00:00")."'";
                    }
                    if ($to !== '' && self::isIsoDate($to)) {
                        $where .= " AND ".$sqlCol." <= '".$db->escape($to." 23:59:59")."'";
                    }
                    break;

                case 'numberrange':
                    $min = isset($filters[$apiCol.'_min']) ? $filters[$apiCol.'_min'] : null;
                    $max = isset($filters[$apiCol.'_max']) ? $filters[$apiCol.'_max'] : null;
                    if ($min !== null && $min !== '' && is_numeric($min)) {
                        $where .= " AND ".$sqlCol." >= ".(float) $min;
                    }
                    if ($max !== null && $max !== '' && is_numeric($max)) {
                        $where .= " AND ".$sqlCol." <= ".(float) $max;
                    }
                    break;

                case 'boolean':
                    if (isset($filters[$apiCol]) && (string) $filters[$apiCol] !== '') {
                        $raw = (string) $filters[$apiCol];
                        if ($raw === '1' || $raw === '0') {
                            $where .= " AND ".$sqlCol." = ".(int) $raw;
                        } else {
                            dol_syslog(
                                "[SmartAuth] PaginatedListTrait::buildSqlFilters ignoring non-boolean value '".$raw."' for filter '".$apiCol."'",
                                LOG_INFO
                            );
                        }
                    }
                    break;

                default:
                    dol_syslog(
                        "[SmartAuth] PaginatedListTrait::buildSqlFilters unknown filter kind '".$kind."' for '".$apiCol."'",
                        LOG_WARNING
                    );
            }
        }

        return [$where, $sqlParams];
    }

    /**
     * Maximum number of values accepted in a single `in` filter: see the
     * PAGINATED_LIST_MAX_IN_VALUES namespace constant above (traits cannot
     * hold constants on PHP < 8.2).
     */

    /**
     * Build an " AND col IN (...)" fragment for an `in` filter.
     *
     * Two input shapes are accepted, because both occur naturally in a query
     * string: a real array (`filter[client][]=1&filter[client][]=2`) and a
     * comma-separated string (`filter[client]=1,2,3`), which is what a hand
     * written URL looks like.
     *
     * Typing follows the `select` kind: an all-numeric set is emitted
     * unquoted, anything else is escaped and quoted. Mixing the two would let
     * a numeric column be compared against a string, so a set containing a
     * single non-numeric value is quoted as a whole.
     *
     * Returns '' (no clause at all) when nothing usable is left, and says so
     * in the log: emitting `IN ()` is a SQL error on every backend, and
     * silently dropping a filter the caller asked for is exactly the kind of
     * widening that must not happen quietly.
     *
     * @param  string $sqlCol Real SQL column (developer-controlled).
     * @param  mixed  $raw    Raw filter value.
     * @param  string $apiCol API key, for logs.
     * @return string         " AND col IN (...)" or ''.
     */
    protected function buildInClause($sqlCol, $raw, $apiCol)
    {
        global $db;

        if ($raw === null || $raw === '' || $raw === []) {
            // Same convention as the other kinds: an empty filter is no filter.
            return '';
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $clean = [];
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                dol_syslog(
                    "[SmartAuth] PaginatedListTrait::buildInClause ignoring non-scalar value in filter '".$apiCol."'",
                    LOG_INFO
                );
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $clean[] = $value;
        }

        $clean = array_values(array_unique($clean));

        if (count($clean) === 0) {
            dol_syslog(
                "[SmartAuth] PaginatedListTrait::buildInClause filter '".$apiCol."' held no usable value, no condition applied",
                LOG_INFO
            );
            return '';
        }

        if (count($clean) > PAGINATED_LIST_MAX_IN_VALUES) {
            dol_syslog(
                "[SmartAuth] PaginatedListTrait::buildInClause filter '".$apiCol."' carried ".count($clean)
                    ." values, truncated to ".PAGINATED_LIST_MAX_IN_VALUES,
                LOG_WARNING
            );
            $clean = array_slice($clean, 0, PAGINATED_LIST_MAX_IN_VALUES);
        }

        $allNumeric = true;
        foreach ($clean as $value) {
            if (!is_numeric($value)) {
                $allNumeric = false;
                break;
            }
        }

        $parts = [];
        foreach ($clean as $value) {
            if ($allNumeric) {
                $parts[] = (strpos($value, '.') !== false) ? (string) (float) $value : (string) (int) $value;
            } else {
                $parts[] = "'".$db->escape($value)."'";
            }
        }

        return " AND ".$sqlCol." IN (".implode(', ', $parts).")";
    }

    /**
     * Build a safe ORDER BY clause from the parsed sort/order params.
     *
     * @param array<string,mixed>  $params       Output of parseListParams().
     * @param array<string,string> $sortableMap  Whitelist [api_col => sql_col].
     * @param string               $defaultSort  Fallback ORDER BY clause WITHOUT
     *                                           the leading "ORDER BY " keyword.
     * @return string                             The full "ORDER BY ..." fragment.
     */
    protected function buildSortClause(array $params, array $sortableMap, $defaultSort)
    {
        $sort = isset($params['sort']) ? (string) $params['sort'] : '';
        $order = isset($params['order']) ? strtolower((string) $params['order']) : 'asc';
        if ($order !== 'asc' && $order !== 'desc') {
            $order = 'asc';
        }

        if ($sort !== '' && isset($sortableMap[$sort])) {
            $sqlCol = (string) $sortableMap[$sort];
            return " ORDER BY ".$sqlCol." ".strtoupper($order);
        }

        if ($sort !== '' && !isset($sortableMap[$sort])) {
            dol_syslog(
                "[SmartAuth] PaginatedListTrait::buildSortClause ignoring non-whitelisted sort '".$sort."'",
                LOG_INFO
            );
        }

        return " ORDER BY ".((string) $defaultSort);
    }

    /**
     * Catalog-driven variant of buildSqlFilters(): the filterMap / searchFields
     * are derived from the mapper's getColumnCatalog() / getSearchFields(). The
     * $sqlAlias is prepended to each doliside column so the WHERE fragment fits
     * the host query (e.g. "s." for societe joined as s).
     *
     * @param array<string,mixed> $params    Output of parseListParams().
     * @param object              $mapper    A dm* mapper (dmBase provides the catalog).
     * @param string              $sqlAlias  Table alias to prefix each column with.
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function buildSqlFiltersFromCatalog(array $params, $mapper, $sqlAlias = '')
    {
        if (!is_object($mapper) || !method_exists($mapper, 'getColumnCatalog')) {
            dol_syslog(
                "[SmartAuth] PaginatedListTrait::buildSqlFiltersFromCatalog mapper does not expose getColumnCatalog()",
                LOG_ERR
            );
            return ['', []];
        }

        $catalog = $mapper->getColumnCatalog();
        $searchFieldsRaw = method_exists($mapper, 'getSearchFields') ? $mapper->getSearchFields() : [];

        $aliasPrefix = $this->normalizeSqlAlias($sqlAlias);

        $filterMap = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry) || empty($entry['filterable']) || empty($entry['key']) || empty($entry['doliside'])) {
                continue;
            }
            $kind = isset($entry['filterKind']) ? (string) $entry['filterKind'] : 'text';
            $filterMap[(string) $entry['key']] = [
                'column' => $aliasPrefix.((string) $entry['doliside']),
                'kind'   => $kind,
            ];
        }

        // Mechanism 3.5: explicit filterable columns declared by the mapper,
        // for cases the catalog cannot derive -- a Dolibarr class with an empty
        // $fields (Expedition/Reception/Task), or an appside key whose doliside
        // is a PHP property, NOT the real SQL column (Task 'project' reads the
        // property fk_project but must FILTER on the column fk_projet). Each entry
        // is apiKey => 'sql_col' or apiKey => ['column'=>'sql_col','kind'=>'select'].
        // These override / add to the catalog-derived map.
        if (method_exists($mapper, 'getFilterableColumns')) {
            foreach ((array) $mapper->getFilterableColumns() as $key => $def) {
                $col = is_array($def) ? (string) ($def['column'] ?? '') : (string) $def;
                if ($col === '') {
                    continue;
                }
                $filterMap[(string) $key] = [
                    'column' => $aliasPrefix.$col,
                    'kind'   => is_array($def) ? (string) ($def['kind'] ?? 'text') : 'text',
                ];
            }
        }

        $searchFields = [];
        foreach ($searchFieldsRaw as $col) {
            $searchFields[] = $aliasPrefix.((string) $col);
        }

        return $this->buildSqlFilters($params, $filterMap, $searchFields);
    }

    /**
     * Catalog-driven variant of buildSortClause(): the sortable whitelist is
     * derived from the catalog (every entry with sortable=true).
     *
     * @param array<string,mixed> $params      Output of parseListParams().
     * @param object              $mapper      A dm* mapper.
     * @param string              $sqlAlias    SQL table alias.
     * @param string              $defaultSort Fallback ORDER BY (without keyword).
     * @return string
     */
    protected function buildSortClauseFromCatalog(array $params, $mapper, $sqlAlias, $defaultSort)
    {
        if (!is_object($mapper) || !method_exists($mapper, 'getColumnCatalog')) {
            dol_syslog(
                "[SmartAuth] PaginatedListTrait::buildSortClauseFromCatalog mapper does not expose getColumnCatalog()",
                LOG_ERR
            );
            return ' ORDER BY '.((string) $defaultSort);
        }

        $catalog = $mapper->getColumnCatalog();
        $aliasPrefix = $this->normalizeSqlAlias($sqlAlias);

        $sortableMap = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry) || empty($entry['sortable']) || empty($entry['key']) || empty($entry['doliside'])) {
                continue;
            }
            $sortableMap[(string) $entry['key']] = $aliasPrefix.((string) $entry['doliside']);
        }

        // Mechanism 3.5: explicit sortable columns declared by the mapper (same
        // rationale as getFilterableColumns above -- empty $fields / property
        // != SQL column). apiKey => 'sql_col'.
        //
        // An entry may also be apiKey => ['expression' => '<sql>'] when the
        // ordering is COMPUTED rather than stored: "most recently active
        // customer" is a MAX() over another table, not a column. The
        // expression is used verbatim, WITHOUT the alias prefix (it carries
        // its own qualified names), and it must be a scalar correlated
        // subquery so the list query keeps its shape -- no JOIN, no GROUP BY,
        // and above all a COUNT that stays exact.
        //
        // Like column names, an expression is developer-controlled: it comes
        // from a mapper in this repository, never from the request.
        if (method_exists($mapper, 'getSortableColumns')) {
            foreach ((array) $mapper->getSortableColumns() as $key => $col) {
                if (is_array($col)) {
                    $expression = isset($col['expression']) ? trim((string) $col['expression']) : '';
                    if ($expression === '') {
                        dol_syslog(
                            "[SmartAuth] PaginatedListTrait::buildSortClauseFromCatalog skipping sortable entry '"
                                .(string) $key."' declared as an array without a usable 'expression'",
                            LOG_WARNING
                        );
                        continue;
                    }
                    $sortableMap[(string) $key] = $expression;
                    continue;
                }
                if ((string) $col === '') {
                    continue;
                }
                $sortableMap[(string) $key] = $aliasPrefix.((string) $col);
            }
        }

        return $this->buildSortClause($params, $sortableMap, $defaultSort);
    }

    /**
     * Normalize a SQL alias so callers can pass either "s" or "s.".
     *
     * @param   string  $sqlAlias
     * @return  string  empty string when no alias is given
     */
    private function normalizeSqlAlias($sqlAlias)
    {
        $a = trim((string) $sqlAlias);
        if ($a === '') {
            return '';
        }
        if (substr($a, -1) === '.') {
            return $a;
        }
        return $a.'.';
    }

    /**
     * Build the standard {items,total,page,limit} response envelope.
     *
     * @param array<int,mixed>  $items
     * @param int               $total
     * @param int               $page
     * @param int               $limit
     * @return array{items:array<int,mixed>,total:int,page:int,limit:int}
     */
    protected function formatPaginatedResponse(array $items, $total, $page, $limit)
    {
        return [
            'items' => $items,
            'total' => (int) $total,
            'page'  => (int) $page,
            'limit' => (int) $limit,
        ];
    }

    /**
     * Cheap ISO-8601 date check (YYYY-MM-DD only).
     *
     * @param string $value
     * @return bool
     */
    private static function isIsoDate($value)
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value);
    }
}
