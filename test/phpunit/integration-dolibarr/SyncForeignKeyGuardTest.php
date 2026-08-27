<?php

/**
 * The tenant guard on written VALUES must hold on the SECOND door too.
 *
 * POST sync/push writes the very same core objects, through the very same dm*
 * mappers, as PATCH objects/{objtype}/{id}. Closing the foreign-key hole on the
 * synchronous facade alone would have left an equivalent one wide open here.
 *
 * What sync ALREADY had, and what it did not:
 *   - processUpdate() refuses to touch a row belonging to another entity
 *     (SyncController::isEntityAllowed) -- the row being MODIFIED is scoped;
 *   - $fkValidationMap validated a few foreign keys, but is keyed on SQL COLUMN
 *     names (fk_soc, fk_project) while the mappers write PHP PROPERTY names, so
 *     `socid` -- the key that spans 14 types -- was never validated by it. The
 *     pre-existing testPushCreateRejectsForeignKeyToMissingTarget only covers
 *     `country` (fk_pays), a name that happens to match the map.
 *
 * So a push could point a local invoice at another tenant's company, exactly as
 * a PATCH could before ForeignKeyGuardTrait existed. SyncController now composes
 * that same trait; there is ONE implementation of the frontier, not two.
 *
 * WHAT ACTUALLY REFUSES HERE: ForeignKeyGuardTrait::foreignKeyViolation(),
 * called from SyncController::applyDataViaMapper(). Falsification RUN: removing
 * that call makes both tests below fail, llx_facture.fk_soc really holding the
 * foreign company and the injected assignment really landing.
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

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

use SmartAuth\Api\SyncController;

/**
 * @covers \SmartAuth\Api\ForeignKeyGuardTrait
 * @covers \SmartAuth\Api\SyncController
 */
class SyncForeignKeyGuardTest extends DolibarrRealTestCase
{
    /** Entity the planted victim rows are moved to. Never the harness entity. */
    private const FOREIGN_ENTITY = 96;

    /** @var SyncController */
    private $controller;

    /** @var string */
    private $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();

        global $user;
        $user = $this->testUser;

        $this->controller = new SyncController();
        $this->clientUuid = $this->uuid();
        $this->registerClient();

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    public function testPushUpdateCannotPointAnInvoiceAtAForeignThirdparty(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $ownerBefore = (int) $this->rawColumn('facture', 'fk_soc', $invoiceId);

        $foreignSoc = $this->createTestSociete(['name' => 'Sync foreign customer']);
        $this->moveToForeignEntity('societe', (int) $foreignSoc->id);

        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'invoice',
            'changes'     => [
                [
                    'action' => 'update',
                    'id'     => $invoiceId,
                    'data'   => ['thirdparty' => (int) $foreignSoc->id],
                ],
            ],
        ]);

        $this->assertSame(200, $result[1], 'the push envelope itself still answers 200');
        $this->assertSame(
            $ownerBefore,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'sync/push re-parented the invoice onto another tenant company'
        );
    }

    /**
     * Same narrowing to an integer as on the facade: the value written must be
     * the value probed. `socid` leaves importMappedData() as a STRING (it is a
     * PHP property name, absent from Facture::$fields, which declares fk_soc),
     * and Facture::update() interpolates it at l.2542 through escape() -- which
     * neither quotes nor casts, so a comma and an equals sign go straight
     * through.
     *
     * The smuggled column is `entity` ON PURPOSE. A first version injected
     * fk_statut and was green with the guard REMOVED: Facture::update() assigns
     * fk_statut again at l.2558, later in the same SET, so the injected value
     * was simply overwritten and the test proved nothing. `entity` appears
     * nowhere in that SET list, so an injected assignment sticks -- and moving
     * a row across tenants is the worst outcome available here.
     */
    public function testPushUpdateNarrowsAGuardedKeyToAnInteger(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $entityBefore = (int) $this->rawColumn('facture', 'entity', $invoiceId);
        $otherLocal = $this->createTestSociete(['name' => 'Sync local customer 2']);

        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'invoice',
            'changes'     => [
                [
                    'action' => 'update',
                    'id'     => $invoiceId,
                    'data'   => ['thirdparty' => $otherLocal->id . ', entity=' . self::FOREIGN_ENTITY],
                ],
            ],
        ]);

        $this->assertSame(200, $result[1]);
        $this->assertSame(
            (int) $otherLocal->id,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'the legitimate part of the value did not reach the database'
        );
        $this->assertSame(
            $entityBefore,
            (int) $this->rawColumn('facture', 'entity', $invoiceId),
            'a second assignment was smuggled into the UPDATE through sync/push: the invoice changed tenant'
        );
    }

    /**
     * Non-regression: the legitimate push must keep working. A guard refusing
     * these would break offline sync for every consumer.
     */
    public function testPushUpdateStillMovesAnInvoiceToAnotherLocalThirdparty(): void
    {
        $invoiceId = $this->createLocalInvoice();
        $otherLocal = $this->createTestSociete(['name' => 'Sync local customer']);

        $result = $this->controller->push([
            'user_id'     => $this->testUser->id,
            'client_uuid' => $this->clientUuid,
            'object_type' => 'invoice',
            'changes'     => [
                [
                    'action' => 'update',
                    'id'     => $invoiceId,
                    'data'   => ['thirdparty' => (int) $otherLocal->id],
                ],
            ],
        ]);

        $this->assertSame(200, $result[1]);
        $this->assertSame(
            (int) $otherLocal->id,
            (int) $this->rawColumn('facture', 'fk_soc', $invoiceId),
            'a same-entity thirdparty must stay writable through sync/push'
        );
    }

    /* -----------------------------------------------------------------
     * fixtures
     * --------------------------------------------------------------- */

    private function registerClient(): void
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_devices'
            . ' (ref, fk_user_creat, uuid, label, date_creation, status, entity) VALUES ('
            . "'SYNC-FK-" . uniqid() . "', " . ((int) $this->testUser->id) . ", '"
            . $this->db->escape($this->uuid()) . "', 'Sync FK guard device', '"
            . $this->db->idate(time()) . "', 1, 1)";
        if (!$this->db->query($sql)) {
            $this->fail('could not create the sync device: ' . $this->db->lasterror());
        }
        $deviceId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');

        $result = $this->controller->register([
            'user_id'       => $this->testUser->id,
            'client_uuid'   => $this->clientUuid,
            'jwt_device_id' => $deviceId,
            'app_version'   => '1.0.0',
        ]);
        $this->assertSame(200, $result[1], 'could not register the sync client');
    }

    private function createLocalInvoice(): int
    {
        $soc = $this->createTestSociete(['name' => 'Sync local customer ' . uniqid()]);

        $invoice = new \Facture($this->db);
        $invoice->socid = (int) $soc->id;
        $invoice->date = dol_now();
        $invoice->type = \Facture::TYPE_STANDARD;
        $id = $invoice->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the invoice: ' . $invoice->error);

        return (int) $id;
    }

    private function moveToForeignEntity(string $table, int $rowid): void
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $table . ' SET entity = ' . self::FOREIGN_ENTITY
            . ' WHERE rowid = ' . $rowid;
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
     * Read one column straight from SQL: what matters is what the table holds.
     *
     * @return mixed
     */
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

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
