<?php

/**
 * UserinfoController.php
 *
 * OAuth2/OIDC Userinfo Endpoint for SmartAuth.
 * Implements OpenID Connect Core 1.0 Section 5.3 (UserInfo Endpoint).
 *
 * Returns claims about the authenticated user based on the scopes
 * granted in the access token.
 *
 * Request: GET or POST with Bearer token in Authorization header
 * Response: Content-Type: application/json
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

require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

class UserinfoController
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
     * Handle userinfo request
     *
     * Accepts GET or POST requests with Bearer token.
     *
     * @return void
     */
    public function handleUserinfo(): void
    {
        // Only GET or POST allowed
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->sendError('invalid_request', 'Method must be GET or POST', 405);
            return;
        }

        // Extract Bearer token
        $token = $this->extractBearerToken();
        if ($token === null) {
            $this->sendError('invalid_token', 'Missing or invalid Bearer token', 401);
            return;
        }

        // Validate access token
        $payload = $this->tokenService->validateAccessToken($token);
        if ($payload === null) {
            $this->sendError('invalid_token', 'Access token is invalid or expired', 401);
            return;
        }

        // Get user ID from token
        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            $this->sendError('invalid_token', 'Token does not contain valid user identifier', 401);
            return;
        }

        // Load user
        $user = new \User($this->db);
        $result = $user->fetch($userId);
        if ($result <= 0) {
            $this->sendError('invalid_token', 'User not found', 401);
            return;
        }

        // Check user is active
        if ($user->statut != 1) {
            $this->sendError('invalid_token', 'User account is disabled', 401);
            return;
        }

        // Get scopes from token
        $scopeString = $payload['scope'] ?? '';
        $scopes = ScopeManager::parseScopes($scopeString);

        // Build claims based on scopes
        $claims = $this->buildClaims($user, $scopes);

        dol_syslog('SmartAuth UserinfoController: Returning claims for user ' . $userId, LOG_INFO);

        $this->sendJsonResponse($claims);
    }

    /**
     * Extract Bearer token from Authorization header
     *
     * Supports:
     * - Authorization: Bearer {token}
     * - POST body: access_token={token} (fallback for form POST)
     *
     * @return string|null Token or null if not found
     */
    private function extractBearerToken(): ?string
    {
        // Debug: log all authorization-related headers
        dol_syslog('SmartAuth UserinfoController::extractBearerToken - REQUEST_METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? 'null'), LOG_DEBUG);
        dol_syslog('SmartAuth UserinfoController::extractBearerToken - HTTP_AUTHORIZATION=' . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present (len=' . strlen($_SERVER['HTTP_AUTHORIZATION']) . ')' : 'missing'), LOG_DEBUG);
        dol_syslog('SmartAuth UserinfoController::extractBearerToken - REDIRECT_HTTP_AUTHORIZATION=' . (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? 'present' : 'missing'), LOG_DEBUG);

        // Try Authorization header first
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Apache may use different variable
        if (empty($authHeader) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            dol_syslog('SmartAuth UserinfoController::extractBearerToken - using REDIRECT_HTTP_AUTHORIZATION', LOG_DEBUG);
        }

        // Debug: log header value (masked)
        if (!empty($authHeader)) {
            $maskedHeader = substr($authHeader, 0, 15) . '...' . substr($authHeader, -10);
            dol_syslog('SmartAuth UserinfoController::extractBearerToken - authHeader=' . $maskedHeader, LOG_DEBUG);
        } else {
            dol_syslog('SmartAuth UserinfoController::extractBearerToken - authHeader is empty', LOG_DEBUG);
        }

        // Check for Bearer scheme
        if (!empty($authHeader) && stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            if (!empty($token)) {
                dol_syslog('SmartAuth UserinfoController::extractBearerToken - Bearer token extracted (len=' . strlen($token) . ')', LOG_DEBUG);
                return $token;
            }
            dol_syslog('SmartAuth UserinfoController::extractBearerToken - Bearer prefix found but token empty', LOG_DEBUG);
        }

        // Fallback: check POST body for access_token (RFC 6750 Section 2.2)
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            dol_syslog('SmartAuth UserinfoController::extractBearerToken - POST Content-Type=' . $contentType, LOG_DEBUG);
            if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $accessToken = $_POST['access_token'] ?? null;
                if (!empty($accessToken)) {
                    dol_syslog('SmartAuth UserinfoController::extractBearerToken - access_token from POST body (len=' . strlen($accessToken) . ')', LOG_DEBUG);
                    return $accessToken;
                }
                dol_syslog('SmartAuth UserinfoController::extractBearerToken - POST body has no access_token', LOG_DEBUG);
            }
        }

        dol_syslog('SmartAuth UserinfoController::extractBearerToken - no token found', LOG_WARNING);
        return null;
    }

    /**
     * Build claims based on user data and granted scopes
     *
     * Claims are filtered according to the scopes in the access token:
     * - openid: sub (always included if openid scope)
     * - profile: name, family_name, given_name, updated_at
     * - email: email, email_verified
     * - groups: groups (array of Dolibarr group names)
     * - roles: roles (array of derived roles)
     *
     * @param \User $user Dolibarr user object
     * @param array $scopes Array of granted scopes
     * @return array Claims to return
     */
    private function buildClaims(\User $user, array $scopes): array
    {
        $claims = [];

        // sub is always returned (required for userinfo)
        $claims['sub'] = (string) $user->id;

        // Profile scope: name, family_name, given_name, updated_at
        if (in_array('profile', $scopes, true)) {
            $fullName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
            if (!empty($fullName)) {
                $claims['name'] = $fullName;
            }
            if (!empty($user->lastname)) {
                $claims['family_name'] = $user->lastname;
            }
            if (!empty($user->firstname)) {
                $claims['given_name'] = $user->firstname;
            }
            // updated_at: use tms (timestamp modification) or datec
            $updatedAt = null;
            if (!empty($user->tms)) {
                $updatedAt = is_numeric($user->tms) ? (int)$user->tms : strtotime($user->tms);
            } elseif (!empty($user->datec)) {
                $updatedAt = is_numeric($user->datec) ? (int)$user->datec : strtotime($user->datec);
            }
            if ($updatedAt !== null && $updatedAt > 0) {
                $claims['updated_at'] = $updatedAt;
            }
        }

        // Email scope: email, email_verified
        if (in_array('email', $scopes, true)) {
            if (!empty($user->email)) {
                $claims['email'] = $user->email;
                // Dolibarr does not track email verification, assume verified
                $claims['email_verified'] = true;
            }
        }

        // Groups scope: groups array
        if (in_array('groups', $scopes, true)) {
            $groups = $this->getUserGroups($user);
            if (!empty($groups)) {
                $claims['groups'] = $groups;
            }
        }

        // Roles scope: roles array
        if (in_array('roles', $scopes, true)) {
            $roles = $this->getUserRoles($user);
            if (!empty($roles)) {
                $claims['roles'] = $roles;
            }
        }

        return $claims;
    }

    /**
     * Get user's group names from Dolibarr
     *
     * @param \User $user Dolibarr user
     * @return array Array of group names
     */
    private function getUserGroups(\User $user): array
    {
        $groups = [];

        // Load user groups if not loaded
        if (empty($user->user_group_list)) {
            $user->getrights();
        }

        // Get group IDs
        $groupIds = [];
        if (!empty($user->user_group_list) && is_array($user->user_group_list)) {
            $groupIds = array_keys($user->user_group_list);
        }

        // Fetch group names
        if (!empty($groupIds)) {
            $sql = "SELECT nom FROM " . MAIN_DB_PREFIX . "usergroup";
            $sql .= " WHERE rowid IN (" . implode(',', array_map('intval', $groupIds)) . ")";

            $resql = $this->db->query($sql);
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $groups[] = $obj->nom;
                }
                $this->db->free($resql);
            }
        }

        return $groups;
    }

    /**
     * Get user's derived roles
     *
     * Maps Dolibarr groups and admin status to OIDC roles.
     *
     * @param \User $user Dolibarr user
     * @return array Array of role names
     */
    private function getUserRoles(\User $user): array
    {
        $roles = ['ROLE_USER'];

        // Admin users get ROLE_ADMIN
        if (!empty($user->admin)) {
            $roles[] = 'ROLE_ADMIN';
        }

        // Map groups to roles based on configuration
        $groups = $this->getUserGroups($user);
        $roleMapping = $this->getRoleMapping();

        foreach ($groups as $group) {
            if (isset($roleMapping[$group])) {
                $roles[] = $roleMapping[$group];
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Get group to role mapping from configuration
     *
     * @return array Mapping of group name => role name
     */
    private function getRoleMapping(): array
    {
        // Default mapping
        $mapping = [
            'Administrateurs' => 'ROLE_ADMIN',
            'Bureau' => 'ROLE_BUREAU',
            'Membres' => 'ROLE_MEMBER',
        ];

        // Load custom mapping from configuration
        $customMapping = getDolGlobalString('SMARTAUTH_OAUTH_ROLE_MAPPING', '');
        if (!empty($customMapping)) {
            $decoded = json_decode($customMapping, true);
            if (is_array($decoded)) {
                $mapping = array_merge($mapping, $decoded);
            }
        }

        return $mapping;
    }

    /**
     * Send JSON response
     *
     * @param array $data Response data
     * @param int $status HTTP status code
     * @return void
     */
    private function sendJsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json;charset=UTF-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send OAuth error response
     *
     * Per RFC 6750, returns error in WWW-Authenticate header for 401 responses.
     *
     * @param string $error Error code
     * @param string $description Human-readable description
     * @param int $status HTTP status code
     * @return void
     */
    private function sendError(string $error, string $description, int $status = 400): void
    {
        dol_syslog('SmartAuth UserinfoController: Error ' . $error . ': ' . $description, LOG_INFO);

        http_response_code($status);
        header('Content-Type: application/json;charset=UTF-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        // Add WWW-Authenticate header for 401 (RFC 6750 Section 3)
        if ($status === 401) {
            $wwwAuth = 'Bearer realm="SmartAuth"';
            $wwwAuth .= ', error="' . $error . '"';
            $wwwAuth .= ', error_description="' . addslashes($description) . '"';
            header('WWW-Authenticate: ' . $wwwAuth);
        }

        $response = [
            'error' => $error,
            'error_description' => $description,
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
