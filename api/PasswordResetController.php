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

        SmartAuthLogger::debug("PasswordResetController::requestReset - Start");

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

        // Rate limiting: by email AND by IP. The per-email limit alone
        // (3/15min) was bypassable for mail-bombing by varying the email
        // parameter. Adding the IP scope makes
        // mass enumeration / spamming much harder.
        $rateLimiter = new RateLimiter($db);
        $clientIp = \SmartAuth\Api\RouteController::get_client_ip();

        $rateCheckIp = $rateLimiter->checkLimit(
            $clientIp,
            'password_reset_ip',
            getDolGlobalInt('SMARTAUTH_RATELIMIT_RESET_IP_MAX', 10),
            getDolGlobalInt('SMARTAUTH_RATELIMIT_RESET_IP_WINDOW', 900)
        );
        if (!$rateCheckIp['allowed']) {
            dol_syslog("SmartAuth PasswordResetController::requestReset - IP rate limit exceeded for: " . $clientIp, LOG_WARNING);
            return [
                [
                    'statusCode' => 429,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $rateCheckIp['retry_after'],
                ],
                429
            ];
        }

        $rateCheck = $rateLimiter->checkLimit(
            $email,
            'password_reset',
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW
        );

        if (!$rateCheck['allowed']) {
            dol_syslog("SmartAuth PasswordResetController::requestReset - Rate limit exceeded for: " . $email, LOG_WARNING);
            return [
                [
                    'statusCode' => 429,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $rateCheck['retry_after'],
                ],
                429
            ];
        }

        // Record the attempts on both buckets
        $rateLimiter->recordAttempt($clientIp, 'password_reset_ip');
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

            // Store the SHA-256 of the token in pass_temp.
            // The plain token is sent to the user's email; only its hash
            // lives in the database, so a SQLi or backup leak does not
            // give the attacker a usable reset link.
            $tokenHash = hash('sha256', $token);
            $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET";
            $sql .= " pass_temp = '" . $db->escape($tokenHash) . "'";
            $sql .= " WHERE rowid = " . ((int) $userId);

            $result = $db->query($sql);

            if ($result) {
                // Send email with the plain token (the only place it ever appears)
                $this->sendResetEmail($userObj, $token);

                SmartAuthLogger::debug("PasswordResetController::requestReset - Reset email sent to user ID: " . $userId);
            }
        } else {
            // Log but don't reveal if email exists
            SmartAuthLogger::debug("PasswordResetController::requestReset - No user found for email: " . $email);
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
            SmartAuthLogger::debug("PasswordResetController::sendResetEmail - Email sent successfully to: " . $user->email);
        } else {
            dol_syslog("SmartAuth PasswordResetController::sendResetEmail - Failed to send email to: " . $user->email . " - Error: " . $mail->error, LOG_ERR);
        }

        return $result;
    }

    /**
     * Confirm password reset with token and new password
     *
     * @param array|null $arr Request parameters containing 'email', 'token', 'password'
     * @return array Response
     */
    public function confirmReset($arr = null)
    {
        global $db, $conf, $langs;

        SmartAuthLogger::debug("PasswordResetController::confirmReset - Start");

        $email = trim($arr['email'] ?? '');
        $token = trim($arr['token'] ?? '');
        $newPassword = $arr['password'] ?? '';

        // Validate required fields
        if (empty($email) || empty($token) || empty($newPassword)) {
            return [
                ['error' => 'Email, token and password are required'],
                400
            ];
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                ['error' => 'Invalid email format'],
                400
            ];
        }

        // Validate password strength
        $passwordValidation = $this->validatePasswordStrength($newPassword);
        if (!$passwordValidation['valid']) {
            return [
                ['error' => $passwordValidation['message']],
                400
            ];
        }

        // Validate token format
        $tokenValidation = self::validateToken($token);
        if (!$tokenValidation['valid']) {
            if ($tokenValidation['expired']) {
                dol_syslog("SmartAuth PasswordResetController::confirmReset - Token expired for: " . $email, LOG_WARNING);
                return [
                    ['error' => 'Reset token has expired. Please request a new one.'],
                    410
                ];
            }
            dol_syslog("SmartAuth PasswordResetController::confirmReset - Invalid token format for: " . $email, LOG_WARNING);
            return [
                ['error' => 'Invalid reset token'],
                400
            ];
        }

        // Find user by email and verify token
        $sql = "SELECT rowid, pass_temp FROM " . MAIN_DB_PREFIX . "user";
        $sql .= " WHERE email = '" . $db->escape($email) . "'";
        $sql .= " AND statut = 1";
        $sql .= " AND entity IN (" . getEntity('user') . ")";
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);

        if (!$resql || $db->num_rows($resql) === 0) {
            dol_syslog("SmartAuth PasswordResetController::confirmReset - User not found: " . $email, LOG_WARNING);
            return [
                ['error' => 'Invalid email or token'],
                400
            ];
        }

        $obj = $db->fetch_object($resql);

        // Verify token matches via constant-time comparison against the
        // stored SHA-256 hash.
        $expectedHash = hash('sha256', $token);
        if (empty($obj->pass_temp) || !hash_equals((string) $obj->pass_temp, $expectedHash)) {
            dol_syslog("SmartAuth PasswordResetController::confirmReset - Token mismatch for user: " . $email, LOG_WARNING);
            return [
                ['error' => 'Invalid email or token'],
                400
            ];
        }

        // Load user and update password
        $userObj = new User($db);
        $userObj->fetch($obj->rowid);

        // Use Dolibarr's setPassword method
        $result = $userObj->setPassword($userObj, $newPassword);

        if ($result < 0) {
            dol_syslog("SmartAuth PasswordResetController::confirmReset - Failed to set password: " . $userObj->error, LOG_ERR);
            return [
                ['error' => 'Failed to update password'],
                500
            ];
        }

        // Clear the reset token
        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET";
        $sql .= " pass_temp = NULL";
        $sql .= " WHERE rowid = " . ((int) $obj->rowid);
        $db->query($sql);

        // Invalidate all existing SmartAuth tokens for this user
        //. Without this, an attacker who held
        // valid tokens before the password change keeps them after.
        $this->revokeAllUserTokens((int) $obj->rowid);

        SmartAuthLogger::debug("PasswordResetController::confirmReset - Password reset successful for user ID: " . $obj->rowid);

        return [
            ['message' => 'Password has been reset successfully'],
            200
        ];
    }

    /**
     * Revoke all live JWT (mobile) and OAuth2 tokens belonging to the
     * given user. Called on password change so that previously-issued
     * tokens cannot survive the credential rotation (M-3).
     */
    private function revokeAllUserTokens(int $userId): void
    {
        global $db;

        // Mobile JWT tokens (llx_smartauth_auth)
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth"
            . " SET status = 0"
            . " WHERE fk_authid = " . $userId
            . " AND status = 1";
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth PasswordResetController::revokeAllUserTokens - failed to revoke JWT tokens: ' . $db->lasterror(), LOG_ERR);
        }

        // OAuth2 tokens (llx_smartauth_oauth_tokens)
        if (class_exists('\\SmartAuthOAuthToken')) {
            \SmartAuthOAuthToken::revokeAllForUser($db, $userId);
        }
    }

    /**
     * Change password for authenticated user
     *
     * @param array|null $arr Request parameters containing 'current_password', 'new_password', 'user'
     * @return array Response
     */
    public function changePassword($arr = null)
    {
        global $db;

        SmartAuthLogger::debug("PasswordResetController::changePassword - Start");

        // Get authenticated user from payload
        $user = $arr['user'] ?? null;
        if (empty($user) || !is_object($user)) {
            return [
                ['error' => 'Authentication required'],
                401
            ];
        }

        $currentPassword = $arr['current_password'] ?? '';
        $newPassword = $arr['new_password'] ?? '';

        // Validate required fields
        if (empty($currentPassword) || empty($newPassword)) {
            return [
                ['error' => 'Current password and new password are required'],
                400
            ];
        }

        // Validate new password strength
        $passwordValidation = $this->validatePasswordStrength($newPassword);
        if (!$passwordValidation['valid']) {
            return [
                ['error' => $passwordValidation['message']],
                400
            ];
        }

        // Reload user to get current password hash
        $userObj = new User($db);
        $userObj->fetch($user->id);

        // Verify current password
        require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

        $passwordOk = false;

        // Check encrypted password
        if (!empty($userObj->pass_indatabase_crypted)) {
            $passwordOk = dol_verifyHash($currentPassword, $userObj->pass_indatabase_crypted);
        }

        if (!$passwordOk) {
            dol_syslog("SmartAuth PasswordResetController::changePassword - Invalid current password for user ID: " . $user->id, LOG_WARNING);
            return [
                ['error' => 'Current password is incorrect'],
                403
            ];
        }

        // Update password
        $result = $userObj->setPassword($userObj, $newPassword);

        if ($result < 0) {
            dol_syslog("SmartAuth PasswordResetController::changePassword - Failed to set password: " . $userObj->error, LOG_ERR);
            return [
                ['error' => 'Failed to update password'],
                500
            ];
        }

        SmartAuthLogger::debug("PasswordResetController::changePassword - Password changed for user ID: " . $user->id);

        return [
            ['message' => 'Password changed successfully'],
            200
        ];
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validatePasswordStrength(string $password): array
    {
        // Minimum length
        $minLength = getDolGlobalInt('USER_PASSWORD_MIN_LENGTH', 8);
        if (strlen($password) < $minLength) {
            return [
                'valid' => false,
                'message' => "Password must be at least $minLength characters long"
            ];
        }

        // Check for required character types if configured
        if (getDolGlobalInt('USER_PASSWORD_NEED_LETTER', 0) && !preg_match('/[a-zA-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one letter'
            ];
        }

        if (getDolGlobalInt('USER_PASSWORD_NEED_DIGIT', 0) && !preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one digit'
            ];
        }

        if (getDolGlobalInt('USER_PASSWORD_NEED_SPECIAL', 0) && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one special character'
            ];
        }

        if (getDolGlobalInt('USER_PASSWORD_NEED_UPPERCASE', 0) && !preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one uppercase letter'
            ];
        }

        if (getDolGlobalInt('USER_PASSWORD_NEED_LOWERCASE', 0) && !preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one lowercase letter'
            ];
        }

        return ['valid' => true, 'message' => ''];
    }
}
