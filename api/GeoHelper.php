<?php

/**
 * GeoHelper.php
 *
 * Shared primitive for SmartMaker business modules to persist GPS
 * coordinates (lat / lon, optional WKT point, geocoder result code) on
 * an ECM file row in llx_ecm_files. Companion to AnnotationsHelper.
 *
 * Storage: native columns on llx_ecm_files (geolat, geolong, geopoint,
 * georesultcode). These columns are part of core Dolibarr 23 schema; on
 * Dolibarr <= 22 modSmartauth::init() pre-creates them with the same
 * shape, except 'point' is stored as TEXT on SQLite (no spatial type).
 *
 * Typical usage in a module controller, after consumeUpload():
 *
 *   use SmartAuth\Api\GeoHelper;
 *
 *   GeoHelper::set($ecmFileId, $lat, $lon, $userId, 'OK');
 *
 *   $coords = GeoHelper::get($ecmFileId, $userId);
 *   // null if nothing stored / no permission
 *   // or ['lat' => 47.21, 'lon' => -1.55, 'point' => 'POINT(...)', 'resultcode' => 'OK']
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class GeoHelper
{
    /**
     * Whitelist regex for the geocoder result code. Aligned on the
     * Google Geocoding API status codes (OK, ZERO_RESULTS,
     * OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST, UNKNOWN_ERROR)
     * but kept open to any module-specific code that fits the regex.
     */
    const RESULTCODE_REGEX = '/^[A-Z][A-Z_]{0,15}$/';

    /**
     * Persist GPS coordinates on an ecmfile row. The WKT point column
     * is auto-generated from (lat, lon).
     *
     * @param int    $ecmFileId   Row id of llx_ecm_files
     * @param float  $lat         Latitude in [-90, 90]
     * @param float  $lon         Longitude in [-180, 180]
     * @param int    $userId      User performing the write (owner check)
     * @param string $resultCode  Optional geocoder status (e.g. 'OK')
     * @return bool               true on success, false on validation/IO failure
     */
    public static function set($ecmFileId, $lat, $lon, $userId, $resultCode = ''): bool
    {
        global $db;

        $ecmFileId = (int) $ecmFileId;
        $userId = (int) $userId;

        if ($ecmFileId <= 0 || $userId <= 0) {
            dol_syslog("SmartAuth GeoHelper::set - invalid ids ecmFileId=$ecmFileId userId=$userId", LOG_WARNING);
            return false;
        }

        $valid = self::validate([
            'lat' => $lat,
            'lon' => $lon,
            'resultcode' => $resultCode,
        ]);
        if ($valid === null) {
            // validate() already logged the precise reason.
            return false;
        }

        if (!self::ownerCheck($db, $ecmFileId, $userId, 'set')) {
            return false;
        }

        $wkt = sprintf('POINT(%F %F)', $valid['lon'], $valid['lat']);
        $latSql = self::sqlFloat($valid['lat']);
        $lonSql = self::sqlFloat($valid['lon']);
        $rcSql  = $valid['resultcode'] === ''
            ? 'NULL'
            : "'" . $db->escape($valid['resultcode']) . "'";

        // SQLite stores the WKT as TEXT directly; MySQL needs the spatial
        // type wrapped via ST_GeomFromText so the column gets a real point.
        if (isset($db->type) && $db->type === 'sqlite3') {
            $pointSql = "'" . $db->escape($wkt) . "'";
        } else {
            $pointSql = "ST_GeomFromText('" . $db->escape($wkt) . "')";
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files SET";
        $sql .= " geolat = " . $latSql . ",";
        $sql .= " geolong = " . $lonSql . ",";
        $sql .= " geopoint = " . $pointSql . ",";
        $sql .= " georesultcode = " . $rcSql;
        $sql .= " WHERE rowid = " . $ecmFileId;

        $res = $db->query($sql);
        if (!$res) {
            $err = method_exists($db, 'lasterror') ? $db->lasterror() : '';
            dol_syslog("SmartAuth GeoHelper::set - UPDATE failed on ecmfile $ecmFileId: $err", LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Read GPS coordinates stored on an ecmfile row.
     *
     * Returns null when the row is missing, the user is not the owner,
     * or no coordinates are set. Otherwise returns an associative array
     * with the four fields. `point` is the WKT string regardless of the
     * underlying column type.
     *
     * @param int $ecmFileId
     * @param int $userId
     * @return array|null
     */
    public static function get($ecmFileId, $userId)
    {
        global $db;

        $ecmFileId = (int) $ecmFileId;
        $userId = (int) $userId;

        if ($ecmFileId <= 0 || $userId <= 0) {
            dol_syslog("SmartAuth GeoHelper::get - invalid ids ecmFileId=$ecmFileId userId=$userId", LOG_WARNING);
            return null;
        }

        if (!self::ownerCheck($db, $ecmFileId, $userId, 'get')) {
            return null;
        }

        // On MySQL the spatial column needs ST_AsText() to come back as a
        // readable WKT string; SQLite stores it as TEXT already.
        $pointExpr = (isset($db->type) && $db->type === 'sqlite3')
            ? 'geopoint'
            : 'ST_AsText(geopoint) AS geopoint';

        $sql = "SELECT geolat, geolong, $pointExpr, georesultcode";
        $sql .= " FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE rowid = " . $ecmFileId;

        $res = $db->query($sql);
        if (!$res) {
            $err = method_exists($db, 'lasterror') ? $db->lasterror() : '';
            dol_syslog("SmartAuth GeoHelper::get - SELECT failed on ecmfile $ecmFileId: $err", LOG_ERR);
            return null;
        }

        $obj = $db->fetch_object($res);
        if (!$obj) {
            return null;
        }

        if ($obj->geolat === null || $obj->geolong === null) {
            return null;
        }

        return [
            'lat' => (float) $obj->geolat,
            'lon' => (float) $obj->geolong,
            'point' => $obj->geopoint !== null ? (string) $obj->geopoint : null,
            'resultcode' => $obj->georesultcode !== null ? (string) $obj->georesultcode : null,
        ];
    }

    /**
     * Reset the four geo columns back to NULL on an ecmfile row.
     *
     * @param int $ecmFileId
     * @param int $userId
     * @return bool
     */
    public static function clear($ecmFileId, $userId): bool
    {
        global $db;

        $ecmFileId = (int) $ecmFileId;
        $userId = (int) $userId;

        if ($ecmFileId <= 0 || $userId <= 0) {
            dol_syslog("SmartAuth GeoHelper::clear - invalid ids ecmFileId=$ecmFileId userId=$userId", LOG_WARNING);
            return false;
        }

        if (!self::ownerCheck($db, $ecmFileId, $userId, 'clear')) {
            return false;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files SET";
        $sql .= " geolat = NULL, geolong = NULL, geopoint = NULL, georesultcode = NULL";
        $sql .= " WHERE rowid = " . $ecmFileId;

        $res = $db->query($sql);
        if (!$res) {
            $err = method_exists($db, 'lasterror') ? $db->lasterror() : '';
            dol_syslog("SmartAuth GeoHelper::clear - UPDATE failed on ecmfile $ecmFileId: $err", LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Validate a raw input array. Pure function -- no DB / IO / globals.
     * Exposed for module controllers that want to pre-validate before
     * calling set(). Returns the sanitized array or null on rejection
     * (each rejection is logged via dol_syslog).
     *
     * Accepted keys:
     *   - lat: numeric in [-90, 90] (required)
     *   - lon: numeric in [-180, 180] (required)
     *   - resultcode: optional, regex /^[A-Z][A-Z_]{0,15}$/
     *
     * Latitude / longitude that fall outside the valid range are
     * rejected outright -- a coordinate that is out of range is always
     * a bug on the caller side, never a rounding artifact.
     *
     * @param array $raw
     * @return array|null
     */
    public static function validate(array $raw)
    {
        if (!array_key_exists('lat', $raw) || !array_key_exists('lon', $raw)) {
            dol_syslog("SmartAuth GeoHelper::validate - missing lat or lon", LOG_WARNING);
            return null;
        }
        if (!is_numeric($raw['lat']) || !is_numeric($raw['lon'])) {
            dol_syslog("SmartAuth GeoHelper::validate - lat or lon is not numeric", LOG_WARNING);
            return null;
        }
        $lat = (float) $raw['lat'];
        $lon = (float) $raw['lon'];

        if (!is_finite($lat) || !is_finite($lon)) {
            dol_syslog("SmartAuth GeoHelper::validate - non-finite lat or lon (lat=$lat lon=$lon)", LOG_WARNING);
            return null;
        }
        if ($lat < -90.0 || $lat > 90.0) {
            dol_syslog("SmartAuth GeoHelper::validate - lat $lat out of [-90, 90]", LOG_WARNING);
            return null;
        }
        if ($lon < -180.0 || $lon > 180.0) {
            dol_syslog("SmartAuth GeoHelper::validate - lon $lon out of [-180, 180]", LOG_WARNING);
            return null;
        }

        $rc = '';
        if (array_key_exists('resultcode', $raw) && $raw['resultcode'] !== null && $raw['resultcode'] !== '') {
            if (!is_string($raw['resultcode']) || !preg_match(self::RESULTCODE_REGEX, $raw['resultcode'])) {
                $disp = is_scalar($raw['resultcode']) ? (string) $raw['resultcode'] : gettype($raw['resultcode']);
                dol_syslog("SmartAuth GeoHelper::validate - invalid resultcode '$disp'", LOG_WARNING);
                return null;
            }
            $rc = $raw['resultcode'];
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'resultcode' => $rc,
        ];
    }

    /**
     * Verify the user owns the ecmfile row. Logs and returns false on
     * mismatch / missing row.
     *
     * @param \DoliDB $db
     * @param int     $ecmFileId
     * @param int     $userId
     * @param string  $op  Short label for logs (set/get/clear)
     * @return bool
     */
    private static function ownerCheck($db, $ecmFileId, $userId, $op): bool
    {
        $sql = "SELECT fk_user_c FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE rowid = " . (int) $ecmFileId;

        $res = $db->query($sql);
        if (!$res) {
            $err = method_exists($db, 'lasterror') ? $db->lasterror() : '';
            dol_syslog("SmartAuth GeoHelper::$op - owner check SELECT failed on ecmfile $ecmFileId: $err", LOG_ERR);
            return false;
        }
        $obj = $db->fetch_object($res);
        if (!$obj) {
            dol_syslog("SmartAuth GeoHelper::$op - ecmfile $ecmFileId not found", LOG_WARNING);
            return false;
        }
        if ((int) $obj->fk_user_c !== (int) $userId) {
            dol_syslog("SmartAuth GeoHelper::$op - owner mismatch on ecmfile $ecmFileId (fk_user_c={$obj->fk_user_c}, userId=$userId)", LOG_WARNING);
            return false;
        }
        return true;
    }

    /**
     * Format a finite float for inline SQL embedding without locale
     * surprises (some locales use a comma as decimal separator).
     *
     * @param float $v
     * @return string
     */
    private static function sqlFloat(float $v): string
    {
        return rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
    }
}
