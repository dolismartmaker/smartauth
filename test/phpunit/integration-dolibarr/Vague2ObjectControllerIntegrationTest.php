<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';

use SmartAuth\Api\ObjectController;

/**
 * Integration tests for the Vague 2 objects of the generic objects/{type} REST
 * facade: order, invoice, proposal, project, task (full CRUD) plus agenda_event
 * and user (read/update/delete; create needs fields outside the generic mapper
 * -- ActionComm requires type_code + userassigned, User requires login).
 *
 * Exercises the property-vs-column fixes applied to the document/project/task
 * mappers (socid/fk_project instead of the SQL columns fk_soc/fk_projet) and the
 * non-rowid primary key handling for agenda_event (llx_actioncomm PK = 'id').
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class Vague2ObjectControllerIntegrationTest extends DolibarrRealTestCase
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

    private function findById(array $items, int $id)
    {
        foreach ($items as $it) {
            if ((int) ($it->id ?? 0) === $id) {
                return $it;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ registration

    public function testColumnsReturnCatalogForEachVague2Type(): void
    {
        foreach (['order', 'invoice', 'proposal', 'project', 'task', 'agenda_event', 'user'] as $type) {
            list($body, $code) = $this->controller->columns(['objtype' => $type]);
            $this->assertSame(200, $code, "columns($type) should be 200: " . json_encode($body));
            $this->assertIsArray($body);
            $this->assertNotEmpty($body, "catalog for $type is empty");
        }
    }

    // --------------------------------------------------- documents (CRUD)

    /**
     * Create a document via the facade, linking a fresh thirdparty, and return
     * [societeId, documentId, createdBody].
     *
     * @return array{0:int,1:int,2:object}
     */
    private function createDocument(string $type, string $table, array $extra = []): array
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        $payload = array_merge([
            'objtype'     => $type,
            'thirdparty'  => (int) $soc->id,
        ], $extra);
        list($body, $code) = $this->controller->create($payload);
        $this->assertSame(201, $code, "create($type) should be 201: " . json_encode($body));
        $this->assertTrue(property_exists($body, 'id'), "created $type must expose an id");
        $id = (int) $body->id;
        $this->track($table, $id);

        // Thirdparty round-trips only because the mapper now addresses $socid.
        $this->assertSame((int) $soc->id, (int) $body->thirdparty, "$type thirdparty link must persist");

        return [(int) $soc->id, $id, $body];
    }

    public function testOrderCrud(): void
    {
        list(, $id, ) = $this->createDocument('order', 'commande');

        list($showBody, $showCode) = $this->controller->show(['objtype' => 'order', 'id' => $id]);
        $this->assertSame(200, $showCode);
        $this->assertSame($id, (int) $showBody->id);

        list($listBody, $listCode) = $this->controller->index(['objtype' => 'order', 'limit' => 100]);
        $this->assertSame(200, $listCode);
        $this->assertNotNull($this->findById($listBody['items'], $id), 'created order absent from list');

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'order', 'id' => $id, 'public_note' => 'hello order',
        ]);
        $this->assertSame(200, $updCode, 'order update body: ' . json_encode($updBody));
        $this->assertSame('hello order', $updBody->public_note);

        list(, $delCode) = $this->controller->destroy(['objtype' => 'order', 'id' => $id]);
        $this->assertSame(200, $delCode);
        $this->assertDatabaseMissing('commande', ['rowid' => $id]);
    }

    public function testInvoiceCrud(): void
    {
        // Facture::create requires a non-empty date; Commande defaults it to now.
        list(, $id, ) = $this->createDocument('invoice', 'facture', ['date_invoice' => dol_now()]);

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'invoice', 'id' => $id, 'public_note' => 'hello invoice',
        ]);
        $this->assertSame(200, $updCode, 'invoice update body: ' . json_encode($updBody));
        $this->assertSame('hello invoice', $updBody->public_note);

        list(, $delCode) = $this->controller->destroy(['objtype' => 'invoice', 'id' => $id]);
        $this->assertSame(200, $delCode);
        $this->assertDatabaseMissing('facture', ['rowid' => $id]);
    }

    public function testProposalCrud(): void
    {
        list(, $id, ) = $this->createDocument('proposal', 'propal', ['date_proposal' => dol_now()]);

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'proposal', 'id' => $id, 'public_note' => 'hello proposal',
        ]);
        $this->assertSame(200, $updCode, 'proposal update body: ' . json_encode($updBody));
        $this->assertSame('hello proposal', $updBody->public_note);

        list(, $delCode) = $this->controller->destroy(['objtype' => 'proposal', 'id' => $id]);
        $this->assertSame(200, $delCode);
        $this->assertDatabaseMissing('propal', ['rowid' => $id]);
    }

    // ------------------------------------------------------- project + task

    public function testProjectCrud(): int
    {
        $ref = 'PJ-' . uniqid();
        list($body, $code) = $this->controller->create([
            'objtype' => 'project', 'ref' => $ref, 'title' => 'Facade Project',
        ]);
        $this->assertSame(201, $code, 'project create body: ' . json_encode($body));
        $id = (int) $body->id;
        $this->track('projet', $id);
        $this->assertSame($ref, $body->ref);
        $this->assertSame('Facade Project', $body->title);

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'project', 'id' => $id, 'description' => 'updated desc',
        ]);
        $this->assertSame(200, $updCode, 'project update body: ' . json_encode($updBody));
        $this->assertSame('updated desc', $updBody->description);

        return $id;
    }

    public function testTaskCrud(): void
    {
        // project + task ref are SERVER-generated (numbering addon, mirrored by
        // the local controllers) -> not writable; the payload must NOT send it.
        list($pBody, $pCode) = $this->controller->create([
            'objtype' => 'project', 'title' => 'Task Host Project',
        ]);
        $this->assertSame(201, $pCode, 'host project create body: ' . json_encode($pBody));
        $projectId = (int) $pBody->id;
        $this->assertNotEmpty($pBody->ref, 'project ref must be auto-generated');
        $this->track('projet', $projectId);

        list($body, $code) = $this->controller->create([
            'objtype' => 'task', 'label' => 'Facade Task',
            'project' => $projectId,
        ]);
        $this->assertSame(201, $code, 'task create body: ' . json_encode($body));
        $this->assertNotEmpty($body->ref, 'task ref must be auto-generated');
        $id = (int) $body->id;
        $this->track('projet_task', $id);
        // Project link round-trips only because dmTask addresses $fk_project.
        $this->assertSame($projectId, (int) $body->project, 'task project link must persist');

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'task', 'id' => $id, 'progress' => 42,
        ]);
        $this->assertSame(200, $updCode, 'task update body: ' . json_encode($updBody));
        $this->assertSame(42, (int) $updBody->progress);

        list(, $delCode) = $this->controller->destroy(['objtype' => 'task', 'id' => $id]);
        $this->assertSame(200, $delCode);
        $this->assertDatabaseMissing('projet_task', ['rowid' => $id]);
    }

    // ------------------------------------------------------- agenda_event

    /**
     * agenda_event exercises the non-rowid PK path (llx_actioncomm.id). Create
     * needs type_code + userassigned, outside the generic mapper, so we seed the
     * row through the class and drive read/update/delete via the facade.
     */
    public function testAgendaEventReadUpdateDeleteWithNonRowidPk(): void
    {
        global $user;
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        $event = new \ActionComm($this->db);
        $event->type_code = 'AC_OTH';
        $event->label = 'Facade Event';
        $event->datep = dol_now();
        $event->datef = dol_now() + 3600;
        $event->percentage = 0;
        $event->userownerid = (int) $user->id;
        $event->userassigned = [(int) $user->id => ['id' => (int) $user->id]];
        $id = $event->create($user);
        $this->assertGreaterThan(0, $id, 'seed ActionComm failed: ' . $event->error);
        $this->track('actioncomm', (int) $id);

        // show: proves fetch by the 'id' primary key works end to end.
        list($showBody, $showCode) = $this->controller->show(['objtype' => 'agenda_event', 'id' => (int) $id]);
        $this->assertSame(200, $showCode, 'agenda show body: ' . json_encode($showBody));
        $this->assertSame((int) $id, (int) $showBody->id);
        $this->assertSame('Facade Event', $showBody->label);

        // index: proves COUNT(a.id)/SELECT a.id aliasing works.
        list($listBody, $listCode) = $this->controller->index(['objtype' => 'agenda_event', 'limit' => 100]);
        $this->assertSame(200, $listCode, 'agenda list body: ' . json_encode($listBody));
        $this->assertNotNull($this->findById($listBody['items'], (int) $id), 'created event absent from list');

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'agenda_event', 'id' => (int) $id, 'label' => 'Renamed Event',
        ]);
        $this->assertSame(200, $updCode, 'agenda update body: ' . json_encode($updBody));
        $this->assertSame('Renamed Event', $updBody->label);

        list(, $delCode) = $this->controller->destroy(['objtype' => 'agenda_event', 'id' => (int) $id]);
        $this->assertSame(200, $delCode, 'agenda delete failed');
        $this->assertDatabaseMissing('actioncomm', ['id' => (int) $id]);
    }

    // ------------------------------------------------------------------ user

    public function testUserReadAndUpdate(): void
    {
        $u = $this->createTestUser(['lastname' => 'Facade', 'firstname' => 'UserRW']);
        $this->track('user', (int) $u->id);

        list($showBody, $showCode) = $this->controller->show(['objtype' => 'user', 'id' => (int) $u->id]);
        $this->assertSame(200, $showCode, 'user show body: ' . json_encode($showBody));
        $this->assertSame((int) $u->id, (int) $showBody->id);
        $this->assertSame('Facade', $showBody->lastname);

        list($updBody, $updCode) = $this->controller->update([
            'objtype' => 'user', 'id' => (int) $u->id, 'job_title' => 'Tester',
        ]);
        $this->assertSame(200, $updCode, 'user update body: ' . json_encode($updBody));
        $this->assertSame('Tester', $updBody->job_title);
    }

    public function testUserListEnvelope(): void
    {
        list($body, $code) = $this->controller->index(['objtype' => 'user', 'limit' => 100]);
        $this->assertSame(200, $code, 'user list body: ' . json_encode($body));
        $this->assertArrayHasKey('items', $body);
        // The admin (id 1) always exists.
        $this->assertGreaterThanOrEqual(1, $body['total']);
    }

    // ----------------------------------------------------------- permissions

    public function testOrderReadFailClosedWhenRightMissing(): void
    {
        global $user;
        $saved = $user->rights->commande->lire;
        $user->rights->commande->lire = 0;
        try {
            list($body, $code) = $this->controller->index(['objtype' => 'order']);
            $this->assertSame(403, $code, 'order read must fail closed: ' . json_encode($body));
        } finally {
            $user->rights->commande->lire = $saved;
        }
    }
}
