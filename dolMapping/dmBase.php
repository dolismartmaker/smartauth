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
    // dmBase composes dmTrait so the mapping methods (exportMappedData,
    // importMappedData, boot, ...) live on the base class itself. This is
    // what makes `parent::exportMappedData()` resolvable from a consumer
    // mapper that overrides the method (eg Dolipocket adding FK-label
    // post-processing) - without it PHP raises
    // "Call to undefined method dmBase::exportMappedData()".
    // Concrete mappers still `use dmTrait;` too; re-applying the same trait
    // in a subclass whose parent already uses it is legal (identical
    // defaults/visibility, no collision) and left untouched on purpose.
    use dmTrait;

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
     * Opt-in foreign-key -> label companion fields (read side).
     *
     * Consumed by dmTrait::_resolveForeignKeyLabels() and preserved by
     * exportMappedDataFiltered(). Declared here (without an initializer) so a
     * mapper may override it with its own map; kept OUT of dmTrait on purpose
     * because a trait property carrying an initial value would fatally conflict
     * with a using class that declares its own. Example:
     *   protected $listOfForeignKeyLabels = [
     *       'fk_soc' => ['class' => 'Societe', 'path' => 'societe/class/societe.class.php',
     *                    'labels' => ['socname' => 'name']],
     *   ];
     *
     * @var array
     */
    protected $listOfForeignKeyLabels;

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

    /**
     * Write a sanitized payload (output of importMappedData(), keyed by Dolibarr
     * field names) onto a Dolibarr object instance, ready for create()/update().
     *
     * Default behaviour is a straight property assignment per field. The
     * mapper's $listOfPublishedFields is expected to address the PHP property
     * that the underlying Dolibarr create()/update() actually reads (e.g.
     * dmThirdparty maps siren/siret/ape onto idprof1/idprof2/idprof3, the
     * properties Societe::update() writes the SQL columns from). A mapper whose
     * Dolibarr class needs irregular write handling can override this method.
     *
     * Fields named options_* are extrafields: they go into $object->array_options
     * (persisted by the object's insertExtraFields()), not onto a plain property.
     * Only extrafields the mapper allowlisted via $extrafieldsRW ever reach this
     * point (importMappedData() rejects the rest with a 400).
     *
     * @param  object    $object     Fresh or fetched Dolibarr object (mutated).
     * @param  \stdClass $sanitized  Output of importMappedData().
     * @return void
     */
    public function applyImportedFields($object, $sanitized)
    {
        foreach (get_object_vars($sanitized) as $field => $value) {
            if (strncmp((string) $field, 'options_', 8) === 0) {
                if (!isset($object->array_options) || !is_array($object->array_options)) {
                    $object->array_options = [];
                }
                $object->array_options[$field] = $value;
            } else {
                $object->{$field} = $value;
            }
        }
    }

    // =====================================================================
    // Column catalog
    //
    // Hoisted from the (formerly Dolipocket-local) dmCatalogTrait so that
    // EVERY mapper in the ecosystem exposes a normalized column catalog for
    // the generic REST facade (objects/{type}/columns + list filtering/sort)
    // without each consumer redeclaring a local trait.
    //
    // These are declared as concrete methods on dmBase (NOT on dmTrait) on
    // purpose: a consumer that still `use`s a local dmCatalogTrait defining
    // the same methods overrides them at its own class level, which is legal
    // (a using-class trait wins over an inherited parent method). Declaring
    // them on dmTrait instead would make them collide fatally with that local
    // trait applied at the SAME level (`use dmTrait, dmCatalogTrait`).
    // =====================================================================

    /**
     * Default UI hint widths in pixels per normalized type.
     *
     * @var array<string,int>
     */
    private static $CATALOG_DEFAULT_WIDTHS = [
        'string'   => 180,
        'int'      => 80,
        'float'    => 100,
        'date'     => 120,
        'datetime' => 140,
        'boolean'  => 80,
        'select'   => 140,
        'text'     => 240,
    ];

    /**
     * Build a normalized column catalog for the DataTable / REST facade consumer.
     *
     * Uses objectDesc() (already cached at boot time) to read the filtered +
     * translated descriptors for each appside field, and pairs them with the
     * raw $fields[$doliside] entry of the parent Dolibarr class to enrich with:
     * raw type string, visible code, searchable flag, arrayofkeyval (select
     * options).
     *
     * Excludes id/entity (always served by the API, not user-facing columns),
     * fields with $fields[$doliside]['visible'] === 0, and the synthetic "lines"
     * repeater.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getColumnCatalog()
    {
        global $db, $langs;

        $desc = $this->objectDesc();      // cached stdClass from dmTrait::boot()
        $publishedFields = $this->listOfPublishedFields ?? [];

        // Instantiate the mapped Dolibarr class so we can read its raw $fields.
        // Tolerate failure -- the catalog still works with the descriptor
        // alone, just with less rich type/searchable info.
        $rawFields = [];
        $dolibarrClassName = $this->dolibarrClassName ?? null;
        if (!empty($dolibarrClassName) && class_exists($dolibarrClassName)) {
            try {
                $parentObj = new $dolibarrClassName($db);
                if (property_exists($parentObj, 'fields') && is_array($parentObj->fields)) {
                    $rawFields = $parentObj->fields;
                }
            } catch (\Throwable $e) {
                dol_syslog(
                    "[SmartAuth] dmBase::getColumnCatalog could not instantiate ".$dolibarrClassName.": ".$e->getMessage(),
                    LOG_WARNING
                );
            }
        }

        // Pre-load extrafields metadata once so we can flag appside keys that
        // come from extrafields with group=extrafield.
        $extrafieldKeys = [];
        $parentElement = $this->parentTableElementToUseForExtraFields ?? '';
        if (!empty($parentElement)) {
            try {
                $ef = new \ExtraFields($db);
                $ef->fetch_name_optionals_label($parentElement);
                if (!empty($ef->attributes[$parentElement]['type']) && is_array($ef->attributes[$parentElement]['type'])) {
                    foreach ($ef->attributes[$parentElement]['type'] as $extraName => $_type) {
                        $extrafieldKeys['options_'.$extraName] = true;
                    }
                }
            } catch (\Throwable $e) {
                dol_syslog(
                    "[SmartAuth] dmBase::getColumnCatalog could not load extrafields for ".$parentElement.": ".$e->getMessage(),
                    LOG_WARNING
                );
            }
        }

        $catalog = [];
        foreach ($publishedFields as $doliside => $appside) {
            $appside = (string) $appside;

            // Skip system / invisible fields.
            if ($appside === 'id' || $doliside === 'rowid' || $appside === 'entity' || $doliside === 'entity') {
                continue;
            }

            // Honor explicit Dolibarr visible=0 (kept "internal", e.g. note_public html).
            if (isset($rawFields[$doliside]) && isset($rawFields[$doliside]['visible']) && (int) $rawFields[$doliside]['visible'] === 0) {
                continue;
            }

            // Pull translated descriptor (label, type, position, max...).
            $fieldDesc = isset($desc->{$appside}) ? $desc->{$appside} : null;
            if (!is_array($fieldDesc)) {
                // Could still be an FK-resolved stdClass (rare). Fallback to raw.
                $fieldDesc = [];
            }

            // Group classification.
            $group = 'main';
            if (isset($extrafieldKeys[$doliside]) || strpos($doliside, 'options_') === 0) {
                $group = 'extrafield';
            }

            // Whether $doliside is a REAL SQL column of the base table. Mappers
            // may address a PHP property that is not a column (e.g. dmThirdparty
            // 'name' -> SQL 'nom', 'idprof1' -> 'siren'; dmProduct 'status' ->
            // 'tosell'). Filtering/sorting the list query on such a name would
            // reference a non-existent column and break the SQL, so those fields
            // are exposed in the catalog for display but are NOT filterable /
            // sortable through the generic facade (same guard getSearchFields
            // applies to the global search). Real-column fields keep the full
            // filter/sort behaviour.
            $isRealColumn = isset($rawFields[$doliside]);

            // Resolve raw type (used for the heuristics below).
            $rawType = '';
            if (isset($rawFields[$doliside]['type'])) {
                $rawType = (string) $rawFields[$doliside]['type'];
            }
            // For extrafields, fall back to the descriptor's normalized type.
            if ($rawType === '' && isset($fieldDesc['type'])) {
                $rawType = (string) $fieldDesc['type'];
            }

            // Normalize type + filter kind.
            list($apiType, $filterKind, $sortable) = $this->normalizeCatalogType($rawType);

            // Visible default.
            $rawVisible = null;
            if (isset($rawFields[$doliside]['visible'])) {
                $rawVisible = (int) $rawFields[$doliside]['visible'];
            }
            $defaultVisible = ($rawVisible === 1);

            // Label (already translated in fieldDesc by propertiesFilter).
            $label = '';
            if (isset($fieldDesc['label']) && is_string($fieldDesc['label']) && $fieldDesc['label'] !== '') {
                $label = $fieldDesc['label'];
            } elseif (isset($rawFields[$doliside]['label'])) {
                $label = $langs->transnoentities((string) $rawFields[$doliside]['label']);
            } else {
                $label = ucfirst(str_replace('_', ' ', $appside));
            }

            // Filter options (select with static map, e.g. tinyint flags).
            $filterOptions = null;
            if ($filterKind === 'select') {
                if (isset($rawFields[$doliside]['arrayofkeyval']) && is_array($rawFields[$doliside]['arrayofkeyval'])) {
                    $filterOptions = [];
                    foreach ($rawFields[$doliside]['arrayofkeyval'] as $val => $optLabel) {
                        $filterOptions[] = [
                            'value' => $val,
                            'label' => $langs->transnoentities((string) $optLabel),
                        ];
                    }
                }
                // sellist: / link: keep filterOptions=null (dynamic, resolved client-side).
            }

            $defaultWidth = self::$CATALOG_DEFAULT_WIDTHS[$apiType] ?? 140;

            $catalog[] = [
                // Convert appside snake_case to camelCase so it matches the keys
                // produced by the frontend mapFromBackend(). The DataTable reads
                // row[key] directly. `doliside` stays snake_case so SQL
                // filters / sort still work.
                'key'            => self::snakeToCamel($appside),
                'label'          => $label,
                'type'           => $apiType,
                'sortable'       => $sortable && $isRealColumn,
                'filterable'     => $isRealColumn,
                'filterKind'     => $filterKind,
                'filterOptions'  => $filterOptions,
                'defaultVisible' => $defaultVisible,
                'defaultWidth'   => $defaultWidth,
                'group'          => $group,
                'doliside'       => (string) $doliside,
            ];
        }

        return $catalog;
    }

    /**
     * Build a normalized catalog for the lines block of a header object
     * (proposal, order, invoice, supplier order, supplier invoice).
     *
     * Returns an array using the exact same shape as getColumnCatalog().
     * Lines have no native ordering / filtering UX so sortable and filterable
     * are always false.
     *
     * Returns [] when $listOfPublishedFieldsForLines is empty,
     * $parentClassNameForLines is not declared, or the class cannot be
     * instantiated (logged via dol_syslog).
     *
     * @return array<int,array<string,mixed>>
     */
    /**
     * Raw line field mapping (Dolibarr line field => API field), snake_case as
     * produced by exportMappedData()'s line loop. Consumed by the write facade
     * (ObjectLineController) to translate an incoming API line payload back to
     * Dolibarr line field names, so a client can read a line, edit it and write
     * it back with the SAME keys it received.
     *
     * @return array<string,string>
     */
    public function getLinesFieldMapping()
    {
        return (isset($this->listOfPublishedFieldsForLines) && is_array($this->listOfPublishedFieldsForLines))
            ? $this->listOfPublishedFieldsForLines
            : [];
    }

    public function getLinesCatalog()
    {
        global $langs;

        $linesFields = $this->listOfPublishedFieldsForLines ?? [];
        if (empty($linesFields)) {
            return [];
        }

        $parentLineClassName = $this->parentClassNameForLines ?? '';
        if (empty($parentLineClassName)) {
            dol_syslog(
                "[SmartAuth] dmBase::getLinesCatalog skipped: parentClassNameForLines is empty on " . static::class,
                LOG_WARNING
            );
            return [];
        }
        if (!class_exists($parentLineClassName)) {
            dol_syslog(
                "[SmartAuth] dmBase::getLinesCatalog skipped: line class " . $parentLineClassName . " does not exist (autoload missing?)",
                LOG_WARNING
            );
            return [];
        }

        global $db;
        $lineObj = null;
        $rawFields = [];
        try {
            $lineObj = new $parentLineClassName($db);
            if (property_exists($lineObj, 'fields') && is_array($lineObj->fields)) {
                $rawFields = $lineObj->fields;
            }
        } catch (\Throwable $e) {
            dol_syslog(
                "[SmartAuth] dmBase::getLinesCatalog could not instantiate " . $parentLineClassName . ": " . $e->getMessage(),
                LOG_WARNING
            );
            return [];
        }

        $catalog = [];
        foreach ($linesFields as $doliside => $appside) {
            $appside = (string) $appside;
            // System columns that are never user-facing.
            if ($doliside === 'rowid' || $appside === 'id' || $doliside === 'entity') {
                continue;
            }
            // Skip extrafields for now (lines extrafields are rare and the
            // structure differs). The header catalog handles them already.
            if (strpos((string) $doliside, 'options_') === 0) {
                continue;
            }

            // Resolve a usable raw type.
            $rawType = '';
            $rawLabel = '';
            $rawVisible = null;
            if (isset($rawFields[$doliside]) && is_array($rawFields[$doliside])) {
                if (isset($rawFields[$doliside]['type'])) {
                    $rawType = (string) $rawFields[$doliside]['type'];
                }
                if (isset($rawFields[$doliside]['label'])) {
                    $rawLabel = (string) $rawFields[$doliside]['label'];
                }
                if (isset($rawFields[$doliside]['visible'])) {
                    $rawVisible = (int) $rawFields[$doliside]['visible'];
                }
            } else {
                $rawType = $this->guessLineFieldType((string) $doliside, $lineObj);
            }

            list($apiType, , ) = $this->normalizeCatalogType($rawType);

            if ($rawLabel !== '') {
                $label = $langs->transnoentities($rawLabel);
            } else {
                $label = ucfirst(str_replace(['_', '-'], ' ', $appside));
            }

            if ($rawVisible === 1) {
                $defaultVisible = true;
            } elseif ($rawVisible === 0) {
                $defaultVisible = false;
            } else {
                $defaultVisible = $this->isDefaultVisibleLineKey((string) $appside, (string) $doliside);
            }

            $defaultWidth = $this->resolveLineDefaultWidth((string) $appside, (string) $doliside, $apiType);

            $catalog[] = [
                'key'            => self::snakeToCamel($appside),
                'label'          => $label,
                'type'           => $apiType,
                'sortable'       => false,
                'filterable'     => false,
                'filterKind'     => 'text',
                'filterOptions'  => null,
                'defaultVisible' => $defaultVisible,
                'defaultWidth'   => $defaultWidth,
                'group'          => 'main',
                'doliside'       => (string) $doliside,
            ];
        }

        return $catalog;
    }

    /**
     * Guess a Dolibarr-style raw type for a line property without $fields.
     *
     * @param   string       $fieldName
     * @param   object|null  $lineObj
     * @return  string                   Raw Dolibarr type (e.g. "double", "integer", ...).
     */
    private function guessLineFieldType($fieldName, $lineObj)
    {
        $value = null;
        if ($lineObj !== null && property_exists($lineObj, $fieldName)) {
            $value = $lineObj->$fieldName ?? null;
        }
        if ($value !== null) {
            if (is_int($value)) {
                return 'integer';
            }
            if (is_float($value)) {
                return 'double(24,8)';
            }
            if (is_bool($value)) {
                return 'integer';
            }
        }

        if (preg_match('/^(fk_|rowid$|_id$|info_bits$|special_code$|rang$|product_type$)/', $fieldName)) {
            return 'integer';
        }
        if (preg_match('/^date|_date$|datec$|datem$|tms$/', $fieldName)) {
            return 'date';
        }
        if (preg_match('/^(price|amount|total|qty|quantity|weight|volume|subprice|tva|tx|remise|localtax)/', $fieldName)) {
            return 'double(24,8)';
        }
        if (preg_match('/^(note|description|desc)/', $fieldName)) {
            return 'text';
        }
        return 'varchar(255)';
    }

    /**
     * Default visible flag for a line column when the underlying $fields
     * entry has no explicit "visible" hint.
     *
     * @param   string  $appside
     * @param   string  $doliside
     * @return  bool
     */
    private function isDefaultVisibleLineKey($appside, $doliside)
    {
        $alwaysShown = [
            'label', 'description', 'qty', 'subprice', 'tvaTx', 'tva_tx',
            'remisePercent', 'remise_percent', 'totalHt', 'total_ht',
            'totalTtc', 'total_ttc', 'productRef', 'product_ref',
            'productLabel', 'product_label', 'rang',
        ];
        return in_array($appside, $alwaysShown, true) || in_array($doliside, $alwaysShown, true);
    }

    /**
     * Heuristic widths tailored for proposal/invoice lines (px values).
     *
     * @param   string  $appside
     * @param   string  $doliside
     * @param   string  $apiType
     * @return  int
     */
    private function resolveLineDefaultWidth($appside, $doliside, $apiType)
    {
        $perKey = [
            'qty'             => 70,
            'subprice'        => 110,
            'price'           => 110,
            'tvaTx'           => 80,
            'tva_tx'          => 80,
            'localtax1Tx'     => 80,
            'localtax1_tx'    => 80,
            'localtax2Tx'     => 80,
            'localtax2_tx'    => 80,
            'remisePercent'   => 90,
            'remise_percent'  => 90,
            'totalHt'         => 120,
            'total_ht'        => 120,
            'totalTva'        => 120,
            'total_tva'       => 120,
            'totalTtc'        => 120,
            'total_ttc'       => 120,
            'rang'            => 60,
            'productType'     => 90,
            'product_type'    => 90,
            'fkProduct'       => 90,
            'fk_product'      => 90,
            'productRef'      => 140,
            'product_ref'     => 140,
            'productLabel'    => 200,
            'product_label'   => 200,
            'fkUnit'          => 80,
            'fk_unit'         => 80,
            'infoBits'        => 80,
            'info_bits'       => 80,
            'specialCode'     => 90,
            'special_code'    => 90,
            'dateStart'       => 120,
            'date_start'      => 120,
            'dateEnd'         => 120,
            'date_end'        => 120,
            'label'           => 220,
            'description'     => 240,
        ];
        if (isset($perKey[$appside])) {
            return $perKey[$appside];
        }
        if (isset($perKey[$doliside])) {
            return $perKey[$doliside];
        }

        $byType = [
            'string'   => 240,
            'int'      => 80,
            'float'    => 100,
            'date'     => 120,
            'datetime' => 120,
            'boolean'  => 80,
            'select'   => 140,
            'text'     => 240,
        ];
        return $byType[$apiType] ?? 140;
    }

    /**
     * Whitelist of doliside columns scanned by the global LIKE search.
     *
     * Default = every string-typed published field whose Dolibarr $fields
     * entry has searchable=1. For Dolibarr classes without searchable hints,
     * the fallback is to expose every string column. Subclasses can override
     * to narrow the scope.
     *
     * @return array<int,string>
     */
    public function getSearchFields()
    {
        global $db;

        $publishedFields = $this->listOfPublishedFields ?? [];

        $rawFields = [];
        $dolibarrClassName = $this->dolibarrClassName ?? null;
        if (!empty($dolibarrClassName) && class_exists($dolibarrClassName)) {
            try {
                $parentObj = new $dolibarrClassName($db);
                if (property_exists($parentObj, 'fields') && is_array($parentObj->fields)) {
                    $rawFields = $parentObj->fields;
                }
            } catch (\Throwable $e) {
                dol_syslog(
                    "[SmartAuth] dmBase::getSearchFields could not instantiate ".$dolibarrClassName.": ".$e->getMessage(),
                    LOG_WARNING
                );
            }
        }

        // Detect whether ANY field declares searchable -- if yes, honor it
        // strictly; if no, fall back to "all string fields are searchable".
        $hasSearchableHints = false;
        foreach ($rawFields as $f) {
            if (is_array($f) && array_key_exists('searchable', $f)) {
                $hasSearchableHints = true;
                break;
            }
        }

        $out = [];
        foreach ($publishedFields as $doliside => $appside) {
            if (strpos((string) $doliside, 'options_') === 0) {
                continue; // extrafields not searched globally
            }
            if ($doliside === 'rowid' || $doliside === 'entity') {
                continue;
            }
            // The column MUST be declared in $object->fields. Without this
            // check the trait emits "WHERE s.country_code LIKE ..." for every
            // mapper publishing a computed property (Societe::$country_code is
            // filled from a JOIN, has no llx_societe.country_code column) and
            // the SQL crashes with "Unknown column".
            if (!isset($rawFields[$doliside])) {
                continue;
            }
            $rawType = isset($rawFields[$doliside]['type']) ? (string) $rawFields[$doliside]['type'] : '';
            if (!$this->isSearchableType($rawType)) {
                continue;
            }
            if ($hasSearchableHints) {
                $searchable = isset($rawFields[$doliside]['searchable']) ? (int) $rawFields[$doliside]['searchable'] : 0;
                if ($searchable !== 1) {
                    continue;
                }
            }
            $out[] = (string) $doliside;
        }

        return $out;
    }

    /**
     * Wrapper around exportMappedData() that filters the resulting stdClass to
     * only the appside keys requested by the caller.
     *
     * Backward compat: when $includeKeys is null, this is a strict pass-through.
     * Structural keys (categories, lines, nb_linked_files, linked_files) and
     * FK-label companions are preserved when present.
     *
     * @param   object       $obj          Dolibarr object instance
     * @param   array|null   $includeKeys  Optional whitelist of appside keys.
     * @return  \stdClass
     */
    public function exportMappedDataFiltered($obj, $includeKeys = null)
    {
        $full = $this->exportMappedData($obj);

        if ($includeKeys === null) {
            return $full;
        }
        if (!is_array($includeKeys) || empty($includeKeys)) {
            // Empty array = no business keys requested; only structural ones survive.
            $includeKeys = [];
        }

        $structuralKeys = ['lines', 'categories', 'nb_linked_files', 'linked_files'];
        // Preserve FK-label companion fields (socname, socEmail, ...) regardless
        // of ?include=: they are derived from a FK and the catalog never lists
        // them as standalone columns.
        if (!empty($this->listOfForeignKeyLabels) && is_array($this->listOfForeignKeyLabels)) {
            foreach ($this->listOfForeignKeyLabels as $spec) {
                if (is_array($spec) && !empty($spec['labels']) && is_array($spec['labels'])) {
                    foreach (array_keys($spec['labels']) as $companion) {
                        $structuralKeys[] = $companion;
                    }
                }
            }
        }
        $allowedSet = array_fill_keys($includeKeys, true);
        // 'id' is always carried (otherwise the row is unusable client-side).
        $allowedSet['id'] = true;

        $out = new \stdClass();
        foreach ($full as $k => $v) {
            if (isset($allowedSet[$k]) || in_array($k, $structuralKeys, true)) {
                $out->{$k} = $v;
            }
        }
        return $out;
    }

    /**
     * Map a raw Dolibarr type string to (apiType, filterKind, sortable).
     *
     * @param   string  $rawType
     * @return  array{0:string,1:string,2:bool}
     */
    private function normalizeCatalogType($rawType)
    {
        $t = strtolower(trim((string) $rawType));

        // Strip parametric suffixes ("varchar(128)" -> "varchar").
        if (($paren = strpos($t, '(')) !== false) {
            $t = substr($t, 0, $paren);
        }
        // Strip Dolibarr FK descriptors ("integer:User:user/class/user.class.php" -> "integer").
        if (($colon = strpos($t, ':')) !== false) {
            $t = substr($t, 0, $colon);
        }

        switch ($t) {
            case 'varchar':
            case 'string':
                return ['string', 'text', true];
            case 'text':
            case 'html':
                return ['text', 'text', false];
            case 'int':
            case 'integer':
                return ['int', 'numberrange', true];
            case 'double':
            case 'float':
            case 'real':
            case 'price':
                return ['float', 'numberrange', true];
            case 'date':
                return ['date', 'daterange', true];
            case 'datetime':
            case 'timestamp':
                return ['datetime', 'daterange', true];
            case 'boolean':
            case 'bool':
            case 'tinyint':
            case 'checkbox':
                return ['boolean', 'boolean', true];
            case 'select':
            case 'sellist':
            case 'chkbxlst':
            case 'radio':
            case 'link':
                return ['select', 'select', true];
            case 'mail':
            case 'email':
                return ['string', 'text', true];
            case 'phone':
            case 'phonenumber':
                return ['string', 'text', true];
            case 'url':
                return ['string', 'text', true];
        }

        // Unknown / module-specific custom types (smartphoto_, smartfile_, ...).
        return ['string', 'text', false];
    }

    /**
     * Heuristic for "is this raw Dolibarr type a string the user might want to
     * search through?". Used by getSearchFields().
     *
     * @param   string  $rawType
     * @return  bool
     */
    private function isSearchableType($rawType)
    {
        $t = strtolower(trim((string) $rawType));
        if ($t === '') {
            // Empty type = unknown, assume string (most common case for legacy fields).
            return true;
        }
        if (($paren = strpos($t, '(')) !== false) {
            $t = substr($t, 0, $paren);
        }
        if (($colon = strpos($t, ':')) !== false) {
            $t = substr($t, 0, $colon);
        }
        return in_array($t, ['varchar', 'string', 'mail', 'email', 'phone', 'url'], true);
    }

    /**
     * Convert a snake_case identifier to lowerCamelCase. Idempotent on a key
     * that already is camelCase (no underscores -> returned as-is).
     *
     * @param   string  $key
     * @return  string
     */
    private static function snakeToCamel($key)
    {
        $key = (string) $key;
        if (strpos($key, '_') === false) {
            return $key;
        }
        $parts = explode('_', $key);
        $first = array_shift($parts);
        $tail = array_map(function ($s) {
            return ucfirst($s);
        }, $parts);
        return $first . implode('', $tail);
    }
}
