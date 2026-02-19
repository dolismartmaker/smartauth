<?php

namespace SmartAuth\Tests\Http;

/**
 * HTTP functional tests for PwaController
 *
 * Tests real HTTP responses including headers, status codes, and body content.
 * Uses PHP built-in server with dolibarr-integration-sqlite.
 *
 * @requires PHP >= 8.2
 * @covers \SmartAuth\Api\PwaController
 */
class PwaControllerHttpTest extends HttpTestCase
{
    // =========================================================================
    // Manifest endpoint tests
    // =========================================================================

    public function testManifestReturnsOk(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertStatusCode(200, $response);
    }

    public function testManifestHasCorrectContentType(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertHeaderContains('content-type', 'application/manifest+json', $response);
    }

    public function testManifestHasCacheControlHeader(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertHeaderContains('cache-control', 'max-age=3600', $response);
    }

    public function testManifestReturnsValidJson(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertNotNull($response['json'], 'Manifest should be valid JSON');
    }

    public function testManifestContainsRequiredFields(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertJsonHasKey('name', $response);
        $this->assertJsonHasKey('short_name', $response);
        $this->assertJsonHasKey('start_url', $response);
        $this->assertJsonHasKey('display', $response);
        $this->assertJsonHasKey('icons', $response);
    }

    public function testManifestDisplayIsStandalone(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertJsonEquals('display', 'standalone', $response);
    }

    public function testManifestStartUrlIsRoot(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertJsonEquals('start_url', '/', $response);
    }

    public function testManifestHasThreeIcons(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertJsonHasKey('icons', $response);
        $this->assertCount(3, $response['json']['icons']);
    }

    public function testManifestIconsHaveCorrectSizes(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $icons = $response['json']['icons'];
        $sizes = array_column($icons, 'sizes');

        $this->assertContains('64x64', $sizes);
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function testManifestIconsArePng(): void
    {
        $response = $this->get('/manifest.webmanifest');

        foreach ($response['json']['icons'] as $icon) {
            $this->assertEquals('image/png', $icon['type']);
        }
    }

    public function testManifestHasColors(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertJsonHasKey('background_color', $response);
        $this->assertJsonHasKey('theme_color', $response);
    }

    // =========================================================================
    // Icon endpoint tests
    // =========================================================================

    public function testIcon64ReturnsOk(): void
    {
        $response = $this->get('/icon/64');

        $this->assertStatusCode(200, $response);
    }

    public function testIcon192ReturnsOk(): void
    {
        $response = $this->get('/icon/192');

        $this->assertStatusCode(200, $response);
    }

    public function testIcon512ReturnsOk(): void
    {
        $response = $this->get('/icon/512');

        $this->assertStatusCode(200, $response);
    }

    public function testIconHasCorrectContentType(): void
    {
        $response = $this->get('/icon/192');

        $this->assertHeaderContains('content-type', 'image/png', $response);
    }

    public function testIconHasCacheControlHeader(): void
    {
        $response = $this->get('/icon/192');

        $this->assertHeaderContains('cache-control', 'max-age', $response);
    }

    public function testIconReturnsImageData(): void
    {
        $response = $this->get('/icon/192');

        // PNG magic bytes
        $this->assertStringStartsWith("\x89PNG", $response['body']);
    }

    public function testIconInvalidSizeDefaultsTo512(): void
    {
        $response = $this->get('/icon/999');

        // Should return 200 (defaults to 512)
        $this->assertStatusCode(200, $response);
        $this->assertHeaderContains('content-type', 'image/png', $response);
    }

    public function testIconZeroSizeDefaultsTo512(): void
    {
        $response = $this->get('/icon/0');

        $this->assertStatusCode(200, $response);
    }

    // =========================================================================
    // Error handling tests
    // =========================================================================

    public function testNotFoundReturns404(): void
    {
        $response = $this->get('/nonexistent');

        $this->assertStatusCode(404, $response);
    }

    public function testNotFoundReturnsJson(): void
    {
        $response = $this->get('/nonexistent');

        $this->assertJsonResponse($response);
        $this->assertJsonHasKey('error', $response);
    }

    // =========================================================================
    // Ping endpoint tests (sanity check)
    // =========================================================================

    public function testPingReturnsOk(): void
    {
        $response = $this->get('/ping');

        $this->assertStatusCode(200, $response);
        $this->assertJsonEquals('status', 'ok', $response);
    }
}
