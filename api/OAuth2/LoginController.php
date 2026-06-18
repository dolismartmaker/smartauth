<?php

/**
 * LoginController.php
 *
 * Handles the SmartAuth login page for OAuth2/OIDC authentication.
 * This is the entry point for user authentication before OAuth authorization.
 *
 * Endpoints:
 * - GET /login  - Display login form
 * - POST /login - Process login submission
 *
 * Security features:
 * - CSRF protection via session token
 * - Rate limiting via RateLimiter
 * - Generic error messages to prevent user enumeration
 * - Secure password verification via Dolibarr User class
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

use SmartAuth\Api\RateLimiter;

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
dol_include_once('/smartauth/api/OAuth2/SubjectAuthenticator.php');

class LoginController
{
    /**
     * Rate limit action identifier
     */
    const RATE_LIMIT_ACTION = 'oauth_login';

    /**
     * Maximum login attempts per IP
     */
    const RATE_LIMIT_IP_MAX = 10;

    /**
     * Rate limit window for IP (seconds)
     */
    const RATE_LIMIT_IP_WINDOW = 300;

    /**
     * Maximum login attempts per username
     */
    const RATE_LIMIT_USER_MAX = 5;

    /**
     * Rate limit window for username (seconds)
     */
    const RATE_LIMIT_USER_WINDOW = 900;

    /**
     * CSRF token session key
     */
    const CSRF_SESSION_KEY = 'smartauth_csrf_token';

    /**
     * Database connection
     * @var object
     */
    private $db;

    /**
     * Session manager
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * Rate limiter
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * Subject authenticator (societe_account + user)
     * @var SubjectAuthenticator
     */
    private $subjectAuthenticator;

    /**
     * Error messages for display (generic to prevent enumeration)
     * @var array
     */
    private $errorMessages = [
        'invalid_credentials' => 'Identifiant ou mot de passe incorrect.',
        'rate_limited' => 'Trop de tentatives. Veuillez patienter avant de réessayer.',
        'csrf_invalid' => 'Session expirée. Veuillez réessayer.',
        'missing_fields' => 'Veuillez remplir tous les champs.',
        'account_disabled' => 'Ce compte est désactivé.',
    ];

    /**
     * Constructor
     *
     * @param object $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->sessionManager = new SessionManager($db);
        $this->rateLimiter = new RateLimiter($db);
        $this->subjectAuthenticator = new SubjectAuthenticator($db);
    }

    /**
     * Handle GET /login request
     *
     * Displays the login form. If user is already logged in, redirects to continue URL.
     *
     * @param array $params Query parameters
     * @return void
     */
    public function handleGet(array $params = []): void
    {
        // Check if already logged in
        $subject = $this->sessionManager->validateSession();
        if ($subject !== null) {
            $continueUrl = $this->sanitizeContinueUrl($params['continue'] ?? '');
            if (!empty($continueUrl)) {
                $this->redirect($continueUrl);
                return;
            }
            // No continue URL, show logged in state or redirect to root
            $this->redirect('/');
            return;
        }

        // Start PHP session for CSRF token
        $this->ensureSession();

        // Generate CSRF token
        $csrfToken = $this->generateCsrfToken();

        // Prepare template variables
        $templateVars = [
            'csrfToken' => $csrfToken,
            'continue' => htmlspecialchars($params['continue'] ?? '', ENT_QUOTES, 'UTF-8'),
            'error' => $params['error'] ?? null,
            'errorMessages' => $this->errorMessages,
            'issuer' => OAuthConfig::getIssuer(),
        ];

        // Render login template
        $this->renderTemplate('login', $templateVars);
    }

    /**
     * Handle POST /login request
     *
     * Processes login form submission:
     * 1. Validate CSRF token
     * 2. Check rate limiting
     * 3. Validate credentials
     * 4. Create session
     * 5. Redirect to continue URL
     *
     * @param array $params POST parameters
     * @return void
     */
    public function handlePost(array $params = []): void
    {
        // Start PHP session for CSRF validation
        $this->ensureSession();

        $username = trim($params['username'] ?? '');
        $password = $params['password'] ?? '';
        $continueUrl = $this->sanitizeContinueUrl($params['continue'] ?? '');
        $csrfToken = $params['csrf_token'] ?? '';

        // Build redirect URL for errors
        $errorRedirectBase = '/login';
        if (!empty($continueUrl)) {
            $errorRedirectBase .= '?continue=' . urlencode($continueUrl);
        }

        // Validate CSRF token
        if (!$this->validateCsrfToken($csrfToken)) {
            dol_syslog('[SmartAuth] LoginController: CSRF validation failed', LOG_WARNING);
            $this->redirectWithError($errorRedirectBase, 'csrf_invalid');
            return;
        }

        // Validate required fields
        if (empty($username) || empty($password)) {
            $this->redirectWithError($errorRedirectBase, 'missing_fields');
            return;
        }

        // Get client IP for rate limiting
        $clientIp = $this->getClientIp();

        // Check rate limit by IP
        $ipCheck = $this->rateLimiter->checkLimit(
            $clientIp,
            self::RATE_LIMIT_ACTION,
            self::RATE_LIMIT_IP_MAX,
            self::RATE_LIMIT_IP_WINDOW
        );

        if (!$ipCheck['allowed']) {
            dol_syslog('[SmartAuth] LoginController: Rate limited IP ' . $clientIp, LOG_WARNING);
            $this->redirectWithError($errorRedirectBase, 'rate_limited');
            return;
        }

        // Check rate limit by username
        $userCheck = $this->rateLimiter->checkLimit(
            'user:' . strtolower($username),
            self::RATE_LIMIT_ACTION,
            self::RATE_LIMIT_USER_MAX,
            self::RATE_LIMIT_USER_WINDOW
        );

        if (!$userCheck['allowed']) {
            dol_syslog('[SmartAuth] LoginController: Rate limited user ' . $username, LOG_WARNING);
            $this->redirectWithError($errorRedirectBase, 'rate_limited');
            return;
        }

        // Attempt authentication (societe_account and/or user, per config)
        $subject = $this->subjectAuthenticator->authenticate($username, $password);
        $success = ($subject !== null);

        // Record attempt for rate limiting
        $this->rateLimiter->recordAttempt($clientIp, self::RATE_LIMIT_ACTION, $success);
        $this->rateLimiter->recordAttempt('user:' . strtolower($username), self::RATE_LIMIT_ACTION, $success);

        if (!$success) {
            dol_syslog('[SmartAuth] LoginController: Authentication failed for ' . $username, LOG_INFO);
            $this->redirectWithError($errorRedirectBase, 'invalid_credentials');
            return;
        }

        // Per-endpoint admission (DECISION_2026-06-02, "deux silos"): the
        // OAuth2/SSO door admits only EXTERNAL subjects (acc: / mbr:). An
        // internal Dolibarr user (usr:) belongs to the PWA/mobile JWT silo and
        // must not obtain an SSO session here. Closed by default; flip
        // SMARTAUTH_SSO_ALLOW_INTERNAL_USER to widen without a code change once
        // the admission policy (per-endpoint vs per-client) is settled.
        // Reported as a generic invalid_credentials to avoid leaking that the
        // credentials were valid but the subject type was wrong.
        if ($subject->isUser() && !getDolGlobalInt('SMARTAUTH_SSO_ALLOW_INTERNAL_USER', 0)) {
            dol_syslog('[SmartAuth] LoginController: internal user subject refused at SSO door for ' . $username, LOG_WARNING);
            $this->redirectWithError($errorRedirectBase, 'invalid_credentials');
            return;
        }

        // Authentication successful - reset rate limit for this user
        $this->rateLimiter->reset($clientIp, self::RATE_LIMIT_ACTION);
        $this->rateLimiter->reset('user:' . strtolower($username), self::RATE_LIMIT_ACTION);

        // Create session
        $this->sessionManager->createSession($subject);

        dol_syslog('[SmartAuth] LoginController: Login successful for subject ' . $subject->toSub(), LOG_INFO);

        // Redirect to continue URL or root
        if (!empty($continueUrl)) {
            $this->redirect($continueUrl);
        } else {
            $this->redirect('/');
        }
    }

    /**
     * Ensure PHP session is started
     *
     * @return void
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session for security
            session_set_cookie_params([
                'lifetime' => 0, // Session cookie
                'path' => '/',
                'domain' => OAuthConfig::getCookieDomain(),
                'secure' => $this->isSecureContext(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Generate and store CSRF token
     *
     * @return string CSRF token
     */
    private function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::CSRF_SESSION_KEY] = $token;
        return $token;
    }

    /**
     * Validate CSRF token
     *
     * @param string $token Token from form
     * @return bool True if valid
     */
    private function validateCsrfToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $storedToken = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        if (empty($storedToken)) {
            return false;
        }

        // Use hash_equals for constant-time comparison
        $valid = hash_equals($storedToken, $token);

        // Regenerate token after validation (one-time use)
        unset($_SESSION[self::CSRF_SESSION_KEY]);

        return $valid;
    }

    /**
     * Sanitize and validate continue URL
     *
     * Only allows URLs on the same host or configured allowed hosts.
     *
     * @param string $url URL to validate
     * @return string Sanitized URL or empty string if invalid
     */
    private function sanitizeContinueUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        // Decode URL if encoded
        $url = urldecode($url);

        // Parse URL
        $parsed = parse_url($url);

        // Allow relative URLs (starting with /). Reject the protocol-relative
        // forms "//evil.com" and "/\\evil.com" (Chrome / Firefox normalise
        // the latter to the former) which would otherwise produce an open
        // redirect.
        if (
            isset($url[0]) && $url[0] === '/'
            && (!isset($url[1]) || ($url[1] !== '/' && $url[1] !== '\\'))
        ) {
            return $url;
        }

        // For absolute URLs, validate host
        if (!isset($parsed['host'])) {
            return '';
        }

        // Get allowed host from issuer
        $issuer = OAuthConfig::getIssuer();
        $issuerParsed = parse_url($issuer);
        $allowedHost = $issuerParsed['host'] ?? '';

        // Check if host matches
        if ($parsed['host'] !== $allowedHost) {
            dol_syslog('[SmartAuth] LoginController: Rejected continue URL with foreign host: ' . $parsed['host'], LOG_WARNING);
            return '';
        }

        return $url;
    }

    /**
     * Resolve the client IP address.
     *
     * Delegates to RouteController::get_client_ip(), which honours the
     * SMARTAUTH_TRUSTED_PROXIES allow-list. The previous local helper
     * trusted forwarding headers unconditionally, defeating the per-IP
     * rate limiter.
     *
     * @return string IP address (validated format), or '0.0.0.0'
     */
    private function getClientIp(): string
    {
        return \SmartAuth\Api\RouteController::get_client_ip();
    }

    /**
     * Determine if we're in a secure context (HTTPS)
     *
     * @return bool True if HTTPS
     */
    private function isSecureContext(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }
        return false;
    }

    /**
     * Redirect to URL
     *
     * @param string $url URL to redirect to
     * @return void
     */
    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Redirect with error parameter
     *
     * @param string $baseUrl Base URL
     * @param string $error Error code
     * @return void
     */
    private function redirectWithError(string $baseUrl, string $error): void
    {
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        $this->redirect($baseUrl . $separator . 'error=' . urlencode($error));
    }

    /**
     * Render template with variables
     *
     * @param string $templateName Template name (without .tpl.php)
     * @param array $vars Variables to pass to template
     * @return void
     */
    private function renderTemplate(string $templateName, array $vars): void
    {
        // Extract variables for template
        extract($vars);

        // Set content type
        header('Content-Type: text/html; charset=utf-8');

        // Calculate template path
        $templatePath = dirname(__DIR__, 2) . '/tpl/' . $templateName . '.tpl.php';

        if (!file_exists($templatePath)) {
            dol_syslog('[SmartAuth] LoginController: Template not found: ' . $templatePath, LOG_ERR);
            http_response_code(500);
            echo 'Template not found';
            exit;
        }

        // Include template
        include $templatePath;
        exit;
    }
}
