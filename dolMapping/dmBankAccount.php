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

	/**
	 * Searchable columns. Account::$fields is empty, so the generic catalog
	 * cannot derive them, and two mapper keys are PHP properties whose SQL
	 * column has a different name (see getFilterableColumns below).
	 *
	 * @return string[]
	 */
	public function getSearchFields()
	{
		return ['ref', 'label', 'bank', 'number'];
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5).
	 *
	 * Two reasons the catalog cannot derive them. Account::$fields is empty,
	 * AND Account::fetch() renames three columns onto PHP properties the mapper
	 * addresses for the READ export:
	 *   - 'type' and 'courant' both read the SQL column `courant`;
	 *   - 'iban' reads `iban_prefix` (aliased "as iban" in the fetch query);
	 *   - 'country_id' reads `fk_pays` (aliased "as country_id").
	 * Emitting the API key as a column name would produce "Unknown column".
	 *
	 * Keys are the front-side keys of $listOfPublishedFields.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'ref'           => ['column' => 'ref', 'kind' => 'text'],
			'label'         => ['column' => 'label', 'kind' => 'text'],
			'bank'          => ['column' => 'bank', 'kind' => 'text'],
			'number'        => ['column' => 'number', 'kind' => 'text'],
			'iban'          => ['column' => 'iban_prefix', 'kind' => 'text'],
			'bic'           => ['column' => 'bic', 'kind' => 'text'],
			'type'          => ['column' => 'courant', 'kind' => 'select'],
			'courant'       => ['column' => 'courant', 'kind' => 'select'],
			'status'        => ['column' => 'clos', 'kind' => 'select'],
			'rappro'        => ['column' => 'rappro', 'kind' => 'boolean'],
			'currency_code' => ['column' => 'currency_code', 'kind' => 'select'],
			'country_id'    => ['column' => 'fk_pays', 'kind' => 'select'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 3.5). Same rationale as
	 * getFilterableColumns(): API key -> real SQL column.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'id'            => 'rowid',
			'ref'           => 'ref',
			'label'         => 'label',
			'bank'          => 'bank',
			'number'        => 'number',
			'type'          => 'courant',
			'courant'       => 'courant',
			'status'        => 'clos',
			'currency_code' => 'currency_code',
			'account_number' => 'account_number',
		];
	}

	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmBankAccount', 'SmartAuth\DolibarrMapping\dmAccount');
