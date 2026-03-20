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
 * \file    smartauth/admin/setup.php
 * \ingroup smartauth
 * \brief   Smartauth setup page.
 */

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
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/smartauth.lib.php';
//require_once "../class/myclass.class.php";

// Translations
$langs->loadLangs(array("admin", "smartauth@smartauth"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('smartauthsetup', 'globalsetup'));

// Access control
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');    // Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';


$error = 0;
$setupnotempty = 0;



/*
* Actions
*/
$out = "";
$dest = DOL_DATA_ROOT . "/geoipmaxmind/GeoLite2-City.mmdb";
if ($action == "download") {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
    $url = "https://raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-City.mmdb";
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        dol_mkdir($dir);
    }
    if (!file_exists($dest)) {
        $res = getURLContent($url);
        if (is_array($res) && $res['http_code'] == 200) {
            // $content = $res['content'];
            file_put_contents($dest, $res['content']);

            $out = "<p>File is downloaded !</p>";
        }
    }

    if (file_exists($dest)) {
        dol_syslog("smartauth / geoip enable file $dest as geoip source... !");
        dolibarr_set_const($db, 'GEOIP_VERSION', '1');
        dolibarr_set_const($db, 'MAIN_MODULE_GEOIPMAXMIND', '1');
        dolibarr_set_const($db, 'GEOIPMAXMIND_COUNTRY_DATAFILE', $dest);
        $out .= "<p>Configuration is done, GeoIP is enabled !</p>";
    } else {
        dol_syslog("smartauth / geoip error file $dest does not exists !");
    }
} else {
    if (file_exists($dest)) {
        $out .= "<p>Configuration is done, GeoIP is enabled !</p>";
    }
}

/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "geoip";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = smartauthAdminPrepareHead();
print dol_get_fiche_head($head, 'geoip', $langs->trans($page_name), -1, "smartauth@smartauth");

// Setup page goes here
echo '<span class="opacitymedium">' . $langs->trans("SmartauthGeoIPSetupPage") . '</span><br><br>';


if ($action == 'download' || file_exists($dest)) {
    print $out;
} else {
    if (file_exists($dest)) {
        print '<p>' . $langs->trans('SetupGeoIPForSmartAuthFileIsPresent', $dest) . '</p>';
    } else {
        print '<a class="button button-cancel" type="submit" href="' . $_SERVER["PHP_SELF"] . '?action=download">' . $langs->trans('SetupGeoIPForSmartAuth') . '</a>';
    }
}




// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
