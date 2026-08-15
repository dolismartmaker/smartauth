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
 * Generic REST facade for the LINES of core Dolibarr documents (order, invoice,
 * proposal), driven by the {objtype} route segment. One controller replaces the
 * per-module reimplementation of add/update/delete/reorder line handling.
 *
 * Routes:
 *   GET    objects/{objtype}/{id}/lines            -> index    (list lines)
 *   POST   objects/{objtype}/{id}/lines            -> store    (add a line, 201)
 *   PATCH  objects/{objtype}/{id}/lines/{lineid}   -> update   (update a line)
 *   DELETE objects/{objtype}/{id}/lines/{lineid}   -> destroy  (delete a line)
 *   POST   objects/{objtype}/{id}/lines/reorder    -> reorder  ({order:[ids]})
 *
 * Contract:
 *   - only types whose registry config carries supports_lines=true are accepted.
 *   - the divergent Dolibarr addline/updateline/deleteline signatures are
 *     absorbed by DocumentLineInvoker (per-class positional dispatch).
 *   - every mutation is gated by the type's 'update' right (a line change is a
 *     document edit) and by entity scope, via ObjectFacadeTrait.
 *   - line payloads use the SAME snake_case API keys the read export produces
 *     (e.g. quantity, unit_price_excl_tax, vat_rate, product), translated back
 *     to Dolibarr line fields through the mapper's line mapping.
 *   - the response is always the full re-exported document (with its lines), so
 *     the client gets recomputed totals in one round-trip.
 */
class ObjectLineController
{
    use ObjectFacadeTrait;
    use PaginatedListTrait;

    /**
     * Dolibarr line fields the facade allows writing. Default-deny: anything the
     * mapper maps but that is not listed here is ignored on write.
     *
     * @var array<int,string>
     */
    private const WRITABLE_LINE_FIELDS = [
        'desc', 'label', 'subprice', 'qty', 'tva_tx', 'fk_product',
        'remise_percent', 'product_type', 'rang', 'special_code',
        'date_start', 'date_end', 'fk_unit',
    ];

    /**
     * Resolve type + mapper + fetched document, enforcing line support,
     * permission ($action) and entity scope.
     *
     * @param  array|null $payload
     * @param  string     $action  read|create|update|delete
     * @return array{0:?array,1:?object,2:?object,3:?array}  [cfg, mapper, doc, errorTuple]
     */
    private function resolveDocument($payload, $action)
    {
        global $db;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return [null, null, null, $err];
        }

        if (empty($cfg['supports_lines'])) {
            dol_syslog("[SmartAuth] ObjectLineController: type '" . ($cfg['object_type'] ?? '?') . "' has no line support", LOG_WARNING);
            return [null, null, null, [['error' => 'This object type has no line support'], 400]];
        }

