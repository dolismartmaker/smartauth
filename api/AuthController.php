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
			'data' => [
				'entities' => $this->_api_GetListOfEntities(),
			]
		];
		return ([$ret, 200]);
	}

	/**
	 * @api {get} /ping check if your token is valid
	 * @apiName GetLogin
	 * @apiGroup Auth
	 *
	 * @apiSuccess {Array} entities array of dolibarr available entities
	 *
	 * @apiDescription Check if your token is already valid
	 */
	public function ping($arr = null)
	{
		dol_syslog("Debug smartauth::AuthController : ping");
		$decoded = $this->check();

		//TODO dev time !!!!
		if (!empty($decoded->login)) {
			$ret = [
				'data' => [
					'token' => 'success',
				]
			];
			return ([$ret, 200]);
		}
		return (["Generic error", 401]);
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

		$jwt = $this->_newUserKey($tmpuser->id, $login, $entity);

		// Renew the hash ?
		// Generate token for user
		$result = $tmpuser->call_trigger('USER_LOGIN', $tmpuser);

		$rememberme  = (int) $payload['rememberMe']  ?? '';

		dol_syslog("Debug smartauth : AuthController::login : return 200 with user=" . $tmpuser->id . ", " . json_encode($tmpuser));
		$user = $tmpuser->email;

		if (empty($tmpuser->email)) {
			$user = $tmpuser->login;
		}
		$ret = [
			'data' => [
				'user' => $user,
				'userid' => $tmpuser->id,
				'entity' => $entity,
				'token' => $jwt,
				'rememberMe' => $rememberme
			]
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
			//soft delete token from db
			$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
			$sql .= " SET status = " . self::STATUS_LOGOUT;
			$sql .= ", salt = 'xxxxxxxxxx' ";
			$sql .= " WHERE rowid = " . (int) $payload['tokenid'];
			dol_syslog("smartauth : disable token from db " . $sql);
			$resql = $db->query($sql);
			if ($resql) {
				dol_syslog("smartauth : disable token from db success");
			} else {
				dol_syslog("smartauth : disable token from db error");
			}
		}

		$result = $user->call_trigger('USER_LOGOUT', $user);

		$ret = [
			'data' => [
				'user' => '',
				'token' => ''
			]
		];
		return ([$ret, 200]);
	}

	/**
	 * check if token is correct
	 *
	 * @return  StdObject  decoded token
	 */
	public static function check()
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $conf;

		$jwt = self::getBearerToken();
		if (empty($jwt)) {
			json_reply('Access denied (protected route)', 401);
		}

		$tokenid = null;
		$salt = "";
		//add salt from client's unique id / other from user agent to avoid reuse of token on an other device
		$salt2 = self::getSalt2();

		if (strpos($jwt, '|') > 0) {
			$tokenid = substr($jwt, 0, strpos($jwt, '|'));
			//remove id from jwt for JWT::lib
			$jwt = substr($jwt, strpos($jwt, '|') + 1);
			if (!is_numeric($tokenid)) {
				json_reply('Access denied (invalid token)', 401);
			}
			if (!isset($conf->cache['smartmakers']['token-' . $tokenid])) {
				//get salt from db
				$sql = "SELECT salt";
				$sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_auth";
				$sql .= " WHERE rowid = " . (int) $tokenid;
				$sql .= " AND status=" . self::STATUS_VALID;

				dol_syslog("smartauth : get salt from db " . $sql);
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					$conf->cache['smartmakers']['token-' . $tokenid] = $obj->salt;
				}
			}
			$salt = $conf->cache['smartmakers']['token-' . $tokenid];
		}

		if (is_null($tokenid)) {
			dol_syslog("smartauth : access denied token not found", LOG_ERR);
			json_reply('Access denied (token not found)', 401);
		}

		dol_syslog("smartauth : salt from db is $salt, and jwt $jwt");
		$key = $salt . $salt2 . $smartAuthAppKey;

		dol_syslog("smartauth : secure key is " . $key);
		try {
			$decoded = JWT::decode($jwt, new Key($key, 'HS256'));
			$decoded->tokenid = $tokenid;
		} catch (SignatureInvalidException $e) {
			dol_syslog("Debug smartauth : jwt signature error : reset token please", LOG_ERR);
			json_reply('invalid token, please login', 401);
		} catch (Exception $e) {
			dol_syslog("Debug smartauth : jwt signature error : " . $e->getMessage());
			json_reply('invalid token, please login', 401);
		}
		dol_syslog("Debug smartauth : route decoded jwt is " . json_encode($decoded));

		//$decoded->login == user auth
		//$decoded->socid == soc auth (for obapi for example)
		if (empty($decoded->login) && empty($decoded->socid)) {
			dol_syslog("smartauth : login not found, return 401" . $sql, LOG_ERR);
			json_reply('Access denied (login not found)', 401);
		}

		//TODO ajouter des verifs de temps / clé périmée / utilisée sur un type de navigateur (signature browser) toussa
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET date_lastused = '" . $db->idate(dol_now()) . "',";
		$sql .= " date_eol = '" . $db->idate(dol_now() + 60 * 60 * 24 * getDolGlobalInt('SMARTAUTH_TOKEN_EOL_DAYS', 30)) . "',";
		$sql .= " ip = '" . self::get_client_ip() . "' ";
		$sql .= " WHERE rowid = " . (int) $tokenid;
		dol_syslog("smartauth : update token last used " . $sql);
		$resql = $db->query($sql);
		if ($resql) {
			//
		} else {
			dol_syslog("smartauth : update token impossible ! return 401" . $sql, LOG_ERR);
			json_reply('Access denied', 401);
		}

		return $decoded;
	}

	/**
	 * create a new salt stored into database and a key
	 *
	 * @param   [type]  $uid     [$uid description]
	 * @param   [type]  $entity  [$entity description]
	 *
	 * @return  [type]           [return description]
	 */
	private function _newUserKey($uid, $login, $entity)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $SERVER;
		dol_syslog("Debug smartauth : AuthController::_newUserKey for $uid / $login / $entity");

		$keyid = $salt = '';
		//remove all other token for that user and that app ?
		//depends on setup ?
		// TODO
		// $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		// $sql .= " SET status = 9,";
		// $sql .= " salt = 'xxxxxxxxxx' ";
		// $sql .= " WHERE appuid=" . (int) $smartAuthAppID;
		// $sql .= " AND fk_authid=" . (int) $uid;
		// $sql .= " AND auth_element='user'";
		// $sql .= " AND entity=" . (int) $entity;
		// $resql = $db->query($sql);
		// dol_syslog("Debug smartauth : $sql ...");

		//store a new one
		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		$salt2 = $this->getSalt2();

		$sql = "INSERT ";
		$sql .= " INTO " . MAIN_DB_PREFIX . "smartauth_auth(appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, auth_element, ip, status, entity)";
		$sql .= " VALUES ('" . (int) $smartAuthAppID . "','" . $salt . "','" . $db->idate(dol_now()) . "','" . $db->idate(dol_now() + 60 * 60 * 24 * getDolGlobalInt('SMARTAUTH_TOKEN_EOL_DAYS', 30)) . "','" . (int) $uid . "','" . (int) $uid . "','user','" . $this->get_client_ip() . "'," . self::STATUS_VALID . ",'" . (int) $entity . "');";
		$resql = $db->query($sql);
		if ($resql) {
			$keyid = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
			// dol_syslog("Debug smartauth : $sql ...");
			$key = $salt . $salt2 . $smartAuthAppKey;

			$payload = array(
				"login"  => $login,
				"entity" => $entity
			);
			$jwt = JWT::encode($payload, $key, 'HS256');

			if (!empty($keyid)) {
				$new = $keyid . '|' . $jwt;
				$jwt = $new;
			}
		}

		dol_syslog("Debug smartauth : AuthController::_newUserKey return");
		return $jwt;
	}

	/**
	 * create a new salt stored into database and a key for thirdpart account
	 *
	 * @param   [type]  $uid     [$uid description]
	 * @param   [type]  $entity  [$entity description]
	 *
	 * @return  [type]           [return description]
	 */
	public function newThirdpartKey($socid, $socname, $entity)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey, $SERVER;
		dol_syslog("Debug smartauth : AuthController::_newThirdpartKey");

		$keyid = $salt = '';
		//remove all other token for that user and that app
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET status = " . self::STATUS_LOGOUT;
		$sql .= ", salt = 'xxxxxxxxxx' ";
		$sql .= " WHERE appuid=" . (int) $smartAuthAppID;
		$sql .= " AND fk_authid=" . (int) $socid;
		$sql .= " AND auth_element='societe_account'";
		$sql .= " AND entity=" . (int) $entity;
		$resql = $db->query($sql);
		// dol_syslog("Debug smartauth : $sql ...");

		$useractions = $this->_FetchUserWithRights();

		//store a new one
		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		$salt2 = $this->getSalt2();

		$sql = "INSERT ";
		$sql .= " INTO " . MAIN_DB_PREFIX . "smartauth_auth(appuid, salt, date_creation, date_eol, fk_user_creat, fk_authid, auth_element, ip, status, entity)";
		$sql .= " VALUES ('" . (int) $smartAuthAppID . "','" . $salt . "','" . $db->idate(dol_now()) . "','" . $db->idate(dol_now() + 60 * 60 * 24 * getDolGlobalInt('SMARTAUTH_TOKEN_EOL_DAYS', 30)) . "','" . (int) $useractions->id . "','" . (int) $socid . "','societe_account','" . $this->get_client_ip() . "',1,'" . (int) $entity . "');";
		$resql = $db->query($sql);
		if ($resql) {
			$keyid = $db->last_insert_id(MAIN_DB_PREFIX . "smartauth_auth");
			// dol_syslog("Debug smartauth : $sql ...");
			$key = $salt . $salt2 . $smartAuthAppKey;

			$payload = array(
				"socid"  => $socid,
				"entity" => $entity
			);
			$jwt = JWT::encode($payload, $key, 'HS256');

			if (!empty($keyid)) {
				$new = $keyid . '|' . $jwt;
				$jwt = $new;
			}
		}

		dol_syslog("Debug smartauth : AuthController::_newThirdpartKey return");
		return $jwt;
	}

	private static function getAuthorizationHeader()
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

	private static function getBearerToken()
	{
		$headers = self::getAuthorizationHeader();
		dol_syslog("Debug smartauth : _getBearerToken");

		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				dol_syslog("Debug smartauth : _getBearerToken, matches, return " . $matches[1]);
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
		$def = [];

		if (isModEnabled('multicompany')) {
			$sql = "SELECT DISTINCT(rowid), rang";
			$sql .= " FROM " . MAIN_DB_PREFIX . "entity";
			$sql .= " WHERE active = 1";
			$sql .= " AND visible = 1";
			$sql .= " ORDER BY rang ASC, rowid ASC";

			$resql = $db->query($sql);
			if ($resql) {
				$i = 0;
				$num_rows = $db->num_rows($resql);
				while ($i < $num_rows) {
					$array = $db->fetch_array($resql);
					array_push($def, $array[0]);
					$i++;
				}
			}
		}
		return $def;
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

	private static function getSalt2()
	{
		// Check for X-App-ID header (future-proof)
		$appId = $_SERVER['HTTP_X_APP_ID'] ?? '';
		if (!empty($appId) && preg_match('/^[a-f0-9\-]{36}$/i', $appId)) {
			return substr(hash('sha256', $appId), 0, 16);
		}

		// Fallback to User-Agent (works now)
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
		return substr(hash('sha256', $ua), 0, 16);
	}
}
