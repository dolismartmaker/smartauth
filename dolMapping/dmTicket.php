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

require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

/**
 * Mapping for Dolibarr Ticket -> API Ticket
 */
class dmTicket extends dmBase
{
	use dmTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'track_id'          => 'track_id',
		'subject'           => 'subject',
		'message'           => 'message',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date_read'         => 'read_at',
		'date_close'        => 'closed_at',
		'date_last_msg_sent' => 'last_message_at',
		'fk_soc'            => 'thirdparty',
		'fk_project'        => 'project',
		'fk_user_create'    => 'created_by',
		'fk_user_assign'    => 'assigned_to',
		'origin_email'      => 'origin_email',
		'email_from'        => 'email_from',
		'type_code'         => 'type_code',
		'type_label'        => 'type_label',
		'category_code'     => 'category_code',
		'category_label'    => 'category_label',
		'severity_code'     => 'severity_code',
		'severity_label'    => 'severity_label',
		'resolution'        => 'resolution',
		'progress'          => 'progress',
		'timing'            => 'timing',
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