        $auth = $this->authorize($cfg, $action);
        if ($auth !== null) {
            return [null, null, null, $auth];
        }

        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return [null, null, null, [['error' => 'Object id is required'], 400]];
        }

        $classname = $cfg['class'];
        $o = new $classname($db);
        if ($o->fetch($id) <= 0) {
            return [null, null, null, [['error' => 'Object not found'], 404]];
        }
        // Refusals answer 404, exactly like an unknown id: a distinct 403 would
        // be an existence oracle (enumerate rowids, tell "exists in another
        // tenant" apart from "does not exist"). The syslog lines keep the real
        // reason server-side.
        if (!$this->inEntityScope($o, $cfg, $this->entityScopeMode($action))) {
            dol_syslog("[SmartAuth] ObjectLineController: cross-entity access refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [null, null, null, [['error' => 'Object not found'], 404]];
        }
        // Types whose table has no entity column are scoped by this probe ONLY
        // (inEntityScope short-circuits to true for them).
        if ($this->isolationDenies($cfg, $mapper, $id)) {
            dol_syslog("[SmartAuth] ObjectLineController: isolation refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [null, null, null, [['error' => 'Object not found'], 404]];
        }
        if (!DocumentLineInvoker::supports($o)) {
            dol_syslog("[SmartAuth] ObjectLineController: DocumentLineInvoker cannot drive lines on " . get_class($o), LOG_ERR);
            return [null, null, null, [['error' => 'Line operations not supported for this object'], 400]];
        }

        return [$cfg, $mapper, $o, null];
    }

    /**
     * Translate an incoming API line payload to Dolibarr line fields, restricted
     * to WRITABLE_LINE_FIELDS. Dates are normalized to Unix seconds.
     *
     * @param  array  $payload
     * @param  object $mapper
     * @return array<string,mixed>  Keyed by Dolibarr line field name.
     */
    private function normalizeLinePayload(array $payload, $mapper)
    {
        // Mapper mapping is Dolibarr => API; reverse it to look up by API key.
        $apiToDoli = [];
        foreach ($mapper->getLinesFieldMapping() as $doliside => $appside) {
            $apiToDoli[(string) $appside] = (string) $doliside;
        }

        $writable = array_flip(self::WRITABLE_LINE_FIELDS);
        $out = [];
        foreach ($payload as $apiKey => $value) {
            $apiKey = (string) $apiKey;
            if (!isset($apiToDoli[$apiKey])) {
                continue;
            }
            $doliField = $apiToDoli[$apiKey];
            if (!isset($writable[$doliField])) {
                continue;
            }
            if ($doliField === 'date_start' || $doliField === 'date_end') {
                $value = self::normalizeTimestamp($value);
            }
            $out[$doliField] = $value;
        }

        return $out;
    }

    /**
     * Fill missing pricing/description fields from the linked product, mirroring
     * the Dolibarr line-creation form. Only sets keys absent from $d.
     *
     * @param  array $d  Normalized line data (mutated).
     * @return void
     */
    private function hydrateFromProduct(array &$d)
    {
        global $db;

        $fkProduct = isset($d['fk_product']) ? (int) $d['fk_product'] : 0;
        if ($fkProduct <= 0) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new \Product($db);
        if ($prod->fetch($fkProduct) <= 0) {
            dol_syslog("[SmartAuth] ObjectLineController: product " . $fkProduct . " not found for line hydration", LOG_WARNING);
            return;
        }

        if (!isset($d['desc']) || $d['desc'] === '') {
            $d['desc'] = (string) $prod->description;
        }
        if (!isset($d['label']) || $d['label'] === '') {
            $d['label'] = (string) $prod->label;
        }
        if (!isset($d['subprice'])) {
            $d['subprice'] = (float) $prod->price;
        }
        if (!isset($d['tva_tx'])) {
            $d['tva_tx'] = (float) $prod->tva_tx;
        }
        if (!isset($d['product_type'])) {
            $d['product_type'] = (int) $prod->type;
        }
        if (!isset($d['fk_unit']) && (int) $prod->fk_unit > 0) {
            $d['fk_unit'] = (int) $prod->fk_unit;
        }
    }

    /**
     * Re-fetch the document with its lines and return the mapped export.
     *
     * @param  object $o
     * @param  object $mapper
     * @return object
     */
    private function exportWithLines($o, $mapper)
    {
        $o->fetch((int) $o->id);
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        if (method_exists($o, 'fetch_optionals')) {
            $o->fetch_optionals();
        }
        return $mapper->exportMappedData($o);
    }

    /**
     * Find a fetched line by its id among $o->lines.
     *
     * @param  object $o
     * @param  int    $lineId
     * @return object|null
     */
    private function findLine($o, $lineId)
    {
        if (!isset($o->lines) || !is_array($o->lines)) {
            return null;
        }
        foreach ($o->lines as $line) {
            $lid = (int) ($line->id ?? $line->rowid ?? 0);
            if ($lid === (int) $lineId) {
                return $line;
            }
        }
        return null;
    }

    /**
     * GET objects/{objtype}/{id}/lines -- list the document's lines.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function index($payload = null)
    {
        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'read');
        if ($err !== null) {
            return $err;
        }

        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        $export = $mapper->exportMappedData($o);
        $lines = (is_object($export) && isset($export->lines) && is_array($export->lines)) ? $export->lines : [];

        return [['lines' => $lines], 200];
    }

    /**
     * POST objects/{objtype}/{id}/lines -- add a line.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function store($payload = null)
    {
        global $user;

        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        $d = $this->normalizeLinePayload(is_array($payload) ? $payload : [], $mapper);
        $this->hydrateFromProduct($d);

        $newLineId = DocumentLineInvoker::add($o, $user, $d);
        if ($newLineId <= 0) {
            dol_syslog("[SmartAuth] ObjectLineController::store failed for " . ($cfg['object_type'] ?? '?') . " id=" . ((int) $o->id) . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to add line: ' . $o->error], 400];
        }

        return [$this->exportWithLines($o, $mapper), 201];
    }

    /**
     * PATCH objects/{objtype}/{id}/lines/{lineid} -- update a line.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function update($payload = null)
    {
        global $user;

        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        $lineId = (int) ($payload['lineid'] ?? 0);
        if ($lineId <= 0) {
            return [['error' => 'Line id is required'], 400];
        }

        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        $existing = $this->findLine($o, $lineId);
        if ($existing === null) {
            return [['error' => 'Line not found'], 404];
        }

        $d = $this->normalizeLinePayload(is_array($payload) ? $payload : [], $mapper);
        // Merge: any writable field the client did not send keeps the existing
        // line value (Dolibarr updateline() overwrites every column it receives).
        foreach (self::WRITABLE_LINE_FIELDS as $field) {
            if (!array_key_exists($field, $d) && isset($existing->$field)) {
                $d[$field] = $existing->$field;
            }
        }

        $res = DocumentLineInvoker::update($o, $user, $lineId, $d);
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectLineController::update failed for " . ($cfg['object_type'] ?? '?') . " line=" . $lineId . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to update line: ' . $o->error], 400];
        }

        return [$this->exportWithLines($o, $mapper), 200];
    }

    /**
     * DELETE objects/{objtype}/{id}/lines/{lineid} -- delete a line.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function destroy($payload = null)
    {
        global $user;

        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        $lineId = (int) ($payload['lineid'] ?? 0);
        if ($lineId <= 0) {
            return [['error' => 'Line id is required'], 400];
        }

        // Confirm the line belongs to this document before deleting, for a clean
        // 404 instead of a silent no-op if a foreign/unknown id is passed.
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        if ($this->findLine($o, $lineId) === null) {
            return [['error' => 'Line not found'], 404];
        }

        $res = DocumentLineInvoker::delete($o, $user, $lineId);
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectLineController::destroy failed for " . ($cfg['object_type'] ?? '?') . " line=" . $lineId . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to delete line: ' . $o->error], 400];
        }

        return [$this->exportWithLines($o, $mapper), 200];
    }

    /**
     * POST objects/{objtype}/{id}/lines/reorder -- reorder lines.
     * Body: { order: [lineId, ...] } -- a full, exact permutation of the current
     * line ids (same count, no foreign id, no duplicate).
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function reorder($payload = null)
    {
        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        $rawOrder = (is_array($payload) && isset($payload['order']) && is_array($payload['order'])) ? $payload['order'] : null;
        if ($rawOrder === null) {
            return [['error' => "Body must include an 'order' array of line ids"], 400];
        }

        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        $realIds = [];
        if (isset($o->lines) && is_array($o->lines)) {
            foreach ($o->lines as $line) {
                $realIds[] = (int) ($line->id ?? $line->rowid ?? 0);
            }
        }

        $order = [];
        foreach ($rawOrder as $rawId) {
            $order[] = (int) $rawId;
        }

        // Exact permutation guard. updateRangOfLine() filters by rowid alone (no
        // fk_element guard in Dolibarr), so this membership check is what stops a
        // tenant renumbering another document's lines.
        if (count($order) !== count($realIds)) {
            dol_syslog("[SmartAuth] ObjectLineController::reorder count mismatch for " . ($cfg['object_type'] ?? '?') . " id=" . ((int) $o->id), LOG_WARNING);
            return [['error' => 'order must list every current line exactly once (count mismatch)'], 422];
        }
        if (count(array_unique($order)) !== count($order)) {
            return [['error' => 'order must not contain duplicate line ids'], 422];
        }
        sort($order);
        sort($realIds);
        if ($order !== $realIds) {
            dol_syslog("[SmartAuth] ObjectLineController::reorder foreign/unknown line id for " . ($cfg['object_type'] ?? '?') . " id=" . ((int) $o->id), LOG_WARNING);
            return [['error' => 'order must be a permutation of the current line ids'], 422];
        }

        // Re-read the caller order (sort() above mutated $order for the guard).
        $orderedIds = [];
        foreach ($rawOrder as $rawId) {
            $orderedIds[] = (int) $rawId;
        }

        $res = $o->line_ajaxorder($orderedIds);
        if ($res === false || (is_int($res) && $res < 0)) {
            dol_syslog("[SmartAuth] ObjectLineController::reorder line_ajaxorder failed for " . ($cfg['object_type'] ?? '?') . " id=" . ((int) $o->id) . ": " . $o->error, LOG_ERR);
            return [['error' => 'Failed to reorder lines: ' . $o->error], 400];
        }

        return [$this->exportWithLines($o, $mapper), 200];
    }
}
