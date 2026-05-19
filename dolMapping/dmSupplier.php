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

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';

/**
 * Mapping for Dolibarr Fournisseur -> API Supplier
 * Alias: dmFournisseur (for backward compatibility with Dolibarr internal calls)
 *
 * Fournisseur extends Societe in Dolibarr: same table, same columns,
 * specialised behaviour. The mapper inherits all the field declarations
 * and override hooks from dmThirdparty and only overrides
 * $dolibarrClassName so that boot() instantiates a Fournisseur, which
 * exposes the supplier-specific methods (paymentByMethod, etc.) while
 * keeping the API contract identical to the Thirdparty contract.
 */
class dmSupplier extends dmThirdparty
{
	protected $dolibarrClassName = 'Fournisseur';
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmSupplier', 'SmartAuth\DolibarrMapping\dmFournisseur');
