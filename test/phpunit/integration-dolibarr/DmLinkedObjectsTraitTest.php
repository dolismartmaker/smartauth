<?php

/**
 * Tests for dmLinkedObjectsTrait functionality
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmLinkedObjectsTrait;

/**
 * Concrete class using dmLinkedObjectsTrait for testing
 */
class TestDmLinkedObjectsMapper
{
    use dmLinkedObjectsTrait;

    /**
     * Expose protected method for testing
     */
    public function testGetLinkedObjectsMapping($dolibarrObject): array
    {
        return $this->getLinkedObjectsMapping($dolibarrObject);
    }

    public function testGetLinkedObjectsWithData($dolibarrObject): array
    {
        return $this->getLinkedObjectsWithData($dolibarrObject);
    }

    public function testMapLinkedObjectType(string $dolibarrType): string
    {
        return $this->mapLinkedObjectType($dolibarrType);
    }

    public function testExtractBasicLinkedObjectData($obj, string $apiType): array
    {
        return $this->extractBasicLinkedObjectData($obj, $apiType);
    }

    public function testGetLinkedObjectsDescription(): array
    {
        return $this->getLinkedObjectsDescription();
    }

    /**
     * Get the static type mapping for verification
     */
    public static function getTypeMapping(): array
    {
        return self::$linkedObjectTypeMapping;
    }
}

/**
 * @covers \SmartAuth\DolibarrMapping\dmLinkedObjectsTrait
 */
