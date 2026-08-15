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

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * Mapping for Dolibarr Facture -> API Invoice
 * Alias: dmFacture (for backward compatibility with Dolibarr internal calls)
 */
class dmInvoice extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Facture';
	protected $parentTableElementToUseForExtraFields = 'facture';

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables. Imported from Dolipocket
	// where this was the de facto pattern for all document mappers.
	protected $parentFieldsOverride = [
		'fk_cond_reglement' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'fk_mode_reglement' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
	];

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty (customer) name (+ email) alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name. Same declaration shape as dmProposal (keyed on the PHP
	// property `socid`, which Facture::fetch fills from the SQL column fk_soc).
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
		// The customer reference lives on the SQL column ref_client. Facture
		// exposes BOTH $ref_client and $ref_customer PHP properties, but only
		// ref_client is a real $fields column (so it is the sortable/filterable
		// one and the one Facture::update() writes). We address ref_client so the
		// value reads back and persists on both create and update (create falls
		// back to ref_client when ref_customer is empty).
		'ref_client'        => 'customer_ref',
		// Invoice type (standard 0 / replacement 1 / credit note 2 / deposit 3 /
		// situation 5). Writable on create; immutable afterwards (the app never
		// sends it on update).
		'type'              => 'type',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_invoice',
		'date_valid'        => 'validated_at',
		'date_lim_reglement' => 'date_due',
		'delivery_date'     => 'date_delivery',
		// Thirdparty/project links live on the PHP properties $socid / $fk_project
		// (SQL columns fk_soc / fk_projet). Facture::create/fetch read those
		// properties, so addressing the columns would never persist on write.
		'socid'             => 'thirdparty',
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_modif'     => 'updated_by',
		'fk_cond_reglement' => 'payment_terms',
		'fk_mode_reglement' => 'payment_method',
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_localtax1'   => 'total_local_tax1',
		'total_localtax2'   => 'total_local_tax2',
		'total_ttc'         => 'total_incl_tax',
		'revenuestamp'      => 'revenue_stamp',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		// Paid flag (0 unpaid, 1 paid). READ-ONLY: it is set by the payment
		// facade / setPaid()/setUnpaid() actions, never written directly. The
		// front reads it (StatusPill, canPay) via the legacy alias `paye`.
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
		// Facture::fetch() populates $this->last_main_doc from the SQL column.
		// Consumed by the front "Telecharger PDF" button + SendEmailModal.
		'last_main_doc'     => 'last_main_doc',
	];

	// Tenant guard on the VALUES written into these foreign keys (see
	// dmBase::$foreignKeyGuards). The allowlist below only vets field NAMES:
	// without this map a PATCH carrying another tenant's socid was written
	// verbatim, leaving the invoice in its own entity while pointing at a
	// company that does not exist for it.
	protected $foreignKeyGuards = [
		'socid'      => 'thirdparty',
		'fk_project' => 'project',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'ref_client',
		// type is writable so the app can create a deposit/credit-note invoice;
		// harmless on update because the edit forms never resend it.
		'type',
		'socid',
		'fk_project',
		'date',
		'date_lim_reglement',
		'delivery_date',
		'fk_cond_reglement',
		'fk_mode_reglement',
		'note_public',
		'note_private',
	];

	// 'fk_contrat'        => 'contract',
	// Configuration for lines support
	protected $parentClassNameForLines = 'FactureLigne';
	protected $parentLabelForLines = 'InvoiceLines';

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
		$this->listOfPublishedFieldsForLines = $this->getInvoiceLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/invoice.
	 *
	 * The generic dmBase::getSearchFields() only keeps string-typed published
	 * fields whose `doliside` is a real Facture::$fields column. `ref_client` IS
	 * a real column, but `ref` alone is not enough parity with the former local
	 * InvoiceController search (ref + ref_client). Pin the two user-facing
	 * reference columns explicitly. Both are real llx_facture varchar columns so
	 * the generated `f.ref LIKE ...` / `f.ref_client LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_client'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmInvoice', 'SmartAuth\DolibarrMapping\dmFacture');
