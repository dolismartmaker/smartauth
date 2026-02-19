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
 */
class dmMember extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Adherent';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
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
		'phone_pro'         => 'phone_pro',
		'phone_mobile'      => 'mobile',
		'fax'               => 'fax',
		'photo'             => 'photo',
		'public'            => 'is_public',
		'morphy'            => 'nature',
		'typeid'            => 'member_type',
		'fk_soc'            => 'thirdparty',
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

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmMember', 'SmartAuth\DolibarrMapping\dmAdherent');
