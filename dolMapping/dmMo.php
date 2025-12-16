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

require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';

/**
 * Mapping for Dolibarr Mo -> API Mo (Manufacturing Order)
 */
class dmMo extends dmBase
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
		'mrptype'           => 'mrp_type',
		'qty'               => 'quantity',
		'date_creation'     => 'created_at',
		'date_valid'        => 'validated_at',
		'tms'               => 'updated_at',
		'date_start_planned' => 'date_start_planned',
		'date_end_planned'  => 'date_end_planned',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
		'fk_warehouse'      => 'warehouse',
		'fk_soc'            => 'thirdparty',
		'fk_product'        => 'product',
		'fk_bom'            => 'bom',
		'fk_project'        => 'project',
		'status'            => 'status',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
	];

	// Configuration for lines support
	protected $parentClassNameForLines = 'MoLine';
	protected $parentLabelForLines = 'MoLines';

	// Dolibarr field => Front field for lines
	protected $listOfPublishedFieldsForLines = [];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->listOfPublishedFieldsForLines = $this->getMoLinesMapping();
		$this->boot();
	}
}
