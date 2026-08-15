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

require_once DOL_DOCUMENT_ROOT . '/supplier_proposal/class/supplier_proposal.class.php';

/**
 * Mapping for Dolibarr SupplierProposal -> API SupplierProposal
 */
class dmSupplierProposal extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'SupplierProposal';

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty (supplier) name (+ email) alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name. Same declaration shape as dmProposal::$listOfForeignKeyLabels
	// (keyed on the PHP property `socid`, which SupplierProposal::fetch fills from
	// the SQL column fk_soc).
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	// Note : llx_supplier_proposal has NO ref_supplier column. The
	// SupplierProposal class declares $ref_supplier as a transient
	// property used only as a parameter of addline()/updateline() to
	// carry ref_fourn down to llx_supplier_proposaldet rows. It is
	// never populated by SupplierProposal::fetch() and therefore must
	// not appear in the header mapping (the previous 'ref_supplier'
	// entry was a dead mapping silently filtered out at export time).
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_proposal',
		'date_validation'   => 'validated_at',
		'delivery_date'     => 'date_delivery',
		'socid'             => 'thirdparty',
		// SupplierProposal reads $this->fk_project (SQL column fk_projet).
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_close'     => 'closed_by',
		'cond_reglement_id' => 'payment_terms',
		'mode_reglement_id' => 'payment_method',
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
		// The SQL column exists on llx_supplier_proposal but SupplierProposal::fetch()
		// does not currently select it, so the exported value is empty until a
		// document is generated through a code path that populates the property.
		// Kept for facade parity with dmProposal; harmless (additive, read-only).
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
		'socid',
		'fk_project',
		'date',
		'delivery_date',
		'cond_reglement_id',
		'mode_reglement_id',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'SupplierProposalLine';
	protected $parentLabelForLines = 'SupplierProposalLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getSupplierProposalLinesMapping();
		$this->boot();
	}

	/**
	 * Global-search columns for objects/supplier_proposal.
	 *
	 * SupplierProposal declares NO $fields, so the generic catalog marks every
	 * column non-searchable and dmBase::getSearchFields() would return an empty
	 * set (no LIKE clause at all). Narrow it explicitly to the single user-facing
	 * reference column. llx_supplier_proposal has a real `ref` varchar column
	 * (and NO ref_supplier / ref_fourn header column -- those live on the det
	 * rows), so the generated `sp.ref LIKE ...` is SQL-safe and matches the
	 * former local SupplierProposalController search.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref'];
	}

	/**
	 * Setter-based header update fallback for the generic facade.
	 *
	 * SupplierProposal (the header class) exposes NO generic update() method:
	 * the update() near the bottom of supplier_proposal.class.php belongs to the
	 * SupplierProposalLine class. ObjectController::update() therefore cannot call
	 * $object->update(); when a mapper defines this method it is used instead,
	 * replaying the exact dedicated setters supplier_proposal/card.php uses.
	 *
	 * Handles the writable header fields the Dolipocket edit form sends (public/
	 * private notes, payment terms/method, delivery date). Fields with no
	 * dedicated setter (socid / fk_project / date) are create-only in the UI and
	 * are logged + skipped here (they were assigned in memory by
	 * applyImportedFields but are not persisted; the caller re-fetches afterwards).
	 *
	 * @param  \SupplierProposal $object    already fetched + entity-checked
	 * @param  \stdClass         $sanitized importMappedData() output (doliside keys)
	 * @param  \User             $user
	 * @return int  >0 on success, <0 on the first failing setter
	 */
	public function updateViaSetters($object, $sanitized, $user)
	{
		$fields = get_object_vars($sanitized);

		if (array_key_exists('note_public', $fields)) {
			if ($object->update_note((string) $fields['note_public'], '_public') < 0) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters update_note(public) failed: ' . $object->error, LOG_ERR);
				return -1;
			}
		}
		if (array_key_exists('note_private', $fields)) {
			if ($object->update_note((string) $fields['note_private'], '_private') < 0) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters update_note(private) failed: ' . $object->error, LOG_ERR);
				return -1;
			}
		}
		if (!empty($fields['cond_reglement_id'])) {
			if ($object->setPaymentTerms((int) $fields['cond_reglement_id']) < 0) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters setPaymentTerms failed: ' . $object->error, LOG_ERR);
				return -1;
			}
		}
		if (!empty($fields['mode_reglement_id'])) {
			if ($object->setPaymentMethods((int) $fields['mode_reglement_id']) < 0) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters setPaymentMethods failed: ' . $object->error, LOG_ERR);
				return -1;
			}
		}
		if (array_key_exists('delivery_date', $fields)) {
			$dd = (int) $fields['delivery_date'];
			if ($object->setDeliveryDate($user, $dd > 0 ? $dd : '') < 0) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters setDeliveryDate failed: ' . $object->error, LOG_ERR);
				return -1;
			}
		}

		foreach (['socid', 'fk_project', 'date'] as $unsettable) {
			if (array_key_exists($unsettable, $fields)) {
				dol_syslog('[SmartAuth] dmSupplierProposal::updateViaSetters ignoring non-persistable header field on update: ' . $unsettable, LOG_NOTICE);
			}
		}

		return 1;
	}
}
