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
	protected $parentTableElementToUseForExtraFields = 'product';

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
		'tms'                => 'updated_at',
		'country_code'       => 'country_code',
		// Product::fetch() (htdocs/product/class/product.class.php
		// lines 2511-2512) renames the SQL columns 'tosell'/'tobuy' into
		// the PHP properties $status / $status_buy. Product::update()
		// (lines 1217-1218) does the inverse: it reads $this->status /
		// $this->status_buy and writes the 'tosell'/'tobuy' SQL columns.
		// The mapper MUST address the PHP property names on both read
		// and write paths -- using 'tosell'/'tobuy' here would yield null
		// values on a Product fetched through fetch().
		'status'             => 'for_sale',
		'status_buy'         => 'for_purchase',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// 'stock_reel' (computed) and 'seuil_stock_alerte' (case-by-case, conservative) excluded.
	// 'status'/'status_buy' are the PHP property names: see comment on
	// listOfPublishedFields above and Product::update() lines 1217-1218.
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
	 * Front-side string aliases for Dolibarr's int product type column
	 * (fk_product_type). Dolibarr stores 0 for a physical product and 1
	 * for a service; the public API exposes these as readable strings so
	 * consumers (PWA browsers, REST clients) don't have to know the int
	 * convention. Keep these in sync with \Product::TYPE_PRODUCT /
	 * \Product::TYPE_SERVICE.
	 */
	public const TYPE_PRODUCT = 'product';
	public const TYPE_SERVICE = 'service';

	/**
	 * Map a Dolibarr product type int to the front-side string alias.
	 * Unknown ints fall back to TYPE_PRODUCT (safer default than a null
	 * that would surface as a missing field on the wire).
	 */
	public static function mapTypeToFront($doliType): string
	{
		switch ((int) $doliType) {
			case \Product::TYPE_SERVICE:
				return self::TYPE_SERVICE;
			case \Product::TYPE_PRODUCT:
			default:
				return self::TYPE_PRODUCT;
		}
	}

	/**
	 * Inverse of mapTypeToFront(). Accepts the canonical string alias OR
	 * the raw int / numeric string so the import path is forgiving with
	 * older clients that still send the Dolibarr int.
	 */
	public static function mapTypeFromFront($frontType): int
	{
		if (is_numeric($frontType)) {
			return ((int) $frontType) === \Product::TYPE_SERVICE
				? \Product::TYPE_SERVICE
				: \Product::TYPE_PRODUCT;
		}
		return ((string) $frontType) === self::TYPE_SERVICE
			? \Product::TYPE_SERVICE
			: \Product::TYPE_PRODUCT;
	}

	/**
	 * exportMappedData() hook: convert the Dolibarr int type to its front
	 * string alias. Invoked automatically by dmTrait::exportMappedData()
	 * when the 'type' field is published.
	 */
	public function fieldFilterValueType($obj, $doliVal): string
	{
		return self::mapTypeToFront($doliVal);
	}

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
