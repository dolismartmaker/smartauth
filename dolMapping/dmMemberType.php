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

require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';

/**
 * Mapping for Dolibarr AdherentType -> API MemberType
 * Alias: dmAdherentType (for backward compatibility with Dolibarr internal calls)
 */
class dmMemberType extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'AdherentType';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'label'             => 'label',
		'description'       => 'description',
		'morphy'            => 'nature',
		'duration_value'    => 'duration_value',
		'duration_unit'     => 'duration_unit',
		'subscription'      => 'subscription_required',
		'amount'            => 'amount',
		'caneditamount'     => 'can_edit_amount',
		'vote'              => 'can_vote',
		'note_public'       => 'public_note',
		'note'              => 'private_note',
		'mail_valid'        => 'mail_validation_template',
		'mail_subscription' => 'mail_subscription_template',
		'mail_resiliate'    => 'mail_resiliate_template',
		'mail_exclude'      => 'mail_exclude_template',
		'email'             => 'email',
		'status'            => 'status',
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
class_alias('SmartAuth\DolibarrMapping\dmMemberType', 'SmartAuth\DolibarrMapping\dmAdherentType');
