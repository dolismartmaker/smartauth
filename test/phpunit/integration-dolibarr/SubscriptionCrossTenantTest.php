<?php

/**
 * Tenant isolation of a membership fee on the generic objects/{objtype} facade.
 *
 * llx_subscription has NO entity column, so a fee belongs to a tenant only
 * through its member -- which is what dmSubscription::isolationWhereSql()
 * expresses. That predicate is evaluated on the row AS IT STANDS, before the
 * request body is parsed (ObjectController::update() probes it at l.428,
 * importMappedData runs at l.444, applyImportedFields at l.462). It therefore
 * protects reading, editing and deleting a FOREIGN fee, and protects nothing
 * against MOVING a local one onto a foreign member.
 *
 * While `fk_adherent` was writable, two writes into another tenant's data were
 * reachable by any user holding 'adherent cotisation creer':
 *
 *   1. PATCH objects/subscription/{id} {"member": <victim>} -- Subscription
 *      ::update() rewrites the column from memory (subscription.class.php:278),
 *      then fetches the DESIGNATED member and calls update_end_date()
 *      (:292-293), which UPDATEs llx_adherent.datefin of the victim (adherent
 *      .class.php:1072-1074);
 *   2. POST objects/subscription {"member": <victim>, ...} -- create() inserts
 *      fk_adherent verbatim (:170), filing the fee in the victim's record.
 *
 * The fix removes the field from dmSubscription::$writableFields and from the
 * registry allowed_fields, and closes the create route with canCreate(). This
 * test proves both holes are shut AND that nothing moved in SQL, which is the
 * only assertion that distinguishes "refused" from "refused after writing".
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
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

use ReflectionClass;
use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectRegistry;
use SmartAuth\DolibarrMapping\dmSubscription;

/**
 * @covers \SmartAuth\DolibarrMapping\dmSubscription
 * @covers \SmartAuth\Api\ObjectController
 */
class SubscriptionCrossTenantTest extends DolibarrRealTestCase
{
    /** Entity the planted victim member is moved to. Never the test entity. */
    private const FOREIGN_ENTITY = 99;

    /** @var ObjectController */
    private $controller;

    /** @var array<int,array{0:string,1:int}> table/rowid pairs to delete */
    private $created = [];

    /** @var \Adherent Member of the CURRENT tenant (attacker side) */
    private $localMember;

    /** @var int Fee of the current tenant, the one an attacker tries to move */
    private $localSubscriptionId;

    /** @var \Adherent Member planted in FOREIGN_ENTITY (victim side) */
    private $foreignMember;

    /** @var string|null Raw llx_adherent.datefin of the victim before the attack */
    private $foreignDatefinBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ObjectController();
        $this->created = [];

        $this->localMember = $this->createMember('local');
        $this->localSubscriptionId = $this->createSubscription($this->localMember);

        $this->foreignMember = $this->createMember('foreign');
        $this->moveToForeignEntity((int) $this->foreignMember->id);
        $this->foreignDatefinBefore = $this->rawColumn('adherent', 'datefin', (int) $this->foreignMember->id);

