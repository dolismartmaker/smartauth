<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\UploadHelper;

/**
 * Unit tests for UploadHelper module-side consumption API.
 *
 * @covers \SmartAuth\Api\UploadHelper
 */
class UploadHelperTest extends TestCase
{
    /** @var string */
    private $tempBase;

    protected function setUp(): void
    {
        global $conf;

        $this->tempBase = sys_get_temp_dir() . '/smartauth-helper-test-' . bin2hex(random_bytes(4));
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
        $this->rrmdir($this->tempBase);
    }

    private function rrmdir($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
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

    public function testConsumeReturnsNullForUnknownUpload(): void
    {
        $dest = $this->tempBase . '/dest/photo.jpg';
        $info = UploadHelper::consumeUpload(str_repeat('a', 64), 1, $dest);
        $this->assertNull($info);
        $this->assertFileDoesNotExist($dest);
    }

    public function testDescribeReturnsNullForUnknownUpload(): void
    {
        $info = UploadHelper::describe(str_repeat('a', 64), 1);
        $this->assertNull($info);
    }

    public function testDiscardIsIdempotent(): void
    {
        // Idempotent: no upload, returns true.
        $this->assertTrue(UploadHelper::discard(str_repeat('a', 64), 42));
        $this->assertTrue(UploadHelper::discard(str_repeat('a', 64), 42));
    }

    public function testConsumeUploadIntegratesWithStagedFile(): void
    {
        // Bypass SmartUpload::store() (it calls move_uploaded_file which
        // requires a real upload) by writing the staging tree manually.
        $userId = 99;
        $uploadId = bin2hex(random_bytes(32));
        $stagingDir = $this->tempBase . '/upload-staging/' . $userId . '/' . $uploadId;
        mkdir($stagingDir, 0700, true);

        $payload = "fake-image-bytes";
        $stagedFile = $stagingDir . '/photo.jpg';
        file_put_contents($stagedFile, $payload);

        $meta = [
            'upload_id' => $uploadId,
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'user_id' => $userId,
            'entity' => 1,
            'created' => time(),
            'expires' => time() + 600,
            'consumed' => 0,
        ];
        file_put_contents($stagingDir . '/meta.json', json_encode($meta));

        $dest = $this->tempBase . '/final/cover.jpg';
        $result = UploadHelper::consumeUpload($uploadId, $userId, $dest);

        $this->assertNotNull($result);
        $this->assertSame('photo.jpg', $result['filename']);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame($payload, file_get_contents($dest));
        // Staging entry must be gone after consume.
        $this->assertFileDoesNotExist($stagedFile);
    }
}
