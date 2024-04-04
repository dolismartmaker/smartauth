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

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use User;

class AuthController
{
	/**
	 * @api {get} /login List of dolibarr entities
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
		$data = $arr['data'];
		$ret = [
		   'statusCode' => 200,
		   'data' => [
			   'entities' => _api_GetListOfEntities(),
		   ]
		];
		return([$ret, 200]);
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
	 *     "statusCode": 200,
	 *     "data": {
	 *         "user": "eric@cap-rel.fr",
	 *         "userid": "3",
	 *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUz88NiJ9.eyJsb2dpbiI622RsYyIsImVu88l0eSI6MH0._XWcHLf999kMqkP65dgXcbkqT522W9zbdUiIA3BU0pI"
	 *     }
	 *  }
	 */
	public function login($arr)
	{
		dol_syslog("Debug smartauth::AuthController : login");
		global $db;
		$data = $arr['data'];
		// dol_syslog("Debug smartauth : AuthController::login : data is " . json_encode($data));
		// dol_syslog("Debug smartauth : AuthController::login : arr is " . json_encode($arr));

		$entity = (int) $data['entity'] ?? 1;
		$login  = filter_var($data['email'], FILTER_SANITIZE_STRING) ?? '';
		if (empty($login)) {
			//try old username field
			$login  = filter_var($data['username'], FILTER_SANITIZE_STRING);
		}
		$pass   = filter_var($data['password'], FILTER_SANITIZE_STRING);
		//check if login / pass is ok
		include_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
		$login = checkLoginPassEntity($login, $pass, $entity, ['dolibarr'], 'api');		// Check credentials.
		if ($login === '--bad-login-validity--') {
			$login = '';
		}
		if (empty($login)) {
			dol_syslog("Debug smartauth : AuthController::login : login empty");
			json_reply('Access denied (login empty)', 401);
		}

		$tmpuser = new User($db);
		$tmpuser->fetch(0, $login, 0, 0, $entity);
		if (empty($tmpuser->id)) {
			dol_syslog("Debug smartauth : AuthController::login : failed to load user");
			json_reply('Failed to load user', 401);
		}

		list($keyid, $key) = $this->_newKey($tmpuser->id, $entity);

		$payload = array(
		   "login"  => $tmpuser->login,
		   "entity" => $entity
		);
		$jwt = JWT::encode($payload, $key, 'HS256');

		if (!empty($keyid)) {
			$new = $keyid . '|' . $jwt;
			$jwt = $new;
		}

		// Renew the hash ?
		// Generate token for user

		dol_syslog("Debug smartauth : AuthController::login : return 200 with user=" . $tmpuser->id . ", " . json_encode($tmpuser));
		$user = $tmpuser->email;
		if (empty($tmpuser->email)) {
			$user = $tmpuser->login;
		}
		$ret = [
		   'statusCode' => 200,
		   'data' => [
			   'user' => $user,
			   'userid' => $tmpuser->id,
			   'token' => $jwt
		   ]
		];
		return([$ret, 200]);
	}

	/**
	 * @api {post} /logout Logout
	 * @apiDescription Logout and close session
	 * @apiName PostLogout
	 * @apiGroup Auth
	 *
	 */
	public function logout($arr)
	{
		dol_syslog("Debug smartauth::AuthController : logout");
		global $db;
		$user = $arr['user'];
		if (!empty($arr['tokenid'])) {
			//delete token from db
			$sql = "DELETE ";
			$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
			$sql .= " WHERE rowid = ". (int) $arr['tokenid'];
			dol_syslog("smartauth : delete token from db " . $sql);
			$resql = $db->query($sql);
			if ($resql) {
				dol_syslog("smartauth : delete token from db success");
			} else {
				dol_syslog("smartauth : delete token from db error");
			}
		}

		$ret = [
			'statusCode' => 200,
			'data' => [
				'user' => '',
				'token' => ''
			]
		];
		return([$ret, 200]);
	}

