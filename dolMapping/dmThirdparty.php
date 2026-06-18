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

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

/**
 * Mapping for Dolibarr Societe -> API Thirdparty
 * Alias: dmSociete (for backward compatibility with Dolibarr internal calls)
 */
class dmThirdparty extends dmBase
{
	use dmTrait;

	protected $type = "object";
	protected $dolibarrClassName = 'Societe';
	protected $parentTableElementToUseForExtraFields = 'societe';

	// Dolibarr PHP property name => Front API key
	// See documentation/api-naming-convention.md.
	//
	// Note on 'name' (not 'nom'): the Societe SQL column is `nom`, but
	// Societe::fetch (htdocs/societe/class/societe.class.php line 1736)
	// SELECTs `s.nom as name`, populating $this->name, and Societe::update
	// (line 1448) writes the SQL column FROM $this->name. The PHP
	// property is the source of truth on both read and write paths.
	// Mirroring that here keeps the mapper aligned with the modern
	// Dolibarr contract (same pattern as dmProduct using 'status' rather
	// than the SQL column 'tosell').
	protected $listOfPublishedFields = [
		'rowid'             => 'id',
		'name'              => 'name',
		'name_alias'        => 'name_alias',
		'address'           => 'address',
		'zip'               => 'zip',
		'town'              => 'city',
		'fk_departement'    => 'state',
		'fk_pays'           => 'country',
		'phone'             => 'phone',
		'url'               => 'website',
		'email'             => 'email',
		'client'            => 'is_customer',
		'fournisseur'       => 'is_supplier',
		'code_client'       => 'customer_code',
		'code_fournisseur'  => 'supplier_code',
		'siren'             => 'siren',
		'siret'             => 'siret',
		'ape'               => 'ape',
		'idprof4'           => 'idprof4',
		'tva_intra'         => 'vat_intra',
		'note_public'       => 'public_note',
		'note_private'      => 'private_note',
	];

	// Derived fields exposed alongside the Dolibarr-backed columns. These
	// have no source column in llx_societe; they are computed from
	// $societe->logo via fieldFilterValueXxx() and rendered into the API
	// payload by dmTrait. The output keys (right side) match
	// documentation/api-naming-convention.md.
	//
	//   logo          -> relative URL to the JWT binary route (full size)
	//   logo_mini     -> relative URL to the JWT binary route (mini)
	//   logo_data_url -> DEPRECATED inline base64 (will be removed in 2.2.0)
	protected $listOfDerivedFields = [
		'logo'          => 'logo',
		'logo_mini'     => 'logo_mini',
		'logo_data_url' => 'logo_data_url',
	];

	// Allowlist for importMappedData() (Dolibarr PHP property names).
	// See documentation/SPEC_A_WRITABLEFIELDS.md and the note on 'name'
	// above for why this list uses the PHP property names rather than
	// the raw SQL column names.
	protected $writableFields = [
		'name',
		'name_alias',
		'address',
		'zip',
		'town',
		'fk_departement',
		'fk_pays',
		'phone',
		'url',
		'email',
		'client',
		'fournisseur',
		'code_client',
		'code_fournisseur',
		'siren',
		'siret',
		'ape',
		'idprof4',
		'tva_intra',
		'note_public',
		'note_private',
	];

	/**
	 * object constructor
	 *
	 * @return  [type]  [return description]
	 */
	public function __construct()
	{
		$this->boot();

		// dol_syslog("[SmartAuth] cacheDesc after is " . json_encode($this->_cacheDesc));
	}


	/**
	 * Logo relative URL for the full-size variant. Consumers fetch the
	 * binary stream from GET media/thirdparty/{id}/logo with their JWT.
	 * PWAs typically pair this with useAuthenticatedImage() from
	 * smartcommon for IndexedDB caching and offline fallback.
	 *
	 * @param   object  $societe  Dolibarr Societe
	 * @return  string|null       Relative URL, or null if no logo configured
	 */
	public function fieldFilterValueLogo($societe)
	{
		if (empty($societe->logo) || empty($societe->id)) {
			return null;
		}

		return 'media/thirdparty/' . (int) $societe->id . '/logo';
	}

	/**
	 * Logo relative URL for the mini (thumbnail) variant. MANDATORY in
	 * list contexts (>= 10 items) to avoid saturating the browser's 6
	 * parallel connections per host limit.
	 *
	 * @param   object  $societe  Dolibarr Societe
	 * @return  string|null       Relative URL, or null if no logo configured
	 */
	public function fieldFilterValueLogoMini($societe)
	{
		if (empty($societe->logo) || empty($societe->id)) {
			return null;
		}

		return 'media/thirdparty/' . (int) $societe->id . '/logo/mini';
	}

	/**
	 * DEPRECATED legacy inline base64 transport. Kept for the 2.1.x
	 * transition period so consumers that still expect base64 keep
	 * working. Will be removed in 2.2.0. Each call emits a WARNING
	 * syslog so we can track in production which consumers still depend
	 * on it.
	 *
	 * @param   object  $societe  Dolibarr Societe
	 * @return  string|null       data: URI base64-encoded, or null if no logo
	 */
	public function fieldFilterValueLogoDataUrl($societe)
	{
		if (empty($societe->logo) || empty($societe->id)) {
			return null;
		}

		dol_syslog(
			"[SmartAuth] dmThirdparty::fieldFilterValueLogoDataUrl -- deprecated "
			. "field still used by a consumer for thirdparty " . (int) $societe->id
			. ". Migrate to GET media/thirdparty/{id}/logo binary route. "
			. "Will be removed in smartauth 2.2.0.",
			LOG_WARNING
		);

		return $this->_legacyLogoBase64($societe);
	}

	/**
	 * Original base64 inline transport, preserved verbatim from the
	 * pre-2.1.0 fieldFilterValueLogo() implementation. Do not optimise
	 * or refactor this method: it is going away with logo_data_url in
	 * 2.2.0 and the goal is to delete it as a single hunk.
	 *
	 * @param   object  $societe  Dolibarr Societe
	 * @return  string            data: URI base64-encoded
	 */
	private function _legacyLogoBase64($societe)
	{
		global $conf;
		$dir     = $conf->societe->multidir_output[$societe->entity] . "/" . $societe->id . "/logos/thumbs";
		$logo = $dir . '/' . $this->_miniLogoFileName($societe->logo);
		$logoBase64 = "";
		if (file_exists($logo)) {
			$type = pathinfo($logo, PATHINFO_EXTENSION);
		} else {
			$logo = dol_buildpath("/smartlivraisons/img/logo.png", 0);
			$type = pathinfo($logo, PATHINFO_EXTENSION);
		}
		$logoBase64 = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($logo));
		return $logoBase64;
	}

	/**
	 * return mini logo file
	 *
	 * @param   [type]  $logoFileName  [$logoFileName description]
	 *
	 * @return  [type]                 [return description]
	 */
	private function _miniLogoFileName($logoFileName)
	{
		return str_replace(['.jpg', '.jpeg', '.png'], ['_mini.jpg','_mini.jpg','_mini.png'], $logoFileName);
	}
}

// Backward compatibility alias for Dolibarr internal FK resolution
class_alias('SmartAuth\DolibarrMapping\dmThirdparty', 'SmartAuth\DolibarrMapping\dmSociete');
