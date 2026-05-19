<?php

/**
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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

require_once DOL_DOCUMENT_ROOT . '/societe/class/companybankaccount.class.php';

/**
 * Mapping for Dolibarr CompanyBankAccount -> API CompanyBankAccount
 *
 * Represents a third-party RIB (llx_societe_rib): bank information
 * attached to a customer or supplier. CompanyBankAccount extends Account
 * in Dolibarr but lives in a different table and exposes SEPA-mandate
 * fields (rum, date_rum, frstrecur) that have no meaning on a company
 * bank account. Keeping a dedicated mapper avoids polluting
 * dmBankAccount with third-party-specific columns.
 */
class dmCompanyBankAccount extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'CompanyBankAccount';

	protected $parentTableElementToUseForExtraFields = 'societe_rib';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'           => 'id',
		'socid'           => 'thirdparty_id',
		'label'           => 'label',
		'bank'            => 'bank',
		'iban'            => 'iban',
		'bic'             => 'bic',
		'number'          => 'number',
		'code_banque'     => 'code_banque',
		'code_guichet'    => 'code_guichet',
		'cle_rib'         => 'cle_rib',
		'currency_code'   => 'currency_code',
		'default_rib'     => 'default_rib',
		'frstrecur'       => 'frstrecur',
		'rum'             => 'rum',
		'date_rum'        => 'date_rum',
		'proprio'         => 'proprio',
		'owner_address'   => 'owner_address',
		'datec'           => 'datec',
		'datem'           => 'datem',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// 'datec' / 'datem' are audit columns, never writable from the API.
	protected $writableFields = [
		'socid',
		'label',
		'bank',
		'iban',
		'bic',
		'number',
		'code_banque',
		'code_guichet',
		'cle_rib',
		'currency_code',
		'default_rib',
		'frstrecur',
		'rum',
		'date_rum',
		'proprio',
		'owner_address',
	];

	public function __construct()
	{
		$this->boot();
	}
}
