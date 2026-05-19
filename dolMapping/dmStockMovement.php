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
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmStockMovement', 'SmartAuth\DolibarrMapping\dmMouvementStock');
