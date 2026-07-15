<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';
require_once __DIR__ . '/../../../api/ObjectLineController.php';
require_once __DIR__ . '/../../../api/ObjectActionController.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;

/**
 * Integration tests for the document workflow-action facade
 * (ObjectActionController + DocumentActionInvoker): validate / setDraft /
 * close* / cancel / setPaid ... across proposal, order and invoice, plus the
 * default-deny action guard and the fail-closed permission gate.
 *
 * @covers \SmartAuth\Api\ObjectActionController
 * @covers \SmartAuth\Api\DocumentActionInvoker
 */
class ObjectActionControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $objects;

    /** @var ObjectLineController */
    private $lines;

    /** @var ObjectActionController */
    private $actions;

    /** @var array<int,array{0:string,1:int}> */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new ObjectController();
        $this->lines = new ObjectLineController();
        $this->actions = new ObjectActionController();
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

    /** Create a draft document with one line so validate() has something to work on. */
    private function createDocumentWithLine(string $type, string $table): int
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        $payload = ['objtype' => $type, 'thirdparty' => (int) $soc->id];
        if ($type === 'invoice') {
            $payload['date_invoice'] = dol_now();
        } elseif ($type === 'proposal') {
            $payload['date_proposal'] = dol_now();
        } elseif ($type === 'order') {
            // The order numbering model (marbre) runs strftime() on $object->date;
            // set an explicit timestamp so validate() gets an int, not a string.
            $payload['date_order'] = dol_now();
        }
        list($body, $code) = $this->objects->create($payload);
        $this->assertSame(201, $code, "create($type) failed: " . json_encode($body));
        $id = (int) $body->id;
        $this->track($table, $id);

        list($lineBody, $lineCode) = $this->lines->store([
            'objtype' => $type, 'id' => $id,
            'description' => 'Line', 'quantity' => 1, 'unit_price_excl_tax' => 100, 'vat_rate' => 0,
        ]);
        $this->assertSame(201, $lineCode, "addLine($type) failed: " . json_encode($lineBody));

        return $id;
    }

    private function act(string $type, int $id, string $action, array $extra = [])
    {
        $payload = array_merge(['objtype' => $type, 'id' => $id, 'action' => $action], $extra);
        return $this->actions->invoke($payload);
    }

    // ------------------------------------------------------------ proposal

    public function testProposalValidateSetDraftCloseSigned(): void
    {
        $id = $this->createDocumentWithLine('proposal', 'propal');

        list($v, $vc) = $this->act('proposal', $id, 'validate');
        $this->assertSame(200, $vc, 'proposal validate failed: ' . json_encode($v));
        $this->assertEquals(1, (int) $v->status, 'proposal must be validated (status 1)');

        list($d, $dc) = $this->act('proposal', $id, 'setdraft');
        $this->assertSame(200, $dc, 'proposal setdraft failed: ' . json_encode($d));
        $this->assertEquals(0, (int) $d->status, 'proposal must be back to draft (status 0)');

        // Re-validate then close as signed (Propal::STATUS_SIGNED = 2).
        $this->act('proposal', $id, 'validate');
        list($s, $sc) = $this->act('proposal', $id, 'closesign', ['note' => 'deal won']);
        $this->assertSame(200, $sc, 'proposal closesign failed: ' . json_encode($s));
        $this->assertEquals(2, (int) $s->status, 'signed proposal must be status 2');
    }

    // ------------------------------------------------------------ order

    public function testOrderValidateThenCancel(): void
    {
        $id = $this->createDocumentWithLine('order', 'commande');

        list($v, $vc) = $this->act('order', $id, 'validate');
        $this->assertSame(200, $vc, 'order validate failed: ' . json_encode($v));
        $this->assertEquals(1, (int) $v->status, 'order must be validated');

        list($c, $cc) = $this->act('order', $id, 'cancel');
        $this->assertSame(200, $cc, 'order cancel failed: ' . json_encode($c));
        // Commande::cancel sets status to STATUS_CANCELED (-1).
        $this->assertEquals(-1, (int) $c->status, 'cancelled order must be status -1');
    }

    // ------------------------------------------------------------ invoice

    public function testInvoiceValidateSetPaidSetUnpaid(): void
    {
        $id = $this->createDocumentWithLine('invoice', 'facture');

        list($v, $vc) = $this->act('invoice', $id, 'validate');
        $this->assertSame(200, $vc, 'invoice validate failed: ' . json_encode($v));
        $this->assertEquals(1, (int) $v->status, 'invoice must be validated');

        list($p, $pc) = $this->act('invoice', $id, 'setpaid');
        $this->assertSame(200, $pc, 'invoice setpaid failed: ' . json_encode($p));
        $this->assertEquals(2, (int) $p->status, 'paid invoice must be status 2');

        list($u, $uc) = $this->act('invoice', $id, 'setunpaid');
        $this->assertSame(200, $uc, 'invoice setunpaid failed: ' . json_encode($u));
        $this->assertEquals(1, (int) $u->status, 'unpaid invoice must be back to validated (status 1)');
    }

    // ----------------------------------------------------------- guards

    public function testUnknownActionRejected(): void
    {
        $id = $this->createDocumentWithLine('proposal', 'propal');
        list($body, $code) = $this->act('proposal', $id, 'frobnicate');
        $this->assertSame(400, $code, 'unknown action must be rejected: ' . json_encode($body));
    }

    public function testActionOnTypeWithoutActionsRejected(): void
    {
        // 'project' declares no 'actions' list.
        $ref = 'PJ-' . uniqid();
        list($pBody, $pCode) = $this->objects->create(['objtype' => 'project', 'ref' => $ref, 'title' => 'X']);
        $this->assertSame(201, $pCode);
        $id = (int) $pBody->id;
        $this->track('projet', $id);

        list($body, $code) = $this->act('project', $id, 'validate');
        $this->assertSame(400, $code, 'project has no workflow actions: ' . json_encode($body));
    }

    public function testActionFailClosedWhenUpdateRightMissing(): void
    {
        global $user;
        $id = $this->createDocumentWithLine('proposal', 'propal');

        $saved = $user->rights->propal->creer;
        $user->rights->propal->creer = 0;
        try {
            list($body, $code) = $this->act('proposal', $id, 'validate');
            $this->assertSame(403, $code, 'action must fail closed without creer: ' . json_encode($body));
        } finally {
            $user->rights->propal->creer = $saved;
        }
    }
}
