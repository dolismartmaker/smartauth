<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    smartauth/admin/about.php
 * \ingroup smartauth
 * \brief   About page of module smartauth.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
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

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/smartauth/lib/smartauth.lib.php');

// Translations
$langs->loadLangs(array("errors", "admin", "install", "smartauth@smartauth"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * Actions
 */

if ($action == 'send_feedback') {
	$rating = GETPOST('rating', 'int');
	$feedback = GETPOST('feedback', 'restricthtml');
	$email = GETPOST('email', 'email');

	if ($rating > 0) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$to = 'commercial+smartauth@cap-rel.fr';
		$from = !empty($email) ? $email : getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		$subject = 'SmartAuth Module Feedback - '.$rating.'/5 stars';

		$message = "New feedback from SmartAuth module:\n\n";
		$message .= "Rating: ".$rating."/5\n";
		$message .= "User: ".$user->getFullName($langs)." (".$user->login.")\n";
		$message .= "Email: ".$email."\n";
		$message .= "Dolibarr version: ".DOL_VERSION."\n\n";
		$message .= "Feedback:\n".$feedback."\n";

		$mail = new CMailFile($subject, $to, $from, $message);

		if ($mail->sendfile()) {
			setEventMessage($langs->trans('SmartauthFeedbackSent'), 'mesgs');
		} else {
			dol_syslog("SmartAuth about.php: failed to send feedback email to ".$to, LOG_ERR);
			setEventMessage($langs->trans('SmartauthFeedbackSendError'), 'errors');
		}
	} else {
		dol_syslog("SmartAuth about.php: feedback submitted without rating", LOG_WARNING);
	}

	$action = '';
}


/*
 * View
 */

$form = new Form($db);

$help_url = 'https://doc.cap-rel.fr/smartauth/';
$page_name = "SmartauthAbout";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, [], ['/smartauth/css/admin.css.php']);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = smartauthAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, "smartauth@smartauth");

dol_include_once('/smartauth/core/modules/modSmartauth.class.php');
$tmpmodule = new modSmartauth($db);

// Module description
print $tmpmodule->getDescLong();
print '<br>';

// Support page layout
print '<div class="support-page">';
print '<div class="support-content">';

// -- Left column --
print '<div class="support-column">';

// Module info box
print '<div class="support-box about-module-info">';
print '<h3>'.$langs->trans('AboutModuleInfo').'</h3>';
print '<table>';

// Version
print '<tr>';
print '<td>'.$langs->trans("Version").'</td>';
print '<td>'.dol_escape_htmltag($tmpmodule->version).'</td>';
print '</tr>';

// Publisher
print '<tr>';
print '<td>'.$langs->trans("Publisher").'</td>';
print '<td>';
$url = $tmpmodule->editor_url;
if ($url && strpos($url, '://') === false) {
	$url = 'https://'.$url;
}
if ($url) {
	print '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($tmpmodule->editor_name).'</a>';
} else {
	print dol_escape_htmltag($tmpmodule->editor_name);
}
print '</td>';
print '</tr>';

// License
print '<tr>';
print '<td>'.$langs->trans("License").'</td>';
print '<td>GPL v3+</td>';
print '</tr>';

// Dolibarr min version
$minversion = $tmpmodule->need_dolibarr_version;
if (is_array($minversion) && count($minversion) >= 2) {
	print '<tr>';
	print '<td>'.$langs->trans("DolibarrMinVersion").'</td>';
	print '<td>'.$minversion[0].'.'.$minversion[1].'</td>';
	print '</tr>';
}

// PHP min version
$phpmin = $tmpmodule->phpmin;
if (is_array($phpmin) && count($phpmin) >= 2) {
	print '<tr>';
	print '<td>'.$langs->trans("PHPMinVersion").'</td>';
	print '<td>'.$phpmin[0].'.'.$phpmin[1].'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Feedback box
print '<div class="support-box">';
print '<h2>'.$langs->trans('SmartauthDoYouLikeModule').'</h2>';
print '<p class="support-intro">'.$langs->trans('SmartauthFeedbackIntro').'</p>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="send_feedback">';

// Star rating
print '<div class="rating-container">';
print '<label>'.$langs->trans('SmartauthYourRating').':</label><br>';
print '<div class="star-rating">';
for ($i = 5; $i >= 1; $i--) {
	print '<input type="radio" id="star'.$i.'" name="rating" value="'.$i.'" required>';
	print '<label for="star'.$i.'" title="'.$i.'">&#9733;</label>';
}
print '</div>';
print '</div>';

// Feedback text
print '<div class="form-group">';
print '<label for="feedback">'.$langs->trans('SmartauthYourFeedback').':</label><br>';
print '<textarea name="feedback" id="feedback" rows="6" class="flat minwidth400" placeholder="'.$langs->trans('SmartauthFeedbackPlaceholder').'"></textarea>';
print '</div>';

// Email
print '<div class="form-group">';
print '<label for="email">'.$langs->trans('SmartauthYourEmail').' ('.$langs->trans('Optional').'):</label><br>';
print '<input type="email" name="email" id="email" class="flat minwidth300" value="'.dol_escape_htmltag($user->email).'" placeholder="your-email@example.com">';
print '</div>';

print '<button type="submit" class="button">'.$langs->trans('SmartauthSendFeedback').'</button>';
print '</form>';

print '</div>';

print '</div>'; // End left column

// -- Right column --
print '<div class="support-column">';

// Donation box
print '<div class="support-box donate-box">';
print '<h2>&#9749; '.$langs->trans('SmartauthBuyMeACoffee').'</h2>';
print '<p>'.$langs->trans('SmartauthDonationText').'</p>';
print '<div class="donation-buttons">';
print '<a href="https://shop.cap-rel.fr/cat/112" target="_blank" rel="noopener noreferrer" class="button-donate button-primary">';
print img_picto('', 'fa-coffee', 'class="pictofixedwidth"').' '.$langs->trans('SmartauthOfferCoffee');
print '</a>';
print '</div>';
print '</div>';

// Useful links box
print '<div class="support-box links-box">';
print '<h3>'.$langs->trans('AboutUsefulLinks').'</h3>';
print '<ul class="support-links">';
print '<li><a href="https://doc.cap-rel.fr/smartauth/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-book').' '.$langs->trans('OnlineDocumentation').'</a></li>';
print '<li><a href="https://cap-rel.fr/sav-module-dolibarr/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-wrench').' '.$langs->trans('AboutSAV').'</a></li>';
print '<li><a href="https://www.dolibarr.fr/forum" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-comments').' '.$langs->trans('AboutForum').'</a></li>';
print '<li><a href="https://cap-rel.fr/contact/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-envelope').' '.$langs->trans('AboutContact').'</a></li>';
print '</ul>';
print '</div>';

print '</div>'; // End right column

print '</div>'; // End support-content
print '</div>'; // End support-page

// Changelog section
$changelog_path = dol_buildpath('/smartauth/CHANGELOG.md', 0);
if (file_exists($changelog_path)) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("ChangeLog").'</td>';
	print '</tr>';
	print '<tr class="oddeven">';
	print '<td class="wordbreak">';
	$changelog_content = file_get_contents($changelog_path);
	$changelog_content = dol_escape_htmltag($changelog_content);
	$changelog_content = preg_replace('/^### (.+)$/m', '<strong>$1</strong>', $changelog_content);
	$changelog_content = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $changelog_content);
	$changelog_content = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $changelog_content);
	$changelog_content = preg_replace('/\n/', '<br>', $changelog_content);
	print $changelog_content;
	print '</td>';
	print '</tr>';
	print '</table>';
	print '</div>';
}

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
