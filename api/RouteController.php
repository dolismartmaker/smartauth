<?php
/**
 * RouteController.php
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

class RouteController
{
	public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		return self::route('GET',$targetAction, $targetClass, $redirectFunction, $protected);
	}

	public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		return self::route('POST',$targetAction, $targetClass, $redirectFunction, $protected);
	}

	public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		return self::route('PUT',$targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * routage des appels sur l'api
	 *
	 * @param   [type]  $targetMethod      [$targetMethod description]
	 * @param   [type]  $targetAction      [$targetAction description]
	 * @param   [type]  $redirectFunction  [$redirectFunction description]
	 * @param   [type]  $protected         [$protected description]
	 *
	 * @return  [type]                     [return description]
	 */
	public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		global $db, $user; //global user super important pour propager les droits de l'utilisateur connecté
		$user = $entity = null;
		//api.php?action
		$action = "";
		$method = $_SERVER['REQUEST_METHOD'];
		if ($method != $targetMethod) {
			// dol_syslog("Route does not match for $method != $targetMethod");
			return;
		}

		$action = parse_url(preg_replace("/.*api.php\//", "", $_SERVER['REQUEST_URI']), PHP_URL_PATH);
		$match_action = str_replace('/', '\/', preg_replace("/{.*}/", ".*", $targetAction));

		if (! preg_match("/" . $match_action . "$/", $action)) {
			dol_syslog("Route does not REGEX match for $action != $targetAction, match_action=/$match_action/");
			return;
		}

		dol_syslog("Route method=$method, targetMethod=$targetMethod, action=$action and targetAction=$targetAction");
		dol_syslog("Route match_action=$match_action, redirectFunction=$redirectFunction");

		$data = $user = null;
		if ($method == "POST" || $method == "PUT") {
			$txt = file_get_contents('php://input');
			$data = json_decode($txt, true);
			dol_syslog("POST / PUT : $txt :: " . json_encode($data));
		} elseif ($method == "GET") {
			//parse query string and add values to data object
			foreach ($_GET as $key => $value) {
				$data[$key] = $value;
				dol_syslog("Extract GET value $key = $value");
			}
		}
		//other possibilities /{id}/{toto}/...
		if (strpos($targetAction, '{')) {
			preg_match_all("/\{(\w+)\}/", $targetAction, $matches);
			$tags_names = $matches[1];
			$tags_values = explode('/', $action);
			$i = 1;
			foreach ($tags_names as $key) {
				$data[$key] = $tags_values[$i] ?? '';
				$i++;
			}
		}

		//check JWT
		$decoded = $tokenid = null;
		if ($protected) {
			$decoded = AuthController::Check();
			$entity = $decoded->entity;
			$login = $decoded->login;
			$tokenid = $decoded->tokenid;
			$user = new User($db);
			$res = $user->fetch(0, $login, 0, 0, $entity);
			if ($res <= 0) {
				dol_syslog("Debug smartdlc : route auth error : return 401");
				$ret = [
					'statusCode' => 401,
					'data' => [
						'message' => 'login error'
					]
				];
				json_reply($ret, 401);
			}
		}

		dol_syslog("Debug smartdlc : route $targetMethod, class=$targetClass, action=$targetAction, redir=$redirectFunction, protected=$protected, authuserid=" . $data['auth_userid']);
		if ($method == $targetMethod) {
			dol_syslog("Debug smartdlc : route match, call class $targetClass and function $redirectFunction...");
			$class = new $targetClass();
			try {
				list($ret, $code) = $class->$redirectFunction(['data' => $data, 'user' => $user, 'entity' => $entity, 'tokenid' => $tokenid]);
				json_reply($ret, $code);
			} catch (Exception $e) {
				dol_syslog("Debug smartdlc : route exception : " . json_encode($e), LOG_ERR);
			}
		}
		json_reply("error", 403);
	}

	private function _getAuthorizationHeader()
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

	private function _getBearerToken()
	{
		$headers = self::_getAuthorizationHeader();
		dol_syslog("Debug smartdlc : _getBearerToken");
		$matches = [];

		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				dol_syslog("Debug smartdlc : _getBearerToken, matches, return " . $matches[1]);
				return $matches[1];
			}
		}

		dol_syslog("Debug smartdlc : _getBearerToken empty headers, return null");
		return null;
	}
}
