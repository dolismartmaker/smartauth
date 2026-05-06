<?php

/**
 * AuthorizationController.php
 *
 * OAuth2 Authorization Endpoint for SmartAuth.
 * Implements RFC 6749 Section 4.1 (Authorization Code Grant).
 *
 * Flow:
 * 1. Validate client_id and redirect_uri
 * 2. Check user session (redirect to /login if missing)
 * 3. Validate response_type, scopes, PKCE
 * 4. Check existing consent
 * 5. Show consent page if needed
 * 6. Generate authorization code and redirect
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
dol_include_once('/smartauth/class/smartauthoauthconsent.class.php');
dol_include_once('/smartauth/class/smartauthoauthcode.class.php');

class AuthorizationController
{
    /**
     * Session key for storing authorization request during consent
     */
    const SESSION_AUTH_REQUEST = 'smartauth_auth_request';

    /**
     * CSRF token session key for consent form
     */
    const SESSION_CSRF_TOKEN = 'smartauth_consent_csrf';

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
     * Validated client object
     * @var \SmartAuthOAuthClient|null
     */
    private $client = null;

    /**
     * Authenticated user ID
     * @var int|null
     */
    private $userId = null;

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
     * Handle authorization request (GET and POST)
     *
     * GET: Initial authorization request or consent page display
     * POST: Consent form submission
     *
     * @return void
     */
    public function handleAuthorize(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handleConsentSubmission();
            return;
        }

        // GET request - process authorization
        $this->processAuthorizationRequest();
    }

    /**
     * Process the initial authorization request
     *
     * @return void
     */
    private function processAuthorizationRequest(): void
    {
        // Gather parameters (query string for GET)
        $params = $this->getRequestParams();

        // Extract required parameters
        $clientId = trim($params['client_id'] ?? '');
        $redirectUri = trim($params['redirect_uri'] ?? '');
        $responseType = trim($params['response_type'] ?? '');
        $scope = trim($params['scope'] ?? '');
        $state = $params['state'] ?? null;
        $codeChallenge = $params['code_challenge'] ?? null;
        $codeChallengeMethod = $params['code_challenge_method'] ?? null;
        $prompt = $params['prompt'] ?? null;
        $nonce = $params['nonce'] ?? null;

        // Step 1: Validate client_id (before any redirect is possible)
        $this->client = $this->validateClient($clientId);
        if ($this->client === null) {
            // Cannot redirect - show error page
            $this->showErrorPage('invalid_request', 'Client invalide ou inconnu.');
            return;
        }

        // Step 2: Validate redirect_uri
        if (!$this->validateRedirectUri($this->client, $redirectUri)) {
            // Cannot redirect - show error page
            $this->showErrorPage('invalid_request', 'URI de redirection non autorisee.');
            return;
        }

        // From here, we can redirect errors to the client
        // Step 3: Validate response_type
        if (!$this->validateResponseType($responseType)) {
            $this->redirectWithError($redirectUri, 'unsupported_response_type', 'Seul response_type=code est supporte.', $state);
            return;
        }

        // Step 4: Parse and validate scopes
        $scopes = ScopeManager::parseScopes($scope);
        if (empty($scopes)) {
            $this->redirectWithError($redirectUri, 'invalid_scope', 'Au moins un scope est requis.', $state);
            return;
        }

        $validatedScopes = $this->validateScopes($this->client, $scopes);
        if ($validatedScopes === null) {
            $this->redirectWithError($redirectUri, 'invalid_scope', 'Un ou plusieurs scopes ne sont pas autorises.', $state);
            return;
        }

        // Step 5: Validate PKCE if required
        if (!$this->validatePKCE($this->client, $codeChallenge, $codeChallengeMethod)) {
            $this->redirectWithError($redirectUri, 'invalid_request', 'PKCE est requis pour ce client.', $state);
            return;
        }

        // Validate PKCE format if provided.
        // S256 is mandatory; we no longer fall back to 'plain' (RFC 7636
        // permits plain but it offers no real protection - OAuth 2.1
        // requires S256). A missing method when a challenge is present is
        // also an explicit invalid_request (no silent default).
        if ($codeChallenge !== null) {
            $method = $codeChallengeMethod ?? '';
            if (!PKCEHelper::isValidMethod($method)) {
                dol_syslog('SmartAuth AuthorizationController: PKCE method must be S256, got: ' . ($method ?: '(missing)'), LOG_WARNING);
                $this->redirectWithError($redirectUri, 'invalid_request', 'Methode code_challenge non supportee (S256 requis).', $state);
                return;
            }
            if (!PKCEHelper::isValidChallenge($codeChallenge, $method)) {
                $this->redirectWithError($redirectUri, 'invalid_request', 'Format code_challenge invalide.', $state);
                return;
            }
        }

        // Step 6: Check user session
        $this->userId = $this->sessionManager->validateSession();
        if ($this->userId === null) {
            // Redirect to login with continue URL
            $this->redirectToLogin();
            return;
        }

        // Handle prompt=login (force re-authentication)
        if ($prompt === 'login') {
            $this->sessionManager->clearSession();
            $this->redirectToLogin();
            return;
        }

        // Step 6.5: Allow external modules (e.g. ssomanager) to block authorization
        $hookResult = HookHelper::runBlockingHook(
            'smartmaker_oauth_pre_authorize',
            [
                'user_id' => $this->userId,
                'client_id' => $this->client->client_id,
                'client_pk' => $this->client->id,
                'scopes' => $validatedScopes,
                'redirect_uri' => $redirectUri,
            ],
            $this->client
        );
        if ($hookResult['internal_error']) {
            $this->showErrorPage('server_error', 'Erreur interne lors de la verification d\'autorisation.');
            return;
        }
        if ($hookResult['blocked']) {
            $error = $hookResult['error'] ?: 'access_denied';
            $description = $hookResult['error_description'] ?: 'Acces refuse.';
            $this->redirectWithError($redirectUri, $error, $description, $state);
            return;
        }

        // Step 7: Check existing consent
        $hasConsent = $this->checkExistingConsent($this->userId, $this->client->id, $validatedScopes);

        // Handle prompt=none (no interaction allowed)
        if ($prompt === 'none') {
            if (!$hasConsent) {
                $this->redirectWithError($redirectUri, 'consent_required', 'Consentement requis mais prompt=none.', $state);
                return;
            }
            // Has consent, generate code
            $this->generateCodeAndRedirect($redirectUri, $validatedScopes, $state, $codeChallenge, $codeChallengeMethod, $nonce);
            return;
        }

        // Handle prompt=consent (force consent page)
        if ($prompt === 'consent') {
            $hasConsent = false;
        }

        // Step 8: Show consent page or generate code
        if (!$hasConsent) {
            $this->showConsentPage($redirectUri, $validatedScopes, $state, $codeChallenge, $codeChallengeMethod, $nonce);
            return;
        }

        // Has consent - generate code and redirect
        $this->generateCodeAndRedirect($redirectUri, $validatedScopes, $state, $codeChallenge, $codeChallengeMethod, $nonce);
    }

    /**
     * Handle consent form submission (POST)
     *
     * @return void
     */
    private function handleConsentSubmission(): void
    {
        // Start session to retrieve stored request
        $this->ensureSession();

        // Retrieve stored authorization request
        $storedRequest = $_SESSION[self::SESSION_AUTH_REQUEST] ?? null;
        if ($storedRequest === null) {
            $this->showErrorPage('invalid_request', 'Session expiree. Veuillez recommencer.');
            return;
        }

        // Validate CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        $storedCsrf = $_SESSION[self::SESSION_CSRF_TOKEN] ?? '';
        if (empty($csrfToken) || !hash_equals($storedCsrf, $csrfToken)) {
            $this->showErrorPage('invalid_request', 'Token CSRF invalide. Veuillez recommencer.');
            return;
        }

        // Clear stored data
        unset($_SESSION[self::SESSION_AUTH_REQUEST]);
        unset($_SESSION[self::SESSION_CSRF_TOKEN]);

        // Extract stored parameters
        $redirectUri = $storedRequest['redirect_uri'];
        $scopes = $storedRequest['scopes'];
        $state = $storedRequest['state'];
        $codeChallenge = $storedRequest['code_challenge'];
        $codeChallengeMethod = $storedRequest['code_challenge_method'];
        $nonce = $storedRequest['nonce'];
        $clientId = $storedRequest['client_id'];

        // Validate client still exists and is enabled
        $this->client = $this->validateClient($clientId);
        if ($this->client === null) {
            $this->showErrorPage('invalid_request', 'Client invalide.');
            return;
        }

        // Check user session is still valid
        $this->userId = $this->sessionManager->validateSession();
        if ($this->userId === null) {
            $this->redirectToLogin();
            return;
        }

        // Check user decision
        $decision = $_POST['decision'] ?? '';

        if ($decision === 'deny') {
            $this->redirectWithError($redirectUri, 'access_denied', 'L\'utilisateur a refuse l\'autorisation.', $state);
            return;
        }

        if ($decision !== 'allow') {
            $this->showErrorPage('invalid_request', 'Decision invalide.');
            return;
        }

        // User allowed - save consent if remember checkbox is checked
        $remember = !empty($_POST['remember']);
        if ($remember && OAuthConfig::rememberConsent()) {
            $this->saveConsent($this->userId, $this->client->id, $scopes);
        }

        // Generate code and redirect
        $this->generateCodeAndRedirect($redirectUri, $scopes, $state, $codeChallenge, $codeChallengeMethod, $nonce);
    }

    /**
     * Validate client exists and is enabled
     *
     * @param string $clientId Client ID
     * @return \SmartAuthOAuthClient|null Client object or null if invalid
     */
    private function validateClient(string $clientId): ?\SmartAuthOAuthClient
    {
        if (empty($clientId)) {
            return null;
        }

        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);

        if ($result <= 0) {
            dol_syslog('SmartAuth AuthorizationController: Client not found: ' . $clientId, LOG_WARNING);
            return null;
        }

        if (!$client->isEnabled()) {
            dol_syslog('SmartAuth AuthorizationController: Client disabled: ' . $clientId, LOG_WARNING);
            return null;
        }

        return $client;
    }

    /**
     * Validate redirect URI against client configuration
     *
     * @param \SmartAuthOAuthClient $client Client object
     * @param string $uri Redirect URI to validate
     * @return bool True if valid
     */
    private function validateRedirectUri(\SmartAuthOAuthClient $client, string $uri): bool
    {
        if (empty($uri)) {
            return false;
        }

        // Validate URI format
        $parsed = parse_url($uri);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        // Must be HTTPS in production (allow HTTP for localhost)
        $isLocalhost = in_array($parsed['host'], ['localhost', '127.0.0.1', '::1']);
        if (!$isLocalhost && $parsed['scheme'] !== 'https') {
            dol_syslog('SmartAuth AuthorizationController: Non-HTTPS redirect URI rejected: ' . $uri, LOG_WARNING);
            return false;
        }

        // Check against registered URIs (exact match required)
        return $client->isRedirectUriAllowed($uri);
    }

    /**
     * Validate response_type parameter
     *
     * @param string $type Response type
     * @return bool True if valid
     */
    private function validateResponseType(string $type): bool
    {
        // Only authorization_code flow supported
        return $type === 'code';
    }

    /**
     * Validate scopes against client configuration
     *
     * @param \SmartAuthOAuthClient $client Client object
     * @param array $scopes Requested scopes
     * @return array|null Validated scopes or null if invalid
     */
    private function validateScopes(\SmartAuthOAuthClient $client, array $scopes): ?array
    {
        // Filter to only valid scopes
        $validScopes = ScopeManager::filterValidScopes($scopes);
        if (empty($validScopes)) {
            return null;
        }

        // Check all scopes are allowed for this client
        $allowedScopes = $client->getAllowedScopesArray();
        if (!ScopeManager::areAllScopesAllowed($validScopes, $allowedScopes)) {
            $disallowed = ScopeManager::getDisallowedScopes($validScopes, $allowedScopes);
            dol_syslog('SmartAuth AuthorizationController: Disallowed scopes: ' . implode(', ', $disallowed), LOG_WARNING);
            return null;
        }

        return $validScopes;
    }

    /**
     * Validate PKCE requirements
     *
     * @param \SmartAuthOAuthClient $client Client object
     * @param string|null $challenge Code challenge
     * @param string|null $method Challenge method
     * @return bool True if PKCE requirements are met
     */
    private function validatePKCE(\SmartAuthOAuthClient $client, ?string $challenge, ?string $method): bool
    {
        // Check if PKCE is required for this client
        if ($client->requiresPkce()) {
            // PKCE is required - challenge must be provided
            if (empty($challenge)) {
                dol_syslog('SmartAuth AuthorizationController: PKCE required but not provided', LOG_WARNING);
                return false;
            }
        }

        return true;
    }

    /**
     * Redirect to login page
     *
     * @return void
     */
    private function redirectToLogin(): void
    {
        // Build the current authorization URL to return to
        $currentUrl = $this->getCurrentUrl();
        $loginUrl = '/login?continue=' . urlencode($currentUrl);

        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Check if user has existing valid consent for the requested scopes
     *
     * @param int $userId User ID
     * @param int $clientId Client ID
     * @param array $scopes Requested scopes
     * @return bool True if consent exists for all scopes
     */
    private function checkExistingConsent(int $userId, int $clientId, array $scopes): bool
    {
        if (!OAuthConfig::rememberConsent()) {
            return false;
        }

        $consent = new \SmartAuthOAuthConsent($this->db);
        $result = $consent->fetchByClientAndUser($clientId, $userId);

        if ($result <= 0) {
            return false;
        }

        // Check if all requested scopes are already consented
        return $consent->hasAllScopes($scopes);
    }

    /**
     * Save user consent
     *
     * @param int $userId User ID
     * @param int $clientId Client ID
     * @param array $scopes Consented scopes
     * @return void
     */
    private function saveConsent(int $userId, int $clientId, array $scopes): void
    {
        $user = new \User($this->db);
        $user->fetch($userId);

        $consent = new \SmartAuthOAuthConsent($this->db);
        $result = $consent->findOrCreate($clientId, $userId, $scopes, $user);

        if ($result < 0) {
            dol_syslog('SmartAuth AuthorizationController: Failed to save consent: ' . implode(', ', $consent->errors), LOG_ERR);
        }
    }

    /**
     * Display the consent page
     *
     * @param string $redirectUri Redirect URI
     * @param array $scopes Requested scopes
     * @param string|null $state State parameter
     * @param string|null $codeChallenge PKCE challenge
     * @param string|null $codeChallengeMethod PKCE method
     * @param string|null $nonce OIDC nonce
     * @return void
     */
    private function showConsentPage(
        string $redirectUri,
        array $scopes,
        ?string $state,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $nonce
    ): void {
        // Start session to store authorization request
        $this->ensureSession();

        // Generate CSRF token
        $csrfToken = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_CSRF_TOKEN] = $csrfToken;

        // Store authorization request in session
        $_SESSION[self::SESSION_AUTH_REQUEST] = [
            'client_id' => $this->client->client_id,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'nonce' => $nonce,
            'timestamp' => time(),
        ];

        // Load user info
        $user = new \User($this->db);
        $user->fetch($this->userId);

        // Prepare template variables
        $templateVars = [
            'csrfToken' => $csrfToken,
            'client' => $this->client,
            'clientName' => $this->client->name,
            'clientLogo' => $this->client->logo_url,
            'scopes' => $scopes,
            'scopeInfo' => ScopeManager::getScopeInfoForConsent($scopes),
            'userName' => $user->getFullName($GLOBALS['langs']),
            'userLogin' => $user->login,
            'rememberConsent' => OAuthConfig::rememberConsent(),
            'issuer' => OAuthConfig::getIssuer(),
        ];

        $this->renderTemplate('consent', $templateVars);
    }

    /**
     * Generate authorization code and redirect to client
     *
     * @param string $redirectUri Redirect URI
     * @param array $scopes Granted scopes
     * @param string|null $state State parameter
     * @param string|null $codeChallenge PKCE challenge
     * @param string|null $codeChallengeMethod PKCE method
     * @param string|null $nonce OIDC nonce
     * @return void
     */
    private function generateCodeAndRedirect(
        string $redirectUri,
        array $scopes,
        ?string $state,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $nonce
    ): void {
        // Generate authorization code
        $plainCode = \SmartAuthOAuthCode::generateCode();
        $codeHash = \SmartAuthOAuthCode::hashCode($plainCode);

        // Get user for creation
        $user = new \User($this->db);
        $user->fetch($this->userId);

        // Create code record
        $oauthCode = new \SmartAuthOAuthCode($this->db);
        $oauthCode->code_hash = $codeHash;
        $oauthCode->fk_client = $this->client->id;
        $oauthCode->fk_user = $this->userId;
        $oauthCode->redirect_uri = $redirectUri;
        $oauthCode->setScopesArray($scopes);
        $oauthCode->state = $state;
        $oauthCode->nonce = $nonce;
        $oauthCode->code_challenge = $codeChallenge;
        $oauthCode->code_challenge_method = $codeChallengeMethod;
        $oauthCode->expires_at = dol_now() + OAuthConfig::getCodeTTL();

        $result = $oauthCode->create($user);
        if ($result < 0) {
            dol_syslog('SmartAuth AuthorizationController: Failed to create code: ' . implode(', ', $oauthCode->errors), LOG_ERR);
            $this->redirectWithError($redirectUri, 'server_error', 'Erreur lors de la generation du code.', $state);
            return;
        }

        dol_syslog('SmartAuth AuthorizationController: Code generated for user ' . $this->userId . ' client ' . $this->client->client_id, LOG_INFO);

        // Build redirect URL
        $params = ['code' => $plainCode];
        if ($state !== null) {
            $params['state'] = $state;
        }

        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        $redirectUrl = $redirectUri . $separator . http_build_query($params);

        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Redirect with OAuth error
     *
     * @param string $redirectUri Redirect URI
     * @param string $error Error code
     * @param string $description Error description
     * @param string|null $state State parameter
     * @return void
     */
    private function redirectWithError(string $redirectUri, string $error, string $description, ?string $state): void
    {
        $params = [
            'error' => $error,
            'error_description' => $description,
        ];

        if ($state !== null) {
            $params['state'] = $state;
        }

        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        $redirectUrl = $redirectUri . $separator . http_build_query($params);

        dol_syslog('SmartAuth AuthorizationController: Redirecting with error ' . $error . ': ' . $description, LOG_INFO);

        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Show error page (when redirect is not possible)
     *
     * @param string $error Error code
     * @param string $description Error description
     * @return void
     */
    private function showErrorPage(string $error, string $description): void
    {
        $templateVars = [
            'error' => $error,
            'errorDescription' => $description,
            'issuer' => OAuthConfig::getIssuer(),
        ];

        $this->renderTemplate('error', $templateVars);
    }

    /**
     * Get request parameters (GET or POST depending on method)
     *
     * @return array Parameters
     */
    private function getRequestParams(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            return array_merge($_GET, $_POST);
        }

        return $_GET;
    }

    /**
     * Get current full URL
     *
     * @return string Current URL
     */
    private function getCurrentUrl(): string
    {
        $protocol = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return $protocol . '://' . $host . $uri;
    }

    /**
     * Ensure PHP session is started
     *
     * @return void
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
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
            dol_syslog('SmartAuth AuthorizationController: Template not found: ' . $templatePath, LOG_ERR);
            http_response_code(500);
            echo 'Template not found';
            exit;
        }

        // Include template
        include $templatePath;
        exit;
    }
}
