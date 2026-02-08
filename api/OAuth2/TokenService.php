<?php

/**
 * TokenService.php
 *
 * OAuth2/OIDC token generation and validation service.
 * Handles access tokens (JWT), refresh tokens (opaque), and ID tokens (JWT OIDC).
 *
 * Token formats:
 * - Access token: JWT signed with RS256, contains client_id, user_id, scopes, jti
 * - Refresh token: Opaque format "smartauth_rt_XXXX", stored hashed in database
 * - ID token: JWT signed with RS256, contains OIDC claims based on scopes
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
require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/class/smartauthoauthtoken.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/smartauth/api/JwtKeyHelper.php';

use SmartAuth\Api\JwtKeyHelper;

class TokenService
{
    /**
     * Database connection
     * @var \DoliDB
     */
    private $db;

    /**
     * Constructor
     *
     * @param \DoliDB $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create an access token (JWT format)
     *
     * @param int $userId Dolibarr user ID
     * @param string $clientId OAuth client ID (public identifier)
     * @param array $scopes Granted scopes
     * @param int|null $lifetime Token lifetime in seconds (null = use client/default)
     * @return array ['token' => JWT string, 'jti' => JWT ID, 'expires_in' => seconds]
     */
    public function createAccessToken(int $userId, string $clientId, array $scopes, ?int $lifetime = null): array
    {
        $lifetime = $lifetime ?? OAuthConfig::getAccessTokenTTL();
        $now = time();
        $expiresAt = $now + $lifetime;
        $jti = \SmartAuthOAuthToken::generateJti();

        $payload = [
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $userId,
            'aud' => $clientId,
            'exp' => $expiresAt,
            'iat' => $now,
            'jti' => $jti,
            'client_id' => $clientId,
            'scope' => implode(' ', $scopes),
        ];

        $jwt = $this->encodeJwt($payload);

        return [
            'token' => $jwt,
            'jti' => $jti,
            'expires_in' => $lifetime,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate an access token (JWT)
     *
     * @param string $jwt JWT token string
     * @return array|null Decoded payload or null if invalid
     */
    public function validateAccessToken(string $jwt): ?array
    {
        $payload = $this->decodeJwt($jwt);
        if ($payload === null) {
            return null;
        }

        // Check expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            dol_syslog('SmartAuth TokenService: Access token expired', LOG_DEBUG);
            return null;
        }

        // Check issuer
        if (!isset($payload['iss']) || $payload['iss'] !== OAuthConfig::getIssuer()) {
            dol_syslog('SmartAuth TokenService: Invalid issuer', LOG_WARNING);
            return null;
        }

        // Check JTI not revoked (if stored)
        if (isset($payload['jti'])) {
            $token = new \SmartAuthOAuthToken($this->db);
            $result = $token->fetchByJti($payload['jti']);
            if ($result > 0 && $token->isRevoked()) {
                dol_syslog('SmartAuth TokenService: Access token revoked (jti=' . $payload['jti'] . ')', LOG_INFO);
                return null;
            }
        }

        return $payload;
    }

    /**
     * Create a refresh token (opaque format)
     *
     * @param int $userId Dolibarr user ID
     * @param int $clientRowId OAuth client database row ID
     * @param array $scopes Granted scopes
     * @param int|null $lifetime Token lifetime in seconds
     * @param int|null $parentTokenId Parent token ID (for rotation tracking)
     * @return array ['token' => plain text token, 'token_id' => database row ID]
     */
    public function createRefreshToken(int $userId, int $clientRowId, array $scopes, ?int $lifetime = null, ?int $parentTokenId = null): array
    {
        $lifetime = $lifetime ?? OAuthConfig::getRefreshTokenTTL();
        $now = dol_now();
        $expiresAt = $now + $lifetime;

        // Generate opaque token
        $plainToken = \SmartAuthOAuthToken::generateRefreshToken();
        $tokenHash = \SmartAuthOAuthToken::hashToken($plainToken);

        // Get user for creation
        $user = new \User($this->db);
        $user->fetch($userId);

        // Store in database
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenRecord->token_hash = $tokenHash;
        $tokenRecord->token_type = \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH;
        $tokenRecord->fk_client = $clientRowId;
        $tokenRecord->fk_user = $userId;
        $tokenRecord->setScopesArray($scopes);
        $tokenRecord->expires_at = $expiresAt;
        $tokenRecord->fk_parent = $parentTokenId;

        $result = $tokenRecord->create($user);
        if ($result < 0) {
            dol_syslog('SmartAuth TokenService: Failed to create refresh token: ' . implode(', ', $tokenRecord->errors), LOG_ERR);
            throw new \RuntimeException('Failed to create refresh token');
        }

        dol_syslog('SmartAuth TokenService: Refresh token created for user ' . $userId, LOG_INFO);

        return [
            'token' => $plainToken,
            'token_id' => $tokenRecord->id,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate a refresh token
     *
     * @param string $token Plain text refresh token
     * @return \SmartAuthOAuthToken|null Token record or null if invalid
     */
    public function validateRefreshToken(string $token): ?\SmartAuthOAuthToken
    {
        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $result = $tokenRecord->fetchByToken($token);

        if ($result <= 0) {
            dol_syslog('SmartAuth TokenService: Refresh token not found', LOG_DEBUG);
            return null;
        }

        // Must be a refresh token
        if (!$tokenRecord->isRefreshToken()) {
            dol_syslog('SmartAuth TokenService: Token is not a refresh token', LOG_WARNING);
            return null;
        }

        // Check validity (not expired, not revoked)
        if (!$tokenRecord->isValid()) {
            dol_syslog('SmartAuth TokenService: Refresh token is invalid (expired or revoked)', LOG_INFO);
            return null;
        }

        return $tokenRecord;
    }

    /**
     * Rotate a refresh token (invalidate old, create new)
     *
     * This implements refresh token rotation for security.
     * The old token is revoked and a new one is created.
     *
     * @param \SmartAuthOAuthToken $oldToken The old refresh token to rotate
     * @param array|null $newScopes New scopes (null = keep same scopes)
     * @return array ['token' => new plain text token, 'token_id' => new database row ID]
     */
    public function rotateRefreshToken(\SmartAuthOAuthToken $oldToken, ?array $newScopes = null): array
    {
        // Get scopes from old token if not provided
        $scopes = $newScopes ?? $oldToken->getScopesArray();

        // Revoke the old token
        $oldToken->revoke();
        dol_syslog('SmartAuth TokenService: Old refresh token revoked (id=' . $oldToken->id . ')', LOG_INFO);

        // Create new refresh token with reference to old one
        return $this->createRefreshToken(
            $oldToken->fk_user,
            $oldToken->fk_client,
            $scopes,
            null,
            $oldToken->id
        );
    }

    /**
     * Create an ID token (OIDC JWT format)
     *
     * @param int $userId Dolibarr user ID
     * @param string $clientId OAuth client ID
     * @param array $scopes Granted scopes
     * @param string|null $nonce Nonce from authorization request
     * @param int $authTime Timestamp when user authenticated
     * @param string|null $accessTokenHash Hash of access token for at_hash claim
     * @return string JWT ID token
     */
    public function createIdToken(
        int $userId,
        string $clientId,
        array $scopes,
        ?string $nonce,
        int $authTime,
        ?string $accessTokenHash = null
    ): string {
        $now = time();
        $expiresAt = $now + OAuthConfig::getAccessTokenTTL();

        // Load user for claims
        $user = new \User($this->db);
        $user->fetch($userId);

        // Build payload with standard OIDC claims
        $payload = [
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $userId,
            'aud' => $clientId,
            'exp' => $expiresAt,
            'iat' => $now,
            'auth_time' => $authTime,
        ];

        // Add nonce if provided (OIDC requires it to be echoed back)
        if ($nonce !== null) {
            $payload['nonce'] = $nonce;
        }

        // Add at_hash if access token provided (for hybrid flows)
        if ($accessTokenHash !== null) {
            $payload['at_hash'] = $this->computeAtHash($accessTokenHash);
        }

        // Add claims based on scopes
        $payload = $this->addUserClaims($payload, $user, $scopes);

        return $this->encodeJwt($payload);
    }

    /**
     * Store an access token record in database (for revocation tracking)
     *
     * @param string $jti JWT ID
     * @param int $clientRowId Client database row ID
     * @param int $userId User ID
     * @param array $scopes Granted scopes
     * @param int $expiresAt Expiration timestamp
     * @param int|null $parentRefreshTokenId Parent refresh token ID
     * @return \SmartAuthOAuthToken Token record
     */
    public function storeAccessToken(
        string $jti,
        int $clientRowId,
        int $userId,
        array $scopes,
        int $expiresAt,
        ?int $parentRefreshTokenId = null
    ): \SmartAuthOAuthToken {
        $user = new \User($this->db);
        $user->fetch($userId);

        $tokenRecord = new \SmartAuthOAuthToken($this->db);
        $tokenRecord->token_hash = hash('sha256', $jti);
        $tokenRecord->token_type = \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS;
        $tokenRecord->fk_client = $clientRowId;
        $tokenRecord->fk_user = $userId;
        $tokenRecord->setScopesArray($scopes);
        $tokenRecord->jti = $jti;
        $tokenRecord->expires_at = $expiresAt;
        $tokenRecord->fk_parent = $parentRefreshTokenId;

        $result = $tokenRecord->create($user);
        if ($result < 0) {
            dol_syslog('SmartAuth TokenService: Failed to store access token: ' . implode(', ', $tokenRecord->errors), LOG_ERR);
            throw new \RuntimeException('Failed to store access token');
        }

        return $tokenRecord;
    }

    /**
     * Revoke a token by its plain text value
     *
     * @param string $token Plain text token (refresh token) or JTI (access token)
     * @param string|null $tokenTypeHint Hint: 'access_token' or 'refresh_token'
     * @return bool True if token was found and revoked
     */
    public function revokeToken(string $token, ?string $tokenTypeHint = null): bool
    {
        $tokenRecord = new \SmartAuthOAuthToken($this->db);

        // Try refresh token first if hinted or no hint
        if ($tokenTypeHint !== 'access_token') {
            $result = $tokenRecord->fetchByToken($token);
            if ($result > 0) {
                $tokenRecord->revokeWithChildren();
                dol_syslog('SmartAuth TokenService: Refresh token revoked (id=' . $tokenRecord->id . ')', LOG_INFO);
                return true;
            }
        }

        // Try as JTI for access token
        if ($tokenTypeHint !== 'refresh_token') {
            $result = $tokenRecord->fetchByJti($token);
            if ($result > 0) {
                $tokenRecord->revoke();
                dol_syslog('SmartAuth TokenService: Access token revoked (jti=' . $token . ')', LOG_INFO);
                return true;
            }
        }

        // Token not found - per RFC 7009, this is not an error
        dol_syslog('SmartAuth TokenService: Token not found for revocation', LOG_DEBUG);
        return false;
    }

    /**
     * Encode a payload as JWT using RS256
     *
     * @param array $payload JWT payload
     * @return string Encoded JWT
     */
    private function encodeJwt(array $payload): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => JwtKeyHelper::getRsaKeyId(),
        ];

        $headerEncoded = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $payloadEncoded = JwtKeyHelper::base64UrlEncode(json_encode($payload));

        $dataToSign = $headerEncoded . '.' . $payloadEncoded;

        $privateKey = JwtKeyHelper::getRsaPrivateKey();
        $signature = '';
        $success = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new \RuntimeException('Failed to sign JWT: ' . openssl_error_string());
        }

        $signatureEncoded = JwtKeyHelper::base64UrlEncode($signature);

        return $dataToSign . '.' . $signatureEncoded;
    }

    /**
     * Decode and verify a JWT
     *
     * @param string $jwt JWT string
     * @return array|null Decoded payload or null if invalid
     */
    private function decodeJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            dol_syslog('SmartAuth TokenService: Invalid JWT format', LOG_DEBUG);
            return null;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Decode header
        $header = json_decode(JwtKeyHelper::base64UrlDecode($headerEncoded), true);
        if ($header === null || !isset($header['alg'])) {
            dol_syslog('SmartAuth TokenService: Invalid JWT header', LOG_DEBUG);
            return null;
        }

        // Only RS256 supported
        if ($header['alg'] !== 'RS256') {
            dol_syslog('SmartAuth TokenService: Unsupported JWT algorithm: ' . $header['alg'], LOG_WARNING);
            return null;
        }

        // Verify signature
        $dataToVerify = $headerEncoded . '.' . $payloadEncoded;
        $signature = JwtKeyHelper::base64UrlDecode($signatureEncoded);
        $publicKey = JwtKeyHelper::getRsaPublicKey();

        $valid = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            dol_syslog('SmartAuth TokenService: JWT signature verification failed', LOG_WARNING);
            return null;
        }

        // Decode payload
        $payload = json_decode(JwtKeyHelper::base64UrlDecode($payloadEncoded), true);
        if ($payload === null) {
            dol_syslog('SmartAuth TokenService: Invalid JWT payload', LOG_DEBUG);
            return null;
        }

        return $payload;
    }

    /**
     * Add user claims to ID token based on scopes
     *
     * @param array $payload Current payload
     * @param \User $user Dolibarr user
     * @param array $scopes Granted scopes
     * @return array Updated payload with claims
     */
    private function addUserClaims(array $payload, \User $user, array $scopes): array
    {
        // Profile scope: name, family_name, given_name, updated_at
        if (in_array('profile', $scopes, true)) {
            $payload['name'] = trim($user->firstname . ' ' . $user->lastname);
            if (!empty($user->lastname)) {
                $payload['family_name'] = $user->lastname;
            }
            if (!empty($user->firstname)) {
                $payload['given_name'] = $user->firstname;
            }
            if (!empty($user->datec)) {
                $updatedAt = is_numeric($user->datec) ? $user->datec : strtotime($user->datec);
                $payload['updated_at'] = $updatedAt;
            }
        }

        // Email scope: email, email_verified
        if (in_array('email', $scopes, true)) {
            if (!empty($user->email)) {
                $payload['email'] = $user->email;
                // Dolibarr doesn't track email verification, assume true
                $payload['email_verified'] = true;
            }
        }

        // Groups scope: groups
        if (in_array('groups', $scopes, true)) {
            $groups = $this->getUserGroups($user);
            if (!empty($groups)) {
                $payload['groups'] = $groups;
            }
        }

        // Roles scope: roles
        if (in_array('roles', $scopes, true)) {
            $roles = $this->getUserRoles($user);
            if (!empty($roles)) {
                $payload['roles'] = $roles;
            }
        }

        return $payload;
    }

    /**
     * Get user's group names
     *
     * @param \User $user Dolibarr user
     * @return array Array of group names
     */
    private function getUserGroups(\User $user): array
    {
        $groups = [];

        // Load user groups if not already loaded
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
     * @param \User $user Dolibarr user
     * @return array Array of role names
     */
    private function getUserRoles(\User $user): array
    {
        $roles = ['ROLE_USER'];

        // Check if admin
        if (!empty($user->admin)) {
            $roles[] = 'ROLE_ADMIN';
        }

        // Add roles based on groups (configurable mapping)
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
        // Default mapping - can be extended via configuration
        $mapping = [
            'Administrateurs' => 'ROLE_ADMIN',
            'Bureau' => 'ROLE_BUREAU',
            'Membres' => 'ROLE_MEMBER',
        ];

        // Load custom mapping from configuration if exists
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
     * Compute at_hash claim for ID token
     *
     * @param string $accessToken Access token to hash
     * @return string Base64url encoded left half of SHA-256 hash
     */
    private function computeAtHash(string $accessToken): string
    {
        $hash = hash('sha256', $accessToken, true);
        $leftHalf = substr($hash, 0, 16);
        return JwtKeyHelper::base64UrlEncode($leftHalf);
    }
}
