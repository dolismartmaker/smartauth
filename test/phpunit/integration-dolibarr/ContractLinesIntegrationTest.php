<?php

/**
 * Contract lines through the generic facade: CRUD, line-level workflow actions
 * (activate/close), document actions (validate/close) and the guards that make
 * the line status and the real dates unreachable from a PATCH.
 *
 * A contract line is the subscription grain of the consumer modules: one line
 * per rented item, opened when a lease takes effect and closed when it ends.
 * That lifecycle is NOT a field assignment -- active_line()/close_line() write
 * the status and the real dates AND fire the LINECONTRACT_* triggers -- hence
 * the dedicated line-action route.
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
require_once __DIR__ . '/../../../api/ObjectLineController.php';
require_once __DIR__ . '/../../../api/ObjectActionController.php';
require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;

/**
 * @covers \SmartAuth\Api\ObjectLineController
 * @covers \SmartAuth\Api\DocumentLineInvoker
 * @covers \SmartAuth\Api\DocumentLineActionInvoker
 * @covers \SmartAuth\Api\DocumentActionInvoker
 */
class ContractLinesIntegrationTest extends DolibarrRealTestCase
{
    /** ContratLigne::STATUS_INITIAL. */
    private const LINE_INACTIVE = 0;

    /** ContratLigne::STATUS_OPEN. */
    private const LINE_ACTIVE = 4;

    /** ContratLigne::STATUS_CLOSED. */
    private const LINE_CLOSED = 5;

    /** Entity a planted contract is moved to. Never the harness one. */
    private const FOREIGN_ENTITY = 95;

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
        $this->ensureMysocCountryCode();

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    /**
     * Give the harness company a country, as any real instance has.
     *
     * Contrat::addline() chains into getLocalTaxesFromRate(), which selects
     * from c_tva joined on c_country through $mysoc->country_code, and then
     * dereferences $localtaxes_type[0] with no guard -- unlike Facture and
     * Propal, which default it to ''. The bootstrap leaves country_code empty,
     * so the SELECT matches nothing, the function returns array() and PHP 8
     * raises "Undefined array key 0". Same fix and same reason as
     * MapperRoundTripLotETest::ensureMysocCountryCode().
     *
     * @return void
     */
    private function ensureMysocCountryCode(): void
    {
        global $mysoc;

        if (empty($mysoc->country_code)) {
            $mysoc->country_code = 'FR';
        }
        if (empty($mysoc->country_id)) {
            $mysoc->country_id = 1;
        }
        // get_localtax() reads these two on the seller inside a dol_syslog()
        // format string, with no isset() guard.
        if (!isset($mysoc->localtax1_assuj)) {
            $mysoc->localtax1_assuj = 0;
        }
        if (!isset($mysoc->localtax2_assuj)) {
            $mysoc->localtax2_assuj = 0;
        }
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

    // ------------------------------------------------------------- fixtures

    /** Create a draft contract through the facade; return its id. */
    private function createContract(): int
    {
        $soc = $this->createTestSociete(['name' => 'Contract customer ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->objects->create([
            'objtype'              => 'contract',
            'thirdparty'           => (int) $soc->id,
            'date_contract'        => dol_now(),
            // Contrat::create refuses without these two.
            'commercial_signature' => (int) $this->testUser->id,
            'commercial_followup'  => (int) $this->testUser->id,
        ]);
        $this->assertSame(201, $code, 'contract create failed: ' . json_encode($body));

        $id = (int) $body->id;
        $this->track('contrat', $id);
        return $id;
    }

    /** Add a line and return the re-exported document. */
    private function addLine(int $contractId, array $line)
    {
        $payload = array_merge(['objtype' => 'contract', 'id' => $contractId], $line);
        list($body, $code) = $this->lines->store($payload);
        $this->assertSame(201, $code, 'contract line add failed: ' . json_encode($body));
        return $body;
    }

    /** The single line of a document export. */
    private function onlyLine($body)
    {
        $this->assertIsArray($body->lines, 'the response carries no lines array');
        $this->assertCount(1, $body->lines);
        return $body->lines[0];
    }

    private function rawColumn(string $table, string $column, int $rowid)
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . $table . ' WHERE rowid = ' . $rowid
        );
        if (!$resql) {
            $this->fail('SQL error reading ' . $table . '.' . $column . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? null : $obj->val;
    }

    // ------------------------------------------------------------------ CRUD

    public function testAddLineCarriesPlannedDatesAndRecomputesTotals(): void
    {
        $id = $this->createContract();
        $start = (int) dol_now();
        $end = $start + 86400 * 365;

        $body = $this->addLine($id, [
            'description'         => 'Location bureau A',
            'quantity'            => 2,
            'unit_price_excl_tax' => 150,
            'vat_rate'            => 20,
            'date_start_planned'  => $start,
            'date_end_planned'    => $end,
        ]);

        $line = $this->onlyLine($body);
        $this->assertSame('Location bureau A', $line->description);
        $this->assertEquals(2, $line->quantity);
        $this->assertEquals(150, $line->unit_price_excl_tax);
        $this->assertEquals($start, $line->date_start_planned, 'planned start must survive the round-trip');
        $this->assertEquals($end, $line->date_end_planned, 'planned end must survive the round-trip');
        $this->assertEquals(self::LINE_INACTIVE, $line->status, 'a fresh line is inactive');
        // jdate(null) gives '', which is what an unset date looks like here.
        $this->assertEmpty($line->date_start_real, 'a fresh line has no real start date');

        // The contract carries no total column: fetch_lines() sums them, and the
        // facade loads the lines on every read path so the totals are there.
        $this->assertEquals(300, $body->total_excl_tax, 'contract HT total must reflect the line');
        $this->assertEquals(360, $body->total_incl_tax);
    }

    public function testUpdateAndDeleteLine(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, [
            'description' => 'Location bureau B', 'quantity' => 1, 'unit_price_excl_tax' => 100, 'vat_rate' => 20,
        ]);
        $lineId = (int) $this->onlyLine($body)->id;

        list($upd, $code) = $this->lines->update([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId, 'quantity' => 4,
        ]);
        $this->assertSame(200, $code, 'contract line update failed: ' . json_encode($upd));
        $this->assertEquals(4, $this->onlyLine($upd)->quantity);
        $this->assertEquals(
            100,
            $this->onlyLine($upd)->unit_price_excl_tax,
            'unit price must be preserved on a partial update'
        );
        $this->assertEquals(400, $upd->total_excl_tax);

        list($del, $delCode) = $this->lines->destroy([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
        ]);
        $this->assertSame(200, $delCode, 'contract line delete failed: ' . json_encode($del));
        $this->assertCount(0, $del->lines ?? []);
    }

