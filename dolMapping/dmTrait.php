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

	/** @var bool Whether to include full linked files list in export */
	public $withFiles = false;

	/** @var int Maximum depth for FK resolution to prevent infinite recursion */
	private static $FK_MAX_DEPTH = 2;

	/** @var int Current recursion depth for FK resolution */
	private static $fkRecursionDepth = 0;

	/**
	 * Per-process cache of objects fetched for FK-label resolution, keyed by
	 * "Class:id". Dedupes the lookup across the rows of a list (many documents
	 * share the same thirdparty) so $listOfForeignKeyLabels stays cheap.
	 * @var array<string,object|null>
	 */
	private static $fkLabelCache = [];

	/**
	 * Mapping from Dolibarr element to category type(s)
	 * Some elements can have multiple category types (e.g. societe can be customer and/or supplier)
	 */
	private static $MAP_ELEMENT_TO_CATEGORY_TYPE = [
		'product'       => ['product'],
		'societe'       => ['customer', 'supplier'],
		'contact'       => ['contact'],
		'member'        => ['member'],
		'user'          => ['user'],
		'project'       => ['project'],
		'bank_account'  => ['bank_account'],
		'warehouse'     => ['warehouse'],
		'actioncomm'    => ['actioncomm'],
		'ticket'        => ['ticket'],
		'knowledgerecord' => ['knowledgemanagement'],
	];

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

		$this->_validateDeclaration();

		//ex: Smartinter or Societe. Object mappers always declare it
		//explicitly (enforced by _validateDeclaration). Dictionary mappers
		//(type=dict/dictionary) are still allowed to omit it for now and
		//rely on the legacy deduction -- see documentation/MAPPERS_CONVENTIONS.md
		//section "Exception : mappers de type dictionary".
		$this->_dolobjectclassname = !empty($this->dolibarrClassName)
			? $this->dolibarrClassName
			: preg_replace('/.*\\\\dm/', '', static::class);

		// dol_syslog("[SmartAuth] ".get_class($this) . " is booting, call _objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname . ", mappping=" . get_class($this->_dolmapping));
		$this->_cacheDesc = $this->_objectDesc();
	}

	/**
	 * Validate mapper declaration at boot time.
	 *
	 * Enforces the conventions documented in documentation/MAPPERS_CONVENTIONS.md:
	 *  - object mappers MUST declare protected $dolibarrClassName pointing
	 *    to an existing Dolibarr class (no more silent deduction from the
	 *    mapper class name, which used to mask mismatches like
	 *    dmThirdparty -> Thirdparty instead of dmThirdparty -> Societe).
	 *  - if $parentClassName is set (only meaningful for sub-objects / lines),
	 *    it must differ from $dolibarrClassName and reference an existing class.
	 *
	 * Dictionary mappers ($type = 'dict' or 'dictionary') are NOT required to
	 * declare $dolibarrClassName -- they describe table-row shapes, not
	 * Dolibarr CommonObjects, and may be consumed without instantiation.
	 *
	 * @return void
	 *
	 * @throws \LogicException when a mapper is misdeclared.
	 */
	private function _validateDeclaration()
	{
		$mapperClass = static::class;
		$isObject = ($this->type === 'object');

		if ($isObject && empty($this->dolibarrClassName)) {
			throw new \LogicException(
				"$mapperClass must declare 'protected \$dolibarrClassName = \"XxxDolibarrClass\";' "
				. "(see documentation/MAPPERS_CONVENTIONS.md). "
				. "Silent deduction from the mapper class name is no longer supported because it "
				. "masks bugs when the Dolibarr class is named differently (e.g. dmThirdparty -> Societe)."
			);
		}

		if (!empty($this->dolibarrClassName) && !class_exists($this->dolibarrClassName)) {
			throw new \LogicException(
				"$mapperClass declares \$dolibarrClassName = '{$this->dolibarrClassName}' "
				. "but this class does not exist. Did you forget the matching "
				. "'require_once DOL_DOCUMENT_ROOT . \"/.../xxx.class.php\";' at the top of the mapper file?"
			);
		}

		if (!empty($this->parentClassName)) {
			if ($this->parentClassName === $this->dolibarrClassName) {
				throw new \LogicException(
					"$mapperClass: \$parentClassName ('{$this->parentClassName}') cannot equal "
					. "\$dolibarrClassName. \$parentClassName is for sub-objects only "
					. "(e.g. a FichinterLigne mapper with parentClassName='Fichinter'). "
					. "For a top-level mapper, remove \$parentClassName."
				);
			}
			if (!class_exists($this->parentClassName)) {
				throw new \LogicException(
					"$mapperClass declares \$parentClassName = '{$this->parentClassName}' "
					. "but this class does not exist."
				);
			}
		}

		// $writableFields must list Dolibarr-side keys (the LEFT side of
		// $listOfPublishedFields), not API-side names. The bug pattern
		// 'doli_field' => 'api_field' in published, then 'api_field' in
		// writable, causes importMappedData() to silently reject every input
		// because $reverseMap is keyed by appside but never finds doliside.
		// Caught silently 3 times on Dolipocket mappers (dmThirdParty.nom,
		// dmContact.civility_code, dmAgenda with 4 entries); this check
		// makes it loud at boot for every consumer that includes the trait.
		if (!empty($this->writableFields) && !empty($this->listOfPublishedFields)) {
			$publishedKeys = array_keys($this->listOfPublishedFields);
			$invalid = array_values(array_diff($this->writableFields, $publishedKeys));
			if (!empty($invalid)) {
				throw new \LogicException(
					"$mapperClass: \$writableFields contains entries that are not Dolibarr-side keys "
					. "of \$listOfPublishedFields: ['" . implode("', '", $invalid) . "']. "
					. "\$writableFields must list the LEFT side of \$listOfPublishedFields "
					. "(Dolibarr column names), not the RIGHT side (API field names). "
					. "Bug pattern: 'doli_field' => 'api_field' in published, then 'api_field' in writable "
					. "makes importMappedData() silently reject every input. "
					. "See documentation/MAPPERS_API.md."
				);
			}
		}
	}

	/**
	 * export object description for client app -- could be better with only serialization (todo/tests)
	 *
	 * @return  \stdClass  object description
	 */
	public function objectDesc()
	{
		// dol_syslog("[SmartAuth] ".get_class($this) . " call : objectDesc " . json_encode($this->_cacheDesc));
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
		// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc for " . $this->_dolmapclassname . " and dolibarr base object " . $this->_dolobjectclassname);


		// $doliMapClass->fetch_optionals();
		// dol_syslog("[SmartAuth] ".get_class($this) . " doliBaseClass is " . json_encode($doliBaseClass));
		$obj = new \stdClass();

		// to make order at the end
		$reorder = [];

		foreach ($this->listOfPublishedFields as $doliside => $appside) {
			// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : $doliside => $appside for " . get_class($this->_dolmapping));
			//note : foreign key detect, could be done thanks to dolibarr name plan (prefix fk_)
			//but it's better to do it in propertiesFilter function
			if (substr($doliside, 0, 8) == "options_") {
				// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : do not call propertiesFilter for that extrafield");
				continue;
			}
			if (isset($this->_dolmapping) && !empty($this->_dolmapping)) {
				// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : call propertiesFilter ...");

				// Get field definition from $fields array or generate from property
				$fieldDef = $this->_getFieldDefinition($doliBaseClass, $doliside);
				if ($fieldDef === null) {
					// Field not found in $fields and not a property, skip it
					dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : field '$doliside' not found in " . get_class($doliBaseClass), LOG_WARNING);
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
		// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : call extrafieldsFilter for element=" . $parentElementToUseForExtraFields . ", soit " . json_encode($listExtra));
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
			// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : call extrafieldsFilter ...");
			$obj->$appside = $this->_dolmapping->extrafieldsFilter($parentElementToUseForExtraFields, $extrakey, $appside, $extrafields);
			// Mirror the guarded reorder above: extrafieldsFilter may return
			// an array without 'position' (or a scalar value at export time);
			// accessing a missing key emits a notice under PHP 8.
			if (isset($obj->$appside['position'])) {
				$reorder[$obj->$appside['position']] = $appside;
			}
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
				// dol_syslog("[SmartAuth] call for _parentClassNameForLines 2 ... : " . json_encode($this->parentFieldsForLines[$doliside]));
				if (substr($doliside, 0, 8) == "options_") {
					continue;
				}

				if (isset($this->parentFieldsForLines[$doliside]) && !empty($this->parentFieldsForLines[$doliside])) {
					// dol_syslog("[SmartAuth] ".get_class($this) . " _objectDesc : call propertiesFilterLine on line for $appside ...");
					$lines->config->{$appside} = $this->_dolmapping->propertiesFilter($this->parentFieldsForLines[$doliside], $doliside, $appside);
				}
			}

			$obj->lines = $lines;
		}

		//order by "position" to help react front code ...
		// dol_syslog("[SmartAuth] Check for properties positions : " . json_encode($reorder));
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
	 * Import an API payload into a sanitized Dolibarr-shaped object.
	 *
	 * Inverse of exportMappedData(). Takes an associative array keyed by
	 * API field names (the VALUES of $listOfPublishedFields), filters it
	 * against the mapper's writable allowlist ($writableFields, keyed by
	 * Dolibarr field name), reverse-maps API names to Dolibarr names, and
	 * casts each value to the type declared in the Dolibarr class's
	 * $fields array.
	 *
	 * Strict by design : any input field that is not declared writable
	 * causes a MapperValidationException listing every offending field.
	 * The exception MUST be caught at the HTTP boundary (controller) and
	 * translated to a 400 response with $e->getErrors() in the body.
	 *
	 * Lines / sub-objects are NOT supported in v1 -- the presence of a
	 * 'lines' key in the input is reported as a validation error. Manage
	 * lines via the Dolibarr object's addLine() / updateLine() / deleteLine().
	 *
	 * Type coercion is best-effort, based on the Dolibarr $fields type
	 * descriptor (integer, double, date, boolean, varchar). Bad input
	 * (e.g. 'abc' for an integer field) silently casts to 0 -- the Dolibarr
	 * persistence layer is responsible for the final validation.
	 *
	 * Example, dmInvoice with writable [ref_client, fk_cond_reglement] :
	 *   $obj = $mapper->importMappedData([
	 *       'customer_ref'  => 'CMD-001',
	 *       'payment_terms' => '42',
	 *   ]);
	 *   // $obj->ref_client === 'CMD-001'
	 *   // $obj->fk_cond_reglement === 42 (int, cast from string)
	 *
	 * See documentation/MAPPERS_API.md for the full contract.
	 *
	 * @param   array<string,mixed>  $input  API payload (API field names as keys)
	 *
	 * @return  \stdClass  sanitized object using Dolibarr field names
	 *
	 * @throws  MapperValidationException  when any input field is rejected
	 */
	public function importMappedData(array $input)
	{
		$errors = [];
		$output = new \stdClass();

		if (array_key_exists('lines', $input)) {
			$errors['lines'] = 'Lines import is not supported by importMappedData() in v1. '
				. 'Manage lines via the Dolibarr object methods (addLine, updateLine, deleteLine).';
			unset($input['lines']);
		}

		// RouteController::dispatch() merges the auth/runtime context into the
		// controller payload BEFORE the request body (see the $payload array it
		// builds: jwt_token_id, jwt_family_id, jwt_device_id, user, login,
		// user_id, entity, buyer, plus the optional oauth_* keys). These are
		// framework-owned and are never writable object fields, so a controller
		// that hands its whole $arr to importMappedData() must not see them
		// rejected. Drop them up front; genuine unknown body fields are still
		// rejected below so client typos remain caught.
		$reservedContextKeys = [
			'jwt_token_id', 'jwt_family_id', 'jwt_device_id',
			'user', 'login', 'user_id', 'entity', 'buyer',
			'oauth_client_id', 'oauth_scopes', 'oauth_grant_type',
		];
		foreach ($reservedContextKeys as $reservedKey) {
			unset($input[$reservedKey]);
		}

		$writableSet = array_flip($this->writableFields ?? []);
		$reverseMap = [];
		foreach ($this->listOfPublishedFields as $doliside => $appside) {
			if (isset($writableSet[$doliside])) {
				$reverseMap[$appside] = $doliside;
			}
		}

		foreach ($input as $apiKey => $apiValue) {
			if (!isset($reverseMap[$apiKey])) {
				$errors[$apiKey] = sprintf(
					"Field '%s' is not writable on %s.",
					$apiKey,
					static::class
				);
			}
		}

		if (!empty($errors)) {
			throw new MapperValidationException($errors);
		}

		$doliBaseClass = new $this->_dolobjectclassname($this->_db);
		foreach ($input as $apiKey => $apiValue) {
			$doliField = $reverseMap[$apiKey];
			$fieldDef = $this->_getFieldDefinition($doliBaseClass, $doliField);
			$output->{$doliField} = $this->_castInputValue($apiValue, $fieldDef);
		}

		return $output;
	}

	/**
	 * Cast an input value to the type declared by a Dolibarr field definition.
	 *
	 * Best-effort. Unknown/missing field defs return the value untouched
	 * so unknown property paths don't lose data.
	 *
	 * @param   mixed       $value     raw input value
	 * @param   array|null  $fieldDef  Dolibarr field descriptor (with 'type' key)
	 *
	 * @return  mixed                  coerced value
	 */
	private function _castInputValue($value, $fieldDef)
	{
		if ($value === null) {
			return null;
		}
		if (!is_array($fieldDef) || empty($fieldDef['type'])) {
			return $value;
		}
		$type = strtolower($fieldDef['type']);

		if (strpos($type, 'integer') === 0 || strpos($type, 'int') === 0) {
			return (int) $value;
		}
		if (strpos($type, 'double') === 0
			|| strpos($type, 'float') === 0
			|| strpos($type, 'real') === 0
			|| strpos($type, 'price') === 0
			|| strpos($type, 'decimal') === 0) {
			return (float) $value;
		}
		if (strpos($type, 'bool') === 0) {
			return ((bool) $value) ? 1 : 0;
		}
		if (strpos($type, 'date') === 0 || strpos($type, 'timestamp') === 0) {
			if (is_numeric($value)) {
				return (int) $value;
			}
			$ts = strtotime((string) $value);
			return $ts === false ? $value : $ts;
		}

		return (string) $value;
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

		// dol_syslog("[SmartAuth] ###############exportMappedData for " . $this->_dolmapclassname . " id=" . $obj->id ?? " no id ");
		// dol_syslog("[SmartAuth] ##########" . json_encode($obj));
		// dol_syslog("[SmartAuth] ######" . json_encode($this->listOfPublishedFields));
		// dol_syslog("[SmartAuth] ######" . json_encode($this->listOfPublishedFields));

		$mapped = new \stdClass;
		foreach ($this->listOfPublishedFields as $doliside => $appside) {
			// Resolve the source value without mutating $obj.
			//
			// Two Dolibarr conventions complicate the lookup:
			//   - 'rowid' is the SQL column name, but CommonObject exposes
			//     the same value via $this->id. Modern Dolibarr classes
			//     (Societe, Product, etc.) do NOT declare $rowid as a
			//     property, so reading $obj->rowid would trigger a PHP 8.2
			//     "Creation of dynamic property" warning if we tried to
			//     assign it back. Read $obj->id and fall back to $obj->rowid
			//     only when the legacy property exists (raw row case).
			//   - 'fk_soc' similarly: some classes expose $socid instead.
			// Reading defensively keeps the mapper compatible with both
			// fetched objects and raw SELECT rows (no property-creation
			// side effect).
			//
			// The trailing ?? null is the catch-all: a mapper can publish
			// keys that Dolibarr only fills under specific fetch paths
			// (fk_user_author after a refresh, etc.) and crashing the whole
			// export over a missing optional field would be the wrong
			// default. The !empty() guard below filters null/0/'' out.
			if ($doliside === 'rowid') {
				$doliVal = !empty($obj->id) ? $obj->id : ($obj->rowid ?? null);
			} elseif ($doliside === 'fk_soc') {
				$doliVal = !empty($obj->socid) ? $obj->socid : ($obj->fk_soc ?? null);
			} else {
				$doliVal = $obj->$doliside ?? null;
			}
			// Use a strict null check rather than PHP empty(): empty() returns
			// true for 0, '0', '', false -- all of which are LEGITIMATE values
			// that must be exported (e.g. fk_product_type=0 for a "product",
			// status=0 for "draft", any boolean flag in its off position). The
			// old !empty() guard silently dropped these from the API payload,
			// forcing every consumer to special-case the missing field. We
			// still skip null so unset Dolibarr properties don't pollute the
			// output, and so downstream FK/extrafield branches keep working.
			if ($doliVal !== null) {
				//try to apply a function as data filter for example for logo to base64 encoded logo (Societe / dmSociete)
				$user_function = "fieldFilterValue" . ucfirst($doliside);
				// dol_syslog("[SmartAuth] Call user function $user_function on object " . get_class($this));
				if (is_callable([$this, $user_function])) {
					$mapped->$appside = call_user_func([$this, $user_function], $obj, $doliVal);
					continue;
				} else {
					$mapped->$appside = $doliVal;
					continue;
				}
			}

			//detect fk and push object into $mapped->$appside
			if (in_array($doliside, array_keys($this->listOfForeignKeys))) {
				// dol_syslog('[SmartAuth] #####_listOfForeignKeys = ' . json_encode($this->listOfForeignKeys));
				$mapped->$appside = $this->exportData($doliside, $doliVal);
			}

			// print json_encode($obj->array_options);exit;
			//extrafields
			if (substr($doliside, 0, 8) == "options_") {
				// dol_syslog("[SmartAuth] ### special extrafields doliside=" . $doliside);
				//new special types
				foreach ($this->_dolmapping->smartNewObjectsTypes as $ntype => $notused) {
					$verifType = substr($doliside, 8, strlen($ntype));
					if ($verifType == $ntype) {
						$user_function = "fieldFilterValue" . str_replace("_", "", ucfirst($ntype));
						$mapped->$appside = call_user_func([$this, $user_function], $obj, $doliside);
						// dol_syslog("[SmartAuth] #### call $user_function for doliside=$doliside, appside=$appside returns " . json_encode($mapped->{$appside}));
						continue 2;
					}
				}

				if (!empty($obj->array_options[$doliside])) {
					$mapped->$appside = $this->exportExtrafieldData($doliside, $obj->array_options[$doliside]);
					continue;
				}
			}
		}

		// Opt-in: resolve declared foreign keys to LABEL companion fields
		// (e.g. fk_soc -> socname / socEmail) without nesting the full related
		// object. Strict consumers keep getting the scalar id AND gain a name
		// string. No-op unless the mapper declares $listOfForeignKeyLabels, so
		// existing mappers are unaffected.
		$this->_resolveForeignKeyLabels($obj, $mapped);

		// Derived fields: computed from the object but not backed by a Dolibarr
		// column. fieldFilterValueXxx() is invoked unconditionally (no source
		// value check). Opt-in: only mappers that declare $listOfDerivedFields
		// trigger this loop. Use case: exposing multiple representations of the
		// same underlying asset (logo / logo_mini / logo_data_url). Method name
		// is derived from the key via snake_case -> CamelCase conversion, eg.
		// 'logo_mini' -> fieldFilterValueLogoMini.
		if (isset($this->listOfDerivedFields) && is_array($this->listOfDerivedFields)) {
			foreach ($this->listOfDerivedFields as $key => $appside) {
				$user_function = "fieldFilterValue" . str_replace('_', '', ucwords($key, '_'));
				if (is_callable([$this, $user_function])) {
					$mapped->$appside = call_user_func([$this, $user_function], $obj);
				}
			}
		}

		//export lines content
		if (isset($obj->lines) && count($obj->lines) > 0) {
			foreach ($obj->lines as $line) {
				$filteredline = new \stdClass;
				//export only needed fields listed into _listOfPublishedFieldsForLines
				foreach ($this->listOfPublishedFieldsForLines as $doliside => $appside) {
					// Dolibarr line classes (CommandeFournisseurLigne, etc.) populate
					// $line->id = $objp->rowid in fetch_lines() but never the inverse,
					// so reading $line->rowid yields null and the API returned id:null
					// even though the line existed in DB. Mirror the header logic by
					// falling back to $line->id when 'rowid' is requested. Same defensive
					// null-coalesce as the header loop avoids PHP 8.2 "Undefined property".
					if ($doliside === 'rowid') {
						$filteredline->$appside = $line->rowid ?? $line->id ?? null;
					} else {
						$filteredline->$appside = $line->$doliside ?? null;
					}
				}
				$mapped->lines[] = $filteredline;
			}
			//for debug get full line raw data
			// $mapped->rawlines = $obj->lines;
		}

		// Automatically add categories if the object supports them
		$categories = $this->getCategoriesForObject($obj);
		if ($categories !== null) {
			$mapped->categories = $categories;
		}

		// Automatically add linked files count (and full list if requested)
		$linkedObjId = $obj->id ?? $obj->rowid ?? 0;
		$linkedElement = $obj->table_element ?? $obj->element ?? '';
		if (!empty($linkedObjId) && !empty($linkedElement)) {
			if ($this->withFiles) {
				$linkedFiles = $this->getLinkedFilesList($obj);
				$mapped->nb_linked_files = count($linkedFiles);
				$mapped->linked_files = $linkedFiles;
			} else {
				$mapped->nb_linked_files = $this->getLinkedFilesCount($obj);
			}
		}

		// dol_syslog("[SmartAuth] fieldFilterValueSmartPhoto mapped is " . json_encode($mapped));
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
		// dol_syslog("[SmartAuth] Ask exportExtrafieldData for name=$name, objectid=$objectid");

		// Read parentTableElementToUseForExtraFields from the MAPPER (this
		// trait is in scope on the mapper), not from a fresh instance of
		// the underlying Dolibarr class. Instantiating $this->_dolmapclassname
		// would build a new Societe / Facture / ... whose default does not
		// carry the mapper's extrafield element binding, so the lookup
		// always returned empty and the function exited early. The
		// property lives on the mapper instance via the trait.
		$parentElementToUseForExtraFields = $this->parentTableElementToUseForExtraFields ?? '';
		if (empty($parentElementToUseForExtraFields)) {
			return;
		}

		$sql = "SELECT param, type FROM " . $this->_db->prefix() . "extrafields WHERE  elementtype='" . $parentElementToUseForExtraFields . "' AND name='" . str_replace("options_", "", $name) . "'";
		$resql = $this->_db->query($sql);
		if ($resql) {
			$obj = $this->_db->fetch_object($resql);
			if (!$obj) {
				// No extrafield definition: the field was migrated off
				// llx_extrafields onto a native/companion column but is still
				// surfaced via array_options['options_<name>'] for backward
				// compatibility (the consumer keeps the historical key). The
				// value is already resolved by the caller's hydration, so pass it
				// through as-is instead of dropping it to null -- returning void
				// here silently blanked such fields in the API payload (e.g.
				// date_intervention once the smartinterventions_date_inter
				// extrafield was removed in favour of the si_date_inter column).
				dol_syslog("[SmartAuth] exportExtrafieldData: no extrafield definition for name=$name elementtype=$parentElementToUseForExtraFields, passing value through", LOG_DEBUG);
				return $objectid;
			}
			$param = jsonOrUnserialize($obj->param);
			$type = isset($obj->type) ? $obj->type : '';
			//a:1:{s:7:"options";a:1:{s:44:"c_smartinterventions_status:label:code::code";N;}}
			// dol_syslog("[SmartAuth] Ask exportExtrafieldData for $name, param is " . json_encode($param));

			if (isset($param['options']) && is_array($param['options'])) {
				// Multi-value extrafields (checkbox / chkbxlst) store the value
				// as a comma-separated list of ids. Resolve each element (one id
				// at a time -- a single id has no comma so it falls through to
				// the static / dynamic single-value resolution below) and return
				// the list of labels instead of the raw csv.
				if (in_array($type, array('checkbox', 'chkbxlst'), true)
					&& strpos((string) $objectid, ',') !== false) {
					$out = array();
					foreach (explode(',', (string) $objectid) as $singleId) {
						$singleId = trim($singleId);
						if ($singleId === '') {
							continue;
						}
						$out[] = $this->exportExtrafieldData($name, $singleId);
					}
					return $out;
				}

				// Static value lists (select / radio, and each element of a
				// checkbox): param['options'] maps value => label directly, there
				// is no source table to query.
				if (in_array($type, array('select', 'radio', 'checkbox'), true)) {
					if (array_key_exists($objectid, $param['options'])) {
						$label = $param['options'][$objectid];
						return ($label === null || $label === '') ? $objectid : $langs->trans($label);
					}
					return $objectid;
				}

				$param_list = array_keys($param['options']);
				$InfoFieldList = explode(":", $param_list[0]);

				if (strpos($param_list[0], 'class.php')) {
					$classname = $InfoFieldList[0];
					$classpath = $InfoFieldList[1];
					// dol_syslog("[SmartAuth] #######classname=$classname, classpath=$classpath");
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

				// dol_syslog("[SmartAuth] ************* " . json_encode($InfoFieldList));

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
					dol_syslog("[SmartAuth] exportExtrafieldData impossible due to data input " . $param_list[0]);
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
				// dol_syslog("[SmartAuth] Ask exportExtrafieldData (1) for name=$name, objectid=$objectid");

				// dol_syslog("[SmartAuth] ************* " . json_encode($keyList));
				if ($filter_categorie === false) {
					$fields_label = explode('|', $labelFieldName);
					if (is_array($fields_label)) {
						$keyList .= ', ';
						$keyList .= implode(', ', $fields_label);
					}


					// dol_syslog("[SmartAuth] Ask exportExtrafieldData (2) for name=$name, objectid=$objectid");
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
							// dol_syslog("[SmartAuth] Ask exportExtrafieldData (5) where for name=$name, objectid=$objectid");
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

					// dol_syslog("[SmartAuth] Ask exportExtrafieldData (3) for name=$name, objectid=$objectid :: where=$sqlwhere");
					$resql = $this->_db->query($sql);
					if ($resql) {
						$obj = $this->_db->fetch_object($resql);
						$this->_db->free($resql);
						if (is_object($obj)) {
							// Build the label from one or several columns (the
							// descriptor syntax allows "field1|field2"), and
							// translate each part like Dolibarr's showOutputField
							// does: dictionary tables (c_*) often store a lang key
							// in their label column rather than plain text.
							// $langs->trans() returns its input unchanged when no
							// translation exists, so free-text labels (e.g. a
							// company name from a non-dictionary sellist) are left
							// intact.
							$labelParts = array();
							foreach ($fields_label as $field_toshow) {
								$field_toshow = trim($field_toshow);
								if ($field_toshow !== '' && isset($obj->{$field_toshow})) {
									$labelParts[] = $langs->trans($obj->{$field_toshow});
								}
							}
							if (!empty($labelParts)) {
								return implode(' ', $labelParts);
							}
							// Descriptor referenced a column not returned by the
							// query: fall back to the legacy single-property read,
							// then to the raw id.
							return $obj->{$labelFieldName} ?? $objectid;
						}
						// No row matched (orphan id referenced by the extrafield): fall back to the raw value
						dol_syslog('[SmartAuth] exportExtrafieldData: no row for name=' . $name . ', objectid=' . $objectid . ' in table ' . $tableName . ', returning raw value', \LOG_WARNING);
					} else {
						dol_syslog('[SmartAuth] Error in request ' . $sql . ' ' . $this->_db->lasterror() . '. Check setup of extra parameters', \LOG_ERR);
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

		// Scalar extrafields (boolean, int, double, price, date) carry no
		// param['options'] and fall through here. Coerce them to a
		// JSON-friendly value using the same type convention as the input
		// side (_castInputValue): boolean -> 0/1, int -> int, double/price ->
		// float, date -> timestamp. varchar / text / unresolved sellist keys
		// stay strings (pass-through).
		return $this->_castInputValue($objectid, array('type' => $type));
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

		// Prevent infinite recursion on circular FK references
		if (self::$fkRecursionDepth >= self::$FK_MAX_DEPTH) {
			return $objectid; // Return just ID at max depth
		}

		//dol_syslog("[SmartAuth] #######Call exportData for $name / $objectid / " . $this->listOfForeignKeys[$name]);
		// dol_syslog("[SmartAuth] #######Call exportData for $name / $objectid / " . getEntity('societe'));
		$InfoFieldList = explode(":", $this->listOfForeignKeys[$name]);
		$classname = $InfoFieldList[1];
		$classpath = $InfoFieldList[2];
		// dol_syslog("[SmartAuth] #######classname=$classname, classpath=$classpath");
		if (!empty($classpath)) {
			$res = dol_include_once($classpath);
			if ($res && $classname && class_exists($classname)) {
				$dolmappingclass = "SmartAuth\\DolibarrMapping\\dm" . $classname;
				$tmpobject = new $classname($this->_db);
				$mapobject = new $dolmappingclass();
				$res = $tmpobject->fetch($objectid);
				if ($res) {
					self::$fkRecursionDepth++;
					try {
						return $mapobject->exportMappedData($tmpobject);
					} finally {
						self::$fkRecursionDepth--;
					}
				}
			}
		}
	}

	/**
	 * Opt-in foreign-key -> label resolution.
	 *
	 * A mapper declares $listOfForeignKeyLabels to surface one or more scalar
	 * label fields derived from a FK, e.g.:
	 *
	 *   protected $listOfForeignKeyLabels = [
	 *       'fk_soc' => [
	 *           'class'  => 'Societe',
	 *           'path'   => 'societe/class/societe.class.php',
	 *           'labels' => ['socname' => 'name', 'socEmail' => 'email'],
	 *       ],
	 *   ];
	 *
	 * Unlike exportData() (which nests the whole mapped related object and is
	 * unreachable for non-null values anyway), this keeps the original scalar
	 * id untouched and only ADDS the requested label properties as companion
	 * fields on $mapped -- so strict consumers do not break. The related object
	 * is fetched once per (class,id) via a per-process cache, so a list of N
	 * rows sharing the same thirdparty costs a single fetch.
	 *
	 * The property is intentionally NOT declared on the trait: declaring it
	 * with an initial value here would fatally conflict with a using class that
	 * declares its own non-empty initializer. empty() reads it safely whether
	 * or not the mapper defines it.
	 *
	 * @param object    $obj    The fetched Dolibarr object being exported.
	 * @param \stdClass $mapped The mapped output to enrich (mutated in place).
	 * @return void
	 */
	protected function _resolveForeignKeyLabels($obj, $mapped)
	{
		if (empty($this->listOfForeignKeyLabels) || !is_array($this->listOfForeignKeyLabels)) {
			return;
		}
		foreach ($this->listOfForeignKeyLabels as $doliside => $spec) {
			if (!is_array($spec) || empty($spec['class']) || empty($spec['labels']) || !is_array($spec['labels'])) {
				continue;
			}
			// fk_soc carries the dual socid/fk_soc convention; other FKs read
			// straight from the published doliside property.
			if ($doliside === 'fk_soc') {
				$fkId = !empty($obj->socid) ? $obj->socid : ($obj->fk_soc ?? null);
			} else {
				$fkId = $obj->$doliside ?? null;
			}
			$labels = $spec['labels'];

			if (empty($fkId)) {
				// Emit empty companions so the output shape stays stable.
				foreach ($labels as $appsideKey => $prop) {
					$mapped->$appsideKey = '';
				}
				continue;
			}

			$class = $spec['class'];
			$cacheKey = $class . ':' . (int) $fkId;
			if (!array_key_exists($cacheKey, self::$fkLabelCache)) {
				$resolved = null;
				if (!empty($spec['path'])) {
					dol_include_once($spec['path']);
				}
				if (class_exists($class)) {
					$tmp = new $class($this->_db);
					if ($tmp->fetch((int) $fkId) > 0) {
						$resolved = $tmp;
					} else {
						dol_syslog('[SmartAuth] _resolveForeignKeyLabels fetch failed for ' . $cacheKey, LOG_WARNING);
					}
				} else {
					dol_syslog('[SmartAuth] _resolveForeignKeyLabels class not found: ' . $class, LOG_WARNING);
				}
				self::$fkLabelCache[$cacheKey] = $resolved;
			}
			$resolved = self::$fkLabelCache[$cacheKey];
			foreach ($labels as $appsideKey => $prop) {
				$mapped->$appsideKey = ($resolved !== null && isset($resolved->$prop)) ? $resolved->$prop : '';
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
		// dol_syslog("[SmartAuth] dmHelper : call for fieldFilterValueSmartPhoto for $doliside"); // . json_encode($object));
		list($dir, $element) = $this->getStoragePath($object);
		// dol_syslog("[SmartAuth] dmHelper : call for fieldFilterValueSmartPhoto dir=$dir");

		$img = $dir . "/" . dol_sanitizeFileName($object->array_options[$doliside]);
		// dol_syslog("[SmartAuth] dmHelper : call for fieldFilterValueSmartPhoto img=$img");

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
			dol_syslog("[SmartAuth] dmHelper : no ECM metadata for img=" . $img . " element=" . $element . " parentid=" . $object->id . " entity=" . $object->entity . " (using defaults)", \LOG_DEBUG);
		}

		// dol_syslog("[SmartAuth] dmHelper : call for fieldFilterValueSmartPhoto, return " . json_encode($ret));
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

	/**
	 * Get all categories associated with a Dolibarr object
	 *
	 * @param   \CommonObject  $object  Dolibarr object (Product, Societe, Contact, etc.)
	 *
	 * @return  array|null  Array of category data or null if object doesn't support categories
	 */
	public function getCategoriesForObject($object)
	{
		if (empty($object->id) || empty($object->element)) {
			return null;
		}

		$element = $object->element;

		// Check if this element type supports categories
		if (!isset(self::$MAP_ELEMENT_TO_CATEGORY_TYPE[$element])) {
			return null;
		}

		require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';

		$categoryTypes = self::$MAP_ELEMENT_TO_CATEGORY_TYPE[$element];
		$allCategories = [];
		$categorie = new \Categorie($this->_db);

		foreach ($categoryTypes as $catType) {
			$cats = $categorie->containing($object->id, $catType, 'object');
			if (is_array($cats)) {
				foreach ($cats as $cat) {
					// Avoid duplicates (e.g. societe with same category as customer and supplier)
					if (!isset($allCategories[$cat->id])) {
						$allCategories[$cat->id] = [
							'id'          => (int) $cat->id,
							'label'       => $cat->label,
							'description' => $cat->description ?? '',
							'color'       => $cat->color ?? '',
							'parent'      => !empty($cat->fk_parent) ? (int) $cat->fk_parent : null,
							'type'        => $catType,
						];
					}
				}
			}
		}

		return array_values($allCategories);
	}

	/**
	 * Count files linked to a Dolibarr object via ECM
	 *
	 * Uses llx_ecm_files with src_object_type + src_object_id (modern Dolibarr approach).
	 *
	 * @param   object  $obj  Dolibarr object with id and table_element/element properties
	 *
	 * @return  int  Number of linked files
	 */
	public function getLinkedFilesCount($obj)
	{
		global $conf;

		$objectId = $obj->id ?? $obj->rowid ?? 0;
		$element = $obj->table_element ?? $obj->element ?? '';

		if (empty($objectId) || empty($element)) {
			return 0;
		}

		$sql = "SELECT COUNT(*) as nb FROM " . $this->_db->prefix() . "ecm_files";
		$sql .= " WHERE src_object_type = '" . $this->_db->escape($element) . "'";
		$sql .= " AND src_object_id = " . (int) $objectId;
		$sql .= " AND entity = " . (int) $conf->entity;

		$resql = $this->_db->query($sql);
		if ($resql && $row = $this->_db->fetch_object($resql)) {
			return (int) $row->nb;
		}

		return 0;
	}

	/**
	 * Get list of files linked to a Dolibarr object via ECM
	 *
	 * Returns metadata from llx_ecm_files including share token for download URLs.
	 *
	 * @param   object  $obj  Dolibarr object with id and table_element/element properties
	 *
	 * @return  array  List of file metadata arrays
	 */
	public function getLinkedFilesList($obj)
	{
		global $conf;

		$objectId = $obj->id ?? $obj->rowid ?? 0;
		$element = $obj->table_element ?? $obj->element ?? '';

		if (empty($objectId) || empty($element)) {
			return [];
		}

		$sql = "SELECT rowid, filename, filepath, date_c, gen_or_uploaded, share, description, keywords";
		$sql .= " FROM " . $this->_db->prefix() . "ecm_files";
		$sql .= " WHERE src_object_type = '" . $this->_db->escape($element) . "'";
		$sql .= " AND src_object_id = " . (int) $objectId;
		$sql .= " AND entity = " . (int) $conf->entity;
		$sql .= " ORDER BY position ASC, date_c ASC";

		$files = [];
		$resql = $this->_db->query($sql);
		if ($resql) {
			while ($fileObj = $this->_db->fetch_object($resql)) {
				$file = [
					'id' => (int) $fileObj->rowid,
					'filename' => $fileObj->filename,
					'path' => $fileObj->filepath,
					'date' => $fileObj->date_c,
					'type' => $fileObj->gen_or_uploaded,
					'share' => $fileObj->share ?: null,
				];
				if (!empty($fileObj->description)) {
					$file['description'] = $fileObj->description;
				}
				if (!empty($fileObj->keywords)) {
					$file['keywords'] = $fileObj->keywords;
				}
				$files[] = $file;
			}
			$this->_db->free($resql);
		}

		return $files;
	}
}
