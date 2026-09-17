<?php

namespace SmartAuth\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that exercise a server-rendered Dolibarr page of the
 * module (user_tab.php and friends) through a real HTTP session.
 *
 * The other HTTP suite (HttpTestCase) drives the REST front controller via
 * test/http/router.php, which declares NOLOGIN and hand-picks a controller.
 * That harness cannot reach a page like user_tab.php: the page includes
 * main.inc.php itself and calls restrictedArea() on a logged-in $user, so the
 * only faithful way to run it is a genuine Dolibarr session.
 *
 * So this harness serves the SQLite Dolibarr htdocs directly with the built-in
 * server, logs the admin in through the real login form (cookie jar kept in
 * /tmp) and then requests the module page under /custom/smartauth/.
 *
 * What it sets up, and why each step is needed:
 *   - the database is copied to RAM and the vendor file symlinked to it, as in
 *     the other suites, so nothing durable is written;
 *   - documents/install.lock is created: without it Dolibarr redirects every
 *     request to the installer;
 *   - MAIN_VERSION_LAST_UPGRADE is aligned with DOL_VERSION, otherwise the
 *     instance believes an upgrade is pending and serves the upgrade notice;
 *   - the admin password is rewritten to a known value (the shipped hash is
 *     unknown) so the login form can be posted;
 *   - htdocs/custom/smartauth is symlinked to the module checkout and the
 *     module is activated through admin/modules.php, exactly as an admin does.
 *
 * Everything is restored in tearDownAfterClass.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * @requires PHP >= 8.2
 */
abstract class DolibarrPageTestCase extends TestCase
{
    /** Module id declared by modSmartauth, needed by the activation URL. */
    protected const MODULE_NUMERO = 471029;

    /** Password given to the admin account on the throwaway test database. */
    protected const ADMIN_PASSWORD = 'phpunit-page-test';

    /** @var int */
    protected static int $serverPort = 0;

    /** @var int|null */
    protected static ?int $serverPid = null;

    /** @var string */
    protected static string $baseUrl = '';

    /** @var string Cookie jar holding the Dolibarr session */
    protected static string $cookieJar = '';

    /** @var string Module checkout root */
    protected static string $projectRoot = '';

    /** @var string vendor/cap-rel/dolibarr-integration-sqlite */
    protected static string $vendorPath = '';

    /** @var string Path of the SQLite file Dolibarr opens */
    protected static string $dbPath = '';

    /** @var string RAM copy actually written to */
    protected static string $ramDbPath = '';

    /** @var bool Did we create htdocs/custom/smartauth ourselves? */
    protected static bool $createdCustomLink = false;

    /** @var bool Did we create documents/install.lock ourselves? */
    protected static bool $createdInstallLock = false;

    /**
     * Offset added to SMARTAUTH_TEST_BACKEND_PORT. One subclass, one offset:
     * two suites sharing a port would also share the cookie jar and the RAM
     * database, and a server left behind by the previous class would answer in
     * place of the new one.
     */
    protected static function portOffset(): int
    {
        return 2;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 3);
        self::$vendorPath = self::$projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';

        if (!is_dir(self::$vendorPath . '/htdocs')) {
            self::markTestSkipped('cap-rel/dolibarr-integration-sqlite is not installed');
        }

        // Port: reserved window of the project (cf ~/docs/TESTING_PWA.md).
        // 8885 serves the REST router and 8886 the OAuth smoke suite, so this
        // harness takes the next ones. Never hardcoded: it is derived from the
        // env var defined in the project settings.
        $basePort = (int) (getenv('SMARTAUTH_TEST_BACKEND_PORT') ?: 8885);
        self::$serverPort = $basePort + static::portOffset();
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;
        self::$cookieJar = sys_get_temp_dir() . '/smartauth_page_test_cookies_' . self::$serverPort . '.txt';
        @unlink(self::$cookieJar);

        self::prepareDatabase();
        self::linkModuleIntoCustom();
        self::startServer();
        self::login();
        self::enableSmartAuthModule();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        // Restore the vendor database file (it was replaced by a symlink).
        if (is_link(self::$dbPath)) {
            @unlink(self::$dbPath);
        }
        if (self::$dbPath !== '' && file_exists(self::$dbPath . '.pagebackup')) {
            @copy(self::$dbPath . '.pagebackup', self::$dbPath);
            @unlink(self::$dbPath . '.pagebackup');
        }
        if (self::$ramDbPath !== '' && file_exists(self::$ramDbPath)) {
            @unlink(self::$ramDbPath);
        }
        if (self::$createdInstallLock) {
            @unlink(self::$vendorPath . '/documents/install.lock');
        }
        if (self::$createdCustomLink) {
            @unlink(self::$vendorPath . '/htdocs/custom/smartauth');
        }
        @unlink(self::$cookieJar);

