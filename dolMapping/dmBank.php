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
	protected $listOfPublishedFields = [
		'rowid'                => 'id',
		'ref'                  => 'ref',
		'datec'                => 'created_at',
		'dateo'                => 'operation_date',
		'datev'                => 'value_date',
		'amount'               => 'amount',
		'amount_main_currency' => 'amount_main_currency',
		'label'                => 'label',
		'fk_account'           => 'fk_account',
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

	// Allowlist for importMappedData() (Dolibarr field names).
	// Amount/dateo/fk_account are NOT writable: a bank line is created
	// by Account::addline() or by a payment workflow, never directly.
	// 'rappro' (conciliation flag) is also write-protected: conciliation
	// goes through AccountLine::confirm().
	protected $writableFields = [
		'label',
		'note',
		'num_chq',
		'bank_chq',
		'num_releve',
	];

	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmBank', 'SmartAuth\DolibarrMapping\dmAccountLine');
