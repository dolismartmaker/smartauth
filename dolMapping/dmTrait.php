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
require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

trait dmTrait
{
	private $_dolmapping;
	private $_dolmapclassname;
	private $_dolobjectclassname;
	private $_db;

	private $listOfForeignKeys = [];
	private $_cacheDesc;

	/**
	 * object constructor
	 */
	public function __construct()
	{
		//Note: don't forget to load langs to get all translations on client side
		//into your dmCustomClass with your module lang name, for example like that
		//$langs->load("smartinterventions@smartinterventions");

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

		// dol_syslog(get_class($this) . " is booting, call _objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname . ", mappping=" . get_class($this->_dolmapping));
		$this->_cacheDesc = $this->_objectDesc();
	}

	/**
	 * export object description for client app -- could be better with only serialization (todo/tests)
	 *
	 * @return  \stdClass  object description
	 */
	public function objectDesc()
	{
		// dol_syslog(get_class($this) . " call : objectDesc " . json_encode($this->_cacheDesc));
		return $this->_cacheDesc;
	}

	/**
	 * build all description of an object : field by field, browse dolibarr class and parse $fields
	 * then convert it to smart* fields names and types
	 *
	 * Note: auto apply translation for label or help fields
	 *
	 * @return  [type]  [return description]
	 */
	private function _objectDesc()
	{
		global $langs;

		$doliBaseClass = new $this->_dolobjectclassname($this->_db);
		// dol_syslog(get_class($this) . " _objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname);


		// $doliMapClass->fetch_optionals();
		// dol_syslog(get_class($this) . " doliBaseClass is " . json_encode($doliBaseClass));
		$obj = new \stdClass();

		// to make order at the end
		$reorder = [];

		foreach ($this->listOfPublishedFields as $doliside => $appside) {
			// dol_syslog(get_class($this) . " _objectDesc : $doliside => $appside for " . get_class($this->_dolmapping));
			//note : foreign key detect, could be done thanks to dolibarr name plan (prefix fk_)
			//but it's better to do it in propertiesFilter function
			if (substr($doliside, 0, 8) == "options_") {
				// dol_syslog(get_class($this) . " _objectDesc : do not call propertiesFilter for that extrafield");
				continue;
			}
			if (isset($this->_dolmapping) && !empty($this->_dolmapping)) {
				// dol_syslog(get_class($this) . " _objectDesc : call propertiesFilter ...");

				// Get field definition from $fields array or generate from property
				$fieldDef = $this->_getFieldDefinition($doliBaseClass, $doliside);
				if ($fieldDef === null) {
					// Field not found in $fields and not a property, skip it
					dol_syslog(get_class($this) . " _objectDesc : field '$doliside' not found in " . get_class($doliBaseClass), LOG_WARNING);
					continue;
				}

				$obj->$appside = $this->_dolmapping->propertiesFilter($fieldDef, $doliside, $appside, $this->parentFieldsOverride);
				if (isset($obj->$appside['position'])) {
					$reorder[$obj->$appside['position']] = $appside;
				}
			}
			//TODO ?
			//foreign key like fk_pays : without integer:class:data ?
			// if (substr($doliside, 0, 3) == "fk_") {
			// 	$obj->$appside['label'] = 'special';
			// }
		}

		//then all official extrafields listed in object definition (for enhanced objects)
		$extrafields = new \ExtraFields($this->_db);

		//TODO CHECK
		$parentElementToUseForExtraFields = $this->parentTableElementToUseForExtraFields ?? '';
		$listExtra = $extrafields->fetch_name_optionals_label($parentElementToUseForExtraFields);
		// dol_syslog(get_class($this) . " _objectDesc : call extrafieldsFilter for element=" . $parentElementToUseForExtraFields . ", soit " . json_encode($listExtra));
		foreach ($listExtra as $extrakey => $extralabel) {
			//do we have to export that extrafield ?
			if (!isset($this->listOfPublishedFields["options_" . $extrakey])) {
				continue;
			}
			//search for mapping
			$appside = $this->listOfPublishedFields["options_" . $extrakey];
			if (trim($appside == '')) {
				$appside = $extrakey;
			}
			// dol_syslog(get_class($this) . " _objectDesc : call extrafieldsFilter ...");
			$obj->$appside = $this->_dolmapping->extrafieldsFilter($parentElementToUseForExtraFields, $extrakey, $appside, $extrafields);
			$reorder[$obj->$appside['position']] = $appside;
		}

		if (isset($this->_dolmapping)) {
			$this->listOfForeignKeys = $this->_dolmapping->getListOfForeignKeys();
		}

		//then lines if needed
		if ($this->parentClassNameForLines != "") {
			$lines = new \stdClass();
			$lines->type = "repeater";
			$lines->label = $langs->trans($this->parentLabelForLines);
			$lines->visible = ["create", "update", "read"];
			$lines->config = new \stdClass();

			$doliBaseLineClass = new $this->parentClassNameForLines($this->_db);
			foreach ($this->listOfPublishedFieldsForLines as $doliside => $appside) {
				// dol_syslog("call for _parentClassNameForLines 2 ... : " . json_encode($this->parentFieldsForLines[$doliside]));
				if (substr($doliside, 0, 8) == "options_") {
					continue;
				}

				if (isset($this->parentFieldsForLines[$doliside]) && !empty($this->parentFieldsForLines[$doliside])) {
					// dol_syslog(get_class($this) . " _objectDesc : call propertiesFilterLine on line for $appside ...");
					$lines->config->{$appside} = $this->_dolmapping->propertiesFilter($this->parentFieldsForLines[$doliside], $doliside, $appside);
				}
			}

			$obj->lines = $lines;
		}

		//order by "position" to help react front code ...
		// dol_syslog("Check for properties positions : " . json_encode($reorder));
		// ksort($reorder);
		// $objsorted = new \stdClass();
		// foreach($reorder as $k => $v) {
		// 	$objsorted->$v = $obj->$v;
		// }
		// $objsorted->lines = $obj->lines;

		//then "lines" if object is like fichinter / propal / invoice ...
		return $obj;
	}

