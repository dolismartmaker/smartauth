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
use SmartAuth\Api\InputSanitizer;
use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\LogSanitizer;
use SmartAuth\Api\RouteCache;

class RouteController
{
	/**
	 * Dispatch request using cached routes (optimized)
	 *
	 * This is the main entry point when using route caching.
	 * It finds the matching route from cache and executes it.
	 *
	 * @return bool True if a route was matched and executed
	 */
	public static function dispatch(): bool
	{
		try {
			return self::dispatchInternal();
		} catch (\JsonReplyEmittedError $e) {
			// In PHPUNIT_RUNNING mode, json_reply() throws this Error instead
			// of exit()ing, so the caller knows the response has already been
			// emitted. Return true to signal the route was handled.
			return true;
		}
	}

	private static function dispatchInternal(): bool
	{
		global $conf, $db, $user, $buyer, $mysoc;
		$user = $entity = $auth_socid = null;
		$buyer = new \Societe($db);

		// Handle CORS headers and preflight requests
		self::handleCORS();

		// Emit baseline security headers on every response
		self::emitSecurityHeaders();

		$method = $_SERVER['REQUEST_METHOD'];

		// Parse action from URI
		self::checkCORSConfiguration();

		$action = self::parseAction();
		if ($action === false) {
			self::insertLogs(null, 400, 'Bad request URI', null);
			\json_reply('Bad request', 400);
			return true;
		}

		// Find route in cache
		$route = RouteCache::findRoute($method, $action);
		if ($route === null) {
			return false;
		}

		dol_syslog("SmartAuth Debug smartauth  Route matched (cached): method=$method, action=$action, target=" . $route['action']);

		// Parse request data
		$data = self::parseRequestData($method, $route['action']);

		// Merge URL parameters from cache
		if (!empty($route['params'])) {
			$data = array_merge($data, $route['params']);
		}

		// Authentication and authorization
		$authContext = self::handleAuthentication($route['protected'], $db, $conf, $mysoc);
		if ($authContext === false) {
			return true;
		}

		list($user, $entity, $token_id, $buyer, $family_id, $device_id, $oauthContext) = $authContext;

		// Execute controller action
		self::executeAction(
			$route['class'],
			$route['function'],
			$data,
			$user,
			$entity,
			$token_id,
			$buyer,
			$family_id,
			$device_id,
			$oauthContext
		);

		return true;
	}

