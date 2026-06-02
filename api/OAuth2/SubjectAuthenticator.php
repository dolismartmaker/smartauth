<?php

/**
 * SubjectAuthenticator.php
 *
 * Resolves a login/password pair into a TokenSubject (account or user),
 * according to the configured authentication sources.
 *
 * See documentation/SPEC_SMARTAUTH_SUBJECT.md. The primary subject is a billed
 * client's portal account (llx_societe_account); an association member
 * (llx_adherent) and a Dolibarr user (llx_user) are the other sources. Which
 * bases are tried is driven by three independent on/off toggles, with a FIXED
 * priority order account > adherent > user:
 *   - SMARTAUTH_AUTH_SOURCE_ACCOUNT  : '1'/'0' (default on)
 *   - SMARTAUTH_AUTH_SOURCE_ADHERENT : '1'/'0' (default off)
 *   - SMARTAUTH_AUTH_SOURCE_USER     : '1'/'0' (default on)
 *   - SMARTAUTH_AUTH_SITES           : CSV of accepted llx_societe_account.site
 *     values (default 'smartauth')
 *
 * Backward compatibility: if none of the three toggles has ever been saved, the
 * legacy enum SMARTAUTH_AUTH_SOURCE ('societe_account' | 'user' | 'both') is
 * read instead (adherent stays off in that case).
 *
 * Phase 2: this class is additive scaffolding. It is integration-tested but not
 * yet wired into LoginController; the live login path is unchanged until the
 * Phase 3 cutover, where LoginController will delegate here and the pipeline
 * starts carrying a TokenSubject end-to-end.
 *
 * The user-credential logic mirrors LoginController::authenticateUser(), which
 * stays the source of truth until Phase 3 consolidates the two.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

final class SubjectAuthenticator
{
    public const SOURCE_ACCOUNT = 'societe_account';
    public const SOURCE_ADHERENT = 'adherent';
    public const SOURCE_USER = 'user';
    public const SOURCE_BOTH = 'both';

    /** Sentinel telling a toggle constant apart from an unset one. */
    private const TOGGLE_UNSET = '__smartauth_unset__';

    /** @var \DoliDB */
    private $db;

    /**
     * @param \DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Resolve credentials into a TokenSubject, or null on failure.
     *
     * @param string $username login (societe_account.login, or user login/email)
     * @param string $password plain text password
     * @return TokenSubject|null
     */
    public function authenticate($username, $password)
    {
        $username = (string) $username;
        $password = (string) $password;

        foreach ($this->enabledSources() as $source) {
            if ($source === self::SOURCE_ACCOUNT) {
                $subject = $this->authenticateAccount($username, $password);
            } elseif ($source === self::SOURCE_ADHERENT) {
                $subject = $this->authenticateAdherent($username, $password);
            } else {
                $subject = $this->authenticateUser($username, $password);
            }
            if ($subject !== null) {
                return $subject;
            }
        }

        // Single timing-equalisation dummy verify on the "no match" path. Kept
        // out of the per-source methods so the response time does not grow with
        // the number of enabled sources (which would leak the configuration).
        password_verify($password, self::getDummyBcryptHash());
        return null;
    }

    /**
     * Authentication sources to try, in fixed priority order
     * (account > adherent > user), filtered by the enabled toggles.
     *
     * @return string[] subset of [SOURCE_ACCOUNT, SOURCE_ADHERENT, SOURCE_USER]
     */
    public function enabledSources()
    {
        $toggles = $this->readToggles();
        $ordered = [self::SOURCE_ACCOUNT, self::SOURCE_ADHERENT, self::SOURCE_USER];

        $out = [];
        foreach ($ordered as $source) {
            if (!empty($toggles[$source])) {
                $out[] = $source;
            }
        }
        return $out;
    }

    /**
     * Resolve the three on/off source toggles. If at least one has been saved,
     * the toggles win; otherwise fall back to the legacy SMARTAUTH_AUTH_SOURCE
     * enum (adherent off in that case).
     *
     * @return array<string, bool>
     */
    private function readToggles()
    {
        $acc = getDolGlobalString('SMARTAUTH_AUTH_SOURCE_ACCOUNT', self::TOGGLE_UNSET);
        $mbr = getDolGlobalString('SMARTAUTH_AUTH_SOURCE_ADHERENT', self::TOGGLE_UNSET);
        $usr = getDolGlobalString('SMARTAUTH_AUTH_SOURCE_USER', self::TOGGLE_UNSET);

        $anyPresent = ($acc !== self::TOGGLE_UNSET || $mbr !== self::TOGGLE_UNSET || $usr !== self::TOGGLE_UNSET);

        if ($anyPresent) {
            return [
                self::SOURCE_ACCOUNT => ($acc !== self::TOGGLE_UNSET && (string) $acc === '1'),
                self::SOURCE_ADHERENT => ($mbr !== self::TOGGLE_UNSET && (string) $mbr === '1'),
                self::SOURCE_USER => ($usr !== self::TOGGLE_UNSET && (string) $usr === '1'),
            ];
        }

        $legacy = $this->authSource();
        return [
            self::SOURCE_ACCOUNT => ($legacy === self::SOURCE_ACCOUNT || $legacy === self::SOURCE_BOTH),
            self::SOURCE_ADHERENT => false,
            self::SOURCE_USER => ($legacy === self::SOURCE_USER || $legacy === self::SOURCE_BOTH),
        ];
    }

    /**
     * Configured authentication source. Defaults to 'both'.
     *
     * @return string self::SOURCE_*
     */
    public function authSource()
    {
        $value = (string) getDolGlobalString('SMARTAUTH_AUTH_SOURCE', self::SOURCE_BOTH);
        $value = strtolower(trim($value));
        if (!in_array($value, [self::SOURCE_ACCOUNT, self::SOURCE_USER, self::SOURCE_BOTH], true)) {
            return self::SOURCE_BOTH;
        }
        return $value;
    }

    /**
     * Accepted llx_societe_account.site values. Defaults to ['smartauth'].
     *
     * @return string[]
     */
    public function authSites()
    {
        $raw = (string) getDolGlobalString('SMARTAUTH_AUTH_SITES', 'smartauth');
        $sites = [];
        foreach (explode(',', $raw) as $site) {
            $site = trim($site);
            if ($site !== '') {
                $sites[] = $site;
            }
        }
        if (empty($sites)) {
            $sites[] = 'smartauth';
        }
        return $sites;
    }

    /**
     * Authenticate against llx_societe_account (the billed client portal base).
     *
     * @param string $username
     * @param string $password
     * @return TokenSubject|null
     */
    private function authenticateAccount($username, $password)
    {
        global $conf;

        $sites = $this->authSites();
        $escapedSites = [];
        foreach ($sites as $site) {
            $escapedSites[] = "'" . $this->db->escape($site) . "'";
        }

        $sql = 'SELECT rowid, pass_crypted, fk_soc FROM ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= " WHERE login = '" . $this->db->escape($username) . "'";
        $sql .= ' AND site IN (' . implode(',', $escapedSites) . ')';
        $sql .= ' AND status = 1';
        $sql .= ' AND entity = ' . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SubjectAuthenticator::authenticateAccount query failed: ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $matched = null;
        while ($obj = $this->db->fetch_object($resql)) {
            $hash = (string) $obj->pass_crypted;
            // A portal account with no password set (e.g. admin-created, awaiting
            // the user's first password) can never authenticate.
            if ($hash !== '' && dol_verifyHash($password, $hash)) {
                $matched = $obj;
                break;
            }
        }
        $this->db->free($resql);

        if ($matched === null) {
            return null;
        }

        return TokenSubject::account((int) $matched->rowid, (int) $matched->fk_soc);
    }

    /**
     * Authenticate against llx_adherent (association members). Mirrors
     * authenticateAccount but on the adherent table: no `site` filter (the
     * column does not exist there) and the validated-state column is `statut`.
     *
     * @param string $username adherent login
     * @param string $password
     * @return TokenSubject|null
     */
    private function authenticateAdherent($username, $password)
    {
        global $conf;

        $sql = 'SELECT rowid, pass_crypted, fk_soc FROM ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= " WHERE login = '" . $this->db->escape($username) . "'";
        $sql .= ' AND statut = 1';
        $sql .= ' AND entity = ' . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SubjectAuthenticator::authenticateAdherent query failed: ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $matched = null;
        while ($obj = $this->db->fetch_object($resql)) {
            $hash = (string) $obj->pass_crypted;
            if ($hash !== '' && dol_verifyHash($password, $hash)) {
                $matched = $obj;
                break;
            }
        }
        $this->db->free($resql);

        if ($matched === null) {
            return null;
        }

        return TokenSubject::member((int) $matched->rowid, (int) $matched->fk_soc);
    }

    /**
     * Authenticate against llx_user (internal staff / external users).
     *
     * Mirrors LoginController::authenticateUser() (source of truth until the
     * Phase 3 consolidation). Returns a `user` subject carrying the user's
     * societe (socid) as fkSoc -- 0 for internal staff, which makes the subject
     * an internal user that bypasses the capsso contract gating.
     *
     * @param string $username
     * @param string $password
     * @return TokenSubject|null
     */
    private function authenticateUser($username, $password)
    {
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

        $user = new \User($this->db);

        $result = $user->fetch('', $username);
        if ($result <= 0) {
            $result = $user->fetch('', '', '', 0, -1, $username);
        }

        if ($result <= 0) {
            return null;
        }

        if ($user->statut != 1) {
            return null;
        }

        $storedHash = $user->pass_indatabase_crypted;
        if (empty($storedHash)) {
            return null;
        }

        if (!password_verify($password, $storedHash)) {
            if (!hash_equals($storedHash, md5($password))) {
                return null;
            }
            dol_syslog(
                'SubjectAuthenticator: legacy MD5 password matched for user_id=' . (int) $user->id
                . ' - upgrade Dolibarr password storage (MAIN_SECURITY_USE_PASSWORD_HASH=1)',
                LOG_WARNING
            );
        }

        return TokenSubject::user((int) $user->id, (int) $user->socid);
    }

    /**
     * Stable per-process dummy bcrypt hash, used to equalise password-verify
     * timing on the "not found" path. Same technique as LoginController.
     *
     * @return string
     */
    private static function getDummyBcryptHash()
    {
        static $dummy = null;
        if ($dummy === null) {
            $dummy = password_hash('SmartAuthDummyTimingHash:' . random_bytes(16), PASSWORD_BCRYPT);
        }
        return $dummy;
    }
}
