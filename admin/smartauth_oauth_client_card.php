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
 * \file    admin/smartauth_oauth_client_card.php
 * \ingroup smartauth
 * \brief   OAuth client card (create/edit/view)
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
require_once __DIR__ . '/../core/lib/smartauth_oauth.lib.php';
require_once __DIR__ . '/../class/smartauthoauthclient.class.php';

// Load translation files
$langs->loadLangs(array("admin", "smartauth@smartauth"));

// Access control - admin only
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');

// Initialize object
$object = new SmartAuthOAuthClient($db);

// Load object if id provided
if ($id > 0) {
	$object->fetch($id);
}

// Store generated secret in session for display after creation
$newlyGeneratedSecret = '';
if (!empty($_SESSION['smartauth_new_client_secret'])) {
	$newlyGeneratedSecret = $_SESSION['smartauth_new_client_secret'];
	unset($_SESSION['smartauth_new_client_secret']);
}

// Available scopes and grants
$availableScopes = smartauth_oauth_get_available_scopes();
$availableGrants = smartauth_oauth_get_available_grants();

/*
 * Actions
 */

if ($cancel) {
	if (!empty($backtopage)) {
		header("Location: " . $backtopage);
		exit;
	}
	header("Location: " . dol_buildpath('/smartauth/admin/smartauth_oauth_clients.php', 1));
	exit;
}

// Create action
if ($action == 'add') {
	$error = 0;

	$object->name = GETPOST('name', 'alphanohtml');
	$object->description = GETPOST('description', 'restricthtml');
	$object->is_confidential = GETPOST('is_confidential', 'int') ? 1 : 0;
	$object->require_pkce = GETPOST('require_pkce', 'int') ? 1 : 0;
	$object->access_token_lifetime = GETPOST('access_token_lifetime', 'int');
	$object->refresh_token_lifetime = GETPOST('refresh_token_lifetime', 'int');

	// Redirect URIs - one per line
	$redirectUrisRaw = GETPOST('redirect_uris', 'restricthtml');
	$redirectUris = array_filter(array_map('trim', explode("\n", $redirectUrisRaw)));
	$object->setRedirectUrisArray($redirectUris);

	// Scopes
	$scopes = GETPOST('allowed_scopes', 'array');
	if (empty($scopes)) {
		$scopes = array();
	}
	$object->setAllowedScopesArray($scopes);

	// Grants
	$grants = GETPOST('allowed_grants', 'array');
	if (empty($grants)) {
		$grants = array('authorization_code', 'refresh_token');
	}
	$object->setAllowedGrantsArray($grants);

	// Service user for client_credentials
	$fk_service_user = GETPOST('fk_service_user', 'int');
	$object->fk_service_user = $fk_service_user > 0 ? $fk_service_user : null;

	// Generate ref from name
	$object->ref = dol_sanitizeFileName(strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $object->name), 0, 20))) . '-' . dol_print_date(dol_now(), '%Y%m%d%H%M%S');

	// Validation
	if (empty($object->name)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Name')), null, 'errors');
		$error++;
	}
	if (empty($redirectUris)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('RedirectURIs')), null, 'errors');
		$error++;
	}

	// Validate redirect URIs format
	foreach ($redirectUris as $uri) {
		if (!filter_var($uri, FILTER_VALIDATE_URL) && $uri !== 'urn:ietf:wg:oauth:2.0:oob') {
			setEventMessages($langs->trans('ErrorInvalidRedirectURI', $uri), null, 'errors');
			$error++;
		}
	}

	if (!$error) {
		// Generate client_id
		$object->client_id = $object->generateClientId();

		// Generate and hash secret for confidential clients
		$plainSecret = '';
		if ($object->is_confidential) {
			$plainSecret = $object->generateClientSecret();
			$object->setClientSecret($plainSecret);
		}

		$result = $object->create($user);

		if ($result > 0) {
			// Store plain secret in session to display once
			if (!empty($plainSecret)) {
				$_SESSION['smartauth_new_client_secret'] = $plainSecret;
			}
			setEventMessages($langs->trans('OAuthClientCreated'), null, 'mesgs');
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = 'create';
		}
	} else {
		$action = 'create';
	}
}

