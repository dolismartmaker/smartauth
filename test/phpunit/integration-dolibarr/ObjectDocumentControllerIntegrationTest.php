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

    // =========================================================================
    // downloadBinary() tests
    // =========================================================================

    public function testDownloadBinaryReturnsErrorWithMissingPath(): void
    {
        $societe = $this->createTestSociete(['name' => 'BinaryMissingPathTest']);

        $result = $this->controller->downloadBinary([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            // Missing 'path'
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('path', $result[0]['error']);
    }

    public function testDownloadBinaryReturnsErrorOnPathTraversal(): void
    {
        $societe = $this->createTestSociete(['name' => 'BinaryPathTraversalTest']);

        $result = $this->controller->downloadBinary([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => '../../../etc/passwd',
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid path', $result[0]['error']);
    }

    public function testDownloadBinaryReturnsErrorWhenFileNotFound(): void
    {
        $societe = $this->createTestSociete(['name' => 'BinaryFileNotFoundTest']);

        // Create directory but no file
        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        $result = $this->controller->downloadBinary([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => 'nonexistent.pdf',
        ]);

        $this->assertEquals(404, $result[1]);
        $this->assertStringContainsString('not found', $result[0]['error']);
    }

    // =========================================================================
    // Product type tests
    // =========================================================================

    public function testIndexWithProductType(): void
    {
        global $conf, $db;

        // Enable product module
        $conf->modules['product'] = 1;
        if (!isset($conf->produit) || !is_object($conf->produit)) {
            $conf->produit = new \stdClass();
        }
        $conf->produit->enabled = 1;
        $conf->produit->dir_output = DOL_DATA_ROOT . '/produit';
        $conf->produit->multidir_output = [1 => DOL_DATA_ROOT . '/produit'];

        // Grant permissions
        $this->testUser->rights->produit = new \stdClass();
        $this->testUser->rights->produit->lire = 1;
        $this->testUser->rights->produit->read = 1;

        // Create a test product
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $product = new \Product($db);
        $product->ref = 'TEST-PROD-' . uniqid();
        $product->label = 'Test Product';
        $product->type = 0; // Product (not service)
        $product->status = 1;
        $product->create($this->testUser);

        // Create document directory and file
        $this->testDocDir = DOL_DATA_ROOT . '/produit/' . $product->ref;
        @mkdir($this->testDocDir, 0755, true);
        file_put_contents($this->testDocDir . '/datasheet.pdf', 'Product datasheet');

        $result = $this->controller->index([
            'type' => 'product',
            'id' => $product->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('documents', $result[0]);

        // Cleanup
        $product->delete($this->testUser);
    }

    public function testIndexWithProductTypeReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;

        // Disable product module
        unset($conf->modules['product']);

        $result = $this->controller->index([
            'type' => 'product',
            'id' => 1,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(403, $result[1]);
        $this->assertStringContainsString('Module not enabled', $result[0]['error']);
    }

    // =========================================================================
    // Project type tests
    // =========================================================================

    public function testIndexWithProjectType(): void
    {
        global $conf, $db;

        // Enable project module (uses 'projet' in modules array but 'project' for config)
        $conf->modules['projet'] = 1;
        if (!isset($conf->projet) || !is_object($conf->projet)) {
            $conf->projet = new \stdClass();
        }
        $conf->projet->enabled = 1;
        $conf->projet->dir_output = DOL_DATA_ROOT . '/projet';

        // Also set $conf->project for code that uses English name
        if (!isset($conf->project) || !is_object($conf->project)) {
            $conf->project = new \stdClass();
        }
        $conf->project->dir_output = DOL_DATA_ROOT . '/projet';

        // Grant permissions
        $this->testUser->rights->projet = new \stdClass();
        $this->testUser->rights->projet->lire = 1;
        $this->testUser->rights->projet->read = 1;

        // Create a test project
        require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
        $project = new \Project($db);
        $project->ref = 'PROJ-' . uniqid();
        $project->title = 'Test Project';
        $project->status = 1;
        $project->date_start = time();
        $project->create($this->testUser);

        // Create document directory
        $this->testDocDir = DOL_DATA_ROOT . '/projet/' . $project->ref;
        @mkdir($this->testDocDir, 0755, true);
        file_put_contents($this->testDocDir . '/specs.pdf', 'Project specs');

        $result = $this->controller->index([
            'type' => 'project',
            'id' => $project->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertArrayHasKey('documents', $result[0]);

        // Cleanup - don't delete to avoid $conf->project error
    }

    public function testIndexWithProjectTypeReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;

        // Ensure project module is disabled
        unset($conf->modules['projet']);

        $result = $this->controller->index([
            'type' => 'project',
            'id' => 1,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(403, $result[1]);
        $this->assertStringContainsString('Module not enabled', $result[0]['error']);
    }

    public function testIndexWithInterventionTypeReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;

        // Ensure fichinter module is disabled
        unset($conf->modules['ficheinter']);

        $result = $this->controller->index([
            'type' => 'intervention',
            'id' => 1,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(403, $result[1]);
        $this->assertStringContainsString('Module not enabled', $result[0]['error']);
    }

    // =========================================================================
    // Edge cases and security tests
    // =========================================================================

    public function testIndexWithSpecialCharactersInFilename(): void
    {
        $societe = $this->createTestSociete(['name' => 'SpecialCharsTest']);

        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        @mkdir($this->testDocDir, 0755, true);

        // Create files with special characters (that are still valid)
        file_put_contents($this->testDocDir . '/document-v2.0_final.pdf', 'content');
        file_put_contents($this->testDocDir . '/report (2024).pdf', 'content');

        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertCount(2, $result[0]['documents']);

        $filenames = array_column($result[0]['documents'], 'filename');
        $this->assertContains('document-v2.0_final.pdf', $filenames);
        $this->assertContains('report (2024).pdf', $filenames);
    }

    public function testDownloadRejectsPathWithPipeCharacter(): void
    {
        $societe = $this->createTestSociete(['name' => 'PipeCharTest']);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => 'file.txt|cat /etc/passwd',
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid path', $result[0]['error']);
    }

    public function testDownloadRejectsPathWithAngleBrackets(): void
    {
        $societe = $this->createTestSociete(['name' => 'AngleBracketTest']);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => '<script>alert(1)</script>.pdf',
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid path', $result[0]['error']);
    }

    public function testIndexWithSubdirectories(): void
    {
        $societe = $this->createTestSociete(['name' => 'SubdirTest']);

        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        $subdir = $this->testDocDir . '/invoices/2024';
        @mkdir($subdir, 0755, true);

        file_put_contents($this->testDocDir . '/main.pdf', 'main doc');
        file_put_contents($subdir . '/invoice-001.pdf', 'invoice');

        $result = $this->controller->index([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertCount(2, $result[0]['documents']);

        // Check relative paths include subdirectories
        $paths = array_column($result[0]['documents'], 'relative_path');
        $this->assertContains('main.pdf', $paths);
        $this->assertContains('invoices/2024/invoice-001.pdf', $paths);
    }

    public function testDownloadFromSubdirectory(): void
    {
        $societe = $this->createTestSociete(['name' => 'SubdirDownloadTest']);

        $this->testDocDir = DOL_DATA_ROOT . '/societe/' . dol_sanitizeFileName($societe->name);
        $subdir = $this->testDocDir . '/contracts';
        @mkdir($subdir, 0755, true);

        $content = 'Contract content here';
        file_put_contents($subdir . '/contract.txt', $content);

        $result = $this->controller->download([
            'type' => 'thirdparty',
            'id' => $societe->id,
            'user' => $this->testUser,
            'path' => 'contracts/contract.txt',
        ]);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals('contract.txt', $result[0]['filename']);
        $this->assertEquals($content, base64_decode($result[0]['content']));
    }
}
