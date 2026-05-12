<?php

/**
 * UploadController.php
 *
 * HTTP controller exposing a generic binary upload endpoint for PWA
 * modules of the SmartMaker stack.
 *
 * Route:
 *   POST /upload    multipart/form-data, field "file" (single)
 *                   or "files[]" (multiple). Returns one or many upload
 *                   metadata blocks. Module code then references the
 *                   returned upload_id from its own JSON payload.
 *
 * The actual storage and validation logic lives in SmartUpload. This
 * controller only adapts $_FILES to that service and shapes the JSON
 * response.
 *
 * Idempotency: if the client sends an Idempotency-Key header containing
 * a valid UUID v4, a retry of the same request (same user, same key)
 * replays the original 2xx response without re-storing the file on disk.
 * Backed by llx_smartauth_upload_idempotency. See
 * SmartAuthUploadIdempotency for the contract.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class UploadController
{
    /**
     * POST /upload
     *
     * Accepts:
     *   - $_FILES['file']        single file
     *   - $_FILES['files']       array of files (HTML5 multiple input)
     *   - $_FILES['files[N]']    indexed entries (legacy pattern)
     *
     * Optional header: Idempotency-Key (UUID v4) to deduplicate retries.
     *
     * @param array|null $payload Routing payload (user, entity, ...)
     * @return array              [responseBody, httpStatus]
     */
    public function store($payload = null)
    {
        dol_syslog("SmartAuth UploadController::store");

        $user = $payload['user'] ?? null;
        $userId = is_object($user) ? (int) $user->id : 0;
        $entity = isset($payload['entity']) ? (int) $payload['entity'] : null;

        if ($userId <= 0) {
            dol_syslog("SmartAuth UploadController::store - Missing authenticated user", LOG_WARNING);
            return [['error' => 'Authentication required'], 401];
        }

        // Resolve the effective entity once so the idempotency scope and
        // the storage scope agree on the same value.
        global $conf, $db;
        $effectiveEntity = $entity ?? ($conf->entity ?? 1);

        // ---- Idempotency middleware -----------------------------------
        // Activated only when the client opts in via a valid UUID v4
        // header. Otherwise we fall through to the legacy path, which
        // keeps old callers (curl scripts, anonymous tests) working.
        $key = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '';
        $idempRepo = null;
        if ($key !== '' && $this->idempotencyEnabled($db)) {
            dol_include_once('/smartauth/class/smartauthuploadidempotency.class.php');
            if (\SmartAuthUploadIdempotency::isValidKey($key)) {
                $idempRepo = new \SmartAuthUploadIdempotency($db);
                $replay = $this->handleIdempotencyLookup($idempRepo, $key, $userId, $effectiveEntity);
                if ($replay !== null) {
                    return $replay;
                }
            } else {
                // Malformed key: log and ignore (legacy path), per spec
                // section 14.2 -- a 400 here would surprise old clients.
                dol_syslog("SmartAuth UploadController::store - Ignoring malformed Idempotency-Key", LOG_NOTICE);
            }
        }

        try {
            list($responseBody, $httpStatus) = $this->processFiles($userId, $effectiveEntity);
        } catch (\Throwable $e) {
            // Unexpected failure: release the idempotency slot so the
            // client can retry with the same key after the root cause is
            // fixed (else the row would block them for the full 24h
            // retention window).
            if ($idempRepo !== null) {
                $idempRepo->deleteRow($key, $userId, $effectiveEntity);
            }
            throw $e;
        }

        if ($idempRepo !== null) {
            if ($httpStatus >= 200 && $httpStatus < 300) {
                $uploadId = $this->extractUploadId($responseBody);
                $idempRepo->markCompleted($key, $userId, $effectiveEntity, $uploadId, $responseBody, $httpStatus);
            } else {
                // 4xx: client sent a bad payload. Drop the row so the
                // same key can be reused with corrected input.
                $idempRepo->deleteRow($key, $userId, $effectiveEntity);
            }
        }

        return [$responseBody, $httpStatus];
    }

    /**
     * DELETE /upload/{upload_id}
     *
     * Lets the PWA cancel a staged upload (e.g. user removed the photo
     * before submitting the form). Idempotent.
     *
     * @param array|null $payload
     * @return array
     */
    public function destroy($payload = null)
    {
        $user = $payload['user'] ?? null;
        $userId = is_object($user) ? (int) $user->id : 0;
        $uploadId = isset($payload['upload_id']) ? (string) $payload['upload_id'] : '';

        if ($userId <= 0) {
            return [['error' => 'Authentication required'], 401];
        }
        if ($uploadId === '') {
            return [['error' => 'Missing upload id'], 400];
        }

        $existed = SmartUpload::get($uploadId, $userId);
        SmartUpload::delete($uploadId, $userId);
        dol_syslog("SmartAuth UploadController::destroy - Removed upload $uploadId for user $userId (existed=" . ($existed ? '1' : '0') . ")");

        return [['deleted' => true], 200];
    }

    /**
     * Resolve an existing idempotency row into a controller response.
     * Returns null when the caller should proceed with the actual upload
     * (either no row, or createProcessing succeeded).
     *
     * The unique index on (idempotency_key, fk_user, entity) acts as the
     * lock: createProcessing returning false means a concurrent request
     * grabbed the slot, and the safe move is to re-read and replay/409.
     *
     * @return array|null  [body, status] tuple or null
     */
    private function handleIdempotencyLookup(\SmartAuthUploadIdempotency $repo, string $key, int $userId, int $entity)
    {
        $existing = $repo->findExisting($key, $userId, $entity);
        if ($existing === null) {
            if ($repo->createProcessing($key, $userId, $entity)) {
                return null;
            }
            // INSERT race: another request just took the slot. Re-read.
            $existing = $repo->findExisting($key, $userId, $entity);
            if ($existing === null) {
                // Pathological: INSERT failed and row still absent.
                // Log and fall through to legacy path so the user is not
                // permanently blocked.
                dol_syslog("SmartAuth UploadController: idempotency INSERT race left no row, falling through", LOG_WARNING);
                return null;
            }
        }

        if ($existing['status'] === \SmartAuthUploadIdempotency::STATUS_COMPLETED) {
            $body = $existing['response_body'] !== null ? json_decode($existing['response_body'], true) : null;
            if (!is_array($body)) {
                $body = ['upload_id' => $existing['upload_id']];
            }
            $status = (int) ($existing['http_status'] ?: 200);
            dol_syslog("SmartAuth UploadController: replaying completed idempotent response for key=" . substr($key, 0, 8) . "...");
            return [$body, $status];
        }

        // status === STATUS_PROCESSING: tell the client to retry shortly.
        return [
            ['error' => 'upload_in_progress', 'retry_after_ms' => 2000],
            409,
        ];
    }

    /**
     * Pick the upload_id to persist in the idempotency cache. Handles
     * both the single-file convenience shape and the multi-file shape.
     * Returns null when no usable id is present (e.g. an error-shaped 2xx).
     */
    private function extractUploadId(array $response): ?string
    {
        if (!empty($response['upload_id']) && is_string($response['upload_id'])) {
            return $response['upload_id'];
        }
        if (!empty($response['uploads']) && is_array($response['uploads'])) {
            $first = reset($response['uploads']);
            if (is_array($first) && !empty($first['upload_id']) && is_string($first['upload_id'])) {
                return $first['upload_id'];
            }
        }
        return null;
    }

    /**
     * Idempotency requires the DAO + the underlying table. In test
     * environments where the integration schema is not loaded (pure unit
     * tests with a NullDolibarrAdapter), $db can be missing or invalid.
     * In that case we silently fall back to the legacy path rather than
     * crash on a CREATE TABLE that does not exist.
     */
    private function idempotencyEnabled($db): bool
    {
        return is_object($db) && method_exists($db, 'query') && method_exists($db, 'escape');
    }

    /**
     * Core upload flow extracted so the idempotency middleware can wrap
     * it cleanly. Returns the same [body, status] tuple the controller
     * historically returned.
     *
     * @return array [body, status]
     */
    private function processFiles(int $userId, int $entity): array
    {
        $files = $this->collectFiles();
        if (empty($files)) {
            dol_syslog("SmartAuth UploadController::processFiles - No file in request", LOG_WARNING);
            return [['error' => 'No file uploaded'], 400];
        }

        $uploads = [];
        $errors = [];

        foreach ($files as $index => $file) {
            $err = SmartUpload::validate($file);
            if ($err !== null) {
                dol_syslog("SmartAuth UploadController::processFiles - Rejected file $index: $err", LOG_WARNING);
                $errors[] = [
                    'index' => $index,
                    'filename' => $file['name'] ?? null,
                    'error' => $err,
                ];
                continue;
            }

            try {
                $info = SmartUpload::store($file, $userId, $entity);
            } catch (\Throwable $e) {
                dol_syslog("SmartAuth UploadController::processFiles - Storage failure: " . $e->getMessage(), LOG_ERR);
                $errors[] = [
                    'index' => $index,
                    'filename' => $file['name'] ?? null,
                    'error' => 'Storage failure',
                ];
                continue;
            }

            $uploads[] = $info;
        }

        if (empty($uploads)) {
            return [['error' => 'No file accepted', 'details' => $errors], 400];
        }

        // Single-file convenience: when the client uploaded exactly one
        // file under the 'file' key, return a flat object rather than a
        // list. The list form is preserved when the client sent multiple.
        if (count($uploads) === 1 && isset($files['file'])) {
            $response = $uploads[0];
            if (!empty($errors)) {
                $response['errors'] = $errors;
            }
            return [$response, 201];
        }

        $response = ['uploads' => $uploads];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        return [$response, 201];
    }

    /**
     * Normalize $_FILES into a flat array of single-file entries keyed
     * by index. Supports the three common field shapes used by the dsd
     * and scanpdf controllers.
     *
     * @return array<string|int, array>
     */
    private function collectFiles()
    {
        $out = [];

        if (!empty($_FILES['file']) && is_array($_FILES['file']) && !is_array($_FILES['file']['name'] ?? null)) {
            $out['file'] = $_FILES['file'];
        }

        if (!empty($_FILES['files'])) {
            $entry = $_FILES['files'];
            if (is_array($entry['name'] ?? null)) {
                // HTML5 multiple input: $_FILES['files']['name'][i] etc.
                $count = count($entry['name']);
                for ($i = 0; $i < $count; $i++) {
                    $out['files[' . $i . ']'] = [
                        'name' => $entry['name'][$i] ?? '',
                        'type' => $entry['type'][$i] ?? '',
                        'tmp_name' => $entry['tmp_name'][$i] ?? '',
                        'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $entry['size'][$i] ?? 0,
                    ];
                }
            } else {
                $out['files'] = $entry;
            }
        }

        // Legacy indexed pattern: files[0], files[1], ...
        foreach ($_FILES as $key => $entry) {
            if ($key === 'file' || $key === 'files') {
                continue;
            }
            if (preg_match('/^files\[\d+\]$/', $key)) {
                $out[$key] = $entry;
            }
        }

        return $out;
    }
}
