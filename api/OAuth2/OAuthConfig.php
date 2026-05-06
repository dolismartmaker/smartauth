<?php

/**
 * OAuthConfig.php
 *
 * Configuration helper for OAuth2/OIDC server functionality.
 * Provides centralized access to all OAuth-related settings.
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

class OAuthConfig
{
    /**
     * Default token lifetimes in seconds
     */
    const DEFAULT_ACCESS_TOKEN_TTL = 3600;        // 1 hour
    const DEFAULT_REFRESH_TOKEN_TTL = 2592000;    // 30 days
    const DEFAULT_CODE_TTL = 600;                 // 10 minutes
    const DEFAULT_SESSION_TTL = 86400;            // 24 hours
    const DEFAULT_REGISTER_TOKEN_TTL = 86400;     // 24 hours - email validation links
    const DEFAULT_REGISTER_RESEND_COOLDOWN = 300; // 5 minutes between resends
    const DEFAULT_REGISTER_RATE_LIMIT = 1;        // registrations per hour per IP

    /**
     * Supported scopes
     */
    const SUPPORTED_SCOPES = [
        'openid',
        'profile',
        'email',
        'groups',
        'roles',
        'offline_access'
    ];

    /**
     * Supported response types
     */
    const SUPPORTED_RESPONSE_TYPES = ['code'];

    /**
     * Supported grant types
     */
    const SUPPORTED_GRANT_TYPES = [
        'authorization_code',
        'refresh_token',
        'client_credentials'
    ];

    /**
     * Supported token endpoint auth methods
     */
    const TOKEN_ENDPOINT_AUTH_METHODS = [
        'client_secret_post',
        'client_secret_basic',
        'none'
    ];

    /**
     * Supported code challenge methods for PKCE.
     * Only S256 is supported - the legacy 'plain' method offers no real
     * protection against code interception (RFC 7636 acknowledges this;
     * OAuth 2.1 / Security BCP §2.1.1 require S256).
     */
    const CODE_CHALLENGE_METHODS = ['S256'];

    /**
     * Supported claims
     */
    const SUPPORTED_CLAIMS = [
        'sub',
        'iss',
        'aud',
        'exp',
        'iat',
        'auth_time',
        'name',
        'family_name',
        'given_name',
        'email',
        'email_verified',
        'groups',
        'roles'
    ];

    /**
     * Check if OAuth2/OIDC server is enabled
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool) getDolGlobalInt('SMARTAUTH_OAUTH_ENABLED', 0);
    }

    /**
     * Get the issuer URL for OIDC
     *
     * Priority:
     * 1. Explicit configuration SMARTAUTH_OAUTH_ISSUER
     * 2. Auto-detection from current request
     *
     * @return string The issuer URL (without trailing slash)
     */
    public static function getIssuer(): string
    {
        $configured = getDolGlobalString('SMARTAUTH_OAUTH_ISSUER', '');

        if (!empty($configured)) {
            return rtrim($configured, '/');
        }

        // Auto-detect from request
        return self::detectIssuerFromRequest();
    }

    /**
     * Auto-detect issuer URL from the current HTTP request.
     *
     * Hardened against Host-header injection:
     *   - SERVER_NAME (web-server config, not client-controlled) is preferred
     *     over HTTP_HOST.
     *   - When SMARTAUTH_ISSUER_ALLOWED_HOSTS is configured (CSV), HTTP_HOST
     *     is honoured only if the value is in the allow-list.
     *   - X-Forwarded-Proto is honoured only when SERVER_NAME isn't set
     *     and the immediate REMOTE_ADDR is private/loopback (typical
     *     reverse-proxy setup).
     *   - When neither SMARTAUTH_OAUTH_ISSUER nor an allow-list is
     *     configured, a warning is logged so the operator is reminded
     *     to harden the deployment.
     *
     * @return string
     */
    private static function detectIssuerFromRequest(): string
    {
        // Determine protocol
        $protocol = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        } elseif (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            $protocol = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
            $isLocalProxy = (
                $remoteAddr === ''
                || preg_match('/^127\./', $remoteAddr)
                || preg_match('/^10\./', $remoteAddr)
                || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $remoteAddr)
                || preg_match('/^192\.168\./', $remoteAddr)
                || $remoteAddr === '::1'
            );
            if ($isLocalProxy && in_array(strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']), ['http', 'https'], true)) {
                $protocol = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
            }
        }

        // Resolve host
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';

        $allowedHostsRaw = getDolGlobalString('SMARTAUTH_ISSUER_ALLOWED_HOSTS', '');
        $allowedHosts = array_filter(array_map('trim', explode(',', $allowedHostsRaw)));

        if (!empty($allowedHosts)) {
            $allowedLower = array_map('strtolower', $allowedHosts);
            // Prefer SERVER_NAME if it's in the allow-list, else fall back to
            // a HTTP_HOST that's also allowed, else the first allowed entry.
            if ($serverName !== '' && in_array(strtolower($serverName), $allowedLower, true)) {
                $host = $serverName;
            } elseif ($httpHost !== '' && in_array(strtolower($httpHost), $allowedLower, true)) {
                $host = $httpHost;
            } else {
                $host = $allowedHosts[0];
                dol_syslog(
                    'SmartAuth OAuthConfig: Host header "' . $httpHost . '" not in SMARTAUTH_ISSUER_ALLOWED_HOSTS - using "' . $host . '" instead',
                    LOG_WARNING
                );
            }
        } else {
            // Back-compat path: prefer SERVER_NAME (set by web server config),
            // fall back to HTTP_HOST with a warning.
            if ($serverName !== '') {
                $host = $serverName;
            } elseif ($httpHost !== '') {
                dol_syslog(
                    'SmartAuth OAuthConfig: deriving issuer from HTTP_HOST - set SMARTAUTH_OAUTH_ISSUER or SMARTAUTH_ISSUER_ALLOWED_HOSTS to harden against Host header injection',
                    LOG_WARNING
                );
                $host = $httpHost;
            } else {
                $host = 'localhost';
            }
        }

        return $protocol . '://' . $host;
    }

    /**
     * Get access token lifetime in seconds
     *
     * @return int
     */
    public static function getAccessTokenTTL(): int
    {
        return getDolGlobalInt('SMARTAUTH_OAUTH_ACCESS_TTL', self::DEFAULT_ACCESS_TOKEN_TTL);
    }

    /**
     * Get refresh token lifetime in seconds
     *
     * @return int
     */
    public static function getRefreshTokenTTL(): int
    {
        return getDolGlobalInt('SMARTAUTH_OAUTH_REFRESH_TTL', self::DEFAULT_REFRESH_TOKEN_TTL);
    }

    /**
     * Get authorization code lifetime in seconds
     *
     * @return int
     */
    public static function getCodeTTL(): int
    {
        return getDolGlobalInt('SMARTAUTH_OAUTH_CODE_TTL', self::DEFAULT_CODE_TTL);
    }

    /**
     * Get session cookie lifetime in seconds
     *
     * @return int
     */
    public static function getSessionTTL(): int
    {
        return getDolGlobalInt('SMARTAUTH_OAUTH_SESSION_TTL', self::DEFAULT_SESSION_TTL);
    }

    /**
     * Get email validation token lifetime in seconds (registration / email change).
     *
     * @return int
     */
    public static function getRegisterTokenTTL(): int
    {
        return getDolGlobalInt('SMARTAUTH_REGISTER_TOKEN_TTL', self::DEFAULT_REGISTER_TOKEN_TTL);
    }

    /**
     * Minimum number of seconds between two confirmation-email resends.
     *
     * @return int
     */
    public static function getRegisterResendCooldown(): int
    {
        return getDolGlobalInt('SMARTAUTH_REGISTER_RESEND_COOLDOWN', self::DEFAULT_REGISTER_RESEND_COOLDOWN);
    }

    /**
     * Maximum number of /register attempts allowed per source IP per hour.
     *
     * @return int
     */
    public static function getRegisterRateLimit(): int
    {
        return getDolGlobalInt('SMARTAUTH_REGISTER_RATE_LIMIT', self::DEFAULT_REGISTER_RATE_LIMIT);
    }

    /**
     * Check if PKCE is required for public clients
     *
     * @return bool
     */
    public static function requirePkce(): bool
    {
        return (bool) getDolGlobalInt('SMARTAUTH_OAUTH_REQUIRE_PKCE', 1);
    }

    /**
     * Check if user consents should be remembered
     *
     * @return bool
     */
    public static function rememberConsent(): bool
    {
        return (bool) getDolGlobalInt('SMARTAUTH_OAUTH_CONSENT_REMEMBER', 1);
    }

    /**
     * Check if OAuth is in bypass mode (maintenance)
     *
     * @return bool
     */
    public static function isBypassMode(): bool
    {
        return (bool) getDolGlobalInt('SMARTAUTH_OAUTH_BYPASS', 0);
    }

    /**
     * Get list of user IDs allowed for fallback authentication
     *
     * @return array
     */
    public static function getFallbackUsers(): array
    {
        $userList = getDolGlobalString('SMARTAUTH_FALLBACK_USERS', '');
        if (empty($userList)) {
            return [];
        }

        $users = explode(',', $userList);
        return array_map('intval', array_filter(array_map('trim', $users)));
    }

    /**
     * Get the cookie domain for session cookies
     *
     * @return string
     */
    public static function getCookieDomain(): string
    {
        $domain = getDolGlobalString('SMARTAUTH_OAUTH_COOKIE_DOMAIN', '');
        if (!empty($domain)) {
            return $domain;
        }

        // Extract domain from issuer or use current host
        $issuer = self::getIssuer();
        $parsed = parse_url($issuer);

        return $parsed['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /**
     * Get authorization endpoint URL
     *
     * @return string
     */
    public static function getAuthorizationEndpoint(): string
    {
        return self::getIssuer() . '/oauth/authorize';
    }

    /**
     * Get token endpoint URL
     *
     * @return string
     */
    public static function getTokenEndpoint(): string
    {
        return self::getIssuer() . '/oauth/token';
    }

    /**
     * Get userinfo endpoint URL
     *
     * @return string
     */
    public static function getUserinfoEndpoint(): string
    {
        return self::getIssuer() . '/oauth/userinfo';
    }

    /**
     * Get revocation endpoint URL
     *
     * @return string
     */
    public static function getRevocationEndpoint(): string
    {
        return self::getIssuer() . '/oauth/revoke';
    }

    /**
     * Get JWKS endpoint URL
     *
     * @return string
     */
    public static function getJwksUri(): string
    {
        return self::getIssuer() . '/.well-known/jwks.json';
    }

    /**
     * Get end session (logout) endpoint URL
     *
     * @return string
     */
    public static function getEndSessionEndpoint(): string
    {
        return self::getIssuer() . '/oauth/logout';
    }

    /**
     * Get supported scopes
     *
     * @return array
     */
    public static function getSupportedScopes(): array
    {
        return self::SUPPORTED_SCOPES;
    }

    /**
     * Get supported response types
     *
     * @return array
     */
    public static function getSupportedResponseTypes(): array
    {
        return self::SUPPORTED_RESPONSE_TYPES;
    }

    /**
     * Get supported grant types
     *
     * @return array
     */
    public static function getSupportedGrantTypes(): array
    {
        return self::SUPPORTED_GRANT_TYPES;
    }

    /**
     * Get token endpoint authentication methods
     *
     * @return array
     */
    public static function getTokenEndpointAuthMethods(): array
    {
        return self::TOKEN_ENDPOINT_AUTH_METHODS;
    }

    /**
     * Get supported code challenge methods
     *
     * @return array
     */
    public static function getCodeChallengeMethods(): array
    {
        return self::CODE_CHALLENGE_METHODS;
    }

    /**
     * Get supported claims
     *
     * @return array
     */
    public static function getSupportedClaims(): array
    {
        return self::SUPPORTED_CLAIMS;
    }

    /**
     * Get the full OpenID Configuration as array
     *
     * @return array
     */
    public static function getOpenIdConfiguration(): array
    {
        $issuer = self::getIssuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => self::getAuthorizationEndpoint(),
            'token_endpoint' => self::getTokenEndpoint(),
            'userinfo_endpoint' => self::getUserinfoEndpoint(),
            'revocation_endpoint' => self::getRevocationEndpoint(),
            'jwks_uri' => self::getJwksUri(),
            'end_session_endpoint' => self::getEndSessionEndpoint(),
            'scopes_supported' => self::getSupportedScopes(),
            'response_types_supported' => self::getSupportedResponseTypes(),
            'grant_types_supported' => self::getSupportedGrantTypes(),
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => self::getTokenEndpointAuthMethods(),
            'code_challenge_methods_supported' => self::getCodeChallengeMethods(),
            'claims_supported' => self::getSupportedClaims()
        ];
    }
}
