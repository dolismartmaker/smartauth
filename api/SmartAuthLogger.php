<?php

/**
 * SmartAuthLogger.php
 *
 * Debug logging helper for SmartAuth module.
 * Logs only when SMARTAUTH_DEBUG constant is enabled.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class SmartAuthLogger
{
    /**
     * Log a debug message if SMARTAUTH_DEBUG is enabled
     *
     * @param string $message Message to log
     * @return void
     */
    public static function debug(string $message): void
    {
        if (getDolGlobalInt('SMARTAUTH_DEBUG')) {
            dol_syslog('[SmartAuth] ' . $message, LOG_DEBUG);
        }
    }
}
