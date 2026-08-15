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

require_once DOL_DOCUMENT_ROOT . '/fichinter/class/fichinter.class.php';

/**
 * Mapping for Dolibarr Fichinter -> API Intervention
 * Alias: dmFichinter (for backward compatibility with Dolibarr internal calls)
 *
 * Fichinter is one of the legacy classes whose fetch()/create()/update() do NOT
 * address their own SQL columns uniformly. Everything below is anchored on
 * htdocs/fichinter/class/fichinter.class.php (18.0.8):
 *
 *   - fetch()  l.441-489: SELECTs `f.duree` INTO $this->duration, `f.tms as
 *              datem` INTO $this->datem, `f.date_valid as datev` INTO
 *              $this->datev, `f.fk_user_author` INTO $this->user_creation, and
 *              never SELECTs datei / fk_user_modif / fk_user_valid at all.
 *   - create() l.280-311: INSERTs fk_soc, datec, ref, ref_client, entity,
 *              fk_user_author, fk_user_modif, description, model_pdf,
 *              fk_projet, fk_contrat, fk_statut, note_private, note_public.
 *              Neither duree nor datei/dateo/datee are part of the INSERT.
 *   - update() l.395-403: UPDATEs description, duree (FROM $this->duration),
 *              ref_client, fk_projet, note_private, note_public, fk_user_modif.
 *              It does NOT rewrite fk_soc nor fk_contrat.
 *
 * Consequence for the write allowlist below: `socid` and `fk_contrat` are
 * CREATE-only, `duration` is UPDATE-only. They are kept because each one is
 * genuinely persisted on its own verb (and socid is mandatory at create,
 * l.267). A PATCH that has to move an intervention to another contract or
 * another thirdparty needs a dedicated Dolibarr call, not this mapper.
 */
