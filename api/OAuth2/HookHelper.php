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
     * Run a blocking hook (pre_authorize / pre_token).
     *
     * Convention:
     *  - return value 1 from any module = block
     *  - module sets resArray['error'] and resArray['error_description']
     *  - return value < 0 = internal error, treated as 500/server_error
     *
     * @param string                       $hookName    Hook name (e.g. smartmaker_oauth_pre_authorize)
     * @param array                        $parameters  Hook parameters
     * @param \SmartAuthOAuthClient|null   $client      Optional client object passed as $object
     * @return array{blocked:bool, error:?string, error_description:?string, internal_error:bool}
     */
    public static function runBlockingHook(string $hookName, array $parameters, $client = null): array
    {
        global $hookmanager;

        $result = [
            'blocked' => false,
            'error' => null,
            'error_description' => null,
            'internal_error' => false,
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

        if ($reshook >= 1) {
            $result['blocked'] = true;
            $resArray = property_exists($hookmanager, 'resArray') && is_array($hookmanager->resArray)
                ? $hookmanager->resArray
                : [];
            $result['error'] = isset($resArray['error']) ? (string) $resArray['error'] : 'access_denied';
            $result['error_description'] = isset($resArray['error_description'])
                ? (string) $resArray['error_description']
                : '';
            dol_syslog('SmartAuth HookHelper: Hook ' . $hookName . ' blocked with error=' . $result['error'], LOG_INFO);
        }

        return $result;
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
