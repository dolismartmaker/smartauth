<?php

/**
 * OAuth2/OIDC Front Controller for SmartAuth
 *
 * This is the entry point for the dedicated vhost serving OAuth2/OIDC endpoints.
 * Routes all requests to appropriate controllers.
 *
 * Endpoints handled:
 * - /.well-known/openid-configuration  (OIDC Discovery)
 * - /.well-known/jwks.json             (JWKS)
 * - /oauth/authorize                    (Authorization)
 * - /oauth/token                        (Token - future)
 * - /oauth/userinfo                     (Userinfo - future)
 * - /oauth/revoke                       (Revocation - future)
 * - /oauth/logout                       (End session - future)
 * - /login                              (Login page)
 * - /logout                             (Logout)
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

// ============================================================================
// Error handling
// ============================================================================

// Set error reporting for production
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');

// Custom error handler for JSON responses
set_exception_handler(function ($e) {
    error_log('SmartAuth OAuth: Uncaught exception: ' . $e->getMessage());
    sendJsonError('server_error', 'An internal error occurred', 500);
});

// ============================================================================
// Security headers
// ============================================================================

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

// ============================================================================
// Bootstrap Dolibarr
// ============================================================================

$dolibarrLoaded = false;
$bootstrapErrors = [];

// Try to find Dolibarr main.inc.php
$possiblePaths = [
    // Standard Dolibarr custom module location
    dirname(__DIR__, 4) . '/main.inc.php',                    // /var/www/dolibarr/htdocs/main.inc.php
    dirname(__DIR__, 3) . '/main.inc.php',                    // One level up
    dirname(__DIR__, 2) . '/main.inc.php',                    // Two levels up
    // Development paths
    '/var/www/html/dolibarr/htdocs/main.inc.php',
    '/var/www/dolibarr/htdocs/main.inc.php',
];

// Also check CONTEXT_DOCUMENT_ROOT
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    array_unshift($possiblePaths, $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php');
}

// Environment variable override
if (!empty($_ENV['DOLIBARR_MAIN_INC'])) {
    array_unshift($possiblePaths, $_ENV['DOLIBARR_MAIN_INC']);
}

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        try {
            // Prevent Dolibarr from starting a session or outputting headers
            if (!defined('NOCSRFCHECK')) {
                define('NOCSRFCHECK', 1);
            }
            if (!defined('NOTOKENRENEWAL')) {
                define('NOTOKENRENEWAL', 1);
            }
            if (!defined('NOLOGIN')) {
                define('NOLOGIN', 1);
            }
            if (!defined('NOIPCHECK')) {
                define('NOIPCHECK', 1);
            }

            require_once $path;
            $dolibarrLoaded = true;
            break;
        } catch (Exception $e) {
            $bootstrapErrors[] = "Failed to load $path: " . $e->getMessage();
        }
    }
}

if (!$dolibarrLoaded) {
    error_log('SmartAuth OAuth: Failed to bootstrap Dolibarr. Tried: ' . implode(', ', $possiblePaths));
    sendJsonError('server_error', 'Server configuration error', 500);
}

// ============================================================================
// Load SmartAuth dependencies
// ============================================================================

// Load OAuth2 classes.
// RouteController is referenced via a fully-qualified name further down
// (\SmartAuth\Api\RouteController::emitSecurityHeaders() and
// ::resolveCorsOrigin()). In production Dolibarr there is no composer
// PSR-4 autoloader, so the class MUST be explicitly required here -
// otherwise the FQN reference fatals with "Class not found".
dol_include_once('/smartauth/api/SmartAuthLogger.php');
dol_include_once('/smartauth/api/JwtKeyHelper.php');
dol_include_once('/smartauth/api/RateLimiter.php');
dol_include_once('/smartauth/api/RouteController.php');
dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');
dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
dol_include_once('/smartauth/api/OAuth2/SubjectAuthenticator.php');
dol_include_once('/smartauth/api/OAuth2/DiscoveryController.php');
dol_include_once('/smartauth/api/OAuth2/SessionManager.php');
dol_include_once('/smartauth/api/OAuth2/LoginController.php');
dol_include_once('/smartauth/api/OAuth2/PKCEHelper.php');
dol_include_once('/smartauth/api/OAuth2/ScopeManager.php');
dol_include_once('/smartauth/api/OAuth2/AuthorizationController.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');
dol_include_once('/smartauth/api/OAuth2/TokenController.php');
dol_include_once('/smartauth/api/OAuth2/UserinfoController.php');
dol_include_once('/smartauth/api/OAuth2/RevocationController.php');
dol_include_once('/smartauth/api/OAuth2/RevokedJtiController.php');
dol_include_once('/smartauth/api/OAuth2/LogoutController.php');
dol_include_once('/smartauth/api/Account/EmailValidationToken.php');
dol_include_once('/smartauth/api/Account/RegistrationGate.php');
dol_include_once('/smartauth/api/Account/RegistrationService.php');
dol_include_once('/smartauth/api/Account/RegisterController.php');
dol_include_once('/smartauth/api/Account/AccountService.php');
dol_include_once('/smartauth/api/Account/AccountController.php');
dol_include_once('/smartauth/api/Account/EmailAlternativeController.php');
dol_include_once('/smartauth/api/Account/PasswordHtmlController.php');
dol_include_once('/smartauth/api/LandingController.php');

use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Api\OAuth2\DiscoveryController;
use SmartAuth\Api\OAuth2\LoginController;
use SmartAuth\Api\OAuth2\AuthorizationController;
use SmartAuth\Api\OAuth2\TokenController;
use SmartAuth\Api\OAuth2\UserinfoController;
use SmartAuth\Api\OAuth2\RevocationController;
use SmartAuth\Api\OAuth2\RevokedJtiController;
use SmartAuth\Api\OAuth2\LogoutController;
use SmartAuth\Api\Account\RegisterController;
use SmartAuth\Api\Account\AccountController;
use SmartAuth\Api\Account\EmailAlternativeController;
use SmartAuth\Api\Account\PasswordHtmlController;
use SmartAuth\Api\LandingController;

// ============================================================================
// Check if OAuth is enabled
// ============================================================================

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = '/' . ltrim($requestPath, '/');

// Discovery endpoints should work even if OAuth is disabled (for health checks)
$isDiscoveryEndpoint = in_array($requestPath, [
    '/.well-known/openid-configuration',
    '/.well-known/jwks.json'
]);

// Check if OAuth is enabled (except for discovery endpoints which return disabled status)
if (!OAuthConfig::isEnabled() && !$isDiscoveryEndpoint) {
    sendJsonError('service_unavailable', 'OAuth2 server is not enabled', 503);
}

// ============================================================================
// Handle OPTIONS requests (CORS preflight)
// ============================================================================

// Emit baseline security headers on every OAuth2 response (H-8).
// public/index.php is the HTML SSO portal entry point, so we pass a
// CSP that allows same-origin CSS, fonts, scripts and inline styles
// (needed by tpl/layout.tpl.php). The API entry point (api.php) keeps
// the default tight CSP ("default-src 'none'"). frame-ancestors stays
// 'none' here too: the OAuth2 portal must never be embeddable.
if (!(defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING)) {
    \SmartAuth\Api\RouteController::emitSecurityHeaders(
        "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // CORS preflight for the OAuth2 endpoints.
    // SMARTAUTH_OAUTH_CORS_ORIGINS is a CSV allow-list (or '*' for any).
    // Empty string (default) means "no CORS" - the preflight succeeds with
    // no Access-Control-Allow-* headers, which is the safe default
    //. Browsers will block the actual request.
    $configured = getDolGlobalString('SMARTAUTH_OAUTH_CORS_ORIGINS', '');
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $resolved = \SmartAuth\Api\RouteController::resolveCorsOrigin($configured, $requestOrigin);

    if ($resolved !== '') {
        header('Access-Control-Allow-Origin: ' . $resolved);
        if ($resolved !== '*') {
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 600');
    }
    http_response_code(204);
    exit;
}

// ============================================================================
// Route request
// ============================================================================

$handled = false;

// Discovery endpoints (/.well-known/*)
if (strpos($requestPath, '/.well-known/') === 0) {
    // If OAuth is disabled, return a minimal response indicating disabled status
    if (!OAuthConfig::isEnabled()) {
        sendJsonError('service_unavailable', 'OAuth2 server is not enabled', 503);
    }

    $discoveryController = new DiscoveryController();
    $handled = $discoveryController->route($requestPath);
}

// OAuth endpoints (/oauth/*)
if (!$handled && strpos($requestPath, '/oauth/') === 0) {
    $endpoint = substr($requestPath, 7); // Remove '/oauth/'

    switch ($endpoint) {
        case 'authorize':
            // Authorization endpoint (Mission 06)
            $authController = new AuthorizationController($db);
            $authController->handleAuthorize();
            $handled = true;
            break;

        case 'token':
            $tokenController = new TokenController($db);
            $tokenController->handleToken();
            $handled = true;
            break;

        case 'userinfo':
            $userinfoController = new UserinfoController($db);
            $userinfoController->handleUserinfo();
            $handled = true;
            break;

        case 'revoke':
            $revocationController = new RevocationController($db);
            $revocationController->handleRevoke();
            $handled = true;
            break;

        case 'revoked-jti':
            // Published revocation list (PERFS.md §3.4). Polled by resource
            // servers on a short cycle so a contract closure propagates
            // faster than the access_token TTL.
            $revokedJtiController = new RevokedJtiController($db);
            $revokedJtiController->handleList();
            $handled = true;
            break;

        case 'logout':
            $logoutController = new LogoutController($db);
            $logoutController->handleLogout();
            $handled = true;
            break;

        default:
            // Unknown OAuth endpoint
            $handled = false;
    }
}

// Public landing page on the root URL: shows the company branding +
// 3 action cards (Login / Register / Account). Skipped for non-GET so
// a stray POST / does not get an HTML response instead of an API error.
if (!$handled && ($requestPath === '/' || $requestPath === '') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $landingController = new LandingController();
    $landingController->handle();
    $handled = true;
}

// Password reset flow on the HTML portal. The /forgot-password page
// gathers an email and triggers PasswordResetController::requestReset
// (which sends the email containing /reset-password?token=...&email=...).
// SMARTAUTH_APP_URL must be set to the portal vhost (e.g.
// https://auth.example.com) for the link in the email to land back here.
if (!$handled && $requestPath === '/forgot-password') {
    $pwdController = new PasswordHtmlController();
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'GET') {
        $pwdController->handleForgotGet($_GET);
    } elseif ($requestMethod === 'POST') {
        $pwdController->handleForgotPost($_POST);
    } else {
        sendJsonError('method_not_allowed', 'Method not allowed', 405);
    }
    $handled = true;
}

if (!$handled && $requestPath === '/reset-password') {
    $pwdController = new PasswordHtmlController();
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'GET') {
        $pwdController->handleResetGet($_GET);
    } elseif ($requestMethod === 'POST') {
        $pwdController->handleResetPost($_POST);
    } else {
        sendJsonError('method_not_allowed', 'Method not allowed', 405);
    }
    $handled = true;
}

// Login page
if (!$handled && $requestPath === '/login') {
    $loginController = new LoginController($db);
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($requestMethod === 'GET') {
        $params = $_GET;
        $loginController->handleGet($params);
    } elseif ($requestMethod === 'POST') {
        $params = $_POST;
        $loginController->handlePost($params);
    } else {
        sendJsonError('method_not_allowed', 'Method not allowed', 405);
    }
    $handled = true;
}

// Self-registration kill switch. When SMARTAUTH_REGISTRATION_ENABLED is off,
// the whole self-provisioning surface answers 404 (the feature is hidden, not
// just the landing-page card). Sign-in for existing accounts is unaffected.
if (!$handled
    && \SmartAuth\Api\Account\RegistrationGate::isRegistrationPath($requestPath)
    && !\SmartAuth\Api\Account\RegistrationGate::isEnabled()) {
    dol_syslog('[SmartAuth] registration route ' . $requestPath . ' blocked, SMARTAUTH_REGISTRATION_ENABLED=0', LOG_NOTICE);
    sendJsonError('not_found', 'Self-registration is disabled', 404);
}

// Public registration (Lot 5)
if (!$handled && $requestPath === '/register') {
    $registerController = new RegisterController($db);
    $registerController->handle();
    $handled = true;
}

// Registration confirmation (Lot 6)
if (!$handled && $requestPath === '/register/confirm') {
    $registerController = new RegisterController($db);
    $registerController->handleConfirm();
    $handled = true;
}

// Registration resend (Lot 6)
if (!$handled && $requestPath === '/register/resend') {
    $registerController = new RegisterController($db);
    $registerController->handleResend();
    $handled = true;
}

// Account lookup by email (Lot 6)
if (!$handled && $requestPath === '/lookup-by-email') {
    $registerController = new RegisterController($db);
    $registerController->handleLookup();
    $handled = true;
}

// Self-service account page (Lot 7)
if (!$handled && $requestPath === '/account') {
    $accountController = new AccountController($db);
    $accountController->handle();
    $handled = true;
}

// Email-alternative confirmation (Lot 9 - SmartAuth side)
if (!$handled && $requestPath === '/email-alternative/confirm') {
    $emailAltController = new EmailAlternativeController($db);
    $emailAltController->handleConfirm();
    $handled = true;
}

// Logout page
if (!$handled && $requestPath === '/logout') {
    // Clear session and redirect
    dol_include_once('/smartauth/api/OAuth2/SessionManager.php');
    $sessionManager = new \SmartAuth\Api\OAuth2\SessionManager($db);
    $sessionManager->clearSession();

    // Validate the redirect target before sending Location, otherwise an
    // attacker could phish the user post-logout.
    // Accepted shapes:
    //   - same-origin relative path:  /, /login, /something/page
    //   - absolute URL whose host is whitelisted via
    //     SMARTAUTH_LOGOUT_REDIRECT_WHITELIST (CSV of hosts)
    // Everything else falls back to '/'.
    $requestedRedirect = $_GET['redirect'] ?? '/';
    $redirectUrl = '/';

    if (is_string($requestedRedirect) && $requestedRedirect !== '') {
        // Reject protocol-relative URLs (//evil.com) and backslash-prefixed
        // variants (/\evil.com) that some browsers normalise into them.
        $startsWithSlashOnly = isset($requestedRedirect[0])
            && $requestedRedirect[0] === '/'
            && (!isset($requestedRedirect[1]) || ($requestedRedirect[1] !== '/' && $requestedRedirect[1] !== '\\'));

        if ($startsWithSlashOnly) {
            $redirectUrl = $requestedRedirect;
        } else {
            $parsed = parse_url($requestedRedirect);
            if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host'])) {
                $whitelistRaw = getDolGlobalString('SMARTAUTH_LOGOUT_REDIRECT_WHITELIST', '');
                $whitelist = array_filter(array_map('trim', explode(',', $whitelistRaw)));
                $isHttp = in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
                if ($isHttp && in_array(strtolower($parsed['host']), array_map('strtolower', $whitelist), true)) {
                    $redirectUrl = $requestedRedirect;
                } else {
                    dol_syslog('[SmartAuth] logout: rejected redirect to non-whitelisted host: ' . $parsed['host'], LOG_WARNING);
                }
            } else {
                dol_syslog('[SmartAuth] logout: rejected malformed or relative-with-host redirect: ' . substr($requestedRedirect, 0, 200), LOG_WARNING);
            }
        }
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// Root path - show basic info
if (!$handled && $requestPath === '/') {
    $response = [
        'service' => 'SmartAuth OAuth2/OIDC Server',
        'status' => OAuthConfig::isEnabled() ? 'enabled' : 'disabled',
        'discovery' => OAuthConfig::getIssuer() . '/.well-known/openid-configuration'
    ];
    sendJsonResponse($response, 200);
    $handled = true;
}

// 404 for unhandled routes
if (!$handled) {
    sendJsonError('not_found', 'The requested endpoint does not exist', 404);
}

// ============================================================================
// Helper functions
// ============================================================================

/**
 * Send JSON response
 *
 * @param array $data Response data
 * @param int $statusCode HTTP status code
 * @return void
 */
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send JSON error response
 *
 * @param string $error Error code
 * @param string $description Error description
 * @param int $statusCode HTTP status code
 * @return void
 */
function sendJsonError(string $error, string $description, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => $error,
        'error_description' => $description
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
