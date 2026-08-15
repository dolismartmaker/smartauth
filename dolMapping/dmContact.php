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

require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

class dmContact extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Contact';
	protected $parentTableElementToUseForExtraFields = 'socpeople';

	// Dolibarr PHP property name => Front API key
	// See documentation/api-naming-convention.md.
	//
	// IMPORTANT -- the LEFT side is the PHP property Contact::fetch() populates
	// AND Contact::update()/create() persist FROM. It is NOT always the SQL
	// column name; Contact has several read/write property aliases where using
	// the SQL column name reads null and writes nothing. Each such case is
	// annotated below (verified against htdocs/contact/class/contact.class.php).
	protected $listOfPublishedFields = [
		'rowid'         => 'id',
		// FIX (civility): Contact::fetch() sets $this->civility to the TRANSLATED
		// label ("Mr", "Mme", ...) while the CODE ("MR", "MME") lands in
		// $this->civility_code, and Contact::update() writes the SQL column from
		// $this->civility_code (contact.class.php line 631). The old mapping
		// 'civility' => 'civility' therefore READ a localized label and WROTE
		// nothing usable. Address the source-of-truth property civility_code so
		// the API round-trips the raw code.
		'civility_code' => 'civility',
		'lastname'      => 'lastname',
		'firstname'     => 'firstname',
		'address'       => 'address',
		'zip'           => 'zip',
		'town'          => 'city',
		// FIX (state): Contact::fetch() populates $this->state_id (SELECT
		// c.fk_departement as state_id) and Contact::update() writes fk_departement
		// FROM $this->state_id (line 639). The old 'fk_departement' => 'state'
		// mapping addressed a property that fetch never fills and update never
		// reads -> read null, lost writes. state_id is the source of truth.
		'state_id'      => 'state',
		// FIX (country): Contact::fetch() fills $this->country_code (ISO code,
		// JOIN-derived) but Contact::update() persists fk_pays FROM
		// $this->country_id, NOT from country_code (line 638). So country_code is
		// exposed for READ only (absent from $writableFields below) -- same
		// read-only contract as dmThirdparty::country_code. The old
		// 'fk_pays' => 'country' mapping addressed a property fetch never fills
		// (read null) and, being writable, silently dropped the value on update.
		'country_code'  => 'country_code',
		// FIX (phone_pro): Contact has no $phone property. Contact::fetch() sets
		// $this->phone_pro = trim($obj->phone) (line 1087) and Contact::update()
		// writes the SQL column `phone` FROM $this->phone_pro (line 648). The old
		// 'phone' => 'phone' mapping read null and wrote nothing. Address the real
		// property phone_pro (kept under the front key `phone`).
		'phone_pro'     => 'phone',
		'phone_mobile'  => 'mobile',
		// Additive fields the Dolipocket UI reads/writes: they were absent from
		// the facade. Each is a genuine Contact property filled by fetch() and
		// persisted by update() (phone_perso l649, fax l641, priv l651,
		// default_lang l658, statut l656).
		'phone_perso'   => 'phone_perso',
		'fax'           => 'fax',
		'email'         => 'email',
		// Contact activity flag (0=inactive, 1=active). SQL column + PHP property
		// are both named `statut`. Kept as apiside `statut` (Contact uses this
		// spelling, unlike Societe's `status`). See the $writableFields note for
		// why it is a documented exception to the state-machine Rule 1.
		'statut'        => 'statut',
		'priv'          => 'priv',
		'default_lang'  => 'default_lang',
		'poste'         => 'job_title',
		'note_public'   => 'public_note',
		'note_private'  => 'private_note',
		// The thirdparty link lives on the PHP property $socid: Contact::create
		// and Contact::update read $this->socid (the SQL column is fk_soc), and
		// Contact::fetch populates BOTH $this->socid and $this->fk_soc. Same
		// property-is-source-of-truth rule as dmThirdparty's idprof mapping --
		// addressing 'fk_soc' would export fine (dmTrait special-cases it) but
		// import/create would never link the contact.
		'socid'         => 'thirdparty',
	];

	// Opt-in FK -> label companion field resolved by dmTrait::_resolveForeignKeyLabels().
	// Surfaces the parent thirdparty name as `thirdpartyName` alongside the raw
	// `thirdparty` (socid) scalar, using the per-process fetch cache (one Societe
	// fetch per list, no N+1). Additive: strict consumers keep the scalar id and
	// gain a display name (Contact lists show the company without a second call).
	protected $listOfForeignKeyLabels = [
		'socid' => [
			'class'  => 'Societe',
			'path'   => 'societe/class/societe.class.php',
			'labels' => ['thirdpartyName' => 'name'],
		],
	];

	// Allowlist for importMappedData() (Dolibarr PHP property names -- the LEFT
	// side of $listOfPublishedFields, per _validateDeclaration()).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// Both spellings are guarded: `socid` is the property Contact::update()
	// reads, `fk_soc` the SQL column, and the registry allowlist carries both.
	protected $foreignKeyGuards = [
		'socid'  => 'thirdparty',
		'fk_soc' => 'thirdparty',
	];

	protected $writableFields = [
		'civility_code',
		'lastname',
		'firstname',
		'address',
		'zip',
		'town',
		'state_id',
		'phone_pro',
		'phone_mobile',
		'phone_perso',
		'fax',
		'email',
		// Documented exception to Rule 1 (status = state machine): a contact
		// 'statut' (active/inactive) is a plain editable flag on Contact, NOT a
		// transition-driven state machine. Contact exposes no dedicated
		// transition method and the facade registers no contact action, so the
		// only way to (de)activate a contact is to write the field -- same
		// reasoning as the dmWarehouse / dmThirdparty status exceptions.
		'statut',
		'priv',
		'default_lang',
		'poste',
		'note_public',
		'note_private',
		'socid',
		// NOTE: 'country_code' is intentionally ABSENT. It is published above for
		// READ, but Contact::update() persists the country via fk_pays =
		// $this->country_id, ignoring $this->country_code. Making it writable
		// would accept the value then silently discard it -- so a PATCH carrying
		// country_code is rejected 400 by importMappedData() (intended contract).
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
	 * Global-search columns for objects/contact.
	 *
	 * The generic dmBase::getSearchFields() keeps only published fields whose
	 * `doliside` is a real key of Contact::$fields. Several of this mapper's
	 * doliside keys are read/write PHP property aliases, NOT $fields columns
	 * (phone_pro -> SQL `phone`, civility_code -> SQL `civility`, state_id ->
	 * `fk_departement`, country_code is JOIN-derived, socid -> `fk_soc`). The
	 * base implementation would therefore DROP `phone` from the searchable set
	 * (its property is phone_pro), so a ?search= on a phone number would never
	 * match. We override with the real llx_socpeople varchar columns so global
	 * search behaves like the former local controller (which searched
	 * lastname/firstname/email/phone/phone_mobile).
	 *
	 * @return array<int,string>  real SQL column names (used as alias.col LIKE)
	 */
	public function getSearchFields()
	{
		return ['lastname', 'firstname', 'email', 'phone', 'phone_mobile', 'phone_perso'];
	}
}