	public function objectType()
	{
		return $this->type;
	}

	/**
	 * Get field definition from Dolibarr $fields array or generate one from object property
	 *
	 * Some Dolibarr classes don't declare all their fields in the $fields array,
	 * but the fields exist as object properties. This method handles both cases.
	 *
	 * @param   object  $doliObject  Dolibarr object instance
	 * @param   string  $fieldName   Field name to look up
	 *
	 * @return  array|null  Field definition array or null if field doesn't exist
	 */
	private function _getFieldDefinition($doliObject, $fieldName)
	{
		// First check if field exists in $fields array (preferred)
		if (isset($doliObject->fields[$fieldName])) {
			return $doliObject->fields[$fieldName];
		}

		// Fallback: check if property exists on the object
		if (!property_exists($doliObject, $fieldName)) {
			return null;
		}

		// Generate a default field definition based on property type/value
		$value = $doliObject->$fieldName ?? null;
		$fieldDef = [
			'type' => 'varchar(255)',
			'label' => ucfirst(str_replace('_', ' ', $fieldName)),
			'enabled' => 1,
			'visible' => 1,
			'position' => 500, // Default position for unmapped fields
			'notnull' => 0,
		];

		// Try to detect field type from value or field name patterns
		if ($value !== null) {
			if (is_int($value)) {
				$fieldDef['type'] = 'integer';
			} elseif (is_float($value)) {
				$fieldDef['type'] = 'double(24,8)';
			} elseif (is_bool($value)) {
				$fieldDef['type'] = 'integer';
			}
		}

		// Detect type from field name patterns
		if (preg_match('/^(fk_|rowid$|id$)/', $fieldName)) {
			$fieldDef['type'] = 'integer';
		} elseif (preg_match('/^date|_date$|datec|datem|tms/', $fieldName)) {
			$fieldDef['type'] = 'datetime';
		} elseif (preg_match('/^(price|amount|total|qty|quantity|weight|volume)/', $fieldName)) {
			$fieldDef['type'] = 'double(24,8)';
		} elseif (preg_match('/^(note|description|comment)/', $fieldName)) {
			$fieldDef['type'] = 'text';
		} elseif (preg_match('/^(email)$/', $fieldName)) {
			$fieldDef['type'] = 'email';
		} elseif (preg_match('/^(phone|fax)/', $fieldName)) {
			$fieldDef['type'] = 'phone';
		} elseif (preg_match('/^(url|website)/', $fieldName)) {
			$fieldDef['type'] = 'url';
		}

		return $fieldDef;
	}

