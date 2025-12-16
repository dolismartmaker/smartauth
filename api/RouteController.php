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

use User;
use Exception;
use SmartAuth\Api\AuthController;

class RouteController
{
	/**
	 * Register a GET route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool    $protected          Whether JWT authentication is required
	 *
	 * @return  void
	 */
	public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		self::route('GET', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a POST route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool    $protected          Whether JWT authentication is required
	 *
	 * @return  void
	 */
	public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		self::route('POST', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a PUT route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool    $protected          Whether JWT authentication is required
	 *
	 * @return  void
	 */
	public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		self::route('PUT', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a DELETE route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool    $protected          Whether JWT authentication is required
	 *
	 * @return  void
	 */
	public static function delete($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		self::route('DELETE', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Main routing dispatcher that handles HTTP requests
	 *
	 * This method:
	 * - Validates HTTP method matches the target
	 * - Parses the request URI and extracts parameters
	 * - Handles authentication for protected routes
	 * - Loads user and entity context
	 * - Executes the target controller method
	 * - Logs all requests for audit purposes
	 *
	 * @param   string  $targetMethod       Expected HTTP method (GET, POST, PUT, DELETE)
	 * @param   string  $targetAction       URL pattern with optional placeholders like '/users/{id}'
	 * @param   string  $targetClass        Fully qualified controller class name
	 * @param   string  $redirectFunction   Method name to invoke on controller
	 * @param   bool    $protected          If true, requires valid JWT token
	 *
	 * @return  void                        Outputs JSON response and exits
	 */
	public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		global $conf, $db, $user, $buyer, $mysoc; //global user super important pour propager les droits de l'utilisateur connecté
		$user = $entity = $auth_socid = null;
		$buyer = new \Societe($db);

		// note: uri is like /action/ but with rewrite rules it's /index.php/action
		$action = "";
		$method = $_SERVER['REQUEST_METHOD'];
		if ($method !== $targetMethod) {
			return;
		}

		// Parse action from URI
		$action = self::parseAction();
		if ($action === false) {
			self::insertLogs(null, 400, 'Bad request URI', null);
			\json_reply('Bad request', 400);
			return;
		}

		// Match action against target pattern
		if (!self::matchAction($action, $targetAction)) {
			dol_syslog("Debug smartauth  Route does not match: $action != $targetAction");
			return;
		}

		dol_syslog("Debug smartauth  Route matched: method=$method, action=$action, target=$targetAction");

		// Parse request data
		$data = self::parseRequestData($method);

		// Extract URL parameters
		$data = self::extractUrlParameters($targetAction, $action, $data);

		// Authentication and authorization
		$authContext = self::handleAuthentication($protected, $db, $conf, $mysoc);
		if ($authContext === false) {
			return; // Error already handled
		}

		list($user, $entity, $token_id, $buyer, $family_id, $device_id) = $authContext;

		// Execute controller action
		self::executeAction(
			$targetClass,
			$redirectFunction,
			$data,
			$user,
			$entity,
			$token_id,
			$buyer,
			$family_id,
			$device_id
		);
	}

	/**
	 * Parse and extract the action path from REQUEST_URI
	 *
	 * Removes the 'api.php/' prefix to get the clean action path.
	 * Example: '/api.php/users/123' becomes 'users/123'
	 *
	 * @return  string|false    The action path or false on error
	 */
	private static function parseAction()
	{
		if (!isset($_SERVER['REQUEST_URI'])) {
			return false;
		}

		$uri = $_SERVER['REQUEST_URI'];
		$action = parse_url(preg_replace("/.*api.php\//", "", $uri), PHP_URL_PATH);

		return $action !== false ? $action : false;
	}

	/**
	 * Match request action against target pattern using regex
	 *
	 * Supports URL placeholders like {id}, {code}, etc.
	 * Example: pattern '/users/{id}' matches '/users/123'
	 *
	 * @param   string  $action         Actual request path from parseAction()
	 * @param   string  $targetAction   Pattern with optional {placeholder} syntax
	 *
	 * @return  bool                    True if action matches pattern
	 */
	private static function matchAction($action, $targetAction)
	{
		// Convert {param} to regex wildcards
		$pattern = str_replace('/', '\/', preg_replace("/{[^}]+}/", "[^/]+", $targetAction));

		return preg_match("/^" . $pattern . "$/", $action) === 1;
	}

	/**
	 * Parse and extract request data based on HTTP method
	 *
	 * - GET: extracts query string parameters from $_GET
	 * - POST/PUT/DELETE: parses JSON body from php://input
	 * - Validates JSON syntax and filters malicious input
	 *
	 * @param   string  $method     HTTP method (GET, POST, PUT, DELETE)
	 *
	 * @return  array               Associative array of request parameters
	 */
	private static function parseRequestData($method)
	{
		$user = null;
		$data = [];

		if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
			$raw = file_get_contents('php://input');
			if ($raw !== false && $raw !== '') {
				$decoded = json_decode($raw, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					$data = $decoded;
				} else {
					dol_syslog("Debug smartauth  JSON decode error: " . json_last_error_msg(), LOG_WARNING);
				}
			}
		} elseif ($method === 'GET') {
			// Filter and sanitize GET parameters
			foreach ($_GET as $key => $value) {
				if (is_string($key) && strlen($key) < 100) { // Basic validation
					$data[$key] = $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Extract URL parameters from placeholder syntax
	 *
	 * Converts URL placeholders into associative array entries.
	 * Example:
	 *   targetAction: '/users/{id}/posts/{postid}'
	 *   action: '/users/123/posts/456'
	 *   Returns: ['id' => '123', 'postid' => '456']
	 *
	 * @param   string  $targetAction   Pattern with {placeholder} syntax
	 * @param   string  $action         Actual request path
	 * @param   array   $data           Existing data array to merge into
	 *
	 * @return  array                   Data array with extracted parameters
	 */
	private static function extractUrlParameters($targetAction, $action, $data)
	{
		if (strpos($targetAction, '{') === false) {
			return $data;
		}

		// Extract parameter names from {name} placeholders
		preg_match_all("/\{(\w+)\}/", $targetAction, $matches);
		$paramNames = $matches[1];

		if (empty($paramNames)) {
			return $data;
		}

		// Get the prefix before first placeholder
		$prefix = substr($targetAction, 0, strpos($targetAction, '{'));

		// Remove prefix from action to get parameter values
		$valuePart = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $action);
		$paramValues = array_filter(explode('/', $valuePart));

		// Map parameter names to values
		$i = 0;
		foreach ($paramNames as $name) {
			$data[$name] = $paramValues[$i] ?? '';
			$i++;
		}
		return $data;
	}


	/**
	 * Handle JWT authentication and load user context
	 *
	 * For protected routes:
	 * - Validates JWT token via AuthController::Check()
	 * - Loads Dolibarr User object
	 * - Sets entity context and reloads configuration
	 * - Loads associated third-party (Societe) if exists
	 *
	 * For public routes:
	 * - Returns empty context (null user/entity)
	 *
	 * @param   bool        $protected  Whether authentication is required
	 * @param   Database    $db         Dolibarr database connection
	 * @param   Conf        $conf       Dolibarr configuration object
	 * @param   Societe     $mysoc      Dolibarr company object
	 *
	 * @return  array|false             [User, entity, token_id, Societe] or false on auth error
	 */
	private static function handleAuthentication($protected, $db, $conf, $mysoc)
	{
		$user = null;
		$entity = null;
		$token_id = null;
		$buyer = new \Societe($db);

		if (!$protected) {
			return [$user, $entity, $token_id, $buyer];
		}

		$ac = new AuthController();

		// Check JWT token
		try {
			$decoded = $ac->Check();
		} catch (Exception $e) {
			dol_syslog("Debug smartauth  Auth check failed: " . $e->getMessage(), LOG_WARNING);
			self::insertLogs(null, 401, 'Authentication failed', null);
			\json_reply('Authentication required', 401);
			return false;
		}

		$entity = $decoded->entity ?? null;
		$login = $decoded->login ?? null;
		$token_id = $decoded->token_id ?? null;
		//TODO ? put into payload ? check for other data ?
		$family_id = $decoded->family_id ?? null;
		$device_id = $decoded->device_id ?? null;

		if (!$login) {
			self::insertLogs($token_id, 401, 'Invalid token', $entity);
			\json_reply('Invalid token', 401);
			return false;
		}

		// Load user
		$user = new User($db);
		$res = $user->fetch(0, $login, 0, 0, $entity);
		if ($res <= 0) {
			dol_syslog("Debug smartauth  User not found: login=$login, entity=$entity");
			self::insertLogs($token_id, 401, 'User not found', $entity);
			\json_reply('Authentication failed', 401);
			return false;
		}

		// Set user entity
		$user->entity = $entity;
		$_SESSION["dol_entity"] = $entity;
		$conf->entity = $entity;

		// Reload configuration for entity
		$conf->setValues($db);
		$mysoc->setMysoc($conf);
		// Load buyer (third-party) if user is attached to one
		if (!empty($user->socid)) {
			$res = $buyer->fetch($user->socid);
			if (!$res) {
				dol_syslog("Debug smartauth  Failed to load buyer socid=" . $user->socid, LOG_ERR);
				self::insertLogs($token_id, 403, 'Buyer load error', $entity);
				\json_reply('Access denied', 403);
				return false;
			}
		}

		dol_syslog("Debug smartauth handleAuthentication return entity=$entity, token_id=$token_id");
		return [$user, $entity, $token_id, $buyer, $family_id, $device_id];
	}
	/**
	 * Execute the target controller method with proper error handling
	 *
	 * Process:
	 * - Validates target class exists
	 * - Validates target method exists on class
	 * - Builds payload with request data and context
	 * - Invokes controller method
	 * - Validates response format [message, httpCode]
	 * - Logs execution and returns JSON response
	 *
	 * @param   string      $targetClass        Controller class name
	 * @param   string      $redirectFunction   Method name to call
	 * @param   array       $data               Request parameters
	 * @param   User|null   $user               Authenticated user or null
	 * @param   int|null    $entity             Dolibarr entity ID
	 * @param   int|null    $token_id           JWT token ID for logging
	 * @param   Societe     $buyer              Third-party object
	 * @param   int|null    $family_id          Token family
	 * @param   int|null    $device_id          Device ID
	 *
	 * @return  void                            Outputs JSON and exits
	 */
	private static function executeAction($targetClass, $redirectFunction, $data, $user, $entity, $token_id, $buyer, $family_id, $device_id)
	{
		dol_syslog("Debug smartauth executeAction: $targetClass, redirectFunction=$redirectFunction, token_id=$token_id, family_id=$family_id, device_id=$device_id");

		// Validate class exists
		if (!class_exists($targetClass)) {
			dol_syslog("Debug smartauth  Class ($targetClass) not found", LOG_ERR);
			self::insertLogs($token_id, 500, 'Internal error - Class not found', $entity);
			\json_reply('Internal server error - Class not found', 500);
			return;
		}

		$class = new $targetClass();

		// Validate method exists
		if (!method_exists($class, $redirectFunction)) {
			dol_syslog("Debug smartauth  Method not found: $targetClass::$redirectFunction", LOG_ERR);
			self::insertLogs($token_id, 500, 'Internal error', $entity);
			\json_reply('Internal server error - Method not found', 500);
			return;
		}

		// Build payload
		$payload = [
			'user' => $user,
			'entity' => $entity,
			'token_id' => $token_id,
			'buyer' => $buyer,
			'family_id' => $family_id,
			'device_id' => $device_id,
		];

		// Flatten data into payload for easier access
		foreach ($data as $key => $value) {
			if (!isset($payload[$key])) { // Don't override main keys
				$payload[$key] = $value;
			}
		}

		// Execute action
		try {
			$result = $class->$redirectFunction($payload);

			// Validate response format
			if (!is_array($result) || count($result) !== 2) {
				dol_syslog("Debug smartauth  Invalid response format from $targetClass::$redirectFunction", LOG_ERR);
				self::insertLogs($token_id, 500, 'Internal error', $entity);
				\json_reply('Internal server error - Invalid response format', 500);
				return;
			}

			list($message, $code) = $result;

			self::insertLogs($token_id, $code, $message, $entity);
			\json_reply($message, $code);
		} catch (Exception $e) {
			dol_syslog("Debug smartauth  Exception in $targetClass::$redirectFunction: " . $e->getMessage(), LOG_ERR);
			self::insertLogs($token_id, 500, 'Exception: ' . $e->getMessage(), $entity);
			\json_reply('Internal server error - Exception', 500);
		}
	}

	/**
	 * Insert API request log entry into database
	 *
	 * Logs include:
	 * - Token ID, app UID, entity
	 * - Client IP (with proxy support)
	 * - HTTP method and status code
	 * - Request URL and user agent
	 * - Response size in bytes
	 *
	 * Only logs when SMARTAUTH_COLLECT_LOGS configuration is enabled.
	 * SQL values are properly escaped to prevent injection.
	 *
	 * @param   int|null    $keyid      JWT token ID (null for unauthenticated requests)
	 * @param   int         $status     HTTP status code (200, 401, 404, etc.)
	 * @param   string      $message    Response message or error description
	 * @param   int         $entity     Dolibarr entity ID
	 * @param   string      $element    Dolibarr element type (optional)
	 *
	 * @return  void
	 */
	public static function insertLogs($keyid, $status, $message = "", $entity = 0, $element = "")
	{
		global $db, $smartAuthAppID;

		// Check if logging is enabled
		if (getDolGlobalString('SMARTAUTH_COLLECT_LOGS') == '') {
			dol_syslog("Debug smartauth  do not collect logs");
			return;
		}

		$device_uuid = sanitizeVal($_SERVER['HTTP_X_DEVICEID']);

		$ac = new AuthController();
		$deviceid = $ac->getDeviceIDFromUUID($device_uuid);
		if (empty($deviceid) || $deviceid <= 0) {
			$deviceid = '-1';
		}

		// Always log, even without keyid (for failed auth attempts)
		$arr = [
			'fk_key' => $keyid ?? 0,
			'appuid' => $smartAuthAppID ?? '',
			'entity' => (int)$entity,
			'dol_element' => substr($element, 0, 32),
			'ip' => substr(self::get_client_ip(), 0, 20),
			'method' => substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 8),
			'http_status' => (int) $status,
			'bytes_sent' => strlen(serialize($message)),
			'content_type' => "json",
			'url_requested' => substr(preg_replace("/.*api.php/", "", $_SERVER['REQUEST_URI'] ?? ''), 0, 255),
			'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? "", 0, 100),
			'fk_device_id' => $deviceid,
			'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255),
		];
		// Escape values for SQL injection prevention
		$escapedValues = array_map(function ($val) use ($db) {
			return $db->escape($val);
		}, $arr);
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_logs (";
		$sql .= implode(',', array_keys($arr));
		$sql .= ") VALUES ('" . implode("','", $escapedValues) . "')";
		try {
			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog("Debug smartauth  Failed to insert log: " . $db->lasterror(), LOG_WARNING);
			}
		} catch (Exception $e) {
			dol_syslog("Debug smartauth  Log insertion error: " . $e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Get real client IP address with proxy support
	 *
	 * Handles various proxy configurations:
	 * - Checks X-Forwarded-For header when behind proxy
	 * - Validates REMOTE_ADDR is not a private IP
	 * - Returns first IP from forwarded chain (most reliable)
	 * - Falls back to REMOTE_ADDR for direct connections
	 *
	 * Private IP ranges detected:
	 * - 127.x.x.x (localhost)
	 * - 10.x.x.x (Class A private)
	 * - 172.16-31.x.x (Class B private)
	 * - 192.168.x.x (Class C private)
	 *
	 * @return  string      Client IP address
	 */
	public static function get_client_ip()
	{
		// Get headers
		$headers = function_exists('apache_request_headers')
			? apache_request_headers()
			: $_SERVER;

		// dol_syslog("get_client_ip :: " . json_encode($headers));

		// Check X-Real-IP header
		if (isset($headers['X-Real-IP'])) {
			$remoteAddr = $headers['X-Real-IP'];
		} elseif (isset($headers['X-Forwarded-For'])) {
			// Check X-Forwarded-For header
			$remoteAddr = $headers['X-Forwarded-For'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Use X-Forwarded-For if present and REMOTE_ADDR is local/private
			$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

			// Check if remote addr is localhost or private IP
			if (
				empty($remoteAddr) ||
				preg_match('/^127\./i', $remoteAddr) ||
				preg_match('/^10\./i', $remoteAddr) ||
				preg_match('/^172\.(1[6-9]|2\d|3[01])\./i', $remoteAddr) ||
				preg_match('/^192\.168\./i', $remoteAddr)
			) {
				// Take first IP from X-Forwarded-For chain
				$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$remoteAddr = trim($ips[0]);
			}
		} else {
			// Default to REMOTE_ADDR
			$remoteAddr = $_SERVER['REMOTE_ADDR'];
		}

		return $remoteAddr;
	}
}
