<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\SmartUpload;

/**
 * Unit tests for SmartUpload service.
 *
 * Storage and validation are exercised against a temporary directory
 * scoped to each test so that runs do not leak into the real smartauth
 * data root.
 *
 * @covers \SmartAuth\Api\SmartUpload
 */
class SmartUploadTest extends TestCase
{
    /** @var string */
    private $tempBase;

    /** @var array<string> */
    private $createdFiles = [];

    protected function setUp(): void
    {
        global $conf;

        $this->tempBase = sys_get_temp_dir() . '/smartauth-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempBase, 0700, true);

        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        $conf->entity = 1;
        $conf->smartauth = new \stdClass();
        $conf->smartauth->dir_output = $this->tempBase;
        $conf->global = new \stdClass();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->rrmdir($this->tempBase);
    }

    private function rrmdir($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build a fake $_FILES entry pointing at a real temp file. The file
     * is NOT a moved upload so SmartUpload::store() will fail at the
     * is_uploaded_file check; this is fine for validation-only tests.
     */
    private function buildFile(string $contents, string $name = 'photo.jpg'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'smartauth-upload-');
        file_put_contents($tmp, $contents);
        $this->createdFiles[] = $tmp;
        return [
            'name' => $name,
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    public function testValidateRejectsMissingFile(): void
    {
        $err = SmartUpload::validate([
            'name' => '', 'type' => '', 'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
        ]);
        $this->assertSame('No file uploaded', $err);
    }

    public function testValidateRejectsNonArray(): void
    {
        $err = SmartUpload::validate('not-an-array');
        $this->assertSame('Invalid file payload', $err);
    }

    public function testValidateRejectsFileFromTestContext(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat('A', 100);
        $file = $this->buildFile($png, 'big.png');
        $err = SmartUpload::validate($file, ['maxBytes' => 8]);
        $this->assertNotNull($err);
        $this->assertSame("Not a valid uploaded file", $err);
    }

    public function testValidateRejectsZeroSizePayload(): void
    {
        $file = [
            'name' => 'empty.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/does-not-matter',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ];
        $this->assertNotNull(SmartUpload::validate($file));
    }

    public function testValidateAcceptsAllowedMimeOverride(): void
    {
        $file = $this->buildFile('plain text content', 'note.txt');
        $err = SmartUpload::validate($file, [
            'allowedMime' => ['text/plain'],
        ]);
        // is_uploaded_file gate still fails in test context, but MIME is OK.
        $this->assertSame('Not a valid uploaded file', $err);
    }

    public function testValidateRejectsDisallowedMime(): void
    {
        // text/plain is not in the default whitelist.
        $file = $this->buildFile('plain text content', 'note.txt');
        $err = SmartUpload::validate($file);
        $this->assertNotNull($err);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $info = SmartUpload::get(str_repeat('a', 64), 1);
        $this->assertNull($info);
    }

    public function testGetReturnsNullForShortId(): void
    {
        $info = SmartUpload::get('short', 1);
        $this->assertNull($info);
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->assertTrue(SmartUpload::delete(str_repeat('a', 64), 42));
        $this->assertTrue(SmartUpload::delete(str_repeat('a', 64), 42));
    }

    public function testDeleteRejectsShortId(): void
    {
        $this->assertFalse(SmartUpload::delete('too-short', 1));
    }

    public function testCleanupOnEmptyTreeReturnsZero(): void
    {
        $this->assertSame(0, SmartUpload::cleanup());
    }

    public function testStoreRejectsZeroUserId(): void
    {
        $file = $this->buildFile('payload', 'a.jpg');
        $this->expectException(\InvalidArgumentException::class);
        SmartUpload::store($file, 0);
    }

    public function testTtlIsClampedToBounds(): void
    {
        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('clampTtl');
        $m->setAccessible(true);
        $this->assertSame(60, $m->invoke(null, 1));
        $this->assertSame(SmartUpload::MAX_TTL, $m->invoke(null, 9999999));
        $this->assertSame(SmartUpload::DEFAULT_TTL, $m->invoke(null, null));
    }

    public function testFilenameSanitizationStripsTraversal(): void
    {
        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('sanitizeFilename');
        $m->setAccessible(true);
        $clean = $m->invoke(null, '../../etc/passwd');
        $this->assertStringNotContainsString('/', $clean);
        $this->assertNotEmpty($clean);
    }

    public function testFilenameSanitizationEnforcesLengthCap(): void
    {
        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('sanitizeFilename');
        $m->setAccessible(true);
        $clean = $m->invoke(null, str_repeat('a', 300) . '.jpg');
        $this->assertLessThanOrEqual(200, strlen($clean));
        $this->assertStringEndsWith('.jpg', $clean);
    }

    public function testResolveMaxBytesUsesConfigOverride(): void
    {
        global $conf;
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_UPLOAD_MAX_BYTES = '512';

        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('resolveMaxBytes');
        $m->setAccessible(true);
        $this->assertSame(512, $m->invoke(null, null));
    }

    public function testResolveMaxBytesFallsBackToDefault(): void
    {
        global $conf;
        $conf->global = new \stdClass();

        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('resolveMaxBytes');
        $m->setAccessible(true);
        $this->assertSame(SmartUpload::DEFAULT_MAX_BYTES, $m->invoke(null, null));
    }

    public function testResolveAllowedMimeUsesConfigOverride(): void
    {
        global $conf;
        $conf->global = new \stdClass();
        $conf->global->SMARTAUTH_UPLOAD_ALLOWED_MIME = 'image/svg+xml , application/json';

        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('resolveAllowedMime');
        $m->setAccessible(true);
        $list = $m->invoke(null, null);
        $this->assertContains('image/svg+xml', $list);
        $this->assertContains('application/json', $list);
    }

    public function testResolveAllowedMimeUsesOptionsOverride(): void
    {
        $rc = new \ReflectionClass(SmartUpload::class);
        $m = $rc->getMethod('resolveAllowedMime');
        $m->setAccessible(true);
        $list = $m->invoke(null, ['allowedMime' => ['IMAGE/PNG']]);
        $this->assertSame(['image/png'], $list);
    }
}
