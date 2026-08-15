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

require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';

/**
 * Mapping for Dolibarr Project -> API Project.
 *
 * EXEMPLAR of the intra-tenant visibility mechanism (mechanism 3.2 of the REST
 * facade). Beyond the generic entity scope (WHERE entity IN ...), a project is
 * only visible to a user who is authorized on it (public, or an internal
 * contact), unless the user holds projet->all->lire or is admin. That finer
 * gate is enforced through the three optional hooks the ObjectController /
 * ObjectFacadeTrait call when a mapper declares them:
 *
 *   - visibilitySqlFilter($user, $alias, $db): string
 *       SQL fragment appended to the list/count WHERE. '' = no restriction;
 *       " AND {alias}.rowid IN (0)" = deny all.
 *   - canAccess($object, $user, $mode): bool
 *       Per-object gate for show(read) / update(write) / destroy+deleteBulk(delete).
 *   - postCreate($object, $user): void
 *       Post-create side-effects Project::create() does NOT do itself: the
 *       PROJECTLEADER default internal contact (so the creator is authorized).
 *
 * They REPLICATE EXACTLY the former dolipocket local ProjectController logic
 * (authorizedProjectFilter / restrictedProjectArea / add_contact + ref gen).
 *
 * IMPORTANT -- doliside keys are PHP property names filled by Project::fetch(),
 * NOT SQL column names. fetch() aliases several columns onto differently named
 * properties (column dateo -> $date_start, datee -> $date_end, fk_statut ->
 * $statut, fk_opp_status -> $opp_status, fk_soc -> $socid, fk_user_close ->
 * $user_close_id). exportMappedData() reads $obj->{doliside}, so publishing the
 * SQL column names (dateo, ...) would export null. Those property-name keys are
 * NOT real llx_projet columns, so the generic catalog marks them non
 * filterable/sortable (buildSqlFiltersFromCatalog skips them) -- SQL stays safe.
 */
