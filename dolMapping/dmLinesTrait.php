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

/**
 * Trait for document lines mapping (Facture, Propal, Commande, etc.)
 * Provides common field mappings for line items
 *
 * See documentation/api-naming-convention.md
 */
trait dmLinesTrait
{
	/**
	 * Common fields mapping for document lines
	 * Dolibarr field => Front field
	 *
	 * @return array
	 */
	protected function getCommonLinesMapping(): array
	{
		return [
			// Identifiers
			'rowid'                 => 'id',
			'rang'                  => 'position',
			'fk_parent_line'        => 'parent_line_id',

			// Product reference
			'fk_product'            => 'product',
			'product_type'          => 'product_type',
			'product_ref'           => 'product_ref',
			'product_label'         => 'product_label',
			'product_desc'          => 'product_description',
			'product_barcode'       => 'product_barcode',

			// Description
			'desc'                  => 'description',
			'label'                 => 'label',

			// Quantity and pricing
			'qty'                   => 'quantity',
			'subprice'              => 'unit_price_excl_tax',
			'remise_percent'        => 'discount_percent',
			'fk_remise_except'      => 'discount_exception_id',

			// VAT and taxes
			'tva_tx'                => 'vat_rate',
			'vat_src_code'          => 'vat_code',
			'localtax1_tx'          => 'local_tax1_rate',
			'localtax2_tx'          => 'local_tax2_rate',
			'localtax1_type'        => 'local_tax1_type',
			'localtax2_type'        => 'local_tax2_type',

			// Totals
			'total_ht'              => 'total_excl_tax',
			'total_tva'             => 'total_vat',
			'total_ttc'             => 'total_incl_tax',
			'total_localtax1'       => 'total_local_tax1',
			'total_localtax2'       => 'total_local_tax2',

			// Margin (buy price)
			'pa_ht'                 => 'buy_price_excl_tax',
			'fk_fournprice'         => 'supplier_price_id',
			'marge_tx'              => 'margin_rate',
			'marque_tx'             => 'markup_rate',

			// Dates (for services)
			'date_start'            => 'date_start',
			'date_end'              => 'date_end',

			// Multicurrency
			'fk_multicurrency'      => 'multicurrency_id',
			'multicurrency_code'    => 'multicurrency_code',
			'multicurrency_subprice' => 'multicurrency_unit_price',
			'multicurrency_total_ht' => 'multicurrency_total_excl_tax',
			'multicurrency_total_tva' => 'multicurrency_total_vat',
			'multicurrency_total_ttc' => 'multicurrency_total_incl_tax',

			// Special codes and info
			'special_code'          => 'special_code',
			'info_bits'             => 'info_bits',

			// Unit
			'fk_unit'               => 'unit',
		];
	}

	/**
	 * Additional fields specific to invoice lines (Facture)
	 *
	 * @return array
	 */
	protected function getInvoiceLinesMapping(): array
	{
		return array_merge($this->getCommonLinesMapping(), [
			'fk_facture'            => 'invoice_id',
			'situation_percent'     => 'situation_percent',
			'fk_prev_id'            => 'previous_situation_line_id',
			'fk_code_ventilation'   => 'accounting_code_id',
			'fk_user_author'        => 'created_by',
			'fk_user_modif'         => 'updated_by',
		]);
	}

	/**
	 * Additional fields specific to proposal lines (Propal)
	 *
	 * @return array
	 */
	protected function getProposalLinesMapping(): array
	{
		return array_merge($this->getCommonLinesMapping(), [
			'fk_propal'             => 'proposal_id',
			'product_tobatch'       => 'product_batch_enabled',
		]);
	}

	/**
	 * Additional fields specific to order lines (Commande)
	 *
	 * @return array
	 */
	protected function getOrderLinesMapping(): array
	{
		return array_merge($this->getCommonLinesMapping(), [
			'fk_commande'           => 'order_id',
			'fk_facture'            => 'invoice_id',
			'ref_ext'               => 'external_ref',
			'product_tobatch'       => 'product_batch_enabled',
		]);
	}
}
