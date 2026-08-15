<?php

/**
 * RUNTIME proof of the write-side tenant guard of the objects/{objtype} facade.
 *
 * The defect: the facade validated the NAMES of the incoming fields and never
 * their VALUES. The three isolation guards of ObjectController all run on the
 * row AS IT STANDS before the write -- they answer "is this object mine?",
 * never "is what you send me mine?" -- and importMappedData() only knows names.
 * A PATCH objects/invoice/{id} carrying the socid of another tenant's company
 * was therefore written straight into llx_facture.fk_soc.
 *
 * WHAT ACTUALLY REFUSES, in each test below. This matters: on the lot banque of
 * Dolipocket a guard was twice believed proven while the real refusal came from
 * the core's own fetch(). Here every refusal comes from
 * ObjectFacadeTrait::foreignKeyViolation(), and the falsification was RUN, not
 * assumed. Setting dmInvoice::$foreignKeyGuards to [] and replaying the suite:
 *
 *   - testUpdateCannotPointAnInvoiceAtAForeignThirdparty answers 200 instead of
 *     404, and the response body carries "thirdparty":<foreign id> -- the write
 *     really reached llx_facture.fk_soc;
 *   - testUpdateCannotAttachAnInvoiceToAForeignProject answers 200 with
 *     "project":<foreign id>;
 *   - ForeignKeyGuardContractTest fails in the same breath, naming
 *     invoice.socid and invoice.fk_project as undecided;
 *   - restoring the two lines turns the whole file green again.
 *
 * Each of the four later tests carries its own falsification note, and each was
 * run the same way -- including one that first had to be REWRITTEN, because the
 * field originally chosen (Task::$fk_task_parent, declared `= 0` at
 * task.class.php:71) was already cast by the sanitizer, so the test stayed green
 * with the guard removed and proved nothing.
 *
 * That is why every test asserts the SQL column on top of the HTTP status: a
 * guard answering 404 while still writing would pass a status-only assertion.
 *
 * The core's fetch() cannot be what refuses here: verified on Dolibarr 18,
 * Project::fetch() and Entrepot::fetch() DROP the entity clause when loaded by
 * rowid, so a foreign project or warehouse loads fine. Societe::fetch() keeps
 * it -- which is exactly why the guard reads the target's entity column
 * directly instead of trusting any fetch().
 *
 * NOT covered here, on purpose: shipment.entrepot_id and reception.entrepot_id
 * go through the same registry target as warehouse.fk_parent (llx_entrepot) and
 * the same code path, but building an Expedition needs a validated order and a
 * stock setup that would test Dolibarr more than this guard. The declaration is
 * pinned by ForeignKeyGuardContractTest instead, and the target resolution is
 * exercised here through warehouse.fk_parent.
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

require_once __DIR__ . '/../../../api/ObjectController.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

use SmartAuth\Api\ObjectController;

/**
 * @covers \SmartAuth\Api\ObjectFacadeTrait
 * @covers \SmartAuth\Api\ObjectController
 */
class ForeignKeyCrossTenantTest extends DolibarrRealTestCase
{
    /** Entity the planted victim rows are moved to. Never the harness entity. */
    private const FOREIGN_ENTITY = 97;

    /** @var ObjectController */
    private $controller;

    /** @var array<int,array{0:string,1:int}> table/rowid pairs to delete */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ObjectController();
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
     * socid -- the key that spans 14 types
     * --------------------------------------------------------------- */

    public function testUpdateCannotPointAnInvoiceAtAForeignThirdparty(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $ownerBefore = (int) $this->rawColumn('facture', 'fk_soc', $invoiceId);

        $foreignSoc = $this->createTestSociete(['name' => 'Foreign customer']);
        $this->track('societe', (int) $foreignSoc->id);
        $this->moveToForeignEntity('societe', (int) $foreignSoc->id);

        list($body, $code) = $this->controller->update([
            'objtype'     => 'invoice',
            'id'          => $invoiceId,
            'thirdparty'  => (int) $foreignSoc->id,
        ]);

        $this->assertSame(404, $code, 'a cross-tenant socid must be refused: ' . json_encode($body));
        $this->assertSame(
            $ownerBefore,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'the invoice was re-parented in SQL despite the refusal'
        );
    }

