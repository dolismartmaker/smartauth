<?php

/**
 * Tests for ThirdpartyMediaController + dmThirdparty derived fields.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/ThirdpartyMediaController.php';
require_once __DIR__ . '/../../../dolMapping/dmBase.php';
require_once __DIR__ . '/../../../dolMapping/dmHelper.php';
require_once __DIR__ . '/../../../dolMapping/dmTrait.php';
require_once __DIR__ . '/../../../dolMapping/dmThirdparty.php';

// Needed at file-load time for the SmartAuthTestCaptureLogHandler class
// declared at the bottom of this file (it extends Dolibarr's LogHandler
// and implements LogHandlerInterface, both global-namespace symbols).
// The bootstrap has already defined DOL_DOCUMENT_ROOT by the time PHPUnit
// loads this test file.
require_once DOL_DOCUMENT_ROOT . '/core/modules/syslog/logHandler.php';

use SmartAuth\Api\ThirdpartyMediaController;
use SmartAuth\DolibarrMapping\dmThirdparty;
use Societe;

/**
 * @covers \SmartAuth\Api\ThirdpartyMediaController
 * @covers \SmartAuth\DolibarrMapping\dmThirdparty
 */
class ThirdpartyMediaTest extends DolibarrRealTestCase
{
	/** @var ThirdpartyMediaController */
	private $controller;

	/** @var string Base data directory for logos (per-test scratch). */
	private $scratchBaseDir;

	/** @var array Tracked files/dirs to clean up after each test. */
	private $cleanupPaths = [];

	/** @var string|null Saved value of HTTP_IF_NONE_MATCH header. */
	private $savedIfNoneMatch;

	/** @var mixed Saved value of $conf->societe->multidir_output. */
	private $savedMultidirOutput;

	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->controller = new ThirdpartyMediaController();

		// Save header state so we can restore it in tearDown.
		$this->savedIfNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
		unset($_SERVER['HTTP_IF_NONE_MATCH']);

		// Enable the societe module in the test conf so hasRight('societe', 'lire')
		// passes its isModEnabled() pre-check inside Dolibarr's User::hasRight().
		if (!isset($conf->modules)) {
			$conf->modules = [];
		}
		$conf->modules['societe'] = 1;

		if (!isset($conf->societe) || !is_object($conf->societe)) {
			$conf->societe = new \stdClass();
		}
		$conf->societe->enabled = 1;

		// Prepare a unique scratch dir under DOL_DATA_ROOT/societe so realpath()
		// path-traversal defence in the controller resolves correctly.
		$dataBase = rtrim(DOL_DATA_ROOT, '/') . '/societe';
		if (!is_dir($dataBase)) {
			@mkdir($dataBase, 0755, true);
		}
		$this->scratchBaseDir = $dataBase;

		// Configure multidir_output for entity 1 AND entity 0 (shared) so
		// the controller can resolve logo paths in both contexts. Save the
		// previous value to restore it in tearDown.
		$this->savedMultidirOutput = $conf->societe->multidir_output ?? null;
		$conf->societe->multidir_output = [0 => $dataBase, 1 => $dataBase];

