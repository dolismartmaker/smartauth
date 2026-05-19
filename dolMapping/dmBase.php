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

abstract class dmBase
{
    protected $type;

    /**
     * Name of the Dolibarr class this mapper represents.
     *
     * MANDATORY for every concrete mapper. The class MUST exist (i.e. its
     * source file MUST be loaded via `require_once DOL_DOCUMENT_ROOT . '/...';`
     * at the top of the mapper file).
     *
     * Example: dmInvoice (which maps Dolibarr Facture) declares:
     *   protected $dolibarrClassName = 'Facture';
     *
     * Do NOT confuse with $parentClassName (see below). $dolibarrClassName
     * answers "what class is THIS mapper for", whereas $parentClassName
     * answers "what is the parent class of this object" -- only relevant
     * for sub-objects / lines.
     *
     * Boot-time validation (dmTrait::_validateDeclaration()) throws a
     * LogicException if this property is missing or points to a non-existing
     * class. See documentation/MAPPERS_CONVENTIONS.md.
     *
     * @var string
     */
    protected $dolibarrClassName;

    /**
     * Name of the parent Dolibarr class -- ONLY for sub-objects / lines.
     *
     * For example, a hypothetical dmFichinterLigne mapper (sub-object of
     * Fichinter) would declare:
     *   protected $dolibarrClassName = 'FichinterLigne';
     *   protected $parentClassName   = 'Fichinter';
     *
     * Most mappers (header objects like dmInvoice, dmThirdparty, dmProduct...)
     * do NOT have a parent and MUST NOT set this property. Setting
     * $parentClassName equal to $dolibarrClassName is a misuse and will
     * trigger a LogicException at boot time -- this guards against a known
     * mistake (declaring $parentClassName = 'Product' on a top-level
     * dmProduct mapper).
     *
     * @var string
     */
    protected $parentClassName;

    /**
     * name of class where you can find extrafields for that object for example Fichinter
     *
     * @var string
     */
    protected $parentClassToUseForExtraFields;

    /**
     * parent element for example fichinter
     *
     * @var string
     */
    protected $parentElementToUseForExtraFields;

    /**
     * parent table name for example fichinter
     *
     * @var string
     */
    protected $parentTableElementToUseForExtraFields;

    /**
     * list of extrafields you want to push as read only on front side
     * (that list should be set via module setup if you want to make that list
     * dynamic for end users)
     *
     * @var array
     */
    protected $extrafieldsRO;

    /**
     * same as $extrafieldsRO but in write, then people can set data into that extrafields
     *
     * @var array
     */
    protected $extrafieldsRW;

    /**
     * list of fields you want to publish on front
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFields;

    /**
     * Allowlist of Dolibarr field names this mapper accepts on import (write).
     *
     * Used by dmTrait::importMappedData() to reject any input field that is
     * not explicitly declared here -- safe-by-default: a mapper that does
     * not declare $writableFields is read-only via the import path.
     *
     * Values are Dolibarr field names (the KEYS of $listOfPublishedFields),
     * NOT the API names. Example for dmInvoice:
     *   protected $writableFields = [
     *       'ref_customer', 'date', 'date_lim_reglement',
     *       'fk_cond_reglement', 'fk_mode_reglement',
     *       'note_public', 'note_private',
     *   ];
     *
     * Lines / sub-objects are NOT covered by this mechanism in v1 of
     * importMappedData -- they must be managed via the Dolibarr object's
     * own addLine() / updateLine() / deleteLine() methods.
     *
     * See documentation/MAPPERS_API.md for the full import contract.
     *
     * @var array
     */
    protected $writableFields = [];

    /**
     * name of class for lines, for exemple FichinterLigne or InventoryLine
     *
     * @var string
     */
    protected $parentClassNameForLines;

    /**
     * label for "title of lines", for exemple on FichinterLigne lines title could be "History"
     * (note: that label will be translated thanks to internal dolibarr translation system)
     *
     * @var string
     */
    protected $parentLabelForLines;

    /**
     * you can customize / overcharge fields for for parent object like dolibarr publish for main object
     * if you would like to change some settings, for exemple changing a field of Fichinter main object
     * to make it readonly in your specific use case
     *
     * example: $parentFieldsOverride['duree']['type'] = "duration";
     *          $parentFieldsOverride['duree'] = [ 'type' => "duration", 'required' => "required" ];
     *
     * @var array
     */
    protected $parentFieldsOverride;

    /**
     * fields for lines like dolibarr publish for main object, for exemple FichinterLigne
     * FichinterLigne could not have ->fields then we have to do it in our "custom" object
     *
     * @var array
     */
    protected $parentFieldsForLines;


    /**
     * list of fields you want to publish on front for lines
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFieldsForLines;
}
