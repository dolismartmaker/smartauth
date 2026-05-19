<?php

/**
 * Tests for dmTrait::importMappedData() and MapperValidationException.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmBase;
use SmartAuth\DolibarrMapping\dmTrait;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * Fixture mapper for tests : maps Facture, declares an explicit writable
 * subset. Used to exercise the import contract without depending on the
 * production dmInvoice (which may or may not declare $writableFields yet).
 */
class TestImportFixtureMapper extends dmBase
{
    use dmTrait;

    protected $type = 'object';
    protected $dolibarrClassName = 'Facture';

    protected $listOfPublishedFields = [
        'rowid'             => 'id',
        'ref'               => 'ref',
        'ref_client'        => 'customer_ref',
        'date'              => 'date_invoice',
        'fk_soc'            => 'thirdparty',
        'fk_cond_reglement' => 'payment_terms',
        'total_ht'          => 'total_excl_tax',
        'note_public'       => 'public_note',
        'note_private'      => 'private_note',
        'statut'            => 'status',
    ];

    protected $writableFields = [
        'ref_client',
        'date',
        'fk_cond_reglement',
        'note_public',
        'note_private',
    ];

    public function __construct()
    {
        $this->boot();
    }
}

/**
 * Fixture mapper without $writableFields : exercises the read-only default.
 */
class TestImportReadOnlyMapper extends dmBase
{
    use dmTrait;

    protected $type = 'object';
    protected $dolibarrClassName = 'Facture';

    protected $listOfPublishedFields = [
        'rowid' => 'id',
        'ref'   => 'ref',
    ];

    // $writableFields not declared : defaults to [] from dmBase
    // -> mapper is read-only via the import path.

    public function __construct()
    {
        $this->boot();
    }
}

class DmTraitImportMappedDataTest extends DolibarrRealTestCase
{
    public function testReadOnlyMapperRejectsAnyInput(): void
    {
        $mapper = new TestImportReadOnlyMapper();

        $this->expectException(MapperValidationException::class);
        $mapper->importMappedData(['ref' => 'XYZ']);
    }

    public function testEmptyInputReturnsEmptyObject(): void
    {
        $mapper = new TestImportFixtureMapper();
        $result = $mapper->importMappedData([]);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals([], get_object_vars($result));
    }

    public function testSingleWritableFieldAccepted(): void
    {
        $mapper = new TestImportFixtureMapper();
        $result = $mapper->importMappedData(['public_note' => 'hello']);

        $this->assertObjectHasProperty('note_public', $result);
        $this->assertEquals('hello', $result->note_public);
    }

    public function testPublishedButNotWritableFieldRejected(): void
    {
        $mapper = new TestImportFixtureMapper();

        try {
            $mapper->importMappedData(['status' => 1]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testUnknownFieldRejected(): void
    {
        $mapper = new TestImportFixtureMapper();

        try {
            $mapper->importMappedData(['unknown_field' => 'foo']);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('unknown_field', $e->getErrors());
        }
    }

    public function testAllRejectedFieldsAreReported(): void
    {
        $mapper = new TestImportFixtureMapper();

        try {
            $mapper->importMappedData([
                'status'         => 1,
                'total_excl_tax' => 100,
                'unknown'        => 'foo',
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('status', $errors);
            $this->assertArrayHasKey('total_excl_tax', $errors);
            $this->assertArrayHasKey('unknown', $errors);
        }
    }

    public function testReverseMappingUsesDolibarrFieldName(): void
    {
        $mapper = new TestImportFixtureMapper();
        $result = $mapper->importMappedData(['customer_ref' => 'CMD-2026-001']);

        // Output uses Dolibarr field name, not API name
        $this->assertObjectHasProperty('ref_client', $result);
        $this->assertFalse(property_exists($result, 'customer_ref'));
        $this->assertEquals('CMD-2026-001', $result->ref_client);
    }

    public function testIntegerCastFromStringInput(): void
    {
        $mapper = new TestImportFixtureMapper();
        // fk_cond_reglement is declared as integer in Dolibarr Facture::$fields
        $result = $mapper->importMappedData(['payment_terms' => '42']);

        $this->assertSame(42, $result->fk_cond_reglement);
    }

    public function testLinesKeyRejected(): void
    {
        $mapper = new TestImportFixtureMapper();

        try {
            $mapper->importMappedData(['lines' => [['id' => 1]]]);
            $this->fail('Expected MapperValidationException for lines');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('lines', $e->getErrors());
        }
    }

    public function testValidationExceptionMessageListsRejectedFields(): void
    {
        $mapper = new TestImportFixtureMapper();

        try {
            $mapper->importMappedData(['status' => 1, 'unknown' => 'x']);
            $this->fail();
        } catch (MapperValidationException $e) {
            $this->assertStringContainsString('status', $e->getMessage());
            $this->assertStringContainsString('unknown', $e->getMessage());
        }
    }

    public function testMultipleWritableFieldsAcceptedAndMapped(): void
    {
        $mapper = new TestImportFixtureMapper();
        $result = $mapper->importMappedData([
            'customer_ref' => 'INV-001',
            'public_note'  => 'public',
            'private_note' => 'private',
        ]);

        $this->assertEquals('INV-001', $result->ref_client);
        $this->assertEquals('public', $result->note_public);
        $this->assertEquals('private', $result->note_private);
    }
}
