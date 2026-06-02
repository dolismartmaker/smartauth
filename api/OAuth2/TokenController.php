<?php

/**
 * TokenController.php
 *
 * OAuth2 Token Endpoint for SmartAuth.
 * Implements RFC 6749 Section 4.1.3 (Token Request) and Section 6 (Refresh Token).
 *
 * Supported grant types:
 * - authorization_code: Exchange code for tokens (with PKCE validation)
 * - refresh_token: Refresh access token (with token rotation)
 *
 * Client authentication methods:
 * - client_secret_basic: HTTP Basic auth with client_id:client_secret
 * - client_secret_post: client_id and client_secret in request body
 * - none: Public clients (PKCE required)
 *
 * Request: Content-Type: application/x-www-form-urlencoded
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

dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/class/smartauthoauthcode.class.php');
dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');
dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
dol_include_once('/smartauth/api/OAuth2/ResponseTrait.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

class TokenController
{
    use ResponseTrait;
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
     * Authenticated client
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
     * Handle token request
     *
     * @return void
     */
    public function handleToken(): void
    {
        // Must be POST
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->sendError('invalid_request', 'Method must be POST', 405);
            return;
        }

        // Must be form-urlencoded
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/x-www-form-urlencoded') === false) {
            $this->sendError('invalid_request', 'Content-Type must be application/x-www-form-urlencoded', 400);
            return;
        }

        // Parse request body
        $params = $this->parseRequestBody();

        // Get grant type
        $grantType = trim($params['grant_type'] ?? '');
        if (empty($grantType)) {
            $this->sendError('invalid_request', 'Missing required parameter: grant_type', 400);
            return;
        }

        // Per-client_id rate limiting on /oauth/token.
        // Each client_id gets a bucket so a single misbehaving / compromised
        // client cannot brute-force its way through; failed attempts are
        // recorded BEFORE authentication so an unauthenticated attacker
        // probing client_secret gets rate-limited too.
        $candidateClientId = trim((string) ($params['client_id'] ?? $_SERVER['PHP_AUTH_USER'] ?? ''));
        $rateKey = $candidateClientId !== '' ? $candidateClientId : (\SmartAuth\Api\RouteController::get_client_ip());
        if (class_exists('\\SmartAuth\\Api\\RateLimiter')) {
            $rateLimiter = new \SmartAuth\Api\RateLimiter($this->db);
            $rl = $rateLimiter->checkLimit(
                $rateKey,
                'oauth_token',
                getDolGlobalInt('SMARTAUTH_OAUTH_TOKEN_RATELIMIT_MAX', 30),
                getDolGlobalInt('SMARTAUTH_OAUTH_TOKEN_RATELIMIT_WINDOW', 300)
            );
            if (empty($rl['allowed'])) {
                dol_syslog('SmartAuth TokenController: /oauth/token rate-limit hit for ' . \SmartAuth\Api\RateLimiter::maskIdentifier($rateKey), LOG_WARNING);
                $this->sendError('temporarily_unavailable', 'Too many requests', 429);
                return;
            }
            $rateLimiter->recordAttempt($rateKey, 'oauth_token');
        }

        // Authenticate client
        $this->client = $this->authenticateClient($params);
        if ($this->client === null) {
            $this->sendError('invalid_client', 'Client authentication failed', 401);
            return;
        }

        // Check client is enabled
        if (!$this->client->isEnabled()) {
            $this->sendError('invalid_client', 'Client is disabled', 401);
            return;
        }

        // Check grant type is allowed for this client
        if (!$this->client->isGrantAllowed($grantType)) {
            $this->sendError('unauthorized_client', 'Grant type not allowed for this client', 400);
            return;
        }

        // Route to grant handler
        switch ($grantType) {
            case 'authorization_code':
                $this->handleAuthorizationCode($params);
                break;

            case 'refresh_token':
                $this->handleRefreshToken($params);
                break;

            case 'client_credentials':
                $this->handleClientCredentials($params);
                break;

            default:
                $this->sendError('unsupported_grant_type', 'Grant type not supported: ' . $grantType, 400);
                break;
        }
    }

    /**
     * Handle authorization_code grant
     *
     * @param array $params Request parameters
     * @return void
     */
    private function handleAuthorizationCode(array $params): void
    {
        // Required parameters
        $code = trim($params['code'] ?? '');
        $redirectUri = trim($params['redirect_uri'] ?? '');
        $codeVerifier = $params['code_verifier'] ?? null;

        if (empty($code)) {
            $this->sendError('invalid_request', 'Missing required parameter: code', 400);
            return;
        }

        if (empty($redirectUri)) {
            $this->sendError('invalid_request', 'Missing required parameter: redirect_uri', 400);
            return;
        }

        // Fetch authorization code
        $authCode = new \SmartAuthOAuthCode($this->db);
        $result = $authCode->fetchByCode($code);

        if ($result <= 0) {
            dol_syslog('SmartAuth TokenController: Authorization code not found', LOG_WARNING);
            $this->sendError('invalid_grant', 'Invalid or expired authorization code', 400);
            return;
        }

        // Verify code belongs to this client
        if ($authCode->fk_client !== $this->client->id) {
            dol_syslog('SmartAuth TokenController: Code client mismatch', LOG_WARNING);
            $this->sendError('invalid_grant', 'Authorization code was not issued to this client', 400);
            return;
        }

        // Check code is not expired
        if ($authCode->isExpired()) {
            dol_syslog('SmartAuth TokenController: Authorization code expired', LOG_INFO);
            $this->sendError('invalid_grant', 'Authorization code has expired', 400);
            return;
        }

        // Check code has not been used (one-time use)
        if ($authCode->isUsed()) {
            dol_syslog('SmartAuth TokenController: Authorization code already used', LOG_WARNING);
            // Per RFC 6749, if code is reused, revoke all tokens issued with it.
            // Subject-aware: an external subject (account/member) carries
            // fk_user = 0, so a fk_user-keyed revocation would wrongly hit every
            // external token of this client.
            if ($authCode->subject_type === 'account') {
                $revokeSubjectId = (int) $authCode->fk_societe_account;
            } elseif ($authCode->subject_type === 'member') {
                $revokeSubjectId = (int) $authCode->fk_adherent;
            } else {
                $revokeSubjectId = (int) $authCode->fk_user;
            }
            \SmartAuthOAuthToken::revokeAllForSubjectAndClient(
                $this->db,
                $authCode->subject_type,
                $revokeSubjectId,
                $authCode->fk_client
            );
            $this->sendError('invalid_grant', 'Authorization code has already been used', 400);
            return;
        }

        // Verify redirect_uri matches exactly
        if ($authCode->redirect_uri !== $redirectUri) {
            dol_syslog('SmartAuth TokenController: Redirect URI mismatch', LOG_WARNING);
            // Burn the code - any failed exchange consumes it (M-13)
            $authCode->markAsUsed();
            $this->sendError('invalid_grant', 'Redirect URI does not match', 400);
            return;
        }

        // Validate PKCE if code was issued with a challenge.
        // The stored method must be S256: AuthorizationController only
        // accepts S256 since the CR-3 fix, and a missing method here means
        // the auth code was created before the fix - reject as invalid_grant
        // rather than silently falling back to plain.
        // If any of these checks fail, mark the code as used so the
        // attacker cannot retry with a different verifier
        //.
        if (!empty($authCode->code_challenge)) {
            if (empty($codeVerifier)) {
                dol_syslog('SmartAuth TokenController: Missing code_verifier', LOG_WARNING);
                $authCode->markAsUsed();
                $this->sendError('invalid_grant', 'Missing required parameter: code_verifier', 400);
                return;
            }

            $storedMethod = $authCode->code_challenge_method ?? '';
            if (!PKCEHelper::isValidMethod($storedMethod)) {
                dol_syslog('SmartAuth TokenController: stored PKCE method is not S256: ' . ($storedMethod ?: '(missing)'), LOG_WARNING);
                $authCode->markAsUsed();
                $this->sendError('invalid_grant', 'Unsupported code_challenge_method on the authorization code', 400);
                return;
            }

            if (!PKCEHelper::validate($codeVerifier, $authCode->code_challenge, $storedMethod)) {
                dol_syslog('SmartAuth TokenController: PKCE verification failed', LOG_WARNING);
                $authCode->markAsUsed();
                $this->sendError('invalid_grant', 'Code verifier does not match', 400);
                return;
            }
        }

        // Get scopes from authorization code (before pre_token hook so external modules can inspect them)
        $scopes = $authCode->getScopesArray();

        // Rebuild the authenticated subject (account or user) from the code.
        $subject = TokenSubject::fromRecord(
            $this->db,
            $authCode->subject_type,
            (int) $authCode->fk_user,
            $authCode->fk_societe_account !== null ? (int) $authCode->fk_societe_account : null,
            $authCode->fk_adherent !== null ? (int) $authCode->fk_adherent : null
        );

        // Pre-token hook: external modules may re-evaluate access right
        // and inject extra claims (PERFS.md §3.3).
        $hookResult = $this->runPreTokenHook($subject, $scopes, 'authorization_code');
        if (!$hookResult['allowed']) {
            return;
        }

        // Mark code as used (only after hook approval, otherwise a blocked grant would burn the code)
        $authCode->markAsUsed();

        // Generate tokens
        $tokens = $this->generateTokens($subject, $scopes, $authCode->nonce, $hookResult['extra_claims']);

        // Send response
        $this->sendTokenResponse($tokens, $scopes);
    }

    /**
     * Handle refresh_token grant
     *
     * @param array $params Request parameters
     * @return void
     */
    private function handleRefreshToken(array $params): void
    {
        // Required parameters
        $refreshToken = trim($params['refresh_token'] ?? '');

        if (empty($refreshToken)) {
            $this->sendError('invalid_request', 'Missing required parameter: refresh_token', 400);
            return;
        }

        // Validate refresh token
        $tokenRecord = $this->tokenService->validateRefreshToken($refreshToken);
        if ($tokenRecord === null) {
            $this->sendError('invalid_grant', 'Invalid or expired refresh token', 400);
            return;
        }

        // Verify token belongs to this client
        if ($tokenRecord->fk_client !== $this->client->id) {
            dol_syslog('SmartAuth TokenController: Refresh token client mismatch', LOG_WARNING);
            $this->sendError('invalid_grant', 'Refresh token was not issued to this client', 400);
            return;
        }

        // Get original scopes
        $originalScopes = $tokenRecord->getScopesArray();

        // Optional: check for scope reduction
        $requestedScope = trim($params['scope'] ?? '');
        if (!empty($requestedScope)) {
            $requestedScopes = ScopeManager::parseScopes($requestedScope);
            // New scopes must be subset of original scopes
            foreach ($requestedScopes as $scope) {
                if (!in_array($scope, $originalScopes, true)) {
                    $this->sendError('invalid_scope', 'Requested scope exceeds original grant', 400);
                    return;
                }
            }
            $scopes = $requestedScopes;
        } else {
            $scopes = $originalScopes;
        }

        // Rebuild the subject from the stored refresh token.
        $subject = TokenSubject::fromRecord(
            $this->db,
            $tokenRecord->subject_type,
            (int) $tokenRecord->fk_user,
            $tokenRecord->fk_societe_account !== null ? (int) $tokenRecord->fk_societe_account : null,
            $tokenRecord->fk_adherent !== null ? (int) $tokenRecord->fk_adherent : null
        );
        $legacyUserId = $subject->isUser() ? $subject->getId() : 0;

        // Pre-token hook: external modules may re-evaluate access right
        // (e.g. contract closed since last refresh) and inject extra claims.
        $hookResult = $this->runPreTokenHook($subject, $scopes, 'refresh_token');
        if (!$hookResult['allowed']) {
            return;
        }

        // Rotate refresh token (revoke old, create new)
        $newRefreshToken = $this->tokenService->rotateRefreshToken($tokenRecord, $scopes);

        // Generate new access token
        $accessToken = $this->tokenService->createAccessToken(
            $legacyUserId,
            $this->client->client_id,
            $scopes,
            $this->client->access_token_lifetime,
            $hookResult['extra_claims'],
            $subject
        );

        // Store access token for revocation tracking
        $this->tokenService->storeAccessToken(
            $accessToken['jti'],
            $this->client->id,
            $legacyUserId,
            $scopes,
            $accessToken['expires_at'],
            $newRefreshToken['token_id'],
            $subject
        );

        // Build response
        $response = [
            'access_token' => $accessToken['token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessToken['expires_in'],
            'refresh_token' => $newRefreshToken['token'],
            'scope' => implode(' ', $scopes),
        ];

        // Generate new ID token if openid scope
        if (in_array('openid', $scopes, true)) {
            // Use original auth_time (we don't re-authenticate on refresh)
            $authTime = is_numeric($tokenRecord->datec) ? (int)$tokenRecord->datec : strtotime($tokenRecord->datec);
            $response['id_token'] = $this->tokenService->createIdToken(
                $legacyUserId,
                $this->client->client_id,
                $scopes,
                null, // No nonce on refresh
                $authTime,
                $accessToken['token'],
                $subject
            );
        }

        dol_syslog('SmartAuth TokenController: Tokens refreshed for subject ' . $subject->toSub(), LOG_INFO);

        $this->sendJsonResponse($response);
    }

    /**
     * Handle client_credentials grant (RFC 6749 Section 4.4)
     *
     * Machine-to-machine authentication. No user interaction.
     * Client authenticates with client_id + client_secret.
     * Returns access token only (no refresh token, no ID token).
     *
     * @param array $params Request parameters
     * @return void
     */
    private function handleClientCredentials(array $params): void
    {
        // Client must be confidential (has a secret)
        if (!$this->client->isConfidential()) {
            $this->sendError('unauthorized_client', 'Public clients cannot use client_credentials grant', 400);
            return;
        }

        // Parse requested scopes (optional)
        $requestedScope = trim($params['scope'] ?? '');
        $clientAllowedScopes = $this->client->getAllowedScopesArray();

        if (!empty($requestedScope)) {
            $scopes = ScopeManager::parseScopes($requestedScope);

            // All requested scopes must be in the client's allowed scopes
            if (!ScopeManager::areAllScopesAllowed($scopes, $clientAllowedScopes)) {
                $disallowed = ScopeManager::getDisallowedScopes($scopes, $clientAllowedScopes);
                $this->sendError('invalid_scope', 'Scope not allowed: ' . implode(' ', $disallowed), 400);
                return;
            }
        } else {
            // No scope requested: use all client allowed scopes
            $scopes = $clientAllowedScopes;
        }

        // Resolve service user
        $serviceUserId = $this->resolveServiceUser();
        if ($serviceUserId === null) {
            $this->sendError('server_error', 'No service user configured for this client', 500);
            return;
        }

        // Verify the service user actually exists and is active before
        // minting tokens for it. A disabled or
        // deleted service user must not yield a working access token.
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $serviceUser = new \User($this->db);
        $fetchResult = $serviceUser->fetch($serviceUserId);
        if ($fetchResult <= 0 || empty($serviceUser->id)) {
            dol_syslog('SmartAuth TokenController: client_credentials service user not found (id=' . $serviceUserId . ')', LOG_WARNING);
            $this->sendError('server_error', 'Service user not available', 500);
            return;
        }
        if ((int) $serviceUser->statut !== 1) {
            dol_syslog('SmartAuth TokenController: client_credentials service user disabled (id=' . $serviceUserId . ', statut=' . $serviceUser->statut . ')', LOG_WARNING);
            $this->sendError('server_error', 'Service user disabled', 500);
            return;
        }

        // The service user is the token subject (a user subject); carry its
        // societe so capsso can gate it (or skip, per spec).
        $subject = TokenSubject::user($serviceUserId, (int) $serviceUser->socid);

        // Pre-token hook: external modules may block client_credentials too
        // (gating may opt out per spec) and inject extra claims.
        $hookResult = $this->runPreTokenHook($subject, $scopes, 'client_credentials');
        if (!$hookResult['allowed']) {
            return;
        }

        // Create access token. grant_type is a SmartAuth-controlled claim
        // (HookHelper strips it from extra_claims), so the merge below cannot
        // collide with hook-contributed claims.
        $extraClaims = array_merge($hookResult['extra_claims'], ['grant_type' => 'client_credentials']);
        $accessToken = $this->tokenService->createAccessToken(
            $serviceUserId,
            $this->client->client_id,
            $scopes,
            $this->client->access_token_lifetime,
            $extraClaims,
            $subject
        );

        // Store access token for revocation tracking (no parent refresh token)
        $this->tokenService->storeAccessToken(
            $accessToken['jti'],
            $this->client->id,
            $serviceUserId,
            $scopes,
            $accessToken['expires_at'],
            null,
            $subject
        );

        // Build response: no refresh_token, no id_token (RFC 6749 Section 4.4.3)
        $response = [
            'access_token' => $accessToken['token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessToken['expires_in'],
            'scope' => implode(' ', $scopes),
        ];

        dol_syslog('SmartAuth TokenController: Client credentials token issued for client ' . $this->client->client_id . ' (service user ' . $serviceUserId . ')', LOG_INFO);

        $this->sendJsonResponse($response);
    }

    /**
     * Resolve the service user for client_credentials grant
     *
     * Priority:
     * 1. fk_service_user on the client
     * 2. SMARTAUTH_DEFAULT_USER global constant
     *
     * @return int|null User ID or null if not configured
     */
    private function resolveServiceUser(): ?int
    {
        // Check client-specific service user
        if (!empty($this->client->fk_service_user)) {
            return (int) $this->client->fk_service_user;
        }

        // Check global default
        $defaultUser = getDolGlobalInt('SMARTAUTH_DEFAULT_USER', 0);
        if ($defaultUser > 0) {
            return $defaultUser;
        }

        return null;
    }

    /**
     * Authenticate client from request
     *
     * Supports:
     * - HTTP Basic authentication (client_secret_basic)
     * - POST body parameters (client_secret_post)
     * - No authentication for public clients (none)
     *
     * @param array $params Request parameters
     * @return \SmartAuthOAuthClient|null Authenticated client or null
     */
    private function authenticateClient(array $params): ?\SmartAuthOAuthClient
    {
        $credentials = $this->getClientCredentials($params);
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];

        if (empty($clientId)) {
            dol_syslog('SmartAuth TokenController: Missing client_id', LOG_DEBUG);
            return null;
        }

        // Fetch client
        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);

        if ($result <= 0) {
            dol_syslog('SmartAuth TokenController: Client not found: ' . $clientId, LOG_WARNING);
            return null;
        }

        // Verify secret for confidential clients
        if ($client->isConfidential()) {
            if (empty($clientSecret)) {
                dol_syslog('SmartAuth TokenController: Missing client_secret for confidential client', LOG_WARNING);
                return null;
            }

            if (!$client->verifySecret($clientSecret)) {
                dol_syslog('SmartAuth TokenController: Invalid client_secret', LOG_WARNING);
                return null;
            }
        }

        return $client;
    }

    /**
     * Extract client credentials from request
     *
     * @param array $params POST parameters
     * @return array ['client_id' => string, 'client_secret' => string|null]
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
            if (strpos($auth, 'Basic ') === 0) {
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
     * Generate all tokens for a successful authorization
     *
     * @param TokenSubject $subject Authenticated subject (account or user)
     * @param array $scopes Granted scopes
     * @param string|null $nonce OIDC nonce
     * @param array $extraClaims Sanitized extra claims contributed by
     *                           the pre_token hook (PERFS.md §3.3)
     * @return array Token data
     */
    private function generateTokens(TokenSubject $subject, array $scopes, ?string $nonce, array $extraClaims = []): array
    {
        $legacyUserId = $subject->isUser() ? $subject->getId() : 0;

        // Generate access token
        $accessToken = $this->tokenService->createAccessToken(
            $legacyUserId,
            $this->client->client_id,
            $scopes,
            $this->client->access_token_lifetime,
            $extraClaims,
            $subject
        );

        $tokens = [
            'access_token' => $accessToken['token'],
            'jti' => $accessToken['jti'],
            'expires_in' => $accessToken['expires_in'],
            'expires_at' => $accessToken['expires_at'],
        ];

        // Generate refresh token if offline_access scope
        if (ScopeManager::requiresOfflineAccess($scopes)) {
            $refreshToken = $this->tokenService->createRefreshToken(
                $legacyUserId,
                $this->client->id,
                $scopes,
                $this->client->refresh_token_lifetime,
                null,
                $subject
            );
            $tokens['refresh_token'] = $refreshToken['token'];
            $tokens['refresh_token_id'] = $refreshToken['token_id'];
        }

        // Store access token record for revocation tracking
        $this->tokenService->storeAccessToken(
            $accessToken['jti'],
            $this->client->id,
            $legacyUserId,
            $scopes,
            $accessToken['expires_at'],
            $tokens['refresh_token_id'] ?? null,
            $subject
        );

        // Generate ID token if openid scope
        if (ScopeManager::requiresOpenId($scopes)) {
            $authTime = time();
            $tokens['id_token'] = $this->tokenService->createIdToken(
                $legacyUserId,
                $this->client->client_id,
                $scopes,
                $nonce,
                $authTime,
                $accessToken['token'],
                $subject
            );
        }

        return $tokens;
    }

    /**
     * Send successful token response
     *
     * @param array $tokens Generated tokens
     * @param array $scopes Granted scopes
     * @return void
     */
    private function sendTokenResponse(array $tokens, array $scopes): void
    {
        $response = [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
        ];

        if (!empty($tokens['refresh_token'])) {
            $response['refresh_token'] = $tokens['refresh_token'];
        }

        $response['scope'] = implode(' ', $scopes);

        if (!empty($tokens['id_token'])) {
            $response['id_token'] = $tokens['id_token'];
        }

        dol_syslog('SmartAuth TokenController: Tokens generated successfully', LOG_INFO);

        $this->sendJsonResponse($response);
    }

    /**
     * Invoke smartmaker_oauth_pre_token hook.
     *
     * If a module blocks the request, sends the OAuth2 error response
     * directly and returns false so the caller can stop processing.
     *
     * @param TokenSubject $subject  Authenticated subject (account or user)
     * @param array  $scopes    Scopes that will be granted
     * @param string $grantType Grant type label ('authorization_code', 'refresh_token', 'client_credentials')
     * @return array{allowed:bool, extra_claims:array} 'allowed' is false when the
     *         flow must stop (the error response has already been sent). When
     *         'allowed' is true, 'extra_claims' carries the sanitized claims
     *         the hook injected for inclusion in the access token JWT.
     */
    private function runPreTokenHook(TokenSubject $subject, array $scopes, string $grantType): array
    {
        $result = HookHelper::runBlockingHook(
            'smartmaker_oauth_pre_token',
            [
                'user_id' => $subject->isUser() ? $subject->getId() : 0,
                'subject_type' => $subject->getType(),
                'fk_societe_account' => $subject->isAccount() ? $subject->getId() : 0,
                'fk_adherent' => $subject->isMember() ? $subject->getId() : 0,
                'fk_soc' => $subject->getFkSoc(),
                'client_id' => $this->client->client_id,
                'client_pk' => $this->client->id,
                'scopes' => $scopes,
                'grant_type' => $grantType,
            ],
            $this->client
        );

        if ($result['internal_error']) {
            $this->sendError('server_error', 'Internal error during token authorization', 500);
            return ['allowed' => false, 'extra_claims' => []];
        }

        if ($result['blocked']) {
            $error = $result['error'] ?: 'invalid_grant';
            $description = $result['error_description'] ?: 'Access denied for this grant';
            $this->sendError($error, $description, 400);
            return ['allowed' => false, 'extra_claims' => []];
        }

        return ['allowed' => true, 'extra_claims' => $result['extra_claims']];
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
}
