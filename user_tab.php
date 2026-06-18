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
dol_include_once('/smartauth/class/smartauthqrpairing.class.php');
dol_include_once('/smartauth/class/smartauthuserdevice.class.php');
dol_include_once('/smartauth/class/smartauthusertokenadmin.class.php');
dol_include_once('/smartauth/api/RouteController.php');
dol_include_once('/smartauth/api/ModulePathHelper.php');
dol_include_once('/smartauth/lib/tools.php');

// Load translation files required by page
$langs->loadLangs(array('users', 'other', 'smartauth@smartauth'));

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

// Handle revoke action. Token ops are delegated to SmartAuthUserTokenAdmin so
// they stay testable in isolation; the page only maps the result to a message.
if ($action == 'revoke' && $permtoedit) {
	$token_id = GETPOST('token_id', 'int');

	if ($token_id > 0) {
		$tokenAdmin = new SmartAuthUserTokenAdmin($db);
		$res = $tokenAdmin->revoke((int) $token_id, (int) $id);
		if ($res === SmartAuthUserTokenAdmin::RES_OK) {
			setEventMessages($langs->trans("TokenRevoked"), null, 'mesgs');
			header("Location: " . $_SERVER['PHP_SELF'] . '?id=' . $id);
			exit;
		} elseif ($res === SmartAuthUserTokenAdmin::RES_DB_ERROR) {
			setEventMessages($langs->trans("ErrorRevokingToken"), null, 'errors');
		} else {
			setEventMessages($langs->trans("TokenNotFound"), null, 'errors');
		}
	}
}

// Handle delete action -- a REAL row delete, distinct from "revoke" which only
// flips status to STATUS_REVOKED (disable). Same ownership check as revoke.
if ($action == 'delete_token' && $permtoedit) {
	$token_id = GETPOST('token_id', 'int');

	if ($token_id > 0) {
		$tokenAdmin = new SmartAuthUserTokenAdmin($db);
		$res = $tokenAdmin->delete((int) $token_id, (int) $id);
		if ($res === SmartAuthUserTokenAdmin::RES_OK) {
			setEventMessages($langs->trans("TokenDeleted"), null, 'mesgs');
			header("Location: " . $_SERVER['PHP_SELF'] . '?id=' . $id);
			exit;
		} elseif ($res === SmartAuthUserTokenAdmin::RES_DB_ERROR) {
			setEventMessages($langs->trans("ErrorDeletingToken"), null, 'errors');
		} else {
			setEventMessages($langs->trans("TokenNotFound"), null, 'errors');
		}
	}
}

// Handle token mass actions: revoke (status=STATUS_REVOKED) or really DELETE
// every selected token row. Ownership is enforced per row inside the helper.
if (in_array($action, array('masstokenrevoke', 'masstokendelete'), true) && $permtoedit) {
	$tokenAdmin = new SmartAuthUserTokenAdmin($db);
	$tokIds = GETPOST('toselect', 'array');
	if ($action === 'masstokendelete') {
		$tokDone = $tokenAdmin->massDelete((array) $tokIds, (int) $id);
	} else {
		$tokDone = $tokenAdmin->massRevoke((array) $tokIds, (int) $id);
	}
	setEventMessages($langs->trans($action === 'masstokendelete' ? 'TokensMassDeleted' : 'TokensMassRevoked', $tokDone), null, 'mesgs');
	header("Location: " . $_SERVER['PHP_SELF'] . '?id=' . ((int) $id));
	exit;
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

// =====================================================================
// QR-pair AJAX handlers (cross-device pairing).
// These return JSON and exit early; no page chrome is involved. Only the
// user's own card may drive these (admin viewing someone else's card
// cannot create or confirm pairings on their behalf).
// =====================================================================
$qrpairAction = (string) $action;
if (in_array($qrpairAction, array('qrpairstatus', 'qrpairconfirm', 'qrpaircancel'), true)) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');

	if ((int) $user->id !== (int) $id) {
		http_response_code(403);
		echo json_encode(array('error' => 'forbidden_not_self'));
		exit;
	}

	$qrPairingId = strtolower(trim((string) GETPOST('pairing_id', 'alpha')));
	if ($qrPairingId === '' || !preg_match('/^[0-9a-f]{32}$/', $qrPairingId)) {
		http_response_code(400);
		echo json_encode(array('error' => 'invalid_pairing_id'));
		exit;
	}

	$qrEntity = (int) ($conf->entity ?? 1);
	$qrRepo = new SmartAuthQrPairing($db);
	$qrRow = $qrRepo->findByPairingId($qrPairingId, $qrEntity);
	if ($qrRow === null) {
		http_response_code(404);
		echo json_encode(array('error' => 'pairing_not_found'));
		exit;
	}
	if ((int) $qrRow['fk_user'] !== (int) $user->id) {
		// The pairing exists but belongs to someone else: never leak this
		// across users. Refuse with a generic 403.
		dol_syslog('[SmartAuth] user_tab.php: cross-user qrpair access by user_id=' . $user->id . ' on pairing fk_user=' . $qrRow['fk_user'], LOG_WARNING);
		http_response_code(403);
		echo json_encode(array('error' => 'forbidden'));
		exit;
	}

	if ($qrpairAction === 'qrpairconfirm') {
		// CSRF: Dolibarr's newToken() returns the canonical session token.
		if (newToken() !== (string) GETPOST('token', 'alpha')) {
			http_response_code(403);
			echo json_encode(array('error' => 'csrf'));
			exit;
		}
		$qrOk = $qrRepo->markConfirmed((int) $qrRow['rowid'], (int) $user->id);
		echo json_encode(array(
			'ok' => $qrOk,
			'status' => $qrOk ? SmartAuthQrPairing::STATUS_CONFIRMED : $qrRow['status'],
		));
		exit;
	}

	if ($qrpairAction === 'qrpaircancel') {
		if (newToken() !== (string) GETPOST('token', 'alpha')) {
			http_response_code(403);
			echo json_encode(array('error' => 'csrf'));
			exit;
		}
		$qrRepo->markCancelled((int) $qrRow['rowid'], (int) $user->id);
		echo json_encode(array('ok' => true, 'status' => SmartAuthQrPairing::STATUS_CANCELLED));
		exit;
	}

	// qrpairstatus (GET)
	$qrEffectiveStatus = (string) $qrRow['status'];
	if (
		in_array($qrEffectiveStatus, array(SmartAuthQrPairing::STATUS_PENDING, SmartAuthQrPairing::STATUS_CLAIMED), true)
		&& SmartAuthQrPairing::isExpired($qrRow)
	) {
		$qrEffectiveStatus = SmartAuthQrPairing::STATUS_EXPIRED;
	}
	echo json_encode(array(
		'status' => $qrEffectiveStatus,
		'device_label' => $qrRow['device_label'] !== null ? (string) $qrRow['device_label'] : '',
		'claim_ip' => $qrRow['claim_ip'] !== null ? (string) $qrRow['claim_ip'] : '',
		'claim_user_agent' => $qrRow['claim_user_agent'] !== null ? (string) $qrRow['claim_user_agent'] : '',
	));
	exit;
}