class dmIntervention extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Fichinter';

	// Metadata repairs for the doliside keys that address a PHP property
	// instead of a Fichinter::$fields entry: without $fields the descriptor
	// falls back to "varchar(255) / Ucfirst(property name)" and the front would
	// render a duration as a text input labelled "Duration" untranslated.
	// The three audit fields are declared visible=5 ("list and view only, not
	// create/update", cf dmHelper::_customFilterAttributeVisible) because they
	// are NOT in $writableFields: now that they carry a real value, a form
	// built from describe() would otherwise offer to edit them and get a 400.
	protected $parentFieldsOverride = [
		'duration'      => ['type' => 'double', 'label' => 'Duration'],
		'datem'         => ['type' => 'datetime', 'label' => 'DateModification', 'visible' => 5],
		'datev'         => ['type' => 'datetime', 'label' => 'DateValidation', 'visible' => 5],
		'user_creation' => ['type' => 'integer', 'label' => 'Author', 'visible' => 5],
		// last_main_doc IS a real column with a real $fields entry (l.63), so
		// it needs no type/label repair -- only the same read-only demotion:
		// the core declares it visible=-1, which _customFilterAttributeVisible()
		// turns into create/update/read, while the field is a server-written
		// file path and is absent from $writableFields below.
		'last_main_doc' => ['visible' => 5],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// IMPORTANT (doliside): the LEFT keys are the PHP properties Fichinter
	// actually fills / reads, NOT the SQL column names. Sorting and filtering on
	// those keys therefore goes through getSortableColumns() /
	// getFilterableColumns() below, which point back at the real columns.
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_client'        => 'customer_ref',
		'datec'             => 'created_at',
		// SQL `tms`, aliased to datem by fetch() (l.444 + l.472). Reading 'tms'
		// exported null on every payload.
		'datem'             => 'updated_at',
		// SQL `date_valid`, aliased to datev by fetch() (l.443 + l.471), written
		// by setValid() (l.592). Read-only companion of `status`.
		'datev'             => 'validated_at',
		'dateo'             => 'date_start',
		'datee'             => 'date_end',
		// Fichinter::create/fetch read the PHP properties $socid / $fk_project
		// (SQL columns fk_soc / fk_projet).
		'socid'             => 'thirdparty',
		'fk_project'        => 'project',
		'fk_contrat'        => 'contract',
		// SQL `fk_user_author`, stored by fetch() into $this->user_creation
		// (l.481). Normalized by fieldFilterValueUser_creation() below because
		// Fichinter::info() puts a full User OBJECT in that same property.
		'user_creation'     => 'created_by',
		'description'       => 'description',
		// SQL `duree`, read into $this->duration by fetch() (l.466) and written
		// back from $this->duration by update() (l.397). The same rename is
		// already handled on the lines side by
		// dmLinesTrait::getInterventionLinesMapping().
		'duration'          => 'duration',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		// SQL `last_main_doc`, SELECTed by fetch() (l.445) and assigned to the
		// property of the same name (l.485). Written by generateDocument()
		// through CommonObject::setLastMainDoc(). Published like on every other
		// document mapper (dmProposal, dmOrder, dmInvoice, dmShipment...) so the
		// front can tell "a PDF exists on disk" from "this session generated
		// one", instead of hiding the download button after a page reload.
		'last_main_doc'     => 'last_main_doc',
	];

	// REMOVED on purpose -- three columns of llx_fichinter that no Fichinter
	// property ever carries, so publishing them could only ever export null:
	//   'datei'         => 'date_intervention'
	//   'fk_user_modif' => 'updated_by'
	//   'fk_user_valid' => 'validated_by'
	// fetch() (l.441-445) SELECTs none of them. `datei` is written only by
	// set_date_delivery() (l.1127-1132), which stores the value in
	// $this->date_delivery -- a property fetch() does not hydrate either -- and
	// nothing in the fichinter module even calls that method. `fk_user_modif`
	// and `fk_user_valid` are server-side stamps ($user->id in create()/update()
	// l.303/l.402 and in setValid() l.593), never client data. The validation
	// user is still observable through `validated_at` above; the two others
	// need a dedicated endpoint (Fichinter::info() reads them, the facade does
	// not call it).

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// 'datec' is intentionally excluded (case-by-case, conservative).
	//
	// REMOVED on purpose: 'datei', 'dateo', 'datee'. None of them is part of the
	// create() INSERT nor of the update() SET, so they were silent no-ops:
	// the request answered 200 and the column never moved. 'dateo' / 'datee'
	// are in fact DERIVED columns, recomputed from the lines by
	// FichinterLigne::update_total() (l.1811-1827, min/max of fichinterdet.date)
	// -- they stay published for reading, and change by adding/editing lines.
	// 'datei' (like the former 'duree' entry) is not even a declared property of
	// \Fichinter nor of \CommonObject, so assigning it also created a dynamic
	// property -- a PHP 8.2 deprecation on every write request.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	protected $foreignKeyGuards = [
		'socid'      => 'thirdparty',
		'fk_project' => 'project',
		'fk_contrat' => 'contract',
	];

	protected $writableFields = [
		'ref_client',
		'socid',
		'fk_project',
		'fk_contrat',
		'duration',
		'description',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'FichinterLigne';
	protected $parentLabelForLines = 'InterventionLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getInterventionLinesMapping();
		$this->boot();
	}

	/**
	 * Explicit filterable columns (facade mechanism 3.5).
	 *
	 * Fichinter::$fields IS declared (l.39-67), so the catalog does derive a
	 * filter map -- but only for the doliside keys that are REAL columns. Every
	 * key renamed by fetch() (statut, socid, fk_project, duration, datem, datev,
	 * user_creation) is invisible to that derivation, which today silently drops
	 * a filter on the three most useful axes of an intervention list: status,
	 * customer and duration. Keys are the camelCase catalog keys sent by the
	 * frontend, values the real llx_fichinter columns.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	/**
	 * Columns a free-text search runs against.
	 *
	 * Declared explicitly instead of being derived: the derivation keeps every
	 * varchar of Fichinter::$fields, which now includes last_main_doc -- the
	 * relative path of the generated PDF. Searching an intervention by the path
	 * of its own PDF makes no sense, and it would let a search for a reference
	 * match unrelated documents through their file names. An intervention is
	 * searched by its reference or by the customer's.
	 *
	 * @return string[]
	 */
	public function getSearchFields()
	{
		return ['ref', 'ref_client'];
	}

	public function getFilterableColumns()
	{
		return [
			'ref'         => ['column' => 'ref', 'kind' => 'text'],
			'customerRef' => ['column' => 'ref_client', 'kind' => 'text'],
			'thirdparty'  => ['column' => 'fk_soc', 'kind' => 'select'],
			'project'     => ['column' => 'fk_projet', 'kind' => 'select'],
			'contract'    => ['column' => 'fk_contrat', 'kind' => 'select'],
			'status'      => ['column' => 'fk_statut', 'kind' => 'select'],
			'createdBy'   => ['column' => 'fk_user_author', 'kind' => 'select'],
			'duration'    => ['column' => 'duree', 'kind' => 'numberrange'],
			'createdAt'   => ['column' => 'datec', 'kind' => 'daterange'],
			'updatedAt'   => ['column' => 'tms', 'kind' => 'daterange'],
			'validatedAt' => ['column' => 'date_valid', 'kind' => 'daterange'],
			'dateStart'   => ['column' => 'dateo', 'kind' => 'daterange'],
			'dateEnd'     => ['column' => 'datee', 'kind' => 'daterange'],
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
			'ref'         => 'ref',
			'customerRef' => 'ref_client',
			'thirdparty'  => 'fk_soc',
			'project'     => 'fk_projet',
			'contract'    => 'fk_contrat',
			'status'      => 'fk_statut',
			'createdBy'   => 'fk_user_author',
			'duration'    => 'duree',
			'createdAt'   => 'datec',
			'updatedAt'   => 'tms',
			'validatedAt' => 'date_valid',
			'dateStart'   => 'dateo',
			'dateEnd'     => 'datee',
		];
	}

	/**
	 * Normalize the `created_by` export to a plain user id.
	 *
	 * Two Dolibarr code paths fill the SAME property with two different shapes:
	 * fetch() (l.481) assigns the raw fk_user_author integer, while info()
	 * (l.977-978) assigns a fully fetched \User object. The facade only calls
	 * fetch(), but a consumer that calls info() before exporting would otherwise
	 * serialize the whole user row into the payload -- password hash and API key
	 * included. Always answer an int.
	 *
	 * Invoked by dmTrait::exportMappedData() through the
	 * "fieldFilterValue" . ucfirst($doliside) convention, hence the underscore
	 * in the method name.
	 *
	 * @param  \Fichinter  $obj      Exported object (unused, kept for the hook signature).
	 * @param  mixed       $doliVal  Raw property value (int, numeric string or \User).
	 * @return int|null              User id, or null when it cannot be resolved.
	 */
	public function fieldFilterValueUser_creation($obj, $doliVal)
	{
		if (is_object($doliVal)) {
			if (!empty($doliVal->id)) {
				return (int) $doliVal->id;
			}
			dol_syslog(
				"[SmartAuth] dmIntervention::fieldFilterValueUser_creation got an object without id for intervention "
				. (isset($obj->id) ? (int) $obj->id : 0),
				LOG_WARNING
			);
			return null;
		}
		if (!is_numeric($doliVal)) {
			dol_syslog(
				"[SmartAuth] dmIntervention::fieldFilterValueUser_creation got a non numeric value for intervention "
				. (isset($obj->id) ? (int) $obj->id : 0),
				LOG_WARNING
			);
			return null;
		}

		return (int) $doliVal;
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmIntervention', 'SmartAuth\DolibarrMapping\dmFichinter');
