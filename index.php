<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
 * Copyright (C) 2025      Eric Seigne <eric.seigne@cap-rel.fr>
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
 *	\file       smartauth/index.php
 *	\ingroup    smartauth
 *	\brief      Dashboard page for SmartAuth monitoring
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array("smartauth@smartauth"));

$action = GETPOST('action', 'aZ09');

// Security check
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("SmartAuthDashboard"), '', '', 0, 0, [], [], [], 'mod-smartauth page-index');

print load_fiche_titre($langs->trans("SmartAuthDashboard"), '', 'smartauth.png@smartauth');

// Quick actions bar
print '<div class="tabsAction">';
// print '<a class="butAction" href="'.DOL_URL_ROOT.'/custom/smartauth/auth_list.php">'.$langs->trans("ViewAllTokens").'</a>';
// print '<a class="butAction" href="'.DOL_URL_ROOT.'/custom/smartauth/logs_list.php">'.$langs->trans("ViewLogs").'</a>';
print '</div>';

print '<div class="fichecenter">';

// ============================================================================
// KPI BOXES (Top row)
// ============================================================================

// Get statistics
$stats = getDashboardStats($db);

// KPI Cards Row
print '<div class="div-table-responsive-no-min" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">';

// Card 1: Active Tokens
print '<div class="box-flex-item" style="flex: 1;">';
print '<div class="info-box info-box-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px;">';
print '<div class="info-box-icon" style="font-size: 40px; opacity: 0.3; float: right;"><i class="fa fa-key"></i></div>';
print '<div class="info-box-content">';
print '<span class="info-box-text" style="color: rgba(255,255,255,0.8); font-size: 12px; text-transform: uppercase;">'.dol_escape_htmltag($langs->trans("ActiveTokens")).'</span>';
print '<span class="info-box-number" style="font-size: 32px; font-weight: bold;">'.number_format($stats['active_tokens'], 0, ',', ' ').'</span>';
print '<div style="font-size: 11px; margin-top: 5px; opacity: 0.9;">';
$pct_change = $stats['tokens_change_pct'];
$arrow = $pct_change >= 0 ? '↑' : '↓';
$color = $pct_change >= 0 ? '#4ade80' : '#f87171';
print '<span style="color: '.$color.';">'.$arrow.' '.abs($pct_change).'%</span> vs last 7 days';
print '</div>';
print '</div>';
print '</div>';
print '</div>';

// Card 2: Total Users
print '<div class="box-flex-item" style="flex: 1;">';
print '<div class="info-box info-box-sm" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border-radius: 8px;">';
print '<div class="info-box-icon" style="font-size: 40px; opacity: 0.3; float: right;"><i class="fa fa-users"></i></div>';
print '<div class="info-box-content">';
print '<span class="info-box-text" style="color: rgba(255,255,255,0.8); font-size: 12px; text-transform: uppercase;">'.dol_escape_htmltag($langs->trans("ConnectedUsers")).'</span>';
print '<span class="info-box-number" style="font-size: 32px; font-weight: bold;">'.number_format($stats['unique_users'], 0, ',', ' ').'</span>';
print '<div style="font-size: 11px; margin-top: 5px; opacity: 0.9;">';
print 'Access: '.$stats['access_tokens'].' / Refresh: '.$stats['refresh_tokens'];
print '</div>';
print '</div>';
print '</div>';
print '</div>';

// Card 3: Rate Limit Hits
print '<div class="box-flex-item" style="flex: 1;">';
$rate_limit_color = $stats['rate_limit_hits_24h'] > 100 ? 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)' : 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)';
print '<div class="info-box info-box-sm" style="background: '.$rate_limit_color.'; color: white; border-radius: 8px;">';
print '<div class="info-box-icon" style="font-size: 40px; opacity: 0.3; float: right;"><i class="fa fa-shield"></i></div>';
print '<div class="info-box-content">';
print '<span class="info-box-text" style="color: rgba(255,255,255,0.8); font-size: 12px; text-transform: uppercase;">'.dol_escape_htmltag($langs->trans("RateLimitHits")).'</span>';
print '<span class="info-box-number" style="font-size: 32px; font-weight: bold;">'.number_format($stats['rate_limit_hits_24h'], 0, ',', ' ').'</span>';
print '<div style="font-size: 11px; margin-top: 5px; opacity: 0.9;">';
print 'Last 24h / '.$stats['blocked_ips'].' IPs blocked';
print '</div>';
print '</div>';
print '</div>';
print '</div>';

