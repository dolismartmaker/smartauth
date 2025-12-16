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

require_once DOL_DOCUMENT_ROOT . '/expensereport/class/expensereport.class.php';

/**
 * Mapping for Dolibarr ExpenseReport -> API ExpenseReport
 */
class dmExpenseReport extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'date_debut'        => 'date_start',
		'date_fin'          => 'date_end',
		'date_create'       => 'created_at',
		'tms'               => 'updated_at',
		'date_valid'        => 'validated_at',
		'date_approve'      => 'approved_at',
		'date_refuse'       => 'refused_at',
		'date_cancel'       => 'cancelled_at',
		'fk_user_author'    => 'user',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_approve'   => 'approved_by',
		'fk_user_refuse'    => 'refused_by',
		'fk_user_cancel'    => 'cancelled_by',
		'fk_user_validator' => 'validator',
		'fk_c_paiement'     => 'payment_method',
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_localtax1'   => 'total_local_tax1',
		'total_localtax2'   => 'total_local_tax2',
		'total_ttc'         => 'total_incl_tax',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'detail_refuse'     => 'refuse_reason',
		'detail_cancel'     => 'cancel_reason',
		'status'            => 'status',
		'paid'              => 'paid',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'ExpenseReportLine';
	protected $parentLabelForLines = 'ExpenseReportLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getExpenseReportLinesMapping();
		$this->boot();
	}
}
