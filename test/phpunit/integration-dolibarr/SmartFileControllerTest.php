<?php

/**
 * Tests for SmartFileController
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SmartFileController.php';

use SmartAuth\Api\SmartFileController;
use EcmFiles;

/**
 * @covers \SmartAuth\Api\SmartFileController
 */
class SmartFileControllerTest extends DolibarrRealTestCase
{
    private SmartFileController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new SmartFileController();
    }

    /**
     * Test SmartFileController instantiation
     */
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(SmartFileController::class, $this->controller);
    }

    /**
     * Test download returns 400 when hash is missing
     */
    public function testDownloadReturnsBadRequestWhenHashMissing(): void
    {
        $result = $this->controller->download([
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('hash', strtolower($result[0]['error']));
    }

    /**
     * Test download returns 400 when hash is empty
     */
    public function testDownloadReturnsBadRequestWhenHashEmpty(): void
    {
        $result = $this->controller->download([
            'hash' => '',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test download returns 400 when hash is too short
     */
    public function testDownloadReturnsBadRequestWhenHashTooShort(): void
    {
        $result = $this->controller->download([
            'hash' => 'abc',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid', $result[0]['error']);
    }

    /**
     * Test download returns 401 when user is missing
     */
    public function testDownloadReturnsUnauthorizedWhenUserMissing(): void
    {
        $result = $this->controller->download([
            'hash' => 'abcdefgh12345678',
            'entity' => 1
        ]);

        $this->assertEquals(401, $result[1]);
        $this->assertStringContainsString('Authentication', $result[0]['error']);
    }

    /**
     * Test download returns 401 when user is null
     */
    public function testDownloadReturnsUnauthorizedWhenUserNull(): void
    {
        $result = $this->controller->download([
            'hash' => 'abcdefgh12345678',
            'user' => null,
            'entity' => 1
        ]);

        $this->assertEquals(401, $result[1]);
    }

    /**
     * Test download returns 404 when file not found by hash
     */
    public function testDownloadReturnsNotFoundWhenHashNotInDatabase(): void
    {
        $result = $this->controller->download([
            'hash' => 'nonexistenthash12345678',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertEquals(404, $result[1]);
        $this->assertStringContainsString('not found', strtolower($result[0]['error']));
    }

    /**
     * Test download sanitizes hash (removes special characters)
     */
    public function testDownloadSanitizesHash(): void
    {
        // Hash with special characters should be sanitized
        $result = $this->controller->download([
            'hash' => 'abc<script>def',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        // After sanitization, hash becomes "abcscriptdef" which is valid length
        // but won't be found in DB
        $this->assertEquals(404, $result[1]);
    }

    /**
     * Test download with null payload returns 400
     */
    public function testDownloadWithNullPayloadReturnsBadRequest(): void
    {
        $result = $this->controller->download(null);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
    }

    /**
     * Test download returns 403 when entity mismatch
     */
    public function testDownloadReturnsAccessDeniedWhenEntityMismatch(): void
    {
        // Create an ECM file entry with entity 99
        $ecmfile = $this->createTestEcmFile([
            'entity' => 99,
            'share' => 'testshare123456789'
        ]);

        $result = $this->controller->download([
            'hash' => 'testshare123456789',
            'user' => $this->testUser,
            'entity' => 1 // Different entity
        ]);

        $this->assertEquals(403, $result[1]);

        // Cleanup
        $ecmfile->delete($this->testUser);
    }

    // ========== downloadBinary tests ========== //

    /**
     * Test downloadBinary returns 400 when hash is missing
     */
    public function testDownloadBinaryReturnsBadRequestWhenHashMissing(): void
    {
        $result = $this->controller->downloadBinary([
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(400, $result[1]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    /**
     * Test downloadBinary returns 401 when user is missing
     */
    public function testDownloadBinaryReturnsUnauthorizedWhenUserMissing(): void
    {
        $result = $this->controller->downloadBinary([
            'hash' => 'abcdefgh12345678',
            'entity' => 1
        ]);

        $this->assertEquals(401, $result[1]);
    }

    /**
     * Test downloadBinary returns 404 when file not found
     */
    public function testDownloadBinaryReturnsNotFoundWhenHashNotInDatabase(): void
    {
        $result = $this->controller->downloadBinary([
            'hash' => 'nonexistenthash12345678',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertEquals(404, $result[1]);
    }

    /**
     * Test downloadBinary returns 403 when entity mismatch
     */
    public function testDownloadBinaryReturnsAccessDeniedWhenEntityMismatch(): void
    {
        $ecmfile = $this->createTestEcmFile([
            'entity' => 99,
            'share' => 'binarytestshare123456'
        ]);

        $result = $this->controller->downloadBinary([
            'hash' => 'binarytestshare123456',
            'user' => $this->testUser,
            'entity' => 1
        ]);

        $this->assertEquals(403, $result[1]);

        $ecmfile->delete($this->testUser);
    }

    // ========== Helper methods ========== //

    /**
     * Helper to create a test ECM file entry
     */
    protected function createTestEcmFile(array $data = []): EcmFiles
    {
        require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

        $ecmfile = new EcmFiles($this->db);
        $ecmfile->ref = $data['ref'] ?? 'TEST' . uniqid();
        $ecmfile->label = $data['label'] ?? md5(uniqid());
        $ecmfile->share = $data['share'] ?? null;
        $ecmfile->entity = $data['entity'] ?? 1;
        $ecmfile->filename = $data['filename'] ?? 'testfile.txt';
        $ecmfile->filepath = $data['filepath'] ?? 'mycompany';
        $ecmfile->fullpath_orig = $data['fullpath_orig'] ?? '';
        $ecmfile->description = $data['description'] ?? 'Test file';
        $ecmfile->keywords = $data['keywords'] ?? '';
        $ecmfile->gen_or_uploaded = $data['gen_or_uploaded'] ?? 'uploaded';
        $ecmfile->fk_user_c = $this->testUser->id;

        $result = $ecmfile->create($this->testUser);
        if ($result < 0) {
            throw new \Exception("Failed to create test ECM file: " . $ecmfile->error);
        }

        return $ecmfile;
    }
}
