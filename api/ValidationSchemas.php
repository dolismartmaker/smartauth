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
	 * Get all validation schemas
	 *
	 * @return array All schemas indexed by endpoint
	 */
	public static function getAllSchemas(): array
	{
		return [
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
			'auth_element' => ['user', 'societe_account'],
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
