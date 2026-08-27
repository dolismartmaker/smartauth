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
 * Positional-argument adapter for the divergent document-line APIs of the core
 * Dolibarr document classes (Commande / Propal / Facture).
 *
 * Reflection cannot absorb these: the classes order addline()/updateline()
 * parameters differently (e.g. Propal::updateline puts $pu before $desc; Facture
 * places date_start/date_end right after remise_percent; Commande::deleteline
 * takes $user first while the two others do not; Contrat::addline and
 * ::updateline take no $user at all and Contrat::deleteline takes it last).
 * CrudInvoker handles the object level; line mutations need this explicit
 * per-class dispatch, exactly like the Dolipocket reference controllers do.
 *
 * Every method receives a NORMALIZED line array keyed by Dolibarr line field
 * names, already merged with existing values by the caller:
 *   desc, label, subprice, qty, tva_tx, fk_product, remise_percent,
 *   product_type, rang, special_code, date_start, date_end, fk_unit
 *
 * Signatures were verified against the vendored Dolibarr (see
 * commande/propal/facture class files); keep them in sync if the pinned version
 * changes.
 */
class DocumentLineInvoker
{
    /**
     * The document classes whose line API this adapter knows how to drive.
     *
     * @return array<int,string>
     */
    public static function supportedClasses()
    {
        return ['Commande', 'Facture', 'Propal', 'CommandeFournisseur', 'FactureFournisseur', 'SupplierProposal', 'Contrat'];
    }

    /**
     * Resolve the effective Dolibarr base class of $object among the supported
     * ones (a module may subclass Commande/Facture/Propal). Returns '' when none
     * matches.
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
     * Whether this adapter can drive line mutations on $object.
     *
     * @param  object $object
     * @return bool
     */
    public static function supports($object)
    {
        return is_object($object) && self::baseClass($object) !== '';
    }

    /**
     * Normalize the caller-provided line array into scalars with defaults.
     *
     * @param  array $d
     * @return array{desc:string,label:string,pu:float,qty:float,txtva:float,fk_product:int,remise:float,type:int,rang:int,special:int,date_start:(int|string),date_end:(int|string),fk_unit:(int|null)}
     */
    private static function fields(array $d)
    {
        $desc = isset($d['desc']) ? (string) $d['desc'] : '';
        if ($desc === '' && isset($d['label'])) {
            $desc = (string) $d['label'];
        }
        $dateStart = (isset($d['date_start']) && $d['date_start'] !== null && $d['date_start'] !== '') ? (int) $d['date_start'] : '';
        $dateEnd = (isset($d['date_end']) && $d['date_end'] !== null && $d['date_end'] !== '') ? (int) $d['date_end'] : '';
        $fkUnit = (isset($d['fk_unit']) && (int) $d['fk_unit'] > 0) ? (int) $d['fk_unit'] : null;

        return [
            'desc'       => $desc,
            'label'      => isset($d['label']) ? (string) $d['label'] : '',
            'pu'         => isset($d['subprice']) ? (float) $d['subprice'] : 0.0,
            'qty'        => isset($d['qty']) ? (float) $d['qty'] : 1.0,
            'txtva'      => isset($d['tva_tx']) ? (float) $d['tva_tx'] : 0.0,
            'fk_product' => isset($d['fk_product']) ? (int) $d['fk_product'] : 0,
            'remise'     => isset($d['remise_percent']) ? (float) $d['remise_percent'] : 0.0,
            'type'       => isset($d['product_type']) ? (int) $d['product_type'] : 0,
            'rang'       => isset($d['rang']) ? (int) $d['rang'] : -1,
            'special'    => isset($d['special_code']) ? (int) $d['special_code'] : 0,
            'date_start' => $dateStart,
            'date_end'   => $dateEnd,
            'fk_unit'    => $fkUnit,
        ];
    }

