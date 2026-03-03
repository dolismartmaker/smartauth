<?php

/**
 * ScopeManager.php
 *
 * OAuth2/OIDC scope management for SmartAuth.
 * Handles scope validation, parsing, formatting, and descriptions.
 *
 * Supported scopes:
 * - openid: Required for OIDC, returns sub claim
 * - profile: User profile information (name, family_name, given_name)
 * - email: User email address
 * - groups: Dolibarr user groups
 * - roles: Derived roles from permissions
 * - offline_access: Allows refresh token
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

class ScopeManager
{
    /**
     * Runtime registry for custom scopes from external modules
     * @var array
     */
    private static $customScopes = [];

    /**
     * Whether custom scopes have been loaded from Dolibarr config
     * @var bool
     */
    private static $customScopesLoaded = false;

    /**
     * Scope definitions with descriptions (French)
     */
    const SCOPE_DEFINITIONS = [
        'openid' => [
            'description' => 'Acceder a votre identifiant unique',
            'description_long' => 'Permet a l\'application de vous identifier de maniere unique.',
            'claims' => ['sub'],
            'required_for_oidc' => true,
        ],
        'profile' => [
            'description' => 'Acceder a vos informations de profil',
            'description_long' => 'Nom, prenom et date de mise a jour du profil.',
            'claims' => ['name', 'family_name', 'given_name', 'updated_at'],
            'required_for_oidc' => false,
        ],
        'email' => [
            'description' => 'Acceder a votre adresse email',
            'description_long' => 'Votre adresse email et son statut de verification.',
            'claims' => ['email', 'email_verified'],
            'required_for_oidc' => false,
        ],
        'groups' => [
            'description' => 'Acceder a vos groupes',
            'description_long' => 'Liste des groupes Dolibarr auxquels vous appartenez.',
            'claims' => ['groups'],
            'required_for_oidc' => false,
        ],
        'roles' => [
            'description' => 'Acceder a vos roles',
            'description_long' => 'Roles deduits de vos permissions Dolibarr.',
            'claims' => ['roles'],
            'required_for_oidc' => false,
        ],
        'offline_access' => [
            'description' => 'Acces hors ligne',
            'description_long' => 'Permet a l\'application d\'acceder a vos donnees meme lorsque vous n\'etes pas connecte.',
            'claims' => [],
            'required_for_oidc' => false,
        ],
    ];

    /**
     * Get the description of a scope
     *
     * @param string $scope The scope name
     * @param bool $long If true, return long description
     * @return string The description or scope name if not found
     */
    public static function getDescription(string $scope, bool $long = false): string
    {
        $allDefs = self::getAllScopeDefinitions();
        if (!isset($allDefs[$scope])) {
            return $scope;
        }

        $key = $long ? 'description_long' : 'description';
        return $allDefs[$scope][$key] ?? $scope;
    }

    /**
     * Get descriptions for multiple scopes
     *
     * @param array $scopes Array of scope names
     * @param bool $long If true, return long descriptions
     * @return array Associative array of scope => description
     */
    public static function getDescriptions(array $scopes, bool $long = false): array
    {
        $descriptions = [];
        foreach ($scopes as $scope) {
            $descriptions[$scope] = self::getDescription($scope, $long);
        }
        return $descriptions;
    }

    /**
     * Check if a scope is valid (known by the system)
     *
     * @param string $scope The scope to check
     * @return bool True if valid
     */
    public static function isValidScope(string $scope): bool
    {
        $allDefs = self::getAllScopeDefinitions();
        return isset($allDefs[$scope]);
    }

    /**
     * Validate multiple scopes
     *
     * @param array $scopes Array of scopes to validate
     * @return array Array of invalid scopes (empty if all valid)
     */
    public static function validateScopes(array $scopes): array
    {
        $invalid = [];
        foreach ($scopes as $scope) {
            if (!self::isValidScope($scope)) {
                $invalid[] = $scope;
            }
        }
        return $invalid;
    }

    /**
     * Parse a space-separated scope string into array
     *
     * @param string $scopeString Space-separated scopes
     * @return array Array of unique scopes
     */
    public static function parseScopes(string $scopeString): array
    {
        if (empty(trim($scopeString))) {
            return [];
        }

        $scopes = preg_split('/\s+/', trim($scopeString));
        return array_values(array_unique(array_filter($scopes)));
    }

    /**
     * Format scopes array as space-separated string
     *
     * @param array $scopes Array of scopes
     * @return string Space-separated scope string
     */
    public static function formatScopes(array $scopes): string
    {
        return implode(' ', array_unique(array_filter($scopes)));
    }

    /**
     * Check if scopes require OpenID Connect
     *
     * @param array $scopes Array of scopes
     * @return bool True if 'openid' scope is present
     */
    public static function requiresOpenId(array $scopes): bool
    {
        return in_array('openid', $scopes, true);
    }

    /**
     * Check if scopes request offline access (refresh token)
     *
     * @param array $scopes Array of scopes
     * @return bool True if 'offline_access' scope is present
     */
    public static function requiresOfflineAccess(array $scopes): bool
    {
        return in_array('offline_access', $scopes, true);
    }

    /**
     * Get claims associated with scopes
     *
     * @param array $scopes Array of scopes
     * @return array Array of claim names
     */
    public static function getClaims(array $scopes): array
    {
        $allDefs = self::getAllScopeDefinitions();
        $claims = [];
        foreach ($scopes as $scope) {
            if (isset($allDefs[$scope]['claims'])) {
                $claims = array_merge($claims, $allDefs[$scope]['claims']);
            }
        }
        return array_values(array_unique($claims));
    }

    /**
     * Get all supported scopes
     *
     * @return array Array of all supported scope names
     */
    public static function getSupportedScopes(): array
    {
        return array_keys(self::getAllScopeDefinitions());
    }

    /**
     * Filter scopes to only include valid ones
     *
     * @param array $scopes Array of requested scopes
     * @return array Array of valid scopes only
     */
    public static function filterValidScopes(array $scopes): array
    {
        return array_values(array_filter($scopes, [self::class, 'isValidScope']));
    }

    /**
     * Filter scopes to only include those allowed by client
     *
     * @param array $requestedScopes Scopes requested by authorization
     * @param array $allowedScopes Scopes allowed for the client
     * @return array Intersection of requested and allowed scopes
     */
    public static function filterAllowedScopes(array $requestedScopes, array $allowedScopes): array
    {
        return array_values(array_intersect($requestedScopes, $allowedScopes));
    }

    /**
     * Check if all requested scopes are allowed
     *
     * @param array $requestedScopes Scopes requested
     * @param array $allowedScopes Scopes allowed
     * @return bool True if all requested scopes are allowed
     */
    public static function areAllScopesAllowed(array $requestedScopes, array $allowedScopes): bool
    {
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $allowedScopes, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get scopes that are not allowed
     *
     * @param array $requestedScopes Scopes requested
     * @param array $allowedScopes Scopes allowed
     * @return array Array of disallowed scopes
     */
    public static function getDisallowedScopes(array $requestedScopes, array $allowedScopes): array
    {
        return array_values(array_diff($requestedScopes, $allowedScopes));
    }

    /**
     * Normalize scopes (lowercase, unique, sorted)
     *
     * @param array $scopes Array of scopes
     * @return array Normalized array of scopes
     */
    public static function normalizeScopes(array $scopes): array
    {
        $normalized = array_map('strtolower', $scopes);
        $normalized = array_unique($normalized);
        sort($normalized);
        return array_values($normalized);
    }

    /**
     * Get scope information for consent page display
     *
     * @param array $scopes Array of scopes
     * @return array Array of scope info for display
     */
    public static function getScopeInfoForConsent(array $scopes): array
    {
        $allDefs = self::getAllScopeDefinitions();
        $info = [];
        foreach ($scopes as $scope) {
            if (isset($allDefs[$scope])) {
                $def = $allDefs[$scope];
                $info[] = [
                    'scope' => $scope,
                    'description' => $def['description'],
                    'description_long' => $def['description_long'],
                    'claims' => $def['claims'],
                ];
            } else {
                $info[] = [
                    'scope' => $scope,
                    'description' => $scope,
                    'description_long' => 'Scope personnalise',
                    'claims' => [],
                ];
            }
        }
        return $info;
    }

    /**
     * Register a custom scope from an external module
     *
     * Called by modules during bootstrap or init to register their scopes.
     * Custom scopes are treated identically to built-in scopes.
     *
     * @param string $scope         Scope identifier (e.g., 'externalprospect:write')
     * @param string $description   Short description
     * @param string $descriptionLong Long description
     * @return void
     */
    public static function registerScope(string $scope, string $description, string $descriptionLong = ''): void
    {
        self::$customScopes[$scope] = [
            'description' => $description,
            'description_long' => $descriptionLong ?: $description,
            'claims' => [],
            'required_for_oidc' => false,
        ];
    }

    /**
     * Get all scope definitions (built-in + custom)
     *
     * @return array
     */
    public static function getAllScopeDefinitions(): array
    {
        self::loadCustomScopesFromConfig();
        return array_merge(self::SCOPE_DEFINITIONS, self::$customScopes);
    }

    /**
     * Load custom scopes from Dolibarr configuration
     *
     * Modules register their scopes via SMARTAUTH_CUSTOM_SCOPES constant.
     * Format: JSON object {"scope_name": {"description": "...", "description_long": "..."}}
     *
     * @return void
     */
    public static function loadCustomScopesFromConfig(): void
    {
        if (self::$customScopesLoaded) {
            return;
        }
        self::$customScopesLoaded = true;

        if (!function_exists('getDolGlobalString')) {
            return;
        }

        $json = getDolGlobalString('SMARTAUTH_CUSTOM_SCOPES', '');
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $scope => $info) {
                    if (!isset(self::$customScopes[$scope])) {
                        self::registerScope(
                            $scope,
                            $info['description'] ?? $scope,
                            $info['description_long'] ?? ''
                        );
                    }
                }
            }
        }
    }

    /**
     * Reset custom scopes registry (for testing purposes)
     *
     * @return void
     */
    public static function resetCustomScopes(): void
    {
        self::$customScopes = [];
        self::$customScopesLoaded = false;
    }
}
