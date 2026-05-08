<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\AnnotationsHelper;

/**
 * Unit tests for AnnotationsHelper::sanitize().
 *
 * The set() / get() integration with EcmFiles + extrafields is covered
 * separately by the Dolibarr SQLite integration suite -- see
 * test/phpunit/integration-dolibarr/AnnotationsHelperIntegrationTest.php.
 *
 * @covers \SmartAuth\Api\AnnotationsHelper
 */
class AnnotationsHelperTest extends TestCase
{
    public function testSanitizeRejectsInvalidId(): void
    {
        $cases = [
            ['type' => 'note', 'x' => 0, 'y' => 0],                                  // missing id
            ['id' => '', 'type' => 'note', 'x' => 0, 'y' => 0],                      // empty id
            ['id' => str_repeat('a', 81), 'type' => 'note', 'x' => 0, 'y' => 0],     // too long
            ['id' => 'has space', 'type' => 'note', 'x' => 0, 'y' => 0],             // space
            ['id' => "non\xC3\xA9ascii", 'type' => 'note', 'x' => 0, 'y' => 0],      // non-ASCII
            ['id' => ['nested'], 'type' => 'note', 'x' => 0, 'y' => 0],              // non-scalar
        ];
        $this->assertSame([], AnnotationsHelper::sanitize($cases));
    }

    public function testSanitizeRejectsInvalidType(): void
    {
        $cases = [
            ['id' => 'a', 'x' => 0, 'y' => 0],                              // missing type
            ['id' => 'b', 'type' => '', 'x' => 0, 'y' => 0],                // empty
            ['id' => 'c', 'type' => 'NOTE', 'x' => 0, 'y' => 0],            // uppercase
            ['id' => 'd', 'type' => '1note', 'x' => 0, 'y' => 0],           // starts with digit
            ['id' => 'e', 'type' => 'has space', 'x' => 0, 'y' => 0],       // space
            ['id' => 'f', 'type' => str_repeat('a', 33), 'x' => 0, 'y' => 0], // too long
        ];
        $this->assertSame([], AnnotationsHelper::sanitize($cases));
    }

    public function testSanitizeClampsCoordinates(): void
    {
        $raw = [
            ['id' => 'low',  'type' => 'note', 'x' => -10, 'y' => 50],
            ['id' => 'high', 'type' => 'note', 'x' => 50,  'y' => 150],
            ['id' => 'ok',   'type' => 'note', 'x' => 12.5, 'y' => 87.5],
        ];
        $clean = AnnotationsHelper::sanitize($raw);

        $this->assertCount(3, $clean);
        $byId = [];
        foreach ($clean as $a) {
            $byId[$a['id']] = $a;
        }

        $this->assertSame(0.0,  $byId['low']['x']);
        $this->assertSame(50.0, $byId['low']['y']);
        $this->assertSame(50.0,  $byId['high']['x']);
        $this->assertSame(100.0, $byId['high']['y']);
        $this->assertSame(12.5, $byId['ok']['x']);
        $this->assertSame(87.5, $byId['ok']['y']);
    }

    public function testSanitizeRejectsNonArrayPayload(): void
    {
        $cases = [
            ['id' => 'a', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => 'string'],
            ['id' => 'b', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => 42],
            ['id' => 'c', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => true],
        ];
        $this->assertSame([], AnnotationsHelper::sanitize($cases));
    }

    public function testSanitizeRejectsTooDeepPayload(): void
    {
        // Build a payload nested 6 levels deep -- one level over the cap.
        $deep = 'leaf';
        for ($i = 0; $i < 6; $i++) {
            $deep = ['k' => $deep];
        }
        $cases = [
            ['id' => 'deep', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => $deep],
        ];
        $this->assertSame([], AnnotationsHelper::sanitize($cases));

        // Sanity check: 5 levels is accepted.
        $shallow = 'leaf';
        for ($i = 0; $i < 5; $i++) {
            $shallow = ['k' => $shallow];
        }
        $cases2 = [
            ['id' => 'ok', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => $shallow],
        ];
        $this->assertCount(1, AnnotationsHelper::sanitize($cases2));
    }