class dmProject extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Project';

	// Element name for file storage / ECM ($object->element).
	protected $parentElementToUseForExtraFields = 'project';

	// Table-side element name for extrafields (= llx_extrafields.elementtype).
	// Project stores its extrafields under table_element 'projet' (NOT the
	// element name 'project'), because CommonObject::fetch_optionals() keys on
	// $this->table_element.
	protected $parentTableElementToUseForExtraFields = 'projet';

	// Project::$fields['opp_amount']['visible'] is a raw PHP expression STRING
	// (getDolGlobalString("PROJECT_USE_OPPORTUNITIES")) that Dolibarr eval's at
	// render time. propertiesFilter does not eval it -- it calls abs() on the
	// value -- so the descriptor build (boot) throws a TypeError as soon as
	// opp_amount is published. Override it with a plain visibility code (1 =
	// create/update/read) so boot succeeds.
	protected $parentFieldsOverride = [
		'opp_amount' => ['visible' => 1],
	];

	// Opt-in FK -> label companion fields resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty name (+ email) alongside the raw `socid`
	// scalar, using the per-process fetch cache (one Societe fetch per list, no
	// N+1). Keyed on the PHP property `socid` (Project::fetch fills it from the
	// SQL column fk_soc), same shape as dmProposal.
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name', 'thirdpartyEmail' => 'email'],
		],
	];

	// Derived (computed, not backed by a column): a human "ref - title" label so
	// the front FkPicker (fk_projet lookups inside documents) shows a readable
	// entry, and the legacy front `label` key stays populated. Not in
	// listOfPublishedFields -> never appears in the column catalog / describe.
	protected $listOfDerivedFields = [
		'label' => 'label',
	];

	// Dolibarr property (doliside) => Front field (appside).
	// See documentation/api-naming-convention.md.
	protected $listOfPublishedFields = [
		'rowid'                => 'id',
		'ref'                  => 'ref',
		'title'                => 'title',
		'description'          => 'description',
		// property $socid (SQL column fk_soc). Project::create/fetch read the
		// property, so the mapper must address it, not the column.
		'socid'                => 'socid',
		'public'               => 'public',
		// property $statut (SQL column fk_statut).
		'statut'               => 'status',
		// property $date_start / $date_end (SQL columns dateo / datee).
		'date_start'           => 'date_start',
		'date_end'             => 'date_end',
		'date_close'           => 'date_close',
		// property $opp_status (SQL column fk_opp_status).
		'opp_status'           => 'opp_status',
		'opp_percent'          => 'opp_percent',
		'opp_amount'           => 'opp_amount',
		'budget_amount'        => 'budget_amount',
		'usage_opportunity'    => 'usage_opportunity',
		'usage_task'           => 'usage_task',
		'usage_bill_time'      => 'usage_bill_time',
		'usage_organize_event' => 'usage_organize_event',
		'note_public'          => 'note_public',
		'note_private'         => 'note_private',
		// Last generated PDF (relative path under DOL_DATA_ROOT), read-only.
		'last_main_doc'        => 'last_main_doc',
		'model_pdf'            => 'model_pdf',
		'user_author_id'       => 'fk_user_author',
		'user_close_id'        => 'fk_user_close',
		// property $datec (SQL column datec). Read-only creation timestamp.
		'datec'                => 'created_at',
	];

	// Allowlist for importMappedData() (Dolibarr-side property names, i.e. the
	// LEFT side of listOfPublishedFields). See documentation/SPEC_A_WRITABLEFIELDS.md.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	protected $foreignKeyGuards = [
		'socid' => 'thirdparty',
	];

	protected $writableFields = [
		'ref',
		'title',
		'socid',
		'description',
		'public',
		'date_start',
		'date_end',
		'opp_status',
		'opp_percent',
		'opp_amount',
		'budget_amount',
		'usage_opportunity',
		'usage_task',
		'usage_bill_time',
		'note_public',
		'note_private',
	];

	/**
	 * object constructor
	 */
	public function __construct()
	{
		$this->boot();
	}

	/**
	 * Global-search columns for objects/project.
	 *
	 * The generic dmBase::getSearchFields() would keep every string-typed
	 * published field whose doliside is a REAL Project::$fields column (which
	 * excludes the property-name keys statut/date_start/... but would drag in
	 * note_public/note_private, model_pdf, last_main_doc). Narrow it to the two
	 * user-facing reference columns, matching the former local ProjectController
	 * search. Both are real llx_projet varchar columns so `p.ref LIKE ...` /
	 * `p.title LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'title'];
	}

	/**
	 * Derived "ref - title" display label (computed, no backing column).
	 *
	 * @param  \Project $obj
	 * @return string
	 */
	public function fieldFilterValueLabel($obj)
	{
		$ref   = isset($obj->ref) ? (string) $obj->ref : '';
		$title = isset($obj->title) ? (string) $obj->title : '';
		if ($ref !== '' && $title !== '') {
			return $ref . ' - ' . $title;
		}
		return $ref !== '' ? $ref : $title;
	}

	/**
	 * Write the sanitized payload onto the Project, then -- on a fresh create
	 * only -- generate the reference.
	 *
	 * Project::create() rejects an empty ref (returns -1), but the numbering
	 * addon normally runs at the "add" screen (card.php), and the generic facade
	 * has no dedicated pre-create hook. applyImportedFields() is called right
	 * before CrudInvoker::create(), so it is the natural point to inject the ref
	 * for a fresh project the client did not name. On update the object is
	 * already fetched (id > 0) -> ref left untouched (no regeneration).
	 *
	 * @param  \Project  $object     Fresh (create) or fetched (update) project.
	 * @param  \stdClass $sanitized  Output of importMappedData().
	 * @return void
	 */
	public function applyImportedFields($object, $sanitized)
	{
		parent::applyImportedFields($object, $sanitized);

		$isNew = empty($object->id);
		$hasRef = isset($object->ref) && trim((string) $object->ref) !== '';
		if ($isNew && !$hasRef) {
			$ref = $this->generateProjectRef($object);
			if ($ref !== '') {
				$object->ref = $ref;
			} else {
				dol_syslog('[SmartAuth] dmProject::applyImportedFields could not pre-generate a project ref', LOG_WARNING);
			}
		}
	}

	/**
	 * Post-create side-effects Project::create() does not perform (mirrors
	 * projet/card.php): finalize the ref if somehow still empty, then add the
	 * creator as the internal PROJECTLEADER contact so restrictedProjectArea()
	 * (and thus canAccess()) authorizes them on their own project.
	 *
	 * @param  \Project $object  Freshly created project (its id is set).
	 * @param  \User    $user
	 * @return void
	 */
	public function postCreate($object, $user)
	{
		// Ref garde-fou: normally already set by applyImportedFields() (else
		// Project::create would have returned -1 and we would not be here).
		if (!isset($object->ref) || trim((string) $object->ref) === '') {
			$ref = $this->generateProjectRef($object);
			if ($ref !== '') {
				$object->ref = $ref;
				if ($object->update($user) < 0) {
					dol_syslog('[SmartAuth] dmProject::postCreate could not persist generated ref: ' . $object->error, LOG_WARNING);
				}
			}
		}

		if (method_exists($object, 'add_contact')) {
			$res = $object->add_contact($user->id, 'PROJECTLEADER', 'internal');
			if ($res < 0) {
				// Non-fatal: the project exists; log so the missing role is visible.
				dol_syslog('[SmartAuth] dmProject::postCreate add_contact(PROJECTLEADER) failed: ' . $object->error, LOG_WARNING);
			}
		}
	}

	/**
	 * Intra-tenant list/count visibility: restrict to the projects the user is
	 * authorized to see. Replicates the former local ProjectController::
	 * authorizedProjectFilter().
	 *
	 * @param  \User    $user
	 * @param  string   $alias  SQL alias of the llx_projet table in the host query.
	 * @param  \DoliDB  $db
	 * @return string           SQL fragment (starts with " AND ") or ''.
	 */
	public function visibilitySqlFilter($user, $alias, $db)
	{
		// Admin (or explicit projet->all->lire) sees every project of the entity.
		if (!empty($user->admin) || $user->hasRight('projet', 'all', 'lire')) {
			return '';
		}

		require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
		$probe = new \Project($db);
		// mode 0, list 1 -> comma-separated string of authorized rowids, '0' if none.
		$idsCsv = $probe->getProjectsAuthorizedForUser($user, 0, 1, 0);
		if (!is_string($idsCsv) || $idsCsv === '') {
			// Nothing authorized -> deny all.
			return ' AND ' . $alias . '.rowid IN (0)';
		}
		return ' AND ' . $alias . '.rowid IN (' . $db->sanitize($idsCsv) . ')';
	}

	/**
	 * Intra-tenant per-object visibility for show / update / destroy. Replicates
	 * the former local ProjectController restrictedProjectArea() gate.
	 *
	 * @param  \Project $object
	 * @param  \User    $user
	 * @param  string   $mode    read|write|delete
	 * @return bool              false REFUSES (the controller emits a 403).
	 */
	public function canAccess($object, $user, $mode)
	{
		if (!empty($user->admin) || $user->hasRight('projet', 'all', 'lire')) {
			return true;
		}
		return $object->restrictedProjectArea($user, $mode) > 0;
	}

	/**
	 * Generate the next project reference via the configured numbering module
	 * (PROJECT_ADDON, default mod_project_simple), mirroring the former local
	 * ProjectController::generateRef() and card.php.
	 *
	 * @param  \Project $project
	 * @return string             Empty string on failure.
	 */
	private function generateProjectRef($project)
	{
		global $db;

		$modele = getDolGlobalString('PROJECT_ADDON', 'mod_project_simple');
		$file = dol_buildpath('/core/modules/project/' . $modele . '.php', 0);
		if (!file_exists($file)) {
			dol_syslog('[SmartAuth] dmProject::generateProjectRef numbering module not found: ' . $modele, LOG_ERR);
			return '';
		}
		dol_include_once('/core/modules/project/' . $modele . '.php');
		if (!class_exists($modele)) {
			dol_syslog('[SmartAuth] dmProject::generateProjectRef numbering class not found: ' . $modele, LOG_ERR);
			return '';
		}

		$thirdparty = null;
		$socid = !empty($project->socid) ? (int) $project->socid : 0;
		if ($socid > 0) {
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$thirdparty = new \Societe($db);
			if ($thirdparty->fetch($socid) <= 0) {
				$thirdparty = null;
			}
		}

		$modProject = new $modele();
		$ref = $modProject->getNextValue($thirdparty, $project);
		if (!is_string($ref) || (is_numeric($ref) && (int) $ref <= 0)) {
			return '';
		}
		return (string) $ref;
	}
}
