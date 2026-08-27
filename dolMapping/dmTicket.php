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

require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

/**
 * Mapping for Dolibarr Ticket -> API Ticket
 *
 * Everything below is anchored on Dolibarr 18.0.8:
 *   - htdocs/ticket/class/ticket.class.php
 *   - htdocs/install/mysql/tables/llx_ticket-ticket.sql (the ticket module
 *     ships its own schema file, suffixed with the module name)
 *
 * Three facts shape this mapper.
 *
 * 1. THE TABLE HAS NO NOTE COLUMNS. llx_ticket-ticket.sql (l.17-46) declares
 *    28 columns and neither note_public nor note_private is among them;
 *    update() (l.985-1006) never writes them either. Both used to be published
 *    AND writable here, which made a note write a silent no-op: the request
 *    answered 200 and the value vanished. They are gone from this allowlist,
 *    which is the only one: the ObjectRegistry 'ticket' entry declares no
 *    write allowlist of its own. The same reasoning removed `email_from`: it IS a
 *    declared property (l.169) but no column, and fetch() (l.604-720) never
 *    hydrates it -- ticket/card.php (l.234) only fills it in memory just before
 *    createTicketMessage() so the message row carries the sender address.
 *    Published, it could only ever export null, and exportMappedData() skips
 *    null, so no payload ever carried the key.
 *
 * 2. fetch() RENAMES THE STATUS. l.629 SELECTs "t.fk_statut as status" and
 *    l.683-684 assign $this->status (then $this->fk_statut for backward
 *    compatibility). The published doliside is therefore the PHP property
 *    `status`, which is NOT a column: dmBase::getColumnCatalog() keys
 *    filterable/sortable on isset(Ticket::$fields[$doliside]) and $fields
 *    declares `fk_statut` (l.323), not `status`. Without the explicit maps at
 *    the bottom of this class, filtering or sorting a ticket list by status was
 *    silently dropped -- the single most useful axis of a support queue.
 *
 * 3. CREATION CANNOT GO THROUGH THE FACADE. create() (l.474) calls verify(),
 *    which appends 'ErrorTicketRefRequired' when $this->ref is empty
 *    (l.444-447) and makes create() return -3 (l.591). create() does not
 *    generate the ref -- the generator is getDefaultRef() (l.2288) and nothing
 *    in create() calls it -- `ref` is deliberately absent from $writableFields
 *    (a client must not choose an identity), and ObjectController::create() has
 *    no pre-create hook: canCreate() runs BEFORE applyImportedFields(),
 *    postCreate() runs AFTER CrudInvoker::create(). So POST objects/ticket can
 *    only ever answer 400. The consuming module owns a local POST route that
 *    sets getDefaultRef() then calls create() -- exactly what the native REST
 *    API does in ticket/class/api_tickets.class.php.
 *
 * KNOWN INTRA-TENANT GAP, DELIBERATELY NOT IMPLEMENTED HERE. Reading through
 * the facade, any user holding the `ticket read` right sees EVERY ticket of his
 * entity. The core is narrower, in three independent ways:
 *   - ticket/list.php l.450-456: a non-admin is restricted to
 *     "fk_user_assign = me" when TICKET_LIMIT_VIEW_ASSIGNED_ONLY is set, and to
 *     "assigned to me OR created by me" in the "mine" mode;
 *   - Ticket::fetchAll() (l.785-786) and ticket/class/api_tickets.class.php
 *     (l.229) additionally join llx_societe_commerciaux when the user lacks
 *     `societe->client->voir`, so he only sees the tickets of the thirdparties
 *     he is a sales representative for -- note that list.php does NOT do this,
 *     the core is inconsistent with itself;
 *   - list.php l.163 and l.373: an external user ($user->socid) is confined to
 *     his own thirdparty.
 * This is NOT a cross-tenant leak: the entity clause always applies. It is the
 * same intra-tenant divergence already on the backlog for thirdparties, and it
 * is written here so nobody reads the absence of a visibilitySqlFilter() as
 * "the restriction must be implemented elsewhere". Closing it means declaring
 * visibilitySqlFilter($user, $alias, $db) plus canAccess($object, $user, $mode)
 * (facade mechanism 6.5), and it has to ship together with a screen managing
 * llx_societe_commerciaux, otherwise a non-admin ends up with an empty list and
 * no way out.
 */