// Update action
if ($action == 'update' && $id > 0) {
	$error = 0;

	$object->fetch($id);

	$object->name = GETPOST('name', 'alphanohtml');
	$object->description = GETPOST('description', 'restricthtml');
	$object->require_pkce = GETPOST('require_pkce', 'int') ? 1 : 0;
	$object->access_token_lifetime = GETPOST('access_token_lifetime', 'int');
	$object->refresh_token_lifetime = GETPOST('refresh_token_lifetime', 'int');

	// Redirect URIs
	$redirectUrisRaw = GETPOST('redirect_uris', 'restricthtml');
	$redirectUris = array_filter(array_map('trim', explode("\n", $redirectUrisRaw)));
	$object->setRedirectUrisArray($redirectUris);

	// Scopes
	$scopes = GETPOST('allowed_scopes', 'array');
	if (empty($scopes)) {
		$scopes = array();
	}
	$object->setAllowedScopesArray($scopes);

	// Grants
	$grants = GETPOST('allowed_grants', 'array');
	if (empty($grants)) {
		$grants = array('authorization_code', 'refresh_token');
	}
	$object->setAllowedGrantsArray($grants);

	// Service user for client_credentials
	$fk_service_user = GETPOST('fk_service_user', 'int');
	$object->fk_service_user = $fk_service_user > 0 ? $fk_service_user : null;

	// Validation
	if (empty($object->name)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Name')), null, 'errors');
		$error++;
	}
	if (empty($redirectUris)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('RedirectURIs')), null, 'errors');
		$error++;
	}

	if (!$error) {
		$result = $object->update($user);

		if ($result > 0) {
			setEventMessages($langs->trans('OAuthClientUpdated'), null, 'mesgs');
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = 'edit';
		}
	} else {
		$action = 'edit';
	}
}

