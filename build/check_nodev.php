<?php

/**
 * check_nodev.php
 *
 * Fails the release when a development dependency is about to be, or has been,
 * shipped.
 *
 * buildzip.php embeds vendor/ as a whole, so anything composer left on disk
 * ends up in the archive: the test harness alone
 * (cap-rel/dolibarr-integration-sqlite) is a full Dolibarr of about 250 MB.
 * "composer install --no-dev" is supposed to clear them out, but it only
 * removes what it believes it installed: against a stale
 * vendor/composer/installed.json it reports "0 removals" and leaves every
 * development package on disk. Checking the outcome rather than trusting the
 * command is what turns the expectation into a guarantee.
 *
 * Usage, from the module root:
 *   php build/check_nodev.php vendor   before building, on the source tree
 *   php build/check_nodev.php zip      after building, on the archive
 *
 * Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Module code and version, detected the same way buildzip.php does so that
 * both agree on the archive name.
 *
 * @return array  module code and version
 */
function detectModule()
{
    $tab = glob("core/modules/mod*.class.php");
    if (count($tab) != 1) {
        fwrite(STDERR, "check_nodev: cannot identify the module descriptor in core/modules/\n");
        exit(1);
    }

    $file = $tab[0];
    $mod = '';
    if (preg_match_all("/.*mod(?<mod>.*)\.class\.php/", $file, $matches)) {
        $mod = strtolower(reset($matches['mod']));
    }
    if ($mod === '') {
        fwrite(STDERR, "check_nodev: cannot extract the module code from $file\n");
        exit(1);
    }

    $version = '';
    $contents = file_get_contents($file);
    if ($contents !== false && preg_match_all("/^.*this->version\s*=\s*'(?<version>.*)'\s*;.*\$/m", $contents, $matches)) {
        $version = reset($matches['version']);
    }
    if ($version === '') {
        fwrite(STDERR, "check_nodev: cannot extract the version from $file\n");
        exit(1);
    }

    return array($mod, $version);
}

/**
 * Development package names declared by the lock file.
 *
 * @return array  composer package names
 */
function devPackages()
{
    if (!file_exists('composer.lock')) {
        fwrite(STDERR, "check_nodev: composer.lock not found, run from the module root\n");
        exit(1);
    }

    $lock = json_decode(file_get_contents('composer.lock'), true);
    if (!is_array($lock) || !isset($lock['packages-dev'])) {
        fwrite(STDERR, "check_nodev: composer.lock holds no packages-dev section\n");
        exit(1);
    }

    return array_column($lock['packages-dev'], 'name');
}

/**
 * Report the outcome and exit.
 *
 * @param  string $subject  what was inspected, for the messages
 * @param  array  $found    development packages that are present
 * @param  int    $total    number of development packages looked for
 * @param  string $extra    trailing detail appended to the success line
 * @return void
 */
function report($subject, array $found, $total, $extra = '')
{
    if (!empty($found)) {
        fwrite(STDERR, "check_nodev: FAILED, " . count($found) . " development dependency(ies) present in $subject:\n");
        foreach ($found as $package) {
            fwrite(STDERR, "  - $package\n");
        }
        fwrite(STDERR, "Rebuild against a --no-dev vendor directory.\n");
        exit(1);
    }

    echo "check_nodev: OK, none of the $total development dependency(ies) is in $subject" . $extra . "\n";
    exit(0);
}

$mode = isset($argv[1]) ? $argv[1] : 'zip';
if (!in_array($mode, array('vendor', 'zip'), true)) {
    fwrite(STDERR, "check_nodev: unknown mode '$mode', expected 'vendor' or 'zip'\n");
    exit(1);
}

$devs = devPackages();
if (empty($devs)) {
    echo "check_nodev: no development dependency declared, nothing to check\n";
    exit(0);
}

if ($mode === 'vendor') {
    $found = array();
    foreach ($devs as $package) {
        if (is_dir('vendor/' . $package)) {
            $found[] = $package;
        }
    }
    report('vendor/', $found, count($devs));
}

list($mod, $version) = detectModule();
$zipPath = sys_get_temp_dir() . "/module_" . $mod . "-" . $version . ".zip";

if (!file_exists($zipPath)) {
    fwrite(STDERR, "check_nodev: $zipPath not found, build the zip first\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "check_nodev: cannot open $zipPath\n");
    exit(1);
}

$entries = array();
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if ($stat !== false) {
        $entries[] = $stat['name'];
    }
}
$zip->close();

$found = array();
foreach ($devs as $package) {
    $needle = 'vendor/' . $package . '/';
    foreach ($entries as $entry) {
        if (strpos($entry, $needle) !== false) {
            $found[] = $package;
            break;
        }
    }
}

report(
    basename($zipPath),
    $found,
    count($devs),
    sprintf(' (%d entries, %s MB)', count($entries), round(filesize($zipPath) / 1024 / 1024, 1))
);
