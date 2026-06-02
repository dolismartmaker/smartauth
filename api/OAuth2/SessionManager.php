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

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');

class SessionManager
{
    /**
     * Cookie name for SmartAuth session
     */
    /**
     * Default session cookie name. The "__Host-" prefix is preferred when
     * the runtime can satisfy the browser's contract (https + path=/ +
     * no Domain attribute). resolveCookieName() applies that prefix
     * conditionally so dev environments served over plain HTTP still work
     *.
     */
    const COOKIE_NAME_PLAIN = 'smartauth_session';
    const COOKIE_NAME_HOST = '__Host-smartauth_session';
    const COOKIE_NAME = self::COOKIE_NAME_PLAIN;

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
     * Validate current session and return the authenticated subject.
     *
     * Checks:
     * 1. Cookie exists and is not empty
     * 2. JWT signature is valid
     * 3. JWT is not expired
     * 4. The `sub` claim is a well-formed prefixed subject (acc:/usr:)
     * 5. The underlying record (account or user) exists and is active
     *
     * A legacy numeric `sub` (pre-cutover) fails step 4 and is treated as an
     * invalid session -> the cookie is cleared and the user must sign in again
     * (SPEC_SMARTAUTH_SUBJECT.md decision 2).
     *
     * @return TokenSubject|null The subject if the session is valid, null otherwise
     */
    public function validateSession(): ?TokenSubject
    {
        // Check if cookie exists
        // Read either the "__Host-" prefixed cookie (https) or the plain
        // one (legacy / dev). resolveCookieName() picks which one we'd
        // emit; reading both lets us survive a switch from http->https.
        $cookie = $_COOKIE[self::COOKIE_NAME_HOST]
            ?? $_COOKIE[self::COOKIE_NAME_PLAIN]
            ?? null;
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

            // Parse and validate the subject (prefixed sub claim).
            $sub = (string) ($payload['sub'] ?? '');
            try {
                $subject = TokenSubject::fromSub($sub);
            } catch (\InvalidArgumentException $e) {
                dol_syslog('SmartAuth SessionManager: invalid/legacy sub claim (' . $sub . '), forcing re-login', LOG_DEBUG);
                $this->clearSession();
                return null;
            }

            if (!$subject->isActive($this->db)) {
                dol_syslog('SmartAuth SessionManager: subject ' . $sub . ' not found or inactive', LOG_DEBUG);
                $this->clearSession();
                return null;
            }

            // Cache payload for getAuthTime()
            $this->cachedPayload = $payload;

            return $subject;
        } catch (\Exception $e) {
            dol_syslog('SmartAuth SessionManager: Session validation error: ' . $e->getMessage(), LOG_WARNING);
            $this->clearSession();
            return null;
        }
    }

    /**
     * Create a new session for a subject (account or user).
     *
     * Generates a signed JWT cookie whose `sub` is the prefixed subject id.
     *
     * @param TokenSubject $subject Authenticated subject
     * @return void
     */
    public function createSession(TokenSubject $subject): void
    {
        $now = time();
        $ttl = OAuthConfig::getSessionTTL();

        $payload = [
            'iss' => OAuthConfig::getIssuer(),
            'sub' => $subject->toSub(),
            'iat' => $now,
            'exp' => $now + $ttl,
            'auth_time' => $now,
        ];

        $jwt = $this->encodeJwt($payload);

        // Determine if we should use Secure flag
        $secure = $this->isSecureContext();
        $cookieName = $this->resolveCookieName();
        // __Host- requires no Domain attribute; otherwise honour configured domain.
        $domain = $cookieName === self::COOKIE_NAME_HOST ? '' : $this->getCookieDomain();

        $cookieOptions = [
            'expires' => $now + $ttl,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($cookieName, $jwt, $cookieOptions);

        // Cache the payload
        $this->cachedPayload = $payload;

        dol_syslog('SmartAuth SessionManager: Session created for subject ' . $subject->toSub(), LOG_INFO);
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

        // Clear both possible cookie names so a logout works regardless of
        // whether the session was originally created in HTTPS / __Host- mode.
        foreach ([self::COOKIE_NAME_HOST, self::COOKIE_NAME_PLAIN] as $name) {
            $domain = $name === self::COOKIE_NAME_HOST ? '' : $this->getCookieDomain();
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // Clear cached payload
        $this->cachedPayload = null;

        // Also unset from current request
        unset($_COOKIE[self::COOKIE_NAME_HOST], $_COOKIE[self::COOKIE_NAME_PLAIN]);
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
     * Get the cookie domain
     *
     * @return string Domain for cookie
     */
    private function getCookieDomain(): string
    {
        return OAuthConfig::getCookieDomain();
    }

    /**
     * Determine if we're in a secure context (HTTPS).
     *
     * X-Forwarded-Proto is honoured only when the immediate REMOTE_ADDR
     * is in a private/loopback range or in SMARTAUTH_TRUSTED_PROXIES
     * - otherwise an attacker on the public
     * Internet could spoof the header to make the server believe
     * "secure" and skip the Secure attribute.
     *
     * @return bool True if HTTPS
     */
    private function isSecureContext(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            $trustedRaw = function_exists('getDolGlobalString')
                ? (string) getDolGlobalString('SMARTAUTH_TRUSTED_PROXIES', '')
                : '';
            $trustedList = array_filter(array_map('trim', explode(',', $trustedRaw)));
            $isPrivate = ($remote === ''
                || preg_match('/^127\./', $remote)
                || preg_match('/^10\./', $remote)
                || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $remote)
                || preg_match('/^192\.168\./', $remote)
                || $remote === '::1');
            if ($isPrivate || in_array($remote, $trustedList, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Choose the actual cookie name. Use the "__Host-" prefix when the
     * browser will accept it (https + no explicit domain).
     */
    private function resolveCookieName(): string
    {
        if (!$this->isSecureContext()) {
            return self::COOKIE_NAME_PLAIN;
        }
        $domain = $this->getCookieDomain();
        if ($domain !== '' && $domain !== null) {
            // __Host- requires Domain attribute to be omitted
            return self::COOKIE_NAME_PLAIN;
        }
        return self::COOKIE_NAME_HOST;
    }
}
