<?php
/* Copyright (C) 2017  Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023  Frédéric France          <frederic.france@netlogic.fr>
 * Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/smartauth.class.php
 * \ingroup     smartauth
 * \brief       This file is a CRUD class file for Auth (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once 'smartauthdevices.class.php';
//require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
//require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Class for Auth
 */
class SmartAuth extends CommonObject
{
	public $socid;
	public $labelStatusShort;
	public $labelStatus;
	public $output;
	public $user_validation;
	/**
	 * @var string ID of module.
	 */
	public $module = 'smartauth';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'auth';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'smartauth_auth';

	/**
	 * @var int  Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 1;

	/**
	 * @var string String with name of icon for auth. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'auth@smartauth' if picto is file 'img/object_auth.png'.
	 */
	public $picto = 'fa-file';


	public const STATUS_DRAFT = 0;
	public const STATUS_VALIDATED = 1;
	public const STATUS_CANCELED = 9;
	public const STATUS_DISABLED = 10;

	//cache
	private $_appNameUIDCache = [];

	/**
	 *  'type' field format:
	 *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
	 *  	'select' (list of values are in 'options'),
	 *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:Sortfield]]]]',
	 *  	'chkbxlst:...',
	 *  	'varchar(x)',
	 *  	'text', 'text:none', 'html',
	 *   	'double(24,8)', 'real', 'price',
	 *  	'date', 'datetime', 'timestamp', 'duration',
	 *  	'boolean', 'checkbox', 'radio', 'array',
	 *  	'mail', 'phone', 'url', 'password', 'ip'
	 *		Note: Filter must be a Dolibarr Universal Filter syntax string. Example: "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.status:!=:0) or (t.nature:is:NULL)"
	 *  'label' the translation key.
	 *  'picto' is code of a picto to show before value in forms
	 *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalInt("MY_SETUP_PARAM")' or 'isModEnabled("multicurrency")' ...)
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'alwayseditable' says if field can be modified also when status is not draft ('1' or '0')
	 *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommanded to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 or 2 if field can be used for measure. Field type must be summable like integer or double(24,8). Use 1 in most cases, or 2 if you don't want to see the column total into list (for example for percentage)
	 *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'css'=>'minwidth300 maxwidth500 widthcentpercentminusx', 'cssview'=>'wordbreak', 'csslist'=>'tdoverflowmax200'
	 *  'help' and 'helplist' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
	 *  'arrayofkeyval' to set a list of values if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel"). Note that type can be 'integer' or 'varchar'
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *	'validate' is 1 if need to validate with $this->validateField()
	 *  'copytoclipboard' is 1 or 2 to allow to add a picto to copy value into clipboard (1=picto after label, 2=picto after value)
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'position' => 10, 'notnull' => 1, 'visible' => -1, 'showoncombobox' => 1),
		'appuid' => array('type' => 'integer', 'label' => 'smartAuthAppuid', 'enabled' => '1', 'position' => 15, 'notnull' => 0, 'visible' => 1,),
		'salt' => array('type' => 'varchar(32)', 'label' => 'smartAuthSalt', 'enabled' => '1', 'position' => 20, 'notnull' => 0, 'visible' => -1,),
		'date_creation' => array('type' => 'datetime', 'label' => 'Datecreation', 'enabled' => '1', 'position' => 25, 'notnull' => 1, 'visible' => 1,),
		'date_lastused' => array('type' => 'datetime', 'label' => 'Datelastused', 'enabled' => '1', 'position' => 30, 'notnull' => 0, 'visible' => 1,),
		'ip' => array('type' => 'varchar(50)', 'label' => 'smartAuthLastIP', 'enabled' => '1', 'position' => 35, 'notnull' => 0, 'visible' => 1, 'default' => ''),
		// refresh_count is hidden in lists: token rotation creates a new
		// row at every refresh, so the counter on the visible (current)
		// row stays at 0. The historical count is on the previous (now
		// revoked) row, not interesting in the active-tokens view.
		'refresh_count' => array('type' => 'integer', 'label' => 'smartAuthRefreshCount', 'enabled' => '1', 'position' => 36, 'notnull' => 0, 'visible' => 0,),
		'date_eol' => array('type' => 'datetime', 'label' => 'Dateeol', 'enabled' => '1', 'position' => 38, 'notnull' => 0, 'visible' => 1,),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'position' => 40, 'notnull' => 1, 'visible' => -1,),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => '$user->admin', 'position' => 45, 'notnull' => 1, 'visible' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150',),
		// fk_user_modif is hidden in lists: token INSERTs in AuthController
		// bypass CommonObject::update() and never set this field, so the
		// column was empty for ~100% of rows and only added visual noise.
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => '$user->admin', 'position' => 50, 'notnull' => -1, 'visible' => 0, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150',),
		'fk_device_id' => array('type' => 'integer:SmartAuthDevices:smartauth/class/smartauthdevices.class.php', 'label' => 'device', 'enabled' => '1', 'position' => 55, 'notnull' => -1, 'visible' => 1, 'index' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150', 'noteditable' => '1',),
		'fk_authid' => array('type' => 'integer', 'label' => 'AuthElementID', 'enabled' => '1', 'position' => 60, 'notnull' => 1, 'visible' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150',),
		'auth_element' => array('type' => 'varchar(128)', 'label' => 'AuthElementSource', 'enabled' => '1', 'position' => 65, 'notnull' => 1, 'visible' => 1,),
		'family_id' => array('type' => 'integer', 'label' => 'smartAuthFamilyId', 'enabled' => '1', 'position' => 70, 'notnull' => 0, 'visible' => 1,),
		'token_type' => array('type' => 'varchar(20)', 'label' => 'smartAuthTokenType', 'enabled' => '1', 'position' => 75, 'notnull' => 0, 'visible' => 1,),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 2, 'position' => 1000, 'notnull' => 1, 'default' => 0, 'index' => 1, 'arrayofkeyval' => array(1 => 'Enabled', 9 => 'Disabled')),
	);
	public $rowid;
	public $appuid;
	public $salt;
	public $refresh_count;
	public $date_creation;
	public $date_lastused;
	public $date_eol;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $fk_device_id;
	public $fk_authid; //id of auth element, it depends on auth_element value (user/societe_account)
	public $auth_element; //user or societe_account for the moment
	public $family_id;
	public $ip;
	public $token_type;
	public $status;
	// END MODULEBUILDER PROPERTIES

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf, $langs;

		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->hasRight('smartauth', 'auth', 'read')) {
			$this->fields['myfield']['visible'] = 1;
			$this->fields['myfield']['noteditable'] = 0;
		}*/

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}

		$this->fields['appuid']['type'] = "sellist";
		$this->fields['appuid']['arrayofkeyval'] = $this->getAllModulesNames();
	}

	/**
	 * Create object into database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		$resultcreate = $this->createCommon($user, $notrigger);

		//$resultvalidate = $this->validate($user, $notrigger);

		return $resultcreate;
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id   Id object
	 * @param string $ref  Ref
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{
		$result = $this->fetchCommon($id, $ref);
		return $result;
	}


	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param  string      $sortorder    Sort Order
	 * @param  string      $sortfield    Sort field
	 * @param  int         $limit        limit
	 * @param  int         $offset       Offset
	 * @param  array       $filter       Filter array. Example array('field'=>'valueforlike', 'customurl'=>...)
	 * @param  string      $filtermode   Filter mode (AND or OR)
	 * @return array|int                 int <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{
		dol_syslog("SmartAuth ".__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = "SELECT ";
		$sql .= $this->getFieldList('t');
		$sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";
		} else {
			$sql .= " WHERE 1 = 1";
		}
		// Manage filter
		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				if ($key == 't.rowid') {
					$sqlwhere[] = $key . " = " . ((int) $value);
				} elseif (isset($this->fields[$key]['type']) && in_array($this->fields[$key]['type'], array('date', 'datetime', 'timestamp'))) {
					$sqlwhere[] = $key . " = '" . $this->db->idate($value) . "'";
				} elseif ($key == 'customsql') {
					$sqlwhere[] = $value;
				} elseif (strpos($value, '%') === false) {
					$sqlwhere[] = $key . " IN (" . $this->db->sanitize($this->db->escape($value)) . ")";
				} else {
					$sqlwhere[] = $key . " LIKE '%" . $this->db->escapeforlike($this->db->escape($value)) . "%'";
				}
			}
		}
		if (count($sqlwhere) > 0) {
			$sql .= " AND (" . implode(" " . $filtermode . " ", $sqlwhere) . ")";
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog("SmartAuth ".__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param User $user       User that deletes
	 * @param bool $notrigger  false=launch triggers, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		return $this->deleteCommon($user, $notrigger);
		//return $this->deleteCommon($user, $notrigger, 1);
	}

	/**
	 *  Delete a line of object in database
	 *
	 *	@param  User	$user       User that delete
	 *  @param	int		$idline		Id of line to delete
	 *  @param 	bool 	$notrigger  false=launch triggers after, true=disable triggers
	 *  @return int         		>0 if OK, <0 if KO
	 */
	public function deleteLine(User $user, $idline, $notrigger = false)
	{
		if ($this->status < 0) {
			$this->error = 'ErrorDeleteLineNotAllowedByObjectStatus';
			return -2;
		}

		return $this->deleteLineCommon($user, $idline, $notrigger);
	}


	/**
	 *	Validate object
	 *
	 *	@param		User	$user     		User making status change
	 *  @param		int		$notrigger		1=Does not execute triggers, 0= execute triggers
	 *	@return  	int						<=0 if OK, 0=Nothing done, >0 if KO
	 */
	public function validate($user, $notrigger = 0)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

		$error = 0;

		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			dol_syslog("SmartAuth ".get_class($this) . "::validate action abandonned: already validated", LOG_WARNING);
			return 0;
		}

		/* if (! ((!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','write'))
		 || (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !empty($user->rights->smartauth->auth->auth_advance->validate))))
		 {
		 $this->error='NotEnoughPermissions';
		 dol_syslog("SmartAuth ".get_class($this)."::valid ".$this->error, LOG_ERR);
		 return -1;
		 }*/

		$now = dol_now();

		$this->db->begin();

		// Define new ref
		if (!$error && (preg_match('/^[\(]?PROV/i', (string) $this->ref) || empty($this->ref))) { // empty should not happened, but when it occurs, the test save life
			$num = $this->getNextNumRef();
		} else {
			$num = $this->ref;
		}
		$this->newref = $num;

		if (!empty($num)) {
			// Validate
			$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
			$sql .= " SET ref = '" . $this->db->escape($num) . "',";
			$sql .= " status = " . self::STATUS_VALIDATED;
			if (!empty($this->fields['date_validation'])) {
				$sql .= ", date_validation = '" . $this->db->idate($now) . "'";
			}
			if (!empty($this->fields['fk_user_valid'])) {
				$sql .= ", fk_user_valid = " . ((int) $user->id);
			}
			$sql .= " WHERE rowid = " . ((int) $this->id);

			dol_syslog("SmartAuth ".get_class($this) . "::validate()", LOG_DEBUG);
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_print_error($this->db);
				$this->error = $this->db->lasterror();
				$error++;
			}

			if (!$error && !$notrigger) {
				// Call trigger
				$result = $this->call_trigger('MYOBJECT_VALIDATE', $user);
				if ($result < 0) {
					$error++;
				}
				// End call triggers
			}
		}

		if (!$error) {
			$this->oldref = $this->ref;

			// Rename directory if dir was a temporary ref
			if (preg_match('/^[\(]?PROV/i', (string) $this->ref)) {
				// Now we rename also files into index
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filename = CONCAT('" . $this->db->escape($this->newref) . "', SUBSTR(filename, " . (strlen($this->ref) + 1) . ")), filepath = 'auth/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filename LIKE '" . $this->db->escape($this->ref) . "%' AND filepath = 'auth/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filepath = 'auth/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filepath = 'auth/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}

				// We rename directory ($this->ref = old ref, $num = new ref) in order not to lose the attachments
				$oldref = dol_sanitizeFileName($this->ref);
				$newref = dol_sanitizeFileName($num);
				$dirsource = $conf->smartauth->dir_output . '/auth/' . $oldref;
				$dirdest = $conf->smartauth->dir_output . '/auth/' . $newref;
				if (!$error && file_exists($dirsource)) {
					dol_syslog("SmartAuth ".get_class($this) . "::validate() rename dir " . $dirsource . " into " . $dirdest);

					if (@rename($dirsource, $dirdest)) {
						dol_syslog("SmartAuth Rename ok");
						// Rename docs starting with $oldref with $newref
						$listoffiles = dol_dir_list($conf->smartauth->dir_output . '/auth/' . $newref, 'files', 1, '^' . preg_quote($oldref, '/'));
						foreach ($listoffiles as $fileentry) {
							$dirsource = $fileentry['name'];
							$dirdest = preg_replace('/^' . preg_quote($oldref, '/') . '/', $newref, $dirsource);
							$dirsource = $fileentry['path'] . '/' . $dirsource;
							$dirdest = $fileentry['path'] . '/' . $dirdest;
							@rename($dirsource, $dirdest);
						}
					}
				}
			}
		}

		// Set new ref and current status
		if (!$error) {
			$this->ref = $num;
			$this->status = self::STATUS_VALIDATED;
		}

		if (!$error) {
			$this->db->commit();
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}


	/**
	 *	Set draft status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		// Protection
		if ($this->status <= self::STATUS_DRAFT) {
			return 0;
		}

		/* if (! ((!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','write'))
		 || (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','smartauth_advance','validate'))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'SMARTAUTH_MYOBJECT_UNVALIDATE');
	}

	/**
	 *	Set cancel status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		// Protection
		if ($this->status != self::STATUS_VALIDATED) {
			return 0;
		}

		/* if (! ((!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','write'))
		 || (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','smartauth_advance','validate'))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'SMARTAUTH_MYOBJECT_CANCEL');
	}

	/**
	 *	Set back to validated status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			return 0;
		}

		/*if (! ((!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','write'))
		 || (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('smartauth','smartauth_advance','validate'))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'SMARTAUTH_MYOBJECT_REOPEN');
	}

	/**
	 * getTooltipContentArray
	 *
	 * @param 	array 	$params 	Params to construct tooltip data
	 * @since 	v18
	 * @return 	array
	 */
	public function getTooltipContentArray($params)
	{
		global $conf, $langs;

		$datas = [];

		if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
			return ['optimize' => $langs->trans("ShowAuth")];
		}
		$datas['picto'] = img_picto('', $this->picto) . ' <u>' . $langs->trans("Auth") . '</u>';
		if (isset($this->status)) {
			$datas['picto'] .= ' ' . $this->getLibStatut(5);
		}
		$datas['ref'] = '<br><b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;

		return $datas;
	}

	/**
	 *  Return a link to the object card (with optionaly the picto)
	 *
	 *  @param  int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param  string  $option                     On what the link point to ('nolink', ...)
	 *  @param  int     $notooltip                  1=Disable tooltip
	 *  @param  string  $morecss                    Add more css on link
	 *  @param  int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs, $hookmanager;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';
		$params = [
			'id' => $this->id,
			'objecttype' => $this->element . ($this->module ? '@' . $this->module : ''),
			'option' => $option,
		];
		$classfortooltip = 'classfortooltip';
		$dataparams = '';
		if (getDolGlobalInt('MAIN_ENABLE_AJAX_TOOLTIP')) {
			$classfortooltip = 'classforajaxtooltip';
			$dataparams = ' data-params="' . dol_escape_htmltag(json_encode($params)) . '"';
			$label = '';
		} else {
			$label = implode($this->getTooltipContentArray($params));
		}

		$url = dol_buildpath('/smartauth/auth_card.php', 1) . '?id=' . $this->id;

		if ($option !== 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($url && $add_save_lastsearch_values) {
				$url .= '&save_lastsearch_values=1';
			}
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
				$label = $langs->trans("ShowAuth");
				$linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
			}
			$linkclose .= ($label ? ' title="' . dol_escape_htmltag($label, 1) . '"' : ' title="tocomplete"');
			$linkclose .= $dataparams . ' class="' . $classfortooltip . ($morecss ? ' ' . $morecss : '') . '"';
		} else {
			$linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
		}

		if ($option == 'nolink' || empty($url)) {
			$linkstart = '<span';
		} else {
			$linkstart = '<a href="' . $url . '"';
		}
		$linkstart .= $linkclose . '>';
		if ($option == 'nolink' || empty($url)) {
			$linkend = '</span>';
		} else {
			$linkend = '</a>';
		}

		$result .= $linkstart;

		if (empty($this->showphoto_on_popup)) {
			if ($withpicto) {
				$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), (($withpicto != 2) ? 'class="paddingright"' : ''), 0, 0, $notooltip ? 0 : 1);
			}
		} else {
			if ($withpicto) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity] . "/$class/" . dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');

					$pathtophoto = $class . '/' . $this->ref . '/thumbs/' . substr($filename, 0, $pospoint) . '_mini' . substr($filename, $pospoint);
					if (!getDolGlobalString(strtoupper($module . '_' . $class) . '_FORMATLISTPHOTOSASUSERS')) {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref"><img class="photo' . $module . '" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div></div>';
					} else {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><img class="photouserphoto userphoto" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div>';
					}

					$result .= '</div>';
				} else {
					$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . '"'), 0, 0, $notooltip ? 0 : 1);
				}
			}
		}

		if ($withpicto != 2) {
			$result .= $this->ref;
		}

		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action, $hookmanager;
		$hookmanager->initHooks(array($this->element . 'dao'));
		$parameters = array('id' => $this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLabelStatus($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		// A token row stays at status=STATUS_VALIDATED until either it is
		// actively revoked (refresh rotation, logout, family kill) or the
		// SMARTAUTH_TOKEN_EOL_DAYS cron sweeps it. Until that sweep runs,
		// the row will look "Enabled" in the list even though its date_eol
		// has passed and any auth attempt would be rejected via the JWT
		// `exp` claim. Show "Expired" in that case so the list is honest.
		if (
			$this->status == self::STATUS_VALIDATED
			&& !empty($this->date_eol)
		) {
			$eolTs = is_numeric($this->date_eol) ? (int) $this->date_eol : strtotime((string) $this->date_eol);
			if ($eolTs !== false && $eolTs > 0 && $eolTs < dol_now()) {
				global $langs;
				$label = $langs->transnoentitiesnoconv('Expired');
				return dolGetStatus($label, $label, '', 'status8', $mode);
			}
		}
		return $this->LibStatut($this->status, $mode);
	}

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the label of a given status
	 *
	 *  @param	int		$status        Id status
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			//$langs->load("smartauth@smartauth");
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatus[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			$this->labelStatus[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
			$this->labelStatus[self::STATUS_DISABLED] = $langs->transnoentitiesnoconv('Disabled');
			$this->labelStatusShort[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatusShort[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			$this->labelStatusShort[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
			$this->labelStatusShort[self::STATUS_DISABLED] = $langs->transnoentitiesnoconv('Disabled');
		}

		$statusType = 'status' . $status;
		//if ($status == self::STATUS_VALIDATED) $statusType = 'status1';
		if ($status == self::STATUS_CANCELED) {
			$statusType = 'status6';
		}

		if ($status == self::STATUS_DISABLED) {
			$statusType = 'status9';
		}

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode);
	}

	/**
	 *	Load the info information in the object
	 *
	 *	@param  int		$id       Id of object
	 *	@return	void
	 */
	public function info($id)
	{
		$sql = "SELECT rowid,";
		$sql .= " date_creation as datec, tms as datem,";
		$sql .= " fk_user_creat, fk_user_modif";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";
		$sql .= " WHERE t.rowid = " . ((int) $id);

		$result = $this->db->query($sql);
		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);

				$this->id = $obj->rowid;

				$this->user_creation_id = $obj->fk_user_creat;
				$this->user_modification_id = $obj->fk_user_modif;
				if (!empty($obj->fk_user_valid)) {
					$this->user_validation_id = $obj->fk_user_valid;
				}
				$this->date_creation     = $this->db->jdate($obj->datec);
				$this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
				if (!empty($obj->datev)) {
					$this->date_validation   = empty($obj->datev) ? '' : $this->db->jdate($obj->datev);
				}
			}

			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 * Initialise object with example values
	 * Id must be 0 if object instance is a specimen
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{
		// Set here init that are not commonf fields
		// $this->property1 = ...
		// $this->property2 = ...

		$this->initAsSpecimenCommon();
	}

	/**
	 *  Returns the reference to the following non used object depending on the active numbering module.
	 *
	 *  @return string      		Object free reference
	 */
	public function getNextNumRef()
	{
		global $langs, $conf;
		$langs->load("smartauth@smartauth");

		if (!getDolGlobalString('SMARTAUTH_MYOBJECT_ADDON')) {
			$conf->global->SMARTAUTH_MYOBJECT_ADDON = 'mod_auth_standard';
		}

		if (getDolGlobalString('SMARTAUTH_MYOBJECT_ADDON')) {
			$mybool = false;

			$file = getDolGlobalString('SMARTAUTH_MYOBJECT_ADDON') . ".php";
			$classname = getDolGlobalString('SMARTAUTH_MYOBJECT_ADDON');

			// Include file with class
			$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
			foreach ($dirmodels as $reldir) {
				$dir = dol_buildpath($reldir . "core/modules/smartauth/");

				// Load file with numbering class (if found)
				$mybool |= @include_once $dir . $file;
			}

			if ($mybool === false) {
				dol_print_error('', "Failed to include file " . $file);
				return '';
			}

			if (class_exists($classname)) {
				$obj = new $classname();
				$numref = $obj->getNextValue($this);

				if ($numref != '' && $numref != '-1') {
					return $numref;
				} else {
					$this->error = $obj->error;
					//dol_print_error($this->db,get_class($this)."::getNextNumRef ".$obj->error);
					return "";
				}
			} else {
				print $langs->trans("Error") . " " . $langs->trans("ClassNotFound") . ' ' . $classname;
				return "";
			}
		} else {
			print $langs->trans("ErrorNumberingModuleNotSetup", $this->element);
			return "";
		}
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		//global $conf, $langs;

		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlofile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';

		dol_syslog("SmartAuth ".__METHOD__, LOG_DEBUG);

		$now = dol_now();

		$this->db->begin();

		//note : do not delete old keys -- used by logs !
		$max = (int) getDolGlobalString('SMARTAUTH_TOKEN_EOL_DAYS');
		if ($max > 0) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth SET status='" . self::STATUS_CANCELED . "', token='outdated' WHERE date_eol < '" . $this->db->idate($now) . "'";
			$resql = $this->db->query($sql);
			if ($resql) {
				dol_syslog("SmartAuth::doScheduledJob Update status success");
			} else {
				dol_syslog("SmartAuth::doScheduledJob Update status error", LOG_ERR);
			}
		}

		//cleanup old logs
		if (getDolGlobalString('SMARTAUTH_CLEAN_LOGS')) {
			$max = (int) getDolGlobalString('SMARTAUTH_LAST_LOGS');
			if ($max > 0) {
				$sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_logs WHERE tms < '" . $this->db->idate(time() - ($max * 24 * 3600)) . "'";
				$resql = $this->db->query($sql);
				if ($resql) {
					dol_syslog("SmartAuth::doScheduledJob Update status success");
				} else {
					dol_syslog("SmartAuth::doScheduledJob Update status error", LOG_ERR);
				}
			}
		}

		// Cleanup short-lived auxiliary tables that would otherwise grow
		// indefinitely. None of these need an opt-in because they only ever
		// store transient state (codes/tokens already expired or revoked,
		// QR pairings older than the audit window).
		dol_include_once('/smartauth/class/smartauthqrpairing.class.php');
		dol_include_once('/smartauth/class/smartauthoauthcode.class.php');
		dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');

		// QR pairings: rows live a few minutes during the flow; keep one
		// week of history for audit then drop. Configurable via
		// SMARTAUTH_QRPAIR_RETENTION_DAYS (defaults to 7).
		$qrRetentionDays = (int) getDolGlobalString('SMARTAUTH_QRPAIR_RETENTION_DAYS');
		if ($qrRetentionDays <= 0) {
			$qrRetentionDays = 7;
		}
		$qrRepo = new \SmartAuthQrPairing($this->db);
		$qrDeleted = $qrRepo->deleteOld($qrRetentionDays * 24 * 3600);
		if ($qrDeleted >= 0) {
			dol_syslog("SmartAuth::doScheduledJob qr_pairings cleanup deleted $qrDeleted rows");
		}

		// OAuth2 authorization codes: short-lived (5-10min TTL). Drop any
		// row past its expires_at, plus any used row older than 1h.
		$codesExpired = \SmartAuthOAuthCode::deleteExpired($this->db);
		if ($codesExpired >= 0) {
			dol_syslog("SmartAuth::doScheduledJob oauth_codes deleteExpired removed $codesExpired rows");
		}
		$codesUsed = \SmartAuthOAuthCode::deleteUsed($this->db);
		if ($codesUsed >= 0) {
			dol_syslog("SmartAuth::doScheduledJob oauth_codes deleteUsed removed $codesUsed rows");
		}

		// OAuth2 access/refresh tokens: drop revoked or expired rows that
		// are older than 7 days (matches the helper's default cutoff).
		$tokensExpired = \SmartAuthOAuthToken::deleteExpired($this->db);
		if ($tokensExpired >= 0) {
			dol_syslog("SmartAuth::doScheduledJob oauth_tokens deleteExpired removed $tokensExpired rows");
		}

		// Upload idempotency cache: completed rows live for 24h (covers
		// the worst-case client retry window of ~10min many times over);
		// processing rows live 10min so a process killed between INSERT
		// and UPDATE cannot block the same key indefinitely.
		dol_include_once('/smartauth/class/smartauthuploadidempotency.class.php');
		$idemRepo = new \SmartAuthUploadIdempotency($this->db);
		$idemDeleted = $idemRepo->deleteOld(86400);
		if ($idemDeleted >= 0) {
			dol_syslog("SmartAuth::doScheduledJob upload_idempotency deleteOld removed $idemDeleted rows");
		}
		$idemStale = $idemRepo->deleteStaleProcessing(600);
		if ($idemStale >= 0) {
			dol_syslog("SmartAuth::doScheduledJob upload_idempotency deleteStaleProcessing removed $idemStale rows");
		}

		// Web Push subscriptions: purge rows the push service marked dead.
		// status=9 (expired: 404/410 or MAX_ERROR_COUNT consecutive failures)
		// is always removed; rows with repeated errors whose last error is
		// older than the retention window are removed too. Retention is
		// configurable (default 7 days). Computed in PHP for SQLite/MySQL
		// compatibility (no DATE_SUB()). affected_rows() takes the RESQL.
		dol_include_once('/smartauth/api/PushSender.php');
		$pushRetentionDays = (int) getDolGlobalString('SMARTAUTH_PUSH_SUBSCRIPTION_RETENTION_DAYS');
		if ($pushRetentionDays <= 0) {
			$pushRetentionDays = 7;
		}
		$pushCutoff = $this->db->idate(dol_now() - $pushRetentionDays * 24 * 3600);
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
		$sql .= " WHERE status = 9";
		$sql .= " OR (error_count >= ".((int) \SmartAuth\Api\PushSender::MAX_ERROR_COUNT);
		$sql .= " AND date_last_error IS NOT NULL AND date_last_error < '".$this->db->escape($pushCutoff)."')";
		$resql = $this->db->query($sql);
		if ($resql) {
			$pushDeleted = (int) $this->db->affected_rows($resql);
			dol_syslog("SmartAuth::doScheduledJob push_subscriptions cleanup deleted $pushDeleted rows");
		} else {
			dol_syslog("SmartAuth::doScheduledJob push_subscriptions cleanup error: ".$this->db->lasterror(), LOG_ERR);
		}

		// Web Push send logs: bounded retention (RGPD). The log stores
		// notification title/body, so it must not grow unbounded. Retention is
		// configurable (default 90 days). Computed in PHP for SQLite/MySQL
		// compatibility (no DATE_SUB()). affected_rows() takes the RESQL.
		$pushLogRetentionDays = (int) getDolGlobalString('SMARTAUTH_PUSH_LOG_RETENTION_DAYS');
		if ($pushLogRetentionDays <= 0) {
			$pushLogRetentionDays = 90;
		}
		dol_include_once('/smartauth/class/smartauthpushlog.class.php');
		if (class_exists('SmartAuthPushLog')) {
			$pushLog = new SmartAuthPushLog($this->db);
			$pushLogDeleted = $pushLog->purgeOlderThan($pushLogRetentionDays);
			if ($pushLogDeleted >= 0) {
				dol_syslog("SmartAuth::doScheduledJob push_logs cleanup deleted $pushLogDeleted rows");
			} else {
				dol_syslog("SmartAuth::doScheduledJob push_logs cleanup error", LOG_ERR);
			}
		} else {
			dol_syslog("SmartAuth::doScheduledJob push_logs cleanup skipped: SmartAuthPushLog class not found", LOG_WARNING);
		}

		$this->db->commit();
		return $error;
	}

	public function getModuleName($id)
	{
		global $db;
		global $_appNameUIDCache;
		if (empty($_appNameUIDCache)) {
			$this->getAllModulesNames();
		}
		if(isset($_appNameUIDCache[$id]))
			return $_appNameUIDCache[$id];
		return '';
	}

	public function getAllModulesNames()
	{
		global $db;
		global $_appNameUIDCache;
		if (empty($_appNameUIDCache)) {
			$_appNameUIDCache[''] = '';
			$sql = "SELECT id,module FROM " . MAIN_DB_PREFIX . "rights_def WHERE id > 100000 GROUP BY module";
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					$id = substr($obj->id, 0, 6);
					$_appNameUIDCache[$id] = $obj->module;
				}
			}
		}
		return $_appNameUIDCache;
	}

	/**
	 *	Set status to disabled
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, >0 if OK
	 */
	public function setDisabled($user, $notrigger = 0)
	{
		return $this->setStatusCommon($user, self::STATUS_DISABLED, $notrigger, 'SMARTAUTH_KEY_DISABLED');
	}
}
