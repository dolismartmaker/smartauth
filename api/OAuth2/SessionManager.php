<?php

/**
 * SessionManager.php
 *
 * Manages SmartAuth session via stateless JWT cookie.
 * No server-side session storage required - all state is in the signed JWT.
 *
 * Cookie format:
 * - Name: smartauth_session
 * - Value: JWT signed with RSA key (RS256)
 * - Attributes: HttpOnly, Secure, SameSite=Lax
 *
 * JWT payload:
 * - iss: Issuer URL
 * - sub: User ID (string)
 * - iat: Issued at timestamp
 * - exp: Expiration timestamp
 * - auth_time: Authentication timestamp
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

use SmartAuth\Api\JwtKeyHelper;

class SessionManager
{
    /**
     * Cookie name for SmartAuth session
     */
    const COOKIE_NAME = 'smartauth_session';

    /**
     * Database connection
     * @var object
     */
    private $db;

    /**
     * Cached session payload after validation
     * @var array|null
     */
    private $cachedPayload = null;

    /**
     * Constructor
     *
     * @param object $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Validate current session and return user ID
     *
     * Checks:
     * 1. Cookie exists and is not empty
     * 2. JWT signature is valid
     * 3. JWT is not expired
     * 4. User exists and is active in Dolibarr
     *
     * @return int|null User ID if session is valid, null otherwise
     */
    public function validateSession(): ?int
    {
        // Check if cookie exists
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (empty($cookie)) {
            return null;
        }

        try {
            // Decode and verify JWT
            $payload = $this->decodeJwt($cookie);
            if ($payload === null) {
                $this->clearSession();
                return null;
            }

            // Check expiration
            $exp = $payload['exp'] ?? 0;
            if ($exp < time()) {
                dol_syslog('SmartAuth SessionManager: Session expired', LOG_DEBUG);
                $this->clearSession();
                return null;
            }

            // Validate user exists and is active
            $userId = (int) ($payload['sub'] ?? 0);
            if ($userId <= 0) {
                $this->clearSession();
                return null;
            }

            if (!$this->isUserActive($userId)) {
                dol_syslog('SmartAuth SessionManager: User ' . $userId . ' not found or inactive', LOG_DEBUG);
                $this->clearSession();
                return null;
            }

            // Cache payload for getAuthTime()
            $this->cachedPayload = $payload;

            return $userId;
        } catch (\Exception $e) {
            dol_syslog('SmartAuth SessionManager: Session validation error: ' . $e->getMessage(), LOG_WARNING);
            $this->clearSession();
            return null;
        }
    }

    /**
     * Create a new session for a user
     *
     * Generates a signed JWT cookie with user information.
     *
     * @param int $userId Dolibarr user ID
     * @return void
     */
    public function createSession(int $userId): void
    {
        $now = time();
        $ttl = OAuthConfig::getSessionTTL();

        $payload = [
            'iss' => OAuthConfig::getIssuer(),
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'auth_time' => $now,
        ];

        $jwt = $this->encodeJwt($payload);

        // Determine if we should use Secure flag
        $secure = $this->isSecureContext();

        // Set cookie
        $cookieOptions = [
            'expires' => $now + $ttl,
            'path' => '/',
            'domain' => $this->getCookieDomain(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie(self::COOKIE_NAME, $jwt, $cookieOptions);

        // Cache the payload
        $this->cachedPayload = $payload;

        dol_syslog('SmartAuth SessionManager: Session created for user ' . $userId, LOG_INFO);
    }

    /**
     * Clear the current session
     *
     * Removes the session cookie by setting it to empty with past expiration.
     *
     * @return void
     */
    public function clearSession(): void
    {
        $secure = $this->isSecureContext();

        $cookieOptions = [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => $this->getCookieDomain(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie(self::COOKIE_NAME, '', $cookieOptions);

        // Clear cached payload
        $this->cachedPayload = null;

        // Also unset from current request
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Get the authentication time from current session
     *
     * Returns the timestamp when the user originally authenticated.
     *
     * @return int|null Unix timestamp of authentication, or null if no valid session
     */
    public function getAuthTime(): ?int
    {
        // Use cached payload if available
        if ($this->cachedPayload !== null) {
            return $this->cachedPayload['auth_time'] ?? null;
        }

        // Otherwise validate session first
        if ($this->validateSession() === null) {
            return null;
        }

        return $this->cachedPayload['auth_time'] ?? null;
    }

    /**
     * Get the session expiration time
     *
     * @return int|null Unix timestamp of expiration, or null if no valid session
     */
    public function getExpirationTime(): ?int
    {
        if ($this->cachedPayload !== null) {
            return $this->cachedPayload['exp'] ?? null;
        }

        if ($this->validateSession() === null) {
            return null;
        }

        return $this->cachedPayload['exp'] ?? null;
    }

    /**
     * Encode payload as JWT signed with RSA key
     *
     * @param array $payload JWT payload
     * @return string Signed JWT
     */
    private function encodeJwt(array $payload): string
    {
        // Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => JwtKeyHelper::getRsaKeyId(),
        ];

        $headerEncoded = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $payloadEncoded = JwtKeyHelper::base64UrlEncode(json_encode($payload));

        $dataToSign = $headerEncoded . '.' . $payloadEncoded;

        // Sign with RSA private key
        $privateKey = JwtKeyHelper::getRsaPrivateKey();
        $signature = '';

        $success = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new \RuntimeException('Failed to sign JWT: ' . openssl_error_string());
        }

        $signatureEncoded = JwtKeyHelper::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Decode and verify JWT
     *
     * @param string $jwt The JWT string
     * @return array|null Decoded payload or null if invalid
     */
    private function decodeJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            dol_syslog('SmartAuth SessionManager: Invalid JWT format', LOG_DEBUG);
            return null;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Decode header
        $headerJson = JwtKeyHelper::base64UrlDecode($headerEncoded);
        $header = json_decode($headerJson, true);
        if (!$header || ($header['alg'] ?? '') !== 'RS256') {
            dol_syslog('SmartAuth SessionManager: Invalid JWT algorithm', LOG_DEBUG);
            return null;
        }

        // Verify signature with RSA public key
        $publicKey = JwtKeyHelper::getRsaPublicKey();
        $dataToVerify = $headerEncoded . '.' . $payloadEncoded;
        $signature = JwtKeyHelper::base64UrlDecode($signatureEncoded);

        $verified = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            dol_syslog('SmartAuth SessionManager: JWT signature verification failed', LOG_DEBUG);
            return null;
        }

        // Decode payload
        $payloadJson = JwtKeyHelper::base64UrlDecode($payloadEncoded);
        $payload = json_decode($payloadJson, true);
        if (!$payload) {
            dol_syslog('SmartAuth SessionManager: Invalid JWT payload', LOG_DEBUG);
            return null;
        }

        return $payload;
    }

    /**
     * Check if a user exists and is active
     *
     * @param int $userId User ID to check
     * @return bool True if user exists and is active
     */
    private function isUserActive(int $userId): bool
    {
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

        $user = new \User($this->db);
        $result = $user->fetch($userId);

        if ($result <= 0) {
            return false;
        }

        // Check user status (statut = 1 means active)
        return ($user->statut == 1);
    }

    /**
     * Get the cookie domain
     *
     * @return string Domain for cookie
     */
    private function getCookieDomain(): string
    {
        return OAuthConfig::getCookieDomain();
    }

    /**
     * Determine if we're in a secure context (HTTPS)
     *
     * @return bool True if HTTPS
     */
    private function isSecureContext(): bool
    {
        // Direct HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Behind reverse proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Check port
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }

        return false;
    }
}
