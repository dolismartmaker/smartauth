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
 * \file    smartauth/admin/smartauth_oauth_setup.php
 * \ingroup smartauth
 * \brief   SmartAuth OAuth2/OIDC server setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
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
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $conf, $db;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
dol_include_once('/smartauth/lib/smartauth.lib.php');

// Translations
$langs->loadLangs(array("admin", "smartauth@smartauth"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;

// Check if Dolibarr OAuth client exists
$dolibarrClientExists = false;
$dolibarrClientId = 'dolibarr-erp';
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_oauth_clients WHERE client_id = '".$db->escape($dolibarrClientId)."'";
$resql = $db->query($sql);
if ($resql && $db->num_rows($resql) > 0) {
	$dolibarrClientExists = true;
}

/*
 * Actions
 */

if ($action == 'create_dolibarr_client') {
	// Create the Dolibarr OAuth client
	if ($dolibarrClientExists) {
		setEventMessages($langs->trans('SmartAuthDolibarrClientAlreadyExists'), null, 'warnings');
	} else {
		$redirectUri = GETPOST('redirect_uri', 'alphanohtml');
		if (empty($redirectUri)) {
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
			$redirectUri = $protocol.'://'.$host.'/index.php';
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."smartauth_oauth_clients (";
		$sql .= "ref, client_id, client_secret, name, description, logo_url, ";
		$sql .= "redirect_uris, allowed_scopes, allowed_grants, ";
		$sql .= "is_confidential, require_pkce, access_token_lifetime, refresh_token_lifetime, ";
		$sql .= "status, fk_user_author, datec, entity";
		$sql .= ") VALUES (";
		$sql .= "'DOLIBARR-INTERNAL', ";
		$sql .= "'".$db->escape($dolibarrClientId)."', ";
		$sql .= "NULL, ";
		$sql .= "'Dolibarr ERP', ";
		$sql .= "'Internal OAuth client for Dolibarr authentication via SmartAuth. Uses PKCE for secure authorization.', ";
		$sql .= "NULL, ";
		$sql .= "'".$db->escape(json_encode(array($redirectUri)))."', ";
		$sql .= "'[\"openid\", \"profile\", \"email\"]', ";
		$sql .= "'[\"authorization_code\", \"refresh_token\"]', ";
		$sql .= "0, ";
		$sql .= "1, ";
		$sql .= "3600, ";
		$sql .= "2592000, ";
		$sql .= "1, ";
		$sql .= $user->id.", ";
		$sql .= "'".$db->idate(dol_now())."', ";
		$sql .= $conf->entity;
		$sql .= ")";

		$result = $db->query($sql);
		if ($result) {
			$dolibarrClientExists = true;
			setEventMessages($langs->trans('SmartAuthDolibarrClientCreated'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('SmartAuthDolibarrClientError').': '.$db->lasterror(), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'test_connection') {
	// Test SmartAuth availability
	$issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
	if (empty($issuer)) {
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$issuer = $protocol.'://'.$host;
	}

	$discoveryUrl = rtrim($issuer, '/').'/.well-known/openid-configuration';

	$ch = curl_init($discoveryUrl);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 5,
		CURLOPT_CONNECTTIMEOUT => 2,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	));

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($curlError) {
		setEventMessages($langs->trans('SmartAuthDolibarrTestFailed', $curlError), null, 'errors');
	} elseif ($httpCode !== 200) {
		setEventMessages($langs->trans('SmartAuthDolibarrTestFailed', 'HTTP '.$httpCode), null, 'errors');
	} else {
		$config = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE || empty($config['issuer'])) {
			setEventMessages($langs->trans('SmartAuthDolibarrTestFailed', 'Invalid JSON response'), null, 'errors');
		} else {
			setEventMessages($langs->trans('SmartAuthDolibarrTestSuccess'), null, 'mesgs');
		}
	}
}

if ($action == 'update') {
	$oauthEnabled = GETPOST('oauth_enabled', 'int') ? 1 : 0;
	$oauthIssuer = GETPOST('oauth_issuer', 'alphanohtml');
	$accessTtl = GETPOST('access_ttl', 'int');
	$refreshTtl = GETPOST('refresh_ttl', 'int');
	$codeTtl = GETPOST('code_ttl', 'int');
	$requirePkce = GETPOST('require_pkce', 'int') ? 1 : 0;
	$consentRemember = GETPOST('consent_remember', 'int') ? 1 : 0;

	// Validate TTL values
	if ($accessTtl < 60) {
		$accessTtl = 3600;
	}
	if ($refreshTtl < 3600) {
		$refreshTtl = 2592000;
	}
	if ($codeTtl < 60) {
		$codeTtl = 600;
	}

	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_ENABLED', $oauthEnabled, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_ISSUER', $oauthIssuer, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_ACCESS_TTL', $accessTtl, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_REFRESH_TTL', $refreshTtl, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_CODE_TTL', $codeTtl, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_REQUIRE_PKCE', $requirePkce, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SMARTAUTH_OAUTH_CONSENT_REMEMBER', $consentRemember, 'chaine', 0, '', $conf->entity);

	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "SmartAuthOAuthSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = smartauthAdminPrepareHead();
print dol_get_fiche_head($head, 'oauth', $langs->trans($page_name), -1, "smartauth@smartauth");

// Get current values
$oauthEnabled = getDolGlobalInt('SMARTAUTH_OAUTH_ENABLED', 0);
$oauthIssuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
$accessTtl = getDolGlobalInt('SMARTAUTH_OAUTH_ACCESS_TTL', 3600);
$refreshTtl = getDolGlobalInt('SMARTAUTH_OAUTH_REFRESH_TTL', 2592000);
$codeTtl = getDolGlobalInt('SMARTAUTH_OAUTH_CODE_TTL', 600);
$requirePkce = getDolGlobalInt('SMARTAUTH_OAUTH_REQUIRE_PKCE', 1);
$consentRemember = getDolGlobalInt('SMARTAUTH_OAUTH_CONSENT_REMEMBER', 1);

// Auto-detect issuer if not set
$detectedIssuer = '';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$detectedIssuer = $protocol.'://'.$host;

print '<span class="opacitymedium">'.$langs->trans("SmartAuthOAuthSetupDesc").'</span><br><br>';

// Configuration form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

// Section: Activation
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("SmartAuthOAuthActivation").'</td>';
print '</tr>';

// OAuth Enabled
print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans("SmartAuthOAuthEnabled").'</td>';
print '<td>';
print '<input type="checkbox" name="oauth_enabled" value="1"'.($oauthEnabled ? ' checked' : '').'>';
print '</td>';
print '</tr>';

// Issuer URL
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthIssuer").'</td>';
print '<td>';
print '<input type="text" class="flat minwidth400" name="oauth_issuer" value="'.dol_escape_htmltag($oauthIssuer).'" placeholder="'.dol_escape_htmltag($detectedIssuer).'">';
print '<br><span class="opacitymedium small">'.$langs->trans("SmartAuthOAuthIssuerHelp", $detectedIssuer).'</span>';
print '</td>';
print '</tr>';

// Section: Token settings
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("SmartAuthOAuthTokenSettings").'</td>';
print '</tr>';

// Access token TTL
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthAccessTTL").'</td>';
print '<td>';
print '<input type="number" class="flat width100" name="access_ttl" value="'.$accessTtl.'" min="60" max="86400">';
print ' <span class="opacitymedium">('.$langs->trans("SmartAuthOAuthDefaultValue", 3600).')</span>';
print '</td>';
print '</tr>';

// Refresh token TTL
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthRefreshTTL").'</td>';
print '<td>';
print '<input type="number" class="flat width150" name="refresh_ttl" value="'.$refreshTtl.'" min="3600" max="31536000">';
print ' <span class="opacitymedium">('.$langs->trans("SmartAuthOAuthDefaultValue", 2592000).' = 30 '.$langs->trans("Days").')</span>';
print '</td>';
print '</tr>';

// Authorization code TTL
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthCodeTTL").'</td>';
print '<td>';
print '<input type="number" class="flat width100" name="code_ttl" value="'.$codeTtl.'" min="60" max="3600">';
print ' <span class="opacitymedium">('.$langs->trans("SmartAuthOAuthDefaultValue", 600).')</span>';
print '</td>';
print '</tr>';

// Section: Security
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("SmartAuthOAuthSecurity").'</td>';
print '</tr>';

// Require PKCE
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthRequirePKCE").'</td>';
print '<td>';
print '<input type="checkbox" name="require_pkce" value="1"'.($requirePkce ? ' checked' : '').'>';
print '<br><span class="opacitymedium small">'.$langs->trans("SmartAuthOAuthRequirePKCEHelp").'</span>';
print '</td>';
print '</tr>';

// Remember consent
print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartAuthOAuthConsentRemember").'</td>';
print '<td>';
print '<input type="checkbox" name="consent_remember" value="1"'.($consentRemember ? ' checked' : '').'>';
print '<br><span class="opacitymedium small">'.$langs->trans("SmartAuthOAuthConsentRememberHelp").'</span>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Display OIDC endpoints when OAuth is enabled
if ($oauthEnabled) {
	$issuerUrl = !empty($oauthIssuer) ? $oauthIssuer : $detectedIssuer;

	print '<br>';
	print '<table class="noborder centpercent">';

	print '<tr class="liste_titre">';
	print '<td colspan="2">'.$langs->trans("SmartAuthOAuthEndpoints").'</td>';
	print '</tr>';

	$endpoints = array(
		'Discovery' => '/.well-known/openid-configuration',
		'JWKS' => '/.well-known/jwks.json',
		'Authorization' => '/oauth/authorize',
		'Token' => '/oauth/token',
		'Userinfo' => '/oauth/userinfo',
		'Revocation' => '/oauth/revoke',
		'End Session' => '/oauth/logout',
	);

	foreach ($endpoints as $name => $path) {
		print '<tr class="oddeven">';
		print '<td class="titlefield">'.$name.'</td>';
		print '<td><code>'.dol_escape_htmltag($issuerUrl.$path).'</code></td>';
		print '</tr>';
	}

	print '</table>';

	// Link to OAuth clients management
	print '<br>';
	print '<div class="center">';
	print '<a class="button" href="'.dol_buildpath('/smartauth/admin/smartauth_oauth_clients.php', 1).'">';
	print img_picto('', 'fa-key', 'class="pictofixedwidth"');
	print $langs->trans("OAuthClients");
	print '</a>';
	print '</div>';

	// Dolibarr Integration Section
	print '<br><br>';
	print '<table class="noborder centpercent">';

	print '<tr class="liste_titre">';
	print '<td colspan="2">'.$langs->trans("SmartAuthDolibarrIntegration").'</td>';
	print '</tr>';

	print '<tr class="oddeven">';
	print '<td colspan="2">';
	print '<span class="opacitymedium">'.$langs->trans("SmartAuthDolibarrIntegrationDesc").'</span>';
	print '</td>';
	print '</tr>';

	// Client status
	print '<tr class="oddeven">';
	print '<td class="titlefield">'.$langs->trans("Status").'</td>';
	print '<td>';
	if ($dolibarrClientExists) {
		print img_picto('', 'statut4', 'class="pictofixedwidth"');
		print '<span class="badge  badge-status4">'.$langs->trans("SmartAuthDolibarrClientExists").'</span>';
	} else {
		print img_picto('', 'statut5', 'class="pictofixedwidth"');
		print '<span class="badge  badge-status5">'.$langs->trans("SmartAuthDolibarrClientNotExists").'</span>';
	}
	print '</td>';
	print '</tr>';

	// Create client button (if not exists)
	if (!$dolibarrClientExists) {
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("SmartAuthDolibarrCreateClient").'</td>';
		print '<td>';
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display: inline;">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="create_dolibarr_client">';
		print '<input type="text" class="flat minwidth300" name="redirect_uri" placeholder="https://erp.example.com/index.php" value="">';
		print ' <input type="submit" class="button" value="'.$langs->trans("Create").'">';
		print '</form>';
		print '<br><span class="opacitymedium small">'.$langs->trans("SmartAuthDolibarrRedirectUriHelp").'</span>';
		print '</td>';
		print '</tr>';
	}

	// Test connection button
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans("SmartAuthDolibarrTestConnection").'</td>';
	print '<td>';
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display: inline;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="test_connection">';
	print '<input type="submit" class="button" value="'.$langs->trans("Test").'">';
	print '</form>';
	print '</td>';
	print '</tr>';

	print '</table>';

	// Configuration instructions (show only if client exists)
	if ($dolibarrClientExists) {
		print '<br>';
		print '<table class="noborder centpercent">';

		print '<tr class="liste_titre">';
		print '<td colspan="2">'.$langs->trans("SmartAuthDolibarrConfig").'</td>';
		print '</tr>';

		print '<tr class="oddeven">';
		print '<td colspan="2">';
		print '<span class="opacitymedium">'.$langs->trans("SmartAuthDolibarrConfigDesc").'</span>';
		print '</td>';
		print '</tr>';

		// conf.php configuration
		print '<tr class="oddeven">';
		print '<td class="titlefield">'.$langs->trans("SmartAuthDolibarrConfPhp").'</td>';
		print '<td>';
		print '<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">';
		print htmlspecialchars('$dolibarr_main_authentication = \'smartauthoauth,dolibarr\';');
		print '</pre>';
		print '</td>';
		print '</tr>';

		// Constants to configure
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("SmartAuthDolibarrConstants").'</td>';
		print '<td>';
		print '<table class="nobordernopadding">';

		// SMARTAUTH_OAUTH_ISSUER
		print '<tr>';
		print '<td class="nowrap"><code>SMARTAUTH_OAUTH_ISSUER</code></td>';
		print '<td> = </td>';
		print '<td><code>'.dol_escape_htmltag($issuerUrl).'</code></td>';
		print '</tr>';

		// SMARTAUTH_OAUTH_CLIENT_ID
		print '<tr>';
		print '<td class="nowrap"><code>SMARTAUTH_OAUTH_CLIENT_ID</code></td>';
		print '<td> = </td>';
		print '<td><code>dolibarr-erp</code></td>';
		print '</tr>';

		print '</table>';
		print '</td>';
		print '</tr>';

		// Get redirect URI from database
		$redirectUriDisplay = '';
		$sql = "SELECT redirect_uris FROM ".MAIN_DB_PREFIX."smartauth_oauth_clients WHERE client_id = 'dolibarr-erp'";
		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			$uris = json_decode($obj->redirect_uris, true);
			if (is_array($uris) && count($uris) > 0) {
				$redirectUriDisplay = $uris[0];
			}
		}

		// Redirect URI
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("SmartAuthDolibarrRedirectUri").'</td>';
		print '<td>';
		print '<code>'.dol_escape_htmltag($redirectUriDisplay).'</code>';
		print '<br><span class="opacitymedium small">'.$langs->trans("SmartAuthDolibarrRedirectUriHelp").'</span>';
		print '</td>';
		print '</tr>';

		// File to copy
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("SmartAuthDolibarrCopyFile").'</td>';
		print '<td>';
		print '<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">';
		print 'cp '.dol_escape_htmltag(dol_buildpath('/smartauth/core/login/functions_smartauthoauth.php', 0)).' \\'."<br>";
		print '   '.dol_escape_htmltag(DOL_DOCUMENT_ROOT.'/core/login/');
		print '</pre>';
		print '</td>';
		print '</tr>';

		// Link to documentation
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("SmartAuthDolibarrInstallDoc").'</td>';
		print '<td>';
		$docPath = dol_buildpath('/smartauth/docs/install_dolibarr_client.md', 0);
		print '<a href="'.dol_buildpath('/smartauth/docs/install_dolibarr_client.md', 1).'" target="_blank">';
		print img_picto('', 'file', 'class="pictofixedwidth"');
		print 'install_dolibarr_client.md';
		print '</a>';
		print '</td>';
		print '</tr>';

		print '</table>';
	}
} else {
	print '<br>';
	print '<div class="info">'.$langs->trans("SmartAuthOAuthNotEnabled").'</div>';
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
