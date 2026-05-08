<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\GeoHelper;

/**
 * Unit tests for the pure GeoHelper::validate() function.
 *
 * The set() / get() / clear() operations against the real llx_ecm_files
 * table are covered by the Dolibarr SQLite integration suite.
 *
 * @covers \SmartAuth\Api\GeoHelper
 */
class GeoHelperTest extends TestCase
{
    public function testValidateRejectsMissingLatOrLon(): void
    {
        $this->assertNull(GeoHelper::validate([]));
        $this->assertNull(GeoHelper::validate(['lat' => 0]));
        $this->assertNull(GeoHelper::validate(['lon' => 0]));
    }

    public function testValidateRejectsNonNumericLatOrLon(): void
    {
        $this->assertNull(GeoHelper::validate(['lat' => 'abc', 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 'xyz']));
        $this->assertNull(GeoHelper::validate(['lat' => null, 'lon' => null]));
        $this->assertNull(GeoHelper::validate(['lat' => [], 'lon' => 0]));
    }

    public function testValidateRejectsNonFiniteCoords(): void
    {
        $this->assertNull(GeoHelper::validate(['lat' => INF, 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => -INF, 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => NAN, 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => NAN]));
    }

    public function testValidateRejectsLatOutOfRange(): void
    {
        $this->assertNull(GeoHelper::validate(['lat' => 90.0001, 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => -90.0001, 'lon' => 0]));
        $this->assertNull(GeoHelper::validate(['lat' => 1000, 'lon' => 0]));
    }

    public function testValidateRejectsLonOutOfRange(): void
    {
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 180.0001]));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => -180.0001]));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 5000]));
    }

    public function testValidateAcceptsBoundaryValues(): void
    {
        $north = GeoHelper::validate(['lat' => 90.0, 'lon' => 0]);
        $this->assertNotNull($north);
        $this->assertSame(90.0, $north['lat']);

        $south = GeoHelper::validate(['lat' => -90.0, 'lon' => 0]);
        $this->assertNotNull($south);

        $east = GeoHelper::validate(['lat' => 0, 'lon' => 180.0]);
        $this->assertNotNull($east);
        $this->assertSame(180.0, $east['lon']);

        $west = GeoHelper::validate(['lat' => 0, 'lon' => -180.0]);
        $this->assertNotNull($west);
    }

    public function testValidateAcceptsRealisticParisCoords(): void
    {
        $v = GeoHelper::validate([
            'lat' => 48.8566,
            'lon' => 2.3522,
            'resultcode' => 'OK',
        ]);
        $this->assertNotNull($v);
        $this->assertSame(48.8566, $v['lat']);
        $this->assertSame(2.3522, $v['lon']);
        $this->assertSame('OK', $v['resultcode']);
    }

    public function testValidateRejectsBadResultCode(): void
    {
        // Lowercase, digits, too long, special chars are all rejected.
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => 'ok']));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => 'OK1']));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => str_repeat('A', 17)]));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => 'OK!']));
        $this->assertNull(GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => '_OK']));
    }

    public function testValidateAcceptsKnownGoogleStatusCodes(): void
    {
        foreach (['OK', 'ZERO_RESULTS', 'OVER_QUERY_LIMIT', 'REQUEST_DENIED', 'INVALID_REQUEST', 'UNKNOWN_ERROR'] as $code) {
            $v = GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => $code]);
            $this->assertNotNull($v, "Should accept $code");
            $this->assertSame($code, $v['resultcode']);
        }
    }

    public function testValidateTreatsEmptyResultCodeAsAbsent(): void
    {
        $v = GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => '']);
        $this->assertNotNull($v);
        $this->assertSame('', $v['resultcode']);

        $v2 = GeoHelper::validate(['lat' => 0, 'lon' => 0, 'resultcode' => null]);
        $this->assertNotNull($v2);
        $this->assertSame('', $v2['resultcode']);
    }

    public function testValidateCastsStringNumbers(): void
    {
        $v = GeoHelper::validate(['lat' => '48.85', 'lon' => '2.35']);
        $this->assertNotNull($v);
        $this->assertSame(48.85, $v['lat']);
        $this->assertSame(2.35, $v['lon']);
    }
}
