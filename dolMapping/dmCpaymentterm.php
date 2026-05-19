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

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/paymentterm.class.php';

/**
 * Mapping for Dolibarr c_payment_term dictionary -> API PaymentTerm
 */
class dmCpaymentterm extends dmBase
{
	use dmTrait;

	protected $type = "dict";
	protected $dolibarrClassName = 'PaymentTerm';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'code'              => 'code',
		'sortorder'         => 'position',
		'active'            => 'active',
		'libelle'           => 'label',
		'libelle_facture'   => 'invoice_label',
		'type_cdr'          => 'calculation_type',
		'nbjour'            => 'days',
		'decalage'          => 'offset',
	];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->boot();
	}
}
