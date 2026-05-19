<?php

/**
 * Meta-test : every concrete mapper class in `dolMapping/` must be
 * referenced by at least one test file under `test/phpunit/`.
 *
 * This guards the CI invariant stated in TODO-mappers-centralisation.md
 * Phase 5 : "tous les mappers coeur ont au moins un test". A new mapper
 * landing in `dolMapping/` without any test in the suite causes this
 * test to fail loudly, preventing silent drift.
 *
 * Excluded from the check:
 *   - Infrastructure files (dmBase, dmTrait, dmHelper, dmLinesTrait,
 *     dmLinkedObjectsTrait, MapperValidationException) -- not mappers.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use PHPUnit\Framework\TestCase;

class MapperTestCoverageMetaTest extends TestCase
{
    /**
     * Files in dolMapping/ that are not concrete mappers and therefore
     * do not need a dedicated test class.
     */
    private const INFRASTRUCTURE_FILES = [
        'dmBase',
        'dmTrait',
        'dmHelper',
        'dmLinesTrait',
        'dmLinkedObjectsTrait',
        'MapperValidationException',
    ];

    public function testEveryCoreMapperHasAtLeastOneTestReference(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $mapperDir = $repoRoot . '/dolMapping';
        $testDir = $repoRoot . '/test/phpunit';

        $mappers = $this->listCoreMappers($mapperDir);
        $this->assertNotEmpty(
            $mappers,
            "No core mapper found under $mapperDir. Did the layout change?"
        );

        $orphans = [];
        foreach ($mappers as $mapper) {
            if (!$this->hasTestReference($testDir, $mapper)) {
                $orphans[] = $mapper;
            }
        }

        $this->assertEmpty(
            $orphans,
            "The following mappers have no test reference under $testDir:\n"
                . "  - " . implode("\n  - ", $orphans) . "\n"
                . "Add at least one test (round-trip or unit) referencing "
                . "the class name, or document the exclusion here."
        );
    }

    /**
     * Walk dolMapping/ and return the list of concrete mapper class
     * names (filenames without .php), filtered against infrastructure
     * and dictionary files.
     *
     * @return string[]
     */
    private function listCoreMappers(string $mapperDir): array
    {
        $found = [];
        foreach (glob($mapperDir . '/*.php') as $file) {
            $base = basename($file, '.php');

            if (in_array($base, self::INFRASTRUCTURE_FILES, true)) {
                continue;
            }
            $found[] = $base;
        }
        sort($found);
        return $found;
    }

    /**
     * Return true if any *.php file under $testDir mentions the class
     * name (use/extends/new/string occurrence). We deliberately accept
     * any textual reference: a test that imports the class but does not
     * exercise it still counts as "noticed", and a stale test will fail
     * for other reasons. The point of this meta-test is only to catch
     * mappers that no test mentions at all.
     */
    private function hasTestReference(string $testDir, string $className): bool
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Avoid matching the meta-test file itself (it lists every
            // exclusion in INFRASTRUCTURE_FILES, which would otherwise
            // satisfy the check for "dmBase" etc., but those are already
            // excluded above so this self-skip is defensive).
            if ($file->getFilename() === basename(__FILE__)) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($className, '/') . '\b/', $contents)) {
                return true;
            }
        }
        return false;
    }
}
