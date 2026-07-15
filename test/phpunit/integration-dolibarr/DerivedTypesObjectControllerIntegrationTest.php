<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectController.php';

use SmartAuth\Api\ObjectController;

/**
 * Integration tests for the Vague 3 derived/child types of the objects/{type}
 * facade: shipment, reception, stock_movement, subscription. These are created
 * from parent documents (or are child rows), so the facade exposes them
 * read-mostly; this suite pins registration + the read path (columns/describe/
 * list). Write flows stay a per-module concern.
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class DerivedTypesObjectControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
    }

    public function testColumnsForEachDerivedType(): void
    {
        foreach (['shipment', 'reception', 'stock_movement', 'subscription'] as $type) {
            list($body, $code) = $this->controller->columns(['objtype' => $type]);
            $this->assertSame(200, $code, "columns($type): " . json_encode($body));
            $this->assertIsArray($body);
        }
    }

    public function testListEnvelopeForEachDerivedType(): void
    {
        foreach (['shipment', 'reception', 'stock_movement', 'subscription'] as $type) {
            list($body, $code) = $this->controller->index(['objtype' => $type, 'limit' => 5]);
            $this->assertSame(200, $code, "list($type): " . json_encode($body));
            $this->assertArrayHasKey('items', $body);
            $this->assertArrayHasKey('total', $body);
        }
    }

    public function testReadFailsClosedWithoutRight(): void
    {
        global $user;
        $saved = $user->rights->expedition->lire;
        $user->rights->expedition->lire = 0;
        try {
            list($body, $code) = $this->controller->index(['objtype' => 'shipment']);
            $this->assertSame(403, $code, 'shipment read must fail closed: ' . json_encode($body));
        } finally {
            $user->rights->expedition->lire = $saved;
        }
    }
}
