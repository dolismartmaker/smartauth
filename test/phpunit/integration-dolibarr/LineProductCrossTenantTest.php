<?php

/**
 * A document line may not point at another tenant's product.
 *
 * This one is a DISCLOSURE, not merely a wrong reference -- the distinction the
 * foreign-key chantier was careful to make elsewhere. ObjectLineController::
 * hydrateFromProduct() copies the target product's description, label, PRICE
 * and VAT rate onto the line, and the route answers with that line. So a caller
 * holding the write right on any document could read another tenant's price
 * list one rowid at a time, without ever touching that tenant's documents.
 *
 * The core cannot stop it: Product::fetch() drops its entity clause as soon as
 * it is given a rowid, exactly like Facture, Propal and Commande, and
 * Commande::addline() applies no entity test of its own.
 *
 * WHAT ACTUALLY REFUSES: ObjectLineController::productGuardError(), which
 * delegates to the shared ForeignKeyGuardTrait::foreignKeyTargetDenies().
 * FALSIFICATION RUN: neutralising that call makes the first test below answer
 * 201 and return a line carrying the foreign product's label and price.
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

require_once __DIR__ . '/../../../api/ObjectLineController.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

use SmartAuth\Api\ObjectLineController;

/**
 * @covers \SmartAuth\Api\ObjectLineController
 * @covers \SmartAuth\Api\ForeignKeyGuardTrait
 */
class LineProductCrossTenantTest extends DolibarrRealTestCase
{
    /** Entity the planted victim product is moved to. Never the harness one. */
    private const FOREIGN_ENTITY = 95;

    /** The victim's price, which must never surface in a local line. */
    private const SECRET_PRICE = 4242.0;

    /** @var ObjectLineController */
    private $lines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lines = new ObjectLineController();

