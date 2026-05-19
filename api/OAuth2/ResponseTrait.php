<?php

/**
 * ResponseTrait.php
 *
 * Common response handling for OAuth2 controllers.
 * In test mode, throws ResponseException instead of calling exit().
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

trait ResponseTrait
{
    /**
     * Test mode flag - when true, throws ResponseException instead of exit
     * @var bool
     */
    private static $testMode = false;

    /**
     * Enable test mode (throws exceptions instead of exit)
     *
     * @return void
     */
    public static function enableTestMode(): void
    {
        self::$testMode = true;
    }

    /**
     * Disable test mode (normal exit behavior)
     *
     * @return void
     */
    public static function disableTestMode(): void
    {
        self::$testMode = false;
    }

    /**
     * Check if test mode is enabled
     *
     * @return bool
     */
    public static function isTestMode(): bool
    {
        return self::$testMode;
    }

    /**
     * Send JSON response
     *
     * In test mode, throws ResponseException.
     * In normal mode, outputs JSON and exits.
     *
     * @param array $data Response data
     * @param int $status HTTP status code
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendJsonResponse(array $data, int $status = 200): void
    {
        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ];

        if (self::$testMode) {
            throw new ResponseException($data, $status, $headers);
        }

        http_response_code($status);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send OAuth error response
     *
     * In test mode, throws ResponseException.
     * In normal mode, outputs JSON error and exits.
     *
     * @param string $error Error code (RFC 6749)
     * @param string $description Human-readable description
     * @param int $status HTTP status code
     * @param string|null $wwwAuthenticate Custom WWW-Authenticate header value (null for default)
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendError(string $error, string $description, int $status = 400, ?string $wwwAuthenticate = null): void
    {
        dol_syslog('SmartAuth OAuth2: Error ' . $error . ': ' . $description, LOG_INFO);

        $response = [
            'error' => $error,
            'error_description' => $description,
        ];

        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ];

        // Add WWW-Authenticate header for 401
        if ($status === 401) {
            if ($wwwAuthenticate !== null) {
                $headers['WWW-Authenticate'] = $wwwAuthenticate;
            } else {
                $headers['WWW-Authenticate'] = 'Basic realm="SmartAuth OAuth2"';
            }
        }

        if (self::$testMode) {
            throw new ResponseException($response, $status, $headers);
        }

        http_response_code($status);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send Bearer token error response (RFC 6750)
     *
     * @param string $error Error code
     * @param string $description Human-readable description
     * @param int $status HTTP status code
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendBearerError(string $error, string $description, int $status = 400): void
    {
        $wwwAuth = null;
        if ($status === 401) {
            $wwwAuth = 'Bearer realm="SmartAuth"';
            $wwwAuth .= ', error="' . $error . '"';
            $wwwAuth .= ', error_description="' . addslashes($description) . '"';
        }
        $this->sendError($error, $description, $status, $wwwAuth);
    }

    /**
     * Send JSON response with caller-controlled headers.
     *
     * Unlike sendJsonResponse(), this variant does NOT force Cache-Control:
     * no-store: the caller decides. Useful for endpoints meant to be polled
     * with conditional GETs (ETag / If-None-Match) such as /oauth/revoked-jti.
     *
     * The Content-Type is set to application/json by default but can be
     * overridden by the caller.
     *
     * @param array $data    Response data
     * @param int   $status  HTTP status code
     * @param array $headers Headers to send (e.g. ['ETag' => ..., 'Cache-Control' => ...])
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendJsonResponseWithHeaders(array $data, int $status, array $headers): void
    {
        if (!array_key_exists('Content-Type', $headers)) {
            $headers['Content-Type'] = 'application/json;charset=UTF-8';
        }

        if (self::$testMode) {
            throw new ResponseException($data, $status, $headers);
        }

        http_response_code($status);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a 304 Not Modified response, optionally echoing back the ETag the
     * client validated against. RFC 7232 §4.1 requires the same validators
     * the client used (ETag and/or Last-Modified) to be present.
     *
     * @param string|null $etag        ETag value to echo back (with quotes)
     * @param string|null $cacheControl Cache-Control directive (default:
     *                                  'public, max-age=0, must-revalidate'
     *                                  so backends always revalidate the
     *                                  freshness on their next poll).
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendNotModified(?string $etag = null, ?string $cacheControl = null): void
    {
        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers['ETag'] = $etag;
        }
        $headers['Cache-Control'] = $cacheControl ?? 'public, max-age=0, must-revalidate';

        if (self::$testMode) {
            throw new ResponseException([], 304, $headers);
        }

        http_response_code(304);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        exit;
    }

    /**
     * Send empty success response (for revocation endpoint)
     *
     * @return void
     * @throws ResponseException In test mode
     */
    protected function sendEmptyResponse(): void
    {
        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ];

        if (self::$testMode) {
            throw new ResponseException([], 200, $headers);
        }

        http_response_code(200);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo '{}';
        exit;
    }
}
