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

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

/**
 * Mapping for Dolibarr FactureFournisseur -> API SupplierInvoice
 * Alias: dmFactureFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierInvoice extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'FactureFournisseur';
	protected $parentTableElementToUseForExtraFields = 'facture_fourn';

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables. Supplier flow exposes
	// fk_account directly (no fk_currency on the header).
	protected $parentFieldsOverride = [
		'cond_reglement_id' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'mode_reglement_id' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
		'fk_account'        => ['type' => 'sellist:bank_account:label:rowid', 'label' => 'BankAccount'],
	];

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty (supplier) name (+ email) alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name. Same declaration shape as dmInvoice (keyed on the PHP
	// property `socid`, which FactureFournisseur::fetch fills from the SQL column
	// fk_soc). The front reads `thirdpartyName` in place of the legacy socname.
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		'label'             => 'label',
		// Supplier invoice type (standard 0 / replacement 1 / credit note 2 /
		// deposit 3). Writable on create; immutable afterwards (the app never
		// resends it on update).
		'type'              => 'type',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_invoice',
		'date_echeance'     => 'date_due',
		'socid'             => 'thirdparty',
		// FactureFournisseur reads $this->fk_project (SQL column fk_projet).
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
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
		// Paid flag (0 unpaid, 1 paid). READ-ONLY: it is set by the payment
		// facade / setpaid()/setunpaid() actions, never written directly (absent
		// from $writableFields). The front reads it (StatusPill, canPay) via the
		// legacy alias `paye`.
		'paye'              => 'paid',
		'close_code'        => 'close_code',
		'close_note'        => 'close_note',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
		// Last generated PDF (relative path under DOL_DATA_ROOT). Read-only
		// (server-owned, set by generateDocument()): NOT in $writableFields.
		// NOTE: unlike Facture, FactureFournisseur::fetch() does NOT select this
		// column, so a plain show/list exports it empty; it only carries a value
		// on the in-memory object right after generateDocument(). Published for
		// shape parity with dmInvoice (consumed by the front PDF button /
		// SendEmailModal, which fall back to the LOCAL pdf/download endpoint).
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
		'label',
		// type is writable so the app can create a credit-note/deposit supplier
		// invoice; harmless on update because the edit forms never resend it.
		'type',
		'socid',
		'fk_project',
		'date',
		'date_echeance',
		'cond_reglement_id',
		'mode_reglement_id',
		'fk_account',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'SupplierInvoiceLine';
	protected $parentLabelForLines = 'SupplierInvoiceLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getSupplierInvoiceLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/supplier_invoice.
	 *
	 * FactureFournisseur::$fields is empty, so the generic
	 * dmBase::getSearchFields() (which keeps string-typed published fields whose
	 * `doliside` is a real $fields column) would yield nothing. Pin the two
	 * user-facing reference columns to match the former local
	 * SupplierInvoiceController search parity (ref + ref_supplier). Both are real
	 * llx_facture_fourn varchar columns, so the generated `ff.ref LIKE ...` /
	 * `ff.ref_supplier LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_supplier'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmSupplierInvoice', 'SmartAuth\DolibarrMapping\dmFactureFournisseur');
