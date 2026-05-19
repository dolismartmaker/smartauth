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

require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';

/**
 * Mapping for Dolibarr Entrepot -> API Warehouse
 * Alias: dmEntrepot (for backward compatibility with Dolibarr internal calls)
 */
class dmWarehouse extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Entrepot';
	protected $parentTableElementToUseForExtraFields = 'entrepot';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'label'             => 'label',
		'description'       => 'description',
		'lieu'              => 'location',
		'address'           => 'address',
		'zip'               => 'zip',
		'town'              => 'city',
		'fk_departement'    => 'state',
		'fk_pays'           => 'country',
		'phone'             => 'phone',
		'fax'               => 'fax',
		'fk_parent'         => 'parent_warehouse',
		'fk_projet'         => 'project',
		'statut'            => 'status',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// 'statut' is intentionally excluded per Rule 1 strict (status = state machine).
	protected $writableFields = [
		'ref',
		'label',
		'description',
		'lieu',
		'address',
		'zip',
		'town',
		'fk_departement',
		'fk_pays',
		'phone',
		'fax',
		'fk_parent',
		'fk_projet',
	];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmWarehouse', 'SmartAuth\DolibarrMapping\dmEntrepot');
