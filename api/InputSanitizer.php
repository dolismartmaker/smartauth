<?php

/**
 * InputSanitizer.php
 *
 * Middleware for sanitizing and validating input data in API requests.
 * Provides type-safe sanitization with configurable schemas per endpoint.
 *
 * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class InputSanitizer
{
	/**
	 * Maximum string length for general text fields
	 */
	const MAX_STRING_LENGTH = 255;

	/**
	 * Cache for external sanitizers loaded via hook
	 *
	 * @var array|null
	 */
	private static ?array $externalSanitizers = null;

	/**
	 * Load sanitizers from external modules via hook
	 *
	 * External modules can register custom sanitization types by implementing
	 * the hook smartmaker_addSanitizers in their actions class.
	 *
	 * Example implementation in a module:
	 * ```php
	 * public function smartmaker_addSanitizers($parameters, &$sanitizers, &$action, $hookmanager) {
	 *     $sanitizers['phone_fr'] = function($value, $rules, $field) {
	 *         $clean = preg_replace('/[^0-9+]/', '', $value);
	 *         if (preg_match('/^(?:\+33|0)[1-9][0-9]{8}$/', $clean)) {
	 *             return $clean;
	 *         }
	 *         if ($rules['required'] ?? false) {
	 *             throw new \InvalidArgumentException("Invalid phone format for field: $field");
	 *         }
	 *         return null;
	 *     };
	 *     return 0;
	 * }
	 * ```
	 *
	 * @param bool $forceReload Force reloading sanitizers (bypass cache)
	 *
	 * @return array External sanitizers indexed by type name
	 */
	public static function loadExternalSanitizers(bool $forceReload = false): array
	{
		if (self::$externalSanitizers !== null && !$forceReload) {
			return self::$externalSanitizers;
		}

		self::$externalSanitizers = [];

		// Check if Dolibarr hookmanager is available
		global $hookmanager;

		if (!is_object($hookmanager)) {
			return self::$externalSanitizers;
		}

		// Initialize hooks for smartmaker context
		$hookmanager->initHooks(['smartmaker']);

		// Call the hook - modules will add their sanitizers
		$parameters = [];
		$action = '';
		$hookmanager->executeHooks(
			'smartmaker_addSanitizers',
			$parameters,
			self::$externalSanitizers,
			$action
		);

		return self::$externalSanitizers;
	}

	/**
	 * Clear the external sanitizers cache
	 * Useful for testing or when modules are dynamically loaded
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::$externalSanitizers = null;
	}

	/**
	 * Maximum string length for short fields (labels, names)
	 */
	const MAX_SHORT_LENGTH = 100;

	/**
	 * Maximum string length for long text fields
	 */
	const MAX_LONG_LENGTH = 1000;

	/**
	 * Sanitization type constants
	 */
	const TYPE_STRING = 'string';
	const TYPE_EMAIL = 'email';
	const TYPE_INT = 'int';
	const TYPE_FLOAT = 'float';
	const TYPE_BOOL = 'bool';
	const TYPE_UUID = 'uuid';
	const TYPE_ALPHANUMERIC = 'alphanumeric';
	const TYPE_ARRAY = 'array';
	const TYPE_RAW = 'raw'; // No sanitization (use with caution)

	/**
	 * Sanitize all data according to a schema
	 *
	 * Schema format:
	 * [
	 *     'fieldname' => ['type' => 'string', 'maxLen' => 100, 'required' => true],
	 *     'email' => ['type' => 'email'],
	 *     'count' => ['type' => 'int', 'min' => 0, 'max' => 1000],
	 * ]
	 *
	 * @param array $data   Raw input data
	 * @param array $schema Validation schema
	 *
	 * @return array Sanitized data
	 * @throws \InvalidArgumentException If required field is missing or validation fails
	 */
	public static function sanitize(array $data, array $schema): array
	{
		$sanitized = [];

		foreach ($schema as $field => $rules) {
			$type = $rules['type'] ?? self::TYPE_STRING;
			$required = $rules['required'] ?? false;
			$default = $rules['default'] ?? null;

			// Check if field exists
			if (!array_key_exists($field, $data)) {
				if ($required) {
					throw new \InvalidArgumentException("Missing required field: $field");
				}
				if ($default !== null) {
					$sanitized[$field] = $default;
				}
				continue;
			}

			$value = $data[$field];

			// Apply type-specific sanitization
			$sanitized[$field] = self::sanitizeByType($value, $type, $rules, $field);
		}

		return $sanitized;
	}

	/**
	 * Sanitize all fields with default string sanitization
	 * Used when no schema is provided
	 *
	 * @param array $data Raw input data
	 *
	 * @return array Sanitized data
	 */
	public static function sanitizeAll(array $data): array
	{
		$sanitized = [];

		foreach ($data as $key => $value) {
			// Sanitize key
			$cleanKey = self::sanitizeAlphanumeric($key, self::MAX_SHORT_LENGTH);
			if (empty($cleanKey)) {
				continue; // Skip invalid keys
			}

			// Sanitize value based on type
			if (is_array($value)) {
				$sanitized[$cleanKey] = self::sanitizeAll($value);
			} elseif (is_int($value)) {
				$sanitized[$cleanKey] = self::sanitizeInt($value);
			} elseif (is_float($value)) {
				$sanitized[$cleanKey] = self::sanitizeFloat($value);
			} elseif (is_bool($value)) {
				$sanitized[$cleanKey] = self::sanitizeBool($value);
			} elseif (is_string($value)) {
				$sanitized[$cleanKey] = self::sanitizeString($value, self::MAX_STRING_LENGTH);
			}
			// Null values are skipped
		}

		return $sanitized;
	}

	/**
	 * Dispatch sanitization based on type
	 *
	 * @param mixed  $value Raw value
	 * @param string $type  Sanitization type
	 * @param array  $rules Additional rules
	 * @param string $field Field name for error messages
	 *
	 * @return mixed Sanitized value
	 */
	private static function sanitizeByType($value, string $type, array $rules, string $field)
	{
		// Check for external sanitizer first
		$externalSanitizers = self::loadExternalSanitizers();
		if (isset($externalSanitizers[$type]) && is_callable($externalSanitizers[$type])) {
			return call_user_func($externalSanitizers[$type], $value, $rules, $field);
		}

		$maxLen = $rules['maxLen'] ?? self::MAX_STRING_LENGTH;

		switch ($type) {
			case self::TYPE_STRING:
				return self::sanitizeString($value, $maxLen);

			case self::TYPE_EMAIL:
				$email = self::sanitizeEmail($value);
				if ($email === null && ($rules['required'] ?? false)) {
					throw new \InvalidArgumentException("Invalid email format for field: $field");
				}
				return $email;

			case self::TYPE_INT:
				$int = self::sanitizeInt($value);
				if (isset($rules['min']) && $int < $rules['min']) {
					$int = $rules['min'];
				}
				if (isset($rules['max']) && $int > $rules['max']) {
					$int = $rules['max'];
				}
				return $int;

			case self::TYPE_FLOAT:
				$float = self::sanitizeFloat($value);
				if (isset($rules['min']) && $float < $rules['min']) {
					$float = $rules['min'];
				}
				if (isset($rules['max']) && $float > $rules['max']) {
					$float = $rules['max'];
				}
				return $float;

			case self::TYPE_BOOL:
				return self::sanitizeBool($value);

			case self::TYPE_UUID:
				$uuid = self::sanitizeUUID($value);
				if ($uuid === null && ($rules['required'] ?? false)) {
					throw new \InvalidArgumentException("Invalid UUID format for field: $field");
				}
				return $uuid;

			case self::TYPE_ALPHANUMERIC:
				return self::sanitizeAlphanumeric($value, $maxLen);

			case self::TYPE_ARRAY:
				if (!is_array($value)) {
					return [];
				}
				$itemType = $rules['itemType'] ?? self::TYPE_STRING;
				return self::sanitizeArray($value, $itemType, $rules);

			case self::TYPE_RAW:
				return $value;

			default:
				return self::sanitizeString($value, $maxLen);
		}
	}

	/**
	 * Sanitize a string value
	 * - Removes null bytes
	 * - Strips HTML tags
	 * - Converts special characters to HTML entities
	 * - Trims whitespace
	 * - Limits length
	 *
	 * @param mixed $value  Raw value
	 * @param int   $maxLen Maximum length
	 *
	 * @return string Sanitized string
	 */
	public static function sanitizeString($value, int $maxLen = self::MAX_STRING_LENGTH): string
	{
		if (!is_string($value) && !is_numeric($value)) {
			return '';
		}

		$value = (string) $value;

		// Remove null bytes (security)
		$value = str_replace("\0", '', $value);

		// Strip HTML tags
		$value = strip_tags($value);

		// Convert special characters to HTML entities
		$value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Trim whitespace
		$value = trim($value);

		// Limit length
		if (strlen($value) > $maxLen) {
			$value = substr($value, 0, $maxLen);
		}

		return $value;
	}

	/**
	 * Sanitize and validate an email address
	 *
	 * @param mixed $value Raw value
	 *
	 * @return string|null Validated email or null if invalid
	 */
	public static function sanitizeEmail($value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		// First sanitize
		$email = filter_var($value, FILTER_SANITIZE_EMAIL);

		// Then validate
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		// Additional length check
		if (strlen($email) > self::MAX_STRING_LENGTH) {
			return null;
		}

		return strtolower($email);
	}

	/**
	 * Sanitize and validate a UUID
	 * Accepts standard UUID format (8-4-4-4-12) or SHA256 hash (64 hex chars)
	 *
	 * @param mixed $value Raw value
	 *
	 * @return string|null Validated UUID or null if invalid
	 */
	public static function sanitizeUUID($value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);

		// Standard UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
		$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

		// SHA256 hash format (64 hex characters)
		$sha256Pattern = '/^[0-9a-f]{64}$/i';

		// Simple hex format (32-64 characters)
		$hexPattern = '/^[0-9a-f]{32,64}$/i';

		if (
			preg_match($uuidPattern, $value) ||
			preg_match($sha256Pattern, $value) ||
			preg_match($hexPattern, $value)
		) {
			return strtolower($value);
		}

		return null;
	}

	/**
	 * Sanitize an integer value
	 *
	 * @param mixed $value Raw value
	 *
	 * @return int Sanitized integer
	 */
	public static function sanitizeInt($value): int
	{
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}

		return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
	}

	/**
	 * Sanitize a float value
	 *
	 * @param mixed $value Raw value
	 *
	 * @return float Sanitized float
	 */
	public static function sanitizeFloat($value): float
	{
		return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
	}

	/**
	 * Sanitize a boolean value
	 *
	 * @param mixed $value Raw value
	 *
	 * @return bool Sanitized boolean
	 */
	public static function sanitizeBool($value): bool
	{
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * Sanitize to alphanumeric characters only (plus hyphen and underscore)
	 *
	 * @param mixed $value  Raw value
	 * @param int   $maxLen Maximum length
	 *
	 * @return string Sanitized alphanumeric string
	 */
	public static function sanitizeAlphanumeric($value, int $maxLen = self::MAX_STRING_LENGTH): string
	{
		if (!is_string($value) && !is_numeric($value)) {
			return '';
		}

		$value = (string) $value;
		$value = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);

		if (strlen($value) > $maxLen) {
			$value = substr($value, 0, $maxLen);
		}

		return $value;
	}

	/**
	 * Sanitize a username (alphanumeric plus hyphen, underscore, and dot)
	 *
	 * @param mixed $value  Raw value
	 * @param int   $maxLen Maximum length
	 *
	 * @return string|null Sanitized username or null if invalid
	 */
	public static function sanitizeUsername($value, int $maxLen = self::MAX_STRING_LENGTH): ?string
	{
		if (!is_string($value) && !is_numeric($value)) {
			return null;
		}

		$value = (string) $value;
		$value = trim($value);

		// Remove null bytes
		$value = str_replace("\0", '', $value);

		// Check length
		if (empty($value) || strlen($value) > $maxLen) {
			return null;
		}

		// Only allow alphanumeric, underscore, hyphen, dot
		if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $value)) {
			return null;
		}

		return $value;
	}

	/**
	 * Sanitize an array of values
	 *
	 * @param array  $value    Raw array
	 * @param string $itemType Type of items in array
	 * @param array  $rules    Additional rules
	 *
	 * @return array Sanitized array
	 */
	public static function sanitizeArray(array $value, string $itemType = self::TYPE_STRING, array $rules = []): array
	{
		$maxItems = $rules['maxItems'] ?? 100;
		$sanitized = [];
		$count = 0;

		foreach ($value as $item) {
			if ($count >= $maxItems) {
				break;
			}

			$sanitized[] = self::sanitizeByType($item, $itemType, $rules, 'array_item');
			$count++;
		}

		return $sanitized;
	}

	/**
	 * Sanitize IP address
	 *
	 * @param mixed $value Raw value
	 *
	 * @return string|null Validated IP or null if invalid
	 */
	public static function sanitizeIP($value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);

		// Validate IPv4 or IPv6
		if (filter_var($value, FILTER_VALIDATE_IP) === false) {
			return null;
		}

		return $value;
	}

	/**
	 * Sanitize URL
	 *
	 * @param mixed $value Raw value
	 *
	 * @return string|null Validated URL or null if invalid
	 */
	public static function sanitizeURL($value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		$url = filter_var($value, FILTER_SANITIZE_URL);

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return null;
		}

		// Additional length check
		if (strlen($url) > self::MAX_LONG_LENGTH) {
			return null;
		}

		return $url;
	}

	/**
	 * Validate a value against a whitelist of allowed values
	 *
	 * @param mixed $value   Raw value
	 * @param array $allowed Allowed values
	 * @param mixed $default Default value if not in whitelist
	 *
	 * @return mixed Value if allowed, default otherwise
	 */
	public static function validateEnum($value, array $allowed, $default = null)
	{
		if (in_array($value, $allowed, true)) {
			return $value;
		}

		return $default;
	}

	/**
	 * Sanitize for SQL-safe logging (escape then truncate)
	 * Use this for fields that will be inserted into logs
	 *
	 * @param mixed    $value  Raw value
	 * @param int      $maxLen Maximum length
	 * @param Database $db     Dolibarr database object for escaping
	 *
	 * @return string Sanitized and escaped string
	 */
	public static function sanitizeForLog($value, int $maxLen, $db): string
	{
		if (!is_string($value)) {
			$value = (string) $value;
		}

		// First escape, then truncate (correct order for SQL safety)
		$escaped = $db->escape($value);

		if (strlen($escaped) > $maxLen) {
			$escaped = substr($escaped, 0, $maxLen);
		}

		return $escaped;
	}
}
