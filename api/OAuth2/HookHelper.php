<?php

/**
 * HookHelper.php
 *
 * Centralized invocation of SmartMaker OAuth2 hooks.
 *
 * Provides a uniform API for SmartAuth controllers to call the
 * smartmaker_oauth_* hooks regardless of whether the Dolibarr
 * hookmanager is available. When no hookmanager is registered
 * (e.g. unit tests), invocations are no-ops returning ['blocked' => false].
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

class HookHelper
{
    /**
     * Claims that SmartAuth itself controls when minting an access/id_token
     * via TokenService::createAccessToken() / addUserClaims(). A hook that
     * tries to write these via resArray['extra_claims'] is dropped (with
     * a warning log) so a misbehaving module cannot forge identity claims,
     * extend token lifetime or shadow OIDC profile fields. PERFS.md §3.3.
     */
    private const RESERVED_TOKEN_CLAIMS = [
        // Identity / lifetime / audience
        'iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'auth_time',
        // OAuth2 / OIDC standard token claims emitted by SmartAuth
        'scope', 'client_id', 'grant_type', 'token_type',
        'at_hash', 'nonce',
        // OIDC profile / email / groups (controlled by addUserClaims)
        'name', 'family_name', 'given_name', 'updated_at',
        'email', 'email_verified',
        'groups', 'roles',
    ];

    /**
     * Run a blocking hook (pre_authorize / pre_token).
     *
     * Convention:
     *  - return value 1 from any module = block
     *  - module sets resArray['error'] and resArray['error_description']
     *  - return value < 0 = internal error, treated as 500/server_error
     *
     * When the hook returns 0 (allow), the helper also harvests
     * resArray['extra_claims'] (PERFS.md §3.3): a sanitized associative
     * array of additional claims to merge into the JWT payload before
     * signature. Reserved claims and invalid value types are dropped with
     * a warning log so a misbehaving module cannot forge identity claims
     * or smuggle nested objects.
     *
     * @param string                       $hookName    Hook name (e.g. smartmaker_oauth_pre_authorize)
     * @param array                        $parameters  Hook parameters
     * @param \SmartAuthOAuthClient|null   $client      Optional client object passed as $object
     * @return array{blocked:bool, error:?string, error_description:?string, internal_error:bool, extra_claims:array}
     */
    public static function runBlockingHook(string $hookName, array $parameters, $client = null): array
    {
        global $hookmanager;

        $result = [
            'blocked' => false,
            'error' => null,
            'error_description' => null,
            'internal_error' => false,
            'extra_claims' => [],
        ];

        if (!is_object($hookmanager) || !method_exists($hookmanager, 'executeHooks')) {
            return $result;
        }

        if (method_exists($hookmanager, 'initHooks')) {
            $hookmanager->initHooks(['smartmaker']);
        }

        // Reset resArray to avoid cross-call leakage
        if (property_exists($hookmanager, 'resArray')) {
            $hookmanager->resArray = [];
        }

        $action = self::stripPrefix($hookName);
        $object = $client;

        $reshook = $hookmanager->executeHooks($hookName, $parameters, $object, $action);

        if ($reshook < 0) {
            $result['internal_error'] = true;
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' returned internal error (' . $reshook . ')', LOG_ERR);
            return $result;
        }

        $resArray = property_exists($hookmanager, 'resArray') && is_array($hookmanager->resArray)
            ? $hookmanager->resArray
            : [];

        if ($reshook >= 1) {
            $result['blocked'] = true;
            $result['error'] = isset($resArray['error']) ? (string) $resArray['error'] : 'access_denied';
            $result['error_description'] = isset($resArray['error_description'])
                ? (string) $resArray['error_description']
                : '';
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' blocked with error=' . $result['error'], LOG_INFO);
            return $result;
        }

        // Hook allowed the flow. Harvest extra_claims if any.
        if (isset($resArray['extra_claims'])) {
            if (is_array($resArray['extra_claims'])) {
                $result['extra_claims'] = self::sanitizeExtraClaims($resArray['extra_claims'], $hookName);
            } else {
                dol_syslog(
                    'SmartAuth HookHelper: ' . $hookName . ' returned non-array extra_claims, ignored',
                    LOG_WARNING
                );
            }
        }

        return $result;
    }

    /**
     * Filter an extra_claims array contributed by a hook before it is merged
     * into the JWT payload.
     *
     * Rules (PERFS.md §3.3):
     *  - keys must be non-empty strings.
     *  - keys in RESERVED_TOKEN_CLAIMS are dropped with a warning (SmartAuth
     *    owns them and a module forging them would break OIDC contract or
     *    enable privilege escalation).
     *  - values must be string|int|bool, or an array whose elements are all
     *    strings. Anything else (objects, nested arrays, arrays of mixed
     *    types) is dropped with a warning. The constraint keeps the JWT
     *    compact and unambiguous to decode on the resource server side.
     *
     * @param array  $extraClaims Raw resArray['extra_claims']
     * @param string $hookName    Hook name (for logging)
     * @return array Filtered claims (associative)
     */
    private static function sanitizeExtraClaims(array $extraClaims, string $hookName): array
    {
        $clean = [];
        foreach ($extraClaims as $key => $value) {
            if (!is_string($key) || $key === '') {
                dol_syslog(
                    'SmartAuth HookHelper: ' . $hookName . ' extra_claims has non-string key, dropped',
                    LOG_WARNING
                );
                continue;
            }
            if (in_array($key, self::RESERVED_TOKEN_CLAIMS, true)) {
                dol_syslog(
                    'SmartAuth HookHelper: ' . $hookName . ' attempted to inject reserved claim "'
                    . $key . '" via extra_claims - dropped',
                    LOG_WARNING
                );
                continue;
            }
            if (!self::isValidClaimValue($value)) {
                dol_syslog(
                    'SmartAuth HookHelper: ' . $hookName . ' extra_claims "' . $key
                    . '" has invalid value type - dropped',
                    LOG_WARNING
                );
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * Tell whether a value is acceptable as an extra_claim value.
     * Allowed: string, int, bool, or array of strings only (PERFS.md §3.3).
     *
     * @param mixed $value
     * @return bool
     */
    private static function isValidClaimValue($value): bool
    {
        if (is_string($value) || is_int($value) || is_bool($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Run the userinfo_claims hook to allow modules to mutate claims.
     *
     * On internal error (return < 0) the original claims are preserved.
     *
     * @param array $parameters Hook parameters (must contain user_id, client_id, scopes, context)
     * @param array $claims     Current claims (will be passed by reference and possibly mutated)
     * @return array Modified claims (or original on error)
     */
    /**
     * Reserved JWT claim names that hook handlers must never overwrite.
     * Letting a hook rewrite iss / aud / sub / exp / iat / nbf / jti would
     * let a misbehaving module forge tokens for other clients or users
     *.
     */
    private const RESERVED_CLAIMS = [
        'iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'auth_time',
        'at_hash', 'nonce', 'token_type', 'grant_type',
    ];

    public static function runClaimsHook(array $parameters, array $claims): array
    {
        global $hookmanager;

        if (!is_object($hookmanager) || !method_exists($hookmanager, 'executeHooks')) {
            return $claims;
        }

        if (method_exists($hookmanager, 'initHooks')) {
            $hookmanager->initHooks(['smartmaker']);
        }

        $hookName = 'smartmaker_oauth_userinfo_claims';
        $action = self::stripPrefix($hookName);
        $modified = $claims;

        $reshook = $hookmanager->executeHooks($hookName, $parameters, $modified, $action);

        if ($reshook < 0) {
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' returned internal error (' . $reshook . '), keeping standard claims', LOG_WARNING);
            return $claims;
        }

        if (!is_array($modified)) {
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' returned non-array claims, keeping standard claims', LOG_WARNING);
            return $claims;
        }

        // Restore reserved claims from the original payload so a hook
        // cannot rewrite them (M-18). Hooks may add user-defined claims
        // freely; identity / lifetime / audience claims are server-owned.
        foreach (self::RESERVED_CLAIMS as $reserved) {
            if (array_key_exists($reserved, $claims)) {
                if (!array_key_exists($reserved, $modified) || $modified[$reserved] !== $claims[$reserved]) {
                    if (array_key_exists($reserved, $modified) && $modified[$reserved] !== $claims[$reserved]) {
                        dol_syslog('SmartAuth HookHelper: Hook attempted to overwrite reserved claim "' . $reserved . '" - ignored', LOG_WARNING);
                    }
                    $modified[$reserved] = $claims[$reserved];
                }
            } else {
                // Hook tried to inject a reserved claim that wasn't in the
                // original payload - drop it.
                if (array_key_exists($reserved, $modified)) {
                    dol_syslog('SmartAuth HookHelper: Hook attempted to inject reserved claim "' . $reserved . '" - dropped', LOG_WARNING);
                    unset($modified[$reserved]);
                }
            }
        }

        return $modified;
    }

    /**
     * Run the smartmaker_account_sections hook to collect HTML sections
     * for the /account self-service page.
     *
     * Each section is expected to be an associative array containing at
     * least 'title' (string) and 'html' (string), and optionally
     * 'priority' (int, default 100). Sections are returned sorted by
     * ascending priority.
     *
     * @param array $parameters Hook parameters (must contain user_id)
     * @return array<int, array{title:string, html:string, priority:int}>
     */
    public static function runAccountSectionsHook(array $parameters): array
    {
        global $hookmanager;

        $sections = [];

        if (!is_object($hookmanager) || !method_exists($hookmanager, 'executeHooks')) {
            return $sections;
        }

        if (method_exists($hookmanager, 'initHooks')) {
            $hookmanager->initHooks(['smartmaker']);
        }

        $hookName = 'smartmaker_account_sections';
        $action = self::stripPrefix($hookName);

        $reshook = $hookmanager->executeHooks($hookName, $parameters, $sections, $action);
        if ($reshook < 0) {
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' returned internal error (' . $reshook . ')', LOG_WARNING);
            return [];
        }

        if (!is_array($sections)) {
            return [];
        }

        // Normalize and sort by priority asc
        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized[] = [
                'title' => isset($section['title']) ? (string) $section['title'] : '',
                'html' => isset($section['html']) ? (string) $section['html'] : '',
                'priority' => isset($section['priority']) ? (int) $section['priority'] : 100,
            ];
        }

        usort($normalized, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $normalized;
    }

    /**
     * Run the smartmaker_email_alternative_persist hook so an external
     * module (e.g. ssomanager) can persist a verified alternative email
     * for a (user, oauth_client) pair.
     *
     * SmartAuth itself does not own the persistence table -- that table
     * lives in the ssomanager schema -- so this hook is the only way the
     * data leaves SmartAuth. If no module is registered, the call is a
     * no-op and the route returns "no handler" so the page can hint that
     * ssomanager is required.
     *
     * Modules MUST return:
     *   - 0 : no handler claimed the request
     *   - 1 : persisted, optionally setting $hookmanager->resArray['service']
     *         (string label of the service, used for confirmation page)
     *   - <0 : internal error, surfaces a 500 to the user
     *
     * @param array $parameters Must contain user_id (int), client_pk (int|null), client_id (string|null), email (string)
     * @return array{handled:bool, service:?string, internal_error:bool}
     */
    public static function runEmailAlternativePersistHook(array $parameters): array
    {
        global $hookmanager;

        $result = [
            'handled' => false,
            'service' => null,
            'internal_error' => false,
        ];

        if (!is_object($hookmanager) || !method_exists($hookmanager, 'executeHooks')) {
            return $result;
        }

        if (method_exists($hookmanager, 'initHooks')) {
            $hookmanager->initHooks(['smartmaker']);
        }
        if (property_exists($hookmanager, 'resArray')) {
            $hookmanager->resArray = [];
        }

        $hookName = 'smartmaker_email_alternative_persist';
        $action = self::stripPrefix($hookName);
        $object = null;

        $reshook = $hookmanager->executeHooks($hookName, $parameters, $object, $action);

        if ($reshook < 0) {
            $result['internal_error'] = true;
            dol_syslog('SmartAuth HookHelper: ' . $hookName . ' returned internal error (' . $reshook . ')', LOG_ERR);
            return $result;
        }

        if ($reshook >= 1) {
            $result['handled'] = true;
            $resArray = property_exists($hookmanager, 'resArray') && is_array($hookmanager->resArray)
                ? $hookmanager->resArray
                : [];
            if (!empty($resArray['service']) && is_string($resArray['service'])) {
                $result['service'] = $resArray['service'];
            }
        }

        return $result;
    }

    /**
     * Strip the smartmaker_ prefix from a hook name to produce a sensible $action.
     *
     * @param string $hookName Hook name
     * @return string Action label
     */
    private static function stripPrefix(string $hookName): string
    {
        if (strpos($hookName, 'smartmaker_oauth_') === 0) {
            return substr($hookName, strlen('smartmaker_oauth_'));
        }
        if (strpos($hookName, 'smartmaker_') === 0) {
            return substr($hookName, strlen('smartmaker_'));
        }
        return $hookName;
    }
}