    public function testCreateCannotFileAnInvoiceOnAForeignThirdparty(): void
    {
        $foreignSoc = $this->createTestSociete(['name' => 'Foreign customer for create']);
        $this->track('societe', (int) $foreignSoc->id);
        $this->moveToForeignEntity('societe', (int) $foreignSoc->id);

        $before = $this->countRows('facture');

        list($body, $code) = $this->controller->create([
            'objtype'      => 'invoice',
            'thirdparty'   => (int) $foreignSoc->id,
            'date_invoice' => dol_now(),
        ]);

        $this->assertSame(404, $code, 'creating on a foreign thirdparty must be refused: ' . json_encode($body));
        $this->assertSame($before, $this->countRows('facture'), 'an invoice was created despite the refusal');
        $this->assertSame(
            0,
            $this->countRows('facture', 'fk_soc = ' . ((int) $foreignSoc->id)),
            'an invoice was filed on the foreign company'
        );
    }

    /* -----------------------------------------------------------------
     * fk_project -- the other key that spans 14 types, and the one whose
     * target fetch() drops the entity clause
     * --------------------------------------------------------------- */

    public function testUpdateCannotAttachAnInvoiceToAForeignProject(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $foreignProjectId = $this->createProject('Foreign project');
        $this->moveToForeignEntity('projet', $foreignProjectId);

        // Sanity check on what does NOT refuse: Project::fetch() loads the
        // foreign project happily when given a rowid, so nothing in the core
        // would have stopped this write.
        $probe = new \Project($this->db);
        $this->assertGreaterThan(
            0,
            $probe->fetch($foreignProjectId),
            'Dolibarr loads a foreign project by rowid: the guard is the only thing that can refuse'
        );

        list($body, $code) = $this->controller->update([
            'objtype' => 'invoice',
            'id'      => $invoiceId,
            'project' => $foreignProjectId,
        ]);

        $this->assertSame(404, $code, 'a cross-tenant fk_project must be refused: ' . json_encode($body));
        $this->assertSame(
            0,
            (int) $this->rawColumn('facture', 'fk_projet', $invoiceId),
            'the invoice was attached to the foreign project in SQL despite the refusal'
        );
    }

    /* -----------------------------------------------------------------
     * fk_parent -- a self-referencing tree
     * --------------------------------------------------------------- */

    public function testUpdateCannotGraftACategoryUnderAForeignParent(): void
    {
        $localId = $this->createCategory('Local category');
        $foreignId = $this->createCategory('Foreign category');
        $this->moveToForeignEntity('categorie', $foreignId);

        list($body, $code) = $this->controller->update([
            'objtype' => 'category',
            'id'      => $localId,
            'parent'  => $foreignId,
        ]);

        $this->assertSame(404, $code, 'a cross-tenant fk_parent must be refused: ' . json_encode($body));
        $this->assertSame(
            0,
            (int) $this->rawColumn('categorie', 'fk_parent', $localId),
            'the category was grafted under the foreign tree in SQL despite the refusal'
        );
    }

    public function testUpdateCannotGraftAWarehouseUnderAForeignParent(): void
    {
        $localId = $this->createWarehouse('Local warehouse');
        $foreignId = $this->createWarehouse('Foreign warehouse');
        $this->moveToForeignEntity('entrepot', $foreignId);

        // Same sanity check as the project: Entrepot::fetch() ignores the
        // entity when loading by rowid, so the core refuses nothing here.
        $probe = new \Entrepot($this->db);
        $this->assertGreaterThan(
            0,
            $probe->fetch($foreignId),
            'Dolibarr loads a foreign warehouse by rowid: the guard is the only thing that can refuse'
        );

        list($body, $code) = $this->controller->update([
            'objtype'          => 'warehouse',
            'id'               => $localId,
            'parent_warehouse' => $foreignId,
        ]);

        $this->assertSame(404, $code, 'a cross-tenant warehouse fk_parent must be refused: ' . json_encode($body));
        $this->assertSame(
            0,
            (int) $this->rawColumn('entrepot', 'fk_parent', $localId),
            'the warehouse was grafted under the foreign tree in SQL despite the refusal'
        );
    }

    /* -----------------------------------------------------------------
     * fk_bank -- target table WITHOUT an entity column
     * --------------------------------------------------------------- */

