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

require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';

/**
 * Mapping for Dolibarr Expedition -> API Shipment
 * Alias: dmExpedition (for backward compatibility with Dolibarr internal calls)
 */
class dmShipment extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_customer'      => 'customer_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date_expedition'   => 'date_shipment',
		'date_delivery'     => 'date_delivery',
		'date_valid'        => 'validated_at',
		'socid'             => 'thirdparty',
		'fk_projet'         => 'project',
		'commande_id'       => 'order',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'entrepot_id'       => 'warehouse',
		'tracking_number'   => 'tracking_number',
		'tracking_url'      => 'tracking_url',
		'fk_shipping_method' => 'shipping_method',
		'trueWeight'        => 'weight',
		'weight_units'      => 'weight_units',
		'trueWidth'         => 'width',
		'width_units'       => 'width_units',
		'trueHeight'        => 'height',
		'height_units'      => 'height_units',
		'trueDepth'         => 'depth',
		'depth_units'       => 'depth_units',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		'billed'            => 'billed',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'ExpeditionLigne';
	protected $parentLabelForLines = 'ShipmentLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getShipmentLinesMapping();
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmShipment', 'SmartAuth\DolibarrMapping\dmExpedition');
