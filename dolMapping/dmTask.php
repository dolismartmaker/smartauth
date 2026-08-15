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

require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';

/**
 * Mapping for Dolibarr Task (projet_task) -> API Task.
 *
 * A task belongs to a project (fk_projet) and forms a tree (fk_task_parent +
 * rang). There is NO dedicated 'task' rights class in Dolibarr: tasks inherit
 * the project rights (projet.lire/creer/supprimer) and access is gated through
 * the PARENT PROJECT. This mapper carries that finer gate via the three generic
 * ObjectFacade hooks (mechanism 3.2 visibility + 3.6 create side-effects) plus
 * the explicit filter/sort columns (mechanism 3.5), REPLICATING EXACTLY the
 * former dolipocket-local TaskController logic:
 *
 *   - visibilitySqlFilter($user, $alias, $db): restrict list/count to the tasks
 *     whose parent project the user is authorized on (getProjectsAuthorizedForUser).
 *   - canAccess($object, $user, $mode): per-object gate for show/update/destroy
 *     via the parent project's restrictedProjectArea().
 *   - getFilterableColumns() / getSortableColumns(): Task::$fields is EMPTY, so
 *     the generic catalog cannot derive a filter/sort whitelist, and several
 *     appside keys read a PHP PROPERTY whose name differs from the SQL column
 *     (project -> property fk_project but SQL column fk_projet; date_start ->
 *     property date_start but SQL column dateo). These declare the REAL columns.
 *   - applyImportedFields(): before create, copy the parent project's entity and
 *     generate the ref via the numbering addon (Task::create leaves ref null).
 *   - postCreate(): add the creator as the internal TASKEXECUTIVE contact.
 *
 * IMPORTANT -- doliside keys are PHP property names filled by Task::fetch(), NOT
 * SQL column names. fetch() aliases several columns onto differently named
 * properties (column fk_projet -> $fk_project, dateo -> $date_start, datee ->
 * $date_end, datec -> $date_c). exportMappedData() reads $obj->{doliside}, so
 * the property names are correct for READ; the SQL column names are only used in
 * getFilterableColumns()/getSortableColumns()/visibilitySqlFilter for the WHERE.
 */