    /**
     * Contrat::addline stores rang verbatim, unlike the classes that read -1 as
     * "append": a line must not land at position -1 and hoist itself above the
     * existing ones.
     */
    public function testAddedLinesKeepTheirInsertionOrder(): void
    {
        $id = $this->createContract();
        $this->addLine($id, ['description' => 'First', 'quantity' => 1, 'unit_price_excl_tax' => 10]);
        $body = $this->addLine($id, ['description' => 'Second', 'quantity' => 1, 'unit_price_excl_tax' => 20]);

        $this->assertCount(2, $body->lines);
        foreach ($body->lines as $line) {
            $this->assertGreaterThanOrEqual(0, (int) $line->position, 'no line may sit at a negative position');
        }
        $this->assertSame('First', $body->lines[0]->description, 'insertion order must be preserved');
    }

    // ---------------------------------------------------------- line actions

    public function testActivateThenCloseALine(): void
    {
        $id = $this->createContract();
        $plannedEnd = (int) dol_now() + 86400 * 30;
        $body = $this->addLine($id, [
            'description'        => 'Location a activer',
            'quantity'           => 1,
            'unit_price_excl_tax' => 90,
            'date_start_planned' => (int) dol_now(),
            'date_end_planned'   => $plannedEnd,
        ]);
        $lineId = (int) $this->onlyLine($body)->id;
        $realStart = (int) dol_now() - 3600;

        list($act, $code) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
            'action' => 'activate', 'date_start_real' => $realStart, 'comment' => 'Bail signe',
        ]);
        $this->assertSame(200, $code, 'activate failed: ' . json_encode($act));
        $line = $this->onlyLine($act);
        $this->assertEquals(self::LINE_ACTIVE, $line->status, 'an activated line must be at status 4');
        $this->assertEquals($realStart, $line->date_start_real, 'the real start date must be the one asked for');
        $this->assertEquals(
            $plannedEnd,
            $line->date_end_planned,
            'activate without date_end_planned must leave the planned end alone'
        );

        $realEnd = (int) dol_now() + 60;
        list($cls, $clsCode) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
            'action' => 'close', 'date_end_real' => $realEnd, 'comment' => 'Bail resilie',
        ]);
        $this->assertSame(200, $clsCode, 'close failed: ' . json_encode($cls));
        $closed = $this->onlyLine($cls);
        $this->assertEquals(self::LINE_CLOSED, $closed->status, 'a closed line must be at status 5');
        $this->assertEquals($realEnd, $closed->date_end_real, 'the real end date must be the one asked for');
        $this->assertEquals($realStart, $closed->date_start_real, 'closing must not erase the real start date');
    }

    public function testActivateCanReviseThePlannedEndDate(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, [
            'description' => 'Location', 'quantity' => 1, 'unit_price_excl_tax' => 10,
            'date_end_planned' => (int) dol_now() + 86400,
        ]);
        $lineId = (int) $this->onlyLine($body)->id;
        $revised = (int) dol_now() + 86400 * 90;

        list($act, $code) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
            'action' => 'activate', 'date_end_planned' => $revised,
        ]);
        $this->assertSame(200, $code, 'activate failed: ' . json_encode($act));
        $this->assertEquals($revised, $this->onlyLine($act)->date_end_planned);
    }

    /**
     * The status and the real dates are read-only through the CRUD door: they
     * are published by the export, so a client echoing back a line it just read
     * sends them without meaning to.
     */
    public function testPatchCannotWriteStatusNorRealDates(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, [
            'description' => 'Location', 'quantity' => 1, 'unit_price_excl_tax' => 100,
        ]);
        $lineId = (int) $this->onlyLine($body)->id;
        $realStart = (int) dol_now() - 7200;

        list($act, $code) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
            'action' => 'activate', 'date_start_real' => $realStart,
        ]);
        $this->assertSame(200, $code, 'activate failed: ' . json_encode($act));

        list($upd, $updCode) = $this->lines->update([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId,
            'quantity'        => 3,
            'status'          => self::LINE_CLOSED,
            'date_start_real' => 1,
            'date_end_real'   => 1,
        ]);
        $this->assertSame(200, $updCode, 'contract line update failed: ' . json_encode($upd));

        $line = $this->onlyLine($upd);
        $this->assertEquals(3, $line->quantity, 'the writable field must still be applied');
        $this->assertEquals(self::LINE_ACTIVE, $line->status, 'a PATCH must not change the line status');
        $this->assertEquals($realStart, $line->date_start_real, 'a PATCH must not rewrite the real start date');
        $this->assertEmpty($line->date_end_real, 'a PATCH must not set a real end date');

        // Read straight from the table: updateline() rewrites date_ouverture on
        // every call and nulls it when handed an empty value, so "unchanged in
        // the export" must also mean "unchanged in the row".
        $this->assertNotNull(
            $this->rawColumn('contratdet', 'date_ouverture', $lineId),
            'the activation date was erased in database by the update'
        );
    }

    public function testUnknownLineActionIsRefused(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, ['description' => 'x', 'quantity' => 1, 'unit_price_excl_tax' => 1]);
        $lineId = (int) $this->onlyLine($body)->id;

        list($res, $code) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId, 'action' => 'terminate',
        ]);
        $this->assertSame(400, $code, 'an unlisted line action must be refused: ' . json_encode($res));
    }

    public function testLineActionOnATypeWithoutLineActionsIsRefused(): void
    {
        $soc = $this->createTestSociete(['name' => 'Proposal customer ' . uniqid()]);
        $this->track('societe', (int) $soc->id);

        list($body, $code) = $this->objects->create([
            'objtype' => 'proposal', 'thirdparty' => (int) $soc->id, 'date_proposal' => dol_now(),
        ]);
        $this->assertSame(201, $code, 'proposal create failed: ' . json_encode($body));
        $propalId = (int) $body->id;
        $this->track('propal', $propalId);

        list($added, $addCode) = $this->lines->store([
            'objtype' => 'proposal', 'id' => $propalId,
            'description' => 'x', 'quantity' => 1, 'unit_price_excl_tax' => 1,
        ]);
        $this->assertSame(201, $addCode, 'proposal line add failed: ' . json_encode($added));
        $lineId = (int) $added->lines[0]->id;

        list($res, $resCode) = $this->lines->invokeAction([
            'objtype' => 'proposal', 'id' => $propalId, 'lineid' => $lineId, 'action' => 'activate',
        ]);
        $this->assertSame(400, $resCode, 'proposal declares no line_actions: ' . json_encode($res));
    }

    public function testLineActionOnAForeignLineIdIs404(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, ['description' => 'x', 'quantity' => 1, 'unit_price_excl_tax' => 1]);
        $lineId = (int) $this->onlyLine($body)->id;

        list($res, $code) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId + 987654, 'action' => 'activate',
        ]);
        $this->assertSame(404, $code, 'a line of another document must be a 404: ' . json_encode($res));
    }

    public function testLineActionFailsClosedWithoutTheUpdateRight(): void
    {
        global $user;

        $id = $this->createContract();
        $body = $this->addLine($id, ['description' => 'x', 'quantity' => 1, 'unit_price_excl_tax' => 1]);
        $lineId = (int) $this->onlyLine($body)->id;

        $saved = $user->rights->contrat->creer;
        $user->rights->contrat->creer = 0;
        try {
            list($res, $code) = $this->lines->invokeAction([
                'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId, 'action' => 'activate',
            ]);
            $this->assertSame(403, $code, 'a line action must fail closed without creer: ' . json_encode($res));
        } finally {
            $user->rights->contrat->creer = $saved;
        }
    }

    // ------------------------------------------------------ document actions

    public function testValidateAndCloseTheContract(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, [
            'description' => 'Location', 'quantity' => 1, 'unit_price_excl_tax' => 500,
        ]);
        $lineId = (int) $this->onlyLine($body)->id;

        list($val, $code) = $this->actions->invoke([
            'objtype' => 'contract', 'id' => $id, 'action' => 'validate',
        ]);
        $this->assertSame(200, $code, 'contract validate failed: ' . json_encode($val));
        $this->assertEquals(1, $val->status, 'a validated contract must be at status 1');

        list($cls, $clsCode) = $this->actions->invoke([
            'objtype' => 'contract', 'id' => $id, 'action' => 'close', 'close_note' => 'Fin de bail',
        ]);
        $this->assertSame(200, $clsCode, 'contract close failed: ' . json_encode($cls));
        $this->assertEquals(
            self::LINE_CLOSED,
            (int) $this->rawColumn('contratdet', 'statut', $lineId),
            'closeAll must close every line of the contract'
        );
    }

    public function testUnknownDocumentActionIsRefused(): void
    {
        $id = $this->createContract();

        list($res, $code) = $this->actions->invoke([
            'objtype' => 'contract', 'id' => $id, 'action' => 'setpaid',
        ]);
        $this->assertSame(400, $code, 'setpaid is not a contract action: ' . json_encode($res));
    }

    // -------------------------------------------------------- entity scoping

    /**
     * A contract of another tenant must be a 404 on the line routes exactly as
     * on the header -- same code, no existence oracle.
     */
    public function testForeignContractIsInvisibleOnEveryLineRoute(): void
    {
        $id = $this->createContract();
        $body = $this->addLine($id, ['description' => 'x', 'quantity' => 1, 'unit_price_excl_tax' => 1]);
        $lineId = (int) $this->onlyLine($body)->id;

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'contrat SET entity = ' . self::FOREIGN_ENTITY . ' WHERE rowid = ' . $id;
        if (!$this->db->query($sql)) {
            $this->fail('could not plant the foreign contract: ' . $this->db->lasterror());
        }

        list($show, $showCode) = $this->objects->show(['objtype' => 'contract', 'id' => $id]);
        $this->assertSame(404, $showCode, 'the header must already be invisible: ' . json_encode($show));

        list($idx, $idxCode) = $this->lines->index(['objtype' => 'contract', 'id' => $id]);
        $this->assertSame(404, $idxCode, 'listing the lines must be a 404: ' . json_encode($idx));

        list($add, $addCode) = $this->lines->store([
            'objtype' => 'contract', 'id' => $id, 'description' => 'y', 'quantity' => 1,
        ]);
        $this->assertSame(404, $addCode, 'adding a line must be a 404: ' . json_encode($add));

        list($act, $actCode) = $this->lines->invokeAction([
            'objtype' => 'contract', 'id' => $id, 'lineid' => $lineId, 'action' => 'activate',
        ]);
        $this->assertSame(404, $actCode, 'a line action must be a 404: ' . json_encode($act));
        $this->assertEquals(
            self::LINE_INACTIVE,
            (int) $this->rawColumn('contratdet', 'statut', $lineId),
            'the line of a foreign contract was activated'
        );
    }

    // ------------------------------------------------------------- registry

    /**
     * The type stays opt-in: it must be asked for explicitly, never enabled by
     * default on a sync client.
     */
    public function testContractStaysOptIn(): void
    {
        $cfg = \SmartAuth\Api\ObjectRegistry::get('contract');

        $this->assertIsArray($cfg);
        $this->assertFalse($cfg['default_enabled'] ?? true, 'contract must stay opt-in');
        $this->assertTrue($cfg['supports_lines'] ?? false);
        $this->assertSame(['validate', 'close'], $cfg['actions'] ?? []);
        $this->assertSame(['activate', 'close'], $cfg['line_actions'] ?? []);
    }
}
