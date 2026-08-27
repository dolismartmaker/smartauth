<?php

/**
 * The /oauth/token rate-limit bucket, and who is allowed to fill it.
 *
 * The bucket used to be keyed on the client_id read from the request body and
 * filled BEFORE authentication. A client_id is public - it appears in every
 * /oauth/authorize URL - so anonymous POSTs carrying a victim's client_id, with
 * no secret at all, filled the victim's bucket and took its SSO down for the
 * whole window. Denial of service with zero credential, repeatable.
 *
 * The rule now: an unauthenticated caller may only ever fill its own IP bucket.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenController::handleToken
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/TokenController.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');
dol_include_once('/smartauth/api/RateLimiter.php');

use SmartAuth\Api\OAuth2\ResponseException;
use SmartAuth\Api\OAuth2\TokenController;

class TokenRateLimitScopeTest extends OAuthTestCase
{
    /** Secret of the client_credentials fixture (test/phpunit/fixtures/oauth_clients.php). */
    private const CLIENT_SECRET = 'test-secret-m2m-12345';

    /** @var TokenController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new TokenController($this->db);
        TokenController::enableTestMode();

        $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'smartauth_ratelimit');

        // Keep the two ceilings apart and small enough to reach in a test.
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_MAX = 5;
        $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_IP_MAX = 100;
        $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_WINDOW = 300;
    }

    protected function tearDown(): void
    {
        TokenController::disableTestMode();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE'] = '';
        $_SERVER['PHP_AUTH_USER'] = '';
        $_SERVER['PHP_AUTH_PW'] = '';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_POST = [];

        global $conf;
        unset(
            $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_MAX,
            $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_IP_MAX,
            $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_WINDOW
        );
        $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'smartauth_ratelimit');

        parent::tearDown();
    }

    /**
     * The attack, reproduced: fill the endpoint with anonymous requests naming
     * the victim's client_id, then check the victim can still get a token.
     */
    public function testAnonymousRequestsCannotFillAnotherClientBucket(): void
    {
        $client = $this->createTestClientFromFixture('client_credentials', [
            'fk_service_user' => $this->testUser->id,
        ]);

        // Ten anonymous POSTs (twice the client ceiling) carrying the victim's
        // public client_id and no secret at all.
        for ($i = 0; $i < 10; $i++) {
            $response = $this->post(['grant_type' => 'client_credentials', 'client_id' => $client->client_id]);
            $this->assertSame(401, $response->getStatusCode(), 'an unauthenticated call must be refused, not counted');
        }

        $attempts = $this->countAttempts($client->client_id, 'oauth_token');
        $this->assertSame(0, $attempts, 'an unauthenticated caller must not write into a client bucket');

        // The legitimate client, with its secret, is still served.
        $response = $this->post(
            ['grant_type' => 'client_credentials'],
            $client->client_id,
            self::CLIENT_SECRET
        );
        $this->assertSame(200, $response->getStatusCode(), 'the victim must still obtain a token: ' . json_encode($response->getResponseBody()));
    }

    /**
     * The IP bucket is what bounds secret guessing, and it is filled from the
     * first anonymous attempt - it can only ever hurt the caller.
     */
    public function testAnonymousRequestsFillTheirOwnIpBucket(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->post(['grant_type' => 'client_credentials', 'client_id' => 'whatever-public-id']);
        $this->post(['grant_type' => 'client_credentials', 'client_id' => 'whatever-public-id']);

        $this->assertSame(2, $this->countAttempts('198.51.100.7', 'oauth_token_ip'));
    }

    /**
     * And that IP bucket does close: past the ceiling the endpoint answers 429
     * instead of letting the guessing continue.
     */
    public function testTheIpBucketEventuallyRefuses(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_TOKEN_RATELIMIT_IP_MAX = 3;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.8';

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->post(['grant_type' => 'client_credentials', 'client_id' => 'probe'])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the IP bucket must close: ' . json_encode($statuses));
    }

    /**
     * An authenticated client still has its own ceiling: the fix moved the
     * bucket, it did not remove it.
     */
    public function testAuthenticatedClientStillHasItsOwnCeiling(): void
    {
        $client = $this->createTestClientFromFixture('client_credentials', [
            'fk_service_user' => $this->testUser->id,
        ]);
        $secret = self::CLIENT_SECRET;

        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->post(['grant_type' => 'client_credentials'], $client->client_id, $secret)->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the client ceiling (5) must be reached: ' . json_encode($statuses));
        $this->assertGreaterThan(0, $this->countAttempts($client->client_id, 'oauth_token'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function post(array $params, ?string $clientId = null, ?string $clientSecret = null): ResponseException
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['PHP_AUTH_USER'] = $clientId ?? '';
        $_SERVER['PHP_AUTH_PW'] = $clientSecret ?? '';
        $_POST = $params;

        try {
            $this->controller->handleToken();
            $this->fail('the token endpoint must always answer through a ResponseException in test mode');
        } catch (ResponseException $e) {
            return $e;
        }
    }

    private function countAttempts(string $identifier, string $action): int
    {
        $sql = 'SELECT COUNT(*) as n FROM ' . MAIN_DB_PREFIX . 'smartauth_ratelimit';
        $sql .= " WHERE identifier = '" . $this->db->escape($identifier) . "'";
        $sql .= " AND action = '" . $this->db->escape($action) . "'";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        return (int) $obj->n;
    }
}
