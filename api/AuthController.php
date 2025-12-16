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

use User;
use Exception;
use SmartAuthDevices;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SmartAuth\Api\RateLimiter;
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
		dol_syslog("Debug smartauth::AuthController : index");
		$ret = [
			'entities' => $this->_api_GetListOfEntities(),
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
		dol_syslog("Call on SmartAuth::ping deprecated function");
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
		global $db, $smartAuthAppID, $smartAuthAppKey;
		dol_syslog("Debug smartauth::AuthController : refresh");

		// Get refresh token from Authorization header
		$refresh_token = self::_getBearerToken();
		if (empty($refresh_token)) {
			return [['error' => 'Refresh token required'], 401];
		}

		// Parse token
		if (strpos($refresh_token, '|') === false) {
			return [['error' => 'Invalid token format'], 401];
		}

		$decoded = $this->_decodeJWT($refresh_token, SmartTokenConfig::TYPE_REFRESH);

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
		dol_syslog("_checkTokenFamily returns " . json_encode($family_check));
		if (!$family_check['valid']) {
			dol_syslog("Token family check failed: " . $family_check['reason'], LOG_WARNING);
			// SECURITY: Revoke entire token family on suspicious activity
			$this->_revokeTokenFamily($family_id, 'suspicious activity');
			return [['error' => 'Security violation detected. All sessions revoked.'], 401];
		}

		// Check max refresh count
		if ($decoded->refresh_count >= SmartTokenConfig::MAX_REFRESH_COUNT) {
			dol_syslog("Max refresh count exceeded for token " . $decoded->token_id, LOG_WARNING);
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

		dol_syslog("Token refreshed successfully for user $login");

		return [[
			'access_token' => $new_tokens['access_token'],
			'refresh_token' => $new_tokens['refresh_token'],
			'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			'token_type' => 'Bearer'
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
	 *     "rememberMe": 0
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
		dol_syslog("Debug smartauth::AuthController : login");
		// dol_syslog("Debug smartauth : AuthController::login : data is " . json_encode($payload));

		$rateLimiter = new RateLimiter($db);
		$ip = $this->get_client_ip();

		$login  = filter_var($payload['email'] ?? '', FILTER_SANITIZE_EMAIL);
		if (empty($login)) {
			//try old username field
			$login  = filter_var($payload['username']  ?? '', FILTER_SANITIZE_EMAIL);
		}

		// Check 1: IP-based rate limit (prevent distributed attacks on same IP)
		$ip_limit = $rateLimiter->checkLimit(
			$ip,
			'login_ip',
			$max_attempts = getDolGlobalInt('SMARTAUTH_RATELIMIT_IP_MAX', 10),
			$window_seconds = getDolGlobalInt('SMARTAUTH_RATELIMIT_IP_WINDOW', 300) // 5 minutes
		);

		if (!$ip_limit['allowed']) {
			dol_syslog("Rate limit: IP $ip blocked", LOG_WARNING);
			return [[
				'error' => 'Too many attempts. Please try again later.',
				'retry_after' => $ip_limit['retry_after']
			], 429]; // HTTP 429 Too Many Requests
		}

		// Check 2: Username-based rate limit (prevent brute force on specific account)
		if (!empty($login)) {
			$login_limit = $rateLimiter->checkLimit(
				$login,
				'login_username',
				$max_attempts = getDolGlobalInt('SMARTAUTH_RATELIMIT_USER_MAX', 5),
				$window_seconds = getDolGlobalInt('SMARTAUTH_RATELIMIT_USER_WINDOW', 900) // 15 minutes
			);

			if (!$login_limit['allowed']) {
				dol_syslog("Rate limit: Username $login blocked", LOG_WARNING);

				// Record IP attempt anyway
				$rateLimiter->recordAttempt($ip, 'login_ip', false);

				return [[
					'error' => 'Too many failed attempts for this account. Please try again later.',
					'retry_after' => $login_limit['retry_after']
				], 429];
			}
		}

		// Record attempts BEFORE authentication
		$rateLimiter->recordAttempt($ip, 'login_ip', false);
		if (!empty($login)) {
			$rateLimiter->recordAttempt($login, 'login_username', false);
		}


		$entity = (int) ($payload['entity'] ?? 1);
		if (isModEnabled('multicompany') && empty($payload['entity'])) {
			//search entity for that user ?
			$entity = $this->_findEntityForUser($login);
		}

		//waiting for regis answer
		$_SESSION["dol_entity"] = $entity;
		// force current entity but maybe a TODO with transverse mode or ...
		$conf->entity = $entity;
		// dol_syslog("conf avant " . json_encode($conf->multicompany));
		$conf->setValues($db);
		$mysoc->setMysoc($conf);
		// dol_syslog("conf apres " . json_encode($conf->multicompany));

		$pass   = $payload['password'] ?? '';

		//check if login / pass is ok
		include_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';
		$login = checkLoginPassEntity($login, $pass, $entity, ['dolibarr'], 'api');		// Check credentials.
		dol_syslog("Debug smartauth : AuthController::login : checklogin is " . json_encode($login));
		if ($login === '--bad-login-validity--') {
			$login = '';
		}
		if (empty($login)) {
			dol_syslog("Debug smartauth : AuthController::login : login empty");
			json_reply('Access denied (login empty)', 401);
		}

		$tmpuser = new User($db);
		$resuser = $tmpuser->fetch(0, $login);
		if ($resuser < 0) {
			dol_syslog("Debug smartauth::AuthController load user from login fail ... try with email");
			$resuser = $tmpuser->fetch(0, '', '', 0, -1, $login);
			if ($resuser < 0) {
				dol_syslog("Debug smartauth::AuthController load user from email fail too !", LOG_ERR);
			}
		}

		// SUCCESS: Reset rate limits
		$rateLimiter->reset($ip, 'login_ip');
		$rateLimiter->reset($login, 'login_username');

		// Record successful attempt
		$rateLimiter->recordAttempt($ip, 'login_ip', true);
		$rateLimiter->recordAttempt($login, 'login_username', true);

		// dol_syslog("Debug smartauth::AuthController : conf " . json_encode($conf));
		// dol_syslog("Debug smartauth::AuthController : user " . $tmpuser->entity);

		if (!is_object($tmpuser) || empty($tmpuser->id)) {
			dol_syslog("Debug smartauth : AuthController::login : failed to load user");
			json_reply('Failed to load user', 401);
		}

		// Create token family (for tracking refresh chain)
		$family_id = $this->_createTokenFamily($tmpuser->id);

		$device_id = $this->_createDeviceIdIfNeeded($tmpuser->id);

		// Generate BOTH tokens
		$tokens = $this->_generateTokenPair('user', $tmpuser->id, $tmpuser->id, $login, $entity, $family_id, $device_id);

		// Renew the hash ?
		// Generate token for user
		$result = $tmpuser->call_trigger('USER_LOGIN', $tmpuser);

		$rememberme  = (int) $payload['rememberMe']  ?? 0;

		dol_syslog("Debug smartauth : AuthController::login : return 200 with user=" . $tmpuser->id); // full debug . ", " . json_encode($tmpuser));
		$user = $tmpuser->email;

		$device_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';
		$name = $this->getDeviceName(null, $device_uuid);
		$devices_choice = null;
		dol_syslog("AuthController : device name is $name for uuid=$device_uuid");
		if (empty($name)) {
			$devices_choice = $this->_getAllDevicesForUser($tmpuser->id);
		}

		if (empty($tmpuser->email)) {
			$user = $tmpuser->login;
		}
		$ret = [
			'user' => $user,
			'userid' => $tmpuser->id,
			'entity' => $entity,
			'token' => $tokens['access_token'], // to be compatible with "old" process
			'access_token' => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'expires_in' => SmartTokenConfig::ACCESS_TOKEN_LIFETIME,
			'token_type' => 'Bearer',
			'devices_choice' => $devices_choice,
			'rememberMe' => $rememberme
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
		// dol_syslog("Debug smartauth::AuthController : logout for " . json_encode($payload));
		if (!empty($payload['family_id'])) {
			dol_syslog("Debug smartauth::AuthController : logout for " . $user->id . ", tokenFamily id=" . $payload['family_id']);
			$this->_revokeTokenFamily($payload['family_id'], 'logout');
		}
		// if (!empty($payload['token_id'])) {
		// 	dol_syslog("Debug smartauth::AuthController : logout for " . $user->id . ", token id=" . $payload['token_id']);
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
		dol_syslog("Debug smartauth::AuthController : device"); // full debug, payload = " . json_encode($payload));

		$result = "error";

		$token = self::_getBearerToken();
		$decoded = self::_decodeJWT($token, SmartTokenConfig::TYPE_ACCESS);

		$current_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';
		$new_uuid = $payload['uuid'];
		$new_name = sanitizeVal($payload['label']);

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
						$message = "";
						$device->validate($user);
					}
					$ret = [
						'message' => $message,
					];
				} else {
					$ret = [
						'message' => "success but device name is empty",
					];
				}
			} else {
				$ret = [
					'message' => "success, same device",
				];
			}
		} else {
			dol_syslog('smartauth::AuthController : device user choice an existing device ' . $new_uuid, LOG_DEBUG);
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
	 * @return  StdObject  Decoded token payload
	 */
	public static function check()
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $conf;

		$token = self::_getBearerToken();
		$tokenparts = explode('|', $token);
		$token_id = $tokenparts[0];

		$decoded = self::_decodeJWT($token, SmartTokenConfig::TYPE_ACCESS);

		// dol_syslog("smartauth : decoded token is $token :: jwt is " . json_encode($decoded));

		// Verify token contains user identification
		// $decoded->login == user auth
		// $decoded->socid == soc auth (for obapi for example)
		if (empty($decoded->login) && empty($decoded->socid)) {
			dol_syslog("smartauth : login/socid not found in token", LOG_ERR);
			json_reply('Access denied (invalid token payload)', 401);
		}
		$decoded->token_id = $token_id;

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
		dol_syslog("Debug smartauth : AuthController::_newThirdpartKey");

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
			$useractions->fk_authid,
			$socmail,
			$entity,
			$family_id,
			$device_id
		);

		dol_syslog("Debug smartauth : AuthController::_newThirdpartKey return");
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
		dol_syslog("Debug smartauth : _getBearerToken");

		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				dol_syslog("Debug smartauth : _getBearerToken, matches, return " . json_encode($matches));
				return $matches[1];
			}
		}

		dol_syslog("Debug smartauth : _getBearerToken empty headers, return null");
		return null;
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
	 * @param   [type]$u  [$u description]
	 * @param   null      [ description]
	 *
	 * @return  [type]    [return description]
	 */
	private function _FetchUserWithRights($u = null)
	{
		global $db;
		if (empty($u)) {
			$u = new \User($db);
			$res = $u->fetch(getDolGlobalString('SMARTAUTH_DEFAULT_USER'));
			if ($res <= 0) {
				dol_syslog('opb: error fetching user id #' . getDolGlobalString('SMARTAUTH_DEFAULT_USER'), LOG_ERR);
				exit(1);
			}
			$u->getrights();
		}
		return $u;
	}

	/**
	 * Get the server variable REMOTE_ADDR, or the first ip of HTTP_X_FORWARDED_FOR (when using proxy).
	 * Source: thanks to prestashop
	 *
	 * @return string $remote_addr ip of client
	 */
	public static function get_client_ip()
	{
		global $conf;
		if (isset($conf->cache['smartmakers']['clientIP'])) {
			dol_syslog("get_client_ip from cache : " . $conf->cache['smartmakers']['clientIP']);
			return $conf->cache['smartmakers']['clientIP'];
		}
		// Try Apache function first if available
		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			$headers = array_change_key_case($headers, CASE_UPPER);

			$priority = [
				'CF-CONNECTING-IP',
				'X-REAL-IP',
				'X-FORWARDED-FOR',
				'CLIENT-IP'
			];

			foreach ($priority as $header) {
				if (!empty($headers[$header])) {
					$ips = explode(',', $headers[$header]);
					$ip = trim($ips[0]);

					if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
						$conf->cache['smartmakers']['clientIP'] = $ip;
						// dol_syslog("get_client_ip (1) use return $ip");
						return $ip;
					}
				}
			}
		}

		// Fallback to $_SERVER
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR'
		];

		foreach ($ip_keys as $key) {
			if (!empty($_SERVER[$key])) {
				$ips = explode(',', $_SERVER[$key]);
				$ip = trim($ips[0]);

				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
					$conf->cache['smartmakers']['clientIP'] = $ip;
					// dol_syslog("get_client_ip (2) use return $ip");
					return $ip;
				}
			}
		}

		// dol_syslog("get_client_ip use return 0.0.0.0");
		return "0.0.0.0";
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
			$device_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';
			dol_syslog("_getSalt2 debug HTTP_X_DEVICEID : " . $device_uuid);

			if (!empty($device_uuid)) {
				dol_syslog("smartauth : using X-DEVICEID header (hash format) for salt2", LOG_DEBUG);
				return substr(hash('sha256', $device_uuid), 0, 16);
			}

			dol_syslog("smartauth : X-DEVICEID empty, falling back to User-Agent", LOG_WARNING);

			// Fallback to User-Agent hash
			$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			return substr(hash('sha256', $userAgent), 0, 16);
		} else {
			dol_syslog("_getSalt2 debug deviceid is set from function arg value : " . $device_uuid);
			dol_syslog("smartauth : using deviceid from function arg (hash format) for salt2", LOG_DEBUG);
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
	 * Create a new token family for tracking refresh chain
	 */
	private function _createTokenFamily($user_id)
	{
		global $db;

		$family_id = bin2hex(random_bytes(32));

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " (family_id, fk_user, created_at, last_refresh_at)";
		$sql .= " VALUES ('" . $db->escape($family_id) . "', ";
		$sql .= (int) $user_id . ", ";
		$sql .= time() . ", " . time() . ")";

		$db->query($sql);

		$family_id = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_token_family");

		return $family_id;
	}

	/**
	 * Generate access + refresh token pair
	 *
	 * @param   String  $element     dolibarr element (user or societe_account for the moment)
	 * @param   Int  	$element_id  dolibarr id of element
	 * @param   Int  	$user_id     user creator id
	 * @param   String  $login       login to use for that token
	 * @param   Int  	$entity      dolibarr entity
	 * @param   String  $family_id   token family
	 * @param   Int 	$device_id   device id (foreign key)
	 * @param   String 	$device_uuid device uuid (in case of previous is null and we don't want to use http header value)
	 *
	 * @return  array               two token (access & refresh)
	 */
	private function _generateTokenPair($element, $element_id, $user_id, $login, $entity, $family_id, $device_id, $device_uuid = '')
	{
		dol_syslog("_generateTokenPair element=$element, element_id=$element_id, user_id=$user_id, login=$login, entity=$entity, family_id=$family_id, device_id=$device_id, device_uuid=$device_uuid");
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
	 * Unified token generation (replaces _newUserKey)
	 */
	private function _generateToken($element, $element_id, $user_id, $login, $entity, $token_type, $lifetime, $family_id, $device_id,  $device_uuid = null)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey;

		dol_syslog("_generateToken element=$element, element_id=$element_id, user_id=$user_id, login=$login, entity=$entity, token_type=$token_type, lifetime=$lifetime, family_id=$family_id, device_id=$device_id,  device_uuid=$device_uuid");

		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		$salt2 = $this->_getSalt2($device_uuid); // same as app_id logic but for device

		// Insert token into database
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " (appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, fk_device_id, ";
		$sql .= " auth_element, token_type, parent_token_id, ip, status, entity)";
		$sql .= " VALUES (";
		$sql .= (int) $smartAuthAppID . ", ";
		$sql .= "'" . $salt . "', ";
		$sql .= "'" . $db->idate(dol_now()) . "', ";
		$sql .= "'" . $db->idate(dol_now() + $lifetime) . "', ";
		$sql .= (int) $user_id . ", ";
		$sql .= (int) $element_id . ", ";
		$sql .= (int) $device_id . ", ";
		$sql .= "'" . $element . "', ";
		$sql .= "'" . $token_type . "', ";
		$sql .= ($family_id ? (int) $family_id : "NULL") . ", ";
		$sql .= "'" . $this->get_client_ip() . "', ";
		$sql .= self::STATUS_VALID . ", ";
		$sql .= (int) $entity . ")";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("Failed to create token: " . $db->lasterror(), LOG_ERR);
			return null;
		}

		$token_id = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");

		// Build JWT payload
		$payload = [
			"login" => $login,
			"user_id" => $user_id,
			"entity" => $entity,
			"token_type" => $token_type,
			"family_id" => $family_id,
			"device_id" => $device_id,
			"exp" => time() + $lifetime // Expiration timestamp
		];

		$key = $salt . $salt2 . $smartAuthAppKey;
		$jwt = JWT::encode($payload, $key, 'HS256');

		// Return token_id|jwt format
		return $token_id . '|' . $jwt;
	}

	/**
	 * Check token family validity (detect replay attacks)
	 */
	private function _checkTokenFamily($family_id, $user_id)
	{
		global $db;
		dol_syslog("_checkTokenFamily family_id=$family_id, user_id=$user_id");

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
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth a";
		$sql .= " SET a.status = " . self::STATUS_LOGOUT;
		$sql .= ", a.salt = '" . $db->escape($reason) . "'";
		$sql .= " WHERE a.parent_token_id = '" . $db->escape($family_id) . "'";
		$db->query($sql);

		dol_syslog("Token family $family_id revoked", LOG_INFO);
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

		$current_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';

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

		dol_syslog('_getAllDevicesForUser returns ' . json_encode($ret), LOG_DEBUG);
		return $ret;
	}


	private function _createDeviceIdIfNeeded($user_id, $device_uuid = '')
	{
		global $db, $user;

		$deviceid = '';
		if ($device_uuid == '') {
			$device_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';
		}

		if ($device_uuid == 'undefined') {
			//note this case is for auto create device uuid on front side
		}

		if ($device_uuid == '') {
			dol_syslog("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !", LOG_AUTH);
			dol_syslog("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !", LOG_ALERT);
			throw new Exception("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !");
		}

		if ($device_uuid != '') {
			$deviceid = self::getDeviceIDFromUUID($device_uuid);
		}

		if ($deviceid <= 0) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " (uuid, fk_user_creat, date_creation, status)";
			$sql .= " VALUES ('" . substr($db->escape($device_uuid), 0, 64) . "', ";
			$sql .= (int) $user_id . ", ";
			$sql .= "'" . $db->idate(time()) . "', " . self::STATUS_DRAFT . ")";

			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog("Failed to create device: " . $db->lasterror(), LOG_ERR);
				throw new Exception("Failed to create device: " . $db->lasterror());
			}

			$rowid = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_devices");
			if ($rowid > 0) {
				return $rowid;
			}
		}

		return $deviceid;
	}

	private static function _decodeJWT($token, $checktype)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $conf;

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
			dol_syslog("Access denied, token not numeric", LOG_ERR);
			json_reply('Access denied (invalid token)', 401);
			//or ??? return [['error' => 'Invalid token ID'], 401];
		}

		$jwt = substr($token, strpos($token, '|') + 1);

		// Check cache first for performance
		$cache_key = 'token-' . $token_id;
		if (!isset($conf->cache['smartmakers'][$cache_key])) {
			// Load token data from database
			$sql = "SELECT rowid as token_id, salt, token_type, fk_authid, entity, date_eol, status, parent_token_id, refresh_count";
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
		dol_syslog("Refresh token check eol : db=" . $db->jdate($token_data->date_eol) . ", now=" . dol_now());
		if ($db->jdate($token_data->date_eol) < dol_now()) {
			dol_syslog("smartauth :Token expired. Please login again !", LOG_WARNING);
			json_reply(['error' => 'Token expired. Please login again.'], 401);
		}

		// Verify JWT signature
		$salt2 = self::_getSalt2();
		$key = $token_data->salt . $salt2 . $smartAuthAppKey;

		try {
			$decoded = JWT::decode($jwt, new Key($key, 'HS256'));
		} catch (SignatureInvalidException $e) {
			dol_syslog("smartauth : jwt signature error : reset token please", LOG_ERR);
			json_reply('Invalid token signature, please login', 401);
		} catch (Exception $e) {
			dol_syslog("smartauth : jwt error : " . $e->getMessage(), LOG_ERR);
			json_reply('Invalid token, please login', 401);
		}

		// dol_syslog("smartauth : _decodeJWT is " . json_encode($decoded));
		return $decoded;
	}
}
