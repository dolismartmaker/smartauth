<?php

/**
 * TokenSubject.php
 *
 * Immutable value object modelling the subject of a SmartAuth token.
 *
 * Historically the subject was always a Dolibarr user (llx_user). The target
 * design (see documentation/SPEC_SMARTAUTH_SUBJECT.md) makes the primary
 * subject a billed client's portal account (llx_societe_account), with the
 * Dolibarr user kept as the exception (internal staff).
 *
 * A subject is the couple (type, id) plus the owning company (fkSoc):
 *   - account : id = llx_societe_account.rowid, the common case
 *   - member  : id = llx_adherent.rowid, an association member portal account
 *   - user    : id = llx_user.rowid, the exception
 *
 * The OIDC `sub` claim is encoded as an opaque, prefixed string so the
 * id-spaces never collide and the type is self-describing on the consumer
 * side: "acc:<rowid>" / "mbr:<rowid>" / "usr:<rowid>".
 *
 * `member` is handled exactly like `account` across the pipeline (an external
 * subject, no \User, no Dolibarr groups/roles, no persisted consent) except
 * for: its backing table (llx_adherent, column `statut`), the absence of a
 * `site` filter at authentication, and the fact that an adherent carries a
 * first/last name (so member claims can expose given_name/family_name).
 *
 * Phase 1: this object is scaffolding. It is unit-tested but not yet wired
 * into live token issuance/validation (that happens in Phases 2-3). Until
 * then, production keeps emitting/accepting the legacy numeric `sub`.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

final class TokenSubject
{
    public const TYPE_ACCOUNT = 'account';
    public const TYPE_MEMBER = 'member';
    public const TYPE_USER = 'user';

    private const PREFIX_ACCOUNT = 'acc:';
    private const PREFIX_MEMBER = 'mbr:';
    private const PREFIX_USER = 'usr:';

    /** @var string self::TYPE_ACCOUNT | self::TYPE_MEMBER | self::TYPE_USER */
    private $type;

    /** @var int rowid in the table matching $type */
    private $id;

    /** @var int owning societe (0 when not applicable, e.g. internal user) */
    private $fkSoc;

    /**
     * @param string $type  self::TYPE_ACCOUNT | self::TYPE_MEMBER | self::TYPE_USER
     * @param int    $id     positive rowid
     * @param int    $fkSoc  owning societe rowid (0 if none)
     */
    private function __construct($type, $id, $fkSoc)
    {
        $this->type = $type;
        $this->id = (int) $id;
        $this->fkSoc = (int) $fkSoc;
    }

    /**
     * Build an `account` subject (llx_societe_account portal account).
     *
     * @param int $accountId llx_societe_account.rowid
     * @param int $fkSoc      owning societe rowid
     * @return self
     */
    public static function account($accountId, $fkSoc)
    {
        $accountId = (int) $accountId;
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('TokenSubject::account requires a positive account id');
        }
        return new self(self::TYPE_ACCOUNT, $accountId, (int) $fkSoc);
    }

    /**
     * Build a `member` subject (llx_adherent portal account).
     *
     * @param int $adherentId llx_adherent.rowid
     * @param int $fkSoc        owning societe rowid (llx_adherent.fk_soc, 0 if none)
     * @return self
     */
    public static function member($adherentId, $fkSoc)
    {
        $adherentId = (int) $adherentId;
        if ($adherentId <= 0) {
            throw new \InvalidArgumentException('TokenSubject::member requires a positive adherent id');
        }
        return new self(self::TYPE_MEMBER, $adherentId, (int) $fkSoc);
    }

    /**
     * Build a `user` subject (llx_user). $fkSoc is the user's societe for an
     * external user, or 0 for internal staff.
     *
     * @param int $userId llx_user.rowid
     * @param int $fkSoc   owning societe rowid (0 for internal staff)
     * @return self
     */
    public static function user($userId, $fkSoc = 0)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            throw new \InvalidArgumentException('TokenSubject::user requires a positive user id');
        }
        return new self(self::TYPE_USER, $userId, (int) $fkSoc);
    }

    /**
     * @return string self::TYPE_ACCOUNT | self::TYPE_USER
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int rowid in the table matching getType()
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return int owning societe rowid (0 if none)
     */
    public function getFkSoc()
    {
        return $this->fkSoc;
    }

    /**
     * @return bool
     */
    public function isAccount()
    {
        return $this->type === self::TYPE_ACCOUNT;
    }

    /**
     * @return bool
     */
    public function isMember()
    {
        return $this->type === self::TYPE_MEMBER;
    }

    /**
     * @return bool
     */
    public function isUser()
    {
        return $this->type === self::TYPE_USER;
    }

    /**
     * Internal staff = a `user` subject not attached to any societe. Such a
     * subject bypasses the capsso contract gating (it has no client contract).
     * See SPEC_SMARTAUTH_SUBJECT.md section 8.
     *
     * @return bool
     */
    public function isInternalUser()
    {
        return $this->isUser() && $this->fkSoc <= 0;
    }

    /**
     * Encode the subject as the opaque, prefixed OIDC `sub` claim.
     *
     * @return string "acc:<id>", "mbr:<id>" or "usr:<id>"
     */
    public function toSub()
    {
        if ($this->isAccount()) {
            $prefix = self::PREFIX_ACCOUNT;
        } elseif ($this->isMember()) {
            $prefix = self::PREFIX_MEMBER;
        } else {
            $prefix = self::PREFIX_USER;
        }
        return $prefix . $this->id;
    }

    /**
     * Decode a prefixed `sub` claim back into a subject. The owning societe is
     * not carried in `sub`; it is resolved separately when needed, so fkSoc is
     * 0 on the returned object.
     *
     * Per SPEC_SMARTAUTH_SUBJECT.md decision 2, a legacy numeric `sub` (or any
     * value without a known prefix) is rejected -- there is no silent fallback.
     *
     * @param string $sub
     * @return self
     * @throws \InvalidArgumentException on an empty/unknown/malformed value
     */
    public static function fromSub($sub)
    {
        $sub = (string) $sub;

        $map = [
            self::PREFIX_ACCOUNT => self::TYPE_ACCOUNT,
            self::PREFIX_MEMBER => self::TYPE_MEMBER,
            self::PREFIX_USER => self::TYPE_USER,
        ];
        foreach ($map as $prefix => $type) {
            if (strpos($sub, $prefix) === 0) {
                $rest = substr($sub, strlen($prefix));
                if ($rest === '' || !ctype_digit($rest)) {
                    throw new \InvalidArgumentException('TokenSubject::fromSub got a malformed sub: ' . $sub);
                }
                $id = (int) $rest;
                if ($id <= 0) {
                    throw new \InvalidArgumentException('TokenSubject::fromSub got a non-positive id: ' . $sub);
                }
                return new self($type, $id, 0);
            }
        }

        throw new \InvalidArgumentException('TokenSubject::fromSub got an unprefixed/unknown sub: ' . $sub);
    }

    /**
     * Rebuild a subject from the columns stored on an oauth code/token row,
     * resolving the owning societe (fkSoc) which those rows do not carry:
     *   - account : fkSoc = llx_societe_account.fk_soc
     *   - member  : fkSoc = llx_adherent.fk_soc
     *   - user    : fkSoc = llx_user.fk_soc (socid)
     *
     * @param \DoliDB     $db
     * @param string      $subjectType      'account', 'member' or 'user'
     * @param int         $fkUser           llx_user.rowid (0 for account/member)
     * @param int|null    $fkSocieteAccount llx_societe_account.rowid (null otherwise)
     * @param int|null    $fkAdherent       llx_adherent.rowid (null otherwise)
     * @return self
     */
    public static function fromRecord($db, $subjectType, $fkUser, $fkSocieteAccount, $fkAdherent = null)
    {
        if ($subjectType === self::TYPE_ACCOUNT) {
            $accountId = (int) $fkSocieteAccount;
            $fkSoc = 0;
            $sql = 'SELECT fk_soc FROM ' . MAIN_DB_PREFIX . 'societe_account WHERE rowid = ' . $accountId;
            $resql = $db->query($sql);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                $db->free($resql);
                if (is_object($obj)) {
                    $fkSoc = (int) $obj->fk_soc;
                }
            }
            return self::account($accountId, $fkSoc);
        }

        if ($subjectType === self::TYPE_MEMBER) {
            $adherentId = (int) $fkAdherent;
            $fkSoc = 0;
            $sql = 'SELECT fk_soc FROM ' . MAIN_DB_PREFIX . 'adherent WHERE rowid = ' . $adherentId;
            $resql = $db->query($sql);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                $db->free($resql);
                if (is_object($obj)) {
                    $fkSoc = (int) $obj->fk_soc;
                }
            }
            return self::member($adherentId, $fkSoc);
        }

        $userId = (int) $fkUser;
        $fkSoc = 0;
        $sql = 'SELECT fk_soc FROM ' . MAIN_DB_PREFIX . 'user WHERE rowid = ' . $userId;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $db->free($resql);
            if (is_object($obj)) {
                $fkSoc = (int) $obj->fk_soc;
            }
        }
        return self::user($userId, $fkSoc);
    }

    /**
     * Whether the underlying record is still enabled (re-validation at token
     * use). For a user: llx_user.statut == 1. For an account:
     * llx_societe_account.status == 1. For a member: llx_adherent.statut == 1
     * (a validated member; the column is `statut`, not `status`).
     *
     * @param \DoliDB $db
     * @return bool
     */
    public function isActive($db)
    {
        if ($this->isUser()) {
            $user = new \User($db);
            $res = $user->fetch($this->id);
            return $res > 0 && (int) $user->statut === 1;
        }

        if ($this->isMember()) {
            $sql = 'SELECT statut FROM ' . MAIN_DB_PREFIX . 'adherent';
            $sql .= ' WHERE rowid = ' . $this->id;
            $resql = $db->query($sql);
            if (!$resql) {
                dol_syslog('[SmartAuth] TokenSubject::isActive (member) query failed: ' . $db->lasterror(), LOG_ERR);
                return false;
            }
            $obj = $db->fetch_object($resql);
            $db->free($resql);
            return is_object($obj) && (int) $obj->statut === 1;
        }

        // account
        $sql = 'SELECT status FROM ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' WHERE rowid = ' . $this->id;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] TokenSubject::isActive query failed: ' . $db->lasterror(), LOG_ERR);
            return false;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return is_object($obj) && (int) $obj->status === 1;
    }

    /**
     * Build the identity claims for this subject, filtered by granted scopes.
     *
     * Always returns `sub` (the prefixed identifier). The `profile` and `email`
     * scopes add the corresponding OIDC claims, sourced polymorphically:
     *   - user    : from the llx_user record (firstname/lastname/email).
     *   - account : email from llx_societe_account.login, display name from the
     *               owning societe raison sociale (a portal account has no
     *               given/family name -- it represents a company login).
     *
     * Dolibarr-internal `groups` / `roles` claims are NOT handled here: they are
     * user-only and the callers keep their existing helpers for them. This
     * method centralises only the part that differs by subject type.
     *
     * @param \DoliDB  $db
     * @param string[] $scopes
     * @return array<string, mixed>
     */
    public function buildClaims($db, array $scopes)
    {
        if ($this->isUser()) {
            return $this->buildUserClaims($db, $scopes);
        }
        if ($this->isMember()) {
            return $this->buildMemberClaims($db, $scopes);
        }
        return $this->buildAccountClaims($db, $scopes);
    }

    /**
     * @param \DoliDB  $db
     * @param string[] $scopes
     * @return array<string, mixed>
     */
    private function buildUserClaims($db, array $scopes)
    {
        $claims = ['sub' => $this->toSub()];

        $user = new \User($db);
        if ($user->fetch($this->id) <= 0) {
            return $claims;
        }

        if (in_array('profile', $scopes, true)) {
            $fullName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
            if ($fullName !== '') {
                $claims['name'] = $fullName;
            }
            if (!empty($user->lastname)) {
                $claims['family_name'] = $user->lastname;
            }
            if (!empty($user->firstname)) {
                $claims['given_name'] = $user->firstname;
            }
            if (!empty($user->datec)) {
                $updatedAt = is_numeric($user->datec) ? (int) $user->datec : (int) strtotime($user->datec);
                if ($updatedAt > 0) {
                    $claims['updated_at'] = $updatedAt;
                }
            }
        }

        if (in_array('email', $scopes, true) && !empty($user->email)) {
            $claims['email'] = $user->email;
            // Dolibarr does not track email verification; assume verified.
            $claims['email_verified'] = true;
        }

        return $claims;
    }

    /**
     * @param \DoliDB  $db
     * @param string[] $scopes
     * @return array<string, mixed>
     */
    private function buildAccountClaims($db, array $scopes)
    {
        $claims = ['sub' => $this->toSub()];

        $sql = 'SELECT login, fk_soc FROM ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' WHERE rowid = ' . $this->id;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] TokenSubject::buildAccountClaims query failed: ' . $db->lasterror(), LOG_ERR);
            return $claims;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        if (!is_object($obj)) {
            return $claims;
        }

        $login = (string) $obj->login;
        $fkSoc = (int) $obj->fk_soc;

        if (in_array('email', $scopes, true) && $login !== '') {
            $claims['email'] = $login;
            $claims['email_verified'] = true;
        }

        if (in_array('profile', $scopes, true) && $fkSoc > 0) {
            $name = $this->fetchSocieteName($db, $fkSoc);
            if ($name !== '') {
                $claims['name'] = $name;
            }
        }

        return $claims;
    }

    /**
     * Build claims for a `member` subject (llx_adherent). Unlike a company
     * portal account, an adherent carries a first/last name, so the `profile`
     * scope can expose given_name/family_name. Email comes from the adherent
     * record (email column, falling back to login).
     *
     * @param \DoliDB  $db
     * @param string[] $scopes
     * @return array<string, mixed>
     */
    private function buildMemberClaims($db, array $scopes)
    {
        $claims = ['sub' => $this->toSub()];

        $sql = 'SELECT firstname, lastname, email, login FROM ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' WHERE rowid = ' . $this->id;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] TokenSubject::buildMemberClaims query failed: ' . $db->lasterror(), LOG_ERR);
            return $claims;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        if (!is_object($obj)) {
            return $claims;
        }

        if (in_array('profile', $scopes, true)) {
            $fullName = trim(((string) ($obj->firstname ?? '')) . ' ' . ((string) ($obj->lastname ?? '')));
            if ($fullName !== '') {
                $claims['name'] = $fullName;
            }
            if (!empty($obj->lastname)) {
                $claims['family_name'] = (string) $obj->lastname;
            }
            if (!empty($obj->firstname)) {
                $claims['given_name'] = (string) $obj->firstname;
            }
        }

        if (in_array('email', $scopes, true)) {
            $email = (string) ($obj->email ?? '');
            if ($email === '') {
                $email = (string) ($obj->login ?? '');
            }
            if ($email !== '') {
                $claims['email'] = $email;
                $claims['email_verified'] = true;
            }
        }

        return $claims;
    }

    /**
     * @param \DoliDB $db
     * @param int     $fkSoc
     * @return string raison sociale, or '' if not found
     */
    private function fetchSocieteName($db, $fkSoc)
    {
        $sql = 'SELECT nom FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid = ' . (int) $fkSoc;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] TokenSubject::fetchSocieteName query failed: ' . $db->lasterror(), LOG_ERR);
            return '';
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return is_object($obj) ? (string) $obj->nom : '';
    }

    /**
     * @param self $other
     * @return bool
     */
    public function equals(self $other)
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
