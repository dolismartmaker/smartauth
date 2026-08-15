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

require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

/**
 * Mapping for Dolibarr Subscription -> API Subscription (Member subscription)
 */
class dmSubscription extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Subscription';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'fk_adherent'       => 'member',
		'fk_type'           => 'member_type',
		'datec'             => 'created_at',
		'datem'             => 'updated_at',
		'dateh'             => 'date_start',
		'datef'             => 'date_end',
		'amount'            => 'amount',
		'fk_bank'           => 'bank_line',
		'note'              => 'note',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	//
	// `fk_bank` IS WRITABLE ON UPDATE ONLY, and that asymmetry comes from the
	// core: Subscription::create() (l.159) lists its INSERT columns by hand --
	// (fk_adherent, fk_type, datec, dateadh, datef, subscription, note) -- and
	// fk_bank is not one of them, while update() (l.284) does write it. So a
	// POST carrying bank_line answers 201 and drops the value; the same value
	// sent in a PATCH is persisted.
	//
	// It is kept writable rather than removed, because removing it would close
	// the ONLY way to reconcile a subscription with its bank line, which is the
	// path that actually works. importMappedData() has no notion of "create" vs
	// "update" (ObjectController calls it identically on both routes, l.340 and
	// l.444), so there is no way to reject it on the create route alone without
	// inventing a mechanism. Callers that need the link must POST then PATCH.
	// DmMemberMapperTest::testSubscriptionCreateIgnoresTheBankLine pins the
	// behaviour so this note cannot silently rot.
	//
	// `fk_adherent` IS DELIBERATELY ABSENT, and that is a TENANT ISOLATION fix.
	// llx_subscription has no entity column: a subscription belongs to a tenant
	// only through its member, which is exactly what isolationWhereSql() below
	// expresses. But that predicate is evaluated on the row AS IT STANDS -- the
	// facade probes it in ObjectController::update() l.428, BEFORE the payload
	// is even parsed (importMappedData l.444, applyImportedFields l.462). So it
	// protects reading, editing and deleting a FOREIGN subscription, and does
	// nothing against MOVING a local one to a foreign member. With the field
	// writable, PATCH objects/subscription/{id} {"member": <victim>} reached
	// Subscription::update(), which rewrites the column from memory (l.278) then
	// fetches the DESIGNATED member and calls update_end_date() (l.292-293) --
	// a write into llx_adherent.datefin of another tenant. The same field on
	// POST inserted the fee straight into the victim's record (create() l.170).
	//
	// The hook that could have vetted the target, canAccess($object, $user,
	// $mode), cannot: ObjectController::update() calls it at l.425 through
	// visibilityDenies(), on the object returned by fetch($id) -- the PRE
	// mutation row -- and it never sees the incoming payload. Removing the field
	// is therefore the only correction that closes the update path at its root,
	// and it costs nothing: a fee's parent is not a facade concern. Every write
	// goes through the local routes member/{id}/subscription[/{sid}], which take
	// the parent from the URL and guard it, and no consumer sends `member` on
	// objects/subscription. A body carrying it now answers 400 with a named
	// error (importMappedData rejects unknown keys, dmTrait l.491-500) instead
	// of being silently honoured. See canCreate() for the POST side.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	//
	// fk_type targets llx_adherent_type, which carries an entity column and is
	// filled by each tenant -- not a dictionary. No ObjectRegistry entry, hence
	// the explicit table/element spec.
	//
	// fk_bank targets llx_bank, which has NO entity column: the guard replays
	// dmBank::isolationWhereSql(), i.e. the very predicate the read routes use,
	// so a fee cannot be attached to a bank line of another tenant.
	protected $foreignKeyGuards = [
		'fk_type' => ['table' => 'adherent_type', 'element' => 'adherent_type'],
		'fk_bank' => 'bank_transaction',
	];

	protected $writableFields = [
		'fk_type',
		'dateh',
		'datef',
		'amount',
		'fk_bank',
		'note',
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

	/**
	 * Pre-create authorization (facade mechanism 6.5), used here as a
	 * FAIL-CLOSED refusal of the generic create route.
	 *
	 * Counterpart of the removal of `fk_adherent` from $writableFields. A
	 * subscription with no member is not a degraded object, it is a broken one:
	 * Subscription::create() interpolates `(int) $this->fk_adherent` into its
	 * INSERT unconditionally (l.170), so an unset parent lands as 0. The row is
	 * then invisible for good -- isolationWhereSql() requires an llx_adherent
	 * whose rowid matches, and no member has rowid 0 -- while the route answers
	 * 201. That is exactly the silent-garbage outcome the project forbids, so
	 * the route is closed instead of being left to manufacture orphans.
	 *
	 * Creating a membership fee is served by the local routes
	 * POST member/{id}/subscription, whose parent comes from the URL and is
	 * tenant-guarded before anything is written.
	 *
	 * ObjectController::create() turns a false into a 403 (l.352-355); the log
	 * line below carries the real reason, which is not an authorization one.
	 *
	 * @param  \stdClass $sanitized  Output of importMappedData() (unused: the
	 *                               refusal does not depend on the payload).
	 * @param  \User     $user       Authenticated user.
	 * @return bool                  Always false.
	 */
	public function canCreate($sanitized, $user)
	{
		dol_syslog(
			'[SmartAuth] dmSubscription::canCreate refused: objects/subscription cannot create a fee because'
				. ' its parent member is not writable through the facade (tenant isolation); use the local route'
				. ' member/{id}/subscription, which carries the guarded parent in its URL. user='
				. ((int) (is_object($user) ? $user->id : 0)),
			LOG_WARNING
		);

		return false;
	}

	/**
	 * Explicit filterable columns (facade mechanism 6.5).
	 *
	 * Keys are the camelCase catalog keys the frontend sends, values the real
	 * llx_subscription columns. Three published keys are properties that
	 * Subscription::fetch() (l.220 and l.236-239) renames from the column it
	 * selects -- `dateh` from dateadh, `amount` from subscription, `datem` from
	 * tms -- so the catalog derivation leaves them non filterable, and a
	 * subscription list could not be filtered on its start date nor on its
	 * amount. The other entries repeat what the catalog derives, to keep the
	 * readable contract in one place and to pin the filter kinds.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'member'     => ['column' => 'fk_adherent', 'kind' => 'select'],
			'memberType' => ['column' => 'fk_type', 'kind' => 'select'],
			'bankLine'   => ['column' => 'fk_bank', 'kind' => 'select'],
			'amount'     => ['column' => 'subscription', 'kind' => 'numberrange'],
			'dateStart'  => ['column' => 'dateadh', 'kind' => 'daterange'],
			'dateEnd'    => ['column' => 'datef', 'kind' => 'daterange'],
			'createdAt'  => ['column' => 'datec', 'kind' => 'daterange'],
			'updatedAt'  => ['column' => 'tms', 'kind' => 'daterange'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 6.5). Same rationale as
	 * getFilterableColumns(): API key -> real SQL column.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'member'     => 'fk_adherent',
			'memberType' => 'fk_type',
			'bankLine'   => 'fk_bank',
			'amount'     => 'subscription',
			'dateStart'  => 'dateadh',
			'dateEnd'    => 'datef',
			'createdAt'  => 'datec',
			'updatedAt'  => 'tms',
		];
	}

	/**
	 * TENANT ISOLATION for a table with NO 'entity' column.
	 *
	 * llx_subscription is the second core table (with llx_stock_mouvement) that
	 * carries no entity, so the registry flags it has_entity=false and the
	 * generic list/count would otherwise serve EVERY tenant's subscriptions. A
	 * subscription always belongs to a member, and llx_adherent IS entity-scoped:
	 * scope through it.
	 *
	 * Consumed by ObjectFacadeTrait::isolationWhereFragment() (list/count) and
	 * ::isolationDenies() (show/update/destroy row probe).
	 *
	 * @param  string $alias  SQL alias of llx_subscription in the host query.
	 * @param  object $db     DoliDB (unused: no user input goes into the fragment).
	 * @return string         SQL fragment starting with " AND ".
	 */
	public function isolationWhereSql($alias, $db)
	{
		$a = (string) $alias;
		if ($a === '') {
			$a = 'sub';
		}

		$sql = " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "adherent as isol_a";
		$sql .= " WHERE isol_a.rowid = " . $a . ".fk_adherent";
		$sql .= " AND isol_a.entity IN (" . getEntity('adherent') . "))";

		return $sql;
	}
}