        // Sanity check: without a stored end date on the victim, "datefin did
        // not change" would be satisfied by a null both before and after, and
        // the assertion would prove nothing.
        $this->assertNotNull(
            $this->foreignDatefinBefore,
            'test setup: the victim member must carry a sentinel datefin'
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
     * The two closed holes
     * --------------------------------------------------------------- */

    /**
     * Hole 1: re-parenting a local fee onto a member of another tenant.
     *
     * The refusal is now a 400 raised by the mapper allowlist (dmTrait
     * l.491-500) rather than a silent acceptance, and -- the point of the test
     * -- the database is untouched on both sides of the boundary.
     */
    public function testFacadeUpdateCannotMoveALocalFeeOntoAForeignMember(): void
    {
        list($body, $code) = $this->controller->update([
            'objtype' => 'subscription',
            'id'      => $this->localSubscriptionId,
            'member'  => (int) $this->foreignMember->id,
        ]);

        $this->assertSame(400, $code, 'moving a fee across tenants must be refused: ' . json_encode($body));
        $this->assertIsArray($body);
        $this->assertArrayHasKey('errors', $body, 'expected a mapper validation error payload');
        $this->assertArrayHasKey(
            'member',
            $body['errors'],
            'the refusal must name the offending field: ' . json_encode($body)
        );

        $this->assertFeeStillBelongsToTheLocalMember();
        $this->assertVictimEndDateUntouched();
    }

    /**
     * Same attack sent with the raw Dolibarr-side key instead of the published
     * one. importMappedData() keys its reverse map on the API side, so
     * 'fk_adherent' was never accepted; asserted so a future rename of the
     * published key cannot reopen the hole through the back door.
     */
    public function testFacadeUpdateRejectsTheRawColumnNameToo(): void
    {
        list($body, $code) = $this->controller->update([
            'objtype'     => 'subscription',
            'id'          => $this->localSubscriptionId,
            'fk_adherent' => (int) $this->foreignMember->id,
        ]);

        $this->assertSame(400, $code, 'raw column name must be refused too: ' . json_encode($body));
        $this->assertArrayHasKey('fk_adherent', $body['errors']);

        $this->assertFeeStillBelongsToTheLocalMember();
        $this->assertVictimEndDateUntouched();
    }

    /**
     * Hole 2: filing a brand new fee directly in the victim's record.
     *
     * Two requests, because the route is closed in two places: the payload key
     * is refused by the allowlist (400), and the route itself is refused by
     * canCreate() (403) so that a parentless fee is never inserted -- an orphan
     * row would answer 201 and then stay invisible for good, the isolation
     * predicate having no llx_adherent with rowid 0 to match.
     */
    public function testFacadeCreateCannotFileAFeeOnAForeignMember(): void
    {
        $rowsBefore = $this->subscriptionRowCount();

        list($body, $code) = $this->controller->create([
            'objtype'    => 'subscription',
            'member'     => (int) $this->foreignMember->id,
            'date_start' => mktime(0, 0, 0, 1, 1, 2026),
            'date_end'   => mktime(0, 0, 0, 12, 31, 2026),
            'amount'     => 42.0,
        ]);

        $this->assertSame(400, $code, 'creating a fee on a foreign member must be refused: ' . json_encode($body));
        $this->assertArrayHasKey('member', $body['errors']);

        list($body2, $code2) = $this->controller->create([
            'objtype'    => 'subscription',
            'date_start' => mktime(0, 0, 0, 1, 1, 2026),
            'date_end'   => mktime(0, 0, 0, 12, 31, 2026),
            'amount'     => 42.0,
        ]);

        $this->assertSame(
            403,
            $code2,
            'a parentless fee must be refused, not inserted as an orphan: ' . json_encode($body2)
        );

        $this->assertSame(
            0,
            $this->subscriptionRowCountForMember((int) $this->foreignMember->id),
            'a fee was filed in the foreign member record'
        );
        $this->assertSame(
            0,
            $this->subscriptionRowCountForMember(0),
            'an orphan fee (fk_adherent = 0) was inserted and is now unreachable'
        );
        $this->assertSame(
            $rowsBefore,
            $this->subscriptionRowCount(),
            'llx_subscription gained a row while both create attempts were refused'
        );
        $this->assertVictimEndDateUntouched();
    }

    /* -----------------------------------------------------------------
     * The contract that makes the closure permanent
     * --------------------------------------------------------------- */

    /**
     * The parent must be unwritable in BOTH declarations. The registry list is
     * not decorative: it is the sync-side write allowlist
     * (SyncController::applyDataLegacy l.1416), a second write path onto the
     * same column.
     */
    public function testTheParentIsWritableNowhereInTheWriteContract(): void
    {
        $writable = $this->writableFields();
        $this->assertNotContains(
            'fk_adherent',
            $writable,
            'fk_adherent is writable again: the facade can move a fee across tenants'
        );

        $builtins = ObjectRegistry::builtins();
        $this->assertArrayHasKey('subscription', $builtins);
        $allowed = $builtins['subscription']['allowed_fields'];
        $this->assertNotContains(
            'fk_adherent',
            $allowed,
            'fk_adherent is allowed again on the sync write path'
        );

        sort($writable);
        sort($allowed);
        $this->assertSame($writable, $allowed, 'the registry entry must keep mirroring the mapper allowlist');
    }

    /**
     * The parent stays READABLE: removing it from the write contract must not
     * take it out of the published mapping, or every fee list would lose the
     * column that says whose fee it is.
     */
    public function testTheParentIsStillPublishedForReading(): void
    {
        $prop = (new ReflectionClass(dmSubscription::class))->getProperty('listOfPublishedFields');
        $prop->setAccessible(true);
        $published = (array) $prop->getValue(new dmSubscription());

        $this->assertArrayHasKey('fk_adherent', $published);
        $this->assertSame('member', $published['fk_adherent']);

        list($body, $code) = $this->controller->show([
            'objtype' => 'subscription',
            'id'      => $this->localSubscriptionId,
        ]);
        $this->assertSame(200, $code, 'reading a local fee must still work: ' . json_encode($body));
        $this->assertSame((int) $this->localMember->id, (int) $body->member);
    }

    /**
     * Non-regression: the fields that remain writable still are, and a normal
     * edit still lets Subscription::update() recompute the end date of ITS OWN
     * member -- which is the legitimate half of the behaviour the fix had to
     * preserve while cutting the cross-tenant half.
     */
    public function testAnOrdinaryEditStillWorksAndStillUpdatesItsOwnMember(): void
    {
        list($body, $code) = $this->controller->update([
            'objtype' => 'subscription',
            'id'      => $this->localSubscriptionId,
            'amount'  => 77.0,
            'note'    => 'ordinary edit',
        ]);

        $this->assertSame(200, $code, 'an ordinary edit must still be accepted: ' . json_encode($body));
        $this->assertSame(77.0, (float) $this->rawColumn('subscription', 'subscription', $this->localSubscriptionId));

        $this->assertFeeStillBelongsToTheLocalMember();
        $this->assertVictimEndDateUntouched();

        // The fee end date is 31/12/2026; update_end_date() copies it onto the
        // OWN member, proving the core path really ran instead of being
        // short-circuited by the fix.
        $ownDatefin = (string) $this->rawColumn('adherent', 'datefin', (int) $this->localMember->id);
        $this->assertStringContainsString(
            '2026-12-31',
            $ownDatefin,
            'the local member end date was not recomputed: ' . $ownDatefin
        );
    }

    /* -----------------------------------------------------------------
     * Assertions and fixtures
     * --------------------------------------------------------------- */

    private function assertFeeStillBelongsToTheLocalMember(): void
    {
        $this->assertSame(
            (int) $this->localMember->id,
            (int) $this->rawColumn('subscription', 'fk_adherent', $this->localSubscriptionId),
            'the fee was re-parented in SQL despite the refusal'
        );
    }

    private function assertVictimEndDateUntouched(): void
    {
        $this->assertSame(
            $this->foreignDatefinBefore,
            $this->rawColumn('adherent', 'datefin', (int) $this->foreignMember->id),
            'the end date of the foreign member was recomputed: a write crossed the tenant boundary'
        );
    }

    /**
     * @return string[]
     */
    private function writableFields(): array
    {
        $prop = (new ReflectionClass(dmSubscription::class))->getProperty('writableFields');
        $prop->setAccessible(true);

        return (array) $prop->getValue(new dmSubscription());
    }

    private function subscriptionRowCount(): int
    {
        return $this->scalar('SELECT COUNT(*) as val FROM ' . MAIN_DB_PREFIX . 'subscription');
    }

    private function subscriptionRowCountForMember(int $memberId): int
    {
        return $this->scalar(
            'SELECT COUNT(*) as val FROM ' . MAIN_DB_PREFIX . 'subscription'
            . ' WHERE fk_adherent = ' . ((int) $memberId)
        );
    }

    private function scalar(string $sql): int
    {
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->fail('SQL error: ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? 0 : (int) $obj->val;
    }

    /**
     * Read one column straight from SQL, bypassing the fetch() renames: what
     * matters here is what the table holds, not what an object reports.
     *
     * @return mixed
     */
    private function rawColumn(string $table, string $column, int $rowid)
    {
        $resql = $this->db->query(
            'SELECT ' . $column . ' as val FROM ' . MAIN_DB_PREFIX . $table
            . ' WHERE rowid = ' . ((int) $rowid)
        );
        if (!$resql) {
            $this->fail('SQL error reading ' . $table . '.' . $column . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? null : $obj->val;
    }

    /**
     * Plant a member in another entity, with a sentinel end date so that a
     * cross-tenant update_end_date() would be visible in SQL.
     */
    private function moveToForeignEntity(int $memberId): void
    {
        $sentinel = $this->db->idate(mktime(0, 0, 0, 6, 15, 2020));
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'adherent'
            . " SET entity = " . self::FOREIGN_ENTITY . ", datefin = '" . $this->db->escape($sentinel) . "'"
            . ' WHERE rowid = ' . ((int) $memberId);

        if (!$this->db->query($sql)) {
            $this->fail('could not plant the foreign member: ' . $this->db->lasterror());
        }

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    /**
     * llx_adherent_type ships EMPTY, and Adherent::create() writes
     * fk_adherent_type unconditionally: a type has to be seeded first.
     * AdherentType::create() inserts only morphy/libelle/entity then pushes the
     * rest through an immediate update(), so every scalar it reads must be
     * pre-populated or it interpolates empty strings into number columns.
     */
    private function createMemberType(): \AdherentType
    {
        $type = new \AdherentType($this->db);
        $type->label = 'SubscriptionCrossTenantTest type ' . uniqid();
        $type->morphy = 'phy';
        $type->status = 1;
        $type->subscription = 1;
        $type->amount = 10.0;
        $type->caneditamount = 0;
        $type->vote = 0;
        $type->duration_value = '1';
        $type->duration_unit = 'y';
        $type->mail_valid = '';
        $type->note_public = '';

        $id = $type->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create AdherentType: ' . $type->error);
        $this->created[] = ['adherent_type', (int) $id];

        return $type;
    }

    private function createMember(string $tag): \Adherent
    {
        $type = $this->createMemberType();

        $member = new \Adherent($this->db);
        $member->login = 'xtenant_' . $tag . '_' . uniqid();
        $member->lastname = 'Doe';
        $member->firstname = ucfirst($tag);
        $member->email = 'xtenant_' . $tag . '_' . uniqid() . '@example.com';
        $member->morphy = 'phy';
        $member->typeid = (int) $type->id;
        $member->public = 0;

        $id = $member->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create Adherent: ' . $member->error);
        $this->created[] = ['adherent', (int) $id];

        return $member;
    }

    private function createSubscription(\Adherent $member): int
    {
        $subscription = new \Subscription($this->db);
        $subscription->fk_adherent = (int) $member->id;
        $subscription->fk_type = (int) $member->typeid;
        $subscription->dateh = mktime(0, 0, 0, 1, 1, 2026);
        $subscription->datef = mktime(0, 0, 0, 12, 31, 2026);
        $subscription->amount = 30.0;
        $subscription->note_public = 'cross tenant fixture';

        $id = $subscription->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create subscription: ' . $subscription->error);
        $this->created[] = ['subscription', (int) $id];

        return (int) $id;
    }
}
