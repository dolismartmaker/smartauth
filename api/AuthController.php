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

use User;
use Exception;
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
	 * @api {get} /index List of dolibarr entities
	 * @apiName GetLogin
	 * @apiGroup Auth
	 *
	 * @apiSuccess {Array} entities array of dolibarr available entities
	 *
	 * @apiDescription Get the list of dolibarr entities before login
	 * then you can make a login request on the right dolibarr entity
	 * if your dolibarr use multicompany module
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
	 * @api {get} /ping check if your token is valid
	 * @apiName GetLogin
	 * @apiGroup Auth
	 *
	 * @apiSuccess {Array} access token + refresh token + expiry date
	 *
	 * @apiDescription Check if your token is already valid
	 * @deprecated
	 */
	public function ping($arr = null)
	{
		dol_syslog("Call on SmartAuth::ping deprecated function");
		return $this->refresh($arr);
	}

	/**
	 * @api {get} /refresh refresh the token
	 * @apiName Refresh
	 * @apiGroup Auth
	 *
	 * @apiSuccess {Array} access token + refresh token + expiry date
	 *
	 * @apiDescription Check if your token is already valid
	 */
	public function refresh($arr = null)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey;
		dol_syslog("Debug smartauth::AuthController : refresh");

		//TODO dev time !!!!
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
		$family_check = $this->_checkTokenFamily($family_id, $decoded->fk_authid);
		if (!$family_check['valid']) {
			dol_syslog("Token family check failed: " . $family_check['reason'], LOG_WARNING);

			// SECURITY: Revoke entire token family on suspicious activity
			$this->_revokeTokenFamily($family_id);

			return [['error' => 'Security violation detected. All sessions revoked.'], 401];
		}

		// Check max refresh count
		if ($decoded->refresh_count >= SmartTokenConfig::MAX_REFRESH_COUNT) {
			dol_syslog("Max refresh count exceeded for token " . $decoded->token_id, LOG_WARNING);
			return [['error' => 'Maximum refresh limit reached. Please login again.'], 401];
		}

		// === TOKEN ROTATION ===
		// Invalidate current refresh token (one-time use)
		$this->_revokeToken($decoded->token_id, 'refresh_used');

		// Generate new token pair
		$new_tokens = $this->__generateTokenPair(
			'user',
			$decoded->fk_authid,
			$decoded->fk_authid,
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
	 *
	 * @apiDescription Try to log into dolibarr with login / password and
	 * in case of success generate a token for that app / session
	 *
	 * @apiName PostLogin
	 * @apiGroup Auth
	 *
	 * @apiBody (Login) {String} email     Mandatory dolibarr user name (email)
	 * @apiBody (Login) {String} password  Mandatory user password
	 * @apiBody (Login) {Number} entity    Mandatory dolibarr entity
	 *
	 * @apiSuccess {String} user      User login
	 * @apiSuccess {Number} userid    User ID
	 * @apiSuccess {String} token     Session JWT to use for next requests as Bearer Auth Token (JWT)
	 *
	 * @apiSuccessExample {json} Success-Response:
	 * HTTP/1.1 200 OK
	 * {
	 *     "data": {
	 *         "user": "eric@cap-rel.fr",
	 *         "userid": "3",
	 *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUz88NiJ9.eyJsb2dpbiI622RsYyIsImVu88l0eSI6MH0._XWcHLf999kMqkP65dgXcbkqT522W9zbdUiIA3BU0pI"
	 *     }
	 *  }
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
		$tokens = $this->__generateTokenPair('user', $tmpuser->id, $tmpuser->id, $login, $entity, $family_id, $device_id);

		// Renew the hash ?
		// Generate token for user
		$result = $tmpuser->call_trigger('USER_LOGIN', $tmpuser);

		$rememberme  = (int) $payload['rememberMe']  ?? '';

		dol_syslog("Debug smartauth : AuthController::login : return 200 with user=" . $tmpuser->id); // full debug . ", " . json_encode($tmpuser));
		$user = $tmpuser->email;

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
			'devices_choice' => $this->_getAllDevicesForUser($tmpuser->id),
			'rememberMe' => $rememberme
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {post} /logout Logout
	 * @apiDescription Logout and close session
	 * @apiName PostLogout
	 * @apiGroup Auth
	 *
	 */
	public function logout($payload)
	{
		dol_syslog("Debug smartauth::AuthController : logout");
		global $db;
		$user = $payload['user'];
		if (!empty($payload['tokenid'])) {
			$this->_revokeToken($payload['tokenid'], 'logout');
		}

		$result = $user->call_trigger('USER_LOGOUT', $user);

		$ret = [
			'user' => '',
			'token' => ''
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {post} /device choose device uuid or set name of current uuid
	 * @apiName PostDevice
	 * @apiGroup Auth
	 *
	 * @apiSuccess {Array} success code
	 *
	 * @apiDescription Set the name of current device uuid or uuid of device_id
	 * user has choosed
	 */
	public function device($arr = null)
	{
		dol_syslog("Debug smartauth::AuthController : device");


		$ret = [
			'device_id' => $this->_api_GetListOfEntities(),
		];
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

		$decoded = self::_decodeJWT($token, SmartTokenConfig::TYPE_ACCESS);

		dol_syslog("smartauth : decoded jwt is " . json_encode($decoded));

		// Verify token contains user identification
		// $decoded->login == user auth
		// $decoded->socid == soc auth (for obapi for example)
		if (empty($decoded->login) && empty($decoded->socid)) {
			dol_syslog("smartauth : login/socid not found in token", LOG_ERR);
			json_reply('Access denied (invalid token payload)', 401);
		}

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

		$new_tokens = $this->__generateTokenPair(
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
			return $conf->cache['smartmakers']['clientIP'];
		}
		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
		} else {
			$headers = $_SERVER;
		}

		if (array_key_exists('X-Forwarded-For', $headers)) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $headers['X-Forwarded-For'];
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && (!isset($_SERVER['REMOTE_ADDR'])
			|| preg_match('/^127\..*/i', trim($_SERVER['REMOTE_ADDR'])) || preg_match('/^172\.(1[6-9]|2\d|30|31)\..*/i', trim($_SERVER['REMOTE_ADDR']))
			|| preg_match('/^192\.168\.*/i', trim($_SERVER['REMOTE_ADDR'])) || preg_match('/^10\..*/i', trim($_SERVER['REMOTE_ADDR'])))) {
			if (strpos($_SERVER['HTTP_X_FORWARDED_FOR'], ',')) {
				$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$conf->cache['smartmakers']['clientIP'] = $ips[0];
				return $ips[0];
			} else {
				$conf->cache['smartmakers']['clientIP'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
				return $_SERVER['HTTP_X_FORWARDED_FOR'];
			}
		} else {
			return $conf->cache['smartmakers']['clientIP'] = $_SERVER['REMOTE_ADDR'];
		}
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
	private static function _getSalt2()
	{
		// Check for X-DEVICEID header (future-proof for mobile apps)
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
	 *
	 * @return  array               two token (access & refresh)
	 */
	private function __generateTokenPair($element, $element_id, $user_id, $login, $entity, $family_id, $device_id)
	{
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
			$device_id
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
			$device_id
		);

		return [
			'access_token' => $access_token,
			'refresh_token' => $refresh_token
		];
	}

	/**
	 * Unified token generation (replaces _newUserKey)
	 */
	private function _generateToken($element, $element_id, $user_id, $login, $entity, $token_type, $lifetime, $family_id, $device_id, $parent_token_id = null)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey;

		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		$salt2 = $this->_getSalt2(); // app_id logic

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
		$sql .= ($parent_token_id ? (int) $parent_token_id : "NULL") . ", ";
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
			"entity" => $entity,
			"token_type" => $token_type,
			"family_id" => $family_id,
			"device_id" => $device_id,
			"exp" => time() + $lifetime // Expiration timestamp
		];

		$key = $salt . $salt2 . $smartAuthAppKey;
		$jwt = JWT::encode($payload, $key, 'HS256');

		// Return tokenid|jwt format
		return $token_id . '|' . $jwt;
	}

	/**
	 * Check token family validity (detect replay attacks)
	 */
	private function _checkTokenFamily($family_id, $user_id)
	{
		global $db;

		$sql = "SELECT revoked, refresh_count, fk_user";
		$sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " WHERE family_id = '" . $db->escape($family_id) . "'";

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
		$sql .= " WHERE family_id = '" . $db->escape($family_id) . "'";

		$db->query($sql);
	}

	/**
	 * Revoke entire token family (security breach detected)
	 */
	private function _revokeTokenFamily($family_id)
	{
		global $db;

		// Mark family as revoked
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_token_family";
		$sql .= " SET revoked = 1";
		$sql .= " WHERE family_id = '" . $db->escape($family_id) . "'";
		$db->query($sql);

		// Revoke all tokens in this family
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth a";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_token_family f";
		$sql .= " ON a.fk_authid = f.fk_user";
		$sql .= " SET a.status = " . self::STATUS_LOGOUT;
		$sql .= ", a.salt = 'family_revoked'";
		$sql .= " WHERE f.family_id = '" . $db->escape($family_id) . "'";
		$db->query($sql);

		dol_syslog("Token family $family_id revoked due to security violation", LOG_WARNING);
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
			}
			$conf->cache['smartmakers'][$cache_key] = $res;
		}
		return $conf->cache['smartmakers'][$cache_key];
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
			$sql .= " OR uuid='" . $db->escape($current_uuid) . "'";
			$sql .= " GROUP BY uuid";
		}
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				// $ret[] = ['label' => $obj->label, 'uuid' => $obj->uuid];
				$ret[] = $obj;
			}
		}
		return $ret;
	}


	private function _createDeviceIdIfNeeded($user_id)
	{
		global $db, $user;

		$deviceid = '';
		$device_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']) ?? '';

		if ($device_uuid == 'undefined') {
			//auto création d'un device uuid local

		}

		if ($device_uuid == '') {
			dol_syslog("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !", LOG_AUTH);
			dol_syslog("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !", LOG_ALERT);
			throw new Exception("SmartAuth : there is no device uuid into HTTP_X_DEVICEID header, this is mandatory !");
		}

		if ($device_uuid != '') {
			$deviceid = $this->getDeviceIDFromUUID($device_uuid);
		}

		if ($deviceid <= 0) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_devices";
			$sql .= " (uuid, fk_user_creat, date_creation, status)";
			$sql .= " VALUES ('" . substr($db->escape($device_uuid), 0, 40) . "', ";
			$sql .= (int) $user_id . ", ";
			$sql .= "'" . $db->idate(time()) . "', " . 1 . ")";

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

		return -1;
	}

	private static function _decodeJWT($token, $checktype)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $conf;

		if (empty($token)) {
			json_reply('Access denied (protected route)', 401);
		}

		// Parse token format: tokenid|jwt
		if (false === strpos($token, '|') > 0) {
			dol_syslog("smartauth : access denied token not found", LOG_ERR);
			json_reply('Access denied (token not found)', 401);
		}

		$token_id = substr($token, 0, strpos($token, '|'));
		if (trim($token_id) == '') {
			dol_syslog("smartauth : access denied token not found", LOG_ERR);
			json_reply('Access denied (token not found)', 401);
		}
		if (!is_numeric($token_id)) {
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
				dol_syslog("smartauth : token not found or revoked", LOG_WARNING);
				json_reply('Invalid or revoked token', 401);
			}

			$token_data = $db->fetch_object($resql);

			// Cache token data
			$conf->cache['smartmakers'][$cache_key] = $token_data;
		}

		$token_data = $conf->cache['smartmakers'][$cache_key];

		// Verify token type
		if ($token_data->token_type !== $checktype) {
			dol_syslog("Attempt to use bad type of token !", LOG_WARNING);
			return [['error' => 'Invalid token type.'], 401];
		}

		// Verify token status
		if ($token_data->status != self::STATUS_VALID) {
			return [['error' => 'Token revoked'], 401];
		}

		// Verify expiration
		dol_syslog("Refresh token check eol : db=" . $db->jdate($token_data->date_eol) . ", now=" . dol_now());
		if ($db->jdate($token_data->date_eol) < dol_now()) {
			return [['error' => 'Token expired. Please login again.'], 401];
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
		return $decoded;
	}
}
