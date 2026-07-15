<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';
require_once __DIR__ . '/../../../api/ObjectLineController.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;

/**
 * Integration tests for the document-line REST facade (ObjectLineController +
 * DocumentLineInvoker) against a real Dolibarr + SQLite backend. Covers the
 * add/update/delete/reorder flow for proposal (pilot), order and invoice, plus
 * product hydration, the exact-permutation reorder guard, and the fail-closed
 * permission gate.
 *
 * @covers \SmartAuth\Api\ObjectLineController
 * @covers \SmartAuth\Api\DocumentLineInvoker
 */
class ObjectLineControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $objects;

    /** @var ObjectLineController */
    private $lines;

    /** @var array<int,array{0:string,1:int}> */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new ObjectController();
        $this->lines = new ObjectLineController();
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

    /**
     * Create a draft document of $type linked to a fresh thirdparty; return its id.
     */
    private function createDocument(string $type, string $table): int
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        $payload = ['objtype' => $type, 'thirdparty' => (int) $soc->id];
        // Facture/Propal require a non-empty date at creation.
        if ($type === 'invoice') {
            $payload['date_invoice'] = dol_now();
        } elseif ($type === 'proposal') {
            $payload['date_proposal'] = dol_now();
        }
        list($body, $code) = $this->objects->create($payload);
        $this->assertSame(201, $code, "create($type) failed: " . json_encode($body));
        $id = (int) $body->id;
        $this->track($table, $id);
        return $id;
    }

    /** Add a simple line and return the decoded response body. */
    private function addLine(string $type, int $id, array $line)
    {
        $payload = array_merge(['objtype' => $type, 'id' => $id], $line);
        list($body, $code) = $this->lines->store($payload);
        $this->assertSame(201, $code, "addLine($type) failed: " . json_encode($body));
        return $body;
    }

    // -------------------------------------------------------- proposal (pilot)

    public function testProposalLineAddUpdateDelete(): void
    {
        $id = $this->createDocument('proposal', 'propal');

        // ADD: totals must be recomputed on the returned document.
        $body = $this->addLine('proposal', $id, [
            'description' => 'Consulting', 'quantity' => 2, 'unit_price_excl_tax' => 100, 'vat_rate' => 20,
        ]);
        $this->assertIsArray($body->lines);
        $this->assertCount(1, $body->lines);
        $line = $body->lines[0];
        $this->assertSame('Consulting', $line->description);
        $this->assertEquals(2, $line->quantity);
        $this->assertEquals(100, $line->unit_price_excl_tax);
        $this->assertEquals(200, $body->total_excl_tax, 'document HT total must reflect the line');

        $lineId = (int) $line->id;

        // UPDATE: change quantity, keep unit price (merge preserves absent fields).
        list($updBody, $updCode) = $this->lines->update([
            'objtype' => 'proposal', 'id' => $id, 'lineid' => $lineId, 'quantity' => 5,
        ]);
        $this->assertSame(200, $updCode, 'line update failed: ' . json_encode($updBody));
        $this->assertEquals(5, $updBody->lines[0]->quantity);
        $this->assertEquals(100, $updBody->lines[0]->unit_price_excl_tax, 'unit price must be preserved on partial update');
        $this->assertEquals(500, $updBody->total_excl_tax);

        // DELETE.
        list($delBody, $delCode) = $this->lines->destroy([
            'objtype' => 'proposal', 'id' => $id, 'lineid' => $lineId,
        ]);
        $this->assertSame(200, $delCode, 'line delete failed: ' . json_encode($delBody));
        $this->assertCount(0, $delBody->lines ?? []);
    }

    public function testProposalLineReorderExactPermutation(): void
    {
        $id = $this->createDocument('proposal', 'propal');
        $b1 = $this->addLine('proposal', $id, ['description' => 'First', 'quantity' => 1, 'unit_price_excl_tax' => 10]);
        $id1 = (int) $b1->lines[0]->id;
        $b2 = $this->addLine('proposal', $id, ['description' => 'Second', 'quantity' => 1, 'unit_price_excl_tax' => 20]);
        // Second add returns both lines; find the new one.
        $id2 = 0;
        foreach ($b2->lines as $l) {
            if ((int) $l->id !== $id1) {
                $id2 = (int) $l->id;
            }
        }
        $this->assertGreaterThan(0, $id2);

        // Reorder reversed.
        list($body, $code) = $this->lines->reorder([
            'objtype' => 'proposal', 'id' => $id, 'order' => [$id2, $id1],
        ]);
        $this->assertSame(200, $code, 'reorder failed: ' . json_encode($body));
        // The first line in position order is now id2.
        $first = null;
        foreach ($body->lines as $l) {
            if ($first === null || (int) $l->position < (int) $first->position) {
                $first = $l;
            }
        }
        $this->assertSame($id2, (int) $first->id, 'reordered first line must be the one moved to the top');
    }

    public function testReorderRejectsForeignLineId(): void
    {
        $id = $this->createDocument('proposal', 'propal');
        $b1 = $this->addLine('proposal', $id, ['description' => 'Only', 'quantity' => 1, 'unit_price_excl_tax' => 10]);
        $id1 = (int) $b1->lines[0]->id;

        list($body, $code) = $this->lines->reorder([
            'objtype' => 'proposal', 'id' => $id, 'order' => [$id1 + 987654],
        ]);
        $this->assertSame(422, $code, 'foreign line id must be rejected: ' . json_encode($body));
    }

    // ------------------------------------------------------------- order

    public function testOrderLineAdd(): void
    {
        $id = $this->createDocument('order', 'commande');
        $body = $this->addLine('order', $id, [
            'description' => 'Widget', 'quantity' => 3, 'unit_price_excl_tax' => 50, 'vat_rate' => 0,
        ]);
        $this->assertCount(1, $body->lines);
        $this->assertEquals(3, $body->lines[0]->quantity);
        $this->assertEquals(150, $body->total_excl_tax);
    }

    // ------------------------------------------------------------- invoice

    public function testInvoiceLineAddThenDeleteOne(): void
    {
        $id = $this->createDocument('invoice', 'facture');
        $body = $this->addLine('invoice', $id, [
            'description' => 'Service', 'quantity' => 1, 'unit_price_excl_tax' => 250, 'vat_rate' => 20,
        ]);
        $this->assertCount(1, $body->lines);
        $lineId = (int) $body->lines[0]->id;
        $this->assertEquals(250, $body->total_excl_tax);

        list($delBody, $delCode) = $this->lines->destroy([
            'objtype' => 'invoice', 'id' => $id, 'lineid' => $lineId,
        ]);
        $this->assertSame(200, $delCode, 'invoice line delete failed: ' . json_encode($delBody));
        $this->assertCount(0, $delBody->lines ?? []);
    }

    // ------------------------------------------------ product hydration

    public function testAddLineHydratesFromProduct(): void
    {
        // A product with a known sell price. Price is not a facade-writable field
        // on dmProduct, so seed it directly on the row the hydration reads.
        list($pBody, $pCode) = $this->objects->create([
            'objtype' => 'product', 'ref' => 'HYD-' . uniqid(), 'label' => 'Hydrated',
        ]);
        $this->assertSame(201, $pCode, 'product create failed: ' . json_encode($pBody));
        $productId = (int) $pBody->id;
        $this->track('product', $productId);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "product SET price = 77 WHERE rowid = " . $productId);

        $id = $this->createDocument('proposal', 'propal');
        // No unit price sent: it must come from the product.
        $body = $this->addLine('proposal', $id, ['product' => $productId, 'quantity' => 1]);
        $this->assertCount(1, $body->lines);
        $this->assertEquals(77, $body->lines[0]->unit_price_excl_tax, 'line price must be hydrated from the product');
    }

    // ----------------------------------------------------------- guards

    public function testLineOnTypeWithoutLineSupportReturns400(): void
    {
        $soc = $this->createTestSociete();
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->lines->store([
            'objtype' => 'thirdparty', 'id' => (int) $soc->id, 'description' => 'x', 'quantity' => 1,
        ]);
        $this->assertSame(400, $code, 'thirdparty has no lines: ' . json_encode($body));
    }

    public function testLineAddFailClosedWhenUpdateRightMissing(): void
    {
        global $user;
        $id = $this->createDocument('proposal', 'propal');

        $saved = $user->rights->propal->creer;
        $user->rights->propal->creer = 0;
        try {
            list($body, $code) = $this->lines->store([
                'objtype' => 'proposal', 'id' => $id, 'description' => 'x', 'quantity' => 1,
            ]);
            $this->assertSame(403, $code, 'line add must fail closed without creer: ' . json_encode($body));
        } finally {
            $user->rights->propal->creer = $saved;
        }
    }
}
