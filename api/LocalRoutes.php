<?php

/**
 * LocalRoutes.php
 *
 * Local routes definitions for SmartAuth module.
 * These routes are automatically discovered and included by RouteCache
 * during the registration phase.
 *
 * Uses the same syntax as api.php: Route::get(), Route::post(), etc.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

use SmartAuth\Api\RouteController as Route;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\PasswordResetController;
use SmartAuth\Api\SmartFileController;
use SmartAuth\Api\SmartTempFileController;
use SmartAuth\Api\SyncController;

// ========== Auth Routes ========== //

// Login (unprotected)
Route::get('login', AuthController::class, 'index');
Route::post('login', AuthController::class, 'login');

// Refresh token
Route::get('refresh', AuthController::class, 'refresh');

// Logout (protected)
Route::post('logout', AuthController::class, 'logout', true);

// Device registration (protected)
Route::post('device', AuthController::class, 'device', true);

// ========== Password Routes ========== //

// Request password reset (unprotected)
Route::post('password/reset', PasswordResetController::class, 'requestReset');

// Confirm password reset with token (unprotected)
Route::post('password/confirm', PasswordResetController::class, 'confirmReset');

// Change password for authenticated user (protected)
Route::post('password/change', PasswordResetController::class, 'changePassword', true);

// ========== File Routes ========== //

// File download by ECM share hash - base64 JSON response (protected)
Route::get('file/{hash}', SmartFileController::class, 'download', true);

// File download by ECM share hash - binary stream (protected)
Route::get('file/{hash}/binary', SmartFileController::class, 'downloadBinary', true);

// ========== Temp File Routes ========== //

// Temporary file download - base64 JSON response (protected)
Route::get('temp-file/{token}', SmartTempFileController::class, 'download', true);

// Temporary file download - binary stream (protected)
Route::get('temp-file/{token}/binary', SmartTempFileController::class, 'downloadBinary', true);

// Temporary file deletion (protected)
Route::delete('temp-file/{token}', SmartTempFileController::class, 'delete', true);

// ========== Sync Routes ========== //

// Sync client registration
Route::post('sync/register', SyncController::class, 'register', true);

// Sync pull - get changes from server
Route::get('sync/pull', SyncController::class, 'pull', true);

// Sync push - send changes to server
Route::post('sync/push', SyncController::class, 'push', true);

// Sync status - get client sync status
Route::get('sync/status', SyncController::class, 'status', true);

// Sync conflicts - list pending conflicts
Route::get('sync/conflicts', SyncController::class, 'conflicts', true);

// Sync conflict resolution
Route::post('sync/conflicts/{id}/resolve', SyncController::class, 'resolveConflict', true);