        $this->assertNotSame(
            self::FOREIGN_ENTITY,
            (int) ($this->conf->entity ?? 1),
            'test setup: the harness entity must differ from the planted one'
        );
    }

    public function testAddingALineCannotReadAForeignProduct(): void
    {
        $orderId = $this->createLocalOrder();
        $foreignProductId = $this->createForeignProduct();
        $linesBefore = $this->countRows('commandedet', 'fk_commande = ' . $orderId);

        list($body, $code) = $this->lines->store([
            'objtype'    => 'order',
            'id'         => $orderId,
            'product'    => $foreignProductId,
            'qty'        => 1,
        ]);

        $this->assertSame(404, $code, 'a foreign product must be refused: ' . json_encode($body));
        $this->assertStringNotContainsString(
            'SECRET-PRODUCT',
            json_encode($body),
            "the foreign product's label leaked into the response"
        );
        $this->assertSame(
            $linesBefore,
            $this->countRows('commandedet', 'fk_commande = ' . $orderId),
            'a line was created despite the refusal'
        );
        $this->assertSame(
            0,
            $this->countRows('commandedet', 'fk_product = ' . $foreignProductId),
            'a line pointing at the foreign product was written'
        );
    }

    public function testUpdatingALineCannotRetargetItAtAForeignProduct(): void
    {
        $orderId = $this->createLocalOrder();
        $localProductId = $this->createLocalProduct();
        $foreignProductId = $this->createForeignProduct();

        list($created, $code) = $this->lines->store([
            'objtype'    => 'order',
            'id'         => $orderId,
            'product'    => $localProductId,
            'qty'        => 1,
        ]);
        $this->assertSame(201, $code, 'a local product must stay addable: ' . json_encode($created));
        $lineId = $this->firstLineId($orderId);

        list($body, $code) = $this->lines->update([
            'objtype'    => 'order',
            'id'         => $orderId,
            'lineid'     => $lineId,
            'product'    => $foreignProductId,
        ]);

        $this->assertSame(404, $code, 'retargeting at a foreign product must be refused: ' . json_encode($body));
        $this->assertSame(
            $localProductId,
            (int) $this->rawColumn('commandedet', 'fk_product', $lineId),
            'the line was retargeted in SQL despite the refusal'
        );
    }

    /**
     * Non-regression: the legitimate path must keep working, hydration
     * included -- refusing it would break every consumer adding a line from
     * the product catalog.
     */
    public function testAddingALineFromALocalProductStillHydratesIt(): void
    {
        $orderId = $this->createLocalOrder();
        $localProductId = $this->createLocalProduct();

        list($body, $code) = $this->lines->store([
            'objtype'    => 'order',
            'id'         => $orderId,
            'product'    => $localProductId,
            'qty'        => 2,
        ]);

        $this->assertSame(201, $code, 'a local product must stay addable: ' . json_encode($body));
        $lineId = $this->firstLineId($orderId);
        $this->assertSame(
            $localProductId,
            (int) $this->rawColumn('commandedet', 'fk_product', $lineId),
            'the legitimate line did not reach the database'
        );
        // Hydration really ran: the label was copied from the product.
        $this->assertStringContainsString(
            'LOCAL-PRODUCT',
            (string) $this->rawColumn('commandedet', 'label', $lineId),
            'the local product was not hydrated onto the line'
        );
    }

    /**
     * A free-text line carries no product at all; the guard must not stand in
     * its way (value <= 0 is never a violation).
     */
    public function testAFreeTextLineIsStillAccepted(): void
    {
        $orderId = $this->createLocalOrder();

        list($body, $code) = $this->lines->store([
            'objtype'  => 'order',
            'id'       => $orderId,
            'desc'     => 'Free text line',
            'qty'      => 1,
            'subprice' => 10.0,
        ]);

        $this->assertSame(201, $code, 'a free-text line must stay addable: ' . json_encode($body));
        $this->assertSame(1, $this->countRows('commandedet', 'fk_commande = ' . $orderId));
    }

    /* -----------------------------------------------------------------
     * fixtures
     * --------------------------------------------------------------- */

    private function createLocalOrder(): int
    {
        $soc = $this->createTestSociete(['name' => 'Line guard customer ' . uniqid()]);

        $order = new \Commande($this->db);
        $order->socid = (int) $soc->id;
        $order->date_commande = dol_now();
        $order->date = $order->date_commande;
        $id = $order->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the order: ' . $order->error);

        return (int) $id;
    }

    private function createLocalProduct(): int
    {
        return $this->createProduct('LOCAL-PRODUCT-' . uniqid(), 100.0);
    }

    private function createForeignProduct(): int
    {
        $id = $this->createProduct('SECRET-PRODUCT-' . uniqid(), self::SECRET_PRICE);
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'product SET entity = ' . self::FOREIGN_ENTITY
            . ' WHERE rowid = ' . $id;
        if (!$this->db->query($sql)) {
            $this->fail('could not plant the foreign product: ' . $this->db->lasterror());
        }
        $this->assertSame(
            self::FOREIGN_ENTITY,
            (int) $this->rawColumn('product', 'entity', $id),
            'the product was not planted in the foreign entity'
        );

        return $id;
    }

    private function createProduct(string $ref, float $price): int
    {
        $product = new \Product($this->db);
        $product->ref = $ref;
        $product->label = $ref;
        $product->price = $price;
        $product->price_base_type = 'HT';
        $product->tva_tx = 20.0;
        $product->type = 0;
        $product->status = 1;
        $product->status_buy = 1;
        $id = $product->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'could not create the product: ' . $product->error);

        return (int) $id;
    }

    private function firstLineId(int $orderId): int
    {
        $resql = $this->db->query(
            'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'commandedet WHERE fk_commande = ' . $orderId
            . ' ORDER BY rowid ASC'
        );
        if (!$resql) {
            $this->fail('SQL error reading the order lines: ' . $this->db->lasterror());
        }
        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $this->assertNotNull($row, 'the order carries no line');

        return (int) $row->rowid;
    }

    /**
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

    private function countRows(string $table, string $where): int
    {
        $resql = $this->db->query('SELECT COUNT(*) as val FROM ' . MAIN_DB_PREFIX . $table . ' WHERE ' . $where);
        if (!$resql) {
            $this->fail('SQL error counting ' . $table . ': ' . $this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj === null ? 0 : (int) $obj->val;
    }
}
