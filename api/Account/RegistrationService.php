<?php

/**
 * RegistrationService.php
 *
 * Self-service account registration for SmartAuth.
 *
 * Creates a Dolibarr prospect (`llx_societe` with client=0, prospect=1),
 * a contact (`llx_socpeople`) and an inactive external user
 * (`llx_user.statut=0, fk_soc=<thirdparty>`). Sends a one-shot
 * email-validation token (purpose 'register') with a 24h TTL.
 *
 * Confirmation, resend and lookup methods will be added in lot 6 (spec).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

use SmartAuth\Api\OAuth2\OAuthConfig;

class RegistrationService
{
    public const ERR_INVALID_EMAIL = -1;
    public const ERR_WEAK_PASSWORD = -2;
    public const ERR_EMAIL_TAKEN = -3;
    public const ERR_THIRDPARTY_FAILED = -4;
    public const ERR_CONTACT_FAILED = -5;
    public const ERR_USER_FAILED = -6;
    public const ERR_TOKEN_FAILED = -7;
    public const ERR_EMAIL_FAILED = -8;
    public const ERR_INTERNAL = -9;
    public const ERR_TOKEN_INVALID = -10;
    public const ERR_USER_NOT_FOUND = -11;
    public const ERR_ACCOUNT_NOT_DELETABLE = -12;

    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var EmailValidationToken
     */
    private $tokens;

    /**
     * Optional injection for tests.
     *
     * @var callable|null fn(string $to, string $subject, string $textBody, string $htmlBody): bool
     */
    private $emailSender;

    public function __construct($db, ?callable $emailSender = null)
    {
        $this->db = $db;
        $this->tokens = new EmailValidationToken($db);
        $this->emailSender = $emailSender;
    }

    /**
     * Start a registration. Atomically:
     *   1. Validates inputs.
     *   2. Refuses if the email is already used (caller must NOT differentiate
     *      this from "new email" in HTTP responses to avoid enumeration; this
     *      method returns ERR_EMAIL_TAKEN to the caller for control flow).
     *   3. Creates a thirdparty (prospect), a contact and an inactive user.
     *   4. Generates a token and sends the validation email.
     *
     * On any failure after the thirdparty was created, the partial state is
     * rolled back via DB transaction.
     *
     * @param string      $email
     * @param string      $password   Plain password (not stored; validated for policy and saved hashed via Dolibarr setPassword)
     * @param string|null $firstname
     * @param string|null $lastname
     * @param int|null    $clientPkContext Client OAuth2 row id that initiated the registration (optional, for branding/logging)
     * @param string      $ip          Source IP (audit)
     * @param string|null $continueUrl Optional URL to resume the OAuth2 flow after confirmation (validated by caller)
     * @return array{user_id?:int, token_sent_to_email?:string, error?:int} Either ['user_id'=>int,'token_sent_to_email'=>string] or ['error'=>int]
     */
    public function startRegistration(
        string $email,
        string $password,
        ?string $firstname,
        ?string $lastname,
        ?int $clientPkContext,
        string $ip,
        ?string $continueUrl = null
    ): array {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            dol_syslog('SmartAuth RegistrationService: invalid email format', LOG_INFO);
            return ['error' => self::ERR_INVALID_EMAIL];
        }

        if (!self::isPasswordStrongEnough($password)) {
            dol_syslog('SmartAuth RegistrationService: password rejected by policy', LOG_INFO);
            return ['error' => self::ERR_WEAK_PASSWORD];
        }

        if ($this->emailAlreadyKnown($email)) {
            dol_syslog('SmartAuth RegistrationService: email already known: ' . $email, LOG_INFO);
            return ['error' => self::ERR_EMAIL_TAKEN];
        }

        $this->db->begin();

        $thirdpartyId = $this->createProspect($email, $firstname, $lastname);
        if ($thirdpartyId <= 0) {
            $this->db->rollback();
            return ['error' => self::ERR_THIRDPARTY_FAILED];
        }

        $contactId = $this->createContact($thirdpartyId, $email, $firstname, $lastname);
        if ($contactId <= 0) {
            $this->db->rollback();
            return ['error' => self::ERR_CONTACT_FAILED];
        }

        $userId = $this->createInactiveExternalUser($thirdpartyId, $contactId, $email, $password, $firstname, $lastname);
        if ($userId <= 0) {
            $this->db->rollback();
            return ['error' => self::ERR_USER_FAILED];
        }

        $plainToken = EmailValidationToken::generatePlainToken();
        $context = [];
        if ($continueUrl !== null && $continueUrl !== '') {
            $context['continue'] = $continueUrl;
        }
        if ($clientPkContext !== null && $clientPkContext > 0) {
            $context['client_pk'] = $clientPkContext;
        }

        $tokenRowId = $this->tokens->create(
            $userId,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plainToken),
            OAuthConfig::getRegisterTokenTTL(),
            $ip,
            $context !== [] ? $context : null
        );
        if ($tokenRowId <= 0) {
            $this->db->rollback();
            return ['error' => self::ERR_TOKEN_FAILED];
        }

        if (!$this->sendValidationEmail($email, $plainToken, $clientPkContext, $continueUrl)) {
            $this->db->rollback();
            return ['error' => self::ERR_EMAIL_FAILED];
        }

        $this->db->commit();

        dol_syslog('SmartAuth RegistrationService: registration started for user_id=' . $userId, LOG_INFO);

        return [
            'user_id' => $userId,
            'token_sent_to_email' => $email,
        ];
    }

    /**
     * Confirm a registration by consuming a 'register' token.
     *
     * On success: marks the token used, activates the user (statut=1) and
     * returns the user id along with the optional `continue` URL stored at
     * registration time. On failure returns a negative error code.
     *
     * @param string $plainToken
     * @return array{user_id?:int, continue?:string|null, error?:int}
     */
    public function confirmRegistration(string $plainToken): array
    {
        if ($plainToken === '') {
            return ['error' => self::ERR_TOKEN_INVALID];
        }

        $row = $this->tokens->findActive(
            EmailValidationToken::hashToken($plainToken),
            EmailValidationToken::PURPOSE_REGISTER
        );
        if ($row === null) {
            dol_syslog('SmartAuth RegistrationService: confirmRegistration - token not found / expired / used', LOG_INFO);
            return ['error' => self::ERR_TOKEN_INVALID];
        }

        $userId = (int) $row['fk_user'];
        if ($userId <= 0) {
            return ['error' => self::ERR_TOKEN_INVALID];
        }

        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }

        $user = new \User($this->db);
        if ($user->fetch($userId) <= 0) {
            dol_syslog('SmartAuth RegistrationService: confirmRegistration - user ' . $userId . ' not found', LOG_ERR);
            return ['error' => self::ERR_USER_NOT_FOUND];
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return ['error' => self::ERR_INTERNAL];
        }

        $this->db->begin();

        if (!$this->tokens->markUsed((int) $row['rowid'])) {
            $this->db->rollback();
            return ['error' => self::ERR_INTERNAL];
        }

        if ((int) ($user->statut ?? 0) !== 1) {
            // Dolibarr's User::update() does not persist statut. Write it
            // directly so the activation is materialised in the database.
            $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET statut = 1 WHERE rowid = " . ((int) $userId);
            if (!$this->db->query($sql)) {
                dol_syslog('SmartAuth RegistrationService: failed to activate user ' . $userId, LOG_ERR);
                $this->db->rollback();
                return ['error' => self::ERR_INTERNAL];
            }
            $user->statut = 1;
        }

        $this->db->commit();

        $continueUrl = null;
        if (!empty($row['context'])) {
            $decoded = json_decode((string) $row['context'], true);
            if (is_array($decoded) && !empty($decoded['continue']) && is_string($decoded['continue'])) {
                $continueUrl = $decoded['continue'];
            }
        }

        dol_syslog('SmartAuth RegistrationService: registration confirmed for user_id=' . $userId, LOG_INFO);

        return [
            'user_id' => $userId,
            'continue' => $continueUrl,
        ];
    }

    /**
     * Resend a registration confirmation email if a non-active account
     * exists for that email and the cooldown is over.
     *
     * Always returns true (the controller emits a generic response) to keep
     * the enumeration mitigation: an attacker cannot tell whether or not a
     * pending account exists for the given address.
     *
     * @param string $email
     * @param string $ip
     * @return bool
     */
    public function resendConfirmation(string $email, string $ip): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        $userRow = $this->fetchInactiveUserByEmail($email);
        if ($userRow === null) {
            return true;
        }
        $userId = (int) $userRow['rowid'];

        $cooldown = OAuthConfig::getRegisterResendCooldown();
        $lastDatec = $this->tokens->lastActiveDatec($userId, EmailValidationToken::PURPOSE_REGISTER);
        if ($lastDatec !== null && (dol_now() - $lastDatec) < $cooldown) {
            dol_syslog('SmartAuth RegistrationService: resend skipped, cooldown active for user_id=' . $userId, LOG_DEBUG);
            return true;
        }

        $this->tokens->invalidateActiveForUser($userId, EmailValidationToken::PURPOSE_REGISTER);

        $plain = EmailValidationToken::generatePlainToken();
        $rowId = $this->tokens->create(
            $userId,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken($plain),
            OAuthConfig::getRegisterTokenTTL(),
            $ip,
            null
        );
        if ($rowId <= 0) {
            return true;
        }

        $this->sendValidationEmail($email, $plain, null, null);

        dol_syslog('SmartAuth RegistrationService: resend confirmation issued for user_id=' . $userId, LOG_INFO);
        return true;
    }

    /**
     * Send a "you already have an account" courtesy email if a SmartAuth
     * account is registered for that email. Always returns true to keep
     * the response indistinguishable from the "no account" case.
     *
     * @param string $email
     * @param string $ip
     * @return bool
     */
    public function lookupByEmail(string $email, string $ip): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        $userRow = $this->fetchActiveUserByEmail($email);
        if ($userRow === null) {
            return true;
        }
        $userId = (int) $userRow['rowid'];

        // Generate an email_change token so the recipient can opt to add
        // this address as an alternative for a service. The choice of which
        // service is made on the form opened by the link.
        $plain = EmailValidationToken::generatePlainToken();
        $context = ['source' => 'lookup_by_email'];
        $rowId = $this->tokens->create(
            $userId,
            EmailValidationToken::PURPOSE_EMAIL_CHANGE,
            EmailValidationToken::hashToken($plain),
            OAuthConfig::getRegisterTokenTTL(),
            $ip,
            $context
        );

        $alternativeUrl = null;
        if ($rowId > 0) {
            $alternativeUrl = OAuthConfig::getIssuer() . '/email-alternative/confirm?token=' . urlencode($plain);
        }

        $this->sendLookupEmail(
            $email,
            (string) ($userRow['login'] ?? ''),
            (string) ($userRow['firstname'] ?? ''),
            (string) ($userRow['lastname'] ?? ''),
            $alternativeUrl
        );

        dol_syslog('SmartAuth RegistrationService: lookupByEmail issued for user_id=' . $userId, LOG_INFO);
        return true;
    }

    /**
     * Fetch an inactive (statut=0) Dolibarr user by email.
     *
     * @param string $email
     * @return array{rowid:int,login:string,firstname:string,lastname:string}|null
     */
    private function fetchInactiveUserByEmail(string $email): ?array
    {
        return $this->fetchUserByEmail($email, 0);
    }

    /**
     * Fetch an active (statut=1) Dolibarr user by email.
     *
     * @param string $email
     * @return array{rowid:int,login:string,firstname:string,lastname:string}|null
     */
    private function fetchActiveUserByEmail(string $email): ?array
    {
        return $this->fetchUserByEmail($email, 1);
    }

    /**
     * Fetch a Dolibarr user by email + statut.
     *
     * @param string $email
     * @param int    $statut
     * @return array{rowid:int,login:string,firstname:string,lastname:string}|null
     */
    private function fetchUserByEmail(string $email, int $statut): ?array
    {
        $sql = "SELECT rowid, login, firstname, lastname FROM " . MAIN_DB_PREFIX . "user";
        $sql .= " WHERE LOWER(email) = '" . $this->db->escape($email) . "'";
        $sql .= " AND statut = " . ((int) $statut);
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'rowid' => (int) $obj->rowid,
            'login' => (string) ($obj->login ?? ''),
            'firstname' => (string) ($obj->firstname ?? ''),
            'lastname' => (string) ($obj->lastname ?? ''),
        ];
    }

    /**
     * Send the courtesy "you already have an account" email used by
     * /lookup-by-email.
     *
     * @param string      $to
     * @param string      $login
     * @param string      $firstname
     * @param string      $lastname
     * @param string|null $alternativeUrl URL to add this email as alternative on a service (or null)
     * @return bool
     */
    private function sendLookupEmail(
        string $to,
        string $login,
        string $firstname,
        string $lastname,
        ?string $alternativeUrl
    ): bool {
        $issuer = OAuthConfig::getIssuer();
        $loginUrl = $issuer . '/login';

        $textTpl = __DIR__ . '/../../tpl/email/lookup_existing_account.txt.php';
        $htmlTpl = __DIR__ . '/../../tpl/email/lookup_existing_account.html.php';

        $vars = [
            'issuer' => $issuer,
            'loginUrl' => $loginUrl,
            'alternativeUrl' => $alternativeUrl,
            'login' => $login,
            'firstname' => $firstname,
            'lastname' => $lastname,
        ];

        $textBody = $this->renderTemplate($textTpl, $vars);
        $htmlBody = $this->renderTemplate($htmlTpl, $vars);
        $subject = 'Vous avez deja un compte chez nous';

        if ($this->emailSender !== null) {
            return (bool) call_user_func($this->emailSender, $to, $subject, $textBody, $htmlBody);
        }
        return $this->dispatchEmailViaDolibarr($to, $subject, $textBody, $htmlBody);
    }

    /**
     * Self-service account deletion. Allowed only when:
     *   - the user is external (fk_soc != 0)
     *   - the linked thirdparty is in prospect mode (client=0)
     *   - no contract exists for that thirdparty (active or closed)
     *
     * On success: anonymises the user (login/email/firstname/lastname),
     * disables it (statut=0), revokes all OAuth2 tokens, deletes
     * oauth_consents and email_validation rows.
     *
     * @param int $fkUser User row id
     * @return int User id on success, or a negative error code
     */
    public function deleteSelfServiceAccount(int $fkUser): int
    {
        if ($fkUser <= 0) {
            return self::ERR_USER_NOT_FOUND;
        }

        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }

        $user = new \User($this->db);
        if ($user->fetch($fkUser) <= 0) {
            return self::ERR_USER_NOT_FOUND;
        }

        // Dolibarr User::fetch() loads fk_soc into $this->socid (legacy
        // attribute name). Read both as a defensive fallback.
        $thirdpartyId = (int) ($user->socid ?? $user->fk_soc ?? 0);
        if ($thirdpartyId <= 0) {
            dol_syslog('SmartAuth RegistrationService: deleteSelfServiceAccount refused - not an external user', LOG_INFO);
            return self::ERR_ACCOUNT_NOT_DELETABLE;
        }

        if (!$this->isThirdpartyDeletableProspect($thirdpartyId)) {
            dol_syslog('SmartAuth RegistrationService: deleteSelfServiceAccount refused - thirdparty has contracts or is a client', LOG_INFO);
            return self::ERR_ACCOUNT_NOT_DELETABLE;
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return self::ERR_INTERNAL;
        }

        $this->db->begin();

        // Revoke OAuth2 tokens for this user (cascade across clients)
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " SET revoked_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        $sql .= " AND revoked_at IS NULL";
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuth RegistrationService: failed to revoke tokens for user ' . $fkUser, LOG_ERR);
            $this->db->rollback();
            return self::ERR_INTERNAL;
        }

        // Delete OAuth2 consents (no longer needed once the account is gone)
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuth RegistrationService: failed to delete consents for user ' . $fkUser, LOG_ERR);
            $this->db->rollback();
            return self::ERR_INTERNAL;
        }

        // Delete pending email validation rows for this user
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuth RegistrationService: failed to delete email_validation for user ' . $fkUser, LOG_ERR);
            $this->db->rollback();
            return self::ERR_INTERNAL;
        }

        // Anonymise + disable the user. Dolibarr User::update() does not
        // persist statut, so write the columns directly.
        $anonLogin = 'deleted-' . $fkUser . '-' . bin2hex(random_bytes(4));
        $anonEmail = $anonLogin . '@deleted.invalid';

        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET";
        $sql .= " statut = 0,";
        $sql .= " login = '" . $this->db->escape($anonLogin) . "',";
        $sql .= " email = '" . $this->db->escape($anonEmail) . "',";
        $sql .= " firstname = '',";
        $sql .= " lastname = 'Deleted'";
        $sql .= " WHERE rowid = " . ((int) $fkUser);
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuth RegistrationService: failed to anonymise user ' . $fkUser, LOG_ERR);
            $this->db->rollback();
            return self::ERR_INTERNAL;
        }
        $user->statut = 0;
        $user->login = $anonLogin;
        $user->email = $anonEmail;
        $user->firstname = '';
        $user->lastname = 'Deleted';

        $this->db->commit();

        dol_syslog('SmartAuth RegistrationService: self-service deletion completed for user_id=' . $fkUser, LOG_INFO);
        return $fkUser;
    }

    /**
     * Returns true if the given thirdparty is in prospect mode AND has no
     * contract row (whatever the contract status). Used as the guard for
     * self-service account deletion.
     *
     * @param int $thirdpartyId
     * @return bool
     */
    public function isThirdpartyDeletableProspect(int $thirdpartyId): bool
    {
        if ($thirdpartyId <= 0) {
            return false;
        }

        $sql = "SELECT client FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . ((int) $thirdpartyId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return false;
        }
        // Dolibarr client column: 0=neither, 1=customer, 2=prospect, 3=both.
        // Self-deletion is only allowed for "prospect only" (value 2) or
        // "neither" (value 0). Bit 1 means "is a customer" and is a hard stop.
        $clientFlag = (int) ($obj->client ?? 0);
        if (($clientFlag & 1) !== 0) {
            return false;
        }

        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "contrat WHERE fk_soc = " . ((int) $thirdpartyId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return false;
        }
        return ((int) $obj->n) === 0;
    }

    /**
     * Password policy: at least 12 chars, mixing upper/lower/digit.
     *
     * @param string $password
     * @return bool
     */
    public static function isPasswordStrongEnough(string $password): bool
    {
        if (strlen($password) < 12) {
            return false;
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }
        return true;
    }

    /**
     * Returns true if the given email already belongs to a Dolibarr user
     * (active or not) or to a contact.
     *
     * @param string $email
     * @return bool
     */
    public function emailAlreadyKnown(string $email): bool
    {
        $escaped = $this->db->escape($email);

        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "user WHERE LOWER(email) = '" . $escaped . "' LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->fetch_object($resql)) {
            return true;
        }

        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "socpeople WHERE LOWER(email) = '" . $escaped . "' LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->fetch_object($resql)) {
            return true;
        }

        return false;
    }

    /**
     * Create a Dolibarr thirdparty in prospect mode (client=0, prospect=1).
     *
     * @param string      $email
     * @param string|null $firstname
     * @param string|null $lastname
     * @return int Thirdparty rowid, or <= 0 on failure
     */
    private function createProspect(string $email, ?string $firstname, ?string $lastname): int
    {
        if (!class_exists('Societe')) {
            require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        }
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            dol_syslog('SmartAuth RegistrationService: no system user available for prospect creation', LOG_ERR);
            return -1;
        }

        $societe = new \Societe($this->db);
        $societe->name = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));
        if ($societe->name === '') {
            $societe->name = $email;
        }
        $societe->email = $email;
        // Dolibarr encodes client/prospect in a single column:
        //   0=neither, 1=customer, 2=prospect, 3=customer+prospect
        $societe->client = 2;
        $societe->prospect = 1;
        $societe->status = 1;
        $societe->code_client = -1;

        $result = $societe->create($admin);
        if ($result <= 0) {
            dol_syslog('SmartAuth RegistrationService: thirdparty create failed: ' . implode(',', (array) ($societe->errors ?? [])) . ' / ' . ($societe->error ?? ''), LOG_ERR);
            return -1;
        }
        return (int) $societe->id;
    }

    /**
     * Create a contact attached to the thirdparty.
     *
     * @param int         $thirdpartyId
     * @param string      $email
     * @param string|null $firstname
     * @param string|null $lastname
     * @return int Contact rowid, or <= 0 on failure
     */
    private function createContact(int $thirdpartyId, string $email, ?string $firstname, ?string $lastname): int
    {
        if (!class_exists('Contact')) {
            require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return -1;
        }

        $contact = new \Contact($this->db);
        $contact->socid = $thirdpartyId;
        $contact->firstname = $firstname ?? '';
        $contact->lastname = $lastname ?? '';
        $contact->email = $email;
        $contact->statut = 1;

        $result = $contact->create($admin);
        if ($result <= 0) {
            dol_syslog('SmartAuth RegistrationService: contact create failed: ' . ($contact->error ?? ''), LOG_ERR);
            return -1;
        }
        return (int) $contact->id;
    }

    /**
     * Create an inactive external user attached to the thirdparty. Stores
     * the password hashed via Dolibarr's standard setPassword.
     *
     * @param int         $thirdpartyId
     * @param int         $contactId
     * @param string      $email
     * @param string      $password
     * @param string|null $firstname
     * @param string|null $lastname
     * @return int User rowid, or <= 0 on failure
     */
    private function createInactiveExternalUser(
        int $thirdpartyId,
        int $contactId,
        string $email,
        string $password,
        ?string $firstname,
        ?string $lastname
    ): int {
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return -1;
        }

        $user = new \User($this->db);
        $user->fk_soc = $thirdpartyId;
        $user->fk_socpeople = $contactId;
        $user->login = $this->generateUniqueLogin($email);
        $user->email = $email;
        $user->firstname = $firstname ?? '';
        $user->lastname = $lastname ?? '';
        $user->statut = 0;
        $user->employee = 0;
        $user->admin = 0;

        $result = $user->create($admin);
        if ($result <= 0) {
            dol_syslog('SmartAuth RegistrationService: user create failed: ' . ($user->error ?? ''), LOG_ERR);
            return -1;
        }

        // Dolibarr User::create() ships a minimal INSERT (datec, login,
        // ldap_sid, entity); statut, fk_soc, fk_socpeople, email and names
        // are NOT written. Persist them explicitly to materialise the
        // external-inactive user contract.
        $sql = "UPDATE " . MAIN_DB_PREFIX . "user SET";
        $sql .= " statut = 0,";
        $sql .= " fk_soc = " . ((int) $thirdpartyId) . ",";
        $sql .= " fk_socpeople = " . ((int) $contactId) . ",";
        $sql .= " email = '" . $this->db->escape($email) . "',";
        $sql .= " firstname = '" . $this->db->escape($firstname ?? '') . "',";
        $sql .= " lastname = '" . $this->db->escape($lastname ?? '') . "',";
        $sql .= " employee = 0,";
        $sql .= " admin = 0";
        $sql .= " WHERE rowid = " . ((int) $user->id);
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuth RegistrationService: failed to materialise external user fields for user ' . $user->id, LOG_ERR);
            return -1;
        }
        $user->statut = 0;
        $user->fk_soc = $thirdpartyId;
        $user->fk_socpeople = $contactId;

        $passwordResult = $user->setPassword($admin, $password, 0, 0, 1, 0);
        if ($passwordResult < 0) {
            dol_syslog('SmartAuth RegistrationService: setPassword failed for user ' . $user->id . ': ' . ($user->error ?? ''), LOG_ERR);
            return -1;
        }

        return (int) $user->id;
    }

    /**
     * Generate a Dolibarr login that does not collide with existing ones.
     * Uses the email local-part as a base, then suffixes a counter.
     *
     * @param string $email
     * @return string
     */
    private function generateUniqueLogin(string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9._-]/i', '.', strstr($email, '@', true) ?: $email));
        $base = trim($base, '.-_');
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $suffix = 1;
        while ($this->loginExists($candidate)) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                $candidate = $base . '_' . bin2hex(random_bytes(4));
                break;
            }
        }
        return $candidate;
    }

    /**
     * Check if a Dolibarr login is already used.
     *
     * @param string $login
     * @return bool
     */
    private function loginExists(string $login): bool
    {
        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "user WHERE login = '" . $this->db->escape($login) . "' LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->fetch_object($resql)) {
            return true;
        }
        return false;
    }

    /**
     * Resolve a Dolibarr user object usable as the "author" for create() calls.
     * Falls back to user id 1 (admin) when SMARTAUTH_DEFAULT_USER is not set.
     *
     * @return \User|null
     */
    private function getSystemUser(): ?\User
    {
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }

        $userId = getDolGlobalInt('SMARTAUTH_DEFAULT_USER', 1);
        $user = new \User($this->db);
        $result = $user->fetch($userId);
        if ($result <= 0) {
            dol_syslog('SmartAuth RegistrationService: system user (id=' . $userId . ') not found', LOG_ERR);
            return null;
        }
        return $user;
    }

    /**
     * Send the validation email. Branding (issuer, client name) is added in
     * the body. Returns false on send failure so the caller can rollback.
     *
     * @param string      $to
     * @param string      $plainToken
     * @param int|null    $clientPk
     * @param string|null $continueUrl
     * @return bool
     */
    private function sendValidationEmail(string $to, string $plainToken, ?int $clientPk, ?string $continueUrl): bool
    {
        $issuer = OAuthConfig::getIssuer();
        $confirmUrl = $issuer . '/register/confirm?token=' . urlencode($plainToken);

        $clientName = '';
        $clientLogoUrl = '';
        if ($clientPk !== null && $clientPk > 0) {
            $branding = $this->fetchClientBranding($clientPk);
            if ($branding !== null) {
                $clientName = (string) $branding['name'];
                $clientLogoUrl = (string) $branding['logo_url'];
            }
        }

        [$subject, $textBody, $htmlBody] = $this->renderValidationEmail($confirmUrl, $clientName, $clientLogoUrl);

        if ($this->emailSender !== null) {
            return (bool) call_user_func($this->emailSender, $to, $subject, $textBody, $htmlBody);
        }

        return $this->dispatchEmailViaDolibarr($to, $subject, $textBody, $htmlBody);
    }

    /**
     * Dispatch an email through Dolibarr's CMailFile.
     *
     * @param string $to
     * @param string $subject
     * @param string $textBody
     * @param string $htmlBody
     * @return bool
     */
    private function dispatchEmailViaDolibarr(string $to, string $subject, string $textBody, string $htmlBody): bool
    {
        if (!class_exists('CMailFile')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        }

        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', '');
        if ($from === '') {
            $from = 'no-reply@' . (parse_url(OAuthConfig::getIssuer(), PHP_URL_HOST) ?: 'localhost');
        }

        $mail = new \CMailFile(
            $subject,
            $to,
            $from,
            $htmlBody,
            [],
            [],
            [],
            '',
            '',
            0,
            1,
            '',
            '',
            '',
            '',
            'mail',
            '',
            '',
            '',
            '',
            $textBody
        );

        if (!empty($mail->error)) {
            dol_syslog('SmartAuth RegistrationService: CMailFile init failed: ' . $mail->error, LOG_ERR);
            return false;
        }

        $sent = $mail->sendfile();
        if (!$sent) {
            dol_syslog('SmartAuth RegistrationService: email sendfile failed: ' . ($mail->error ?? 'unknown error'), LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Render the registration validation email (subject + text body + html body).
     *
     * @param string $confirmUrl
     * @param string $clientName
     * @param string $clientLogoUrl
     * @return array{0:string,1:string,2:string}
     */
    private function renderValidationEmail(string $confirmUrl, string $clientName, string $clientLogoUrl): array
    {
        $textTpl = __DIR__ . '/../../tpl/email/register_confirmation.txt.php';
        $htmlTpl = __DIR__ . '/../../tpl/email/register_confirmation.html.php';

        $vars = [
            'confirmUrl' => $confirmUrl,
            'clientName' => $clientName,
            'clientLogoUrl' => $clientLogoUrl,
            'issuer' => OAuthConfig::getIssuer(),
        ];

        $textBody = $this->renderTemplate($textTpl, $vars);
        $htmlBody = $this->renderTemplate($htmlTpl, $vars);

        $subject = $clientName !== ''
            ? 'Confirmez votre adresse e-mail pour ' . $clientName
            : 'Confirmez votre adresse e-mail';

        return [$subject, $textBody, $htmlBody];
    }

    /**
     * Render a simple PHP template into a string.
     *
     * @param string $path
     * @param array  $vars
     * @return string
     */
    private function renderTemplate(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            dol_syslog('SmartAuth RegistrationService: missing email template: ' . $path, LOG_ERR);
            return '';
        }
        ob_start();
        extract($vars, EXTR_SKIP);
        include $path;
        return (string) ob_get_clean();
    }

    /**
     * Fetch (name, logo_url) for the given client row id.
     *
     * @param int $clientPk
     * @return array{name:string,logo_url:string}|null
     */
    private function fetchClientBranding(int $clientPk): ?array
    {
        $sql = "SELECT name, logo_url FROM " . MAIN_DB_PREFIX . "smartauth_oauth_clients WHERE rowid = " . ((int) $clientPk);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'name' => (string) ($obj->name ?? ''),
            'logo_url' => (string) ($obj->logo_url ?? ''),
        ];
    }
}
