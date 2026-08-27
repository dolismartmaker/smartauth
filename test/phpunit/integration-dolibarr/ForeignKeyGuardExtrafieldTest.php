<?php

/**
 * RUNTIME PROOF that the tenant guard reaches the EXTRAFIELDS a mapper opens
 * for write.
 *
 * The gap this closes. $foreignKeyGuards only ever names native columns, so the
 * guard walked right past the 'options_*' keys -- and a Dolibarr extrafield of
 * type 'link' holds exactly what a native fk_ column holds: another object's
 * rowid. Once $extrafieldsRW opened extrafields for write (facade vague 3), a
 * PATCH could point a custom field at another tenant's company through a door
 * $foreignKeyGuards had been built to close on the very same table. The spec had
 * called the order of operations (TODO section 9.3: "extend the foreign-key
 * guards to link-typed extrafields BEFORE opening the write"); the write was
 * opened first.
 *
 * What is exercised here: real extrafields created in llx_extrafields, real
 * rows planted in a foreign entity, and the SHARED guard
 * (ForeignKeyGuardTrait::foreignKeyViolation) called exactly as
 * ObjectController::create/update call it -- a non-null return is what those two
 * turn into a 404 (ObjectController l.381-385 and l.496-500).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\ForeignKeyGuardTrait;
use SmartAuth\Api\ObjectRegistry;
use SmartAuth\DolibarrMapping\dmBase;

/**
 * Minimal host for the shared trait, so the guard is exercised through the very
 * method the four facade controllers and SyncController call.
 */
class ExtrafieldGuardHost
{
    use ForeignKeyGuardTrait;

    /**
     * @param  object    $mapper
     * @param  \stdClass $sanitized
     * @param  array     $cfg
     * @return string|null
     */
    public function guard($mapper, $sanitized, $cfg)
    {
        return $this->foreignKeyViolation($mapper, $sanitized, $cfg);
    }
}

/**
 * Societe mapper opening four extrafields for write, one per shape the guard
 * has to tell apart.
 */
class ExtrafieldGuardTestMapper extends dmBase
{
    protected $type = 'object';
    protected $dolibarrClassName = 'Societe';
    protected $parentTableElementToUseForExtraFields = 'societe';
    protected $listOfPublishedFields = [
        'rowid'              => 'id',
        'name'               => 'name',
        'options_efglink'    => 'ef_link',
        'options_efgproject' => 'ef_project',
        'options_efgsellist' => 'ef_sellist',
        'options_efgchkbx'   => 'ef_chkbx',
        'options_efgcode'    => 'ef_code',
        'options_efgdict'    => 'ef_dict',
        'options_efgforeign' => 'ef_foreign',
        'options_efgtext'    => 'ef_text',
    ];
    protected $writableFields = ['name'];
    protected $extrafieldsRW = [
        'efglink', 'efgproject', 'efgsellist', 'efgchkbx', 'efgcode', 'efgdict', 'efgforeign', 'efgtext',
    ];
}

/**
 * Mapper declaring an extrafield that exists in NO llx_extrafields row: the
 * fail-closed case of a broken declaration.
 */
class ExtrafieldGuardGhostMapper extends dmBase
{
    protected $type = 'object';
    protected $dolibarrClassName = 'Societe';
    protected $parentTableElementToUseForExtraFields = 'societe';
    protected $listOfPublishedFields = [
        'rowid'           => 'id',
        'options_efghost' => 'ef_ghost',
    ];
    protected $extrafieldsRW = ['efghost'];
}

/**
 * Mapper opening an extrafield through $writableFields instead of
 * $extrafieldsRW -- the undocumented second door dmTrait::importMappedData()
 * leaves open (its reverse map is built with an OR, l.491-496).
 */
