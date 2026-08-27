<?php

/**
 * Changing an agenda event's type through the facade must keep the NUMERIC type.
 *
 * dmAgendaEvent::applyImportedFields() resets $object->type_id to 0 whenever the
 * payload carries type_code, so the core re-resolves the id from the code. That
 * is right on CREATE and wrong on UPDATE:
 *
 *   - ActionComm::create() (actioncomm.class.php l.493-511) really does resolve
 *     it: "if (!$this->type_id || !$this->type_code) { ... $cactioncomm->fetch(
 *     $key); $this->type_id = $cactioncomm->id; }";
 *   - ActionComm::update() has NO such branch. It only walks the other way,
 *     filling type_code FROM type_id (l.1161-1169, and only "if ($this->type_id
 *     > 0)"), then persists "fk_action = ".(int) $this->type_id (l.1181).
 *
 * So a PATCH of the type wrote fk_action = 0: the event kept the right `code`
 * column and lost its numeric type. Every screen and query joining
 * llx_c_actioncomm on fk_action then shows nothing.
 *
 * The mapper's own comment cited ":1162" as the place where update() re-resolves
 * the id. It does not; that line is the id -> code direction.
 *
 * WHAT ACTUALLY REFUSES / REPAIRS: dmAgendaEvent::applyImportedFields(). This
 * test was written BEFORE the fix and observed red (fk_action = 0), which is the
 * only way to know it tests the fix and not something else.
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
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/cactioncomm.class.php';

use SmartAuth\Api\ObjectController;

/**
 * @covers \SmartAuth\DolibarrMapping\dmAgendaEvent
 * @covers \SmartAuth\Api\ObjectController
 */
class AgendaEventTypeUpdateTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ObjectController();
    }

    public function testPatchingTheTypeCodeKeepsTheNumericType(): void
    {
        list($fromCode, $fromId) = $this->anActionType(0);
        list($toCode, $toId) = $this->anActionType(1);
        $this->assertNotSame($fromCode, $toCode, 'test setup: two distinct action types are needed');

        $eventId = $this->createEvent($fromCode);
        $this->assertSame(
            $fromId,
            (int) $this->rawColumn('fk_action', $eventId),
            'test setup: the event must start with a resolved numeric type'
        );

        list($body, $code) = $this->controller->update([
            'objtype'   => 'agenda_event',
            'id'        => $eventId,
            'type_code' => $toCode,
        ]);

        $this->assertSame(200, $code, 'changing the type must be accepted: ' . json_encode($body));
        $this->assertSame(
            $toCode,
            (string) $this->rawColumn('code', $eventId),
            'the code column did not follow'
        );
        $this->assertSame(
            $toId,
            (int) $this->rawColumn('fk_action', $eventId),
            'fk_action was zeroed: the event lost its numeric type'
        );
    }

    /**
     * An unknown code must not silently wipe the stored type either. Refusing to
     * guess is the point: writing 0 would be the same corruption by another
     * route.
     */
    public function testPatchingAnUnknownTypeCodeLeavesTheNumericTypeAlone(): void
    {
        list($fromCode, $fromId) = $this->anActionType(0);
        $eventId = $this->createEvent($fromCode);

        $this->controller->update([
            'objtype'   => 'agenda_event',
            'id'        => $eventId,
            'type_code' => 'AC_DOES_NOT_EXIST',
        ]);

        $this->assertSame(
            $fromId,
            (int) $this->rawColumn('fk_action', $eventId),
            'an unknown code wiped the numeric type'
        );
    }

    /**
     * Non-regression on the CREATE side, where zeroing type_id is REQUIRED:
     * ActionComm::create() only resolves the id when it is empty.
     */
    public function testCreatingWithATypeCodeStillResolvesTheNumericType(): void
    {
        list($code, $id) = $this->anActionType(1);

        list($body, $httpCode) = $this->controller->create([
            'objtype'    => 'agenda_event',
            'label'      => 'Type resolution on create',
            'type_code'  => $code,
            'date_start' => dol_now(),
            'date_end'   => dol_now(),
        ]);

        $this->assertSame(201, $httpCode, 'creating an event must still work: ' . json_encode($body));
        $newId = (int) $body->id;
        $this->assertSame(
            $id,
            (int) $this->rawColumn('fk_action', $newId),
            'create() no longer resolves the numeric type from the code'
        );
    }

    /* -----------------------------------------------------------------
     * fixtures
     * --------------------------------------------------------------- */

    /**
     * The Nth usable action type of the dictionary, seeding two if the schema
     * ships none (the harness database is minimal).
     *
     * @return array{0:string,1:int}  [code, id]
     */
    private function anActionType(int $offset): array
    {
        $rows = [];
        $resql = $this->db->query(
            'SELECT id, code FROM ' . MAIN_DB_PREFIX . 'c_actioncomm ORDER BY id ASC'
        );
        if ($resql) {
            while ($row = $this->db->fetch_object($resql)) {
                $rows[] = [(string) $row->code, (int) $row->id];
            }
            $this->db->free($resql);
        }

        if (count($rows) < 2) {
            foreach ([[91, 'AC_FKG_A'], [92, 'AC_FKG_B']] as $seed) {
                $this->db->query(
                    'INSERT INTO ' . MAIN_DB_PREFIX . 'c_actioncomm (id, code, type, libelle, active)'
                    . " VALUES (" . $seed[0] . ", '" . $seed[1] . "', 'system', '" . $seed[1] . "', 1)"
                );
            }
            $rows = [['AC_FKG_A', 91], ['AC_FKG_B', 92]];
        }

        $this->assertArrayHasKey($offset, $rows, 'the action type dictionary is too small');

        return $rows[$offset];
    }

    private function createEvent(string $typeCode): int
    {
        $event = new \ActionComm($this->db);
        $event->type_code = $typeCode;
        $event->label = 'Type update test ' . uniqid();
        $event->datep = dol_now();
        $event->datef = dol_now();
        $event->userownerid = (int) $this->testUser->id;
        $id = $event->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the event: ' . $event->error);

        return (int) $id;
    }

    /**
     * llx_actioncomm keys on `id`, not `rowid`.
     *
     * @return mixed
     */
    private function rawColumn(string $column, int $eventId)
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . 'actioncomm WHERE id = ' . $eventId
        );
        if (!$resql) {
            $this->fail('SQL error reading actioncomm.' . $column . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? null : $obj->val;
    }
}
