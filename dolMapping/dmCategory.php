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

require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';

/**
 * Mapping for Dolibarr Categorie -> API Category
 * Alias: dmCategorie (for backward compatibility with Dolibarr internal calls)
 */
class dmCategory extends dmBase
{
	use dmTrait;

	protected $type = "object";

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'fk_parent'         => 'parent',
		'label'             => 'label',
		'description'       => 'description',
		'color'             => 'color',
		'visible'           => 'visible',
		'type'              => 'type',
		'fk_soc'            => 'thirdparty',
		'date_creation'     => 'created_at',
		'tms'               => 'updated_at',
		'fk_user_creat'     => 'created_by',
		'fk_user_modif'     => 'updated_by',
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
class_alias('SmartAuth\DolibarrMapping\dmCategory', 'SmartAuth\DolibarrMapping\dmCategorie');
