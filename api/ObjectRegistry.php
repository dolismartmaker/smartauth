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

namespace SmartAuth\Api;

/**
 * Single source of truth for the "core Dolibarr object" registry shared by the
 * offline sync engine (SyncController) and the synchronous REST facade
 * (ObjectController / objects/{type}).
 *
 * Before this class the per-type configuration (Dolibarr class, table, element,
 * write-permission map, allowed_fields, mapper class) was duplicated in three
 * divergent places: SyncController::loadSyncableObjects(),
 * SyncController::resolveMapperClass() and, for documents only,
 * ObjectDocumentController::$objectTypeConfig. The first two now delegate here.
 * ObjectDocumentController stays independent for now (it carries document-only
 * keys such as modulepart/subdir_method that are out of scope for the CRUD
 * facade).
 *
 * Each built-in config exposes:
 *   - class            : Dolibarr CommonObject class name (e.g. 'Societe')
 *   - file             : absolute path to require_once before instantiation
 *   - table            : llx_ table suffix (no prefix), for the list queries
 *   - element          : Dolibarr element code, used for getEntity() scoping
 *   - label            : human label
 *   - module           : Dolibarr module code, used for isModEnabled()
 *   - priority         : sync hint (high/medium/low)
 *   - default_enabled  : whether sync enables the type by default
 *   - rights           : per-action Dolibarr right args passed to User::hasRight()
 *   - allowed_fields   : sync-side write allowlist (payload keys)
 *   - mapper           : fully qualified SmartAuth\DolibarrMapping\dm* class
 *   - alias            : SQL table alias used by the facade list query
 *   - default_sort     : facade default ORDER BY clause (without "ORDER BY ")
 *
 * The 'mapper', 'alias' and 'default_sort' keys are additive: sync ignores keys
 * it does not read, so seeding syncableObjects from this registry keeps the
 * sync engine byte-compatible with its previous inline definitions.
 */
class ObjectRegistry
{
    /**
     * The hook name modules use to register additional syncable/facadable
     * object types. Reused verbatim from the historical sync contract so a
     * module that already registers a syncable object also becomes reachable
     * through the REST facade with no extra wiring.
     */
    const HOOK_NAME = 'smartmaker_registerSyncableObjects';

