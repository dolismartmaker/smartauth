<?php
/* Copyright (C) 2002-2007 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2015 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2010      Juanjo Menent        <jmenent@2byte.es>
 * Copyright (C) 2013      Cédric Salvador      <csalvador@gpcsolutions.fr>
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
 *  \file       smartauth/user_tab.php
 *  \brief      Tab for user's smartAuth details
 *  \ingroup    user
 */

// Load Dolibarr environment
// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/usergroups.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

// load module libraries
dol_include_once('/smartauth/class/smartauth.class.php');
dol_include_once('/smartauth/class/smartlogs.class.php');
dol_include_once('/smartauth/lib/tools.php');

// Load translation files required by page
$langs->loadLangs(array('users', 'other'));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm');
$id = (GETPOST('userid', 'int') ? GETPOST('userid', 'int') : GETPOST('id', 'int'));
$ref = GETPOST('ref', 'alpha');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'userdoc'; // To manage different context of search

if (!isset($id) || empty($id)) {
	accessforbidden();
}

$massaction = GETPOST('massaction', 'alpha'); // The bulk action (combo box choice into lists)
$cancel     = GETPOST('cancel', 'alpha'); // We click on a Cancel button
$toselect   = GETPOST('toselect', 'array'); // Array of ids of elements selected into a list
$backtopage = GETPOST('backtopage', 'alpha'); // Go back to a dedicated page
$optioncss  = GETPOST('optioncss', 'aZ'); // Option for the css output (always '' except when 'print')
$mode       = GETPOST('mode', 'aZ'); // The output mode ('list', 'kanban', 'hierarchy', 'calendar', ...)

// Load variable for pagination
$limit = 5; //hard
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Define value to know what current user can do on users
$canadduser = (!empty($user->admin) || $user->hasRight("user", "user", "write"));
$canreaduser = (!empty($user->admin) || $user->hasRight("user", "user", "read"));
$canedituser = (!empty($user->admin) || $user->hasRight("user", "user", "write"));
$candisableuser = (!empty($user->admin) || $user->hasRight("user", "user", "delete"));
$canreadgroup = $canreaduser;
$caneditgroup = $canedituser;
if (!empty($conf->global->MAIN_USE_ADVANCED_PERMS)) {
	$canreadgroup = (!empty($user->admin) || $user->hasRight("user", "group_advance", "read"));
	$caneditgroup = (!empty($user->admin) || $user->hasRight("user", "group_advance", "write"));
}
// Define value to know what current user can do on properties of edited user
if ($id) {
	// $user est le user qui edite, $id est l'id de l'utilisateur edite
	$caneditfield = ((($user->id == $id) && $user->hasRight("user", "self", "write"))
		|| (($user->id != $id) && $user->hasRight("user", "user", "write")));
	$caneditpassword = ((($user->id == $id) && $user->hasRight("user", "self", "password"))
		|| (($user->id != $id) && $user->hasRight("user", "user", "passsword")));
}

$permissiontoadd = $caneditfield;	// Used by the include of actions_addupdatedelete.inc.php and actions_linkedfiles
$permtoedit = $caneditfield;

// Security check
$socid = 0;
if ($user->socid > 0) {
	$socid = $user->socid;
}
$feature2 = 'user';

$result = restrictedArea($user, 'user', $id, 'user&user', $feature2);

if ($user->id <> $id && !$canreaduser) {
	accessforbidden();
}

// Get parameters
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
	$sortorder = "DESC";
}
if (!$sortfield) {
	$sortfield = "rowid";
}

$object = new User($db);
if ($id > 0 || !empty($ref)) {
	$result = $object->fetch($id, $ref, '', 1);
	$object->getrights();
	//$upload_dir = $conf->user->multidir_output[$object->entity] . "/" . $object->id ;
	// For users, the upload_dir is always $conf->user->entity for the moment
	$upload_dir = $conf->user->dir_output . "/" . $object->id;
}

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('usercard', 'userdoc', 'globalcard'));



/*
 * Actions
 */

$parameters = array('id' => $socid);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	include DOL_DOCUMENT_ROOT . '/core/actions_linkedfiles.inc.php';
}