class dmTask extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Task';

	// Element name for file storage / ECM ($object->element).
	protected $parentElementToUseForExtraFields = 'project_task';

	// Table-side element name for extrafields (= llx_extrafields.elementtype).
	// Task stores its extrafields under table_element 'projet_task'.
	protected $parentTableElementToUseForExtraFields = 'projet_task';

	// Dolibarr property (doliside) => Front field (appside).
	// See documentation/api-naming-convention.md.
	protected $listOfPublishedFields = [
		'rowid'              => 'id',
		'ref'                => 'ref',
		'label'              => 'label',
		'description'        => 'description',
		// property $fk_project (SQL column fk_projet). Task::create/fetch read
		// the property, so the mapper must address it, not the column.
		'fk_project'         => 'project',
		'fk_task_parent'     => 'parent_task',
		// property $date_start / $date_end (SQL columns dateo / datee).
		'date_start'         => 'date_start',
		'date_end'           => 'date_end',
		'planned_workload'   => 'planned_workload',
		// Denormalized total of the time spent (seconds), read-only.
		'duration_effective' => 'time_spent',
		'progress'           => 'progress',
		'priority'           => 'priority',
		// property $fk_statut (SQL column fk_statut).
		'fk_statut'          => 'status',
		'budget_amount'      => 'budget_amount',
		'note_public'        => 'note_public',
		'note_private'       => 'note_private',
		'rang'               => 'rang',
		// property $date_c (SQL column datec). Read-only creation timestamp.
		'date_c'             => 'created_at',
		'fk_user_creat'      => 'created_by',
	];

	// Allowlist for importMappedData() (Dolibarr-side property names, i.e. the
	// LEFT side of listOfPublishedFields). See documentation/SPEC_A_WRITABLEFIELDS.md.
	// `ref` is intentionally absent: it is server-generated (applyImportedFields
	// / the numbering addon), never client-provided.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// fk_task_parent points back at llx_projet_task, so a task can be reparented
	// under a foreign task exactly as it can be moved to a foreign project.
	protected $foreignKeyGuards = [
		'fk_project'     => 'project',
		'fk_task_parent' => 'task',
	];

	protected $writableFields = [
		'label',
		'description',
		'fk_project',
		'fk_task_parent',
		'date_start',
		'date_end',
		'planned_workload',
		'progress',
		'priority',
		'budget_amount',
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
	 * Global-search columns for objects/task.
	 *
	 * Task::$fields is empty, so the generic dmBase::getSearchFields() (which
	 * requires the doliside to be declared in $object->fields) returns []. Narrow
	 * it to the two user-facing reference columns, matching the former local
	 * TaskController search. Both are real llx_projet_task varchar columns so
	 * `t.ref LIKE ...` / `t.label LIKE ...` is SQL-safe.
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['ref', 'label'];
	}

	/**
	 * Mechanism 3.5 -- explicit filterable columns.
	 *
	 * Required because Task::$fields is empty (no catalog-derived filter map) and
	 * because the appside `project`/`parent_task`/`status` keys read PHP
	 * properties whose names differ from the SQL columns. The map is apiKey =>
	 * ['column' => real_sql_col, 'kind' => ...]; ObjectController prefixes each
	 * column with the table alias (pt.) before use.
	 *
	 * `project` -> fk_projet is THE scoping key: the project "Tasks" section and
	 * the facade list call GET objects/task?filter[project]=<id>, and this maps
	 * that to `pt.fk_projet = <id>` -- so the list is scoped to one project, not
	 * every task of the tenant.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'project'     => ['column' => 'fk_projet', 'kind' => 'select'],
			'parent_task' => ['column' => 'fk_task_parent', 'kind' => 'select'],
			'status'      => ['column' => 'fk_statut', 'kind' => 'select'],
			'ref'         => ['column' => 'ref', 'kind' => 'text'],
			'label'       => ['column' => 'label', 'kind' => 'text'],
		];
	}

	/**
	 * Mechanism 3.5 -- explicit sortable columns (same rationale as
	 * getFilterableColumns: empty $fields + property != SQL column). apiKey =>
	 * real SQL column (aliased by ObjectController).
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'ref'        => 'ref',
			'label'      => 'label',
			'date_start' => 'dateo',
			'date_end'   => 'datee',
			'progress'   => 'progress',
			'rang'       => 'rang',
			'status'     => 'fk_statut',
		];
	}

	/**
	 * Write the sanitized payload onto the Task, then -- on a fresh create only --
	 * copy the parent project's entity and generate the reference.
	 *
	 * Task::create() falls back to $conf->entity and accepts a null ref, but the
	 * former local TaskController copied the project entity explicitly and ran the
	 * numbering addon (as task/card.php does). applyImportedFields() runs right
	 * before CrudInvoker::create(), so it is the point to inject both for a fresh
	 * task. On update the object is already fetched (id > 0) -> entity/ref left
	 * untouched.
	 *
	 * Dates are also normalized to Unix seconds here: Task has an empty $fields,
	 * so importMappedData() cannot type-cast date_start/date_end and would leave a
	 * millisecond value (or ISO string) that Task::create/idate mis-stores.
	 *
	 * @param  \Task     $object     Fresh (create) or fetched (update) task.
	 * @param  \stdClass $sanitized  Output of importMappedData().
	 * @return void
	 */
	public function applyImportedFields($object, $sanitized)
	{
		parent::applyImportedFields($object, $sanitized);

		// Defensive date normalization for the fields the client actually sent.
		foreach (['date_start', 'date_end'] as $df) {
			if (isset($sanitized->{$df}) && $sanitized->{$df} !== '' && $sanitized->{$df} !== null) {
				$ts = $this->normalizeToSeconds($object->{$df});
				$object->{$df} = ($ts !== null) ? $ts : '';
			}
		}

		$isNew = empty($object->id);
		if (!$isNew) {
			return;
		}

		$projectId = isset($object->fk_project) ? (int) $object->fk_project : 0;
		$project = $projectId > 0 ? $this->loadProject($projectId) : null;
		if ($project !== null) {
			$object->entity = (int) $project->entity;
		}

		$hasRef = isset($object->ref) && trim((string) $object->ref) !== '';
		if (!$hasRef) {
			$ref = $this->generateTaskRef($object, $project);
			if ($ref !== '') {
				$object->ref = $ref;
			} else {
				dol_syslog('[SmartAuth] dmTask::applyImportedFields could not pre-generate a task ref', LOG_WARNING);
			}
		}
	}

	/**
	 * Post-create side-effect Task::create() does not perform (mirrors
	 * task/card.php): add the creator as the internal TASKEXECUTIVE contact so the
	 * task is assigned to them (grants timesheet access).
	 *
	 * @param  \Task $object  Freshly created task (its id is set).
	 * @param  \User $user
	 * @return void
	 */
	public function postCreate($object, $user)
	{
		if (method_exists($object, 'add_contact')) {
			$res = $object->add_contact($user->id, 'TASKEXECUTIVE', 'internal');
			if ($res < 0) {
				// Non-fatal: the task exists; log so the missing assignment is visible.
				dol_syslog('[SmartAuth] dmTask::postCreate add_contact(TASKEXECUTIVE) failed: ' . $object->error, LOG_WARNING);
			}
		}
	}

	/**
	 * Intra-tenant list/count visibility: restrict to the tasks whose PARENT
	 * PROJECT the user is authorized to see. Replicates the former local
	 * TaskController scoping (a task is visible iff its project is).
	 *
	 * @param  \User    $user
	 * @param  string   $alias  SQL alias of llx_projet_task in the host query.
	 * @param  \DoliDB  $db
	 * @return string           SQL fragment (starts with " AND ") or ''.
	 */
	public function visibilitySqlFilter($user, $alias, $db)
	{
		// Admin (or explicit projet->all->lire) sees every task of the entity.
		if (!empty($user->admin) || $user->hasRight('projet', 'all', 'lire')) {
			return '';
		}

		require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
		$probe = new \Project($db);
		// mode 0, list 1 -> comma-separated string of authorized project rowids.
		$idsCsv = $probe->getProjectsAuthorizedForUser($user, 0, 1, 0);
		if (!is_string($idsCsv) || $idsCsv === '') {
			// Authorized on nothing -> deny all (fk_projet is never 0 for a real task).
			return ' AND ' . $alias . '.fk_projet IN (0)';
		}
		return ' AND ' . $alias . '.fk_projet IN (' . $db->sanitize($idsCsv) . ')';
	}

	/**
	 * Intra-tenant per-object visibility for show / update / destroy. A task is
	 * accessible iff its PARENT PROJECT is (restrictedProjectArea).
	 *
	 * @param  \Task  $object
	 * @param  \User  $user
	 * @param  string $mode    read|write|delete
	 * @return bool            false REFUSES (the controller emits a 403).
	 */
	public function canAccess($object, $user, $mode)
	{
		if (!empty($user->admin) || $user->hasRight('projet', 'all', 'lire')) {
			return true;
		}

		$projectId = isset($object->fk_project) ? (int) $object->fk_project : 0;
		if ($projectId <= 0) {
			dol_syslog('[SmartAuth] dmTask::canAccess task ' . ((int) ($object->id ?? 0)) . ' has no parent project', LOG_WARNING);
			return false;
		}
		$project = $this->loadProject($projectId);
		if ($project === null) {
			return false;
		}
		return $project->restrictedProjectArea($user, $mode) > 0;
	}

	/**
	 * Pre-create authorization (mechanism 3.2, create side): a task may only be
	 * created under a project the user can WRITE to. The coarse projet.creer
	 * right is not enough -- mirrors the former local
	 * TaskController::requireProjectAccess('write'). Reads the parent project id
	 * from the sanitized payload (Dolibarr property name fk_project).
	 *
	 * @param  \stdClass $sanitized
	 * @param  \User     $user
	 * @return bool
	 */
	public function canCreate($sanitized, $user)
	{
		if (!empty($user->admin) || $user->hasRight('projet', 'all', 'creer')) {
			return true;
		}
		$projectId = isset($sanitized->fk_project) ? (int) $sanitized->fk_project : 0;
		if ($projectId <= 0) {
			return false;
		}
		$project = $this->loadProject($projectId);
		if ($project === null) {
			return false;
		}
		return $project->restrictedProjectArea($user, 'write') > 0;
	}

	/**
	 * Fetch the parent Project (or null when missing).
	 *
	 * @param  int $projectId
	 * @return \Project|null
	 */
	private function loadProject($projectId)
	{
		global $db;

		$projectId = (int) $projectId;
		if ($projectId <= 0) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
		$project = new \Project($db);
		if ($project->fetch($projectId) <= 0) {
			dol_syslog('[SmartAuth] dmTask::loadProject parent project not found id=' . $projectId, LOG_WARNING);
			return null;
		}
		return $project;
	}

	/**
	 * Generate the next task reference via the configured numbering module
	 * (PROJECT_TASK_ADDON, default mod_task_simple), mirroring the former local
	 * TaskController::generateTaskRef() and task/card.php.
	 *
	 * @param  \Task         $task
	 * @param  \Project|null $project  Parent project (for the thirdparty prefix).
	 * @return string                  Empty string on failure.
	 */
	private function generateTaskRef($task, $project)
	{
		global $db;

		$modele = getDolGlobalString('PROJECT_TASK_ADDON', 'mod_task_simple');
		$file = dol_buildpath('/core/modules/project/task/' . $modele . '.php', 0);
		if (!file_exists($file)) {
			dol_syslog('[SmartAuth] dmTask::generateTaskRef numbering module not found: ' . $modele, LOG_ERR);
			return '';
		}
		dol_include_once('/core/modules/project/task/' . $modele . '.php');
		if (!class_exists($modele)) {
			dol_syslog('[SmartAuth] dmTask::generateTaskRef numbering class not found: ' . $modele, LOG_ERR);
			return '';
		}

		$thirdparty = null;
		if ($project !== null && !empty($project->socid)) {
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$thirdparty = new \Societe($db);
			if ($thirdparty->fetch((int) $project->socid) <= 0) {
				$thirdparty = null;
			}
		}

		$modTask = new $modele();
		$ref = $modTask->getNextValue($thirdparty, $task);
		if (!is_string($ref) || (is_numeric($ref) && (int) $ref <= 0)) {
			return '';
		}
		return (string) $ref;
	}

	/**
	 * Normalise a date/timestamp value to Unix seconds.
	 *
	 * Accepts seconds, milliseconds (12+ digits), ISO strings. Returns null on
	 * empty / invalid input (caller decides on a default).
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