    public function testSanitizeDedupesById(): void
    {
        $raw = [
            ['id' => 'dup', 'type' => 'note', 'x' => 1, 'y' => 1, 'payload' => ['v' => 'first']],
            ['id' => 'dup', 'type' => 'note', 'x' => 2, 'y' => 2, 'payload' => ['v' => 'second']],
            ['id' => 'dup', 'type' => 'note', 'x' => 3, 'y' => 3, 'payload' => ['v' => 'third']],
            ['id' => 'unique', 'type' => 'note', 'x' => 9, 'y' => 9],
        ];
        $clean = AnnotationsHelper::sanitize($raw);

        $this->assertCount(2, $clean);
        $byId = [];
        foreach ($clean as $a) {
            $byId[$a['id']] = $a;
        }
        $this->assertSame('third', $byId['dup']['payload']['v']);
        $this->assertSame(3.0, $byId['dup']['x']);
        $this->assertSame(3.0, $byId['dup']['y']);
    }

    public function testSanitizeRejectsNonJsonEncodablePayload(): void
    {
        // NaN / Inf are not encodable with JSON_THROW_ON_ERROR.
        $cases = [
            ['id' => 'nan', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => ['v' => NAN]],
            ['id' => 'inf', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => ['v' => INF]],
        ];
        $this->assertSame([], AnnotationsHelper::sanitize($cases));

        // A resource also fails.
        $fp = fopen('php://memory', 'r');
        try {
            $cases2 = [
                ['id' => 'res', 'type' => 'note', 'x' => 0, 'y' => 0, 'payload' => ['v' => $fp]],
            ];
            $this->assertSame([], AnnotationsHelper::sanitize($cases2));
        } finally {
            fclose($fp);
        }
    }

    public function testSanitizeAcceptsFullValidEntry(): void
    {
        $raw = [
            [
                'id' => 'abc-def_1',
                'type' => 'note',
                'x' => 50.5,
                'y' => 25.0,
                'payload' => ['description' => 'filtre clim use', 'severity' => 2],
            ],
        ];
        $clean = AnnotationsHelper::sanitize($raw);

        $this->assertCount(1, $clean);
        $this->assertSame('abc-def_1', $clean[0]['id']);
        $this->assertSame('note', $clean[0]['type']);
        $this->assertSame(50.5, $clean[0]['x']);
        $this->assertSame(25.0, $clean[0]['y']);
        $this->assertSame('filtre clim use', $clean[0]['payload']['description']);
        $this->assertSame(2, $clean[0]['payload']['severity']);
    }

    public function testSanitizeAcceptsIntegerIdAsString(): void
    {
        $raw = [
            ['id' => 42, 'type' => 'note', 'x' => 0, 'y' => 0],
        ];
        $clean = AnnotationsHelper::sanitize($raw);
        $this->assertCount(1, $clean);
        $this->assertSame('42', $clean[0]['id']);
    }

    public function testSanitizeSkipsNonArrayEntries(): void
    {
        $raw = [
            'string-entry',
            42,
            null,
            ['id' => 'ok', 'type' => 'note', 'x' => 0, 'y' => 0],
        ];
        $clean = AnnotationsHelper::sanitize($raw);
        $this->assertCount(1, $clean);
        $this->assertSame('ok', $clean[0]['id']);
    }

    public function testSanitizeFillsEmptyPayloadWhenAbsent(): void
    {
        $raw = [
            ['id' => 'no-payload', 'type' => 'note', 'x' => 0, 'y' => 0],
        ];
        $clean = AnnotationsHelper::sanitize($raw);
        $this->assertCount(1, $clean);
        $this->assertSame([], $clean[0]['payload']);
    }
}
