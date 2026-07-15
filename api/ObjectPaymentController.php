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
 * Generic REST facade for recording and listing PAYMENTS against a core
 * Dolibarr invoice, driven by the {objtype} route segment.
 *
 * Routes:
 *   GET  objects/{objtype}/{id}/payments   -> index  (list payments)
 *   POST objects/{objtype}/{id}/payments   -> store  (record a payment, 201)
 *
 * Only types whose registry config carries a 'payment' block (class/file) are
 * accepted; today that is the customer invoice (Paiement). Supplier documents
 * (PaiementFourn) plug in the same way in a later wave.
 *
 * Contract (store):
 *   - body: amount (required, non-zero float), payment_mode (required, id from
 *     llx_c_paiement), fk_account (optional bank account id), payment_date
 *     (optional, defaults to now), ref (optional cheque/transfer label), note
 *     (optional private note).
 *   - Paiement::create($user, 1) is used so Dolibarr flips the invoice 'paye'
 *     flag when the running total reaches total_ttc, matching the standard
 *     payment card. Mirrors the Dolipocket reference implementation; like it,
 *     this does not post a bank-ledger line (addPaymentToBank) -- fk_account is
 *     only recorded on the payment row.
 *   - gated by the type's 'update' right and entity scope, via ObjectFacadeTrait.
 */
class ObjectPaymentController
{
    use ObjectFacadeTrait;
    use PaginatedListTrait;

    /**
     * Resolve type + mapper + fetched invoice, enforcing payment support,
     * permission and entity scope.
     *
     * @param  array|null $payload
     * @param  string     $action  read|update
     * @return array{0:?array,1:?object,2:?object,3:?array}  [cfg, mapper, invoice, errorTuple]
     */
    private function resolveInvoice($payload, $action)
    {
        global $db;

        list($cfg, $mapper, $err) = $this->resolve($payload);
        if ($err !== null) {
            return [null, null, null, $err];
        }

        if (empty($cfg['payment']) || !is_array($cfg['payment'])) {
            dol_syslog("[SmartAuth] ObjectPaymentController: type '" . ($cfg['object_type'] ?? '?') . "' has no payment support", LOG_WARNING);
            return [null, null, null, [['error' => 'This object type has no payment support'], 400]];
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
        if (!$this->inEntityScope($o, $cfg)) {
            dol_syslog("[SmartAuth] ObjectPaymentController: cross-entity access refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [null, null, null, [['error' => 'Access denied (entity)'], 403]];
        }

        return [$cfg, $mapper, $o, null];
    }

    /**
     * GET objects/{objtype}/{id}/payments -- list payments recorded on the invoice.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function index($payload = null)
    {
        list($cfg, $mapper, $o, $err) = $this->resolveInvoice($payload, 'read');
        if ($err !== null) {
            return $err;
        }

        $payments = [];
        if (method_exists($o, 'getListOfPayments')) {
            $raw = $o->getListOfPayments();
            if (is_array($raw)) {
                $payments = $raw;
            }
        }

        $totalPaid = method_exists($o, 'getSommePaiement') ? (float) $o->getSommePaiement() : 0.0;
        $totalTtc = (float) ($o->total_ttc ?? 0);

        return [[
            'payments'      => $payments,
            'total_paid'    => $totalPaid,
            'total_incl_tax' => $totalTtc,
            'remain_to_pay' => $totalTtc - $totalPaid,
        ], 200];
    }

    /**
     * POST objects/{objtype}/{id}/payments -- record a payment.
     *
     * @param  array|null $payload
     * @return array  [body, httpCode]
     */
    public function store($payload = null)
    {
        global $db, $user, $conf;

        list($cfg, $mapper, $o, $err) = $this->resolveInvoice($payload, 'update');
        if ($err !== null) {
            return $err;
        }

        // amount: required, numeric, non-zero.
        if (!isset($payload['amount']) || $payload['amount'] === '' || $payload['amount'] === null || !is_numeric($payload['amount'])) {
            dol_syslog("[SmartAuth] ObjectPaymentController::store missing/invalid amount for invoice " . ((int) $o->id), LOG_WARNING);
            return [['error' => 'amount is required and must be a number'], 400];
        }
        $amount = (float) $payload['amount'];
        if ($amount == 0.0) {
            return [['error' => 'amount must be non-zero'], 400];
        }

        // payment_mode: required id from llx_c_paiement.
        $paymentMode = isset($payload['payment_mode']) ? (int) $payload['payment_mode'] : 0;
        if ($paymentMode <= 0) {
            dol_syslog("[SmartAuth] ObjectPaymentController::store missing payment_mode for invoice " . ((int) $o->id), LOG_WARNING);
            return [['error' => 'payment_mode is required'], 400];
        }

        $paymentClass = (string) ($cfg['payment']['class'] ?? '');
        if (!empty($cfg['payment']['file'])) {
            require_once $cfg['payment']['file'];
        }
        if ($paymentClass === '' || !class_exists($paymentClass)) {
            dol_syslog("[SmartAuth] ObjectPaymentController::store payment class unavailable for type " . ($cfg['object_type'] ?? '?'), LOG_ERR);
            return [['error' => 'Payment class unavailable'], 500];
        }

        $id = (int) $o->id;
        $paymentDate = self::normalizeTimestamp($payload['payment_date'] ?? null);
        if ($paymentDate === null) {
            $paymentDate = dol_now();
        }
        $currency = (string) ($conf->currency ?? 'EUR');

        $payment = new $paymentClass($db);
        $payment->datepaye = $paymentDate;
        $payment->paiementid = $paymentMode;
        $payment->amounts = [$id => $amount];
        // Paiement::create iterates multicurrency_amounts even in mono-currency.
        $payment->multicurrency_amounts = [$id => $amount];
        $payment->multicurrency_code = [$id => $currency];
        $payment->multicurrency_tx = [$id => 1.0];
        $payment->num_payment = isset($payload['ref']) ? (string) $payload['ref'] : '';
        $payment->note_private = isset($payload['note']) ? (string) $payload['note'] : '';
        $payment->note = $payment->note_private;
        $fkAccount = isset($payload['fk_account']) ? (int) $payload['fk_account'] : 0;
        if ($fkAccount > 0) {
            $payment->fk_account = $fkAccount;
        }

        // $closepaidinvoices=1: core flips llx_facture.paye once the running
        // total reaches total_ttc (same behaviour as the standard payment card).
        $res = $payment->create($user, 1);
        if ($res <= 0) {
            $errMsg = ($payment->error !== '' && $payment->error !== null) ? $payment->error : 'Failed to create payment';
            dol_syslog("[SmartAuth] ObjectPaymentController::store create() failed for invoice " . $id . ": " . $errMsg, LOG_ERR);
            return [['error' => 'Failed to create payment: ' . $errMsg], 400];
        }
        $paymentId = (int) $res;

        $o->fetch($id);
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        $totalPaid = method_exists($o, 'getSommePaiement') ? (float) $o->getSommePaiement() : 0.0;
        $totalTtc = (float) ($o->total_ttc ?? 0);

        return [[
            'payment_id'    => $paymentId,
            'invoice_id'    => $id,
            'amount'        => $amount,
            'total_paid'    => $totalPaid,
            'remain_to_pay' => $totalTtc - $totalPaid,
            'paye'          => (int) ($o->paye ?? 0),
            'invoice'       => $mapper->exportMappedData($o),
        ], 201];
    }
}
