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

require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';

/**
 * Mapping for Dolibarr Adherent -> API Member
 * Alias: dmAdherent (for backward compatibility with Dolibarr internal calls)
 *
 * TWO TRAPS ARE SPECIFIC TO THIS OBJECT AND ARE HANDLED BELOW.
 *
 * 1. The member status is NEGATIVE and 0 is NOT "draft": -1 draft,
 *    1 validated, 0 RESILIATED, -2 excluded (Adherent::STATUS_* l.402-419,
 *    and the header comment of install/mysql/tables/llx_adherent.sql l.22-26).
 *    Any consumer testing "=== 0" for a draft treats a resiliated member as a
 *    draft. The SQL column is `statut`, the object carries BOTH $statut and
 *    $status (fetch() l.1537-1538 fills the two), and this mapper publishes
 *    `status`: filtering or sorting on "statut" vs "status" is the difference
 *    between a working query and "Unknown column a.status".
 *
 * 2. Eleven doliside keys below are PHP properties, not columns. Adherent has
 *    an ample $fields array, but fetch() (l.1444-1476) renames half of what it
 *    selects: societe -> $company, fk_soc -> $socid, civility -> $civility_id,
 *    country -> $country_id, fk_adherent_type -> $typeid, statut -> $status,
 *    tms -> $datem. getColumnCatalog() only marks a key sortable/filterable
 *    when its doliside is a key of Adherent::$fields, so without the explicit
 *    maps at the bottom of this class a member list could not be sorted nor
 *    filtered on its status, its type or its third party -- the three axes a
 *    member list is actually used through.
 */
