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

require_once DOL_DOCUMENT_ROOT . '/reception/class/reception.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.dispatch.class.php';

/**
 * Mapping for Dolibarr Reception -> API Reception
 * Note: Reception lines use CommandeFournisseurDispatch class in Dolibarr
 */
class dmReception extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Reception';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date_reception'    => 'date_reception',
		'date_delivery'     => 'date_delivery',
		'date_valid'        => 'validated_at',
		'socid'             => 'thirdparty',
		'fk_projet'         => 'project',
		'origin_id'         => 'origin_id',
		'origin'            => 'origin_type',
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
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'ref_supplier',
		'socid',
		'fk_projet',
		'date_reception',
		'date_delivery',
		'entrepot_id',
		'fk_shipping_method',
		'tracking_number',
		'tracking_url',
		'trueWeight',
		'weight_units',
		'trueWidth',
		'width_units',
		'trueHeight',
		'height_units',
		'trueDepth',
		'depth_units',
		'note_public',
		'note_private',
	];

	// Configuration for lines support - Reception uses CommandeFournisseurDispatch for lines
	protected $parentClassNameForLines = 'CommandeFournisseurDispatch';
	protected $parentLabelForLines = 'ReceptionLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getReceptionLinesMapping();
		$this->boot();
	}
}