// =====================================================================
// QR-pair generate handler (Post-Redirect-Get).
// Triggered by the explicit "Générer un QR code" button on the card.
// On purpose we do NOT generate a fresh pairing on every page load --
// that would mean a real, scannable QR is exposed as soon as somebody
// merely opens this page, with the obvious "shoulder surfing" risk.
// Instead the page loads as a blurred decoy and the user has to click
// a button to spawn the real pairing.
// =====================================================================
if ($qrpairAction === 'qrpairgenerate' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	if ((int) $user->id !== (int) $id) {
		accessforbidden();
	}
	if (newToken() !== (string) GETPOST('token', 'alpha')) {
		accessforbidden();
	}

	$qrEntity = (int) ($conf->entity ?? 1);
	$qrRepo = new SmartAuthQrPairing($db);

	// Cancel any pending/claimed pairing the user already has on file
	// (multi-tab / earlier "Générer" click) so only one QR can be valid
	// at any given time.
	$cleanupSql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_qr_pairings"
		. " SET status = '" . $db->escape(SmartAuthQrPairing::STATUS_CANCELLED) . "'"
		. " WHERE fk_user = " . ((int) $user->id)
		. " AND entity = " . $qrEntity
		. " AND status IN ('" . SmartAuthQrPairing::STATUS_PENDING . "','" . SmartAuthQrPairing::STATUS_CLAIMED . "')";
	$db->query($cleanupSql);

	$qrNewPairingId = SmartAuthQrPairing::generatePairingId();
	$qrInitiatorIp = \SmartAuth\Api\RouteController::get_client_ip();
	$qrRepo->createPending($qrNewPairingId, (int) $user->id, $qrInitiatorIp, $qrEntity);

	header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $id), true, 303);
	exit;
}

