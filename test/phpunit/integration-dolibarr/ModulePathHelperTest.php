<?php

/**
 * Integration tests for ModulePathHelper class
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\ModulePathHelper;

/**
 * @covers \SmartAuth\Api\ModulePathHelper
 */
class ModulePathHelperTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModulePathHelper::resetCache();
    }

    protected function tearDown(): void
    {
        ModulePathHelper::resetCache();
        parent::tearDown();
    }

    public function testModuleRootDirsReturnsArray(): void
    {
        $dirs = ModulePathHelper::moduleRootDirs();
        $this->assertIsArray($dirs);
        foreach ($dirs as $dir) {
            $this->assertDirectoryExists($dir);
            $this->assertStringEndsNotWith('/', $dir);
        }
    }

    public function testActiveRouteModulesEmptyWhenNotDeclared(): void
    {
        global $conf;
        if (isset($conf->modules_parts['smartauth'])) {
            unset($conf->modules_parts['smartauth']);
        }
        $this->assertSame([], ModulePathHelper::activeRouteModules());
    }

    public function testActiveRouteModulesReturnsDeclaredKeysLowercased(): void
    {
        global $conf;
        if (!isset($conf->modules_parts) || !is_array($conf->modules_parts)) {
            $conf->modules_parts = [];
        }
        $conf->modules_parts['smartauth'] = [
            'capmail' => ['routes' => 1],
            'CapTodo' => ['routes' => 1],
        ];

        $modules = ModulePathHelper::activeRouteModules();
        sort($modules);
        $this->assertSame(['capmail', 'captodo'], $modules);

        unset($conf->modules_parts['smartauth']);
    }

    public function testModuleUrlPrefixFallsBackToCustom(): void
    {
        // A module that exists in no configured root resolves to the historical
        // /custom/<module> default.
        $this->assertSame(
            '/custom/definitelynotamodule',
            ModulePathHelper::moduleUrlPrefix('definitelynotamodule')
        );
    }

    public function testLocalRoutesFileReturnsEmptyWhenAbsent(): void
    {
        $this->assertSame('', ModulePathHelper::localRoutesFile('definitelynotamodule'));
    }
}
