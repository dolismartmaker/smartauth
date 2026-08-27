<?php

/**
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
 */

namespace SmartAuth\Api;

/**
 * Per-class dispatcher for the workflow transitions of a single document LINE.
 *
 * Twin of DocumentActionInvoker, kept separate because the scope is different:
 * there the subject is the document, here it is one of its lines, and the two
 * vocabularies would collide on the same verbs ('close' ends a whole contract on
 * one side, one rented item on the other). One class per scope keeps each
 * (class, action) table readable and makes the route that reaches it obvious.
 *
 * Only Contrat has such actions today: a contract line carries its own status
 * (0 inactive, 4 active, 5 closed) and its own real dates, both written by
 * active_line()/close_line() alone -- they fire the LINECONTRACT_ACTIVATE /
 * LINECONTRACT_CLOSE triggers, which a PATCH on the line fields would skip,
 * leaving an "open" line no automation ever heard about.
 *
 * Signatures verified against the vendored Dolibarr; keep in sync on upgrade.
 */
class DocumentLineActionInvoker
{
    /**
     * Sentinel returned by run() for an unimplemented (class, action) pair.
     * Distinct from Dolibarr's own <=0 failure codes.
     */
    const UNKNOWN = -9999;

    /**
     * Keep the planned end date of a contract line untouched on activation.
     *
     * ContratLigne::active_line() rewrites date_fin_validite whenever its
     * $date_end argument compares >= 0, and only skips the column for a strictly
     * negative one (the class docblock spells out "-1 to keep it unchanged").
     * An empty string would not do: '' >= 0 is TRUE in PHP 7 and FALSE in PHP 8,
     * so the planned end date would survive or vanish depending on the runtime.
     */
    const KEEP_DATE = -1;

    /**
     * The document classes whose LINE actions this adapter knows how to drive.
     *
     * @return array<int,string>
     */
    public static function supportedClasses()
    {
        return ['Contrat'];
    }

    /**
     * Resolve $object to one of the supported base classes, or '' if none.
     *
     * @param  object $object
     * @return string
     */
    private static function baseClass($object)
    {
        foreach (self::supportedClasses() as $cls) {
            $fqcn = '\\' . $cls;
            if ($object instanceof $fqcn) {
                return $cls;
            }
        }
        return '';
    }

    /**
     * Whether this adapter can drive line actions on $object.
     *
     * @param  object $object
     * @return bool
     */
    public static function supports($object)
    {
        return is_object($object) && self::baseClass($object) !== '';
    }

    /**
     * Read an optional date from the action body, defaulting to now.
     *
     * A transition dates itself: "this rental starts" means it starts now unless
     * the caller backdates it explicitly. Accepts a Unix timestamp or an ISO
     * date, like every other date the facade takes in.
     *
     * @param  array  $params
     * @param  string $key
     * @return int    Unix seconds.
     */
    private static function dateOrNow(array $params, $key)
    {
        if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return (int) dol_now();
        }

        $raw = $params[$key];
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        $ts = strtotime((string) $raw);
        if ($ts === false) {
            dol_syslog("[SmartAuth] DocumentLineActionInvoker: unparsable " . $key . " '" . (string) $raw . "', falling back to now", LOG_WARNING);
            return (int) dol_now();
        }
        return (int) $ts;
    }

    /**
     * Run a line $action. Returns Dolibarr's result (>0 success, <=0 failure),
     * or self::UNKNOWN when the (class, action) pair is not implemented.
     *
     * PRECONDITION, and it is not optional: $object must have been fetched AND
     * its lines loaded, because Contrat::active_line/close_line index
     * $this->lines through $this->lines_id_index_mapper, which fetch_lines()
     * builds. Called on a contract whose lines were never loaded, they would
     * dereference null. The caller (ObjectLineController) loads the lines and
     * checks the line belongs to the document before getting here.
     *
     * @param  object $object  Fetched Contrat, lines loaded.
     * @param  object $user    Current Dolibarr user.
     * @param  int    $lineId  Line already verified to belong to $object.
     * @param  string $action  Normalized action name (lowercase).
     * @param  array  $params  Optional body inputs (date_start_real,
     *                         date_end_planned, date_end_real, comment).
     * @return int
     */
    public static function run($object, $user, $lineId, $action, array $params = [])
    {
        $class = self::baseClass($object);
        if ($class === '') {
            return self::UNKNOWN;
        }

        $lineId = (int) $lineId;
        $comment = isset($params['comment']) ? (string) $params['comment'] : '';

        switch ($class . ':' . $action) {
            case 'Contrat:activate':
                // active_line($user, $line_id, $date_start, $date_end, $comment)
                // where $date_start is the REAL opening date (it lands in
                // date_ouverture) and $date_end the PLANNED end date, which the
                // caller may revise on the way in -- or leave alone.
                $dateEnd = (array_key_exists('date_end_planned', $params) && $params['date_end_planned'] !== null && $params['date_end_planned'] !== '')
                    ? self::dateOrNow($params, 'date_end_planned')
                    : self::KEEP_DATE;
                return (int) $object->active_line(
                    $user,
                    $lineId,
                    self::dateOrNow($params, 'date_start_real'),
                    $dateEnd,
                    $comment
                );
            case 'Contrat:close':
                // close_line($user, $line_id, $date_end, $comment) -- $date_end
                // is the REAL end date (column date_cloture).
                return (int) $object->close_line(
                    $user,
                    $lineId,
                    self::dateOrNow($params, 'date_end_real'),
                    $comment
                );
        }

        return self::UNKNOWN;
    }
}
