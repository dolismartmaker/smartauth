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
use SmartAuth\Api\ObjectDocumentController;
use SmartAuth\Api\ObjectController;
use SmartAuth\Api\ObjectLineController;
use SmartAuth\Api\ObjectActionController;
use SmartAuth\Api\ObjectPaymentController;
use SmartAuth\Api\PwaController;
use SmartAuth\Api\UploadController;
use SmartAuth\Api\QrPairController;
use SmartAuth\Api\ThirdpartyMediaController;
use SmartAuth\Api\PushController;
use SmartAuth\Api\Account\UserDeviceController;

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

// ========== Binary Upload Routes ========== //

// Generic binary upload endpoint for PWA modules.
// POST a multipart/form-data body with a "file" or "files[]" field. The
// staged upload returns an opaque upload_id that business modules then
// reference from their own JSON payload (and consume server-side via
// SmartAuth\Api\UploadHelper::consumeUpload). See ~/docs/UPLOAD_PWA.md.
Route::post('upload', UploadController::class, 'store', true);

// Cancel a staged upload before consumption (idempotent).
Route::delete('upload/{upload_id}', UploadController::class, 'destroy', true);

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

// ========== Object Document Routes ========== //

// Batch list documents for all objects of a type (for offline sync, WAF-friendly path-only)
Route::get('object/documents/{type}/{doctypes}', ObjectDocumentController::class, 'batchIndex', true);
Route::get('object/documents/{type}/{doctypes}/since/{timestamp}', ObjectDocumentController::class, 'batchIndex', true);

// Bundle download: multiple documents as a single ZIP archive (share hash authorization)
Route::post('object/documents/bundle', ObjectDocumentController::class, 'bundle', true);

// List documents for a single object (for offline sync)
Route::get('object/{type}/{id}/documents', ObjectDocumentController::class, 'index', true);

// Download a document via share hash: ?q=<share_hash> (recommended)
Route::get('object/{type}/{id}/document', ObjectDocumentController::class, 'download', true);

// Download a document binary via share hash: ?q=<share_hash> (recommended)
Route::get('object/{type}/{id}/document/binary', ObjectDocumentController::class, 'downloadBinary', true);

// Download a document via path segment (legacy, for simple filenames without subdirectories)
Route::get('object/{type}/{id}/document/{path}', ObjectDocumentController::class, 'download', true);

// Download a document binary via path segment (legacy)
Route::get('object/{type}/{id}/document/{path}/binary', ObjectDocumentController::class, 'downloadBinary', true);

// ========== Generic Object Facade (objects/{type}) ========== //
//
// Synchronous CRUD/search/pagination over the core Dolibarr objects, driven
// by ObjectRegistry + the dm* mappers. Replaces the per-module reimplementation
// of the same controller (cf TODO-facade-rest-objets-dolibarr.md).
//
// The route param is named {objtype} (not {type}) on purpose: RouteController
// merges path params over the body, so a placeholder named 'type' would shadow
// the object's own 'type' field (Product/Category/ActionComm...) and make it
// impossible to set on create/update. The URL is unchanged (e.g. objects/product).
//
// ORDERING MATTERS: RouteCache matches dynamic routes (those carrying a
// {placeholder}) in registration order, first match wins. The static
// sub-resources count/columns/describe MUST be declared BEFORE the catch-all
// objects/{objtype}/{id} pattern -- otherwise {id}=([^/]+) would swallow 'count'.
Route::get('objects/{objtype}/count', ObjectController::class, 'count', true);
Route::get('objects/{objtype}/columns', ObjectController::class, 'columns', true);
Route::get('objects/{objtype}/describe', ObjectController::class, 'describe', true);
Route::get('objects/{objtype}/{id}', ObjectController::class, 'show', true);
Route::get('objects/{objtype}', ObjectController::class, 'index', true);
Route::post('objects/{objtype}', ObjectController::class, 'create', true);
Route::patch('objects/{objtype}/{id}', ObjectController::class, 'update', true);
Route::delete('objects/{objtype}/{id}', ObjectController::class, 'destroy', true);
Route::delete('objects/{objtype}', ObjectController::class, 'deleteBulk', true);

// ----- Document lines (order/invoice/proposal ; supports_lines types) -----
// These patterns carry extra static segments ('lines') so they never collide by
// segment count with the CRUD routes above. Within POST, the 5-segment
// lines/reorder and the 4-segment lines patterns differ in length, and the
// PATCH/DELETE lines/{lineid} routes are 5-segment (vs the 3-segment CRUD
// {id}). No same-method same-length ambiguity, so ordering here is free.
Route::get('objects/{objtype}/{id}/lines', ObjectLineController::class, 'index', true);
Route::post('objects/{objtype}/{id}/lines/reorder', ObjectLineController::class, 'reorder', true);
Route::post('objects/{objtype}/{id}/lines', ObjectLineController::class, 'store', true);
Route::patch('objects/{objtype}/{id}/lines/{lineid}', ObjectLineController::class, 'update', true);
Route::delete('objects/{objtype}/{id}/lines/{lineid}', ObjectLineController::class, 'destroy', true);

