<?php
/* Copyright (C) 2024-2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       core/login/functions_smartauthoauth.php
 * \ingroup    smartauth
 * \brief      SmartAuth OAuth2 authentication handler for Dolibarr.
 *
 * This file should be copied to htdocs/core/login/ in the main Dolibarr installation.
 * It allows Dolibarr to authenticate users via SmartAuth OAuth2/OIDC server.
 *
 * Configuration in conf.php:
 *   $dolibarr_main_authentication = 'smartauthoauth,dolibarr';
 *
 * Required constants in Dolibarr:
 *   - SMARTAUTH_OAUTH_ISSUER: SmartAuth server URL (e.g., https://auth.example.com)
 *   - SMARTAUTH_OAUTH_CLIENT_ID: OAuth client ID for Dolibarr
 *   - SMARTAUTH_OAUTH_CLIENT_SECRET: OAuth client secret (optional for public clients)
 */

/**
 * Check user credentials via SmartAuth OAuth2.
 *
 * @param string $usertotest     Username to test (not used in OAuth flow, kept for interface compatibility)
 * @param string $passwordtotest Password to test (not used in OAuth flow, kept for interface compatibility)
 * @param int    $entitytotest   Entity to test
 * @return mixed                 User object on success, false on failure
 */
function check_user_password_smartauthoauth($usertotest, $passwordtotest, $entitytotest)
{
    global $db, $conf, $langs;

    dol_syslog("functions_smartauthoauth::check_user_password_smartauthoauth start", LOG_DEBUG);

    // Bypass mode for initial setup or maintenance
    if (getDolGlobalInt('SMARTAUTH_OAUTH_BYPASS', 0)) {
        dol_syslog("SmartAuth OAuth bypassed by SMARTAUTH_OAUTH_BYPASS configuration", LOG_INFO);
        return false; // Pass to next handler (e.g., dolibarr)
    }

    // Check if SmartAuth OAuth is configured and available
    if (!smartauth_is_available($conf)) {
        dol_syslog("SmartAuth OAuth not available or not configured, falling back to next handler", LOG_INFO);
        return false; // Pass to next handler
    }

    // Handle OAuth callback (code exchange)
    if (!empty($_GET['code']) && !empty($_GET['state'])) {
        return smartauth_handle_callback($db, $conf, $entitytotest);
    }

    // Handle OAuth error callback
    if (!empty($_GET['error'])) {
        $error = $_GET['error'] ?? '';
        $errorDesc = $_GET['error_description'] ?? '';
        dol_syslog("SmartAuth OAuth error callback: " . $error . " - " . $errorDesc, LOG_WARNING);
        // Clear session state
        unset($_SESSION['smartauth_state'], $_SESSION['smartauth_code_verifier'], $_SESSION['smartauth_redirect_after_login']);
        return false;
    }

    // Store the current URL to redirect back after authentication
    $currentUrl = smartauth_get_current_url();
    $_SESSION['smartauth_redirect_after_login'] = $currentUrl;

    // Initiate OAuth flow by redirecting to SmartAuth authorization endpoint
    smartauth_redirect_to_authorize($conf);
    exit;
}

/**
 * Redirect to SmartAuth authorization endpoint with PKCE.
 *
 * @param object $conf Dolibarr configuration object
 * @return void
 */
function smartauth_redirect_to_authorize($conf)
{
    // Generate PKCE parameters
    $codeVerifier = smartauth_generate_code_verifier();
    $codeChallenge = smartauth_generate_code_challenge($codeVerifier);

    // Generate state for CSRF protection
    $state = bin2hex(random_bytes(16));

    // Store in session for callback validation
    $_SESSION['smartauth_state'] = $state;
    $_SESSION['smartauth_code_verifier'] = $codeVerifier;

    // Build authorization URL
    $params = array(
        'response_type' => 'code',
        'client_id' => getDolGlobalString('SMARTAUTH_OAUTH_CLIENT_ID', ''),
        'redirect_uri' => smartauth_get_callback_url(),
        'scope' => 'openid profile email',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
    );

    $issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
    $authorizeUrl = rtrim($issuer, '/') . '/oauth/authorize?' . http_build_query($params);

    dol_syslog("SmartAuth OAuth: Redirecting to authorization endpoint", LOG_DEBUG);

    header('Location: ' . $authorizeUrl);
    exit;
}

/**
 * Handle OAuth callback with authorization code.
 *
 * @param object $db     Database handler
 * @param object $conf   Dolibarr configuration object
 * @param int    $entity Entity ID
 * @return mixed         User object on success, false on failure
 */