    /**
     * Built-in core-object definitions (Vague 1: thirdparty, contact, product,
     * category). Returned fresh on each call because the 'file' paths depend on
     * the DOL_DOCUMENT_ROOT runtime constant.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function builtins()
    {
        return [
            'thirdparty' => [
                'class' => 'Societe',
                'file' => DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php',
                'table' => 'societe',
                'element' => 'societe',
                'label' => 'ThirdParties',
                'module' => 'societe',
                'priority' => 'high',
                'default_enabled' => true,
                // Dolibarr permission required per write action. Arguments are
                // forwarded as-is to User::hasRight($module, $perm1[, $perm2]).
                'rights' => [
                    'read'   => ['societe', 'lire'],
                    'create' => ['societe', 'creer'],
                    'update' => ['societe', 'creer'],
                    'delete' => ['societe', 'supprimer'],
                ],
                'allowed_fields' => [
                    'name', 'name_alias',
                    'email', 'phone', 'fax', 'url',
                    'address', 'zip', 'town', 'country_id', 'state_id',
                    'client', 'fournisseur',
                    'code_client', 'code_fournisseur',
                    'note_public', 'note_private',
                    'siren', 'siret', 'ape',
                    'idprof4', 'idprof5', 'idprof6',
                    'capital', 'tva_assuj', 'tva_intra',
                    'gencod', 'barcode',
                    'effectif_id', 'forme_juridique_code', 'typent_id',
                    'outstanding_limit',
                    'mode_reglement_id', 'cond_reglement_id',
                    'status',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmThirdparty',
                'alias' => 's',
                'default_sort' => 's.nom ASC, s.rowid ASC',
            ],
            'contact' => [
                'class' => 'Contact',
                'file' => DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php',
                'table' => 'socpeople',
                'element' => 'contact',
                'label' => 'Contacts',
                'module' => 'societe',
                'priority' => 'high',
                'default_enabled' => true,
                // Contacts use the societe->contact sub-permission.
                'rights' => [
                    'read'   => ['societe', 'contact', 'lire'],
                    'create' => ['societe', 'contact', 'creer'],
                    'update' => ['societe', 'contact', 'creer'],
                    'delete' => ['societe', 'contact', 'supprimer'],
                ],
                'allowed_fields' => [
                    'lastname', 'firstname', 'civility_id',
                    'address', 'zip', 'town', 'country_id',
                    'email', 'phone_pro', 'phone_mobile', 'phone_perso', 'fax',
                    'fk_soc', 'socid',
                    'no_email',
                    'note_public', 'note_private',
                    'poste', 'birthday',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmContact',
                'alias' => 'sp',
                'default_sort' => 'sp.lastname ASC, sp.firstname ASC, sp.rowid ASC',
            ],
            'product' => [
                'class' => 'Product',
                'file' => DOL_DOCUMENT_ROOT . '/product/class/product.class.php',
                'table' => 'product',
                'element' => 'product',
                'label' => 'Products',
                'module' => 'product',
                'priority' => 'medium',
                'default_enabled' => true,
                // Product permissions live under the 'produit' rights class.
                'rights' => [
                    'read'   => ['produit', 'lire'],
                    'create' => ['produit', 'creer'],
                    'update' => ['produit', 'creer'],
                    'delete' => ['produit', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref', 'label', 'description',
                    'status', 'status_buy', 'status_batch',
                    'finished', 'type',
                    'customcode', 'country_id',
                    'weight', 'weight_units',
                    'length', 'length_units',
                    'surface', 'surface_units',
                    'volume', 'volume_units',
                    'price', 'price_ttc',
                    'price_min', 'price_min_ttc',
                    'price_label',
                    'tva_tx', 'barcode',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmProduct',
                'alias' => 'p',
                'default_sort' => 'p.ref ASC, p.rowid ASC',
            ],
            'category' => [
                'class' => 'Categorie',
                'file' => DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php',
                'table' => 'categorie',
                'element' => 'categorie',
                'label' => 'Categories',
                'module' => 'categorie',
                'priority' => 'low',
                'default_enabled' => true,
                'rights' => [
                    'read'   => ['categorie', 'lire'],
                    'create' => ['categorie', 'creer'],
                    'update' => ['categorie', 'creer'],
                    'delete' => ['categorie', 'supprimer'],
                ],
                'allowed_fields' => [
                    'label', 'description', 'color', 'type', 'fk_parent',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmCategory',
                'alias' => 'c',
                'default_sort' => 'c.label ASC, c.rowid ASC',
            ],
        ];
    }

    /**
     * Resolve the full registry = built-ins merged with hook-registered types,
     * with the object_type key stamped onto each config.
     *
     * Reproduces exactly what SyncController::loadSyncableObjects() used to do
     * inline (built-ins, smartmaker_registerSyncableObjects hook merge,
     * object_type self-stamp), so sync behaviour is unchanged.
     *
     * @param  object|null $hookmanager  Dolibarr HookManager (global $hookmanager)
     * @return array<string,array<string,mixed>>
     */
    public static function resolveWithHooks($hookmanager = null)
    {
        $objects = self::builtins();

        if (is_object($hookmanager)) {
            $parameters = [];
            $hookObjects = [];
            $action = '';

            $hookmanager->initHooks(['smartmaker']);
            $reshook = $hookmanager->executeHooks(
                self::HOOK_NAME,
                $parameters,
                $hookObjects,
                $action
            );

            if ($reshook >= 0 && is_array($hookObjects) && !empty($hookObjects)) {
                $objects = array_merge($objects, $hookObjects);
            }
        }

        // Self-stamp the object_type key onto each config so downstream helpers
        // (mapper resolution, FK validation, permission checks) can recover the
        // type from a $config alone.
        foreach ($objects as $type => &$cfg) {
            if (is_array($cfg)) {
                $cfg['object_type'] = $type;
            }
        }
        unset($cfg);

        return $objects;
    }

    /**
     * Config for a single type, or null when unknown.
     *
     * @param  string      $type
     * @param  object|null $hookmanager
     * @return array<string,mixed>|null
     */
    public static function get($type, $hookmanager = null)
    {
        $all = self::resolveWithHooks($hookmanager);
        return $all[$type] ?? null;
    }

    /**
     * Whether a type is registered.
     *
     * @param  string      $type
     * @param  object|null $hookmanager
     * @return bool
     */
    public static function has($type, $hookmanager = null)
    {
        return self::get($type, $hookmanager) !== null;
    }

    /**
     * List of registered type keys.
     *
     * @param  object|null $hookmanager
     * @return array<int,string>
     */
    public static function types($hookmanager = null)
    {
        return array_keys(self::resolveWithHooks($hookmanager));
    }
}
