<?php
/* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    admin/smartauth_oauth_clients.php
 * \ingroup smartauth
 * \brief   List of OAuth clients
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once __DIR__ . '/../lib/smartauth.lib.php';
require_once __DIR__ . '/../class/smartauthoauthclient.class.php';

// Load translation files
$langs->loadLangs(array("admin", "smartauth@smartauth"));

// Access control - admin only
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'smartauthoauthclientslist';

$id = GETPOST('id', 'int');

// Pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;

if (!$sortfield) {
	$sortfield = "t.name";
}
if (!$sortorder) {
	$sortorder = "ASC";
}

// Search filters
$search_name = GETPOST('search_name', 'alpha');
$search_client_id = GETPOST('search_client_id', 'alpha');
$search_status = GETPOST('search_status', 'int');

// Initialize object
$object = new SmartAuthOAuthClient($db);

/*
 * Actions
 */

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_name = '';
	$search_client_id = '';
	$search_status = '';
	$toselect = array();
}

// Toggle status
if ($action == 'enable' && !empty($id)) {
	$object->fetch($id);
	$object->status = SmartAuthOAuthClient::STATUS_ENABLED;
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans('OAuthClientEnabled'), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	$action = '';
}

if ($action == 'disable' && !empty($id)) {
	$object->fetch($id);
	$object->status = SmartAuthOAuthClient::STATUS_DISABLED;
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans('OAuthClientDisabled'), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	$action = '';
}

