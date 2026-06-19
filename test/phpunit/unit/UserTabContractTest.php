<?php

/**
 * Static contract tests for user_tab.php (SmartAuth tab of the Dolibarr user card).
 *
 * A Dolibarr page cannot be re-included in a test process (function
 * redeclaration -> Fatal), so the destructive-action wiring of the tab is
 * locked here by reading its source and asserting the contract with regexes:
 *
 *   - a real "Delete" (row removal) exists and delegates to delete()/massDelete(),
 *     distinct from "revoke" which only flips the status (SI-126 / SMA-005),
 *   - the bulk-action combo offers BOTH "revoke selection" and "delete selection",
 *     for tokens and for logical devices,
 *   - every destructive action is permission-gated (admin/user right) and, on the
 *     device side, CSRF + POST protected,
 *   - no deprecated $user->rights-> property access survives (DOL23: hasRight()).
 *
 * A companion test checks fr_FR/en_US parity of the translation keys the tab
 * uses for those actions.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class UserTabContractTest extends TestCase
{
    /** @var string */
    private static $source = '';

    /** @var string */
    private static $pagePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$pagePath = dirname(__DIR__, 3) . '/user_tab.php';
        $content = file_get_contents(self::$pagePath);
        self::$source = $content === false ? '' : $content;
    }

    public function testPageSourceIsReadable(): void
    {
        $this->assertFileExists(self::$pagePath);
        $this->assertNotSame('', self::$source, 'user_tab.php source must be readable');
    }

    /**
     * The token "Delete" action must perform a REAL delete (delegate to
     * SmartAuthUserTokenAdmin::delete), not reuse the revoke path.
     */
    public function testTokenDeleteActionDelegatesToDelete(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$action\s*==\s*'delete_token'/",
            self::$source,
            "The 'delete_token' action must be wired in user_tab.php"
        );
        $this->assertStringContainsString(
            '$tokenAdmin->delete(',
            self::$source,
            "'delete_token' must delegate to SmartAuthUserTokenAdmin::delete (real row removal)"
        );
    }

    /**
     * Token mass actions: both revoke (status flip) and real delete must exist
     * and delegate to the matching helper method.
     */
    public function testTokenMassActionsOfferBothRevokeAndDelete(): void
    {
        $this->assertMatchesRegularExpression(
            "/'masstokenrevoke'\s*,\s*'masstokendelete'/",
            self::$source,
            "Both masstokenrevoke and masstokendelete must be handled"
        );
        $this->assertStringContainsString('$tokenAdmin->massDelete(', self::$source, 'masstokendelete must call massDelete()');
        $this->assertStringContainsString('$tokenAdmin->massRevoke(', self::$source, 'masstokenrevoke must call massRevoke()');

        // The combo must surface BOTH buttons to the user.
        $this->assertStringContainsString('RevokeSelectedTokens', self::$source, 'Bulk "revoke selection" button must be present');
        $this->assertStringContainsString('DeleteSelectedTokens', self::$source, 'Bulk "delete selection" button must be present');
    }

    /**
     * Logical-device mass actions: revoke vs real delete, both wired and both
     * surfaced as buttons.
     */
    public function testDeviceMassActionsOfferBothRevokeAndDelete(): void
    {
        $this->assertMatchesRegularExpression(
            "/'userdevicemassrevoke'\s*,\s*'userdevicemassdelete'/",
            self::$source,
            "Both userdevicemassrevoke and userdevicemassdelete must be handled"
        );
        // Real delete delegates to repo->delete(); revoke to repo->revoke().
        $this->assertMatchesRegularExpression('/\$udRepo->delete\(/', self::$source, 'userdevicemassdelete must call repo->delete()');
        $this->assertMatchesRegularExpression('/\$udRepo->revoke\(/', self::$source, 'userdevicemassrevoke must call repo->revoke()');

        $this->assertStringContainsString('SmartAuthUserDeviceMassRevoke', self::$source, 'Device bulk "revoke" button must be present');
        $this->assertStringContainsString('SmartAuthUserDeviceMassDelete', self::$source, 'Device bulk "delete" button must be present');
    }

    /**
     * The single-device "Delete" must be a real removal, distinct from revoke.
     */
    public function testDeviceSingleDeleteIsRealRemoval(): void
    {
        $this->assertStringContainsString('userdevicedelete', self::$source, "'userdevicedelete' action must exist");
        $this->assertStringContainsString('userdevicerevoke', self::$source, "'userdevicerevoke' action must exist (distinct from delete)");
    }

    /**
     * Every token action is gated behind the edit permission ($permtoedit,
     * itself derived from admin / user "write" right).
     */
    public function testTokenActionsArePermissionGated(): void
    {
        // $permtoedit comes from hasRight() based capabilities.
        $this->assertMatchesRegularExpression(
            "/\\\$permtoedit\s*=\s*\\\$caneditfield/",
            self::$source,
            '$permtoedit must derive from the user edit capability'
        );
        // Each destructive token branch must check $permtoedit.
        $this->assertMatchesRegularExpression(
            "/'delete_token'\s*&&\s*\\\$permtoedit/",
            self::$source,
            "delete_token must be guarded by \$permtoedit"
        );
        $this->assertMatchesRegularExpression(
            "/'masstokendelete'\)\s*,\s*true\)\s*&&\s*\\\$permtoedit/",
            self::$source,
            'Token mass actions must be guarded by $permtoedit'
        );
    }

    /**
     * Device destructive actions are POST + CSRF protected and refuse acting on
     * a user other than the authenticated one.
     */
    public function testDeviceActionsAreCsrfAndOwnershipGated(): void
    {
        // POST only.
        $this->assertMatchesRegularExpression(
            "/REQUEST_METHOD'\]\s*\?\?\s*'GET'\)\s*===\s*'POST'/",
            self::$source,
            'Device actions must be POST-only'
        );
        // CSRF token check.
        $this->assertMatchesRegularExpression(
            "/newToken\(\)\s*!==\s*\(string\)\s*GETPOST\('token'/",
            self::$source,
            'Device actions must verify the CSRF token'
        );
        // Acting only on the authenticated user's own card.
        $this->assertMatchesRegularExpression(
            "/\(int\)\s*\\\$user->id\s*!==\s*\(int\)\s*\\\$id\)\s*\{\s*accessforbidden\(\);/",
            self::$source,
            'Device actions must refuse a foreign user card (accessforbidden)'
        );
    }

    /**
     * DOL23: no deprecated $user->rights-> property access anywhere in the tab.
     * Capabilities must go through hasRight().
     */
    public function testNoDeprecatedRightsPropertyAccess(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/->rights->/',
            self::$source,
            'Deprecated $user->rights-> access is forbidden (use hasRight())'
        );
        $this->assertStringContainsString('->hasRight(', self::$source, 'Capabilities must be resolved via hasRight()');
    }

    /**
     * Translation-key parity: every key the tab uses for the destructive /
     * bulk actions must exist in BOTH fr_FR and en_US.
     *
     * @dataProvider provideContractTranslationKeys
     */
    public function testTranslationKeyParity(string $key): void
    {
        $root = dirname(__DIR__, 3);
        $fr = $root . '/langs/fr_FR/smartauth.lang';
        $en = $root . '/langs/en_US/smartauth.lang';

        $this->assertTrue($this->langHasKey($fr, $key), "Missing key '$key' in fr_FR/smartauth.lang");
        $this->assertTrue($this->langHasKey($en, $key), "Missing key '$key' in en_US/smartauth.lang");
    }

    /**
     * @return array<int,array{0:string}>
     */
    public static function provideContractTranslationKeys(): array
    {
        $keys = [
            'TokenDeleted',
            'ConfirmDeleteToken',
            'DeleteToken',
            'ErrorDeletingToken',
            'TokensMassDeleted',
            'TokensMassRevoked',
            'RevokeSelectedTokens',
            'DeleteSelectedTokens',
            'SmartAuthUserDeviceMassRevoke',
            'SmartAuthUserDeviceMassDelete',
            'SmartAuthUserDeviceMassRevoked',
            'SmartAuthUserDeviceMassDeleted',
        ];
        return array_map(static function ($k) {
            return [$k];
        }, $keys);
    }

    private function langHasKey(string $file, string $key): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }
        return (bool) preg_match('/^' . preg_quote($key, '/') . '=/m', $content);
    }
}