class ExtrafieldGuardBackdoorMapper extends dmBase
{
    protected $type = 'object';
    protected $dolibarrClassName = 'Societe';
    protected $parentTableElementToUseForExtraFields = 'societe';
    protected $listOfPublishedFields = [
        'rowid'           => 'id',
        'options_efglink' => 'ef_link',
    ];
    protected $writableFields = ['options_efglink'];
}

/**
 * @covers \SmartAuth\Api\ForeignKeyGuardTrait
 * @covers \SmartAuth\DolibarrMapping\dmBase
 */
class ForeignKeyGuardExtrafieldTest extends DolibarrRealTestCase
{
    /**
     * Entity the planted victim rows are moved to. 95, 96, 97 and 99 are taken
     * by LineProductCrossTenantTest, SyncForeignKeyGuardTest,
     * ForeignKeyCrossTenantTest and SubscriptionCrossTenantTest respectively.
     */
    private const FOREIGN_ENTITY = 94;

    /** @var ExtrafieldGuardHost */
    private $host;

    /** @var ExtrafieldGuardTestMapper */
    private $mapper;

    /** @var array<string,mixed> */
    private $cfg;

    /** @var array<int,array{0:string,1:int}> table/rowid pairs to delete */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $ef = new \ExtraFields($this->db);
        // 'link' -> int(11) column holding a rowid of llx_societe.
        $ef->addExtraField(
            'efglink',
            'EFG link',
            'link',
            1500,
            0,
            'societe',
            0,
            0,
            '',
            ['options' => ['Societe:societe/class/societe.class.php' => null]]
        );
        // 'sellist' keyed on rowid -> varchar column holding a rowid of
        // llx_societe.
        $ef->addExtraField(
            'efgsellist',
            'EFG sellist',
            'sellist',
            1501,
            255,
            'societe',
            0,
            0,
            '',
            ['options' => ['societe:nom:rowid' => null]]
        );
        // 'chkbxlst' keyed on rowid -> varchar column holding a comma-separated
        // list of llx_societe rowids.
        $ef->addExtraField(
            'efgchkbx',
            'EFG chkbxlst',
            'chkbxlst',
            1502,
            255,
            'societe',
            0,
            0,
            '',
            ['options' => ['societe:nom:rowid' => null]]
        );
        // A SECOND 'link', to another registry type. Without it the whole file
        // would only ever resolve 'societe', and a typeForTable() that ignored
        // its argument (or compared 'element' instead of 'table') would stay
        // green while sending every probe to llx_societe.
        $ef->addExtraField(
            'efgproject',
            'EFG project link',
            'link',
            1504,
            0,
            'societe',
            0,
            0,
            '',
            ['options' => ['Project:projet/class/project.class.php' => null]]
        );
        // 'sellist' keyed on a CODE rather than a rowid, on a table that IS a
        // registry type. That combination is deliberate: it isolates the
        // key-field rule, since the table alone would otherwise resolve and the
        // test would pass for the wrong reason.
        $ef->addExtraField(
            'efgcode',
            'EFG code list',
            'sellist',
            1505,
            255,
            'societe',
            0,
            0,
            '',
            ['options' => ['societe:nom:code_client' => null]]
        );
        // 'sellist' keyed on rowid but on a table outside the registry (a
        // dictionary): the other unguarded branch of the list resolution.
        $ef->addExtraField(
            'efgdict',
            'EFG dictionary list',
            'sellist',
            1507,
            255,
            'societe',
            0,
            0,
            '',
            ['options' => ['c_typent:libelle:rowid' => null]]
        );
        // 'link' to a class that LOADS but whose table backs no registry type
        // (llx_don). Left unguarded on purpose, so a module linking its own
        // objects is not broken. Pins the second deliberate fail-open. Don is
        // used rather than a fixture class because the resolution reads
        // table_element off a real instance.
        $ef->addExtraField(
            'efgforeign',
            'EFG unregistered table link',
            'link',
            1506,
            0,
            'societe',
            0,
            0,
            '',
            ['options' => ['Don:don/class/don.class.php' => null]]
        );
        // A plain scalar: proof the guard leaves the non-referencing types alone.
        $ef->addExtraField('efgtext', 'EFG text', 'varchar', 1503, 64, 'societe');

