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
 * Mapping for Dolibarr AccountLine -> API BankTransaction
 * Alias: dmAccountLine (for backward compatibility with Dolibarr internal calls)
 *
 * AccountLine represents one line in the bank transaction journal
 * (llx_bank). Each line is one credit or debit on an Account. The
 * AccountLine class lives at the bottom of account.class.php (the
 * require_once at the top of this file loads both classes).
 *
 * Audit-oriented: most fields are read-only because lines are written
 * by Dolibarr's payment / reconciliation workflows, not by the API.
 * Only label, note and operation metadata are reasonably writable
 * after the fact.
 */
class dmBank extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'AccountLine';

	protected $parentTableElementToUseForExtraFields = 'bank';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// Note: 'numero_compte' and 'emetteur' are NOT exposed. The columns
	// exist in llx_bank but AccountLine::fetch (account.class.php) has
	// a latent bug: 'emetteur' is SELECTed but never assigned to
	// $this->emetteur, and 'numero_compte' is not even SELECTed. Both
	// PHP properties stay null after fetch, so emitting them as null
	// keys in the API payload would mislead consumers. Skip until the
	// Dolibarr fetch is fixed upstream.
	// Note: 'amount_main_currency' is NOT exposed either, for the same reason
	// as the two above: the column exists in llx_bank but AccountLine::fetch()
	// never assigns it, so the property stays null. Since ObjectController
	// exports every row through fetch() (it SELECTs rowids then hydrates each
	// object), the key would be a constant null in list AND in show.
	protected $listOfPublishedFields = [
		'rowid'                => 'id',
		'ref'                  => 'ref',
		'datec'                => 'created_at',
		'dateo'                => 'operation_date',
		'datev'                => 'value_date',
		'amount'               => 'amount',
		'label'                => 'label',
		'fk_account'           => 'fk_account',
		'bank_account_ref'     => 'bank_account_ref',
		'bank_account_label'   => 'bank_account_label',
		'fk_type'              => 'fk_type',
		'fk_user_author'       => 'fk_user_author',
		'fk_user_rappro'       => 'fk_user_rappro',
		'fk_bordereau'         => 'fk_bordereau',
		'rappro'               => 'rappro',
		'num_releve'           => 'num_releve',
		'num_chq'              => 'num_chq',
		'bank_chq'             => 'bank_chq',
		'note'                 => 'note',
	];

	// Allowlist for importMappedData(): INTENTIONALLY EMPTY.
	//
	// A bank line is never written through the generic facade. Two reasons,
	// both verified against Dolibarr 18.0.8:
	//
	//  1. AccountLine::update() writes ONLY amount, datev and dateo -- none of
	//     the fields a caller would want to edit. Declaring label/note/num_chq
	//     writable made PATCH a silent no-op: the properties were set, the SQL
	//     never mentioned them, and the API answered 200.
	//  2. Worse, that same update() would then rewrite the three columns it DOES
	//     handle from a freshly fetched object -- and fetch() stores datev/dateo
	//     as raw database strings, not timestamps, so idate() would mangle both
	//     dates on any update.
	//
	// The real edit path is the direct SQL of compta/bank/line.php, which also
	// enforces the rules the class does not: label, amount and both dates are
	// frozen once the line is reconciled, and the line may only change account
	// when it is neither reconciled nor already exported to accounting. That
	// path lives in the Dolipocket BankController.
	protected $writableFields = [];

	/**
	 * Searchable columns. AccountLine::$fields is empty, so the generic catalog
	 * cannot derive them.
	 *
	 * @return string[]
	 */
	public function getSearchFields()
	{
		return ['label', 'num_chq', 'num_releve', 'note'];
	}

	/**
	 * Isolation predicate for a table with NO 'entity' column (registry flags
	 * bank_transaction as has_entity => false).
	 *
	 * llx_bank carries no entity of its own; a line belongs to a tenant through
	 * its bank account, which IS entity-scoped. This is the same predicate
	 * AccountLine::fetch() applies as an INNER JOIN -- but fetch() only protects
	 * the single-row read. Without this fragment the list would enumerate every
	 * tenant's rowids, report a global COUNT, and return short pages (each
	 * foreign row silently dropped when its fetch fails).
	 *
	 * Consumed by ObjectFacadeTrait::isolationWhereFragment() (list/count) and
	 * ::isolationDenies() (show/update/destroy row probe).
	 *
	 * @param  string $alias  SQL alias of llx_bank in the host query.
	 * @param  object $db     DoliDB (unused: no user input goes into the fragment).
	 * @return string         SQL fragment starting with " AND ".
	 */
	public function isolationWhereSql($alias, $db)
	{
		$a = (string) $alias;
		if ($a === '') {
			$a = 'b';
		}

		$sql = " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "bank_account as isol_ba";
		$sql .= " WHERE isol_ba.rowid = " . $a . ".fk_account";
		$sql .= " AND isol_ba.entity IN (" . getEntity('bank_account') . "))";

		return $sql;
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5).
	 *
	 * AccountLine::$fields is empty, and two mapper keys are PHP properties
	 * whose SQL column has a different name: 'bank_chq' reads the `banque`
	 * column, and 'ref' is the rowid (fetch() assigns $this->ref = $obj->rowid).
	 * 'bank_account_ref' and 'bank_account_label' come from a JOIN and are
	 * deliberately absent: they are display-only, not columns of llx_bank.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'label'          => ['column' => 'label', 'kind' => 'text'],
			'note'           => ['column' => 'note', 'kind' => 'text'],
			'num_chq'        => ['column' => 'num_chq', 'kind' => 'text'],
			'bank_chq'       => ['column' => 'banque', 'kind' => 'text'],
			'num_releve'     => ['column' => 'num_releve', 'kind' => 'text'],
			'fk_account'     => ['column' => 'fk_account', 'kind' => 'select'],
			'fk_type'        => ['column' => 'fk_type', 'kind' => 'select'],
			'rappro'         => ['column' => 'rappro', 'kind' => 'boolean'],
			'amount'         => ['column' => 'amount', 'kind' => 'numberrange'],
			'operation_date' => ['column' => 'dateo', 'kind' => 'daterange'],
			'value_date'     => ['column' => 'datev', 'kind' => 'daterange'],
			'created_at'     => ['column' => 'datec', 'kind' => 'daterange'],
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
			'id'             => 'rowid',
			'ref'            => 'rowid',
			'label'          => 'label',
			'amount'         => 'amount',
			'operation_date' => 'dateo',
			'value_date'     => 'datev',
			'created_at'     => 'datec',
			'num_chq'        => 'num_chq',
			'num_releve'     => 'num_releve',
			'rappro'         => 'rappro',
			'fk_account'     => 'fk_account',
			'fk_type'        => 'fk_type',
		];
	}

	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmBank', 'SmartAuth\DolibarrMapping\dmAccountLine');