    public function testUpdateCannotAttachAFeeToABankLineOfAnotherTenant(): void
    {
        $subscriptionId = $this->createLocalSubscription();

        $foreignAccount = $this->createTestBankAccount(['label' => 'Foreign tenant account']);
        $this->track('bank_account', (int) $foreignAccount->id);
        $this->moveToForeignEntity('bank_account', (int) $foreignAccount->id);
        $foreignLineId = $this->createBankLine((int) $foreignAccount->id);

        list($body, $code) = $this->controller->update([
            'objtype'   => 'subscription',
            'id'        => $subscriptionId,
            'bank_line' => $foreignLineId,
        ]);

        $this->assertSame(
            404,
            $code,
            'llx_bank has no entity column: the guard must replay dmBank::isolationWhereSql(). Got: ' . json_encode($body)
        );
        $this->assertSame(
            0,
            (int) $this->rawColumn('subscription', 'fk_bank', $subscriptionId),
            'the fee was attached to the foreign bank line in SQL despite the refusal'
        );
    }

    public function testUpdateStillAcceptsABankLineOfTheOwnTenant(): void
    {
        $subscriptionId = $this->createLocalSubscription();

        $localAccount = $this->createTestBankAccount(['label' => 'Local tenant account']);
        $this->track('bank_account', (int) $localAccount->id);
        $localLineId = $this->createBankLine((int) $localAccount->id);

        list($body, $code) = $this->controller->update([
            'objtype'   => 'subscription',
            'id'        => $subscriptionId,
            'bank_line' => $localLineId,
        ]);

        $this->assertSame(200, $code, 'a local bank line must stay writable: ' . json_encode($body));
        $this->assertSame(
            $localLineId,
            (int) $this->rawColumn('subscription', 'fk_bank', $subscriptionId),
            'the legitimate write did not reach the database'
        );
    }

    /* -----------------------------------------------------------------
     * Non-regression of the LEGITIMATE path -- the half that must not break.
     * A guard refusing these would be a worse regression than the defect it
     * closes (Dolipocket writes socid at creation and fk_project on linking).
     * --------------------------------------------------------------- */

    public function testUpdateStillAttachesAnInvoiceToAProjectOfTheSameEntity(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $localProjectId = $this->createProject('Local project');

        list($body, $code) = $this->controller->update([
            'objtype' => 'invoice',
            'id'      => $invoiceId,
            'project' => $localProjectId,
        ]);

        $this->assertSame(200, $code, 'a same-entity project must stay writable: ' . json_encode($body));
        $this->assertSame(
            $localProjectId,
            (int) $this->rawColumn('facture', 'fk_projet', $invoiceId),
            'the legitimate link did not reach the database'
        );
    }

    public function testUpdateStillDetachesAnInvoiceFromItsProject(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $localProjectId = $this->createProject('Project to detach from');

        list(, $code) = $this->controller->update([
            'objtype' => 'invoice',
            'id'      => $invoiceId,
            'project' => $localProjectId,
        ]);
        $this->assertSame(200, $code);
        $this->assertSame($localProjectId, (int) $this->rawColumn('facture', 'fk_projet', $invoiceId));

        // A value of 0 is a detach, not a violation: refusing it would break
        // the 14 types carrying fk_project.
        list($body, $code) = $this->controller->update([
            'objtype' => 'invoice',
            'id'      => $invoiceId,
            'project' => 0,
        ]);

        $this->assertSame(200, $code, 'detaching from a project must stay allowed: ' . json_encode($body));
        $this->assertSame(
            0,
            (int) $this->rawColumn('facture', 'fk_projet', $invoiceId),
            'the detach did not reach the database'
        );
    }

    public function testUpdateStillMovesAnInvoiceToAnotherLocalThirdparty(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $otherLocal = $this->createTestSociete(['name' => 'Another local customer']);
        $this->track('societe', (int) $otherLocal->id);

        list($body, $code) = $this->controller->update([
            'objtype'    => 'invoice',
            'id'         => $invoiceId,
            'thirdparty' => (int) $otherLocal->id,
        ]);

        $this->assertSame(200, $code, 'a same-entity thirdparty must stay writable: ' . json_encode($body));
        $this->assertSame(
            (int) $otherLocal->id,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'the legitimate move did not reach the database'
        );
    }

    /* -----------------------------------------------------------------
     * A reference that exists nowhere is refused too: the guard answers
     * "the target is not one of mine", which covers the dangling id.
     * --------------------------------------------------------------- */

