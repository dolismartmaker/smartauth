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

require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';

/**
 * Mapping for Dolibarr BOM -> API Bom (Bill of Materials)
 */
class dmBom extends dmBase
{
	use dmTrait;
	use dmLinesTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'ref'               => 'ref',
		'label'             => 'label',
		'bomtype'           => 'bom_type',
		'description'       => 'description',
		'date_creation'     => 'created_at',
		'date_valid'        => 'validated_at',
		'tms'               => 'updated_at',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
		'fk_user_valid'     => 'validated_by',
		'fk_warehouse'      => 'warehouse',
		'fk_product'        => 'product',
		'qty'               => 'quantity',
		'duration'          => 'duration',
		'efficiency'        => 'efficiency',
		'status'            => 'status',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'BOMLine';
	protected $parentLabelForLines = 'BomLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getBomLinesMapping();
		$this->boot();
	}
}
