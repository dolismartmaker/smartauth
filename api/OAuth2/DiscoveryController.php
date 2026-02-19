<?php

/**
 * DiscoveryController.php
 *
 * OIDC Discovery endpoints for SmartAuth OAuth2 server.
 * Implements OpenID Connect Discovery 1.0 specification.
 *
 * Endpoints:
 * - GET /.well-known/openid-configuration
 * - GET /.well-known/jwks.json
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

class DiscoveryController
{
    use ResponseTrait;
    /**
     * Handle OpenID Configuration request
     *
     * Returns the OIDC discovery document with all endpoints and capabilities.
     *
     * @return void Outputs JSON response directly
     */
    public function handleOpenidConfiguration(): void
    {
        $config = OAuthConfig::getOpenIdConfiguration();

        $this->sendJsonResponse($config, 200);
    }

    /**
     * Handle JWKS request
     *
     * Returns the JSON Web Key Set containing public key(s) for token verification.
     *
     * @return void Outputs JSON response directly
     */
    public function handleJwks(): void
    {
        try {
            $jwks = JwtKeyHelper::getJwks();
            $this->sendJsonResponse($jwks, 200);
        } catch (\Exception $e) {
            dol_syslog('SmartAuth DiscoveryController: JWKS error: ' . $e->getMessage(), LOG_ERR);
            $this->sendJsonResponse([
                'error' => 'server_error',
                'error_description' => 'Failed to retrieve JWKS'
            ], 500);
        }
    }

    /**
     * Route request to appropriate handler based on path
     *
     * @param string $path Request path
     * @return bool True if route was handled, false otherwise
     */
    public function route(string $path): bool
    {
        // Normalize path
        $path = '/' . ltrim($path, '/');

        switch ($path) {
            case '/.well-known/openid-configuration':
                $this->handleOpenidConfiguration();
                return true;

            case '/.well-known/jwks.json':
                $this->handleJwks();
                return true;

            default:
                return false;
        }
    }

}
