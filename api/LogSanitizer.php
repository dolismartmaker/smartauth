<?php

/**
 * LogSanitizer.php
 *
 * Utility class for sanitizing sensitive data before logging.
 * Prevents exposure of PII and security-sensitive information in logs.
 *
 * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class LogSanitizer
{
	/**
	 * Mask an IP address for privacy
	 * Keeps first two octets for IPv4, first 4 groups for IPv6
	 *
	 * @param string $ip Raw IP address
	 * @return string Masked IP (e.g., "192.168.xxx.xxx")
	 */
	public static function maskIP($ip)
	{
		if (empty($ip) || !is_string($ip)) {
			return '0.0.0.0';
		}

		// IPv4
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$parts = explode('.', $ip);
			if (count($parts) === 4) {
				return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
			}
		}

		// IPv6
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$parts = explode(':', $ip);
			if (count($parts) >= 4) {
				return $parts[0] . ':' . $parts[1] . ':' . $parts[2] . ':' . $parts[3] . ':xxxx:xxxx:xxxx:xxxx';
			}
		}

		return 'x.x.x.x';
	}

	/**
	 * Mask an email address for privacy
	 * Shows first 2 chars of local part and domain
	 *
	 * @param string $email Raw email
	 * @return string Masked email (e.g., "us***@example.com")
	 */
	public static function maskEmail($email)
	{
		if (empty($email) || !is_string($email)) {
			return '***@***.***';
		}

		$parts = explode('@', $email);
		if (count($parts) !== 2) {
			return '***@***.***';
		}

		$local = $parts[0];
		$domain = $parts[1];

		// Show first 2 chars of local part
		$maskedLocal = substr($local, 0, 2) . '***';

		return $maskedLocal . '@' . $domain;
	}

	/**
	 * Truncate and sanitize User-Agent for logging
	 * Removes version numbers and keeps only browser/OS family
	 *
	 * @param string $userAgent Raw User-Agent string
	 * @param int $maxLen Maximum length (default 50)
	 * @return string Sanitized User-Agent
	 */
	public static function sanitizeUserAgent($userAgent, $maxLen = 50)
	{
		if (empty($userAgent) || !is_string($userAgent)) {
			return 'unknown';
		}

		// Remove version numbers (digits followed by dots)
		$sanitized = preg_replace('/\d+(\.\d+)+/', 'x.x', $userAgent);

		// Remove potential injection attempts
		$sanitized = preg_replace('/[<>"\']/', '', $sanitized);

		// Truncate
		if (strlen($sanitized) > $maxLen) {
			$sanitized = substr($sanitized, 0, $maxLen - 3) . '...';
		}

		return $sanitized;
	}

	/**
	 * Mask a token for logging (shows only first and last 4 chars)
	 *
	 * @param string $token Raw token
	 * @return string Masked token (e.g., "eyJ0...abc1")
	 */
	public static function maskToken($token)
	{
		if (empty($token) || !is_string($token)) {
			return '***';
		}

		$len = strlen($token);
		if ($len <= 8) {
			return '***';
		}

		return substr($token, 0, 4) . '...' . substr($token, -4);
	}

	/**
	 * Mask a salt or secret key for logging
	 * Shows only first 4 chars
	 *
	 * @param string $salt Raw salt
	 * @return string Masked salt (e.g., "a1b2...")
	 */
	public static function maskSalt($salt)
	{
		if (empty($salt) || !is_string($salt)) {
			return '***';
		}

		$len = strlen($salt);
		if ($len <= 4) {
			return '***';
		}

		return substr($salt, 0, 4) . '...';
	}

	/**
	 * Sanitize URL for logging
	 * Removes query string parameters that might contain sensitive data
	 *
	 * @param string $url Raw URL
	 * @param int $maxLen Maximum length (default 255)
	 * @return string Sanitized URL
	 */
	public static function sanitizeURL($url, $maxLen = 255)
	{
		if (empty($url) || !is_string($url)) {
			return '';
		}

		// Parse URL
		$parsed = parse_url($url);
		if ($parsed === false) {
			return substr($url, 0, $maxLen);
		}

		// Rebuild URL without sensitive query params
		$result = $parsed['path'] ?? '/';

		// If there's a query string, mask sensitive parameters
		if (isset($parsed['query'])) {
			parse_str($parsed['query'], $params);

			$sensitiveKeys = ['password', 'token', 'key', 'secret', 'auth', 'api_key', 'apikey', 'pass'];
			$maskedParams = [];

			foreach ($params as $key => $value) {
				$lowerKey = strtolower($key);
				$isSensitive = false;

				foreach ($sensitiveKeys as $sensitiveKey) {
					if (strpos($lowerKey, $sensitiveKey) !== false) {
						$isSensitive = true;
						break;
					}
				}

				if ($isSensitive) {
					$maskedParams[] = $key . '=***';
				} else {
					// Truncate long values
					$safeValue = strlen($value) > 50 ? substr($value, 0, 47) . '...' : $value;
					$maskedParams[] = $key . '=' . $safeValue;
				}
			}

			if (!empty($maskedParams)) {
				$result .= '?' . implode('&', $maskedParams);
			}
		}

		// Truncate if needed
		if (strlen($result) > $maxLen) {
			$result = substr($result, 0, $maxLen - 3) . '...';
		}

		return $result;
	}

	/**
	 * Mask device UUID for logging
	 * Shows only first 8 chars
	 *
	 * @param string $uuid Raw UUID
	 * @return string Masked UUID (e.g., "a1b2c3d4-****-****-****-************")
	 */
	public static function maskUUID($uuid)
	{
		if (empty($uuid) || !is_string($uuid)) {
			return '***';
		}

		$len = strlen($uuid);
		if ($len <= 8) {
			return '***';
		}

		// For standard UUID format (36 chars with dashes)
		if ($len === 36 && substr_count($uuid, '-') === 4) {
			return substr($uuid, 0, 8) . '-****-****-****-************';
		}

		// For hash format (64 chars)
		if ($len === 64) {
			return substr($uuid, 0, 8) . '...[hash]';
		}

		return substr($uuid, 0, 8) . '...';
	}

	/**
	 * Create a safe log entry array from raw data
	 * Applies appropriate masking to all sensitive fields
	 *
	 * @param array $data Raw data array
	 * @return array Sanitized data array safe for logging
	 */
	public static function sanitizeLogData(array $data)
	{
		$result = [];

		foreach ($data as $key => $value) {
			$lowerKey = strtolower($key);

			if (!is_string($value) && !is_numeric($value)) {
				$result[$key] = is_array($value) ? '[array]' : '[object]';
				continue;
			}

			$value = (string) $value;

			// Apply appropriate masking based on field name
			if (strpos($lowerKey, 'ip') !== false) {
				$result[$key] = self::maskIP($value);
			} elseif (strpos($lowerKey, 'email') !== false || strpos($lowerKey, 'login') !== false) {
				$result[$key] = self::maskEmail($value);
			} elseif (strpos($lowerKey, 'token') !== false || strpos($lowerKey, 'jwt') !== false) {
				$result[$key] = self::maskToken($value);
			} elseif (strpos($lowerKey, 'salt') !== false || strpos($lowerKey, 'secret') !== false || strpos($lowerKey, 'key') !== false) {
				$result[$key] = self::maskSalt($value);
			} elseif (strpos($lowerKey, 'user_agent') !== false || strpos($lowerKey, 'useragent') !== false) {
				$result[$key] = self::sanitizeUserAgent($value);
			} elseif (strpos($lowerKey, 'uuid') !== false || strpos($lowerKey, 'device') !== false) {
				$result[$key] = self::maskUUID($value);
			} elseif (strpos($lowerKey, 'url') !== false || strpos($lowerKey, 'uri') !== false) {
				$result[$key] = self::sanitizeURL($value);
			} elseif (strpos($lowerKey, 'password') !== false || strpos($lowerKey, 'pass') !== false) {
				$result[$key] = '***';
			} else {
				// Default: truncate long values
				$result[$key] = strlen($value) > 100 ? substr($value, 0, 97) . '...' : $value;
			}
		}

		return $result;
	}
}