class dmMember extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Adherent';

	// Metadata repairs for the doliside keys that address a PHP property with
	// no Adherent::$fields entry. Without them dmTrait::_getFieldDefinition()
	// (l.340-361) falls back to "varchar(255) / Ucfirst(property name) /
	// visible=1", so describe() would advertise the status as an editable free
	// text field labelled "Status" (untranslated) and the third party link as a
	// varchar named "Socid".
	//
	// visible=5 is "list and view only, not create/update" (cf
	// dmHelper::_customFilterAttributeVisible): correct for status, datem and
	// the two author ids, none of which is writable. `socid` is writable but
	// also carries visible=5 on purpose: Adherent::update() (l.822-824) only
	// emits "fk_soc = ..." when $socid is truthy, so a generated form could
	// link a third party but never UNLINK one -- that relation belongs to a
	// dedicated route, not to a generic form. The allowlist is untouched
	// ($writableFields still accepts it), only form generation is.
	//
	// Labels are Dolibarr translation keys, all present in the core language
	// files (Civility/Status/DateModification/UserAuthor/UserModification in
	// main.lang, Company/ThirdParty/Country in companies.lang, MemberType in
	// members.lang). Whether they come back translated depends on the language
	// files the caller loaded, exactly like every other mapper.
	//
	// NOTE: this override feeds objectDesc() ONLY. getColumnCatalog() reads the
	// RAW Adherent::$fields to decide whether a doliside is a real column, so
	// these entries cannot make the facade emit "WHERE a.status = ..." on a
	// column that does not exist.
	protected $parentFieldsOverride = [
		'civility_id'   => ['type' => 'varchar(6)', 'label' => 'Civility', 'visible' => 1],
		'company'       => ['type' => 'varchar(128)', 'label' => 'Company', 'visible' => 1],
		'country_id'    => ['type' => 'integer', 'label' => 'Country', 'visible' => 1],
		// Mandatory at creation: Adherent::create() (l.689) interpolates
		// $typeid into a NOT NULL column, and fetch() joins llx_adherent_type
		// unconditionally (l.1462-1465), so a member with no type is invisible.
		'typeid'        => ['type' => 'integer', 'label' => 'MemberType', 'visible' => 1, 'required' => 1],
		'socid'         => ['type' => 'integer', 'label' => 'ThirdParty', 'visible' => 5],
		'status'        => ['type' => 'integer', 'label' => 'Status', 'visible' => 5],
		'datem'         => ['type' => 'datetime', 'label' => 'DateModification', 'visible' => 5],
		'fk_user_creat' => ['type' => 'integer', 'label' => 'UserAuthor', 'visible' => 5],
		'fk_user_modif' => ['type' => 'integer', 'label' => 'UserModification', 'visible' => 5],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	//
	// IMPORTANT (doliside): the LEFT keys are the PHP properties Adherent
	// actually fills, NOT always the SQL column names -- see trap 2 in the
	// class docblock. Sorting and filtering therefore go through the explicit
	// maps at the bottom of this class.
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'login'             => 'login',
		'civility_id'       => 'civility',
		'lastname'          => 'lastname',
		'firstname'         => 'firstname',
		'gender'            => 'gender',
		'birth'             => 'birthdate',
		'company'           => 'company',
		'address'           => 'address',
		'zip'               => 'zip',
		'town'              => 'city',
		'state_id'          => 'state',
		'country_id'        => 'country',
		'email'             => 'email',
		'url'               => 'website',
		'phone'             => 'phone',
		'phone_perso'       => 'phone_personal',
		'phone_mobile'      => 'mobile',
		// GHOST COLUMNS, DELIBERATELY ABSENT: Adherent declares the properties
		// $phone_pro (l.222) and $fax (l.232), but llx_adherent has NO such
		// column (install/mysql/tables/llx_adherent.sql l.66-68 ships phone,
		// phone_perso and phone_mobile and nothing else), fetch() never selects
		// them (l.1447) and update() never writes them (l.834-836). Publishing
		// them exported a constant null and, for phone_pro which was also
		// writable, accepted a value and lost it without a word. They only
		// survive in the class for the LDAP export (l.2866-2874), which reads
		// properties a caller sets by hand.
		'photo'             => 'photo',
		'public'            => 'is_public',
		'morphy'            => 'nature',
		'typeid'            => 'member_type',
		// Adherent reads the PHP property $socid (SQL column fk_soc).
		'socid'             => 'thirdparty',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
		'fk_user_valid'     => 'validated_by',
		'datec'             => 'created_at',
		'datem'             => 'updated_at',
		'datevalid'         => 'validated_at',
		'datefin'           => 'subscription_end',
		'status'            => 'status',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// typeid targets llx_adherent_type, which is NOT a dictionary: it carries an
	// entity column and its rows are created by each tenant (Adherent::update()
	// writes fk_adherent_type = $this->typeid, adherent.class.php l.843). It has
	// no ObjectRegistry entry, hence the explicit table/element spec.
	protected $foreignKeyGuards = [
		'socid'  => 'thirdparty',
		'typeid' => ['table' => 'adherent_type', 'element' => 'adherent_type'],
	];

	// civility_id looks like a foreign key and is NOT one: it holds a CODE
	// ('MR', 'MME'), declared varchar(6) above and written quoted+escaped by
	// Adherent::update() l.817. It is therefore kept out of the global integer
	// dictionary list of dmBase, whose keys are all narrowed with an (int) cast
	// -- that cast would destroy this value.
	protected $foreignKeyGuardExemptions = [
		'civility_id' => 'not a foreign key: llx_adherent.civility stores the c_civility CODE as a string, not a rowid',
	];

	protected $writableFields = [
		'civility_id',
		'lastname',
		'firstname',
		'gender',
		'birth',
		'company',
		'address',
		'zip',
		'town',
		'state_id',
		'country_id',
		'email',
		'url',
		'phone',
		'phone_perso',
		'phone_mobile',
		'login',
		'morphy',
		'typeid',
		'socid',
		'note_public',
		'note_private',
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
	 * Declared explicitly instead of derived. No entry of Adherent::$fields
	 * carries a `searchable` key, so dmBase::getSearchFields() falls back to
	 * "every varchar column that is published" and drags in three columns
	 * nobody searches through: `photo` (a file name), `morphy` (the two letter
	 * code 'phy' / 'mor') and `gender` ('man' / 'woman') -- typing "man" would
	 * then return every male member. It also MISSES the company name, because
	 * the doliside for that key is the property $company while the column is
	 * `societe`, and the derivation skips any doliside absent from $fields.
	 *
	 * The list below is the one Dolibarr itself searches, adherents/list.php
	 * l.130-145, minus note_public and note_private: those two are free text
	 * blobs, they were never part of the derived set, and a quick-find box
	 * matching inside a private note is a surprise, not a feature.
	 *
	 * @return string[]
	 */
	public function getSearchFields()
	{
		return [
			'ref', 'login', 'lastname', 'firstname', 'societe', 'email',
			'address', 'zip', 'town', 'phone', 'phone_perso', 'phone_mobile',
		];
	}

	/**
	 * Explicit filterable columns (facade mechanism 6.5).
	 *
	 * Keys are the camelCase catalog keys the frontend sends (dmBase converts
	 * the appside name through snakeToCamel), values the real llx_adherent
	 * columns.
	 *
	 * Needed because the catalog only marks a field filterable when its
	 * doliside is a key of Adherent::$fields, which excludes every property
	 * fetch() renames -- `status` (column statut), `memberType` (column
	 * fk_adherent_type), `thirdparty` (column fk_soc), `company` (column
	 * societe), `civility` (column civility), `country` (column country),
	 * `updatedAt` (column tms), `createdBy` (column fk_user_author) and
	 * `updatedBy` (column fk_user_mod). The first three are the whole point:
	 * they are how a member list is read.
	 *
	 * The remaining entries repeat what the catalog already derives, on
	 * purpose: this map is the readable contract of what a member list can be
	 * filtered by, and it pins the filter kind (a dictionary code derived as
	 * varchar would render a free text box instead of a select).
	 *
	 * `address` and `photo` are absent on purpose: a postal address is served
	 * by the free-text search, a photo file name is not a filter axis.
	 *
	 * @return array<string,array{column:string,kind:string}>
	 */
	public function getFilterableColumns()
	{
		return [
			'ref'             => ['column' => 'ref', 'kind' => 'text'],
			'login'           => ['column' => 'login', 'kind' => 'text'],
			'lastname'        => ['column' => 'lastname', 'kind' => 'text'],
			'firstname'       => ['column' => 'firstname', 'kind' => 'text'],
			'company'         => ['column' => 'societe', 'kind' => 'text'],
			'email'           => ['column' => 'email', 'kind' => 'text'],
			'website'         => ['column' => 'url', 'kind' => 'text'],
			'phone'           => ['column' => 'phone', 'kind' => 'text'],
			'phonePersonal'   => ['column' => 'phone_perso', 'kind' => 'text'],
			'mobile'          => ['column' => 'phone_mobile', 'kind' => 'text'],
			'zip'             => ['column' => 'zip', 'kind' => 'text'],
			'city'            => ['column' => 'town', 'kind' => 'text'],
			'civility'        => ['column' => 'civility', 'kind' => 'select'],
			'gender'          => ['column' => 'gender', 'kind' => 'select'],
			'nature'          => ['column' => 'morphy', 'kind' => 'select'],
			'state'           => ['column' => 'state_id', 'kind' => 'select'],
			'country'         => ['column' => 'country', 'kind' => 'select'],
			'memberType'      => ['column' => 'fk_adherent_type', 'kind' => 'select'],
			'thirdparty'      => ['column' => 'fk_soc', 'kind' => 'select'],
			'status'          => ['column' => 'statut', 'kind' => 'select'],
			'isPublic'        => ['column' => 'public', 'kind' => 'boolean'],
			'createdBy'       => ['column' => 'fk_user_author', 'kind' => 'select'],
			'updatedBy'       => ['column' => 'fk_user_mod', 'kind' => 'select'],
			'validatedBy'     => ['column' => 'fk_user_valid', 'kind' => 'select'],
			'birthdate'       => ['column' => 'birth', 'kind' => 'daterange'],
			'createdAt'       => ['column' => 'datec', 'kind' => 'daterange'],
			'updatedAt'       => ['column' => 'tms', 'kind' => 'daterange'],
			'validatedAt'     => ['column' => 'datevalid', 'kind' => 'daterange'],
			'subscriptionEnd' => ['column' => 'datefin', 'kind' => 'daterange'],
		];
	}

	/**
	 * Explicit sortable columns (facade mechanism 6.5). Same rationale as
	 * getFilterableColumns(): API key -> real SQL column.
	 *
	 * Deliberately the same key set: every axis a member list can be filtered
	 * by can also be sorted on, and each value below is a real column of
	 * llx_adherent, so no ORDER BY can name something the table does not have.
	 *
	 * @return array<string,string>
	 */
	public function getSortableColumns()
	{
		return [
			'ref'             => 'ref',
			'login'           => 'login',
			'lastname'        => 'lastname',
			'firstname'       => 'firstname',
			'company'         => 'societe',
			'email'           => 'email',
			'website'         => 'url',
			'phone'           => 'phone',
			'phonePersonal'   => 'phone_perso',
			'mobile'          => 'phone_mobile',
			'zip'             => 'zip',
			'city'            => 'town',
			'civility'        => 'civility',
			'gender'          => 'gender',
			'nature'          => 'morphy',
			'state'           => 'state_id',
			'country'         => 'country',
			'memberType'      => 'fk_adherent_type',
			'thirdparty'      => 'fk_soc',
			'status'          => 'statut',
			'isPublic'        => 'public',
			'createdBy'       => 'fk_user_author',
			'updatedBy'       => 'fk_user_mod',
			'validatedBy'     => 'fk_user_valid',
			'birthdate'       => 'birth',
			'createdAt'       => 'datec',
			'updatedAt'       => 'tms',
			'validatedAt'     => 'datevalid',
			'subscriptionEnd' => 'datefin',
		];
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmMember', 'SmartAuth\DolibarrMapping\dmAdherent');
