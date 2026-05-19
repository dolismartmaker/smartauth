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

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';

/**
 * Mapping for Dolibarr CommandeFournisseur -> API SupplierOrder
 * Alias: dmCommandeFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierOrder extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'CommandeFournisseur';
	protected $parentTableElementToUseForExtraFields = 'commande_fournisseur';

	// Hints front-side: render these FKs as sellists wired to the
	// matching Dolibarr dictionary tables. Supplier flow exposes
	// fk_account directly.
	protected $parentFieldsOverride = [
		'cond_reglement_id' => ['type' => 'sellist:c_payment_term:libelle:rowid', 'label' => 'PaymentConditionsShort'],
		'mode_reglement_id' => ['type' => 'sellist:c_paiement:libelle:id', 'label' => 'PaymentMode'],
		'fk_account'        => ['type' => 'sellist:bank_account:label:rowid', 'label' => 'BankAccount'],
	];

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_supplier'      => 'supplier_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date'              => 'date_order',
		'date_valid'        => 'validated_at',
		'date_approve'      => 'approved_at',
		'date_commande'     => 'date_order_supplier',
		'delivery_date'     => 'date_delivery',
		'socid'             => 'thirdparty',
		'fk_projet'         => 'project',
		'fk_user_author'    => 'created_by',
		'fk_user_valid'     => 'validated_by',
		'fk_user_approve'   => 'approved_by',
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
		'billed'            => 'billed',
		'fk_multicurrency'  => 'multicurrency_id',
		'multicurrency_code' => 'multicurrency_code',
		'multicurrency_tx'  => 'multicurrency_rate',
		'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
		'multicurrency_total_tva' => 'multicurrency_total_vat',
		'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	protected $writableFields = [
		'ref_supplier',
		'socid',
		'fk_projet',
		'date',
		'date_commande',
		'delivery_date',
		'cond_reglement_id',
		'mode_reglement_id',
		'fk_account',
		'note_public',
		'note_private',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'CommandeFournisseurLigne';
	protected $parentLabelForLines = 'SupplierOrderLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getSupplierOrderLinesMapping();
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmSupplierOrder', 'SmartAuth\DolibarrMapping\dmCommandeFournisseur');
