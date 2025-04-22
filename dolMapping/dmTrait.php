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
	private $_dolmapclassname;
	private $_dolobjectclassname;
	private $_db;

	private $_listOfForeignKeys = [];
	private $_cacheDesc;

	/**
	 * object constructor
	 */
	public function __construct()
	{
		$this->boot();
	}

	public function boot()
	{
		global $db;
		$this->_db = $db;
		$this->_dolmapping = new dmHelper();
		//ex: dmSmartinter or dmSociete
		$this->_dolmapclassname = static::class;
		//ex: Smartinter or Societe
		$this->_dolobjectclassname = preg_replace('/.*\\\\dm/', '', static::class);

		$this->_cacheDesc = $this->_objectDesc();
	}

	/**
	 * export object description for client app -- could be better with only serialization (todo/tests)
	 *
	 * @return  \stdClass  object description
	 */
	public function objectDesc()
	{
		// dol_syslog(get_class($this) . " call : objectDesc " . $this->_cacheDesc);
		return $this->_cacheDesc;
	}

	private function _objectDesc() {
		// dol_syslog(get_class($this) . " call : objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname);
		// $doliMapClass = new $this->_dolmapclassname($this->_db);
		// dol_syslog(get_class($this) . " call : objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname);
		$doliBaseClass = new $this->_dolobjectclassname($this->_db);

		// $doliMapClass->fetch_optionals();
		// dol_syslog(get_class($this) . " doliBaseClass is " . json_encode($doliBaseClass));
		$obj = new \stdClass();

		foreach ($this->_listOfPublishedFields as $doliside => $appside) {
			// dol_syslog(get_class($this) . " call : $doliside => $appside for " . json_encode($doliMapClass->fields));
			//note : foreign key detect, could be done thanks to dolibarr name plan (prefix fk_)
			//but it's better to do it in propertiesFilter function
			if (isset($this->_dolmapping)) {
				$obj->$appside = $this->_dolmapping->propertiesFilter($doliBaseClass->fields[$doliside], $doliside, $appside);
			}
			//TODO
			//foreign key like fk_pays : without integer:class:data ?
			// if (substr($doliside, 0, 3) == "fk_") {
			// 	$obj->$appside['label'] = 'special';
			// }
		}

		//les extrafields
		$extrafields = new \ExtraFields($this->_db);
		//TODO CHECK
		$parentElementToUseForExtraFields = $this->parentTableElementToUseForExtraFields;
		$listExtra = $extrafields->fetch_name_optionals_label($parentElementToUseForExtraFields);
		foreach ($listExtra as $extra) {
			//search for mapping
			$appside = $this->_listOfPublishedFields["options_" . $extra];
			if (trim($appside == '')) {
				$appside = $extra;
			}
			$obj->$appside = $this->_dolmapping->extrafieldsFilter($parentElementToUseForExtraFields, $extra, $appside, $extrafields);
		}

		if (isset($this->_dolmapping)) {
			$this->_listOfForeignKeys = $this->_dolmapping->getListOfForeignKeys();
		}
		return $obj;
	}

	public function objectType()
	{
		return $this->_type;
	}

	/**
	 * export object data mapped thanks to _listOfPublishedFields
	 *
	 * @param   [type]  $obj  [$obj description]
	 *
	 * @return  [type]        [return description]
	 */
	public function exportMappedData($obj)
	{
		$this->_dolmapclassname = preg_replace('/.*DolibarrMapping/', '', get_class($obj));

		// dol_syslog(" #################### exportMappedData for " . $this->_dolmapclassname . " id=" . $obj->id ?? " no id ");
		// dol_syslog(" ############### " . json_encode($obj));
		// dol_syslog(" ########### " . json_encode($this->_listOfPublishedFields));

		$mapped = new \stdClass;
		foreach ($this->_listOfPublishedFields as $doliside => $appside) {
			//race condition for fk_soc : dolibarr change it to socid
			if ($doliside == "fk_soc") {
				//to keep generic process on smart*
				if (!empty($obj->socid)) {
					$obj->fk_soc = $obj->socid;
				}
			}
			//same with id/rowid
			if ($doliside == "rowid") {
				//to keep generic process on smart*
				if (!empty($obj->id)) {
					$obj->rowid = $obj->id;
				}
			}

			// print json_encode($obj->array_options);//exit;
			// dol_syslog(" ## dolisde=" . $doliside . " and appside=" . $appside);
			// dol_syslog(" ## value on dolibarr object =" . $obj->$doliside ?? 'null');
			if (!empty($obj->$doliside)) {
				$mapped->$appside = $obj->$doliside;
				//TODO if id from external table
			}



			//TODO : detect fk and push object into $mapped->$appside
			if(in_array($doliside,array_keys($this->_listOfForeignKeys))) {
				dol_syslog('########## _listOfForeignKeys = ' . json_encode($this->_listOfForeignKeys));
				$mapped->$appside = $this->exportData($doliside, $obj->$doliside);
			}

			// print json_encode($obj->array_options);exit;
			//extrafields
			if (substr($doliside, 0, 8) == "options_") {
				if (!empty($obj->array_options[$doliside])) {
					$mapped->$appside = $this->exportExtrafieldData($doliside, $obj->array_options[$doliside]);
				}
			}
		}
		return $mapped;
	}


	/**
	 * map extrafield, for example
	 * smartinterventions_type_event is a sellist
	 * and definition is 'options'=>array('c_actioncomm:libelle:id'=>null)
	 * so we have to get value ...
	 *
	 * @param   [type]  $name   [$name description]
	 * @param   [type]  $objectid  [$objectid description]
	 *
	 * @return  [type]          [return description]
	 */
	public function exportExtrafieldData($name, $objectid)
	{
		global $conf, $langs;

		$doliMapClass = new $this->_dolmapclassname($this->_db);
		$parentElementToUseForExtraFields = isset($doliMapClass->parentTableElementToUseForExtraFields) ? $doliMapClass->parentTableElementToUseForExtraFields : '';
		if (empty($parentElementToUseForExtraFields)) {
			return;
		}

		$sql = "SELECT param FROM " . $this->_db->prefix() . "extrafields WHERE  elementtype='" . $parentElementToUseForExtraFields . "' AND name='" . str_replace("options_", "", $name) . "'";
		$resql = $this->_db->query($sql);
		if ($resql) {
			$obj = $this->_db->fetch_object($resql);
			$param = jsonOrUnserialize($obj->param);
			//TODO implement all dolibarr possibilites :-)
			if (isset($param['options'])) {
				$param_list = array_keys($param['options']);
				$InfoFieldList = explode(":", $param_list[0]);
				$parentName = '';
				$parentField = '';
				// 0 : tableName
				// 1 : label field name
				// 2 : key fields name (if differ of rowid)
				// 3 : key field parent (for dependent lists)
				// 4 : where clause filter on column or table extrafield, syntax field='value' or extra.field=value
				// 5 : id category type
				// 6 : ids categories list separated by comma for category root
				$keyList = (empty($InfoFieldList[2]) ? 'rowid' : $InfoFieldList[2] . ' as rowid');

				dol_syslog("** " . json_encode($keyList));
				$out = "";

				if (count($InfoFieldList) > 4 && !empty($InfoFieldList[4])) {
					if (strpos($InfoFieldList[4], 'extra.') !== false) {
						$keyList = 'main.' . $InfoFieldList[2] . ' as rowid';
					} else {
						$keyList = $InfoFieldList[2] . ' as rowid';
					}
				}
				if (count($InfoFieldList) > 3 && !empty($InfoFieldList[3])) {
					list($parentName, $parentField) = explode('|', $InfoFieldList[3]);
					$keyList .= ', ' . $parentField;
				}

				$filter_categorie = false;
				if (count($InfoFieldList) > 5) {
					if ($InfoFieldList[0] == 'categorie') {
						$filter_categorie = true;
					}
				}

				if ($filter_categorie === false) {
					$fields_label = explode('|', $InfoFieldList[1]);
					if (is_array($fields_label)) {
						$keyList .= ', ';
						$keyList .= implode(', ', $fields_label);
					}

					$sqlwhere = '';
					$sql = "SELECT " . $keyList;
					$sql .= ' FROM ' . $this->_db->prefix() . $InfoFieldList[0];
					if (!empty($InfoFieldList[4])) {
						// can use current entity filter
						if (strpos($InfoFieldList[4], '$ENTITY$') !== false) {
							$InfoFieldList[4] = str_replace('$ENTITY$', $conf->entity, $InfoFieldList[4]);
						}
						// can use SELECT request
						if (strpos($InfoFieldList[4], '$SEL$') !== false) {
							$InfoFieldList[4] = str_replace('$SEL$', 'SELECT', $InfoFieldList[4]);
						}

						// current object id can be use into filter
						if (strpos($InfoFieldList[4], '$ID$') !== false && !empty($objectid)) {
							$InfoFieldList[4] = str_replace('$ID$', $objectid, $InfoFieldList[4]);
						} else {
							$InfoFieldList[4] = str_replace('$ID$', '0', $InfoFieldList[4]);
						}
						//We have to join on extrafield table
						if (strpos($InfoFieldList[4], 'extra.') !== false) {
							$sql .= ' as main, ' . $this->_db->prefix() . $InfoFieldList[0] . '_extrafields as extra';
							$sqlwhere .= " WHERE extra.fk_object=main." . $InfoFieldList[2] . " AND " . $InfoFieldList[4];
						} else {
							$sqlwhere .= " WHERE " . $InfoFieldList[4];
						}
					} else {
						$sqlwhere .= " WHERE id='" . $objectid . "'";
					}
					// Some tables may have field, some other not. For the moment we disable it.
					if (in_array($InfoFieldList[0], array('tablewithentity'))) {
						$sqlwhere .= ' AND entity = ' . ((int) $conf->entity);
					}
					$sql .= $sqlwhere;
					//print $sql;

					dol_syslog(get_class($this) . '::exportExtrafieldData type=sellist', LOG_DEBUG);
					$resql = $this->_db->query($sql);
					if ($resql) {
						$obj = $this->_db->fetch_object($resql);
						return $obj->{$InfoFieldList[1]};
						$this->_db->free($resql);
					} else {
						dol_syslog('Error in request ' . $sql . ' ' . $this->_db->lasterror() . '. Check setup of extra parameters', LOG_ERR);
					}
				} else {
					require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
					$data = $form->select_all_categories(\Categorie::$MAP_ID_TO_CODE[$InfoFieldList[5]], '', 'parent', 64, $InfoFieldList[6], 1, 1);
					if (is_array($data)) {
						foreach ($data as $data_key => $data_value) {
							if ($objectid == $data_key) {
								return $data_value;
							}
						}
					}
				}
			}
		}

		//default return orignal value :-(
		return $objectid;
	}



	/**
	 * export data for foreign keys ex
	 * fk_soc is a int so we get Societe object
	 *
	 * @param   [type]  $name   [$name description]
	 * @param   [type]  $objectid  [$objectid description]
	 *
	 * @return  [type]          [return description]
	 */
	public function exportData($name, $objectid)
	{
		global $conf, $langs;
		dol_syslog("############ Call exportData for $name / $objectid / " . $this->_listOfForeignKeys[$name]);


	}
}
