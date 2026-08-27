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
     * API key of the localized status label companion, or null to opt out.
     *
     * Consumed by dmTrait::_resolveStatusLabel(), which fills it from
     * getLibStatut(1) while the object is loaded, and preserved by
     * exportMappedDataFiltered() like the FK-label companions. Only emitted for
     * mappers that publish a status field, on objects that implement
     * getLibStatut().
     *
     * Defaults ON: the missing status label is what forced consumers to fetch
     * every row a second time, so opting IN mapper by mapper would have left
     * the problem in place for the twenty-odd types nobody thought to update.
     *
     * @var string|null
     */
    protected $statusLabelKey = 'status_label';

    /**
     * ?include= keys of the export currently running, or null when the caller
     * wants everything. Published by exportMappedDataFiltered() and read by
     * dmTrait::_exportWants() so the per-row companions that cost a query
     * (categories, linked-file count) are only computed when asked for.
     *
     * Request-scoped, always restored by the caller.
     *
     * @var array<int,string>|null
     */
    protected $_exportIncludeKeys = null;

    /**
     * Whether this mapper may serve a list from the list query alone, without
     * fetching each row through its Dolibarr class (see
     * supportsCompactProjection and documentation/facade-list-performance.md).
     *
     * Off by default: turning it on is a claim that this type's export is
     * identical either way, and that claim is only worth what
     * CompactProjectionParityTest proves. Flip it, run the suite, keep it if
     * green.
     *
     * @var bool
     */
    protected $compactProjectionAllowed = false;

    /**
     * Tenant guard for the writable foreign keys of this mapper (write side).
     *
     * WHY. The facade validates the NAMES of the incoming fields
     * ($writableFields) and never their VALUES. The three isolation guards of
     * ObjectController (inEntityScope / isolationDenies / visibilityDenies) all
     * run on the row AS IT STANDS, before the body is parsed: they answer "is
     * this object mine?", never "is what you send me yours?". A PATCH carrying
     * the socid of another tenant's company was therefore written verbatim --
     * not a disclosure (Societe::fetch() keeps its entity clause, so the label
     * resolver returns nothing) but a corruption: the invoice stays in its
     * entity while pointing at a company that does not exist for it.
     *
     * SHAPE. Key = Dolibarr-side field name, exactly as in $writableFields.
     * Value = the target, in one of three forms:
     *
     *   'socid' => 'thirdparty'          // an ObjectRegistry type key: table,
     *                                    // pk, element and has_entity are read
     *                                    // from the registry, so the guard can
     *                                    // never drift from the rest of the
     *                                    // facade
     *
     *   'typeid' => ['table' => 'adherent_type', 'element' => 'adherent_type']
     *                                    // a target that is NOT a registry
     *                                    // type; 'pk' defaults to 'rowid'
     *
     *   'fk_element' => ['polymorphic' => 'elementtype']
     *                                    // the target table is named by a
     *                                    // sibling field of the same payload
     *
     * A registry target flagged has_entity=false (llx_bank) is guarded by
     * REPLAYING its own mapper's isolationWhereSql() -- the very predicate the
     * read routes use -- so a table with no entity column needs no special case
     * here.
     *
     * Declared here without an initializer, like $listOfForeignKeyLabels, so a
     * concrete mapper simply overrides it.
     *
     * @var array<string,string|array<string,mixed>>
     */
    protected $foreignKeyGuards;

    /**
     * Foreign-key-shaped writable fields this mapper deliberately does NOT
     * guard, each mapped to the reason.
     *
     * The contract test (ForeignKeyGuardContractTest) walks the registry and
     * fails on any writable field whose NAME looks like a foreign key and that
     * is neither guarded above nor exempted here: an exemption must be a
     * decision, never an oversight. The reason string is what the failure
     * message shows, so it has to say WHY, not just "not needed".
     *
     * The dictionary columns shared by many mappers are exempted once and for
     * all in $GLOBAL_FK_GUARD_EXEMPTIONS below; this property is for the local
     * cases only. A key declared in $foreignKeyGuards wins over both.
     *
     * @var array<string,string>
     */
    protected $foreignKeyGuardExemptions;

    /**
     * Dictionary columns exempted from the tenant guard for EVERY mapper.
     *
     * These reference reference-data tables that the installer ships and that
     * every tenant reads as-is. Several of them DO carry an `entity` column,
     * which is exactly the trap: their rows are inserted with a hardcoded
     * entity 1 (cf htdocs/install/mysql/data/llx_accounting_abc.sql l.50-56 for
     * the accounting journals, and the same pattern for the c_* tables), so a
     * check "does this key belong to my entity?" would refuse EVERY one of them
     * on every tenant whose entity is not 1. That regression would be far more
     * visible than the defect this mechanism closes -- the lot banque of
     * Dolipocket hit it on c_paiement, whose ids are referenced from a dozen
     * documentary columns.
     *
     * A dictionary key pointing at a row of another tenant writes a wrong
     * reference; it discloses nothing and reparents nothing.
     *
     * EVERY KEY HERE IS AN INTEGER COLUMN, and that is load-bearing, not
     * decorative: getForeignKeyIntegerFields() feeds this list to the same
     * integer normalisation as the guarded keys. Exempting a field means "do
     * not probe the target tenant", NEVER "let a non-integer reach an integer
     * column" -- several core update() methods interpolate these raw, so a
     * string would smuggle a second assignment into the SET clause exactly like
     * a guarded key would. A field that is NOT an integer (Adherent's
     * civility_id holds a CODE, adherent.class.php l.817 writes it quoted) must
     * therefore be documented on its own mapper, not here.
     *
     * @var array<string,string>
     */
    private static $GLOBAL_FK_GUARD_EXEMPTIONS = [
        'fk_cond_reglement'      => 'dictionary llx_c_payment_term, shipped by the installer and read by every tenant',
        'cond_reglement_id'      => 'dictionary llx_c_payment_term (property alias of fk_cond_reglement)',
        'fk_mode_reglement'      => 'dictionary llx_c_paiement, shipped by the installer and read by every tenant',
        'mode_reglement_id'      => 'dictionary llx_c_paiement (property alias of fk_mode_reglement)',
        'fk_c_paiement'          => 'dictionary llx_c_paiement',
        'fk_availability'        => 'dictionary llx_c_availability, no entity column at all',
        'fk_shipping_method'     => 'dictionary llx_c_shipment_mode',
        'shipping_method_id'     => 'dictionary llx_c_shipment_mode (property alias of fk_shipping_method)',
        'fk_input_reason'        => 'dictionary llx_c_input_reason, no entity column at all',
        'fk_departement'         => 'dictionary llx_c_departements, no entity column at all',
        'state_id'               => 'dictionary llx_c_departements (property alias of fk_departement)',
        'fk_pays'                => 'dictionary llx_c_country, no entity column at all',
        'country_id'             => 'dictionary llx_c_country (property alias of fk_pays)',
        'owner_country_id'       => 'dictionary llx_c_country (bank account owner address)',
        'effectif_id'            => 'dictionary llx_c_effectif, no entity column at all',
        'typent_id'              => 'dictionary llx_c_typent, no entity column at all',
        'fk_accountancy_journal' => 'dictionary llx_accounting_journal: it HAS an entity column, but the installer seeds every journal with a hardcoded entity 1 (data/llx_accounting_abc.sql l.50-56), so guarding it would refuse the shipped journals on every tenant that is not entity 1',
    ];

    /**
     * Resolved tenant guards of this mapper (see $foreignKeyGuards).
     *
     * Public because ObjectFacadeTrait reads it from the controllers and the
     * contract test walks it across the whole registry.
     *
     * @return array<string,string|array<string,mixed>>
     */
    public function getForeignKeyGuards()
    {
        return (isset($this->foreignKeyGuards) && is_array($this->foreignKeyGuards))
            ? $this->foreignKeyGuards
            : [];
    }

    /**
     * Resolved exemptions: the global dictionary list, overridable per mapper.
     *
     * @return array<string,string>
     */
    public function getForeignKeyGuardExemptions()
    {
        $local = (isset($this->foreignKeyGuardExemptions) && is_array($this->foreignKeyGuardExemptions))
            ? $this->foreignKeyGuardExemptions
            : [];

        return array_merge(self::$GLOBAL_FK_GUARD_EXEMPTIONS, $local);
    }

    /**
     * Memoised extrafield write targets, keyed by the NORMALISED parent element.
     *
     * fetch_name_optionals_label() has no cache of its own and ignores its
     * $forceload argument (extrafields.class.php l.833, l.852-853): it runs one
     * SELECT per call. Resolving a 'link' target costs more still (a
     * dol_include_once plus a constructor, to read table_element). Without this
     * cache a list of 200 rows would pay both 200 times.
     *
     * @var array<string,array<string,array<string,mixed>>>|null
     */
    private static $EXTRAFIELD_WRITE_TARGETS = [];

    /**
     * TENANT-BEARING TARGETS of the extrafields this mapper opens for WRITE.
     *
     * WHY THIS EXISTS. $writableFields closes the native columns, and
     * $foreignKeyGuards makes each of their foreign keys tenant-checked. The
     * extrafields opened by $extrafieldsRW went through NEITHER: they travel as
     * 'options_*' keys straight into $object->array_options, and a Dolibarr
     * extrafield of type 'link' holds exactly what a native fk_ column holds --
     * the rowid of another object. A PATCH could therefore point a custom field
     * at another tenant's company through the very door $foreignKeyGuards was
     * built to close. The spec anticipated it (TODO section 9.3: "extend the
     * foreign-key guards to link-typed extrafields BEFORE opening the write");
     * the write was opened first, so this closes it after the fact.
     *
     * Deliberately NOT merged into $foreignKeyGuards, for two verified reasons:
     *   a) ForeignKeyGuardTrait casts every guarded key with (int) -- correct for
     *      a 'link' (int(11) column) and destructive for a 'sellist' or a
     *      'chkbxlst' (varchar(255), possibly a comma-separated list);
     *   b) ForeignKeyGuardContractTest::testNoGuardIsDeclaredOnANonWritableField
     *      would flag every entry as an orphan, since its writableFieldsOf()
     *      helper does not read $extrafieldsRW.
     *
     * SHAPE. 'options_<name>' => ['mode' => ..., 'target' => ..., 'reason' => ...]
     *   mode 'int'       : type 'link'. Value is a rowid in an int column.
     *   mode 'id'        : type 'sellist' whose key field is rowid. Value is a
     *                      rowid in a varchar column -- probed, never cast.
     *   mode 'csv'       : type 'chkbxlst' whose key field is rowid. Value is a
     *                      comma-separated list of rowids.
     *   mode 'unguarded' : nothing to check (plain scalar type, a target outside
     *                      the registry, or a list keyed by a CODE rather than a
     *                      rowid). Carries 'reason' for the log line.
     *   mode 'refuse'    : the declaration itself is broken (extrafield absent
     *                      from llx_extrafields, unusable param, link class that
     *                      cannot be loaded). Fail-closed: the mapper opened this
     *                      field on purpose, so a broken setup must break loudly
     *                      rather than write unchecked. Carries 'reason'.
     *
     * 'target' is a registry type KEY, so pk / element / has_entity come from the
     * single registry entry (see ObjectRegistry::typeForTable).
     *
     * @return array<string,array<string,mixed>>
     */
    public function getExtrafieldWriteTargets()
    {
        $parentElement = (string) ($this->parentTableElementToUseForExtraFields ?? '');
        $writableExtras = $this->writableExtrafieldNames();

        if ($parentElement === '' || empty($writableExtras)) {
            return [];
        }

        // Dolibarr normalises a handful of element types before storing them in
        // llx_extrafields.elementtype (extrafields.class.php l.842-850). Index
        // the cache on the normalised value or every lookup misses.
        $normalised = $parentElement;
        if ($normalised === 'thirdparty') {
            $normalised = 'societe';
        } elseif ($normalised === 'contact') {
            $normalised = 'socpeople';
        } elseif ($normalised === 'order_supplier') {
            $normalised = 'commande_fournisseur';
        }

        $cacheKey = $normalised . '|' . implode(',', $writableExtras);
        if (isset(self::$EXTRAFIELD_WRITE_TARGETS[$cacheKey])) {
            return self::$EXTRAFIELD_WRITE_TARGETS[$cacheKey];
        }

        $attributes = $this->loadExtrafieldAttributes($normalised);
        $targets = [];
        foreach ($writableExtras as $name) {
            $spec = $this->resolveExtrafieldTarget($normalised, $name, $attributes);

            // Announce every field left unguarded, ONCE per process thanks to
            // the memoisation below -- not once per request, which would drown
            // the log. Silence here would be the worst outcome: an extrafield
            // that references another object and is not tenant-checked has to be
            // visible to whoever reads the logs, exactly as
            // ForeignKeyGuardTrait::resolvePolymorphicTarget announces its own
            // unguarded case.
            if (($spec['mode'] ?? '') === 'unguarded' && !empty($spec['reason'])) {
                dol_syslog(
                    "[SmartAuth] " . static::class . ": " . (string) $spec['reason'] . " - left unguarded",
                    LOG_WARNING
                );
            }

            $targets['options_' . $name] = $spec;
        }

        self::$EXTRAFIELD_WRITE_TARGETS[$cacheKey] = $targets;

        return $targets;
    }

    /**
     * Extrafield attribute names this mapper accepts on write.
     *
     * Reads BOTH declaration paths, because both work today: $extrafieldsRW is
     * the documented one, but dmTrait::importMappedData() builds its reverse map
     * with an OR (l.491-496), so an 'options_xxx' key placed directly in
     * $writableFields opens the very same door -- and a guard that only walked
     * $extrafieldsRW would leave that second door unwatched.
     *
     * @return array<int,string>  Attribute names, without the 'options_' prefix.
     */
    private function writableExtrafieldNames()
    {
        $names = [];

        if (isset($this->extrafieldsRW) && is_array($this->extrafieldsRW)) {
            foreach ($this->extrafieldsRW as $ef) {
                $ef = (string) $ef;
                $names[] = (strncmp($ef, 'options_', 8) === 0) ? substr($ef, 8) : $ef;
            }
        }

        if (isset($this->writableFields) && is_array($this->writableFields)) {
            foreach ($this->writableFields as $field) {
                $field = (string) $field;
                if (strncmp($field, 'options_', 8) === 0) {
                    $names[] = substr($field, 8);
                }
            }
        }

        $names = array_values(array_unique(array_filter($names, 'strlen')));
        sort($names);

        return $names;
    }

    /**
     * Load the Dolibarr extrafield descriptors of an element.
     *
     * Through ExtraFields::fetch_name_optionals_label(), NOT through a hand-made
     * SELECT on llx_extrafields: that table's entity filtering is done in PHP by
     * Dolibarr (extrafields.class.php l.861 shows the SQL clause deliberately
     * commented out, l.872 does the filtering), so a home-made query would see
     * the other entities' definitions. Reusing the core loader is what keeps this
     * multi-entity safe.
     *
     * @param  string $element  Normalised element type.
     * @return array<string,array<string,mixed>>  ['type' => [...], 'param' => [...]]
     */
    private function loadExtrafieldAttributes($element)
    {
        global $db;

        try {
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $ef = new \ExtraFields($db);
            $ef->fetch_name_optionals_label($element);

            return is_array($ef->attributes[$element] ?? null) ? $ef->attributes[$element] : [];
        } catch (\Throwable $e) {
            dol_syslog(
                "[SmartAuth] dmBase::getExtrafieldWriteTargets could not load extrafields for " . $element . ": " . $e->getMessage(),
                LOG_ERR
            );

            return [];
        }
    }

    /**
     * Classify ONE writable extrafield: does its value reference another
     * object, and if so which registry type?
     *
     * @param  string $element     Normalised element type (log only).
     * @param  string $name        Extrafield attribute name.
     * @param  array  $attributes  Output of loadExtrafieldAttributes().
     * @return array<string,mixed>  Entry of getExtrafieldWriteTargets().
     */
    private function resolveExtrafieldTarget($element, $name, $attributes)
    {
        $type = (string) ($attributes['type'][$name] ?? '');
        if ($type === '') {
            return [
                'mode' => 'refuse',
                'reason' => 'extrafield "' . $name . '" is opened for write by ' . static::class
                    . ' but has no definition in llx_extrafields for element ' . $element,
            ];
        }

        // Every other type (varchar, int, date, select, radio, checkbox...)
        // holds a scalar of its own: no target, nothing to guard.
        if ($type !== 'link' && $type !== 'sellist' && $type !== 'chkbxlst') {
            return ['mode' => 'unguarded', 'reason' => 'type ' . $type . ' holds no object reference'];
        }

        $param = $attributes['param'][$name] ?? null;
        // jsonOrUnserialize() (functions.lib.php) returns '' or false on a
        // malformed param, and the admin screen accepts a raw string, so this is
        // never guaranteed to be an array.
        if (!is_array($param) || empty($param['options']) || !is_array($param['options'])) {
            return [
                'mode' => 'refuse',
                'reason' => 'extrafield "' . $name . '" is of type ' . $type . ' but carries no usable param.options',
            ];
        }

        $descriptor = (string) key($param['options']);
        if ($descriptor === '') {
            return [
                'mode' => 'refuse',
                'reason' => 'extrafield "' . $name . '" of type ' . $type . ' has an empty target descriptor',
            ];
        }

        if ($type === 'link') {
            return $this->resolveLinkExtrafieldTarget($name, $descriptor);
        }

        return $this->resolveListExtrafieldTarget($name, $type, $descriptor);
    }

    /**
     * Target of a 'link' extrafield, whose descriptor names a PHP class:
     * "Societe:societe/class/societe.class.php". The table is read from the
     * instantiated class (table_element), never guessed from the class name.
     *
     * @param  string $name
     * @param  string $descriptor
     * @return array<string,mixed>
     */
    private function resolveLinkExtrafieldTarget($name, $descriptor)
    {
        global $db, $hookmanager;

        $parts = explode(':', $descriptor);
        $className = (string) ($parts[0] ?? '');
        $classPath = (string) ($parts[1] ?? '');

        if ($className === '' || $classPath === '') {
            return [
                'mode' => 'refuse',
                'reason' => 'link extrafield "' . $name . '" has a malformed descriptor "' . $descriptor . '"',
            ];
        }

        if (!class_exists($className)) {
            dol_include_once($classPath);
        }
        if (!class_exists($className)) {
            // Dolibarr itself refuses to render such a field
            // (extrafields.class.php l.1908-1911). A field whose class cannot be
            // loaded -- a disabled module, a typo -- must not be written blind.
            return [
                'mode' => 'refuse',
                'reason' => 'link extrafield "' . $name . '" targets class ' . $className . ' which cannot be loaded from ' . $classPath,
            ];
        }

        try {
            $target = new $className($db);
            $table = (string) ($target->table_element ?? '');
        } catch (\Throwable $e) {
            return [
                'mode' => 'refuse',
                'reason' => 'link extrafield "' . $name . '" targets class ' . $className . ' which cannot be instantiated: ' . $e->getMessage(),
            ];
        }

        if ($table === '') {
            return [
                'mode' => 'refuse',
                'reason' => 'link extrafield "' . $name . '" targets class ' . $className . ' which declares no table_element',
            ];
        }

        $registryType = \SmartAuth\Api\ObjectRegistry::typeForTable($table, is_object($hookmanager) ? $hookmanager : null);
        if ($registryType === null) {
            // Same verdict as an unregistered polymorphic element
            // (ForeignKeyGuardTrait::resolvePolymorphicTarget): smartauth knows
            // neither this table's entity column nor its isolation predicate, and
            // refusing would break every module linking its OWN objects.
            return [
                'mode' => 'unguarded',
                'reason' => 'link extrafield "' . $name . '" targets llx_' . $table . ', which backs no registry type',
            ];
        }

        return ['mode' => 'int', 'target' => $registryType];
    }

    /**
     * Target of a 'sellist' / 'chkbxlst' extrafield, whose descriptor names a
     * TABLE: "societe:nom:rowid::filter" (table, label field, key field...).
     *
     * The key field decides whether there is anything to guard at all: Dolibarr
     * defaults it to rowid (extrafields.class.php l.1195), in which case the
     * stored value IS a row id; when it names another column the stored value is
     * a CODE, which references nothing tenant-borne.
     *
     * @param  string $name
     * @param  string $type        'sellist' or 'chkbxlst'
     * @param  string $descriptor
     * @return array<string,mixed>
     */
    private function resolveListExtrafieldTarget($name, $type, $descriptor)
    {
        global $hookmanager;

        $parts = explode(':', $descriptor);
        $table = (string) ($parts[0] ?? '');
        if ($table === '') {
            return [
                'mode' => 'refuse',
                'reason' => $type . ' extrafield "' . $name . '" has a malformed descriptor "' . $descriptor . '"',
            ];
        }

        $keyField = empty($parts[2]) ? 'rowid' : (string) $parts[2];
        if ($keyField !== 'rowid') {
            return [
                'mode' => 'unguarded',
                'reason' => $type . ' extrafield "' . $name . '" is keyed on ' . $keyField . ', so it stores a code and not a row id',
            ];
        }

        $registryType = \SmartAuth\Api\ObjectRegistry::typeForTable($table, is_object($hookmanager) ? $hookmanager : null);
        if ($registryType === null) {
            // The common case by far: a dictionary (llx_c_*) or a module's own
            // table. Dictionaries are shipped seeded with a hardcoded entity 1
            // (see $GLOBAL_FK_GUARD_EXEMPTIONS above), so guarding them would
            // refuse legitimate values on every tenant that is not entity 1.
            return [
                'mode' => 'unguarded',
                'reason' => $type . ' extrafield "' . $name . '" targets llx_' . $table . ', which backs no registry type',
            ];
        }

        return ['mode' => ($type === 'chkbxlst' ? 'csv' : 'id'), 'target' => $registryType];
    }

    /**
     * Fields that MUST reach the database as integers: the guarded keys plus
     * the global dictionary keys.
     *
     * Read by ObjectFacadeTrait::foreignKeyViolation(), which narrows each of
     * them with an (int) cast. Guarding a key already implied it; the dictionary
     * keys need it just as much and were the hole: importMappedData() only casts
     * a field whose Dolibarr-side name is a key of the class $fields, so the
     * property aliases (cond_reglement_id, mode_reglement_id,
     * shipping_method_id, ...) come out as STRINGS -- and
     * CommandeFournisseur::update() l.1690-1691, Reception::update() l.970 and
     * their siblings interpolate them with no quote, no cast and no escape. A
     * payload of "0, fk_soc=<foreign id>" on payment_terms therefore reparented
     * the document while never touching a guarded key at all.
     *
     * The per-mapper $foreignKeyGuardExemptions are deliberately NOT included:
     * they are free-form documentation and may describe a field that is not an
     * integer (Adherent::$civility_id holds a code).
     *
     * @return array<int,string>
     */
    public function getForeignKeyIntegerFields()
    {
        return array_values(array_unique(array_merge(
            array_keys($this->getForeignKeyGuards()),
            array_keys(self::$GLOBAL_FK_GUARD_EXEMPTIONS)
        )));
    }

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
    /**
     * Dolibarr legacy write-aliases: a handful of FK columns are persisted by
     * the object's create()/update() SQL from a DIFFERENT property than the
     * $fields column name. Facture/Commande/Propal/CommandeFournisseur all
     * write `fk_cond_reglement = $this->cond_reglement_id` (never
     * $this->fk_cond_reglement) and `fk_mode_reglement = $this->mode_reglement_id`.
     * A facade update that only sets the $fields column name would be silently
     * dropped, so we mirror the value onto the alias the SQL actually reads.
     *
     * @var array<string,string>  columnName => aliasProperty
     */
    private static $WRITE_ALIASES = [
        'fk_cond_reglement' => 'cond_reglement_id',
        'fk_mode_reglement' => 'mode_reglement_id',
    ];

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
                // Mirror onto the legacy alias property the create()/update()
                // SQL reads for this column (see $WRITE_ALIASES). Harmless for
                // classes that read the column property directly.
                if (isset(self::$WRITE_ALIASES[$field])) {
                    $object->{self::$WRITE_ALIASES[$field]} = $value;
                }
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
        // Publish the requested keys BEFORE the export so the companions that
        // cost a query per row (categories, linked-file count) can skip
        // themselves when nobody asked. Restored right after: the mapper
        // instance is reused across the rows of a page and across requests.
        $previousInclude = $this->_exportIncludeKeys;
        $this->_exportIncludeKeys = $includeKeys;
        try {
            $full = $this->exportMappedData($obj);
        } finally {
            $this->_exportIncludeKeys = $previousInclude;
        }

        if ($includeKeys === null) {
            return $full;
        }
        if (!is_array($includeKeys) || empty($includeKeys)) {
            // Empty array = no business keys requested; only structural ones survive.
            $includeKeys = [];
        }

        $structuralKeys = ['lines', 'categories', 'nb_linked_files', 'linked_files'];
        // status_label is NOT structural: it is only produced when ?include=
        // names it (see dmTrait::_resolveStatusLabel), so it is already in the
        // allowed set when present. Listing it here would be harmless but
        // misleading -- it would suggest the label rides along unrequested,
        // which is exactly what the compact list path cannot guarantee.
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
     * Can this list be served from the list query alone, without fetching every
     * row through its Dolibarr class?
     *
     * WHY. The facade builds ONE efficient list query, then reloads each row in
     * full: 1 + 2N queries for a page of N. Measured at x276 against a compact
     * SELECT (50 companies, SQLite in memory, the case most favourable to the
     * facade). That price buys mapped objects complete with foreign-key labels,
     * extrafields and computed fields -- but it is paid even when the consumer
     * asked for three columns. See documentation/facade-list-performance.md.
     *
     * The intent is already expressed by ?include=; this reads it.
     *
     * FAIL-CLOSED, and deliberately narrow. A key qualifies only when its
     * Dolibarr-side name is a REAL column of the object's $fields, which is the
     * same test the catalog uses for `filterable` and guarantees the PHP
     * property carries the column name (dmThirdparty publishes `name` for the
     * SQL column `nom`: not a real column, so it disqualifies the whole page).
     * Any doubt -> null -> the full path. A slow list beats a wrong one.
     *
     * @param  array<int,string>|null $includeKeys  Keys from ?include=, null = all
     * @return bool  true when the compact path is safe for this exact request
     */
    public function supportsCompactProjection($includeKeys)
    {
        global $db;

        // OPT-IN PER MAPPER, and it is not timidity. Building the object from a
        // raw row instead of fetch() diverges wherever a class does more than
        // read its columns, and those places are not predictable from the
        // outside: MouvementStock::fetch() returns price as a float where the
        // raw row yields an int, Contact keeps socid rather than the fk_soc
        // column, Product reads $status but stores `tosell`. Every one of those
        // was found by CompactProjectionParityTest, none by reading the code.
        // So a type joins the fast path when its parity is DEMONSTRATED, not
        // when it looks safe: flip $compactProjectionAllowed, run the parity
        // suite, keep it only if green.
        if (empty($this->compactProjectionAllowed)) {
            return false;
        }

        // "Everything" means extrafields, FK nesting, computed fields: full path.
        if ($includeKeys === null || !is_array($includeKeys) || empty($includeKeys)) {
            return false;
        }
        if (empty($this->listOfPublishedFields) || !is_array($this->listOfPublishedFields)) {
            return false;
        }

        // Documents with lines: fetch() loads $obj->lines and the export emits
        // them as a structural key. The list query knows nothing about lines, so
        // the compact path would silently serve line-less documents. Refuse
        // outright rather than invent a second shape for the same type.
        if (!empty($this->listOfPublishedFieldsForLines) && is_array($this->listOfPublishedFieldsForLines)) {
            return false;
        }

        // FK-label companions (thirdpartyName, ...) are emitted unconditionally
        // and read the PHP property fetch() fills -- Contact carries socid, not
        // the fk_soc column, so a row-hydrated object resolves no label and the
        // companion would come back empty. Refuse: the label is part of what
        // consumers already receive, and a silently empty one is worse than a
        // slower list.
        if (!empty($this->listOfForeignKeyLabels) && is_array($this->listOfForeignKeyLabels)) {
            return false;
        }

        $dolibarrClassName = $this->dolibarrClassName ?? null;
        if (empty($dolibarrClassName) || !class_exists($dolibarrClassName)) {
            return false;
        }

        // The compact path hydrates through CommonObject::setVarsFromFetchObj,
        // which is what applies the declared type conversions (dates through
        // jdate, ints, floats). Without it we would be inventing a second,
        // divergent hydration.
        try {
            $probe = new $dolibarrClassName($db);
        } catch (\Throwable $e) {
            return false;
        }
        if (!method_exists($probe, 'setVarsFromFetchObj')) {
            return false;
        }
        $rawFields = (property_exists($probe, 'fields') && is_array($probe->fields)) ? $probe->fields : [];
        if (empty($rawFields)) {
            return false;
        }

        // appside -> doliside, to read the request in the mapper's own terms.
        $bySide = [];
        foreach ($this->listOfPublishedFields as $doliside => $appside) {
            $bySide[(string) $appside] = (string) $doliside;
        }

        foreach ($includeKeys as $key) {
            $key = (string) $key;
            if ($key === 'id') {
                continue;   // always carried, always the primary key
            }
            if (!isset($bySide[$key])) {
                // Unknown key, or a derived / companion field: the full export
                // is the only thing that knows how to produce it.
                return false;
            }
            $doliside = $bySide[$key];

            if (strncmp($doliside, 'options_', 8) === 0) {
                return false;   // extrafield: needs fetch_optionals()
            }
            if (!empty($this->listOfForeignKeys) && array_key_exists($doliside, (array) $this->listOfForeignKeys)) {
                return false;   // nested FK object: needs the related fetch
            }
            // A per-field export filter may read anything on the object, not
            // just the column, so a partially hydrated object could feed it
            // different input.
            if (is_callable([$this, 'fieldFilterValue' . ucfirst($doliside)])) {
                return false;
            }
            if (!isset($rawFields[$doliside])) {
                return false;   // not a real column of the table
            }
        }

        return true;
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