// Regenerate secret with confirmation
if ($action == 'confirm_regenerate_secret' && $confirm == 'yes' && $id > 0) {
	$object->fetch($id);

	if ($object->is_confidential) {
		$plainSecret = $object->generateClientSecret();
		$object->setClientSecret($plainSecret);

		$result = $object->update($user);

		if ($result > 0) {
			$_SESSION['smartauth_new_client_secret'] = $plainSecret;
			setEventMessages($langs->trans('OAuthClientSecretRegenerated'), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

// Delete with confirmation
if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0) {
	$object->fetch($id);
	$result = $object->delete($user);

	if ($result > 0) {
		setEventMessages($langs->trans('OAuthClientDeleted'), null, 'mesgs');
		header("Location: " . dol_buildpath('/smartauth/admin/smartauth_oauth_clients.php', 1));
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

// Toggle status
if ($action == 'enable' && $id > 0) {
	$object->fetch($id);
	$object->status = SmartAuthOAuthClient::STATUS_ENABLED;
	$object->update($user);
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $id);
	exit;
}

if ($action == 'disable' && $id > 0) {
	$object->fetch($id);
	$object->status = SmartAuthOAuthClient::STATUS_DISABLED;
	$object->update($user);
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $id);
	exit;
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("OAuthClientCard");
$help_url = '';

llxHeader('', $title, $help_url);

// Create form
if ($action == 'create') {
	print load_fiche_titre($langs->trans("NewOAuthClient"), '', 'fa-key');

	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="add">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
	}

	print dol_get_fiche_head(array(), '');

	print '<table class="border centpercent tableforfieldcreate">';

	// Name
	print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("Name") . '</td>';
	print '<td><input type="text" class="flat minwidth300" name="name" value="' . dol_escape_htmltag(GETPOST('name', 'alphanohtml')) . '"></td></tr>';

	// Description
	print '<tr><td>' . $langs->trans("Description") . '</td>';
	print '<td><textarea class="flat minwidth300" name="description" rows="3">' . dol_escape_htmltag(GETPOST('description', 'restricthtml')) . '</textarea></td></tr>';

	// Client type
	print '<tr><td class="fieldrequired">' . $langs->trans("ClientType") . '</td>';
	print '<td>';
	$isConfidential = GETPOSTISSET('is_confidential') ? GETPOST('is_confidential', 'int') : 1;
	print '<input type="radio" name="is_confidential" id="confidential" value="1"' . ($isConfidential ? ' checked' : '') . '>';
	print ' <label for="confidential">' . $langs->trans("ConfidentialClient") . '</label>';
	print ' <span class="opacitymedium small">(' . $langs->trans("ConfidentialClientHelp") . ')</span><br>';
	print '<input type="radio" name="is_confidential" id="public" value="0"' . (!$isConfidential ? ' checked' : '') . '>';
	print ' <label for="public">' . $langs->trans("PublicClient") . '</label>';
	print ' <span class="opacitymedium small">(' . $langs->trans("PublicClientHelp") . ')</span>';
	print '</td></tr>';

	// Redirect URIs
	print '<tr><td class="fieldrequired">' . $langs->trans("RedirectURIs") . '</td>';
	print '<td>';
	print '<textarea class="flat minwidth400" name="redirect_uris" rows="3" placeholder="https://example.com/callback">' . dol_escape_htmltag(GETPOST('redirect_uris', 'restricthtml')) . '</textarea>';
	print '<br><span class="opacitymedium small">' . $langs->trans("RedirectURIsHelp") . '</span>';
	print '</td></tr>';

	// Allowed scopes
	print '<tr><td>' . $langs->trans("AllowedScopes") . '</td>';
	print '<td>';
	$selectedScopes = GETPOST('allowed_scopes', 'array');
	if (empty($selectedScopes)) {
		$selectedScopes = array('openid', 'profile', 'email');
	}
	foreach ($availableScopes as $scope => $label) {
		$checked = in_array($scope, $selectedScopes) ? ' checked' : '';
		print '<input type="checkbox" name="allowed_scopes[]" id="scope_' . $scope . '" value="' . $scope . '"' . $checked . '>';
		print ' <label for="scope_' . $scope . '">' . $scope . '</label>';
		print ' <span class="opacitymedium small">(' . $label . ')</span><br>';
	}
	print '</td></tr>';

	// Allowed grants
	print '<tr><td>' . $langs->trans("AllowedGrants") . '</td>';
	print '<td>';
	$selectedGrants = GETPOST('allowed_grants', 'array');
	if (empty($selectedGrants)) {
		$selectedGrants = array('authorization_code', 'refresh_token');
	}
	foreach ($availableGrants as $grant => $label) {
		$checked = in_array($grant, $selectedGrants) ? ' checked' : '';
		print '<input type="checkbox" name="allowed_grants[]" id="grant_' . $grant . '" value="' . $grant . '"' . $checked . '>';
		print ' <label for="grant_' . $grant . '">' . $grant . '</label>';
		print ' <span class="opacitymedium small">(' . $label . ')</span><br>';
	}
	print '</td></tr>';

	// Service user (for client_credentials)
	print '<tr><td>' . $langs->trans("SmartAuthServiceUser") . '</td>';
	print '<td>';
	$fkServiceUser = GETPOSTISSET('fk_service_user') ? GETPOST('fk_service_user', 'int') : 0;
	print img_picto('', 'user', 'class="pictofixedwidth"');
	print $form->select_dolusers($fkServiceUser, 'fk_service_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'minwidth200');
	print '<br><span class="opacitymedium small">' . $langs->trans("SmartAuthServiceUserHelp") . '</span>';
	print '</td></tr>';

	// Require PKCE
	print '<tr><td>' . $langs->trans("RequirePKCE") . '</td>';
	print '<td>';
	$requirePkce = GETPOSTISSET('require_pkce') ? GETPOST('require_pkce', 'int') : 0;
	print '<input type="checkbox" name="require_pkce" value="1"' . ($requirePkce ? ' checked' : '') . '>';
	print ' <span class="opacitymedium small">' . $langs->trans("RequirePKCEHelp") . '</span>';
	print '</td></tr>';

	// Access token lifetime
	print '<tr><td>' . $langs->trans("AccessTokenLifetime") . '</td>';
	print '<td>';
	$accessTtl = GETPOSTISSET('access_token_lifetime') ? GETPOST('access_token_lifetime', 'int') : 3600;
	print '<input type="number" class="flat width100" name="access_token_lifetime" value="' . $accessTtl . '" min="60" max="86400">';
	print ' <span class="opacitymedium">(' . $langs->trans("Seconds") . ', ' . $langs->trans("SmartAuthOAuthDefaultValue", 3600) . ')</span>';
	print '</td></tr>';

	// Refresh token lifetime
	print '<tr><td>' . $langs->trans("RefreshTokenLifetime") . '</td>';
	print '<td>';
	$refreshTtl = GETPOSTISSET('refresh_token_lifetime') ? GETPOST('refresh_token_lifetime', 'int') : 2592000;
	print '<input type="number" class="flat width150" name="refresh_token_lifetime" value="' . $refreshTtl . '" min="3600" max="31536000">';
	print ' <span class="opacitymedium">(' . $langs->trans("Seconds") . ', ' . $langs->trans("SmartAuthOAuthDefaultValue", '2592000 = 30 ' . $langs->trans("Days")) . ')</span>';
	print '</td></tr>';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel("Create");

	print '</form>';
}

// Edit form
if ($action == 'edit' && $object->id > 0) {
	$head = smartauth_oauth_client_prepare_head($object);

	print dol_get_fiche_head($head, 'card', $langs->trans("OAuthClientCard"), -1, 'fa-key');

	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="' . $object->id . '">';

	print '<table class="border centpercent tableforfieldedit">';

	// Ref (read-only)
	print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td>';
	print '<td>' . dol_escape_htmltag($object->ref) . '</td></tr>';

	// Client ID (read-only)
	print '<tr><td>' . $langs->trans("ClientId") . '</td>';
	print '<td><code>' . dol_escape_htmltag($object->client_id) . '</code></td></tr>';

	// Name
	print '<tr><td class="fieldrequired">' . $langs->trans("Name") . '</td>';
	print '<td><input type="text" class="flat minwidth300" name="name" value="' . dol_escape_htmltag($object->name) . '"></td></tr>';

	// Description
	print '<tr><td>' . $langs->trans("Description") . '</td>';
	print '<td><textarea class="flat minwidth300" name="description" rows="3">' . dol_escape_htmltag($object->description) . '</textarea></td></tr>';

	// Client type (read-only)
	print '<tr><td>' . $langs->trans("ClientType") . '</td>';
	print '<td>';
	if ($object->is_confidential) {
		print '<span class="badge badge-status4">' . $langs->trans("ConfidentialClient") . '</span>';
	} else {
		print '<span class="badge badge-status1">' . $langs->trans("PublicClient") . '</span>';
	}
	print ' <span class="opacitymedium small">(' . $langs->trans("ClientTypeCannotChange") . ')</span>';
	print '</td></tr>';

	// Redirect URIs
	print '<tr><td class="fieldrequired tdtop">' . $langs->trans("RedirectURIs") . '</td>';
	print '<td>';
	$uris = implode("\n", $object->getRedirectUrisArray());
	print '<textarea class="flat minwidth400" name="redirect_uris" rows="3">' . dol_escape_htmltag($uris) . '</textarea>';
	print '<br><span class="opacitymedium small">' . $langs->trans("RedirectURIsHelp") . '</span>';
	print '</td></tr>';

	// Allowed scopes
	print '<tr><td class="tdtop">' . $langs->trans("AllowedScopes") . '</td>';
	print '<td>';
	$selectedScopes = $object->getAllowedScopesArray();
	foreach ($availableScopes as $scope => $label) {
		$checked = in_array($scope, $selectedScopes) ? ' checked' : '';
		print '<input type="checkbox" name="allowed_scopes[]" id="scope_' . $scope . '" value="' . $scope . '"' . $checked . '>';
		print ' <label for="scope_' . $scope . '">' . $scope . '</label>';
		print ' <span class="opacitymedium small">(' . $label . ')</span><br>';
	}
	print '</td></tr>';

	// Allowed grants
	print '<tr><td class="tdtop">' . $langs->trans("AllowedGrants") . '</td>';
	print '<td>';
	$selectedGrants = $object->getAllowedGrantsArray();
	foreach ($availableGrants as $grant => $label) {
		$checked = in_array($grant, $selectedGrants) ? ' checked' : '';
		print '<input type="checkbox" name="allowed_grants[]" id="grant_' . $grant . '" value="' . $grant . '"' . $checked . '>';
		print ' <label for="grant_' . $grant . '">' . $grant . '</label>';
		print ' <span class="opacitymedium small">(' . $label . ')</span><br>';
	}
	print '</td></tr>';

	// Service user (for client_credentials)
	print '<tr><td>' . $langs->trans("SmartAuthServiceUser") . '</td>';
	print '<td>';
	print img_picto('', 'user', 'class="pictofixedwidth"');
	print $form->select_dolusers($object->fk_service_user, 'fk_service_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'minwidth200');
	print '<br><span class="opacitymedium small">' . $langs->trans("SmartAuthServiceUserHelp") . '</span>';
	print '</td></tr>';

	// Require PKCE
	print '<tr><td>' . $langs->trans("RequirePKCE") . '</td>';
	print '<td>';
	print '<input type="checkbox" name="require_pkce" value="1"' . ($object->require_pkce ? ' checked' : '') . '>';
	print ' <span class="opacitymedium small">' . $langs->trans("RequirePKCEHelp") . '</span>';
	print '</td></tr>';

	// Access token lifetime
	print '<tr><td>' . $langs->trans("AccessTokenLifetime") . '</td>';
	print '<td>';
	print '<input type="number" class="flat width100" name="access_token_lifetime" value="' . $object->access_token_lifetime . '" min="60" max="86400">';
	print ' <span class="opacitymedium">(' . $langs->trans("Seconds") . ')</span>';
	print '</td></tr>';

	// Refresh token lifetime
	print '<tr><td>' . $langs->trans("RefreshTokenLifetime") . '</td>';
	print '<td>';
	print '<input type="number" class="flat width150" name="refresh_token_lifetime" value="' . $object->refresh_token_lifetime . '" min="3600" max="31536000">';
	print ' <span class="opacitymedium">(' . $langs->trans("Seconds") . ')</span>';
	print '</td></tr>';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel();

	print '</form>';
}

// View mode
if ($object->id > 0 && empty($action) || ($action != 'edit' && $action != 'create')) {
	// Confirmation dialogs
	if ($action == 'delete') {
		print $form->formconfirm(
			$_SERVER["PHP_SELF"] . '?id=' . $object->id,
			$langs->trans('DeleteOAuthClient'),
			$langs->trans('ConfirmDeleteOAuthClient', $object->name),
			'confirm_delete',
			'',
			0,
			1
		);
	}

	if ($action == 'regenerate_secret') {
		print $form->formconfirm(
			$_SERVER["PHP_SELF"] . '?id=' . $object->id,
			$langs->trans('RegenerateSecret'),
			$langs->trans('ConfirmRegenerateSecret'),
			'confirm_regenerate_secret',
			'',
			0,
			1
		);
	}

	$head = smartauth_oauth_client_prepare_head($object);

	print dol_get_fiche_head($head, 'card', $langs->trans("OAuthClientCard"), -1, 'fa-key');

	// Display newly generated secret (only once after creation or regeneration)
	if (!empty($newlyGeneratedSecret)) {
		print '<div class="warning">';
		print '<strong>' . $langs->trans("ImportantClientSecretWarning") . '</strong><br><br>';
		print $langs->trans("ClientSecretDisplayOnce") . '<br><br>';
		print '<div class="center" style="background: #f8f8f8; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0;">';
		print '<strong>' . $langs->trans("ClientSecret") . ':</strong><br>';
		print '<code style="font-size: 1.2em; user-select: all;">' . dol_escape_htmltag($newlyGeneratedSecret) . '</code>';
		print '</div>';
		print $langs->trans("CopySecretNow");
		print '</div><br>';
	}

	// Linkback
	$linkback = '<a href="' . dol_buildpath('/smartauth/admin/smartauth_oauth_clients.php', 1) . '">' . $langs->trans("BackToList") . '</a>';

	$morehtmlref = '<div class="refidno">';
	$morehtmlref .= '</div>';

	dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', $morehtmlref);

	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';

	// Client ID
	print '<tr><td class="titlefield">' . $langs->trans("ClientId") . '</td>';
	print '<td>';
	print '<code style="user-select: all;">' . dol_escape_htmltag($object->client_id) . '</code>';
	print ' <button type="button" class="button small" onclick="navigator.clipboard.writeText(\'' . dol_escape_js($object->client_id) . '\'); alert(\'' . dol_escape_js($langs->trans("Copied")) . '\');">' . $langs->trans("Copy") . '</button>';
	print '</td></tr>';

	// Client Secret info (for confidential clients)
	if ($object->is_confidential) {
		print '<tr><td>' . $langs->trans("ClientSecret") . '</td>';
		print '<td>';
		print '<span class="opacitymedium">' . $langs->trans("SecretStoredHashed") . '</span>';
		print ' <a href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=regenerate_secret&token=' . newToken() . '" class="button small">';
		print $langs->trans("RegenerateSecret");
		print '</a>';
		print '</td></tr>';
	}

	// Name
	print '<tr><td>' . $langs->trans("Name") . '</td>';
	print '<td>' . dol_escape_htmltag($object->name) . '</td></tr>';

	// Description
	print '<tr><td>' . $langs->trans("Description") . '</td>';
	print '<td>' . dol_escape_htmltag($object->description) . '</td></tr>';

	// Client type
	print '<tr><td>' . $langs->trans("ClientType") . '</td>';
	print '<td>';
	if ($object->is_confidential) {
		print '<span class="badge badge-status4">' . $langs->trans("ConfidentialClient") . '</span>';
	} else {
		print '<span class="badge badge-status1">' . $langs->trans("PublicClient") . '</span>';
	}
	print '</td></tr>';

	// Status
	print '<tr><td>' . $langs->trans("Status") . '</td>';
	print '<td>' . $object->getLibStatut(5) . '</td></tr>';

	// Redirect URIs
	print '<tr><td class="tdtop">' . $langs->trans("RedirectURIs") . '</td>';
	print '<td>';
	$uris = $object->getRedirectUrisArray();
	foreach ($uris as $uri) {
		print '<code>' . dol_escape_htmltag($uri) . '</code><br>';
	}
	print '</td></tr>';

	// Allowed scopes
	print '<tr><td class="tdtop">' . $langs->trans("AllowedScopes") . '</td>';
	print '<td>';
	$scopes = $object->getAllowedScopesArray();
	foreach ($scopes as $scope) {
		print '<span class="badge badge-secondary marginrightonly">' . dol_escape_htmltag($scope) . '</span>';
	}
	print '</td></tr>';

	// Allowed grants
	print '<tr><td class="tdtop">' . $langs->trans("AllowedGrants") . '</td>';
	print '<td>';
	$grants = $object->getAllowedGrantsArray();
	foreach ($grants as $grant) {
		print '<span class="badge badge-secondary marginrightonly">' . dol_escape_htmltag($grant) . '</span>';
	}
	print '</td></tr>';

	// Service user (for client_credentials)
	print '<tr><td>' . $langs->trans("SmartAuthServiceUser") . '</td>';
	print '<td>';
	if (!empty($object->fk_service_user)) {
		$serviceUser = new User($db);
		$serviceUser->fetch($object->fk_service_user);
		print $serviceUser->getNomUrl(1);
	} else {
		print '<span class="opacitymedium">' . $langs->trans("Undefined") . '</span>';
	}
	print '</td></tr>';

	// Require PKCE
	print '<tr><td>' . $langs->trans("RequirePKCE") . '</td>';
	print '<td>' . yn($object->requiresPkce()) . '</td></tr>';

	// Access token lifetime
	print '<tr><td>' . $langs->trans("AccessTokenLifetime") . '</td>';
	print '<td>' . $object->access_token_lifetime . ' ' . $langs->trans("Seconds");
	if ($object->access_token_lifetime >= 3600) {
		print ' (' . round($object->access_token_lifetime / 3600, 1) . ' ' . $langs->trans("Hours") . ')';
	}
	print '</td></tr>';

	// Refresh token lifetime
	print '<tr><td>' . $langs->trans("RefreshTokenLifetime") . '</td>';
	print '<td>' . $object->refresh_token_lifetime . ' ' . $langs->trans("Seconds");
	if ($object->refresh_token_lifetime >= 86400) {
		print ' (' . round($object->refresh_token_lifetime / 86400, 1) . ' ' . $langs->trans("Days") . ')';
	}
	print '</td></tr>';

	// Date creation
	print '<tr><td>' . $langs->trans("DateCreation") . '</td>';
	print '<td>' . dol_print_date($object->datec, 'dayhour') . '</td></tr>';

	print '</table>';
	print '</div>';

	// Right column - Statistics
	print '<div class="fichehalfright">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';

	// Count active tokens
	$sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
	$sql .= " WHERE fk_client = " . ((int) $object->id);
	$sql .= " AND revoked_at IS NULL AND expires_at > '" . $db->idate(dol_now()) . "'";
	$resql = $db->query($sql);
	$activeTokens = 0;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$activeTokens = $obj->nb;
	}

	print '<tr><td class="titlefield">' . $langs->trans("ActiveTokens") . '</td>';
	print '<td><span class="badge badge-status4">' . $activeTokens . '</span></td></tr>';

	// Last token issued
	$sql = "SELECT MAX(datec) as last_token FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
	$sql .= " WHERE fk_client = " . ((int) $object->id);
	$resql = $db->query($sql);
	$lastToken = null;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$lastToken = $obj->last_token;
	}

	print '<tr><td>' . $langs->trans("LastTokenIssued") . '</td>';
	print '<td>';
	if ($lastToken) {
		print dol_print_date($db->jdate($lastToken), 'dayhour');
	} else {
		print '<span class="opacitymedium">' . $langs->trans("Never") . '</span>';
	}
	print '</td></tr>';

	print '</table>';
	print '</div>';

	print '</div>';
	print '<div class="clearboth"></div>';

	print dol_get_fiche_end();

	// Action buttons
	print '<div class="tabsAction">';

	// Edit
	print dolGetButtonAction(
		'',
		$langs->trans('Modify'),
		'default',
		$_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=edit&token=' . newToken(),
		'',
		true
	);

	// Enable/Disable
	if ($object->status == SmartAuthOAuthClient::STATUS_ENABLED) {
		print dolGetButtonAction(
			'',
			$langs->trans('Disable'),
			'default',
			$_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=disable&token=' . newToken(),
			'',
			true
		);
	} else {
		print dolGetButtonAction(
			'',
			$langs->trans('Enable'),
			'default',
			$_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=enable&token=' . newToken(),
			'',
			true
		);
	}

	// Delete
	print dolGetButtonAction(
		'',
		$langs->trans('Delete'),
		'delete',
		$_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(),
		'',
		true
	);

	print '</div>';
}

llxFooter();
$db->close();
