<?php

/**
 * Tests for the dmMember and dmSubscription mappers.
 *
 * They cover the three defects fixed on that pair, each one falsifiable:
 * neutralise the fix and the matching test goes red.
 *
 *   1. dmMember published phone_pro and fax (phone_pro was even writable)
 *      although llx_adherent has no such column and Adherent::update() only
 *      ever writes phone, phone_perso and phone_mobile -- a write answered 200
 *      and lost the value. testGhostColumnsDoNotExistInLlxAdherent proves the
 *      premise against the real schema, so the removal cannot be a guess.
 *   2. eleven published keys address a PHP property that fetch() renames from
 *      the column it selects -- including the status (statut), the member type
 *      (fk_adherent_type) and the third party (fk_soc). The catalog derivation
 *      cannot see them, so the three axes a member list is actually read
 *      through were silently non filterable and non sortable. The SQL tests
 *      below assert the produced fragments really name the columns.
 *   3. Subscription::create() does not insert fk_bank while update() writes it:
 *      the field is kept writable and the asymmetry is pinned here instead of
 *      living only in a comment.
 *
 * The isolation hook of dmSubscription is asserted too: llx_subscription has
 * no entity column, the registry flags it has_entity=false, and that hook
 * carries the WHOLE tenant isolation of the type (the facade is fail-closed
 * without it).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmMember;
use SmartAuth\DolibarrMapping\dmSubscription;
use SmartAuth\DolibarrMapping\MapperValidationException;
use SmartAuth\Api\ObjectRegistry;
use ReflectionClass;

require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

/**
 * Test double exposing the protected catalog-driven builders of
 * PaginatedListTrait, so the SQL a real list query would emit can be asserted.
 * Same shape as DmTicketListProbe.
 */
class DmMemberListProbe
{
    use \SmartAuth\Api\PaginatedListTrait;

    public function filters(array $params, $mapper, $alias)
    {
        return $this->buildSqlFiltersFromCatalog($params, $mapper, $alias);
    }

    public function sort(array $params, $mapper, $alias, $default)
    {
        return $this->buildSortClauseFromCatalog($params, $mapper, $alias, $default);
    }

    public function parse($arr)
    {
        return $this->parseListParams($arr);
    }
}

/**
 * @covers \SmartAuth\DolibarrMapping\dmMember
 * @covers \SmartAuth\DolibarrMapping\dmSubscription
 */
class DmMemberMapperTest extends DolibarrRealTestCase
{
    /** @var dmMember */
    private $mapper;

    /** @var dmSubscription */
    private $subscriptionMapper;