	/**
	 * export object data mapped thanks to _listOfPublishedFields
	 *
	 * @param   [type]  $obj  [$obj description]
	 *
	 * @return  stdClass       object
	 */
	public function exportMappedData($obj)
	{
		$this->_dolmapclassname = preg_replace('/.*DolibarrMapping/', '', get_class($obj));

		// dol_syslog(" ###############exportMappedData for " . $this->_dolmapclassname . " id=" . $obj->id ?? " no id ");
		// dol_syslog(" ##########" . json_encode($obj));
		// dol_syslog(" ######" . json_encode($this->listOfPublishedFields));
		// dol_syslog(" ######" . json_encode($this->listOfPublishedFields));

		$mapped = new \stdClass;
		foreach ($this->listOfPublishedFields as $doliside => $appside) {
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
			// dol_syslog("  ## doliside=" . $doliside . " and appside=" . $appside);
			// dol_syslog("  ## value on dolibarr object =" . $obj->$doliside ?? 'null');
			if (!empty($obj->$doliside)) {
				//try to apply a function as data filter for example for logo to base64 encoded logo (Societe / dmSociete)
				$user_function = "fieldFilterValue" . ucfirst($doliside);
				// dol_syslog("Call user function $user_function on object " . get_class($this));
				if (is_callable([$this, $user_function])) {
					$mapped->$appside = call_user_func([$this, $user_function], $obj, $obj->$doliside);
					continue;
				} else {
					$mapped->$appside = $obj->$doliside;
					continue;
				}
			}

			//detect fk and push object into $mapped->$appside
			if (in_array($doliside, array_keys($this->listOfForeignKeys))) {
				// dol_syslog('#####_listOfForeignKeys = ' . json_encode($this->listOfForeignKeys));
				$mapped->$appside = $this->exportData($doliside, $obj->$doliside);
			}

			// print json_encode($obj->array_options);exit;
			//extrafields
			if (substr($doliside, 0, 8) == "options_") {
				// dol_syslog("  ### special extrafields doliside=" . $doliside);
				//new special types
				foreach ($this->_dolmapping->smartNewObjectsTypes as $ntype => $notused) {
					$verifType = substr($doliside, 8, strlen($ntype));
					if ($verifType == $ntype) {
						$user_function = "fieldFilterValue" . str_replace("_", "", ucfirst($ntype));
						$mapped->$appside = call_user_func([$this, $user_function], $obj, $doliside);
						// dol_syslog("  #### call $user_function for doliside=$doliside, appside=$appside returns " . json_encode($mapped->{$appside}));
						continue 2;
					}
				}

				if (!empty($obj->array_options[$doliside])) {
					$mapped->$appside = $this->exportExtrafieldData($doliside, $obj->array_options[$doliside]);
					continue;
				}
			}
		}

		//export lines content
		if (isset($obj->lines) && count($obj->lines) > 0) {
			foreach ($obj->lines as $line) {
				$filteredline = new \stdClass;
				//export only needed fields listed into _listOfPublishedFieldsForLines
				foreach ($this->listOfPublishedFieldsForLines as $doliside => $appside) {
					$filteredline->$appside = $line->$doliside;
				}
				$mapped->lines[] = $filteredline;
			}
			//for debug get full line raw data
			// $mapped->rawlines = $obj->lines;
		}

		// dol_syslog("fieldFilterValueSmartPhoto mapped is " . json_encode($mapped));
		return $mapped;
	}


