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

dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');
dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/api/JwtKeyHelper.php');

use SmartAuth\Api\JwtKeyHelper;

class TokenService
{
    /**
     * Maximum size of the JSON-encoded JWT payload before signature, in bytes.
     * Leaves ~1 KB of margin under the 4 KB HTTP header / cookie limit so a
     * signed JWT (payload + header + signature, base64url-encoded) stays well
     * under the practical upper bound enforced by proxies and browsers.
     * PERFS.md §3.3.
     */
    const MAX_JWT_PAYLOAD_BYTES = 3072;

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
     * @param array $extraClaims Additional claims to include in the JWT payload
     * @return array ['token' => JWT string, 'jti' => JWT ID, 'expires_in' => seconds]
     */
    public function createAccessToken(int $userId, string $clientId, array $scopes, ?int $lifetime = null, array $extraClaims = []): array
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

        // Merge extra claims (e.g. grant_type for client_credentials, or
        // module-contributed claims harvested by HookHelper::runBlockingHook).
        // SmartAuth-owned claims (iss, sub, aud, exp, etc.) win on collision
        // because they are set before the merge in $payload, and HookHelper
        // already strips reserved claims from $extraClaims; we keep the same
        // win order here as a defense-in-depth in case extra claims sneak in
        // by another code path.
        if (!empty($extraClaims)) {
            $payload = array_merge($extraClaims, $payload);
        }