        // The global $extrafields cache survives from one test to the next and
        // may predate these attributes (same reason as ObjectExtrafieldWriteTest).
        global $extrafields;
        if (!isset($extrafields) || !is_object($extrafields)) {
            $extrafields = new \ExtraFields($this->db);
        }
        $extrafields->fetch_name_optionals_label('societe', true);

        $this->host = new ExtrafieldGuardHost();
        $this->mapper = new ExtrafieldGuardTestMapper();
        $this->cfg = ObjectRegistry::get('thirdparty');
        $this->cfg['object_type'] = 'thirdparty';
        $this->created = [];

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            list($table, $id) = $row;
            $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . $table . ' WHERE rowid = ' . ((int) $id));
        }

        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * type 'link' -- the shape that mirrors a native fk_ column
     * --------------------------------------------------------------- */

    public function testLinkExtrafieldPointingAtAForeignCompanyIsRefused(): void
    {
        $foreignId = $this->plantForeignSociete('Foreign target of a link extrafield');

        $sanitized = (object) ['options_efglink' => $foreignId];
        $offending = $this->host->guard($this->mapper, $sanitized, $this->cfg);

        $this->assertSame(
            'options_efglink',
            $offending,
            'a link extrafield pointing at another tenant must be refused (the facade turns this into a 404)'
        );
    }

    public function testLinkExtrafieldPointingAtALocalCompanyIsAccepted(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target of a link extrafield']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) ['options_efglink' => (int) $local->id];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a link extrafield pointing inside the tenant must pass'
        );
    }

    /**
     * The value written must be the value probed, so a numeric string is
     * normalised to the integer that was checked -- same rule as the native
     * keys (ForeignKeyGuardTrait l.271-272).
     */
    public function testLinkExtrafieldValueIsNormalisedToTheIntegerThatWasProbed(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target, string payload']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) ['options_efglink' => (string) $local->id];
        $this->assertNull($this->host->guard($this->mapper, $sanitized, $this->cfg));

        $this->assertSame(
            (int) $local->id,
            $sanitized->options_efglink,
            'the link value must reach the database as the integer the guard probed'
        );
    }

    /**
     * "<id>, entity=NN" is the payload that smuggled a second SQL assignment on
     * the native side, where the core interpolates the value raw. An extrafield
     * is written through insertExtraFields(), which escapes -- so the danger is
     * not injection here, it is ambiguity: the guard would probe row <id> and
     * the column would receive something else. Refused outright, which is
     * stricter than the native keys and costs nothing since no legitimate client
     * sends this.
     */
    public function testLinkExtrafieldRefusesTheSmuggledAssignmentPayload(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target, smuggled payload']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) ['options_efglink' => $local->id . ', entity=' . self::FOREIGN_ENTITY];

        $this->assertSame(
            'options_efglink',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a value that is not exactly a row id must be refused'
        );
    }

    public function testClearingALinkExtrafieldIsAlwaysAllowed(): void
    {
        foreach ([null, '', 0, '0'] as $empty) {
            $sanitized = (object) ['options_efglink' => $empty];
            $this->assertNull(
                $this->host->guard($this->mapper, $sanitized, $this->cfg),
                'detaching a link extrafield must stay legitimate (value ' . var_export($empty, true) . ')'
            );
        }
    }

    public function testLinkExtrafieldPointingAtANonExistentRowIsRefused(): void
    {
        $sanitized = (object) ['options_efglink' => 99999999];

        $this->assertSame(
            'options_efglink',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'an unknown target row is a refusal, like everywhere else in this guard'
        );
    }

    /**
     * A client sending a REFERENCE where a row id is expected must be refused,
     * not silently detached. `(int) 'PJ2401'` is 0, and 0 reads as a legitimate
     * detach: the field would be unlinked and the request would answer 200.
     */
    public function testLinkExtrafieldWithANonNumericValueIsRefusedRatherThanDetached(): void
    {
        $sanitized = (object) ['options_efglink' => 'PJ2401'];

        $this->assertSame(
            'options_efglink',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a non-numeric link value must be refused, never cast to a detach'
        );
        $this->assertSame(
            'PJ2401',
            $sanitized->options_efglink,
            'a refused value must not have been rewritten on the way out'
        );
    }

    /**
     * `(int) [...]` is 0 or 1 plus a PHP warning: a value nobody sent, written
     * silently. Non-scalars are refused before any classification.
     */
    public function testNonScalarExtrafieldValuesAreRefused(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target, array payload']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) ['options_efglink' => [(int) $local->id]];

        $this->assertSame(
            'options_efglink',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'an array cannot be a row id: refusing beats casting it to 0 or 1'
        );
    }

    /* -----------------------------------------------------------------
     * type 'sellist' / 'chkbxlst' keyed on rowid -- varchar columns, so
     * validated by shape rather than cast
     * --------------------------------------------------------------- */

    public function testSellistExtrafieldPointingAtAForeignCompanyIsRefused(): void
    {
        $foreignId = $this->plantForeignSociete('Foreign target of a sellist extrafield');

        $sanitized = (object) ['options_efgsellist' => (string) $foreignId];

        $this->assertSame(
            'options_efgsellist',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a sellist keyed on rowid references a row and must be tenant-checked'
        );
    }

    public function testSellistExtrafieldValueIsNotCast(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target of a sellist']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) ['options_efgsellist' => (string) $local->id];
        $this->host->guard($this->mapper, $sanitized, $this->cfg);

        $this->assertSame(
            (string) $local->id,
            $sanitized->options_efgsellist,
            'the sellist column is a varchar: casting it would corrupt the stored format'
        );
    }

    public function testSellistExtrafieldWithANonNumericValueIsRefused(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target, smuggled value']);
        $this->track('societe', (int) $local->id);

        // Probed as row N by an (int) cast, stored verbatim in the varchar
        // column: refusing is the only consistent answer.
        $sanitized = (object) ['options_efgsellist' => $local->id . 'abc'];

        $this->assertSame(
            'options_efgsellist',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a rowid-keyed list must carry digits only'
        );
    }

    public function testChkbxlstExtrafieldRefusesTheListThatHidesAForeignId(): void
    {
        $local = $this->createTestSociete(['name' => 'Local member of the list']);
        $this->track('societe', (int) $local->id);
        $foreignId = $this->plantForeignSociete('Foreign member of the list');

        $sanitized = (object) ['options_efgchkbx' => $local->id . ',' . $foreignId];

        $this->assertSame(
            'options_efgchkbx',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'every id of the list is checked, not just the first'
        );
    }

    /**
     * Each id of a list costs one SELECT in the tenant probe. An uncapped list
     * turns one authenticated request into as many queries as the payload has
     * commas -- a 400 kB body buys 200 000 of them. The cap and the dedup run
     * BEFORE any probe.
     */
    public function testAnOversizedListIsRefusedBeforeAnyProbe(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target of a huge list']);
        $this->track('societe', (int) $local->id);

        // 101 DISTINCT ids: over the ceiling, refused.
        $ids = [];
        for ($i = 1; $i <= 101; $i++) {
            $ids[] = $i;
        }
        $sanitized = (object) ['options_efgchkbx' => implode(',', $ids)];

        $this->assertSame(
            'options_efgchkbx',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a list longer than the ceiling must be refused rather than probed row by row'
        );
    }

    /**
     * The dedup is what makes the cap meaningful: "12,12,12,..." is one distinct
     * id, so it must pass rather than count as thousands.
     */
    public function testARepeatedIdCountsOnce(): void
    {
        $local = $this->createTestSociete(['name' => 'Local target, repeated']);
        $this->track('societe', (int) $local->id);

        $sanitized = (object) [
            'options_efgchkbx' => implode(',', array_fill(0, 500, (int) $local->id)),
        ];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a repeated local id is one distinct id and must not trip the ceiling'
        );
    }

    public function testChkbxlstExtrafieldAcceptsAFullyLocalList(): void
    {
        $a = $this->createTestSociete(['name' => 'Local list member A']);
        $this->track('societe', (int) $a->id);
        $b = $this->createTestSociete(['name' => 'Local list member B']);
        $this->track('societe', (int) $b->id);

        $sanitized = (object) ['options_efgchkbx' => $a->id . ',' . $b->id];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a list of local ids must pass untouched'
        );
        $this->assertSame(
            $a->id . ',' . $b->id,
            $sanitized->options_efgchkbx,
            'the comma-separated format must survive the guard'
        );
    }

    /* -----------------------------------------------------------------
     * the target is resolved from the DESCRIPTOR, not assumed
     * --------------------------------------------------------------- */

    /**
     * Two link extrafields on the SAME object, pointing at two DIFFERENT
     * registry types. Without this pair the whole file would only ever resolve
     * 'societe', and a typeForTable() that ignored its argument -- or that
     * compared `element` instead of `table`, which differ on contact, project
     * and category -- would stay green while sending every probe to llx_societe.
     */
    public function testEachLinkIsProbedAgainstItsOwnTargetTable(): void
    {
        $project = $this->createLocalProject('Local project target');

        // A local project id, on the field that points at llx_projet: accepted.
        $sanitized = (object) ['options_efgproject' => $project];
        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a project link pointing at a local project must pass'
        );

        // The SAME id on the field that points at llx_societe: it must be
        // probed there, where it is either absent (refused) or a different row.
        // Either way the two fields cannot share a verdict by construction.
        $foreignProject = $this->createLocalProject('Project moved away');
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'projet SET entity = ' . self::FOREIGN_ENTITY
            . ' WHERE rowid = ' . $foreignProject;
        $this->assertNotFalse($this->db->query($sql), 'could not plant the foreign project');

        $sanitized = (object) ['options_efgproject' => $foreignProject];
        $this->assertSame(
            'options_efgproject',
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a project link pointing at another tenant must be refused'
        );
    }

    /* -----------------------------------------------------------------
     * the two deliberate fail-opens -- pinned so they stay decisions
     * --------------------------------------------------------------- */

    /**
     * A list keyed on a CODE references no row: guarding it would refuse every
     * legitimate value. The target table here IS a registry type, so only the
     * key-field rule stands between this value and a probe -- drop that rule and
     * every write on the field answers 404.
     */
    public function testAListKeyedOnACodeIsLeftUnguarded(): void
    {
        $sanitized = (object) ['options_efgcode' => 'CU2401-ACME'];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a sellist keyed on a code stores a code, not a row id: it must pass untouched'
        );
        $this->assertSame('CU2401-ACME', $sanitized->options_efgcode, 'the code must not be rewritten');
    }

    /**
     * A list keyed on rowid but pointing at a dictionary: smartauth cannot probe
     * it, and dictionaries are seeded with a hardcoded entity 1 anyway, so
     * guarding them would refuse the shipped rows on every other tenant.
     */
    public function testAListPointingAtAnUnregisteredTableIsLeftUnguarded(): void
    {
        $sanitized = (object) ['options_efgdict' => '3'];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a dictionary row id must not be probed against a tenant it does not carry'
        );
    }

    /**
     * A link to a table that backs no registry type stays writable. Refusing it
     * would break every module linking its OWN objects through an extrafield --
     * the same trade-off resolvePolymorphicTarget() already made for agenda
     * events.
     */
    public function testALinkToAnUnregisteredTableIsLeftUnguarded(): void
    {
        $sanitized = (object) ['options_efgforeign' => 4242];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'smartauth knows neither the entity column nor the isolation predicate of that table: it must not refuse'
        );
    }

    /* -----------------------------------------------------------------
     * the shapes that carry no reference, and the broken declarations
     * --------------------------------------------------------------- */

    public function testScalarExtrafieldsAreLeftAlone(): void
    {
        $sanitized = (object) ['options_efgtext' => '42abc'];

        $this->assertNull(
            $this->host->guard($this->mapper, $sanitized, $this->cfg),
            'a varchar extrafield references nothing: guarding it would be noise'
        );
        $this->assertSame('42abc', $sanitized->options_efgtext, 'a scalar extrafield must not be rewritten');
    }

    public function testAnExtrafieldOpenedForWriteButAbsentFromTheSchemaIsRefused(): void
    {
        $ghost = new ExtrafieldGuardGhostMapper();
        $sanitized = (object) ['options_efghost' => 1];

        $this->assertSame(
            'options_efghost',
            $this->host->guard($ghost, $sanitized, $this->cfg),
            'a mapper opening a field that does not exist is a broken setup: fail-closed'
        );
    }

    /**
     * 'options_xxx' placed in $writableFields opens the same door as
     * $extrafieldsRW (dmTrait l.491-496). A guard reading only $extrafieldsRW
     * would leave that second door unwatched.
     */
    public function testTheWritableFieldsBackdoorIsGuardedToo(): void
    {
        $foreignId = $this->plantForeignSociete('Foreign target through the backdoor');

        $backdoor = new ExtrafieldGuardBackdoorMapper();
        $sanitized = (object) ['options_efglink' => $foreignId];

        $this->assertSame(
            'options_efglink',
            $this->host->guard($backdoor, $sanitized, $this->cfg),
            'an extrafield opened through $writableFields must be guarded like one opened through $extrafieldsRW'
        );
    }

    /**
     * A mapper that does not derive from dmBase exposes neither
     * getForeignKeyGuards() nor getExtrafieldWriteTargets(): it keeps its
     * historical behaviour instead of being broken by a mechanism it never
     * opted into. Pinned so the fail-open stays a decision.
     */
    public function testAMapperOutsideDmBaseIsLeftUntouched(): void
    {
        $foreign = new \stdClass();
        $sanitized = (object) ['options_efglink' => 1];

        $this->assertNull(
            $this->host->guard($foreign, $sanitized, $this->cfg),
            'a consumer mapper outside dmBase must not be refused by this mechanism'
        );
    }

    /* -----------------------------------------------------------------
     * helpers
     * --------------------------------------------------------------- */

    /**
     * Create a company and move it to the foreign entity, returning its rowid.
     *
     * @param  string $name
     * @return int
     */
    private function plantForeignSociete($name)
    {
        $soc = $this->createTestSociete(['name' => $name]);
        $id = (int) $soc->id;
        $this->track('societe', $id);

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'societe SET entity = ' . self::FOREIGN_ENTITY . ' WHERE rowid = ' . $id;
        $this->assertNotFalse($this->db->query($sql), 'could not plant the foreign company: ' . $this->db->lasterror());

        return $id;
    }

    /**
     * Create a project in the harness entity and return its rowid.
     *
     * @param  string $label
     * @return int
     */
    private function createLocalProject($label)
    {
        global $user;

        require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';

        $project = new \Project($this->db);
        $project->ref = 'EFGP' . substr(md5($label . microtime(true)), 0, 8);
        $project->title = $label;
        $project->entity = (int) ($this->conf->entity ?? 1);
        $project->statut = 0;

        $id = $project->create($user);
        $this->assertGreaterThan(0, $id, 'could not create the test project: ' . $project->error);
        $this->track('projet', (int) $id);

        return (int) $id;
    }

    /**
     * @param  string $table
     * @param  int    $id
     * @return void
     */
    private function track($table, $id)
    {
        $this->created[] = [$table, (int) $id];
    }
}
