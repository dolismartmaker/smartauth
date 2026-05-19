<?php

/**
 * ThirdpartyMediaController.php
 *
 * Streams binary media (logo, ...) attached to a Dolibarr Societe over HTTP
 * with JWT authentication, ETag-based revalidation and aggressive cache
 * directives. Replaces the legacy base64 inline transport in
 * dmThirdparty::fieldFilterValueLogo() which gonfled every JSON payload.
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

namespace SmartAuth\Api;

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

class ThirdpartyMediaController
{
    // Cache directive applied to both 200 and 304 responses. Logo assets
    // change rarely (months between updates), so the cache is aggressive:
    // 1 day fresh + 30 days stale-while-revalidate. private keeps the
    // cache client-only since the route is JWT-protected.
    const CACHE_CONTROL = 'private, max-age=86400, stale-while-revalidate=2592000';

    // MIME whitelist. Anything else returns 415. Path traversal defence
    // happens before this check, so an unsupported extension here means
    // the Dolibarr DB recorded a logo filename that we cannot serve.
    private static $mimemap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Stream the full-size logo of a thirdparty.
     * Route: GET media/thirdparty/{id}/logo
     *
     * @param array|null $payload Router payload {id, user, entity}
     * @return array Only returned on error: [body, status]. Successful
     *               responses emit headers + body and exit.
     */
    public function logo($payload = null)
    {
        return $this->_dispatch($payload, 'full');
    }

    /**
     * Stream the mini (thumbnail) logo of a thirdparty.
     * Route: GET media/thirdparty/{id}/logo/mini
     *
     * @param array|null $payload Router payload {id, user, entity}
     * @return array Only returned on error: [body, status].
     */
    public function logoMini($payload = null)
    {
        return $this->_dispatch($payload, 'mini');
    }

    /**
     * Public entry wrapper: delegates to _streamLogo() for the testable
     * logic, then emits headers + streams + exits when a body is present,
     * or just emits headers + exits on 304. On error the tuple bubbles up
     * to the router which serialises it as JSON.
     *
     * @param array|null $payload
     * @param string     $variant 'full' or 'mini'
     * @return array Error tuple only.
     */
    private function _dispatch($payload, $variant)
    {
        global $db;

        $result = $this->_streamLogo($payload, $variant);

        if (isset($result['error'])) {
            return [['error' => $result['error']], $result['status']];
        }

        // Close DB before streaming, same pattern as
        // SmartFileController::downloadBinary.
        if (is_object($db)) {
            $db->close();
        }

        // 304 path: emit status, ETag and Cache-Control, no body.
        if ($result['status'] === 304) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $result['headers']['ETag']);
            header('Cache-Control: ' . $result['headers']['Cache-Control']);
            exit;
        }

        // 200 path: emit full headers and stream the file.
        foreach ($result['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        readfileLowMemory($result['body_filepath']);
        exit;
    }

    /**
     * Testable streaming logic. Returns a structured tuple instead of
     * emitting headers / streaming / exiting, so unit tests can inspect
     * the headers and body filepath without intercepting exit().
     *
     * Success 200:
     *   ['status' => 200, 'headers' => [...], 'body_filepath' => string]
     * Success 304:
     *   ['status' => 304, 'headers' => ['ETag' => ..., 'Cache-Control' => ...]]
     * Error:
     *   ['error' => string, 'status' => int]
     *
     * @param array|null $payload
     * @param string     $variant 'full' or 'mini'
     * @return array
     */
    public function _streamLogo($payload, $variant)
    {
        global $conf;

        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($id <= 0) {
            dol_syslog("smartauth::ThirdpartyMediaController - missing or invalid id in payload", LOG_WARNING);
            return ['error' => 'Bad Request: invalid id', 'status' => 400];
        }

        $user = $payload['user'] ?? null;
        if (!is_object($user)) {
            dol_syslog("smartauth::ThirdpartyMediaController - no authenticated user in payload (tp $id)", LOG_WARNING);
            return ['error' => 'Authentication required', 'status' => 401];
        }

        if (!$user->hasRight('societe', 'lire')) {
            $uid = (int) ($user->id ?? 0);
            dol_syslog("smartauth::ThirdpartyMediaController - user $uid lacks societe->lire on tp $id", LOG_WARNING);
            return ['error' => 'Forbidden', 'status' => 403];
        }

        // Direct SQL fetch instead of Societe::fetch(): the Dolibarr fetch()
        // pre-filters by $conf->entity (multi-company), which would mask an
        // entity mismatch as a 404. We need the entity field to distinguish
        // "does not exist" (404) from "exists in another entity" (403), so
        // we read the row unfiltered and apply the entity check ourselves.
        global $db;
        $sql = "SELECT rowid, entity, logo FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $id;
        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) === 0) {
            dol_syslog("smartauth::ThirdpartyMediaController - thirdparty $id not found", LOG_WARNING);
            return ['error' => 'Not Found', 'status' => 404];
        }
        $row = $db->fetch_object($resql);
        $db->free($resql);

        // Entity isolation. entity=0 is the shared/global entity and is
        // always readable. Otherwise the thirdparty entity must match the
        // requesting context.
        $reqEntity = (int) ($payload['entity'] ?? $conf->entity);
        $socEntity = (int) $row->entity;
        if ($socEntity !== 0 && $socEntity !== $reqEntity) {
            $uid = (int) ($user->id ?? 0);
            dol_syslog("smartauth::ThirdpartyMediaController - entity mismatch tp $id (soc entity=$socEntity, req entity=$reqEntity, user $uid)", LOG_WARNING);
            return ['error' => 'Forbidden', 'status' => 403];
        }

        // Minimal stdClass holder with only the fields _resolveLogoPath
        // actually reads: id, entity, logo. Avoids the cost (and entity
        // filter) of a full Societe::fetch().
        $soc = new \stdClass();
        $soc->id = (int) $row->rowid;
        $soc->entity = $socEntity;
        $soc->logo = $row->logo;

        $info = $this->_resolveLogoPath($soc, $variant);
        if (isset($info['error'])) {
            return $info;
        }

        // Path traversal defence: the resolved real path must live under
        // the configured societe output directory for this entity. If the
        // Dolibarr DB ever recorded a poisoned logo filename like
        // "../../etc/passwd", realpath() resolves the join and the
        // strpos check refuses to serve it.
        $entityForBase = (int) $soc->entity;
        $base = $conf->societe->multidir_output[$entityForBase] ?? '';
        $realBase = $base !== '' ? realpath($base) : false;
        $realPath = realpath($info['fullpath']);
        if ($realBase === false || $realPath === false || strpos($realPath, $realBase) !== 0) {
            dol_syslog("smartauth::ThirdpartyMediaController - path traversal refused for tp {$soc->id}: $realPath not under $realBase", LOG_ERR);
            return ['error' => 'Forbidden', 'status' => 403];
        }

        // ETag based on filesize+mtime. Cheap, deterministic, invalidates
        // automatically when the file is overwritten.
        $etag = '"' . dechex($info['filesize']) . '-' . dechex(filemtime($realPath)) . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return [
                'status'  => 304,
                'headers' => [
                    'ETag'          => $etag,
                    'Cache-Control' => self::CACHE_CONTROL,
                ],
            ];
        }

        return [
            'status'        => 200,
            'headers'       => [
                'Content-Type'   => $info['mimetype'],
                'Content-Length' => (string) $info['filesize'],
                'Cache-Control'  => self::CACHE_CONTROL,
                'ETag'           => $etag,
            ],
            'body_filepath' => $realPath,
        ];
    }

    /**
     * Resolve the on-disk path for a logo variant.
     *
     * Layout (mirrors Dolibarr Societe::set_as_client_thumb behaviour):
     *   <multidir_output[entity]>/<id>/logos/<logo>          (full)
     *   <multidir_output[entity]>/<id>/logos/thumbs/<mini>   (mini)
     *
     * @param Societe $soc
     * @param string  $variant 'full' or 'mini'
     * @return array {fullpath, mimetype, filesize} or {error, status}
     */
    private function _resolveLogoPath($soc, $variant)
    {
        global $conf;

        if (empty($soc->logo)) {
            dol_syslog("smartauth::ThirdpartyMediaController - tp {$soc->id} has no logo configured", LOG_DEBUG);
            return ['error' => 'No logo configured', 'status' => 404];
        }

        $entity = (int) $soc->entity;
        $base = $conf->societe->multidir_output[$entity] ?? null;
        if (empty($base)) {
            dol_syslog("smartauth::ThirdpartyMediaController - multidir_output not configured for entity $entity (tp {$soc->id})", LOG_ERR);
            return ['error' => 'Server configuration error', 'status' => 500];
        }

        $logosDir = rtrim($base, '/') . '/' . (int) $soc->id . '/logos';

        if ($variant === 'mini') {
            $miniName = str_replace(
                ['.jpg', '.jpeg', '.png'],
                ['_mini.jpg', '_mini.jpg', '_mini.png'],
                $soc->logo
            );
            $fullpath = $logosDir . '/thumbs/' . $miniName;
        } else {
            $fullpath = $logosDir . '/' . $soc->logo;
        }

        if (!file_exists($fullpath)) {
            dol_syslog("smartauth::ThirdpartyMediaController - logo file missing on disk: $fullpath (tp {$soc->id}, variant=$variant)", LOG_WARNING);
            return ['error' => 'Logo file missing', 'status' => 404];
        }

        $ext = strtolower(pathinfo($fullpath, PATHINFO_EXTENSION));
        if (!isset(self::$mimemap[$ext])) {
            dol_syslog("smartauth::ThirdpartyMediaController - unsupported logo extension '$ext' for tp {$soc->id}", LOG_WARNING);
            return ['error' => 'Unsupported logo format', 'status' => 415];
        }

        return [
            'fullpath' => $fullpath,
            'mimetype' => self::$mimemap[$ext],
            'filesize' => filesize($fullpath),
        ];
    }
}
