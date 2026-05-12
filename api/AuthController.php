<?php

/**
 * AuthController.php
 *
 * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api;

dol_include_once('/smartauth/api/tools.php');
dol_include_once('/smartauth/class/smartauthdevices.class.php');
dol_include_once('/smartauth/class/smartauthuserdevice.class.php');

use User;
use Exception;
use SmartAuth;
use SmartAuthDevices;
use SmartAuthUserDevice;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Api\InputSanitizer;
use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\JwtKeyHelper;
use Firebase\JWT\SignatureInvalidException;

class AuthController
{
	// rate limiter
	const SMARTAUTH_RATELIMIT_IP_MAX = 10;
	const SMARTAUTH_RATELIMIT_IP_WINDOW = 300; // 5 min

	// Strict pour username (protéger comptes)
	const SMARTAUTH_RATELIMIT_USER_MAX = 5;
	const SMARTAUTH_RATELIMIT_USER_WINDOW = 900; // 15 min


	const STATUS_DRAFT = 0;
	const STATUS_VALID = 1;
	const STATUS_LOGOUT = 9;

	/**
	 * @api {get} /index List available entities
	 * @apiName GetEntities
	 * @apiGroup Auth
	 * @apiVersion 1.0.0
	 *
	 * @apiDescription Get the list of available Dolibarr entities.
	 * Use this endpoint before login if your Dolibarr uses the MultiCompany module.
	 * This allows the user to select the correct entity before authentication.
	 *
	 * @apiSuccess {Object[]} entities List of available entities
	 * @apiSuccess {Number} entities.id Entity ID
	 * @apiSuccess {String} entities.label Entity name
	 *
	 * @apiSuccessExample {json} Success-Response:
	 * HTTP/1.1 200 OK
	 * {
	 *     "entities": [
	 *         {"id": 1, "label": "Main Company"},
	 *         {"id": 2, "label": "Branch Office"}
	 *     ]
	 * }
	 */
	public function index($arr = null)
	{
		global $mysoc;
		dol_syslog("SmartAuth Debug smartauth::AuthController : index");
		$ret = [
			'entities' => $this->_api_GetListOfEntities(),
			'socname' => $mysoc->name,
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {get} /ping Check token validity
	 * @apiName Ping
	 * @apiGroup Auth
	 * @apiVersion 1.0.0
	 * @apiDeprecated Use (#Auth:Refresh) instead.
	 *
	 * @apiDescription Check if your token is still valid. Redirects to refresh endpoint.
	 *
	 * @apiHeader {String} Authorization Bearer token
	 * @apiHeader {String} X-DeviceId Unique device identifier
	 */
	public function ping($arr = null)
	{
		dol_syslog("smartauth : Call on SmartAuth::ping deprecated function");
		return $this->refresh($arr);
	}

	/**
	 * @api {get} /refresh Refresh tokens
	 * @apiName Refresh
	 * @apiGroup Auth
	 * @apiVersion 1.0.0
	 *
	 * @apiDescription Use the refresh token to obtain a new access token and refresh token pair.
	 * The current refresh token is invalidated after use (token rotation).
	 *
	 * @apiHeader {String} Authorization Bearer refresh_token (format: token_id|jwt)
	 * @apiHeader {String} X-DeviceId Unique device identifier
	 *
	 * @apiSuccess {String} access_token New JWT access token
	 * @apiSuccess {String} refresh_token New JWT refresh token
	 * @apiSuccess {Number} expires_in Access token lifetime in seconds
	 * @apiSuccess {String} token_type Token type (Bearer)
	 *
	 * @apiSuccessExample {json} Success-Response:
	 * HTTP/1.1 200 OK
	 * {
	 *     "access_token": "123|eyJ0eXAiOiJKV1Q...",
	 *     "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
	 *     "expires_in": 3600,
	 *     "token_type": "Bearer"
	 * }
	 *
	 * @apiError (401) RefreshTokenRequired Refresh token is missing
	 * @apiError (401) InvalidTokenFormat Token format is invalid
	 * @apiError (401) InvalidTokenPayload Token payload is invalid
	 * @apiError (401) SecurityViolation Token replay attack detected, all sessions revoked
	 * @apiError (401) MaxRefreshExceeded Maximum refresh limit reached, login required
	 */
	public function refresh($arr = null)
	{
		global $db, $smartAuthAppID;
		dol_syslog("SmartAuth Debug smartauth::AuthController : refresh");

		// Get refresh token from Authorization header
		$refresh_token = self::_getBearerToken();
		if (empty($refresh_token)) {
			return [['error' => 'Refresh token required'], 401];
		}

		// Parse token
		if (strpos($refresh_token, '|') === false) {
			return [['error' => 'Invalid token format'], 401];
		}

		// Verify signature, expiration, type and status BEFORE looking at any
		// payload claim. Reading jti from an unverified payload would let an
		// attacker poison llx_smartauth_jti_used with a victim's known jti and
		// force the next legitimate refresh to be classified as a replay,
		// triggering family revocation.
		$decoded = $this->_decodeJWT($refresh_token, SmartTokenConfig::TYPE_REFRESH);

		// === REPLAY ATTACK PREVENTION ===
		// jti is now read from the verified payload only.
		$jti = $decoded->jti ?? null;
		if (!empty($jti)) {
			if (!$this->_markJtiAsUsed($jti)) {
				// jti already used = replay attack detected
				dol_syslog("smartauth : REPLAY ATTACK DETECTED on refresh token", LOG_ERR);
				$replayFamilyId = $decoded->family_id ?? '';
				if (!empty($replayFamilyId)) {
					$this->_revokeTokenFamily($replayFamilyId, 'replay_attack_detected');
				}
				return [['error' => 'Security violation detected. Token reuse is not allowed.'], 401];
			}
		}
		// Note: if jti is null, token is old format (pre-jti) - continue with legacy validation

		// Periodic cleanup of old jti entries (1% chance per request to avoid overhead)
		if (mt_rand(1, 100) === 1) {
			$this->_cleanupOldJti();
		}

		// Extract info from JWT
		$login = $decoded->login ?? '';
		$entity = $decoded->entity ?? 0;
		$family_id = $decoded->family_id ?? '';
		$device_id = $decoded->device_id ?? '';

		if (empty($login) || empty($family_id) || empty($device_id)) {
			return [['error' => 'Invalid token payload'], 401];
		}

		// Check token family (detect token replay attacks)
		$family_check = $this->_checkTokenFamily($family_id, $decoded->user_id);
		dol_syslog("SmartAuth _checkTokenFamily returns " . json_encode($family_check));
		if (!$family_check['valid']) {
			dol_syslog("SmartAuth Token family check failed: " . $family_check['reason'], LOG_WARNING);
			// SECURITY: Revoke entire token family on suspicious activity
			$this->_revokeTokenFamily($family_id, 'suspicious activity');
			return [['error' => 'Security violation detected. All sessions revoked.'], 401];
		}

		// Check max refresh count
		if ($decoded->refresh_count >= SmartTokenConfig::MAX_REFRESH_COUNT) {
			dol_syslog("SmartAuth Max refresh count exceeded for token " . $decoded->token_id, LOG_WARNING);
			return [['error' => 'Maximum refresh limit reached. Please login again.'], 401];
		}

		// if (empty($family_id)) {
		// 	$family_id = $this->_createTokenFamily($decoded->user_id);
		// }

		// === TOKEN ROTATION ===
		// Invalidate current refresh token (one-time use)
		$this->_revokeToken($decoded->token_id, 'refresh_used');

		// Generate new token pair
		$new_tokens = $this->_generateTokenPair(
			'user',
			$decoded->user_id,
			$decoded->user_id,
			$login,
			$entity,
			$family_id,
			$device_id
		);

		// Update token family stats
		$this->_updateTokenFamily($family_id, $decoded->refresh_count + 1);

		dol_syslog("SmartAuth Token refreshed successfully for user $login");

		return [[
			'access_token' => $new_tokens['access_token'],
			'refresh_token' => $new_tokens['refresh_token'],
			'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			'token_type' => 'Bearer',
			// Versions are returned on every refresh so the PWA About modal
			// stays accurate even after a long-running session that survived
			// a backend module upgrade. Cf AuthController::login() for the
			// authoritative comment on these fields.
			'app_version' => SmartAuthApp::version(),
			'smartauth_version' => SmartAuthApp::smartauthVersion(),
			'dolibarr_version' => defined('DOL_VERSION') ? (string) DOL_VERSION : '',
		], 200];
	}

	/**
	 * @api {post} /login Login
	 * @apiName PostLogin
	 * @apiGroup Auth
	 * @apiVersion 1.0.0
	 *
	 * @apiDescription Authenticate user with email/password and obtain JWT tokens.
	 * On success, returns both an access token and a refresh token.
	 * Rate limiting is applied per IP and per username.
	 *
	 * @apiHeader {String} X-DeviceId Unique device identifier (UUID or SHA256 hash)
	 * @apiHeader {String} Content-Type Must be application/json
	 *
	 * @apiBody {String} email User email or login
	 * @apiBody {String} password User password
	 * @apiBody {Number} [entity=1] Dolibarr entity ID (for MultiCompany)
	 * @apiBody {Number} [rememberMe=0] Remember me flag
	 *
	 * @apiSuccess {String} user User email or login
	 * @apiSuccess {Number} userid User ID
	 * @apiSuccess {Number} entity Entity ID
	 * @apiSuccess {String} token Access token (legacy, same as access_token)
	 * @apiSuccess {String} access_token JWT access token
	 * @apiSuccess {String} refresh_token JWT refresh token
	 * @apiSuccess {Number} expires_in Access token lifetime in seconds
	 * @apiSuccess {String} token_type Token type (Bearer)
	 * @apiSuccess {Object[]} [devices_choice] List of known devices for this user (if device is new)
	 * @apiSuccess {Number} rememberMe Remember me flag
	 * @apiSuccess {Boolean} must_change_password True if user must change password (first login or temp password)
	 *
	 * @apiSuccessExample {json} Success-Response:
	 * HTTP/1.1 200 OK
	 * {
	 *     "user": "user@example.com",
	 *     "userid": 3,
	 *     "entity": 1,
	 *     "token": "123|eyJ0eXAiOiJKV1Q...",
	 *     "access_token": "123|eyJ0eXAiOiJKV1Q...",
	 *     "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
	 *     "expires_in": 3600,
	 *     "token_type": "Bearer",
	 *     "devices_choice": null,
	 *     "rememberMe": 0,
	 *     "must_change_password": false
	 * }
	 *
	 * @apiError (401) AccessDenied Invalid credentials
	 * @apiError (429) TooManyRequests Rate limit exceeded
	 *
	 * @apiErrorExample {json} Rate-Limit-Response:
	 * HTTP/1.1 429 Too Many Requests
	 * {
	 *     "error": "Too many attempts. Please try again later.",
	 *     "retry_after": 180
	 * }
	 */
	public function login($payload)
	{
		global $db, $conf, $mysoc;
		dol_syslog("SmartAuth Debug smartauth::AuthController : login");
		// dol_syslog("SmartAuth Debug smartauth : AuthController::login : data is " . json_encode($payload));

		$rateLimiter = new RateLimiter($db);
		$ip = $this->get_client_ip();

		// Extract and validate email/login
		$rawLogin = $payload['email'] ?? '';
		if (empty($rawLogin)) {
			// Try old username field for backwards compatibility
			$rawLogin = $payload['username'] ?? '';
		}

		// Sanitize and validate email format. We also normalise to lowercase
		// so that "Admin", "aDmIn", "ADMIN" share the same rate-limit bucket
		$login = $this->_validateAndSanitizeLogin($rawLogin);
		$rateLimitKey = strtolower($login);

		// Check 1: IP-based rate limit (prevent distributed attacks on same IP)
		$ip_limit = $rateLimiter->checkLimit(
			$ip,
			'login_ip',
			$max_attempts = getDolGlobalInt('SMARTAUTH_RATELIMIT_IP_MAX', 10),
			$window_seconds = getDolGlobalInt('SMARTAUTH_RATELIMIT_IP_WINDOW', 300) // 5 minutes
		);

		if (!$ip_limit['allowed']) {
			dol_syslog("SmartAuth Rate limit: IP $ip blocked", LOG_WARNING);
			return [[
				'error' => 'Too many attempts. Please try again later.',
				'retry_after' => $ip_limit['retry_after']
			], 429]; // HTTP 429 Too Many Requests
		}

		// Reject empty login fast.
		// Without this, a request whose login sanitises to '' would skip the
		// per-user rate limit entirely. Even with the IP limit fixed (H-1),
		// flooding the auth endpoint with empty logins consumes only the IP
		// counter and leaks server resources.
		if (empty($login)) {
			$rateLimiter->recordAttempt($ip, 'login_ip', false);
			dol_syslog("SmartAuth login rejected: empty/invalid login from IP $ip", LOG_WARNING);
			return [['error' => 'Invalid credentials'], 401];
		}

		// Check 2: Username-based rate limit (prevent brute force on specific account)
		$login_limit = $rateLimiter->checkLimit(
			$rateLimitKey,
			'login_username',
			$max_attempts = getDolGlobalInt('SMARTAUTH_RATELIMIT_USER_MAX', 5),
			$window_seconds = getDolGlobalInt('SMARTAUTH_RATELIMIT_USER_WINDOW', 900) // 15 minutes
		);

		if (!$login_limit['allowed']) {
			dol_syslog("SmartAuth Rate limit: Username $rateLimitKey blocked", LOG_WARNING);

			// Record IP attempt anyway
			$rateLimiter->recordAttempt($ip, 'login_ip', false);

			return [[
				'error' => 'Too many failed attempts for this account. Please try again later.',
				'retry_after' => $login_limit['retry_after']
			], 429];
		}

		// Record attempts BEFORE authentication
		$rateLimiter->recordAttempt($ip, 'login_ip', false);
		$rateLimiter->recordAttempt($rateLimitKey, 'login_username', false);


		$entity = (int) ($payload['entity'] ?? 1);
		if (empty($payload['entity'])) {
			if (isModEnabled('multicompany')) {
			//search entity for that user ?
			$entity = $this->_findEntityForUser($login);
			} else {
				$entity = 1;
			}
		}

		//waiting for regis answer
		$_SESSION["dol_entity"] = $entity;
		// force current entity but maybe a TODO with transverse mode or ...
		$conf->entity = $entity;
		// dol_syslog("SmartAuth conf avant " . json_encode($conf->multicompany));
		$conf->setValues($db);
		$mysoc->setMysoc($conf);
		// dol_syslog("SmartAuth conf apres " . json_encode($conf->multicompany));

		$pass   = $payload['password'] ?? '';

		//check if login / pass is ok
		include_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';
		$login = checkLoginPassEntity($login, $pass, $entity, ['dolibarr'], 'api');		// Check credentials.
		dol_syslog("SmartAuth Debug smartauth : AuthController::login : checklogin is " . json_encode($login));
		// SECURITY: Use generic error message to prevent user enumeration
		// Detailed reason is logged server-side only
		$genericAuthError = 'Invalid credentials';

		if ($login === '--bad-login-validity--') {
			$login = '';
		}
		if (empty($login)) {
			// Dummy password_verify on the failure path to equalise the
			// response time with the success path - prevents user
			// enumeration via timing.
			password_verify($pass, self::_getDummyBcryptHash());
			dol_syslog("smartauth : AuthController::login : authentication failed (empty login after check)", LOG_WARNING);
			json_reply($genericAuthError, 401);
		}

		$tmpuser = new User($db);
		$resuser = $tmpuser->fetch(0, $login);
		if ($resuser < 0) {
			SmartAuthLogger::debug("smartauth : AuthController::login : fetch by login failed, trying email");
			$resuser = $tmpuser->fetch(0, '', '', 0, -1, $login);
			if ($resuser < 0) {
				dol_syslog("smartauth : AuthController::login : fetch by email also failed", LOG_WARNING);
			}
		}

		// SUCCESS: Reset rate limits
		$rateLimiter->reset($ip, 'login_ip');
		$rateLimiter->reset($rateLimitKey, 'login_username');

		// Record successful attempt
		$rateLimiter->recordAttempt($ip, 'login_ip', true);
		$rateLimiter->recordAttempt($rateLimitKey, 'login_username', true);

		if (!is_object($tmpuser) || empty($tmpuser->id)) {
			dol_syslog("smartauth : AuthController::login : authentication failed (user object invalid)", LOG_WARNING);
			json_reply($genericAuthError, 401);
		}

		// Create token family (for tracking refresh chain)
		$family_id = $this->_createTokenFamily($tmpuser->id);

		$device_id = $this->_createDeviceIdIfNeeded($tmpuser->id);

		// Single-session-per-app invariant: a fresh login from this device for
		// this app supersedes any previous session of the same (user, device,
		// app, entity) tuple. Other apps and other devices stay untouched.
		$this->_revokePreviousFamiliesForDeviceApp((int) $tmpuser->id, (int) $device_id, (int) $entity);

		// Generate BOTH tokens
		$tokens = $this->_generateTokenPair('user', $tmpuser->id, $tmpuser->id, $login, $entity, $family_id, $device_id);

		// New-login alert (opt-in via SMARTAUTH_NEW_LOGIN_NOTIFY): if the
		// IP or device is unknown for this user, fire an email so the
		// owner of the account can react quickly. Wrapped in a no-throw
		// helper so a mail failure never breaks the login itself.
		$this->_maybeNotifyNewLogin($tmpuser, $device_id);

		// Renew the hash ?
		// Generate token for user
		$result = $tmpuser->call_trigger('USER_LOGIN', $tmpuser);

		$rememberme  = (int) $payload['rememberMe']  ?? 0;

		dol_syslog("SmartAuth Debug smartauth : AuthController::login : return 200 with user=" . $tmpuser->id); // full debug . ", " . json_encode($tmpuser));
		$userlogin = $tmpuser->email;

		$device_uuid = InputSanitizer::sanitizeUUID($_SERVER['HTTP_X_DEVICEID'] ?? '') ?? '';
		$name = $this->getDeviceName(null, $device_uuid);
		$devices_choice = null;
		dol_syslog("SmartAuth AuthController : device name is $name for uuid=$device_uuid");
		if (empty($name)) {
			$devices_choice = $this->_getAllDevicesForUser($tmpuser->id);
		}

		// New logical-device layer. The technical row in smartauth_devices
		// either already points at a smartauth_user_devices parent (the
		// physical phone has been declared on a previous login of any of
		// this user's PWAs) or it does not. If not, surface the picker so
		// the mobile can ask "which of your devices is this?" and call
		// /account/user-devices/{id}/link or POST /account/user-devices.
		$logicalDeviceContext = $this->_resolveLogicalDeviceContext((int) $tmpuser->id, (int) $device_id, (int) $entity);
		if (!empty($logicalDeviceContext['linked_user_device_id'])) {
			$this->_touchUserDeviceLastseen((int) $logicalDeviceContext['linked_user_device_id']);
		}

		if (empty($tmpuser->email)) {
			$userlogin = $tmpuser->login;
		}

		// Check if user must change password
		$mustChangePassword = false;

		// First login: datepreviouslogin is null
		if (empty($tmpuser->datepreviouslogin)) {
			$mustChangePassword = true;
			SmartAuthLogger::debug("smartauth : first login detected for user " . $tmpuser->id . ", password change required");
		}

		$ret = [
			'user' => $userlogin,
			'userid' => $tmpuser->id,
			'entity' => $entity,
			'token' => $tokens['access_token'], // to be compatible with "old" process
			'access_token' => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			'token_type' => 'Bearer',
			'devices_choice' => $devices_choice,
			// Logical-device picker hints. Mobile clients on the new
			// smartcommon code path consume these two fields and ignore
			// devices_choice; legacy clients keep working unchanged
			// because the legacy field is still populated above.
			'needs_device_pick' => $logicalDeviceContext['needs_device_pick'],
			'existing_user_devices' => $logicalDeviceContext['existing_user_devices'],
			'rememberMe' => $rememberme,
			'must_change_password' => $mustChangePassword,
			// Three versions exposed so the PWA can populate its About modal
			// in one shot, without an extra round-trip per module.
			//   - app_version       : version of the host module (read from
			//                         $smartAuthApp->version when the module
			//                         exposes its DolibarrModules instance)
			//   - smartauth_version : version of smartauth itself
			//   - dolibarr_version  : version of the underlying Dolibarr core
			'app_version' => SmartAuthApp::version(),
			'smartauth_version' => SmartAuthApp::smartauthVersion(),
			'dolibarr_version' => defined('DOL_VERSION') ? (string) DOL_VERSION : '',
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {post} /logout Logout
	 * @apiName PostLogout
	 * @apiGroup Auth
	 * @apiVersion 1.0.0
	 *
	 * @apiDescription Logout the user and revoke all tokens in the current token family.
	 * This invalidates both access and refresh tokens.
	 *
	 * @apiHeader {String} Authorization Bearer access_token
	 * @apiHeader {String} X-DeviceId Unique device identifier
	 *
	 * @apiSuccess {String} user Empty string (logged out)
	 * @apiSuccess {String} token Empty string (revoked)
	 *
	 * @apiSuccessExample {json} Success-Response:
	 * HTTP/1.1 200 OK
	 * {
	 *     "user": "",
	 *     "token": ""
	 * }
	 */
	public function logout($payload)
	{
		global $db;
		$user = $payload['user'];
		// dol_syslog("SmartAuth Debug smartauth::AuthController : logout for " . json_encode($payload));
		// RouteController populates the payload with 'jwt_family_id' (and
		// 'jwt_token_id', 'jwt_device_id'), not 'family_id'. The previous
		// code read 'family_id' which is never set, so logout silently
		// revoked nothing.
		$familyId = $payload['jwt_family_id'] ?? $payload['family_id'] ?? '';
		if (!empty($familyId)) {
			dol_syslog("SmartAuth Debug smartauth::AuthController : logout for " . $user->id . ", tokenFamily id=" . $familyId);
			$this->_revokeTokenFamily($familyId, 'logout');
		} else {
			dol_syslog("SmartAuth AuthController::logout: no jwt_family_id in payload, nothing revoked", LOG_WARNING);
		}
		// if (!empty($payload['token_id'])) {
		// 	dol_syslog("SmartAuth Debug smartauth::AuthController : logout for " . $user->id . ", token id=" . $payload['token_id']);
		// 	$this->_revokeToken($payload['token_id'], 'logout');
		// }

		$result = $user->call_trigger('USER_LOGOUT', $user);

		$ret = [
			'user' => '',
			'token' => ''
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {post} /device Manage device
	 * @apiName PostDevice
	 * @apiGroup Device
	 * @apiVersion 1.0.0
	 *
	 * @apiDescription Manage device association for the authenticated user.
	 * Two use cases:
	 * 1. Same UUID: Update the device name/label
	 * 2. Different UUID: Switch to an existing device (generates new tokens)
	 *
	 * @apiHeader {String} Authorization Bearer access_token
	 * @apiHeader {String} X-DeviceId Current device UUID
	 *
	 * @apiBody {String} uuid Device UUID to associate
	 * @apiBody {String} [label] Device name/label (for naming the device)
	 *
	 * @apiSuccess {String} [message] Status message
	 * @apiSuccess {String} [token] New access token (if device switched)
	 * @apiSuccess {String} [access_token] New access token (if device switched)
	 * @apiSuccess {String} [refresh_token] New refresh token (if device switched)
	 * @apiSuccess {Number} [expires_in] Token lifetime in seconds (if device switched)
	 * @apiSuccess {String} [token_type] Token type (if device switched)
	 *
	 * @apiSuccessExample {json} Success-Response (same device, name updated):
	 * HTTP/1.1 200 OK
	 * {
	 *     "message": "update device name : success"
	 * }
	 *
	 * @apiSuccessExample {json} Success-Response (device switched):
	 * HTTP/1.1 200 OK
	 * {
	 *     "token": "125|eyJ0eXAiOiJKV1Q...",
	 *     "access_token": "125|eyJ0eXAiOiJKV1Q...",
	 *     "refresh_token": "126|eyJ0eXAiOiJKV1Q...",
	 *     "expires_in": 3600,
	 *     "token_type": "Bearer",
	 *     "message": "please use this new token"
	 * }
	 */
	public function device($payload = null)
	{
		global $db;
		dol_syslog("SmartAuth Debug smartauth::AuthController : device"); // full debug, payload = " . json_encode($payload));

		$result = "error";

		$token = self::_getBearerToken();
		$decoded = self::_decodeJWT($token, SmartTokenConfig::TYPE_ACCESS);

		$current_uuid = InputSanitizer::sanitizeUUID($_SERVER['HTTP_X_DEVICEID'] ?? '') ?? '';
		$new_uuid = InputSanitizer::sanitizeUUID($payload['uuid'] ?? '') ?? '';
		$new_name = InputSanitizer::sanitizeString($payload['label'] ?? '', 100);

		$ret = null;

		// dol_syslog("smartauth device payload=" . json_encode($payload));
		// dol_syslog("smartauth device current = $current_uuid and new = $new_uuid");

		//first case : same uuid, update device name
		if ($current_uuid == $new_uuid) {
			if ($new_name != "") {
				$device = new SmartAuthDevices($db);
				if ($device->fetch(null, null, $new_uuid)) {
					$user = $payload['user'];
					$device->label = $new_name;
					$result = $device->update($user);
					$message = "update device name : success";
					if ($result) {
						SmartAuthLogger::debug("smartauth::AuthController : device update ok call validate");
						$message = "";
						$device->validate($user);

						// $device->entity is not always hydrated by fetch();
						// pull the canonical entity from the row itself.
						$entSql = "SELECT entity FROM " . MAIN_DB_PREFIX . "smartauth_devices WHERE rowid = " . (int) $device->id;
						$entRes = $db->query($entSql);
						$entRow = $entRes ? $db->fetch_object($entRes) : null;
						$deviceEntity = $entRow !== null ? (int) $entRow->entity : (int) ($user->entity ?? 1);

						// Logical-device sync: the previous behaviour was to
						// collapse every sibling row that shared this label
						// (winner takes all). It killed cross-app sessions
						// every time the user renamed a device. Now the
						// rename is recorded into the new logical layer
						// instead: find or create the matching user_device
						// row and attach this technical device to it. The
						// label on llx_smartauth_devices is kept in sync so
						// legacy queries still see the label.
						$this->_syncLogicalDeviceForRenamedTechnical(
							(int) $user->id,
							(int) $device->id,
							(string) $new_name,
							$deviceEntity
						);
					}
					$ret = [
						'message' => $message,
					];
				} else {
					SmartAuthLogger::debug("smartauth::AuthController : success but device name is empty");
					$ret = [
						'message' => "success but device name is empty",
					];
				}
			} else {
				SmartAuthLogger::debug("smartauth::AuthController : success same device");
				$ret = [
					'message' => "success, same device",
				];
			}
		} else {
			SmartAuthLogger::debug("smartauth::AuthController : device user choice an existing device current_uuid=$current_uuid and new_uuid=$new_uuid");
			//user choosed an other key ... need to delete current key and make a new one
			$user = $payload['user'];

			//revoke temporary tokens - sorry for them
			$this->_revokeTokenFamily($decoded->family_id, 'choice an other existing device');

			// Create token family (for tracking refresh chain)
			$family_id = $this->_createTokenFamily($user->id);

			//use uuid user choosed
			$device_id = $this->_createDeviceIdIfNeeded($user->id, $new_uuid);

			// Generate BOTH tokens
			$tokens = $this->_generateTokenPair('user', $user->id, $user->id, $user->login, $payload['entity'], $family_id, $device_id, $new_uuid);

			$ret = [
				'token' => $tokens['access_token'], // to be compatible with "old" process
				'access_token' => $tokens['access_token'],
				'refresh_token' => $tokens['refresh_token'],
				'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
				'token_type' => 'Bearer',
				'message' => 'please use this new token',
			];
		}

		return ([$ret, 200]);
	}

	/**
	 * Check if token is correct and valid
	 *
	 * Validates JWT token and ensures:
	 * - Token exists and is not revoked
	 * - Token type is 'access' (not 'refresh')
	 * - Token has not expired
	 * - JWT signature is valid
	 *
	 * @return object{
	 *     login: string,
	 *     token_id: int,
	 *     user_id: int,
	 *     entity: int,
	 *     token_type: string,
	 *     family_id: int,
	 *     device_id: int,
	 *     refresh_count: int,
	 *     exp: int
	 * }|null Decoded token payload object with user info, or null on failure (calls json_reply on error)
	 */
	public static function check()
	{
		global $db, $smartAuthAppID, $conf;

		$token = self::_getBearerToken() ?? '';
		$tokenparts = explode('|', $token);
		$token_id = $tokenparts[0];

		$decoded = self::_decodeJWT($token, SmartTokenConfig::TYPE_ACCESS);

		if (!is_object($decoded) || empty($decoded)) {
			dol_syslog("smartauth : decoded token is null", LOG_ERR);
			json_reply('Access denied (invalid token payload)', 401);
		}

		// dol_syslog("smartauth : decoded token is $token :: jwt is " . json_encode($decoded));

		// Verify token contains user identification
		// $decoded->login == user auth
		// $decoded->socid == soc auth (for obapi for example)
		if (empty($decoded->login) && empty($decoded->socid)) {
			dol_syslog("smartauth : login/socid not found in token", LOG_ERR);
			json_reply('Access denied (invalid token payload)', 401);
		}

		if (isset($decoded->token_id) && $decoded->token_id > 0) {
			// * 24 * getDolGlobalInt('SMARTAUTH_TOKEN_EOL_DAYS', 30)
			// Update token last used timestamp and refresh expiry
			$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " SET date_lastused = '" . $db->idate(dol_now()) . "',";
			$sql .= " date_eol = '" . $db->idate(dol_now() + SmartTokenConfig::ACCESS_TOKEN_LIFETIME) . "',";
			$sql .= " ip = '" . $db->escape(self::get_client_ip()) . "' ";
			$sql .= " WHERE rowid = " . (int) $decoded->token_id;

			dol_syslog("smartauth : update token last used " . $sql);
			$resql = $db->query($sql);

			if (!$resql) {
				dol_syslog("smartauth : update token failed: " . $db->lasterror(), LOG_ERR);
				json_reply('Access denied', 401);
			}
		}
		return $decoded;
	}


	/**
	 * create a new salt stored into database and a key for thirdpart account
	 *
	 * @param   int  $socid     dolibarr Societe id
	 * @param   strning $socmail Societe emali addr
	 * @param   int  $entity     dolibarr entity
	 *
	 * @return  [type]           [return description]
	 */
	public function newThirdpartKey($socid, $socmail, $entity = 1)
	{
		global $db, $smartAuthAppID;
		dol_syslog("SmartAuth Debug smartauth : AuthController::_newThirdpartKey");

		//remove all other token for that user and that app ?
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET status = " . self::STATUS_LOGOUT;
		$sql .= ", salt = 'xxxxxxxxxx' ";
		$sql .= " WHERE appuid=" . (int) $smartAuthAppID;
		$sql .= " AND fk_authid=" . (int) $socid;
		$sql .= " AND auth_element='societe_account'";
		$sql .= " AND entity=" . (int) $entity;
		$resql = $db->query($sql);

		$useractions = $this->_FetchUserWithRights();

		$family_id = $this->_createTokenFamily($useractions->id);

		$device_id = $this->_createDeviceIdIfNeeded($useractions->id);

		$new_tokens = $this->_generateTokenPair(
			'societe_account',
			$socid,
			$useractions->id,
			$socmail,
			$entity,
			$family_id,
			$device_id
		);

		dol_syslog("SmartAuth Debug smartauth : AuthController::_newThirdpartKey return");
		return $new_tokens;
	}

	private static function _getAuthorizationHeader()
	{
		$headers = null;
		if (isset($_SERVER['Authorization'])) {
			$headers = trim($_SERVER["Authorization"]);
		} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
			$headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
		} elseif (function_exists('apache_request_headers')) {
			$requestHeaders = apache_request_headers();
			$requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));

			if (isset($requestHeaders['Authorization'])) {
				$headers = trim($requestHeaders['Authorization']);
			}
		}

		return $headers;
	}

	private static function _getBearerToken()
	{
		$headers = self::_getAuthorizationHeader();
		dol_syslog("SmartAuth Debug smartauth : _getBearerToken");

		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				$token = $matches[1];

				// Validate token format: must be "numeric_id|jwt_base64"
				// JWT format: base64.base64.base64 (header.payload.signature)
				if (!self::_validateBearerTokenFormat($token)) {
					dol_syslog("SmartAuth Debug smartauth : _getBearerToken invalid token format", LOG_WARNING);
					return null;
				}

				dol_syslog("SmartAuth Debug smartauth : _getBearerToken, valid format, return token");
				return $token;
			}
		}

		dol_syslog("SmartAuth Debug smartauth : _getBearerToken empty headers, return null");
		return null;
	}

	/**
	 * Validate Bearer token format
	 *
	 * Expected format: "token_id|jwt_token"
	 * - token_id: numeric identifier (1-20 digits)
	 * - jwt_token: standard JWT format (base64url.base64url.base64url)
	 *
	 * @param string $token Raw token string
	 * @return bool True if format is valid
	 */
	private static function _validateBearerTokenFormat($token)
	{
		if (empty($token) || !is_string($token)) {
			return false;
		}

		// Check max length to prevent DoS (reasonable limit for JWT)
		if (strlen($token) > 2048) {
			dol_syslog("smartauth : token exceeds maximum length", LOG_WARNING);
			return false;
		}

		// Must contain exactly one pipe separator
		if (substr_count($token, '|') !== 1) {
			return false;
		}

		// Split token into parts
		$parts = explode('|', $token);
		if (count($parts) !== 2) {
			return false;
		}

		list($token_id, $jwt) = $parts;

		// Validate token_id: must be numeric (1-20 digits)
		if (!preg_match('/^\d{1,20}$/', $token_id)) {
			dol_syslog("smartauth : invalid token_id format", LOG_WARNING);
			return false;
		}

		// Validate JWT format: three base64url-encoded parts separated by dots
		// Base64url: A-Z, a-z, 0-9, -, _ (no padding = allowed at end)
		$jwtPattern = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/';
		if (!preg_match($jwtPattern, $jwt)) {
			dol_syslog("smartauth : invalid JWT format", LOG_WARNING);
			return false;
		}

		return true;
	}

	/**
	 * return array with list of entities if multientity is enabled
	 *
	 * @return  [type]  [return description]
	 */
	private function _api_GetListOfEntities()
	{
		global $db;
		$ret = [];

		if (isModEnabled('multicompany')) {
			$sql = "SELECT DISTINCT(rowid), rang, label";
			$sql .= " FROM " . MAIN_DB_PREFIX . "entity";
			$sql .= " WHERE active = 1";
			$sql .= " AND visible = 1";
			$sql .= " ORDER BY rang ASC, rowid ASC";

			$resql = $db->query($sql);
			if ($resql) {
				$i = 0;
				$num_rows = $db->num_rows($resql);
				while ($i < $num_rows) {
					$obj = $db->fetch_object($resql);
					$ret[] = ['id' => $obj->rowid, 'label' => $obj->label];
					$i++;
				}
			}
		}

		//dev time
		// $ret[] = ['id' => 1, 'label' => "CAP-REL"];
		// $ret[] = ['id' => 5, 'label' => "Audit Process"];
		// $ret[] = ['id' => 8, 'label' => "Open-DSI"];
		// $ret[] = ['id' => 9, 'label' => "Vichy"];
		// $ret[] = ['id' => 23, 'label' => "GigaRUN"];

		return $ret;
	}

	/**
	 * return entity for that login
	 *
	 * @return  [type]  [return description]
	 */
	private function _findEntityForUser($login)
	{
		global $db;
		$def = 0;

		if (isModEnabled('multicompany')) {
			$sql = "SELECT DISTINCT(entity)";
			$sql .= " FROM " . MAIN_DB_PREFIX . "usergroup_user";
			$sql .= " WHERE fk_user in(";
			$sql .= "   SELECT rowid ";
			$sql .= "   FROM " . MAIN_DB_PREFIX . "user";
			$sql .= "   WHERE login='" . $db->escape($login) . "'";
			$sql .= "   OR email ='" . $db->escape($login) . "'";
			$sql .= " )";

			$resql = $db->query($sql);
			if ($resql) {
				$i = 0;
				$num_rows = $db->num_rows($resql);
				while ($i < $num_rows) {
					$array = $db->fetch_array($resql);
					return $array[0];
				}
			}
		}
		return 0;
	}

	/**
	 * fetch a dolibarr user and load its rights
	 *
	 * @param   \User|null  current dolibarr user
	 *
	 * @return  \User    	dolibarr user
	 */
	private function _FetchUserWithRights($u = null)
	{
		global $db;
		if (empty($u)) {
			$u = new \User($db);
			$res = $u->fetch(getDolGlobalString('SMARTAUTH_DEFAULT_USER'));
			if ($res <= 0) {
				dol_syslog('opb: error fetching user id #' . getDolGlobalString('SMARTAUTH_DEFAULT_USER'), LOG_ERR);
				//exit ? force stop / exception
				exit(1);
			}
		}
		$u->getrights();
		return $u;
	}

	/**
	 * Return a valid bcrypt hash to be used as a dummy target for
	 * password_verify() on the auth-failure path.
	 *
	 * Computed once per process and cached so the cost is paid only on
	 * the first failed attempt (subsequent calls reuse the same hash).
	 * Must be a syntactically valid bcrypt string, otherwise
	 * password_verify() short-circuits to false instantly and the timing
	 * equalisation is defeated.
	 *
	 * @return string A pre-computed bcrypt hash that no password matches.
	 */
	private static function _getDummyBcryptHash(): string
	{
		static $dummy = null;
		if ($dummy === null) {
			$dummy = password_hash('SmartAuthDummyTimingHash:' . random_bytes(16), PASSWORD_BCRYPT);
		}
		return $dummy;
	}

	/**
	 * Resolve the client IP address.
	 *
	 * Delegates to RouteController::get_client_ip(), which honours the
	 * SMARTAUTH_TRUSTED_PROXIES allow-list. The previous local
	 * implementation accepted CF-Connecting-IP / X-Forwarded-For / X-Real-IP
	 * unconditionally, allowing trivial rate-limit bypass (H-1 of
	 * TODO-SECURITY-01). This facade is kept so existing callers and tests
	 * can keep referencing AuthController::get_client_ip().
	 *
	 * @return string Client IP address (validated format), or '0.0.0.0'
	 */
	public static function get_client_ip()
	{
		return RouteController::get_client_ip();
	}

	/**
	 * Get salt2 for device/app identification with fallback
	 *
	 * Priority:
	 * 1. X-App-ID header (best - unique per device)
	 * 2. User-Agent hash (fallback)
	 *
	 * @return string 16-character salt for key derivation
	 */
	private static function _getSalt2($device_uuid = '')
	{
		// Check for X-DEVICEID header (future-proof for mobile apps)
		if ($device_uuid == '') {
			$device_uuid = InputSanitizer::sanitizeUUID($_SERVER['HTTP_X_DEVICEID'] ?? '') ?? '';
			dol_syslog("smartauth : _getSalt2 debug HTTP_X_DEVICEID : " . $device_uuid);

			if (!empty($device_uuid)) {
				SmartAuthLogger::debug("smartauth : using X-DEVICEID header (hash format) for salt2");
				return substr(hash('sha256', $device_uuid), 0, 16);
			}

			dol_syslog("smartauth : X-DEVICEID empty, falling back to User-Agent", LOG_WARNING);

			// Fallback to User-Agent hash
			$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			return substr(hash('sha256', $userAgent), 0, 16);
		} else {
			dol_syslog("smartauth : _getSalt2 debug deviceid is set from function arg value : " . $device_uuid);
			SmartAuthLogger::debug("smartauth : using deviceid from function arg (hash format) for salt2");
			return substr(hash('sha256', $device_uuid), 0, 16);
		}
	}

	/**
	 * Validate uuid format (UUID or SHA256 hash)
	 *
	 * @param string $uuid identifier
	 * @return bool True if valid format
	 */
	private static function _validateUUID($uuid)
	{
		// Accept UUID format (36 chars with dashes)
		if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
			return true;
		}

		// Accept SHA256 hash format (64 hex chars)
		if (preg_match('/^[a-f0-9]{64}$/i', $uuid)) {
			return true;
		}

		return false;
	}

	/**
	 * Validate and sanitize login/email input
	 *
	 * Uses InputSanitizer for consistent validation across the codebase.
	 * Accepts both email format and plain username (for backwards compatibility)
	 *
	 * @param string $input Raw login/email input
	 * @return string Sanitized login or empty string if invalid
	 */
	private function _validateAndSanitizeLogin($input)
	{
		if (empty($input) || !is_string($input)) {
			return '';
		}

		// Check if it looks like an email
		if (strpos($input, '@') !== false) {
			// Use InputSanitizer for email validation
			$sanitized = InputSanitizer::sanitizeEmail($input);
			if ($sanitized === null) {
				dol_syslog("smartauth : invalid email format for login", LOG_WARNING);
				return '';
			}
			return $sanitized;
		}

		// Plain username: use username sanitization (allows alphanumeric, underscore, hyphen, dot)
		$sanitized = InputSanitizer::sanitizeUsername($input, 255);
		if ($sanitized === null) {
			dol_syslog("smartauth : invalid characters in username", LOG_WARNING);
			return '';
		}

		return $sanitized;
	}


	/**
	 * Create a new token family for tracking refresh chain
	 */
	private function _createTokenFamily($user_id)
	{
		global $db;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " (fk_user, created_at, last_refresh_at)";
		$sql .= " VALUES ( ";
		$sql .= (int) $user_id . ", ";
		$sql .= time() . ", " . time() . ")";

		$db->query($sql);

		$family_rowid = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_token_family");

		return $family_rowid;
	}

	/**
	 * Generate access + refresh token pair
	 *
	 * @param   String  $element     dolibarr element (user or societe_account for the moment)
	 * @param   Int  	$element_id  dolibarr id of element
	 * @param   Int  	$user_id     user creator id
	 * @param   String  $login       login to use for that token
	 * @param   Int  	$entity      dolibarr entity
	 * @param   Int     $family_id   token family
	 * @param   Int 	$device_id   device id (foreign key)
	 * @param   String 	$device_uuid device uuid (in case of previous is null and we don't want to use http header value)
	 *
	 * @return  array               two token (access & refresh)
	 */
	private function _generateTokenPair($element, $element_id, $user_id, $login, $entity, $family_id, $device_id, $device_uuid = '')
	{
		dol_syslog("smartauth : _generateTokenPair element=$element, element_id=$element_id, user_id=$user_id, login=$login, entity=$entity, family_id=$family_id, device_id=$device_id, device_uuid=$device_uuid");
		// Generate access token (short-lived)
		$access_token = $this->_generateToken(
			$element,
			$element_id,
			$user_id,
			$login,
			$entity,
			SmartTokenConfig::TYPE_ACCESS,
			SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			$family_id,
			$device_id,
			$device_uuid
		);

		// Generate refresh token (long-lived)
		$refresh_token = $this->_generateToken(
			$element,
			$element_id,
			$user_id,
			$login,
			$entity,
			SmartTokenConfig::TYPE_REFRESH,
			SmartTokenConfig::REFRESH_TOKEN_LIFETIME,
			$family_id,
			$device_id,
			$device_uuid
		);

		return [
			'access_token' => $access_token,
			'refresh_token' => $refresh_token
		];
	}

	/**
	 * Generate a JWT token and store it in the database
	 *
	 * Creates an authentication token (access or refresh) for a user/element,
	 * stores it in the smartauth_auth table, and returns the formatted token string.
	 *
	 * @param string      $element      The element type being authenticated ('user', 'societe_account', etc.)
	 * @param int         $element_id   The ID of the element (user ID, societe ID, etc.)
	 * @param int         $user_id      The user ID associated with this token
	 * @param string      $login        The login/username for the token payload
	 * @param int         $entity       The entity ID (for multi-company support)
	 * @param string      $token_type   The type of token ('access' or 'refresh')
	 * @param int         $lifetime     Token lifetime in seconds
	 * @param int         $family_id    The token family ID (parent token ID for token rotation)
	 * @param int         $device_id    The device ID associated with this token
	 * @param string|null $device_uuid  Optional device UUID for additional identification
	 *
	 * @return string|null The generated token in format "rowid|jwt_token", or null on failure
	 */
	private function _generateToken($element, $element_id, $user_id, $login, $entity, $token_type, $lifetime, $family_id, $device_id,  $device_uuid = null)
	{
		global $db, $smartAuthAppID;

		dol_syslog("smartauth : _generateToken element=$element, element_id=$element_id, user_id=$user_id, login=$login, entity=$entity, token_type=$token_type, lifetime=$lifetime, family_id=$family_id, device_id=$device_id,  device_uuid=$device_uuid");

		// Validate enum values against whitelist
		$safeElement = ValidationSchemas::validateEnum('auth_element', $element, null);
		if ($safeElement === null) {
			dol_syslog("smartauth : _generateToken invalid element: $element", LOG_ERR);
			return null;
		}

		$safeTokenType = ValidationSchemas::validateEnum('token_type', $token_type, null);
		if ($safeTokenType === null) {
			dol_syslog("smartauth : _generateToken invalid token_type: $token_type", LOG_ERR);
			return null;
		}

		// Sanitize IP address
		$clientIp = InputSanitizer::sanitizeIP($this->get_client_ip()) ?? '0.0.0.0';

		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		$salt2 = $this->_getSalt2($device_uuid); // same as app_id logic but for device

		// Insert token into database
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " (appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, fk_device_id, ";
		$sql .= " auth_element, token_type, family_id, ip, status, entity)";
		$sql .= " VALUES (";
		$sql .= (int) $smartAuthAppID . ", ";
		$sql .= "'" . $salt . "', ";
		$sql .= "'" . $db->idate(dol_now()) . "', ";
		$sql .= "'" . $db->idate(dol_now() + $lifetime) . "', ";
		$sql .= (int) $user_id . ", ";
		$sql .= (int) $element_id . ", ";
		$sql .= (int) $device_id . ", ";
		$sql .= "'" . $db->escape($safeElement) . "', ";
		$sql .= "'" . $db->escape($safeTokenType) . "', ";
		$sql .= ($family_id ? (int) $family_id : "NULL") . ", ";
		$sql .= "'" . $db->escape($clientIp) . "', ";
		$sql .= self::STATUS_VALID . ", ";
		$sql .= (int) $entity . ")";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("SmartAuth Failed to create token: " . $db->lasterror(), LOG_ERR);
			return null;
		}

		$token_id = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
		dol_syslog("SmartAuth _generateToken id=$token_id");

		// Generate unique JWT ID to prevent replay attacks
		$jti = bin2hex(random_bytes(16));

		// Build JWT payload. iss/iat/nbf/typ are added as part of H-14
		// (TODO-SECURITY-01) so that _decodeJWT can perform the standard
		// RFC 7519 / RFC 8725 claim checks on every refresh.
		$now = time();
		$payload = [
			"jti" => $jti,
			"iss" => \SmartAuth\Api\OAuth2\OAuthConfig::getIssuer(),
			"iat" => $now,
			"nbf" => $now,
			"login" => $login,
			"user_id" => $user_id,
			"entity" => $entity,
			"token_type" => $token_type,
			"family_id" => $family_id,
			"device_id" => $device_id,
			"refresh_count" => 0,
			"exp" => $now + $lifetime,
		];

		$key = $salt . $salt2 . JwtKeyHelper::getKey();
		// 'typ' header explicitly set for RFC 8725 §3.11 compliance
		$jwt = JWT::encode($payload, $key, 'HS256', null, ['typ' => 'JWT']);

		// Return token_id|jwt format
		return $token_id . '|' . $jwt;
	}

	/**
	 * Check token family validity (detect replay attacks)
	 */
	private function _checkTokenFamily($family_id, $user_id)
	{
		global $db;
		dol_syslog("smartauth : _checkTokenFamily family_id=$family_id, user_id=$user_id");

		$sql = "SELECT revoked, refresh_count, fk_user";
		$sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " WHERE rowid = '" . $db->escape($family_id) . "'";

		$resql = $db->query($sql);
		if (!$resql || $db->num_rows($resql) == 0) {
			return ['valid' => false, 'reason' => 'family_not_found'];
		}

		$family = $db->fetch_object($resql);

		if ($family->revoked) {
			return ['valid' => false, 'reason' => 'family_revoked'];
		}

		if ($family->fk_user != $user_id) {
			return ['valid' => false, 'reason' => 'user_mismatch'];
		}

		return ['valid' => true];
	}

	/**
	 * Update token family after successful refresh
	 */
	private function _updateTokenFamily($family_id, $new_count)
	{
		global $db;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " SET last_refresh_at = " . time();
		$sql .= ", refresh_count = " . (int) $new_count;
		$sql .= " WHERE rowid = '" . $db->escape($family_id) . "'";

		$db->query($sql);
	}

	/**
	 * Revoke previous active token families for the same (user, device, app, entity)
	 * tuple. Called at login time before issuing a fresh family so that a single
	 * physical device can hold at most one live session per application -- but
	 * remains free to keep concurrent sessions on other SmartMaker apps or on
	 * other physical devices.
	 *
	 * Scope rationale: the scoping key is (fk_user_creat, fk_device_id, appuid,
	 * entity). Filtering on appuid is what keeps capTodo from killing capCRM,
	 * filtering on fk_device_id is what keeps the mobile from killing the web,
	 * and filtering on entity respects Dolibarr multi-company isolation.
	 *
	 * @param   int $user_id    Authenticated user (must match fk_user_creat)
	 * @param   int $device_id  smartauth_devices.rowid resolved from the device UUID
	 * @param   int $entity     Dolibarr entity (multi-company isolation)
	 *
	 * @return  int             Number of families revoked (>=0)
	 */
	private function _revokePreviousFamiliesForDeviceApp($user_id, $device_id, $entity)
	{
		global $db, $smartAuthAppID;

		$user_id = (int) $user_id;
		$device_id = (int) $device_id;
		$entity = (int) $entity;
		$appuid = (int) ($smartAuthAppID ?? 0);

		if ($user_id <= 0 || $device_id <= 0) {
			dol_syslog("SmartAuth _revokePreviousFamiliesForDeviceApp skipped: user_id=$user_id device_id=$device_id", LOG_WARNING);
			return 0;
		}
		// Without an app id we cannot scope the revocation. Bail out rather
		// than risk killing every session of the user on this device.
		if ($appuid <= 0) {
			dol_syslog("SmartAuth _revokePreviousFamiliesForDeviceApp skipped: empty smartAuthAppID", LOG_WARNING);
			return 0;
		}

		$sql = "SELECT DISTINCT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " WHERE fk_user_creat = " . $user_id;
		$sql .= " AND fk_device_id = " . $device_id;
		$sql .= " AND appuid = " . $appuid;
		$sql .= " AND entity = " . $entity;
		$sql .= " AND status = " . self::STATUS_VALID;
		$sql .= " AND family_id IS NOT NULL";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("SmartAuth _revokePreviousFamiliesForDeviceApp select failed: " . $db->lasterror(), LOG_ERR);
			return 0;
		}

		$families = [];
		while ($obj = $db->fetch_object($resql)) {
			$families[] = (int) $obj->family_id;
		}

		foreach ($families as $fid) {
			$this->_revokeTokenFamily($fid, 'replaced_by_new_session');
		}

		if (count($families) > 0) {
			dol_syslog("SmartAuth _revokePreviousFamiliesForDeviceApp revoked " . count($families) . " families for user=$user_id device=$device_id app=$appuid entity=$entity", LOG_INFO);
		}

		return count($families);
	}

	/**
	 * When the user names a device with a label that already belongs to other
	 * devices of his own (same user, same entity), collapse those siblings:
	 *  - revoke ALL their active token families across every SmartMaker app
	 *    (cross-app), because the user just declared that the previous
	 *    "iPhone de Bertrand" physical device no longer exists
	 *  - mark the device rows themselves as STATUS_CANCELED so they no longer
	 *    appear in the "choose your device" picker on next logins
	 *
	 * Cross-app rationale: a CANCELED device with still-valid JWTs in the wild
	 * would be a zombie. The single-label-per-user invariant the caller asked
	 * for ("2 sessions on 'iPhone de Bertrand' is not allowed") only makes
	 * sense if every session bound to the previous physical device dies at
	 * once. The user accepts that re-logging in capCRM/capTodo/etc. is the
	 * cost of renaming a fresh device install with an existing label.
	 *
	 * Note: this is DIFFERENT from _revokePreviousFamiliesForDeviceApp which is
	 * scoped to the current app -- there the user is just relogging on the
	 * same physical device and capCRM/capTodo must keep cohabiting.
	 *
	 * @param   int    $user_id          Authenticated user (fk_user_creat owner)
	 * @param   int    $current_device_id Device id just being (re)named, EXCLUDED from collapse
	 * @param   string $label             Label assigned to the current device (must be non-empty)
	 * @param   int    $entity            Dolibarr entity for multi-company isolation
	 *
	 * @return  int                       Number of sibling devices collapsed
	 */
	private function _collapseDuplicateLabelDevices($user_id, $current_device_id, $label, $entity)
	{
		global $db;

		$user_id = (int) $user_id;
		$current_device_id = (int) $current_device_id;
		$entity = (int) $entity;
		$label = trim((string) $label);

		if ($user_id <= 0 || $current_device_id <= 0 || $label === '') {
			dol_syslog("SmartAuth _collapseDuplicateLabelDevices skipped: user_id=$user_id device_id=$current_device_id label='$label'", LOG_WARNING);
			return 0;
		}

		// Sibling devices: same owner, same entity, same label, different rowid,
		// and not already canceled. Empty labels are excluded so that draft
		// devices created by _createDeviceIdIfNeeded (label '') do not collide.
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_devices";
		$sql .= " WHERE fk_user_creat = " . $user_id;
		$sql .= " AND entity = " . $entity;
		$sql .= " AND rowid != " . $current_device_id;
		$sql .= " AND TRIM(label) = '" . $db->escape($label) . "'";
		$sql .= " AND status != " . \SmartAuthDevices::STATUS_CANCELED;

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("SmartAuth _collapseDuplicateLabelDevices select failed: " . $db->lasterror(), LOG_ERR);
			return 0;
		}

		$siblingIds = [];
		while ($obj = $db->fetch_object($resql)) {
			$siblingIds[] = (int) $obj->rowid;
		}

		if (empty($siblingIds)) {
			return 0;
		}

		// Revoke EVERY active family on each sibling device, regardless of
		// appuid. Without this, capCRM tokens pointing at the now-canceled
		// device would survive until JWT expiry and become unattributable.
		foreach ($siblingIds as $sid) {
			$sql = "SELECT DISTINCT family_id FROM " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " WHERE fk_user_creat = " . $user_id;
			$sql .= " AND fk_device_id = " . $sid;
			$sql .= " AND entity = " . $entity;
			$sql .= " AND status = " . self::STATUS_VALID;
			$sql .= " AND family_id IS NOT NULL";
			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog("SmartAuth _collapseDuplicateLabelDevices select families failed for sid=$sid: " . $db->lasterror(), LOG_ERR);
				continue;
			}
			$families = [];
			while ($obj = $db->fetch_object($resql)) {
				$families[] = (int) $obj->family_id;
			}
			foreach ($families as $fid) {
				$this->_revokeTokenFamily($fid, 'duplicate_label_device_collapsed');
			}
			if (count($families) > 0) {
				dol_syslog("SmartAuth _collapseDuplicateLabelDevices revoked " . count($families) . " cross-app families on sibling device=$sid for user=$user_id", LOG_INFO);
			}
		}

		// Cancel the sibling device rows so the picker no longer offers them.
		// Direct UPDATE rather than SmartAuthDevices::setCanceled() because the
		// latter only accepts transitions from STATUS_VALIDATED and we want to
		// cancel uniformly regardless of the sibling's current state (DRAFT,
		// VALIDATED, ...). Triggers on cancellation are not needed here.
		$canceledCount = 0;
		$idsList = implode(',', array_map('intval', $siblingIds));
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_devices";
		$sql .= " SET status = " . \SmartAuthDevices::STATUS_CANCELED;
		$sql .= " WHERE rowid IN (" . $idsList . ")";
		if ($db->query($sql)) {
			$canceledCount = count($siblingIds);
		} else {
			dol_syslog("SmartAuth _collapseDuplicateLabelDevices cancel UPDATE failed: " . $db->lasterror(), LOG_ERR);
		}

		dol_syslog("SmartAuth _collapseDuplicateLabelDevices collapsed " . $canceledCount . " sibling devices for user=$user_id label='$label' entity=$entity (kept device=$current_device_id)", LOG_INFO);

		return $canceledCount;
	}

	/**
	 * Revoke entire token family, example of reason: security breach detected)
	 *
	 * @param   [type]          $family_id  id of family token
	 * @param   [type]          $reason     reason of revocation
	 *
	 */
	private function _revokeTokenFamily($family_id, $reason = 'family_revoked')
	{
		global $db;

		// Mark family as revoked
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " SET revoked = 1";
		$sql .= " WHERE rowid = '" . $db->escape($family_id) . "'";
		$db->query($sql);

		// Revoke all tokens in this family
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET status = " . self::STATUS_LOGOUT;
		$sql .= ", salt = '" . $db->escape($reason) . "'";
		$sql .= " WHERE family_id = " . (int) $family_id;
		$db->query($sql);

		dol_syslog("SmartAuth Token family $family_id revoked", LOG_INFO);
	}

	/**
	 * Revoke single token
	 */
	private function _revokeToken($token_id, $reason = 'manual')
	{
		global $db;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET status = " . self::STATUS_LOGOUT;
		$sql .= ", salt = '" . $db->escape($reason) . "'";
		$sql .= " WHERE rowid = " . (int) $token_id;

		$resql = $db->query($sql);
		if ($resql) {
			dol_syslog("smartauth : revokeToken success");
		} else {
			dol_syslog("smartauth : revokeToken error", LOG_ERR);
		}
	}

	/**
	 * Mark a JWT ID (jti) as used to prevent replay attacks
	 * This operation is atomic - if the jti already exists, returns false
	 *
	 * @param string $jti The JWT ID to mark as used
	 * @param int|null $token_id Optional token ID for reference
	 * @return bool True if marked successfully (first use), false if already used (replay detected)
	 */
	private function _markJtiAsUsed($jti, $token_id = null)
	{
		global $db;

		// Validate jti format (32 hex characters)
		if (empty($jti) || !preg_match('/^[a-f0-9]{32}$/i', $jti)) {
			dol_syslog("smartauth : _markJtiAsUsed invalid jti format", LOG_ERR);
			return false;
		}

		// Atomic insert - will fail if jti already exists (PRIMARY KEY constraint)
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_jti_used";
		$sql .= " (jti, used_at, token_id)";
		$sql .= " VALUES (";
		$sql .= "'" . $db->escape($jti) . "', ";
		$sql .= time() . ", ";
		$sql .= ($token_id ? (int) $token_id : "NULL") . ")";

		$resql = $db->query($sql);

		if (!$resql) {
			// Insert failed - jti already exists = replay attack
			dol_syslog("smartauth : _markJtiAsUsed REPLAY DETECTED for jti=" . substr($jti, 0, 8) . "...", LOG_WARNING);
			return false;
		}

		dol_syslog("smartauth : _markJtiAsUsed success for jti=" . substr($jti, 0, 8) . "...");
		return true;
	}

	/**
	 * Extract jti from a token without signature verification.
	 *
	 * Low-level utility for diagnostics and unit tests. MUST NOT be used
	 * to drive security decisions: a non-signed payload is attacker
	 * controlled. For replay detection during refresh(), read the jti
	 * from the object returned by _decodeJWT() instead.
	 *
	 * @param string $token The full token in format "token_id|jwt"
	 * @return string|null The jti if found, null otherwise
	 */
	private function _extractJtiFromToken($token)
	{
		if (empty($token) || strpos($token, '|') === false) {
			return null;
		}

		$jwt = substr($token, strpos($token, '|') + 1);
		$parts = explode('.', $jwt);

		if (count($parts) !== 3) {
			return null;
		}

		// Decode payload (middle part) without signature verification
		$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

		if (!$payload || !isset($payload['jti'])) {
			return null;
		}

		return $payload['jti'];
	}

	/**
	 * Cleanup old jti entries to prevent table bloat
	 * Should be called periodically (e.g., via cron or on each refresh)
	 *
	 * @param int $max_age_seconds Maximum age of jti entries to keep (default: 30 days)
	 * @return int Number of entries deleted
	 */
	private function _cleanupOldJti($max_age_seconds = 2592000)
	{
		global $db;

		$cutoff = time() - $max_age_seconds;

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_jti_used";
		$sql .= " WHERE used_at < " . $cutoff;

		$resql = $db->query($sql);

		if ($resql) {
			$deleted = $db->affected_rows($resql);
			if ($deleted > 0) {
				dol_syslog("smartauth : _cleanupOldJti deleted $deleted old entries");
			}
			return $deleted;
		}

		return 0;
	}



	/**
	 * search the rowid of a device thanks to its uuid
	 *
	 * @param   String  $uuid  string of unique id identifier for the device
	 *
	 * @return  int     <= 0 in error, > 0 : rowid on success
	 */
	public static function getDeviceIDFromUUID($uuid)
	{
		global $db, $conf;

		$cache_key = 'device-' . $uuid;
		if (!isset($conf->cache['smartmakers'][$cache_key])) {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " WHERE uuid = '" . $db->escape($uuid) . "'";

			$resql = $db->query($sql);
			$res = -1;
			if ($resql && $obj = $db->fetch_object($resql)) {
				$res = $obj->rowid;
			}
			$conf->cache['smartmakers'][$cache_key] = $res;
		}
		return $conf->cache['smartmakers'][$cache_key];
	}

	/**
	 * search the name of a device
	 *
	 * @return  int     <= 0 in error, > 0 : rowid on success
	 */
	public static function getDeviceName($id = null, $uuid = null)
	{
		global $db, $conf;

		$sql = "SELECT label FROM " . MAIN_DB_PREFIX . "smartauth_devices";
		if (null !== $id) {
			$sql .= " WHERE rowid='" . (int) $id . "'";
		} elseif (null !== $uuid) {
			$sql .= " WHERE uuid='" . $db->escape($uuid) . "'";
		} else {
			return '';
		}

		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			return $obj->label;
		}
		return '';
	}


	/**
	 * get all devices for a user, then user can choose his device or
	 * give a name to the new one
	 *
	 * @param   [type]  $user_id  [$user_id description]
	 *
	 * @return  [type]            [return description]
	 */
	private function _getAllDevicesForUser($user_id)
	{
		global $db;

		$current_uuid = InputSanitizer::sanitizeUUID($_SERVER['HTTP_X_DEVICEID'] ?? '') ?? '';

		$ret = [];
		$sql = "SELECT label, uuid FROM " . MAIN_DB_PREFIX . "smartauth_devices";
		$sql .= " WHERE fk_user_creat = " . (int) $user_id;
		$sql .= " AND label != ''";
		$sql .= " AND status = 1";
		$sql .= " AND entity IN (" . getEntity('user') . ")";
		if ($current_uuid != "") {
			$sql .= " OR ( uuid='" . $db->escape($current_uuid) . "' AND label != '')";
		}
		$sql .= " GROUP BY uuid,label";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				// $ret[] = ['label' => $obj->label, 'uuid' => $obj->uuid];
				$ret[] = $obj;
			}
		}

		//filtrage: si le device uuid a un nom et qu'on a qu'un seul match on return un vide pour éviter d'avoir la popup de choix/nom sur le front
		if (count($ret) == 1 && trim($ret[0]->label) != "") {
			$ret = [];
		}

		SmartAuthLogger::debug('_getAllDevicesForUser returns ' . json_encode($ret));
		return $ret;
	}


	private function _createDeviceIdIfNeeded($user_id, $device_uuid = '')
	{
		global $db, $user, $conf;

		$deviceid = '';

		// Get device UUID from parameter or HTTP header
		if ($device_uuid == '') {
			$raw_uuid = $_SERVER['HTTP_X_DEVICEID'] ?? '';

			// Reject invalid JavaScript values explicitly
			$invalid_values = ['undefined', 'null', 'NaN', 'false', 'true', '0', ''];
			if (in_array($raw_uuid, $invalid_values, true)) {
				dol_syslog("SmartAuth : invalid device UUID value: '$raw_uuid'", LOG_ERR);
				throw new Exception("SmartAuth: invalid device UUID. Please provide a valid UUID or SHA256 hash.");
			}

			// Sanitize and validate UUID format
			$device_uuid = InputSanitizer::sanitizeUUID($raw_uuid);
		} else {
			// Validate UUID passed as parameter
			$device_uuid = InputSanitizer::sanitizeUUID($device_uuid);
		}

		// Reject if UUID is invalid or empty after sanitization
		if (empty($device_uuid)) {
			dol_syslog("SmartAuth : device UUID is missing or invalid format", LOG_ERR);
			throw new Exception("SmartAuth: X-DeviceId header is required and must be a valid UUID (RFC 4122) or SHA256 hash.");
		}

		if ($device_uuid != '') {
			$deviceid = self::getDeviceIDFromUUID($device_uuid);
		}

		if ($deviceid <= 0) {
			// Stamp the device with the active Dolibarr entity. Without this
			// the column took DEFAULT 1 and any login from entity != 1 ended
			// up cross-leaking through every "WHERE entity = X" filter
			// downstream (token issuance, label collapse, picker, etc.).
			$insertEntity = (int) ($conf->entity ?? 1);
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " (uuid, fk_user_creat, date_creation, status, entity)";
			$sql .= " VALUES ('" . substr($db->escape($device_uuid), 0, 64) . "', ";
			$sql .= (int) $user_id . ", ";
			$sql .= "'" . $db->idate(time()) . "', " . self::STATUS_DRAFT . ", ";
			$sql .= $insertEntity . ")";

			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog("SmartAuth Failed to create device: " . $db->lasterror(), LOG_ERR);
				throw new Exception("Failed to create device: " . $db->lasterror());
			}

			$rowid = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
			if ($rowid > 0) {
				// Invalidate cache for this UUID
				$cache_key = 'device-' . $device_uuid;
				$conf->cache['smartmakers'][$cache_key] = $rowid;
				return $rowid;
			}
		}

		return $deviceid;
	}




	/**
	 * Decode and validate a JWT token
	 *
	 * Decodes the JWT token, validates its signature and expiration,
	 * verifies it exists in the database with valid status, and updates
	 * usage statistics (last used date, IP address, EOL).
	 *
	 * @param string $token     The JWT token to decode (format: "rowid|jwt_token")
	 * @param string $checktype The expected token type to validate ('access' or 'refresh')
	 *
	 * @return object{
	 *     login: string,
	 *     token_id: int,
	 *     user_id: int,
	 *     entity: int,
	 *     token_type: string,
	 *     family_id: int,
	 *     device_id: int,
	 *     refresh_count: int,
	 *     exp: int
	 * }|null Decoded token payload object with user info, or null on failure (calls json_reply on error)
	 */
	private static function _decodeJWT($token, $checktype)
	{
		global $db, $smartAuthAppID, $conf;

		$token = $token ?? '';
		if (empty($token)) {
			json_reply('Access denied (protected route)', 401);
		}

		// Parse token format: token_id|jwt
		if (false === strpos($token, '|') > 0) {
			dol_syslog("smartauth : access denied token not found, missing | ", LOG_ERR);
			json_reply('Access denied (token not found)', 401);
		}

		$token_id = substr($token, 0, strpos($token, '|'));
		if (trim($token_id) == '') {
			dol_syslog("smartauth : access denied token not found, missing token id", LOG_ERR);
			json_reply('Access denied (token not found)', 401);
		}
		if (!is_numeric($token_id)) {
			dol_syslog("SmartAuth Access denied, token not numeric", LOG_ERR);
			json_reply('Access denied (invalid token)', 401);
			//or ??? return [['error' => 'Invalid token ID'], 401];
		}

		$jwt = substr($token, strpos($token, '|') + 1);

		// Check cache first for performance
		$cache_key = 'token-' . $token_id;
		if (!isset($conf->cache['smartmakers'][$cache_key])) {
			// Load token data from database
			$sql = "SELECT rowid as token_id, salt, token_type, fk_authid, entity, date_eol, status, family_id, refresh_count";
			$sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " WHERE rowid = " . (int) $token_id;
			$sql .= " AND status = " . self::STATUS_VALID;

			dol_syslog("smartauth : get token data from db " . $sql);
			$resql = $db->query($sql);

			if (!$resql || $db->num_rows($resql) == 0) {
				dol_syslog("smartauth : Invalid or revoked token", LOG_WARNING);
				json_reply('Invalid or revoked token', 401);
			}

			$token_data = $db->fetch_object($resql);

			// Cache token data
			$conf->cache['smartmakers'][$cache_key] = $token_data;
		}

		$token_data = $conf->cache['smartmakers'][$cache_key];

		// Verify token type
		if ($token_data->token_type !== $checktype) {
			dol_syslog("smartauth :Attempt to use bad type of token !", LOG_WARNING);
			json_reply(['error' => 'Invalid token type.'], 401);
		}

		// Verify token status
		if ($token_data->status != self::STATUS_VALID) {
			dol_syslog("smartauth :Token revoked !", LOG_WARNING);
			json_reply(['error' => 'Token revoked'], 401);
		}

		// Verify expiration
		dol_syslog("SmartAuth Refresh token check eol : db=" . $db->jdate($token_data->date_eol) . ", now=" . dol_now());
		if ($db->jdate($token_data->date_eol) < dol_now()) {
			dol_syslog("smartauth :Token expired. Please login again !", LOG_WARNING);
			json_reply(['error' => 'Token expired. Please login again.'], 401);
		}

		// Verify refresh
		// Check max refresh count
		if ($token_data->refresh_count >= SmartTokenConfig::MAX_REFRESH_COUNT) {
			dol_syslog("SmartAuth Max refresh count exceeded for token " . $token_data->token_id, LOG_WARNING);
			json_reply(['error' => 'Invalid token type.'], 401);
		}

		// Verify JWT signature
		$salt2 = self::_getSalt2();
		$key = $token_data->salt . $salt2 . JwtKeyHelper::getKey();

		// Debug logging for signature verification
		$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
		$httpDeviceId = $_SERVER['HTTP_X_DEVICEID'] ?? '';
		SmartAuthLogger::debug("smartauth : _decodeJWT DEBUG: token_id=$token_id, salt=" . substr($token_data->salt, 0, 8) . "..., salt2=$salt2");
		SmartAuthLogger::debug("smartauth : _decodeJWT DEBUG: HTTP_X_DEVICEID=" . ($httpDeviceId ?: '(empty)') . ", User-Agent=" . substr($userAgent, 0, 50) . "...");
		SmartAuthLogger::debug("smartauth : _decodeJWT DEBUG: expected salt2 from UA=" . substr(hash('sha256', $userAgent), 0, 16));

		$decoded = null;
		try {
			$decoded = JWT::decode($jwt, new Key($key, 'HS256'));
		} catch (SignatureInvalidException $e) {
			dol_syslog("smartauth : jwt signature error : reset token please (salt2 used: $salt2)", LOG_ERR);
			json_reply('Invalid token signature, please login', 401);
		} catch (Exception $e) {
			dol_syslog("smartauth : jwt error : " . $e->getMessage(), LOG_ERR);
			json_reply('Invalid token, please login', 401);
		}

		// RFC 7519 / RFC 8725 claim checks.
		// firebase/php-jwt validates 'exp', 'nbf' and 'iat' itself when those
		// claims are present, but it does NOT validate 'iss' or the header
		// 'typ' value. We add those checks here, plus a 30s clock-skew
		// tolerance for nbf/iat just in case the upstream lib was permissive.
		// Tokens issued before the H-14 fix will not carry these claims; we
		// remain backward-compatible by only enforcing the constraints when
		// the claim is present.
		if (is_object($decoded)) {
			$now = time();
			$skew = 30;

			$expectedIss = \SmartAuth\Api\OAuth2\OAuthConfig::getIssuer();
			if (isset($decoded->iss) && $decoded->iss !== $expectedIss) {
				dol_syslog("smartauth : _decodeJWT iss mismatch: got " . substr((string) $decoded->iss, 0, 80), LOG_WARNING);
				json_reply('Invalid token issuer, please login', 401);
			}
			if (isset($decoded->nbf) && (int) $decoded->nbf > $now + $skew) {
				dol_syslog("smartauth : _decodeJWT nbf in the future", LOG_WARNING);
				json_reply('Token not yet valid, please login', 401);
			}
			if (isset($decoded->iat) && (int) $decoded->iat > $now + $skew) {
				dol_syslog("smartauth : _decodeJWT iat in the future", LOG_WARNING);
				json_reply('Token issued in the future, please login', 401);
			}

			// Verify header.typ (cheap parse of the unsigned middle is OK
			// here because the signature was already verified by JWT::decode).
			$parts = explode('.', $jwt);
			if (count($parts) === 3) {
				$header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
				if (is_array($header) && isset($header['typ'])) {
					$typ = strtoupper((string) $header['typ']);
					if ($typ !== 'JWT') {
						dol_syslog("smartauth : _decodeJWT unexpected typ: " . $header['typ'], LOG_WARNING);
						json_reply('Invalid token type, please login', 401);
					}
				}
			}

			$decoded->token_id = $token_id;
		}
		return $decoded;
	}


	/**
	 * Generate tokens for an already authenticated Dolibarr user
	 *
	 * Use this when user is already logged in Dolibarr (session-based)
	 * and needs a JWT token for API calls (e.g., from embedded React apps)
	 *
	 * @param User $user Dolibarr User object (already authenticated)
	 * @param int $entity Entity ID
	 * @param string $device_label Optional device label (default: 'Dolibarr Web')
	 * @param string $device_uuid Optional device UUID. If not provided, uses User-Agent hash for consistency with _getSalt2 fallback
	 * @return array ['access_token' => string, 'refresh_token' => string, 'expires_in' => int, 'device_uuid' => string]
	 */
	public function generateTokenForAuthenticatedUser($user, $entity = 1, $device_label = 'Dolibarr Web', $device_uuid = '')
	{
		global $db;

		if (!is_object($user) || empty($user->id) || empty($user->login)) {
			throw new \Exception('Invalid user object');
		}

		// Generate device_uuid from User-Agent if not provided
		// IMPORTANT: Must match exactly the fallback behavior in _getSalt2()
		// which uses: substr(hash('sha256', $userAgent), 0, 16)
		// So we use the raw User-Agent hash (not prefixed) to ensure
		// token verification will produce the same salt2
		if (empty($device_uuid)) {
			$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			$device_uuid = $userAgent; // Will be hashed by _getSalt2()
			SmartAuthLogger::debug("smartauth : generateTokenForAuthenticatedUser: using User-Agent as device_uuid: " . substr($userAgent, 0, 50) . "...");
			SmartAuthLogger::debug("smartauth : generateTokenForAuthenticatedUser: salt2 will be: " . substr(hash('sha256', $userAgent), 0, 16));
		}

		// Create token family
		$family_id = $this->_createTokenFamily($user->id);

		// Hash the device_uuid for database storage (max 64 chars)
		// but keep original for _generateTokenPair which passes it to _getSalt2
		$device_uuid_for_db = hash('sha256', $device_uuid);

		// Create/get device directly (bypass _createDeviceIdIfNeeded which requires HTTP_X_DEVICEID)
		$device_id = self::getDeviceIDFromUUID($device_uuid_for_db);
		if ($device_id <= 0) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " (uuid, fk_user_creat, label, date_creation, status, entity)";
			$sql .= " VALUES ('" . $db->escape($device_uuid_for_db) . "', ";
			$sql .= (int) $user->id . ", ";
			$sql .= "'" . $db->escape($device_label) . "', ";
			$sql .= "'" . $db->idate(time()) . "', 1, " . (int) $entity . ")";

			$db->query($sql);
			$device_id = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
		}

		// Same single-session-per-app invariant as the regular /login path.
		// QR-pair issuance must not coexist with a previous JWT for the same
		// user/device/app -- otherwise scanning the QR twice leaves the older
		// JWT alive in the wild.
		$this->_revokePreviousFamiliesForDeviceApp((int) $user->id, (int) $device_id, (int) $entity);

		// Generate token pair with original device_uuid (will be hashed by _getSalt2)
		$tokens = $this->_generateTokenPair(
			'user',
			$user->id,
			$user->id,
			$user->login,
			$entity,
			$family_id,
			$device_id,
			$device_uuid
		);

		// New-login alert. Same opt-in switch as the regular /login
		// path; covers QR-pair issuance and any other server-side
		// caller that bypasses the user-driven login flow.
		$this->_maybeNotifyNewLogin($user, $device_id);

		$logicalDeviceContext = $this->_resolveLogicalDeviceContext((int) $user->id, (int) $device_id, (int) $entity);
		if (!empty($logicalDeviceContext['linked_user_device_id'])) {
			$this->_touchUserDeviceLastseen((int) $logicalDeviceContext['linked_user_device_id']);
		}

		return [
			'access_token' => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			'device_uuid' => $device_uuid_for_db,
			'devices_choice' => $this->_getAllDevicesForUser($user->id),
			'needs_device_pick' => $logicalDeviceContext['needs_device_pick'],
			'existing_user_devices' => $logicalDeviceContext['existing_user_devices'],
		];
	}

	/**
	 * Resolve the logical-device context for a freshly-issued JWT.
	 *
	 * Inspects the technical smartauth_devices row to see whether it
	 * already points at a logical smartauth_user_devices parent. If it
	 * does, returns the parent id so the caller can update its
	 * date_lastseen. If it does not, returns the list of active logical
	 * devices the user already owns so the mobile can render a picker
	 * and call /account/user-devices/{id}/link or POST /account/user-devices.
	 *
	 * @return array{needs_device_pick: bool, existing_user_devices: array<int,array<string,mixed>>, linked_user_device_id: int}
	 */
	private function _resolveLogicalDeviceContext(int $userId, int $technicalDeviceId, int $entity): array
	{
		global $db;

		$linked = 0;
		if ($technicalDeviceId > 0) {
			$sql = "SELECT fk_user_device FROM " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " WHERE rowid = " . $technicalDeviceId;
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj && $obj->fk_user_device !== null && $obj->fk_user_device !== '') {
					$linked = (int) $obj->fk_user_device;
				}
			}
		}

		if ($linked > 0) {
			return [
				'needs_device_pick' => false,
				'existing_user_devices' => [],
				'linked_user_device_id' => $linked,
			];
		}

		$repo = new SmartAuthUserDevice($db);
		$rows = $repo->listForUser($userId, $entity);

		$existing = [];
		foreach ($rows as $row) {
			$existing[] = [
				'id' => (int) $row['rowid'],
				'label' => (string) $row['label'],
				'icon' => (string) $row['icon'],
				'date_creation' => $row['date_creation'],
				'date_lastseen' => $row['date_lastseen'],
				'session_count' => (int) ($row['session_count'] ?? 0),
			];
		}

		return [
			'needs_device_pick' => true,
			'existing_user_devices' => $existing,
			'linked_user_device_id' => 0,
		];
	}

	/**
	 * Bump date_lastseen on a logical user_device. Cheap UPDATE used at
	 * every login that targets a device already attached to a parent.
	 */
	private function _touchUserDeviceLastseen(int $userDeviceId): void
	{
		global $db;
		if ($userDeviceId <= 0) {
			return;
		}
		$repo = new SmartAuthUserDevice($db);
		$repo->touchLastseen($userDeviceId);
	}

	/**
	 * Replacement for _collapseDuplicateLabelDevices in the /device
	 * rename path: instead of revoking siblings that share the same
	 * label, attach this technical device to the matching logical
	 * user_device row (creating one if necessary). Other PWAs of the
	 * same physical device that were never tagged stay alive; once
	 * they hit /device themselves with the same label, they'll converge
	 * onto the same parent.
	 */
	private function _syncLogicalDeviceForRenamedTechnical(int $userId, int $technicalDeviceId, string $label, int $entity): void
	{
		global $db;
		$label = SmartAuthUserDevice::normaliseLabel($label);
		if ($userId <= 0 || $technicalDeviceId <= 0 || $label === '') {
			return;
		}

		$repo = new SmartAuthUserDevice($db);
		$existing = $repo->findByLabel($userId, $label, $entity);
		if ($existing === null) {
			$rowid = $repo->create($userId, $label, SmartAuthUserDevice::ICON_PHONE, $entity);
			if ($rowid <= 0) {
				dol_syslog("SmartAuth _syncLogicalDeviceForRenamedTechnical: create returned $rowid for user=$userId label='$label'", LOG_WARNING);
				return;
			}
			$userDeviceId = $rowid;
		} else {
			$userDeviceId = (int) $existing['rowid'];
			if ((int) $existing['status'] !== SmartAuthUserDevice::STATUS_ACTIVE) {
				dol_syslog("SmartAuth _syncLogicalDeviceForRenamedTechnical: existing user_device $userDeviceId is revoked, skipping link", LOG_WARNING);
				return;
			}
		}

		if (!$repo->linkTechnicalDevice($userDeviceId, $technicalDeviceId, $userId, $entity)) {
			dol_syslog("SmartAuth _syncLogicalDeviceForRenamedTechnical: link failed user_device=$userDeviceId tech=$technicalDeviceId", LOG_WARNING);
		}
	}

	/**
	 * Fire a "new login from unknown IP / device" alert email when the
	 * SMARTAUTH_NEW_LOGIN_NOTIFY constant is set. Errors are swallowed
	 * inside NewLoginNotifier::notifyIfNewLogin so they cannot abort
	 * the login.
	 *
	 * @param object $user
	 * @param int    $deviceId
	 * @return void
	 */
	private function _maybeNotifyNewLogin($user, $deviceId)
	{
		global $db;
		if (!getDolGlobalString('SMARTAUTH_NEW_LOGIN_NOTIFY')) {
			return;
		}
		try {
			require_once __DIR__ . '/Account/NewLoginNotifier.php';
			$ip = self::get_client_ip();
			$notifier = new \SmartAuth\Api\Account\NewLoginNotifier($db);
			$notifier->notifyIfNewLogin($user, (string) $ip, (int) $deviceId);
		} catch (\Throwable $e) {
			dol_syslog('SmartAuth _maybeNotifyNewLogin failed: ' . $e->getMessage(), LOG_ERR);
		}
	}
}
