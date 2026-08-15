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
 *     llx_c_paiement), fk_account (bank account id -- REQUIRED whenever the
 *     bank module is enabled), payment_date (optional, defaults to now), ref
 *     (optional cheque/transfer number), note (optional private note),
 *     cheque_issuer and cheque_bank (the first is required for a cheque).
 *   - Paiement::create($user, 1) is used so Dolibarr flips the invoice 'paye'
 *     flag when the running total reaches total_ttc, matching the standard
 *     payment card.
 *   - When the bank module is enabled, addPaymentToBank() then posts the
 *     matching line in llx_bank, inside the same transaction. Skipping it used
 *     to lose fk_account outright -- llx_paiement has no such column, the
 *     property is read back through a JOIN on llx_bank -- so a tenant's bank
 *     balance never moved. The 'bank_mode' of the registry decides the sign:
 *     money in for a customer payment, out for a supplier one.
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
        // Refusals answer 404, exactly like an unknown id: a distinct 403 would
        // be an existence oracle (enumerate rowids, tell "exists in another
        // tenant" apart from "does not exist"). The syslog lines keep the real
        // reason server-side.
        if (!$this->inEntityScope($o, $cfg, $this->entityScopeMode($action))) {
            dol_syslog("[SmartAuth] ObjectPaymentController: cross-entity access refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [null, null, null, [['error' => 'Object not found'], 404]];
        }
        // Types whose table has no entity column are scoped by this probe ONLY
        // (inEntityScope short-circuits to true for them).
        if ($this->isolationDenies($cfg, $mapper, $id)) {
            dol_syslog("[SmartAuth] ObjectPaymentController: isolation refused for " . ($cfg['object_type'] ?? '?') . " id=" . $id, LOG_WARNING);
            return [null, null, null, [['error' => 'Object not found'], 404]];
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
        // The payment mode CODE ('CHQ', 'VIR', ...), not just its id.
        // addPaymentToBank falls back to the numeric id when the code is
        // missing, and Account::addline() then resolves it through a SELECT
        // whose failure branch calls dol_print_error() -- which writes to the
        // output stream and corrupts the JSON response. Resolve it here, as
        // api_invoices.class.php l.1597 and api_supplier_invoices.class.php
        // l.479 both do.
        //
        // WITHOUT the entity filter those two pass as their 6th argument, and
        // that difference matters on a multi-tenant install. llx_c_paiement is
        // a dictionary SHIPPED by the installer: its rows carry no explicit
        // entity, so they all take the column default of 1. A tenant running on
        // entity 5 filtering on its own entity therefore resolves NOTHING, the
        // code comes back empty, the numeric id reaches Account::addline(), and
        // its failure branch prints into the response. The dictionary is
        // identical for everyone and is addressed by id from a dozen document
        // tables (fk_mode_reglement, fk_paiement): it is global reference data,
        // not tenant data.
        $payment->paiementcode = dol_getIdFromCode($db, $paymentMode, 'c_paiement', 'id', 'code', 0);
        if ((string) $payment->paiementcode === '') {
            dol_syslog("[SmartAuth] ObjectPaymentController::store unknown payment_mode " . $paymentMode, LOG_WARNING);
            return [['error' => 'Unknown payment_mode'], 400];
        }

        $fkAccount = isset($payload['fk_account']) ? (int) $payload['fk_account'] : 0;
        $bankEnabled = isModEnabled('banque');
        // Same tenant guard as the foreign keys of ObjectController: the invoice
        // is scoped, the account id sent along with it was NOT. Without this,
        // cashing a local invoice on another tenant's account was accepted and
        // addPaymentToBank() below wrote a real llx_bank line into their ledger.
        // 404 like every other scope refusal of the facade, never 403.
        if ($fkAccount > 0 && $this->foreignKeyTargetDenies('bank_account', $fkAccount, $cfg, 'fk_account')) {
            dol_syslog("[SmartAuth] ObjectPaymentController::store cross-tenant fk_account " . $fkAccount . " refused for invoice " . $id, LOG_WARNING);
            return [['error' => 'Object not found'], 404];
        }
        if ($fkAccount > 0) {
            $payment->fk_account = $fkAccount;
        }

        // A bank account is REQUIRED once the bank module is on. Until this
        // check existed, fk_account was accepted, assigned to the object, and
        // then silently dropped: llx_paiement has no fk_account column (the
        // property is read back through a JOIN on llx_bank), so without the
        // addPaymentToBank call below the chosen account was lost and no bank
        // ledger line was ever written. Refusing early is what the core's own
        // REST API does in effect -- it calls addPaymentToBank unconditionally,
        // and that method returns -1 on a missing account id.
        if ($bankEnabled && $fkAccount <= 0) {
            dol_syslog("[SmartAuth] ObjectPaymentController::store missing fk_account for invoice " . ((int) $o->id) . " while module banque is enabled", LOG_WARNING);
            return [['error' => 'fk_account is required when the bank module is enabled'], 400];
        }
        // Mirrors the core API: the issuer identifies the cheque in the ledger.
        $chqEmetteur = isset($payload['cheque_issuer']) ? (string) $payload['cheque_issuer'] : '';
        $chqBank = isset($payload['cheque_bank']) ? (string) $payload['cheque_bank'] : '';
        if ($bankEnabled && $payment->paiementcode === 'CHQ' && $chqEmetteur === '') {
            dol_syslog("[SmartAuth] ObjectPaymentController::store missing cheque_issuer for invoice " . ((int) $o->id), LOG_WARNING);
            return [['error' => 'cheque_issuer is required when the payment mode is a cheque'], 400];
        }

        // One transaction around both writes: a payment without its bank line
        // is a silent accounting hole, so a failure on the second must undo the
        // first (same begin/rollback envelope as api_invoices.class.php).
        $db->begin();

        // $closepaidinvoices=1: core flips llx_facture.paye once the running
        // total reaches total_ttc (same behaviour as the standard payment card).
        $res = $payment->create($user, 1);
        if ($res <= 0) {
            $db->rollback();
            $errMsg = ($payment->error !== '' && $payment->error !== null) ? $payment->error : 'Failed to create payment';
            dol_syslog("[SmartAuth] ObjectPaymentController::store create() failed for invoice " . $id . ": " . $errMsg, LOG_ERR);
            return [['error' => 'Failed to create payment: ' . $errMsg], 400];
        }
        $paymentId = (int) $res;

        $bankLineId = 0;
        if ($bankEnabled) {
            $bankMode = (string) ($cfg['payment']['bank_mode'] ?? 'payment');
            $bankLabel = (string) ($cfg['payment']['bank_label'] ?? '(CustomerInvoicePayment)');
            // A payment on a credit note is a refund: the core swaps the label.
            // Facture::TYPE_CREDIT_NOTE is 2; compare the value rather than the
            // constant so the branch also holds for a supplier document, whose
            // own credit-note type shares the same numbering.
            if (!empty($cfg['payment']['bank_label_credit_note']) && (int) ($o->type ?? 0) === 2) {
                $bankLabel = (string) $cfg['payment']['bank_label_credit_note'];
            }

            $bankLineId = (int) $payment->addPaymentToBank($user, $bankMode, $bankLabel, $fkAccount, $chqEmetteur, $chqBank);
            if ($bankLineId <= 0) {
                $db->rollback();
                $errMsg = ($payment->error !== '' && $payment->error !== null) ? $payment->error : 'Failed to write the bank ledger line';
                dol_syslog("[SmartAuth] ObjectPaymentController::store addPaymentToBank() failed for payment " . $paymentId . " on invoice " . $id . ": " . $errMsg, LOG_ERR);
                return [['error' => 'Failed to write the bank ledger line: ' . $errMsg], 400];
            }
        }

        $db->commit();

        $o->fetch($id);
        if (method_exists($o, 'fetch_lines')) {
            $o->fetch_lines();
        }
        $totalPaid = method_exists($o, 'getSommePaiement') ? (float) $o->getSommePaiement() : 0.0;
        $totalTtc = (float) ($o->total_ttc ?? 0);

        return [[
            'payment_id'    => $paymentId,
            'bank_line_id'  => $bankLineId,
            'invoice_id'    => $id,
            'amount'        => $amount,
            'total_paid'    => $totalPaid,
            'remain_to_pay' => $totalTtc - $totalPaid,
            'paye'          => (int) ($o->paye ?? 0),
            'invoice'       => $mapper->exportMappedData($o),
        ], 201];
    }
}
