<?php

namespace SmartAuth\Tests\Http;

/**
 * Functional tests of the SmartAuth tab of the Dolibarr user card
 * (user_tab.php), loaded over HTTP with a real Dolibarr session.
 *
 * What is locked here is the scope of the tab: it shows the SmartAuth data OF
 * THE DISPLAYED USER. The page used to filter on the browsing user instead,
 * and skipped the filter entirely for an admin -- so an admin opening anybody's
 * card was served every token and every log of the whole instance, while the
 * revoke buttons on those very rows refused to act on them (ownership in
 * SmartAuthUserTokenAdmin is scoped to the displayed user).
 *
 * The tests run as the admin precisely because that was the broken path.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * @requires PHP >= 8.2
 * @coversNothing
 */
class UserTabHttpTest extends DolibarrPageTestCase
{
    /** Admin is rowid 1 in the shipped database. */
    private const ADMIN_ID = 1;

    /** @var int Second user created for the isolation tests */
    private static int $otherUserId = 0;

    /** @var int Token owned by the admin */
    private static int $adminTokenId = 0;

    /** @var int Token owned by the second user */
    private static int $otherTokenId = 0;

    /** Marker IPs, so a row can be spotted in the rendered HTML. */
    private const ADMIN_IP = '10.11.11.11';
    private const OTHER_IP = '10.22.22.22';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::seedTwoUsersWithTokensAndLogs();
    }

    /**
     * One extra user, then one device + one token + one log line for each of
     * the two users, all in the current entity.
     */
    private static function seedTwoUsersWithTokensAndLogs(): void
    {
        $pdo = self::pdo();

        $pdo->exec("INSERT INTO llx_user (entity, login, lastname, firstname, statut, datec, admin)"
            . " VALUES (1, 'usertab_other', 'Other', 'User', 1, '2026-01-01 00:00:00', 0)");
        self::$otherUserId = (int) $pdo->lastInsertId();

        self::$adminTokenId = self::seedTokenFor($pdo, self::ADMIN_ID, self::ADMIN_IP, 'DeviceOfAdmin');
        self::$otherTokenId = self::seedTokenFor($pdo, self::$otherUserId, self::OTHER_IP, 'DeviceOfOther');
    }

    /**
     * @return int rowid of the created token
     */
    private static function seedTokenFor(\PDO $pdo, int $userId, string $ip, string $deviceLabel): int
    {
        $pdo->exec("INSERT INTO llx_smartauth_devices (entity, label, uuid, fk_user_creat, date_creation, status)"
            . " VALUES (1, " . $pdo->quote($deviceLabel) . ", " . $pdo->quote('uuid-' . $userId)
            . ", " . $userId . ", '2026-01-01 00:00:00', 1)");
        $deviceId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO llx_smartauth_auth"
            . " (appuid, salt, date_creation, date_lastused, date_eol, fk_user_creat, fk_authid,"
            . "  auth_element, token_type, ip, fk_device_id, status, entity)"
            . " VALUES (" . self::MODULE_NUMERO . ", 'salt', '2026-01-01 00:00:00', '2026-01-01 00:00:00',"
            . " '2027-01-01 00:00:00', " . $userId . ", " . $userId . ", 'user', 'access',"
            . " " . $pdo->quote($ip) . ", " . $deviceId . ", 1, 1)");
        $tokenId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO llx_smartauth_logs"
            . " (appuid, fk_key, entity, dol_element, ip, method, http_status, bytes_sent,"
            . "  content_type, url_requested, fk_device_id)"
            . " VALUES (" . self::MODULE_NUMERO . ", " . $tokenId . ", 1, 'user', " . $pdo->quote($ip) . ","
            . " 'GET', 200, 10, 'json', " . $pdo->quote('/probe-user-' . $userId) . ", " . $deviceId . ")");

        return $tokenId;
    }

    private function logUrlOf(int $userId): string
    {
        return '/probe-user-' . $userId;
    }

    /**
     * The page must render: a Dolibarr technical error would make every
     * "absence" assertion below pass for the wrong reason.
     */
    public function testTabRendersWithoutDatabaseError(): void
    {
        $response = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString(
            'technical error',
            $response['body'],
            'The tab must render; a failing list query would void the isolation assertions'
        );
        $this->assertStringContainsString('data-rowid', $response['body'], 'The token list must have rows');
    }

    /**
     * Own card: the admin's own token, and only it.
     */
    public function testOwnCardShowsOnlyOwnTokens(): void
    {
        $body = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID)['body'];

        $this->assertStringContainsString(self::ADMIN_IP, $body, "The displayed user's own token must be listed");
        $this->assertStringNotContainsString(
            self::OTHER_IP,
            $body,
            'Another user token leaked into the card'
        );
    }

    /**
     * The regression that motivated the fix: an admin browsing somebody else's
     * card must see that user's tokens, not the whole instance's.
     */
    public function testOtherUserCardShowsOnlyThatUserTokens(): void
    {
        $body = $this->get('/custom/smartauth/user_tab.php?id=' . self::$otherUserId)['body'];

        $this->assertStringContainsString(self::OTHER_IP, $body, "The displayed user's token must be listed");
        $this->assertStringNotContainsString(
            self::ADMIN_IP,
            $body,
            "The browsing admin's own token must not appear on somebody else's card"
        );
    }

    /**
     * Same scoping on the second list of the page (the API logs).
     */
    public function testLogListIsScopedToDisplayedUser(): void
    {
        $ownCard = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID)['body'];
        $this->assertStringContainsString($this->logUrlOf(self::ADMIN_ID), $ownCard);
        $this->assertStringNotContainsString($this->logUrlOf(self::$otherUserId), $ownCard);

        $otherCard = $this->get('/custom/smartauth/user_tab.php?id=' . self::$otherUserId)['body'];
        $this->assertStringContainsString($this->logUrlOf(self::$otherUserId), $otherCard);
        $this->assertStringNotContainsString($this->logUrlOf(self::ADMIN_ID), $otherCard);
    }

    /**
     * The history modal endpoint returns the activity of a token of the
     * displayed user...
     */
    public function testViewHistoryReturnsActivityOfTheDisplayedUserToken(): void
    {
        $response = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID
            . '&action=viewhistory&token_id=' . self::$adminTokenId);

        $this->assertSame(200, $response['status']);
        $rows = json_decode($response['body'], true);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame($this->logUrlOf(self::ADMIN_ID), $rows[0]['url']);
        $this->assertSame('GET', $rows[0]['method']);
        $this->assertSame(200, $rows[0]['status']);
        $this->assertGreaterThan(0, $rows[0]['time'], 'The JS builds a Date from time * 1000');
    }

    /**
     * ... and nothing at all for a token belonging to somebody else, even
     * though the browsing user is an admin.
     */
    public function testViewHistoryRefusesAForeignToken(): void
    {
        $response = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID
            . '&action=viewhistory&token_id=' . self::$otherTokenId);

        $this->assertSame(200, $response['status']);
        $this->assertSame([], json_decode($response['body'], true), 'A foreign token must yield no activity');
    }

    /**
     * A missing or malformed token_id is a client error, not an empty list.
     */
    public function testViewHistoryRejectsAnInvalidTokenId(): void
    {
        $response = $this->get('/custom/smartauth/user_tab.php?id=' . self::ADMIN_ID
            . '&action=viewhistory&token_id=0');

        $this->assertSame(400, $response['status']);
        $this->assertSame(['error' => 'invalid_token_id'], json_decode($response['body'], true));
    }
}