// Handle revoke action
if ($action == 'revoke' && $permtoedit) {
	$token_id = GETPOST('token_id', 'int');

	if ($token_id > 0) {
		// Verify token belongs to current user (security check)
		$sql = "SELECT fk_authid FROM " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " WHERE rowid = " . (int)$token_id;
		$sql .= " AND fk_authid = " . (int)$id;

		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			// Token belongs to user, revoke it
			$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " SET status = 9, salt = 'revoked_by_user'";
			$sql .= " WHERE rowid = " . (int)$token_id;

			$result = $db->query($sql);
			if ($result) {
				setEventMessages($langs->trans("TokenRevoked"), null, 'mesgs');
				header("Location: " . $_SERVER['PHP_SELF'] . '?id=' . $id);
				exit;
			} else {
				setEventMessages($langs->trans("ErrorRevokingToken"), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans("TokenNotFound"), null, 'errors');
		}
	}
}

// Handle rename device action
if ($action == 'rename' && $permtoedit) {
	$token_id = GETPOST('token_id', 'int');
	$device_label = GETPOST('device_label', 'restricthtml');

	if ($token_id > 0) {
		// Verify token belongs to current user
		$sql = "SELECT fk_authid FROM " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " WHERE rowid = " . (int)$token_id;
		$sql .= " AND fk_authid = " . (int)$id;

		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			// Update device label - need to add column first or use extrafields
			// For now, we'll store in a custom field if it exists
			$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " SET note_private = '" . $db->escape($device_label) . "'";
			$sql .= " WHERE rowid = " . (int)$token_id;

			$result = $db->query($sql);
			if ($result) {
				setEventMessages($langs->trans("DeviceRenamed"), null, 'mesgs');
				header("Location: " . $_SERVER['PHP_SELF'] . '?id=' . $id);
				exit;
			}
		}
	}
}

if (GETPOST('cancel', 'alpha')) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

	// Mass actions
	$objectclass = 'SmartAuth';
	$objectlabel = 'SmartAuth';
	$uploaddir = $conf->smartauth->dir_output;
	include DOL_DOCUMENT_ROOT . '/core/actions_massactions.inc.php';

	// You can add more action here
	// if ($action == 'xxx' && $permissiontoxxx) ...
}

//========================

/*
 * View
 */

$form = new Form($db);


$person_name = !empty($object->firstname) ? $object->lastname . ", " . $object->firstname : $object->lastname;
$title = $person_name . " - " . $langs->trans('SmartAuth');
$help_url = '';
llxHeader('', $title, $help_url);

