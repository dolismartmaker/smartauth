<?php

/**
 * RevocationController.php
 *
 * OAuth2 Token Revocation Endpoint for SmartAuth.
 * Implements RFC 7009 (OAuth 2.0 Token Revocation).
 *
 * Key behavior per RFC 7009:
 * - Always returns 200 OK, even if token is invalid or already revoked
 * - Supports both access_token and refresh_token revocation
 * - Cascade revocation: revoking refresh token also revokes child access tokens
 *
 * Request: POST with Content-Type: application/x-www-form-urlencoded
 * Response: 200 OK (empty body on success)
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
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

namespace SmartAuth\Api\OAuth2;

require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/class/smartauthoauthclient.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/class/smartauthoauthtoken.class.php';

class RevocationController
{
    /**
     * Database connection
     * @var \DoliDB
     */
    private $db;

    /**
     * Token service
     * @var TokenService
     */
    private $tokenService;

    /**
     * Authenticated client (optional for revocation)
     * @var \SmartAuthOAuthClient|null
     */
    private $client = null;

    /**
     * Constructor
     *
     * @param \DoliDB $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->tokenService = new TokenService($db);
    }

    /**
     * Handle token revocation request
     *
     * Per RFC 7009, this endpoint ALWAYS returns 200 OK.
     * Invalid tokens are silently ignored.
     *
     * @return void
     */
    public function handleRevoke(): void
    {
        // Must be POST
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->sendError('invalid_request', 'Method must be POST', 405);
            return;
        }

        // Parse request body
        $params = $this->parseRequestBody();

        // Get token to revoke
        $token = trim($params['token'] ?? '');
        if (empty($token)) {
            // Per RFC 7009 Section 2.1, missing token is an error
            $this->sendError('invalid_request', 'Missing required parameter: token', 400);
            return;
        }

        // Optional: token_type_hint helps optimize lookup
        $tokenTypeHint = $params['token_type_hint'] ?? null;
        if ($tokenTypeHint !== null && !in_array($tokenTypeHint, ['access_token', 'refresh_token'], true)) {
            // Invalid hint, ignore it per RFC 7009
            $tokenTypeHint = null;
        }

        // Optional: authenticate client
        // Per RFC 7009, client authentication is OPTIONAL for revocation
        // But if provided, we should validate it
        $this->client = $this->authenticateClientIfProvided($params);

        // Attempt to revoke the token
        $this->revokeTokenByValue($token, $tokenTypeHint);

        // Per RFC 7009, always return 200 OK with empty body
        dol_syslog('SmartAuth RevocationController: Revocation request processed', LOG_INFO);
        $this->sendSuccessResponse();
    }

    /**
     * Revoke a token by its value
     *
     * Tries to find and revoke the token. If the token is a refresh token,
     * also revokes all child access tokens (cascade revocation).
     *
     * @param string $token Token value
     * @param string|null $tokenTypeHint Hint about token type
     * @return bool True if token was found and revoked
     */
    private function revokeTokenByValue(string $token, ?string $tokenTypeHint): bool
    {
        // Try as refresh token first if hinted or no hint
        if ($tokenTypeHint !== 'access_token') {
            if ($this->revokeRefreshToken($token)) {
                return true;
            }
        }

        // Try as access token (by JTI or by decoding JWT)
        if ($tokenTypeHint !== 'refresh_token') {
            if ($this->revokeAccessToken($token)) {
                return true;
            }
        }

        // Token not found - this is OK per RFC 7009
        dol_syslog('SmartAuth RevocationController: Token not found for revocation (this is OK)', LOG_DEBUG);
        return false;
    }

    /**
     * Revoke a refresh token and its children
     *
     * @param string $token Refresh token value
     * @return bool True if token was found and revoked
     */
    private function revokeRefreshToken(string $token): bool
    {
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $result = $tokenRecord->fetchByToken($token);

        if ($result <= 0) {
            return false;
        }

        // Verify it's a refresh token
        if (!$tokenRecord->isRefreshToken()) {
            return false;
        }

        // If client is authenticated, verify token belongs to this client
        if ($this->client !== null && $tokenRecord->fk_client !== $this->client->id) {
            dol_syslog('SmartAuth RevocationController: Token does not belong to authenticated client', LOG_DEBUG);
            // Per RFC 7009, we should still return 200 OK but not revoke
            return false;
        }

        // Already revoked? Still return success
        if ($tokenRecord->isRevoked()) {
            dol_syslog('SmartAuth RevocationController: Refresh token already revoked', LOG_DEBUG);
            return true;
        }

        // Revoke with cascade (revokes all child access tokens)
        $count = $tokenRecord->revokeWithChildren();
        if ($count > 0) {
            dol_syslog('SmartAuth RevocationController: Revoked refresh token and ' . ($count - 1) . ' child tokens', LOG_INFO);
            return true;
        }

        return false;
    }

    /**
     * Revoke an access token
     *
     * Access tokens are JWTs. We try to:
     * 1. Decode the JWT to get the JTI
     * 2. Find and revoke the token record by JTI
     *
     * @param string $token Access token value (JWT)
     * @return bool True if token was found and revoked
     */
    private function revokeAccessToken(string $token): bool
    {
        // First, try to decode as JWT to get JTI
        $payload = $this->tokenService->validateAccessToken($token);

        if ($payload !== null && !empty($payload['jti'])) {
            $jti = $payload['jti'];

            $tokenRecord = new \SmartAuthOAuthToken($this->db);
            $result = $tokenRecord->fetchByJti($jti);

            if ($result > 0) {
                // If client is authenticated, verify token belongs to this client
                if ($this->client !== null && $tokenRecord->fk_client !== $this->client->id) {
                    dol_syslog('SmartAuth RevocationController: Access token does not belong to authenticated client', LOG_DEBUG);
                    return false;
                }

                // Already revoked?
                if ($tokenRecord->isRevoked()) {
                    dol_syslog('SmartAuth RevocationController: Access token already revoked', LOG_DEBUG);
                    return true;
                }

                $result = $tokenRecord->revoke();
                if ($result > 0) {
                    dol_syslog('SmartAuth RevocationController: Access token revoked (jti=' . $jti . ')', LOG_INFO);
                    return true;
                }
            }
        }

        // Token might be expired but still in database - try finding by token hash
        // This handles the case where JWT is invalid but we still want to revoke the record
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenHash = hash('sha256', $token);
        $result = $tokenRecord->fetchByTokenHash($tokenHash);

        if ($result > 0 && $tokenRecord->isAccessToken()) {
            if ($this->client !== null && $tokenRecord->fk_client !== $this->client->id) {
                return false;
            }
            if (!$tokenRecord->isRevoked()) {
                $tokenRecord->revoke();
                dol_syslog('SmartAuth RevocationController: Access token revoked by hash', LOG_INFO);
            }
            return true;
        }

        return false;
    }

    /**
     * Authenticate client if credentials are provided
     *
     * Per RFC 7009, client authentication is optional for revocation.
     * But if credentials are provided, they must be valid.
     *
     * @param array $params Request parameters
     * @return \SmartAuthOAuthClient|null Client if authenticated, null otherwise
     */
    private function authenticateClientIfProvided(array $params): ?\SmartAuthOAuthClient
    {
        $credentials = $this->getClientCredentials($params);
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];

        // No credentials provided - this is OK
        if (empty($clientId)) {
            return null;
        }

        // Credentials provided - must be valid
        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);

        if ($result <= 0) {
            dol_syslog('SmartAuth RevocationController: Client not found: ' . $clientId, LOG_DEBUG);
            // Invalid client - still return null, don't error
            return null;
        }

        // For confidential clients, verify secret
        if ($client->isConfidential()) {
            if (empty($clientSecret) || !$client->verifySecret($clientSecret)) {
                dol_syslog('SmartAuth RevocationController: Invalid client secret', LOG_DEBUG);
                return null;
            }
        }

        return $client;
    }

    /**
     * Extract client credentials from request
     *
     * @param array $params POST parameters
     * @return array ['client_id' => string|null, 'client_secret' => string|null]
     */
    private function getClientCredentials(array $params): array
    {
        $clientId = null;
        $clientSecret = null;

        // Try HTTP Basic Auth first
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $clientId = $_SERVER['PHP_AUTH_USER'];
            $clientSecret = $_SERVER['PHP_AUTH_PW'] ?? null;
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (stripos($auth, 'Basic ') === 0) {
                $decoded = base64_decode(substr($auth, 6));
                if ($decoded !== false) {
                    $parts = explode(':', $decoded, 2);
                    $clientId = urldecode($parts[0]);
                    $clientSecret = isset($parts[1]) ? urldecode($parts[1]) : null;
                }
            }
        }

        // Fall back to POST body
        if (empty($clientId)) {
            $clientId = $params['client_id'] ?? null;
            $clientSecret = $params['client_secret'] ?? null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * Parse form-urlencoded request body
     *
     * @return array Parsed parameters
     */
    private function parseRequestBody(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return $_POST;
        }

        $params = [];
        parse_str($input, $params);

        return array_merge($_POST, $params);
    }

    /**
     * Send success response (empty body, 200 OK)
     *
     * @return void
     */
    private function sendSuccessResponse(): void
    {
        http_response_code(200);
        header('Content-Type: application/json;charset=UTF-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        // RFC 7009 does not require a response body, but returning empty JSON is cleaner
        echo '{}';
        exit;
    }

    /**
     * Send OAuth error response
     *
     * Note: Per RFC 7009, most errors should still return 200 OK.
     * Only use this for protocol-level errors (wrong method, missing token param).
     *
     * @param string $error Error code
     * @param string $description Human-readable description
     * @param int $status HTTP status code
     * @return void
     */
    private function sendError(string $error, string $description, int $status = 400): void
    {
        dol_syslog('SmartAuth RevocationController: Error ' . $error . ': ' . $description, LOG_INFO);

        http_response_code($status);
        header('Content-Type: application/json;charset=UTF-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        $response = [
            'error' => $error,
            'error_description' => $description,
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