// Card 4: Token Refresh Success Rate
print '<div class="box-flex-item" style="flex: 1;">';
$refresh_color = $stats['refresh_success_rate'] > 95 ? 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)' : 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)';
print '<div class="info-box info-box-sm" style="background: '.$refresh_color.'; color: white; border-radius: 8px;">';
print '<div class="info-box-icon" style="font-size: 40px; opacity: 0.3; float: right;"><i class="fa fa-sync"></i></div>';
print '<div class="info-box-content">';
print '<span class="info-box-text" style="color: rgba(255,255,255,0.8); font-size: 12px; text-transform: uppercase;">'.dol_escape_htmltag($langs->trans("RefreshSuccessRate")).'</span>';
print '<span class="info-box-number" style="font-size: 32px; font-weight: bold;">'.number_format($stats['refresh_success_rate'], 1).'%</span>';
print '<div style="font-size: 11px; margin-top: 5px; opacity: 0.9;">';
print $stats['refresh_count_24h'].' refreshes in 24h';
print '</div>';
print '</div>';
print '</div>';
print '</div>';

print '</div>'; // End KPI row

// ============================================================================
// SECURITY ALERTS SECTION
// ============================================================================

$alerts = getSecurityAlerts($db);
if (!empty($alerts)) {
	print '<div class="info-box" style="margin-bottom: 20px; padding: 15px; border-left: 4px solid #f87171; background: #fef2f2;">';
	print '<div style="display: flex; align-items: center;">';
	print '<i class="fa fa-exclamation-triangle" style="font-size: 24px; color: #f87171; margin-right: 15px;"></i>';
	print '<div style="flex: 1;">';
	print '<strong style="color: #dc2626;">'.count($alerts).' '.dol_escape_htmltag($langs->trans("SecurityAlertsDetected")).'</strong>';
	print '<div style="margin-top: 8px;">';

	foreach ($alerts as $alert) {
		$icon = getAlertIcon($alert['type']);
		print '<div style="margin: 5px 0; padding: 8px; background: white; border-radius: 4px; font-size: 13px;">';
		print $icon.' <strong>'.dol_escape_htmltag($alert['title']).'</strong>: '.dol_escape_htmltag($alert['message']);
		if (!empty($alert['link'])) {
			print ' <a href="'.$alert['link'].'" style="color: #2563eb;">'.dol_escape_htmltag($langs->trans("ViewDetails")).' →</a>';
		}
		print '</div>';
	}

	print '</div>';
	print '</div>';
	print '</div>';
	print '</div>';
}

// ============================================================================
// TOP USERS TABLE
// ============================================================================

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("TopUsers").'</th>';
print '<th class="center">'.$langs->trans("ActiveTokens").'</th>';
print '<th class="center">'.$langs->trans("Devices").'</th>';
print '<th class="center">'.$langs->trans("LastActivity").'</th>';
print '<th class="right">'.$langs->trans("Actions").'</th>';
print '</tr>';

