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
 * Trait for exposing linked objects in API responses
 * Uses Dolibarr's fetchObjectLinked() mechanism
 *
 * See documentation/api-naming-convention.md
 */
trait dmLinkedObjectsTrait
{
	/**
	 * Mapping of Dolibarr element types to API types
	 * Used to convert internal Dolibarr names to API-friendly names
	 *
	 * @var array
	 */
	protected static $linkedObjectTypeMapping = [
		// Commercial documents
		'propal'            => 'proposal',
		'commande'          => 'order',
		'facture'           => 'invoice',
		'contrat'           => 'contract',
		'fichinter'         => 'intervention',

		// Supplier documents
		'supplier_proposal' => 'supplier_proposal',
		'order_supplier'    => 'supplier_order',
		'invoice_supplier'  => 'supplier_invoice',

		// Logistics
		'shipping'          => 'shipment',
		'reception'         => 'reception',

		// Other
		'societe'           => 'thirdparty',
		'project'           => 'project',
		'action'            => 'agenda_event',
		'product'           => 'product',
		'expensereport'     => 'expense_report',
	];

	/**
	 * Get linked objects mapping for API response
	 * Returns an array structure suitable for API output
	 *
	 * @param object $dolibarrObject The Dolibarr object with linkedObjectsIds loaded
	 * @return array Array of linked objects grouped by type
	 */
	protected function getLinkedObjectsMapping($dolibarrObject): array
	{
		$linkedObjects = [];

		if (empty($dolibarrObject->linkedObjectsIds) || !is_array($dolibarrObject->linkedObjectsIds)) {
			return $linkedObjects;
		}

		foreach ($dolibarrObject->linkedObjectsIds as $type => $ids) {
			$apiType = $this->mapLinkedObjectType($type);

			if (!isset($linkedObjects[$apiType])) {
				$linkedObjects[$apiType] = [];
			}

			// Handle both array formats: [id => id] or just [id]
			$objectIds = is_array($ids) ? array_values($ids) : [$ids];

			foreach ($objectIds as $id) {
				$linkedObjects[$apiType][] = [
					'id' => (int) $id,
					'type' => $apiType,
				];
			}
		}

		return $linkedObjects;
	}

	/**
	 * Get linked objects with full object data (when linkedObjects is loaded)
	 *
	 * @param object $dolibarrObject The Dolibarr object with linkedObjects loaded
	 * @return array Array of linked objects with basic fields
	 */
	protected function getLinkedObjectsWithData($dolibarrObject): array
	{
		$linkedObjects = [];

		if (empty($dolibarrObject->linkedObjects) || !is_array($dolibarrObject->linkedObjects)) {
			return $linkedObjects;
		}

		foreach ($dolibarrObject->linkedObjects as $type => $objects) {
			$apiType = $this->mapLinkedObjectType($type);

			if (!isset($linkedObjects[$apiType])) {
				$linkedObjects[$apiType] = [];
			}

			foreach ($objects as $obj) {
				$linkedObjects[$apiType][] = $this->extractBasicLinkedObjectData($obj, $apiType);
			}
		}

		return $linkedObjects;
	}

	/**
	 * Map Dolibarr element type to API type
	 *
	 * @param string $dolibarrType The Dolibarr element type
	 * @return string The API-friendly type name
	 */
	protected function mapLinkedObjectType(string $dolibarrType): string
	{
		return self::$linkedObjectTypeMapping[$dolibarrType] ?? $dolibarrType;
	}

	/**
	 * Extract basic data from a linked object for API response
	 *
	 * @param object $obj The linked Dolibarr object
	 * @param string $apiType The API type name
	 * @return array Basic object data
	 */
	protected function extractBasicLinkedObjectData($obj, string $apiType): array
	{
		$data = [
			'id' => (int) ($obj->id ?? $obj->rowid ?? 0),
			'type' => $apiType,
		];

		// Add ref if available
		if (!empty($obj->ref)) {
			$data['ref'] = $obj->ref;
		}

		// Add label/name based on object type
		if (!empty($obj->label)) {
			$data['label'] = $obj->label;
		} elseif (!empty($obj->nom)) {
			$data['name'] = $obj->nom;
		} elseif (!empty($obj->name)) {
			$data['name'] = $obj->name;
		}

		// Add status if available
		if (isset($obj->status)) {
			$data['status'] = (int) $obj->status;
		} elseif (isset($obj->statut)) {
			$data['status'] = (int) $obj->statut;
		}

		// Add total_ttc for commercial documents
		if (isset($obj->total_ttc)) {
			$data['total_incl_tax'] = (float) $obj->total_ttc;
		}

		// Add date for documents
		if (!empty($obj->date)) {
			$data['date'] = $obj->date;
		}

		return $data;
	}

	/**
	 * Structure for API documentation
	 * Describes the linked_objects field format
	 *
	 * @return array
	 */
	protected function getLinkedObjectsDescription(): array
	{
		return [
			'type' => 'object',
			'description' => 'Linked objects grouped by type',
			'example' => [
				'proposal' => [
					['id' => 123, 'type' => 'proposal', 'ref' => 'PR2024-001', 'status' => 2],
				],
				'order' => [
					['id' => 456, 'type' => 'order', 'ref' => 'CO2024-001', 'status' => 1],
				],
				'invoice' => [
					['id' => 789, 'type' => 'invoice', 'ref' => 'FA2024-001', 'status' => 1, 'total_incl_tax' => 1200.00],
				],
			],
		];
	}
}
