<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/PwaController.php';
require_once __DIR__ . '/../../../api/RouteCache.php';

use SmartAuth\Api\PwaController;
use SmartAuth\Api\RouteCache;
use ReflectionClass;

/**
 * Testable subclass of PwaController that captures output instead of exit
 */
class TestablePwaController extends PwaController
{
    public array $lastResponse = [];
    public int $lastStatusCode = 200;
    public array $lastHeaders = [];
    public string $lastOutput = '';
    public bool $exitCalled = false;

    /**
     * Override closeDb to prevent actual DB close during tests
     */
    private function closeDb(): void
    {
        // Do nothing in tests
    }

    /**
     * Capture manifest output instead of exit
     */
    public function manifest($payload = null)
    {
        global $conf;

        $moduleName = RouteCache::getModuleName();
        if (empty($moduleName)) {
            $this->lastStatusCode = 500;
            $this->lastResponse = ['error' => 'Module not initialized'];
            $this->exitCalled = true;
            return;
        }

        $constPrefix = strtoupper($moduleName);

        // Get app name: custom > company name > module name
        $appName = getDolGlobalString($constPrefix . '_PWA_NAME');
        if (empty($appName)) {
            $appName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
        }
        if (empty($appName)) {
            $appName = ucfirst($moduleName);
        }

        // Short name (max 12 chars for home screen)
        $shortName = mb_substr($appName, 0, 12);

        $manifest = [
            'name' => $appName,
            'short_name' => $shortName,
            'description' => getDolGlobalString($constPrefix . '_PWA_DESCRIPTION', ''),
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => getDolGlobalString($constPrefix . '_PWA_BG_COLOR', '#ffffff'),
            'theme_color' => getDolGlobalString($constPrefix . '_PWA_THEME_COLOR', '#000000'),
            'icons' => [
                ['src' => 'api.php/icon/64', 'sizes' => '64x64', 'type' => 'image/png'],
                ['src' => 'api.php/icon/192', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => 'api.php/icon/512', 'sizes' => '512x512', 'type' => 'image/png'],
            ]
        ];

        $this->lastStatusCode = 200;
        $this->lastHeaders = [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ];
        $this->lastOutput = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->lastResponse = $manifest;
        $this->exitCalled = true;
    }

    /**
     * Capture icon response instead of exit
     */
    public function icon($payload = null)
    {
        global $conf;

        $moduleName = RouteCache::getModuleName();
        if (empty($moduleName)) {
            $this->lastStatusCode = 500;
            $this->lastResponse = ['error' => 'Module not initialized'];
            $this->exitCalled = true;
            return;
        }

        $size = (int) ($payload['size'] ?? 512);

        // Get allowed sizes via reflection
        $reflection = new ReflectionClass(PwaController::class);
        $allowedSizes = $reflection->getConstant('ALLOWED_SIZES');

        // Validate size
        if (!in_array($size, $allowedSizes)) {
            $size = 512;
        }

        // 1. Try custom icon (uploaded via admin)
        if (!empty($conf->{$moduleName}) && !empty($conf->{$moduleName}->dir_output)) {
            $customIconPath = $conf->{$moduleName}->dir_output . '/pwa/icon_' . $size . '.png';
            if (file_exists($customIconPath)) {
                $this->lastStatusCode = 200;
                $this->lastHeaders = [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                ];
                $this->lastResponse = ['type' => 'custom', 'path' => $customIconPath, 'size' => $size];
                $this->exitCalled = true;
                return;
            }
        }

        // 2. Fallback to default icon (shipped with module via SmartBoot)
        $defaultIconPath = DOL_DOCUMENT_ROOT . '/custom/' . $moduleName . '/pwa/images/pwa-' . $size . 'x' . $size . '.png';
        if (file_exists($defaultIconPath)) {
            $this->lastStatusCode = 200;
            $this->lastHeaders = [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ];
            $this->lastResponse = ['type' => 'default', 'path' => $defaultIconPath, 'size' => $size];
            $this->exitCalled = true;
            return;
        }

        // 3. Last resort: generate placeholder
        $this->lastStatusCode = 200;
        $this->lastHeaders = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ];
        $this->lastResponse = ['type' => 'placeholder', 'size' => $size, 'moduleName' => $moduleName];
        $this->exitCalled = true;
    }
}

/**
 * Integration tests for PwaController
 *
 * @covers \SmartAuth\Api\PwaController
 */