    public function testUpdateRefusesAThirdpartyIdThatDoesNotExist(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $ownerBefore = (int) $this->rawColumn('facture', 'fk_soc', $invoiceId);
        $ghostId = $this->maxRowid('societe') + 100000;

        list($body, $code) = $this->controller->update([
            'objtype'    => 'invoice',
            'id'         => $invoiceId,
            'thirdparty' => $ghostId,
        ]);

        $this->assertSame(404, $code, 'a dangling socid must be refused: ' . json_encode($body));
        $this->assertSame(
            $ownerBefore,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'the invoice now points at a company that does not exist'
        );
    }

    /* -----------------------------------------------------------------
     * A guarded key is an integer BY CONSTRUCTION: the value written must be
     * the value probed, never the raw string the client sent.
     *
     * This is not a theoretical hardening. importMappedData() only casts a
     * field whose Dolibarr-side name is a key of the class $fields
     * (dmTrait::_getFieldDefinition, $applyNameHeuristics = false). Failing
     * that, the fallback types the field from the value a FRESH object carries
     * -- an int-initialised property yields 'integer' and is cast, a property
     * with no default yields varchar(255) and stays a STRING.
     *
     * `socid` and `fk_project` are exactly the second case: they are PHP
     * property names (the $fields entries are `fk_soc` and `fk_projet`), and
     * Commande::$socid (l.100) and CommonObject::$fk_project (l.208) are
     * declared with no default. Commande::update() then interpolates them raw:
     * " fk_soc=".(isset($this->socid) ? $this->socid : "null") at l.3389 and
     * the same shape for fk_projet at l.3400 -- no quote, no cast, no escape.
     * llx_commande.entity is NOT in that SET list, so a smuggled assignment
     * sticks.
     *
     * (Task::$fk_task_parent, by contrast, is declared `= 0` at
     * task.class.php:71, so the sanitizer already casts it. Writing the test on
     * that field would have proved nothing -- it passed with the normalisation
     * REMOVED. Hence the two tests below target the fields that really carry
     * the exposure.)
     *
     * FALSIFICATION, run: removing the `$sanitized->{$field} = $id;` line of
     * ObjectFacadeTrait::foreignKeyViolation() makes both tests below fail, the
     * order really landing in entity 97. The entity-scope guard does NOT catch
     * it: it ran before the body was even parsed, on a row that was still local
     * at the time.
     * --------------------------------------------------------------- */

    public function testAGuardedKeyIsNarrowedToTheIntegerThatWasProbed(): void
    {
        $orderId = $this->createLocalOrder();
        $entityBefore = (int) $this->rawColumn('commande', 'entity', $orderId);
        $otherLocal = $this->createTestSociete(['name' => 'Injection probe customer']);
        $this->track('societe', (int) $otherLocal->id);

        // The probe casts to $otherLocal->id and finds it local, so the payload
        // is accepted; what must reach SQL is that integer ALONE.
        list($body, $code) = $this->controller->update([
            'objtype'    => 'order',
            'id'         => $orderId,
            'thirdparty' => $otherLocal->id . ', entity=' . self::FOREIGN_ENTITY,
        ]);

        $this->assertSame(200, $code, 'the payload probes as a local thirdparty: ' . json_encode($body));
        $this->assertSame(
            (int) $otherLocal->id,
            (int) $this->rawColumn('commande', 'fk_soc', $orderId),
            'the legitimate part of the value did not reach the database'
        );
        $this->assertSame(
            $entityBefore,
            (int) $this->rawColumn('commande', 'entity', $orderId),
            'a second assignment was smuggled into the UPDATE: the order changed tenant'
        );
    }

    public function testTheNarrowingAlsoCoversTheDetachValueThatSkipsTheProbe(): void
    {
        $orderId = $this->createLocalOrder();
        $entityBefore = (int) $this->rawColumn('commande', 'entity', $orderId);

        // Casts to 0, so this is a legitimate DETACH as far as the probe is
        // concerned -- which is precisely why the normalisation cannot live
        // behind the "value > 0" short-circuit.
        list($body, $code) = $this->controller->update([
            'objtype' => 'order',
            'id'      => $orderId,
            'project' => '0, entity=' . self::FOREIGN_ENTITY,
        ]);

        $this->assertSame(200, $code, 'a detach must still be accepted: ' . json_encode($body));
        $this->assertSame(
            $entityBefore,
            (int) $this->rawColumn('commande', 'entity', $orderId),
            'a second assignment was smuggled in through a detach value'
        );
        $this->assertSame(
            0,
            (int) $this->rawColumn('commande', 'fk_projet', $orderId),
            'the detach itself did not reach the database'
        );
    }

