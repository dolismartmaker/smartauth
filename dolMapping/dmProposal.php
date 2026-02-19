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
		'fk_soc'            => 'thirdparty',
		'fk_projet'         => 'project',
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
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmProposal', 'SmartAuth\DolibarrMapping\dmPropal');
