<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ObjectDocumentController.php';
require_once __DIR__ . '/../../../api/InputSanitizer.php';

use SmartAuth\Api\ObjectDocumentController;

/**
 * Integration tests for ObjectDocumentController
 *
 * @covers \SmartAuth\Api\ObjectDocumentController
 */
class ObjectDocumentControllerIntegrationTest extends DolibarrRealTestCase
{
    private ObjectDocumentController $controller;
    private string $testDocDir;

    protected function setUp(): void
    {
        global $conf;

        parent::setUp();
        $this->controller = new ObjectDocumentController();

        // Ensure required modules are enabled for tests
        // isModEnabled() checks $conf->modules[$module]
        if (!isset($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['societe'] = 1;

        // Also set the old-style config for compatibility
        if (!isset($conf->societe) || !is_object($conf->societe)) {
            $conf->societe = new \stdClass();
        }
        $conf->societe->enabled = 1;
        $conf->societe->dir_output = DOL_DATA_ROOT . '/societe';

        // Enable thirdparty read permissions for test user
        if (!empty($this->testUser) && is_object($this->testUser)) {
            if (!isset($this->testUser->rights) || !is_object($this->testUser->rights)) {
                $this->testUser->rights = new \stdClass();
            }
            $this->testUser->rights->societe = new \stdClass();
            $this->testUser->rights->societe->lire = 1;
            $this->testUser->rights->societe->read = 1;
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (!empty($this->testDocDir) && is_dir($this->testDocDir)) {
            $this->removeDirectory($this->testDocDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // getSupportedTypes() tests
    // =========================================================================

    public function testGetSupportedTypesReturnsBuiltInTypes(): void
    {
        $types = ObjectDocumentController::getSupportedTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('product', $types);
        $this->assertArrayHasKey('thirdparty', $types);
        $this->assertArrayHasKey('project', $types);
        $this->assertArrayHasKey('intervention', $types);
    }

    public function testGetSupportedTypesContainsModuleInfo(): void
    {
        $types = ObjectDocumentController::getSupportedTypes();

        $this->assertArrayHasKey('module', $types['product']);
        $this->assertArrayHasKey('modulepart', $types['product']);
        $this->assertEquals('product', $types['product']['module']);
    }

    // =========================================================================
    // registerObjectType() tests
    // =========================================================================

    public function testRegisterObjectTypeSucceeds(): void
    {
        $result = ObjectDocumentController::registerObjectType('testobject', [
            'class' => 'TestObject',
            'file' => '/test/class/testobject.class.php',
            'module' => 'test',
            'modulepart' => 'test',
            'subdir_method' => 'getTestSubdir',
        ]);

        $this->assertTrue($result);

        $types = ObjectDocumentController::getSupportedTypes();
        $this->assertArrayHasKey('testobject', $types);
    }

    public function testRegisterObjectTypeFailsWithMissingKeys(): void
    {
        $result = ObjectDocumentController::registerObjectType('incomplete', [
            'class' => 'TestObject',
            // Missing required keys
        ]);

        $this->assertFalse($result);
    }

    // =========================================================================
    // index() - List documents tests
    // =========================================================================

    public function testIndexReturnsErrorWithoutUser(): void
    {
        $result = $this->controller->index([
            'type' => 'product',
            'id' => 1,
        ]);

        $this->assertEquals(401, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    public function testIndexReturnsErrorWithInvalidType(): void
    {
        $result = $this->controller->index([
            'type' => 'invalid_type',
            'id' => 1,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid object type', $result[0]['error']);
    }

    public function testIndexReturnsErrorWithInvalidId(): void
    {
        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => 0,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid object ID', $result[0]['error']);
    }

    public function testIndexReturnsErrorWhenObjectNotFound(): void
    {
        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => 999999,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(404, $result[1]);
        $this->assertStringContainsString('not found', $result[0]['error']);
    }

    public function testIndexReturnsEmptyArrayWhenNoDocuments(): void
    {
        // Create a thirdparty without documents
        $societe = $this->createTestSociete(['name' => 'NoDocsCompany']);

        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('documents', $result[0]);
        $this->assertIsArray($result[0]['documents']);
        $this->assertArrayHasKey('server_time', $result[0]);
    }

    public function testIndexReturnsDocumentsWhenPresent(): void
    {
        global $conf;

        // Create a thirdparty
        $societe = $this->createTestSociete(['name' => 'WithDocsCompany']);

        // Create document directory and a test file
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $testFile = $this->testDocDir . '/test_document.pdf';
        file_put_contents($testFile, 'PDF test content');

        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertNotEmpty($result[0]['documents']);

        $doc = $result[0]['documents'][0];
        $this->assertEquals('test_document.pdf', $doc['filename']);
        $this->assertEquals($societe->id, $doc['object_id']);
        $this->assertArrayHasKey('mime_type', $doc);
        $this->assertArrayHasKey('size', $doc);
        $this->assertArrayHasKey('updated_at', $doc);
        $this->assertEquals('pdf', $doc['type']);
    }

    public function testIndexFiltersBySinceParameter(): void
    {
        global $conf;

        // Create a thirdparty
        $societe = $this->createTestSociete(['name' => 'FilteredDocsCompany']);

        // Create document directory and files
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $oldFile = $this->testDocDir . '/old_document.pdf';
        file_put_contents($oldFile, 'Old content');
        touch($oldFile, strtotime('-1 week'));

        $newFile = $this->testDocDir . '/new_document.pdf';
        file_put_contents($newFile, 'New content');

        // Filter to only get files from yesterday onwards
        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'since' => date('c', strtotime('-1 day')),
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertCount(1, $result[0]['documents']);
        $this->assertEquals('new_document.pdf', $result[0]['documents'][0]['filename']);
    }

    // =========================================================================
    // download() tests
    // =========================================================================

    public function testDownloadReturnsErrorWithMissingPath(): void
    {
        $societe = $this->createTestSociete(['name' => 'DownloadTestCompany']);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            // Missing 'path'
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('path', $result[0]['error']);
    }

    public function testDownloadReturnsErrorOnPathTraversal(): void
    {
        $societe = $this->createTestSociete(['name' => 'PathTraversalTest']);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => '../../../etc/passwd',
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid path', $result[0]['error']);
    }

    public function testDownloadReturnsErrorWhenFileNotFound(): void
    {
        $societe = $this->createTestSociete(['name' => 'FileNotFoundTest']);

        // Create directory but no file
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => 'nonexistent.pdf',
        ]);

        $this->assertEquals(404, $result[1]);
        $this->assertStringContainsString('not found', $result[0]['error']);
    }

    public function testDownloadReturnsBase64EncodedContent(): void
    {
        $societe = $this->createTestSociete(['name' => 'DownloadSuccessTest']);

        // Create document
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $testContent = 'This is test file content for download';
        $testFile = $this->testDocDir . '/downloadable.txt';
        file_put_contents($testFile, $testContent);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => 'downloadable.txt',
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('downloadable.txt', $result[0]['filename']);
        $this->assertEquals('base64', $result[0]['encoding']);
        $this->assertEquals(strlen($testContent), $result[0]['filesize']);
        $this->assertEquals($testContent, base64_decode($result[0]['content']));
    }

    public function testDownloadHandlesUrlEncodedPath(): void
    {
        $societe = $this->createTestSociete(['name' => 'UrlEncodedPathTest']);

        // Create document with space in name
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $testFile = $this->testDocDir . '/file with spaces.txt';
        file_put_contents($testFile, 'content');

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => urlencode('file with spaces.txt'),
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('file with spaces.txt', $result[0]['filename']);
    }

    // =========================================================================
    // Document type detection tests
    // =========================================================================

    public function testDocumentTypeDetection(): void
    {
        $societe = $this->createTestSociete(['name' => 'TypeDetectionTest']);

        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        // Create files of different types
        file_put_contents($this->testDocDir . '/image.jpg', 'fake image');
        file_put_contents($this->testDocDir . '/document.pdf', 'fake pdf');
        file_put_contents($this->testDocDir . '/data.csv', 'fake csv');

        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);

        $types = [];
        foreach ($result[0]['documents'] as $doc) {
            $types[$doc['filename']] = $doc['type'];
        }

        $this->assertEquals('image', $types['image.jpg']);
        $this->assertEquals('pdf', $types['document.pdf']);
        $this->assertEquals('other', $types['data.csv']);
    }
}