	/**
	 * map extrafield, for example
	 * smartinterventions_type_event is a sellist
	 * and definition is 'options'=>array('c_actioncomm:libelle:id'=>null)
	 * so we have to get values ...
	 *
	 * @param   [type]  $name   [$name description]
	 * @param   [type]  $objectid  [$objectid description]
	 *
	 * @return  [type]          [return description]
	 */
	public function exportExtrafieldData($name, $objectid)
	{
		global $conf, $langs;
		// dol_syslog("Ask exportExtrafieldData for name=$name, objectid=$objectid");

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
			//a:1:{s:7:"options";a:1:{s:44:"c_smartinterventions_status:label:code::code";N;}}
			// dol_syslog("Ask exportExtrafieldData for $name, param is " . json_encode($param));

			//TODO implement all dolibarr possibilites :-)
			if (isset($param['options'])) {
				$param_list = array_keys($param['options']);
				$InfoFieldList = explode(":", $param_list[0]);

				if (strpos($param_list[0], 'class.php')) {
					$classname = $InfoFieldList[0];
					$classpath = $InfoFieldList[1];
					// dol_syslog("#######classname=$classname, classpath=$classpath");
					if (!empty($classpath)) {
						$res = dol_include_once($classpath);
						if ($res && $classname && class_exists($classname)) {
							$dolmappingclass = "SmartAuth\\DolibarrMapping\\dm" . $classname;
							$tmpobject = new $classname($this->_db);
							$mapobject = new $dolmappingclass();
							$res = $tmpobject->fetch($objectid);
							if ($res) {
								return $mapobject->exportMappedData($tmpobject);
							}
						}
					}
				}

				// dol_syslog("************* " . json_encode($InfoFieldList));

				$parentField = '';
				// 0 : tableName
				// 1 : label field name
				// 2 : key fields name (if differ of rowid)
				// 3 : key field parent (for dependent lists)
				// 4 : where clause filter on column or table extrafield, syntax field='value' or extra.field=value
				// 5 : id category type
				// 6 : ids categories list separated by comma for category root
				// example c_smartinterventions_status:label:code::code

				if (count($InfoFieldList) < 1) {
					dol_syslog("exportExtrafieldData impossible due to data input " . $param_list[0]);
					return $objectid;
				}

				$tableName 			= $InfoFieldList[0];
				$labelFieldName 	= $InfoFieldList[1];
				$keyFieldName 		= $InfoFieldList[2] ?? null;
				$keyFieldParent 	= $InfoFieldList[3] ?? null;
				$whereFieldOrExtra 	= $InfoFieldList[4] ?? null;

				$keyList = (empty($keyFieldName) ? 'rowid' : $keyFieldName . ' as rowid');


				$idfieldname = $keyFieldName ?? "rowid";

				$out = "";

				if (count($InfoFieldList) > 4 && !empty($whereFieldOrExtra)) {
					if (strpos($whereFieldOrExtra, 'extra.') !== false) {
						$keyList = 'main.' . $keyFieldName . ' as rowid';
					} else {
						$keyList = $keyFieldName . ' as rowid';
					}
				}
				if (count($InfoFieldList) > 3 && !empty($keyFieldParent)) {
					list($parentName, $parentField) = explode('|', $keyFieldParent);
					$keyList .= ', ' . $parentField;
				}

				$filter_categorie = false;
				if (count($InfoFieldList) > 5) {
					if ($tableName == 'categorie') {
						$filter_categorie = true;
					}
				}
				// dol_syslog("Ask exportExtrafieldData (1) for name=$name, objectid=$objectid");

				// dol_syslog("************* " . json_encode($keyList));
				if ($filter_categorie === false) {
					$fields_label = explode('|', $labelFieldName);
					if (is_array($fields_label)) {
						$keyList .= ', ';
						$keyList .= implode(', ', $fields_label);
					}


					// dol_syslog("Ask exportExtrafieldData (2) for name=$name, objectid=$objectid");
					$sqlwhere = '';
					$sql = "SELECT " . $keyList;
					$sql .= ' FROM ' . $this->_db->prefix() . $tableName;
					if (!empty($whereFieldOrExtra)) {
						// can use current entity filter
						if (strpos($whereFieldOrExtra, '$ENTITY$') !== false) {
							$whereFieldOrExtra = str_replace('$ENTITY$', $conf->entity, $whereFieldOrExtra);
						}
						// can use SELECT request
						if (strpos($whereFieldOrExtra, '$SEL$') !== false) {
							$whereFieldOrExtra = str_replace('$SEL$', 'SELECT', $whereFieldOrExtra);
						}

						// current object id can be use into filter
						if (strpos($whereFieldOrExtra, '$ID$') !== false && !empty($objectid)) {
							$whereFieldOrExtra = str_replace('$ID$', $objectid, $whereFieldOrExtra);
						} else {
							$whereFieldOrExtra = str_replace('$ID$', '0', $whereFieldOrExtra);
						}
						//We have to join on extrafield table
						if (strpos($whereFieldOrExtra, 'extra.') !== false) {
							$sql .= ' as main, ' . $this->_db->prefix() . $tableName . '_extrafields as extra';
							$sqlwhere .= " WHERE extra.fk_object=main." . $keyFieldName . " AND " . $whereFieldOrExtra;
						} else {
							// dol_syslog("Ask exportExtrafieldData (5) where for name=$name, objectid=$objectid");
							$sqlwhere .= " WHERE " . $whereFieldOrExtra;
						}
					} else {
						$sqlwhere .= " WHERE $idfieldname='" . $objectid . "'";
					}
					// Some tables may have field, some other not. For the moment we disable it.
					if (in_array($tableName, array('tablewithentity'))) {
						$sqlwhere .= ' AND entity = ' . ((int) $conf->entity);
					}
					$sql .= $sqlwhere;
					//print $sql;

					// dol_syslog("Ask exportExtrafieldData (3) for name=$name, objectid=$objectid :: where=$sqlwhere");
					$resql = $this->_db->query($sql);
					if ($resql) {
						$obj = $this->_db->fetch_object($resql);
						return $obj->{$labelFieldName};
						$this->_db->free($resql);
					} else {
						dol_syslog('Error in request ' . $sql . ' ' . $this->_db->lasterror() . '. Check setup of extra parameters', \LOG_ERR);
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
	 * in bief : follow foreign keys and grab data
	 *
	 * @param   [type]  $name   [$name description]
	 * @param   [type]  $objectid  [$objectid description]
	 *
	 * @return  [type]          [return description]
	 */
	public function exportData($name, $objectid)
	{
		global $conf, $langs;
		//dol_syslog("#######Call exportData for $name / $objectid / " . $this->listOfForeignKeys[$name]);
		// dol_syslog("#######Call exportData for $name / $objectid / " . getEntity('societe'));
		$InfoFieldList = explode(":", $this->listOfForeignKeys[$name]);
		$classname = $InfoFieldList[1];
		$classpath = $InfoFieldList[2];
		// dol_syslog("#######classname=$classname, classpath=$classpath");
		if (!empty($classpath)) {
			$res = dol_include_once($classpath);
			if ($res && $classname && class_exists($classname)) {
				$dolmappingclass = "SmartAuth\\DolibarrMapping\\dm" . $classname;
				$tmpobject = new $classname($this->_db);
				$mapobject = new $dolmappingclass();
				$res = $tmpobject->fetch($objectid);
				if ($res) {
					return $mapobject->exportMappedData($tmpobject);
				}
			}
		}
	}

	/**
	 * get storage path of a linked file
	 *
	 * @param   \CommonObject $object dolibarr object
	 * @param   bool $relativepath   if true return only the last part relative to DOL_DATA_ROOT
	 * 								 if false, return full file path with /home/server/www/ part
	 *
	 * @return  array           file path, element
	 */
	public function getStoragePath($object, $relativepath = true)
	{
		global $conf;

		$dir = '';
		$element = $elementpath = null;
		if (isset($object->parentElementToUseForExtraFields)) {
			$element = $elementpath = $object->parentElementToUseForExtraFields;
		}
		if (empty($element)) {
			$element = $elementpath = $object->element;
		}

		//et toutes les races conditions de dolibarr
		if ($element == "fichinter") {
			$elementpath = "ficheinter";
		}

		if (empty($elementpath)) {
			return null;
		}

		$dir = null;
		if (isset($conf->{$elementpath}->multidir_output[$object->entity])) {
			$dir = $conf->{$elementpath}->multidir_output[$object->entity];
		}
		if (empty($dir)) {
			$dir = $conf->{$elementpath}->dir_output;
		}
		$dir .= "/" . dol_sanitizeFileName($object->ref);

		if ($relativepath) {
			$dir = str_replace(DOL_DATA_ROOT . "/", '', $dir);
		}

		return [$dir, $element];
	}


	/**
	 * photo is stored as varchar dolibarr side (file name) but app need a base64 encoded data
	 *
	 * @param   [type]  $societe  [dolibarr $societe]
	 *
	 * @return  [type]        [return description]
	 */
	public function fieldFilterValueSmartPhoto($object, $doliside)
	{
		global $conf, $db;
		// dol_syslog("dmHelper : call for fieldFilterValueSmartPhoto for $doliside"); // . json_encode($object));
		list($dir, $element) = $this->getStoragePath($object);
		// dol_syslog("dmHelper : call for fieldFilterValueSmartPhoto dir=$dir");

		$img = $dir . "/" . dol_sanitizeFileName($object->array_options[$doliside]);
		// dol_syslog("dmHelper : call for fieldFilterValueSmartPhoto img=$img");

		$ret = new \stdClass;
		$ret->filename = basename($img);
		$ret->title = "default titre";
		$ret->description = "default description";
		$ret->gps = "";
		$ret->src = "";
		$ret->ref = "xxxxxxxxxxx";
		$ret->element = $element;
		$ret->parentid = $object->id;
		$ret->keywords = "one,two,other";
		$ret->note_private = "private note (probably unused)";
		$ret->note_public = "public note (probably unused)";

		$ecm = new \EcmFiles($db);
		$res = $ecm->fetch('', '', $img, '', '', $element, $object->id, $object->entity);
		if ($res) {
			$ret->title = $ecm->cover;
			$ret->description = $ecm->description;
			$ret->ref = $ecm->ref;
			$ret->keywords = $ecm->keywords;
			$ret->note_private = $ecm->note_private;
			$ret->note_public = $ecm->note_public;
			$ret->gps = "";
			$ret->src = "";
		} else {
			dol_syslog("dmHelper : file not found " . json_encode($ecm), \LOG_WARNING);
		}

		// dol_syslog("dmHelper : call for fieldFilterValueSmartPhoto, return " . json_encode($ret));
		return $ret;

		//for example could be a base64 field
		// imgBase64 = "";
		// if (file_exists(img)) {
		// 	$type = pathinfo(img, PATHINFO_EXTENSION);
		// } else {
		// return null;
		// }
		// imgBase64 = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents(img));
		// return imgBase64;
	}
}
