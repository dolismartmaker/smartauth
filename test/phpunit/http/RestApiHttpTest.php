<?php

namespace SmartAuth\Tests\Http;

/**
 * HTTP functional tests for the generic objects/{objtype} REST facade.
 *
 * Per ~/docs/TESTING_HTTP.md a dedicated RestApiHttpTest is mandatory for any
 * front-controller REST endpoint dispatched through SmartAuth\Api\RouteController:
 * the admin-page suites never touch the REST router, so a broken route would
 * ship green without this test. It runs the facade routes over a real php -S
 * server + the dolibarr-integration-sqlite bootstrap.
 *
 * SCOPE: the facade routes are all protected (JWT). This harness does not mint a
 * Bearer, so every route answers 401 -- which is exactly the signal we need: a
 * 401 proves the URL DISPATCHED through RouteController to the auth layer (a route
 * missing from the compiled cache would instead fall through to the router's
 * generic 404 "Not found"). Combined with assertNotEquals(500) + a fatal-pattern
 * scan, this catches: routes absent from the cache, a controller class that
 * fatals on load, and dispatch wiring regressions. Controller execution and the
 * count-vs-{id} routing precedence are covered deterministically by
 * RouteResolutionTest (integration-dolibarr) and the direct-call
 * ObjectControllerIntegrationTest.
 *
 * @covers \SmartAuth\Api\ObjectController
 */
class RestApiHttpTest extends HttpTestCase
{
    /** Fatal/parse patterns that must never appear in a REST response body. */
    private const FATAL_PATTERNS = [
        'Fatal error:',
        'Uncaught Error:',
        'Uncaught TypeError:',
        'Call to undefined method',
        'Call to undefined function',
        'Parse error:',
        'syntax error, unexpected',
        'PHPUNIT_FATAL_ERROR:',
    ];

    /**
     * Invalidate any stale compiled route cache BEFORE the server starts, so the
     * first request recompiles it from the current LocalRoutes.php (which now
     * declares the objects/{objtype} routes). Without this a pre-existing cache
     * would answer 404 for the new routes and the suite would pass blind.
     */
    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $docs = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/documents';
        foreach ((glob($docs . '/*/cache/routes.php') ?: []) as $cacheFile) {
            @unlink($cacheFile);
        }

        parent::setUpBeforeClass();
    }

    /**
     * One case per declared facade route/verb shape. Values: [method, path].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public function facadeRouteProvider(): array
    {
        // A real-ish id for GET show; a sentinel for mutations so nothing is
        // touched even if auth were ever bypassed.
        $id = 123;
        return [
            'GET list'      => ['GET', '/objects/thirdparty'],
            'GET count'     => ['GET', '/objects/thirdparty/count'],
            'GET columns'   => ['GET', '/objects/thirdparty/columns'],
            'GET describe'  => ['GET', '/objects/thirdparty/describe'],
            'GET show'      => ['GET', '/objects/thirdparty/' . $id],
            'POST create'   => ['POST', '/objects/thirdparty'],
            'PATCH update'  => ['PATCH', '/objects/thirdparty/' . $id],
            'DELETE one'    => ['DELETE', '/objects/thirdparty/' . $id],
            'DELETE bulk'   => ['DELETE', '/objects/thirdparty'],
            'GET product'   => ['GET', '/objects/product'],
            'GET contact'   => ['GET', '/objects/contact'],
            'GET category'  => ['GET', '/objects/category'],
            // Vague 2 types reuse the same generic {objtype} routes; a 401 (not a
            // 404) proves they dispatch through the router just like Vague 1.
            'GET order'        => ['GET', '/objects/order'],
            'GET invoice'      => ['GET', '/objects/invoice'],
            'GET proposal'     => ['GET', '/objects/proposal'],
            'GET project'      => ['GET', '/objects/project'],
            'GET task'         => ['GET', '/objects/task'],
            'GET agenda_event' => ['GET', '/objects/agenda_event'],
            'GET user'         => ['GET', '/objects/user'],
            // Document line routes (ObjectLineController). A 401 proves dispatch.
            'GET lines'        => ['GET', '/objects/proposal/' . $id . '/lines'],
            'POST line'        => ['POST', '/objects/proposal/' . $id . '/lines'],
            'POST reorder'     => ['POST', '/objects/proposal/' . $id . '/lines/reorder'],
            'PATCH line'       => ['PATCH', '/objects/proposal/' . $id . '/lines/42'],
            'DELETE line'      => ['DELETE', '/objects/proposal/' . $id . '/lines/42'],
            // Workflow action route (ObjectActionController).
            'POST action'      => ['POST', '/objects/order/' . $id . '/actions/validate'],
            // Invoice payment routes (ObjectPaymentController).
            'GET payments'     => ['GET', '/objects/invoice/' . $id . '/payments'],
            'POST payment'     => ['POST', '/objects/invoice/' . $id . '/payments'],
        ];
    }

    /**
     * @dataProvider facadeRouteProvider
     */
    public function testFacadeRouteDispatchesWithoutServerError(string $method, string $path): void
    {
        $response = $this->request($method, $path);

        $this->assertNotEquals(
            500,
            $response['statusCode'],
            "$method $path returned 500. Body: " . substr($response['body'], 0, 400)
        );

        foreach (self::FATAL_PATTERNS as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $response['body'],
                "$method $path body contains a fatal pattern '$pattern'"
            );
        }

        // A protected route reached by RouteController without a Bearer answers
        // 401. A 404 here would mean the route never registered (stale cache /
        // broken declaration).
        $this->assertSame(
            401,
            $response['statusCode'],
            "$method $path should dispatch to the auth layer (401), not 404/route-missing. Body: "
                . substr($response['body'], 0, 400)
        );
    }

    /**
     * Sanity guard: the harness itself must serve a live response, otherwise
     * every route test degrades into meaningless connection errors.
     */
    public function testSanityHarnessIsUp(): void
    {
        $response = $this->get('/ping');
        $this->assertSame(200, $response['statusCode']);
        $this->assertNotNull($response['json']);
        $this->assertSame('ok', $response['json']['status'] ?? null);
    }

    /**
     * An unregistered path must fall through to the router's generic 404, which
     * is what distinguishes it from a dispatched-but-unauthorized 401 above.
     */
    public function testUnknownRouteFallsThroughTo404(): void
    {
        $response = $this->get('/objects/thirdparty/columns/nope/extra/segments');
        $this->assertSame(404, $response['statusCode']);
    }
}
