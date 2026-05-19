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

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

/**
 * Mapping for Dolibarr Account -> API BankAccount
 * Alias: dmAccount (for backward compatibility with Dolibarr internal calls)
 *
 * Represents a company's bank account (llx_bank_account). For
 * third-party RIBs (customer / supplier bank info), see
 * dmCompanyBankAccount which maps the CompanyBankAccount subclass.
 */
class dmBankAccount extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Account';

	protected $parentTableElementToUseForExtraFields = 'bank_account';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'                  => 'id',
		'ref'                    => 'ref',
		'label'                  => 'label',
		'bank'                   => 'bank',
		'courant'                => 'courant',
		'type'                   => 'type',
		'iban'                   => 'iban',
		'bic'                    => 'bic',
		'number'                 => 'number',
		'code_banque'            => 'code_banque',
		'code_guichet'           => 'code_guichet',
		'cle_rib'                => 'cle_rib',
		'currency_code'          => 'currency_code',
		'country_id'             => 'country_id',
		'clos'                   => 'status',
		'rappro'                 => 'rappro',
		'url'                    => 'url',
		'comment'                => 'comment',
		'account_number'         => 'account_number',
		'fk_accountancy_journal' => 'fk_accountancy_journal',
		'proprio'                => 'proprio',
		'owner_address'          => 'owner_address',
		'owner_zip'              => 'owner_zip',
		'owner_town'             => 'owner_town',
		'owner_country_id'       => 'owner_country_id',
		'min_allowed'            => 'min_allowed',
		'min_desired'            => 'min_desired',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// 'clos' is intentionally excluded per Rule 1 strict (status = state machine);
	// closing an account goes through Account::setStatut().
	// 'solde' and 'balance' are computed columns and never writable.
	protected $writableFields = [
		'ref',
		'label',
		'bank',
		'courant',
		'type',
		'iban',
		'bic',
		'number',
		'code_banque',
		'code_guichet',
		'cle_rib',
		'currency_code',
		'country_id',
		'rappro',
		'url',
		'comment',
		'account_number',
		'fk_accountancy_journal',
		'proprio',
		'owner_address',
		'owner_zip',
		'owner_town',
		'owner_country_id',
		'min_allowed',
		'min_desired',
	];

	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmBankAccount', 'SmartAuth\DolibarrMapping\dmAccount');