class DmLinkedObjectsTraitTest extends DolibarrRealTestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TestDmLinkedObjectsMapper();
    }

    /**
     * Test mapLinkedObjectType for commercial documents
     */
    public function testMapLinkedObjectTypeCommercialDocuments(): void
    {
        $this->assertEquals('proposal', $this->mapper->testMapLinkedObjectType('propal'));
        $this->assertEquals('order', $this->mapper->testMapLinkedObjectType('commande'));
        $this->assertEquals('invoice', $this->mapper->testMapLinkedObjectType('facture'));
        $this->assertEquals('contract', $this->mapper->testMapLinkedObjectType('contrat'));
        $this->assertEquals('intervention', $this->mapper->testMapLinkedObjectType('fichinter'));
    }

    /**
     * Test mapLinkedObjectType for supplier documents
     */
    public function testMapLinkedObjectTypeSupplierDocuments(): void
    {
        $this->assertEquals('supplier_proposal', $this->mapper->testMapLinkedObjectType('supplier_proposal'));
        $this->assertEquals('supplier_order', $this->mapper->testMapLinkedObjectType('order_supplier'));
        $this->assertEquals('supplier_invoice', $this->mapper->testMapLinkedObjectType('invoice_supplier'));
    }

    /**
     * Test mapLinkedObjectType for logistics
     */
    public function testMapLinkedObjectTypeLogistics(): void
    {
        $this->assertEquals('shipment', $this->mapper->testMapLinkedObjectType('shipping'));
        $this->assertEquals('reception', $this->mapper->testMapLinkedObjectType('reception'));
    }

    /**
     * Test mapLinkedObjectType for other types
     */
    public function testMapLinkedObjectTypeOtherTypes(): void
    {
        $this->assertEquals('thirdparty', $this->mapper->testMapLinkedObjectType('societe'));
        $this->assertEquals('project', $this->mapper->testMapLinkedObjectType('project'));
        $this->assertEquals('agenda_event', $this->mapper->testMapLinkedObjectType('action'));
        $this->assertEquals('product', $this->mapper->testMapLinkedObjectType('product'));
        $this->assertEquals('expense_report', $this->mapper->testMapLinkedObjectType('expensereport'));
    }

    /**
     * Test mapLinkedObjectType returns original type if not in mapping
     */
    public function testMapLinkedObjectTypeUnknownType(): void
    {
        $this->assertEquals('unknown_type', $this->mapper->testMapLinkedObjectType('unknown_type'));
        $this->assertEquals('custom_element', $this->mapper->testMapLinkedObjectType('custom_element'));
    }

    /**
     * Test getLinkedObjectsMapping with empty linkedObjectsIds
     */
    public function testGetLinkedObjectsMappingWithEmptyLinkedObjectsIds(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = [];

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getLinkedObjectsMapping with null linkedObjectsIds
     */
    public function testGetLinkedObjectsMappingWithNullLinkedObjectsIds(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = null;

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getLinkedObjectsMapping with missing linkedObjectsIds property
     */
    public function testGetLinkedObjectsMappingWithMissingProperty(): void
    {
        $obj = new \stdClass();

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getLinkedObjectsMapping with single linked object
     */
    public function testGetLinkedObjectsMappingWithSingleLinkedObject(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = [
            'propal' => [123 => 123]
        ];

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('proposal', $result);
        $this->assertCount(1, $result['proposal']);
        $this->assertEquals(123, $result['proposal'][0]['id']);
        $this->assertEquals('proposal', $result['proposal'][0]['type']);
    }

    /**
     * Test getLinkedObjectsMapping with multiple linked objects of same type
     */
    public function testGetLinkedObjectsMappingWithMultipleLinkedObjectsSameType(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = [
            'facture' => [1 => 1, 2 => 2, 3 => 3]
        ];

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertArrayHasKey('invoice', $result);
        $this->assertCount(3, $result['invoice']);
        $this->assertEquals(1, $result['invoice'][0]['id']);
        $this->assertEquals(2, $result['invoice'][1]['id']);
        $this->assertEquals(3, $result['invoice'][2]['id']);
    }

    /**
     * Test getLinkedObjectsMapping with multiple linked object types
     */
    public function testGetLinkedObjectsMappingWithMultipleTypes(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = [
            'propal' => [10 => 10],
            'commande' => [20 => 20],
            'facture' => [30 => 30]
        ];

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertCount(3, $result);
        $this->assertArrayHasKey('proposal', $result);
        $this->assertArrayHasKey('order', $result);
        $this->assertArrayHasKey('invoice', $result);
    }

    /**
     * Test getLinkedObjectsWithData with empty linkedObjects
     */
    public function testGetLinkedObjectsWithDataWithEmptyLinkedObjects(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjects = [];

        $result = $this->mapper->testGetLinkedObjectsWithData($obj);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getLinkedObjectsWithData with null linkedObjects
     */
    public function testGetLinkedObjectsWithDataWithNullLinkedObjects(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjects = null;

        $result = $this->mapper->testGetLinkedObjectsWithData($obj);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getLinkedObjectsWithData with linked objects containing data
     */
    public function testGetLinkedObjectsWithDataWithObjectData(): void
    {
        $linkedPropal = new \stdClass();
        $linkedPropal->id = 123;
        $linkedPropal->ref = 'PR2024-001';
        $linkedPropal->status = 2;
        $linkedPropal->total_ttc = 1200.50;

        $obj = new \stdClass();
        $obj->linkedObjects = [
            'propal' => [$linkedPropal]
        ];

        $result = $this->mapper->testGetLinkedObjectsWithData($obj);

        $this->assertArrayHasKey('proposal', $result);
        $this->assertCount(1, $result['proposal']);
        $this->assertEquals(123, $result['proposal'][0]['id']);
        $this->assertEquals('PR2024-001', $result['proposal'][0]['ref']);
        $this->assertEquals(2, $result['proposal'][0]['status']);
        $this->assertEquals(1200.50, $result['proposal'][0]['total_incl_tax']);
    }

    /**
     * Test extractBasicLinkedObjectData with id property
     */
    public function testExtractBasicLinkedObjectDataWithId(): void
    {
        $obj = new \stdClass();
        $obj->id = 123;

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertEquals(123, $result['id']);
        $this->assertEquals('invoice', $result['type']);
    }

    /**
     * Test extractBasicLinkedObjectData with rowid property
     */
    public function testExtractBasicLinkedObjectDataWithRowid(): void
    {
        $obj = new \stdClass();
        $obj->rowid = 456;

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'order');

        $this->assertEquals(456, $result['id']);
        $this->assertEquals('order', $result['type']);
    }

    /**
     * Test extractBasicLinkedObjectData with ref
     */
    public function testExtractBasicLinkedObjectDataWithRef(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->ref = 'FA2024-0001';

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertArrayHasKey('ref', $result);
        $this->assertEquals('FA2024-0001', $result['ref']);
    }

    /**
     * Test extractBasicLinkedObjectData with label
     */
    public function testExtractBasicLinkedObjectDataWithLabel(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->label = 'Product Label';

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'product');

        $this->assertArrayHasKey('label', $result);
        $this->assertEquals('Product Label', $result['label']);
    }

    /**
     * Test extractBasicLinkedObjectData with nom property
     */
    public function testExtractBasicLinkedObjectDataWithNom(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->nom = 'Company Name';

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'thirdparty');

        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('Company Name', $result['name']);
    }

    /**
     * Test extractBasicLinkedObjectData with name property
     */
    public function testExtractBasicLinkedObjectDataWithName(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->name = 'Project Name';

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'project');

        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('Project Name', $result['name']);
    }

    /**
     * Test extractBasicLinkedObjectData with status
     */
    public function testExtractBasicLinkedObjectDataWithStatus(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->status = 3;

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(3, $result['status']);
    }

    /**
     * Test extractBasicLinkedObjectData with statut (French spelling)
     */
    public function testExtractBasicLinkedObjectDataWithStatut(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->statut = 2;

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'order');

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(2, $result['status']);
    }

    /**
     * Test extractBasicLinkedObjectData with total_ttc
     */
    public function testExtractBasicLinkedObjectDataWithTotalTtc(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->total_ttc = 1500.75;

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertArrayHasKey('total_incl_tax', $result);
        $this->assertEquals(1500.75, $result['total_incl_tax']);
    }

    /**
     * Test extractBasicLinkedObjectData with date
     */
    public function testExtractBasicLinkedObjectDataWithDate(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->date = '2024-01-15';

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertArrayHasKey('date', $result);
        $this->assertEquals('2024-01-15', $result['date']);
    }

    /**
     * Test extractBasicLinkedObjectData with empty object returns minimal data
     */
    public function testExtractBasicLinkedObjectDataWithEmptyObject(): void
    {
        $obj = new \stdClass();

        $result = $this->mapper->testExtractBasicLinkedObjectData($obj, 'invoice');

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals(0, $result['id']);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('invoice', $result['type']);
    }

    /**
     * Test getLinkedObjectsDescription returns proper structure
     */
    public function testGetLinkedObjectsDescriptionReturnsProperStructure(): void
    {
        $result = $this->mapper->testGetLinkedObjectsDescription();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('object', $result['type']);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('example', $result);
    }

    /**
     * Test getLinkedObjectsDescription example contains expected types
     */
    public function testGetLinkedObjectsDescriptionExampleContainsExpectedTypes(): void
    {
        $result = $this->mapper->testGetLinkedObjectsDescription();
        $example = $result['example'];

        $this->assertArrayHasKey('proposal', $example);
        $this->assertArrayHasKey('order', $example);
        $this->assertArrayHasKey('invoice', $example);
    }

    /**
     * Test static type mapping contains all expected keys
     */
    public function testStaticTypeMappingContainsExpectedKeys(): void
    {
        $mapping = TestDmLinkedObjectsMapper::getTypeMapping();

        // Commercial documents
        $this->assertArrayHasKey('propal', $mapping);
        $this->assertArrayHasKey('commande', $mapping);
        $this->assertArrayHasKey('facture', $mapping);
        $this->assertArrayHasKey('contrat', $mapping);
        $this->assertArrayHasKey('fichinter', $mapping);

        // Supplier documents
        $this->assertArrayHasKey('supplier_proposal', $mapping);
        $this->assertArrayHasKey('order_supplier', $mapping);
        $this->assertArrayHasKey('invoice_supplier', $mapping);

        // Logistics
        $this->assertArrayHasKey('shipping', $mapping);
        $this->assertArrayHasKey('reception', $mapping);

        // Other
        $this->assertArrayHasKey('societe', $mapping);
        $this->assertArrayHasKey('project', $mapping);
        $this->assertArrayHasKey('action', $mapping);
        $this->assertArrayHasKey('product', $mapping);
        $this->assertArrayHasKey('expensereport', $mapping);
    }

    /**
     * Test getLinkedObjectsMapping handles array format [id] correctly
     */
    public function testGetLinkedObjectsMappingHandlesSimpleArrayFormat(): void
    {
        $obj = new \stdClass();
        $obj->linkedObjectsIds = [
            'propal' => [100, 200, 300]  // Simple array format
        ];

        $result = $this->mapper->testGetLinkedObjectsMapping($obj);

        $this->assertArrayHasKey('proposal', $result);
        $this->assertCount(3, $result['proposal']);
    }

    /**
     * Test complete integration with multiple linked objects and data
     */
    public function testCompleteIntegrationWithMultipleLinkedObjects(): void
    {
        $linkedPropal = new \stdClass();
        $linkedPropal->id = 1;
        $linkedPropal->ref = 'PR001';
        $linkedPropal->status = 2;

        $linkedOrder = new \stdClass();
        $linkedOrder->id = 2;
        $linkedOrder->ref = 'CO001';
        $linkedOrder->statut = 1;
        $linkedOrder->total_ttc = 500.00;

        $linkedInvoice = new \stdClass();
        $linkedInvoice->id = 3;
        $linkedInvoice->ref = 'FA001';
        $linkedInvoice->status = 1;
        $linkedInvoice->total_ttc = 500.00;
        $linkedInvoice->date = '2024-06-15';

        $obj = new \stdClass();
        $obj->linkedObjects = [
            'propal' => [$linkedPropal],
            'commande' => [$linkedOrder],
            'facture' => [$linkedInvoice]
        ];

        $result = $this->mapper->testGetLinkedObjectsWithData($obj);

        $this->assertCount(3, $result);
        $this->assertArrayHasKey('proposal', $result);
        $this->assertArrayHasKey('order', $result);
        $this->assertArrayHasKey('invoice', $result);

        // Verify proposal data
        $this->assertEquals(1, $result['proposal'][0]['id']);
        $this->assertEquals('PR001', $result['proposal'][0]['ref']);

        // Verify order data
        $this->assertEquals(2, $result['order'][0]['id']);
        $this->assertEquals('CO001', $result['order'][0]['ref']);
        $this->assertEquals(1, $result['order'][0]['status']);

        // Verify invoice data
        $this->assertEquals(3, $result['invoice'][0]['id']);
        $this->assertEquals('FA001', $result['invoice'][0]['ref']);
        $this->assertEquals(500.00, $result['invoice'][0]['total_incl_tax']);
        $this->assertEquals('2024-06-15', $result['invoice'][0]['date']);
    }
}