    /**
     * The narrowing must cover the EXEMPTED dictionary keys too.
     *
     * Exempting a key means "do not check the target's tenant", never "let a
     * string reach an integer column". `payment_terms` maps to the property
     * alias cond_reglement_id, which is absent from CommandeFournisseur::$fields
     * and therefore leaves importMappedData() as a STRING, and
     * CommandeFournisseur::update() interpolates it raw at l.1690. A payload of
     * "0, fk_soc=<foreign id>" reparented the supplier order without ever
     * touching a guarded key -- the socid guard was simply never reached.
     *
     * FALSIFICATION, run: restricting the normalisation set back to the guarded
     * keys alone makes this test fail with llx_commande_fournisseur.fk_soc
     * holding the foreign company.
     */
    public function testAnExemptedDictionaryKeyCannotSmuggleAnAssignmentEither(): void
    {
        $orderId = $this->createLocalSupplierOrder();
        $ownerBefore = (int) $this->rawColumn('commande_fournisseur', 'fk_soc', $orderId);

        $foreignSoc = $this->createTestSociete(['name' => 'Foreign supplier target']);
        $this->track('societe', (int) $foreignSoc->id);
        $this->moveToForeignEntity('societe', (int) $foreignSoc->id);

        list($body, $code) = $this->controller->update([
            'objtype'       => 'supplier_order',
            'id'            => $orderId,
            'payment_terms' => '0, fk_soc=' . ((int) $foreignSoc->id),
        ]);

        $this->assertSame(200, $code, 'the dictionary write itself stays allowed: ' . json_encode($body));
        $this->assertSame(
            $ownerBefore,
            (int) $this->rawColumn('commande_fournisseur', 'fk_soc', $orderId),
            'an exempted key smuggled a second assignment: the supplier order changed thirdparty'
        );
    }

    /**
     * A polymorphic key must be judged as the PAIR that ends up in database.
     *
     * fk_element alone is meaningless: the table it points at is named by
     * elementtype. A PATCH moving only the type left the stored id validated
     * against the PREVIOUS table.
     *
     * FALSIFICATION, run: removing the polymorphicSiblingViolation() call makes
     * this test fail, the event ending up on the foreign company.
     */
    public function testMovingOnlyTheElementTypeRevalidatesTheStoredElementId(): void
    {
        // A LOCAL project, and a FOREIGN company planted on the SAME rowid: the
        // stored id is legitimate under elementtype 'project' and points at
        // another tenant under 'societe'.
        $sharedId = $this->freeRowidAcross(['societe', 'projet']);
        $projectId = $this->createProject('Polymorphic pair project');
        $projectId = $this->relocateRow('projet', $projectId, $sharedId);
        $foreignSoc = $this->createTestSociete(['name' => 'Foreign polymorphic target']);
        $this->relocateRow('societe', (int) $foreignSoc->id, $sharedId);
        $this->moveToForeignEntity('societe', $sharedId);

        $eventId = $this->createAgendaEvent($projectId, 'project');

        list($body, $code) = $this->controller->update([
            'objtype'     => 'agenda_event',
            'id'          => $eventId,
            'elementtype' => 'societe',
        ]);

        $this->assertSame(404, $code, 'the pair (id, type) must be re-judged as a whole: ' . json_encode($body));
        $this->assertSame(
            'project',
            (string) $this->rawColumn('actioncomm', 'elementtype', $eventId, 'id'),
            'the element type was changed in SQL despite the refusal'
        );
        $this->assertSame(
            $projectId,
            (int) $this->rawColumn('actioncomm', 'fk_element', $eventId, 'id'),
            'the linked element moved in SQL despite the refusal'
        );
    }

    /* -----------------------------------------------------------------
     * fixtures
     * --------------------------------------------------------------- */

    private function track(string $table, int $id): void
    {
        $this->created[] = [$table, $id];
    }