$top_users = getTopUsers($db, 10);
if (!empty($top_users)) {
	foreach ($top_users as $i => $user_stat) {
		print '<tr class="oddeven">';

		// User name with avatar
		print '<td>';
		if ($user_stat['user_id'] > 0) {
			$tmpuser = new User($db);
			$tmpuser->fetch($user_stat['user_id']);
			print $tmpuser->getNomUrl(1, '', 0, 0, 24, 0, 'login');
			print ' <span class="opacitymedium">('.$tmpuser->email.')</span>';
		} else {
			print '<span class="opacitymedium">'.$langs->trans("Unknown").'</span>';
		}
		print '</td>';

		// Token count with badge
		print '<td class="center">';
		$badge_color = $user_stat['token_count'] > 5 ? 'badge-warning' : 'badge-status4';
		print '<span class="badge '.$badge_color.'">'.$user_stat['token_count'].'</span>';
		print '</td>';

		// Devices count
		print '<td class="center">';
		print $user_stat['device_count'] > 0 ? $user_stat['device_count'] : '-';
		print '</td>';

		// Last activity (relative time)
		print '<td class="center">';
		if ($user_stat['last_activity']) {
			$delay = dol_now() - $user_stat['last_activity'];
			if ($delay < 3600) {
				print '<span style="color: #10b981;">'.dol_escape_htmltag($langs->trans("ActiveNow")).'</span>';
			} elseif ($delay < 86400) {
				print dol_print_date($user_stat['last_activity'], '%H:%M');
			} else {
				print dol_print_date($user_stat['last_activity'], 'day');
			}
		} else {
			print '-';
		}
		print '</td>';

		// Actions
		print '<td class="right">';
		print '<a href="'.DOL_URL_ROOT.'/custom/smartauth/auth_list.php?search_user='.$user_stat['user_id'].'" class="butAction" style="padding: 5px 10px; margin: 0;">'.dol_escape_htmltag($langs->trans("ViewTokens")).'</a>';
		print '</td>';

		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans("NoData").'</td></tr>';
}

print '</table>';
print '</div>';

// ============================================================================
// RIGHT COLUMN - Recent activity & Quick stats
// ============================================================================
print '</div>'; // End fichecenter

print '<div class="fichehalfleft">';
// Quick Links
print '<div class="info-box" style="padding: 5px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">';
print '<div style="font-weight: bold; margin-bottom: 10px; color: #374151;">';
print '<i class="fa fa-link"></i> '.$langs->trans("QuickLinks");
print '</div>';
print '<div style="display: flex; flex-direction: column; gap: 8px;">';
print '<a href="'.DOL_URL_ROOT.'/custom/smartauth/auth_list.php" class="valignmiddle" style="padding: 8px; background: white; border-radius: 4px; display: block;">';
print '<i class="fa fa-list"></i> '.$langs->trans("ManageTokens");
print '</a>';
print '<a href="'.DOL_URL_ROOT.'/custom/smartauth/logs_list.php" class="valignmiddle" style="padding: 8px; background: white; border-radius: 4px; display: block;">';
print '<i class="fa fa-file-text"></i> '.$langs->trans("ViewLogs");
print '</a>';
print '<a href="'.DOL_URL_ROOT.'/custom/smartauth/admin/setup.php" class="valignmiddle" style="padding: 8px; background: white; border-radius: 4px; display: block;">';
print '<i class="fa fa-cog"></i> '.$langs->trans("Configuration");
print '</a>';
print '</div>';
print '</div>';

print '</div>'; //End fichehalfleft

print '<div class="fichehalfright">';


// Recent Rate Limit Blocks
print '<div class="div-table-responsive" style="margin-bottom: 20px;">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="3">'.$langs->trans("RecentRateLimitBlocks").'</th>';
print '</tr>';

$recent_blocks = getRecentRateLimitBlocks($db, 5);
if (!empty($recent_blocks)) {
	foreach ($recent_blocks as $block) {
		print '<tr class="oddeven">';
		print '<td style="width: 40%;">';
		print '<i class="fa fa-ban" style="color: #f87171;"></i> ';
		print '<code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;">'.dol_escape_htmltag($block['ip']).'</code>';
		print '</td>';
		print '<td style="width: 30%;">';
		print '<span class="opacitymedium">'.dol_escape_htmltag($block['action']).'</span>';
		print '</td>';
		print '<td class="right" style="width: 30%;">';
		$time_ago = dol_now() - $block['attempt_time'];
		if ($time_ago < 60) {
			print $langs->trans("JustNow");
		} elseif ($time_ago < 3600) {
			print floor($time_ago / 60).' min';
		} else {
			print floor($time_ago / 3600).' h';
		}
		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="3" class="opacitymedium center">'.$langs->trans("NoRecentBlocks").'</td></tr>';
}

print '</table>';
print '</div>';

// Token Families Stats
print '<div class="div-table-responsive" style="margin-bottom: 20px;">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">'.$langs->trans("TokenFamiliesStats").'</th>';
print '</tr>';

$family_stats = getTokenFamilyStats($db);

print '<tr class="oddeven">';
print '<td>'.$langs->trans("ActiveFamilies").'</td>';
print '<td class="right"><strong>'.$family_stats['active_families'].'</strong></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("RevokedFamilies").'</td>';
print '<td class="right">';
if ($family_stats['revoked_families'] > 0) {
	print '<span style="color: #f87171;"><strong>'.$family_stats['revoked_families'].'</strong></span>';
} else {
	print '<span class="opacitymedium">0</span>';
}
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("AverageRefreshCount").'</td>';
print '<td class="right">'.number_format($family_stats['avg_refresh_count'], 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("ReplayAttacksDetected").'</td>';
print '<td class="right">';
if ($family_stats['revoked_families'] > 0) {
	print '<span style="color: #dc2626;"><strong>'.$family_stats['revoked_families'].'</strong> <i class="fa fa-exclamation-triangle"></i></span>';
} else {
	print '<span style="color: #10b981;">0 <i class="fa fa-check"></i></span>';
}
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '</div>'; // End fichehalfright


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get dashboard statistics
 */
function getDashboardStats($db)
{
	$stats = [
		'active_tokens' => 0,
		'access_tokens' => 0,
		'refresh_tokens' => 0,
		'unique_users' => 0,
		'rate_limit_hits_24h' => 0,
		'blocked_ips' => 0,
		'refresh_count_24h' => 0,
		'refresh_success_rate' => 0,
		'tokens_change_pct' => 0
	];

	// Active tokens count
	$sql = "SELECT COUNT(*) as count, token_type";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
	$sql .= " WHERE status = 1";
	$sql .= " AND date_eol > '".$db->idate(dol_now())."'";
	$sql .= " GROUP BY token_type";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if ($obj->token_type == 'access') {
				$stats['access_tokens'] = (int)$obj->count;
			} elseif ($obj->token_type == 'refresh') {
				$stats['refresh_tokens'] = (int)$obj->count;
			}
			$stats['active_tokens'] += (int)$obj->count;
		}
	}

	// Unique users with active tokens
	$sql = "SELECT COUNT(DISTINCT fk_authid) as count";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
	$sql .= " WHERE status = 1";
	$sql .= " AND auth_element = 'user'";
	$sql .= " AND date_eol > '".$db->idate(dol_now())."'";

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$stats['unique_users'] = (int)$obj->count;
	}

	// Rate limit hits last 24h
	if ($db->type == 'mysqli') {
		$sql = "SELECT COUNT(*) as count, COUNT(DISTINCT identifier) as blocked_ips";
		$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_ratelimit";
		$sql .= " WHERE attempt_time > ".(time() - 86400);
		$sql .= " AND success = 0";

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$stats['rate_limit_hits_24h'] = (int)$obj->count;
			$stats['blocked_ips'] = (int)$obj->blocked_ips;
		}
	}

	// Refresh stats
	if ($db->type == 'mysqli') {
		$sql = "SELECT COUNT(*) as total,";
		$sql .= " SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful";
		$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_ratelimit";
		$sql .= " WHERE action = 'token_refresh'";
		$sql .= " AND attempt_time > ".(time() - 86400);

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$stats['refresh_count_24h'] = (int)$obj->total;
			if ($obj->total > 0) {
				$stats['refresh_success_rate'] = ($obj->successful / $obj->total) * 100;
			}
		}
	}

	// Token change percentage (vs last 7 days)
	$sql = "SELECT COUNT(*) as count";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
	$sql .= " WHERE date_creation > '".$db->idate(dol_now() - (7 * 86400))."'";
	$sql .= " AND date_creation <= '".$db->idate(dol_now() - (14 * 86400))."'";

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$last_week = (int)$obj->count;

		$sql2 = "SELECT COUNT(*) as count";
		$sql2 .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
		$sql2 .= " WHERE date_creation > '".$db->idate(dol_now() - (7 * 86400))."'";

		$resql2 = $db->query($sql2);
		if ($resql2) {
			$obj2 = $db->fetch_object($resql2);
			$this_week = (int)$obj2->count;

			if ($last_week > 0) {
				$stats['tokens_change_pct'] = round((($this_week - $last_week) / $last_week) * 100, 1);
			}
		}
	}

	return $stats;
}