function smartauth_handle_callback($db, $conf, $entity)
{
    global $langs;

    dol_syslog("SmartAuth OAuth: Processing callback", LOG_DEBUG);

    // Validate state to prevent CSRF
    $receivedState = $_GET['state'] ?? '';
    $storedState = $_SESSION['smartauth_state'] ?? '';

    if (empty($storedState) || !hash_equals($storedState, $receivedState)) {
        dol_syslog("SmartAuth OAuth: State mismatch - possible CSRF attack", LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Get stored code verifier for PKCE
    $codeVerifier = $_SESSION['smartauth_code_verifier'] ?? '';
    if (empty($codeVerifier)) {
        dol_syslog("SmartAuth OAuth: Missing code verifier", LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Exchange authorization code for tokens
    $code = $_GET['code'] ?? '';
    $tokenResponse = smartauth_exchange_code($code, $codeVerifier, $conf);

    if (!$tokenResponse || empty($tokenResponse['access_token'])) {
        dol_syslog("SmartAuth OAuth: Token exchange failed", LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Get user information from userinfo endpoint
    $userinfo = smartauth_get_userinfo($tokenResponse['access_token'], $conf);

    if (!$userinfo || empty($userinfo['sub'])) {
        dol_syslog("SmartAuth OAuth: Failed to get userinfo", LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Load Dolibarr user by ID (sub claim contains user rowid)
    require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
    $user = new User($db);
    $result = $user->fetch((int) $userinfo['sub']);

    if ($result <= 0) {
        dol_syslog("SmartAuth OAuth: User not found with ID " . $userinfo['sub'], LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Check if user is active
    if ($user->statut != 1) {
        dol_syslog("SmartAuth OAuth: User " . $userinfo['sub'] . " is not active", LOG_WARNING);
        smartauth_clear_session();
        return false;
    }

    // Check entity access if multi-entity is enabled
    if (isModEnabled('multicompany')) {
        if ($entity > 0 && !in_array($entity, $user->getListOfEntities())) {
            dol_syslog("SmartAuth OAuth: User " . $userinfo['sub'] . " has no access to entity " . $entity, LOG_WARNING);
            smartauth_clear_session();
            return false;
        }
    }

    // Store tokens in session for potential later use (e.g., API calls, logout)
    $_SESSION['smartauth_access_token'] = $tokenResponse['access_token'];
    if (!empty($tokenResponse['refresh_token'])) {
        $_SESSION['smartauth_refresh_token'] = $tokenResponse['refresh_token'];
    }
    if (!empty($tokenResponse['id_token'])) {
        $_SESSION['smartauth_id_token'] = $tokenResponse['id_token'];
    }

    // Clear temporary OAuth session data
    unset($_SESSION['smartauth_state'], $_SESSION['smartauth_code_verifier']);

    dol_syslog("SmartAuth OAuth: User " . $user->login . " (ID: " . $user->id . ") authenticated successfully", LOG_INFO);

    return $user;
}

/**
 * Exchange authorization code for tokens.
 *
 * @param string $code         Authorization code
 * @param string $codeVerifier PKCE code verifier
 * @param object $conf         Dolibarr configuration object
 * @return array|null          Token response or null on failure
 */
function smartauth_exchange_code($code, $codeVerifier, $conf)
{
    $issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
    $tokenUrl = rtrim($issuer, '/') . '/oauth/token';

    $postData = array(
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => smartauth_get_callback_url(),
        'client_id' => getDolGlobalString('SMARTAUTH_OAUTH_CLIENT_ID', ''),
        'code_verifier' => $codeVerifier,
    );

    // Add client secret if configured (confidential client)
    $clientSecret = getDolGlobalString('SMARTAUTH_OAUTH_CLIENT_SECRET', '');
    if (!empty($clientSecret)) {
        $postData['client_secret'] = $clientSecret;
    }

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        dol_syslog("SmartAuth OAuth: cURL error during token exchange: " . $curlError, LOG_ERR);
        return null;
    }

    if ($httpCode !== 200) {
        dol_syslog("SmartAuth OAuth: Token endpoint returned HTTP " . $httpCode, LOG_ERR);
        return null;
    }

    $tokenData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        dol_syslog("SmartAuth OAuth: Invalid JSON response from token endpoint", LOG_ERR);
        return null;
    }

    if (!empty($tokenData['error'])) {
        dol_syslog("SmartAuth OAuth: Token error: " . ($tokenData['error_description'] ?? $tokenData['error']), LOG_ERR);
        return null;
    }

    return $tokenData;
}

/**
 * Get user information from userinfo endpoint.
 *
 * @param string $accessToken OAuth access token
 * @param object $conf        Dolibarr configuration object
 * @return array|null         User info or null on failure
 */
function smartauth_get_userinfo($accessToken, $conf)
{
    $issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
    $userinfoUrl = rtrim($issuer, '/') . '/oauth/userinfo';

    $ch = curl_init($userinfoUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        dol_syslog("SmartAuth OAuth: cURL error during userinfo request: " . $curlError, LOG_ERR);
        return null;
    }

    if ($httpCode !== 200) {
        dol_syslog("SmartAuth OAuth: Userinfo endpoint returned HTTP " . $httpCode, LOG_ERR);
        return null;
    }

    $userinfo = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        dol_syslog("SmartAuth OAuth: Invalid JSON response from userinfo endpoint", LOG_ERR);
        return null;
    }

    return $userinfo;
}

/**
 * Check if SmartAuth OAuth server is available and configured.
 *
 * @param object $conf Dolibarr configuration object
 * @return bool        True if available, false otherwise
 */
function smartauth_is_available($conf)
{
    $issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');
    $clientId = getDolGlobalString('SMARTAUTH_OAUTH_CLIENT_ID', '');

    // Check basic configuration
    if (empty($issuer) || empty($clientId)) {
        dol_syslog("SmartAuth OAuth: Missing configuration (issuer or client_id)", LOG_DEBUG);
        return false;
    }

    // Health check with short timeout (2 seconds)
    $discoveryUrl = rtrim($issuer, '/') . '/.well-known/openid-configuration';

    $ch = curl_init($discoveryUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOBODY => false,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        dol_syslog("SmartAuth OAuth: Server not reachable at " . $discoveryUrl . " (HTTP: " . $httpCode . ", Error: " . $curlError . ")", LOG_DEBUG);
        return false;
    }

    // Verify response is valid JSON
    $config = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($config['issuer'])) {
        dol_syslog("SmartAuth OAuth: Invalid discovery response", LOG_DEBUG);
        return false;
    }

    return true;
}

/**
 * Get the OAuth callback URL (redirect_uri).
 *
 * @return string Callback URL
 */
function smartauth_get_callback_url()
{
    // Use DOL_MAIN_URL_ROOT if defined, otherwise build from server variables
    if (defined('DOL_MAIN_URL_ROOT') && !empty(DOL_MAIN_URL_ROOT)) {
        return DOL_MAIN_URL_ROOT . '/index.php';
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $protocol . '://' . $host . '/index.php';
}

/**
 * Get the current full URL (for redirect after login).
 *
 * @return string Current URL
 */
function smartauth_get_current_url()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    return $protocol . '://' . $host . $uri;
}

/**
 * Generate a PKCE code verifier.
 *
 * @return string Code verifier (43-128 characters, URL-safe base64)
 */
function smartauth_generate_code_verifier()
{
    // Generate 32 random bytes, encode to base64url (will be 43 characters)
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Generate a PKCE code challenge from a verifier using S256 method.
 *
 * @param string $verifier The code verifier
 * @return string          The code challenge (base64url encoded SHA256 hash)
 */
function smartauth_generate_code_challenge($verifier)
{
    $hash = hash('sha256', $verifier, true);
    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}

/**
 * Clear all SmartAuth session data.
 *
 * @return void
 */
function smartauth_clear_session()
{
    unset(
        $_SESSION['smartauth_state'],
        $_SESSION['smartauth_code_verifier'],
        $_SESSION['smartauth_redirect_after_login'],
        $_SESSION['smartauth_access_token'],
        $_SESSION['smartauth_refresh_token'],
        $_SESSION['smartauth_id_token']
    );
}

/**
 * Logout from SmartAuth (revoke tokens and clear session).
 *
 * @param object $conf Dolibarr configuration object
 * @return void
 */
function smartauth_logout($conf)
{
    $accessToken = $_SESSION['smartauth_access_token'] ?? '';
    $idToken = $_SESSION['smartauth_id_token'] ?? '';
    $issuer = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');

    // Revoke access token if present
    if (!empty($accessToken) && !empty($issuer)) {
        $revokeUrl = rtrim($issuer, '/') . '/oauth/revoke';

        $ch = curl_init($revokeUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'token' => $accessToken,
                'token_type_hint' => 'access_token',
            )),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ));
        curl_exec($ch);
        curl_close($ch);
    }

    // Clear all SmartAuth session data
    smartauth_clear_session();

    // Optionally redirect to SmartAuth end session endpoint
    if (!empty($idToken) && !empty($issuer)) {
        $logoutUrl = rtrim($issuer, '/') . '/oauth/logout';
        $params = array(
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => smartauth_get_callback_url(),
        );
        header('Location: ' . $logoutUrl . '?' . http_build_query($params));
        exit;
    }
}
