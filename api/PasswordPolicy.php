<?php

/**
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

/**
 * Single source of truth for password strength across SmartAuth
 * (registration, account change, password reset, change password).
 *
 * It honours Dolibarr's configured password rules: the active generator
 * module selected in Home > Setup > Security (constant USER_PASSWORD_GENERATED
 * = none / standard / perso) exposes validatePassword(), the very method
 * User::setPassword() uses to enforce the admin-defined constraints. By
 * delegating here, the module stops carrying its own divergent policy and
 * applies whatever the Dolibarr instance is configured with.
 *
 * When no generator is configured (USER_PASSWORD_GENERATED empty), no Dolibarr
 * rule exists, so a sane built-in baseline is enforced instead - at least as
 * strong as the registration policy used historically, so a reset can never be
 * weaker than a registration.
 */
class PasswordPolicy
{
    /**
     * Baseline minimum length applied when no Dolibarr generator is set.
     */
    const BASELINE_MIN_LENGTH = 12;

    /**
     * Validate a clear-text password.
     *
     * @param string $password Clear-text password to check
     * @return array{valid: bool, message: string} valid=true when accepted;
     *               message holds a human-readable reason on rejection.
     */
    public static function validate(string $password): array
    {
        // Prefer Dolibarr's configured generator rules when we are running
        // inside a real Dolibarr (function + constant available).
        if (function_exists('getDolGlobalString') && defined('DOL_DOCUMENT_ROOT')) {
            $generator = getDolGlobalString('USER_PASSWORD_GENERATED', '');
            if ($generator !== '') {
                $result = self::validateWithDolibarrGenerator($password, $generator);
                if ($result !== null) {
                    return $result;
                }
                // Generator configured but not loadable: fall through to the
                // baseline rather than accepting an unchecked password.
            }
        }

        return self::validateBaseline($password);
    }

    /**
     * Run Dolibarr's active password generator module validatePassword().
     * Mirrors exactly how User::setPassword() instantiates and calls it.
     *
     * @param string $password  Clear-text password
     * @param string $generator Value of USER_PASSWORD_GENERATED (eg 'standard')
     * @return array{valid: bool, message: string}|null null when the generator
     *               module cannot be loaded (caller falls back to baseline)
     */
    private static function validateWithDolibarrGenerator(string $password, string $generator): ?array
    {
        global $db, $conf, $langs, $user;

        $className = 'modGeneratePass' . ucfirst($generator);
        $file = DOL_DOCUMENT_ROOT . '/core/modules/security/generate/' . $className . '.class.php';

        if (!is_file($file)) {
            dol_syslog('[SmartAuth] PasswordPolicy: password generator file not found (' . $file . '), using baseline', LOG_WARNING);
            return null;
        }

        require_once $file;
        if (!class_exists($className)) {
            dol_syslog('[SmartAuth] PasswordPolicy: password generator class not found (' . $className . '), using baseline', LOG_WARNING);
            return null;
        }

        $gen = new $className($db, $conf, $langs, $user);

        // WithoutAmbi only matters when generating a password; disable it for
        // user-supplied input checks, like User::setPassword() does.
        if (property_exists($gen, 'WithoutAmbi')) {
            $gen->WithoutAmbi = 0;
        }

        // validatePassword() returns 1 when the password matches the configured
        // rules, 0 otherwise (with a translated reason in $gen->error).
        if ($gen->validatePassword($password)) {
            return ['valid' => true, 'message' => ''];
        }

        $message = !empty($gen->error)
            ? $gen->error
            : 'Password does not meet the security requirements';

        return ['valid' => false, 'message' => $message];
    }

    /**
     * Built-in fallback policy when Dolibarr defines no generator: at least
     * BASELINE_MIN_LENGTH characters mixing upper case, lower case and a digit.
     *
     * @param string $password Clear-text password
     * @return array{valid: bool, message: string}
     */
    private static function validateBaseline(string $password): array
    {
        if (function_exists('dol_strlen')) {
            $length = dol_strlen($password);
        } else {
            $length = strlen($password);
        }

        if ($length < self::BASELINE_MIN_LENGTH) {
            return [
                'valid' => false,
                'message' => 'Password must be at least ' . self::BASELINE_MIN_LENGTH . ' characters long',
            ];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one digit'];
        }

        return ['valid' => true, 'message' => ''];
    }
}
