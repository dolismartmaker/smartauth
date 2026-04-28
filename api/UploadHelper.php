<?php

/**
 * UploadHelper.php
 *
 * Helper API for business modules that consume staged uploads created
 * via the smartauth /upload route.
 *
 * Typical usage in a module controller:
 *
 *   use SmartAuth\Api\UploadHelper;
 *
 *   $info = UploadHelper::consumeUpload(
 *       $body['cover_image_upload_id'],
 *       $userId,
 *       $finalDir . '/cover.jpg'
 *   );
 *   // $info: ['filename' => 'photo.jpg', 'mime' => 'image/jpeg',
 *   //         'size' => 12345, 'sha256' => '...', 'path' => '/abs/.../cover.jpg']
 *
 * The staged file is moved (not copied) into $destPath. The staging
 * entry is removed from disk afterwards. Returns null if the upload id
 * does not exist, has expired, or does not belong to the given user.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class UploadHelper
{
    /**
     * Move a staged upload into its final destination.
     *
     * @param string $uploadId  Token returned by POST /upload
     * @param int    $userId    Owning user id (must match the staged upload)
     * @param string $destPath  Absolute final path on disk. Parent directories
     *                          are created when missing.
     * @return array|null       File metadata + 'path' on success, null on failure
     */
    public static function consumeUpload($uploadId, $userId, $destPath)
    {
        $info = SmartUpload::get($uploadId, (int) $userId);
        if ($info === null) {
            dol_syslog("SmartAuth UploadHelper::consumeUpload - Upload not found or expired: $uploadId (user $userId)", LOG_WARNING);
            return null;
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            $created = false;
            if (function_exists('dol_mkdir')) {
                $created = dol_mkdir($destDir);
            } else {
                $created = @mkdir($destDir, 0755, true);
            }
            if (!$created && !is_dir($destDir)) {
                dol_syslog("SmartAuth UploadHelper::consumeUpload - Failed to create $destDir", LOG_ERR);
                return null;
            }
        }

        if (!@rename($info['filepath'], $destPath)) {
            // rename() fails across filesystems; fall back to copy + unlink.
            if (!@copy($info['filepath'], $destPath)) {
                dol_syslog("SmartAuth UploadHelper::consumeUpload - Failed to copy {$info['filepath']} -> $destPath", LOG_ERR);
                return null;
            }
            @unlink($info['filepath']);
        }

        // Best-effort cleanup of the now-empty staging subdir.
        SmartUpload::delete($uploadId, (int) $userId);

        return [
            'filename' => $info['filename'],
            'mime' => $info['mime'],
            'size' => $info['size'],
            'sha256' => $info['sha256'],
            'path' => $destPath,
        ];
    }

    /**
     * Inspect a staged upload without consuming it. Useful when the
     * module needs to validate context (e.g. extra MIME constraints
     * specific to the entity type) before moving the file.
     *
     * @param string $uploadId
     * @param int    $userId
     * @return array|null      Same shape as SmartUpload::get(), or null
     */
    public static function describe($uploadId, $userId)
    {
        return SmartUpload::get($uploadId, (int) $userId);
    }

    /**
     * Drop a staged upload without consuming it (e.g. PWA aborted the
     * form, or the module rejected the upload). Idempotent.
     *
     * @param string $uploadId
     * @param int    $userId
     * @return bool
     */
    public static function discard($uploadId, $userId)
    {
        return SmartUpload::delete($uploadId, (int) $userId);
    }
}
