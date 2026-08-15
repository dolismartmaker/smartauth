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
	protected $dolibarrClassName = 'Expedition';

	// Element name for extrafields (= llx_extrafields.elementtype).
	protected $parentTableElementToUseForExtraFields = 'expedition';

	// Opt-in FK -> label companion fields (cf dmOrder): the shipment lists show
	// the customer name next to the raw socid.
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// IMPORTANT (doliside): the LEFT keys are the PHP properties Expedition::fetch()
	// fills, NOT the SQL column names -- fetch() renames several columns
	// (fk_statut -> statut, fk_shipping_method -> shipping_method_id, weight ->
	// trueWeight, fk_address -> fk_delivery_address). Sorting/filtering on those
	// keys therefore goes through getSortableColumns()/getFilterableColumns()
	// below, which point at the real columns.
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_customer'      => 'customer_ref',
		// The creation date property/column is date_creation (Expedition has no
		// `datec`), so the historical 'datec' doliside always exported null.
		'date_creation'     => 'created_at',
		'tms'               => 'updated_at',
		'date_expedition'   => 'date_shipment',
		'date_delivery'     => 'date_delivery',
		'date_valid'        => 'validated_at',
		'socid'             => 'thirdparty',
		// Expedition reads $this->fk_project (SQL column fk_projet).
		'fk_project'        => 'project',
		'commande_id'       => 'order',
		'origin'            => 'origin_type',
		'origin_id'         => 'origin_id',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'entrepot_id'       => 'warehouse',
		'fk_delivery_address' => 'delivery_address',
		'tracking_number'   => 'tracking_number',
		'tracking_url'      => 'tracking_url',
		// Expedition::fetch() fills $this->shipping_method_id from the SQL
		// column fk_shipping_method, and update() persists that column FROM
		// $this->shipping_method_id -- addressing 'fk_shipping_method' would
		// read AND write nothing. $this->shipping_method holds the label.
		'shipping_method_id' => 'shipping_method',
		'shipping_method'   => 'shipping_method_label',
		// Totals: computed by Expedition::fetch_lines() from the origin order
		// lines. The list and the Totals block read them.
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_ttc'         => 'total_incl_tax',
		'model_pdf'         => 'model_pdf',
		'last_main_doc'     => 'last_main_doc',
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

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	//
	// Expedition::update() rewrites EVERY column from the in-memory object, so
	// the controller's fetch-then-apply order matters; each key below is a
	// property update() actually reads (shipping_method_id, NOT the column name
	// fk_shipping_method -- the historical entry here was a silent no-op).
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// entrepot_id names llx_entrepot. Note that llx_expedition has NO warehouse
	// column -- the warehouse lives on the LINES (Expedition::addline) -- so
	// Expedition::update() drops this value silently today. The guard is kept
	// anyway: it refuses a foreign or dangling id instead of accepting it into
	// a no-op, and it is already in place should the field ever be honoured.
	// A local warehouse id keeps answering 200, so nothing legitimate changes.
	protected $foreignKeyGuards = [
		'socid'       => 'thirdparty',
		'fk_project'  => 'project',
		'entrepot_id' => 'warehouse',
	];

	protected $writableFields = [
		'ref_customer',
		'socid',
		'fk_project',
		'date_expedition',
		'date_delivery',
		'entrepot_id',
		'shipping_method_id',
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

	/**
	 * Global-search columns for objects/shipment.
	 *
	 * Expedition::$fields is declared EMPTY in Dolibarr, so the generic
	 * derivation (which needs a $fields entry per column) would return an empty
	 * list and the search box would silently match nothing. These three are real
	 * varchar columns of llx_expedition; PaginatedListTrait prefixes the alias.
	 *
	 * @return array<int,string>
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_customer', 'tracking_number'];
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5).
	 *
	 * Two reasons the catalog cannot derive them: Expedition::$fields is empty,
	 * AND several mapper doliside keys are PHP properties whose SQL column has a
	 * different name (statut -> fk_statut, shipping_method_id ->
	 * fk_shipping_method, trueWeight -> weight, fk_delivery_address ->
	 * fk_address). Keys are the camelCase catalog keys sent by the frontend.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'ref'             => ['column' => 'ref', 'kind' => 'text'],
			'customerRef'     => ['column' => 'ref_customer', 'kind' => 'text'],
			'trackingNumber'  => ['column' => 'tracking_number', 'kind' => 'text'],
			'thirdparty'      => ['column' => 'fk_soc', 'kind' => 'select'],
			'status'          => ['column' => 'fk_statut', 'kind' => 'select'],
			'billed'          => ['column' => 'billed', 'kind' => 'boolean'],
			'dateShipment'    => ['column' => 'date_expedition', 'kind' => 'daterange'],
			'dateDelivery'    => ['column' => 'date_delivery', 'kind' => 'daterange'],
			'createdAt'       => ['column' => 'date_creation', 'kind' => 'daterange'],
			'project'         => ['column' => 'fk_projet', 'kind' => 'select'],
			'shippingMethod'  => ['column' => 'fk_shipping_method', 'kind' => 'select'],
			'weight'          => ['column' => 'weight', 'kind' => 'numberrange'],
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
			'ref'            => 'ref',
			'customerRef'    => 'ref_customer',
			'thirdparty'     => 'fk_soc',
			'status'         => 'fk_statut',
			'billed'         => 'billed',
			'dateShipment'   => 'date_expedition',
			'dateDelivery'   => 'date_delivery',
			'createdAt'      => 'date_creation',
			'validatedAt'    => 'date_valid',
			'trackingNumber' => 'tracking_number',
			'weight'         => 'weight',
		];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmShipment', 'SmartAuth\DolibarrMapping\dmExpedition');