/**
 * Get security alerts
 */
function getSecurityAlerts($db)
{
	$alerts = [];

	// Check for revoked token families (replay attacks)
	$sql = "SELECT COUNT(*) as count";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_token_family";
	$sql .= " WHERE revoked = 1";
	$sql .= " AND last_refresh_at > ".(time() - 86400);

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj->count > 0) {
			$alerts[] = [
				'type' => 'replay_attack',
				'title' => 'Replay Attack Detected',
				'message' => $obj->count.' token families revoked in last 24h due to suspicious activity',
				'link' => DOL_URL_ROOT.'/custom/smartauth/auth_list.php?search_status=9'
			];
		}
	}

	// Check for excessive rate limiting
	if ($db->type == 'mysqli') {
		$sql = "SELECT identifier, COUNT(*) as attempts";
		$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_ratelimit";
		$sql .= " WHERE attempt_time > ".(time() - 3600);
		$sql .= " AND success = 0";
		$sql .= " GROUP BY identifier";
		$sql .= " HAVING attempts > 50";
		$sql .= " ORDER BY attempts DESC LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$alerts[] = [
				'type' => 'ddos',
				'title' => 'Potential DDoS Attack',
				'message' => 'IP '.$obj->identifier.' made '.$obj->attempts.' failed attempts in last hour',
				'link' => ''
			];
		}
	}

	// Check for multiple failed logins on same account
	if ($db->type == 'mysqli') {
		$sql = "SELECT identifier, COUNT(*) as attempts";
		$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_ratelimit";
		$sql .= " WHERE attempt_time > ".(time() - 3600);
		$sql .= " AND action = 'login_username'";
		$sql .= " AND success = 0";
		$sql .= " GROUP BY identifier";
		$sql .= " HAVING attempts > 10";

		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$alerts[] = [
				'type' => 'brute_force',
				'title' => 'Brute Force Attempt',
				'message' => 'Account "'.$obj->identifier.'" has '.$obj->attempts.' failed login attempts',
				'link' => ''
			];
		}
	}

	return $alerts;
}

