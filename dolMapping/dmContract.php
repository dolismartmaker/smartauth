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

require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';

/**
 * Mapping for Dolibarr Contrat -> API Contract
 * Alias: dmContrat (for backward compatibility with Dolibarr internal calls)
 */
class dmContract extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Contrat';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'ref_customer'      => 'customer_ref',
		'ref_supplier'      => 'supplier_ref',
		'datec'             => 'created_at',
		'tms'               => 'updated_at',
		'date_contrat'      => 'date_contract',
		'fk_soc'            => 'thirdparty',
		'fk_projet'         => 'project',
		'fk_user_author'    => 'created_by',
		'fk_commercial_signature' => 'commercial_signature',
		'fk_commercial_suivi' => 'commercial_followup',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'ContratLigne';
	protected $parentLabelForLines = 'ContractLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getContractLinesMapping();
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmContract', 'SmartAuth\DolibarrMapping\dmContrat');