        parent::tearDownAfterClass();
    }

    /**
     * Copy the shipped database to RAM, point the vendor path at it, then make
     * the instance usable headlessly: install lock, version alignment and a
     * known admin password.
     */
    private static function prepareDatabase(): void
    {
        self::$dbPath = self::$vendorPath . '/documents/database_dolibarr.sdb';
        $ramDisk = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        self::$ramDbPath = $ramDisk . '/smartauth_page_test_' . self::$serverPort . '.sdb';

        if (!is_file(self::$dbPath) && !is_link(self::$dbPath)) {
            self::markTestSkipped('SQLite Dolibarr database not found');
        }

        // Keep the pristine file aside, then serve a RAM copy through a symlink.
        if (!file_exists(self::$dbPath . '.pagebackup')) {
            copy(self::$dbPath, self::$dbPath . '.pagebackup');
        }
        copy(self::$dbPath, self::$ramDbPath);
        if (is_link(self::$dbPath) || is_file(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        symlink(self::$ramDbPath, self::$dbPath);

        $lock = self::$vendorPath . '/documents/install.lock';
        if (!file_exists($lock)) {
            file_put_contents($lock, (string) time());
            self::$createdInstallLock = true;
        }

        $pdo = self::pdo();
        // DOL_VERSION of the shipped code, read from filefunc.inc.php so the
        // alignment survives a package bump.
        $version = '18.0.9';
        $filefunc = @file_get_contents(self::$vendorPath . '/htdocs/filefunc.inc.php');
        if (is_string($filefunc) && preg_match("/define\('DOL_VERSION',\s*'([^']+)'/", $filefunc, $m)) {
            $version = $m[1];
        }
        $pdo->exec("UPDATE llx_const SET value = " . $pdo->quote($version)
            . " WHERE name IN ('MAIN_VERSION_LAST_INSTALL', 'MAIN_VERSION_LAST_UPGRADE')");
        $hasUpgradeConst = $pdo->query("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_VERSION_LAST_UPGRADE'");
        if ($hasUpgradeConst !== false && (int) $hasUpgradeConst->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO llx_const (name, entity, value, type, visible)"
                . " VALUES ('MAIN_VERSION_LAST_UPGRADE', 0, " . $pdo->quote($version) . ", 'chaine', 0)");
        }

        // The shipped admin hash is unknown, so give it one we can post.
        $hash = password_hash(self::ADMIN_PASSWORD, PASSWORD_BCRYPT);
        $pdo->exec("UPDATE llx_user SET pass_crypted = " . $pdo->quote($hash) . ", pass = NULL WHERE rowid = 1");
    }

    /**
     * Expose the module under htdocs/custom/smartauth, which is where
     * $dolibarr_main_document_root_alt points.
     */
    private static function linkModuleIntoCustom(): void
    {
        $link = self::$vendorPath . '/htdocs/custom/smartauth';
        if (is_link($link) || file_exists($link)) {
            return;
        }
        symlink(self::$projectRoot, $link);
        self::$createdCustomLink = true;
    }

    private static function startServer(): void
    {
        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s > /tmp/smartauth_page_test_%d.log 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg(self::$vendorPath . '/htdocs'),
            self::$serverPort
        );
        $output = [];
        exec($command, $output);
        self::$serverPid = (int) ($output[0] ?? 0);
        if (self::$serverPid <= 0) {
            throw new \RuntimeException('Failed to start the PHP built-in server');
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }

        self::stopServer();
        throw new \RuntimeException(
            'PHP server did not start in time, see /tmp/smartauth_page_test_' . self::$serverPort . '.log'
        );
    }

    protected static function stopServer(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            exec('kill ' . self::$serverPid . ' 2>/dev/null');
            exec('pkill -P ' . self::$serverPid . ' 2>/dev/null');
            self::$serverPid = null;
        }
    }

    /**
     * Post the real login form so the following requests carry a Dolibarr
     * session, which is what user_tab.php authenticates against.
     */
    private static function login(): void
    {
        $loginPage = self::request('GET', '/index.php', [], true);
        if (!preg_match('/name="token" value="([^"]+)"/', $loginPage['body'], $m)) {
            throw new \RuntimeException('No CSRF token on the Dolibarr login page');
        }

        $response = self::request('POST', '/index.php', [
            'token' => $m[1],
            'actionlogin' => 'login',
            'loginfunction' => 'loginfunction',
            'username' => 'admin',
            'password' => self::ADMIN_PASSWORD,
        ]);

        if ($response['status'] !== 302) {
            throw new \RuntimeException('Dolibarr login failed (HTTP ' . $response['status'] . ')');
        }
    }

    /**
     * Activate the module the way an admin does, so the descriptor's init()
     * creates the SmartAuth tables and registers the user tab.
     */
    private static function enableSmartAuthModule(): void
    {
        $modulesPage = self::request('GET', '/admin/modules.php?mode=common');
        if (!preg_match('/name="token" value="([^"]+)"/', $modulesPage['body'], $m)) {
            throw new \RuntimeException('No CSRF token on the modules admin page');
        }

        self::request('GET', '/admin/modules.php?id=' . self::MODULE_NUMERO
            . '&action=set&token=' . $m[1] . '&value=modSmartauth&mode=common');

        $pdo = self::pdo();
        $enabled = $pdo->query("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_SMARTAUTH'");
        if ($enabled === false || (string) $enabled->fetchColumn() !== '1') {
            throw new \RuntimeException('SmartAuth module could not be activated');
        }
    }

    /**
     * Direct PDO handle on the test database, for seeding rows and asserting
     * state without going through Dolibarr.
     */
    protected static function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite:' . self::$ramDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Minimal cURL wrapper with a file cookie jar (the session cookie is what
     * makes these page requests authenticated).
     *
     * @param array<string,string> $postFields
     * @return array{status:int, body:string}
     */
    protected static function request(string $method, string $path, array $postFields = [], bool $followRedirects = false): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, self::$cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, self::$cookieJar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * @return array{status:int, body:string}
     */
    protected function get(string $path): array
    {
        return self::request('GET', $path);
    }
}
