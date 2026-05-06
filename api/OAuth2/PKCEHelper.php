<?php

/**
 * PKCEHelper.php
 *
 * PKCE (Proof Key for Code Exchange) helper for OAuth2 authorization.
 * Implements RFC 7636 for public clients protection.
 *
 * Supports:
 * - S256: SHA-256 hash (recommended)
 * - plain: Plain text (not recommended, but required by spec)
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

class PKCEHelper
{
    /**
     * Supported code challenge methods
     */
    const METHOD_S256 = 'S256';
    const METHOD_PLAIN = 'plain';

    /**
     * Minimum length for code verifier (RFC 7636)
     */
    const VERIFIER_MIN_LENGTH = 43;

    /**
     * Maximum length for code verifier (RFC 7636)
     */
    const VERIFIER_MAX_LENGTH = 128;

    /**
     * Validate a code verifier against a stored challenge
     *
     * @param string $verifier The code_verifier from token request
     * @param string $challenge The code_challenge stored during authorization
     * @param string $method The code_challenge_method (S256 or plain)
     * @return bool True if the verifier matches the challenge
     */
    public static function validate(string $verifier, string $challenge, string $method): bool
    {
        if (empty($verifier) || empty($challenge)) {
            return false;
        }

        // Validate verifier format
        if (!self::isValidVerifier($verifier)) {
            return false;
        }

        // Only S256 is accepted (RFC 7636 + OAuth 2.1 / Security BCP §2.1.1).
        // The 'plain' method is rejected even if a legacy auth code carries it,
        // because the resulting verifier == challenge is just a bearer secret.
        if ($method !== self::METHOD_S256) {
            return false;
        }

        $expectedChallenge = self::generateChallenge($verifier, self::METHOD_S256);
        return hash_equals($challenge, $expectedChallenge);
    }

    /**
     * Generate a code challenge from a verifier
     *
     * @param string $verifier The code verifier
     * @param string $method The challenge method (S256 or plain)
     * @return string The code challenge
     */
    public static function generateChallenge(string $verifier, string $method = self::METHOD_S256): string
    {
        if ($method === self::METHOD_S256) {
            $hash = hash('sha256', $verifier, true);
            return self::base64UrlEncode($hash);
        }

        if ($method === self::METHOD_PLAIN) {
            return $verifier;
        }

        throw new \InvalidArgumentException('Unsupported code challenge method: ' . $method);
    }

    /**
     * Generate a random code verifier
     *
     * Generates a cryptographically secure random string suitable
     * for use as a PKCE code verifier.
     *
     * @param int $length Length of the verifier (43-128, default 64)
     * @return string The code verifier
     */
    public static function generateVerifier(int $length = 64): string
    {
        if ($length < self::VERIFIER_MIN_LENGTH || $length > self::VERIFIER_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Verifier length must be between ' . self::VERIFIER_MIN_LENGTH .
                ' and ' . self::VERIFIER_MAX_LENGTH
            );
        }

        // Generate random bytes and encode to URL-safe base64
        $bytes = random_bytes((int) ceil($length * 0.75));
        $verifier = self::base64UrlEncode($bytes);

        // Trim to exact length
        return substr($verifier, 0, $length);
    }

    /**
     * Check if a code verifier has valid format
     *
     * RFC 7636: code_verifier = [A-Za-z0-9-._~]{43,128}
     *
     * @param string $verifier The verifier to validate
     * @return bool True if valid format
     */
    public static function isValidVerifier(string $verifier): bool
    {
        $length = strlen($verifier);

        if ($length < self::VERIFIER_MIN_LENGTH || $length > self::VERIFIER_MAX_LENGTH) {
            return false;
        }

        // RFC 7636: unreserved characters only
        // A-Z, a-z, 0-9, hyphen, period, underscore, tilde
        return preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1;
    }

    /**
     * Check if a code challenge method is supported.
     *
     * Only S256 is supported. The legacy 'plain' method is rejected here
     * because, with no hashing, knowing the challenge is equivalent to
     * knowing the verifier, which voids the protection PKCE is supposed
     * to provide (RFC 7636 acknowledges this; OAuth 2.1 mandates S256).
     *
     * @param string $method The method to check
     * @return bool True if supported
     */
    public static function isValidMethod(string $method): bool
    {
        return $method === self::METHOD_S256;
    }

    /**
     * Check if a code challenge has valid format
     *
     * @param string $challenge The challenge to validate
     * @param string $method The challenge method
     * @return bool True if valid format
     */
    public static function isValidChallenge(string $challenge, string $method): bool
    {
        if (empty($challenge)) {
            return false;
        }

        // Only S256 is supported; see isValidMethod().
        if ($method !== self::METHOD_S256) {
            return false;
        }

        // S256 challenge is base64url-encoded SHA256 hash (43 chars)
        if (strlen($challenge) !== 43) {
            return false;
        }
        // Must be valid base64url
        return preg_match('/^[A-Za-z0-9\-_]+$/', $challenge) === 1;
    }

    /**
     * Base64 URL-safe encoding (without padding)
     *
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
