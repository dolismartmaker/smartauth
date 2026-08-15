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

	// Element name for extrafields (= llx_extrafields.elementtype).
	protected $parentTableElementToUseForExtraFields = 'reception';

	// Opt-in FK -> label companion fields (cf dmOrder): the reception lists show
	// the supplier name next to the raw socid.
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
	// IMPORTANT (doliside): the LEFT keys are the PHP properties Reception::fetch()
	// fills, NOT the SQL column names (fk_statut -> statut, fk_shipping_method ->
	// shipping_method_id, weight -> trueWeight). Sort/filter therefore go through
	// getSortableColumns()/getFilterableColumns() below.
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		// Reception::fetch() fills date_creation (there is no `datec` property).
		'date_creation'     => 'created_at',
		'tms'               => 'updated_at',
		'date_reception'    => 'date_reception',
		'date_delivery'     => 'date_delivery',
		'date_valid'        => 'validated_at',
		'socid'             => 'thirdparty',
		// Reception reads $this->fk_project (SQL column fk_projet).
		'fk_project'        => 'project',
		'origin_id'         => 'origin_id',
		'origin'            => 'origin_type',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'entrepot_id'       => 'warehouse',
		'tracking_number'   => 'tracking_number',
		'tracking_url'      => 'tracking_url',
		// Property filled from the SQL column fk_shipping_method, and the one
		// update() persists FROM -- addressing the column name would be a no-op.
		'shipping_method_id' => 'shipping_method',
		// Totals computed by Reception::fetch_lines() from the supplier order.
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
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	//
	// Reception::update() rewrites every column from the in-memory object; each
	// key below is a property it actually reads (shipping_method_id, NOT the
	// column name fk_shipping_method -- the historical entry was a no-op).
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// entrepot_id names llx_entrepot. Note that llx_reception has NO warehouse
	// column -- the warehouse lives on the dispatch LINES -- so
	// Reception::update() drops this value silently today. The guard is kept
	// anyway: it refuses a foreign or dangling id instead of accepting it into
	// a no-op, and it is already in place should the field ever be honoured.
	// A local warehouse id keeps answering 200, so nothing legitimate changes.
	protected $foreignKeyGuards = [
		'socid'       => 'thirdparty',
		'fk_project'  => 'project',
		'entrepot_id' => 'warehouse',
	];

	protected $writableFields = [
		'ref_supplier',
		'socid',
		'fk_project',
		'date_reception',
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

	/**
	 * Global-search columns for objects/reception.
	 *
	 * Reception::$fields is declared EMPTY in Dolibarr, so the generic
	 * derivation would return an empty list and the search box would match
	 * nothing. These are real varchar columns of llx_reception.
	 *
	 * @return array<int,string>
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_supplier', 'tracking_number'];
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5): empty $fields + mapper
	 * keys that are PHP properties, not SQL columns (statut -> fk_statut,
	 * shipping_method_id -> fk_shipping_method, trueWeight -> weight). Keys are
	 * the camelCase catalog keys sent by the frontend.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'ref'            => ['column' => 'ref', 'kind' => 'text'],
			'supplierRef'    => ['column' => 'ref_supplier', 'kind' => 'text'],
			'trackingNumber' => ['column' => 'tracking_number', 'kind' => 'text'],
			'thirdparty'     => ['column' => 'fk_soc', 'kind' => 'select'],
			'status'         => ['column' => 'fk_statut', 'kind' => 'select'],
			'billed'         => ['column' => 'billed', 'kind' => 'boolean'],
			'dateReception'  => ['column' => 'date_reception', 'kind' => 'daterange'],
			'dateDelivery'   => ['column' => 'date_delivery', 'kind' => 'daterange'],
			'createdAt'      => ['column' => 'date_creation', 'kind' => 'daterange'],
			'project'        => ['column' => 'fk_projet', 'kind' => 'select'],
			'shippingMethod' => ['column' => 'fk_shipping_method', 'kind' => 'select'],
			'weight'         => ['column' => 'weight', 'kind' => 'numberrange'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 3.5): API key -> real column.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'ref'            => 'ref',
			'supplierRef'    => 'ref_supplier',
			'thirdparty'     => 'fk_soc',
			'status'         => 'fk_statut',
			'billed'         => 'billed',
			'dateReception'  => 'date_reception',
			'dateDelivery'   => 'date_delivery',
			'createdAt'      => 'date_creation',
			'validatedAt'    => 'date_valid',
			'trackingNumber' => 'tracking_number',
			'weight'         => 'weight',
		];
	}
}
