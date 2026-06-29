<?php

/**
 * smartauthsyncidempotency.class.php
 *
 * Tiny data-access helper for the llx_smartauth_sync_idempotency table.
 *
 * Backs idempotency of the 'create' action on POST /sync/push: when a client
 * retries a push after a 2xx response was lost on the wire, the original
 * server_id is replayed (keyed on client_uuid + temp_id + object_type)
 * instead of inserting a duplicate object.
 *
 * Two-layer defense (mirrors SmartAuthUploadIdempotency):
 *   1. Application-level pre-check (findServerId), required because Dolibarr's
 *      SQLite translator used in tests does not load the .key.sql index files,
 *      so the DB constraint cannot be relied upon there.
 *   2. MySQL/PG: the UNIQUE INDEX uk_sync_idempotency is the real lock under
 *      concurrency; record() fails cleanly if a parallel retry slipped past
 *      the pre-check window (treated as "already recorded", not an error).
 *
 * Cleanup is driven by SmartAuth::doScheduledJob() -> deleteOld(86400).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class SmartAuthSyncIdempotency
{
    private const TABLE = 'smartauth_sync_idempotency';

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Return the server_id previously created for this (client_uuid, temp_id,
     * object_type, entity), or null if this create has not been seen before.
     *
     * @param  string $clientUuid Client UUID
     * @param  string $tempId     Client-side temporary id of the change
     * @param  string $objectType Syncable object type
     * @param  int    $entity     Entity the object was created in
     * @return int|null           Existing server_id, or null
     */
    public function findServerId(string $clientUuid, string $tempId, string $objectType, int $entity): ?int
    {
        $sql = "SELECT server_id FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE client_uuid = '" . $this->db->escape($clientUuid) . "'";
        $sql .= " AND temp_id = '" . $this->db->escape($tempId) . "'";
        $sql .= " AND object_type = '" . $this->db->escape($objectType) . "'";
        $sql .= " AND entity = " . ((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] SmartAuthSyncIdempotency: findServerId failed: ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }
        return (int) $obj->server_id;
    }

    /**
     * Record the mapping after a successful create. A duplicate (a concurrent
     * retry that slipped past findServerId and hit the unique index) is not an
     * error: the mapping already exists with the same server_id.
     *
     * @return bool True if a row was inserted, false on duplicate/failure
     */
    public function record(string $clientUuid, string $tempId, string $objectType, int $serverId, int $fkUser, int $entity): bool
    {
        $now = dol_now();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " (client_uuid, temp_id, object_type, server_id, fk_user, created_at, entity)";
        $sql .= " VALUES ('" . $this->db->escape($clientUuid) . "',";
        $sql .= " '" . $this->db->escape($tempId) . "',";
        $sql .= " '" . $this->db->escape($objectType) . "',";
        $sql .= " " . ((int) $serverId) . ",";
        $sql .= " " . ((int) $fkUser) . ",";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " " . ((int) $entity) . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] SmartAuthSyncIdempotency: record failed (likely duplicate): ' . $this->db->lasterror(), LOG_DEBUG);
            return false;
        }
        return true;
    }

    /**
     * Drop rows older than $maxAgeSeconds (based on created_at). Called from
     * SmartAuth::doScheduledJob() to keep the cache bounded.
     *
     * @return int Rows deleted, or -1 on failure
     */
    public function deleteOld(int $maxAgeSeconds = 86400): int
    {
        if ($maxAgeSeconds <= 0) {
            return 0;
        }
        $cutoff = dol_now() - $maxAgeSeconds;
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE created_at < '" . $this->db->idate($cutoff) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] SmartAuthSyncIdempotency: deleteOld failed', LOG_ERR);
            return -1;
        }
        return (int) $this->db->affected_rows($resql);
    }
}
