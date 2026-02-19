<?php

/**
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (c) 2025 Paolo Debaisieux <paolo.debaisieux@cap-rel.fr>
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

namespace SmartAuth\DolibarrMapping;

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

/**
 * Mapping for Dolibarr FactureFournisseur -> API SupplierInvoice
 * Alias: dmFactureFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierInvoice extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'FactureFournisseur';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		'label'             => 'label',
		'type'              => 'type',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_invoice',
		'date_echeance'     => 'date_due',
		'socid'             => 'thirdparty',
		'fk_projet'         => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'cond_reglement_id' => 'payment_terms',
		'mode_reglement_id' => 'payment_method',
		'fk_account'        => 'bank_account',
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_localtax1'   => 'total_local_tax1',
		'total_localtax2'   => 'total_local_tax2',
		'total_ttc'         => 'total_incl_tax',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		'paye'              => 'paid',
		'close_code'        => 'close_code',
		'close_note'        => 'close_note',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'SupplierInvoiceLine';
	protected $parentLabelForLines = 'SupplierInvoiceLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getSupplierInvoiceLinesMapping();
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmSupplierInvoice', 'SmartAuth\DolibarrMapping\dmFactureFournisseur');