// ----- Document workflow actions (validate/setDraft/close/setPaid/...) -----
// 5-segment POST like lines/reorder, but the 4th segment is the literal
// 'actions' (vs 'lines'), so the two never match the same path.
Route::post('objects/{objtype}/{id}/actions/{action}', ObjectActionController::class, 'invoke', true);

// ----- Invoice payments (record/list) -----
// 4-segment routes with the static 'payments' segment; no collision with the
// CRUD or line routes.
Route::get('objects/{objtype}/{id}/payments', ObjectPaymentController::class, 'index', true);
Route::post('objects/{objtype}/{id}/payments', ObjectPaymentController::class, 'store', true);

// ========== Media Routes ========== //
//
// Convention: media/{object_type}/{id}/{variant}[/{sub_variant}].
// The media/ prefix reserves the URL namespace for all binary asset
// streams smartauth serves (logos, photos, avatars, ...). Each object
// type has its own controller so the entity-isolation and Dolibarr
// permission rules stay localised. PWAs pair these with
// useAuthenticatedImage() from smartcommon for IndexedDB caching.

// Thirdparty logo (full + mini). Replaces the legacy base64 transport
// that lived in dmThirdparty::fieldFilterValueLogo(). Cached aggressively
// (1 day fresh + 30 days stale-while-revalidate) with ETag for cheap
// revalidation on the rare cases the logo actually changes.
Route::get('media/thirdparty/{id}/logo', ThirdpartyMediaController::class, 'logo', true);
Route::get('media/thirdparty/{id}/logo/mini', ThirdpartyMediaController::class, 'logoMini', true);

// Routes futures (placeholders documentaires, a decommenter quand
// les controllers correspondants seront crees) :
// Route::get('media/product/{id}/photo', ProductMediaController::class, 'photo', true);
// Route::get('media/product/{id}/photo/mini', ProductMediaController::class, 'photoMini', true);
// Route::get('media/user/{id}/avatar', UserMediaController::class, 'avatar', true);
// Route::get('media/contact/{id}/photo', ContactMediaController::class, 'photo', true);

// ========== PWA Routes ========== //

// PWA manifest (unprotected - needed before login)
Route::get('manifest.webmanifest', PwaController::class, 'manifest');

// PWA icons (unprotected)
Route::get('icon/{size}', PwaController::class, 'icon');

// ========== Logical user devices (cross-PWA grouping) ========== //

// List the authenticated user's logical devices (e.g. "mon iPhone").
Route::get('account/user-devices', UserDeviceController::class, 'index', true);

// Create a new logical device and link the current JWT device to it.
// Body: { label, icon? }
Route::post('account/user-devices', UserDeviceController::class, 'create', true);

// Link the current JWT device row to an existing logical device.
Route::post('account/user-devices/{id}/link', UserDeviceController::class, 'link', true);

// Rename a logical device. Body: { label }
Route::post('account/user-devices/{id}/rename', UserDeviceController::class, 'rename', true);

// Update the persistent viewport mode (auto | mobile | tablet | desktop)
// of a logical device. Shared across every SmartMaker PWA on this device.
// Body: { viewport_mode }
Route::post('account/user-devices/{id}/viewport-mode', UserDeviceController::class, 'setViewportMode', true);

// Cascade-revoke a logical device: every PWA session attached to it is
// terminated in one shot.
Route::delete('account/user-devices/{id}', UserDeviceController::class, 'revoke', true);

// ========== Cross-device QR pairing (mobile side) ========== //

// Mobile claims a QR pairing after scanning. The PC (Dolibarr session) has
// already created the pending row through /custom/smartauth/user/qrpair.php.
Route::post('qr-pair/{pairing_id}/claim', QrPairController::class, 'claim');

// Mobile polls the pairing until the PC user confirms; the first poll on a
// confirmed row exchanges it for an access+refresh JWT pair (single-use).
Route::post('qr-pair/{pairing_id}/poll', QrPairController::class, 'poll');

// ========== Web Push Notifications ========== //

// VAPID public key (public, read-only - needed before subscribing).
Route::get('push/vapid-public-key', PushController::class, 'getVapidPublicKey', false);

// Subscription management (JWT mobile -> subject-aware, silo A / usr: only).
Route::post('push/subscribe', PushController::class, 'subscribe', true);
Route::delete('push/unsubscribe', PushController::class, 'unsubscribe', true);
Route::get('push/subscriptions', PushController::class, 'listSubscriptions', true);

// NO 'push/send' route by default: sending is internal via PushSender
// (triggers, cron, admin). A 'true' route would let any authenticated subject
// push to any target. Only open a send route as 'oauth2' + scope
// 'smartauth:push.send' for a confidential M2M client if ever required.
