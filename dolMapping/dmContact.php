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

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid' 			=> 'id',
		'civility' 			=> 'civility',
		'lastname' 			=> 'lastname',
		'firstname' 		=> 'firstname',
		'address' 			=> 'address',
		'zip' 				=> 'zip',
		'town' 				=> 'city',
		'fk_departement' 	=> 'state',
		'fk_pays' 			=> 'country',
		'phone' 			=> 'phone',
		'phone_mobile' 		=> 'mobile',
		'email' 			=> 'email',
		'note_public' 		=> 'public_note',
		'note_private' 		=> 'private_note',
		// The thirdparty link lives on the PHP property $socid: Contact::create
		// and Contact::update read $this->socid (the SQL column is fk_soc), and
		// Contact::fetch populates BOTH $this->socid and $this->fk_soc. Same
		// property-is-source-of-truth rule as dmThirdparty's idprof mapping --
		// addressing 'fk_soc' would export fine (dmTrait special-cases it) but
		// import/create would never link the contact.
		'socid'             => 'thirdparty',
		'poste'             => 'job_title',
	];
	// 'fk_c_type_contact' => 'contact_type',

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'civility',
		'lastname',
		'firstname',
		'address',
		'zip',
		'town',
		'fk_departement',
		'fk_pays',
		'phone',
		'phone_mobile',
		'email',
		'socid',
		'poste',
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
}
