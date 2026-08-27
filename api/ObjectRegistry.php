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
 * write-permission map, write allowlist, mapper class) was duplicated in three
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
 *                        count queries omit the entity filter. Such a type MUST
 *                        then declare an isolationWhereSql() on its mapper (cf
 *                        ObjectFacadeTrait) or it would serve every tenant's
 *                        rows.
 *
 * The 'mapper', 'alias' and 'default_sort' keys are additive: sync ignores keys
 * it does not read, so seeding syncableObjects from this registry keeps the
 * sync engine byte-compatible with its previous inline definitions.
 *
 * ONE WRITE ALLOWLIST, NOT TWO -- why no built-in carries 'allowed_fields'
 * -------------------------------------------------------------------------
 * Every built-in used to declare an 'allowed_fields' list alongside its mapper.
 * It read as a second line of defence; it was dead code. applyDataToObject()
 * only reaches applyDataLegacy() -- the sole runtime reader of the key -- when
 * no mapper resolves, and all 26 built-ins declare one that loads. So the
 * effective allowlist was the mapper's $writableFields, always, on both doors.
 *
 * Two allowlists that nothing keeps in step do not add defence, they subtract
 * trust: 10 of the 26 had already drifted, in BOTH directions. The registry
 * listed 35 writable fields for thirdparty where the mapper opens 25; it listed
 * 8 for project where the mapper opens 16; not one of the 12 it listed for order
 * was even a valid API key. A reader checking "what can be written on this type"
 * got an answer wrong by a factor of two, and a reviewer adding a field had one
 * chance in two of putting it in the list that governs nothing.
 *
 * So the key is GONE from the built-ins and stays SUPPORTED for hook-registered
 * types that declare no mapper -- the only case where applyDataLegacy() is
 * reachable and where the key still governs something real. Such a type without
 * it is refused outright (fail-closed) rather than filtered by the denylist
 * alone. Pinned by RegistryWriteContractTest; declaring a mapper is the
 * recommended path either way (see documentation/hooks.md).
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
                // The write allowlist lives on the mapper: civility_code (not
                // civility_id) + state_id + statut/priv/default_lang are the
                // mapper's property-is-source-of-truth write keys.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmProduct',
                'alias' => 'p',
                'default_sort' => 'p.ref ASC, p.rowid ASC',
            ],
            'category' => [
                'class' => 'Categorie',
                'file' => DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php',
                'table' => 'categorie',
                // 'category', not 'categorie': this is the element code, and
                // Categorie::$element is 'category' (categorie.class.php l.196).
                // getEntity() translates a few French element names to English
                // (projet, contrat) but NOT this one, so the wrong spelling was
                // only ever rescued by Multicompany's own compatibility table.
                // It stopped being cosmetic when dmCategory started guarding
                // fk_parent: ObjectFacadeTrait::foreignKeyTargetDenies() reads
                // this very key to call getEntity(). Harmless to change --
                // neither spelling is in the $addzero list of getEntity(), so
                // without Multicompany both resolve to the current entity.
                'element' => 'category',
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmCategory',
                'alias' => 'c',
                'default_sort' => 'c.label ASC, c.rowid ASC',
            ],

            // ===== Vague 2: documents a lignes + gestion projet =====
            // These are NOT enabled for offline sync by default (default_enabled
            // false): the synchronous REST facade is their online access path.
            // Their write allowlist is the mapper's $writableFields, and only
            // that -- see the class docblock on why the registry carries none.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmInvoice',
                'alias' => 'f',
                'default_sort' => 'f.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'setpaid', 'setunpaid', 'setcanceled'],
                // Customer payments via ObjectPaymentController
                // (POST/GET objects/invoice/{id}/payments).
                // 'bank_mode' and 'bank_label' drive Paiement::addPaymentToBank:
                // the mode decides the SIGN of the bank line (money in for a
                // customer, out for a supplier) and the label is the one the
                // core's own REST API writes.
                'payment' => [
                    'class' => 'Paiement',
                    'file' => DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php',
                    'bank_mode' => 'payment',
                    'bank_label' => '(CustomerInvoicePayment)',
                    // A payment recorded on a credit note is a refund going the
                    // other way; api_invoices.class.php l.1465 swaps the label.
                    'bank_label_credit_note' => '(CustomerInvoicePaymentBack)',
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
                // dmAgendaEvent::$writableFields addresses Dolibarr-side
                // PROPERTY names, not SQL columns: percentage not percent, socid
                // not fk_soc, contact_id not fk_contact, fk_project not
                // fk_projet, userownerid not fk_user_action.
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
                // phone_pro is absent from dmMember::$writableFields on purpose:
                // llx_adherent has NO such column
                // (install/mysql/tables/llx_adherent.sql l.66-68 ships phone,
                // phone_perso and phone_mobile only) and Adherent::update()
                // (l.834-836) never writes it, so it was a silent no-op
                // answering 200. Same story for the read-only `fax`, removed
                // from the published fields of the mapper.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmContract',
                'alias' => 'ct',
                'default_sort' => 'ct.rowid DESC',
                // Contract lines are the subscription grain of the consumer
                // modules: one line per rented item, opened when the lease
                // starts and closed when it ends. Contrat::fetch() does NOT
                // load them (unlike Facture/Commande/Propal), so the read paths
                // ask for them explicitly -- see ObjectFacadeTrait::loadLines().
                'supports_lines' => true,
                'actions' => ['validate', 'close'],
                // LINE-level actions, a scope the other types do not have: a
                // contract line has its own lifecycle (0 inactive, 4 active,
                // 5 closed) driven by active_line()/close_line(), which fire the
                // LINECONTRACT_ACTIVATE / LINECONTRACT_CLOSE triggers. A PATCH
                // on the line fields cannot express that transition, so it is
                // exposed as
                // POST objects/contract/{id}/lines/{lineid}/actions/{action}
                // and dispatched by DocumentLineActionInvoker.
                'line_actions' => ['activate', 'close'],
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
                // The module also declares 'manage' (l.209) and 'export'
                // (l.216). A 'view' right exists in the file but sits INSIDE a
                // block comment (core/modules/modTicket.class.php l.217-225,
                // "Seems not used and in conflict with societe->client->voir"),
                // so hasRight('ticket', 'view') is always false -- never map an
                // action onto it.
                'rights' => [
                    'read'   => ['ticket', 'read'],
                    'create' => ['ticket', 'write'],
                    'update' => ['ticket', 'write'],
                    'delete' => ['ticket', 'delete'],
                ],
                // CREATE IS UNREACHABLE THROUGH THE FACADE, and the 'create'
                // right above only guards a route that cannot succeed:
                // Ticket::create() (ticket.class.php l.474) calls verify(),
                // which refuses an empty ref (l.444-447) and makes create()
                // return -3 (l.591); the ref is produced by getDefaultRef()
                // (l.2288), which create() never calls, it is not writable, and
                // ObjectController::create() has no pre-create hook. A module
                // needing to create tickets owns a local POST route that sets
                // the default ref then calls create(), like the native REST API
                // does. Kept declared so the entry stays uniform with its
                // siblings and so a future pre-create mechanism needs no
                // registry change.
                //
                // note_public and note_private are absent from
                // dmTicket::$writableFields on purpose: llx_ticket has NO such
                // columns (install/mysql/tables/llx_ticket-ticket.sql l.17-46)
                // and Ticket::update() (l.985-1006) never writes them, so they
                // were a silent no-op answering 200.
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
                // datei / dateo / datee / duree are absent from
                // dmIntervention::$writableFields because no Fichinter SQL verb
                // writes them (dateo and datee are recomputed from the lines by
                // FichinterLigne::update_total(), datei only by
                // set_date_delivery()), and `duree` was renamed `duration`
                // because update() reads the PHP property $this->duration.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSupplierInvoice',
                'alias' => 'ff',
                'default_sort' => 'ff.rowid DESC',
                'supports_lines' => true,
                'actions' => ['validate', 'setdraft', 'setpaid', 'setunpaid', 'setcanceled'],
                // Supplier payments. 'payment_supplier' makes addPaymentToBank
                // negate the amount: the money leaves the account.
                'payment' => [
                    'class' => 'PaiementFourn',
                    'file' => DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php',
                    'bank_mode' => 'payment_supplier',
                    'bank_label' => '(SupplierInvoicePayment)',
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmShipment',
                'alias' => 'exp',
                'default_sort' => 'exp.date_expedition DESC, exp.rowid DESC',
                // Workflow transitions -> DocumentActionInvoker (Expedition:*).
                // Stock is moved by Dolibarr itself inside valid()/setClosed()/
                // cancel(), following STOCK_CALCULATE_ON_SHIPMENT[_CLOSE].
                'actions' => ['validate', 'close', 'reopen', 'setdraft', 'cancel'],
                // NO supports_lines: a shipment line is not a generic document
                // line (Expedition::addline($entrepot_id, $origin_line, $qty)
                // copies a source ORDER line), so the line writes stay on the
                // create-from-order path. The READ needs nothing here anyway:
                // Expedition::fetch() calls fetch_lines(), so show() already
                // exports the lines.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmReception',
                'alias' => 'rec',
                'default_sort' => 'rec.date_reception DESC, rec.rowid DESC',
                // Workflow transitions -> DocumentActionInvoker (Reception:*).
                // No 'cancel': the Reception class has no cancel() method.
                // Stock is INCREMENTED by valid()/setClosed(), per
                // STOCK_CALCULATE_ON_RECEPTION[_CLOSE].
                'actions' => ['validate', 'close', 'reopen', 'setdraft'],
                // NO supports_lines, same rationale as shipment: a reception
                // line is a CommandeFournisseurDispatch row created from a
                // supplier order line, not a generic document line. Reception::
                // fetch() calls fetch_lines(), so the READ already carries them.
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
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmStockMovement',
                'alias' => 'sm',
                // Audit trail: newest movement first (matches the module's own
                // list screen and the pre-facade Dolipocket controller).
                'default_sort' => 'sm.datem DESC, sm.rowid DESC',
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
                // In dmSubscription::$writableFields, fk_bank is honoured on
                // PATCH only: Subscription::create() (l.159) does not list it
                // among its INSERT columns while update() (l.284) writes it.
                // Documented on the mapper, pinned by DmMemberMapperTest.
                // fk_adherent is ABSENT on purpose: llx_subscription has no
                // entity column, so the parent member IS the tenant boundary
                // (dmSubscription::isolationWhereSql). Leaving it writable let a
                // PATCH move a local fee onto another tenant's member, and a
                // POST file one directly there. Full rationale on the mapper;
                // the fee routes that need a parent are the local, URL-scoped
                // member/{id}/subscription ones. Both write doors read that one
                // list, so the removal closes them both.
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmSubscription',
                'alias' => 'sub',
                'default_sort' => 'sub.rowid DESC',
            ],

            // ===== Banque =====
            // Read and update only, by design. Both writes that the generic
            // facade cannot express correctly stay in the consumer's local
            // routes:
            //   - creating an account needs date_solde and an opening balance,
            //     which Account::create() requires but which are not columns of
            //     llx_bank_account (they seed the first llx_bank line);
            //   - deleting one must be refused when the account carries more
            //     than its opening line, a guard Account::delete() does NOT
            //     apply (it would orphan every llx_bank row);
            //   - closing one writes `clos`, deliberately absent from the
            //     writable fields because status is a state machine.
            'bank_account' => [
                'class' => 'Account',
                'file' => DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php',
                'table' => 'bank_account',
                'element' => 'bank_account',
                'label' => 'BankAccounts',
                'module' => 'banque',
                'priority' => 'low',
                'default_enabled' => false,
                // Reading a bank account is 'lire'; every write on the account
                // itself is 'configurer' -- NOT 'modifier', which governs the
                // transactions (compta/bank/card.php l.883-901 vs
                // bankentries_list.php l.239).
                'rights' => [
                    'read'   => ['banque', 'lire'],
                    'create' => ['banque', 'configurer'],
                    'update' => ['banque', 'configurer'],
                    'delete' => ['banque', 'configurer'],
                ],
                // dmBankAccount::$writableFields excludes 'clos' (state machine).
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmBankAccount',
                'alias' => 'ba',
                'default_sort' => 'ba.clos ASC, ba.label ASC, ba.rowid ASC',
            ],
            'bank_transaction' => [
                'class' => 'AccountLine',
                'file' => DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php',
                'table' => 'bank',
                'element' => 'bank',
                'label' => 'BankTransactions',
                'module' => 'banque',
                'priority' => 'low',
                'default_enabled' => false,
                // llx_bank has no entity column: dmBank::isolationWhereSql()
                // scopes every row through its bank account. MANDATORY -- see
                // the mapper for what breaks without it.
                'has_entity' => false,
                // Read-only through the facade. dmBank::$writableFields is
                // empty (AccountLine::update() writes neither the fields a
                // caller would edit nor safely the ones it does handle), and
                // deletion must honour the reconciliation and accounting guards
                // that AccountLine::delete() applies only partially. Both live
                // in the consumer's local routes. 'create' and 'delete' still
                // name a real right so a mis-declared entry fails closed.
                'rights' => [
                    'read'   => ['banque', 'lire'],
                    'create' => ['banque', 'modifier'],
                    'update' => ['banque', 'modifier'],
                    'delete' => ['banque', 'modifier'],
                ],
                'mapper' => '\\SmartAuth\\DolibarrMapping\\dmBank',
                'alias' => 'b',
                // Statement order: most recent operation first, then the
                // insertion order, exactly like compta/bank/bankentries_list.php.
                'default_sort' => 'b.dateo DESC, b.rowid DESC',
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

    /**
     * Reverse lookup: the registry type backed by a given llx_ table.
     *
     * Needed by the extrafield tenant guard, which starts from a Dolibarr
     * extrafield descriptor -- and a descriptor names a TABLE ('sellist' :
     * "societe:nom:rowid") or a CLASS whose table_element is then read ('link' :
     * "Societe:societe/class/societe.class.php"). Neither names a registry key.
     *
     * Returning the KEY rather than a hand-built ['table' => ...] spec is the
     * point: the caller then inherits pk, element (load-bearing for getEntity())
     * and has_entity from the single registry entry, so the guard can never
     * drift from the rest of the facade.
     *
     * A table backing several types (none today) resolves to the first one
     * declared, which is deterministic since builtins() is an ordered literal.
     *
     * @param  string      $table        llx_ table suffix, no prefix.
     * @param  object|null $hookmanager
     * @return string|null  Registry type key, or null when no type backs it.
     */
    public static function typeForTable($table, $hookmanager = null)
    {
        $table = (string) $table;
        if ($table === '') {
            return null;
        }

        foreach (self::resolveWithHooks($hookmanager) as $type => $cfg) {
            if (is_array($cfg) && (string) ($cfg['table'] ?? '') === $table) {
                return (string) $type;
            }
        }

        return null;
    }
}