        // PERFS.md §3.3: enforce a hard cap on the JSON-encoded payload size
        // to keep the final base64url-encoded JWT well under the practical
        // 4 KB HTTP header / cookie limit. A misbehaving hook or runaway
        // claims list must fail loudly, not silently produce a token that
        // breaks downstream proxies and browsers.
        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            dol_syslog('SmartAuth TokenService: createAccessToken json_encode failed: ' . json_last_error_msg(), LOG_ERR);
            throw new \RuntimeException('Failed to encode JWT payload');
        }
        $payloadSize = strlen($payloadJson);
        if ($payloadSize > self::MAX_JWT_PAYLOAD_BYTES) {
            dol_syslog(
                'SmartAuth TokenService: createAccessToken payload too large (' . $payloadSize
                . ' bytes > ' . self::MAX_JWT_PAYLOAD_BYTES . ' bytes max) for user=' . $userId
                . ' client=' . $clientId,
                LOG_ERR
            );
            throw new \RuntimeException('JWT payload exceeds the maximum allowed size');
        }

        $jwt = $this->encodeJwt($payload);

        return [
            'token' => $jwt,
            'jti' => $jti,
            'expires_in' => $lifetime,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate an access token (JWT).
     *
     * Verifies signature (via decodeJwt), header typ, and the standard
     * lifetime claims (exp, iat, nbf). When $expectedAudience is supplied,
     * the aud claim must contain it (string equality, or membership for
     * array-shaped aud values) - this is what closes the "confused deputy"
     * cross-client scenario described in CR-4 of TODO-SECURITY-01.
     *
     * @param string $jwt JWT token string
     * @param string|null $expectedAudience If non-null, the token's aud
     *                                      claim must contain this value.
     * @return array|null Decoded payload or null if invalid
     */
    public function validateAccessToken(string $jwt, ?string $expectedAudience = null): ?array
    {
        $payload = $this->decodeJwt($jwt);
        if ($payload === null) {
            return null;
        }

        $now = time();
        // Tolerate small clock skew between this server and any peer that
        // signed/issued the token (RFC 7519 doesn't define a value; 30s is
        // the de-facto common upper bound).
        $skew = 30;

        // Check expiration (mandatory: every access token must have exp)
        if (!isset($payload['exp']) || (int) $payload['exp'] < $now) {
            dol_syslog('SmartAuth TokenService: Access token expired', LOG_DEBUG);
            return null;
        }

        // Check not-before (optional). If present and still in the future
        // beyond skew, reject.
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + $skew) {
            dol_syslog('SmartAuth TokenService: Access token not yet valid (nbf)', LOG_WARNING);
            return null;
        }

        // Check issued-at (optional). A token with iat in the future beyond
        // skew is suspicious - reject.
        if (isset($payload['iat']) && (int) $payload['iat'] > $now + $skew) {
            dol_syslog('SmartAuth TokenService: Access token issued in the future (iat)', LOG_WARNING);
            return null;
        }

        // Check issuer
        if (!isset($payload['iss']) || $payload['iss'] !== OAuthConfig::getIssuer()) {
            dol_syslog('SmartAuth TokenService: Invalid issuer', LOG_WARNING);
            return null;
        }

        // Check audience when the caller has stated which audience it expects.
        // Tokens issued for client A must not be accepted by a resource that
        // expects audience B (RFC 8725 §3.10, RFC 7519 §4.1.3).
        if ($expectedAudience !== null) {
            if (!isset($payload['aud'])) {
                dol_syslog('SmartAuth TokenService: aud claim missing', LOG_WARNING);
                return null;
            }
            $audClaim = $payload['aud'];
            $audMatches = is_array($audClaim)
                ? in_array($expectedAudience, $audClaim, true)
                : ($audClaim === $expectedAudience);
            if (!$audMatches) {
                dol_syslog('SmartAuth TokenService: aud mismatch (expected=' . $expectedAudience . ')', LOG_WARNING);
                return null;
            }
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
     * Validate a refresh token.
     *
     * Implements RFC 9700 §2.2.2 (formerly OAuth 2.0 Security BCP §4.13.2)
     * refresh-token replay detection: if the presented refresh token has
     * already been revoked (i.e., a previous use already rotated it), we
     * assume the token was leaked and revoke the entire family rooted at
     * the original token. This is H-11 of TODO-SECURITY-01.
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

        // Refresh token replay: token exists but is already revoked.
        // Per RFC 9700, revoke the entire family.
        if ($tokenRecord->isRevoked()) {
            dol_syslog(
                'SmartAuth TokenService: refresh token replay detected (id=' . (int) $tokenRecord->id
                . ', user=' . (int) $tokenRecord->fk_user . ') - revoking family',
                LOG_WARNING
            );
            $this->revokeFamily($tokenRecord);
            return null;
        }

        // Check validity (not expired)
        if (!$tokenRecord->isValid()) {
            dol_syslog('SmartAuth TokenService: Refresh token is invalid (expired)', LOG_INFO);
            return null;
        }

        return $tokenRecord;
    }

    /**
     * Walk up the parent chain to the root token, then revoke it and all
     * descendants. Used by validateRefreshToken() when a replay is detected.
     */
    private function revokeFamily(\SmartAuthOAuthToken $token): void
    {
        $root = $token;
        $guard = 0;
        while (!empty($root->fk_parent) && $guard < 100) {
            $parent = new \SmartAuthOAuthToken($this->db);
            if ($parent->fetch((int) $root->fk_parent) <= 0) {
                break;
            }
            $root = $parent;
            $guard++;
        }
        $root->revokeWithChildren();
    }

    /**
     * Rotate a refresh token (invalidate old, create new) atomically.
     *
     * The revoke + create pair runs inside a single SQL transaction with
     * a conditional UPDATE so two concurrent refresh requests with the
     * same token cannot both succeed. The
     * loser of the race sees a runtime exception and the caller should
     * surface that as invalid_grant to the client.
     *
     * @param \SmartAuthOAuthToken $oldToken The old refresh token to rotate
     * @param array|null $newScopes New scopes (null = keep same scopes)
     * @return array ['token' => new plain text token, 'token_id' => new database row ID]
     */
    public function rotateRefreshToken(\SmartAuthOAuthToken $oldToken, ?array $newScopes = null): array
    {
        $scopes = $newScopes ?? $oldToken->getScopesArray();

        $this->db->begin();
        $inTx = true;
        try {
            // Atomic conditional revoke: only the first concurrent attempt
            // sees affected_rows=1, the others see 0 and abort.
            $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens"
                . " SET revoked_at = '" . $this->db->idate(dol_now()) . "'"
                . " WHERE rowid = " . ((int) $oldToken->id)
                . " AND revoked_at IS NULL";
            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new \RuntimeException('Failed to revoke old refresh token: ' . $this->db->lasterror());
            }
            if ((int) $this->db->affected_rows($resql) !== 1) {
                // Lost the race - another caller already rotated this token.
                // Treat as a replay and revoke the family before returning.
                dol_syslog(
                    'SmartAuth TokenService: rotateRefreshToken concurrent reuse detected (id=' . (int) $oldToken->id . ')',
                    LOG_WARNING
                );
                $this->db->rollback();
                $inTx = false;
                $this->revokeFamily($oldToken);
                throw new \RuntimeException('Refresh token already used');
            }

            // Reflect the revoke locally so the rest of the codebase sees it
            $oldToken->revoked_at = dol_now();

            $newToken = $this->createRefreshToken(
                $oldToken->fk_user,
                $oldToken->fk_client,
                $scopes,
                null,
                $oldToken->id
            );

            $this->db->commit();
            $inTx = false;
            dol_syslog('SmartAuth TokenService: Refresh token rotated (old_id=' . (int) $oldToken->id . ')', LOG_INFO);
            return $newToken;
        } catch (\Throwable $e) {
            if ($inTx) {
                $this->db->rollback();
            }
            throw $e;
        }
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

        // Allow external modules to mutate claims (id_token context)
        $clientPk = $this->resolveClientPk($clientId);
        $payload = HookHelper::runClaimsHook(
            [
                'user_id' => $userId,
                'client_id' => $clientId,
                'client_pk' => $clientPk,
                'scopes' => $scopes,
                'context' => 'id_token',
            ],
            $payload
        );

        return $this->encodeJwt($payload);
    }

    /**
     * Resolve a client primary key (rowid) from its public client_id.
     *
     * @param string $clientId Public client ID
     * @return int|null Database rowid or null
     */
    private function resolveClientPk(string $clientId): ?int
    {
        if ($clientId === '') {
            return null;
        }

        $client = new \SmartAuthOAuthClient($this->db);
        $result = $client->fetch(0, null, $clientId);
        if ($result <= 0) {
            return null;
        }

        return (int) $client->id;
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
     * Revoke all access and refresh tokens for a (user, client) couple.
     *
     * Stamps revoked_at = NOW() on every active token. Does NOT delete
     * oauth_consents (the user may reconsent later without re-passing the
     * consent screen). Returns the number of tokens revoked, or a negative
     * error code on database failure.
     *
     * @param int $fk_user      User row ID
     * @param int $fk_client_pk OAuth client row ID
     * @return int Number of revoked tokens, or -1 on error
     */
    public function revokeAllForUserAndClient(int $fk_user, int $fk_client_pk): int
    {
        $count = \SmartAuthOAuthToken::revokeAllForUserAndClient($this->db, $fk_user, $fk_client_pk);
        if ($count < 0) {
            dol_syslog('SmartAuth TokenService: revokeAllForUserAndClient failed for user ' . $fk_user . ' client ' . $fk_client_pk, LOG_ERR);
            return -1;
        }
        dol_syslog('SmartAuth TokenService: Revoked ' . $count . ' tokens for user ' . $fk_user . ' client ' . $fk_client_pk, LOG_INFO);
        return $count;
    }

    /**
     * Revoke every active token for a user across all clients.
     *
     * Used by /account "log out everywhere" and by ssomanager USER_DISABLE
     * / USER_DELETE / COMPANY_DELETE triggers. Returns the number of tokens
     * revoked, or a negative error code on database failure.
     *
     * @param int $fk_user User row ID
     * @return int Number of revoked tokens, or -1 on error
     */
    public function revokeAllForUser(int $fk_user): int
    {
        $count = \SmartAuthOAuthToken::revokeAllForUser($this->db, $fk_user);
        if ($count < 0) {
            dol_syslog('SmartAuth TokenService: revokeAllForUser failed for user ' . $fk_user, LOG_ERR);
            return -1;
        }
        dol_syslog('SmartAuth TokenService: Revoked ' . $count . ' tokens for user ' . $fk_user, LOG_INFO);
        return $count;
    }

    // =========================================================================
    // Revoked-JTI list (PERFS.md §3.4 hybrid revocation: TTL + revocation list)
    // =========================================================================

    /**
     * Add a JTI to the published revocation list.
     *
     * Idempotent: re-adding an already-known jti is a no-op. Resource
     * servers poll GET /oauth/revoked-jti and reject any access token
     * whose jti appears in the list -- this is what closes the window
     * between the TTL-based revocation (up to access_token_lifetime) and
     * the actual contract-closure event (a few minutes via the poll).
     *
     * @param string $jti        JWT ID, as emitted by createAccessToken
     * @param int    $expiresAt  Original exp claim of the token (unix ts).
     *                           Once this is past, the token is rejected
     *                           anyway by the standard exp check -- so the
     *                           row can be purged.
     * @param string $reason     Free-form short label, kept for audit
     *                           (e.g. 'contract_closed', 'manual',
     *                           'credential_compromise').
     * @return int  1 if inserted, 0 if jti already in list, -1 on error
     */
    public function addRevokedJti(string $jti, int $expiresAt, string $reason): int
    {
        $jti = trim($jti);
        if ($jti === '' || $expiresAt <= 0) {
            dol_syslog('SmartAuth TokenService: addRevokedJti rejected empty jti or non-positive expiresAt', LOG_WARNING);
            return -1;
        }

        // Idempotence: check existence first. PK on jti would catch a
        // duplicate INSERT anyway, but a clean SELECT lets us log the
        // no-op path distinctly without producing a SQL error.
        // Scoped by entity: the revocation list is per-entity.
        $sql = "SELECT jti FROM " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " WHERE jti = '" . $this->db->escape($jti) . "'"
            . " AND entity IN (" . getEntity('smartauthrevokedjti') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: addRevokedJti SELECT failed: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $exists = ($this->db->num_rows($resql) > 0);
        $this->db->free($resql);
        if ($exists) {
            return 0;
        }

        global $conf;
        $entity = isset($conf->entity) ? (int) $conf->entity : 1;
        $reason = substr((string) $reason, 0, 64);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " (jti, revoked_at, expires_at, reason, entity)"
            . " VALUES ("
            . "'" . $this->db->escape($jti) . "',"
            . "'" . $this->db->idate(dol_now()) . "',"
            . "'" . $this->db->idate($expiresAt) . "',"
            . "'" . $this->db->escape($reason) . "',"
            . $entity
            . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: addRevokedJti INSERT failed for jti=' . $jti . ': ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        dol_syslog('SmartAuth TokenService: jti revoked (jti=' . $jti . ', reason=' . $reason . ')', LOG_INFO);
        return 1;
    }

    /**
     * List the currently-active access tokens for a (user, client) couple,
     * returning each jti and its original exp claim.
     *
     * "Currently active" means: token_type = access, revoked_at IS NULL,
     * expires_at in the future. This is the set a trigger (e.g. capsso
     * LINECONTRACT_CLOSE) wants to publish to the revocation list before
     * calling revokeAllForUserAndClient.
     *
     * Caller workflow:
     *   $jtis = $svc->listRevokedJtiActiveForUserAndClient($u, $c);
     *   foreach ($jtis as $row) { $svc->addRevokedJti($row['jti'], $row['expires_at'], 'contract_closed'); }
     *   $svc->revokeAllForUserAndClient($u, $c);
     *
     * @param int $fk_user      User row ID
     * @param int $fk_client_pk OAuth client row ID
     * @return array<int, array{jti: string, expires_at: int}> Empty array on error or no match
     */
    public function listRevokedJtiActiveForUserAndClient(int $fk_user, int $fk_client_pk): array
    {
        $results = [];

        $sql = "SELECT jti, expires_at FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens"
            . " WHERE fk_user = " . ((int) $fk_user)
            . " AND fk_client = " . ((int) $fk_client_pk)
            . " AND token_type = '" . \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS . "'"
            . " AND revoked_at IS NULL"
            . " AND expires_at > '" . $this->db->idate(dol_now()) . "'"
            . " AND jti IS NOT NULL AND jti <> ''"
            . " AND entity IN (" . getEntity('smartauthoauthtoken') . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: listRevokedJtiActiveForUserAndClient SQL failed: ' . $this->db->lasterror(), LOG_ERR);
            return $results;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $exp = $obj->expires_at;
            $expTs = is_numeric($exp) ? (int) $exp : (int) strtotime((string) $exp);
            $results[] = [
                'jti' => (string) $obj->jti,
                'expires_at' => $expTs,
            ];
        }
        $this->db->free($resql);
        return $results;
    }

    /**
     * Same idea as listRevokedJtiActiveForUserAndClient, but across every
     * client. Used by user-scope triggers (USER_DISABLE / USER_DELETE /
     * COMPANY_DELETE) that revoke the user globally and want to publish
     * every still-unexpired jti.
     *
     * @param int $fk_user User row ID
     * @return array<int, array{jti: string, expires_at: int}>
     */
    public function listRevokedJtiActiveForUser(int $fk_user): array
    {
        $results = [];

        $sql = "SELECT jti, expires_at FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens"
            . " WHERE fk_user = " . ((int) $fk_user)
            . " AND token_type = '" . \SmartAuthOAuthToken::TOKEN_TYPE_ACCESS . "'"
            . " AND revoked_at IS NULL"
            . " AND expires_at > '" . $this->db->idate(dol_now()) . "'"
            . " AND jti IS NOT NULL AND jti <> ''"
            . " AND entity IN (" . getEntity('smartauthoauthtoken') . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: listRevokedJtiActiveForUser SQL failed: ' . $this->db->lasterror(), LOG_ERR);
            return $results;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $exp = $obj->expires_at;
            $expTs = is_numeric($exp) ? (int) $exp : (int) strtotime((string) $exp);
            $results[] = [
                'jti' => (string) $obj->jti,
                'expires_at' => $expTs,
            ];
        }
        $this->db->free($resql);
        return $results;
    }

    /**
     * Fetch the published revocation list, filtered by an optional "since"
     * timestamp (unix seconds, comparing against revoked_at). Rows whose
     * expires_at is already in the past are excluded since the underlying
     * access token would be rejected by the exp check anyway.
     *
     * Used by GET /oauth/revoked-jti to feed resource servers' poll.
     *
     * @param int $sinceTs Unix timestamp; 0 means "from the beginning"
     * @return array<int, array{jti: string, revoked_at_ts: int}>
     */
    public function listRevokedJtiSince(int $sinceTs = 0): array
    {
        $results = [];

        $nowSql = $this->db->idate(dol_now());

        $sql = "SELECT jti, revoked_at FROM " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " WHERE expires_at > '" . $nowSql . "'"
            . " AND entity IN (" . getEntity('smartauthrevokedjti') . ")";
        if ($sinceTs > 0) {
            $sql .= " AND revoked_at > '" . $this->db->idate($sinceTs) . "'";
        }
        $sql .= " ORDER BY revoked_at ASC, jti ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: listRevokedJtiSince SQL failed: ' . $this->db->lasterror(), LOG_ERR);
            return $results;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rev = $obj->revoked_at;
            $revTs = is_numeric($rev) ? (int) $rev : (int) strtotime((string) $rev);
            $results[] = [
                'jti' => (string) $obj->jti,
                'revoked_at_ts' => $revTs,
            ];
        }
        $this->db->free($resql);
        return $results;
    }

    /**
     * Purge rows whose expires_at has passed. Cheap, can be called from a
     * scheduled job or lazily before reading the list (PERFS.md §3.4).
     *
     * @return int Number of rows deleted, or -1 on error.
     */
    public function purgeExpiredRevokedJti(): int
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " WHERE expires_at < '" . $this->db->idate(dol_now()) . "'"
            . " AND entity IN (" . getEntity('smartauthrevokedjti') . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth TokenService: purgeExpiredRevokedJti SQL failed: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $affected = (int) $this->db->affected_rows($resql);
        if ($affected > 0) {
            dol_syslog('SmartAuth TokenService: purged ' . $affected . ' expired revoked_jti rows', LOG_INFO);
        }
        return $affected;
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

        // Reject unexpected typ values (RFC 8725 §3.11). 'JWT' is the default
        // when typ is set; we tolerate its absence (RFC 7519 makes typ optional)
        // but never accept any other media type, to prevent confusion with
        // other JOSE-shaped artefacts.
        if (isset($header['typ']) && $header['typ'] !== 'JWT' && $header['typ'] !== 'jwt') {
            dol_syslog('SmartAuth TokenService: Unexpected JWT typ: ' . $header['typ'], LOG_WARNING);
            return null;
        }

        // Verify signature. The header.kid tells us which key signed the
        // token; we look it up by kid (current or archived) so rotation
        // does not invalidate live tokens.
        $dataToVerify = $headerEncoded . '.' . $payloadEncoded;
        $signature = JwtKeyHelper::base64UrlDecode($signatureEncoded);
        $publicKey = '';
        if (!empty($header['kid'])) {
            $publicKey = JwtKeyHelper::getRsaPublicKeyByKid((string) $header['kid']);
        }
        if ($publicKey === '') {
            $publicKey = JwtKeyHelper::getRsaPublicKey();
        }

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
