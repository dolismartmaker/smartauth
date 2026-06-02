<?php

/**
 * LandingController.php
 *
 * Public landing page for the SmartAuth SSO portal. Served at the root
 * "/" of the OAuth2 vhost so visitors land on a clean HTML page instead
 * of a 404 JSON blob.
 *
 * Surface:
 *   - mysoc logo (inlined as base64 if the Dolibarr company logo exists,
 *     otherwise fallback to /assets/img/logo.svg)
 *   - site title from mysoc->name
 *   - up to 3 action cards: Sign in, Create account, Manage account
 *     (the last two are conditional on SMARTAUTH_REGISTRATION_ENABLED /
 *      SMARTAUTH_ACCOUNT_ENABLED, both default 1)
 *   - a tiny footer with the OIDC discovery URL for devs
 *
 * The page reuses the existing tpl/layout.tpl.php so it gets the same
 * CSS, same favicon, same security cache-control headers as the login
 * page.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

use SmartAuth\Api\Account\RegistrationGate;

dol_include_once('/smartauth/api/Account/RegistrationGate.php');

class LandingController
{
    /**
     * Maximum size (in bytes) we are willing to inline as base64 in the
     * landing HTML. A typical company logo is well under 50 KB; this cap
     * stops a hostile-or-careless mysoc->logo upload from blowing up the
     * landing page payload.
     */
    private const MAX_INLINED_LOGO_BYTES = 200 * 1024;

    /**
     * Render the landing HTML page directly to the response.
     */
    public function handle(): void
    {
        global $mysoc, $langs;

        $siteName = '';
        if (is_object($mysoc) && !empty($mysoc->name)) {
            $siteName = (string) $mysoc->name;
        }
        if ($siteName === '') {
            $siteName = 'SmartAuth';
        }

        $logoMarkup = $this->resolveLogoMarkup($siteName);

        // Three actions, two of them gated by their own flag. Login is
        // always available - if it were not, the entire portal would be
        // pointless.
        $registrationEnabled = RegistrationGate::isEnabled();
        $accountEnabled = (bool) getDolGlobalInt('SMARTAUTH_ACCOUNT_ENABLED', 1);

        $templateVars = [
            'pageTitle' => 'Accueil',
            'pageClass' => 'landing-page',
            'siteName' => $siteName,
            'logoMarkup' => $logoMarkup,
            'showRegister' => $registrationEnabled,
            'showAccount' => $accountEnabled,
        ];

        $templatePath = dirname(__DIR__) . '/tpl/landing.tpl.php';
        if (!is_file($templatePath)) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'error' => 'server_error',
                'error_description' => 'Landing template missing',
            ]);
            return;
        }

        extract($templateVars, EXTR_OVERWRITE);
        include $templatePath;
    }

    /**
     * Build an <img> tag that points at the company logo when available
     * (inlined as a data: URL so we do not have to plumb a separate
     * /branding/logo route just for the landing), or falls back to the
     * generic SmartAuth SVG shipped under public/assets/img/.
     */
    private function resolveLogoMarkup(string $altText): string
    {
        global $mysoc, $conf;

        $altEscaped = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');

        // mysoc->logo holds just the filename; the actual file lives
        // under <DOL_DATA_ROOT>/mycompany/logos/. The OAuth2 vhost does
        // not expose that directory, so inline as base64 when present.
        $logoFile = '';
        if (is_object($mysoc) && !empty($mysoc->logo)) {
            $candidate = '';
            if (defined('DOL_DATA_ROOT')) {
                $candidate = rtrim(DOL_DATA_ROOT, '/') . '/mycompany/logos/' . $mysoc->logo;
            }
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                $logoFile = $candidate;
            }
        }

        if ($logoFile !== '' && filesize($logoFile) <= self::MAX_INLINED_LOGO_BYTES) {
            $mime = function_exists('mime_content_type') ? mime_content_type($logoFile) : '';
            if ($mime === '' || strpos($mime, 'image/') !== 0) {
                // Best-effort: derive from extension when mime_content_type
                // is unavailable or returned something nonsensical.
                $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
                $extMap = [
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif'  => 'image/gif',
                    'svg'  => 'image/svg+xml',
                    'webp' => 'image/webp',
                ];
                $mime = $extMap[$ext] ?? '';
            }
            if (strpos($mime, 'image/') === 0) {
                $bytes = @file_get_contents($logoFile);
                if ($bytes !== false && $bytes !== '') {
                    $encoded = base64_encode($bytes);
                    return '<img src="data:' . $mime . ';base64,' . $encoded
                        . '" alt="' . $altEscaped . '" class="logo">';
                }
            }
        }

        // Fallback: the default SmartAuth glyph shipped with the module.
        // The same-origin reference is allowed by the HTML CSP set in
        // public/index.php.
        return '<img src="/assets/img/logo.svg" alt="' . $altEscaped . '" class="logo">';
    }
}
