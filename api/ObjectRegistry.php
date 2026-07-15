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
 *   - pk               : primary key column of the table (optional, default
 *                        'rowid'). A few Dolibarr tables use 'id' instead
 *                        (e.g. llx_actioncomm); the facade list/count queries
 *                        read this so they never assume 'rowid'.
 *   - has_entity       : whether the table has an 'entity' column (optional,
 *                        default true). A few tables (llx_stock_mouvement,
 *                        llx_subscription) have none; set false so the list/
 *                        count queries omit the entity filter.
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

            // ===== Vague 2: documents a lignes + gestion projet =====
            // These are NOT enabled for offline sync by default (default_enabled
            // false): the synchronous REST facade is their online access path.
            // allowed_fields mirrors each mapper's $writableFields (defence in
            // depth for the sync legacy path; the mapper path is primary).
            'order' => [
                'class' => 'Commande',
                'file' => DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php',
                'table' => 'commande',
                'element' => 'commande',
                'label' => 'Orders',
                'module' => 'commande',
                'priority' => 'medium',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['commande', 'lire'],
                    'create' => ['commande', 'creer'],
                    'update' => ['commande', 'creer'],
                    'delete' => ['commande', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_customer', 'socid', 'fk_project', 'date', 'date_livraison',
                    'fk_cond_reglement', 'fk_mode_reglement', 'fk_availability',
                    'fk_shipping_method', 'fk_input_reason', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmOrder',
                'alias' => 'c',
                'default_sort' => 'c.rowid DESC',
                // Document lines are writable through ObjectLineController
                // (add/update/delete/reorder) via DocumentLineInvoker.
                'supports_lines' => true,
                // Workflow actions exposed via ObjectActionController
                // (POST objects/order/{id}/actions/{action}), dispatched by
                // DocumentActionInvoker.
                'actions' => ['validate', 'setdraft', 'classifybilled', 'close', 'cancel'],
            ],
            'invoice' => [
                'class' => 'Facture',
                'file' => DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php',
                'table' => 'facture',
                'element' => 'facture',
                'label' => 'Invoices',
                'module' => 'facture',
                'priority' => 'medium',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['facture', 'lire'],
                    'create' => ['facture', 'creer'],
                    'update' => ['facture', 'creer'],
                    'delete' => ['facture', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_customer', 'socid', 'fk_project', 'date', 'date_lim_reglement',
                    'delivery_date', 'fk_cond_reglement', 'fk_mode_reglement',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmInvoice',
                'alias' => 'f',
                'default_sort' => 'f.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'setpaid', 'setunpaid', 'setcanceled'],
                // Customer payments via ObjectPaymentController
                // (POST/GET objects/invoice/{id}/payments).
                'payment' => [
                    'class' => 'Paiement',
                    'file' => DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php',
                ],
            ],
            'proposal' => [
                'class' => 'Propal',
                'file' => DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php',
                'table' => 'propal',
                'element' => 'propal',
                'label' => 'Proposals',
                'module' => 'propal',
                'priority' => 'medium',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['propal', 'lire'],
                    'create' => ['propal', 'creer'],
                    'update' => ['propal', 'creer'],
                    'delete' => ['propal', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_client', 'socid', 'fk_project', 'date', 'fin_validite',
                    'delivery_date', 'fk_cond_reglement', 'fk_mode_reglement',
                    'fk_availability', 'fk_shipping_method', 'fk_input_reason',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmProposal',
                'alias' => 'p',
                'default_sort' => 'p.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'classifybilled', 'closesign', 'closeunsign'],
            ],
            'project' => [
                'class' => 'Project',
                'file' => DOL_DOCUMENT_ROOT . '/projet/class/project.class.php',
                'table' => 'projet',
                'element' => 'project',
                'label' => 'Projects',
                'module' => 'projet',
                'priority' => 'medium',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['projet', 'lire'],
                    'create' => ['projet', 'creer'],
                    'update' => ['projet', 'creer'],
                    'delete' => ['projet', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref', 'title', 'description', 'dateo', 'datee', 'socid',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmProject',
                'alias' => 'proj',
                'default_sort' => 'proj.rowid DESC',
            ],
            'task' => [
                'class' => 'Task',
                'file' => DOL_DOCUMENT_ROOT . '/projet/class/task.class.php',
                'table' => 'projet_task',
                'element' => 'project_task',
                'label' => 'Tasks',
                'module' => 'projet',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['projet', 'lire'],
                    'create' => ['projet', 'creer'],
                    'update' => ['projet', 'creer'],
                    'delete' => ['projet', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref', 'label', 'description', 'fk_project', 'fk_task_parent',
                    'date_start', 'date_end', 'planned_workload', 'progress', 'priority',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmTask',
                'alias' => 'pt',
                'default_sort' => 'pt.rowid DESC',
            ],
            'agenda_event' => [
                'class' => 'ActionComm',
                'file' => DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php',
                'table' => 'actioncomm',
                'element' => 'actioncomm',
                'label' => 'Events',
                'module' => 'agenda',
                'priority' => 'low',
                'default_enabled' => false,
                // llx_actioncomm's primary key is 'id', not 'rowid'.
                'pk' => 'id',
                // Agenda uses nested rights agenda->myactions->{read,create,delete}.
                'rights' => [
                    'read'   => ['agenda', 'myactions', 'read'],
                    'create' => ['agenda', 'myactions', 'create'],
                    'update' => ['agenda', 'myactions', 'create'],
                    'delete' => ['agenda', 'myactions', 'delete'],
                ],
                'allowed_fields' => [
                    'label', 'datep', 'datef', 'duree', 'fk_soc', 'fk_contact',
                    'fk_projet', 'location', 'percent', 'priority',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmAgendaEvent',
                'alias' => 'a',
                'default_sort' => 'a.datep DESC, a.id DESC',
            ],
            'user' => [
                'class' => 'User',
                'file' => DOL_DOCUMENT_ROOT . '/user/class/user.class.php',
                'table' => 'user',
                'element' => 'user',
                'label' => 'Users',
                // No 'module' key: user management is a core capability, always
                // available; an isModEnabled('user') gate would wrongly 403.
                'priority' => 'low',
                'default_enabled' => false,
                // Nested rights user->user->{lire,creer,supprimer}. Writes are
                // allowed but dmUser::$writableFields excludes login/pass*/admin/
                // statut, so no privilege escalation through the facade.
                'rights' => [
                    'read'   => ['user', 'user', 'lire'],
                    'create' => ['user', 'user', 'creer'],
                    'update' => ['user', 'user', 'creer'],
                    'delete' => ['user', 'user', 'supprimer'],
                ],
                'allowed_fields' => [
                    'civility_code', 'lastname', 'firstname', 'gender', 'email',
                    'office_phone', 'user_mobile', 'job', 'address', 'zip', 'town',
                    'state_id', 'country_id',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmUser',
                'alias' => 'u',
                'default_sort' => 'u.lastname ASC, u.firstname ASC, u.rowid ASC',
            ],

            // ===== Vague 3: stock, associatif, contrats, tickets, interventions =====
            'warehouse' => [
                'class' => 'Entrepot',
                'file' => DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php',
                'table' => 'entrepot',
                'element' => 'stock',
                'label' => 'Warehouses',
                'module' => 'stock',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['stock', 'lire'],
                    'create' => ['stock', 'creer'],
                    'update' => ['stock', 'creer'],
                    'delete' => ['stock', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref', 'label', 'description', 'lieu', 'address', 'zip', 'town',
                    'fk_departement', 'fk_pays', 'phone', 'fax', 'fk_parent', 'fk_project',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmWarehouse',
                'alias' => 'e',
                'default_sort' => 'e.ref ASC, e.rowid ASC',
            ],
            'member' => [
                'class' => 'Adherent',
                'file' => DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php',
                'table' => 'adherent',
                'element' => 'member',
                'label' => 'Members',
                'module' => 'adherent',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['adherent', 'lire'],
                    'create' => ['adherent', 'creer'],
                    'update' => ['adherent', 'creer'],
                    'delete' => ['adherent', 'supprimer'],
                ],
                'allowed_fields' => [
                    'civility_id', 'lastname', 'firstname', 'gender', 'birth', 'company',
                    'address', 'zip', 'town', 'state_id', 'country_id', 'email', 'url',
                    'phone', 'phone_perso', 'phone_pro', 'phone_mobile', 'login', 'morphy',
                    'typeid', 'socid', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmMember',
                'alias' => 'a',
                'default_sort' => 'a.rowid DESC',
            ],
            'expensereport' => [
                'class' => 'ExpenseReport',
                'file' => DOL_DOCUMENT_ROOT . '/expensereport/class/expensereport.class.php',
                'table' => 'expensereport',
                'element' => 'expensereport',
                'label' => 'ExpenseReports',
                'module' => 'expensereport',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['expensereport', 'lire'],
                    'create' => ['expensereport', 'creer'],
                    'update' => ['expensereport', 'creer'],
                    'delete' => ['expensereport', 'supprimer'],
                ],
                'allowed_fields' => [
                    'date_debut', 'date_fin', 'fk_c_paiement', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmExpenseReport',
                'alias' => 'er',
                'default_sort' => 'er.rowid DESC',
            ],
            'contract' => [
                'class' => 'Contrat',
                'file' => DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php',
                'table' => 'contrat',
                'element' => 'contrat',
                'label' => 'Contracts',
                'module' => 'contrat',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['contrat', 'lire'],
                    'create' => ['contrat', 'creer'],
                    'update' => ['contrat', 'creer'],
                    'delete' => ['contrat', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_customer', 'ref_supplier', 'date_contrat', 'socid', 'fk_project',
                    'commercial_signature_id', 'commercial_suivi_id', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmContract',
                'alias' => 'ct',
                'default_sort' => 'ct.rowid DESC',
            ],
            'ticket' => [
                'class' => 'Ticket',
                'file' => DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php',
                'table' => 'ticket',
                'element' => 'ticket',
                'label' => 'Tickets',
                'module' => 'ticket',
                'priority' => 'low',
                'default_enabled' => false,
                // Ticket uses read/write/delete rights (English), not lire/creer.
                'rights' => [
                    'read'   => ['ticket', 'read'],
                    'create' => ['ticket', 'write'],
                    'update' => ['ticket', 'write'],
                    'delete' => ['ticket', 'delete'],
                ],
                'allowed_fields' => [
                    'subject', 'message', 'fk_soc', 'fk_project', 'fk_user_assign',
                    'type_code', 'category_code', 'severity_code', 'resolution',
                    'progress', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmTicket',
                'alias' => 'tk',
                'default_sort' => 'tk.rowid DESC',
            ],
            'intervention' => [
                'class' => 'Fichinter',
                'file' => DOL_DOCUMENT_ROOT . '/fichinter/class/fichinter.class.php',
                'table' => 'fichinter',
                'element' => 'fichinter',
                'label' => 'Interventions',
                'module' => 'ficheinter',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['ficheinter', 'lire'],
                    'create' => ['ficheinter', 'creer'],
                    'update' => ['ficheinter', 'creer'],
                    'delete' => ['ficheinter', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_client', 'socid', 'fk_project', 'fk_contrat', 'datei', 'dateo',
                    'datee', 'duree', 'description', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmIntervention',
                'alias' => 'fi',
                'default_sort' => 'fi.rowid DESC',
            ],

            // ===== Vague 3: documents fournisseurs (CRUD ; lignes/actions/
            // paiements suivront quand DocumentLine/Action/Payment invokers
            // gereront CommandeFournisseur/FactureFournisseur/SupplierProposal) =====
            'supplier_order' => [
                'class' => 'CommandeFournisseur',
                'file' => DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php',
                'table' => 'commande_fournisseur',
                'element' => 'order_supplier',
                'label' => 'SupplierOrders',
                'module' => 'fournisseur',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['fournisseur', 'commande', 'lire'],
                    'create' => ['fournisseur', 'commande', 'creer'],
                    'update' => ['fournisseur', 'commande', 'creer'],
                    'delete' => ['fournisseur', 'commande', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_supplier', 'socid', 'fk_project', 'date', 'date_commande',
                    'delivery_date', 'cond_reglement_id', 'mode_reglement_id',
                    'fk_account', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSupplierOrder',
                'alias' => 'cf',
                'default_sort' => 'cf.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'approve', 'cancel'],
            ],
            'supplier_invoice' => [
                'class' => 'FactureFournisseur',
                'file' => DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php',
                'table' => 'facture_fourn',
                'element' => 'invoice_supplier',
                'label' => 'SupplierInvoices',
                'module' => 'fournisseur',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['fournisseur', 'facture', 'lire'],
                    'create' => ['fournisseur', 'facture', 'creer'],
                    'update' => ['fournisseur', 'facture', 'creer'],
                    'delete' => ['fournisseur', 'facture', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_supplier', 'label', 'socid', 'fk_project', 'date', 'date_echeance',
                    'cond_reglement_id', 'mode_reglement_id', 'fk_account',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSupplierInvoice',
                'alias' => 'ff',
                'default_sort' => 'ff.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'setpaid', 'setunpaid', 'setcanceled'],
                'payment' => [
                    'class' => 'PaiementFourn',
                    'file' => DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php',
                ],
            ],
            'supplier_proposal' => [
                'class' => 'SupplierProposal',
                'file' => DOL_DOCUMENT_ROOT . '/supplier_proposal/class/supplier_proposal.class.php',
                'table' => 'supplier_proposal',
                'element' => 'supplier_proposal',
                'label' => 'SupplierProposals',
                'module' => 'supplier_proposal',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['supplier_proposal', 'lire'],
                    'create' => ['supplier_proposal', 'creer'],
                    'update' => ['supplier_proposal', 'creer'],
                    'delete' => ['supplier_proposal', 'supprimer'],
                ],
                'allowed_fields' => [
                    'socid', 'fk_project', 'date', 'delivery_date', 'cond_reglement_id',
                    'mode_reglement_id', 'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSupplierProposal',
                'alias' => 'sp',
                'default_sort' => 'sp.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'closesign', 'closeunsign'],
            ],

            // ===== Vague 3: types derives/enfants (crees depuis un parent ;
            // exposes surtout en lecture via la facade) =====
            'shipment' => [
                'class' => 'Expedition',
                'file' => DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php',
                'table' => 'expedition',
                'element' => 'shipping',
                'label' => 'Shipments',
                'module' => 'expedition',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['expedition', 'lire'],
                    'create' => ['expedition', 'creer'],
                    'update' => ['expedition', 'creer'],
                    'delete' => ['expedition', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_customer', 'socid', 'fk_project', 'date_expedition', 'date_delivery',
                    'entrepot_id', 'fk_shipping_method', 'tracking_number', 'tracking_url',
                    'weight_units', 'width_units', 'height_units', 'depth_units',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmShipment',
                'alias' => 'exp',
                'default_sort' => 'exp.rowid DESC',
            ],
            'reception' => [
                'class' => 'Reception',
                'file' => DOL_DOCUMENT_ROOT . '/reception/class/reception.class.php',
                'table' => 'reception',
                'element' => 'reception',
                'label' => 'Receptions',
                'module' => 'reception',
                'priority' => 'low',
                'default_enabled' => false,
                'rights' => [
                    'read'   => ['reception', 'lire'],
                    'create' => ['reception', 'creer'],
                    'update' => ['reception', 'creer'],
                    'delete' => ['reception', 'supprimer'],
                ],
                'allowed_fields' => [
                    'ref_supplier', 'socid', 'fk_project', 'date_reception', 'date_delivery',
                    'entrepot_id', 'fk_shipping_method', 'tracking_number', 'tracking_url',
                    'weight_units', 'width_units', 'height_units', 'depth_units',
                    'note_public', 'note_private',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmReception',
                'alias' => 'rec',
                'default_sort' => 'rec.rowid DESC',
            ],
            'stock_movement' => [
                'class' => 'MouvementStock',
                'file' => DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php',
                'table' => 'stock_mouvement',
                'element' => 'stockmouvement',
                'label' => 'StockMovements',
                'module' => 'stock',
                'priority' => 'low',
                'default_enabled' => false,
                // llx_stock_mouvement has no entity column.
                'has_entity' => false,
                // Movements are created through product stock corrections, not
                // generic field writes (dmStockMovement has no $writableFields):
                // the facade exposes them read-mostly. Delete maps to the create
                // right (whoever records a movement can reverse it).
                'rights' => [
                    'read'   => ['stock', 'mouvement', 'lire'],
                    'create' => ['stock', 'mouvement', 'creer'],
                    'update' => ['stock', 'mouvement', 'creer'],
                    'delete' => ['stock', 'mouvement', 'creer'],
                ],
                'allowed_fields' => [],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmStockMovement',
                'alias' => 'sm',
                'default_sort' => 'sm.rowid DESC',
            ],
            'subscription' => [
                'class' => 'Subscription',
                'file' => DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php',
                'table' => 'subscription',
                'element' => 'subscription',
                'label' => 'Subscriptions',
                'module' => 'adherent',
                'priority' => 'low',
                'default_enabled' => false,
                // llx_subscription has no entity column.
                'has_entity' => false,
                // The cotisation dictionary has no delete right; map delete to
                // create to keep a non-empty entry (fail-closed otherwise).
                'rights' => [
                    'read'   => ['adherent', 'cotisation', 'lire'],
                    'create' => ['adherent', 'cotisation', 'creer'],
                    'update' => ['adherent', 'cotisation', 'creer'],
                    'delete' => ['adherent', 'cotisation', 'creer'],
                ],
                'allowed_fields' => [
                    'fk_adherent', 'fk_type', 'dateh', 'datef', 'amount', 'fk_bank', 'note',
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSubscription',
                'alias' => 'sub',
                'default_sort' => 'sub.rowid DESC',
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
