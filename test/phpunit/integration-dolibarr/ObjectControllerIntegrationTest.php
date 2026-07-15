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
