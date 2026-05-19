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

require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

/**
 * Mapping for Dolibarr ActionComm -> API AgendaEvent
 * Alias: dmActioncomm (for backward compatibility with Dolibarr internal calls)
 */
class dmAgendaEvent extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'ActionComm';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'label'             => 'label',
		'type_code'         => 'type_code',
		'type_label'        => 'type_label',
		'datec'             => 'created_at',
		'datep'             => 'date_start',
		'datef'             => 'date_end',
		'duree'             => 'duration',
		'fk_soc'            => 'thirdparty',
		'fk_contact'        => 'contact',
		'fk_projet'         => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_action'    => 'assigned_to',
		'location'          => 'location',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'percent'           => 'progress',
		'priority'          => 'priority',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'label',
		'datep',
		'datef',
		'duree',
		'fk_soc',
		'fk_contact',
		'fk_projet',
		'location',
		'percent',
		'priority',
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

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmAgendaEvent', 'SmartAuth\DolibarrMapping\dmActionComm');
