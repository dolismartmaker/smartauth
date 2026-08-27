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
 * Per-class dispatcher for the workflow state transitions of the core Dolibarr
 * document classes (Commande / Propal / Facture).
 *
 * Like DocumentLineInvoker, this cannot be reflection-generic: the methods have
 * different names for the same intent (Commande::valid vs Facture::validate),
 * some skip $user (Commande::cancel), and closing means different things per
 * type (Commande::cloture, Propal::closeProposal(status), Facture::setPaid/
 * setCanceled). Each (class, action) pair is spelled out here.
 *
 * Signatures verified against the vendored Dolibarr; keep in sync on upgrade.
 */
class DocumentActionInvoker
{
    /** Propal::STATUS_SIGNED. */
    private const PROPAL_STATUS_SIGNED = 2;

    /** Propal::STATUS_NOTSIGNED. */
    private const PROPAL_STATUS_NOTSIGNED = 3;

    /**
     * @return array<int,string>
     */
    private static function supportedClasses()
    {
        return [
            'Commande', 'Facture', 'Propal',
            'CommandeFournisseur', 'FactureFournisseur', 'SupplierProposal',
            'Expedition', 'Reception', 'Contrat',
        ];
    }

    /**
     * Normalize a Dolibarr "already in that state" no-op to a success code.
     *
     * Expedition::setClosed/reOpen/setDraft and Reception::setClosed/setDraft
     * return 0 (not >0) when the document ALREADY is in the requested state --
     * a guard, not an error. The caller treats <=0 as a failure, which would
     * turn an idempotent transition into a 400 with an empty message. The
     * module screens (expedition/card.php, reception/card.php) accept it, so we
     * do the same, per (class, action) pair -- never globally, because 0 IS a
     * failure code on other methods.
     *
     * @param  int $res
     * @return int
     */
    private static function zeroIsNoop($res)
    {
        return $res === 0 ? 1 : $res;
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
     * Run a workflow $action on $object. Returns Dolibarr's result (>0 success,
     * <=0 failure), or the sentinel self::UNKNOWN when the (class, action) pair
     * is not implemented (the caller turns that into a 400).
     *
     * @param  object $object  Fetched Commande/Facture/Propal.
     * @param  object $user    Current Dolibarr user.
     * @param  string $action  Normalized action name (lowercase).
     * @param  array  $params  Optional extra inputs (note, close_code, close_note).
     * @return int
     */
    public static function run($object, $user, $action, array $params = [])
    {
        $class = self::baseClass($object);
        if ($class === '') {
            return self::UNKNOWN;
        }

        $note = isset($params['note']) ? (string) $params['note'] : '';
        $closeCode = isset($params['close_code']) ? (string) $params['close_code'] : '';
        $closeNote = isset($params['close_note']) ? (string) $params['close_note'] : '';

        switch ($class . ':' . $action) {
            // ----- Commande -----
            case 'Commande:validate':
                return (int) $object->valid($user);
            case 'Commande:setdraft':
                return (int) $object->setDraft($user);
            case 'Commande:classifybilled':
                return (int) $object->classifyBilled($user);
            case 'Commande:close':
                return (int) $object->cloture($user);
            case 'Commande:cancel':
                // cancel() takes no $user.
                return (int) $object->cancel();

            // ----- Propal -----
            case 'Propal:validate':
                return (int) $object->valid($user);
            case 'Propal:setdraft':
                return (int) $object->setDraft($user);
            case 'Propal:classifybilled':
                return (int) $object->classifyBilled($user);
            case 'Propal:closesign':
                return (int) $object->closeProposal($user, self::PROPAL_STATUS_SIGNED, $note);
            case 'Propal:closeunsign':
                return (int) $object->closeProposal($user, self::PROPAL_STATUS_NOTSIGNED, $note);

            // ----- Facture -----
            case 'Facture:validate':
                // Facture uses validate(), not valid().
                return (int) $object->validate($user);
            case 'Facture:setdraft':
                return (int) $object->setDraft($user);
            case 'Facture:setpaid':
                return (int) $object->setPaid($user, $closeCode, $closeNote);
            case 'Facture:setunpaid':
                return (int) $object->setUnpaid($user);
            case 'Facture:setcanceled':
                return (int) $object->setCanceled($user, $closeCode, $closeNote);

            // ----- CommandeFournisseur (supplier order) -----
            case 'CommandeFournisseur:validate':
                return (int) $object->valid($user);
            case 'CommandeFournisseur:approve':
                return (int) $object->approve($user);
            case 'CommandeFournisseur:cancel':
                // Method is spelled Cancel($user) on this class.
                return (int) $object->Cancel($user);

            // ----- FactureFournisseur (supplier invoice) -----
            case 'FactureFournisseur:validate':
                return (int) $object->validate($user);
            case 'FactureFournisseur:setdraft':
                return (int) $object->setDraft($user);
            case 'FactureFournisseur:setpaid':
                return (int) $object->setPaid($user, $closeCode, $closeNote);
            case 'FactureFournisseur:setunpaid':
                return (int) $object->setUnpaid($user);
            case 'FactureFournisseur:setcanceled':
                return (int) $object->setCanceled($user, $closeCode, $closeNote);

            // ----- SupplierProposal -----
            case 'SupplierProposal:validate':
                return (int) $object->valid($user);
            case 'SupplierProposal:setdraft':
                return (int) $object->setDraft($user);
            case 'SupplierProposal:closesign':
                // cloture($user, status, note); STATUS_SIGNED = 2.
                return (int) $object->cloture($user, self::PROPAL_STATUS_SIGNED, $note);
            case 'SupplierProposal:closeunsign':
                // STATUS_NOTSIGNED = 3.
                return (int) $object->cloture($user, self::PROPAL_STATUS_NOTSIGNED, $note);

            // ----- Expedition (customer shipment) -----
            // Stock is moved by valid()/setClosed()/cancel() themselves,
            // according to STOCK_CALCULATE_ON_SHIPMENT[_CLOSE]: never here.
            case 'Expedition:validate':
                return (int) $object->valid($user);
            case 'Expedition:close':
                return self::zeroIsNoop((int) $object->setClosed());
            case 'Expedition:reopen':
                // reOpen() takes no $user (it reads the global).
                return self::zeroIsNoop((int) $object->reOpen());
            case 'Expedition:setdraft':
                return self::zeroIsNoop((int) $object->setDraft($user));
            case 'Expedition:cancel':
                // cancel() takes no $user; refused by Dolibarr when a delivery
                // receipt is linked.
                return (int) $object->cancel();

            // ----- Reception (supplier reception) -----
            // No cancel(): the Reception class does not implement one.
            case 'Reception:validate':
                return (int) $object->valid($user);
            case 'Reception:close':
                return self::zeroIsNoop((int) $object->setClosed());
            case 'Reception:reopen':
                return self::zeroIsNoop((int) $object->reOpen());
            case 'Reception:setdraft':
                return self::zeroIsNoop((int) $object->setDraft($user));

            // ----- Contrat -----
            // No setdraft: Contrat has no such transition. 'close' is closeAll(),
            // which walks every line through close_line() -- so it is the whole
            // contract that ends, not one service. Closing a single line is a
            // LINE action (DocumentLineActionInvoker).
            case 'Contrat:validate':
                return (int) $object->validate($user);
            case 'Contrat:close':
                // closeAll($user, $notrigger, $comment).
                return (int) $object->closeAll($user, 0, $closeNote);
        }

        return self::UNKNOWN;
    }

    /**
     * Sentinel returned by run() for an unimplemented (class, action) pair.
     * Distinct from Dolibarr's own <=0 failure codes.
     */
    const UNKNOWN = -9999;
}
