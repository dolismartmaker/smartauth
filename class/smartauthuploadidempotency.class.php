<?php

/**
 * smartauthuploadidempotency.class.php
 *
 * Tiny data-access helper for the llx_smartauth_upload_idempotency table.
 *
 * Backs the Idempotency-Key middleware on POST /upload: when a PWA retries
 * an upload after a 2xx response was lost on the wire, we replay the
 * original response instead of re-storing the file on disk.
 *
 * Lifecycle (see sql/llx_smartauth_upload_idempotency.sql):
 *   - createProcessing  -> INSERT status=processing (atomic on the unique
 *                          index, so a concurrent retry naturally fails and
 *                          gets a 409 from the controller).
 *   - markCompleted     -> UPDATE status=completed with stored response.
 *   - deleteRow         -> rollback when the upload throws (lets the client
 *                          retry with the same key after fixing the input).
 *
 * Cleanup is driven by SmartAuth::doScheduledJob():
 *   - deleteOld(86400)            : drop completed rows after 24h
 *   - deleteStaleProcessing(600)  : drop processing rows orphaned by a
 *                                   process killed between INSERT and UPDATE
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class SmartAuthUploadIdempotency
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';

    public const KEY_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const TABLE = 'smartauth_upload_idempotency';

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Strict UUID v4 check. Used to skip idempotency entirely when the
     * client did not send a header (or sent garbage) -- falling back to
     * the legacy "no dedup" path keeps old callers working.
     */
    public static function isValidKey(string $key): bool
    {
        return $key !== '' && preg_match(self::KEY_REGEX, $key) === 1;
    }

    /**
     * Look up an existing row by (key, user, entity).
     *
     * @return array<string,mixed>|null
     */
    public function findExisting(string $key, int $fkUser, int $entity): ?array
    {
        $sql = "SELECT rowid, idempotency_token, fk_user, status, upload_id,";
        $sql .= " response_body, http_status, created_at, completed_at, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE idempotency_token = '" . $this->db->escape($key) . "'";
        $sql .= " AND fk_user = " . ((int) $fkUser);
        $sql .= " AND entity = " . ((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUploadIdempotency: findExisting failed', LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'rowid'           => (int) $obj->rowid,
            'idempotency_token' => (string) $obj->idempotency_token,
            'fk_user'         => (int) $obj->fk_user,
            'status'          => (string) $obj->status,
            'upload_id'       => $obj->upload_id !== null ? (string) $obj->upload_id : null,
            'response_body'   => $obj->response_body !== null ? (string) $obj->response_body : null,
            'http_status'     => $obj->http_status !== null ? (int) $obj->http_status : null,
            'created_at'      => $obj->created_at,
            'completed_at'    => $obj->completed_at,
            'entity'          => (int) $obj->entity,
        ];
    }

    /**
     * Try to insert a fresh "processing" row. Returns true on success.
     *
     * Returns false on duplicate (existing row OR unique-index violation).
     * The caller MUST then re-read via findExisting() to decide between
     * 409 (still processing) and replay (already completed).
     *
     * Two-layer defense:
     *   1. Application-level pre-check (cheap SELECT), required because
     *      Dolibarr's SQLite translator used in tests does not load the
     *      .key.sql index files, so we cannot rely on the DB constraint
     *      there.
     *   2. MySQL/PG: the UNIQUE INDEX uk_upload_idempotency_token_user is
     *      the real lock under real concurrency; the INSERT below fails
     *      cleanly if a parallel HTTP request slipped through the pre-check
     *      window.
     */
    public function createProcessing(string $key, int $fkUser, int $entity): bool
    {
        if ($this->findExisting($key, $fkUser, $entity) !== null) {
            return false;
        }

        $now = dol_now();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " (idempotency_token, fk_user, status, created_at, entity)";
        $sql .= " VALUES ('" . $this->db->escape($key) . "',";
        $sql .= " " . ((int) $fkUser) . ",";
        $sql .= " '" . $this->db->escape(self::STATUS_PROCESSING) . "',";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " " . ((int) $entity) . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            // Likely a UNIQUE constraint violation from a concurrent retry
            // that slipped past the pre-check above.
            dol_syslog('SmartAuthUploadIdempotency: createProcessing failed (likely duplicate token): ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_DEBUG);
            return false;
        }
        return true;
    }

    /**
     * Promote a processing row to completed with the response snapshot.
     */
    public function markCompleted(string $key, int $fkUser, int $entity, ?string $uploadId, array $response, int $httpStatus): bool
    {
        $now = dol_now();
        $body = json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            dol_syslog('SmartAuthUploadIdempotency: markCompleted json_encode failed', LOG_ERR);
            $body = '{}';
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
        $sql .= " status = '" . $this->db->escape(self::STATUS_COMPLETED) . "',";
        $sql .= " upload_id = " . ($uploadId !== null && $uploadId !== '' ? "'" . $this->db->escape($uploadId) . "'" : "NULL") . ",";
        $sql .= " response_body = '" . $this->db->escape($body) . "',";
        $sql .= " http_status = " . ((int) $httpStatus) . ",";
        $sql .= " completed_at = '" . $this->db->idate($now) . "'";
        $sql .= " WHERE idempotency_token = '" . $this->db->escape($key) . "'";
        $sql .= " AND fk_user = " . ((int) $fkUser);
        $sql .= " AND entity = " . ((int) $entity);
        $sql .= " AND status = '" . $this->db->escape(self::STATUS_PROCESSING) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUploadIdempotency: markCompleted failed', LOG_ERR);
            return false;
        }
        return ((int) $this->db->affected_rows($resql)) === 1;
    }

    /**
     * Drop a row (used to roll back a failed attempt so the client can
     * retry the same key after fixing the input).
     */
    public function deleteRow(string $key, int $fkUser, int $entity): bool
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE idempotency_token = '" . $this->db->escape($key) . "'";
        $sql .= " AND fk_user = " . ((int) $fkUser);
        $sql .= " AND entity = " . ((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUploadIdempotency: deleteRow failed', LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Drop all rows older than $maxAgeSeconds (based on created_at).
     * Targets completed rows (24h default); processing orphans go
     * through deleteStaleProcessing() with a shorter window.
     *
     * @return int rows deleted, or -1 on failure
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
            dol_syslog('SmartAuthUploadIdempotency: deleteOld failed', LOG_ERR);
            return -1;
        }
        return (int) $this->db->affected_rows($resql);
    }

    /**
     * Drop processing rows older than $maxAgeSeconds. These are orphans
     * of a process killed between the INSERT and the UPDATE. Default 10
     * minutes covers the worst-case client retry window (backoff cap
     * 60s * maxRetries 10).
     *
     * @return int rows deleted, or -1 on failure
     */
    public function deleteStaleProcessing(int $maxAgeSeconds = 600): int
    {
        if ($maxAgeSeconds <= 0) {
            return 0;
        }
        $cutoff = dol_now() - $maxAgeSeconds;
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE status = '" . $this->db->escape(self::STATUS_PROCESSING) . "'";
        $sql .= " AND created_at < '" . $this->db->idate($cutoff) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUploadIdempotency: deleteStaleProcessing failed', LOG_ERR);
            return -1;
        }
        return (int) $this->db->affected_rows($resql);
    }
}