		// Grant testUser societe->lire so the default user passes authorization.
		if (!isset($this->testUser->rights) || !is_object($this->testUser->rights)) {
			$this->testUser->rights = new \stdClass();
		}
		$this->testUser->rights->societe = new \stdClass();
		$this->testUser->rights->societe->lire = 1;
		$this->testUser->rights->societe->read = 1;
	}

	protected function tearDown(): void
	{
		global $conf;

		// Remove any scratch files / dirs we created.
		foreach (array_reverse($this->cleanupPaths) as $p) {
			if (is_file($p) || is_link($p)) {
				@unlink($p);
			} elseif (is_dir($p)) {
				@rmdir($p);
			}
		}
		$this->cleanupPaths = [];

		// Restore HTTP_IF_NONE_MATCH state.
		if ($this->savedIfNoneMatch === null) {
			unset($_SERVER['HTTP_IF_NONE_MATCH']);
		} else {
			$_SERVER['HTTP_IF_NONE_MATCH'] = $this->savedIfNoneMatch;
		}

		// Restore multidir_output.
		if ($this->savedMultidirOutput === null) {
			unset($conf->societe->multidir_output);
		} else {
			$conf->societe->multidir_output = $this->savedMultidirOutput;
		}

		parent::tearDown();
	}

	/**
	 * Create real PNG/JPG logo files on disk for a given Societe, mirroring
	 * Dolibarr's layout: <multidir_output>/<id>/logos/<logo> and
	 * <multidir_output>/<id>/logos/thumbs/<mini>.
	 *
	 * @param Societe $soc     Test thirdparty (must have an id).
	 * @param string  $ext     png|jpg|jpeg|gif|webp|bmp
	 * @param bool    $withMini  also generate the mini variant under thumbs/
	 * @param int     $widthFull width of the full image (used to vary file size)
	 * @param int     $widthMini width of the mini image
	 * @return array  ['fullpath' => ..., 'minipath' => ..., 'filename' => ...]
	 */
	private function createLogoOnDisk(Societe $soc, string $ext = 'png', bool $withMini = false, int $widthFull = 10, int $widthMini = 4): array
	{
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD extension required to generate test images.');
		}

		$base = $this->scratchBaseDir;
		$socDir = $base . '/' . (int) $soc->id;
		$logosDir = $socDir . '/logos';
		$thumbsDir = $logosDir . '/thumbs';

		foreach ([$socDir, $logosDir, $thumbsDir] as $d) {
			if (!is_dir($d)) {
				mkdir($d, 0755, true);
				$this->cleanupPaths[] = $d;
			}
		}

		$filename = 'logo_' . uniqid() . '.' . $ext;
		$fullpath = $logosDir . '/' . $filename;

		$this->writeImage($fullpath, $ext, $widthFull);
		$this->cleanupPaths[] = $fullpath;

		$minipath = null;
		if ($withMini) {
			// Mirror the controller's mini naming logic.
			$miniName = str_replace(
				['.jpg', '.jpeg', '.png'],
				['_mini.jpg', '_mini.jpg', '_mini.png'],
				$filename
			);
			$minipath = $thumbsDir . '/' . $miniName;
			$this->writeImage($minipath, $ext, $widthMini);
			$this->cleanupPaths[] = $minipath;
		}

		// Persist the logo filename on the thirdparty using a direct SQL UPDATE
		// (avoid Societe::update which has many side effects).
		$sql = "UPDATE " . MAIN_DB_PREFIX . "societe SET logo = '"
			. $this->db->escape($filename) . "' WHERE rowid = " . (int) $soc->id;
		$this->db->query($sql);
		$soc->logo = $filename;

		return ['fullpath' => $fullpath, 'minipath' => $minipath, 'filename' => $filename];
	}

	/**
	 * Write a minimal image file at the given path using GD. For .bmp (used
	 * by the unsupported-extension test) we just write raw bytes since GD's
	 * bmp support varies across builds and we don't need a valid BMP.
	 */
	private function writeImage(string $path, string $ext, int $width = 10): void
	{
		if ($ext === 'bmp') {
			file_put_contents($path, str_repeat("\x00", 64));
			return;
		}
		$img = imagecreatetruecolor($width, $width);
		$blue = imagecolorallocate($img, 30, 60, 200);
		imagefill($img, 0, 0, $blue);
		switch (strtolower($ext)) {
			case 'jpg':
			case 'jpeg':
				imagejpeg($img, $path, 85);
				break;
			case 'gif':
				imagegif($img, $path);
				break;
			case 'webp':
				if (!function_exists('imagewebp')) {
					$this->markTestSkipped('GD imagewebp not available.');
				}
				imagewebp($img, $path);
				break;
			case 'png':
			default:
				imagepng($img, $path);
				break;
		}
		// Note: imagedestroy() is a no-op since PHP 8.0 (GD images are
		// now objects, freed by the garbage collector). Skipping the call
		// keeps the IDE happy on PHP 8.2+.
		unset($img);
	}

	// ====================================================================
	// _streamLogo() error paths
	// ====================================================================

	public function testStreamLogoReturns400WhenIdIsMissing(): void
	{
		// id missing entirely.
		$r1 = $this->controller->_streamLogo([
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertArrayHasKey('error', $r1);
		$this->assertEquals(400, $r1['status']);

		// id explicitly 0.
		$r2 = $this->controller->_streamLogo([
			'id'     => 0,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertEquals(400, $r2['status']);

		// Negative id.
		$r3 = $this->controller->_streamLogo([
			'id'     => -42,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertEquals(400, $r3['status']);
	}

	public function testStreamLogoReturns401WhenNoAuthenticatedUser(): void
	{
		// No 'user' key in payload.
		$r1 = $this->controller->_streamLogo([
			'id'     => 1,
			'entity' => 1,
		], 'full');
		$this->assertArrayHasKey('error', $r1);
		$this->assertEquals(401, $r1['status']);

		// 'user' is not an object (string instead).
		$r2 = $this->controller->_streamLogo([
			'id'     => 1,
			'user'   => 'not-an-object',
			'entity' => 1,
		], 'full');
		$this->assertEquals(401, $r2['status']);
	}

	public function testStreamLogoReturns403WhenUserLacksSocieteLire(): void
	{
		// Build a user that has no rights->societe->lire set.
		$unprivileged = $this->createTestUser([
			'login'    => 'noright_' . uniqid(),
			'lastname' => 'NoRight',
		]);
		// Ensure the rights object exists but the lire flag is empty.
		$unprivileged->rights = new \stdClass();
		$unprivileged->rights->societe = new \stdClass();
		$unprivileged->rights->societe->lire = 0;

		$result = $this->controller->_streamLogo([
			'id'     => 1,
			'user'   => $unprivileged,
			'entity' => 1,
		], 'full');

		$this->assertEquals(403, $result['status']);
		$this->assertStringContainsString('Forbidden', $result['error']);
	}

	public function testStreamLogoReturns404WhenThirdpartyDoesNotExist(): void
	{
		$result = $this->controller->_streamLogo([
			'id'     => 999999,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(404, $result['status']);
		$this->assertStringContainsString('Not Found', $result['error']);
	}

	public function testStreamLogoReturns404WhenThirdpartyHasNoLogo(): void
	{
		$soc = $this->createTestSociete();
		// Force logo to NULL just to be explicit.
		$sql = "UPDATE " . MAIN_DB_PREFIX . "societe SET logo = NULL WHERE rowid = " . (int) $soc->id;
		$this->db->query($sql);

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(404, $result['status']);
		$this->assertEquals('No logo configured', $result['error']);
	}

	public function testStreamLogoReturns403OnEntityMismatch(): void
	{
		// Create a societe in entity 2.
		$soc = $this->createTestSociete(['entity' => 2]);
		$this->createLogoOnDisk($soc, 'png');

		// Request from entity 1 must be refused with 403. The controller
		// uses a direct SELECT (not Societe::fetch()) precisely so this
		// branch is reachable: fetch() would entity-filter the row away
		// and we would see 404 instead, masking the cross-entity attempt.
		$resMismatch = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertEquals(403, $resMismatch['status']);
		$this->assertArrayHasKey('error', $resMismatch);

		// Thirdparty with entity=0 (shared) is always accessible, even
		// from another requesting entity. The controller's explicit
		// "$socEntity !== 0" guard short-circuits the entity check.
		$shared = $this->createTestSociete(['entity' => 0]);
		$this->createLogoOnDisk($shared, 'png');

		$resShared = $this->controller->_streamLogo([
			'id'     => $shared->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertEquals(200, $resShared['status']);
	}

	// ====================================================================
	// _streamLogo() success paths
	// ====================================================================

	public function testStreamLogoReturnsTuple200WithPngLogo(): void
	{
		$soc = $this->createTestSociete();
		$logo = $this->createLogoOnDisk($soc, 'png');

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(200, $result['status'], 'Expected 200, got: ' . json_encode($result));
		$this->assertArrayHasKey('headers', $result);
		$this->assertArrayHasKey('body_filepath', $result);

		$h = $result['headers'];
		$this->assertEquals('image/png', $h['Content-Type']);
		$this->assertEquals((string) filesize($logo['fullpath']), $h['Content-Length']);
		$this->assertStringStartsWith('"', $h['ETag']);
		$this->assertStringEndsWith('"', $h['ETag']);
		$this->assertEquals(
			'private, max-age=86400, stale-while-revalidate=2592000',
			$h['Cache-Control']
		);

		$this->assertFileExists($result['body_filepath']);
		$this->assertEquals(
			file_get_contents($logo['fullpath']),
			file_get_contents($result['body_filepath'])
		);
	}

	public function testStreamLogoReturnsTupleJpegMimeForJpgLogo(): void
	{
		$soc = $this->createTestSociete();
		$logo = $this->createLogoOnDisk($soc, 'jpg');

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(200, $result['status']);
		$this->assertEquals('image/jpeg', $result['headers']['Content-Type']);
		$this->assertEquals(
			file_get_contents($logo['fullpath']),
			file_get_contents($result['body_filepath'])
		);
	}

	public function testStreamLogoMiniReturnsMiniFile(): void
	{
		$soc = $this->createTestSociete();
		// Big full + small mini so the two files have different sizes and
		// we can prove the right one was returned.
		$logo = $this->createLogoOnDisk($soc, 'png', true, 32, 8);

		$this->assertNotNull($logo['minipath']);
		$this->assertNotEquals(
			filesize($logo['fullpath']),
			filesize($logo['minipath']),
			'Test fixture must produce full and mini of different sizes.'
		);

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'mini');

		$this->assertEquals(200, $result['status']);
		$this->assertEquals(
			filesize($logo['minipath']),
			(int) $result['headers']['Content-Length'],
			'Mini variant must report the mini file size, not the full one.'
		);
		$this->assertEquals(
			file_get_contents($logo['minipath']),
			file_get_contents($result['body_filepath'])
		);
	}

	public function testStreamLogoRefusesPathTraversal(): void
	{
		$soc = $this->createTestSociete();
		// Inject a poisoned logo filename directly in DB to bypass any
		// Dolibarr-level sanitisation that Societe::update() might apply.
		$poison = '../../../etc/passwd';
		$sql = "UPDATE " . MAIN_DB_PREFIX . "societe SET logo = '"
			. $this->db->escape($poison) . "' WHERE rowid = " . (int) $soc->id;
		$this->db->query($sql);

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		// Two valid outcomes: 403 if the file resolves outside the base,
		// or 404 if the resolved path doesn't exist (also safe). The
		// important invariant is: we never get a 200.
		$this->assertNotEquals(200, $result['status'], 'Path traversal MUST never serve a 200.');
		$this->assertContains($result['status'], [403, 404]);
	}

	public function testStreamLogoRefusesUnsupportedExtension(): void
	{
		$soc = $this->createTestSociete();
		$this->createLogoOnDisk($soc, 'bmp');

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(415, $result['status']);
		$this->assertEquals('Unsupported logo format', $result['error']);
	}

	public function testStreamLogoSetsCacheControlLong(): void
	{
		$soc = $this->createTestSociete();
		$this->createLogoOnDisk($soc, 'png');

		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(200, $result['status']);
		$cc = $result['headers']['Cache-Control'];
		$this->assertStringContainsString('private', $cc);
		$this->assertStringContainsString('max-age=86400', $cc);
		$this->assertStringContainsString('stale-while-revalidate=2592000', $cc);
		// Must NOT advertise itself as cacheable by intermediaries.
		$this->assertStringNotContainsString('public', $cc);
	}

	public function testStreamLogoReturns304WhenIfNoneMatchMatches(): void
	{
		$soc = $this->createTestSociete();
		$this->createLogoOnDisk($soc, 'png');

		// First request: capture ETag.
		$first = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');
		$this->assertEquals(200, $first['status']);
		$etag = $first['headers']['ETag'];
		$this->assertNotEmpty($etag);

		// Second request with matching If-None-Match.
		$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
		$second = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(304, $second['status']);
		$this->assertArrayNotHasKey('body_filepath', $second);
		$this->assertArrayHasKey('headers', $second);
		$this->assertEquals($etag, $second['headers']['ETag']);
		$this->assertStringContainsString('max-age=86400', $second['headers']['Cache-Control']);
	}

	public function testStreamLogoReturns200WhenIfNoneMatchStale(): void
	{
		$soc = $this->createTestSociete();
		$this->createLogoOnDisk($soc, 'png');

		$_SERVER['HTTP_IF_NONE_MATCH'] = '"stale-etag-deadbeef"';
		$result = $this->controller->_streamLogo([
			'id'     => $soc->id,
			'user'   => $this->testUser,
			'entity' => 1,
		], 'full');

		$this->assertEquals(200, $result['status']);
		$this->assertArrayHasKey('body_filepath', $result);
		$this->assertNotEquals('"stale-etag-deadbeef"', $result['headers']['ETag']);
	}

	// ====================================================================
	// dmThirdparty derived fields
	// ====================================================================

	public function testDmThirdpartyLogoReturnsUrlNotBase64(): void
	{
		$mapper = new dmThirdparty();

		$soc = new \stdClass();
		$soc->id = 42;
		$soc->entity = 1;
		$soc->logo = 'whatever.png';

		$value = $mapper->fieldFilterValueLogo($soc);

		$this->assertIsString($value);
		$this->assertEquals('media/thirdparty/42/logo', $value);
		$this->assertStringNotContainsString('data:image', $value);
		$this->assertStringNotContainsString('base64', $value);
	}

	public function testDmThirdpartyLogoMiniReturnsUrl(): void
	{
		$mapper = new dmThirdparty();

		$soc = new \stdClass();
		$soc->id = 77;
		$soc->entity = 1;
		$soc->logo = 'something.jpg';

		$value = $mapper->fieldFilterValueLogoMini($soc);

		$this->assertEquals('media/thirdparty/77/logo/mini', $value);
	}

	public function testDmThirdpartyLogoIsNullWhenNoLogo(): void
	{
		$mapper = new dmThirdparty();

		$soc = new \stdClass();
		$soc->id = 1;
		$soc->entity = 1;
		$soc->logo = '';

		$this->assertNull($mapper->fieldFilterValueLogo($soc));
		$this->assertNull($mapper->fieldFilterValueLogoMini($soc));
		$this->assertNull($mapper->fieldFilterValueLogoDataUrl($soc));
	}

	public function testDmThirdpartyLogoDataUrlReturnsBase64Legacy(): void
	{
		global $conf;

		$soc = $this->createTestSociete();
		// Need the mini file present on disk -- the legacy code reads the
		// thumb variant (not the full one).
		$logo = $this->createLogoOnDisk($soc, 'png', true);

		$mapper = new dmThirdparty();

		// Re-fetch as a plain stdClass-shaped object that has logo/id/entity
		// (the mapper only uses these three properties for the legacy path).
		$obj = new \stdClass();
		$obj->id = $soc->id;
		$obj->entity = 1;
		$obj->logo = $logo['filename'];

		// --------------------------------------------------------------
		// Wire up an in-memory LogHandler so we can assert that the
		// deprecation warning is actually emitted via dol_syslog().
		//
		// dol_syslog() short-circuits on three conditions we must lift:
		//   1. isModEnabled('syslog') must be true
		//      -> set $conf->modules['syslog'] = 1
		//   2. SYSLOG_LEVEL global must be >= LOG_WARNING
		//      -> set $conf->global->SYSLOG_LEVEL = LOG_DEBUG (7)
		//   3. $conf->loghandlers must contain at least one handler
		//      -> push an instance of SmartAuthTestCaptureLogHandler
		//
		// Everything is restored in the finally block to keep this test
		// hermetic and avoid leaking state into sibling tests.
		// LogHandler/LogHandlerInterface are loaded once at the top of
		// this file (see the require_once near the use statements).
		// --------------------------------------------------------------
		$savedSyslogModule = $conf->modules['syslog'] ?? null;
		$savedSyslogLevel  = $conf->global->SYSLOG_LEVEL ?? null;
		$savedLogHandlers  = isset($conf->loghandlers) ? $conf->loghandlers : null;

		$conf->modules['syslog']    = 1;
		$conf->global->SYSLOG_LEVEL = LOG_DEBUG;

		$captureHandler     = new SmartAuthTestCaptureLogHandler();
		$conf->loghandlers  = array($captureHandler);

		try {
			$value = $mapper->fieldFilterValueLogoDataUrl($obj);

			$this->assertIsString($value);
			$this->assertStringStartsWith('data:image/png;base64,', $value);
			// Strip the prefix and verify the remainder is valid base64.
			$b64 = substr($value, strlen('data:image/png;base64,'));
			$decoded = base64_decode($b64, true);
			$this->assertNotFalse($decoded, 'Suffix after "data:image/png;base64," must be valid base64.');
			$this->assertNotEmpty($decoded);

			// Now the deprecation-warning sub-assertion: exactly one
			// dol_syslog() call at LOG_WARNING level mentioning the
			// deprecated logo_data_url field for our thirdparty id.
			$warningMessages = array();
			foreach ($captureHandler->messages as $entry) {
				if ((int) $entry['level'] === LOG_WARNING) {
					$warningMessages[] = $entry['message'];
				}
			}
			$this->assertNotEmpty(
				$warningMessages,
				'fieldFilterValueLogoDataUrl() must emit at least one LOG_WARNING via dol_syslog().'
			);

			$found = false;
			foreach ($warningMessages as $msg) {
				if (
					strpos($msg, 'fieldFilterValueLogoDataUrl') !== false
					&& strpos($msg, 'deprecated') !== false
					&& strpos($msg, 'thirdparty ' . (int) $obj->id) !== false
					&& strpos($msg, '2.2.0') !== false
				) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				'Expected a LOG_WARNING mentioning fieldFilterValueLogoDataUrl, '
				. '"deprecated", "thirdparty ' . (int) $obj->id . '" and the '
				. '"2.2.0" removal milestone. Got: '
				. var_export($warningMessages, true)
			);
		} finally {
			// Restore $conf state regardless of test outcome so other
			// tests are not contaminated by the syslog wiring above.
			if ($savedSyslogModule === null) {
				unset($conf->modules['syslog']);
			} else {
				$conf->modules['syslog'] = $savedSyslogModule;
			}
			if ($savedSyslogLevel === null) {
				unset($conf->global->SYSLOG_LEVEL);
			} else {
				$conf->global->SYSLOG_LEVEL = $savedSyslogLevel;
			}
			if ($savedLogHandlers === null) {
				unset($conf->loghandlers);
			} else {
				$conf->loghandlers = $savedLogHandlers;
			}
		}
	}

	public function testDmThirdpartyDerivedFieldsExportedViaMapper(): void
	{
		// Use a real fetched Societe + the real mapper so this exercises
		// dmTrait::exportMappedData() end-to-end including the new
		// $listOfDerivedFields loop.
		$soc = $this->createTestSociete();
		$logo = $this->createLogoOnDisk($soc, 'png', true);

		// Reload through Dolibarr's Societe class to get the live object
		// with the persisted logo column.
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		$fresh = new Societe($this->db);
		$fresh->fetch($soc->id);
		$this->assertEquals($logo['filename'], $fresh->logo);

		$mapper = new dmThirdparty();
		$exported = $mapper->exportMappedData($fresh);

		$this->assertIsObject($exported);
		// URL-form fields.
		$this->assertObjectHasProperty('logo', $exported);
		$this->assertEquals('media/thirdparty/' . (int) $soc->id . '/logo', $exported->logo);

		$this->assertObjectHasProperty('logo_mini', $exported);
		$this->assertEquals('media/thirdparty/' . (int) $soc->id . '/logo/mini', $exported->logo_mini);

		// Legacy base64.
		$this->assertObjectHasProperty('logo_data_url', $exported);
		$this->assertIsString($exported->logo_data_url);
		$this->assertStringStartsWith('data:image/png;base64,', $exported->logo_data_url);
	}
}

/**
 * In-memory LogHandler used by testDmThirdpartyLogoDataUrlReturnsBase64Legacy()
 * to capture dol_syslog() output and assert on the deprecation warning.
 *
 * Extends Dolibarr's LogHandler base class (loaded on demand in the test
 * via require_once 'core/modules/syslog/logHandler.php') and implements
 * the LogHandlerInterface contract. Both live in the global namespace,
 * hence the leading backslashes.
 *
 * dol_syslog() calls $handler->export($data, $suffixinfilename) where
 * $data is an associative array (keys: message, script, level, user, ip).
 * The interface declares export($content) with a single argument; we
 * accept the optional second one to match the actual call site without
 * triggering a strict-signature mismatch.
 */
class SmartAuthTestCaptureLogHandler extends \LogHandler implements \LogHandlerInterface
{
	/** @var string Code referenced by dol_syslog()'s restricttologhandler filter. */
	public $code = 'smartauth_test_capture';

	/** @var array<int,array{message:string,level:int,script:mixed,user:mixed,ip:mixed}> */
	public $messages = array();

	public function getName()
	{
		return 'SmartAuthTestCapture';
	}

	public function getVersion()
	{
		return 'test';
	}

	public function isActive()
	{
		return true;
	}

	public function export($content, $suffixinfilename = '')
	{
		// dol_syslog() always passes an array; keep a defensive fallback.
		if (is_array($content)) {
			$this->messages[] = array(
				'message' => isset($content['message']) ? (string) $content['message'] : '',
				'level'   => isset($content['level'])   ? (int) $content['level']      : 0,
				'script'  => $content['script'] ?? null,
				'user'    => $content['user']   ?? null,
				'ip'      => $content['ip']     ?? null,
			);
		} else {
			$this->messages[] = array(
				'message' => (string) $content,
				'level'   => 0,
				'script'  => null,
				'user'    => null,
				'ip'      => null,
			);
		}
	}
}
