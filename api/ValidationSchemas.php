<?php

/**
 * ValidationSchemas.php
 *
 * Defines validation schemas for each API endpoint.
 * Used by InputSanitizer to validate and sanitize request data.
 *
 * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class ValidationSchemas
{
	/**
	 * Cache for external schemas loaded via hook
	 *
	 * @var array|null
	 */
	private static ?array $externalSchemas = null;

	/**
	 * Get validation schema for a specific endpoint
	 *
	 * @param string $endpoint Endpoint identifier (e.g., 'login', 'device', 'refresh')
	 *
	 * @return array|null Validation schema or null if no specific schema defined
	 */
	public static function getSchema(string $endpoint): ?array
	{
		$schemas = self::getAllSchemas();

		return $schemas[$endpoint] ?? null;
	}

	/**
	 * Get validation schema for a specific module and endpoint
	 *
	 * @param string $module   Module identifier (e.g., 'interventions', 'smartauth')
	 * @param string $endpoint Endpoint identifier (e.g., 'POST:/interventions')
	 *
	 * @return array|null Validation schema or null if not found
	 */
	public static function getSchemaForModule(string $module, string $endpoint): ?array
	{
		// SmartAuth internal schemas
		if ($module === 'smartauth') {
			return self::getSchema($endpoint);
		}

		// External module schemas
		$externalSchemas = self::loadExternalSchemas();

		return $externalSchemas[$module][$endpoint] ?? null;
	}

	/**
	 * Load validation schemas from external modules via hook
	 *
	 * External modules can register their schemas by implementing
	 * the hook smartmaker_addValidationSchemas in their actions class.
	 *
	 * Example implementation in a module:
	 * ```php
	 * public function smartmaker_addValidationSchemas($parameters, &$schemas, &$action, $hookmanager) {
	 *     $schemas['mymodule'] = [
	 *         'POST:/myendpoint' => [
	 *             'field1' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true],
	 *             'field2' => ['type' => InputSanitizer::TYPE_INT, 'min' => 0],
	 *         ],
	 *     ];
	 *     return 0;
	 * }
	 * ```
	 *
	 * @param bool $forceReload Force reloading schemas (bypass cache)
	 *
	 * @return array External schemas indexed by module name
	 */
	public static function loadExternalSchemas(bool $forceReload = false): array
	{
		if (self::$externalSchemas !== null && !$forceReload) {
			return self::$externalSchemas;
		}

		self::$externalSchemas = [];

		// Check if Dolibarr hookmanager is available
		global $hookmanager;

		if (!is_object($hookmanager)) {
			return self::$externalSchemas;
		}

		// Initialize hooks for smartmaker context
		$hookmanager->initHooks(['smartmaker']);

		// Call the hook - modules will add their schemas to $externalSchemas
		$parameters = [];
		$action = '';
		$hookmanager->executeHooks(
			'smartmaker_addValidationSchemas',
			$parameters,
			self::$externalSchemas,
			$action
		);

		return self::$externalSchemas;
	}

	/**
	 * Clear the external schemas cache
	 * Useful for testing or when modules are dynamically loaded
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::$externalSchemas = null;
	}

	/**
	 * Get all validation schemas (SmartAuth + external modules)
	 *
	 * @param bool $includeExternal Include schemas from external modules
	 *
	 * @return array All schemas indexed by endpoint (or by module then endpoint if external)
	 */
	public static function getAllSchemas(bool $includeExternal = false): array
	{
		$schemas = [
			// POST /login
			'login' => [
				'email' => [
					'type' => InputSanitizer::TYPE_EMAIL,
					'required' => false,
				],
				'username' => [
					'type' => InputSanitizer::TYPE_EMAIL,
					'required' => false,
				],
				'password' => [
					'type' => InputSanitizer::TYPE_RAW, // Password not sanitized, passed to auth
					'required' => true,
				],
				'entity' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'default' => 0,
					'min' => 0,
				],
				'uuid' => [
					'type' => InputSanitizer::TYPE_UUID,
					'required' => false,
				],
				'device_label' => [
					'type' => InputSanitizer::TYPE_STRING,
					'maxLen' => 100,
					'required' => false,
				],
			],

			// POST /device
			'device' => [
				'uuid' => [
					'type' => InputSanitizer::TYPE_UUID,
					'required' => true,
				],
				'label' => [
					'type' => InputSanitizer::TYPE_STRING,
					'maxLen' => 100,
					'required' => false,
				],
			],

			// GET /refresh
			'refresh' => [
				// No body params, token in header
			],

			// POST /logout
			'logout' => [
				// No body params typically
			],

			// GET /index (list entities)
			'index' => [
				'login' => [
					'type' => InputSanitizer::TYPE_EMAIL,
					'required' => false,
				],
			],

			// GET /ping
			'ping' => [
				// No params
			],

			// GET /push/vapid-public-key (public, no body)
			'push_vapid_public_key' => [
				// No params
			],

			// POST /push/subscribe
			// 'subscription' is the nested W3C object {endpoint, keys:{p256dh,auth}}.
			// It MUST stay TYPE_RAW: the endpoint is a URL that can exceed 255
			// chars and must reach the controller byte-for-byte (it is the UPSERT
			// identity and the real delivery target). sanitizeString would
			// strip_tags + truncate it. The controller validates it (HTTPS +
			// base64url keys) and escapes via $db->escape downstream.
			'push_subscribe' => [
				'subscription' => [
					'type' => InputSanitizer::TYPE_RAW,
					'required' => true,
				],
				'label' => [
					'type' => InputSanitizer::TYPE_STRING,
					'maxLen' => 128,
					'required' => false,
				],
				'device_id' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'min' => 0,
				],
			],

			// DELETE /push/unsubscribe
			// 'endpoint' is TYPE_RAW for the same reason as above (full URL,
			// escaped downstream in the controller WHERE clause).
			'push_unsubscribe' => [
				'endpoint' => [
					'type' => InputSanitizer::TYPE_RAW,
					'required' => false,
				],
				'id' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'min' => 0,
				],
			],

			// GET /push/subscriptions (no body)
			'push_subscriptions' => [
				// No params
			],

			// Generic GET params schema
			'get_params' => [
				'id' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'min' => 0,
				],
				'limit' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'default' => 50,
					'min' => 1,
					'max' => 500,
				],
				'offset' => [
					'type' => InputSanitizer::TYPE_INT,
					'required' => false,
					'default' => 0,
					'min' => 0,
				],
				'sortfield' => [
					'type' => InputSanitizer::TYPE_ALPHANUMERIC,
					'maxLen' => 50,
					'required' => false,
				],
				'sortorder' => [
					'type' => InputSanitizer::TYPE_ALPHANUMERIC,
					'maxLen' => 4,
					'required' => false,
				],
			],
		];

		if ($includeExternal) {
			$externalSchemas = self::loadExternalSchemas();
			foreach ($externalSchemas as $module => $moduleSchemas) {
				foreach ($moduleSchemas as $endpoint => $schema) {
					$schemas[$module . ':' . $endpoint] = $schema;
				}
			}
		}

		return $schemas;
	}

	/**
	 * Map route pattern to schema name
	 *
	 * @param string $targetAction Route pattern (e.g., 'login', 'device', 'users/{id}')
	 *
	 * @return string Schema name
	 */
	public static function mapRouteToSchema(string $targetAction): string
	{
		// Remove leading slash if present
		$action = ltrim($targetAction, '/');

		// Map common patterns
		$routeMap = [
			'login' => 'login',
			'logout' => 'logout',
			'device' => 'device',
			'refresh' => 'refresh',
			'index' => 'index',
			'ping' => 'ping',
			'push/vapid-public-key' => 'push_vapid_public_key',
			'push/subscribe' => 'push_subscribe',
			'push/unsubscribe' => 'push_unsubscribe',
			'push/subscriptions' => 'push_subscriptions',
		];

		// Direct match
		if (isset($routeMap[$action])) {
			return $routeMap[$action];
		}

		// Extract base route (before any parameters)
		$baseParts = explode('/', $action);
		$base = $baseParts[0] ?? '';

		if (isset($routeMap[$base])) {
			return $routeMap[$base];
		}

		// No specific schema found
		return 'default';
	}

	/**
	 * Whitelist of allowed enum values for specific fields
	 *
	 * @return array Enum definitions
	 */
	public static function getEnumWhitelists(): array
	{
		return [
			'auth_element' => ['user', 'societe_account', 'adherent'],
			'token_type' => ['access', 'refresh'],
			'http_method' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
			'content_type' => ['json', 'xml', 'form'],
			'status' => [0, 1, 9], // draft, valid, logout
		];
	}

	/**
	 * Validate an enum value against its whitelist
	 *
	 * @param string $enumName Name of the enum
	 * @param mixed  $value    Value to validate
	 * @param mixed  $default  Default value if not valid
	 *
	 * @return mixed Validated value or default
	 */
	public static function validateEnum(string $enumName, $value, $default = null)
	{
		$whitelists = self::getEnumWhitelists();

		if (!isset($whitelists[$enumName])) {
			return $default;
		}

		return InputSanitizer::validateEnum($value, $whitelists[$enumName], $default);
	}
}