if ($object->id) {
	/*
	 * Affichage onglets
	 */
	if (isModEnabled('notification')) {
		$langs->load("mails");
	}
	$head = user_prepare_head($object);

	print dol_get_fiche_head($head, 'tabSmartAuth', $langs->trans("User"), -1, 'user');

	print '<div class="fichecenter">';


	// ------------------------------------------------------------------------------------------------------------ API KEYS
	//note eric attention truandage, object était user et passe maintenant Auth ...
	$id = GETPOST('id', 'int');
	// Initialize technical objects
	$object = new SmartAuth($db);
	$object->fields['fk_authid']['visible'] = 0;
	$object->fields['auth_element']['visible'] = 0;

	$diroutputmassaction = $conf->smartauth->dir_output . '/temp/massgeneration/' . $user->id;
	// print "<p>contexte: $contextpage</p>";
	$hookmanager->initHooks(array($contextpage)); 	// Note that conf->hooks_modules contains array of activated contexes

	// Default sort order (if not yet defined by previous GETPOST)
	if (!$sortfield) {
		reset($object->fields);					// Reset is required to avoid key() to return null.
		$sortfield = "t." . key($object->fields); // Set here default search field. By default 1st field in definition.
	}

	// Definition of array of fields for columns
	$arrayfields = array();
	foreach ($object->fields as $key => $val) {
		// If $val['visible']==0, then we never show the field
		if (!empty($val['visible'])) {
			$visible = (int) dol_eval($val['visible'], 1);
			$arrayfields['t.' . $key] = array(
				'label' => $val['label'],
				'checked' => (($visible < 0) ? 0 : 1),
				'enabled' => (abs($visible) != 3 && dol_eval($val['enabled'], 1)),
				'position' => $val['position'],
				'help' => isset($val['help']) ? $val['help'] : ''
			);
		}
	}
	// Extra fields

	$object->fields = dol_sort_array($object->fields, 'position');
	//$arrayfields['anotherfield'] = array('type'=>'integer', 'label'=>'AnotherField', 'checked'=>1, 'enabled'=>1, 'position'=>90, 'csslist'=>'right');
	$arrayfields = dol_sort_array($arrayfields, 'position');



	// Build and execute select
	// --------------------------------------------------------------------
	$sql = 'SELECT ';
	$sql .= $object->getFieldList('t');
	// Add fields from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql = preg_replace('/,\s*$/', '', $sql);

	$sqlfields = $sql; // $sql fields to remove for count total

	$sql .= " FROM " . MAIN_DB_PREFIX . $object->table_element . " as t";

	// Add table from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql .= " WHERE 1 = 1";

	if (! $user->admin) {
		$sql .= " AND fk_authid=" . (int) $user->id;
	}

	// Add where from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;

	// Count total nb of records
	$nbtotalofrecords = '';
	if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
		/* The fast and low memory method to get and count full list converts the sql into a sql count */
		$sqlforcount = preg_replace('/^' . preg_quote($sqlfields, '/') . '/', 'SELECT COUNT(*) as nbtotalofrecords', $sql);
		$sqlforcount = preg_replace('/GROUP BY .*$/', '', $sqlforcount);

		$resql = $db->query($sqlforcount);
		if ($resql) {
			$objforcount = $db->fetch_object($resql);
			$nbtotalofrecords = $objforcount->nbtotalofrecords;
		} else {
			dol_print_error($db);
		}

		if (($page * $limit) > $nbtotalofrecords) {	// if total resultset is smaller than the paging size (filtering), goto and load page 0
			$page = 0;
			$offset = 0;
		}
		$db->free($resql);
	}

	// Complete request and execute it with limit
	$sql .= $db->order($sortfield, $sortorder);
	if ($limit) {
		$sql .= $db->plimit($limit + 1, $offset);
	}
	// print $sql;

	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		exit;
	}

	$num = $db->num_rows($resql);

	print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">' . "\n";

	$newcardbutton = '';

	print "<p>" . $langs->trans("SmartAuthHereIsYourLastKeys", dol_buildpath("/smartauth/auth_list.php", 1)) . "</p>";

	// print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_'.$object->picto, 0, $newcardbutton, '', $limit, 0, 0, 1);

	// Add code for pre mass action (confirmation or email presend form)
	$topicmail = "SendAuthRef";
	$modelmail = "auth";
	$objecttmp = new SmartAuth($db);
	$trackid = 'xxxx' . $object->id;
	include DOL_DOCUMENT_ROOT . '/core/tpl/massactions_pre.tpl.php';

	$moreforfilter = '';

	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$moreforfilter .= $hookmanager->resPrint;
	} else {
		$moreforfilter = $hookmanager->resPrint;
	}

	if (!empty($moreforfilter)) {
		print '<div class="liste_titre liste_titre_bydiv centpercent">';
		print $moreforfilter;
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;
		print '</div>';
	}

	$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
	$arrayofmassactions = array();
	$selectedfields = ($mode != 'kanban' ? $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN', '')) : ''); // This also change content of $arrayfields
	$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

	print '<div class="div-table-responsive">'; // You can use div-table-responsive-no-min if you dont need reserved height for your table
	print '<table class="tagtable nobottomiftotal liste' . ($moreforfilter ? " listwithfilterbefore" : "") . '">' . "\n";


	// Fields from hook
	$parameters = array('arrayfields' => $arrayfields);
	$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	/*if (!empty($arrayfields['anotherfield']['checked'])) {
	print '<td class="liste_titre"></td>';
}*/
	// print '</tr>'."\n";

	$totalarray = array();
	$totalarray['nbfield'] = 0;

	// Fields title label
	// --------------------------------------------------------------------
	print '<tr class="liste_titre">';
	// Action column
	// if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	// print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	// $totalarray['nbfield']++;
	// }
	foreach ($object->fields as $key => $val) {
		$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
		if ($key == 'status') {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
		} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
		} elseif (in_array($val['type'], array('timestamp'))) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'nowrap';
		} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'right';
		}
		$cssforfield = preg_replace('/small\s*/', '', $cssforfield);	// the 'small' css must not be used for the title label
		if (!empty($arrayfields['t.' . $key]['checked'])) {
			print getTitleFieldOfList($arrayfields['t.' . $key]['label'], 0, $_SERVER['PHP_SELF'], 't.' . $key, '', $param, ($cssforfield ? 'class="' . $cssforfield . '"' : ''), $sortfield, $sortorder, ($cssforfield ? $cssforfield . ' ' : ''), 0, (empty($val['helplist']) ? '' : $val['helplist'])) . "\n";
			$totalarray['nbfield']++;
		}
	}

	// Hook fields
	$parameters = array('arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder, 'totalarray' => &$totalarray);
	$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	/*if (!empty($arrayfields['anotherfield']['checked'])) {
	print '<th class="liste_titre right">'.$langs->trans("AnotherField").'</th>';
	$totalarray['nbfield']++;
}*/
	print getTitleFieldOfList('', 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center') . "\n";
	// Action column
	// if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	// print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	// $totalarray['nbfield']++;
	// }
	print '</tr>' . "\n";

	// Detect if we need a fetch on each output line
	$needToFetchEachLine = 0;

	// Loop on record
	// --------------------------------------------------------------------
	$i = 0;
	$savnbfield = $totalarray['nbfield'];
	$totalarray = array();
	$totalarray['nbfield'] = 0;
	$imaxinloop = ($limit ? min($num, $limit) : $num);
	while ($i < $imaxinloop) {
		$obj = $db->fetch_object($resql);
		if (empty($obj)) {
			break; // Should not happen
		}

		// Store properties in $object
		$object->setVarsFromFetchObj($obj);

		//erics
		// Parse device information
		$deviceInfo = parseDeviceInfo($obj->user_agent ?? '');
		$countryInfo = getCountryFromIP($obj->ip);

		// Get custom label if exists (stored in note_private for now)
		$deviceLabel = !empty($obj->note_private) ? $obj->note_private : null;

		// $object->appuid = $object->getModuleName($object->appuid);

		/*
	$object->thirdparty = null;
	if ($obj->fk_soc > 0) {
		if (!empty($conf->cache['thirdparty'][$obj->fk_soc])) {
			$companyobj = $conf->cache['thirdparty'][$obj->fk_soc];
		} else {
			$companyobj = new Societe($db);
			$companyobj->fetch($obj->fk_soc);
			$conf->cache['thirdparty'][$obj->fk_soc] = $companyobj;
		}

		$object->thirdparty = $companyobj;
	}*/

		// Show line of result
		$j = 0;
		print '<tr data-rowid="' . $object->id . '" class="oddeven">';
		if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td class="nowrap center">';
			print '<input type="checkbox" class="flat checkforselect" name="toselect[]" value="' . $object->id . '">';
			print '</td>';
			if (!$i) $totalarray['nbfield']++;
		}

		foreach ($object->fields as $key => $val) {
			$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);

			if (!empty($arrayfields['t.' . $key]['checked'])) {
				// Special handling for specific fields
				if ($key == 'rowid') {
					print '<td class="' . $cssforfield . '">';
					print $deviceInfo['icon'] . ' ';
					print '<strong>#' . $object->id . '</strong><br>';
					if ($deviceLabel) {
						print '<span style="color: #6366f1; font-weight: bold;">📝 ' . dol_escape_htmltag($deviceLabel) . '</span><br>';
					}
					print '<small style="color: #6b7280;">' . $deviceInfo['name'];
					if ($deviceInfo['os']) {
						print ' (' . $deviceInfo['os'] . ')';
					}
					print '</small>';
					print '</td>';
				} elseif ($key == 'ip') {
					print '<td class="' . $cssforfield . '">';
					print $countryInfo['flag'] . ' ';
					print '<code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;">' . $obj->ip . '</code><br>';
					print '<small style="color: #6b7280;">' . $countryInfo['name'] . '</small>';
					print '</td>';
				} elseif ($key == 'token_type') {
					print '<td class="center ' . $cssforfield . '">';
					print getTokenTypeBadge($obj->token_type);
					print '</td>';
				} elseif ($key == 'date_lastused') {
					print '<td class="center ' . $cssforfield . '">';
					print getRelativeTime($db->jdate($obj->date_lastused));
					print '<br><small style="color: #9ca3af;">' . dol_print_date($db->jdate($obj->date_lastused), '%d/%m %H:%M') . '</small>';
					print '</td>';
				} elseif ($key == 'date_creation') {
					print '<td class="center ' . $cssforfield . '">';
					$age_days = floor((time() - $db->jdate($obj->date_creation)) / 86400);
					print dol_print_date($db->jdate($obj->date_creation), 'day');
					print '<br><small style="color: #9ca3af;">' . $age_days . ' ' . $langs->trans("days") . '</small>';
					print '</td>';
				} elseif ($key == 'date_eol') {
					print '<td class="center ' . $cssforfield . '">';
					$days_left = floor(($db->jdate($obj->date_eol) - time()) / 86400);
					if ($days_left < 0) {
						print '<span style="color: #ef4444;">⚠️ ' . $langs->trans("Expired") . '</span>';
					} elseif ($days_left < 7) {
						print '<span style="color: #f59e0b;">⏰ ' . dol_print_date($db->jdate($obj->date_eol), 'day') . '</span>';
						print '<br><small>(' . $days_left . ' ' . $langs->trans("days") . ')</small>';
					} else {
						print dol_print_date($db->jdate($obj->date_eol), 'day');
						print '<br><small style="color: #9ca3af;">' . $days_left . ' ' . $langs->trans("days") . '</small>';
					}
					print '</td>';
				} elseif ($key == 'status') {
					print '<td class="center ' . $cssforfield . '">';
					print $object->getLibStatut(5);
					print '</td>';
				} else {
					// Default field display
					print '<td' . ($cssforfield ? ' class="' . $cssforfield . '"' : '') . '>';
					print $object->showOutputField($val, $key, $object->$key, '');
					print '</td>';
				}

				if (!$i) $totalarray['nbfield']++;
			}
		}
		// Actions column
		print '<td class="nowrap center">';

		// Rename button
		print '<a class="editfielda marginleftonly" href="#" onclick="renameDevice(' . $object->id . ', \'' . dol_escape_js($deviceLabel ? $deviceLabel : $deviceInfo['name']) . '\'); return false;" title="' . $langs->trans("RenameDevice") . '">';
		print img_edit();
		print '</a> ';

		// View history button
		print '<a class="marginleftonly" href="#" onclick="viewHistory(' . $object->id . '); return false;" title="' . $langs->trans("ViewHistory") . '">';
		print '<i class="fa fa-history"></i>';
		print '</a> ';

		// Revoke button
		print '<a class="marginleftonly" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=revoke&token_id=' . $object->id . '&token=' . newToken() . '" onclick="return confirm(\'' . $langs->trans("ConfirmRevokeToken") . '\');" title="' . $langs->trans("RevokeToken") . '">';
		print '<i class="fa fa-trash" style="color: #ef4444;"></i>';
		print '</a>';

		print '</td>';
		if (!$i) $totalarray['nbfield']++;

		// Fields from hook
		$parameters = array('arrayfields' => $arrayfields, 'object' => $object, 'obj' => $obj, 'i' => $i, 'totalarray' => &$totalarray);
		$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;

		print '</tr>' . "\n";

		$i++;
	}

	// Show total line
	include DOL_DOCUMENT_ROOT . '/core/tpl/list_print_total.tpl.php';

	// If no record found
	if ($num == 0) {
		$colspan = 1;
		foreach ($arrayfields as $key => $val) {
			if (!empty($val['checked'])) {
				$colspan++;
			}
		}
		print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
	}


	$db->free($resql);

	$parameters = array('arrayfields' => $arrayfields, 'sql' => $sql);
	$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;

	print '</table>' . "\n";
	print '</div>' . "\n";

	print '</form>' . "\n";

	// ------------------------------------------------------------------------------------------------------------ API KEYS

	// ------------------------------------------------------------------------------------------------------------ API LOGS
	$object = new SmartLogs($db);
	// Definition of array of fields for columns
	$arrayfields = array();
	foreach ($object->fields as $key => $val) {
		// If $val['visible']==0, then we never show the field
		if (!empty($val['visible'])) {
			$visible = (int) dol_eval($val['visible'], 1);
			$arrayfields['t.' . $key] = array(
				'label' => $val['label'],
				'checked' => (($visible < 0) ? 0 : 1),
				'enabled' => (abs($visible) != 3 && dol_eval($val['enabled'], 1)),
				'position' => $val['position'],
				'help' => isset($val['help']) ? $val['help'] : ''
			);
		}
	}

	$object->fields = dol_sort_array($object->fields, 'position');
	//$arrayfields['anotherfield'] = array('type'=>'integer', 'label'=>'AnotherField', 'checked'=>1, 'enabled'=>1, 'position'=>90, 'csslist'=>'right');
	$arrayfields = dol_sort_array($arrayfields, 'position');

	$form = new Form($db);

	$now = dol_now();

	$title = $langs->trans("SmartAuthAPILogs");
	//$help_url = "EN:Module_Logs|FR:Module_Logs_FR|ES:Módulo_Logs";
	$help_url = '';
	$morejs = array();
	$morecss = array();


	// Build and execute select
	// --------------------------------------------------------------------
	$sql = 'SELECT ';
	$sql .= $object->getFieldList('t');

	// Add fields from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql = preg_replace('/,\s*$/', '', $sql);

	$sqlfields = $sql; // $sql fields to remove for count total

	$sql .= " FROM " . MAIN_DB_PREFIX . $object->table_element . " as t";

	// Add table from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql .= " WHERE 1 = 1";

	if (! $user->admin) {
		$sql .= " AND fk_key IN ( SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE fk_authid = " . (int) $user->id . ")";
	}

	// Add where from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;

	// Count total nb of records
	$nbtotalofrecords = '';
	if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
		/* The fast and low memory method to get and count full list converts the sql into a sql count */
		$sqlforcount = preg_replace('/^' . preg_quote($sqlfields, '/') . '/', 'SELECT COUNT(*) as nbtotalofrecords', $sql);
		$sqlforcount = preg_replace('/GROUP BY .*$/', '', $sqlforcount);

		$resql = $db->query($sqlforcount);
		if ($resql) {
			$objforcount = $db->fetch_object($resql);
			$nbtotalofrecords = $objforcount->nbtotalofrecords;
		} else {
			dol_print_error($db);
		}

		if (($page * $limit) > $nbtotalofrecords) {	// if total resultset is smaller than the paging size (filtering), goto and load page 0
			$page = 0;
			$offset = 0;
		}
		$db->free($resql);
	}

	// Complete request and execute it with limit
	$sql .= $db->order($sortfield, $sortorder);
	if ($limit) {
		$sql .= $db->plimit($limit + 1, $offset);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		exit;
	}

	$num = $db->num_rows($resql);

	$arrayofselected = is_array($toselect) ? $toselect : array();

	$param = '';
	if (!empty($mode)) {
		$param .= '&mode=' . urlencode($mode);
	}
	if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
		$param .= '&contextpage=' . urlencode($contextpage);
	}
	if ($limit > 0 && $limit != $conf->liste_limit) {
		$param .= '&limit=' . ((int) $limit);
	}
	if ($optioncss != '') {
		$param .= '&optioncss=' . urlencode($optioncss);
	}

	// Add $param from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSearchParam', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$param .= $hookmanager->resPrint;

	print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_' . $object->picto, 0, $newcardbutton, '', $limit, 0, 0, 1);

	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$moreforfilter .= $hookmanager->resPrint;
	} else {
		$moreforfilter = $hookmanager->resPrint;
	}

	$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;

	print '<div class="div-table-responsive">'; // You can use div-table-responsive-no-min if you dont need reserved height for your table
	print '<table class="tagtable nobottomiftotal liste' . ($moreforfilter ? " listwithfilterbefore" : "") . '">' . "\n";

	$totalarray = array();
	$totalarray['nbfield'] = 0;

	// Fields title label
	// --------------------------------------------------------------------
	print '<tr class="liste_titre">';
	// Action column
	foreach ($object->fields as $key => $val) {
		$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
		if ($key == 'status') {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
		} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
		} elseif (in_array($val['type'], array('timestamp'))) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'nowrap';
		} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
			$cssforfield .= ($cssforfield ? ' ' : '') . 'right';
		}
		$cssforfield = preg_replace('/small\s*/', '', $cssforfield);	// the 'small' css must not be used for the title label
		if (!empty($arrayfields['t.' . $key]['checked'])) {
			print getTitleFieldOfList($arrayfields['t.' . $key]['label'], 0, $_SERVER['PHP_SELF'], 't.' . $key, '', $param, ($cssforfield ? 'class="' . $cssforfield . '"' : ''), $sortfield, $sortorder, ($cssforfield ? $cssforfield . ' ' : ''), 0, (empty($val['helplist']) ? '' : $val['helplist'])) . "\n";
			$totalarray['nbfield']++;
		}
	}
	print '</tr>' . "\n";

	// Loop on record
	// --------------------------------------------------------------------
	$i = 0;
	$savnbfield = $totalarray['nbfield'];
	$totalarray = array();
	$totalarray['nbfield'] = 0;
	$imaxinloop = ($limit ? min($num, $limit) : $num);
	while ($i < $imaxinloop) {
		$obj = $db->fetch_object($resql);
		if (empty($obj)) {
			break; // Should not happen
		}

		// Store properties in $object
		$object->setVarsFromFetchObj($obj);


		// Show line of result
		$j = 0;
		print '<tr data-rowid="' . $object->id . '" class="oddeven">';

		// Action column
		if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) { // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
				$selected = 0;
				if (in_array($object->id, $arrayofselected)) {
					$selected = 1;
				}
				print '<input id="cb' . $object->id . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $object->id . '"' . ($selected ? ' checked="checked"' : '') . '>';
			}
			print '</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
		}
		foreach ($object->fields as $key => $val) {
			$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
			if (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
			} elseif ($key == 'status') {
				$cssforfield .= ($cssforfield ? ' ' : '') . 'center';
			}

			if (in_array($val['type'], array('timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '') . 'nowraponall';
			} elseif ($key == 'ref') {
				$cssforfield .= ($cssforfield ? ' ' : '') . 'nowraponall';
			}

			if (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && empty($val['arrayofkeyval'])) {
				$cssforfield .= ($cssforfield ? ' ' : '') . 'right';
			}
			//if (in_array($key, array('fk_soc', 'fk_user', 'fk_warehouse'))) $cssforfield = 'tdoverflowmax100';

			if (!empty($arrayfields['t.' . $key]['checked'])) {
				print '<td' . ($cssforfield ? ' class="' . $cssforfield . (preg_match('/tdoverflow/', $cssforfield) ? ' classfortooltip' : '') . '"' : '');
				if (preg_match('/tdoverflow/', $cssforfield) && !is_numeric($object->$key)) {
					print ' title="' . dol_escape_htmltag($object->$key) . '"';
				}
				print '>';
				if ($key == 'status') {
					print $object->getLibStatut(5);
				} elseif ($key == 'rowid') {
					print $object->showOutputField($val, $key, $object->id, '');
				} else {
					print $object->showOutputField($val, $key, $object->$key, '');
				}
				print '</td>';
				if (!$i) {
					$totalarray['nbfield']++;
				}
				if (!empty($val['isameasure']) && $val['isameasure'] == 1) {
					if (!$i) {
						$totalarray['pos'][$totalarray['nbfield']] = 't.' . $key;
					}
					if (!isset($totalarray['val'])) {
						$totalarray['val'] = array();
					}
					if (!isset($totalarray['val']['t.' . $key])) {
						$totalarray['val']['t.' . $key] = 0;
					}
					$totalarray['val']['t.' . $key] += $object->$key;
				}
			}
		}
		// Extra fields
		include DOL_DOCUMENT_ROOT . '/core/tpl/extrafields_list_print_fields.tpl.php';
		// Fields from hook
		$parameters = array('arrayfields' => $arrayfields, 'object' => $object, 'obj' => $obj, 'i' => $i, 'totalarray' => &$totalarray);
		$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;

		/*if (!empty($arrayfields['anotherfield']['checked'])) {
			print '<td class="right">'.$obj->anotherfield.'</td>';
		}*/

		print '</tr>' . "\n";

		$i++;
	}

	// Show total line
	include DOL_DOCUMENT_ROOT . '/core/tpl/list_print_total.tpl.php';

	// If no record found
	if ($num == 0) {
		$colspan = 1;
		foreach ($arrayfields as $key => $val) {
			if (!empty($val['checked'])) {
				$colspan++;
			}
		}
		print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
	}


	$db->free($resql);

	$parameters = array('arrayfields' => $arrayfields, 'sql' => $sql);
	$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;

	print '</table>' . "\n";
	print '</div>' . "\n";

	print '</form>' . "\n";



	print '</div>';

	print dol_get_fiche_end();

	$modulepart = 'user';
	$param = '&id=' . $object->id;
	// include DOL_DOCUMENT_ROOT.'/core/tpl/document_actions_post_headers.tpl.php';
} else {
	accessforbidden('', 0, 1);
}

