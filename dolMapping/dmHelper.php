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


class dmHelper
{
	private $_listOfForeignKeys = [];

	//dolibarr < - > application mapping for main attributes
	private $_mappingAttributes = [
		'type' 				=> 'type',
		'label' 			=> 'label',
		'placeholder' 		=> 'placeholder',
		'help' 				=> 'help',
		'picto' 			=> 'icon',
		'default' 			=> 'defaultValue',
		'copytoclipboard' 	=> 'hasCopyButton',
		'notnull' 			=> 'required',
		'noteditable' 		=> 'readOnly',
		'disabled' 			=> 'disabled',
		'visible' 			=> 'visible',
		'length' 			=> 'max',
		'position' 			=> 'position',
		'options' 			=> 'options',
		'logo'				=> 'logo',
		// 'prefix' => 'prefix',
		// 'suffix' => 'suffix',
		// 'typeVariant' => 'typeVariant',
		// 'pattern' => 'pattern',
		// 'min' => 'min',
		// 'max' => 'max',
		// 'step' => 'step',
		// 'rows' => 'rows',
		// 'multiple' => 'multiple',
		// 'accept' => 'accept'
	];

	//dolibarr < - > application mapping for extrafields attributes
	private $_mappingExtrafieldsAttributes = [
		'type' 				=> 'type',
		'label' 			=> 'label',
		'placeholder' 		=> 'placeholder',
		'help' 				=> 'help',
		'picto' 			=> 'icon',
		'default' 			=> 'defaultValue',
		'copytoclipboard' 	=> 'hasCopyButton',
		'required' 			=> 'required',
		'noteditable' 		=> 'readOnly',
		'visible' 			=> 'visible',
		'size' 				=> 'max',
		'pos' 				=> 'position',
		'options' 			=> 'options',
		// 'prefix' => 'prefix',
		// 'suffix' => 'suffix',
		// 'typeVariant' => 'typeVariant',
		// 'pattern' => 'pattern',
		// 'min' => 'min',
		// 'max' => 'max',
		// 'step' => 'step',
		// 'rows' => 'rows',
		// 'multiple' => 'multiple',
		// 'accept' => 'accept'
	];

	/**
	 * Filter attribute type integer
	 *
	 * @param   [type]  $str  [$str description]
	 *
	 * @return  [type]        [return description]
	 */
	private function _customFilterAttributeTypeInteger($str)
	{
		global $db;
		// dol_syslog("propertiesFilter > _customFilterAttributeTypeInteger call with $str");
		$ret = [];
		$tab = explode(":", $str);
		if (isset($tab[2])) {
			$dolmapclass = __NAMESPACE__ . "\\dm" . $tab[1];
			// dol_syslog("propertiesFilter >>> _customFilterAttributeTypeInteger try to call $dolmapclass");
			if (class_exists($dolmapclass, true)) {
				include_once(DOL_DOCUMENT_ROOT . '/' . $tab[2]);
				$dm = new $dolmapclass();
				$ret = $dm->objectDesc();
				$ret->type = $dm->objectType();
			}
		}
		return $ret;
	}

	/**
	 * Filter attribute type list of selection
	 *
	 * @param   [type]  $str  [$str description]
	 *
	 * @return  [type]        [return description]
	 */
	private function _customFilterAttributeTypeSellist($str)
	{
		return [
			'type' => 'sellist',
			'todo' => 'todo'
		];
	}

	/**
	 * custom filter on type field
	 * ex: integer:Fichinter:fichinter/class/fichinter.class.php:0
	 *     varchar(30)
	 *     ...
	 *
	 * @param   [type]  $str  dolibarr "type" string
	 *
	 * @return  [type]        [return description]
	 */
	private function _customFilterAttributeType($str)
	{
		// print "<p>Call _customFilterAttributeType for $str</p>";
		$tab = explode(":", $str);
		$ret['type'] = $tab[0];

		if (count($tab) > 1) {
			$specialFilter = "_customFilterAttributeType" . ucfirst(strtolower($ret['type']));
			if (is_callable([$this, $specialFilter])) {
				return call_user_func([$this, $specialFilter], $str);
			}
		}

		//special cases
		//varchar(w) -> varchar
		//double(x,y) -> double
		if (strpos($ret['type'], "(")) {
			preg_match("/(\w+)\((\w+)\)/", $ret['type'], $st);
			$ret['type'] = $st[1];
			$ret['max'] = $st[2];
		} else {
			switch ($ret['type']) {
				case "integer":
					$ret['type'] = "int";
					break;
				case "double":
				case "real":
				case "price":
					$ret['type'] = "float";
					break;
				case "checkbox":
					$ret['type'] = "boolean";
					$ret['typeVariant'] = 'switch';
					break;
				case "radio":
					$ret['type'] = "boolean";
					$ret['typeVariant'] = 'radio';
					break;
				case "mail":
					$ret['type'] = "email";
					break;
				case "phone":
					$ret['type'] = "phoneNumber";
					break;
				case "sellist":
					$ret['type'] = "select";
					break;
				case "chkbxlst":
					$ret['type'] = "check";
					$ret['typeVariant'] = 'checkbox';
					$ret['multiple'] = true;
					break;
				case "ip":
					$ret['type'] = "varchar";
					break;
				case "text":
					$ret['type'] = "text";
					break;
				default:
					// $ret['type'] = "";
					break;
			}
		}
		return $ret;
	}


