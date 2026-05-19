<?php

/**
 * Integration tests for the RevokedJtiController endpoint
 * (GET /oauth/revoked-jti, PERFS.md §3.4 hybrid revocation list).
 *
 * Covers the wire-level behavior: method check, ?since= filtering,
 * ETag emission and If-None-Match -> 304 round-trip.
 *
 * @covers \SmartAuth\Api\OAuth2\RevokedJtiController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/RevokedJtiController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

use SmartAuth\Api\OAuth2\RevokedJtiController;
use SmartAuth\Api\OAuth2\ResponseException;

class RevokedJtiControllerTest extends OAuthTestCase
{
    /** @var RevokedJtiController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new RevokedJtiController($this->db);
        RevokedJtiController::enableTestMode();
    }

    protected function tearDown(): void
    {
        RevokedJtiController::disableTestMode();
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        $_GET = [];
        parent::tearDown();
    }

    public function testMethodMustBeGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('invalid_request', $e->getErrorCode());
        }
    }

    public function testEmptyListReturnsAsOfZeroAndEtag(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertSame(0, $body['as_of']);
            $this->assertSame([], $body['jtis']);
            $headers = $e->getHeaders();
            $this->assertArrayHasKey('ETag', $headers);
            $this->assertStringStartsWith('W/"', $headers['ETag']);
        }
    }

    public function testListReturnsAllJtisOrderedByRevokedAt(): void
    {
        // Three rows, all in the future for expires_at. addRevokedJti stamps
        // revoked_at to NOW so they all land "now"-ish; rely on the listSince
        // helper's deterministic ordering (revoked_at ASC, jti ASC).
        $this->tokenService->addRevokedJti('jti-a', dol_now() + 3600, 'manual');
        $this->tokenService->addRevokedJti('jti-b', dol_now() + 3600, 'contract_closed');
        $this->tokenService->addRevokedJti('jti-c', dol_now() + 3600, 'manual');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            sort($body['jtis']);
            $this->assertSame(['jti-a', 'jti-b', 'jti-c'], $body['jtis']);
            $this->assertGreaterThan(0, $body['as_of']);
        }
    }

    public function testSinceFilterExcludesOlderEntries(): void
    {
        $now = dol_now();

        $this->tokenService->addRevokedJti('jti-old', $now + 3600, 'manual');
        $this->backdateRevokedAt('jti-old', $now - 1800);

        $this->tokenService->addRevokedJti('jti-recent', $now + 3600, 'contract_closed');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['since' => (string) ($now - 600)];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertContains('jti-recent', $body['jtis']);
            $this->assertNotContains('jti-old', $body['jtis']);
        }
    }

    public function testIfNoneMatchReturns304WhenEtagMatches(): void
    {
        $this->tokenService->addRevokedJti('jti-x', dol_now() + 3600, 'manual');
        $this->tokenService->addRevokedJti('jti-y', dol_now() + 3600, 'manual');

        // First call: capture the ETag
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $firstResponse) {
            $this->assertSame(200, $firstResponse->getStatusCode());
            $etag = $firstResponse->getHeaders()['ETag'];
            $this->assertNotEmpty($etag);

            // Second call with If-None-Match -> 304
            $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;

            try {
                $this->controller->handleList();
                $this->fail('Expected 304 ResponseException not thrown');
            } catch (ResponseException $secondResponse) {
                $this->assertSame(304, $secondResponse->getStatusCode());
                $this->assertSame($etag, $secondResponse->getHeaders()['ETag']);
                $this->assertEmpty($secondResponse->getResponseBody());
            }
        }
    }

    public function testIfNoneMatchStarReturns304(): void
    {
        $this->tokenService->addRevokedJti('jti-x', dol_now() + 3600, 'manual');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '*';
        $_GET = [];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(304, $e->getStatusCode());
        }
    }

    public function testIfNoneMatchMismatchReturnsFullResponse(): void
    {
        $this->tokenService->addRevokedJti('jti-x', dol_now() + 3600, 'manual');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"some-stale-etag"';
        $_GET = [];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertSame(['jti-x'], $body['jtis']);
        }
    }

    public function testInvalidSinceValueTreatedAsZero(): void
    {
        $now = dol_now();
        $this->tokenService->addRevokedJti('jti-old', $now + 3600, 'manual');
        $this->backdateRevokedAt('jti-old', $now - 7200);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['since' => 'not-a-number'];

        try {
            $this->controller->handleList();
            $this->fail('Expected ResponseException not thrown');
        } catch (ResponseException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $body = $e->getResponseBody();
            $this->assertContains('jti-old', $body['jtis']);
        }
    }

    private function backdateRevokedAt(string $jti, int $newTs): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_revoked_jti"
            . " SET revoked_at = '" . $this->db->idate($newTs) . "'"
            . " WHERE jti = '" . $this->db->escape($jti) . "'";
        $this->db->query($sql);
    }
}
