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

function json_reply($message, $code)
{
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

	// Skip exit() in PHPUnit test environment to allow tests to continue
	if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
		exit;
	}
}