	/**
	 * convert dolibarr visible code to smart* values
	 *
	 *	0=Not visible
	 *	1=Visible on list and create/update/view forms
	 *	2=Visible on list only
	 *	3=Visible on create/update/view form only (not list)
	 *	4=Visible on list and update/view form only (not create).
	 *	5=Visible on list and view only (not create/not update).
	 *	Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *
	 * @param   [type]  $val  [$val description]
	 *
	 * @return  [type]        [return description]
	 */
	private function _customFilterAttributeVisible($val)
	{
		//dolibarr values -> smart* values
		$dolmap = [
			0 => [],
			1 => ["create", "update", "read"],
			2 => ["read"],
			3 => ["create", "update", "read"],
			4 => ["update", "read"],
			5 => ["read"],
		];
		$ret['visible'] = $dolmap[abs($val)];
		return $ret;
	}

	/**
	 * contacts linked to dolibarr object
	 *
	 * @param   [type]  $val  [$val description]
	 *
	 * @return  [type]        [return description]
	 */
	private function _customFilterAttributeContacts($val)
	{
		dol_syslog(("dmHelper : call for _customFilterAttributeContacts ..."));
	}

	/**
	 * filter all dolibarr properties to make beautifull objects
	 * definitions for smart app
	 *
	 * @param   [type]  $input     [$input description]
	 * @param   [type]  $dolikey   [$dolikey description]
	 * @param   [type]  $frontkey  [$frontkey description]
	 *
	 * @return  [type]             [return description]
	 */
	public function propertiesFilter($input, $dolikey = null, $frontkey = null)
	{
		global $langs;
		$langs->loadLangs(array('companies', 'smartinterventions'));

		// dol_syslog("call propertiesFilter on $dolikey / $frontkey for input " . json_encode($input));
		$ret = [];
		$type = $label = '';

		if (is_array($input)) {
			foreach ($input as $key => $val) {
				if (!in_array($key, array_keys($this->_mappingAttributes))) {
					// dol_syslog("call propertiesFilter on $key => continue");
					continue;
				}
				if ($key == "label") {
					$ret[$key] = $langs->transnoentities($val);
					continue;
				}
				//try to call a private function like _customFilterAttributeXXXXXXX (XXXX last part is dynamic)
				$specialFilter = "_customFilterAttribute" . ucfirst($key);
				// dol_syslog("call propertiesFilter on $dolikey start $specialFilter");
				if (is_callable([$this, $specialFilter])) {
					$r = call_user_func([$this, $specialFilter], $val);
					// dol_syslog("call propertiesFilter via customfilterattribute for $key:$val :: $specialFilter, returns " . json_encode($r));
					// dol_syslog("add _listOfForeignKeys $dolikey || $val");

					if (isset($r->type) && !isset($this->_listOfForeignKeys[$dolikey])) {
						$this->_listOfForeignKeys[$dolikey] = $val;
					}
					foreach ($r as $k => $v) {
						$ret[$k] = $v;
					}
				} else {
					//use front key name from correspondance table mapping
					$frontkey = $this->_mappingAttributes[$key];
					$ret[$frontkey] = $val;
				}
			}
		}

		return $ret;
	}


	/**
	 * filter all dolibarr extrafields to make beautifull objects
	 * definitions for smart app
	 *
	 * @param   [type]  $array  [$array description]
	 *
	 * @return  [type]          [return description]
	 */
	public function extrafieldsFilter($objectElement, $dolikey, $frontkey, $extrafields)
	{
		global $langs;
		// dol_syslog("dmHelper generic extrafieldsFilter element=$objectElement, dolikey=$dolikey, frontkey=$frontkey");
		//TODO mapping + RO/RW
		$ret = [];


		foreach ($this->_mappingExtrafieldsAttributes as $dolattr => $appattr) {
			$val = $extrafields->attributes[$objectElement][$dolattr][str_replace('options_', '', $dolikey)];
			if ($dolattr == "label" && is_string($val)) {
				// print "<p> pour $objectElement  / $dolikey :: $dolattr :: $appattr == " . json_encode($val) . "</p>";
				$ret[$appattr] = $langs->transnoentities($val);
				continue;
			}

			//race condition, extrafields use "enabled", all dolibarr objects "disabled"
			if ($dolattr == "enabled") {
				$ret['disabled'] = !($val);
				continue;
			}

			//try to call a private function like _customFilterAttributeXXXXXXX (XXXX last part is dynamic)
			// $specialFilter = "_customFilterAttribute" . $dolattr;
			// if (is_callable([$this, $specialFilter])) {
			// 	$r = call_user_func([$this, $specialFilter], $val);
			// 	foreach ($r as $k => $v) {
			// 		$ret[$k] = $v;
			// 	}
			// } else {
			$ret[$appattr] = $val;
			// }
		}

		//race condition for new type(not yet available into dolibarr core)
		//for that the solution is to use a special prefix for fields like "photo_"
		//then we convert it into application type like doc :
		//https://inligit.fr/cap-rel/dolibarr/plugin-smartinterventions/-/wikis/home
		$mapNew = ['photo_' => 'photos', 'audio_' => 'audios', 'video_' => 'videos', 'file_' => 'files', 'signature_' => 'signature'];
		foreach ($mapNew as $dolside => $appside) {
			if (substr($dolikey, 0, strlen($dolside)) == $dolside) {
				$ret['type'] = $appside;
				$ret['visible'] = ["create", "update", "read"];
			}
		}

		// print " pour $objectElement  / $dolikey :: " . json_encode($extrafields->attributes[$objectElement]['label'][$dolikey]);
		$ret['is_extrafield'] = true;
		return $ret;
	}

	public function getListOfForeignKeys()
	{
		return $this->_listOfForeignKeys;
	}
}
