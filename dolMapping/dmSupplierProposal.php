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
		'fk_projet'         => 'project',
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
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'socid',
		'fk_projet',
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
}