?>

<script type="text/javascript">
// Rename device function
function renameDevice(token_id, currentName) {
	var newName = prompt('<?php echo dol_escape_js($langs->trans("EnterDeviceName")); ?>', currentName);
	if (newName != null && newName != '') {
		window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $id; ?>&action=rename&token_id=' + token_id + '&device_label=' + encodeURIComponent(newName) + '&token=<?php echo newToken(); ?>';
	}
}

// View history in modal
function viewHistory(token_id) {
	// Create modal
	var modal = document.createElement('div');
	modal.id = 'historyModal';
	modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';

	var modalContent = document.createElement('div');
	modalContent.style.cssText = 'background: white; padding: 30px; border-radius: 8px; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;';

	modalContent.innerHTML = '<h3><?php echo dol_escape_js($langs->trans("TokenActivityHistory")); ?></h3><div id="historyContent"><?php echo dol_escape_js($langs->trans("Loading")); ?>...</div><br><button type="button" class="button" onclick="document.getElementById(\'historyModal\').remove();"><?php echo dol_escape_js($langs->trans("Close")); ?></button>';

	modal.appendChild(modalContent);
	document.body.appendChild(modal);

	// Load history via AJAX
	fetch('<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $id; ?>&action=viewhistory&token_id=' + token_id)
		.then(response => response.json())
		.then(data => {
			var html = '<table class="noborder centpercent">';
			html += '<tr class="liste_titre">';
			html += '<th><?php echo dol_escape_js($langs->trans("Time")); ?></th>';
			html += '<th><?php echo dol_escape_js($langs->trans("Method")); ?></th>';
			html += '<th><?php echo dol_escape_js($langs->trans("Endpoint")); ?></th>';
			html += '<th><?php echo dol_escape_js($langs->trans("Status")); ?></th>';
			html += '</tr>';

			if (data.length == 0) {
				html += '<tr><td colspan="4" class="center opacitymedium"><?php echo dol_escape_js($langs->trans("NoHistory")); ?></td></tr>';
			} else {
				data.forEach(function(item) {
					var statusColor = item.status >= 200 && item.status < 300 ? '#10b981' : '#ef4444';
					var date = new Date(item.time * 1000);
					html += '<tr class="oddeven">';
					html += '<td>' + date.toLocaleString() + '</td>';
					html += '<td><code>' + item.method + '</code></td>';
					html += '<td style="font-size: 0.9em;">' + item.url + '</td>';
					html += '<td style="color: ' + statusColor + '; font-weight: bold;">' + item.status + '</td>';
					html += '</tr>';
				});
			}

			html += '</table>';
			document.getElementById('historyContent').innerHTML = html;
		})
		.catch(error => {
			document.getElementById('historyContent').innerHTML = '<span style="color: #ef4444;"><?php echo dol_escape_js($langs->trans("ErrorLoadingHistory")); ?></span>';
		});
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
	if (e.key === 'Escape') {
		var modal = document.getElementById('historyModal');
		if (modal) modal.remove();
	}
});
</script>


<?php

// End of page
llxFooter();
$db->close();