// Delete with confirmation
if ($action == 'confirm_delete' && $confirm == 'yes' && !empty($id)) {
	$object->fetch($id);
	$result = $object->delete($user);
	if ($result > 0) {
		setEventMessages($langs->trans('OAuthClientDeleted'), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	$action = '';
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("OAuthClients");
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="' . dol_buildpath("/smartauth/admin/smartauth_oauth_setup.php", 1) . '">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("OAuthClientsList"), $linkback, 'title_setup');

// Configuration header
$head = smartauthAdminPrepareHead();
print dol_get_fiche_head($head, 'oauth', $langs->trans("SmartAuthOAuthSetup"), -1, "smartauth@smartauth");

// Check if OAuth is enabled
$oauthEnabled = getDolGlobalInt('SMARTAUTH_OAUTH_ENABLED', 0);
if (!$oauthEnabled) {
	print '<div class="warning">' . $langs->trans("SmartAuthOAuthNotEnabled") . '</div>';
	print '<br>';
}

// Delete confirmation dialog
if ($action == 'delete' && !empty($id)) {
	$object->fetch($id);
	print $form->formconfirm(
		$_SERVER["PHP_SELF"] . '?id=' . $id,
		$langs->trans('DeleteOAuthClient'),
		$langs->trans('ConfirmDeleteOAuthClient', $object->name),
		'confirm_delete',
		'',
		0,
		1
	);
}

// Build SQL query
$sql = "SELECT t.rowid, t.ref, t.client_id, t.name, t.is_confidential, t.status, t.datec";
$sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_oauth_clients as t";
$sql .= " WHERE t.entity IN (" . getEntity('smartauthoauthclient') . ")";

if (!empty($search_name)) {
	$sql .= natural_search('t.name', $search_name);
}
if (!empty($search_client_id)) {
	$sql .= natural_search('t.client_id', $search_client_id);
}
if ($search_status !== '' && $search_status >= 0) {
	$sql .= " AND t.status = " . ((int) $search_status);
}

// Count total
$sqlforcount = preg_replace('/^SELECT[^]+FROM/Ui', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
$resql = $db->query($sqlforcount);
$nbtotalofrecords = 0;
if ($resql) {
	$objforcount = $db->fetch_object($resql);
	$nbtotalofrecords = $objforcount->nbtotalofrecords;
}
$db->free($resql);

if (($page * $limit) > $nbtotalofrecords) {
	$page = 0;
	$offset = 0;
}

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

// Build param for URLs
$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}
if (!empty($search_name)) {
	$param .= '&search_name=' . urlencode($search_name);
}
if (!empty($search_client_id)) {
	$param .= '&search_client_id=' . urlencode($search_client_id);
}
if ($search_status !== '' && $search_status >= 0) {
	$param .= '&search_status=' . ((int) $search_status);
}

// New client button
$newcardbutton = dolGetButtonTitle(
	$langs->trans('NewOAuthClient'),
	'',
	'fa fa-plus-circle',
	dol_buildpath('/smartauth/admin/smartauth_oauth_client_card.php', 1) . '?action=create',
	'',
	$oauthEnabled
);

print_barre_liste(
	$title,
	$page,
	$_SERVER["PHP_SELF"],
	$param,
	$sortfield,
	$sortorder,
	'',
	$num,
	$nbtotalofrecords,
	'fa-key',
	0,
	$newcardbutton,
	'',
	$limit,
	0,
	0,
	1
);

// Search form
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" name="formulaire">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Header row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_name" value="' . dol_escape_htmltag($search_name) . '"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_client_id" value="' . dol_escape_htmltag($search_client_id) . '"></td>';
print '<td class="liste_titre center">';
$arrayofstatus = array(
	'' => '',
	SmartAuthOAuthClient::STATUS_ENABLED => $langs->trans('Enabled'),
	SmartAuthOAuthClient::STATUS_DISABLED => $langs->trans('Disabled')
);
print $form->selectarray('search_status', $arrayofstatus, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100 center');
print '</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch">';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Column titles
print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans("Name"), 0, $_SERVER["PHP_SELF"], "t.name", '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans("ClientId"), 0, $_SERVER["PHP_SELF"], "t.client_id", '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans("Status"), 0, $_SERVER["PHP_SELF"], "t.status", '', $param, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans("Type"), 0, $_SERVER["PHP_SELF"], "t.is_confidential", '', $param, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans("DateCreation"), 0, $_SERVER["PHP_SELF"], "t.datec", '', $param, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans("Actions"), 0, $_SERVER["PHP_SELF"], '', '', $param, 'class="center"', $sortfield, $sortorder);
print '</tr>';

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}

	$object->id = $obj->rowid;
	$object->ref = $obj->ref;
	$object->client_id = $obj->client_id;
	$object->name = $obj->name;
	$object->is_confidential = $obj->is_confidential;
	$object->status = $obj->status;
	$object->datec = $obj->datec;

	print '<tr class="oddeven">';

	// Name with link
	print '<td class="nowraponall">';
	print '<a href="' . dol_buildpath('/smartauth/admin/smartauth_oauth_client_card.php', 1) . '?id=' . $obj->rowid . '">';
	print img_picto('', 'fa-key', 'class="pictofixedwidth"');
	print dol_escape_htmltag($obj->name);
	print '</a>';
	print '</td>';

	// Client ID
	print '<td class="tdoverflowmax200">';
	print '<code>' . dol_escape_htmltag($obj->client_id) . '</code>';
	print '</td>';

	// Status
	print '<td class="center">';
	print $object->getLibStatut(5);
	print '</td>';

	// Type (confidential / public)
	print '<td class="center">';
	if ($obj->is_confidential) {
		print '<span class="badge badge-status4">' . $langs->trans('ConfidentialClient') . '</span>';
	} else {
		print '<span class="badge badge-status1">' . $langs->trans('PublicClient') . '</span>';
	}
	print '</td>';

	// Date creation
	print '<td class="center nowraponall">';
	print dol_print_date($db->jdate($obj->datec), 'dayhour');
	print '</td>';

	// Actions
	print '<td class="center nowraponall">';

	// View
	print '<a class="editfielda paddingleft paddingright" href="' . dol_buildpath('/smartauth/admin/smartauth_oauth_client_card.php', 1) . '?id=' . $obj->rowid . '">';
	print img_picto($langs->trans('View'), 'eye');
	print '</a>';

	// Edit
	print '<a class="editfielda paddingleft paddingright" href="' . dol_buildpath('/smartauth/admin/smartauth_oauth_client_card.php', 1) . '?id=' . $obj->rowid . '&action=edit">';
	print img_picto($langs->trans('Edit'), 'edit');
	print '</a>';

	// Enable/Disable
	if ($obj->status == SmartAuthOAuthClient::STATUS_ENABLED) {
		print '<a class="editfielda paddingleft paddingright" href="' . $_SERVER["PHP_SELF"] . '?action=disable&id=' . $obj->rowid . '&token=' . newToken() . '">';
		print img_picto($langs->trans('Disable'), 'switch_on');
		print '</a>';
	} else {
		print '<a class="editfielda paddingleft paddingright" href="' . $_SERVER["PHP_SELF"] . '?action=enable&id=' . $obj->rowid . '&token=' . newToken() . '">';
		print img_picto($langs->trans('Enable'), 'switch_off');
		print '</a>';
	}

	// Delete
	print '<a class="editfielda paddingleft paddingright" href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . $obj->rowid . '&token=' . newToken() . '">';
	print img_picto($langs->trans('Delete'), 'delete');
	print '</a>';

	print '</td>';

	print '</tr>';

	$i++;
}

// No records found
if ($num == 0) {
	print '<tr><td colspan="6"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
