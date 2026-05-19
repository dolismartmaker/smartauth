<?php

/**
 * HTTP functional tests for the media/thirdparty/{id}/logo[/mini] binary
 * stream. Runs against the PHP built-in server with the
 * dolibarr-integration-sqlite bootstrap. Validates that the streaming
 * path (readfileLowMemory + headers + exit) produces an output whose
 * body sha256 matches the source file byte-for-byte, and that ETag /
 * If-None-Match revalidation produces a real 304 over the wire.
 *
 * The JWT layer is not exercised here; that path is covered by the
 * integration-dolibarr suite. The point of this HTTP test is purely the
 * binary transport: no JSON escaping, no truncation, no inadvertent
 * base64, correct Content-Length, correct Content-Type per extension.
 *
 * @covers \SmartAuth\Api\ThirdpartyMediaController
 */

namespace SmartAuth\Tests\Http;

class ThirdpartyMediaHttpTest extends HttpTestCase
{
    /**
     * Seed a thirdparty with both a full-size and a mini logo on disk.
     *
     * @param string $ext png|jpg
     * @return array Seed payload as returned by the test router
     */
    private function seedThirdpartyWithLogo(string $ext = 'png'): array
    {
        $response = $this->get('/_test/seed-thirdparty-logo?ext=' . $ext);
        $this->assertStatusCode(200, $response);
        $this->assertNotNull($response['json'], 'seed must return JSON');
        $this->assertArrayHasKey('id', $response['json']);
        $this->assertArrayHasKey('sha256_full', $response['json']);
        return $response['json'];
    }

    public function testFullLogoBinaryStreamMatchesSourceSha256(): void
    {
        $seed = $this->seedThirdpartyWithLogo('png');

        $response = $this->get('/media/thirdparty/' . $seed['id'] . '/logo');

        $this->assertStatusCode(200, $response);
        $this->assertHeader('content-type', 'image/png', $response);
        $this->assertHeader('content-length', (string) $seed['size_full'], $response);

        // Byte-for-byte integrity: the body coming over HTTP must be
        // identical to the file on disk. This is THE invariant of the
        // whole spec (no base64, no inadvertent string mangling).
        $this->assertEquals(
            $seed['sha256_full'],
            hash('sha256', $response['body']),
            'Streamed body sha256 must match source file sha256'
        );
    }

    public function testMiniLogoBinaryStreamMatchesSourceSha256(): void
    {
        $seed = $this->seedThirdpartyWithLogo('png');

        $response = $this->get('/media/thirdparty/' . $seed['id'] . '/logo/mini');

        $this->assertStatusCode(200, $response);
        $this->assertHeader('content-type', 'image/png', $response);
        $this->assertHeader('content-length', (string) $seed['size_mini'], $response);
        $this->assertEquals(
            $seed['sha256_mini'],
            hash('sha256', $response['body']),
            'Mini stream sha256 must match the thumbnail on disk (NOT the full size)'
        );
        // Defence in depth: the mini must be smaller than the full so we
        // know the controller actually served the right variant.
        $this->assertNotEquals($seed['sha256_full'], $seed['sha256_mini']);
    }

    public function testJpegLogoContentTypeIsImageJpeg(): void
    {
        $seed = $this->seedThirdpartyWithLogo('jpg');

        $response = $this->get('/media/thirdparty/' . $seed['id'] . '/logo');

        $this->assertStatusCode(200, $response);
        $this->assertHeader('content-type', 'image/jpeg', $response);
        $this->assertEquals(
            $seed['sha256_full'],
            hash('sha256', $response['body'])
        );
    }

    public function testLogoEmitsLongCacheControlAndEtag(): void
    {
        $seed = $this->seedThirdpartyWithLogo('png');

        $response = $this->get('/media/thirdparty/' . $seed['id'] . '/logo');

        $this->assertStatusCode(200, $response);
        $this->assertHeaderContains('cache-control', 'private', $response);
        $this->assertHeaderContains('cache-control', 'max-age=86400', $response);
        $this->assertHeaderContains('cache-control', 'stale-while-revalidate=2592000', $response);
        $this->assertStringNotContainsString(
            'public',
            $response['headers']['cache-control'][0] ?? '',
            'Cache must be private (JWT-protected route)'
        );

        $this->assertArrayHasKey('etag', $response['headers']);
        $etag = $response['headers']['etag'][0];
        // Expected format: "<hex>-<hex>" (filesize-mtime)
        $this->assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', $etag);
    }

    public function testLogoReturns304WhenIfNoneMatchMatches(): void
    {
        $seed = $this->seedThirdpartyWithLogo('png');

        // First GET captures the ETag.
        $first = $this->get('/media/thirdparty/' . $seed['id'] . '/logo');
        $this->assertStatusCode(200, $first);
        $etag = $first['headers']['etag'][0];

        // Second GET with If-None-Match must return 304 with no body
        // and the cache directives re-asserted.
        $second = $this->get(
            '/media/thirdparty/' . $seed['id'] . '/logo',
            ['If-None-Match' => $etag]
        );
        $this->assertStatusCode(304, $second);
        $this->assertEquals('', $second['body'], '304 must not carry a body');
        $this->assertArrayHasKey('etag', $second['headers']);
        $this->assertEquals($etag, $second['headers']['etag'][0]);
        $this->assertHeaderContains('cache-control', 'max-age=86400', $second);
    }

    public function testLogoReturns200WhenIfNoneMatchStale(): void
    {
        $seed = $this->seedThirdpartyWithLogo('png');

        $response = $this->get(
            '/media/thirdparty/' . $seed['id'] . '/logo',
            ['If-None-Match' => '"stale-etag-deadbeef"']
        );

        $this->assertStatusCode(200, $response);
        $this->assertEquals(
            $seed['sha256_full'],
            hash('sha256', $response['body']),
            'Stale If-None-Match must still deliver the full binary'
        );
    }

    public function testLogoReturns404WhenThirdpartyHasNoLogo(): void
    {
        // Seed a thirdparty with NO logo column populated.
        $response = $this->get('/_test/seed-thirdparty-logo?ext=png&nologo=1');
        $this->assertStatusCode(200, $response);
        $id = $response['json']['id'];

        $res = $this->get('/media/thirdparty/' . $id . '/logo');
        $this->assertStatusCode(404, $res);
        $this->assertJsonResponse($res);
        $this->assertArrayHasKey('error', $res['json']);
    }

    public function testLogoReturns404WhenThirdpartyDoesNotExist(): void
    {
        $res = $this->get('/media/thirdparty/9999999/logo');
        $this->assertStatusCode(404, $res);
    }
}
