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

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';

/**
 * Mapping for Dolibarr CommandeFournisseur -> API SupplierOrder
 * Alias: dmCommandeFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierOrder extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'CommandeFournisseur';
	protected $parentTableElementToUseForExtraFields = 'commande_fournisseur';

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty (supplier) name (+ email) alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name. Keyed on the PHP property `socid`, which
	// CommandeFournisseur::fetch fills from the SQL column fk_soc.
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables. Supplier flow exposes
	// fk_account directly.
	protected $parentFieldsOverride = [
		'cond_reglement_id' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'mode_reglement_id' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
		'fk_account'        => ['type' => 'sellist:bank_account:label:rowid', 'label' => 'BankAccount'],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_order',
		'date_valid'        => 'validated_at',
		'date_approve'      => 'approved_at',
		'date_commande'     => 'date_order_supplier',
		'delivery_date'     => 'date_delivery',
		'socid'             => 'thirdparty',
		// CommandeFournisseur reads $this->fk_project (SQL column fk_projet).
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_approve'   => 'approved_by',
		'cond_reglement_id' => 'payment_terms',
		'mode_reglement_id' => 'payment_method',
		'fk_account'        => 'bank_account',
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_localtax1'   => 'total_local_tax1',
		'total_localtax2'   => 'total_local_tax2',
		'total_ttc'         => 'total_incl_tax',
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
		// Last generated PDF (relative path under DOL_DATA_ROOT). Read-only
		// (server-owned, set by generateDocument()): NOT in $writableFields.
		// CommandeFournisseur declares the SQL column in $fields but its custom
		// fetch() does not currently SELECT it, so the exported value stays empty
		// until a document is generated through a code path that populates the
		// property. Additive + read-only + harmless; kept for facade parity with
		// dmProposal / dmSupplierProposal (the front reads `lastMainDoc`).
		'last_main_doc'     => 'last_main_doc',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// fk_account is a llx_bank_account reference: pointing it at another
	// tenant's account is how a payment ends up in the wrong ledger.
	protected $foreignKeyGuards = [
		'socid'      => 'thirdparty',
		'fk_project' => 'project',
		'fk_account' => 'bank_account',
	];

	protected $writableFields = [
		'ref_supplier',
		'socid',
		'fk_project',
		'date',
		'date_commande',
		'delivery_date',
		'cond_reglement_id',
		'mode_reglement_id',
		'fk_account',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'CommandeFournisseurLigne';
	protected $parentLabelForLines = 'SupplierOrderLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getSupplierOrderLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/supplier_order.
	 *
	 * CommandeFournisseur DOES declare $fields, but to pin the facade global
	 * search to the same behaviour the former local SupplierOrderController had
	 * (LIKE on ref + ref_supplier), narrow it explicitly to the two user-facing
	 * reference columns. Both `ref` and `ref_supplier` are real varchar columns
	 * on llx_commande_fournisseur, so the generated `cf.ref LIKE ... OR
	 * cf.ref_supplier LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_supplier'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmSupplierOrder', 'SmartAuth\DolibarrMapping\dmCommandeFournisseur');
