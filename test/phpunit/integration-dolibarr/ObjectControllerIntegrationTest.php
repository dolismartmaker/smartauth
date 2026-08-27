<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';

use SmartAuth\Api\ObjectController;

/**
 * Integration tests for the generic objects/{type} REST facade against a real
 * Dolibarr + SQLite backend. Exercises the full CRUD/search surface for the
 * Vague 1 objects (thirdparty, product, contact, category), permission
 * fail-closed behaviour, entity scoping and the mapper write allowlist.
 *
 * Controllers read global $user/$db; DolibarrRealTestCase::setUp() loads the
 * admin user, grants the business rights and enables the modules the facade
 * gates on, so a plain method call reproduces the production request path.
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class ObjectControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    /** @var array<int,array{0:string,1:int}> Business fixtures to purge in tearDown. */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
        $this->created = [];
    }

    protected function tearDown(): void
    {
        // Business tables (societe/product/socpeople/categorie) survive
        // cleanSmartAuthTables(), so purge what each test created.
        foreach (array_reverse($this->created) as $row) {
            list($table, $id) = $row;
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table . " WHERE rowid = " . (int) $id);
        }
        parent::tearDown();
    }

    /** Track a business fixture for teardown cleanup. */
    private function track(string $table, int $id): void
    {
        $this->created[] = [$table, $id];
    }

    /** Find an exported item (stdClass) by its API id inside a paginated list. */
    private function findById(array $items, int $id)
    {
        foreach ($items as $it) {
            if ((int) ($it->id ?? 0) === $id) {
                return $it;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ meta

    public function testColumnsReturnsCatalogForEachType(): void
    {
        foreach (['thirdparty', 'product', 'contact', 'category'] as $type) {
            list($body, $code) = $this->controller->columns(['objtype' => $type]);
            $this->assertSame(200, $code, "columns($type) should be 200");
            $this->assertIsArray($body);
            $this->assertNotEmpty($body, "catalog for $type is empty");
            $entry = $body[0];
            $this->assertArrayHasKey('key', $entry);
            $this->assertArrayHasKey('type', $entry);
            $this->assertArrayHasKey('doliside', $entry);
            $this->assertArrayHasKey('filterable', $entry);
        }
    }

    public function testDescribeReturnsFieldSchema(): void
    {
        list($body, $code) = $this->controller->describe(['objtype' => 'thirdparty']);
        $this->assertSame(200, $code);
        $this->assertIsObject($body);
        $this->assertTrue(property_exists($body, 'name'), 'objectDesc should describe the name field');
    }

    public function testUnsupportedTypeReturns400(): void
    {
        list($body, $code) = $this->controller->index(['objtype' => 'not_a_real_type']);
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('error', $body);
    }

    public function testMissingTypeReturns400(): void
    {
        list($body, $code) = $this->controller->index([]);
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('error', $body);
    }

    // ------------------------------------------------------------------ list

    public function testIndexReturnsPaginatedEnvelopeContainingCreatedRow(): void
    {
        $soc = $this->createTestSociete(['name' => 'ListCo ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->index(['objtype' => 'thirdparty', 'limit' => 100]);
        $this->assertSame(200, $code);
        $this->assertArrayHasKey('items', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('page', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertGreaterThanOrEqual(1, $body['total']);

        $found = $this->findById($body['items'], (int) $soc->id);
        $this->assertNotNull($found, 'created thirdparty not present in list');
        $this->assertSame($soc->name, $found->name);
    }

    public function testCountReturnsTotal(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->count(['objtype' => 'thirdparty']);
        $this->assertSame(200, $code);
        $this->assertArrayHasKey('total', $body);
        $this->assertGreaterThanOrEqual(1, $body['total']);
    }

    public function testIndexSearchFiltersRows(): void
    {
        // Search hits the mapper's real-column search whitelist (getSearchFields).
        // The company name maps to the PHP property 'name' (SQL column 'nom'),
        // which is intentionally NOT in that whitelist, so we search a real
        // column: email.
        $needle = 'needle' . uniqid() . '@search.test';
        $soc = $this->createTestSociete(['email' => $needle]);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->index(['objtype' => 'thirdparty', 'search' => $needle]);
        $this->assertSame(200, $code);
        $this->assertSame(1, $body['total'], 'search on a unique email should match exactly one row');
        $this->assertSame((int) $soc->id, (int) $body['items'][0]->id);
    }

    // ------------------------------------------------------- `in` filter

    /**
     * `client` encodes a CATEGORY (1 customer, 2 prospect, 3 both), so
     * "every company a salesperson can quote" is client IN (1,2,3). With the
     * equality-only `select` kind a consumer had to issue three requests and
     * merge them client-side, or drop prospects; dmThirdparty declares the
     * column as `in` for that reason.
     */
    public function testIndexInFilterMatchesASetOfValues(): void
    {
        $tag = 'inset' . uniqid();
        $customer = $this->createTestSociete(['name' => 'Customer ' . $tag, 'email' => 'c-' . $tag . '@in.test', 'client' => 1]);
        $prospect = $this->createTestSociete(['name' => 'Prospect ' . $tag, 'email' => 'p-' . $tag . '@in.test', 'client' => 2]);
        $supplier = $this->createTestSociete(['name' => 'Supplier ' . $tag, 'email' => 's-' . $tag . '@in.test', 'client' => 0]);
        foreach ([$customer, $prospect, $supplier] as $soc) {
            $this->track('societe', (int) $soc->id);
        }

        list($body, $code) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@in.test',
            'filter' => ['is_customer' => '1,2,3'],
            'limit' => 100,
        ]);

        $this->assertSame(200, $code);
        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);

        $this->assertContains((int) $customer->id, $ids, 'a customer is in the set');
        $this->assertContains((int) $prospect->id, $ids, 'a prospect is in the set too');
        $this->assertNotContains((int) $supplier->id, $ids, 'a non-customer stays out');
    }

    /**
     * Backward compatibility: a single value must behave exactly like the
     * equality filter it replaces.
     */
    public function testIndexInFilterWithASingleValueBehavesLikeEquality(): void
    {
        $tag = 'inone' . uniqid();
        $customer = $this->createTestSociete(['name' => 'Customer ' . $tag, 'email' => 'c-' . $tag . '@in.test', 'client' => 1]);
        $prospect = $this->createTestSociete(['name' => 'Prospect ' . $tag, 'email' => 'p-' . $tag . '@in.test', 'client' => 2]);
        foreach ([$customer, $prospect] as $soc) {
            $this->track('societe', (int) $soc->id);
        }

        list($body, $code) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@in.test',
            'filter' => ['is_customer' => '1'],
            'limit' => 100,
        ]);

        $this->assertSame(200, $code);
        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);

        $this->assertContains((int) $customer->id, $ids);
        $this->assertNotContains((int) $prospect->id, $ids);
    }

    /**
     * An empty set must not emit `IN ()`, which is a syntax error: the query
     * has to keep working, simply without that condition.
     */
    public function testIndexInFilterWithAnEmptySetStillReturnsAList(): void
    {
        $tag = 'inempty' . uniqid();
        $soc = $this->createTestSociete(['name' => 'Any ' . $tag, 'email' => 'a-' . $tag . '@in.test', 'client' => 1]);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@in.test',
            'filter' => ['is_customer' => ''],
            'limit' => 100,
        ]);

        $this->assertSame(200, $code, 'an empty set must not break the SQL');
        $this->assertContains((int) $soc->id, array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']));
    }

    // -------------------------------------------------- computed sorting

    /**
     * `last_activity` orders by the most recent linked agenda event, falling
     * back to the company's own modification date. It is a correlated
     * subquery, so this test also proves the list query keeps its shape: the
     * COUNT must stay exact and the other filters must still compose.
     */
    public function testIndexSortsByComputedLastActivity(): void
    {
        global $db, $user;

        $tag = 'lastact' . uniqid();
        $stale = $this->createTestSociete(['name' => 'Stale ' . $tag, 'email' => 'stale-' . $tag . '@sort.test', 'client' => 1]);
        $active = $this->createTestSociete(['name' => 'Active ' . $tag, 'email' => 'active-' . $tag . '@sort.test', 'client' => 1]);
        foreach ([$stale, $active] as $soc) {
            $this->track('societe', (int) $soc->id);
        }

        // One agenda event, far in the future, on the "active" company only.
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
        $event = new \ActionComm($db);
        $event->type_code = 'AC_OTH';
        $event->label = 'Rendez-vous ' . $tag;
        $event->datep = dol_now() + 86400 * 30;
        $event->datef = $event->datep + 3600;
        $event->socid = (int) $active->id;
        $event->userownerid = (int) $user->id;
        $eventId = $event->create($user);
        $this->assertGreaterThan(0, $eventId, 'agenda event creation must succeed: ' . $event->error);
        $this->track('actioncomm', (int) $eventId);

        list($body, $code) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@sort.test',
            'sort' => 'last_activity',
            'order' => 'desc',
            'limit' => 100,
        ]);

        $this->assertSame(200, $code);
        $this->assertSame(2, (int) $body['total'], 'the correlated subquery must not duplicate rows in the COUNT');

        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);
        $this->assertSame(
            [(int) $active->id, (int) $stale->id],
            $ids,
            'the company with a recent event must come first'
        );

        // Reversing the order must reverse the list: proof the expression is
        // really what drives the sort.
        list($body, ) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@sort.test',
            'sort' => 'last_activity',
            'order' => 'asc',
            'limit' => 100,
        ]);
        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);
        $this->assertSame([(int) $stale->id, (int) $active->id], $ids);
    }

    /**
     * The computed sort must compose with the `in` filter, since the real
     * consumer needs both at once ("recently active customers AND prospects").
     */
    public function testComputedSortComposesWithTheInFilter(): void
    {
        $tag = 'compose' . uniqid();
        $customer = $this->createTestSociete(['name' => 'C ' . $tag, 'email' => 'c-' . $tag . '@sort.test', 'client' => 1]);
        $prospect = $this->createTestSociete(['name' => 'P ' . $tag, 'email' => 'p-' . $tag . '@sort.test', 'client' => 2]);
        $neither = $this->createTestSociete(['name' => 'N ' . $tag, 'email' => 'n-' . $tag . '@sort.test', 'client' => 0]);
        foreach ([$customer, $prospect, $neither] as $soc) {
            $this->track('societe', (int) $soc->id);
        }

        list($body, $code) = $this->controller->index([
            'objtype' => 'thirdparty',
            'search' => $tag . '@sort.test',
            'filter' => ['is_customer' => '1,2,3'],
            'sort' => 'last_activity',
            'order' => 'desc',
            'limit' => 100,
        ]);

        $this->assertSame(200, $code);
        $this->assertSame(2, (int) $body['total'], 'the filter still applies under the computed sort');

        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);
        $this->assertNotContains((int) $neither->id, $ids);
    }

    // ------------------------------------------- contacts of a company

    /**
     * "The contacts of company X" is the single most common query on
     * objects/contact, and it was NOT expressible: the mapper addresses the
     * company through the PHP property `socid` while the SQL column is
     * `fk_soc`, so the catalog never marked it filterable. dmContact now
     * declares it explicitly.
     */
    public function testIndexFiltersContactsByThirdpartyAndStatus(): void
    {
        global $db, $user;

        require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

        $tag = 'ctc' . uniqid();
        $socA = $this->createTestSociete(['name' => 'A ' . $tag]);
        $socB = $this->createTestSociete(['name' => 'B ' . $tag]);
        $this->track('societe', (int) $socA->id);
        $this->track('societe', (int) $socB->id);

        $make = function ($soc, $last, $status) use ($db, $user, $tag) {
            $c = new \Contact($db);
            $c->lastname = $last;
            $c->firstname = 'Test';
            $c->socid = (int) $soc->id;
            $c->email = strtolower($last) . '-' . $tag . '@ctc.test';
            $c->statut = $status;
            $id = $c->create($user);
            $this->assertGreaterThan(0, $id, 'contact creation must succeed: ' . $c->error);
            $this->track('socpeople', (int) $id);

            return (int) $id;
        };

        $activeA = $make($socA, 'Zulu', 1);
        $inactiveA = $make($socA, 'Alpha', 0);
        $activeB = $make($socB, 'Bravo', 1);

        list($body, $code) = $this->controller->index([
            'objtype' => 'contact',
            'filter' => ['thirdparty' => (string) $socA->id, 'statut' => '1'],
            'sort' => 'lastname',
            'order' => 'asc',
            'limit' => 100,
        ]);

        $this->assertSame(200, $code);
        $ids = array_map(static function ($item) {
            return (int) $item->id;
        }, $body['items']);

        $this->assertContains($activeA, $ids, 'the active contact of A is listed');
        $this->assertNotContains($inactiveA, $ids, 'an inactive contact is filtered out');
        $this->assertNotContains($activeB, $ids, 'a contact of another company is filtered out');
        $this->assertSame(1, (int) $body['total']);
    }

    // ------------------------------------------------------------------ show

    public function testShowReturnsMappedObject(): void
    {
        $soc = $this->createTestSociete(['name' => 'ShowCo', 'email' => 'show@co.test']);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->show(['objtype' => 'thirdparty', 'id' => (int) $soc->id]);
        $this->assertSame(200, $code);
        $this->assertSame((int) $soc->id, (int) $body->id);
        $this->assertSame('ShowCo', $body->name);
        $this->assertSame('show@co.test', $body->email);
    }

    public function testShowUnknownIdReturns404(): void
    {
        list($body, $code) = $this->controller->show(['objtype' => 'thirdparty', 'id' => 99999999]);
        $this->assertSame(404, $code);
    }

    public function testShowMissingIdReturns400(): void
    {
        list($body, $code) = $this->controller->show(['objtype' => 'thirdparty']);
        $this->assertSame(400, $code);
    }

    // ---------------------------------------------------------------- create

    public function testCreateThirdpartyPersistsAndRoundTripsSiren(): void
    {
        $payload = [
            'objtype'        => 'thirdparty',
            'name'        => 'CreatedCo ' . uniqid(),
            'email'       => 'created@co.test',
            'is_customer' => 1,
            'siren'       => '123456789',
        ];
        list($body, $code) = $this->controller->create($payload);
        $this->assertSame(201, $code, 'create should return 201 (body: ' . json_encode($body) . ')');
        $this->assertTrue(property_exists($body, 'id'), 'created object should expose an id');
        $id = (int) $body->id;
        $this->track('societe', $id);

        // The siren round-trips only because the mapper now addresses idprof1
        // (the property Societe fetch/update actually use).
        $this->assertSame('123456789', (string) $body->siren);
        $this->assertSame($payload['name'], $body->name);

        $this->assertDatabaseHas('societe', ['rowid' => $id, 'nom' => $payload['name']]);
        $this->assertDatabaseHas('societe', ['rowid' => $id, 'siren' => '123456789']);
    }

    public function testCreateRejectsUnknownFieldWith400(): void
    {
        list($body, $code) = $this->controller->create([
            'objtype'          => 'thirdparty',
            'name'          => 'Bogus ' . uniqid(),
            'not_a_field'   => 'x',
        ]);
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('not_a_field', $body['errors']);
    }

    public function testExtrafieldWriteIsRejectedWith400(): void
    {
        // Extrafields are read-only in v1: an options_* key is not in any
        // mapper's $writableFields, so importMappedData rejects it -> 400.
        list($body, $code) = $this->controller->create([
            'objtype'         => 'thirdparty',
            'name'            => 'ExtraCo ' . uniqid(),
            'options_myfield' => 'some value',
        ]);
        $this->assertSame(400, $code, 'extrafield write should be rejected: ' . json_encode($body));
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('options_myfield', $body['errors']);
    }

    // ---------------------------------------------------------------- update

    public function testUpdateThirdpartyPersists(): void
    {
        $soc = $this->createTestSociete(['email' => 'before@co.test']);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->update([
            'objtype'  => 'thirdparty',
            'id'    => (int) $soc->id,
            'email' => 'after@co.test',
        ]);
        $this->assertSame(200, $code, 'update body: ' . json_encode($body));
        $this->assertSame('after@co.test', $body->email);
        $this->assertDatabaseHas('societe', ['rowid' => (int) $soc->id, 'email' => 'after@co.test']);
    }

    public function testUpdateRejectsUnknownFieldWith400(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->update([
            'objtype'        => 'thirdparty',
            'id'          => (int) $soc->id,
            'not_a_field' => 'x',
        ]);
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('errors', $body);
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteThirdparty(): void
    {
        $soc = $this->createTestSociete();
        $id = (int) $soc->id;

        list($body, $code) = $this->controller->destroy(['objtype' => 'thirdparty', 'id' => $id]);
        $this->assertSame(200, $code, 'destroy body: ' . json_encode($body));
        $this->assertDatabaseMissing('societe', ['rowid' => $id]);
    }

    public function testDeleteBulkThirdparty(): void
    {
        $a = $this->createTestSociete();
        $b = $this->createTestSociete();

        list($body, $code) = $this->controller->deleteBulk([
            'objtype' => 'thirdparty',
            'ids'  => [(int) $a->id, (int) $b->id],
        ]);
        $this->assertSame(200, $code);
        $this->assertContains((int) $a->id, $body['success']);
        $this->assertContains((int) $b->id, $body['success']);
        $this->assertDatabaseMissing('societe', ['rowid' => (int) $a->id]);
        $this->assertDatabaseMissing('societe', ['rowid' => (int) $b->id]);
    }

    // ------------------------------------------- cross-type CRUD (CrudInvoker)

    public function testCreateAndDeleteProduct(): void
    {
        $ref = 'FAC-PROD-' . uniqid();
        list($body, $code) = $this->controller->create([
            'objtype'  => 'product',
            'ref'   => $ref,
            'label' => 'Facade Product',
        ]);
        $this->assertSame(201, $code, 'product create body: ' . json_encode($body));
        $id = (int) $body->id;
        $this->assertSame($ref, $body->ref);
        $this->assertDatabaseHas('product', ['rowid' => $id, 'ref' => $ref]);

        // Product::delete(User $user, ...) -- user-first signature via CrudInvoker.
        list($delBody, $delCode) = $this->controller->destroy(['objtype' => 'product', 'id' => $id]);
        $this->assertSame(200, $delCode, 'product destroy body: ' . json_encode($delBody));
        $this->assertDatabaseMissing('product', ['rowid' => $id]);
    }

    public function testCreateContactLinksThirdpartyAndDeletes(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->create([
            'objtype'        => 'contact',
            'lastname'    => 'Facade',
            'firstname'   => 'Contact',
            'email'       => 'facade.contact@test.test',
            'thirdparty'  => (int) $soc->id,
        ]);
        $this->assertSame(201, $code, 'contact create body: ' . json_encode($body));
        $id = (int) $body->id;

        // The link persists only because dmContact now addresses $socid (the
        // property Contact::create reads), stored in the fk_soc SQL column.
        $this->assertSame((int) $soc->id, (int) $body->thirdparty);
        $this->assertDatabaseHas('socpeople', ['rowid' => $id, 'fk_soc' => (int) $soc->id]);

        // Contact::delete($notrigger) -- no-user signature via CrudInvoker.
        list($delBody, $delCode) = $this->controller->destroy(['objtype' => 'contact', 'id' => $id]);
        $this->assertSame(200, $delCode, 'contact destroy body: ' . json_encode($delBody));
        $this->assertDatabaseMissing('socpeople', ['rowid' => $id]);
    }

    public function testCreateAndDeleteCategory(): void
    {
        // The category's own 'type' field is settable because the route param is
        // named {objtype}, not {type} -- proving the collision fix.
        list($body, $code) = $this->controller->create([
            'objtype' => 'category',
            'label'   => 'FacadeCat ' . uniqid(),
            'type'    => 0, // 0 = product category
        ]);
        $this->assertSame(201, $code, 'category create body: ' . json_encode($body));
        $id = (int) $body->id;
        $this->assertDatabaseHas('categorie', ['rowid' => $id]);

        // Categorie::delete($user, ...) -- user-first signature via CrudInvoker.
        list($delBody, $delCode) = $this->controller->destroy(['objtype' => 'category', 'id' => $id]);
        $this->assertSame(200, $delCode, 'category destroy body: ' . json_encode($delBody));
        $this->assertDatabaseMissing('categorie', ['rowid' => $id]);
    }

    // ----------------------------------------------------------- permissions

    public function testReadFailClosedWhenRightMissing(): void
    {
        global $user;
        $saved = $user->rights->societe->lire;
        $user->rights->societe->lire = 0;
        try {
            list($body, $code) = $this->controller->index(['objtype' => 'thirdparty']);
            $this->assertSame(403, $code);
            $this->assertArrayHasKey('error', $body);
        } finally {
            $user->rights->societe->lire = $saved;
        }
    }

    public function testCreateFailClosedWhenRightMissing(): void
    {
        global $user;
        $saved = $user->rights->societe->creer;
        $user->rights->societe->creer = 0;
        try {
            list($body, $code) = $this->controller->create(['objtype' => 'thirdparty', 'name' => 'NoRight']);
            $this->assertSame(403, $code);
        } finally {
            $user->rights->societe->creer = $saved;
        }
    }

    public function testDeleteFailClosedWhenRightMissing(): void
    {
        global $user;
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        $saved = $user->rights->societe->supprimer;
        $user->rights->societe->supprimer = 0;
        try {
            list($body, $code) = $this->controller->destroy(['objtype' => 'thirdparty', 'id' => (int) $soc->id]);
            $this->assertSame(403, $code);
        } finally {
            $user->rights->societe->supprimer = $saved;
        }
    }

    // --------------------------------------------------------- entity scoping

    public function testCrossEntityObjectIsNotAccessible(): void
    {
        $soc = $this->createTestSociete();
        $id = (int) $soc->id;
        $this->track('societe', $id);

        // Move the row to another entity the current user cannot see.
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET entity = 2 WHERE rowid = " . $id);

        list($body, $code) = $this->controller->show(['objtype' => 'thirdparty', 'id' => $id]);
        // Either the facade's entity guard (403) or a fetch that filters the row
        // out (404) is acceptable -- the security property is "not returned".
        $this->assertContains($code, [403, 404], 'cross-entity object must be refused');
    }
}
