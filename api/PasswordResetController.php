<?php

/**
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (c) 2025 Paolo Debaisieux <paolo.debaisieux@cap-rel.fr>
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

require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';

use User;
use SmartAuth\Api\RateLimiter;

class PasswordResetController
{
    /**
     * Token expiry time in seconds (1 hour)
     */
    private const TOKEN_EXPIRY_SECONDS = 3600;

    /**
     * Rate limit: max requests per window
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 3;

    /**
     * Rate limit: window in seconds (15 minutes)
     */
    private const RATE_LIMIT_WINDOW = 900;

    /**
     * Request password reset
     *
     * Sends an email with a password reset link if the email exists.
     * Always returns success to prevent email enumeration.
     *
     * @param array|null $arr Request parameters containing 'email'
     * @return array Response
     */
    public function requestReset($arr = null)
    {
        global $db, $conf, $langs, $mysoc;
        $langs->loadLangs(array("main", "users", "other"));

        dol_syslog("PasswordResetController::requestReset - Start", LOG_DEBUG);

        $email = trim($arr['email'] ?? '');

        if (empty($email)) {
            return [
                [
                    'statusCode' => 400,
                    'message' => 'Email is required',
                ],
                400
            ];
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                [
                    'statusCode' => 400,
                    'message' => 'Invalid email format',
                ],
                400
            ];
        }

        // Rate limiting by email to prevent abuse
        $rateLimiter = new RateLimiter($db);
        $rateCheck = $rateLimiter->checkLimit(
            $email,
            'password_reset',
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW
        );

        if (!$rateCheck['allowed']) {
            dol_syslog("PasswordResetController::requestReset - Rate limit exceeded for: " . $email, LOG_WARNING);
            return [
                [
                    'statusCode' => 429,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $rateCheck['retry_after'],
                ],
                429
            ];
        }

        // Record the attempt
        $rateLimiter->recordAttempt($email, 'password_reset');

        // Find user by email
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user";
        $sql .= " WHERE email = '" . $db->escape($email) . "'";
        $sql .= " AND statut = 1"; // Only active users
        $sql .= " AND entity IN (" . getEntity('user') . ")";
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);

        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $userId = $obj->rowid;

            // Load user
            $userObj = new User($db);
            $userObj->fetch($userId);

            // Generate reset token with embedded expiry
            $token = $this->generateTokenWithExpiry();

            // Store token in user's pass_temp field
            $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET";
            $sql .= " pass_temp = '" . $db->escape($token) . "'";
            $sql .= " WHERE rowid = " . ((int) $userId);

            $result = $db->query($sql);

            if ($result) {
                // Send email
                $this->sendResetEmail($userObj, $token);

                dol_syslog("PasswordResetController::requestReset - Reset email sent to user ID: " . $userId, LOG_DEBUG);
            }
        } else {
            // Log but don't reveal if email exists
            dol_syslog("PasswordResetController::requestReset - No user found for email: " . $email, LOG_DEBUG);
        }

        // Always return success to prevent email enumeration
        return [
            [
                'statusCode' => 200,
                'message' => 'If this email exists, a password reset link has been sent.',
            ],
            200
        ];
    }

    /**
     * Generate a token with embedded expiry timestamp
     *
     * Format: base64(random_token|expiry_timestamp)
     *
     * @return string
     */
    private function generateTokenWithExpiry(): string
    {
        $randomPart = getRandomPassword(true, array(), 32);
        $expiry = time() + self::TOKEN_EXPIRY_SECONDS;

        return base64_encode($randomPart . '|' . $expiry);
    }

    /**
     * Validate a token and check expiry
     *
     * @param string $token Token to validate
     * @return array ['valid' => bool, 'token' => string|null, 'expired' => bool]
     */
    public static function validateToken(string $token): array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return ['valid' => false, 'token' => null, 'expired' => false];
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 2) {
            return ['valid' => false, 'token' => null, 'expired' => false];
        }

        $randomPart = $parts[0];
        $expiry = (int) $parts[1];

        if ($expiry < time()) {
            return ['valid' => false, 'token' => $randomPart, 'expired' => true];
        }

        return ['valid' => true, 'token' => $randomPart, 'expired' => false];
    }

    /**
     * Send password reset email
     *
     * @param User $user User object
     * @param string $token Reset token
     * @return bool Success
     */
    private function sendResetEmail($user, $token)
    {
        global $conf, $langs, $mysoc;

        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';

        // Build reset URL - use generic SMARTAUTH_APP_URL config
        $resetUrl = getDolGlobalString('SMARTAUTH_APP_URL', '');
        if (empty($resetUrl)) {
            $resetUrl = DOL_MAIN_URL_ROOT;
        }
        $resetUrl = rtrim($resetUrl, '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);

        // Email subject
        $subject = $langs->transnoentities('PasswordResetRequest');
        if (empty($subject) || $subject === 'PasswordResetRequest') {
            $subject = 'Password Reset Request';
        }

        // Email body
        $message = $langs->transnoentities('PasswordResetEmailBody', $user->firstname, $resetUrl);
        if (empty($message) || strpos($message, 'PasswordResetEmailBody') !== false) {
            $message = "Hello " . $user->firstname . ",\n\n";
            $message .= "You have requested to reset your password.\n\n";
            $message .= "Click the following link to reset your password:\n";
            $message .= $resetUrl . "\n\n";
            $message .= "This link will expire in 1 hour.\n\n";
            $message .= "If you did not request this, please ignore this email.\n\n";
            $message .= "Best regards,\n";
            $message .= $mysoc->name ?? 'The Team';
        }

        // Sender
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        // Create and send email
        $mail = new \CMailFile(
            $subject,
            $user->email,
            $from,
            $message,
            array(),
            array(),
            array(),
            '',
            '',
            0,
            0
        );

        $result = $mail->sendfile();

        if ($result) {
            dol_syslog("PasswordResetController::sendResetEmail - Email sent successfully to: " . $user->email, LOG_DEBUG);
        } else {
            dol_syslog("PasswordResetController::sendResetEmail - Failed to send email to: " . $user->email . " - Error: " . $mail->error, LOG_ERR);
        }

        return $result;
    }
}
