<?php

/**
 * tools.php
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

use SmartAuth\Api\RouteController;

/**
 * Thrown by json_reply() in PHPUNIT_RUNNING mode to abort the request flow
 * after a response has been emitted, without exit() (which would roll back
 * the SQLite transaction in tests).
 *
 * Extends \Error (not \Exception) so it bypasses all "catch (Exception)"
 * blocks in controllers and reaches the outer router/test harness.
 */
class JsonReplyEmittedError extends \Error
{
}

function json_reply($message, $code)
{
	// Trace every reply with its HTTP status AND the exact caller (file:line +
	// method) so any error response (401/403/...) is attributable in the logs
	// without guessing. Cheap: only a 2-frame backtrace.
	$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
	$origin = isset($bt[0]['file']) ? basename($bt[0]['file']) . ':' . ($bt[0]['line'] ?? '?') : 'unknown';
	$caller = isset($bt[1]['function'])
		? (($bt[1]['class'] ?? '') . ($bt[1]['type'] ?? '') . $bt[1]['function'] . '()')
		: 'global';
	$msgFlat = is_string($message) ? $message : json_encode($message);
	dol_syslog("[SmartAuth] json_reply: HTTP $code from $caller at $origin -- message=" . $msgFlat,
		((int) $code >= 400 ? LOG_WARNING : LOG_DEBUG));

	// remove any string that could create an invalid JSON
	// such as PHP Notice, Warning, logs...
	ob_start();
	ob_clean();

	if(substr($code,0,1) == "4") {
		RouteController::insertLogs(null, $code, $message, null);
	}

	// Suppress header errors in test environment (headers may already be sent)
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($code);
	}

	// header("HTTP/1.1 401 Unauthorized");
	// header("HTTP/1.1 " . $code);
	print json_encode($message, JSON_PRETTY_PRINT);

	// In production, exit() ends the request immediately. In PHPUNIT_RUNNING
	// mode we cannot exit() because that would roll back the SQLite
	// transaction the tests rely on; we throw an Error instead so the flow
	// aborts cleanly through the router's outer catch.
	if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
		exit;
	}
	throw new JsonReplyEmittedError('json_reply emitted (HTTP '.$code.')');
}