    /** @var DmMemberListProbe */
    private $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new dmMember();
        $this->subscriptionMapper = new dmSubscription();
        $this->probe = new DmMemberListProbe();
    }

    /* -----------------------------------------------------------------
     * Defect 1 -- the ghost columns
     * --------------------------------------------------------------- */

    /**
     * Premise of the whole removal, asserted against the schema actually
     * loaded in the harness rather than against the SQL file: if either column
     * existed, dropping the keys would be a regression.
     */
    public function testGhostColumnsDoNotExistInLlxAdherent(): void
    {
        $columns = $this->tableColumns('adherent');

        $this->assertNotEmpty($columns, 'llx_adherent introspection returned no column');
        $this->assertContains('phone_mobile', $columns, 'sanity check: llx_adherent must expose its real columns');

        foreach (['phone_pro', 'fax'] as $ghost) {
            $this->assertNotContains(
                $ghost,
                $columns,
                $ghost . ' now exists in llx_adherent: dmMember must publish it again'
            );
        }
    }

    /**
     * The two keys are gone from the published mapping, so they can no longer
     * show up as always-empty columns in a column catalog nor as editable
     * fields in a generated form.
     */
    public function testGhostKeysAreNoLongerPublished(): void
    {
        $published = $this->publishedFields(dmMember::class, $this->mapper);

        foreach (['phone_pro', 'fax'] as $ghost) {
            $this->assertArrayNotHasKey($ghost, $published);
        }

        $desc = $this->mapper->objectDesc();
        foreach (['phone_pro', 'fax'] as $apiKey) {
            $this->assertObjectNotHasProperty($apiKey, $desc);
        }
    }

    /**
     * Write side, and the actual behaviour change: a phone_pro write used to be
     * accepted and silently dropped. It is now rejected with a named error.
     */
    public function testWritingPhoneProIsRejectedInsteadOfSilentlyIgnored(): void
    {
        try {
            $this->mapper->importMappedData(['phone_pro' => 'lost in the void']);
            $this->fail('Expected MapperValidationException for phone_pro');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('phone_pro', $e->getErrors());
        }
    }

    /**
     * The generic guard that would have caught the original bug on its own:
     * anything declared writable must be a real column of llx_adherent, since
     * Adherent::update() only ever writes columns.
     */
    public function testEveryWritableFieldIsARealColumnOfLlxAdherent(): void
    {
        $columns = $this->tableColumns('adherent');
        $writable = $this->writableFields(dmMember::class, $this->mapper);

        $this->assertNotEmpty($writable);
        foreach ($writable as $field) {
            $this->assertContains(
                $this->columnForDoliside($field),
                $columns,
                "writable field '" . $field . "' has no column in llx_adherent: writing it would be a silent no-op"
            );
        }
    }

    /**
     * The registry entry documents itself as mirroring the mapper allowlist.
     * Kept in step here so the two never drift again.
     */
    public function testRegistryAllowedFieldsMirrorWritableFields(): void
    {
        $builtins = ObjectRegistry::builtins();
        $this->assertArrayHasKey('member', $builtins);

        $allowed = $builtins['member']['allowed_fields'];
        sort($allowed);
        $writable = $this->writableFields(dmMember::class, $this->mapper);
        sort($writable);

        $this->assertSame($writable, $allowed);
    }

    /* -----------------------------------------------------------------
     * Defect 2 -- filter, sort and free-text search
     * --------------------------------------------------------------- */

    /**
     * The core of the fix, and the trap of this object at the same time: the
     * member status is negative and 0 means RESILIATED, not draft. A filter on
     * the draft status must reach the statut column with the value -1.
     */
    public function testStatusFilterTargetsTheStatutColumn(): void
    {
        $params = $this->probe->parse(['filter' => ['status' => (string) \Adherent::STATUS_DRAFT]]);
        list($where) = $this->probe->filters($params, $this->mapper, 'a');

        $this->assertSame(-1, \Adherent::STATUS_DRAFT, 'the draft status of a member is -1, not 0');
        $this->assertSame(0, \Adherent::STATUS_RESILIATED, '0 is RESILIATED, never a draft');

        $this->assertStringContainsString('a.statut = -1', $where);
        $this->assertStringNotContainsString('a.status', $where, 'a.status is not a column of llx_adherent');
    }

    /**
     * The two other axes a member list is read through, both renamed by
     * fetch(): the type (property typeid, column fk_adherent_type) and the
     * third party (property socid, column fk_soc).
     */
    public function testMemberTypeAndThirdpartyFiltersTargetTheirRealColumns(): void
    {
        $params = $this->probe->parse(['filter' => ['memberType' => '7', 'thirdparty' => '42']]);
        list($where) = $this->probe->filters($params, $this->mapper, 'a');

        $this->assertStringContainsString('a.fk_adherent_type = 7', $where);
        $this->assertStringContainsString('a.fk_soc = 42', $where);
        $this->assertStringNotContainsString('a.typeid', $where);
        $this->assertStringNotContainsString('a.socid', $where);
    }

    /**
     * Same for the sort axis.
     */
    public function testStatusSortTargetsTheStatutColumn(): void
    {
        $params = $this->probe->parse(['sort' => 'status', 'order' => 'desc']);
        $clause = $this->probe->sort($params, $this->mapper, 'a', 'a.rowid DESC');

        $this->assertSame(' ORDER BY a.statut DESC', $clause);
    }

    /**
     * Sorting on the company name goes through the societe column: the
     * published doliside is the property $company, which the catalog cannot
     * resolve, so without the map the request degraded to the default clause.
     */
    public function testCompanySortTargetsTheSocieteColumn(): void
    {
        $params = $this->probe->parse(['sort' => 'company', 'order' => 'asc']);
        $clause = $this->probe->sort($params, $this->mapper, 'a', 'a.rowid DESC');

        $this->assertSame(' ORDER BY a.societe ASC', $clause);
    }

    /**
     * An unknown key must still degrade to the default clause rather than emit
     * an ORDER BY on something llx_adherent does not have.
     */
    public function testSortingOnAnUnknownKeyFallsBackToTheDefaultClause(): void
    {
        $params = $this->probe->parse(['sort' => 'photo_of_the_cat', 'order' => 'asc']);
        $clause = $this->probe->sort($params, $this->mapper, 'a', 'a.rowid DESC');

        $this->assertSame(' ORDER BY a.rowid DESC', $clause);
    }

    /**
     * Every column named by the two maps must exist, otherwise the list query
     * dies on "Unknown column" -- the exact failure mode the maps exist to
     * prevent.
     *
     * @dataProvider mapperMapProvider
     */
    public function testFilterAndSortMapsOnlyNameRealColumns(string $mapperKey, string $table): void
    {
        $mapper = $this->mapperFor($mapperKey);
        $columns = $this->tableColumns($table);

        // Guard against the vacuous pass: an empty map would satisfy every
        // foreach below while having silently dropped the whole fix.
        $this->assertNotEmpty($mapper->getFilterableColumns());
        $this->assertNotEmpty($mapper->getSortableColumns());

        foreach ($mapper->getFilterableColumns() as $apiKey => $def) {
            $this->assertContains(
                $def['column'],
                $columns,
                "filterable '" . $apiKey . "' points at a missing column '" . $def['column'] . "'"
            );
        }
        foreach ($mapper->getSortableColumns() as $apiKey => $column) {
            $this->assertContains(
                $column,
                $columns,
                "sortable '" . $apiKey . "' points at a missing column '" . $column . "'"
            );
        }
    }

    /**
     * The maps are keyed on the CAMEL CASE catalog keys the frontend sends
     * (dmBase converts the appside name through snakeToCamel). Keying them on
     * the snake_case appside is a silent no-op: the filter is simply never
     * matched.
     *
     * @dataProvider mapperMapProvider
     */
    public function testFilterAndSortKeysAreCatalogKeys(string $mapperKey, string $table): void
    {
        $mapper = $this->mapperFor($mapperKey);
        $class = $mapperKey === 'member' ? dmMember::class : dmSubscription::class;

        $expected = [];
        foreach ($this->publishedFields($class, $mapper) as $appside) {
            if ($appside === 'id') {
                continue;
            }
            $expected[$this->camelize($appside)] = true;
        }

        foreach (array_keys($mapper->getFilterableColumns()) as $key) {
            $this->assertArrayHasKey(
                $key,
                $expected,
                "filter key '" . $key . "' of llx_" . $table . " is not a catalog key"
            );
        }
        foreach (array_keys($mapper->getSortableColumns()) as $key) {
            $this->assertArrayHasKey(
                $key,
                $expected,
                "sort key '" . $key . "' of llx_" . $table . " is not a catalog key"
            );
        }
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function mapperMapProvider(): array
    {
        return [
            'dmMember'       => ['member', 'adherent'],
            'dmSubscription' => ['subscription', 'subscription'],
        ];
    }

    /**
     * KNOWN BOUNDARY of the fix, pinned so the next reader does not discover it
     * in production: the two maps repair the SQL, NOT the catalog flags.
     * getColumnCatalog() still derives filterable/sortable from
     * "is the doliside a key of Adherent::$fields", so status, memberType and
     * thirdparty come back flagged false even though the facade now handles
     * them perfectly. A frontend that hides a filter on filterable=false must
     * force these three columns through its own listConfig overrides.
     */
    public function testCatalogStillFlagsTheRenamedKeysAsNonFilterable(): void
    {
        $flags = [];
        foreach ($this->mapper->getColumnCatalog() as $entry) {
            $flags[$entry['key']] = $entry;
        }

        foreach (['status', 'memberType', 'thirdparty', 'company'] as $key) {
            $this->assertArrayHasKey($key, $flags, $key . ' disappeared from the catalog');
            $this->assertFalse(
                (bool) $flags[$key]['filterable'],
                $key . ' is now derived as filterable: the catalog learned to read the mapper maps, '
                    . 'and the frontend overrides compensating for this can be dropped'
            );
            $this->assertFalse((bool) $flags[$key]['sortable']);
        }

        // The SQL side, in the same test, so the two statements cannot drift:
        // flagged false in the catalog, yet fully served by the facade.
        $params = $this->probe->parse(['filter' => ['thirdparty' => '5'], 'sort' => 'memberType', 'order' => 'asc']);
        list($where) = $this->probe->filters($params, $this->mapper, 'a');
        $this->assertStringContainsString('a.fk_soc = 5', $where);
        $this->assertSame(
            ' ORDER BY a.fk_adherent_type ASC',
            $this->probe->sort($params, $this->mapper, 'a', 'a.rowid DESC')
        );
    }

    /**
     * Free-text search runs on the columns Dolibarr itself searches
     * (adherents/list.php l.130-145), minus the two note blobs.
     */
    public function testSearchFieldsAreTheCoreSearchedColumns(): void
    {
        $this->assertSame(
            [
                'ref', 'login', 'lastname', 'firstname', 'societe', 'email',
                'address', 'zip', 'town', 'phone', 'phone_perso', 'phone_mobile',
            ],
            $this->mapper->getSearchFields()
        );

        $columns = $this->tableColumns('adherent');
        foreach ($this->mapper->getSearchFields() as $field) {
            $this->assertContains($field, $columns);
        }
    }

    /**
     * Falsification of the previous test: without the override, the dmBase
     * fallback ("every varchar of $fields, unless some field declares
     * `searchable`") would have searched through the photo file name, the
     * two-letter nature code and the gender, and would have MISSED the company
     * name. Both premises of that fallback are asserted here, so the override
     * cannot be dismissed as defensive noise.
     */
    public function testTheDerivationWouldHaveSearchedTechnicalColumns(): void
    {
        $fields = (new \Adherent($this->db))->fields;

        foreach ($fields as $name => $def) {
            $this->assertArrayNotHasKey(
                'searchable',
                $def,
                "Adherent::\$fields['" . $name . "'] now declares 'searchable': dmBase would honour it strictly"
            );
        }

        foreach (['photo', 'morphy', 'gender'] as $technical) {
            $this->assertArrayHasKey($technical, $fields);
            $this->assertStringStartsWith('varchar', (string) $fields[$technical]['type']);
            $this->assertNotContains(
                $technical,
                $this->mapper->getSearchFields(),
                $technical . ' is a technical column, searching free text through it matches unrelated members'
            );
        }

        // The company name is what the derivation missed: its doliside is the
        // property $company while the column is `societe`.
        $this->assertArrayNotHasKey('company', $fields);
        $this->assertContains('societe', $this->mapper->getSearchFields());
    }

    /* -----------------------------------------------------------------
     * Defect 3 -- the bank line of a subscription
     * --------------------------------------------------------------- */

    /**
     * The asymmetry, asserted against the real database rather than against
     * the source: the value sent at creation is dropped, the same value sent
     * afterwards is persisted. Kept writable for the second path.
     */
    public function testSubscriptionCreateIgnoresTheBankLine(): void
    {
        $this->assertContains(
            'fk_bank',
            $this->writableFields(dmSubscription::class, $this->subscriptionMapper),
            'fk_bank must stay writable: update() is the only path that can link a bank line'
        );

        $member = $this->createMember();

        $subscription = new \Subscription($this->db);
        $subscription->fk_adherent = (int) $member->id;
        $subscription->fk_type = (int) $member->typeid;
        $subscription->dateh = mktime(0, 0, 0, 1, 1, 2026);
        $subscription->datef = mktime(0, 0, 0, 12, 31, 2026);
        $subscription->amount = 25.0;
        $subscription->note_public = 'Bank line asymmetry';
        // Set BEFORE create(): this is the value the INSERT does not carry.
        $subscription->fk_bank = 4242;

        $subId = $subscription->create($this->testUser);
        $this->assertGreaterThan(0, $subId, 'failed to create subscription: ' . $subscription->error);

        $this->assertNull(
            $this->rawColumn('subscription', 'fk_bank', $subId),
            'Subscription::create() now inserts fk_bank: the mapper comment and this test must be revisited'
        );

        // Same value through update(): persisted.
        $subscription->fk_bank = 4242;
        $this->assertGreaterThan(0, $subscription->update($this->testUser), 'update failed: ' . $subscription->error);
        $this->assertSame(4242, (int) $this->rawColumn('subscription', 'fk_bank', $subId));
    }

    /* -----------------------------------------------------------------
     * Tenant isolation of the subscriptions
     * --------------------------------------------------------------- */

    /**
     * llx_subscription has no entity column, so this hook carries the whole
     * isolation of the type: the facade refuses every access when it is
     * missing or empty (ObjectFacadeTrait::isolationDenies l.372-381).
     * The predicate must scope through llx_adherent, which IS entity-scoped.
     */
    public function testSubscriptionIsolationHookScopesThroughTheMember(): void
    {
        $this->assertTrue(
            method_exists($this->subscriptionMapper, 'isolationWhereSql'),
            'removing this hook makes every subscription route answer 403'
        );

        $fragment = $this->subscriptionMapper->isolationWhereSql('sub', $this->db);

        $this->assertStringStartsWith(' AND ', $fragment);
        $this->assertStringContainsString('EXISTS', $fragment);
        $this->assertStringContainsString(MAIN_DB_PREFIX . 'adherent', $fragment);
        $this->assertStringContainsString('isol_a.rowid = sub.fk_adherent', $fragment);
        $this->assertStringContainsString('isol_a.entity IN (' . getEntity('adherent') . ')', $fragment);
    }

    /**
     * The registry must keep declaring the table as entity-less, otherwise the
     * generic scoping would look for a column that does not exist.
     */
    public function testSubscriptionTableHasNoEntityColumnAndTheRegistrySaysSo(): void
    {
        $this->assertNotContains('entity', $this->tableColumns('subscription'));

        $builtins = ObjectRegistry::builtins();
        $this->assertArrayHasKey('subscription', $builtins);
        $this->assertFalse($builtins['subscription']['has_entity']);
    }

    /**
     * End to end on the real database: the fragment really selects the
     * subscription of a member of the current entity, and really rejects the
     * one whose member sits in another entity.
     */
    public function testSubscriptionIsolationFragmentFiltersOnRealRows(): void
    {
        global $conf;

        $mine = $this->createMember();
        $mineSub = $this->createSubscription($mine);

        $foreign = $this->createMember();
        $this->db->query(
            'UPDATE ' . MAIN_DB_PREFIX . 'adherent SET entity = ' . ((int) $conf->entity + 77)
            . ' WHERE rowid = ' . ((int) $foreign->id)
        );
        $foreignSub = $this->createSubscription($foreign);

        $fragment = $this->subscriptionMapper->isolationWhereSql('sub', $this->db);

        $this->assertTrue($this->subscriptionVisible($mineSub, $fragment), 'own subscription must stay visible');
        $this->assertFalse(
            $this->subscriptionVisible($foreignSub, $fragment),
            'a subscription whose member belongs to another entity must not be reachable'
        );
    }

    /* -----------------------------------------------------------------
     * Round trip
     * --------------------------------------------------------------- */

    /**
     * End-to-end read: the status survives the fetch() rename, the negative
     * value is exported as such, and neither removed key reappears.
     */
    public function testExportCarriesStatusAndNoGhostKey(): void
    {
        $member = $this->createMember(['lastname' => 'Roundtrip', 'firstname' => 'Member']);

        $fresh = new \Adherent($this->db);
        $this->assertGreaterThan(0, $fresh->fetch($member->id));

        $payload = $this->mapper->exportMappedData($fresh);

        $this->assertSame((int) $member->id, (int) $payload->id);
        $this->assertSame('Roundtrip', $payload->lastname);
        $this->assertSame((int) $member->typeid, (int) $payload->member_type);
        // A freshly created member is a DRAFT, and a draft is -1.
        $this->assertObjectHasProperty('status', $payload);
        $this->assertSame(\Adherent::STATUS_DRAFT, (int) $payload->status);

        foreach (['phone_pro', 'fax'] as $ghost) {
            $this->assertObjectNotHasProperty($ghost, $payload);
        }
    }

    /* -----------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------- */

    /**
     * @return dmMember|dmSubscription
     */
    private function mapperFor(string $key)
    {
        return $key === 'member' ? $this->mapper : $this->subscriptionMapper;
    }

    /**
     * Column names of a table as the harness really loaded them.
     *
     * @return string[]
     */
    private function tableColumns(string $table): array
    {
        $out = [];
        $resql = $this->db->query('PRAGMA table_info(' . MAIN_DB_PREFIX . $table . ')');
        if (!$resql) {
            $this->fail('could not introspect ' . MAIN_DB_PREFIX . $table . ': ' . $this->db->lasterror());
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $out[] = (string) $obj->name;
        }
        $this->db->free($resql);

        return $out;
    }

    /**
     * Read one column of one row straight from SQL, bypassing the fetch()
     * renames: the point of the bank-line test is what the table holds.
     *
     * @return mixed
     */
    private function rawColumn(string $table, string $column, int $rowid)
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . $table
            . ' WHERE rowid = ' . ((int) $rowid)
        );
        if (!$resql) {
            $this->fail('SQL error reading ' . $table . '.' . $column . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? null : $obj->val;
    }

    /**
     * Run the isolation predicate as the facade row probe does.
     */
    private function subscriptionVisible(int $subId, string $fragment): bool
    {
        $sql = 'SELECT sub.rowid FROM ' . MAIN_DB_PREFIX . 'subscription as sub';
        $sql .= ' WHERE sub.rowid = ' . ((int) $subId) . $fragment;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->fail('isolation probe SQL failed: ' . $this->db->lasterror());
        }
        $found = ((int) $this->db->num_rows($resql)) > 0;
        $this->db->free($resql);

        return $found;
    }

    /**
     * The doliside keys of the writable allowlist are PHP properties: three of
     * them are renamed on the way to SQL. Resolve them so the "every writable
     * field is a real column" guard checks the column, not the property.
     */
    private function columnForDoliside(string $doliside): string
    {
        $renames = [
            'civility_id' => 'civility',
            'company'     => 'societe',
            'country_id'  => 'country',
            'typeid'      => 'fk_adherent_type',
            'socid'       => 'fk_soc',
        ];

        return $renames[$doliside] ?? $doliside;
    }

    /**
     * @return array<string,string> doliside => appside
     */
    private function publishedFields(string $class, $mapper): array
    {
        $prop = (new ReflectionClass($class))->getProperty('listOfPublishedFields');
        $prop->setAccessible(true);

        return (array) $prop->getValue($mapper);
    }

    /**
     * @return string[]
     */
    private function writableFields(string $class, $mapper): array
    {
        $prop = (new ReflectionClass($class))->getProperty('writableFields');
        $prop->setAccessible(true);

        return (array) $prop->getValue($mapper);
    }

    /**
     * Local copy of dmBase::snakeToCamel (private static there), so the test
     * checks the maps against the conversion rule and not against itself.
     */
    private function camelize(string $key): string
    {
        if (strpos($key, '_') === false) {
            return $key;
        }
        $parts = explode('_', $key);
        $first = array_shift($parts);

        return $first . implode('', array_map('ucfirst', $parts));
    }

    /**
     * llx_adherent_type is EMPTY on a fresh install: no member can be created
     * without seeding a type first, and fetch() joins the type table
     * unconditionally.
     *
     * AdherentType::create() inserts only morphy/libelle/entity and pushes the
     * rest through an immediate update(), so every scalar update() reads has to
     * be pre-populated or it interpolates empty strings into number columns.
     */
    private function createMemberType(): \AdherentType
    {
        $type = new \AdherentType($this->db);
        $type->label = 'DmMemberMapperTest type ' . uniqid();
        $type->morphy = 'phy';
        $type->status = 1;
        $type->subscription = 1;
        $type->amount = 10.0;
        $type->caneditamount = 0;
        $type->vote = 0;
        $type->duration_value = '1';
        $type->duration_unit = 'y';
        $type->mail_valid = '';
        $type->note_public = '';

        $id = $type->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create AdherentType: ' . $type->error);

        return $type;
    }

    private function createMember(array $overrides = []): \Adherent
    {
        $type = $this->createMemberType();

        $member = new \Adherent($this->db);
        $member->login = $overrides['login'] ?? ('dmmember_' . uniqid());
        $member->lastname = $overrides['lastname'] ?? 'Doe';
        $member->firstname = $overrides['firstname'] ?? 'John';
        $member->email = $overrides['email'] ?? ('dmmember_' . uniqid() . '@example.com');
        $member->morphy = 'phy';
        $member->typeid = (int) $type->id;
        $member->public = 0;

        $id = $member->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create Adherent: ' . $member->error);

        return $member;
    }

    private function createSubscription(\Adherent $member): int
    {
        $subscription = new \Subscription($this->db);
        $subscription->fk_adherent = (int) $member->id;
        $subscription->fk_type = (int) $member->typeid;
        $subscription->dateh = mktime(0, 0, 0, 1, 1, 2026);
        $subscription->datef = mktime(0, 0, 0, 12, 31, 2026);
        $subscription->amount = 30.0;
        $subscription->note_public = 'isolation fixture';

        $id = $subscription->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create subscription: ' . $subscription->error);

        return (int) $id;
    }
}
