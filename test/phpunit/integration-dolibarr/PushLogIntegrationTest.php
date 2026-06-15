<?php

/**
 * Integration tests for the Web Push send log (SmartAuthPushLog DAO + cron purge).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * @requires PHP >= 8.2
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

class PushLogIntegrationTest extends DolibarrRealTestCase
{
    /** @var string */
    private $table;

    protected function setUp(): void
    {
        parent::setUp();
        dol_include_once('/smartauth/class/smartauthpushlog.class.php');
        $this->table = MAIN_DB_PREFIX.'smartauth_push_logs';
        // Logging is on by default; make the default explicit for the suite.
        global $conf;
        $conf->global->SMARTAUTH_PUSH_LOG_ENABLED = 1;
    }

    private function newLog(): \SmartAuthPushLog
    {
        return new \SmartAuthPushLog($this->db);
    }

    private function countRows(): int
    {
        return (int) $this->db->num_rows($this->db->query("SELECT rowid FROM ".$this->table));
    }

    public function testRecordSendInsertsRowWithExpectedColumns(): void
    {
        $log = $this->newLog();
        $id = $log->recordSend([
            'fk_subscription'    => 42,
            'subject_type'       => 'user',
            'fk_user'            => 7,
            'entity'             => 1,
            'notification_type'  => 'ticket_new',
            'notification_title' => 'Nouveau ticket',
            'notification_body'  => 'Un ticket vous est assigné',
            'notification_data'  => json_encode(['url' => '/ticket/3']),
            'http_status'        => 201,
            'success'            => 1,
            'error_message'      => null,
        ]);

        $this->assertGreaterThan(0, $id);

        $obj = $this->db->fetch_object($this->db->query("SELECT * FROM ".$this->table." WHERE rowid = ".(int) $id));
        $this->assertSame('user', $obj->subject_type);
        $this->assertSame(7, (int) $obj->fk_user);
        $this->assertSame(42, (int) $obj->fk_subscription);
        $this->assertSame('ticket_new', $obj->notification_type);
        $this->assertSame('Nouveau ticket', $obj->notification_title);
        $this->assertSame(201, (int) $obj->http_status);
        $this->assertSame(1, (int) $obj->success);
        $this->assertNull($obj->error_message);
    }

    public function testRecordSendStoresFailureReason(): void
    {
        $log = $this->newLog();
        $id = $log->recordSend([
            'subject_type'  => 'account',
            'fk_user'       => 0,
            'fk_societe_account' => 15,
            'entity'        => 1,
            'success'       => 0,
            'http_status'   => 410,
            'error_message' => 'Subscription expired',
        ]);

        $obj = $this->db->fetch_object($this->db->query("SELECT * FROM ".$this->table." WHERE rowid = ".(int) $id));
        $this->assertSame('account', $obj->subject_type);
        $this->assertSame(0, (int) $obj->fk_user);
        $this->assertSame(15, (int) $obj->fk_societe_account);
        $this->assertSame(0, (int) $obj->success);
        $this->assertSame('Subscription expired', $obj->error_message);
    }

    public function testRecordSendNoOpWhenLoggingDisabled(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_PUSH_LOG_ENABLED = 0;

        $before = $this->countRows();
        $res = $this->newLog()->recordSend([
            'subject_type' => 'user', 'fk_user' => 1, 'entity' => 1, 'success' => 1,
        ]);

        $this->assertSame(0, $res);
        $this->assertSame($before, $this->countRows());

        $conf->global->SMARTAUTH_PUSH_LOG_ENABLED = 1;
    }

    public function testRecordSendTruncatesOverlongTitle(): void
    {
        $long = str_repeat('a', 400);
        $id = $this->newLog()->recordSend([
            'subject_type' => 'user', 'fk_user' => 1, 'entity' => 1, 'success' => 1,
            'notification_title' => $long,
        ]);

        $obj = $this->db->fetch_object($this->db->query("SELECT notification_title FROM ".$this->table." WHERE rowid = ".(int) $id));
        $this->assertLessThanOrEqual(255, strlen($obj->notification_title));
    }

    public function testPurgeOlderThanRemovesOldRowsOnly(): void
    {
        $now = $this->db->idate(dol_now());
        $old = $this->db->idate(dol_now() - 120 * 24 * 3600);

        $insert = function (string $when) {
            $sql = "INSERT INTO ".$this->table." (subject_type, fk_user, entity, success, date_creation)";
            $sql .= " VALUES ('user', 1, 1, 1, '".$this->db->escape($when)."')";
            $this->assertNotFalse($this->db->query($sql));
        };
        $insert($now);
        $insert($old);
        $insert($old);

        $deleted = $this->newLog()->purgeOlderThan(90);
        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->countRows());
    }

    public function testDoScheduledJobPurgesPushLogsBeyondRetention(): void
    {
        global $conf;
        $conf->global->SMARTAUTH_PUSH_LOG_RETENTION_DAYS = 90;

        $now = $this->db->idate(dol_now());
        $old = $this->db->idate(dol_now() - 200 * 24 * 3600);

        $insert = function (string $when) {
            $sql = "INSERT INTO ".$this->table." (subject_type, fk_user, entity, success, date_creation)";
            $sql .= " VALUES ('user', 1, 1, 1, '".$this->db->escape($when)."')";
            $this->assertNotFalse($this->db->query($sql));
        };
        $insert($now);
        $insert($old);

        $sa = new \SmartAuth($this->db);
        $sa->doScheduledJob();

        $this->assertSame(1, $this->countRows());
    }
}
