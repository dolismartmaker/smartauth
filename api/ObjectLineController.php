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
 *   POST   objects/{objtype}/{id}/lines/{lineid}/actions/{action}
 *                                                  -> invokeAction (line workflow)
 *
 * Contract:
 *   - only types whose registry config carries supports_lines=true are accepted,
 *     and a line action additionally requires the type to list it in
 *     'line_actions' (default-deny, like the document-level 'actions').
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
                // Dropped on purpose, and said out loud: these are fields the
                // READ export publishes, so a client that echoes back a line it
                // just received sends them without meaning to. Silence here
                // would read as "accepted". Typical case: the contract line
                // status and its real dates, which only active_line() and
                // close_line() may write.
                dol_syslog(
                    "[SmartAuth] ObjectLineController: read-only line field '" . $apiKey
                    . "' (Dolibarr '" . $doliField . "') ignored on write",
                    LOG_NOTICE
                );
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
     * Tenant guard on the product a line points at.
     *
     * The document itself is scoped by resolveDocument(); the fk_product the
     * payload carries was not. This one is worse than a wrong reference: it is
     * a DISCLOSURE. hydrateFromProduct() below copies the target's description,
     * label, PRICE and VAT rate onto the line, and the route answers with that
     * line -- so a caller could read another tenant's price list one rowid at a
     * time. Product::fetch() cannot stop it: like Facture, Propal and Commande,
     * it drops the entity clause as soon as it is given a rowid.
     *
     * Same rules as everywhere else in the facade (ForeignKeyGuardTrait): the
     * value is narrowed to the integer that was probed, a value <= 0 is not a
     * violation (no product = a free-text line, which is legitimate), and the
     * refusal is a 404, never a 403.
     *
     * @param  array $d    Normalized line payload (mutated: fk_product narrowed).
     * @param  array $cfg  Registry config of the DOCUMENT, for the log line.
     * @return array|null  An error [body,code] tuple, or null when allowed.
     */
    private function productGuardError(array &$d, $cfg)
    {
        if (!array_key_exists('fk_product', $d) || $d['fk_product'] === null) {
            return null;
        }

        $fkProduct = (int) $d['fk_product'];
        $d['fk_product'] = $fkProduct;
        if ($fkProduct <= 0) {
            return null;
        }

        if ($this->foreignKeyTargetDenies('product', $fkProduct, $cfg, 'fk_product')) {
            dol_syslog(
                "[SmartAuth] ObjectLineController: cross-tenant product " . $fkProduct
                . " refused on " . ($cfg['object_type'] ?? '?') . " line",
                LOG_WARNING
            );
            return [['error' => 'Object not found'], 404];
        }

        return null;
    }

    /**
     * Fill missing pricing/description fields from the linked product, mirroring
     * the Dolibarr line-creation form. Only sets keys absent from $d.
     *
     * PRECONDITION: productGuardError() has already vetted $d['fk_product'].
     * This method reads the target and copies its data onto the line, so it must
     * never run on a product of another tenant.
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
        // BEFORE hydration: hydrateFromProduct() copies the target's label and
        // price onto the line, so a foreign product must be refused before it
        // is ever read, not after.
        $productErr = $this->productGuardError($d, $cfg);
        if ($productErr !== null) {
            return $productErr;
        }
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
        // Guard the CLIENT-SENT product before the merge below, so the check
        // applies to what the caller asked for and never to a value the line
        // already carried (which was guarded when it was written).
        $productErr = $this->productGuardError($d, $cfg);
        if ($productErr !== null) {
            return $productErr;
        }
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

    /**
     * POST objects/{objtype}/{id}/lines/{lineid}/actions/{action} -- run a
     * workflow transition on ONE line.
     *
     * Distinct from the document actions of ObjectActionController: what opens
     * and closes a contract line ("this rental starts", "this one is
     * terminated") carries the business meaning, writes the status and the real
     * dates, and fires the LINECONTRACT_* triggers. A PATCH of line fields does
     * not replace it -- and is refused on those fields for exactly that reason.
     *
     * Gated by the type's 'update' right and the entity scope, like every other
     * line mutation: acting on a line is editing the document.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function invokeAction($payload = null)
    {
        global $user;

        list($cfg, $mapper, $o, $err) = $this->resolveDocument($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        // Default-deny, same contract as the document actions: a type that
        // declares no line_actions has none, whatever the invoker could do.
        $allowed = (isset($cfg['line_actions']) && is_array($cfg['line_actions'])) ? $cfg['line_actions'] : [];
        if (empty($allowed)) {
            dol_syslog("[SmartAuth] ObjectLineController: type '" . ($cfg['object_type'] ?? '?') . "' declares no line actions", LOG_WARNING);
            return [['error' => 'This object type has no line actions'], 400];
        }

        $action = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['action'] ?? '')));
        if ($action === '') {
            return [['error' => 'Action is required'], 400];
        }
        if (!in_array($action, $allowed, true)) {
            dol_syslog("[SmartAuth] ObjectLineController: line action '" . $action . "' not allowed for type '" . ($cfg['object_type'] ?? '?') . "'", LOG_WARNING);
            return [['error' => "Unsupported line action '" . $action . "' for this object type"], 400];
        }

        $lineId = (int) ($payload['lineid'] ?? 0);
        if ($lineId <= 0) {
            return [['error' => 'Line id is required'], 400];
        }

        // Loading the lines is BOTH the ownership check (a line id belonging to
        // another document is a 404, not a silent no-op) and a precondition of
        // the invoker: Contrat::active_line/close_line reach their line through
        // the lines_id_index_mapper that fetch_lines() builds.
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        if ($this->findLine($o, $lineId) === null) {
            return [['error' => 'Line not found'], 404];
        }

        if (!DocumentLineActionInvoker::supports($o)) {
            dol_syslog("[SmartAuth] ObjectLineController: DocumentLineActionInvoker cannot drive line actions on " . get_class($o), LOG_ERR);
            return [['error' => 'Line actions not supported for this object'], 400];
        }

        $params = is_array($payload) ? $payload : [];
        $res = DocumentLineActionInvoker::run($o, $user, $lineId, $action, $params);

        if ($res === DocumentLineActionInvoker::UNKNOWN) {
            dol_syslog("[SmartAuth] ObjectLineController: no invoker mapping for " . get_class($o) . " line action " . $action, LOG_ERR);
            return [['error' => "Line action '" . $action . "' is not implemented for this object"], 400];
        }
        if ($res <= 0) {
            dol_syslog("[SmartAuth] ObjectLineController::invokeAction '" . $action . "' failed for " . ($cfg['object_type'] ?? '?') . " line=" . $lineId . ": " . $o->error, LOG_ERR);
            return [['error' => "Failed to run line action '" . $action . "': " . $o->error], 400];
        }

        return [$this->exportWithLines($o, $mapper), 200];
    }
}
