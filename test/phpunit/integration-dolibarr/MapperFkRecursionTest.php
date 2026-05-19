<?php

/**
 * Test that the FK recursion guard (dmTrait::$FK_MAX_DEPTH = 2)
 * actually prevents infinite traversal on circular foreign keys.
 *
 * The mapping engine resolves FK fields (`fk_soc`, `fk_pays`, ...) by
 * instantiating the matching `dmXxx` mapper and calling its
 * `exportMappedData()`. Without a guard, a chain like
 * Societe -> fk_user_creat (User) -> fk_user_creat (User) -> ...
 * (Dolibarr's User objects do self-reference their creator) would
 * recurse without bound. This test pins the contract: at depth >= 2,
 * the engine returns the raw FK id instead of a nested object.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmThirdparty;

class MapperFkRecursionTest extends DolibarrRealTestCase
{
    /**
     * Country id used for fk_pays in tests. Dolibarr seed data ships
     * c_country.rowid = 1 (France) in every install profile we ship.
     */
    private const COUNTRY_FRANCE = 1;

    /**
     * The static depth counter must reset to 0 between tests because
     * a previous run that leaks (test failure between increment and
     * decrement) would poison every subsequent test silently.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->forceRecursionDepth(0);
    }

    protected function tearDown(): void
    {
        $this->forceRecursionDepth(0);
        parent::tearDown();
    }

    public function testExportDataReturnsRawIdWhenAtMaxDepth(): void
    {
        $mapper = new dmThirdparty();

        // Drive the counter to FK_MAX_DEPTH. We are explicitly testing
        // the guard, not the real-world chain that would trigger it.
        $this->forceRecursionDepth(2);

        $result = $mapper->exportData('fk_pays', self::COUNTRY_FRANCE);

        $this->assertSame(
            self::COUNTRY_FRANCE,
            $result,
            'At max depth, exportData must return the raw FK id, not a resolved object.'
        );
    }

    public function testExportDataResolvesNestedObjectBelowMaxDepth(): void
    {
        $mapper = new dmThirdparty();
        $this->forceRecursionDepth(0);

        $result = $mapper->exportData('fk_pays', self::COUNTRY_FRANCE);

        // Below max depth, we expect a resolved object (stdClass with
        // at least 'code', the canonical column dmCcountry exposes),
        // not the bare integer.
        $this->assertNotSame(
            self::COUNTRY_FRANCE,
            $result,
            'Below max depth, exportData must resolve the FK to an object.'
        );
        $this->assertIsObject($result, 'Resolved FK must be an object.');
        $this->assertObjectHasProperty('code', $result);
    }

    public function testCounterIsRestoredAfterNestedExport(): void
    {
        $mapper = new dmThirdparty();
        $this->forceRecursionDepth(0);

        $mapper->exportData('fk_pays', self::COUNTRY_FRANCE);

        // The try/finally in exportData must guarantee the counter
        // returns to its pre-call value. A leak here would break every
        // subsequent mapper call in the same request.
        $this->assertSame(0, $this->readRecursionDepth());
    }

    /**
     * PHP 8.2 deprecates direct access to a trait's static property;
     * the property must be reached via a class that uses the trait
     * (here, dmThirdparty). PHP 8.1+ also makes private members
     * reflection-accessible without setAccessible().
     */
    private function forceRecursionDepth(int $value): void
    {
        $prop = (new \ReflectionClass(dmThirdparty::class))
            ->getProperty('fkRecursionDepth');
        $prop->setValue(null, $value);
    }

    private function readRecursionDepth(): int
    {
        $prop = (new \ReflectionClass(dmThirdparty::class))
            ->getProperty('fkRecursionDepth');
        return (int) $prop->getValue();
    }
}
