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

require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';

/**
 * Mapping for Dolibarr MultiCurrency -> API Multicurrency
 */
class dmMulticurrency extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'MultiCurrency';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// Note: $this->rate is NOT exposed. MultiCurrency::fetch() does not
	// populate it -- the rate lives in llx_multicurrency_rate and is
	// fetched separately through MultiCurrency::fetchAllCurrencyRate()
	// or fetchCurrencyRate(). When populated, $this->rate is a
	// CurrencyRate OBJECT, not a numeric value, so a flat mapping would
	// emit malformed payloads. Consumers needing the rate must use a
	// dedicated endpoint backed by the CurrencyRate class.
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'code'              => 'code',
		'name'              => 'name',
		'date_create'       => 'created_at',
		'fk_user'           => 'created_by',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'code',
		'name',
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
