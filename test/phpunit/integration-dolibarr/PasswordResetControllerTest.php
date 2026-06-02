<?php

/**
 * Integration tests for PasswordResetController.
 *
 * The reset flow is subject-aware: it resolves an email to a TokenSubject
 * across the enabled sources (account / member / user), stores a single-use
 * token in llx_smartauth_email_validation (no longer in llx_user.pass_temp),
 * and writes the new password on the right backing table.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\PasswordResetController;
use SmartAuth\Api\Account\EmailValidationToken;

/**
 * @covers \SmartAuth\Api\PasswordResetController
 */
class PasswordResetControllerTest extends DolibarrRealTestCase
{
    private PasswordResetController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new PasswordResetController();

        // Clean rate limit + reset tokens for password reset tests.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit WHERE action LIKE 'password_reset%'");
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "smartauth_email_validation WHERE purpose = 'password_reset'");
    }

    protected function tearDown(): void
    {
        global $conf;
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT);
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT);
        unset($conf->global->SMARTAUTH_AUTH_SOURCE_USER);
        parent::tearDown();
    }

    // ==================== requestReset() validation ====================

    public function testRequestResetWithEmptyEmailReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => '']);
        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    public function testRequestResetWithNullEmailReturns400(): void
    {
        $result = $this->controller->requestReset([]);
        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    public function testRequestResetWithInvalidEmailFormatReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => 'not-an-email']);
        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Invalid email format', $result[0]['message']);
    }

    public function testRequestResetWithMissingDomainReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => 'test@']);
        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Invalid email format', $result[0]['message']);
    }

    public function testRequestResetWithWhitespaceEmailReturns400(): void
    {
        $result = $this->controller->requestReset(['email' => '   ']);
        $this->assertEquals(400, $result[1]);
        $this->assertEquals('Email is required', $result[0]['message']);
    }

    public function testRequestResetWithNonExistentEmailReturns200(): void
    {
        $result = $this->controller->requestReset(['email' => 'nonexistent@example.com']);
        $this->assertEquals(200, $result[1]);
        $this->assertStringContainsString('If this email exists', $result[0]['message']);
    }

    public function testRequestResetRateLimitingBlocks(): void
    {
        $email = 'ratelimit@example.com';
        for ($i = 0; $i < 3; $i++) {
            $result = $this->controller->requestReset(['email' => $email]);
            $this->assertEquals(200, $result[1], "Request $i should succeed");
        }
        $result = $this->controller->requestReset(['email' => $email]);
        $this->assertEquals(429, $result[1]);
        $this->assertStringContainsString('Too many requests', $result[0]['message']);
        $this->assertArrayHasKey('retry_after', $result[0]);
    }

    public function testRequestResetRecordsAttempt(): void
    {
        $email = 'record@example.com';
        $this->controller->requestReset(['email' => $email]);

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "smartauth_ratelimit";
        $sql .= " WHERE identifier = '" . $this->db->escape($email) . "'";
        $sql .= " AND action = 'password_reset'";
        $obj = $this->db->fetch_object($this->db->query($sql));
        $this->assertGreaterThan(0, (int) $obj->cnt);
    }

    // ==================== requestReset() per subject ====================

    public function testRequestResetWithExistingUserStoresToken(): void
    {
        $testUser = $this->createTestUser(['email' => 'resettest@example.com', 'statut' => 1]);

        $result = $this->controller->requestReset(['email' => 'resettest@example.com']);
        $this->assertEquals(200, $result[1]);

        $this->assertSame(1, $this->countResetTokens('user', (int) $testUser->id));
    }

    public function testRequestResetTrimsEmailWhitespace(): void
    {
        $testUser = $this->createTestUser(['email' => 'trimtest@example.com', 'statut' => 1]);

        $result = $this->controller->requestReset(['email' => '  trimtest@example.com  ']);
        $this->assertEquals(200, $result[1]);

        $this->assertSame(1, $this->countResetTokens('user', (int) $testUser->id));
    }

    public function testRequestResetWithInactiveUserDoesNotStoreToken(): void
    {
        $testUser = $this->createTestUser(['email' => 'inactive@example.com', 'statut' => 0]);

        $result = $this->controller->requestReset(['email' => 'inactive@example.com']);
        $this->assertEquals(200, $result[1]); // anti-enumeration

        $this->assertSame(0, $this->countResetTokens('user', (int) $testUser->id));
    }

    public function testRequestResetMatchesViaContactEmail(): void
    {
        $contactEmail = 'contactonly_' . uniqid() . '@example.com';

        $testUser = $this->createTestUser(['email' => 'placeholder_' . uniqid() . '@example.com', 'statut' => 1]);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "user SET email = '' WHERE rowid = " . (int) $testUser->id);

        $contactId = $this->createContactWithEmail($contactEmail);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "user SET fk_socpeople = " . (int) $contactId . " WHERE rowid = " . (int) $testUser->id);

        $result = $this->controller->requestReset(['email' => $contactEmail]);
        $this->assertEquals(200, $result[1]);

        // Token stored for the user subject even though user.email is empty.
        $this->assertSame(1, $this->countResetTokens('user', (int) $testUser->id));
    }

    public function testRequestResetWithPortalAccountStoresAccountToken(): void
    {
        $soc = $this->createTestSociete();
        $accountId = $this->createPortalAccount('portalreset@example.com', (int) $soc->id);

        $result = $this->controller->requestReset(['email' => 'portalreset@example.com']);
        $this->assertEquals(200, $result[1]);

        $this->assertSame(1, $this->countResetTokens('account', $accountId, 'fk_societe_account'));
    }

    public function testRequestResetWithMemberStoresMemberTokenWhenEnabled(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '1';

        $adherentId = $this->createAdherent('memberreset@example.com', 1);

        $result = $this->controller->requestReset(['email' => 'memberreset@example.com']);
        $this->assertEquals(200, $result[1]);

        $this->assertSame(1, $this->countResetTokens('member', $adherentId, 'fk_adherent'));
    }

    public function testRequestResetIgnoresMemberWhenAdherentToggleOff(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_AUTH_SOURCE_ACCOUNT = '1';
        $conf->global->SMARTAUTH_AUTH_SOURCE_ADHERENT = '0';
        $conf->global->SMARTAUTH_AUTH_SOURCE_USER = '1';

        $adherentId = $this->createAdherent('memberoff@example.com', 1);

        $result = $this->controller->requestReset(['email' => 'memberoff@example.com']);
        $this->assertEquals(200, $result[1]); // anti-enumeration

        $this->assertSame(0, $this->countResetTokens('member', $adherentId, 'fk_adherent'));
    }

    // ==================== confirmReset() ====================

    public function testConfirmResetWithMissingFieldsReturns400(): void
    {
        $result = $this->controller->confirmReset([]);
        $this->assertEquals(400, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    public function testConfirmResetWithInvalidEmailReturns400(): void
    {
        $result = $this->controller->confirmReset([
            'email' => 'not-valid',
            'token' => 'sometoken',
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('email', strtolower($result[0]['error']));
    }

    public function testConfirmResetWithShortPasswordReturns400(): void
    {
        $result = $this->controller->confirmReset([
            'email' => 'test@example.com',
            'token' => 'whatever',
            'password' => 'short',
        ]);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('password', strtolower($result[0]['error']));
    }

    public function testConfirmResetWithUnknownTokenReturns400(): void
    {
        $result = $this->controller->confirmReset([
            'email' => 'test@example.com',
            'token' => 'a-token-that-was-never-issued',
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(400, $result[1]);
    }

    public function testConfirmResetWithExpiredTokenReturns410(): void
    {
        $testUser = $this->createTestUser(['email' => 'expired@example.com', 'statut' => 1]);
        $plain = 'expired-plain-token';
        // Seed an already-expired token.
        $this->seedResetToken($plain, 'user', (int) $testUser->id, null, null, -3600);

        $result = $this->controller->confirmReset([
            'email' => 'expired@example.com',
            'token' => $plain,
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(410, $result[1]);
        $this->assertStringContainsString('expired', strtolower($result[0]['error']));
    }

    public function testConfirmResetSingleUseTokenCannotBeReused(): void
    {
        $testUser = $this->createTestUser(['email' => 'singleuse@example.com', 'statut' => 1, 'pass' => 'oldpassword']);
        $plain = 'single-use-plain';
        $this->seedResetToken($plain, 'user', (int) $testUser->id, null, null, 3600);

        $first = $this->controller->confirmReset([
            'email' => 'singleuse@example.com',
            'token' => $plain,
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(200, $first[1]);

        // Re-using the now-consumed token must fail.
        $second = $this->controller->confirmReset([
            'email' => 'singleuse@example.com',
            'token' => $plain,
            'password' => 'evennewerpass123',
        ]);
        $this->assertEquals(400, $second[1]);
    }

    public function testConfirmResetUserUpdatesPasswordAndConsumesToken(): void
    {
        $testUser = $this->createTestUser(['email' => 'validreset@example.com', 'statut' => 1, 'pass' => 'oldpassword']);
        $plain = 'valid-user-token';
        $this->seedResetToken($plain, 'user', (int) $testUser->id, null, null, 3600);

        $result = $this->controller->confirmReset([
            'email' => 'validreset@example.com',
            'token' => $plain,
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(200, $result[1]);
        $this->assertStringContainsString('success', strtolower($result[0]['message']));

        // Token consumed (used_at set).
        $this->assertSame(0, $this->countActiveResetTokens('user', (int) $testUser->id, 'fk_user'));
    }

    public function testConfirmResetMatchesViaContactEmail(): void
    {
        $contactEmail = 'contactconfirm_' . uniqid() . '@example.com';
        $testUser = $this->createTestUser(['email' => 'placeholder_' . uniqid() . '@example.com', 'statut' => 1, 'pass' => 'oldpassword']);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "user SET email = '' WHERE rowid = " . (int) $testUser->id);
        $contactId = $this->createContactWithEmail($contactEmail);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "user SET fk_socpeople = " . (int) $contactId . " WHERE rowid = " . (int) $testUser->id);

        $plain = 'contact-user-token';
        $this->seedResetToken($plain, 'user', (int) $testUser->id, null, null, 3600);

        $result = $this->controller->confirmReset([
            'email' => $contactEmail,
            'token' => $plain,
            'password' => 'newpassword123',
        ]);
        $this->assertEquals(200, $result[1]);
    }

    public function testConfirmResetAccountWritesVerifiablePassword(): void
    {
        $soc = $this->createTestSociete();
        $accountId = $this->createPortalAccount('accountconfirm@example.com', (int) $soc->id);

        $plain = 'account-token';
        $this->seedResetToken($plain, 'account', 0, $accountId, null, 3600);

        $result = $this->controller->confirmReset([
            'email' => 'accountconfirm@example.com',
            'token' => $plain,
            'password' => 'BrandNewPass1',
        ]);
        $this->assertEquals(200, $result[1]);

        // The stored pass_crypted must verify against the new password.
        $obj = $this->db->fetch_object($this->db->query(
            "SELECT pass_crypted FROM " . MAIN_DB_PREFIX . "societe_account WHERE rowid = " . (int) $accountId
        ));
        $this->assertTrue(dol_verifyHash('BrandNewPass1', (string) $obj->pass_crypted));
    }

    public function testConfirmResetMemberWritesVerifiablePassword(): void
    {
        $adherentId = $this->createAdherent('memberconfirm@example.com', 1);

        $plain = 'member-token';
        $this->seedResetToken($plain, 'member', 0, null, $adherentId, 3600);

        $result = $this->controller->confirmReset([
            'email' => 'memberconfirm@example.com',
            'token' => $plain,
            'password' => 'MemberNewPass1',
        ]);
        $this->assertEquals(200, $result[1]);

        $obj = $this->db->fetch_object($this->db->query(
            "SELECT pass_crypted FROM " . MAIN_DB_PREFIX . "adherent WHERE rowid = " . (int) $adherentId
        ));
        $this->assertTrue(dol_verifyHash('MemberNewPass1', (string) $obj->pass_crypted));
    }

    // ==================== changePassword() ====================

    public function testChangePasswordWithoutUserReturns401(): void
    {
        $result = $this->controller->changePassword([
            'current_password' => 'oldpass',
            'new_password' => 'newpass123',
        ]);
        $this->assertEquals(401, $result[1]);
    }

    public function testChangePasswordWithMissingFieldsReturns400(): void
    {
        $result = $this->controller->changePassword(['user' => $this->testUser]);
        $this->assertEquals(400, $result[1]);
    }

    public function testChangePasswordWithShortNewPasswordReturns400(): void
    {
        $result = $this->controller->changePassword([
            'user' => $this->testUser,
            'current_password' => 'currentpass',
            'new_password' => 'short',
        ]);
        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('password', strtolower($result[0]['error']));
    }

    public function testChangePasswordWithWrongCurrentPasswordReturns403(): void
    {
        $testUser = $this->createTestUser(['email' => 'changepass@example.com', 'statut' => 1, 'pass' => 'correctpassword']);

        $result = $this->controller->changePassword([
            'user' => $testUser,
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123',
        ]);
        $this->assertEquals(403, $result[1]);
        $this->assertStringContainsString('incorrect', strtolower($result[0]['error']));
    }

    public function testChangePasswordWithCorrectCurrentPasswordSucceeds(): void
    {
        $testUser = $this->createTestUser(['email' => 'changepass2@example.com', 'statut' => 1, 'pass' => 'correctpassword']);

        $result = $this->controller->changePassword([
            'user' => $testUser,
            'current_password' => 'correctpassword',
            'new_password' => 'newpassword123',
        ]);
        $this->assertEquals(200, $result[1]);
        $this->assertStringContainsString('success', strtolower($result[0]['message']));
    }

    // ==================== helpers ====================

    /**
     * Count active (unused, non-expired) + used reset tokens for a subject.
     * $idColumn defaults to fk_user; pass fk_societe_account / fk_adherent for
     * external subjects.
     */
    private function countResetTokens(string $subjectType, int $id, string $idColumn = 'fk_user'): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " WHERE purpose = 'password_reset'";
        $sql .= " AND subject_type = '" . $this->db->escape($subjectType) . "'";
        $sql .= " AND " . $idColumn . " = " . (int) $id;
        $obj = $this->db->fetch_object($this->db->query($sql));
        return (int) $obj->cnt;
    }

    private function countActiveResetTokens(string $subjectType, int $id, string $idColumn = 'fk_user'): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " WHERE purpose = 'password_reset'";
        $sql .= " AND subject_type = '" . $this->db->escape($subjectType) . "'";
        $sql .= " AND " . $idColumn . " = " . (int) $id;
        $sql .= " AND used_at IS NULL";
        $obj = $this->db->fetch_object($this->db->query($sql));
        return (int) $obj->cnt;
    }

    /**
     * Insert a password_reset token row directly (the plain token is hashed,
     * as requestReset would). $ttl can be negative to seed an expired token.
     */
    private function seedResetToken(string $plain, string $subjectType, int $fkUser, ?int $fkSocieteAccount, ?int $fkAdherent, int $ttl): void
    {
        $now = dol_now();
        $expires = $this->db->idate($now + $ttl);
        $hash = EmailValidationToken::hashToken($plain);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "smartauth_email_validation";
        $sql .= " (token_hash, fk_user, subject_type, fk_societe_account, fk_adherent, purpose, expires_at, used_at, datec, entity)";
        $sql .= " VALUES ('" . $this->db->escape($hash) . "', " . (int) $fkUser . ",";
        $sql .= " '" . $this->db->escape($subjectType) . "',";
        $sql .= " " . ($fkSocieteAccount !== null ? (int) $fkSocieteAccount : 'NULL') . ",";
        $sql .= " " . ($fkAdherent !== null ? (int) $fkAdherent : 'NULL') . ",";
        $sql .= " 'password_reset', '" . $expires . "', NULL, '" . $this->db->idate($now) . "', 1)";

        if (!$this->db->query($sql)) {
            throw new \Exception('Failed to seed reset token: ' . $this->db->lasterror());
        }
    }

    private function createContactWithEmail(string $email): int
    {
        require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

        $contact = new \Contact($this->db);
        $contact->lastname = 'ResetContact';
        $contact->firstname = 'Test';
        $contact->email = $email;
        $contact->statut = 1;
        $contact->entity = 1;

        $id = $contact->create($this->testUser);
        if ($id <= 0) {
            throw new \Exception('Failed to create test contact: ' . $contact->error);
        }
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "socpeople SET email = '" . $this->db->escape($email) . "' WHERE rowid = " . (int) $id);
        return (int) $id;
    }

    private function createPortalAccount(string $login, int $fkSoc): int
    {
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, fk_soc, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, '" . $this->db->escape($login) . "', " . (int) $fkSoc . ",";
        $sql .= " 'smartauth', 1, " . (int) $this->testUser->id . ", '" . $now . "')";
        if (!$this->db->query($sql)) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
    }

    private function createAdherent(string $email, int $statut): int
    {
        $uniq = uniqid();
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, statut, login, email, firstname, lastname, datec)';
        $sql .= " VALUES ('MBR_" . $uniq . "', 1, 1, 'phy', " . (int) $statut . ",";
        $sql .= " 'login_" . $uniq . "', '" . $this->db->escape($email) . "', 'Test', 'Member', '" . $now . "')";
        if (!$this->db->query($sql)) {
            throw new \Exception('Failed to insert adherent: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }
}