	/**
	 * Register a GET route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void
	 */
	public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		// Register in cache if in registration mode
		if (RouteCache::isRegistrationMode()) {
			RouteCache::register('GET', $targetAction, $targetClass, $redirectFunction, $protected);
			return;
		}
		self::route('GET', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a POST route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void
	 */
	public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		if (RouteCache::isRegistrationMode()) {
			RouteCache::register('POST', $targetAction, $targetClass, $redirectFunction, $protected);
			return;
		}
		self::route('POST', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a PUT route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void
	 */
	public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		if (RouteCache::isRegistrationMode()) {
			RouteCache::register('PUT', $targetAction, $targetClass, $redirectFunction, $protected);
			return;
		}
		self::route('PUT', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a DELETE route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void
	 */
	public static function delete($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		if (RouteCache::isRegistrationMode()) {
			RouteCache::register('DELETE', $targetAction, $targetClass, $redirectFunction, $protected);
			return;
		}
		self::route('DELETE', $targetAction, $targetClass, $redirectFunction, $protected);
	}

	/**
	 * Register a PATCH route in the routing table
	 *
	 * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
	 * @param   string  $targetClass        Controller class name to instantiate
	 * @param   string  $redirectFunction   Method name to call on the controller
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void
	 */
	public static function patch($targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		if (RouteCache::isRegistrationMode()) {
			RouteCache::register('PATCH', $targetAction, $targetClass, $redirectFunction, $protected);
			return;
		}
		self::route('PATCH', $targetAction, $targetClass, $redirectFunction, $protected);
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
	 * @param   bool|string $protected      false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 *
	 * @return  void                        Outputs JSON response and exits
	 */
	public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
	{
		global $conf, $db, $user, $buyer, $mysoc; //global user super important pour propager les droits de l'utilisateur connecté
		$user = $entity = $auth_socid = null;
		$buyer = new \Societe($db);

		// note: uri is like /action/ but with rewrite rules it's /index.php/action
		self::handleCORS();

		// note: uri is like /action/ but with rewrite rules it's /index.php/action
		$action = "";
		$method = $_SERVER['REQUEST_METHOD'];
		if ($method !== $targetMethod) {
			return;
		}

		// Parse action from URI
		self::checkCORSConfiguration();

		// Parse action from URI
		$action = self::parseAction();
		if ($action === false) {
			self::insertLogs(null, 400, 'Bad request URI', null);
			\json_reply('Bad request', 400);
			return;
		}

		// Match action against target pattern
		if (!self::matchAction($action, $targetAction)) {
			// dol_syslog("SmartAuth Debug smartauth  Route does not match: $action != $targetAction");
			return;
		}

		dol_syslog("SmartAuth Debug smartauth  Route matched: method=$method, action=$action, target=$targetAction");

		// Parse request data
		$data = self::parseRequestData($method, $targetAction);

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
	 * - Applies sanitization via InputSanitizer middleware
	 *
	 * @param   string  $method     HTTP method (GET, POST, PUT, DELETE)
	 * @param   string|null $targetAction   Route pattern for schema lookup
	 *
	 * @return  array                       Associative array of sanitized request parameters
	 */
	private static function parseRequestData($method, $targetAction = null)
	{
		$data = [];

		if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE' || $method === 'PATCH') {
			$raw = file_get_contents('php://input');
			// Log only the body length and the action target. Logging the
			// raw body (even truncated to 500 chars) would persist passwords
			// and tokens to the syslog when SMARTAUTH_DEBUG is enabled
			//.
			dol_syslog("SmartAuth Debug smartauth parseRequestData: method=$method, raw_length=" . strlen((string) $raw) . ", target=" . (string) $targetAction);
			if ($raw !== false && $raw !== '') {
				$decoded = json_decode($raw, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
					$data = $decoded;
					dol_syslog("SmartAuth Debug smartauth parseRequestData: decoded data keys=" . implode(',', array_keys($data)));
				} else {
					dol_syslog("SmartAuth Debug smartauth  JSON decode error: " . json_last_error_msg(), LOG_WARNING);
				}
			} else {
				dol_syslog("SmartAuth Debug smartauth parseRequestData: raw is empty or false");
			}
		} elseif ($method === 'GET') {
			// Filter and sanitize GET parameters
			foreach ($_GET as $key => $value) {
				if (is_string($key) && strlen($key) < 100) { // Basic validation
					$data[$key] = $value;
				}
			}
		}

		// Apply sanitization middleware
		$data = self::sanitizeRequestData($data, $targetAction, $method);

		return $data;
	}

	/**
	 * Apply sanitization to request data
	 *
	 * Lookup order:
	 *  1. External schemas registered by smartmaker modules via the
	 *     `smartmaker_addValidationSchemas` hook, keyed `METHOD:targetAction`
	 *     (e.g. `PUT:intervention/{id}`) inside `$schemas[<module>][...]`.
	 *  2. Internal schemas mapped via ValidationSchemas::mapRouteToSchema().
	 *  3. Default sanitization (sanitizeAll) when no schema matches.
	 *
	 * @param   array       $data           Raw request data
	 * @param   string|null $targetAction   Route pattern for schema lookup
	 * @param   string|null $method         HTTP method (used to scope external schema lookup)
	 *
	 * @return  array                       Sanitized data
	 */
	private static function sanitizeRequestData(array $data, $targetAction = null, $method = null)
	{
		if (empty($data)) {
			return $data;
		}

		try {
			$schema = null;

			// 1. External schemas: modules may register a schema for any
			// route they own. Keyed by `METHOD:targetAction` (e.g.
			// `PUT:intervention/{id}`). Without this lookup the smartmaker
			// hook documented on ValidationSchemas::loadExternalSchemas()
			// would never fire on real requests.
			if ($targetAction !== null && $method !== null) {
				$lookupKey = strtoupper($method) . ':' . ltrim($targetAction, '/');
				$externalSchemas = ValidationSchemas::loadExternalSchemas();
				foreach ($externalSchemas as $moduleSchemas) {
					if (is_array($moduleSchemas) && isset($moduleSchemas[$lookupKey]) && is_array($moduleSchemas[$lookupKey])) {
						$schema = $moduleSchemas[$lookupKey];
						break;
					}
				}
			}

			// 2. Internal schema fallback (login, device, refresh, ...).
			if ($schema === null) {
				$schemaName = $targetAction ? ValidationSchemas::mapRouteToSchema($targetAction) : 'default';
				$schema = ValidationSchemas::getSchema($schemaName);
			}

			if ($schema !== null && !empty($schema)) {
				// Schema-based sanitization: validate known fields, sanitize unknown ones.
				$sanitized = [];

				// Split exact keys from wildcard prefix keys. A schema entry like
				// `'options_smartphoto_*'` declares rules for every input field
				// whose name starts with `options_smartphoto_`. This is required
				// for Dolibarr extrafield uploads where the field name is built
				// from the extrafield key at runtime.
				$exactRules = [];
				$wildcardRules = [];
				foreach ($schema as $field => $rules) {
					if (is_string($field) && substr($field, -1) === '*') {
						$wildcardRules[substr($field, 0, -1)] = $rules;
					} else {
						$exactRules[$field] = $rules;
					}
				}

				// First, apply schema to known exact fields.
				foreach ($exactRules as $field => $rules) {
					if (array_key_exists($field, $data)) {
						$sanitized[$field] = self::sanitizeField($data[$field], $rules, $field);
					} elseif (isset($rules['default'])) {
						$sanitized[$field] = $rules['default'];
					}
				}

				// Then, walk every other input field. Wildcard prefixes win over
				// the default sanitizer; unknown fields fall back to it.
				// NOTE: `isset()` is used here (not `array_key_exists`) on
				// purpose -- when a typed sanitizer returns null (e.g. EMAIL
				// rejecting a non-email like 'sidemo-jean'), the historical
				// behaviour is to fall back to the default sanitizer with the
				// raw value. Switching to `array_key_exists` would silently
				// break login payloads where `email` carries a username.
				foreach ($data as $key => $value) {
					if (isset($sanitized[$key])) {
						continue;
					}
					$matched = null;
					foreach ($wildcardRules as $prefix => $rules) {
						if (is_string($key) && strncmp($key, $prefix, strlen($prefix)) === 0) {
							$matched = $rules;
							break;
						}
					}
					if ($matched !== null) {
						$sanitized[$key] = self::sanitizeField($value, $matched, $key);
					} else {
						$sanitized[$key] = self::sanitizeUnknownField($key, $value);
					}
				}

				return $sanitized;
			}

			// No specific schema: apply default sanitization to all fields
			return InputSanitizer::sanitizeAll($data);
		} catch (\InvalidArgumentException $e) {
			dol_syslog("SmartAuth Debug smartauth sanitizeRequestData validation error: " . $e->getMessage(), LOG_WARNING);
			// Return empty array on validation error - controller will handle missing required fields
			return [];
		} catch (Exception $e) {
			dol_syslog("SmartAuth Debug smartauth sanitizeRequestData error: " . $e->getMessage(), LOG_ERR);
			// Fallback to default sanitization on unexpected errors
			return InputSanitizer::sanitizeAll($data);
		}
	}

	/**
	 * Sanitize a single field based on schema rules
	 *
	 * @param   mixed   $value  Raw value
	 * @param   array   $rules  Validation rules from schema
	 * @param   string  $field  Field name for error messages
	 *
	 * @return  mixed           Sanitized value
	 */
	private static function sanitizeField($value, array $rules, string $field)
	{
		$type = $rules['type'] ?? InputSanitizer::TYPE_STRING;
		$maxLen = $rules['maxLen'] ?? InputSanitizer::MAX_STRING_LENGTH;

		switch ($type) {
			case InputSanitizer::TYPE_EMAIL:
				return InputSanitizer::sanitizeEmail($value);

			case InputSanitizer::TYPE_UUID:
				return InputSanitizer::sanitizeUUID($value);

			case InputSanitizer::TYPE_INT:
				$int = InputSanitizer::sanitizeInt($value);
				if (isset($rules['min']) && $int < $rules['min']) {
					$int = $rules['min'];
				}
				if (isset($rules['max']) && $int > $rules['max']) {
					$int = $rules['max'];
				}
				return $int;

			case InputSanitizer::TYPE_BOOL:
				return InputSanitizer::sanitizeBool($value);

			case InputSanitizer::TYPE_ALPHANUMERIC:
				return InputSanitizer::sanitizeAlphanumeric($value, $maxLen);

			case InputSanitizer::TYPE_RAW:
				// No sanitization (for passwords, etc.)
				return $value;

			case InputSanitizer::TYPE_ARRAY:
				if (!is_array($value)) {
					return [];
				}
				return InputSanitizer::sanitizeArray($value, $rules['itemType'] ?? InputSanitizer::TYPE_STRING, $rules);

			case InputSanitizer::TYPE_STRING:
			default:
				return InputSanitizer::sanitizeString($value, $maxLen);
		}
	}

	/**
	 * Sanitize an unknown field (not in schema)
	 *
	 * @param   string  $key    Field name
	 * @param   mixed   $value  Raw value
	 *
	 * @return  mixed           Sanitized value
	 */
	private static function sanitizeUnknownField(string $key, $value)
	{
		// Sanitize key first
		$cleanKey = InputSanitizer::sanitizeAlphanumeric($key, InputSanitizer::MAX_SHORT_LENGTH);
		if (empty($cleanKey)) {
			return null;
		}

		// Apply type-appropriate sanitization
		if (is_array($value)) {
			return InputSanitizer::sanitizeAll($value);
		} elseif (is_int($value)) {
			return InputSanitizer::sanitizeInt($value);
		} elseif (is_float($value)) {
			return InputSanitizer::sanitizeFloat($value);
		} elseif (is_bool($value)) {
			return InputSanitizer::sanitizeBool($value);
		} elseif (is_string($value)) {
			return InputSanitizer::sanitizeString($value, InputSanitizer::MAX_STRING_LENGTH);
		}

		return null;
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

		// Split both target and action into segments
		$targetSegments = explode('/', $targetAction);
		$actionSegments = explode('/', $action);

		// Match segments and extract parameter values
		foreach ($targetSegments as $i => $segment) {
			if (preg_match('/^\{(\w+)\}$/', $segment, $match)) {
				// This is a placeholder - extract the value from the action
				$paramName = $match[1];
				$data[$paramName] = $actionSegments[$i] ?? '';
			}
		}

		return $data;
	}


	/**
	 * Handle authentication and load user context
	 *
	 * For protected routes ($protected === true):
	 * - Validates JWT token via AuthController::Check()
	 * - Loads Dolibarr User object
	 * - Sets entity context and reloads configuration
	 * - Loads associated third-party (Societe) if exists
	 *
	 * For OAuth2 routes ($protected === 'oauth2'):
	 * - Validates OAuth2 Bearer token (JWT)
	 * - Loads service user context
	 * - Returns OAuth2-specific metadata (client_id, scopes, grant_type)
	 *
	 * For public routes ($protected === false):
	 * - Returns empty context (null user/entity)
	 *
	 * @param   bool|string $protected  false=public, true=JWT auth, 'oauth2'=OAuth2 Bearer token
	 * @param   \DoliDB     $db         Dolibarr database connection
	 * @param   \Conf       $conf       Dolibarr configuration object
	 * @param   \Societe    $mysoc      Dolibarr company object
	 *
	 * @return  array|false             [User, entity, token_id, Societe, family_id, device_id, oauthContext] or false on auth error
	 */
	private static function handleAuthentication($protected, $db, $conf, $mysoc)
	{
		$user = null;
		$entity = null;
		$token_id = null;
		$buyer = new \Societe($db);

		if ($protected === false || $protected === 0) {
			return [$user, $entity, $token_id, $buyer, null, null, null];
		}

		// OAuth2 Bearer token authentication
		if ($protected === 'oauth2') {
			return self::handleOAuth2Authentication($db, $conf, $mysoc);
		}

		$ac = new AuthController();

		// Check JWT token
		try {
			$decoded = $ac->Check();
		} catch (Exception $e) {
			dol_syslog("SmartAuth Debug smartauth  Auth check failed: " . $e->getMessage(), LOG_WARNING);
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
			dol_syslog("SmartAuth Debug smartauth  User not found: login=$login, entity=$entity");
			self::insertLogs($token_id, 401, 'User not found', $entity);
			\json_reply('Authentication failed', 401);
			return false;
		} else {
			dol_syslog("SmartAuth Debug smartauth  User found: login=$login, entity=$entity, id=" . $user->id);
		}

		// Set user entity
		$user->entity = $entity;
		$_SESSION["dol_entity"] = $entity;
		$conf->entity = $entity;

		// Reload configuration for entity
		$conf->setValues($db);
		$mysoc->setMysoc($conf);

		// Load user permissions
		$user->getrights('',1);

		// Load buyer (third-party) if user is attached to one
		if (!empty($user->socid)) {
			$res = $buyer->fetch($user->socid);
			if (!$res) {
				dol_syslog("SmartAuth Debug smartauth  Failed to load buyer socid=" . $user->socid, LOG_ERR);
				self::insertLogs($token_id, 403, 'Buyer load error', $entity);
				\json_reply('Access denied', 403);
				return false;
			}
		}

		dol_syslog("SmartAuth Debug smartauth handleAuthentication return entity=$entity, token_id=$token_id");
		return [$user, $entity, $token_id, $buyer, $family_id, $device_id, null];
	}
	/**
	 * Handle OAuth2 Bearer token authentication
	 *
	 * Validates an OAuth2 access token (JWT) issued via any OAuth2 grant type.
	 * Loads the associated client and service user context.
	 *
	 * @param   \DoliDB     $db     Dolibarr database connection
	 * @param   \Conf       $conf   Dolibarr configuration object
	 * @param   \Societe    $mysoc  Dolibarr company object
	 *
	 * @return  array|false         [User, entity, token_id, Societe, family_id, device_id, oauthContext] or false
	 */
	private static function handleOAuth2Authentication($db, $conf, $mysoc)
	{
		$buyer = new \Societe($db);

		// Extract Bearer token from Authorization header
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		if (empty($authHeader) && function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			$authHeader = $headers['Authorization'] ?? '';
		}

		if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
			self::insertLogs(null, 401, 'Missing OAuth2 Bearer token', null);
			\json_reply('Authentication required: Bearer token expected', 401);
			return false;
		}

		$jwt = substr($authHeader, 7);

		// Validate JWT via TokenService
		dol_include_once('/smartauth/api/OAuth2/TokenService.php');
		dol_include_once('/smartauth/class/smartauthoauthclient.class.php');

		$tokenService = new \SmartAuth\Api\OAuth2\TokenService($db);

		// Confused-deputy defence: a token minted for a third-party OAuth client
		// (aud = that client_id) must not be accepted on SmartAuth's own
		// privileged API. SMARTAUTH_API_AUDIENCE lists the client_id(s) allowed
		// to call these 'oauth2'-protected routes. When a single audience is
		// configured we hand it to the validator (RFC 8725 aud check); a
		// multi-client allow-list is enforced by membership just below.
		$apiAudienceRaw = getDolGlobalString('SMARTAUTH_API_AUDIENCE', '');
		$allowedAudiences = array_filter(array_map('trim', explode(',', $apiAudienceRaw)));
		$expectedAudience = (count($allowedAudiences) === 1) ? $allowedAudiences[0] : null;

		$payload = $tokenService->validateAccessToken($jwt, $expectedAudience);

		if ($payload === null) {
			self::insertLogs(null, 401, 'Invalid or expired OAuth2 token', null);
			\json_reply('Invalid or expired token', 401);
			return false;
		}

		// Extract OAuth2 metadata from JWT payload
		$clientId = $payload['client_id'] ?? '';
		$scopeString = $payload['scope'] ?? '';
		$scopes = !empty($scopeString) ? explode(' ', $scopeString) : [];
		$grantType = $payload['grant_type'] ?? 'authorization_code';

		// Enforce the first-party audience allow-list (see above). An empty list
		// means enforcement is disabled: log loudly so operators know the
		// confused-deputy gate is open on this instance.
		if (!empty($allowedAudiences)) {
			if (!in_array($clientId, $allowedAudiences, true)) {
				self::insertLogs(null, 401, 'OAuth2 token audience not allowed on first-party API', null);
				dol_syslog('SmartAuth handleOAuth2Authentication: client ' . $clientId . ' not in SMARTAUTH_API_AUDIENCE allow-list - confused-deputy rejection', LOG_WARNING);
				\json_reply('Invalid token: audience not allowed', 401);
				return false;
			}
		} else {
			dol_syslog('SmartAuth handleOAuth2Authentication: SMARTAUTH_API_AUDIENCE not set - first-party audience enforcement disabled', LOG_WARNING);
		}

		// Parse the prefixed subject (acc:/usr:). These protected API routes are
		// user-scoped (they load a Dolibarr \User), so only `user` subjects are
		// accepted here; an `account` (portal) subject is rejected.
		dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
		try {
			$subject = \SmartAuth\Api\OAuth2\TokenSubject::fromSub((string) ($payload['sub'] ?? ''));
		} catch (\InvalidArgumentException $e) {
			self::insertLogs(null, 401, 'Invalid subject in OAuth2 token', null);
			\json_reply('Invalid token: bad subject', 401);
			return false;
		}
		if (!$subject->isUser()) {
			self::insertLogs(null, 401, 'Account subject not allowed on user-scoped route', null);
			\json_reply('Invalid token: account subject not supported here', 401);
			return false;
		}
		$userId = $subject->getId();

		// Load OAuth client and verify it's still active
		$client = new \SmartAuthOAuthClient($db);
		$result = $client->fetch(0, null, $clientId);
		if ($result <= 0 || !$client->isEnabled()) {
			self::insertLogs(null, 401, 'OAuth2 client not found or disabled', null);
			\json_reply('Invalid client', 401);
			return false;
		}

		// Load user
		if ($userId <= 0) {
			self::insertLogs(null, 401, 'Invalid user in OAuth2 token', null);
			\json_reply('Invalid token: missing user', 401);
			return false;
		}

		$user = new User($db);
		$res = $user->fetch($userId);
		if ($res <= 0) {
			self::insertLogs(null, 401, 'OAuth2 user not found', null);
			\json_reply('Authentication failed', 401);
			return false;
		}

		// Set entity context
		$entity = $client->entity;
		$user->entity = $entity;
		$_SESSION["dol_entity"] = $entity;
		$conf->entity = $entity;
		$conf->setValues($db);
		$mysoc->setMysoc($conf);

		// Load user permissions
		$user->getrights('', 1);

		// Load buyer if user is attached to a third-party
		if (!empty($user->socid)) {
			$res = $buyer->fetch($user->socid);
			if (!$res) {
				dol_syslog("SmartAuth Debug smartauth OAuth2: Failed to load buyer socid=" . $user->socid, LOG_ERR);
			}
		}

		$oauthContext = [
			'client_id' => $clientId,
			'scopes' => $scopes,
			'grant_type' => $grantType,
		];

		$jti = $payload['jti'] ?? null;

		dol_syslog("SmartAuth Debug smartauth handleOAuth2Authentication: client=$clientId, user=" . $user->id . ", grant=$grantType");

		return [$user, $entity, $jti, $buyer, null, null, $oauthContext];
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
	 * @param   array|null  $oauthContext       OAuth2 context (client_id, scopes, grant_type) or null
	 *
	 * @return  void                            Outputs JSON and exits
	 */
	private static function executeAction($targetClass, $redirectFunction, $data, $user, $entity, $token_id, $buyer, $family_id, $device_id, $oauthContext = null)
	{
		dol_syslog("SmartAuth Debug smartauth executeAction: $targetClass, redirectFunction=$redirectFunction, token_id=$token_id, family_id=$family_id, device_id=$device_id");

		// Validate class exists
		if (!class_exists($targetClass)) {
			dol_syslog("SmartAuth Debug smartauth  Class ($targetClass) not found", LOG_ERR);
			self::insertLogs($token_id, 500, 'Internal error - Class not found', $entity);
			\json_reply('Internal server error - Class not found', 500);
			return;
		}

		$class = new $targetClass();

		// Validate method exists
		if (!method_exists($class, $redirectFunction)) {
			dol_syslog("SmartAuth Debug smartauth  Method not found: $targetClass::$redirectFunction", LOG_ERR);
			self::insertLogs($token_id, 500, 'Internal error', $entity);
			\json_reply('Internal server error - Method not found', 500);
			return;
		}

		// Build payload
		$payload = [
			'jwt_token_id' => $token_id,
			'jwt_family_id' => $family_id,
			'jwt_device_id' => $device_id,
			'user' => $user,
			'login' => $user ? $user->login : null,
			'user_id' => $user ? $user->id : null,
			'entity' => $entity,
			'buyer' => $buyer,
		];

		// Add OAuth2 context if present
		if (!empty($oauthContext)) {
			$payload['oauth_client_id'] = $oauthContext['client_id'] ?? null;
			$payload['oauth_scopes'] = $oauthContext['scopes'] ?? [];
			$payload['oauth_grant_type'] = $oauthContext['grant_type'] ?? null;
		}

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
				dol_syslog("SmartAuth Debug smartauth  Invalid response format from $targetClass::$redirectFunction", LOG_ERR);
				self::insertLogs($token_id, 500, 'Internal error', $entity);
				\json_reply('Internal server error - Invalid response format', 500);
				return;
			}

			list($message, $code) = $result;

			self::insertLogs($token_id, $code, $message, $entity);
			\json_reply($message, $code);
		} catch (\Throwable $e) {
			// Let JsonReplyEmittedError (flow-control in PHPUNIT_RUNNING mode) bubble up
			if ($e instanceof \JsonReplyEmittedError) {
				throw $e;
			}
			// Capture full diagnostic for 500 troubleshooting (incl. \Error subclasses
			// like TypeError that catch(Exception) used to miss and turn into raw 500)
			$exClass = get_class($e);
			$exWhere = $e->getFile() . ':' . $e->getLine();
			$exMsg = $e->getMessage();
			$exTrace = substr($e->getTraceAsString(), 0, 1500);
			dol_syslog("SmartAuth Exception in $targetClass::$redirectFunction [$exClass @ $exWhere]: $exMsg | trace: $exTrace", LOG_ERR);
			self::insertLogs($token_id, 500, "Exception ($exClass @ $exWhere): $exMsg", $entity);
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
			dol_syslog("SmartAuth Debug smartauth  do not collect logs");
			return;
		}

		$device_uuid = InputSanitizer::sanitizeUUID($_SERVER['HTTP_X_DEVICEID'] ?? '');

		$ac = new AuthController();
		$deviceid = $ac->getDeviceIDFromUUID($device_uuid);
		if (empty($deviceid) || $deviceid <= 0) {
			$deviceid = -1;
		}

		// Validate HTTP method against whitelist
		$httpMethod = ValidationSchemas::validateEnum(
			'http_method',
			$_SERVER['REQUEST_METHOD'] ?? '',
			'GET'
		);

		// Sanitize element against whitelist (if provided)
		$safeElement = '';
		if (!empty($element)) {
			$safeElement = ValidationSchemas::validateEnum('auth_element', $element, '');
			if (empty($safeElement)) {
				// Not in whitelist, use alphanumeric sanitization
				$safeElement = InputSanitizer::sanitizeAlphanumeric($element, 32);
			}
		}


		// Always log, even without keyid (for failed auth attempts)
		// SECURITY: Sanitize sensitive data before logging to prevent PII exposure
		$rawUrl = preg_replace("/.*api.php/", "", $_SERVER['REQUEST_URI'] ?? '');
		$rawUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$rawReferer = $_SERVER['HTTP_REFERER'] ?? '';
		$rawIp = self::get_client_ip();

		$arr = [
			'fk_key' => (int) ($keyid ?? 0),
			'appuid' => InputSanitizer::sanitizeAlphanumeric($smartAuthAppID ?? '', 64),
			'entity' => (int)$entity,
			'dol_element' => $safeElement,
			// Store masked IP for privacy (full IP in memory only)
			'ip' => LogSanitizer::maskIP($rawIp),
			'method' => $httpMethod,
			'http_status' => (int) $status,
			'bytes_sent' => (int) strlen(serialize($message)),
			'content_type' => 'json',
			// Sanitize URL to remove sensitive query parameters
			'url_requested' => LogSanitizer::sanitizeURL($rawUrl, 255),
			// Truncate and sanitize User-Agent (remove version fingerprinting)
			'user_agent' => LogSanitizer::sanitizeUserAgent($rawUserAgent, 100),
			'fk_device_id' => (int) $deviceid,
			// Sanitize referer URL
			'referer' => LogSanitizer::sanitizeURL($rawReferer, 255),
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
				dol_syslog("SmartAuth Debug smartauth  Failed to insert log: " . $db->lasterror(), LOG_WARNING);
			}
		} catch (\Throwable $e) {
			// Widen to Throwable so a DB driver Error (e.g. SQLite locked) on the
			// log insert path never bubbles up and turns a normal 4xx into a 500.
			dol_syslog("SmartAuth Debug smartauth  Log insertion error (" . get_class($e) . "): " . $e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Get real client IP address with secure proxy support
	 *
	 * SECURITY: Only trusts X-Forwarded-For/X-Real-IP headers when:
	 * - REMOTE_ADDR is a known trusted proxy (configured via SMARTAUTH_TRUSTED_PROXIES)
	 * - OR REMOTE_ADDR is a private/localhost IP (backward compatibility)
	 *
	 * Configuration:
	 * - SMARTAUTH_TRUSTED_PROXIES: Comma-separated list of trusted proxy IPs
	 *   Example: "10.0.0.1,10.0.0.2,192.168.1.100"
	 *
	 * Private IP ranges auto-trusted (for reverse proxy setups):
	 * - 127.x.x.x (localhost)
	 * - 10.x.x.x (Class A private)
	 * - 172.16-31.x.x (Class B private)
	 * - 192.168.x.x (Class C private)
	 *
	 * @return string Client IP address (validated format)
	 */
	public static function get_client_ip()
	{
		// Start with REMOTE_ADDR - only truly reliable source (TCP connection IP)
		$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

		// Parse the explicit trusted-proxy allow-list.
		$trustedProxies = getDolGlobalString('SMARTAUTH_TRUSTED_PROXIES', '');
		$trustedList = array_filter(array_map('trim', explode(',', $trustedProxies)));
		$hasAllowList = !empty($trustedList);

		// Decide whether to trust forwarding headers from this peer.
		$trustForwardedHeaders = false;
		if ($hasAllowList) {
			// An explicit allow-list is authoritative: only listed peers are
			// trusted. Private/loopback ranges are NOT auto-trusted in this
			// mode, otherwise an attacker reaching us from a private network
			// (Docker, LAN) could forge the client IP.
			$trustForwardedHeaders = in_array($remoteAddr, $trustedList, true);
		} else {
			// Backward-compatible fallback (no allow-list configured): trust the
			// usual reverse-proxy ranges (loopback + RFC 1918).
			$trustForwardedHeaders = (
				empty($remoteAddr)
				|| preg_match('/^127\./i', $remoteAddr)
				|| preg_match('/^10\./i', $remoteAddr)
				|| preg_match('/^172\.(1[6-9]|2\d|3[01])\./i', $remoteAddr)
				|| preg_match('/^192\.168\./i', $remoteAddr)
			);
		}

		// Only use forwarded headers if we trust the source
		if ($trustForwardedHeaders) {
			$headers = function_exists('apache_request_headers')
				? apache_request_headers()
				: [];

			$xRealIp = $headers['X-Real-IP'] ?? ($_SERVER['HTTP_X_REAL_IP'] ?? '');
			$xff = $headers['X-Forwarded-For'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

			if (!empty($xRealIp)) {
				// Single value set by the trusted proxy.
				$remoteAddr = trim($xRealIp);
			} elseif (!empty($xff)) {
				// The client controls the LEFT of the X-Forwarded-For chain (it
				// can pre-inject entries); each trusted hop appends the peer it
				// actually saw on the RIGHT. The real client is therefore the
				// rightmost entry that is not itself a trusted proxy - never
				// ips[0], which is attacker-controlled.
				$chain = array_filter(array_map('trim', explode(',', $xff)));
				for ($i = count($chain) - 1; $i >= 0; $i--) {
					if ($hasAllowList && in_array($chain[$i], $trustedList, true)) {
						continue; // skip known proxies, keep peeling from the right
					}
					$remoteAddr = $chain[$i];
					break;
				}
			}
		}

		// Validate IP format - return safe fallback if invalid
		if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
			return '0.0.0.0';
		}

		return $remoteAddr;
	}

	/**
	 * Handle CORS headers and preflight requests
	 *
	 * Sets appropriate CORS headers for cross-origin requests and
	 * handles OPTIONS preflight requests automatically.
	 *
	 * Configuration via Dolibarr constants:
	 * - SMARTAUTH_CORS_ORIGIN: Allowed origin (default: '*')
	 * - SMARTAUTH_CORS_METHODS: Allowed methods (default: 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
	 * - SMARTAUTH_CORS_HEADERS: Allowed headers (default: 'Content-Type, Authorization, X-DeviceId')
	 *
	 * @return void
	 */
	public static function handleCORS(): void
	{
		// Skip header sending in PHPUnit test environment
		if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
			return;
		}

		// SMARTAUTH_CORS_ORIGIN now defaults to '' (no CORS) instead of '*'.
		// Setting it to '*' is still possible but explicit and logged as a
		// warning since allowing every origin to read /login responses is a
		// rate-limit / credential-leak risk.
		$configured = getDolGlobalString('SMARTAUTH_CORS_ORIGIN', '');
		$allowedMethods = getDolGlobalString('SMARTAUTH_CORS_METHODS', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
		$allowedHeaders = getDolGlobalString('SMARTAUTH_CORS_HEADERS', 'Content-Type, Authorization, X-DeviceId');

		$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
		$resolvedOrigin = self::resolveCorsOrigin($configured, $requestOrigin);

		// Only emit Access-Control-Allow-Origin when we have a concrete answer
		// (configured + matched, OR explicit wildcard).
		if ($resolvedOrigin !== '') {
			header('Access-Control-Allow-Origin: ' . $resolvedOrigin);
			if ($resolvedOrigin !== '*') {
				header('Vary: Origin');
				// Credentials are only meaningful with a specific origin
				header('Access-Control-Allow-Credentials: true');
			}
			header('Access-Control-Allow-Methods: ' . $allowedMethods);
			header('Access-Control-Allow-Headers: ' . $allowedHeaders);
		}

		// Handle preflight OPTIONS request
		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
			http_response_code(204);
			exit;
		}
	}

	/**
	 * Emit baseline HTTP security headers on every API response.
	 *
	 * Addresses H-8 of TODO-SECURITY-01 (no CSP / HSTS / X-Content-Type-Options
	 * / X-Frame-Options / Referrer-Policy was sent). Each header can be
	 * overridden via a Dolibarr global constant, and HSTS is opt-in to avoid
	 * locking dev environments served over plain HTTP.
	 */
	public static function emitSecurityHeaders(string $cspOverride = ''): void
	{
		if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
			return;
		}

		// X-Content-Type-Options: prevent MIME-sniffing of API responses.
		$xcto = getDolGlobalString('SMARTAUTH_HEADER_XCTO', 'nosniff');
		if ($xcto !== '') {
			header('X-Content-Type-Options: ' . $xcto);
		}

		// X-Frame-Options: API responses are JSON, never meant to be framed.
		$xfo = getDolGlobalString('SMARTAUTH_HEADER_XFO', 'DENY');
		if ($xfo !== '') {
			header('X-Frame-Options: ' . $xfo);
		}

		// Referrer-Policy: do not leak the URL (which may carry tokens) to
		// downstream resources.
		$refPolicy = getDolGlobalString('SMARTAUTH_HEADER_REFERRER_POLICY', 'no-referrer');
		if ($refPolicy !== '') {
			header('Referrer-Policy: ' . $refPolicy);
		}

		// Content-Security-Policy: tight default suitable for a JSON API.
		// HTML entry points (public/index.php for the OAuth2/SSO portal)
		// pass a relaxed override because the tight default ("default-src
		// 'none'") blocks even same-origin CSS and images. The admin can
		// still override globally via SMARTAUTH_HEADER_CSP, but the
		// call-site override wins when supplied.
		if ($cspOverride !== '') {
			$csp = $cspOverride;
		} else {
			$csp = getDolGlobalString('SMARTAUTH_HEADER_CSP', "default-src 'none'; frame-ancestors 'none'");
		}
		if ($csp !== '') {
			header('Content-Security-Policy: ' . $csp);
		}

		// HSTS: opt-in (only emit when explicitly configured, since locking
		// in HTTPS for a dev environment served over plain HTTP would brick
		// it). Recommended prod value: "max-age=31536000; includeSubDomains".
		$hsts = getDolGlobalString('SMARTAUTH_HEADER_HSTS', '');
		$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		if ($hsts !== '' && $isHttps) {
			header('Strict-Transport-Security: ' . $hsts);
		}

		// Cross-Origin policies: tighten cross-origin reads on API responses.
		$coop = getDolGlobalString('SMARTAUTH_HEADER_COOP', 'same-origin');
		if ($coop !== '') {
			header('Cross-Origin-Opener-Policy: ' . $coop);
		}
		$corp = getDolGlobalString('SMARTAUTH_HEADER_CORP', 'same-origin');
		if ($corp !== '') {
			header('Cross-Origin-Resource-Policy: ' . $corp);
		}
	}

	/**
	 * Resolve the value to send in Access-Control-Allow-Origin.
	 *
	 * @param string $configured Comma-separated allow-list (or '*' for any).
	 *                            Empty string means "no CORS", we return ''.
	 * @param string $requestOrigin The Origin header from the request.
	 * @return string The origin to echo back, '*' for wildcard, or '' to skip.
	 */
	public static function resolveCorsOrigin(string $configured, string $requestOrigin): string
	{
		if ($configured === '') {
			return '';
		}
		if ($configured === '*') {
			dol_syslog('SmartAuth CORS: SMARTAUTH_CORS_ORIGIN=* allows any site to read API responses (H-6) - prefer an explicit allow-list', LOG_WARNING);
			return '*';
		}
		if ($requestOrigin === '') {
			return '';
		}
		$allowed = array_filter(array_map('trim', explode(',', $configured)));
		// Case-insensitive comparison on host part
		foreach ($allowed as $candidate) {
			if (strcasecmp($candidate, $requestOrigin) === 0) {
				return $requestOrigin;
			}
		}
		dol_syslog('SmartAuth CORS: rejected origin "' . substr($requestOrigin, 0, 200) . '"', LOG_WARNING);
		return '';
	}

	/**
	 * Check if CORS headers are properly configured
	 *
	 * This is a lightweight check that runs once per session.
	 * It logs a warning if CORS doesn't appear to be configured,
	 * which could indicate a security misconfiguration.
	 *
	 * The check is performed only when:
	 * - Origin header is present (cross-origin request)
	 * - Check hasn't been performed in this session
	 *
	 * @return void
	 */
	private static function checkCORSConfiguration()
	{
		global $conf;

		// Only check once per session (performance)
		$cacheKey = 'smartauth_cors_checked';
		if (isset($conf->cache['smartmakers'][$cacheKey])) {
			return;
		}
		$conf->cache['smartmakers'][$cacheKey] = true;

		// Only relevant for cross-origin requests
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if (empty($origin)) {
			return; // Same-origin request, no CORS needed
		}

		// Check if this is a preflight request
		$isPreflight = ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS';

		// Check for common CORS header indicators
		// These might be set by Apache/Nginx or by PHP
		$corsHeaders = [
			'Access-Control-Allow-Origin',
			'access-control-allow-origin',
		];

		$corsConfigured = false;

		// Check if headers are already sent (by server config)
		$sentHeaders = headers_list();
		foreach ($sentHeaders as $header) {
			$headerLower = strtolower($header);
			if (strpos($headerLower, 'access-control-allow-origin') !== false) {
				$corsConfigured = true;
				break;
			}
		}

		// If not configured and this is a cross-origin request, log warning
		if (!$corsConfigured && !$isPreflight) {
			// Only log once per day to avoid log spam
			$lastWarning = getDolGlobalInt('SMARTAUTH_CORS_WARNING_TIME', 0);
			if ((time() - $lastWarning) > 86400) {
				dol_syslog(
					"SmartAuth SECURITY WARNING: Cross-origin request detected from '$origin' but no CORS headers found. " .
					"Ensure CORS is configured at server level (Apache/Nginx) for security.",
					LOG_WARNING
				);

				// Update last warning time
				global $db;
				if ($db) {
					$sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . time() . "' " .
						   "WHERE name = 'SMARTAUTH_CORS_WARNING_TIME' AND entity = 0";
					$db->query($sql);

					if ($db->affected_rows($db) == 0) {
						$sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, value, type, visible, entity) " .
							   "VALUES ('SMARTAUTH_CORS_WARNING_TIME', '" . time() . "', 'chaine', 0, 0)";
						$db->query($sql);
					}
				}
			}
		}
	}
}
