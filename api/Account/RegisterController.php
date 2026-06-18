<?php

/**
 * RegisterController.php
 *
 * HTTP entry point for the public /register flow.
 *
 * Routes:
 *   GET  /register       -> render the registration form (with client branding)
 *   POST /register       -> validate inputs, call RegistrationService, render
 *                           a generic "verify your inbox" page regardless of
 *                           the email being new or already known
 *                           (enumeration mitigation).
 *
 * CSRF: a token is created in $_SESSION on GET and verified on POST via
 * hash_equals (same pattern as AuthorizationController).
 *
 * Rate limiting:
 *   - SmartAuth\Api\RateLimiter on action 'register' / IP, max
 *     SMARTAUTH_REGISTER_RATE_LIMIT per hour.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Api\RateLimiter;

dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/api/RateLimiter.php');

class RegisterController
{
    private const SESSION_CSRF = 'smartauth_register_csrf';
    private const SESSION_CSRF_RESEND = 'smartauth_resend_csrf';
    private const SESSION_CSRF_LOOKUP = 'smartauth_lookup_csrf';
    private const RATE_ACTION = 'register';
    private const RATE_ACTION_RESEND = 'register_resend';
    private const RATE_ACTION_LOOKUP = 'lookup_by_email';
    private const RATE_WINDOW_SECONDS = 3600; // 1 hour

    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var RegistrationService
     */
    private $service;

    public function __construct($db, ?RegistrationService $service = null)
    {
        $this->db = $db;
        $this->service = $service ?? new RegistrationService($db);
    }

    /**
     * Dispatch a /register request.
     *
     * @return void
     */
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') {
            $this->handlePost();
            return;
        }
        $this->handleGet();
    }

    /**
     * Handle GET /register/confirm?token=...
     *
     * @return void
     */
    public function handleConfirm(): void
    {
        $this->ensureSession();

        $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
        if ($token === '') {
            $this->renderInvalidConfirmation();
            return;
        }

        $result = $this->service->confirmRegistration($token);
        if (!empty($result['error'])) {
            dol_syslog('[SmartAuth] RegisterController: confirmRegistration failed code=' . $result['error'], LOG_INFO);
            $this->renderInvalidConfirmation();
            return;
        }

        $continueUrl = isset($result['continue']) && is_string($result['continue']) && $result['continue'] !== ''
            ? $result['continue']
            : null;
        $loginUrl = '/login';
        if ($continueUrl !== null) {
            $loginUrl = '/login?continue=' . urlencode($continueUrl);
        }

        $this->renderTemplate('register_confirmed', [
            'loginUrl' => $loginUrl,
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Handle POST /register/resend.
     *
     * @return void
     */
    public function handleResend(): void
    {
        $this->ensureSession();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->renderInvalidConfirmation();
            return;
        }

        $ip = $this->getClientIp();
        $rateLimit = new RateLimiter($this->db);
        $rateLimitResult = $rateLimit->checkLimit(
            $ip,
            self::RATE_ACTION_RESEND,
            max(1, OAuthConfig::getRegisterRateLimit() * 5),
            self::RATE_WINDOW_SECONDS
        );
        if (empty($rateLimitResult['allowed'])) {
            $this->renderTooManyRequests((int) ($rateLimitResult['retry_after'] ?? 60));
            return;
        }
        $rateLimit->recordAttempt($ip, self::RATE_ACTION_RESEND, false);

        $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

        if (!$this->validCsrfFor(self::SESSION_CSRF_RESEND, $csrf)) {
            // Render the same generic page rather than a CSRF error page,
            // so the failure mode looks identical to "I don't know that email".
            $this->jitter();
            $this->renderTemplate('register_sent', ['issuer' => OAuthConfig::getIssuer()]);
            return;
        }
        unset($_SESSION[self::SESSION_CSRF_RESEND]);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->service->resendConfirmation($email, $ip);
        }

        $this->jitter();
        $this->renderTemplate('register_sent', ['issuer' => OAuthConfig::getIssuer()]);
    }

    /**
     * Handle POST /lookup-by-email.
     *
     * @return void
     */
    public function handleLookup(): void
    {
        $this->ensureSession();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            $this->renderTemplate('error', [
                'error' => 'method_not_allowed',
                'errorDescription' => 'Methode non autorisee.',
                'issuer' => OAuthConfig::getIssuer(),
            ]);
            return;
        }

        $ip = $this->getClientIp();
        $rateLimit = new RateLimiter($this->db);
        $rateLimitResult = $rateLimit->checkLimit(
            $ip,
            self::RATE_ACTION_LOOKUP,
            max(1, OAuthConfig::getRegisterRateLimit() * 5),
            self::RATE_WINDOW_SECONDS
        );
        if (empty($rateLimitResult['allowed'])) {
            $this->renderTooManyRequests((int) ($rateLimitResult['retry_after'] ?? 60));
            return;
        }
        $rateLimit->recordAttempt($ip, self::RATE_ACTION_LOOKUP, false);

        $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

        if (!$this->validCsrfFor(self::SESSION_CSRF_LOOKUP, $csrf)) {
            $this->jitter();
            $this->renderTemplate('lookup_sent', ['issuer' => OAuthConfig::getIssuer()]);
            return;
        }
        unset($_SESSION[self::SESSION_CSRF_LOOKUP]);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->service->lookupByEmail($email, $ip);
        }

        $this->jitter();
        $this->renderTemplate('lookup_sent', ['issuer' => OAuthConfig::getIssuer()]);
    }

    /**
     * Render the "invalid / expired token" page with a CSRF token bound to
     * the resend form (SESSION_CSRF_RESEND).
     */
    private function renderInvalidConfirmation(): void
    {
        $token = $this->ensureCsrfTokenIn(self::SESSION_CSRF_RESEND);
        $this->renderTemplate('register_invalid', [
            'csrfToken' => $token,
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Validate a CSRF token against a specific session key.
     *
     * @param string $sessionKey
     * @param string $token
     * @return bool
     */
    private function validCsrfFor(string $sessionKey, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stored = $_SESSION[$sessionKey] ?? '';
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Make sure a given session key holds a CSRF token.
     *
     * @param string $sessionKey
     * @return string
     */
    private function ensureCsrfTokenIn(string $sessionKey): string
    {
        if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[$sessionKey];
    }

    /**
     * GET /register: render the form with optional client branding.
     */
    private function handleGet(): void
    {
        $this->ensureSession();

        $clientId = isset($_GET['client_id']) ? trim((string) $_GET['client_id']) : '';
        $continueParam = isset($_GET['continue']) ? trim((string) $_GET['continue']) : '';

        $client = $this->loadBrandingClient($clientId);
        $continueUrl = $this->validateContinueUrl($continueParam, $client);

        $csrfToken = $this->ensureCsrfToken();

        $this->renderForm([
            'csrfToken' => $csrfToken,
            'clientName' => $client !== null ? (string) $client->name : '',
            'clientLogo' => $client !== null ? (string) $client->logo_url : '',
            'clientId' => $client !== null ? (string) $client->client_id : '',
            'continueUrl' => $continueUrl ?? '',
            'errors' => [],
            'values' => [],
        ]);
    }

    /**
     * POST /register: validate, dispatch to service, render generic confirmation.
     */
    private function handlePost(): void
    {
        $this->ensureSession();

        $ip = $this->getClientIp();
        $rateLimit = new RateLimiter($this->db);
        $rateLimitResult = $rateLimit->checkLimit(
            $ip,
            self::RATE_ACTION,
            OAuthConfig::getRegisterRateLimit(),
            self::RATE_WINDOW_SECONDS
        );
        if (empty($rateLimitResult['allowed'])) {
            $this->renderTooManyRequests((int) ($rateLimitResult['retry_after'] ?? 60));
            return;
        }
        $rateLimit->recordAttempt($ip, self::RATE_ACTION, false);

        $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';
        $firstname = isset($_POST['firstname']) ? trim((string) $_POST['firstname']) : '';
        $lastname = isset($_POST['lastname']) ? trim((string) $_POST['lastname']) : '';
        $acceptCgu = !empty($_POST['accept_cgu']);
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        $clientIdInput = isset($_POST['client_id']) ? trim((string) $_POST['client_id']) : '';
        $continueInput = isset($_POST['continue']) ? trim((string) $_POST['continue']) : '';

        $client = $this->loadBrandingClient($clientIdInput);
        $continueUrl = $this->validateContinueUrl($continueInput, $client);

        $errors = [];
        if (!$this->validCsrf($csrf)) {
            $errors['_global'] = 'Session expiree. Veuillez recharger la page et reessayer.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (!RegistrationService::isPasswordStrongEnough($password)) {
            $errors['password'] = 'Mot de passe trop faible (12 caracteres minimum, majuscules, minuscules et chiffres).';
        }
        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Les deux mots de passe ne correspondent pas.';
        }
        if (!$acceptCgu) {
            $errors['accept_cgu'] = 'Vous devez accepter les conditions generales pour creer un compte.';
        }

        if (!empty($errors)) {
            $this->jitter();
            $csrfToken = $this->ensureCsrfToken();
            $this->renderForm([
                'csrfToken' => $csrfToken,
                'clientName' => $client !== null ? (string) $client->name : '',
                'clientLogo' => $client !== null ? (string) $client->logo_url : '',
                'clientId' => $client !== null ? (string) $client->client_id : '',
                'continueUrl' => $continueUrl ?? '',
                'errors' => $errors,
                'values' => [
                    'email' => $email,
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'accept_cgu' => $acceptCgu,
                ],
            ]);
            return;
        }

        // Single CSRF use: rotate the token now to invalidate the previous one.
        unset($_SESSION[self::SESSION_CSRF]);

        $clientPk = $client !== null ? (int) $client->id : null;

        $result = $this->service->startRegistration(
            $email,
            $password,
            $firstname !== '' ? $firstname : null,
            $lastname !== '' ? $lastname : null,
            $clientPk,
            $ip,
            $continueUrl
        );

        // Generic response regardless of success / collision / failure
        // (enumeration mitigation). Internal errors are still logged in the
        // service, and the form keeps a clean redirection.
        $this->jitter();
        $this->renderSent();
    }

    /**
     * Validate the CSRF token sent with the form.
     *
     * @param string $token
     * @return bool
     */
    private function validCsrf(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stored = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Make sure $_SESSION holds a CSRF token; create one if missing.
     *
     * @return string
     */
    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_CSRF]) || !is_string($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_CSRF];
    }

    /**
     * Load and return the OAuth2 client for branding purposes, or null.
     *
     * @param string $clientId
     * @return \SmartAuthOAuthClient|null
     */
    private function loadBrandingClient(string $clientId): ?\SmartAuthOAuthClient
    {
        if ($clientId === '') {
            return null;
        }
        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);
        if ($result <= 0) {
            dol_syslog('[SmartAuth] RegisterController: unknown client_id provided for branding: ' . $clientId, LOG_INFO);
            return null;
        }
        if (!$client->isEnabled()) {
            return null;
        }
        return $client;
    }

    /**
     * Validate a `continue` URL against the (optional) client OAuth2 redirect
     * URIs. If the URL doesn't match, returns null and logs.
     *
     * @param string $url
     * @param \SmartAuthOAuthClient|null $client
     * @return string|null
     */
    private function validateContinueUrl(string $url, ?\SmartAuthOAuthClient $client): ?string
    {
        if ($url === '') {
            return null;
        }
        if ($client === null) {
            // We can only honor continue when bound to a known client.
            dol_syslog('[SmartAuth] RegisterController: continue URL ignored, no client context', LOG_INFO);
            return null;
        }
        if (!$client->isRedirectUriAllowed($url)) {
            dol_syslog('[SmartAuth] RegisterController: continue URL not whitelisted for client ' . $client->client_id, LOG_WARNING);
            return null;
        }
        return $url;
    }

    /**
     * Resolve the source IP.
     *
     * Delegates to RouteController::get_client_ip(), which honours the
     * SMARTAUTH_TRUSTED_PROXIES allow-list. The previous local helper
     * relied on a single SMARTAUTH_TRUSTED_PROXY_HEADER constant without
     * verifying that the request came from a trusted proxy (H-1 of
     * TODO-SECURITY-01).
     *
     * @return string
     */
    private function getClientIp(): string
    {
        return \SmartAuth\Api\RouteController::get_client_ip();
    }

    /**
     * Add a small random delay to the response so attackers cannot reliably
     * differentiate the "email taken" path from the "new email" path on
     * timing alone.
     */
    private function jitter(): void
    {
        usleep(random_int(100000, 300000));
    }

    /**
     * Start the PHP session if not already started, with safe cookie params.
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => OAuthConfig::getCookieDomain(),
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Render the registration form.
     *
     * @param array $vars
     */
    private function renderForm(array $vars): void
    {
        $vars['issuer'] = OAuthConfig::getIssuer();
        $this->renderTemplate('register', $vars);
    }

    /**
     * Render the generic "verify your inbox" page.
     */
    private function renderSent(): void
    {
        $this->renderTemplate('register_sent', [
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Render a 429 page when rate limit is hit.
     *
     * @param int $retryAfter
     */
    private function renderTooManyRequests(int $retryAfter): void
    {
        http_response_code(429);
        header('Retry-After: ' . max(1, $retryAfter));
        $this->renderTemplate('error', [
            'error' => 'rate_limited',
            'errorDescription' => 'Trop de tentatives d\'inscription depuis cette adresse. Reessayez plus tard.',
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Render a template from tpl/.
     *
     * @param string $templateName
     * @param array  $vars
     */
    private function renderTemplate(string $templateName, array $vars): void
    {
        extract($vars);
        header('Content-Type: text/html; charset=utf-8');
        $path = dirname(__DIR__, 2) . '/tpl/' . $templateName . '.tpl.php';
        if (!file_exists($path)) {
            dol_syslog('[SmartAuth] RegisterController: template not found: ' . $path, LOG_ERR);
            http_response_code(500);
            echo 'Template not found';
            exit;
        }
        include $path;
        exit;
    }
}