	/**
	 * check if token is correct
	 *
	 * @return  StdObject  decoded token
	 */
	public static function check()
	{
		global $db, $smartAuthAppID, $smartAuthAppKey;

		$jwt = self::getBearerToken();
		if (empty($jwt)) {
			json_reply('Access denied (protected route)', 401);
		}

		$tokenid=null;
		$salt = "";
		//add salt from client's unique id / other from user agent to avoid reuse of token on an other device
		$salt2 = substr(crc32($_SERVER['HTTP_USER_AGENT']), 0, 16);

		if (strpos($jwt, '|') > 0) {
			$tokenid = substr($jwt, 0, strpos($jwt, '|'));
			//remove id from jwt for JWT::lib
			$jwt = substr($jwt, strpos($jwt, '|')+1);
			if (!is_numeric($tokenid)) {
				json_reply('Access denied (invalid token)', 401);
			}
			//get salt from db
			$sql = "SELECT salt";
			$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
			$sql .= " WHERE rowid = ". (int) $tokenid;
			dol_syslog("smartauth : get salt from db " . $sql);
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				$salt = $obj->salt;
			}
		}

		if(is_null($tokenid)) {
			dol_syslog("smartauth : access dened token not found", LOG_ERR);
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
			$ret = [
				'statusCode' => 401,
				'data' => [
					'message' => 'invalid token, please login'
				]
			];
			json_reply($ret, 401);
		} catch (Exception $e) {
			dol_syslog("Debug smartauth : jwt signature error : " . $e->getMessage());
			$ret = [
				'statusCode' => 401,
				'data' => [
					'message' => 'invalid token, please login'
				]
			];
			json_reply($ret, 401);
		}
		dol_syslog("Debug smartauth : route decoded jwt is " . json_encode($decoded));
		if (empty($decoded->login)) {
			dol_syslog("smartauth : login not found, return 401" . $sql, LOG_ERR);
			json_reply('Access denied (login not found)', 401);
		}

		//TODO ajouter des verifs de temps / clé périmée / utilisée sur un type de navigateur (signature browser) toussa
		$sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth";
		$sql .= " SET date_lastused = '". $db->idate(dol_now()) . "', ";
		$sql .= " ip = '". $_SERVER['REMOTE_ADDR'] . "' ";
		$sql .= " WHERE rowid = ". (int) $tokenid;
		dol_syslog("smartauth : update token last used " . $sql);
		$resql = $db->query($sql);
		if ($resql) {
			//
		} else {
			dol_syslog("smartauth : update token impossible ! return 401" . $sql, LOG_ERR);
			json_reply('Access denied (login not found)', 401);
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
	private function _newKey($uid, $entity)
	{
		global $db, $smartAuthAppID, $smartAuthAppKey;
		dol_syslog("Debug smartauth : AuthController::_newkey");

		$id = $salt = '';
		//remove all other token for that user and that app
		$sql = "DELETE ";
		$sql .= " FROM ".MAIN_DB_PREFIX."smartauth_auth";
		$sql .= " WHERE appuid=".(int) $smartAuthAppID;
		$sql .= " AND fk_user_creat=".(int) $uid;
		$sql .= " AND entity=".(int) $entity;
		$resql = $db->query($sql);
		dol_syslog("Debug smartauth : $sql ...");

		//store a new one
		$salt = substr(bin2hex(random_bytes(32)), 0, 32);
		//add salt from client's unique id / other from user agent to avoid reuse of token on an other device
		$salt2 = substr(crc32($_SERVER['HTTP_USER_AGENT']), 0, 16);

		$sql = "INSERT ";
		$sql .= " INTO ".MAIN_DB_PREFIX."smartauth_auth(appuid, salt, date_creation, date_eol, fk_user_creat, ip, status, entity)";
		$sql .= " VALUES ('".(int) $smartAuthAppID . "','" . $salt . "','" . $db->idate(dol_now()) . "','" . $db->idate(dol_now()+60*60*24*30) . "','" . (int) $uid . "','" . $SERVER['REMOTE_ADDR'] . "',1,'" . (int) $entity . "');";
		$resql = $db->query($sql);
		if ($resql) {
			$id = $db->last_insert_id(MAIN_DB_PREFIX."mailing");
		} else {
			$salt = "";
		}
		dol_syslog("Debug smartauth : $sql ...");
		$key = $salt . $salt2 . $smartAuthAppKey;
		dol_syslog("Debug smartauth : AuthController::_newkey return $id / $key ...");
		return [$id, $key];
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
}
