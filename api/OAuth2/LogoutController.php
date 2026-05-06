<?php

/**
 * LogoutController.php
 *
 * OAuth2/OIDC End Session Endpoint for SmartAuth.
 * Implements OpenID Connect RP-Initiated Logout 1.0.
 *
 * Handles user logout from the SmartAuth IdP:
 * - Clears the SmartAuth session cookie
 * - Optionally revokes all user tokens
 * - Redirects to post_logout_redirect_uri if provided and valid
 * - Otherwise displays a logout confirmation page
 *
 * Request: GET with optional parameters
 * Response: Redirect or HTML page
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

dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');
dol_include_once('/smartauth/api/JwtKeyHelper.php');

use SmartAuth\Api\JwtKeyHelper;

class LogoutController
{
    /**
     * Database connection
     * @var \DoliDB
     */
    private $db;

    /**
     * Session manager
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * Constructor
     *
     * @param \DoliDB $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->sessionManager = new SessionManager($db);
    }

    /**
     * Handle logout request
     *
     * @return void
     */
    public function handleLogout(): void
    {
        // Get parameters
        $idTokenHint = $_GET['id_token_hint'] ?? null;
        $postLogoutRedirectUri = $_GET['post_logout_redirect_uri'] ?? null;
        $state = $_GET['state'] ?? null;

        // Get current user from session (before clearing it)
        $userId = $this->sessionManager->validateSession();

        // Extract user and client info from id_token_hint if provided
        $tokenUserId = null;
        $tokenClientId = null;
        if ($idTokenHint !== null) {
            $tokenInfo = $this->decodeIdTokenHint($idTokenHint);
            $tokenUserId = $tokenInfo['userId'];
            $tokenClientId = $tokenInfo['clientId'];
        }

        // Determine which user to log out
        $logoutUserId = $tokenUserId ?? $userId;

        // Clear the session
        $this->sessionManager->clearSession();
        dol_syslog('SmartAuth LogoutController: Session cleared', LOG_INFO);

        // Revoke user tokens if we know who to log out
        if ($logoutUserId !== null) {
            $this->revokeUserTokens($logoutUserId);
        }

        // Handle redirect if post_logout_redirect_uri is provided
        if ($postLogoutRedirectUri !== null) {
            if ($this->validatePostLogoutUri($postLogoutRedirectUri, $tokenClientId)) {
                $redirectUrl = $postLogoutRedirectUri;
                if ($state !== null) {
                    $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?');
                    $redirectUrl .= 'state=' . urlencode($state);
                }
                dol_syslog('SmartAuth LogoutController: Redirecting to ' . $redirectUrl, LOG_INFO);
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                dol_syslog('SmartAuth LogoutController: Invalid post_logout_redirect_uri', LOG_WARNING);
                // Invalid redirect URI - show logout page instead of redirecting
            }
        }

        // No redirect or invalid redirect - show logout confirmation page
        $this->showLogoutPage();
    }

    /**
     * Decode and extract information from id_token_hint.
     *
     * Per OpenID Connect RP-Initiated Logout 1.0 section 3, expired tokens
     * are accepted (the whole point of logout is that the session may be
     * over). The signature, however, is mandatory: without it a forged
     * payload would let any caller revoke another user's tokens.
     *
     * @param string $idTokenHint ID token JWT
     * @return array ['userId' => int|null, 'clientId' => string|null]
     */
    private function decodeIdTokenHint(string $idTokenHint): array
    {
        $result = ['userId' => null, 'clientId' => null];

        // Split JWT
        $parts = explode('.', $idTokenHint);
        if (count($parts) !== 3) {
            return $result;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify the JWT header advertises RS256 (no 'none', no HS*).
        $headerJson = JwtKeyHelper::base64UrlDecode($headerEncoded);
        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') {
            dol_syslog('SmartAuth LogoutController: id_token_hint has unsupported alg', LOG_WARNING);
            return $result;
        }

        // Verify the signature against our current RSA public key.
        $signature = JwtKeyHelper::base64UrlDecode($signatureEncoded);
        $publicKey = JwtKeyHelper::getRsaPublicKey();
        if (empty($publicKey)) {
            dol_syslog('SmartAuth LogoutController: no RSA public key configured', LOG_ERR);
            return $result;
        }

        $dataToVerify = $headerEncoded . '.' . $payloadEncoded;
        $verified = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            dol_syslog('SmartAuth LogoutController: id_token_hint signature is invalid', LOG_WARNING);
            return $result;
        }

        $payload = json_decode(JwtKeyHelper::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload)) {
            return $result;
        }

        // Verify issuer matches our server (now trustworthy since signed)
        $issuer = $payload['iss'] ?? '';
        if ($issuer !== OAuthConfig::getIssuer()) {
            dol_syslog('SmartAuth LogoutController: id_token_hint has wrong issuer', LOG_WARNING);
            return $result;
        }

        // Extract user ID
        if (!empty($payload['sub'])) {
            $result['userId'] = (int) $payload['sub'];
        }

        // Extract client ID
        if (!empty($payload['aud'])) {
            $result['clientId'] = is_array($payload['aud']) ? $payload['aud'][0] : $payload['aud'];
        }

        return $result;
    }

    /**
     * Validate post_logout_redirect_uri
     *
     * The URI must be registered with a known client.
     * If id_token_hint was provided, the URI must belong to that client.
     *
     * @param string $uri Post logout redirect URI
     * @param string|null $clientId Client ID from id_token_hint (if provided)
     * @return bool True if URI is valid
     */
    private function validatePostLogoutUri(string $uri, ?string $clientId): bool
    {
        // Basic validation
        if (empty($uri)) {
            return false;
        }

        // Must be absolute URI
        $parsedUri = parse_url($uri);
        if (empty($parsedUri['scheme']) || empty($parsedUri['host'])) {
            return false;
        }

        // Must be HTTPS (unless localhost for development)
        $host = $parsedUri['host'];
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($parsedUri['scheme'] !== 'https' && !$isLocalhost) {
            dol_syslog('SmartAuth LogoutController: post_logout_redirect_uri must be HTTPS', LOG_DEBUG);
            return false;
        }

        // If we have a specific client ID, validate against that client
        if ($clientId !== null) {
            return $this->isUriRegisteredForClient($uri, $clientId);
        }

        // Otherwise, check if URI is registered with any client
        return $this->isUriRegisteredForAnyClient($uri);
    }

    /**
     * Check if URI is registered for a specific client
     *
     * @param string $uri URI to check
     * @param string $clientId Client ID
     * @return bool True if registered
     */
    private function isUriRegisteredForClient(string $uri, string $clientId): bool
    {
        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);

        if ($result <= 0) {
            return false;
        }

        // Check redirect URIs (we allow post_logout to match redirect URIs)
        $redirectUris = $client->getRedirectUrisArray();
        foreach ($redirectUris as $registeredUri) {
            if ($this->uriMatches($uri, $registeredUri)) {
                return true;
            }
        }

        // Check post_logout_redirect_uris if the client has them
        $postLogoutUris = $client->getPostLogoutRedirectUrisArray();
        foreach ($postLogoutUris as $registeredUri) {
            if ($this->uriMatches($uri, $registeredUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if URI is registered for any client
     *
     * @param string $uri URI to check
     * @return bool True if registered with at least one client
     */
    private function isUriRegisteredForAnyClient(string $uri): bool
    {
        // Fetch all active clients. The post_logout_redirect_uris column
        // does not exist in the schema - the
        // previous SELECT referenced it and made this whole function fail.
        // Until the column is migrated, we accept any registered redirect
        // URI as a valid post-logout target (consistent with what
        // isUriRegisteredForClient does on its own client lookup).
        $sql = "SELECT rowid, redirect_uris FROM " . MAIN_DB_PREFIX . "smartauth_oauth_clients";
        $sql .= " WHERE status = 1";
        $sql .= " AND entity IN (" . getEntity('smartauthoauthclient') . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // Check redirect_uris
            $redirectUris = json_decode($obj->redirect_uris ?? '[]', true);
            if (is_array($redirectUris)) {
                foreach ($redirectUris as $registeredUri) {
                    if ($this->uriMatches($uri, $registeredUri)) {
                        $this->db->free($resql);
                        return true;
                    }
                }
            }
        }

        $this->db->free($resql);
        return false;
    }

    /**
     * Check if URIs match (exact match required)
     *
     * @param string $requestedUri URI from request
     * @param string $registeredUri Registered URI
     * @return bool True if match
     */
    private function uriMatches(string $requestedUri, string $registeredUri): bool
    {
        // Normalize URIs (remove trailing slash for comparison)
        $requested = rtrim($requestedUri, '/');
        $registered = rtrim($registeredUri, '/');

        return $requested === $registered;
    }

    /**
     * Revoke all tokens for a user
     *
     * @param int $userId User ID
     * @return void
     */
    private function revokeUserTokens(int $userId): void
    {
        $count = \SmartAuthOAuthToken::revokeAllForUser($this->db, $userId);
        if ($count > 0) {
            dol_syslog('SmartAuth LogoutController: Revoked ' . $count . ' tokens for user ' . $userId, LOG_INFO);
        }
    }

    /**
     * Show logout confirmation page
     *
     * @return void
     */
    private function showLogoutPage(): void
    {
        $issuer = OAuthConfig::getIssuer();

        // Include the logout template
        dol_include_once('/smartauth/tpl/logout.tpl.php');
        exit;
    }
}