    private function createLocalInvoice(): int
    {
        $soc = $this->createTestSociete(['name' => 'Local customer ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        $invoice = new \Facture($this->db);
        $invoice->socid = (int) $soc->id;
        $invoice->date = dol_now();
        $invoice->type = \Facture::TYPE_STANDARD;
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the local invoice: ' . $invoice->error);
        $this->track('facture', (int) $id);

        return (int) $id;
    }

    private function createProject(string $title): int
    {
        $project = new \Project($this->db);
        $project->ref = 'PJ-' . uniqid();
        $project->title = $title;
        $project->entity = (int) $this->conf->entity;
        $id = $project->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the project: ' . $project->error);
        $this->track('projet', (int) $id);

        return (int) $id;
    }

    private function createLocalOrder(): int
    {
        $soc = $this->createTestSociete(['name' => 'Local order customer ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        $order = new \Commande($this->db);
        $order->socid = (int) $soc->id;
        $order->date_commande = dol_now();
        $order->date = $order->date_commande;
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the local order: ' . $order->error);
        $this->track('commande', (int) $id);

        return (int) $id;
    }

    private function createLocalSupplierOrder(): int
    {
        $soc = $this->createTestSociete(['name' => 'Local supplier ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        $order = new \CommandeFournisseur($this->db);
        $order->socid = (int) $soc->id;
        $order->date_commande = dol_now();
        $order->date = $order->date_commande;
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the supplier order: ' . $order->error);
        $this->track('commande_fournisseur', (int) $id);

        return (int) $id;
    }

    /**
     * An identifier free in EVERY listed table.
     *
     * The polymorphic test needs ONE integer that is a local project and, in
     * another table, another tenant's company. Picking the project's own rowid
     * only worked in isolation: run inside the whole suite, a company already
     * sat there and the setup assertion blew up. Computing a value above every
     * max makes the fixture independent of execution order.
     */
    private function freeRowidAcross(array $tables): int
    {
        $max = 0;
        foreach ($tables as $table) {
            $max = max($max, $this->maxRowid($table));
        }

        return $max + 1000;
    }

    /**
     * Move a row to a chosen rowid, and track the NEW id for cleanup.
     *
     * @return int  the new rowid
     */
    private function relocateRow(string $table, int $from, int $to): int
    {
        $this->assertSame(
            0,
            $this->countRows($table, 'rowid = ' . $to),
            'test setup: llx_' . $table . ' already occupies rowid ' . $to
        );
        if (!$this->db->query('UPDATE ' . MAIN_DB_PREFIX . $table . ' SET rowid = ' . $to . ' WHERE rowid = ' . $from)) {
            $this->fail('could not move llx_' . $table . ' ' . $from . ' to ' . $to . ': ' . $this->db->lasterror());
        }
        $this->track($table, $to);

        return $to;
    }

    private function createAgendaEvent(int $elementId, string $elementType): int
    {
        $event = new \ActionComm($this->db);
        $event->type_code = 'AC_OTH';
        $event->label = 'FK guard event ' . uniqid();
        $event->datep = dol_now();
        $event->datef = dol_now();
        $event->userownerid = (int) $this->testUser->id;
        $event->fk_element = $elementId;
        $event->elementtype = $elementType;
        $id = $event->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the agenda event: ' . $event->error);
        $this->created[] = ['actioncomm', (int) $id];

        // ActionComm::create() rewrites a few element types on the way in
        // (facture -> invoice, ...); assert what really landed so the test is
        // built on the stored pair, not on the one we asked for.
        $this->assertSame(
            $elementType,
            (string) $this->rawColumn('actioncomm', 'elementtype', (int) $id, 'id'),
            'the element type stored differs from the one requested'
        );

        return (int) $id;
    }

    private function createCategory(string $label): int
    {
        $cat = new \Categorie($this->db);
        $cat->label = $label . ' ' . uniqid();
        $cat->type = \Categorie::TYPE_CUSTOMER;
        $cat->entity = (int) $this->conf->entity;
        $id = $cat->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the category: ' . $cat->error);
        $this->track('categorie', (int) $id);

        return (int) $id;
    }

    private function createWarehouse(string $label): int
    {
        $wh = new \Entrepot($this->db);
        $wh->ref = 'WH-' . uniqid();
        $wh->label = $wh->ref;
        $wh->libelle = $wh->ref;
        $wh->description = $label;
        $wh->statut = 1;
        $id = $wh->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the warehouse: ' . $wh->error);
        $this->track('entrepot', (int) $id);

        return (int) $id;
    }

    /**
     * A membership fee of the current tenant. Built through the Dolibarr
     * classes rather than the facade: dmSubscription::canCreate() closes the
     * POST route on purpose (the parent member is not a facade concern).
     */
    private function createLocalSubscription(): int
    {
        $type = new \AdherentType($this->db);
        $type->label = 'FK guard type ' . uniqid();
        $type->libelle = $type->label;
        $type->morphy = 'phy';
        $type->subscription = 0;
        $type->amount = 0;
        $type->caneditamount = 0;
        $type->vote = 0;
        $type->note_public = '';
        $type->note_private = '';
        $type->mail_valid = '';
        $typeId = $type->create($this->testUser);
        $this->assertGreaterThan(0, $typeId, 'could not create the member type: ' . $type->error);
        $this->track('adherent_type', (int) $typeId);

        $member = new \Adherent($this->db);
        $member->lastname = 'FkGuard';
        $member->firstname = 'Local';
        $member->login = 'fkguard' . uniqid();
        $member->pass = 'fkguardPass1!';
        $member->typeid = (int) $typeId;
        $member->morphy = 'phy';
        $member->statut = 1;
        $member->email = 'fkguard' . uniqid() . '@example.test';
        $memberId = $member->create($this->testUser);
        $this->assertGreaterThan(0, $memberId, 'could not create the member: ' . $member->error);
        $this->track('adherent', (int) $memberId);

        $sub = new \Subscription($this->db);
        $sub->fk_adherent = (int) $memberId;
        $sub->fk_type = (int) $typeId;
        $sub->dateh = mktime(0, 0, 0, 1, 1, 2026);
        $sub->datef = mktime(0, 0, 0, 12, 31, 2026);
        $sub->amount = 10.0;
        $subId = $sub->create($this->testUser);
        $this->assertGreaterThan(0, $subId, 'could not create the subscription: ' . $sub->error);
        $this->track('subscription', (int) $subId);

        return (int) $subId;
    }

    /**
     * A raw llx_bank line. Inserted by hand on purpose: what this test needs is
     * a row of that exact table attached to a given account, not the whole
     * Account::addline() ceremony.
     */
    private function createBankLine(int $accountId): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'bank (datec, datev, dateo, amount, label, fk_account, fk_type)'
            . " VALUES ('" . $this->db->idate(dol_now()) . "', '" . $this->db->idate(dol_now()) . "', '"
            . $this->db->idate(dol_now()) . "', 10, 'fk guard line', " . ((int) $accountId) . ", 'VIR')";
        if (!$this->db->query($sql)) {
            $this->fail('could not insert the bank line: ' . $this->db->lasterror());
        }
        $id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'bank');
        $this->assertGreaterThan(0, $id, 'could not read back the bank line id');
        $this->track('bank', $id);

        return $id;
    }

    /**
     * Move an existing row to another entity, which is how a "row belonging to
     * another tenant" is planted without booting a second Dolibarr.
     */
    private function moveToForeignEntity(string $table, int $rowid): void
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $table . ' SET entity = ' . self::FOREIGN_ENTITY
            . ' WHERE rowid = ' . ((int) $rowid);
        if (!$this->db->query($sql)) {
            $this->fail('could not plant the foreign ' . $table . ': ' . $this->db->lasterror());
        }
        $this->assertSame(
            self::FOREIGN_ENTITY,
            (int) $this->rawColumn($table, 'entity', $rowid),
            'the row was not planted in the foreign entity'
        );
    }

    /**
     * Read one column straight from SQL: what matters is what the table holds,
     * not what an object reports after a fetch() that may rename or drop it.
     *
     * @return mixed
     */
    private function rawColumn(string $table, string $column, int $rowid, string $pk = 'rowid')
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = ' . ((int) $rowid)
        );
        if (!$resql) {
            $this->fail('SQL error reading ' . $table . '.' . $column . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? null : $obj->val;
    }

    private function countRows(string $table, string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) as val FROM ' . MAIN_DB_PREFIX . $table;
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->fail('SQL error counting ' . $table . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? 0 : (int) $obj->val;
    }

    private function maxRowid(string $table): int
    {
        $resql = $this->db->query('SELECT MAX(rowid) as val FROM ' . MAIN_DB_PREFIX . $table);
        if (!$resql) {
            $this->fail('SQL error on max(rowid) of ' . $table . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? 0 : (int) $obj->val;
    }
}
