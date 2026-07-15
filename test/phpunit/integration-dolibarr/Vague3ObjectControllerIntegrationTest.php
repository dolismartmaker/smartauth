<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';

use SmartAuth\Api\ObjectController;

/**
 * Integration tests for the Vague 3 objects of the generic objects/{type} REST
 * facade: warehouse, member, contract, ticket, intervention (CRUD) plus
 * expensereport (read path -- create needs an author outside the mapper).
 *
 * Also exercises the property-vs-column fixes on the Vague 3 mappers
 * (socid/fk_project instead of fk_soc/fk_projet on contract/intervention/
 * warehouse/member).
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class Vague3ObjectControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    /** @var array<int,array{0:string,1:int}> */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
        $this->created = [];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            list($table, $id) = $row;
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table . " WHERE rowid = " . (int) $id);
        }
        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->created[] = [$table, $id];
    }

    // ------------------------------------------------------------ registration

    public function testColumnsReturnCatalogForEachVague3Type(): void
    {
        foreach (['warehouse', 'member', 'expensereport', 'contract', 'ticket', 'intervention'] as $type) {
            list($body, $code) = $this->controller->columns(['objtype' => $type]);
            $this->assertSame(200, $code, "columns($type) should be 200: " . json_encode($body));
            $this->assertIsArray($body);
            $this->assertNotEmpty($body, "catalog for $type is empty");
        }
    }

    // ------------------------------------------------------------- warehouse

    public function testWarehouseCrud(): void
    {
        $ref = 'WH-' . uniqid();
        list($body, $code) = $this->controller->create([
            'objtype' => 'warehouse', 'ref' => $ref, 'label' => 'Main Warehouse',
        ]);
        $this->assertSame(201, $code, 'warehouse create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('entrepot', $id);
        // Entrepot's display name is 'label' ('ref' is derived from it), so
        // assert the label round-trips rather than the input ref.
        $this->assertSame('Main Warehouse', $body->label);

        list($u, $uc) = $this->controller->update(['objtype' => 'warehouse', 'id' => $id, 'city' => 'Lyon']);
        $this->assertSame(200, $uc, 'warehouse update: ' . json_encode($u));
        $this->assertSame('Lyon', $u->city);

        list(, $dc) = $this->controller->destroy(['objtype' => 'warehouse', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('entrepot', ['rowid' => $id]);
    }

    // -------------------------------------------------------------- contract

    public function testContractCrudLinksThirdparty(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        // Contrat::create requires the two sales-representative fields.
        list($body, $code) = $this->controller->create([
            'objtype' => 'contract', 'thirdparty' => (int) $soc->id, 'date_contract' => dol_now(),
            'commercial_signature' => (int) $this->testUser->id,
            'commercial_followup' => (int) $this->testUser->id,
        ]);
        $this->assertSame(201, $code, 'contract create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('contrat', $id);
        // Round-trips only because dmContract now addresses $socid.
        $this->assertSame((int) $soc->id, (int) $body->thirdparty);

        list($u, $uc) = $this->controller->update(['objtype' => 'contract', 'id' => $id, 'public_note' => 'hello']);
        $this->assertSame(200, $uc, 'contract update: ' . json_encode($u));
        $this->assertSame('hello', $u->public_note);

        list(, $dc) = $this->controller->destroy(['objtype' => 'contract', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('contrat', ['rowid' => $id]);
    }

    // ----------------------------------------------------------- intervention

    public function testInterventionCrudLinksThirdparty(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->controller->create([
            'objtype' => 'intervention', 'thirdparty' => (int) $soc->id,
        ]);
        $this->assertSame(201, $code, 'intervention create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('fichinter', $id);
        $this->assertSame((int) $soc->id, (int) $body->thirdparty);

        list($u, $uc) = $this->controller->update(['objtype' => 'intervention', 'id' => $id, 'description' => 'onsite']);
        $this->assertSame(200, $uc, 'intervention update: ' . json_encode($u));
        $this->assertSame('onsite', $u->description);

        list(, $dc) = $this->controller->destroy(['objtype' => 'intervention', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('fichinter', ['rowid' => $id]);
    }

    // -------------------------------------------------------------- member

    public function testMemberCrud(): void
    {
        require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';
        $type = new \AdherentType($this->db);
        $type->label = 'TestType ' . uniqid();
        $type->morphy = 'phy';
        $type->subscription = 0;
        $tid = $type->create($this->testUser);
        $this->assertGreaterThan(0, $tid, 'seed AdherentType failed: ' . $type->error);
        $this->track('adherent_type', (int) $tid);

        $login = 'mbr' . uniqid();
        list($body, $code) = $this->controller->create([
            'objtype' => 'member', 'login' => $login, 'lastname' => 'Doe', 'firstname' => 'Jane',
            'nature' => 'phy', 'member_type' => (int) $tid, 'email' => $login . '@test.test',
        ]);
        $this->assertSame(201, $code, 'member create: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('adherent', $id);
        $this->assertSame('Doe', $body->lastname);

        list($u, $uc) = $this->controller->update(['objtype' => 'member', 'id' => $id, 'city' => 'Paris']);
        $this->assertSame(200, $uc, 'member update: ' . json_encode($u));
        $this->assertSame('Paris', $u->city);

        list(, $dc) = $this->controller->destroy(['objtype' => 'member', 'id' => $id]);
        $this->assertSame(200, $dc);
        $this->assertDatabaseMissing('adherent', ['rowid' => $id]);
    }

    // --------------------------------------------------------------- ticket

    public function testTicketDescribe(): void
    {
        // The llx_ticket table is not part of the in-RAM base schema, so the
        // list path (which queries the table) cannot run here; describe() only
        // boots the mapper. Registration + reachability are covered by the unit
        // ObjectRegistryTest and the HTTP RestApiHttpTest.
        list($desc, $dCode) = $this->controller->describe(['objtype' => 'ticket']);
        $this->assertSame(200, $dCode);
        $this->assertTrue(property_exists($desc, 'subject'), 'ticket describe should expose subject');
    }

    // ---------------------------------------------------------- expensereport

    public function testExpenseReportListEnvelope(): void
    {
        list($body, $code) = $this->controller->index(['objtype' => 'expensereport', 'limit' => 10]);
        $this->assertSame(200, $code, 'expensereport list: ' . json_encode($body));
        $this->assertArrayHasKey('items', $body);
        $this->assertArrayHasKey('total', $body);
    }
}
