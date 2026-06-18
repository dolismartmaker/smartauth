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

dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');
dol_include_once('/smartauth/api/OAuth2/SubjectAuthenticator.php');
dol_include_once('/smartauth/api/Account/EmailValidationToken.php');

use User;
use SmartAuth\Api\RateLimiter;
use SmartAuth\Api\OAuth2\TokenSubject;
use SmartAuth\Api\OAuth2\SubjectAuthenticator;
use SmartAuth\Api\Account\EmailValidationToken;

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
            dol_syslog("[SmartAuth] PasswordResetController::requestReset - IP rate limit exceeded for: " . $clientIp, LOG_WARNING);
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
            dol_syslog("[SmartAuth] PasswordResetController::requestReset - Rate limit exceeded for: " . $email, LOG_WARNING);
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

        // Resolve the target subject across the enabled authentication sources
        // (account / member / user), in the fixed priority order. The reset is
        // no longer tied to llx_user: a portal account (llx_societe_account) or
        // a member (llx_adherent) can reset its password too.
        $subject = $this->resolveSubjectByEmail($email);

        if ($subject !== null) {
            // A single-use, time-limited token stored (hashed) in
            // llx_smartauth_email_validation, keyed by the subject. Only the
            // hash lives in the database; the plain token travels by email.
            $plain = EmailValidationToken::generatePlainToken();
            $tokenHash = EmailValidationToken::hashToken($plain);

            $tokens = new EmailValidationToken($db);
            $rowId = $tokens->create(
                $subject->isUser() ? $subject->getId() : 0,
                EmailValidationToken::PURPOSE_PASSWORD_RESET,
                $tokenHash,
                self::TOKEN_EXPIRY_SECONDS,
                $clientIp,
                null,
                (int) $conf->entity,
                $subject->getType(),
                $subject->isAccount() ? $subject->getId() : null,
                $subject->isMember() ? $subject->getId() : null
            );

            if ($rowId > 0) {
                // Deliver to the address the visitor actually typed (which we
                // just confirmed belongs to this subject).
                $this->sendResetEmail($email, $plain, $this->subjectDisplayName($subject));
                SmartAuthLogger::debug("PasswordResetController::requestReset - Reset email sent for subject " . $subject->toSub());
            } else {
                dol_syslog('[SmartAuth] PasswordResetController::requestReset - failed to store reset token for ' . $subject->toSub(), LOG_ERR);
            }
        } else {
            // Log but don't reveal if email exists
            SmartAuthLogger::debug("PasswordResetController::requestReset - No subject found for email: " . $email);
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
     * Resolve the active llx_user account that owns the given email.
     *
     * The email is matched, in priority order, against:
     *   1. llx_user.email (the account's own address)
     *   2. the linked thirdparty llx_societe.email (via user.fk_soc)
     *   3. the linked contact llx_socpeople.email (via user.fk_socpeople)
     *
     * This covers self-service portal accounts (external users linked to a
     * contact/thirdparty) whose llx_user.email is empty or differs from the
     * address the visitor knows. Only active accounts (statut = 1) in the
     * current entity are considered.
     *
     * @param string $email Validated, trimmed email.
     * @return \stdClass|null Row with rowid, pass_temp, user_email, or null.
     */
    private function resolveActiveUserByEmail(string $email)
    {
        global $db;

        $e = $db->escape($email);

        $sql = "SELECT u.rowid, u.pass_temp, u.email AS user_email";
        $sql .= " FROM " . MAIN_DB_PREFIX . "user u";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "socpeople sp ON sp.rowid = u.fk_socpeople";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = u.fk_soc";
        $sql .= " WHERE u.statut = 1";
        $sql .= " AND u.entity IN (" . getEntity('user') . ")";
        $sql .= " AND (u.email = '" . $e . "' OR sp.email = '" . $e . "' OR s.email = '" . $e . "')";
        // Prefer an exact user.email match, then the thirdparty, then the contact.
        $sql .= " ORDER BY CASE";
        $sql .= " WHEN u.email = '" . $e . "' THEN 0";
        $sql .= " WHEN s.email = '" . $e . "' THEN 1";
        $sql .= " ELSE 2 END";
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) === 0) {
            return null;
        }

        return $db->fetch_object($resql);
    }

    /**
     * Resolve the email to a TokenSubject across the enabled authentication
     * sources, in the fixed priority order account > adherent > user. Only the
     * sources currently enabled (SubjectAuthenticator toggles) are searched, so
     * a reset cannot target a base that is closed to login.
     *
     * @param string $email Validated, trimmed email.
     * @return TokenSubject|null
     */
    private function resolveSubjectByEmail(string $email)
    {
        global $db;

        $auth = new SubjectAuthenticator($db);
        foreach ($auth->enabledSources() as $source) {
            if ($source === SubjectAuthenticator::SOURCE_ACCOUNT) {
                $subject = $this->resolveAccountByEmail($email, $auth->authSites());
            } elseif ($source === SubjectAuthenticator::SOURCE_ADHERENT) {
                $subject = $this->resolveMemberByEmail($email);
            } else {
                $row = $this->resolveActiveUserByEmail($email);
                $subject = ($row !== null) ? TokenSubject::user((int) $row->rowid) : null;
            }
            if ($subject !== null) {
                return $subject;
            }
        }
        return null;
    }

    /**
     * Resolve an active portal account (llx_societe_account) by login (= email),
     * restricted to the accepted sites and the current entity.
     *
     * @param string   $email
     * @param string[] $sites
     * @return TokenSubject|null
     */
    private function resolveAccountByEmail(string $email, array $sites)
    {
        global $db, $conf;

        $escapedSites = [];
        foreach ($sites as $site) {
            $escapedSites[] = "'" . $db->escape($site) . "'";
        }
        if (empty($escapedSites)) {
            $escapedSites[] = "'smartauth'";
        }

        $sql = "SELECT rowid, fk_soc FROM " . MAIN_DB_PREFIX . "societe_account";
        $sql .= " WHERE login = '" . $db->escape($email) . "'";
        $sql .= " AND site IN (" . implode(',', $escapedSites) . ")";
        $sql .= " AND status = 1";
        $sql .= " AND entity = " . (int) $conf->entity;
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) === 0) {
            return null;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return TokenSubject::account((int) $obj->rowid, (int) $obj->fk_soc);
    }

    /**
     * Resolve an active member (llx_adherent) by email or login, in the current
     * entity. statut = 1 means a validated member.
     *
     * @param string $email
     * @return TokenSubject|null
     */
    private function resolveMemberByEmail(string $email)
    {
        global $db, $conf;

        $e = $db->escape($email);
        $sql = "SELECT rowid, fk_soc FROM " . MAIN_DB_PREFIX . "adherent";
        $sql .= " WHERE (email = '" . $e . "' OR login = '" . $e . "')";
        $sql .= " AND statut = 1";
        $sql .= " AND entity = " . (int) $conf->entity;
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) === 0) {
            return null;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return TokenSubject::member((int) $obj->rowid, $obj->fk_soc !== null ? (int) $obj->fk_soc : 0);
    }

    /**
     * Best-effort first name / greeting for the reset email, by subject type.
     *
     * @param TokenSubject $subject
     * @return string
     */
    private function subjectDisplayName(TokenSubject $subject): string
    {
        global $db;

        if ($subject->isUser()) {
            $user = new User($db);
            if ($user->fetch($subject->getId()) > 0) {
                return (string) ($user->firstname ?? '');
            }
            return '';
        }

        if ($subject->isMember()) {
            $sql = "SELECT firstname FROM " . MAIN_DB_PREFIX . "adherent WHERE rowid = " . (int) $subject->getId();
            $resql = $db->query($sql);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                $db->free($resql);
                if (is_object($obj)) {
                    return (string) ($obj->firstname ?? '');
                }
            }
        }

        // Portal account: no personal name.
        return '';
    }

    /**
     * Send password reset email.
     *
     * @param string $recipientEmail Address to deliver to and to embed in the
     *        reset link (the address the visitor typed, confirmed to belong to
     *        the subject).
     * @param string $token          Plain reset token.
     * @param string $displayName    Greeting name (may be empty for accounts).
     * @return bool Success
     */
    private function sendResetEmail($recipientEmail, $token, $displayName = '')
    {
        global $conf, $langs, $mysoc;

        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';

        $displayName = (string) $displayName;

        // Build reset URL - use generic SMARTAUTH_APP_URL config
        $resetUrl = getDolGlobalString('SMARTAUTH_APP_URL', '');
        if (empty($resetUrl)) {
            $resetUrl = DOL_MAIN_URL_ROOT;
        }
        $resetUrl = rtrim($resetUrl, '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($recipientEmail);

        // Email subject
        $subject = $langs->transnoentities('PasswordResetRequest');
        if (empty($subject) || $subject === 'PasswordResetRequest') {
            $subject = 'Password Reset Request';
        }

        // Email body
        $message = $langs->transnoentities('PasswordResetEmailBody', $displayName, $resetUrl);
        if (empty($message) || strpos($message, 'PasswordResetEmailBody') !== false) {
            $message = "Hello " . $displayName . ",\n\n";
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
            $recipientEmail,
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
            SmartAuthLogger::debug("PasswordResetController::sendResetEmail - Email sent successfully to: " . $recipientEmail);
        } else {
            dol_syslog("[SmartAuth] PasswordResetController::sendResetEmail - Failed to send email to: " . $recipientEmail . " - Error: " . $mail->error, LOG_ERR);
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

        // Look up the single-use reset token in llx_smartauth_email_validation.
        // The token alone resolves the subject (account/member/user); the email
        // field is no longer used to match, which fixes the empty-user.email
        // case and keeps the flow uniform across sources.
        $tokens = new EmailValidationToken($db);
        $tokenHash = EmailValidationToken::hashToken($token);
        $row = $tokens->findActive($tokenHash, EmailValidationToken::PURPOSE_PASSWORD_RESET, (int) $conf->entity);

        if ($row === null) {
            // Distinguish an expired token (410) from an invalid/used one (400)
            // for a clearer message on the reset page.
            if ($this->tokenIsExpired($tokenHash, (int) $conf->entity)) {
                dol_syslog("[SmartAuth] PasswordResetController::confirmReset - Token expired", LOG_WARNING);
                return [
                    ['error' => 'Reset token has expired. Please request a new one.'],
                    410
                ];
            }
            dol_syslog("[SmartAuth] PasswordResetController::confirmReset - Invalid or consumed token", LOG_WARNING);
            return [
                ['error' => 'Invalid reset token'],
                400
            ];
        }

        // Rebuild the subject the token was issued for.
        $subject = TokenSubject::fromRecord(
            $db,
            $row['subject_type'],
            (int) $row['fk_user'],
            $row['fk_societe_account'],
            $row['fk_adherent']
        );

        // Write the new password on the right backing table.
        if (!$this->writeSubjectPassword($subject, $newPassword)) {
            return [
                ['error' => 'Failed to update password'],
                500
            ];
        }

        // Consume the single-use token.
        $tokens->markUsed((int) $row['rowid']);

        // Invalidate every existing token of THIS subject. Without this, an
        // attacker who held valid tokens before the password change would keep
        // them. Subject-aware so an external subject (fk_user = 0) does not
        // wrongly revoke unrelated rows.
        $this->revokeAllSubjectTokens($subject);

        SmartAuthLogger::debug("PasswordResetController::confirmReset - Password reset successful for subject " . $subject->toSub());

        return [
            ['message' => 'Password has been reset successfully'],
            200
        ];
    }

    /**
     * Whether a reset token hash exists but is past its expiry (used to return
     * 410 rather than a generic 400).
     *
     * @param string $tokenHash
     * @param int    $entity
     * @return bool
     */
    private function tokenIsExpired(string $tokenHash, int $entity): bool
    {
        global $db;

        $sql = "SELECT expires_at FROM " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " WHERE token_hash = '" . $db->escape($tokenHash) . "'";
        $sql .= " AND purpose = '" . $db->escape(EmailValidationToken::PURPOSE_PASSWORD_RESET) . "'";
        $sql .= " AND used_at IS NULL";
        $sql .= " AND entity = " . ((int) $entity);
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) === 0) {
            return false;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return is_object($obj) && strtotime($obj->expires_at) <= dol_now();
    }

    /**
     * Persist the new password on the table matching the subject type. user ->
     * User::setPassword (Dolibarr-managed). account/member -> dol_hash() into
     * pass_crypted, the same scheme onepagebasket uses and that dol_verifyHash
     * (SubjectAuthenticator) reads back.
     *
     * @param TokenSubject $subject
     * @param string       $newPassword
     * @return bool
     */
    private function writeSubjectPassword(TokenSubject $subject, string $newPassword): bool
    {
        global $db;

        if ($subject->isUser()) {
            $userObj = new User($db);
            if ($userObj->fetch($subject->getId()) <= 0) {
                dol_syslog('[SmartAuth] PasswordResetController::writeSubjectPassword - user not found ' . $subject->getId(), LOG_ERR);
                return false;
            }
            $result = $userObj->setPassword($userObj, $newPassword);
            if ($result < 0) {
                dol_syslog('[SmartAuth] PasswordResetController::writeSubjectPassword - setPassword failed: ' . $userObj->error, LOG_ERR);
                return false;
            }
            return true;
        }

        $table = $subject->isAccount() ? 'societe_account' : 'adherent';
        $hash = dol_hash($newPassword);
        $sql = "UPDATE " . MAIN_DB_PREFIX . $table;
        $sql .= " SET pass_crypted = '" . $db->escape($hash) . "'";
        $sql .= " WHERE rowid = " . ((int) $subject->getId());

        if (!$db->query($sql)) {
            dol_syslog('[SmartAuth] PasswordResetController::writeSubjectPassword - update ' . $table . ' failed: ' . $db->lasterror(), LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Revoke all live JWT (mobile) and OAuth2 tokens belonging to the given
     * subject. Called on password reset so that previously-issued tokens cannot
     * survive the credential rotation (M-3). Subject-aware: an external subject
     * (account/member) carries fk_user = 0, so revoking by fk_user would either
     * hit nothing or wrongly hit every external token.
     *
     * @param TokenSubject $subject
     * @return void
     */
    private function revokeAllSubjectTokens(TokenSubject $subject): void
    {
        global $db;

        $subjectId = (int) $subject->getId();

        // Mobile JWT tokens (llx_smartauth_auth), keyed by (fk_authid, auth_element).
        if ($subject->isAccount()) {
            $authElement = 'societe_account';
        } elseif ($subject->isMember()) {
            $authElement = 'adherent';
        } else {
            $authElement = 'user';
        }
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_auth"
            . " SET status = 0"
            . " WHERE fk_authid = " . $subjectId
            . " AND auth_element = '" . $db->escape($authElement) . "'"
            . " AND status = 1";
        if (!$db->query($sql)) {
            dol_syslog('[SmartAuth] PasswordResetController::revokeAllSubjectTokens - failed to revoke JWT tokens: ' . $db->lasterror(), LOG_ERR);
        }

        // OAuth2 tokens (llx_smartauth_oauth_tokens), subject-aware.
        if (class_exists('\\SmartAuthOAuthToken')) {
            \SmartAuthOAuthToken::revokeAllForSubject($db, $subject->getType(), $subjectId);
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
            dol_syslog("[SmartAuth] PasswordResetController::changePassword - Invalid current password for user ID: " . $user->id, LOG_WARNING);
            return [
                ['error' => 'Current password is incorrect'],
                403
            ];
        }

        // Update password
        $result = $userObj->setPassword($userObj, $newPassword);

        if ($result < 0) {
            dol_syslog("[SmartAuth] PasswordResetController::changePassword - Failed to set password: " . $userObj->error, LOG_ERR);
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
     * Validate password strength against the policy configured in Dolibarr
     * (Home > Setup > Security). Delegates to the shared PasswordPolicy so the
     * reset path enforces exactly the same rules as registration, and honours
     * the admin-defined constraints instead of a weaker module-local default.
     *
     * @param string $password Password to validate
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validatePasswordStrength(string $password): array
    {
        return PasswordPolicy::validate($password);
    }
}
