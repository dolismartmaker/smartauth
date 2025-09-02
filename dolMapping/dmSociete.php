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

class dmSociete extends dmBase
{
	use dmTrait;

	protected $_type = "object";

	//corresponding fields left dolibarr right front app
	protected $_listOfPublishedFields = [
		'rowid' 			=> 'rowid',
		'nom' 				=> 'name',
		'address' 			=> 'address',
		'zip' 				=> 'zip',
		'town' 				=> 'city',
		'fk_departement' 	=> 'departement',
		'fk_pays' 			=> 'country',
		'phone' 			=> 'phone',
		// 'phone_mobile' 		=> 'phone_mobile',
		'url' 				=> 'url',
		'email' 			=> 'email',
		'note_public' 		=> 'note_public',
		'note_private' 		=> 'note_private',
		'logo' 				=> 'logo'
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

		dol_syslog("cacheDesc after is " . json_encode($this->_cacheDesc));
	}


	/**
	 * logo is stored as varchar dolibarr side (file name) but app need a base64 encoded data
	 *
	 * @param   [type]  $societe  [dolibarr $societe]
	 *
	 * @return  [type]        [return description]
	 */
	public function _fieldFilterValueLogo($societe)
	{
		global $conf;
		// dol_syslog("##### dmHelper : call for _fieldFilterValueLogo for " . $societe->logo);
		$dir     = $conf->societe->multidir_output[$societe->entity] . "/" . $societe->id . "/logos";
		$logo = $dir . '/' . $societe->logo;
		$logoBase64 = "";
		if (file_exists($logo)) {
			$type = pathinfo($logo, PATHINFO_EXTENSION);
		} else {
			$logo = dol_buildpath("/smartlivraisons/img/logo.png", 0);
			$type = pathinfo($logo, PATHINFO_EXTENSION);
		}
		$logoBase64 = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($logo));
		// dol_syslog("##### dmHelper : returns " . strlen($logoBase64));
		return $logoBase64;
	}
}