// =====================================================================
// Logical user-device handlers (rename / revoke). Only the owner of the
// devices being touched is allowed to act; cross-user requests get a
// hard accessforbidden(). Both actions follow the POST -> 303 PRG
// pattern so a refresh after submit does not re-execute the action.
// =====================================================================
$userDeviceAction = (string) $action;
if (in_array($userDeviceAction, array('userdevicerevoke', 'userdevicerename', 'userdevicedelete', 'userdevicemassrevoke', 'userdevicemassdelete'), true)
	&& ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {
	if ((int) $user->id !== (int) $id) {
		accessforbidden();
	}
	if (newToken() !== (string) GETPOST('token', 'alpha')) {
		accessforbidden();
	}

	$udEntity = (int) ($conf->entity ?? 1);
	$udRepo = new SmartAuthUserDevice($db);
	$udId = (int) GETPOST('user_device_id', 'int');

	// todo l.25 -- mass actions on "Mes appareils": revoke or really DELETE
	// every selected logical device in one POST. Ownership is enforced inside
	// revoke()/delete() (fk_user check), so a forged id silently no-ops.
	if ($userDeviceAction === 'userdevicemassrevoke' || $userDeviceAction === 'userdevicemassdelete') {
		$udIds = GETPOST('user_device_ids', 'array');
		$udDone = 0;
		foreach ((array) $udIds as $udOne) {
			$udOne = (int) $udOne;
			if ($udOne <= 0) {
				continue;
			}
			if ($userDeviceAction === 'userdevicemassdelete') {
				if ($udRepo->delete($udOne, (int) $user->id, $udEntity)) {
					$udDone++;
				}
			} else {
				$udRepo->revoke($udOne, (int) $user->id, $udEntity);
				$udDone++;
			}
		}
		setEventMessages($langs->trans($userDeviceAction === 'userdevicemassdelete' ? 'SmartAuthUserDeviceMassDeleted' : 'SmartAuthUserDeviceMassRevoked', $udDone), null, 'mesgs');
	} elseif ($udId > 0) {
		if ($userDeviceAction === 'userdevicerevoke') {
			$udRepo->revoke($udId, (int) $user->id, $udEntity);
		} elseif ($userDeviceAction === 'userdevicedelete') {
			// Real removal of the logical device (todo l.25), not a revoke.
			$udRepo->delete($udId, (int) $user->id, $udEntity);
		} elseif ($userDeviceAction === 'userdevicerename') {
			$udNewLabel = SmartAuthUserDevice::normaliseLabel((string) GETPOST('new_label', 'alphanohtml'));
			if ($udNewLabel !== '') {
				$udRepo->rename($udId, (int) $user->id, $udNewLabel, $udEntity);
				// Keep llx_smartauth_devices.label in sync so legacy
				// queries see the new name immediately.
				$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_devices";
				$sql .= " SET label = '" . $db->escape($udNewLabel) . "'";
				$sql .= " WHERE fk_user_device = " . $udId;
				$sql .= " AND fk_user_creat = " . ((int) $user->id);
				$sql .= " AND entity = " . $udEntity;
				$db->query($sql);
			}
		}
	}

	header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $id), true, 303);
	exit;
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

	// =====================================================================
	// QR-pair card (cross-device pairing). Rendered as the right column of
	// a flex container that wraps the existing device-list block. The
	// previous attempt at float:right did not work because the device list
	// uses Dolibarr's `div-table-responsive` which sets overflow-x:auto
	// and therefore creates a new block-formatting context that ignores
	// outside floats. Flexbox sidesteps the problem entirely.
	// $object has been reassigned later in the page so we compare against
	// $id (URL parameter, validated by restrictedArea above) to detect
	// "own card". AJAX handlers (qrpairstatus / qrpairconfirm /
	// qrpaircancel) sit near the top of this file. The mobile side flow
	// lives in api/QrPairController.php (claim + poll).
	// =====================================================================
	$qrCardHtml = '';
	if ((int) $user->id === (int) $id) {
		$qrEntity = (int) ($conf->entity ?? 1);
		$qrRepoUI = new SmartAuthQrPairing($db);

		// Two render modes:
		//   - REAL  : an active pending/claimed pairing exists (just
		//             generated via the qrpairgenerate POST handler, or
		//             still alive after a page refresh during a scan).
		//             The QR is rendered clear, polling JS is enabled.
		//   - DECOY : no active pairing yet. We render a *random* QR
		//             (32 hex bytes that match nothing in DB) blurred
		//             behind a "Générer un QR code" button, so a stray
		//             screenshot of the page does not yield a scannable
		//             QR.
		// The page never spawns a real pairing on plain GET -- only the
		// explicit "Générer" form POST creates one (PRG handler above).
		$qrExisting = $qrRepoUI->findActiveForUser((int) $user->id, $qrEntity);
		$qrIsDecoy = ($qrExisting === null);
		$qrPairingId = $qrIsDecoy
			? SmartAuthQrPairing::generatePairingId() // unrelated to any DB row
			: (string) $qrExisting['pairing_id'];

		// Build the public mobile URL embedded in the QR code. Prefer the
		// configured PWA base URL, fall back to the current host's
		// /custom/smartauth/public path.
		$qrBaseUrl = '';
		if (!empty($conf->global->SMARTAUTH_PWA_URL)) {
			$qrBaseUrl = rtrim((string) $conf->global->SMARTAUTH_PWA_URL, '/');
		} else {
			$qrScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
			$qrHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
			$qrBaseUrl = $qrScheme . '://' . $qrHost . \SmartAuth\Api\ModulePathHelper::moduleUrlPrefix('smartauth') . '/public';
		}
		$qrPayload = $qrBaseUrl . '/qr-pair/' . $qrPairingId;

		// Inline overlay/QR styles toggled depending on the mode.
		$qrInitialFilter = $qrIsDecoy ? 'filter:blur(4px) opacity(0.45);' : '';
		$qrOverlayDisplay = $qrIsDecoy ? 'flex' : 'none';
		$qrStatusInitialDisplay = $qrIsDecoy ? 'none' : 'block';

		// Capture the card markup into a buffer; it is emitted later as
		// the right-hand flex item once the left column is closed.
		// The body is wrapped in #smartauth-qrpair-body so JS can collapse
		// it (accordion) on narrow screens where the card sits above the
		// device list and would otherwise hide it from view.
		ob_start();
		echo '<div id="smartauth-qrpair-card" class="info-box" data-decoy="' . ($qrIsDecoy ? '1' : '0') . '" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">';
		echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
		echo '  <h3 style="margin:0;font-size:16px;">' . dol_escape_htmltag($langs->trans('QrPairCardTitle')) . '</h3>';
		echo '  <button type="button" id="smartauth-qrpair-toggle" aria-expanded="true" aria-controls="smartauth-qrpair-body" title="' . dol_escape_htmltag($langs->trans('QrPairToggle')) . '" style="display:none;background:none;border:none;cursor:pointer;font-size:18px;padding:4px 8px;color:#6b7280;line-height:1;">';
		echo '    <span id="smartauth-qrpair-toggle-icon" aria-hidden="true">&#9650;</span>';
		echo '  </button>';
		echo '</div>';
		echo '<div id="smartauth-qrpair-body">';
		echo '<p style="margin:0 0 14px 0;color:#4b5563;font-size:13px;line-height:1.4;">' . dol_escape_htmltag($langs->trans('QrPairCardLegend')) . '</p>';

		// Hidden form used by both the placeholder "Générer" button and
		// the JS regenerate path (terminal state). One CSRF-protected
		// POST endpoint, two ways to trigger it.
		echo '<form id="smartauth-qrpair-genform" method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . ((int) $user->id) . '" style="display:none;">';
		echo '  <input type="hidden" name="action" value="qrpairgenerate">';
		echo '  <input type="hidden" name="id" value="' . ((int) $user->id) . '">';
		echo '  <input type="hidden" name="token" value="' . dol_escape_htmltag(newToken()) . '">';
		echo '</form>';

		require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
		$qrBarcode = new TCPDF2DBarcode($qrPayload, 'QRCODE,M');
		$qrSvg = $qrBarcode->getBarcodeSVGcode(5, 5, 'black');
		// The wrapper is position:relative so the JS can drop a refresh
		// overlay on top of the QR when it reaches a terminal state, and
		// it is also pre-applied for the decoy initial render.
		echo '<div id="smartauth-qrpair-wrap" style="position:relative;margin:0 auto 10px;max-width:260px;">';
		echo '  <div id="smartauth-qrpair-qr" style="text-align:center;transition:filter 0.2s;' . $qrInitialFilter . '">' . $qrSvg . '</div>';
		echo '  <div id="smartauth-qrpair-overlay" style="display:' . $qrOverlayDisplay . ';position:absolute;inset:0;align-items:center;justify-content:center;">';
		echo '    <button type="button" id="smartauth-qrpair-refresh" class="butAction" style="font-size:13px;">' . dol_escape_htmltag($langs->trans('QrPairRegenerate')) . '</button>';
		echo '  </div>';
		echo '</div>';

		echo '<div id="smartauth-qrpair-status" data-pairing-id="' . dol_escape_htmltag($qrIsDecoy ? '' : $qrPairingId) . '" style="display:' . $qrStatusInitialDisplay . ';">';
		echo '  <p id="smartauth-qrpair-msg" style="margin:0;color:#6b7280;font-size:13px;text-align:center;">' . dol_escape_htmltag($langs->trans('QrPairWaitingScan')) . '</p>';
		echo '  <div id="smartauth-qrpair-claim-info" style="display:none;background:#f9fafb;padding:10px;border-radius:6px;margin:10px 0;font-size:12px;">';
		echo '    <p style="margin:4px 0;"><strong>' . dol_escape_htmltag($langs->trans('QrPairDeviceLabel')) . ':</strong> <span id="smartauth-qrpair-device"></span></p>';
		echo '    <p style="margin:4px 0;"><strong>' . dol_escape_htmltag($langs->trans('QrPairClaimIp')) . ':</strong> <span id="smartauth-qrpair-ip"></span></p>';
		echo '    <p style="margin:4px 0;"><strong>' . dol_escape_htmltag($langs->trans('QrPairUserAgent')) . ':</strong> <span id="smartauth-qrpair-ua" style="word-break:break-all;"></span></p>';
		echo '  </div>';
		echo '  <div id="smartauth-qrpair-actions" style="display:none;margin-top:10px;text-align:center;">';
		echo '    <button type="button" id="smartauth-qrpair-confirm" class="butAction">' . dol_escape_htmltag($langs->trans('QrPairConfirm')) . '</button>';
		echo '    <button type="button" id="smartauth-qrpair-cancel" class="butActionDelete">' . dol_escape_htmltag($langs->trans('QrPairCancel')) . '</button>';
		echo '  </div>';
		echo '</div>'; // close #smartauth-qrpair-status
		echo '</div>'; // close #smartauth-qrpair-body
		echo '</div>'; // close #smartauth-qrpair-card
		// Inline CSS: only show the toggle button when the right column has
		// wrapped under the table (single-column stack). 920px is a
		// reasonable breakpoint -- below that, our 340px QR + 16px gap +
		// table padding leaves the table too narrow to be useful.
		echo '<style>'
			. '@media (max-width: 920px) { #smartauth-qrpair-toggle { display: inline-block !important; } }'
			. '#smartauth-qrpair-card.collapsed #smartauth-qrpair-body { display: none; }'
			. '</style>';
		$qrCardHtml = (string) ob_get_clean();
	}

	// Open the two-column flex layout. Left column gets `min-width:0` so
	// the inner div-table-responsive can shrink horizontally; without
	// it, flex defaults to min-width:auto and keeps the table at its
	// content's natural width, ignoring the right column.
	if ($qrCardHtml !== '') {
		print '<div style="display:flex;gap:16px;align-items:flex-start;">';
		print '<div style="flex:1;min-width:0;">';
	}

	print '<div class="fichecenter">';

	// ------------------------------------------------------------------------------------------------------------ LOGICAL DEVICES
	// "Mes appareils" : one logical row per physical device the user
	// declared. Several PWAs on the same phone collapse into a single
	// line here; clicking "Révoquer cet appareil" cascades through every
	// session attached to the parent in one shot.
	$udEntity = (int) ($conf->entity ?? 1);
	$udRepo = new SmartAuthUserDevice($db);
	$udRows = $udRepo->listForUser((int) $id, $udEntity);
	$udIsOwner = ((int) $user->id === (int) $id);

	print '<div class="div-table-responsive-no-min" style="margin-bottom:18px;">';
	print '<table class="noborder centpercent">';
	print '<thead><tr class="liste_titre">';
	// todo l.25 -- selection column for the mass actions (owner only). The
	// checkboxes live OUTSIDE the per-row rename/revoke forms (HTML5 form=
	// attribute) so nothing nests, and target the sibling mass-action form.
	if ($udIsOwner) {
		print '<th class="center" style="width:24px;"><input type="checkbox" id="ud-select-all" title="' . dol_escape_htmltag($langs->trans('SelectAll')) . '"></th>';
	}
	print '<th>' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceLabel')) . '</th>';
	print '<th class="center">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceSessions')) . '</th>';
	print '<th class="center">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceLastSeen')) . '</th>';
	if ($udIsOwner) {
		print '<th class="right">' . dol_escape_htmltag($langs->trans('Actions')) . '</th>';
	}
	print '</tr></thead><tbody>';

	if (empty($udRows)) {
		$colspan = $udIsOwner ? 5 : 3;
		print '<tr><td colspan="' . $colspan . '" class="opacitymedium">';
		print dol_escape_htmltag($langs->trans('SmartAuthUserDeviceEmpty'));
		print '</td></tr>';
	} else {
		$udIconMap = array(
			SmartAuthUserDevice::ICON_PHONE => 'fa-mobile-alt',
			SmartAuthUserDevice::ICON_TABLET => 'fa-tablet-alt',
			SmartAuthUserDevice::ICON_LAPTOP => 'fa-laptop',
			SmartAuthUserDevice::ICON_DESKTOP => 'fa-desktop',
		);
		foreach ($udRows as $udRow) {
			$udIconClass = $udIconMap[$udRow['icon']] ?? 'fa-mobile-alt';
			$udRowId = (int) $udRow['rowid'];
			print '<tr class="oddeven">';
			if ($udIsOwner) {
				print '<td class="center"><input type="checkbox" class="ud-check" name="user_device_ids[]" value="' . $udRowId . '" form="ud-massform"></td>';
			}
			print '<td>';
			print '<i class="fas ' . $udIconClass . '" aria-hidden="true" style="margin-right:6px;"></i>';
			print dol_escape_htmltag((string) $udRow['label']);
			print '</td>';
			print '<td class="center">' . (int) ($udRow['session_count'] ?? 0) . '</td>';
			print '<td class="center">';
			print !empty($udRow['date_lastseen']) ? dol_print_date($db->jdate($udRow['date_lastseen']), 'dayhour') : '-';
			print '</td>';
			if ($udIsOwner) {
				print '<td class="right">';
				// Rename form (inline, label-only).
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . ((int) $id) . '" style="display:inline-block;margin-right:8px;">';
				print '<input type="hidden" name="action" value="userdevicerename">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="user_device_id" value="' . $udRowId . '">';
				print '<input type="text" name="new_label" maxlength="100" value="' . dol_escape_htmltag((string) $udRow['label']) . '" style="width:140px;">';
				print '<button type="submit" class="butAction" style="padding:2px 8px;font-size:12px;">' . dol_escape_htmltag($langs->trans('Rename')) . '</button>';
				print '</form>';
				// Revoke form (disable, keeps the row) with JS confirm.
				$udConfirmMsg = $langs->transnoentities('SmartAuthUserDeviceRevokeConfirm', $udRow['label']);
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . ((int) $id) . '" style="display:inline-block;margin-right:6px;" onsubmit="return confirm(' . htmlspecialchars(json_encode($udConfirmMsg), ENT_QUOTES, 'UTF-8') . ');">';
				print '<input type="hidden" name="action" value="userdevicerevoke">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="user_device_id" value="' . $udRowId . '">';
				print '<button type="submit" class="butAction" style="padding:2px 8px;font-size:12px;">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceRevokeAction')) . '</button>';
				print '</form>';
				// Delete form (todo l.25): really removes the logical device.
				$udDelConfirmMsg = $langs->transnoentities('SmartAuthUserDeviceDeleteConfirm', $udRow['label']);
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . ((int) $id) . '" style="display:inline-block;" onsubmit="return confirm(' . htmlspecialchars(json_encode($udDelConfirmMsg), ENT_QUOTES, 'UTF-8') . ');">';
				print '<input type="hidden" name="action" value="userdevicedelete">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="user_device_id" value="' . $udRowId . '">';
				print '<button type="submit" class="butActionDelete" style="padding:2px 8px;font-size:12px;">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceDeleteAction')) . '</button>';
				print '</form>';
				print '</td>';
			}
			print '</tr>';
		}
	}
	print '</tbody></table>';
	print '</div>';

	// todo l.25 -- mass-action bar for "Mes appareils" (owner, non-empty list).
	// Sibling form referenced by the row checkboxes via their form= attribute,
	// so it never nests inside the per-row rename/revoke/delete forms.
	if ($udIsOwner && !empty($udRows)) {
		$udMassDelConfirm = $langs->transnoentities('SmartAuthUserDeviceMassDeleteConfirm');
		$udMassRevConfirm = $langs->transnoentities('SmartAuthUserDeviceMassRevokeConfirm');
		print '<form id="ud-massform" method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . ((int) $id) . '" style="margin:-8px 0 18px;">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<button type="submit" name="action" value="userdevicemassrevoke" class="butAction" onclick="return confirm(' . htmlspecialchars(json_encode($udMassRevConfirm), ENT_QUOTES, 'UTF-8') . ');">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceMassRevoke')) . '</button> ';
		print '<button type="submit" name="action" value="userdevicemassdelete" class="butActionDelete" onclick="return confirm(' . htmlspecialchars(json_encode($udMassDelConfirm), ENT_QUOTES, 'UTF-8') . ');">' . dol_escape_htmltag($langs->trans('SmartAuthUserDeviceMassDelete')) . '</button>';
		print '</form>';
		print '<script>(function(){var a=document.getElementById("ud-select-all");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".ud-check").forEach(function(c){c.checked=a.checked;});});})();</script>';
	}

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
	// llx_smartauth_auth has no user_agent column; the previous code used
	// $obj->user_agent (always empty) and parseDeviceInfo('') returned the
	// "(PROV)" placeholder, masking the real device. Pull label/uuid from
	// the linked smartauth_devices row to display a meaningful name.
	$sql .= ', d.label AS device_label_db, d.uuid AS device_uuid_db';
	// Add fields from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql = preg_replace('/,\s*$/', '', $sql);

	$sqlfields = $sql; // $sql fields to remove for count total

	$sql .= " FROM " . MAIN_DB_PREFIX . $object->table_element . " as t";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "smartauth_devices AS d ON d.rowid = t.fk_device_id";

	// Add table from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql .= " WHERE 1 = 1";

	if (! $user->admin) {
		$sql .= " AND t.fk_authid=" . (int) $user->id;
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

	// Initialise list-related variables read by partial templates below
	// (getTitleFieldOfList, printFieldListTitle hook, etc.). Without this,
	// PHP 8.x emits a stream of "Undefined variable $param" warnings on
	// every page load.
	$param = '';
	$massactionbutton = '';

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
	// Action column header carries the "select all" checkbox for the token
	// mass actions (todo l.25).
	print '<th class="center liste_titre"><input type="checkbox" id="tok-select-all" title="' . dol_escape_htmltag($langs->trans('SelectAll')) . '"></th>' . "\n";
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
		// Parse device information. The auth row itself has no user_agent
		// column; we display the linked smartauth_devices.label / uuid
		// when present, with parseDeviceInfo as a last-resort fallback.
		$deviceInfo = parseDeviceInfo($obj->device_label_db ?? '');
		$countryInfo = getCountryFromIP($obj->ip);

		// Custom user-supplied label takes precedence over the raw
		// device_label_db (which is the auto-generated one). Today the
		// rename action stores nothing persistent (note_private column
		// does not exist on this table), so this stays null until the
		// rename feature is wired to a real column.
		$deviceLabel = !empty($obj->device_label_db) ? (string) $obj->device_label_db : null;

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
				} elseif ($key == 'fk_device_id') {
					// The default rendering resolves the FK and shows the
					// auto-generated ref ("SMAUTHD-0003"), which is opaque
					// to the user. Display the linked device.label
					// instead (LEFT JOIN result), truncated to 20 chars
					// and wrapped in a link to the device card. Tooltip
					// keeps the full label so nothing is lost on truncate.
					print '<td' . ($cssforfield ? ' class="' . $cssforfield . '"' : '') . '>';
					$deviceFullLabel = (string) ($obj->device_label_db ?? '');
					$deviceLinkId = (int) ($obj->fk_device_id ?? 0);
					if ($deviceFullLabel !== '' && $deviceLinkId > 0) {
						$shown = dol_trunc($deviceFullLabel, 20, 'right', 'UTF-8', 1);
						$deviceUrl = dol_buildpath('/smartauth/smartauthdevices_card.php', 1) . '?id=' . $deviceLinkId;
						print '<a href="' . dol_escape_htmltag($deviceUrl) . '" title="' . dol_escape_htmltag($deviceFullLabel) . '">' . dol_escape_htmltag($shown) . '</a>';
					} else {
						print '<span style="color:#9ca3af;font-style:italic;">' . dol_escape_htmltag($langs->trans('Anonymous')) . '</span>';
					}
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

		// Revoke button -- disables the token (status=9) but keeps the row.
		print '<a class="marginleftonly" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=revoke&token_id=' . $object->id . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("ConfirmRevokeToken")) . '\');" title="' . dol_escape_htmltag($langs->trans("RevokeToken")) . '">';
		print '<i class="fa fa-ban" style="color: #f59e0b;"></i>';
		print '</a>';

		// Delete button (todo l.25) -- really removes the token row.
		print '<a class="marginleftonly" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete_token&token_id=' . $object->id . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("ConfirmDeleteToken")) . '\');" title="' . dol_escape_htmltag($langs->trans("DeleteToken")) . '">';
		print '<i class="fa fa-trash" style="color: #ef4444;"></i>';
		print '</a>';

		// Selection checkbox for the token mass actions (todo l.25).
		print ' <input type="checkbox" class="tok-check" name="toselect[]" value="' . $object->id . '" style="margin-left:8px;vertical-align:middle;">';

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

	// todo l.25 -- token mass actions bar (inside the searchFormList form, so the
	// row checkboxes name="toselect[]" are submitted together).
	if ($num > 0 && $permtoedit) {
		$tokMassRevConfirm = $langs->transnoentities('ConfirmRevokeSelectedTokens');
		$tokMassDelConfirm = $langs->transnoentities('ConfirmDeleteSelectedTokens');
		print '<div style="margin:8px 0;">';
		print '<button type="submit" name="action" value="masstokenrevoke" class="butAction" onclick="return confirm(' . htmlspecialchars(json_encode($tokMassRevConfirm), ENT_QUOTES, 'UTF-8') . ');">' . dol_escape_htmltag($langs->trans('RevokeSelectedTokens')) . '</button> ';
		print '<button type="submit" name="action" value="masstokendelete" class="butActionDelete" onclick="return confirm(' . htmlspecialchars(json_encode($tokMassDelConfirm), ENT_QUOTES, 'UTF-8') . ');">' . dol_escape_htmltag($langs->trans('DeleteSelectedTokens')) . '</button>';
		print '</div>';
		print '<script>(function(){var a=document.getElementById("tok-select-all");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".tok-check").forEach(function(c){c.checked=a.checked;});});})();</script>';
	}

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
	// Pull the linked device label so the rendering loop below can show
	// it (truncated and linked) instead of the opaque "SMAUTHD-XXXX" ref.
	$sql .= ', d.label AS device_label_db';

	// Add fields from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql = preg_replace('/,\s*$/', '', $sql);

	$sqlfields = $sql; // $sql fields to remove for count total

	$sql .= " FROM " . MAIN_DB_PREFIX . $object->table_element . " as t";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "smartauth_devices AS d ON d.rowid = t.fk_device_id";

	// Add table from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;
	$sql .= " WHERE 1 = 1";

	if (! $user->admin) {
		$sql .= " AND t.fk_key IN ( SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_auth WHERE fk_authid = " . (int) $user->id . ")";
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

	// Pointer to the dedicated logs page (full list with filters and
	// search). Mirrors the SmartAuthHereIsYourLastKeys hint above the
	// auth list.
	print "<p>" . $langs->trans("SmartAuthHereIsYourLastLogs", dol_buildpath("/smartauth/logs_list.php", 1)) . "</p>";

	// $hideselectlimit=1 + $hidenavigation=1: this view shows the latest
	// hits only (capped by $limit). The pagination dropdown and arrows
	// did not work reliably here because $page/$limit are shared with
	// the first table on this page; redirecting users to logs_list.php
	// is both simpler and gives them proper filters.
	print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_' . $object->picto, 0, $newcardbutton, '', $limit, 1, 1, 1);

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
				} elseif ($key == 'fk_key' && (int) $object->fk_key <= 0) {
					// Logs emitted from public routes (login, register,
					// manifest, qr-pair claim/poll...) carry no SmartAuth
					// token id - show "anonyme" instead of an empty cell.
					print '<span style="color:#9ca3af;font-style:italic;">' . dol_escape_htmltag($langs->trans('Anonymous')) . '</span>';
				} elseif ($key == 'dol_element' && empty($object->dol_element)) {
					// Same story: insertLogs() callers rarely pass an
					// element name. Empty here just means "not declared
					// at log time" -- mark it instead of leaving blank.
					print '<span style="color:#9ca3af;font-style:italic;">' . dol_escape_htmltag($langs->trans('Anonymous')) . '</span>';
				} elseif ($key == 'fk_device_id') {
					// Same treatment as the auth list above: link to the
					// device card with the truncated label, "anonyme" if
					// no label was ever recorded for the device.
					$deviceFullLabel = (string) ($obj->device_label_db ?? '');
					$deviceLinkId = (int) ($obj->fk_device_id ?? 0);
					if ($deviceFullLabel !== '' && $deviceLinkId > 0) {
						$shown = dol_trunc($deviceFullLabel, 20, 'right', 'UTF-8', 1);
						$deviceUrl = dol_buildpath('/smartauth/smartauthdevices_card.php', 1) . '?id=' . $deviceLinkId;
						print '<a href="' . dol_escape_htmltag($deviceUrl) . '" title="' . dol_escape_htmltag($deviceFullLabel) . '">' . dol_escape_htmltag($shown) . '</a>';
					} else {
						print '<span style="color:#9ca3af;font-style:italic;">' . dol_escape_htmltag($langs->trans('Anonymous')) . '</span>';
					}
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

	// Close the left flex column and emit the QR card as the right one.
	if ($qrCardHtml !== '') {
		print '</div>'; // close flex:1 left column
		print '<div style="width:340px;flex-shrink:0;">';
		print $qrCardHtml;
		print '</div>'; // close right flex item
		print '</div>'; // close flex container
	}

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

// =====================================================================
// QR-pair polling (cross-device pairing). Only runs when the section has
// been rendered AND the QR is real (decoy mode = data-pairing-id empty).
// In decoy mode we still wire the "Générer un QR code" button so the
// user can submit the form to spawn a real pairing.
// =====================================================================
(function () {
	var cardEl = document.getElementById('smartauth-qrpair-card');
	if (!cardEl) { return; }
	var genForm = document.getElementById('smartauth-qrpair-genform');
	var refreshBtn = document.getElementById('smartauth-qrpair-refresh');
	if (refreshBtn && genForm) {
		// Single behaviour for the "Générer" button regardless of why the
		// overlay is visible (decoy default, expired, cancelled): submit
		// the CSRF-protected form so the server creates the pairing and
		// redirects back to a clean URL (PRG).
		refreshBtn.addEventListener('click', function () {
			refreshBtn.disabled = true;
			genForm.submit();
		});
	}

	// Accordion toggle is independent of decoy/real state -- wire it up
	// even when polling is skipped below.
	var toggleBtn = document.getElementById('smartauth-qrpair-toggle');
	var toggleIcon = document.getElementById('smartauth-qrpair-toggle-icon');
	if (toggleBtn) {
		toggleBtn.addEventListener('click', function () {
			var collapsed = cardEl.classList.toggle('collapsed');
			toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			if (toggleIcon) {
				toggleIcon.innerHTML = collapsed ? '&#9660;' : '&#9650;';
			}
		});
	}

	var statusEl = document.getElementById('smartauth-qrpair-status');
	if (!statusEl) { return; }
	var pairingId = statusEl.getAttribute('data-pairing-id') || '';
	if (!pairingId) {
		// Decoy mode: nothing to poll, the user has not generated a real
		// pairing yet. The refresh button (above) is the only interaction.
		return;
	}

	var csrfToken = <?php echo json_encode(newToken()); ?>;
	var userId    = <?php echo (int) $id; ?>;
	var i18n = {
		waitingScan:    <?php echo json_encode($langs->transnoentities('QrPairWaitingScan')); ?>,
		waitingConfirm: <?php echo json_encode($langs->transnoentities('QrPairWaitingConfirm')); ?>,
		confirmed:      <?php echo json_encode($langs->transnoentities('QrPairConfirmed')); ?>,
		cancelled:      <?php echo json_encode($langs->transnoentities('QrPairCancelled')); ?>,
		expired:        <?php echo json_encode($langs->transnoentities('QrPairExpired')); ?>,
		consumed:       <?php echo json_encode($langs->transnoentities('QrPairConsumed')); ?>
	};

	var msgEl = document.getElementById('smartauth-qrpair-msg');
	var infoEl = document.getElementById('smartauth-qrpair-claim-info');
	var deviceEl = document.getElementById('smartauth-qrpair-device');
	var ipEl = document.getElementById('smartauth-qrpair-ip');
	var uaEl = document.getElementById('smartauth-qrpair-ua');
	var actionsEl = document.getElementById('smartauth-qrpair-actions');
	var confirmBtn = document.getElementById('smartauth-qrpair-confirm');
	var cancelBtn = document.getElementById('smartauth-qrpair-cancel');
	var qrEl = document.getElementById('smartauth-qrpair-qr');
	var overlayEl = document.getElementById('smartauth-qrpair-overlay');
	var pollHandle = null;

	function stopPolling() {
		if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
	}
	function setTerminal(text, showRefresh) {
		stopPolling();
		msgEl.textContent = text;
		infoEl.style.display = 'none';
		actionsEl.style.display = 'none';
		// On expired/cancelled the QR is no longer usable; blur it and
		// reveal the "regenerate" button on top so the user can request
		// a fresh pairing without leaving the tab. Form submit (the
		// button click handler set up above) handles the actual reload.
		if (showRefresh && qrEl && overlayEl) {
			qrEl.style.filter = 'blur(4px) opacity(0.45)';
			overlayEl.style.display = 'flex';
		}
	}

	// (The accordion toggle and the refresh-button handler are wired up
	// above, before the early "decoy" return -- they need to work both
	// in real and decoy modes.)

	function poll() {
		var url = window.location.pathname + '?action=qrpairstatus&id=' + userId
			+ '&pairing_id=' + encodeURIComponent(pairingId);
		fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.status) {
					pollHandle = setTimeout(poll, 2000);
					return;
				}
				switch (data.status) {
					case 'pending':
					case 'claimed':
						if (data.device_label || data.claim_ip) {
							deviceEl.textContent = data.device_label || '(unknown)';
							ipEl.textContent = data.claim_ip || '';
							uaEl.textContent = data.claim_user_agent || '';
							infoEl.style.display = 'block';
							actionsEl.style.display = 'block';
							msgEl.textContent = i18n.waitingConfirm;
						} else {
							msgEl.textContent = i18n.waitingScan;
							infoEl.style.display = 'none';
							actionsEl.style.display = 'none';
						}
						pollHandle = setTimeout(poll, 2000);
						break;
					case 'confirmed':
						// Local PC has confirmed; mobile will fetch tokens
						// on its next /poll. Keep watching for 'consumed'.
						msgEl.textContent = i18n.confirmed;
						infoEl.style.display = 'none';
						actionsEl.style.display = 'none';
						pollHandle = setTimeout(poll, 2000);
						break;
					case 'consumed':
						// Pairing successfully used by the mobile; no need
						// to regenerate, the QR has done its job.
						setTerminal(i18n.confirmed, false);
						break;
					case 'cancelled':
						setTerminal(i18n.cancelled, true);
						break;
					case 'expired':
						setTerminal(i18n.expired, true);
						break;
					default:
						pollHandle = setTimeout(poll, 2000);
				}
			})
			.catch(function () { pollHandle = setTimeout(poll, 4000); });
	}

	function postQrAction(action) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('id', String(userId));
		fd.append('pairing_id', pairingId);
		fd.append('token', csrfToken);
		return fetch(window.location.pathname, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
			headers: { 'Accept': 'application/json' }
		}).then(function (r) { return r.json(); });
	}

	if (confirmBtn) {
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			cancelBtn.disabled = true;
			postQrAction('qrpairconfirm').then(function (data) {
				if (data && data.ok) {
					msgEl.textContent = i18n.confirmed;
					infoEl.style.display = 'none';
					actionsEl.style.display = 'none';
					if (!pollHandle) { pollHandle = setTimeout(poll, 2000); }
				} else {
					confirmBtn.disabled = false;
					cancelBtn.disabled = false;
				}
			});
		});
	}
	if (cancelBtn) {
		cancelBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			cancelBtn.disabled = true;
			postQrAction('qrpaircancel').then(function () { setTerminal(i18n.cancelled, true); });
		});
	}

	pollHandle = setTimeout(poll, 1500);
})();
</script>


<?php

// End of page
llxFooter();
$db->close();
