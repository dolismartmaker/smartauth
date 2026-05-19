<?php

/**
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (c) 2025 Paolo Debaisieux <paolo.debaisieux@cap-rel.fr>
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

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

class dmProduct extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Product';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'              => 'id',
		'ref'                => 'ref',
		'label'              => 'label',
		'description'        => 'description',
		'type'               => 'type',
		'price'              => 'price_excl_tax',
		'price_ttc'          => 'price_incl_tax',
		'price_min'          => 'price_min_excl_tax',
		'price_min_ttc'      => 'price_min_incl_tax',
		'price_base_type'    => 'price_base_type',
		'tva_tx'             => 'vat_rate',
		'barcode'            => 'barcode',
		'weight'             => 'weight',
		'length'             => 'length',
		'width'              => 'width',
		'height'             => 'height',
		'stock_reel'         => 'stock',
		'seuil_stock_alerte' => 'stock_alert_threshold',
		'note_public'        => 'public_note',
		'note_private'       => 'private_note',
		'datec'              => 'created_at',
		// Product::fetch (product.class.php line 2511-2512) reads the SQL
		// columns 'tosell'/'tobuy' INTO $this->status / $this->status_buy
		// (renamed at fetch time). The mapper must read the PHP property
		// names, not the SQL column names.
		'status'             => 'for_sale',
		'status_buy'         => 'for_purchase',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// 'stock_reel' (computed) and 'seuil_stock_alerte' (case-by-case, conservative) excluded.
	protected $writableFields = [
		'ref',
		'label',
		'description',
		'type',
		'price',
		'price_ttc',
		'price_min',
		'price_min_ttc',
		'price_base_type',
		'tva_tx',
		'barcode',
		'weight',
		'length',
		'width',
		'height',
		'status',
		'status_buy',
		'note_public',
		'note_private',
	];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->boot();
	}
}