    /**
     * Add a line. Returns the new line id (>0) or a negative/zero error code.
     *
     * @param  object $object  A booted Commande/Facture/Propal (fetched).
     * @param  object $user    Current Dolibarr user.
     * @param  array  $d       Normalized line data.
     * @return int
     */
    public static function add($object, $user, array $d)
    {
        $class = self::baseClass($object);
        $f = self::fields($d);

        switch ($class) {
            case 'Commande':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2, fk_product,
                //   remise_percent, info_bits, fk_remise_except, price_base_type, pu_ttc,
                //   date_start, date_end, type, rang, special_code, fk_parent_line,
                //   fk_fournprice, pa_ht, label, array_options, fk_unit)
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0, 0, $f['fk_product'],
                    $f['remise'], 0, 0, 'HT', 0, $f['date_start'], $f['date_end'],
                    $f['type'], $f['rang'], $f['special'], 0, null, 0, $f['label'], 0, $f['fk_unit']
                );
            case 'Propal':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2, fk_product,
                //   remise_percent, price_base_type, pu_ttc, info_bits, type, rang,
                //   special_code, fk_parent_line, fk_fournprice, pa_ht, label,
                //   date_start, date_end, array_options, fk_unit)
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0.0, 0.0, $f['fk_product'],
                    $f['remise'], 'HT', 0.0, 0, $f['type'], $f['rang'], $f['special'],
                    0, 0, 0, $f['label'], $f['date_start'], $f['date_end'], 0, $f['fk_unit']
                );
            case 'Facture':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2, fk_product,
                //   remise_percent, date_start, date_end, ventil, info_bits,
                //   fk_remise_except, price_base_type, pu_ttc, type, rang, special_code,
                //   origin, origin_id, fk_parent_line, fk_fournprice, pa_ht, label,
                //   array_options, situation_percent, fk_prev_id, fk_unit)
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0, 0, $f['fk_product'],
                    $f['remise'], $f['date_start'], $f['date_end'], 0, 0, '', 'HT', 0,
                    $f['type'], $f['rang'], $f['special'], '', 0, 0, null, 0, $f['label'],
                    0, 100, 0, $f['fk_unit']
                );
            case 'CommandeFournisseur':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2, fk_product,
                //   fk_prod_fourn_price, ref_supplier, remise_percent, price_base_type,
                //   pu_ttc, type, info_bits, notrigger, date_start, date_end,
                //   array_options, fk_unit, pu_ht_devise, origin, origin_id, rang,
                //   special_code)
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0.0, 0.0, $f['fk_product'],
                    0, '', $f['remise'], 'HT', 0.0, $f['type'], 0, false, $f['date_start'],
                    $f['date_end'], 0, $f['fk_unit'], 0, '', 0, $f['rang'], $f['special']
                );
            case 'FactureFournisseur':
                // addline(desc, pu, txtva, txlocaltax1, txlocaltax2, qty, fk_product,
                //   remise_percent, date_start, date_end, ventil, info_bits,
                //   price_base_type, type, rang, notrigger, array_options, fk_unit,
                //   origin_id, pu_devise, ref_supplier, special_code, fk_parent_line,
                //   fk_remise_except). NOTE: pu/txtva precede qty here.
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['txtva'], 0, 0, $f['qty'], $f['fk_product'],
                    $f['remise'], $f['date_start'], $f['date_end'], 0, '', 'HT', $f['type'],
                    $f['rang'], false, 0, $f['fk_unit'], 0, 0, '', $f['special'], 0, 0
                );
            case 'SupplierProposal':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2, fk_product,
                //   remise_percent, price_base_type, pu_ttc, info_bits, type, rang,
                //   special_code, fk_parent_line, fk_fournprice, pa_ht, label,
                //   array_options, ref_supplier, fk_unit, origin, origin_id,
                //   pu_ht_devise, date_start, date_end)
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0, 0, $f['fk_product'],
                    $f['remise'], 'HT', 0, 0, $f['type'], $f['rang'], $f['special'], 0, 0, 0,
                    $f['label'], 0, '', $f['fk_unit'], '', 0, 0, $f['date_start'], $f['date_end']
                );
            case 'Contrat':
                // addline(desc, pu_ht, qty, txtva, txlocaltax1, txlocaltax2,
                //   fk_product, remise_percent, date_start, date_end,
                //   price_base_type, pu_ttc, info_bits, fk_fournprice, pa_ht,
                //   array_options, fk_unit, rang)
                // NO $user (the class reads the global), no $type, no $label and
                // no $special_code: llx_contratdet has no column for them and
                // addline() inserts label = ''. The two dates are the PLANNED
                // ones; the real ones belong to active_line()/close_line().
                self::declareContractBuyPrice($object);
                return (int) $object->addline(
                    $f['desc'], $f['pu'], $f['qty'], $f['txtva'], 0, 0, $f['fk_product'],
                    $f['remise'], $f['date_start'], $f['date_end'], 'HT', 0, 0, null, 0, 0,
                    $f['fk_unit'], self::contractRang($f['rang'])
                );
        }

        return -1;
    }

    /**
     * Position to hand Contrat::addline().
     *
     * The other classes read rang = -1 as "append at the end"; Contrat does not
     * -- it only maps empty to 0 and inserts anything else verbatim, so the -1
     * default of fields() would store a line at rang -1 and fetch_lines()
     * (ORDER BY rang ASC) would hoist it above every existing line. Fall back to
     * the 0 the contract card itself uses.
     *
     * @param  int $rang
     * @return int
     */
    private static function contractRang($rang)
    {
        return ((int) $rang) > 0 ? (int) $rang : 0;
    }

    /**
     * Declare the buy price Contrat::addline()/updateline() read on themselves.
     *
     * Both guard their margin computation with "if ($this->pa_ht == 0)", but
     * $pa_ht is declared on ContratLigne, not on Contrat -- so the read hits an
     * undefined property. PHP treats that as a warning and evaluates null == 0
     * to true, which is precisely the branch the class wants; setting the
     * property to 0 keeps that behaviour and stops the warning. Same value, no
     * semantic change.
     *
     * The trade is a PHP 8.2 dynamic-property deprecation instead of a warning
     * on every single line write -- and Contrat::fetch() already creates
     * $fk_soc the same way, so this adds no new kind of noise. If the pinned
     * Dolibarr ever declares $pa_ht on Contrat, isset() becomes true and this
     * turns into a no-op on its own.
     *
     * @param  object $object
     * @return void
     */
    private static function declareContractBuyPrice($object)
    {
        if (!isset($object->pa_ht)) {
            $object->pa_ht = 0;
        }
    }

    /**
     * Read the REAL activation dates currently stored on a contract line.
     *
     * Contrat::updateline() rewrites date_ouverture and date_cloture on every
     * call, setting them to null when it receives an empty value. Leaving them
     * empty -- as a "the facade never writes those" reading would suggest --
     * therefore ERASES the activation record of a line while leaving statut = 4:
     * an open line that nothing can date any more. So the facade echoes back
     * what is already there. The values stay unwritable from the outside
     * (absent from WRITABLE_LINE_FIELDS); only active_line()/close_line() set
     * them.
     *
     * @param  object $object  Fetched Contrat.
     * @param  int    $lineId
     * @return array{0:(int|string),1:(int|string)}  [date_start_real, date_end_real]
     */
    private static function contractRealDates($object, $lineId)
    {
        if ((!isset($object->lines) || !is_array($object->lines)) && method_exists($object, 'fetch_lines')) {
            $object->fetch_lines();
        }
        if (!isset($object->lines) || !is_array($object->lines)) {
            dol_syslog("[SmartAuth] DocumentLineInvoker: no lines loaded on Contrat " . ((int) ($object->id ?? 0)) . ", real dates cannot be preserved", LOG_WARNING);
            return ['', ''];
        }

        foreach ($object->lines as $line) {
            if ((int) ($line->id ?? $line->rowid ?? 0) !== (int) $lineId) {
                continue;
            }
            $start = (isset($line->date_start_real) && $line->date_start_real !== null && $line->date_start_real !== '') ? (int) $line->date_start_real : '';
            $end = (isset($line->date_end_real) && $line->date_end_real !== null && $line->date_end_real !== '') ? (int) $line->date_end_real : '';
            return [$start, $end];
        }

        dol_syslog("[SmartAuth] DocumentLineInvoker: line " . ((int) $lineId) . " not found on Contrat " . ((int) ($object->id ?? 0)) . ", real dates cannot be preserved", LOG_WARNING);
        return ['', ''];
    }

    /**
     * Update a line. Returns >0 on success, <=0 on failure.
     *
     * @param  object $object  Fetched document.
     * @param  object $user    Current Dolibarr user (unused by Propal/Facture, kept uniform).
     * @param  int    $lineId
     * @param  array  $d       Normalized line data (already merged with existing values).
     * @return int
     */
    public static function update($object, $user, $lineId, array $d)
    {
        $class = self::baseClass($object);
        $f = self::fields($d);
        $lineId = (int) $lineId;

        switch ($class) {
            case 'Commande':
                // updateline(rowid, desc, pu, qty, remise_percent, txtva, txlocaltax1,
                //   txlocaltax2, price_base_type, info_bits, date_start, date_end, type,
                //   fk_parent_line, skip_update_total, fk_fournprice, pa_ht, label,
                //   special_code, array_options, fk_unit, pu_ht_devise, notrigger,
                //   ref_ext, rang)
                return (int) $object->updateline(
                    $lineId, $f['desc'], $f['pu'], $f['qty'], $f['remise'], $f['txtva'], 0.0, 0.0,
                    'HT', 0, $f['date_start'], $f['date_end'], $f['type'], 0, 0, null, 0,
                    $f['label'], $f['special'], 0, $f['fk_unit'], 0, 0, '', $f['rang']
                );
            case 'Propal':
                // updateline(rowid, pu, qty, remise_percent, txtva, txlocaltax1,
                //   txlocaltax2, desc, price_base_type, info_bits, special_code,
                //   fk_parent_line, skip_update_total, fk_fournprice, pa_ht, label, type,
                //   date_start, date_end, array_options, fk_unit, pu_ht_devise,
                //   notrigger, rang)
                return (int) $object->updateline(
                    $lineId, $f['pu'], $f['qty'], $f['remise'], $f['txtva'], 0.0, 0.0, $f['desc'],
                    'HT', 0, $f['special'], 0, 0, 0, 0, $f['label'], $f['type'],
                    $f['date_start'], $f['date_end'], 0, $f['fk_unit'], 0, 0, $f['rang']
                );
            case 'Facture':
                // updateline(rowid, desc, pu, qty, remise_percent, date_start, date_end,
                //   txtva, txlocaltax1, txlocaltax2, price_base_type, info_bits, type,
                //   fk_parent_line, skip_update_total, fk_fournprice, pa_ht, label,
                //   special_code, array_options, situation_percent, fk_unit,
                //   pu_ht_devise, notrigger, ref_ext, rang)
                return (int) $object->updateline(
                    $lineId, $f['desc'], $f['pu'], $f['qty'], $f['remise'], $f['date_start'], $f['date_end'],
                    $f['txtva'], 0, 0, 'HT', 0, $f['type'], 0, 0, null, 0, $f['label'],
                    $f['special'], 0, 100, $f['fk_unit'], 0, 0, '', $f['rang']
                );
            case 'CommandeFournisseur':
                // updateline(rowid, desc, pu, qty, remise_percent, txtva, txlocaltax1,
                //   txlocaltax2, price_base_type, info_bits, type, notrigger, date_start,
                //   date_end, array_options, fk_unit, pu_ht_devise, ref_supplier)
                return (int) $object->updateline(
                    $lineId, $f['desc'], $f['pu'], $f['qty'], $f['remise'], $f['txtva'], 0, 0,
                    'HT', 0, $f['type'], 0, $f['date_start'], $f['date_end'], 0, $f['fk_unit'], 0, ''
                );
            case 'FactureFournisseur':
                // updateline(id, desc, pu, vatrate, txlocaltax1, txlocaltax2, qty,
                //   idproduct, price_base_type, info_bits, type, remise_percent,
                //   notrigger, date_start, date_end, array_options, fk_unit, pu_devise,
                //   ref_supplier, rang)
                return (int) $object->updateline(
                    $lineId, $f['desc'], $f['pu'], $f['txtva'], 0, 0, $f['qty'], $f['fk_product'],
                    'HT', 0, $f['type'], $f['remise'], false, $f['date_start'], $f['date_end'],
                    0, $f['fk_unit'], 0, '', $f['rang']
                );
            case 'SupplierProposal':
                // updateline(rowid, pu, qty, remise_percent, txtva, txlocaltax1,
                //   txlocaltax2, desc, price_base_type, info_bits, special_code,
                //   fk_parent_line, skip_update_total, fk_fournprice, pa_ht, label, type,
                //   array_options, ref_supplier, fk_unit, pu_ht_devise)
                return (int) $object->updateline(
                    $lineId, $f['pu'], $f['qty'], $f['remise'], $f['txtva'], 0, 0, $f['desc'],
                    'HT', 0, $f['special'], 0, 0, 0, 0, $f['label'], $f['type'], 0, '', $f['fk_unit'], 0
                );
            case 'Contrat':
                // updateline(rowid, desc, pu, qty, remise_percent, date_start,
                //   date_end, tvatx, localtax1tx, localtax2tx, date_start_real,
                //   date_end_real, price_base_type, info_bits, fk_fournprice,
                //   pa_ht, array_options, fk_unit, rang)
                // Two divergences to watch: $tvatx comes AFTER the two planned
                // dates, and positions 11/12 expose the REAL dates. Those two are
                // passed back unchanged (see contractRealDates) -- never taken
                // from the client, never left empty. 'statut' is not in the SET
                // list of the UPDATE, so the line status survives on its own.
                list($startReal, $endReal) = self::contractRealDates($object, $lineId);
                self::declareContractBuyPrice($object);
                return (int) $object->updateline(
                    $lineId, $f['desc'], $f['pu'], $f['qty'], $f['remise'], $f['date_start'], $f['date_end'],
                    $f['txtva'], 0.0, 0.0, $startReal, $endReal, 'HT', 0, null, 0, 0,
                    $f['fk_unit'], self::contractRang($f['rang'])
                );
        }

        return -1;
    }

    /**
     * Delete a line. Returns >0 on success, <=0 on failure. Absorbs the
     * Commande-only user-first deleteline() signature.
     *
     * @param  object $object  Fetched document.
     * @param  object $user    Current Dolibarr user.
     * @param  int    $lineId
     * @return int
     */
    public static function delete($object, $user, $lineId)
    {
        $class = self::baseClass($object);
        $lineId = (int) $lineId;
        $id = (int) ($object->id ?? 0);

        switch ($class) {
            case 'Commande':
                // deleteline($user, $lineid, $id)
                return (int) $object->deleteline($user, $lineId, $id);
            case 'Propal':
                // deleteline($lineid, $id)
                return (int) $object->deleteline($lineId, $id);
            case 'Facture':
                // deleteline($rowid, $id)
                return (int) $object->deleteline($lineId, $id);
            case 'CommandeFournisseur':
                // deleteline($idline, $notrigger) -- no user.
                return (int) $object->deleteline($lineId);
            case 'FactureFournisseur':
                // deleteline($rowid, $notrigger) -- no user.
                return (int) $object->deleteline($lineId);
            case 'SupplierProposal':
                // deleteline($lineid) -- single arg.
                return (int) $object->deleteline($lineId);
            case 'Contrat':
                // deleteline($idline, User $user) -- user LAST, and typed, so a
                // null would be a TypeError rather than a soft failure.
                return (int) $object->deleteline($lineId, $user);
        }

        return -1;
    }
}
