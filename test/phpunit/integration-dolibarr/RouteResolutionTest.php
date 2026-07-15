<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\RouteCache;
use SmartAuth\Api\RouteController as Route;
use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;
use SmartAuth\Api\ObjectPaymentController;

/**
 * Deterministic routing tests for the objects/{objtype} facade, driven against
 * the REAL compiled route table (RouteCache), not over HTTP.
 *
 * These pin the two properties the HTTP layer cannot distinguish (every
 * protected route answers 401 before the controller runs):
 *   - the static sub-resources count/columns/describe resolve to their own
 *     handlers and are NOT swallowed by the catch-all {id} route (registration
 *     order / matching precedence), and
 *   - the route param is exposed as 'objtype' (+ 'id'), so it never collides
 *     with an object's own 'type' field.
 *
 * @covers \SmartAuth\Api\RouteCache
 * @covers \SmartAuth\Api\ObjectController
 */
class RouteResolutionTest extends DolibarrRealTestCase
{
    /** @var string */
    private const CTRL = 'SmartAuth\\Api\\ObjectController';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Register the facade routes in the SAME order as api/LocalRoutes.php and
        // compile them, so findRoute() exercises the real RouteCache matching
        // (registration-order precedence) deterministically.
        //
        // We register manually rather than re-including LocalRoutes.php because
        // endRegistration() reaches it via include_once, which no-ops once any
        // earlier test in the process has already pulled the file -- making the
        // route table order-dependent on the rest of the suite. Manual
        // registration is registered first, so it wins resolution regardless of
        // the additional routes endRegistration() then scans in. The real
        // LocalRoutes.php is compiled fresh and swept in RestApiHttpTest.
        RouteCache::init('smartauth');
        RouteCache::startRegistration();
        Route::get('objects/{objtype}/count', ObjectController::class, 'count', true);
        Route::get('objects/{objtype}/columns', ObjectController::class, 'columns', true);
        Route::get('objects/{objtype}/describe', ObjectController::class, 'describe', true);
        Route::get('objects/{objtype}/{id}', ObjectController::class, 'show', true);
        Route::get('objects/{objtype}', ObjectController::class, 'index', true);
        Route::post('objects/{objtype}', ObjectController::class, 'create', true);
        Route::patch('objects/{objtype}/{id}', ObjectController::class, 'update', true);
        Route::delete('objects/{objtype}/{id}', ObjectController::class, 'destroy', true);
        Route::delete('objects/{objtype}', ObjectController::class, 'deleteBulk', true);
        // Line routes -- same registration order as api/LocalRoutes.php.
        Route::get('objects/{objtype}/{id}/lines', ObjectLineController::class, 'index', true);
        Route::post('objects/{objtype}/{id}/lines/reorder', ObjectLineController::class, 'reorder', true);
        Route::post('objects/{objtype}/{id}/lines', ObjectLineController::class, 'store', true);
        Route::patch('objects/{objtype}/{id}/lines/{lineid}', ObjectLineController::class, 'update', true);
        Route::delete('objects/{objtype}/{id}/lines/{lineid}', ObjectLineController::class, 'destroy', true);
        Route::post('objects/{objtype}/{id}/actions/{action}', ObjectActionController::class, 'invoke', true);
        Route::get('objects/{objtype}/{id}/payments', ObjectPaymentController::class, 'index', true);
        Route::post('objects/{objtype}/{id}/payments', ObjectPaymentController::class, 'store', true);
        RouteCache::endRegistration();
        RouteCache::loadCache();
    }

    /** @var string */
    private const LINE_CTRL = 'SmartAuth\\Api\\ObjectLineController';

    /** @var string */
    private const ACTION_CTRL = 'SmartAuth\\Api\\ObjectActionController';

    /** @var string */
    private const PAYMENT_CTRL = 'SmartAuth\\Api\\ObjectPaymentController';

    /**
     * Assert a method+path resolves to $class::$fn with $params.
     *
     * @param array<string,string> $params
     */
    private function assertRoute(string $method, string $path, string $fn, array $params = [], string $class = self::CTRL): void
    {
        $route = RouteCache::findRoute($method, $path);
        $this->assertNotNull($route, "$method $path did not resolve to any route");
        $this->assertSame($class, ltrim((string) $route['class'], '\\'), "$method $path resolved to the wrong class");
        $this->assertSame($fn, $route['function'], "$method $path resolved to the wrong method");
        foreach ($params as $key => $value) {
            $this->assertSame($value, $route['params'][$key] ?? null, "$method $path param '$key' mismatch");
        }
    }

    public function testCountResolvesToCountNotShow(): void
    {
        // The critical ordering assertion: 'count' must NOT be captured as {id}.
        $this->assertRoute('GET', 'objects/thirdparty/count', 'count', ['objtype' => 'thirdparty']);
    }

    public function testColumnsResolvesToColumns(): void
    {
        $this->assertRoute('GET', 'objects/product/columns', 'columns', ['objtype' => 'product']);
    }

    public function testDescribeResolvesToDescribe(): void
    {
        $this->assertRoute('GET', 'objects/contact/describe', 'describe', ['objtype' => 'contact']);
    }

    public function testShowResolvesWithObjtypeAndId(): void
    {
        $this->assertRoute('GET', 'objects/thirdparty/42', 'show', ['objtype' => 'thirdparty', 'id' => '42']);
    }

    public function testIndexResolvesToIndex(): void
    {
        $this->assertRoute('GET', 'objects/category', 'index', ['objtype' => 'category']);
    }

    public function testCreateResolvesToCreate(): void
    {
        $this->assertRoute('POST', 'objects/thirdparty', 'create', ['objtype' => 'thirdparty']);
    }

    public function testUpdateResolvesToUpdate(): void
    {
        $this->assertRoute('PATCH', 'objects/thirdparty/42', 'update', ['objtype' => 'thirdparty', 'id' => '42']);
    }

    public function testDestroyResolvesToDestroy(): void
    {
        $this->assertRoute('DELETE', 'objects/thirdparty/42', 'destroy', ['objtype' => 'thirdparty', 'id' => '42']);
    }

    public function testDeleteBulkResolvesToDeleteBulk(): void
    {
        $this->assertRoute('DELETE', 'objects/thirdparty', 'deleteBulk', ['objtype' => 'thirdparty']);
    }

    public function testTheParamIsNamedObjtypeNotType(): void
    {
        $route = RouteCache::findRoute('GET', 'objects/product/7');
        $this->assertNotNull($route);
        $this->assertArrayHasKey('objtype', $route['params']);
        $this->assertArrayNotHasKey('type', $route['params']);
    }

    // ------------------------------------------------------------ line routes

    public function testShowIsNotSwallowedByLinesIndex(): void
    {
        // objects/proposal/5 (3 seg) must still be 'show', not the 4-seg lines
        // index -- proving the extra static segment keeps them distinct.
        $this->assertRoute('GET', 'objects/proposal/5', 'show', ['objtype' => 'proposal', 'id' => '5']);
    }

    public function testLinesIndexResolves(): void
    {
        $this->assertRoute('GET', 'objects/proposal/5/lines', 'index', ['objtype' => 'proposal', 'id' => '5'], self::LINE_CTRL);
    }

    public function testLineReorderResolvesNotSwallowedByLineId(): void
    {
        // POST .../lines/reorder must hit reorder, never the store (4-seg) or the
        // PATCH lines/{lineid} (different method).
        $this->assertRoute('POST', 'objects/proposal/5/lines/reorder', 'reorder', ['objtype' => 'proposal', 'id' => '5'], self::LINE_CTRL);
    }

    public function testLineStoreResolves(): void
    {
        $this->assertRoute('POST', 'objects/proposal/5/lines', 'store', ['objtype' => 'proposal', 'id' => '5'], self::LINE_CTRL);
    }

    public function testLineUpdateResolvesWithLineId(): void
    {
        $this->assertRoute('PATCH', 'objects/order/9/lines/42', 'update', ['objtype' => 'order', 'id' => '9', 'lineid' => '42'], self::LINE_CTRL);
    }

    public function testLineDestroyResolvesWithLineId(): void
    {
        $this->assertRoute('DELETE', 'objects/invoice/3/lines/7', 'destroy', ['objtype' => 'invoice', 'id' => '3', 'lineid' => '7'], self::LINE_CTRL);
    }

    public function testActionResolvesNotConfusedWithLineReorder(): void
    {
        // Both are 5-segment POST; the literal 4th segment ('actions' vs 'lines')
        // keeps them apart.
        $this->assertRoute('POST', 'objects/order/5/actions/validate', 'invoke', ['objtype' => 'order', 'id' => '5', 'action' => 'validate'], self::ACTION_CTRL);
        $this->assertRoute('POST', 'objects/order/5/lines/reorder', 'reorder', ['objtype' => 'order', 'id' => '5'], self::LINE_CTRL);
    }

    public function testPaymentRoutesResolve(): void
    {
        $this->assertRoute('GET', 'objects/invoice/8/payments', 'index', ['objtype' => 'invoice', 'id' => '8'], self::PAYMENT_CTRL);
        $this->assertRoute('POST', 'objects/invoice/8/payments', 'store', ['objtype' => 'invoice', 'id' => '8'], self::PAYMENT_CTRL);
    }
}
