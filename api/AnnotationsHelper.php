<?php

/**
 * AnnotationsHelper.php
 *
 * Shared primitive for SmartMaker business modules to persist spatial
 * annotations (markers placed on a photo by the PWA <PhotoAnnotator>)
 * attached to a binary file already staged through the smartauth /upload
 * pipeline and consumed via UploadHelper::consumeUpload().
 *
 * Storage: a Dolibarr extrafield named "smartmaker_annotations"
 * (mediumtext) on the llx_ecm_files extrafield table. One ecmfile row
 * holds a flat JSON array of annotation entries -- there is no per-photo
 * map nesting because one ecmfile is exactly one photo.
 *
 * Typical usage in a module controller, after consumeUpload():
 *
 *   use SmartAuth\Api\AnnotationsHelper;
 *
 *   AnnotationsHelper::set($ecmFileId, $payload['annotations'][$photoKey], $userId);
 *
 *   // Later, on read:
 *   $annotations = AnnotationsHelper::get($ecmFileId, $userId);
 *
 * The PWA is responsible for HTML-escaping any annotation content at
 * render time -- the JSON returned by get() is raw user input and is
 * NOT html-escaped server-side.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class AnnotationsHelper
{
    /**
     * Hard cap on the number of annotation entries stored on a single
     * ecmfile. Anything past this is truncated (with a WARNING log).
     */
    const MAX_ANNOTATIONS_PER_FILE = 200;

    /**
     * Hard cap on the serialized JSON byte size before persisting. Keeps
     * a stray PUT from blowing up the row even though the underlying
     * mediumtext column has 16 MB of headroom.
     */
    const MAX_PAYLOAD_BYTES = 1048576; // 1 MB

    /**
     * Hard cap on the nesting depth of the per-annotation `payload`
     * array. Entries deeper than this are skipped.
     */
    const MAX_PAYLOAD_DEPTH = 5;

    /**
     * Allowed shape for an annotation `id`.
     */
    const ID_REGEX = '/^[A-Za-z0-9_-]{1,80}$/';

    /**
     * Allowed shape for an annotation `type`.
     */
    const TYPE_REGEX = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * Name of the extrafield used on llx_ecm_files. Created in
     * modSmartauth::init() at module activation.
     */
    const EXTRAFIELD_NAME = 'smartmaker_annotations';

    /**
     * Persist annotations on an ecmfile row.
     *
     * Sanitizes the input first (see sanitize()), then enforces the size
     * caps. Returns false (with a log) on owner mismatch, validation
     * failure or storage error.
     *
     * @param int   $ecmFileId   Row id of llx_ecm_files
     * @param array $annotations Plain array of annotation arrays
     * @param int   $userId      User performing the write (owner check)
     * @return bool              true on success, false on validation/IO failure
     */
    public static function set($ecmFileId, array $annotations, $userId): bool
    {
        global $db;

        $ecmFileId = (int) $ecmFileId;
        $userId = (int) $userId;

        if ($ecmFileId <= 0 || $userId <= 0) {
            dol_syslog("SmartAuth AnnotationsHelper::set - invalid ids ecmFileId=$ecmFileId userId=$userId", LOG_WARNING);
            return false;
        }

        $ecm = self::loadEcmFile($db, $ecmFileId);
        if ($ecm === null) {
            dol_syslog("SmartAuth AnnotationsHelper::set - ecmfile $ecmFileId not found", LOG_WARNING);
            return false;
        }

        if ((int) $ecm->fk_user_c !== $userId) {
            dol_syslog("SmartAuth AnnotationsHelper::set - owner mismatch on ecmfile $ecmFileId (fk_user_c={$ecm->fk_user_c}, userId=$userId)", LOG_WARNING);
            return false;
        }

        $clean = self::sanitize($annotations);

        if (count($clean) > self::MAX_ANNOTATIONS_PER_FILE) {
            $kept = self::MAX_ANNOTATIONS_PER_FILE;
            $dropped = count($clean) - $kept;
            dol_syslog("SmartAuth AnnotationsHelper::set - truncating $dropped entries past cap ($kept) on ecmfile $ecmFileId", LOG_WARNING);
            $clean = array_slice($clean, 0, $kept);
        }

        try {
            $json = json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            dol_syslog("SmartAuth AnnotationsHelper::set - json_encode failed on ecmfile $ecmFileId: " . $e->getMessage(), LOG_ERR);
            return false;
        }

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            dol_syslog("SmartAuth AnnotationsHelper::set - serialized payload " . strlen($json) . "B exceeds cap " . self::MAX_PAYLOAD_BYTES . "B on ecmfile $ecmFileId", LOG_ERR);
            return false;
        }

        $ecm->array_options = is_array($ecm->array_options ?? null) ? $ecm->array_options : [];
        $ecm->array_options['options_' . self::EXTRAFIELD_NAME] = $json;

        $res = $ecm->updateExtraField(self::EXTRAFIELD_NAME);
        if ($res <= 0) {
            $err = is_array($ecm->errors ?? null) ? implode('; ', $ecm->errors) : '';
            if ($err === '' && !empty($ecm->error)) {
                $err = $ecm->error;
            }
            dol_syslog("SmartAuth AnnotationsHelper::set - updateExtraField returned $res on ecmfile $ecmFileId: $err", LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Read annotations stored on an ecmfile row.
     *
     * Always returns an array. Returns [] on owner mismatch, missing
     * row, or corrupted JSON (always logs the cause). Re-runs sanitize()
     * defensively in case the column was edited manually outside the
     * helper.
     *
     * @param int $ecmFileId
     * @param int $userId
     * @return array
     */
    public static function get($ecmFileId, $userId): array
    {
        global $db;

        $ecmFileId = (int) $ecmFileId;
        $userId = (int) $userId;

        if ($ecmFileId <= 0 || $userId <= 0) {
            dol_syslog("SmartAuth AnnotationsHelper::get - invalid ids ecmFileId=$ecmFileId userId=$userId", LOG_WARNING);
            return [];
        }

        $ecm = self::loadEcmFile($db, $ecmFileId);
        if ($ecm === null) {
            dol_syslog("SmartAuth AnnotationsHelper::get - ecmfile $ecmFileId not found", LOG_WARNING);
            return [];
        }

        if ((int) $ecm->fk_user_c !== $userId) {
            dol_syslog("SmartAuth AnnotationsHelper::get - owner mismatch on ecmfile $ecmFileId (fk_user_c={$ecm->fk_user_c}, userId=$userId)", LOG_WARNING);
            return [];
        }

        // loadEcmFile() already calls fetch_optionals(); array_options is
        // present whether the row has any extrafields or not.
        $raw = null;
        if (is_array($ecm->array_options ?? null)) {
            $raw = $ecm->array_options['options_' . self::EXTRAFIELD_NAME] ?? null;
        }

        if ($raw === null || $raw === '') {
            return [];
        }

        if (!is_string($raw)) {
            // Already structured (defensive: future code may pre-decode).
            if (is_array($raw)) {
                return self::sanitize($raw);
            }
            dol_syslog("SmartAuth AnnotationsHelper::get - unexpected non-string non-array extrafield on ecmfile $ecmFileId (" . gettype($raw) . ")", LOG_WARNING);
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            dol_syslog("SmartAuth AnnotationsHelper::get - corrupted JSON in extrafield on ecmfile $ecmFileId: " . $e->getMessage(), LOG_ERR);
            return [];
        }

        if (!is_array($decoded)) {
            dol_syslog("SmartAuth AnnotationsHelper::get - decoded extrafield is not an array on ecmfile $ecmFileId (" . gettype($decoded) . ")", LOG_WARNING);
            return [];
        }

        return self::sanitize($decoded);
    }

    /**
     * Sanitize a raw annotations array.
     *
     * Pure function, no DB / IO / globals. Filters invalid entries,
     * clamps coordinates onto [0..100], dedupes by id (last wins). Each
     * rejection is logged via dol_syslog. Used by set() and exposed for
     * module controllers that want to pre-validate before calling set().
     *
     * @param array $raw
     * @return array
     */
    public static function sanitize(array $raw): array
    {
        $byId = [];
        $duplicates = 0;

        foreach ($raw as $idx => $ann) {
            if (!is_array($ann)) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx is not an array (" . gettype($ann) . "), skipping", LOG_WARNING);
                continue;
            }

            $id = $ann['id'] ?? null;
            if (!is_string($id) && !is_int($id)) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx missing or non-scalar id, skipping", LOG_WARNING);
                continue;
            }
            $idStr = (string) $id;
            if (!preg_match(self::ID_REGEX, $idStr)) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx has invalid id '$idStr', skipping", LOG_WARNING);
                continue;
            }

            $type = $ann['type'] ?? null;
            if (!is_string($type) || !preg_match(self::TYPE_REGEX, $type)) {
                $disp = is_scalar($type) ? (string) $type : gettype($type);
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) has invalid type '$disp', skipping", LOG_WARNING);
                continue;
            }

            if (!array_key_exists('x', $ann) || !array_key_exists('y', $ann)) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) missing x or y, skipping", LOG_WARNING);
                continue;
            }
            if (!is_numeric($ann['x']) || !is_numeric($ann['y'])) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) non-numeric coords, skipping", LOG_WARNING);
                continue;
            }
            $xRaw = (float) $ann['x'];
            $yRaw = (float) $ann['y'];
            $x = max(0.0, min(100.0, $xRaw));
            $y = max(0.0, min(100.0, $yRaw));
            if ($x !== $xRaw || $y !== $yRaw) {
                dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) coords clamped from ($xRaw,$yRaw) to ($x,$y)", LOG_WARNING);
            }

            $payload = [];
            if (array_key_exists('payload', $ann)) {
                if (!is_array($ann['payload'])) {
                    dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) payload is not an array (" . gettype($ann['payload']) . "), skipping", LOG_WARNING);
                    continue;
                }
                if (self::arrayDepth($ann['payload']) > self::MAX_PAYLOAD_DEPTH) {
                    dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) payload depth exceeds " . self::MAX_PAYLOAD_DEPTH . ", skipping", LOG_WARNING);
                    continue;
                }
                try {
                    json_encode($ann['payload'], JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    dol_syslog("SmartAuth AnnotationsHelper::sanitize - entry #$idx (id=$idStr) payload not JSON-encodable: " . $e->getMessage() . ", skipping", LOG_WARNING);
                    continue;
                }
                $payload = $ann['payload'];
            }

            $entry = [
                'id' => $idStr,
                'type' => $type,
                'x' => $x,
                'y' => $y,
                'payload' => $payload,
            ];

            if (isset($byId[$idStr])) {
                $duplicates++;
            }
            $byId[$idStr] = $entry;
        }

        if ($duplicates > 0) {
            dol_syslog("SmartAuth AnnotationsHelper::sanitize - $duplicates duplicate id(s), kept last occurrence each", LOG_WARNING);
        }

        return array_values($byId);
    }

    /**
     * Compute the maximum nesting depth of an array. An array containing
     * only scalars has depth 1.
     *
     * @param array $arr
     * @return int
     */
    private static function arrayDepth(array $arr): int
    {
        $depth = 1;
        foreach ($arr as $v) {
            if (is_array($v)) {
                $sub = 1 + self::arrayDepth($v);
                if ($sub > $depth) {
                    $depth = $sub;
                }
            }
        }
        return $depth;
    }

    /**
     * Load and prepare an EcmFiles row, including its extrafields.
     * Returns null on any failure.
     *
     * @param \DoliDB $db
     * @param int     $ecmFileId
     * @return \EcmFiles|null
     */
    private static function loadEcmFile($db, $ecmFileId)
    {
        if (!class_exists('EcmFiles')) {
            $path = defined('DOL_DOCUMENT_ROOT') ? DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php' : '';
            if ($path !== '' && is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('EcmFiles')) {
            dol_syslog("SmartAuth AnnotationsHelper - EcmFiles class not available", LOG_ERR);
            return null;
        }

        $ecm = new \EcmFiles($db);
        $res = $ecm->fetch($ecmFileId);
        if ($res <= 0) {
            return null;
        }
        $ecm->fetch_optionals();
        return $ecm;
    }
}
