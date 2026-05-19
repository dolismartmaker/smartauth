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
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\DolibarrMapping;

require_once DOL_DOCUMENT_ROOT . '/delivery/class/delivery.class.php';

/**
 * Mapping for Dolibarr Delivery -> API DeliveryNote (bon de livraison)
 * Alias: dmLivraison (for backward compatibility with Dolibarr internal calls)
 *
 * Note : the Dolibarr Delivery module is separate from Expedition
 * (shipment). A Delivery is a printable BL document linked to a
 * Commande, while an Expedition tracks physical shipping. Most modern
 * Dolibarr workflows favour Expedition; Delivery is kept for legacy.
 */
class dmDeliveryNote extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Delivery';

	protected $parentTableElementToUseForExtraFields = 'delivery';

	// Dolibarr field => Front field
	// See documentation/api-naming-convention.md
	protected $listOfPublishedFields = [
		'rowid'         => 'id',
		'ref'           => 'ref',
		'ref_customer'  => 'customer_ref',
		'socid'         => 'thirdparty',
		'commande_id'   => 'order',
		'date_delivery' => 'date_delivery',
		'date_creation' => 'created_at',
		'date_valid'    => 'validated_at',
		'model_pdf'     => 'model_pdf',
		'statut'        => 'status',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// 'statut' is intentionally excluded per Rule 1 strict (status = state machine);
	// validation goes through Delivery::valid().
	protected $writableFields = [
		'ref_customer',
		'socid',
		'commande_id',
		'date_delivery',
		'model_pdf',
	];

	public function __construct()
	{
		$this->boot();
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmDeliveryNote', 'SmartAuth\DolibarrMapping\dmLivraison');
