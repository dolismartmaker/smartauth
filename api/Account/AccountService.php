<?php

/**
 * AccountService.php
 *
 * Backend logic for the self-service /account page (Lot 7).
 *
 * Provides:
 *   - identity update (firstname, lastname)
 *   - password change (current + new + confirm)
 *   - active sessions listing (non-revoked, non-expired tokens grouped by client)
 *   - per-session revocation by jti
 *   - global revocation (all sessions of the user)
 *
 * The /register flow uses RegistrationService for self-service deletion,
 * so this service stays focused on profile management.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

use SmartAuth\Api\OAuth2\TokenService;

class AccountService
{
    public const ERR_USER_NOT_FOUND = -1;
    public const ERR_CURRENT_PASSWORD_WRONG = -2;
    public const ERR_WEAK_PASSWORD = -3;
    public const ERR_PASSWORD_MISMATCH = -4;
    public const ERR_INTERNAL = -5;
    public const ERR_TOKEN_NOT_FOUND = -6;

    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var TokenService
     */
    private $tokens;

    public function __construct($db)
    {
        $this->db = $db;
        $this->tokens = new TokenService($db);
    }

    /**
     * Update first/last name on a Dolibarr user.
     *
     * @param int    $fkUser
     * @param string $firstname
     * @param string $lastname
     * @return int User id on success, negative error code otherwise
     */
    public function updateIdentity(int $fkUser, string $firstname, string $lastname): int
    {
        if ($fkUser <= 0) {
            return self::ERR_USER_NOT_FOUND;
        }

        $user = $this->fetchUser($fkUser);
        if ($user === null) {
            return self::ERR_USER_NOT_FOUND;
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return self::ERR_INTERNAL;
        }

        $user->firstname = $firstname;
        $user->lastname = $lastname;

        $result = $user->update($admin);
        if ($result <= 0) {
            dol_syslog('[SmartAuth] AccountService: identity update failed for user ' . $fkUser . ': ' . ($user->error ?? ''), LOG_ERR);
            return self::ERR_INTERNAL;
        }

        dol_syslog('[SmartAuth] AccountService: identity updated for user ' . $fkUser, LOG_INFO);
        return $fkUser;
    }

    /**
     * Change a user's password. Requires the current password to be supplied
     * and verified before applying the new one.
     *
     * @param int    $fkUser
     * @param string $currentPassword
     * @param string $newPassword
     * @param string $newPasswordConfirm
     * @return int User id on success, negative error code otherwise
     */
    public function changePassword(int $fkUser, string $currentPassword, string $newPassword, string $newPasswordConfirm): int
    {
        if ($newPassword !== $newPasswordConfirm) {
            return self::ERR_PASSWORD_MISMATCH;
        }
        if (!RegistrationService::isPasswordStrongEnough($newPassword)) {
            return self::ERR_WEAK_PASSWORD;
        }

        $user = $this->fetchUser($fkUser);
        if ($user === null) {
            return self::ERR_USER_NOT_FOUND;
        }

        if (!$this->verifyCurrentPassword($user, $currentPassword)) {
            dol_syslog('[SmartAuth] AccountService: current password verification failed for user ' . $fkUser, LOG_INFO);
            return self::ERR_CURRENT_PASSWORD_WRONG;
        }

        $admin = $this->getSystemUser();
        if ($admin === null) {
            return self::ERR_INTERNAL;
        }

        $result = $user->setPassword($admin, $newPassword, 0, 0, 1, 0);
        if ($result < 0) {
            dol_syslog('[SmartAuth] AccountService: setPassword failed for user ' . $fkUser . ': ' . ($user->error ?? ''), LOG_ERR);
            return self::ERR_INTERNAL;
        }

        dol_syslog('[SmartAuth] AccountService: password changed for user ' . $fkUser, LOG_INFO);
        return $fkUser;
    }

    /**
     * List active OAuth2 sessions for a user, grouped by client.
     *
     * Returns an array of:
     *   [
     *     'client_pk' => int,
     *     'client_id' => string,
     *     'client_name' => string,
     *     'client_logo' => string,
     *     'tokens' => [
     *        ['jti' => string|null, 'token_type' => 'access'|'refresh',
     *         'datec' => int, 'expires_at' => int, 'token_hash' => string],
     *        ...
     *     ],
     *   ]
     *
     * @param int $fkUser
     * @return array
     */
    public function listActiveSessions(int $fkUser): array
    {
        if ($fkUser <= 0) {
            return [];
        }

        $sql = "SELECT t.rowid, t.jti, t.token_type, t.datec, t.expires_at, t.token_hash, t.fk_client,";
        $sql .= " c.client_id AS oauth_client_id, c.name AS oauth_name, c.logo_url AS oauth_logo";
        $sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens t";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "smartauth_oauth_clients c ON c.rowid = t.fk_client";
        $sql .= " WHERE t.fk_user = " . ((int) $fkUser);
        $sql .= " AND t.revoked_at IS NULL";
        $sql .= " AND t.expires_at > '" . $this->db->idate(dol_now()) . "'";
        $sql .= " ORDER BY t.fk_client ASC, t.datec DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return [];
        }

        $byClient = [];
        while (($obj = $this->db->fetch_object($resql))) {
            $clientPk = (int) $obj->fk_client;
            if (!isset($byClient[$clientPk])) {
                $byClient[$clientPk] = [
                    'client_pk' => $clientPk,
                    'client_id' => (string) ($obj->oauth_client_id ?? ''),
                    'client_name' => (string) ($obj->oauth_name ?? ''),
                    'client_logo' => (string) ($obj->oauth_logo ?? ''),
                    'tokens' => [],
                ];
            }
            $byClient[$clientPk]['tokens'][] = [
                'rowid' => (int) $obj->rowid,
                'jti' => $obj->jti !== null ? (string) $obj->jti : null,
                'token_type' => (string) $obj->token_type,
                'datec' => is_numeric($obj->datec) ? (int) $obj->datec : strtotime((string) $obj->datec),
                'expires_at' => is_numeric($obj->expires_at) ? (int) $obj->expires_at : strtotime((string) $obj->expires_at),
                'token_hash' => (string) $obj->token_hash,
            ];
        }

        return array_values($byClient);
    }

    /**
     * Revoke all tokens of a user across all clients.
     *
     * @param int $fkUser
     * @return int Number of tokens revoked, or -1 on error
     */
    public function revokeAllSessions(int $fkUser): int
    {
        return $this->tokens->revokeAllForUser($fkUser);
    }

    /**
     * Revoke a single session identified by token row id, owned by the user.
     *
     * @param int $fkUser
     * @param int $tokenRowId
     * @return int 1 on success, negative error code otherwise
     */
    public function revokeSessionByRowId(int $fkUser, int $tokenRowId): int
    {
        if ($fkUser <= 0 || $tokenRowId <= 0) {
            return self::ERR_TOKEN_NOT_FOUND;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE rowid = " . ((int) $tokenRowId);
        $sql .= " AND fk_user = " . ((int) $fkUser);
        $sql .= " AND revoked_at IS NULL";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return self::ERR_INTERNAL;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return self::ERR_TOKEN_NOT_FOUND;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " SET revoked_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE rowid = " . ((int) $tokenRowId);
        $sql .= " AND fk_user = " . ((int) $fkUser);
        $sql .= " AND revoked_at IS NULL";

        if (!$this->db->query($sql)) {
            return self::ERR_INTERNAL;
        }
        dol_syslog('[SmartAuth] AccountService: session ' . $tokenRowId . ' revoked for user ' . $fkUser, LOG_INFO);
        return 1;
    }

    /**
     * Verify the user-supplied current password against Dolibarr's stored hash.
     *
     * @param \User  $user
     * @param string $candidate
     * @return bool
     */
    private function verifyCurrentPassword(\User $user, string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }
        if (!empty($user->pass_indatabase_crypted) && function_exists('dol_verifyHash')) {
            return (bool) dol_verifyHash($candidate, (string) $user->pass_indatabase_crypted);
        }
        if (!empty($user->pass_indatabase)) {
            return hash_equals((string) $user->pass_indatabase, $candidate);
        }
        return false;
    }

    /**
     * Fetch a Dolibarr user by row id, or null if not found.
     *
     * @param int $fkUser
     * @return \User|null
     */
    private function fetchUser(int $fkUser): ?\User
    {
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }
        $user = new \User($this->db);
        if ($user->fetch($fkUser) <= 0) {
            return null;
        }
        return $user;
    }

    /**
     * Resolve the system user used to call Dolibarr ->update / ->setPassword.
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
        if ($user->fetch($userId) <= 0) {
            dol_syslog('[SmartAuth] AccountService: system user (id=' . $userId . ') not found', LOG_ERR);
            return null;
        }
        return $user;
    }
}
