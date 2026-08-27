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
		// Contrat::create/fetch read the PHP properties $socid / $fk_project
		// (SQL columns fk_soc / fk_projet).
		'socid'             => 'thirdparty',
		'fk_project'        => 'project',
		'fk_user_author'    => 'created_by',
		// Contrat::fetch (contrat.class.php line 715-716) reads the SQL
		// columns 'fk_commercial_signature' / 'fk_commercial_suivi' INTO
		// $this->commercial_signature_id / $this->commercial_suivi_id
		// (legacy PHP alias). Reading the SQL names yields null. For
		// writes, Contrat::update() aliases commercial_signature_id back
		// to fk_commercial_signature automatically (line 1314), so the
		// PHP name works for both read and write paths.
		'commercial_signature_id' => 'commercial_signature',
		'commercial_suivi_id'     => 'commercial_followup',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
		'statut'            => 'status',
		// Read-only, and NOT columns of llx_contrat: Contrat::fetch_lines sums
		// its lines into these properties ("global properties on contract not
		// stored into database"). They are therefore only meaningful once the
		// lines are loaded, which every read path of the facade now does
		// (ObjectFacadeTrait::loadLines). Absent from $writableFields on
		// purpose: writing a total that no column holds is meaningless.
		'total_ht'          => 'total_excl_tax',
		'total_tva'         => 'total_vat',
		'total_ttc'         => 'total_incl_tax',
	];

	// Allowlist for importMappedData() (Dolibarr field names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md.
	// Tenant guard on the VALUES written into these foreign keys
	// (cf dmBase::$foreignKeyGuards): the allowlist below only vets names.
	// The two commercial_* ids are llx_user references; getEntity('user') covers
	// the shared entity-0 accounts, so only another TENANT's user is refused.
	protected $foreignKeyGuards = [
		'socid'                    => 'thirdparty',
		'fk_project'               => 'project',
		'commercial_signature_id'  => 'user',
		'commercial_suivi_id'      => 'user',
	];

	protected $writableFields = [
		'ref_customer',
		'ref_supplier',
		'date_contrat',
		'socid',
		'fk_project',
		'commercial_signature_id',
		'commercial_suivi_id',
		'note_public',
		'note_private',
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
