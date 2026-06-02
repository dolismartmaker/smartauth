<?php

/**
 * RegistrationGate.php
 *
 * Single source of truth for the public self-registration kill switch.
 *
 * The front controller (public/index.php) and the landing page
 * (LandingController) both decide whether self-registration is open based
 * on the SMARTAUTH_REGISTRATION_ENABLED Dolibarr constant. Centralising the
 * decision here keeps the routing guard and the UI in sync and makes the
 * behaviour unit-testable without spinning up the HTTP front controller.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

class RegistrationGate
{
    /**
     * Paths that make up the public self-registration surface. When the
     * feature is off, all of them must answer as if they did not exist.
     *
     * @var string[]
     */
    public const ROUTES = [
        '/register',
        '/register/confirm',
        '/register/resend',
        '/lookup-by-email',
    ];

    /**
     * Whether visitors may create their own account.
     *
     * Defaults to disabled (0): self-registration is opt-in. The admin must
     * explicitly tick the toggle in the setup page before the /register*
     * surface answers anything other than 404.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool) getDolGlobalInt('SMARTAUTH_REGISTRATION_ENABLED', 0);
    }

    /**
     * Whether the given request path belongs to the self-registration
     * surface that the kill switch must guard.
     *
     * @param string $path Request path (already parsed, no query string).
     * @return bool
     */
    public static function isRegistrationPath(string $path): bool
    {
        return in_array($path, self::ROUTES, true);
    }
}
