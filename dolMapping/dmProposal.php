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

require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';

/**
 * Mapping for Dolibarr Propal -> API Proposal
 * Alias: dmPropal (for backward compatibility with Dolibarr internal calls)
 */
class dmProposal extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Propal';
	protected $parentTableElementToUseForExtraFields = 'propal';

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables.
	protected $parentFieldsOverride = [
		'fk_cond_reglement' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'fk_mode_reglement' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
	];

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty name (+ email) alongside the raw `thirdparty`
	// (socid) scalar, using the per-process fetch cache (one Societe fetch per list,
	// no N+1). Additive: strict consumers keep the scalar id and gain a display name.
	// Same declaration shape as dmContact::$listOfForeignKeyLabels (keyed on the
	// PHP property `socid`, which Propal::fetch fills from the SQL column fk_soc).
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
		'ref_client'        => 'customer_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_proposal',
		'date_valid'        => 'validated_at',
		'date_signature'    => 'signed_at',
		'fin_validite'      => 'date_expiry',
		'delivery_date'     => 'date_delivery',
		// Thirdparty/project links live on the PHP properties $socid / $fk_project
		// (SQL columns fk_soc / fk_projet). Propal::create/fetch read those
		// properties, so addressing the columns would never persist on write.
		'socid'             => 'thirdparty',
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_modif'     => 'updated_by',
		'user_signature'    => 'signed_by',
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
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
		// Last generated PDF (relative path under DOL_DATA_ROOT). Read-only
		// (server-owned, set by generateDocument()): NOT in $writableFields.
		// Propal::fetch() populates $this->last_main_doc from the SQL column.
		// Consumed by the front "Telecharger PDF" button.
		'last_main_doc'     => 'last_main_doc',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
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
		'date',
		'fin_validite',
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
	protected $parentClassNameForLines = 'PropaleLigne';
	protected $parentLabelForLines = 'ProposalLines';

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
		$this->listOfPublishedFieldsForLines = $this->getProposalLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/proposal.
	 *
	 * The generic dmBase::getSearchFields() keeps every string-typed published
	 * field whose `doliside` is a real Propal::$fields column. That set would
	 * also drag in noise columns (last_main_doc = a PDF file path,
	 * multicurrency_code) into the ?search= LIKE clause. Narrow it to the two
	 * user-facing reference columns, matching the former local ProposalController
	 * search (ref / ref_client). Both are real llx_propal varchar columns so the
	 * generated `p.ref LIKE ...` / `p.ref_client LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_client'];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmProposal', 'SmartAuth\DolibarrMapping\dmPropal');
