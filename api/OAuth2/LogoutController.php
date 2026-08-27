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
dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
// LoginController owns the one-shot CSRF session key shared by every
// interactive form of the portal (login, confirmed logout).
dol_include_once('/smartauth/api/OAuth2/LoginController.php');

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
     * Two very different callers meet here, and each gets the revocation
     * scope it can prove:
     *
     *  - RP-initiated logout (valid id_token_hint): the hint is a signed
     *    statement from ONE client the subject once logged into. An id_token
     *    is not a secret (every RP holds valid ones, expired included), so it
     *    must never authorise revoking the subject's tokens at OTHER
     *    clients. Revocation is bounded to the hinting client (its aud),
     *    and nothing happens at all when the hint contradicts the caller's
     *    own session subject.
     *
     *  - Subject-initiated logout (session only, no hint): revoking every
     *    token of the session subject is destructive, so it requires an
     *    explicit POST confirmed by the one-shot session CSRF token. A bare
     *    GET only drops the session cookie.
     *
     * @return void
     */
    public function handleLogout(): void
    {
        // GET stays the OIDC RP-Initiated Logout transport (the RP navigates
        // the browser here); POST is accepted for both shapes.
        $idTokenHint = $_GET['id_token_hint'] ?? $_POST['id_token_hint'] ?? null;
        $postLogoutRedirectUri = $_GET['post_logout_redirect_uri'] ?? $_POST['post_logout_redirect_uri'] ?? null;
        $state = $_GET['state'] ?? $_POST['state'] ?? null;

        // Current subject from session (before any clearing).
        $sessionSubject = $this->sessionManager->validateSession();

        $hintSubject = null;
        $tokenClientId = null;
        if ($idTokenHint !== null) {
            $tokenInfo = $this->decodeIdTokenHint($idTokenHint);
            $hintSubject = $tokenInfo['subject'];
            $tokenClientId = $tokenInfo['clientId'];
        }

        $tokensRevoked = false;

        if ($hintSubject !== null) {
            // The hint contradicts the browser's own session: act on nothing.
            // A legitimate RP logout arrives either session-less or with the
            // subject's own session; anything else is a replay attempt.
            if ($sessionSubject !== null && $sessionSubject->toSub() !== $hintSubject->toSub()) {
                dol_syslog(
                    '[SmartAuth] LogoutController: id_token_hint subject ' . $hintSubject->toSub()
                    . ' does not match session subject ' . $sessionSubject->toSub() . ' - no action taken',
                    LOG_WARNING
                );
                $this->showLogoutPage($tokensRevoked);
                return;
            }

            $this->sessionManager->clearSession();
            dol_syslog('[SmartAuth] LogoutController: Session cleared (RP-initiated, subject ' . $hintSubject->toSub() . ')', LOG_INFO);

            // Bounded revocation: only what the hinting client obtained.
            if ($tokenClientId !== null) {
                // The aud claim carries the client_id string; the revocation
                // helper wants the rowid.
                $client = new \SmartAuthOAuthClient($this->db);
                if ($client->fetch(0, null, $tokenClientId) > 0) {
                    $count = \SmartAuthOAuthToken::revokeAllForSubjectAndClient(
                        $this->db,
                        $hintSubject->getType(),
                        $hintSubject->getId(),
                        (int) $client->id
                    );
                    if ($count > 0) {
                        $tokensRevoked = true;
                        dol_syslog('[SmartAuth] LogoutController: Revoked ' . $count . ' tokens of subject '
                            . $hintSubject->toSub() . ' for client ' . $tokenClientId, LOG_INFO);
                    }
                } else {
                    dol_syslog('[SmartAuth] LogoutController: hint client ' . $tokenClientId . ' not found - nothing revoked', LOG_NOTICE);
                }
            }
        } elseif ($sessionSubject !== null && $this->isConfirmedLogoutPost()) {
            // Subject explicitly asked to end everything: confirmed POST only.
            $this->sessionManager->clearSession();
            $count = \SmartAuthOAuthToken::revokeAllForSubject(
                $this->db,
                $sessionSubject->getType(),
                $sessionSubject->getId()
            );
            dol_syslog('[SmartAuth] LogoutController: Revoked ' . (int) $count . ' tokens of subject '
                . $sessionSubject->toSub() . ' (confirmed logout)', LOG_INFO);
            $tokensRevoked = true;
        } else {
            // Plain session logout: drop the cookie, keep the tokens.
            if ($sessionSubject !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                dol_syslog('[SmartAuth] LogoutController: unconfirmed POST - session cleared, tokens kept', LOG_NOTICE);
            }
            $this->sessionManager->clearSession();
            dol_syslog('[SmartAuth] LogoutController: Session cleared (cookie only)', LOG_INFO);
        }

        // Handle redirect if post_logout_redirect_uri is provided
        if ($postLogoutRedirectUri !== null) {
            if ($this->validatePostLogoutUri($postLogoutRedirectUri, $tokenClientId)) {
                $redirectUrl = $postLogoutRedirectUri;
                if ($state !== null) {
                    $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?');
                    $redirectUrl .= 'state=' . urlencode($state);
                }
                dol_syslog('[SmartAuth] LogoutController: Redirecting to ' . $redirectUrl, LOG_INFO);
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                dol_syslog('[SmartAuth] LogoutController: Invalid post_logout_redirect_uri', LOG_WARNING);
                // Invalid redirect URI - show logout page instead of redirecting
            }
        }

        // No redirect or invalid redirect - show logout confirmation page
        $this->showLogoutPage($tokensRevoked);
    }

    /**
     * Whether the request is a POST confirmed by the one-shot session CSRF
     * token (same storage and key the login form uses).
     *
     * @return bool
     */
    private function isConfirmedLogoutPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }
        $provided = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if ($provided === '') {
            return false;
        }
        $stored = isset($_SESSION[LoginController::CSRF_SESSION_KEY]) ? (string) $_SESSION[LoginController::CSRF_SESSION_KEY] : '';
        if ($stored === '') {
            return false;
        }
        // One-shot: consume the token whatever the outcome.
        unset($_SESSION[LoginController::CSRF_SESSION_KEY]);
        return hash_equals($stored, $provided);
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
     * @return array ['subject' => TokenSubject|null, 'userId' => int|null, 'clientId' => string|null]
     */
    private function decodeIdTokenHint(string $idTokenHint): array
    {
        $result = ['subject' => null, 'userId' => null, 'clientId' => null];

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
            dol_syslog('[SmartAuth] LogoutController: id_token_hint has unsupported alg', LOG_WARNING);
            return $result;
        }

        // Verify the signature against our current RSA public key.
        $signature = JwtKeyHelper::base64UrlDecode($signatureEncoded);
        $publicKey = JwtKeyHelper::getRsaPublicKey();
        if (empty($publicKey)) {
            dol_syslog('[SmartAuth] LogoutController: no RSA public key configured', LOG_ERR);
            return $result;
        }

        $dataToVerify = $headerEncoded . '.' . $payloadEncoded;
        $verified = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            dol_syslog('[SmartAuth] LogoutController: id_token_hint signature is invalid', LOG_WARNING);
            return $result;
        }

        $payload = json_decode(JwtKeyHelper::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload)) {
            return $result;
        }

        // Verify issuer matches our server (now trustworthy since signed)
        $issuer = $payload['iss'] ?? '';
        if ($issuer !== OAuthConfig::getIssuer()) {
            dol_syslog('[SmartAuth] LogoutController: id_token_hint has wrong issuer', LOG_WARNING);
            return $result;
        }

        // Extract user ID from the prefixed sub. Bulk revocation is keyed on
        // fk_user, so only a `user` subject yields a userId here; an `account`
        // subject leaves userId null (session cookie clearing still applies).
        if (!empty($payload['sub'])) {
            try {
                $hintSubject = TokenSubject::fromSub((string) $payload['sub']);
                // External subjects (acc:/mbr:) are returned too: revocation
                // is subject-aware downstream. userId stays user-only for
                // backward compatibility with older callers.
                $result['subject'] = $hintSubject;
                if ($hintSubject->isUser()) {
                    $result['userId'] = $hintSubject->getId();
                }
            } catch (\InvalidArgumentException $e) {
                dol_syslog('[SmartAuth] LogoutController: id_token_hint has malformed sub', LOG_WARNING);
            }
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
            dol_syslog('[SmartAuth] LogoutController: post_logout_redirect_uri must be HTTPS', LOG_DEBUG);
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
     * Show logout confirmation page
     *
     * @param bool $tokensRevoked Whether any OAuth token was actually revoked
     * @return void
     */
    private function showLogoutPage(bool $tokensRevoked = false): void
    {
        $issuer = OAuthConfig::getIssuer();

        // Include the logout template
        dol_include_once('/smartauth/tpl/logout.tpl.php');
        exit;
    }
}
