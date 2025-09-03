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

class dmFichinter extends dmBase
{
	use dmTrait;

	protected $type = "object";

	//corresponding fields left dolibarr right front app
	protected $listOfPublishedFields = [
		'rowid' 			=> 'rowid',
		'ref' 				=> 'ref',
		'ref_client' 		=> 'ref_client',
		'datei' 			=> 'datei',
		'description' 		=> 'description',
		'note_public' 		=> 'note_public',
		'note_private' 		=> 'note_private'
	];


	//		'fk_pays' =>array('type'=>'integer:Ccountry:core/class/ccountry.class.php', 'label'=>'Country', 'enabled'=>1, 'visible'=>-1, 'position'=>95),


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