class dmTicket extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Ticket';

	// Metadata repairs for the doliside keys that address a PHP property with
	// no Ticket::$fields entry. Without them dmTrait::_getFieldDefinition()
	// falls back to "varchar(255) / Ucfirst(property name) / visible=1", so
	// describe() would advertise the status as an editable free-text field
	// labelled "Status" (untranslated), and a form built from it would offer to
	// edit values that $writableFields rejects with a 400.
	//
	// visible=5 is "list and view only, not create/update" (cf
	// dmHelper::_customFilterAttributeVisible l.375): correct for all five,
	// since none of them is writable. Labels are Dolibarr translation keys
	// reused verbatim from Ticket::$fields so the front gets the same wording as
	// the native screens. 'Timing' has no translation entry in the core (its
	// $fields line is commented out, l.311) and falls back to the key itself.
	//
	// NOTE: this override feeds objectDesc() ONLY. getColumnCatalog() reads the
	// RAW Ticket::$fields to decide whether a doliside is a real column, so
	// these entries cannot accidentally make the facade emit
	// "WHERE tk.status = ..." on a column that does not exist. The real SQL
	// columns are declared in getFilterableColumns() / getSortableColumns().
	protected $parentFieldsOverride = [
		'status'         => ['type' => 'integer', 'label' => 'Status', 'visible' => 5],
		'type_label'     => ['type' => 'varchar(128)', 'label' => 'Type', 'visible' => 5],
		'category_label' => ['type' => 'varchar(128)', 'label' => 'TicketCategory', 'visible' => 5],
		'severity_label' => ['type' => 'varchar(128)', 'label' => 'Severity', 'visible' => 5],
		'timing'         => ['type' => 'varchar(20)', 'label' => 'Timing', 'visible' => 5],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// IMPORTANT (doliside): the LEFT keys are the PHP properties Ticket
	// actually fills, NOT always the SQL column names. `status` is the
	// prominent case (SQL fk_statut) and the three *_label keys are JOIN
	// results, not columns at all. Sorting and filtering therefore go through
	// the explicit maps at the bottom of this class.
	protected $listOfPublishedFields = [
		'rowid'              => 'id',
		'ref'                => 'ref',
		// Public tracking hash, generated by create() (l.468-470) when blank.
		// Read-only on purpose: it identifies the ticket on the public
		// interface, a client must never be able to forge it.
		'track_id'           => 'track_id',
		'subject'            => 'subject',
		'message'            => 'message',
		'datec'              => 'created_at',
		// fetch() hydrates $this->tms (l.708) and $this->socid (l.672) although
		// Ticket declares NEITHER property: reading a ticket emits two PHP 8.2
		// "Creation of dynamic property" deprecations, from the core, on every
		// fetch. Harmless in production, but a test harness that escalates
		// deprecations to errors will trip on them -- expect them, they are not
		// caused by this mapper.
		'tms'                => 'updated_at',
		'date_read'          => 'read_at',
		'date_close'         => 'closed_at',
		// Real column, but Ticket::$fields declares it visible=0 (l.314) and
		// getColumnCatalog() skips visible=0 outright: the key is exported and
		// stays filterable/sortable through the explicit maps below, it simply
		// never shows up as a selectable DataTable column.
		'date_last_msg_sent' => 'last_message_at',
		'fk_soc'             => 'thirdparty',
		'fk_project'         => 'project',
		'fk_user_create'     => 'created_by',
		'fk_user_assign'     => 'assigned_to',
		'origin_email'       => 'origin_email',
		'type_code'          => 'type_code',
		// Dictionary labels resolved by the three LEFT JOINs of fetch()
		// (l.644-646) and assigned l.692 / l.696 / l.700, after a
		// $langs->trans("TicketTypeShort".$code) lookup. Read-only by nature.
		'type_label'         => 'type_label',
		'category_code'      => 'category_code',
		'category_label'     => 'category_label',
		'severity_code'      => 'severity_code',
		'severity_label'     => 'severity_label',
		// INTEGER, not a code: it holds a rowid of llx_c_ticket_resolution and
		// Ticket::$fields types it 'integer' (l.322). The core only offers the
		// dictionary when TICKET_ENABLE_RESOLUTION is set.
		'resolution'         => 'resolution',
		'progress'           => 'progress',
		// Real column, but its $fields entry is COMMENTED OUT in the core
		// (l.311, with the comment "what is this ?"). Worse, update() clobbers
		// it: l.969-980 read type_code, category_code and severity_code but
		// assign $this->timing every time (a copy-paste bug of the core), so
		// after any update the column holds the severity code. Kept published
		// because it is a genuine column that fetch() hydrates (l.688) and a
		// never-updated ticket still carries its real value -- but never
		// writable, and deliberately absent from the filter / sort maps.
		'timing'             => 'timing',
		// SQL fk_statut, aliased to `status` by fetch() (see point 2 of the
		// class docblock). Statuses: 0 not read, 1 read, 2 assigned,
		// 3 in progress, 5 needs more info, 7 waiting, 8 closed-solved,
		// 9 closed-unsolved (l.260-267). 4 and 6 do not exist, and the
		// 'arrayofkeyval' of $fields['fk_statut'] (l.323) is STALE -- it claims
		// 3=Answered, 4=Assigned, 5=InProgress, 6=Waiting, 9=Deleted. A consumer
		// building a status filter must use the class constants, never that
		// array.
		'status'             => 'status',
	];

	// REMOVED on purpose -- three keys that could only ever export null:
	//   'note_public'  => 'public_note'
	//   'note_private' => 'private_note'
	//   'email_from'   => 'email_from'
	// See point 1 of the class docblock. The two notes were ALSO in
	// $writableFields, which is what made them harmful rather than merely
	// useless: importMappedData() accepted them, applyImportedFields() set the
	// properties (CommonObject declares both, l.476 and l.482, so not even a
	// PHP 8.2 dynamic-property notice betrayed the mistake), Ticket::update()
	// ignored them and the facade answered 200. A write to public_note /
	// private_note now gets an explicit 400 "Field 'public_note' is not
	// writable on ..." -- loud instead of silent. The free text of a ticket
	// lives in `message` and in the discussion thread (llx_actioncomm rows
	// carrying elementtype='ticket').

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	//
	// Every entry below is genuinely persisted, by create() (l.478-531) and by
	// update() (l.985-1006).
	//
	// `resolution` and `progress` are interpolated RAW by the core: update()
	// l.996 emits "resolution=".$this->resolution with neither quote nor
	// escape, and setProgression() (l.2024) does the same with its argument.
	// The facade path is safe because dmTrait::_castInputValue() casts on the
	// declared Dolibarr type and Ticket::$fields types both as 'integer'
	// (l.321-322), so the value reaching the property is always an int. Any
	// OTHER caller -- typically a module route handing client input straight to
	// setProgression() -- must cast to (int) itself, and bound the progress to
	// 0-100, which nothing in the core does.
	//
	// `fk_user_assign` stays writable (business assignment, not audit trail),
	// but a PATCH on it is NOT equivalent to Ticket::assignUser() (l.1631): it
	// writes the raw id with no check that the user belongs to the current
	// entity, it does not add the SUPPORTTEC internal contact the way create()
	// does (l.553), and it does not fire the assignment trigger. A module
	// exposing assignment to end users should route it through its own guarded
	// endpoint rather than through the generic PATCH.
	//
	// NOT writable, on purpose: `ref` and `track_id` (server-generated
	// identities), `status` (state machine driven by markAsRead / assignUser /
	// close / setProgression), every date, `timing` (corrupted by the core's
	// own update()), and the three *_label JOIN results.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	protected $foreignKeyGuards = [
		'fk_soc'         => 'thirdparty',
		'fk_project'     => 'project',
		'fk_user_assign' => 'user',
	];

	// `resolution` is an integer foreign key despite its name (Ticket::$fields
	// l.322 types it 'integer', update() writes it raw at l.996). The decision
	// is recorded here even though the contract detector cannot see it: the
	// name matches neither 'fk_*' nor an 'id' suffix.
	protected $foreignKeyGuardExemptions = [
		'resolution' => 'dictionary llx_c_ticket_resolution: it has an entity column but the installer seeds every row in entity 1, so guarding it would refuse the shipped resolutions on any tenant that is not entity 1',
	];

	protected $writableFields = [
		'subject',
		'message',
		'fk_soc',
		'fk_project',
		'fk_user_assign',
		'type_code',
		'category_code',
		'severity_code',
		'resolution',
		'progress',
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
	 * Columns a free-text search runs against (facade mechanism 6.5).
	 *
	 * Declared explicitly instead of derived. dmBase::getSearchFields() falls
	 * back to "every varchar of $fields" when no field carries a `searchable`
	 * key -- and Ticket::$fields carries `searchall`, not `searchable`, so the
	 * fallback triggers and drags in type_code, category_code and
	 * severity_code. Those hold dictionary CODES: searching a reference would
	 * then match every ticket whose category happens to be COMMERCIAL because
	 * the typed string appears inside the code. They are select axes, served by
	 * the filter row, not by free text.
	 *
	 * The list below is exactly the set Dolibarr itself searches: the columns
	 * flagged 'searchall' => 1 in Ticket::$fields (ref l.300, track_id l.301,
	 * origin_email l.303, subject l.304), collected by ticket/list.php
	 * l.126-131. `fk_soc` also carries the flag (l.308) but is an integer
	 * foreign key -- list.php only includes it because its loop is
	 * unconditional, and a LIKE on it would be meaningless.
	 *
	 * track_id is kept: it is the reference a requester quotes when he comes
	 * back through the public interface, so an operator does search on it.
	 *
	 * @return string[]
	 */
	public function getSearchFields()
	{
		return ['ref', 'track_id', 'subject', 'origin_email'];
	}

	/**
	 * Explicit filterable columns (facade mechanism 6.5).
	 *
	 * Keys are the camelCase catalog keys the frontend sends (dmBase converts
	 * the appside name through snakeToCamel), values the real llx_ticket
	 * columns.
	 *
	 * Needed because the catalog only marks a field filterable when its
	 * doliside is a key of Ticket::$fields. Three groups escape that:
	 *   - `status`, whose doliside is the PHP property aliased by fetch() while
	 *     the column is fk_statut -- the most used filter of a support queue,
	 *     silently ignored until now;
	 *   - `lastMessageAt`, a real column that $fields declares visible=0, which
	 *     makes getColumnCatalog() drop the entry entirely;
	 *   - the three *Code columns, which the catalog derives as free-text
	 *     filters because they are varchar(32) while they are dictionary keys
	 *     and belong in a select.
	 * The remaining entries repeat what the catalog already derives, on
	 * purpose: this map is the readable contract of what a ticket list can be
	 * filtered by, and it pins the filter kind (an fk_* derived as numberrange
	 * from its 'integer' type would render a min/max box instead of a picker).
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'ref'           => ['column' => 'ref', 'kind' => 'text'],
			'trackId'       => ['column' => 'track_id', 'kind' => 'text'],
			'subject'       => ['column' => 'subject', 'kind' => 'text'],
			'originEmail'   => ['column' => 'origin_email', 'kind' => 'text'],
			'status'        => ['column' => 'fk_statut', 'kind' => 'select'],
			'typeCode'      => ['column' => 'type_code', 'kind' => 'select'],
			'categoryCode'  => ['column' => 'category_code', 'kind' => 'select'],
			'severityCode'  => ['column' => 'severity_code', 'kind' => 'select'],
			'resolution'    => ['column' => 'resolution', 'kind' => 'select'],
			'thirdparty'    => ['column' => 'fk_soc', 'kind' => 'select'],
			'project'       => ['column' => 'fk_project', 'kind' => 'select'],
			'createdBy'     => ['column' => 'fk_user_create', 'kind' => 'select'],
			'assignedTo'    => ['column' => 'fk_user_assign', 'kind' => 'select'],
			'progress'      => ['column' => 'progress', 'kind' => 'numberrange'],
			'createdAt'     => ['column' => 'datec', 'kind' => 'daterange'],
			'updatedAt'     => ['column' => 'tms', 'kind' => 'daterange'],
			'readAt'        => ['column' => 'date_read', 'kind' => 'daterange'],
			'closedAt'      => ['column' => 'date_close', 'kind' => 'daterange'],
			'lastMessageAt' => ['column' => 'date_last_msg_sent', 'kind' => 'daterange'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 6.5). Same rationale as
	 * getFilterableColumns(): API key -> real SQL column.
	 *
	 * `timing` and the three *Label keys are absent on purpose: the first is
	 * corrupted by the core's own update() (see $listOfPublishedFields), the
	 * others are JOIN results with no column on llx_ticket -- sorting on them
	 * would emit "ORDER BY tk.type_label" and break the query. Sorting by type,
	 * category or severity is done on the *Code columns.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'ref'           => 'ref',
			'trackId'       => 'track_id',
			'subject'       => 'subject',
			'originEmail'   => 'origin_email',
			'status'        => 'fk_statut',
			'typeCode'      => 'type_code',
			'categoryCode'  => 'category_code',
			'severityCode'  => 'severity_code',
			'resolution'    => 'resolution',
			'thirdparty'    => 'fk_soc',
			'project'       => 'fk_project',
			'createdBy'     => 'fk_user_create',
			'assignedTo'    => 'fk_user_assign',
			'progress'      => 'progress',
			'createdAt'     => 'datec',
			'updatedAt'     => 'tms',
			'readAt'        => 'date_read',
			'closedAt'      => 'date_close',
			'lastMessageAt' => 'date_last_msg_sent',
		];
	}
}
