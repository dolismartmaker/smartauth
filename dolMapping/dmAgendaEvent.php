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

require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

/**
 * Mapping for Dolibarr ActionComm (agenda event) -> API AgendaEvent.
 * Alias: dmActionComm (for backward compatibility with Dolibarr internal calls).
 *
 * Backs the generic object facade objects/agenda_event (CRUD show/create/update/
 * delete). The calendar READ path (windowed list, delta-sync, birthdays, buckets,
 * counts, filter-options) stays a dedicated Dolipocket-local controller -- it has
 * no facade equivalent -- so this mapper only has to serve single-object CRUD.
 *
 * IMPORTANT -- doliside keys are PHP PROPERTY names, NOT SQL column names. That
 * distinction is the "doliside pitfall": ActionComm::fetch() aliases several
 * columns onto differently named properties, and ActionComm::create()/update()
 * read those SAME properties (not the columns) when persisting. exportMappedData()
 * reads $obj->{doliside} and applyImportedFields() writes $object->{doliside}, so
 * BOTH paths must address the property:
 *
 *   SQL column        PHP property (doliside used here)
 *   ----------        --------------------------------
 *   percent       ->  percentage
 *   fk_soc        ->  socid
 *   fk_contact    ->  contact_id
 *   fk_project    ->  fk_project        (same name, but NOT fk_projet)
 *   fk_user_action->  userownerid       (the assigned/owner user)
 *   fk_user_author->  authorid
 *   datep2        ->  datef
 *
 * Verified against htdocs/comm/action/class/actioncomm.class.php: fetch() lines
 * ~835-890, create() lines ~439-600, update() lines ~1120-1211.
 *
 * The assigned user needs extra care: create() REQUIRES $userownerid (returns -1
 * otherwise) and update() DELETEs+reINSERTs the actioncomm_resources rows from
 * $userassigned (wiping them when empty), so applyImportedFields() keeps
 * userownerid + userassigned consistent.
 */
