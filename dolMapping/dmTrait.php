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

use SmartAuth\DolibarrMapping\dmHelper;

require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

trait dmTrait
{
	private $_dolmapping;

	/**
	 * object constructor
	 */
	public function __construct()
	{
		$this->boot();
	}

	public function boot()
	{
		$this->_dolmapping = new dmHelper();
	}

	/**
	 * export object description for client app -- could be better with only serialization (todo/tests)
	 *
	 * @return  \stdClass  object description
	 */
	public function objectDesc()
	{
		global $db;
		$doliClassName = preg_replace('/.*DolibarrMapping/', '', get_class($this));
		$doliMapClass = new $doliClassName($db);
		// $doliMapClass->fetch_optionals();
		// print json_encode($doliMapClass);exit;
		$obj = new \stdClass();

		foreach ($this->_listOfPublishedFields as $doliside => $appside) {
			// print "<p> [" . get_class($this) . "] : $doliside => $appside for " . json_encode($doliMapClass->fields) . "</p>\n";
			//note : foreign key detect, could be done thanks to dolibarr name plan (prefix fk_)
			//but it's better to do it in propertiesFilter function
			if (isset($this->_dolmapping)) {
				$obj->$appside = $this->_dolmapping->propertiesFilter($doliMapClass->fields[$doliside], $doliside, $appside);
			}
			//foreign key like fk_pays : without integer:class:data ?
			// if (substr($doliside, 0, 3) == "fk_") {
			// 	$obj->$appside['label'] = 'special';
			// }
		}

		//les extrafields
		$extrafields = new \ExtraFields($db);
		$parentClassToUseForExtraFields = isset($doliMapClass->parentClassToUseForExtraFields) ? $doliMapClass->parentClassToUseForExtraFields : get_class($doliMapClass);
		$parentElementToUseForExtraFields = isset($doliMapClass->parentTableElementToUseForExtraFields) ? $doliMapClass->parentTableElementToUseForExtraFields : '';
		$listExtra = $extrafields->fetch_name_optionals_label($parentClassToUseForExtraFields);
		foreach ($listExtra as $extra) {
			//search for mapping
			$appside = $this->_listOfPublishedFields["options_".$extra];
			if(trim($appside == '')) {
				$appside = $extra;
			}
			$obj->$appside = $this->_dolmapping->extrafieldsFilter($parentElementToUseForExtraFields, $extra, $appside, $extrafields);
		}

		return $obj;
	}

	public function objectType()
	{
		return $this->_type;
	}
}
