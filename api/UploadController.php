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

        $files = $this->collectFiles();
        if (empty($files)) {
            dol_syslog("SmartAuth UploadController::store - No file in request", LOG_WARNING);
            return [['error' => 'No file uploaded'], 400];
        }

        $uploads = [];
        $errors = [];

        foreach ($files as $index => $file) {
            $err = SmartUpload::validate($file);
            if ($err !== null) {
                dol_syslog("SmartAuth UploadController::store - Rejected file $index: $err", LOG_WARNING);
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
                dol_syslog("SmartAuth UploadController::store - Storage failure: " . $e->getMessage(), LOG_ERR);
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
            // All files were rejected: return 400 with the per-file errors.
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