class dmAgendaEvent extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'ActionComm';

	// Element name for file storage / ECM ($object->element).
	protected $parentElementToUseForExtraFields = 'actioncomm';

	// Table-side element name for extrafields (= llx_extrafields.elementtype).
	protected $parentTableElementToUseForExtraFields = 'actioncomm';

	// Dolibarr property (doliside) => Front field (appside).
	// See documentation/api-naming-convention.md.
	protected $listOfPublishedFields = [
		'rowid'          => 'id',            // read-only (pk 'id', see registry pk)
		'ref'            => 'ref',           // read-only
		'label'          => 'label',
		'type_code'      => 'type_code',
		'type_label'     => 'type_label',    // read-only display label
		'datec'          => 'created_at',    // read-only
		'datep'          => 'date_start',
		'datef'          => 'date_end',
		'percentage'     => 'progress',
		'location'       => 'location',
		'fulldayevent'   => 'fulldayevent',
		'note_private'   => 'private_note',
		'userownerid'    => 'assigned_to',   // property, not the fk_user_action column
		'socid'          => 'thirdparty',    // property, not the fk_soc column
		'contact_id'     => 'contact',       // property, not the fk_contact column
		'fk_project'     => 'project',       // property (fk_project), not fk_projet
		'fk_element'     => 'fk_element',    // linked object id
		'elementtype'    => 'elementtype',   // linked object type
		'priority'       => 'priority',
		'status'         => 'status',
		'authorid'       => 'created_by',    // read-only (property, not fk_user_author)
	];

	// Allowlist for importMappedData() (Dolibarr-side PROPERTY names, i.e. the
	// LEFT side of $listOfPublishedFields). See documentation/SPEC_A_WRITABLEFIELDS.md.
	// `rowid`/`ref`/`type_label`/`datec`/`authorid` are intentionally absent
	// (read-only). `note_public` is NOT here because ActionComm has no public
	// note column (only `note` = note_private), so a writable public_note would
	// be a silent no-op.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	//
	// userownerid targets llx_user, whose element getEntity() prefixes with "0,"
	// (the $addzero list of htdocs/core/lib/functions.lib.php). An entity-0 user
	// is genuinely visible from every tenant, so owning an event to one stays
	// legal; only ANOTHER tenant's user is refused.
	//
	// fk_element is polymorphic: its table is named by the sibling elementtype
	// column, which ActionComm::create() partly rewrites on the way in
	// (facture -> invoice, commande -> order, contrat -> contract, l.470-478).
	// The resolver accepts both spellings and leaves an element type it does not
	// know unguarded, so a module linking an event to its OWN object is not
	// broken by this mechanism.
	protected $foreignKeyGuards = [
		'userownerid' => 'user',
		'socid'       => 'thirdparty',
		'contact_id'  => 'contact',
		'fk_project'  => 'project',
		'fk_element'  => ['polymorphic' => 'elementtype'],
	];

	protected $writableFields = [
		'label',
		'type_code',
		'datep',
		'datef',
		'percentage',
		'location',
		'fulldayevent',
		'note_private',
		'userownerid',
		'socid',
		'contact_id',
		'fk_project',
		'fk_element',
		'elementtype',
		'priority',
		'status',
	];

	/**
	 * object constructor
	 */
	public function __construct()
	{
		$this->boot();
	}

	/**
	 * Global-search columns for objects/agenda_event.
	 *
	 * ActionComm::$fields is EMPTY, so the generic dmBase::getSearchFields()
	 * (which requires the doliside to be declared in $object->fields) returns [].
	 * Narrow it to the two user-facing reference columns. Both are real
	 * llx_actioncomm varchar columns so `a.label LIKE ...` / `a.ref LIKE ...`
	 * stays SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['label', 'ref'];
	}

	/**
	 * Write the sanitized payload onto the ActionComm, then reconcile the few
	 * ActionComm-specific quirks the generic writer cannot know about.
	 *
	 * Runs right before CrudInvoker::create()/update():
	 *   - normalise datep/datef to Unix seconds (ActionComm::$fields is empty so
	 *     importMappedData() cannot type-cast a date; a stray ms value would be
	 *     mis-stored by idate()).
	 *   - reset type_id when type_code was sent so create()/update() re-resolve
	 *     the numeric type from the code (see actioncomm.class.php:494 / :1162).
	 *   - keep the assigned user coherent: create() REQUIRES userownerid and
	 *     update() rebuilds the resources table from userassigned. On a fresh
	 *     event default the owner to the current user (mirrors the former local
	 *     AgendaController), and default an empty type_code to 'AC_OTH' (create()
	 *     rejects an unknown/empty type). On update, preserve the fetched
	 *     userassigned unless a new owner was sent.
	 *
	 * @param  \ActionComm $object     Fresh (create) or fetched (update) event.
	 * @param  \stdClass   $sanitized  Output of importMappedData().
	 * @return void
	 */
	public function applyImportedFields($object, $sanitized)
	{
		global $user;

		parent::applyImportedFields($object, $sanitized);

		// Defensive date normalization for the fields the client actually sent.
		foreach (['datep', 'datef'] as $df) {
			if (property_exists($sanitized, $df)) {
				$ts = $this->normalizeToSeconds($object->{$df});
				$object->{$df} = ($ts !== null) ? $ts : '';
			}
		}

		// Changing the type by code. The two verbs need OPPOSITE treatments, and
		// treating them alike corrupted every update.
		//
		// create() resolves the numeric type from the code by itself
		// (actioncomm.class.php l.493-511: "if (!$this->type_id ||
		// !$this->type_code) { ... $cactioncomm->fetch($key); $this->type_id =
		// $cactioncomm->id; }"), so clearing the cached id is precisely what
		// triggers that branch. Keep doing it.
		//
		// update() has NO such branch. It only walks the other way, filling
		// type_code FROM type_id (l.1161-1169, and only when type_id > 0), then
		// persists "fk_action = ".(int) $this->type_id (l.1181). Clearing the id
		// there wrote fk_action = 0: the event lost its numeric type, and every
		// screen joining llx_c_actioncomm on fk_action then showed nothing.
		// It also kept the OLD `code` column, because update() only refreshes it
		// when $oldcopy is set (l.1174-1177) and the facade never sets one -- so
		// the type change did not take at all. Resolve both here.
		if (property_exists($sanitized, 'type_code')) {
			if (empty($object->id)) {
				$object->type_id = 0;
			} else {
				$resolvedId = $this->resolveActionTypeId((string) $object->type_code);
				if ($resolvedId > 0) {
					$object->type_id = $resolvedId;
					$object->code = (string) $object->type_code;
				} else {
					// Unknown code: keep the stored type rather than wiping it.
					// Writing 0 would be the same corruption by another route.
					dol_syslog(
						'[SmartAuth] dmAgendaEvent: unknown action type code "'
							. (string) $object->type_code . '" on event ' . ((int) $object->id)
							. ' - keeping the stored type',
						LOG_WARNING
					);
				}
			}
		}

		$isNew = empty($object->id);
		$ownerSent = property_exists($sanitized, 'userownerid')
			&& $sanitized->userownerid !== '' && $sanitized->userownerid !== null;

		if ($isNew) {
			// create() returns -1 without a defined owner: default to the caller.
			if (!$ownerSent || (int) $object->userownerid <= 0) {
				$object->userownerid = (int) $user->id;
			}
			// create() rejects an empty/unknown type code.
			if (empty($object->type_code)) {
				$object->type_code = 'AC_OTH';
				$object->type_id = 0;
			}
			$ownerId = (int) $object->userownerid;
			$object->userassigned = [$ownerId => ['id' => $ownerId, 'transparency' => 0]];
			return;
		}

		// Update: ActionComm::update() DELETEs then re-INSERTs the user resources
		// from $userassigned. fetch() (with its default $loadresources=1) already
		// populated it, so it is preserved as-is UNLESS the client sent a new
		// owner -- in which case rebuild it to that single owner so the persisted
		// fk_user_action column and the resources table stay in sync.
		if ($ownerSent) {
			$ownerId = (int) $object->userownerid;
			$object->userassigned = [$ownerId => ['id' => $ownerId, 'transparency' => 0]];
		} elseif (empty($object->userassigned) || !is_array($object->userassigned) || count($object->userassigned) === 0) {
			if (method_exists($object, 'fetchResources')) {
				$object->fetchResources();
			}
			if ((empty($object->userassigned) || !is_array($object->userassigned) || count($object->userassigned) === 0)
				&& !empty($object->userownerid)) {
				$ownerId = (int) $object->userownerid;
				$object->userassigned = [$ownerId => ['id' => $ownerId, 'transparency' => 0]];
			}
		}
	}

	/**
	 * Intra-tenant list/count visibility (mechanism 3.2): restrict to events the
	 * user OWNS or is ASSIGNED to, unless they hold agenda.allactions.read (or are
	 * admin). Replicates the former local AgendaController owned/assigned filter.
	 *
	 * @param  \User    $user
	 * @param  string   $alias  SQL alias of llx_actioncomm in the host query.
	 * @param  \DoliDB  $db
	 * @return string           SQL fragment (starts with " AND ") or ''.
	 */
	public function visibilitySqlFilter($user, $alias, $db)
	{
		if (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'read')) {
			return '';
		}
		$uid = (int) $user->id;
		// NB: the actioncomm primary key is `id` (registry pk='id'), so the
		// EXISTS correlates on {$alias}.id, not rowid.
		return ' AND (' . $alias . '.fk_user_action = ' . $uid
			. ' OR EXISTS (SELECT 1 FROM ' . MAIN_DB_PREFIX . 'actioncomm_resources ar'
			. ' WHERE ar.fk_actioncomm = ' . $alias . '.id AND ar.element_type = \'user\''
			. ' AND ar.fk_element = ' . $uid . '))';
	}

	/**
	 * Intra-tenant per-object visibility for show / update / destroy
	 * (mechanism 3.2). An event is accessible iff the user OWNS it or is ASSIGNED
	 * to it, unless they hold agenda.allactions.read (or are admin).
	 *
	 * @param  \ActionComm $object
	 * @param  \User       $user
	 * @param  string      $mode    read|write|delete
	 * @return bool                 false REFUSES (the controller emits a 403).
	 */
	public function canAccess($object, $user, $mode)
	{
		global $db;

		if (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'read')) {
			return true;
		}

		$uid = (int) $user->id;

		// Owner: fetch() exposes the owner via $userownerid (from the
		// fk_user_action column). Fall back to fk_user_action for a raw row.
		$ownerId = 0;
		if (isset($object->userownerid) && $object->userownerid !== '') {
			$ownerId = (int) $object->userownerid;
		} elseif (isset($object->fk_user_action)) {
			$ownerId = (int) $object->fk_user_action;
		}
		if ($ownerId > 0 && $ownerId === $uid) {
			return true;
		}

		// Assigned via the resources table (element_type='user').
		$eventId = (int) ($object->id ?? 0);
		if ($eventId <= 0) {
			dol_syslog('[SmartAuth] dmAgendaEvent::canAccess event has no id', LOG_WARNING);
			return false;
		}
		$sql = 'SELECT COUNT(*) as nb FROM ' . MAIN_DB_PREFIX . 'actioncomm_resources';
		$sql .= " WHERE fk_actioncomm = " . $eventId . " AND element_type = 'user'";
		$sql .= ' AND fk_element = ' . $uid;
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog('[SmartAuth] dmAgendaEvent::canAccess resources SQL failed: ' . $db->lasterror(), LOG_ERR);
			return false;
		}
		$row = $db->fetch_object($resql);
		$db->free($resql);
		return $row !== null && (int) $row->nb > 0;
	}

	/**
	 * Numeric id of an action type, from its dictionary code.
	 *
	 * Used on the UPDATE path only: ActionComm::update() never resolves it (see
	 * applyImportedFields), so the mapper must. CActionComm::fetch() accepts
	 * either an id or a code -- it branches on is_numeric (cactioncomm.class.php
	 * l.111-120) -- which is exactly how ActionComm::create() resolves it too.
	 *
	 * @param  string $code  Dictionary code, e.g. 'AC_TEL'.
	 * @return int           The id, or 0 when the code is unknown.
	 */
	private function resolveActionTypeId($code)
	{
		global $db;

		$code = trim((string) $code);
		if ($code === '') {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT . '/comm/action/class/cactioncomm.class.php';
		$dict = new \CActionComm($db);
		if ($dict->fetch($code) <= 0) {
			return 0;
		}

		return (int) $dict->id;
	}

	/**
	 * Normalise a date/timestamp value to Unix seconds.
	 *
	 * Accepts seconds, milliseconds (12+ digits), ISO/human strings. Returns null
	 * on empty / invalid input (caller decides on a default).
	 *
	 * @param  mixed $value
	 * @return int|null
	 */
	private function normalizeToSeconds($value)
	{
		if ($value === null || $value === '' || $value === false) {
			return null;
		}
		if (is_numeric($value)) {
			$n = (int) $value;
			if ($n > 99999999999) {
				return intdiv($n, 1000);
			}
			return $n;
		}
		$ts = strtotime((string) $value);
		return $ts === false ? null : $ts;
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmAgendaEvent', 'SmartAuth\DolibarrMapping\dmActionComm');
