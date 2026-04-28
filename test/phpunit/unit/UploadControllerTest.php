<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\UploadController;

/**
 * Unit tests for UploadController.
 *
 * The store() method exits the test process early because we cannot
 * easily simulate an authenticated user + a real $_FILES upload from
 * PHPUnit (move_uploaded_file requires the SAPI upload pipeline). We
 * focus on the explicit error paths and on collectFiles() shape
 * normalization.
 *
 * @covers \SmartAuth\Api\UploadController
 */
class UploadControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
    }

    public function testStoreRejectsAnonymousCaller(): void
    {
        $controller = new UploadController();
        list($body, $code) = $controller->store(['user' => null]);
        $this->assertSame(401, $code);
        $this->assertSame('Authentication required', $body['error']);
    }

    public function testStoreReturnsBadRequestWhenNoFile(): void
    {
        $controller = new UploadController();
        $user = new \stdClass();
        $user->id = 7;
        list($body, $code) = $controller->store(['user' => $user, 'entity' => 1]);
        $this->assertSame(400, $code);
        $this->assertSame('No file uploaded', $body['error']);
    }

    public function testDestroyRejectsAnonymous(): void
    {
        $controller = new UploadController();
        list($body, $code) = $controller->destroy(['user' => null, 'upload_id' => 'abc']);
        $this->assertSame(401, $code);
    }

    public function testDestroyRequiresUploadId(): void
    {
        $controller = new UploadController();
        $user = new \stdClass();
        $user->id = 7;
        list($body, $code) = $controller->destroy(['user' => $user]);
        $this->assertSame(400, $code);
        $this->assertSame('Missing upload id', $body['error']);
    }

    public function testCollectFilesNormalizesIndexedShape(): void
    {
        $_FILES = [
            'files[0]' => [
                'name' => 'a.jpg', 'type' => 'image/jpeg',
                'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 1,
            ],
            'files[1]' => [
                'name' => 'b.jpg', 'type' => 'image/jpeg',
                'tmp_name' => '/tmp/b', 'error' => 0, 'size' => 2,
            ],
        ];

        $rc = new \ReflectionClass(UploadController::class);
        $m = $rc->getMethod('collectFiles');
        $m->setAccessible(true);
        $controller = new UploadController();
        $files = $m->invoke($controller);

        $this->assertCount(2, $files);
        $this->assertArrayHasKey('files[0]', $files);
        $this->assertArrayHasKey('files[1]', $files);
    }

    public function testCollectFilesNormalizesHtml5MultipleShape(): void
    {
        $_FILES = [
            'files' => [
                'name' => ['a.jpg', 'b.jpg'],
                'type' => ['image/jpeg', 'image/jpeg'],
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'error' => [0, 0],
                'size' => [1, 2],
            ],
        ];

        $rc = new \ReflectionClass(UploadController::class);
        $m = $rc->getMethod('collectFiles');
        $m->setAccessible(true);
        $controller = new UploadController();
        $files = $m->invoke($controller);

        $this->assertCount(2, $files);
        $this->assertSame('a.jpg', $files['files[0]']['name']);
        $this->assertSame('b.jpg', $files['files[1]']['name']);
    }

    public function testCollectFilesPicksSingleFileEntry(): void
    {
        $_FILES = [
            'file' => [
                'name' => 'x.jpg', 'type' => 'image/jpeg',
                'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 1,
            ],
        ];

        $rc = new \ReflectionClass(UploadController::class);
        $m = $rc->getMethod('collectFiles');
        $m->setAccessible(true);
        $controller = new UploadController();
        $files = $m->invoke($controller);

        $this->assertCount(1, $files);
        $this->assertArrayHasKey('file', $files);
    }
}
