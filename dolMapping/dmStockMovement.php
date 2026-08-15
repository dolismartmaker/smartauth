<?php

/**
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

namespace SmartAuth\DolibarrMapping;

require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';

/**
 * Mapping for Dolibarr MouvementStock -> API StockMovement
 * Alias: dmMouvementStock (for backward compatibility with Dolibarr internal calls)
 *
 * Audit-only object: stock movements are immutable from a user's
 * perspective. Creation happens through Product::correct_stock() or
 * similar business endpoints. $writableFields is intentionally empty;
 * the API exposes read-only access.
 *
 * Field-name caveat: MouvementStock::fetch() renames several BDD
 * columns onto PHP properties (fk_product -> product_id,
 * fk_entrepot -> warehouse_id, value -> qty, type_mouvement -> type).
 * Mapper keys here use the PHP property names so exportMappedData()
 * reads the correct values after fetch().
 */
class dmStockMovement extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'MouvementStock';

	// Element name for the extrafields lookup (matches llx_extrafields.elementtype)
	protected $parentTableElementToUseForExtraFields = 'stock_mouvement';

	// Dolibarr PHP-property name => API field name
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'           => 'id',
		'product_id'      => 'product_id',
		'warehouse_id'    => 'warehouse_id',
		'qty'             => 'qty',
		'type'            => 'type',
		'datem'           => 'datem',
		'price'           => 'price',
		'fk_user_author'  => 'fk_user_author',
		'label'           => 'label',
		'origin_id'       => 'origin_id',
		'origin_type'     => 'origin_type',
		'inventorycode'   => 'inventorycode',
		'batch'           => 'batch',
		'eatby'           => 'eatby',
		'sellby'          => 'sellby',
		'fk_project'      => 'fk_project',
	];

	// Audit-only object: no field is writable through the generic
	// importMappedData() path. Stock corrections must go through
	// Product::correct_stock() or the dedicated business endpoint.
	protected $writableFields = [];

	public function __construct()
	{
		$this->boot();
	}

	/**
	 * TENANT ISOLATION for a table with NO 'entity' column.
	 *
	 * llx_stock_mouvement carries no entity, so the facade would otherwise list
	 * EVERY tenant's movements. A movement is always attached to a warehouse AND
	 * a product, both of which ARE entity-scoped: a movement belongs to the
	 * current tenant when both do. This reproduces, as an EXISTS predicate, the
	 * double INNER JOIN the Dolipocket StockController used before the facade
	 * migration.
	 *
	 * Consumed by ObjectFacadeTrait::isolationWhereFragment() (list/count) and
	 * ::isolationDenies() (show/update/destroy row probe).
	 *
	 * @param  string $alias  SQL alias of llx_stock_mouvement in the host query.
	 * @param  object $db     DoliDB (unused: no user input goes into the fragment).
	 * @return string         SQL fragment starting with " AND ".
	 */
	public function isolationWhereSql($alias, $db)
	{
		$a = (string) $alias;
		if ($a === '') {
			$a = 'sm';
		}

		$sql = " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "entrepot as isol_e";
		$sql .= " WHERE isol_e.rowid = " . $a . ".fk_entrepot";
		$sql .= " AND isol_e.entity IN (" . getEntity('stock') . "))";
		$sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "product as isol_p";
		$sql .= " WHERE isol_p.rowid = " . $a . ".fk_product";
		$sql .= " AND isol_p.entity IN (" . getEntity('product') . "))";

		return $sql;
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5).
	 *
	 * MouvementStock::fetch() renames columns onto PHP properties, and the
	 * mapper addresses those properties (product_id, warehouse_id, qty, type,
	 * origin_id, origin_type) so the READ export is correct. The generic catalog
	 * therefore cannot mark them filterable: they are not keys of
	 * MouvementStock::$fields, which is written with the SQL column names.
	 * Worse, $fields declares BOTH 'fk_projet' and a spurious 'fk_project' while
	 * only fk_projet exists in llx_stock_mouvement -- filtering the catalog key
	 * would emit a non-existent column. Map every API key to its REAL column.
	 *
	 * Keys are the camelCase catalog keys the frontend sends.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'productId'     => ['column' => 'fk_product', 'kind' => 'select'],
			'warehouseId'   => ['column' => 'fk_entrepot', 'kind' => 'select'],
			'qty'           => ['column' => 'value', 'kind' => 'numberrange'],
			'type'          => ['column' => 'type_mouvement', 'kind' => 'select'],
			'datem'         => ['column' => 'datem', 'kind' => 'daterange'],
			'price'         => ['column' => 'price', 'kind' => 'numberrange'],
			'fkUserAuthor'  => ['column' => 'fk_user_author', 'kind' => 'select'],
			'label'         => ['column' => 'label', 'kind' => 'text'],
			'inventorycode' => ['column' => 'inventorycode', 'kind' => 'text'],
			'batch'         => ['column' => 'batch', 'kind' => 'text'],
			'originId'      => ['column' => 'fk_origin', 'kind' => 'select'],
			'originType'    => ['column' => 'origintype', 'kind' => 'text'],
			'fkProject'     => ['column' => 'fk_projet', 'kind' => 'select'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 3.5). Same rationale as
	 * getFilterableColumns(): API key -> real SQL column.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'datem'         => 'datem',
			'productId'     => 'fk_product',
			'warehouseId'   => 'fk_entrepot',
			'qty'           => 'value',
			'type'          => 'type_mouvement',
			'price'         => 'price',
			'label'         => 'label',
			'inventorycode' => 'inventorycode',
			'batch'         => 'batch',
			'fkUserAuthor'  => 'fk_user_author',
		];
	}

	/**
	 * Columns scanned by the global ?search= LIKE. Restricted to the movement's
	 * own string columns (the generic derivation would return nothing usable
	 * because the mapper addresses PHP properties, cf getFilterableColumns).
	 *
	 * @return array<int,string>
	 */
	public function getSearchFields()
	{
		return ['label', 'inventorycode', 'batch'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmStockMovement', 'SmartAuth\DolibarrMapping\dmMouvementStock');
