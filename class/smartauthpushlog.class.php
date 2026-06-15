<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file        class/smartauthpushlog.class.php
 * \ingroup     smartauth
 * \brief       CRUD class for Web Push send logs (llx_smartauth_push_logs)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for SmartAuthPushLog.
 *
 * One row per Web Push dispatch attempt (one recipient = one row). Powers the
 * push_logs_list.php audit page. Rows are inserted by PushSender::send() through
 * recordSend() (a plain INSERT that needs no User object, so it works from cron
 * and triggers), and purged by SmartAuth::doScheduledJob().
 */
class SmartAuthPushLog extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'smartauth';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'pushlog';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'smartauth_push_logs';

	/**
	 * @var int  Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string Fontawesome picto.
	 */
	public $picto = 'fa-bell';

	/**
	 * @var array  Array with all fields and their property.
	 */
	public $fields = array(
		'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>'1', 'position'=>1, 'notnull'=>1, 'visible'=>0, 'noteditable'=>'1', 'index'=>1, 'css'=>'left', 'comment'=>"Id"),
		'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>'1', 'position'=>10, 'notnull'=>1, 'visible'=>1, 'noteditable'=>'1', 'csslist'=>'nowraponall'),
		'subject_type' => array('type'=>'varchar(16)', 'label'=>'PushLogSubjectType', 'enabled'=>'1', 'position'=>20, 'notnull'=>1, 'visible'=>1, 'noteditable'=>'1', 'index'=>1, 'arrayofkeyval'=>array('user'=>'PushLogSubjectUser', 'account'=>'PushLogSubjectAccount', 'member'=>'PushLogSubjectMember'), 'csslist'=>'tdoverflowmax100'),
		'fk_user' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'PushLogUser', 'enabled'=>'1', 'position'=>25, 'notnull'=>1, 'visible'=>1, 'noteditable'=>'1', 'index'=>1, 'css'=>'tdoverflowmax150', 'csslist'=>'tdoverflowmax150'),
		'fk_societe_account' => array('type'=>'integer', 'label'=>'PushLogAccount', 'enabled'=>'1', 'position'=>26, 'notnull'=>0, 'visible'=>-1, 'noteditable'=>'1', 'index'=>1, 'csslist'=>'tdoverflowmax100'),
		'fk_adherent' => array('type'=>'integer', 'label'=>'PushLogMember', 'enabled'=>'1', 'position'=>27, 'notnull'=>0, 'visible'=>-1, 'noteditable'=>'1', 'index'=>1, 'csslist'=>'tdoverflowmax100'),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'picto'=>'company', 'enabled'=>'1', 'position'=>30, 'notnull'=>1, 'visible'=>-1, 'index'=>1, 'noteditable'=>'1'),
		'notification_type' => array('type'=>'varchar(64)', 'label'=>'PushLogType', 'enabled'=>'1', 'position'=>40, 'notnull'=>0, 'visible'=>1, 'noteditable'=>'1', 'index'=>1, 'css'=>'tdoverflowmax150', 'csslist'=>'tdoverflowmax150'),
		'notification_title' => array('type'=>'varchar(255)', 'label'=>'PushLogTitle', 'enabled'=>'1', 'position'=>50, 'notnull'=>0, 'visible'=>1, 'noteditable'=>'1', 'css'=>'tdoverflowmax200', 'csslist'=>'tdoverflowmax200'),
		'notification_body' => array('type'=>'text', 'label'=>'PushLogBody', 'enabled'=>'1', 'position'=>60, 'notnull'=>0, 'visible'=>1, 'noteditable'=>'1', 'csslist'=>'tdoverflowmax300'),
		'notification_data' => array('type'=>'text', 'label'=>'PushLogData', 'enabled'=>'1', 'position'=>70, 'notnull'=>0, 'visible'=>-2, 'noteditable'=>'1'),
		'http_status' => array('type'=>'integer', 'label'=>'PushLogHttpStatus', 'enabled'=>'1', 'position'=>80, 'notnull'=>0, 'visible'=>1, 'noteditable'=>'1', 'index'=>1, 'css'=>'center', 'csslist'=>'center'),
		'success' => array('type'=>'integer', 'label'=>'PushLogSuccess', 'enabled'=>'1', 'position'=>90, 'notnull'=>1, 'visible'=>1, 'noteditable'=>'1', 'index'=>1, 'arrayofkeyval'=>array('0'=>'PushLogFailed', '1'=>'PushLogAccepted'), 'css'=>'center', 'csslist'=>'center'),
		'error_message' => array('type'=>'varchar(255)', 'label'=>'PushLogError', 'enabled'=>'1', 'position'=>100, 'notnull'=>0, 'visible'=>1, 'noteditable'=>'1', 'css'=>'tdoverflowmax200', 'csslist'=>'tdoverflowmax200'),
		'fk_subscription' => array('type'=>'integer', 'label'=>'PushLogSubscription', 'enabled'=>'1', 'position'=>110, 'notnull'=>0, 'visible'=>-1, 'noteditable'=>'1', 'index'=>1, 'csslist'=>'tdoverflowmax100'),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>'1', 'position'=>500, 'notnull'=>0, 'visible'=>0),
	);

	public $rowid;
	public $date_creation;
	public $subject_type;
	public $fk_user;
	public $fk_societe_account;
	public $fk_adherent;
	public $entity;
	public $notification_type;
	public $notification_title;
	public $notification_body;
	public $notification_data;
	public $http_status;
	public $success;
	public $error_message;
	public $fk_subscription;
	public $tms;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf, $langs;

		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Unset fields that are disabled.
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate arrayofkeyval labels.
		if (is_object($langs)) {
			$langs->loadLangs(array('smartauth@smartauth'));
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Insert a send log row directly (no User object required).
	 *
	 * Used by PushSender::send() from cron/trigger context. Silently does
	 * nothing (returns 0) when logging is disabled by SMARTAUTH_PUSH_LOG_ENABLED.
	 *
	 * @param array $data {
	 *   fk_subscription:?int, subject_type:string, fk_user:int,
	 *   fk_societe_account:?int, fk_adherent:?int, entity:int,
	 *   notification_type:?string, notification_title:?string,
	 *   notification_body:?string, notification_data:?string,
	 *   http_status:?int, success:int, error_message:?string }
	 * @return int <0 if KO, 0 if logging disabled, rowid if OK
	 */
	public function recordSend(array $data)
	{
		if (!getDolGlobalInt('SMARTAUTH_PUSH_LOG_ENABLED', 1)) {
			return 0;
		}

		$now = $this->db->idate(dol_now());

		$cols = array(
			'fk_subscription'    => isset($data['fk_subscription']) && $data['fk_subscription'] !== null ? (int) $data['fk_subscription'] : 'null',
			'subject_type'       => "'".$this->db->escape(!empty($data['subject_type']) ? $data['subject_type'] : 'user')."'",
			'fk_user'            => isset($data['fk_user']) ? (int) $data['fk_user'] : 0,
			'fk_societe_account' => isset($data['fk_societe_account']) && $data['fk_societe_account'] !== null ? (int) $data['fk_societe_account'] : 'null',
			'fk_adherent'        => isset($data['fk_adherent']) && $data['fk_adherent'] !== null ? (int) $data['fk_adherent'] : 'null',
			'entity'             => isset($data['entity']) ? (int) $data['entity'] : 1,
			'notification_type'  => $this->sqlStrOrNull(isset($data['notification_type']) ? $data['notification_type'] : null, 64),
			'notification_title' => $this->sqlStrOrNull(isset($data['notification_title']) ? $data['notification_title'] : null, 255),
			'notification_body'  => $this->sqlStrOrNull(isset($data['notification_body']) ? $data['notification_body'] : null, 0),
			'notification_data'  => $this->sqlStrOrNull(isset($data['notification_data']) ? $data['notification_data'] : null, 0),
			'http_status'        => isset($data['http_status']) && $data['http_status'] !== null ? (int) $data['http_status'] : 'null',
			'success'            => !empty($data['success']) ? 1 : 0,
			'error_message'      => $this->sqlStrOrNull(isset($data['error_message']) ? $data['error_message'] : null, 255),
			'date_creation'      => "'".$now."'",
		);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " (".implode(', ', array_keys($cols)).")";
		$sql .= " VALUES (".implode(', ', array_values($cols)).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("SmartAuthPushLog::recordSend insert failed: ".$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
	}

	/**
	 * Quote a string for SQL or return the literal 'null'.
	 *
	 * @param string|null $value  Raw value
	 * @param int         $maxlen Truncate length (0 = no truncation)
	 * @return string             Quoted SQL literal or 'null'
	 */
	private function sqlStrOrNull($value, $maxlen)
	{
		if ($value === null || $value === '') {
			return 'null';
		}
		if ($maxlen > 0) {
			$value = dol_substr((string) $value, 0, $maxlen);
		}
		return "'".$this->db->escape((string) $value)."'";
	}

	/**
	 * Load object in memory from the database.
	 *
	 * @param int    $id  Id object
	 * @param string $ref Ref
	 * @return int        <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param  string $sortorder  Sort Order
	 * @param  string $sortfield  Sort field
	 * @param  int    $limit      Limit
	 * @param  int    $offset     Offset
	 * @param  array  $filter     Filter array
	 * @param  string $filtermode Filter mode (AND or OR)
	 * @return array|int          int <0 if KO, array of objects if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{
		dol_syslog("SmartAuth ".__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = "SELECT ".$this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (".getEntity($this->element).")";
		} else {
			$sql .= " WHERE 1 = 1";
		}

		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				if ($key == 't.rowid') {
					$sqlwhere[] = $key." = ".((int) $value);
				} elseif (isset($this->fields[preg_replace('/^t\./', '', $key)]) && in_array($this->fields[preg_replace('/^t\./', '', $key)]['type'], array('date', 'datetime', 'timestamp'))) {
					$sqlwhere[] = $key." = '".$this->db->idate($value)."'";
				} elseif ($key == 'customsql') {
					$sqlwhere[] = $value;
				} elseif (strpos($value, '%') === false) {
					$sqlwhere[] = $key." IN (".$this->db->sanitize($this->db->escape($value)).")";
				} else {
					$sqlwhere[] = $key." LIKE '%".$this->db->escapeforlike($this->db->escape($value))."%'";
				}
			}
		}
		if (count($sqlwhere) > 0) {
			$sql .= " AND (".implode(" ".$filtermode." ", $sqlwhere).")";
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
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog("SmartAuth ".__METHOD__.' '.join(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Create object into database.
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Update object into database.
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
	 * Delete object in database.
	 *
	 * @param User $user      User that deletes
	 * @param bool $notrigger false=launch triggers, true=disable triggers
	 * @return int            <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Purge log rows older than the given number of days.
	 *
	 * @param int $retentionDays Keep rows newer than this many days
	 * @return int               <0 if KO, number of deleted rows if OK
	 */
	public function purgeOlderThan($retentionDays)
	{
		$retentionDays = (int) $retentionDays;
		if ($retentionDays <= 0) {
			return 0;
		}

		$limit = dol_time_plus_duree(dol_now(), -$retentionDays, 'd');

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE date_creation < '".$this->db->idate($limit)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("SmartAuthPushLog::purgeOlderThan failed: ".$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * Initialise object with example values.
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{
		$this->initAsSpecimenCommon();
	}
}