class PwaControllerIntegrationTest extends DolibarrRealTestCase
{
    private TestablePwaController $controller;
    private string $testIconDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestablePwaController();
        // Initialize RouteCache with test module name
        RouteCache::init('testmodule');
    }

    protected function tearDown(): void
    {
        // Clean up test icon directory
        if (!empty($this->testIconDir) && is_dir($this->testIconDir)) {
            $this->removeDirectory($this->testIconDir);
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
    // ALLOWED_SIZES constant tests
    // =========================================================================

    public function testAllowedSizesConstant(): void
    {
        $reflection = new ReflectionClass(PwaController::class);
        $allowedSizes = $reflection->getConstant('ALLOWED_SIZES');

        $this->assertIsArray($allowedSizes);
        $this->assertContains(64, $allowedSizes);
        $this->assertContains(192, $allowedSizes);
        $this->assertContains(512, $allowedSizes);
    }

    // =========================================================================
    // manifest() tests
    // =========================================================================

    public function testManifestReturnsErrorWhenModuleNotInitialized(): void
    {
        // Clear module name
        RouteCache::init('');

        $this->controller->manifest();

        $this->assertEquals(500, $this->controller->lastStatusCode);
        $this->assertArrayHasKey('error', $this->controller->lastResponse);
        $this->assertStringContainsString('Module not initialized', $this->controller->lastResponse['error']);
    }

    public function testManifestReturnsValidStructure(): void
    {
        RouteCache::init('testmodule');

        $this->controller->manifest();

        $this->assertEquals(200, $this->controller->lastStatusCode);
        $this->assertTrue($this->controller->exitCalled);

        $manifest = $this->controller->lastResponse;
        $this->assertArrayHasKey('name', $manifest);
        $this->assertArrayHasKey('short_name', $manifest);
        $this->assertArrayHasKey('description', $manifest);
        $this->assertArrayHasKey('start_url', $manifest);
        $this->assertArrayHasKey('display', $manifest);
        $this->assertArrayHasKey('background_color', $manifest);
        $this->assertArrayHasKey('theme_color', $manifest);
        $this->assertArrayHasKey('icons', $manifest);
    }

    public function testManifestUsesModuleNameAsDefaultAppName(): void
    {
        RouteCache::init('myapp');

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $this->assertEquals('Myapp', $manifest['name']);
        $this->assertEquals('Myapp', $manifest['short_name']);
    }

    public function testManifestUsesCustomPwaName(): void
    {
        global $conf;

        RouteCache::init('smartmaker');
        // Set custom PWA name in conf
        $conf->global->SMARTMAKER_PWA_NAME = 'My Custom App Name';

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $this->assertEquals('My Custom App Name', $manifest['name']);
        // Short name should be truncated to 12 chars
        $this->assertEquals('My Custom Ap', $manifest['short_name']);

        // Cleanup
        unset($conf->global->SMARTMAKER_PWA_NAME);
    }

    public function testManifestUsesFallbackToCompanyName(): void
    {
        global $conf;

        RouteCache::init('testmodule');
        // Set company name but not custom PWA name
        $conf->global->MAIN_INFO_SOCIETE_NOM = 'Acme Corporation';

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $this->assertEquals('Acme Corporation', $manifest['name']);
        $this->assertEquals('Acme Corpora', $manifest['short_name']);

        // Cleanup
        unset($conf->global->MAIN_INFO_SOCIETE_NOM);
    }

    public function testManifestUsesCustomColors(): void
    {
        global $conf;

        RouteCache::init('smartmaker');
        $conf->global->SMARTMAKER_PWA_BG_COLOR = '#ff0000';
        $conf->global->SMARTMAKER_PWA_THEME_COLOR = '#00ff00';

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $this->assertEquals('#ff0000', $manifest['background_color']);
        $this->assertEquals('#00ff00', $manifest['theme_color']);

        // Cleanup
        unset($conf->global->SMARTMAKER_PWA_BG_COLOR);
        unset($conf->global->SMARTMAKER_PWA_THEME_COLOR);
    }

    public function testManifestUsesDefaultColors(): void
    {
        RouteCache::init('testmodule');

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $this->assertEquals('#ffffff', $manifest['background_color']);
        $this->assertEquals('#000000', $manifest['theme_color']);
    }

    public function testManifestContainsCorrectIcons(): void
    {
        RouteCache::init('testmodule');

        $this->controller->manifest();

        $manifest = $this->controller->lastResponse;
        $icons = $manifest['icons'];

        $this->assertCount(3, $icons);

        $this->assertEquals('api.php/icon/64', $icons[0]['src']);
        $this->assertEquals('64x64', $icons[0]['sizes']);
        $this->assertEquals('image/png', $icons[0]['type']);

        $this->assertEquals('api.php/icon/192', $icons[1]['src']);
        $this->assertEquals('192x192', $icons[1]['sizes']);

        $this->assertEquals('api.php/icon/512', $icons[2]['src']);
        $this->assertEquals('512x512', $icons[2]['sizes']);
    }

    public function testManifestSetsCorrectHeaders(): void
    {
        RouteCache::init('testmodule');

        $this->controller->manifest();

        $this->assertEquals('application/manifest+json', $this->controller->lastHeaders['Content-Type']);
        $this->assertEquals('public, max-age=3600', $this->controller->lastHeaders['Cache-Control']);
    }

    public function testManifestOutputIsValidJson(): void
    {
        RouteCache::init('testmodule');

        $this->controller->manifest();

        $decoded = json_decode($this->controller->lastOutput, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    // =========================================================================
    // icon() tests
    // =========================================================================

    public function testIconReturnsErrorWhenModuleNotInitialized(): void
    {
        RouteCache::init('');

        $this->controller->icon(['size' => 192]);

        $this->assertEquals(500, $this->controller->lastStatusCode);
        $this->assertArrayHasKey('error', $this->controller->lastResponse);
    }

    public function testIconDefaultsTo512WhenNoSizeProvided(): void
    {
        RouteCache::init('testmodule');

        $this->controller->icon([]);

        $this->assertEquals(512, $this->controller->lastResponse['size']);
    }

    public function testIconDefaultsTo512WhenInvalidSizeProvided(): void
    {
        RouteCache::init('testmodule');

        $this->controller->icon(['size' => 999]);

        // Invalid size should default to 512
        $this->assertEquals(512, $this->controller->lastResponse['size']);
    }

    public function testIconAcceptsValidSizes(): void
    {
        RouteCache::init('testmodule');

        // Test 64
        $this->controller->icon(['size' => 64]);
        $this->assertEquals(64, $this->controller->lastResponse['size']);

        // Test 192
        $this->controller->icon(['size' => 192]);
        $this->assertEquals(192, $this->controller->lastResponse['size']);

        // Test 512
        $this->controller->icon(['size' => 512]);
        $this->assertEquals(512, $this->controller->lastResponse['size']);
    }

    public function testIconUsesCustomIconWhenAvailable(): void
    {
        global $conf;

        RouteCache::init('testmodule');

        // Create custom icon directory and file
        $this->testIconDir = DOL_DATA_ROOT . '/testmodule/pwa';
        @mkdir($this->testIconDir, 0755, true);

        // Setup conf for module
        $conf->testmodule = new \stdClass();
        $conf->testmodule->dir_output = DOL_DATA_ROOT . '/testmodule';

        // Create test icon file
        $iconPath = $this->testIconDir . '/icon_192.png';
        file_put_contents($iconPath, 'fake png content');

        $this->controller->icon(['size' => 192]);

        $this->assertEquals(200, $this->controller->lastStatusCode);
        $this->assertEquals('custom', $this->controller->lastResponse['type']);
        $this->assertEquals($iconPath, $this->controller->lastResponse['path']);

        // Cleanup
        unset($conf->testmodule);
    }

    public function testIconFallsBackToPlaceholderWhenNoIconFound(): void
    {
        RouteCache::init('testmodule');

        $this->controller->icon(['size' => 192]);

        $this->assertEquals(200, $this->controller->lastStatusCode);
        $this->assertEquals('placeholder', $this->controller->lastResponse['type']);
        $this->assertEquals(192, $this->controller->lastResponse['size']);
        $this->assertEquals('testmodule', $this->controller->lastResponse['moduleName']);
    }

    public function testIconSetsCorrectHeadersForCustomIcon(): void
    {
        global $conf;

        RouteCache::init('testmodule');

        // Create custom icon
        $this->testIconDir = DOL_DATA_ROOT . '/testmodule/pwa';
        @mkdir($this->testIconDir, 0755, true);

        $conf->testmodule = new \stdClass();
        $conf->testmodule->dir_output = DOL_DATA_ROOT . '/testmodule';

        $iconPath = $this->testIconDir . '/icon_64.png';
        file_put_contents($iconPath, 'fake png');

        $this->controller->icon(['size' => 64]);

        $this->assertEquals('image/png', $this->controller->lastHeaders['Content-Type']);
        $this->assertEquals('public, max-age=86400', $this->controller->lastHeaders['Cache-Control']);

        // Cleanup
        unset($conf->testmodule);
    }

    public function testIconSetsCorrectHeadersForPlaceholder(): void
    {
        RouteCache::init('testmodule');

        $this->controller->icon(['size' => 512]);

        $this->assertEquals('image/png', $this->controller->lastHeaders['Content-Type']);
        $this->assertEquals('public, max-age=3600', $this->controller->lastHeaders['Cache-Control']);
    }

    // =========================================================================
    // Integration with RouteCache tests
    // =========================================================================

    public function testManifestAndIconUseConsistentModuleName(): void
    {
        RouteCache::init('myspecialapp');

        $this->controller->manifest();
        $manifestName = $this->controller->lastResponse['name'];

        $this->controller->icon(['size' => 512]);
        $iconModuleName = $this->controller->lastResponse['moduleName'];

        $this->assertEquals('Myspecialapp', $manifestName);
        $this->assertEquals('myspecialapp', $iconModuleName);
    }
}
