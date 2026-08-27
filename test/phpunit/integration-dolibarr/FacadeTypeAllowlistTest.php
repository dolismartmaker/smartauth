<?php

/**
 * SMARTAUTH_FACADE_TYPES: which registry types this instance exposes.
 *
 * The gap this closes. The registry is global -- 26 built-in types plus the
 * hook's -- and the only granularity was isModEnabled() plus the caller's
 * Dolibarr rights. Installing a module that needs thirdparties therefore opened
 * invoices and members to its token as well, whenever the user held those
 * rights. Not a hole (the rights do apply), but wider than the spec announced.
 * TODO section 9.2.
 *
 * What is deliberately NOT bounded here, and is asserted below so a later reader
 * does not "fix" it: the sync engine, which has its own per-client opt-in, and
 * the per-token / per-OAuth-client scope, which belongs to scopes rather than to
 * a second authorization mechanism.
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
require_once __DIR__ . '/../../../api/ObjectPaymentController.php';
require_once __DIR__ . '/../../../api/SyncController.php';

use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;
use SmartAuth\Api\ObjectPaymentController;
use SmartAuth\Api\SyncController;

/**
 * @covers \SmartAuth\Api\ObjectFacadeTrait
 */
class FacadeTypeAllowlistTest extends DolibarrRealTestCase
{
    /** @var ObjectController */
    private $objects;

    /** @var bool */
    private $hadConstant = false;

    /** @var mixed */
    private $savedConstant;

    /** @var int[] */
    private $createdSocIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new ObjectController();

