<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmBase;
use SmartAuth\DolibarrMapping\MapperValidationException;

/**
 * Test mapper: Societe with two extrafields -- facadeef is allowlisted for
 * write via $extrafieldsRW, facadero is published (readable) but NOT writable.
 */
class ExtrafieldWriteTestMapper extends dmBase
{
    protected $type = 'object';
    protected $dolibarrClassName = 'Societe';
    protected $parentTableElementToUseForExtraFields = 'societe';
    protected $listOfPublishedFields = [
        'rowid'            => 'id',
        'name'             => 'name',
        'options_facadeef' => 'facade_ef',
        'options_facadero' => 'facade_ro',
    ];
    protected $writableFields = ['name'];
    protected $extrafieldsRW = ['facadeef'];
}

/**
 * End-to-end proof that the facade writes extrafields when (and only when) the
 * mapper allowlists them via $extrafieldsRW: importMappedData() accepts the
 * allowlisted key and rejects the rest, applyImportedFields() routes it into
 * array_options, and insertExtraFields() persists it for the export round-trip.
 *
 * @covers \SmartAuth\DolibarrMapping\dmBase
 * @covers \SmartAuth\DolibarrMapping\dmTrait
 */
class ObjectExtrafieldWriteTest extends DolibarrRealTestCase
{
    /** @var ExtrafieldWriteTestMapper */
    private $mapper;

    /** @var int[] */
    private $createdSocIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        // Create the two societe extrafields (addExtraField is re-entrant).
        $ef = new \ExtraFields($this->db);
        $ef->addExtraField('facadeef', 'Facade EF', 'varchar', 100, 64, 'societe');
        $ef->addExtraField('facadero', 'Facade RO', 'varchar', 101, 64, 'societe');

        // An earlier test in the process may have populated the global
        // $extrafields cache for 'societe' BEFORE these attributes existed;
        // CommonObject::fetch_optionals() reuses that global, so force a reload
        // or the round-trip read below would not see the new columns.
        global $extrafields;
        if (!isset($extrafields) || !is_object($extrafields)) {
            $extrafields = new \ExtraFields($this->db);
        }
        $extrafields->fetch_name_optionals_label('societe', true);

        $this->mapper = new ExtrafieldWriteTestMapper();
        $this->createdSocIds = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSocIds as $id) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $id);
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_extrafields WHERE fk_object = " . (int) $id);
        }
        parent::tearDown();
    }

    public function testAllowlistedExtrafieldIsImportedRoutedAndPersisted(): void
    {
        // 1. importMappedData accepts the allowlisted extrafield and maps the
        //    appside key onto the options_ doliside key.
        $sanitized = $this->mapper->importMappedData(['name' => 'EfCo', 'facade_ef' => 'warranty-2030']);
        $this->assertTrue(property_exists($sanitized, 'options_facadeef'));
        $this->assertSame('warranty-2030', $sanitized->options_facadeef);
        $this->assertSame('EfCo', $sanitized->name);

        // 2. applyImportedFields routes the extrafield into array_options (NOT a
        //    plain property) and leaves native fields as properties.
        $soc = new \Societe($this->db);
        $this->mapper->applyImportedFields($soc, $sanitized);
        $this->assertSame('warranty-2030', $soc->array_options['options_facadeef']);
        $this->assertSame('EfCo', $soc->name);
        $this->assertFalse(property_exists($soc, 'options_facadeef') && isset($soc->options_facadeef));

        // 3. persist + reload: the extrafield survives the round-trip.
        $soc->client = 1;
        $soc->entity = 1;
        $res = $soc->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'societe create failed: ' . $soc->error);
        $this->createdSocIds[] = (int) $soc->id;
        $this->assertGreaterThanOrEqual(0, $soc->insertExtraFields());

        $reload = new \Societe($this->db);
        $reload->fetch((int) $soc->id);
        $reload->fetch_optionals();
        $this->assertSame('warranty-2030', $reload->array_options['options_facadeef']);

        // 4. export surfaces it under the appside key.
        $exported = $this->mapper->exportMappedData($reload);
        $this->assertSame('warranty-2030', $exported->facade_ef);
    }

    public function testNonAllowlistedExtrafieldIsRejected(): void
    {
        // facade_ro maps to options_facadero, which is published (readable) but
        // absent from $extrafieldsRW -> import must reject it.
        try {
            $this->mapper->importMappedData(['name' => 'x', 'facade_ro' => 'nope']);
            $this->fail('expected MapperValidationException for a non-allowlisted extrafield');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('facade_ro', $e->getErrors());
        }
    }
}
