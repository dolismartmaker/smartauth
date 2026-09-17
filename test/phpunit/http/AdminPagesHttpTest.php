<?php

namespace SmartAuth\Tests\Http;

/**
 * Loads every server-rendered page of the module over HTTP and fails on a PHP
 * fatal.
 *
 * This is the safety net the other suites cannot provide. PHPStan does not
 * resolve method calls on the Dolibarr globals ($langs, $db, $user, $form...),
 * so a renamed method or a missing dol_include_once goes through static
 * analysis untouched; RestApiHttpTest only covers the JSON routes, and
 * UserTabHttpTest a single page. Between the two, the fourteen admin and
 * list/card pages were reached by nothing at all.
 *
 * Nothing is hardcoded: admin/ and the module root are scanned at run time, so
 * a page added tomorrow is covered without touching this file.
 *
 * Three things are exercised, because a page has three ways of breaking:
 *   - plain GET, which catches include and syntax problems;
 *   - GET of a *_card.php with a REAL id discovered from its list page. Without
 *     an id the "if ($object->id > 0)" branch is skipped and the whole card
 *     rendering, getNomUrl() and getLibStatut() included, never runs;
 *   - POST of every action the admin pages declare, with an empty body, which
 *     walks the form validation paths where the error branches live.
 *
 * Business rows are seeded first: on empty tables the list pages render an
 * empty <table> and the per-row code is never reached.
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
class AdminPagesHttpTest extends DolibarrPageTestCase
{
    /** URL prefix of the module inside the test Dolibarr. */
    private const MODULE_URL = '/custom/smartauth';

    /**
     * Signs of a PHP fatal in a response body. The built-in server runs with
     * display_errors on, so a fatal lands in the body even when the status code
     * stays at 200 (output already flushed before the error).
     */
    private const FATAL_PATTERNS = [
        'Fatal error',
        'Uncaught Error',
        'Uncaught TypeError',
        'Uncaught ValueError',
        'Call to undefined method',
        'Call to undefined function',
        'Parse error',
    ];

    /**
     * Actions left out of the POST scan, with the reason. Only for actions
     * whose side effect is unacceptable in a test run -- never to hide a page
     * that breaks.
     *
     * @var array<string,string>
     */
    private const SKIPPED_ACTIONS = [
        // Fetches GeoLite2-City.mmdb (tens of megabytes) from GitHub. The point
        // of the scan is form validation, not a download over the network.
        'admin/geoip.php:download' => 'downloads the GeoIP database',
    ];

    /**
     * Pages that need a GET parameter to do anything useful, or that must not
     * be requested bare. Keyed by file name.
     *
     * @var array<string,string>
     */
    private const EXTRA_QUERY = [
        'smartauthdevices_document.php' => 'id=%deviceid%',
    ];

    /** @var int Seeded token, used as ?id= on auth_card.php */
    private static int $tokenId = 0;

    /** @var int Seeded device, used as ?id= on the device pages */
    private static int $deviceId = 0;

    /** @var int Seeded OAuth2 client, used as ?id= on the client card */
    private static int $oauthClientId = 0;

    protected static function portOffset(): int
    {
        return 3;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::seedBusinessRows();
    }

    /**
     * One row per business class of the module, so the list pages have
     * something to render and the per-row methods actually run.
     */
    private static function seedBusinessRows(): void
    {
        $pdo = self::pdo();

        $pdo->exec("INSERT INTO llx_smartauth_devices (entity, label, uuid, fk_user_creat, date_creation, status)"
            . " VALUES (1, 'AdminPagesProbe', 'uuid-adminpages', 1, '2026-01-01 00:00:00', 1)");
        self::$deviceId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO llx_smartauth_auth"
            . " (appuid, salt, date_creation, date_lastused, date_eol, fk_user_creat, fk_authid,"
            . "  auth_element, token_type, ip, fk_device_id, status, entity)"
            . " VALUES (" . self::MODULE_NUMERO . ", 'salt', '2026-01-01 00:00:00', '2026-01-01 00:00:00',"
            . " '2027-01-01 00:00:00', 1, 1, 'user', 'access', '10.33.33.33', " . self::$deviceId . ", 1, 1)");
        self::$tokenId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO llx_smartauth_logs"
            . " (appuid, fk_key, entity, dol_element, ip, method, http_status, bytes_sent,"
            . "  content_type, url_requested, fk_device_id)"
            . " VALUES (" . self::MODULE_NUMERO . ", " . self::$tokenId . ", 1, 'user', '10.33.33.33',"
            . " 'GET', 200, 10, 'json', '/probe-admin-pages', " . self::$deviceId . ")");

        $pdo->exec("INSERT INTO llx_smartauth_push_logs"
            . " (subject_type, fk_user, entity, notification_type, notification_title,"
            . "  notification_body, http_status, success, date_creation)"
            . " VALUES ('user', 1, 1, 'probe', 'Probe title', 'Probe body', 201, 1, '2026-01-01 00:00:00')");

        $pdo->exec("INSERT INTO llx_smartauth_oauth_clients"
            . " (ref, client_id, client_secret, name, description, redirect_uris, allowed_scopes,"
            . "  allowed_grants, is_confidential, require_pkce, status, fk_user_author, datec, entity)"
            . " VALUES ('PROBE-1', 'probe-client-id', 'probe-secret', 'Probe client', 'Seeded by the HTTP suite',"
            . " 'https://example.com/callback', 'openid profile', 'authorization_code', 1, 0, 1, 1,"
            . " '2026-01-01 00:00:00', 1)");
        self::$oauthClientId = (int) $pdo->lastInsertId();
    }

    /**
     * Every admin page and every root page, requested bare.
     *
     * @dataProvider modulePagesProvider
     */
    public function testPageLoadsWithoutFatal(string $relativePath): void
    {
        $query = self::extraQueryFor(basename($relativePath));
        $response = $this->get(self::MODULE_URL . '/' . $relativePath . $query);

        self::assertPageIsHealthy($relativePath . $query, $response);
    }

    /**
     * Card pages with a real id taken from their own list page, so the record
     * rendering branch is executed.
     *
     * @dataProvider cardPagesProvider
     */
    public function testCardLoadsWithRealId(string $relativePath, string $fixture): void
    {
        $id = self::fixtureId($fixture);
        $this->assertGreaterThan(0, $id, 'The fixture for ' . $relativePath . ' was not seeded');

        $path = self::MODULE_URL . '/' . $relativePath . '?id=' . $id;
        $response = $this->get($path);

        self::assertPageIsHealthy($path, $response);
        $this->assertStringNotContainsString(
            'ErrorRecordNotFound',
            $response['body'],
            $relativePath . ' did not load the seeded record, the card rendering was skipped'
        );
    }

    /**
     * Every action an admin page declares, posted with an empty body. This is
     * where form validation reaches its error branches, which are the least
     * travelled lines of an admin page.
     *
     * @dataProvider adminActionsProvider
     */
    public function testAdminActionPostsWithoutFatal(string $relativePath, string $action): void
    {
        $response = self::request('POST', self::MODULE_URL . '/' . $relativePath, [
            'token' => self::csrfTokenFrom($relativePath),
            'action' => $action,
        ]);

        self::assertPageIsHealthy($relativePath . ' [action=' . $action . ']', $response);
    }

    /**
     * admin/*.php plus the root pages, discovered on disk.
     *
     * @return array<string,array{0:string}>
     */
    public static function modulePagesProvider(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];

        foreach (self::scanDirectory($root . '/admin') as $file) {
            $cases['admin/' . $file] = ['admin/' . $file];
        }
        foreach (self::scanDirectory($root . '/ajax') as $file) {
            $cases['ajax/' . $file] = ['ajax/' . $file];
        }
        foreach (self::scanDirectory($root) as $file) {
            // autoload.php is included by other pages, never served on its own:
            // requested directly it registers an autoloader and prints nothing.
            if ($file === 'autoload.php') {
                continue;
            }
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function cardPagesProvider(): array
    {
        return [
            'auth_card.php' => ['auth_card.php', 'token'],
            'smartauthdevices_card.php' => ['smartauthdevices_card.php', 'device'],
            'smartauthdevices_document.php' => ['smartauthdevices_document.php', 'device'],
            'admin/smartauth_oauth_client_card.php' => ['admin/smartauth_oauth_client_card.php', 'oauthclient'],
        ];
    }

    /**
     * Seeded rowid by fixture name. Resolved here and not in the provider,
     * because data providers run before setUpBeforeClass has seeded anything.
     */
    private static function fixtureId(string $fixture): int
    {
        switch ($fixture) {
            case 'token':
                return self::$tokenId;
            case 'device':
                return self::$deviceId;
            case 'oauthclient':
                return self::$oauthClientId;
        }

        throw new \InvalidArgumentException('Unknown fixture ' . $fixture);
    }

    /**
     * Actions parsed out of the admin pages, the way the pages themselves test
     * them: $action == 'xxx', GETPOST('action') === 'xxx', in_array(...).
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function adminActionsProvider(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];

        foreach (self::scanDirectory($root . '/admin') as $file) {
            $contents = (string) file_get_contents($root . '/admin/' . $file);
            if (!preg_match_all('/\$action\s*(?:==|===)\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $contents, $matches)) {
                continue;
            }
            foreach (array_unique($matches[1]) as $action) {
                $key = 'admin/' . $file . ':' . $action;
                if (isset(self::SKIPPED_ACTIONS[$key])) {
                    continue;
                }
                $cases[$key] = ['admin/' . $file, $action];
            }
        }

        return $cases;
    }

    /**
     * PHP files directly inside a directory, sorted for a stable test order.
     *
     * @return array<int,string>
     */
    private static function scanDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach ((array) scandir($directory) as $entry) {
            if (!is_string($entry) || substr($entry, -4) !== '.php') {
                continue;
            }
            if (!is_file($directory . '/' . $entry)) {
                continue;
            }
            $files[] = $entry;
        }
        sort($files);

        return $files;
    }

    /**
     * Fresh CSRF token for a page, read from the page itself. Dolibarr rejects
     * a POST without it long before the action is dispatched, which would make
     * every action test pass without executing anything.
     */
    private static function csrfTokenFrom(string $relativePath): string
    {
        $body = self::request('GET', self::MODULE_URL . '/' . $relativePath)['body'];
        if (preg_match('/name="token" value="([^"]+)"/', $body, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Resolve the placeholders of EXTRA_QUERY against the seeded fixtures.
     */
    private static function extraQueryFor(string $file): string
    {
        if (!isset(self::EXTRA_QUERY[$file])) {
            return '';
        }

        return '?' . strtr(self::EXTRA_QUERY[$file], [
            '%deviceid%' => (string) self::$deviceId,
            '%tokenid%' => (string) self::$tokenId,
        ]);
    }

    /**
     * @param array{status:int, body:string} $response
     */
    private static function assertPageIsHealthy(string $what, array $response): void
    {
        self::assertNotSame(500, $response['status'], $what . ' answered HTTP 500');

        foreach (self::FATAL_PATTERNS as $pattern) {
            if (strpos($response['body'], $pattern) === false) {
                continue;
            }
            self::fail($what . ' contains "' . $pattern . '":' . "\n" . self::excerptAround($response['body'], $pattern));
        }
    }

    /**
     * The failure message is the whole point of this suite: print the faulty
     * line rather than a bare "assertion failed" on a 200 kB page.
     */
    private static function excerptAround(string $body, string $pattern): string
    {
        $position = strpos($body, $pattern);
        if ($position === false) {
            return '';
        }
        $start = max(0, $position - 200);

        return trim(strip_tags(substr($body, $start, 600)));
    }
}
