<?php
/**
 * SmartTokenConfig.php
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

namespace SmartAuth\Api;

class SmartTokenConfig
{
    // Access token: short-lived, used for API calls
    const ACCESS_TOKEN_LIFETIME = 3600; // 1 hour (can be 15min - 24h)

    // Refresh token: long-lived, used to get new access tokens
    const REFRESH_TOKEN_LIFETIME = 2592000; // 30 days (can be 7-90 days)

    // Maximum refresh count before forced re-authentication
    const MAX_REFRESH_COUNT = 100;

    // Token types
    const TYPE_ACCESS = 'access';
    const TYPE_REFRESH = 'refresh';
}

