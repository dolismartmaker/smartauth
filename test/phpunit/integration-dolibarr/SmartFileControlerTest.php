<?php

/**
 * Tests for SmartFileControler
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SmartFileControler.php';

use SmartAuth\Api\SmartFileControler;

class SmartFileControlerTest extends DolibarrRealTestCase
{
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new SmartFileControler();
    }

    /**
     * Test SmartFileControler instantiation
     */
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(SmartFileControler::class, $this->controller);
    }

    /**
     * Test download method returns expected structure
     */
    public function testDownloadReturnsExpectedStructure(): void
    {
        $result = $this->controller->download();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[1]);
    }

    /**
     * Test download method returns data array
     */
    public function testDownloadReturnsDataArray(): void
    {
        $result = $this->controller->download();

        $this->assertIsArray($result[0]);
        $this->assertArrayHasKey('tobecome', $result[0]);
    }

    /**
     * Test download with null argument
     */
    public function testDownloadWithNullArgument(): void
    {
        $result = $this->controller->download(null);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
    }

    /**
     * Test download with array argument
     */
    public function testDownloadWithArrayArgument(): void
    {
        $result = $this->controller->download(['file' => 'test.txt']);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result[1]);
    }

    /**
     * Test download returns HTTP 200 status
     */
    public function testDownloadReturnsHttp200(): void
    {
        $result = $this->controller->download();

        $this->assertEquals(200, $result[1]);
    }
}
