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
 * \file    core/lib/smartauth_oauth.lib.php
 * \ingroup smartauth
 * \brief   Library files for OAuth client management
 */

/**
 * Prepare tabs for OAuth client card
 *
 * @param SmartAuthOAuthClient $object OAuth client object
 * @return array Array of tabs
 */
function smartauth_oauth_client_prepare_head($object)
{
	global $langs, $conf;

	$langs->load("smartauth@smartauth");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/smartauth/admin/smartauth_oauth_client_card.php", 1) . '?id=' . $object->id;
	$head[$h][1] = $langs->trans("OAuthClientCard");
	$head[$h][2] = 'card';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'smartauthoauthclient');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'smartauthoauthclient', 'remove');

	return $head;
}

/**
 * Get available OAuth scopes
 *
 * @return array Array of scope => description
 */
function smartauth_oauth_get_available_scopes()
{
	global $langs;

	$langs->load("smartauth@smartauth");

	$scopes = array(
		'openid' => $langs->trans('ScopeOpenID'),
		'profile' => $langs->trans('ScopeProfile'),
		'email' => $langs->trans('ScopeEmail'),
		'groups' => $langs->trans('ScopeGroups'),
		'offline_access' => $langs->trans('ScopeOfflineAccess'),
	);

	// Add custom scopes from ScopeManager registry
	dol_include_once('/smartauth/api/OAuth2/ScopeManager.php');
	if (class_exists('SmartAuth\Api\OAuth2\ScopeManager')) {
		$allDefs = \SmartAuth\Api\OAuth2\ScopeManager::getAllScopeDefinitions();
		foreach ($allDefs as $scope => $info) {
			if (!isset($scopes[$scope])) {
				$scopes[$scope] = $info['description'] ?? $scope;
			}
		}
	}

	return $scopes;
}

/**
 * Get available OAuth grant types
 *
 * @return array Array of grant => description
 */
function smartauth_oauth_get_available_grants()
{
	global $langs;

	$langs->load("smartauth@smartauth");

	return array(
		'authorization_code' => $langs->trans('GrantAuthorizationCode'),
		'refresh_token' => $langs->trans('GrantRefreshToken'),
		'client_credentials' => $langs->trans('GrantClientCredentials'),
	);
}
