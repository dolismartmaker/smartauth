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

require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';

/**
 * Mapping for Dolibarr Commande -> API Order
 * Alias: dmCommande (for backward compatibility with Dolibarr internal calls)
 */
class dmOrder extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Commande';
	protected $parentTableElementToUseForExtraFields = 'commande';

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty (customer) name (+ email) alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name. Keyed on the PHP property `socid`, which Commande::fetch
	// fills from the SQL column fk_soc.
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables.
	protected $parentFieldsOverride = [
		'fk_cond_reglement' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'fk_mode_reglement' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		// Customer reference. Address the PHP property / SQL column `ref_client`,
		// NOT the read-only alias `$this->ref_customer` (which Commande::fetch
		// fills from the same column, so read yields the same value): only
		// $this->ref_client is persisted by Commande::create()/update(), and
		// ref_client is the real SQL column for catalog sort/filter + getSearchFields.
		'ref_client'        => 'customer_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		// The customer-order date. IMPORTANT: address the PHP property /
		// SQL column `date_commande`, NOT the runtime alias `$this->date`:
		// Commande::fetch() fills BOTH from the SQL column date_commande, so
		// reading either yields the same value, but Commande::update() persists
		// the column FROM $this->date_commande only (never $this->date), and
		// `date` is not a real SQL column (so catalog sort/filter on it would be
		// invalid SQL too). Mapping the doliside to date_commande makes the
		// apiside `date_order` key round-trip: read == write == sort/filter.
		'date_commande'     => 'date_order',
		'date_valid'        => 'validated_at',
		// Delivery date. Address the property `delivery_date` (NOT the deprecated
		// alias `date_livraison`): Commande::fetch fills both, but Commande::update
		// persists the column FROM $this->delivery_date only.
		'delivery_date'     => 'date_delivery',
		// Thirdparty/project links live on the PHP properties $socid / $fk_project
		// (SQL columns fk_soc / fk_projet). Commande::create/fetch read those
		// properties, so addressing the columns would never persist on write.
		'socid'             => 'thirdparty',
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_modif'     => 'updated_by',
		'fk_cond_reglement' => 'payment_terms',
		'fk_mode_reglement' => 'payment_method',
		'fk_availability'   => 'availability',
		'fk_shipping_method' => 'shipping_method',
		'fk_input_reason'   => 'source_reason',
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_localtax1'   => 'total_local_tax1',
		'total_localtax2'   => 'total_local_tax2',
		'total_ttc'         => 'total_incl_tax',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		'billed'            => 'is_billed',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
		// Last generated PDF (relative path under DOL_DATA_ROOT). Read-only
		// (server-owned, set by generateDocument()): NOT in $writableFields.
		// Additive + read-only; the front reads `lastMainDoc` to enable the
		// "download PDF" action.
		'last_main_doc'     => 'last_main_doc',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	//
	// `date_commande` (NOT `date`): Commande::update() persists the SQL column
	// date_commande FROM $this->date_commande, so the writable field must be the
	// property that write actually reads (cf $listOfPublishedFields note on the
	// date_order key).
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	protected $foreignKeyGuards = [
		'socid'      => 'thirdparty',
		'fk_project' => 'project',
	];

	protected $writableFields = [
		'ref_client',
		'socid',
		'fk_project',
		'date_commande',
		'delivery_date',
		'fk_cond_reglement',
		'fk_mode_reglement',
		'fk_availability',
		'fk_shipping_method',
		'fk_input_reason',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'OrderLine';
	protected $parentLabelForLines = 'OrderLines';

	// Dolibarr field => Front field for lines
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getOrderLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/order.
	 *
	 * Pins the facade global search to the same behaviour the former local
	 * OrderController had (LIKE on ref + ref_client). Both `ref` and `ref_client`
	 * are real varchar columns on llx_commande, so the generated
	 * `c.ref LIKE ... OR c.ref_client LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_client'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmOrder', 'SmartAuth\DolibarrMapping\dmCommande');