        $this->hadConstant = isset($this->conf->global->SMARTAUTH_FACADE_TYPES);
        $this->savedConstant = $this->hadConstant ? $this->conf->global->SMARTAUTH_FACADE_TYPES : null;
        $this->createdSocIds = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSocIds as $id) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $id);
        }
        if ($this->hadConstant) {
            $this->conf->global->SMARTAUTH_FACADE_TYPES = $this->savedConstant;
        } else {
            unset($this->conf->global->SMARTAUTH_FACADE_TYPES);
        }
        parent::tearDown();
    }

    private function setAllowlist($value): void
    {
        $this->conf->global->SMARTAUTH_FACADE_TYPES = $value;
    }

    // ------------------------------------------------------- default: open

    /**
     * No constant: every registered type stays served. Narrowing an existing
     * install silently would break its consumers, so the default must not.
     */
    public function testWithoutTheConstantEveryTypeIsServed(): void
    {
        unset($this->conf->global->SMARTAUTH_FACADE_TYPES);

        foreach (['thirdparty', 'product', 'invoice'] as $type) {
            list($body, $code) = $this->objects->index(['objtype' => $type]);
            $this->assertSame(200, $code, $type . ' must be served by default: ' . json_encode($body));
        }
    }

    public function testAnEmptyConstantIsTreatedAsNoRestriction(): void
    {
        $this->setAllowlist('   ');

        list($body, $code) = $this->objects->index(['objtype' => 'product']);
        $this->assertSame(200, $code, 'an empty list must not close anything: ' . json_encode($body));
    }

    // ----------------------------------------------------------- allowlist

    public function testAListedTypeIsStillServed(): void
    {
        $this->setAllowlist('thirdparty,product');

        list($body, $code) = $this->objects->index(['objtype' => 'thirdparty']);
        $this->assertSame(200, $code, 'a listed type must be served: ' . json_encode($body));
    }

    public function testAnUnlistedTypeIsRefused(): void
    {
        $this->setAllowlist('thirdparty');

        list($body, $code) = $this->objects->index(['objtype' => 'invoice']);
        $this->assertSame(400, $code, 'an unlisted type must be refused: ' . json_encode($body));
    }

    /**
     * The refusal must be INDISTINGUISHABLE from that of a type which does not
     * exist: same code, same body. A distinct answer would tell a caller which
     * types exist but are closed -- the existence oracle every refusal of this
     * facade avoids.
     */
    public function testAClosedTypeAnswersExactlyLikeAnUnknownOne(): void
    {
        $this->setAllowlist('thirdparty');

        list($closedBody, $closedCode) = $this->objects->index(['objtype' => 'invoice']);
        list($unknownBody, $unknownCode) = $this->objects->index(['objtype' => 'invoice_that_never_existed']);

        $this->assertSame($unknownCode, $closedCode);
        $this->assertSame(
            str_replace('invoice_that_never_existed', 'invoice', json_encode($unknownBody)),
            json_encode($closedBody),
            'a closed type must not be told apart from an unknown one'
        );
    }

    public function testCaseAndSpacingAreTolerated(): void
    {
        $this->setAllowlist('  Thirdparty , PRODUCT  ');

        list($body, $code) = $this->objects->index(['objtype' => 'thirdparty']);
        $this->assertSame(200, $code, 'casing must not close a type: ' . json_encode($body));

        list($body2, $code2) = $this->objects->index(['objtype' => 'product']);
        $this->assertSame(200, $code2, 'spacing must not close a type: ' . json_encode($body2));
    }

    /**
     * A list that normalises to nothing is a typo, and it closes everything --
     * fail-closed, which is the right verdict for an allowlist, and logged as
     * LOG_ERR so the admin is not left guessing.
     */
    public function testAListNamingNoValidTypeClosesEverything(): void
    {
        $this->setAllowlist(',,,');

        list($body, $code) = $this->objects->index(['objtype' => 'thirdparty']);
        $this->assertSame(400, $code, 'a list naming nothing must close the facade: ' . json_encode($body));
    }

    /**
     * The parsed set is memoised against the raw string, so a change takes
     * effect on the next call with no cache to flush.
     */
    public function testChangingTheConstantTakesEffectImmediately(): void
    {
        $this->setAllowlist('thirdparty');
        list(, $closed) = $this->objects->index(['objtype' => 'product']);
        $this->assertSame(400, $closed);

        $this->setAllowlist('thirdparty,product');
        list($body, $open) = $this->objects->index(['objtype' => 'product']);
        $this->assertSame(200, $open, 'the new value must apply at once: ' . json_encode($body));
    }

    // ------------------------------------------- every door of the facade

    /**
     * The gate sits in resolve(), the single entry point of the four facade
     * controllers, so no route can bypass it.
     */
    public function testTheGateAppliesToEveryFacadeController(): void
    {
        $this->setAllowlist('thirdparty');

        $lines = new ObjectLineController();
        $actions = new ObjectActionController();
        $payments = new ObjectPaymentController();

        $calls = [
            'lines.index'      => $lines->index(['objtype' => 'invoice', 'id' => 1]),
            'lines.store'      => $lines->store(['objtype' => 'invoice', 'id' => 1]),
            'lines.action'     => $lines->invokeAction(['objtype' => 'contract', 'id' => 1, 'lineid' => 1, 'action' => 'activate']),
            'actions.invoke'   => $actions->invoke(['objtype' => 'invoice', 'id' => 1, 'action' => 'validate']),
            'payments.index'   => $payments->index(['objtype' => 'invoice', 'id' => 1]),
            'objects.show'     => $this->objects->show(['objtype' => 'invoice', 'id' => 1]),
            'objects.count'    => $this->objects->count(['objtype' => 'invoice']),
            'objects.columns'  => $this->objects->columns(['objtype' => 'invoice']),
            'objects.describe' => $this->objects->describe(['objtype' => 'invoice']),
            'objects.create'   => $this->objects->create(['objtype' => 'invoice']),
        ];

        foreach ($calls as $label => $result) {
            $this->assertSame(400, $result[1], $label . ' must refuse a closed type: ' . json_encode($result[0]));
        }
    }

    // ------------------------------------------------------ scope of the gate

    /**
     * The sync engine is NOT bounded by this constant: it has its own per-client
     * opt-in (sync_scope, default_enabled, GET /sync/objects), and stacking a
     * second mechanism on top would leave two lists to keep in agreement. This
     * is a deliberate limit, asserted so it is not "fixed" by accident.
     */
    public function testTheSyncEngineIsNotBoundedByTheFacadeAllowlist(): void
    {
        $this->setAllowlist('product');

        $controller = new SyncController();
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('syncableObjects');
        $prop->setAccessible(true);
        $config = $prop->getValue($controller)['thirdparty'];

        $method = $ref->getMethod('processCreate');
        $method->setAccessible(true);
        $result = $method->invokeArgs($controller, [
            $config,
            ['name' => 'Sync Beyond Facade ' . uniqid()],
            $this->testUser,
        ]);

        $this->assertTrue(
            $result['success'] ?? false,
            'the sync door must stay open on a type the facade does not expose: ' . ($result['error'] ?? 'unknown')
        );
        $this->createdSocIds[] = (int) $result['id'];
    }
}