/**
 * Get alert icon
 */
function getAlertIcon($type)
{
	$icons = [
		'replay_attack' => '<i class="fa fa-exclamation-triangle" style="color: #dc2626;"></i>',
		'ddos' => '<i class="fa fa-bolt" style="color: #ea580c;"></i>',
		'brute_force' => '<i class="fa fa-lock" style="color: #d97706;"></i>'
	];

	return $icons[$type] ?? '<i class="fa fa-info-circle" style="color: #2563eb;"></i>';
}

/**
 * Get top users by token count
 */
function getTopUsers($db, $limit = 10)
{
	$users = [];

	$sql = "SELECT fk_authid as user_id,";
	$sql .= " COUNT(*) as token_count,";
	$sql .= " COUNT(DISTINCT ip) as device_count,";
	$sql .= " MAX(date_lastused) as last_activity";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
	$sql .= " WHERE status = 1";
	$sql .= " AND auth_element = 'user'";
	$sql .= " AND date_eol > '".$db->idate(dol_now())."'";
	$sql .= " GROUP BY fk_authid";
	$sql .= " ORDER BY token_count DESC";
	$sql .= " LIMIT ".(int)$limit;

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$users[] = [
				'user_id' => (int)$obj->user_id,
				'token_count' => (int)$obj->token_count,
				'device_count' => (int)$obj->device_count,
				'last_activity' => $db->jdate($obj->last_activity)
			];
		}
	}

	return $users;
}

/**
 * Get recent rate limit blocks
 */
function getRecentRateLimitBlocks($db, $limit = 5)
{
	$blocks = [];

	if ($db->type != 'mysqli') {
		return $blocks;
	}

	$sql = "SELECT identifier as ip, action, MAX(attempt_time) as attempt_time";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_ratelimit";
	$sql .= " WHERE success = 0";
	$sql .= " AND attempt_time > ".(time() - 3600);
	$sql .= " GROUP BY identifier, action";
	$sql .= " ORDER BY attempt_time DESC";
	$sql .= " LIMIT ".(int)$limit;

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$blocks[] = [
				'ip' => $obj->ip,
				'action' => $obj->action,
				'attempt_time' => (int)$obj->attempt_time
			];
		}
	}

	return $blocks;
}

/**
 * Get token family statistics
 */
function getTokenFamilyStats($db)
{
	$stats = [
		'active_families' => 0,
		'revoked_families' => 0,
		'avg_refresh_count' => 0
	];

	$sql = "SELECT";
	$sql .= " SUM(CASE WHEN revoked = 0 THEN 1 ELSE 0 END) as active,";
	$sql .= " SUM(CASE WHEN revoked = 1 THEN 1 ELSE 0 END) as revoked,";
	$sql .= " AVG(refresh_count) as avg_refresh";
	$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_token_family";

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$stats['active_families'] = (int)$obj->active;
		$stats['revoked_families'] = (int)$obj->revoked;
		$stats['avg_refresh_count'] = (float)$obj->avg_refresh;
	}

	return $stats;
}

// End of page
llxFooter();
$db->close();