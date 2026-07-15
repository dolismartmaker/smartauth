<?php

/**
 * Dev-only: link the SQLite Dolibarr test-harness driver from a local checkout.
 *
 * Integration tests run against cap-rel/dolibarr-integration-sqlite (a full
 * Dolibarr + SQLite), normally installed as a plain composer COPY. When you are
 * actively developing the driver's MySQL-compatibility layer
 * (htdocs/core/db/sqlite3.class.php) in a sibling checkout, you want those
 * edits picked up by this project's tests WITHOUT re-copying the package.
 *
 * We deliberately link ONLY the driver file, not the whole package. Symlinking
 * the entire package turns vendor/.../htdocs/custom/<module> into live symlinks
 * back to the consumer projects, whose own vendor/ re-links the package -> a
 * symlink diamond that makes Dolibarr's recursive dol_dir_list() loop forever
 * ("Too many levels of symbolic links"). A single-file symlink has no such
 * problem: the surrounding package stays a clean copy.
 *
 * No-op when the sibling source checkout is absent (CI, fresh clone): the plain
 * composer copy is used as-is. Wired on composer post-install-cmd /
 * post-update-cmd so the link survives a reinstall.
 *
 * The same script applies verbatim to every other consumer project (see
 * ~/docs/DOLIBARR_SQLITE_DEV_LINK.md); only the relative sibling path matters.
 */

$projectRoot = dirname(__DIR__);
$sourceRoot  = dirname($projectRoot) . '/dolibarr-integration-sqlite';

$sourceDriver = $sourceRoot . '/htdocs/core/db/sqlite3.class.php';
$vendorDriver = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/htdocs/core/db/sqlite3.class.php';

// No local source checkout (CI / fresh clone): keep the composer copy.
if (!is_file($sourceDriver)) {
    return;
}
// Package not installed yet: nothing to link.
if (!is_dir(dirname($vendorDriver))) {
    return;
}
// Already linked to this source: nothing to do.
if (is_link($vendorDriver) && realpath($vendorDriver) === realpath($sourceDriver)) {
    return;
}

// Atomic swap: build the symlink under a temp name, then rename over the copy.
// If anything fails, the vendor driver (the copy) is left untouched.
$tmp = $vendorDriver . '.linktmp';
@unlink($tmp);
if (@symlink($sourceDriver, $tmp) && @rename($tmp, $vendorDriver)) {
    fwrite(STDERR, "[smartauth] Linked SQLite driver from local source: $sourceRoot\n");
} else {
    @unlink($tmp);
    fwrite(STDERR, "[smartauth] Could not link local SQLite driver; using the composer copy.\n");
}
