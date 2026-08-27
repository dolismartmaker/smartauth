<?php

/**
 * A subject-aware table may NOT carry a foreign key on fk_user.
 *
 * THE DEFECT. Since the subject refactor a token subject is a (type, id)
 * couple. External subjects -- 'account' (llx_societe_account) and 'member'
 * (llx_adherent) -- put their id in fk_societe_account / fk_adherent and write
 * the SENTINEL 0 into fk_user, which stays NOT NULL because SQLite has no
 * MODIFY COLUMN (cf the header of sql/update_015.sql, the migration that
 * introduced the sentinel).
 *
 * Four .key.sql files nevertheless declared
 * "FOREIGN KEY (fk_user) REFERENCES llx_user(rowid)". On InnoDB 0 is not NULL:
 * it must match a llx_user row of rowid 0, which never exists. So on
 * MySQL/MariaDB every insert of an acc:/mbr: row failed with error 1452 --
 * self-service registration, password reset and the whole OAuth2 flow dead for
 * exactly the population the SSO door admits.
 *
 * WHY THE SUITE NEVER SAW IT, and what each half of this file really proves.
 * The integration harness builds its schema from the llx_*.sql files only: it
 * does not load the .key.sql at all, and SQLite runs with foreign_keys=OFF by
 * default, so no constraint ever existed there. No integration test could have
 * caught this, and none can prove the fix. Hence two independent assertions:
 *
 *   1. testAForeignKeyRejectsTheSentinelZero() proves the SEMANTICS on a
 *      throw-away SQLite database with foreign_keys=ON -- 0 really is refused
 *      by a foreign key, it is not a MySQL quirk being assumed;
 *   2. testNoSubjectAwareTableDeclaresAForeignKeyOnFkUser() proves the FILE
 *      CONTRACT -- no shipped .key.sql declares that constraint on a table
 *      carrying a subject_type column, today or after a future addition.
 *
 * Neither half is sufficient alone. Together they pin the defect closed.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class SubjectSentinelForeignKeyTest extends TestCase
{
    /**
     * The premise, demonstrated rather than assumed: under an enforced foreign
     * key, the sentinel 0 is refused while NULL is accepted. That asymmetry is
     * the whole defect.
     */
    public function testAForeignKeyRejectsTheSentinelZero(): void
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required to demonstrate foreign-key semantics');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE llx_user (rowid INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT)');
        $pdo->exec(
            'CREATE TABLE probe ('
            . ' rowid INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' fk_user INTEGER NOT NULL,'
            . ' FOREIGN KEY (fk_user) REFERENCES llx_user(rowid))'
        );
        $pdo->exec("INSERT INTO llx_user (login) VALUES ('real')");

        // A real user id passes.
        $pdo->exec('INSERT INTO probe (fk_user) VALUES (1)');
        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn()
        );

        // The sentinel does not: rowid 0 never exists (AUTOINCREMENT starts at 1).
        $rejected = false;
        try {
            $pdo->exec('INSERT INTO probe (fk_user) VALUES (0)');
        } catch (\PDOException $e) {
            $rejected = true;
        }
        $this->assertTrue(
            $rejected,
            'a foreign key must refuse fk_user = 0: this is exactly why the constraint '
            . 'broke every external-subject insert on MySQL/MariaDB (error 1452)'
        );
    }

    /**
     * The file contract: a table that carries subject_type stores external
     * subjects, therefore writes the sentinel, therefore must not constrain
     * fk_user against llx_user.
     *
     * Walks the shipped SQL rather than a hardcoded list, so a subject-aware
     * table added later cannot reintroduce the defect quietly.
     */
    public function testNoSubjectAwareTableDeclaresAForeignKeyOnFkUser(): void
    {
        $sqlDir = dirname(__DIR__, 3) . '/sql';
        $this->assertDirectoryExists($sqlDir);

        $subjectAware = $this->subjectAwareTables($sqlDir);
        $this->assertNotEmpty(
            $subjectAware,
            'no table with a subject_type column found: the walk would be vacuous '
            . '(did the schema change shape?)'
        );

        $offenders = [];
        foreach (glob($sqlDir . '/*.key.sql') as $keyFile) {
            foreach (preg_split('/\R/', (string) file_get_contents($keyFile)) as $lineNo => $line) {
                $line = trim($line);
                if ($line === '' || strncmp($line, '--', 2) === 0) {
                    continue;
                }
                if (stripos($line, 'FOREIGN KEY (fk_user)') === false) {
                    continue;
                }
                if (!preg_match('/ALTER\s+TABLE\s+(\S+)/i', $line, $m)) {
                    continue;
                }
                $table = strtolower($m[1]);
                if (isset($subjectAware[$table])) {
                    $offenders[] = basename($keyFile) . ':' . ($lineNo + 1) . ' -> ' . $table;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These tables store external subjects (they carry a subject_type column), so they write\n"
            . "the sentinel fk_user = 0 -- a foreign key to llx_user(rowid) makes every such insert\n"
            . "fail with error 1452 on InnoDB. Drop the constraint, or make fk_user nullable and\n"
            . "write NULL instead of 0:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The user-only tables keep their constraint: it is a genuine integrity
     * rule there, and dropping it would be a silent loss. Guards against an
     * over-broad "fix" that strips every fk_user foreign key of the module.
     */
    public function testUserOnlyTablesKeepTheirForeignKey(): void
    {
        $sqlDir = dirname(__DIR__, 3) . '/sql';
        $expected = [
            'llx_smartauth_qr_pairings.key.sql',
            'llx_smartauth_upload_idempotency.key.sql',
            'llx_smartauth_user_devices.key.sql',
        ];

        foreach ($expected as $file) {
            $path = $sqlDir . '/' . $file;
            $this->assertFileExists($path);
            $this->assertStringContainsString(
                'FOREIGN KEY (fk_user)',
                (string) file_get_contents($path),
                $file . ' has no subject_type column: its fk_user constraint is legitimate and must stay'
            );
        }
    }

    /**
     * Tables declaring a subject_type column, keyed by table name.
     *
     * @return array<string,true>
     */
    private function subjectAwareTables(string $sqlDir): array
    {
        $out = [];
        foreach (glob($sqlDir . '/llx_*.sql') as $file) {
            if (substr($file, -8) === '.key.sql') {
                continue;
            }
            // One file may declare SEVERAL tables -- llx_smartauth_oauth.sql
            // holds clients, codes, tokens and consents. A first version of
            // this helper matched only the first CREATE TABLE of the file, so
            // llx_smartauth_oauth_tokens was never classified subject-aware and
            // the contract stayed green while the constraint was restored.
            // Caught by falsification, hence the per-block walk below.
            $current = null;
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
                $line = trim($line);
                if ($line === '' || strncmp($line, '--', 2) === 0) {
                    continue;
                }
                if (preg_match('/create\s+table\s+(?:if\s+not\s+exists\s+)?([A-Za-z0-9_]+)/i', $line, $m)) {
                    $current = strtolower($m[1]);
                    continue;
                }
                // A column declaration, not a mention inside a comment.
                if ($current !== null && preg_match('/^subject_type\s+/i', $line)) {
                    $out[$current] = true;
                }
            }
        }

        return $out;
    }
}
