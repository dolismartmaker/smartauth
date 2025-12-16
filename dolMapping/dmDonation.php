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

require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

/**
 * Mapping for Dolibarr Don -> API Donation
 * Alias: dmDon (for backward compatibility with Dolibarr internal calls)
 */
class dmDonation extends dmBase
{
	use dmTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'date'              => 'date_donation',
		'datec'             => 'created_at',
		'datem'             => 'updated_at',
		'date_valid'        => 'validated_at',
		'amount'            => 'amount',
		'socid'             => 'thirdparty_id',
		'societe'           => 'company_name',
		'lastname'          => 'lastname',
		'firstname'         => 'firstname',
		'address'           => 'address',
		'zip'               => 'zip',
		'town'              => 'city',
		'country_id'        => 'country',
		'email'             => 'email',
		'phone'             => 'phone',
		'phone_mobile'      => 'mobile',
		'fk_project'        => 'project',
		'fk_typepayment'    => 'payment_type',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
		'fk_user_valid'     => 'validated_by',
		'public'            => 'is_public',
		'paid'              => 'paid',
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
class_alias('SmartAuth\DolibarrMapping\dmDonation', 'SmartAuth\DolibarrMapping\dmDon');
