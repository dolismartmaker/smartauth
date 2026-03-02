# Spécification Technique - Synchronisation Offline

**Module** : smartAuth
**Version** : 1.0.0
**Date** : 2026-01-19
**Statut** : Phase 1 (MVP) en cours d'implémentation

---

## Statut d'implémentation

### Phase 1 (MVP) - En cours

| Composant | Fichier | Statut | Notes |
|-----------|---------|--------|-------|
| Tables SQL sync | `sql/update_010.sql` | Implémenté | Tables: sync_clients, sync_tombstones, sync_conflicts, sync_events |
| SyncController | `api/SyncController.php` | Implémenté | Endpoints: register, pull, push, status, conflicts, resolve |
| Routes sync | `api/sync_routes.php` | Implémenté | Toutes routes protégées (JWT requis) |
| Détection conflits tms | Dans SyncController | Implémenté | Comparaison tms + données champ par champ |
| Verrouillage optimiste | Dans SyncController | Implémenté | SELECT FOR UPDATE avec retry |
| Objets simples | Config SyncController | Implémenté | thirdparty, contact, product |
| **Documents/Blobs** | `api/ObjectDocumentController.php` | Implémenté | List + download docs pour product, thirdparty, project, intervention, category. Intégration ECM avec share hash |
| Hooks objets sync | - | Prévu Phase 2 | smartmaker_registerSyncableObjects |
| Objets composites | - | Prévu Phase 2 | Factures, commandes avec lignes |
| Client JavaScript | - | À documenter | Documentation frontend à générer |

### Pour utiliser la sync API

1. **Appliquer la migration SQL** : Exécuter `sql/update_010.sql`
2. **Inclure les routes** : Ajouter dans votre point d'entrée API :
   ```php
   require_once __DIR__ . '/api/SyncController.php';
   require_once __DIR__ . '/api/sync_routes.php';
   ```
3. **Endpoints disponibles** :
   - `POST /sync/register` - Enregistrer un client
   - `GET /sync/pull?client_uuid=...&object_type=...` - Récupérer les changements
   - `POST /sync/push` - Envoyer les modifications
   - `GET /sync/status?client_uuid=...` - État de la sync
   - `GET /sync/conflicts?client_uuid=...` - Lister les conflits
   - `POST /sync/conflicts/{id}/resolve` - Résoudre un conflit
   - `GET /object/{type}/{id}/documents` - Lister les documents d'un objet (inclut `ecm_id` et `share` hash)
   - `GET /object/{type}/{id}/document?q={share_hash}` - Télécharger un document via share hash (recommandé)
   - `GET /object/{type}/{id}/document/binary?q={share_hash}` - Télécharger un document binaire via share hash (recommandé)
   - `GET /object/{type}/{id}/document/{path}` - Télécharger un document via chemin (legacy, noms simples sans sous-répertoires)
   - `GET /object/{type}/{id}/document/{path}/binary` - Télécharger un document binaire via chemin (legacy)
   - `POST /object/documents/bundle` - Télécharger plusieurs documents en un seul ZIP via share hashes

---

## 1. Objectifs

### 1.1 Cas d'usage principal

Permettre aux utilisateurs terrain (commerciaux, techniciens) d'utiliser une application PWA Dolibarr en mode déconnecté, avec synchronisation bidirectionnelle des données lorsque la connexion est rétablie.

### 1.2 Exigences fonctionnelles

| ID | Exigence | Priorité |
|----|----------|----------|
| F01 | Consultation des données hors ligne | Critique |
| F02 | Création/modification d'objets hors ligne | Critique |
| F03 | Détection automatique online/offline | Critique |
| F04 | Résolution manuelle obligatoire des conflits | Critique |
| F05 | Synchronisation de tous les objets dolMapping | Haute |
| F06 | Historique des modifications locales | Moyenne |
| F07 | Indicateur visuel de l'état de sync | Haute |

### 1.3 Exigences non-fonctionnelles

| ID | Exigence | Cible |
|----|----------|-------|
| NF01 | Stockage client | 500 Mo - 2 Go (voir section 10.4) |
| NF02 | Temps de sync initiale | < 30s pour 1000 objets |
| NF03 | Temps de sync incrémentale | < 5s |
| NF04 | Rétention tombstones serveur | 30 jours |
| NF05 | Nombre de clients simultanés | 100+ par instance |

---

## 2. Architecture

### 2.1 Vue d'ensemble

```
+------------------+          +------------------+          +------------------+
|   PWA Client     |   HTTPS  |   smartAuth API  |          |    Dolibarr DB   |
|                  | <------> |                  | <------> |                  |
|  - IndexedDB     |   JWT    |  - SyncController|          |  - Tables métier |
|  - Service Worker|          |  - dolMapping    |          |  - Tables sync   |
|  - SyncManager   |          |  - ConflictMgr   |          |  - Tombstones    |
+------------------+          +------------------+          +------------------+
```

### 2.2 Composants serveur

| Composant | Fichier | Responsabilité |
|-----------|---------|----------------|
| SyncController | `api/SyncController.php` | Endpoints REST sync |
| OfflineSyncManager | `class/offlinesyncmanager.class.php` | Logique métier sync |
| ConflictResolver | `class/conflictresolver.class.php` | Gestion des conflits |
| SyncMigration | `sql/sync_schema.sql` | Schéma BDD sync |

### 2.3 Composants client

| Composant | Fichier | Responsabilité |
|-----------|---------|----------------|
| SyncClient | `js/sync-client.js` | Orchestration sync |
| OfflineStorage | `js/offline-storage.js` | Abstraction IndexedDB |
| ConflictUI | `js/conflict-ui.js` | Interface résolution conflits |
| ServiceWorker | `sw.js` | Cache et requêtes offline |

---

## 3. Modèle de données

### 3.1 Tables serveur (nouvelles)

#### llx_sync_clients

Enregistrement des clients synchronisés. Lié aux devices smartAuth existants.

```sql
CREATE TABLE llx_sync_clients (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_device       INTEGER NOT NULL,           -- FK vers llx_smartauth_devices
    client_uuid     VARCHAR(64) NOT NULL UNIQUE,
    last_sync_at    DATETIME,
    last_pull_at    DATETIME,
    last_push_at    DATETIME,
    sync_scope      TEXT,                       -- JSON: liste des tables autorisées
    storage_used    INTEGER DEFAULT 0,          -- Octets utilisés côté client
    entity          INTEGER DEFAULT 1,
    datec           DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_device (fk_device),
    INDEX idx_uuid (client_uuid),
    FOREIGN KEY (fk_device) REFERENCES llx_smartauth_devices(rowid)
) ENGINE=InnoDB;
```

#### llx_sync_tombstones

Enregistrement des suppressions pour propagation aux clients.

```sql
CREATE TABLE llx_sync_tombstones (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    table_name      VARCHAR(64) NOT NULL,
    object_id       INTEGER NOT NULL,
    object_ref      VARCHAR(128),               -- Référence métier (ex: FA2401-0001)
    deleted_at      DATETIME NOT NULL,
    deleted_by      INTEGER,
    entity          INTEGER DEFAULT 1,

    INDEX idx_table_object (table_name, object_id),
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_entity (entity)
) ENGINE=InnoDB;
```

#### llx_sync_conflicts

File d'attente des conflits en attente de résolution.

```sql
CREATE TABLE llx_sync_conflicts (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_client       INTEGER NOT NULL,
    table_name      VARCHAR(64) NOT NULL,
    object_id       INTEGER NOT NULL,
    client_data     TEXT NOT NULL,              -- JSON: version client
    server_data     TEXT NOT NULL,              -- JSON: version serveur
    client_tms      DATETIME NOT NULL,          -- tms base du client
    server_tms      DATETIME NOT NULL,          -- tms actuel serveur
    field_conflicts TEXT,                       -- JSON: liste des champs en conflit
    status          VARCHAR(20) DEFAULT 'pending', -- pending, resolved, expired
    resolution      VARCHAR(20),                -- client, server, merged
    resolved_data   TEXT,                       -- JSON: données finales
    resolved_at     DATETIME,
    resolved_by     INTEGER,
    created_at      DATETIME NOT NULL,
    entity          INTEGER DEFAULT 1,

    INDEX idx_client (fk_client),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
```

#### llx_sync_events

Journal des événements de synchronisation (audit).

```sql
CREATE TABLE llx_sync_events (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_client       INTEGER,
    event_type      VARCHAR(20) NOT NULL,       -- PULL, PUSH, CONFLICT, RESOLVE, ERROR
    table_name      VARCHAR(64),
    object_id       INTEGER,
    event_data      TEXT,                       -- JSON: détails
    created_at      DATETIME NOT NULL,

    INDEX idx_client (fk_client),
    INDEX idx_type (event_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
```

### 3.2 Utilisation du champ `tms` natif

**Principe** : L'algorithme de synchronisation s'appuie sur le champ `tms` (timestamp)
natif des tables Dolibarr, évitant ainsi tout overhead en fonctionnement normal.

**Avantages :**
- Pas de table de métadonnées à maintenir en permanence
- Pas de calcul de hash à chaque modification
- Le champ `tms` est déjà mis à jour automatiquement par Dolibarr

**Cas particuliers nécessitant une table de métadonnées :**

Pour les objets dolMapping qui agrègent plusieurs tables (ex: thirdparty + contacts + adresses),
ou pour les tables sans champ `tms`, une table optionnelle peut être utilisée :

```sql
CREATE TABLE llx_sync_object_meta (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    object_type     VARCHAR(64) NOT NULL,     -- Clé dolMapping: 'thirdparty', 'invoice', etc.
    object_id       INTEGER NOT NULL,          -- rowid de l'objet source principal
    composite_tms   DATETIME NOT NULL,         -- MAX(tms) des tables agrégées
    entity          INTEGER DEFAULT 1,

    UNIQUE KEY uk_object (object_type, object_id, entity),
    INDEX idx_type_updated (object_type, composite_tms),
    INDEX idx_entity (entity)
) ENGINE=InnoDB;
```

**Note** : Cette table n'est peuplée que pour les objets composites. Pour les objets simples
(1 table = 1 dolMapping), le `tms` de la table source est utilisé directement.

**Mise à jour du composite_tms :**

Pour les objets composites, le `composite_tms` doit être mis à jour quand une des tables
liées est modifiée. Cela se fait via hooks Dolibarr sur les tables enfants :

```php
// Hook sur modification de socpeople (contact)
public function contactUpdate($parameters, &$object, &$action, $hookmanager)
{
    if ($object->fk_soc > 0) {
        $this->updateCompositeTms('thirdparty', $object->fk_soc);
    }
    return 0;
}

// Hook sur modification de facturedet (ligne facture)
public function invoiceLineUpdate($parameters, &$object, &$action, $hookmanager)
{
    if ($object->fk_facture > 0) {
        $this->updateCompositeTms('invoice', $object->fk_facture);
    }
    return 0;
}

private function updateCompositeTms($objectType, $objectId)
{
    global $db, $conf;

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."sync_object_meta";
    $sql .= " (object_type, object_id, composite_tms, entity)";
    $sql .= " VALUES ('".$db->escape($objectType)."', ".(int)$objectId.", NOW(), ".(int)$conf->entity.")";
    $sql .= " ON DUPLICATE KEY UPDATE composite_tms = NOW()";

    $db->query($sql);
}
```

**Hooks nécessaires pour objets composites :**

| Objet composite | Tables enfants à surveiller |
|-----------------|----------------------------|
| thirdparty | socpeople, societe_contacts, societe_address |
| invoice | facturedet, facture_extrafields |
| order | commandedet, commande_extrafields |
| proposal | propaldet, propal_extrafields |
| intervention | fichinterdet |

### 3.3 Gestion des tombstones via hooks Dolibarr

Le hook de suppression crée les tombstones et gère les cascades
(le `tms` étant géré nativement par Dolibarr).

```php
// Dans class/actions_smartauth.class.php

class ActionsSmartAuth
{
    /**
     * Hook appelé après suppression d'un objet
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $user;

        // Intercepter uniquement les suppressions
        if (strpos($action, 'delete') === false && strpos($action, 'DELETE') === false) {
            return 0;
        }

        // Déterminer le type dolMapping pour cet objet
        $objectType = $this->getObjectTypeFromClass(get_class($object));
        if (!$objectType || !$this->isObjectTypeSyncable($objectType)) {
            return 0;
        }

        // Créer un tombstone pour propagation aux clients
        $this->createTombstone($objectType, $object->id, $object->ref ?? null, $user->id);

        return 0;
    }

    private function createTombstone($tableName, $objectId, $objectRef, $userId)
    {
        global $db, $conf;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."sync_tombstones";
        $sql .= " (table_name, object_id, object_ref, deleted_at, deleted_by, entity)";
        $sql .= " VALUES ('".$db->escape($tableName)."', ".(int)$objectId;
        $sql .= ", ".($objectRef ? "'".$db->escape($objectRef)."'" : "NULL");
        $sql .= ", NOW(), ".(int)$userId.", ".(int)$conf->entity.")";

        return $db->query($sql);
    }

    /**
     * Crée les tombstones pour les objets enfants (cascade)
     * Appelé AVANT la suppression effective (les FK existent encore)
     */
    private function createCascadeTombstones($objectType, $objectId, $userId)
    {
        global $db;

        $cascades = [
            'thirdparty' => [
                ['table' => 'contact', 'fk' => 'fk_soc', 'source' => 'socpeople'],
            ],
            'invoice' => [
                // Les lignes de facture ne sont pas des objets sync individuels
                // Elles sont incluses dans l'objet composite invoice
            ],
            'project' => [
                ['table' => 'task', 'fk' => 'fk_projet', 'source' => 'projet_task'],
            ],
        ];

        if (!isset($cascades[$objectType])) {
            return;
        }

        foreach ($cascades[$objectType] as $cascade) {
            $sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX.$cascade['source'];
            $sql .= " WHERE ".$cascade['fk']." = ".(int)$objectId;

            $resql = $db->query($sql);
            while ($obj = $db->fetch_object($resql)) {
                $this->createTombstone($cascade['table'], $obj->rowid, $obj->ref ?? null, $userId);
            }
        }
    }

    /**
     * Mapping classe Dolibarr -> type dolMapping
     */
    private function getObjectTypeFromClass($className)
    {
        $mapping = [
            'Societe' => 'thirdparty',
            'Contact' => 'contact',
            'Facture' => 'invoice',
            'Propal' => 'proposal',
            'Commande' => 'order',
            'Product' => 'product',
            'Project' => 'project',
            'Task' => 'task',
            'ActionComm' => 'agendaevent',
            'Fichinter' => 'intervention',
            // ... autres mappings
        ];
        return $mapping[$className] ?? null;
    }
}
```

**Gestion des cascades :**

Quand un objet parent est supprimé, les tombstones des objets enfants doivent être créés
explicitement car les cascades SQL (`ON DELETE CASCADE`) ne déclenchent pas les hooks Dolibarr.

| Parent | Enfants à cascader |
|--------|-------------------|
| thirdparty | contact (fk_soc) |
| project | task (fk_projet) |

**Note** : Les lignes de documents (facturedet, propaldet, etc.) ne sont pas des objets
sync individuels - elles font partie de l'objet composite parent.

**Hooks Dolibarr à intercepter (suppressions uniquement) :**

| Hook | Déclencheur | Action sync |
|------|-------------|-------------|
| `COMPANY_DELETE` | Suppression tiers | Tombstone + cascade contacts |
| `CONTACT_DELETE` | Suppression contact | Tombstone |
| `BILL_DELETE` | Suppression facture | Tombstone |
| `PROPAL_DELETE` | Suppression devis | Tombstone |
| `ORDER_DELETE` | Suppression commande | Tombstone |
| `PROJECT_DELETE` | Suppression projet | Tombstone + cascade tasks |
| ... | ... | ... |

**Note** : Les modules tiers peuvent enregistrer leurs propres types via `smartmaker_registerSyncableObjects`.

### 3.4 Récupération des objets modifiés (PULL)

La requête PULL utilise directement le `tms` des tables Dolibarr :

```php
class SyncPullService
{
    /**
     * Récupère les objets modifiés depuis un timestamp
     */
    public function getModifiedSince($tableName, $since, $entity, $limit = 500, $offset = 0)
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX.$this->db->escape($tableName);
        $sql .= " WHERE entity = ".(int)$entity;
        if ($since) {
            $sql .= " AND tms > '".$this->db->idate($since)."'";
        }
        $sql .= " ORDER BY tms ASC";
        $sql .= " LIMIT ".(int)$limit." OFFSET ".(int)$offset;

        // ... exécution et transformation via dolMapping
    }

    /**
     * Pour les objets composites : récupérer via la table meta
     */
    public function getCompositeModifiedSince($objectType, $since, $entity, $limit = 500)
    {
        $sql = "SELECT object_id FROM ".MAIN_DB_PREFIX."sync_object_meta";
        $sql .= " WHERE object_type = '".$this->db->escape($objectType)."'";
        $sql .= " AND entity = ".(int)$entity;
        if ($since) {
            $sql .= " AND composite_tms > '".$this->db->idate($since)."'";
        }
        $sql .= " ORDER BY composite_tms ASC";
        $sql .= " LIMIT ".(int)$limit;

        // ... exécution et chargement des objets complets via dolMapping
    }
}
```

### 3.5 Schéma IndexedDB (client)

```javascript
const schema = {
    version: 1,
    stores: {
        // Données métier cachées
        entities: {
            keyPath: ['table', 'id'],
            indexes: {
                'by_table': 'table',
                'by_tms': 'server_tms',           // tms serveur au moment du pull
                'by_local_updated': 'local_updated_at'
            }
        },

        // Modifications en attente d'envoi
        pending_changes: {
            keyPath: 'queue_id',
            autoIncrement: true,
            indexes: {
                'by_table': 'table',
                'by_created': 'created_at'
            }
        },

        // Conflits en attente de résolution
        pending_conflicts: {
            keyPath: 'conflict_id',
            indexes: {
                'by_table': 'table',
                'by_created': 'created_at'
            }
        },

        // Métadonnées de sync
        sync_meta: {
            keyPath: 'key'
            // Stocke : last_sync_at, client_uuid, etc.
        },

        // Tombstones locaux (suppressions faites offline)
        local_tombstones: {
            keyPath: ['table', 'id']
        }
    }
};

// Structure d'une entité stockée
const entityExample = {
    table: 'thirdparty',
    id: 42,
    data: { /* données dolMapping */ },
    server_tms: '2026-01-19T14:00:00Z',  // tms au moment du pull (pour détection conflit)
    local_updated_at: null                // null si non modifié localement
};

// Structure d'un pending_change
const pendingChangeExample = {
    queue_id: 1,                          // auto-increment
    table: 'thirdparty',
    id: 42,
    action: 'update',                     // create, update, delete
    base_tms: '2026-01-19T14:00:00Z',    // tms serveur au moment de la modif locale
    data: { name: 'Acme Corp Updated' },
    created_at: '2026-01-19T16:30:00Z'
};
```

---

## 4. API REST

### 4.1 Endpoints

Base URL : `/api/smartauth/sync`

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/register` | Enregistrer un client sync |
| GET | `/pull` | Récupérer les changements serveur |
| POST | `/push` | Envoyer les changements client |
| GET | `/conflicts` | Lister les conflits en attente |
| POST | `/conflicts/{id}/resolve` | Résoudre un conflit |
| GET | `/status` | État de la synchronisation |
| DELETE | `/client` | Désenregistrer le client |

### 4.2 Authentification

Tous les endpoints utilisent l'authentification JWT smartAuth existante.

Headers requis :
```
Authorization: Bearer {access_token}
X-Sync-Client-UUID: {client_uuid}
```

### 4.3 Spécifications des endpoints

#### POST /register

Enregistre un nouveau client de synchronisation, lié au device smartAuth.

**Request**
```json
{
    "device_uuid": "jwt_dev_abc123",
    "platform": "PWA",
    "user_agent": "Mozilla/5.0...",
    "requested_scope": ["thirdparty", "contact", "invoice", "proposal"]
}
```

**Response 201**
```json
{
    "client_uuid": "sync_cli_xyz789",
    "sync_scope": ["thirdparty", "contact", "invoice", "proposal"],
    "server_time": "2026-01-19T14:30:00Z",
    "config": {
        "max_batch_size": 100,
        "tombstone_retention_days": 30,
        "recommended_sync_interval_ms": 60000
    }
}
```

#### GET /pull

Récupère les modifications serveur depuis la dernière synchronisation.

**Query params**
- `tables` : Liste des tables (comma-separated), ou `*` pour toutes
- `since` : Timestamp ISO8601 de la dernière sync (optionnel)
- `limit` : Nombre max d'objets par table (défaut: 500)
- `offset` : Pagination

**Response 200**
```json
{
    "server_time": "2026-01-19T14:35:00Z",
    "changes": {
        "thirdparty": {
            "updated": [
                {
                    "id": 42,
                    "ref": "CU2401-0001",
                    "name": "Acme Corp",
                    "email": "contact@acme.com",
                    "tms": "2026-01-19T14:00:00Z"
                }
            ],
            "deleted": [
                {"id": 15, "ref": "CU2301-0015", "deleted_at": "2026-01-19T12:00:00Z"}
            ],
            "has_more": false
        },
        "contact": {
            "updated": [...],
            "deleted": [...],
            "has_more": true
        }
    },
    "stats": {
        "total_updated": 45,
        "total_deleted": 2
    }
}
```

#### POST /push

Envoie les modifications locales vers le serveur.

**Ordre de traitement :**

Le serveur traite les changements dans un ordre topologique pour respecter les dépendances :

1. **CREATE** des objets parents (thirdparty, product, project...)
2. **CREATE** des objets enfants (contact, task, invoice...)
3. **UPDATE** (tous les objets)
4. **DELETE** des objets enfants
5. **DELETE** des objets parents

**Résolution des IDs temporaires :**

Quand un objet créé localement référence un autre objet créé localement, le client
utilise un `temp_id` préfixé. Le serveur résout ces références dans l'ordre de traitement.

```
Exemple : Création d'un tiers + contact dans le même batch
- thirdparty créé avec temp_id="local_soc_1" → server_id=42
- contact créé avec fk_soc="local_soc_1" → résolu en fk_soc=42 avant insertion
```

**Request**
```json
{
    "changes": [
        {
            "table": "thirdparty",
            "action": "create",
            "temp_id": "local_soc_1",
            "data": {
                "name": "Nouvelle Société",
                "client": 1
            },
            "local_updated_at": "2026-01-19T09:00:00Z"
        },
        {
            "table": "thirdparty",
            "action": "update",
            "id": 42,
            "base_tms": "2026-01-19T08:30:00Z",
            "data": {
                "name": "Acme Corporation",
                "phone": "+33 1 23 45 67 89"
            },
            "local_updated_at": "2026-01-19T10:00:00Z"
        },
        {
            "table": "contact",
            "action": "create",
            "temp_id": "local_contact_1",
            "data": {
                "lastname": "Dupont",
                "firstname": "Jean",
                "fk_soc": "local_soc_1"
            },
            "local_updated_at": "2026-01-19T10:05:00Z"
        },
        {
            "table": "thirdparty",
            "action": "delete",
            "id": 99,
            "base_tms": "2026-01-18T14:00:00Z"
        }
    ]
}
```

**Response 200**
```json
{
    "server_time": "2026-01-19T14:40:00Z",
    "results": {
        "success": [
            {"table": "thirdparty", "temp_id": "local_soc_1", "server_id": 100, "tms": "2026-01-19T14:40:00Z"},
            {"table": "contact", "temp_id": "local_contact_1", "server_id": 156, "tms": "2026-01-19T14:40:01Z"}
        ],
        "conflicts": [
            {
                "conflict_id": 789,
                "table": "thirdparty",
                "id": 42,
                "client_tms": "2026-01-19T08:30:00Z",
                "server_tms": "2026-01-19T12:00:00Z",
                "client_data": {"name": "Acme Corporation", "phone": "+33 1 23 45 67 89"},
                "server_data": {"name": "Acme Corp", "email": "contact@acme.com"},
                "field_conflicts": ["name"],
                "requires_resolution": true
            }
        ],
        "errors": [
            {"table": "thirdparty", "id": 99, "error": "OBJECT_NOT_FOUND", "message": "Object already deleted"}
        ],
        "id_mappings": {
            "local_soc_1": 100,
            "local_contact_1": 156
        }
    },
    "stats": {
        "processed": 4,
        "success": 2,
        "conflicts": 1,
        "errors": 1
    }
}
```

#### GET /conflicts

Liste les conflits en attente de résolution pour ce client.

**Response 200**
```json
{
    "conflicts": [
        {
            "conflict_id": 789,
            "table": "thirdparty",
            "object_id": 42,
            "object_ref": "CU2401-0001",
            "client_data": {...},
            "server_data": {...},
            "field_conflicts": ["name"],
            "created_at": "2026-01-19T14:40:00Z",
            "expires_at": "2026-01-26T14:40:00Z"
        }
    ],
    "total": 1
}
```

#### POST /conflicts/{id}/resolve

Résout un conflit avec la décision de l'utilisateur.

**Request**
```json
{
    "resolution": "merged",
    "data": {
        "name": "Acme Corporation",
        "email": "contact@acme.com",
        "phone": "+33 1 23 45 67 89"
    }
}
```

Résolution possible :
- `client` : Garder la version client
- `server` : Garder la version serveur
- `merged` : Fusion manuelle (data obligatoire)

**Response 200**
```json
{
    "status": "resolved",
    "object": {
        "id": 42,
        "tms": "2026-01-19T14:45:00Z"
    }
}
```

#### GET /status

Retourne l'état de synchronisation du client.

**Response 200**
```json
{
    "client_uuid": "sync_cli_xyz789",
    "last_sync_at": "2026-01-19T14:35:00Z",
    "pending_conflicts": 1,
    "sync_scope": ["thirdparty", "contact", "invoice", "proposal"],
    "server_stats": {
        "thirdparty": {"total": 150, "updated_since_last_sync": 3},
        "contact": {"total": 420, "updated_since_last_sync": 12}
    }
}
```

---

## 5. Gestion des conflits

### 5.1 Principe d'optimisation

Pour éviter de maintenir des métadonnées de synchronisation en permanence (hash, version),
l'algorithme s'appuie sur le champ `tms` (timestamp) natif des tables Dolibarr.

**Avantage** : Zéro overhead en fonctionnement normal. Le calcul de comparaison n'intervient
que lorsqu'un conflit potentiel est détecté.

### 5.2 Algorithme de détection

Lors du PUSH, pour chaque modification client :

```
1. Comparer base_tms (client) avec tms actuel (serveur)

2. Si base_tms == server_tms :
   → Pas de conflit, appliquer la modification

3. Si base_tms != server_tms :
   → Conflit potentiel détecté
   → Comparer les données champ par champ (syncableFields)

   3a. Si données identiques :
       → Faux conflit (modification concurrente identique)
       → Appliquer sans demander résolution

   3b. Si données différentes :
       → Conflit réel
       → Stocker dans llx_sync_conflicts
       → Retourner au client pour résolution manuelle
```

**Implémentation serveur :**

```php
private function detectConflict(string $table, array $clientChange): ?array
{
    $objectId = $clientChange['id'];
    $baseTms = $clientChange['base_tms'];

    // Récupérer l'objet serveur actuel
    $serverObject = $this->fetchObject($table, $objectId);
    if (!$serverObject) {
        return null; // Object deleted on server
    }

    // Comparer les timestamps
    if ($serverObject->tms == $baseTms) {
        return null; // No conflict
    }

    // tms différent : vérifier si les données ont réellement changé
    $dm = dolMapping::createFromObject($serverObject);
    $syncableFields = $dm->getSyncableFields();

    if (!$this->hasDataChanged($clientChange['data'], $serverObject, $syncableFields)) {
        return null; // False conflict - same data
    }

    // Real conflict
    return [
        'server_data' => $dm->toArray(),
        'server_tms' => $serverObject->tms,
        'field_conflicts' => $this->getConflictingFields($clientChange['data'], $serverObject, $syncableFields)
    ];
}

private function hasDataChanged(array $clientData, object $serverData, array $fields): bool
{
    foreach ($fields as $field) {
        $clientValue = $clientData[$field] ?? null;
        $serverValue = $serverData->$field ?? null;

        // Normaliser pour comparaison (trim, cast types)
        if ($this->normalizeValue($clientValue) != $this->normalizeValue($serverValue)) {
            return true;
        }
    }
    return false;
}

private function getConflictingFields(array $clientData, object $serverData, array $fields): array
{
    $conflicts = [];
    foreach ($fields as $field) {
        $clientValue = $clientData[$field] ?? null;
        $serverValue = $serverData->$field ?? null;

        if ($this->normalizeValue($clientValue) != $this->normalizeValue($serverValue)) {
            $conflicts[] = $field;
        }
    }
    return $conflicts;
}
```

### 5.3 Cas d'usage : faux conflits évités

Ce mécanisme évite les "faux conflits" dans plusieurs situations :

| Situation | Sans optimisation | Avec optimisation |
|-----------|-------------------|-------------------|
| 2 users font la même modif | Conflit | Pas de conflit |
| Trigger met à jour tms | Conflit | Pas de conflit si données identiques |
| Champ calculé recalculé | Conflit | Pas de conflit si syncableFields identiques |
| Migration batch | Conflits massifs | Uniquement vrais changements |

### 5.4 Types de conflits

| Type | Description | Exemple |
|------|-------------|---------|
| UPDATE-UPDATE | Modification simultanée | Client et serveur modifient le nom |
| UPDATE-DELETE | Modification vs suppression | Client modifie, serveur supprime |
| DELETE-UPDATE | Suppression vs modification | Client supprime, serveur modifie |
| CREATE-CREATE | Création avec même référence | Deux clients créent "FA2401-0001" |

### 5.5 Verrouillage optimiste (race condition)

**Problème** : Si deux clients pushent le même objet quasi-simultanément, le second
pourrait écraser le premier avant que le conflit soit détecté.

**Solution** : Verrouillage optimiste avec `SELECT ... FOR UPDATE` et retry.

```php
public function applyChange(string $table, array $clientChange): array
{
    $maxRetries = 3;
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $this->db->begin();

        try {
            // Verrouiller la ligne pendant la transaction
            $sql = "SELECT tms FROM ".MAIN_DB_PREFIX.$table;
            $sql .= " WHERE rowid = ".(int)$clientChange['id'];
            $sql .= " FOR UPDATE";

            $resql = $this->db->query($sql);
            if (!$resql || $this->db->num_rows($resql) == 0) {
                $this->db->rollback();
                return ['status' => 'error', 'code' => 'OBJECT_NOT_FOUND'];
            }

            $row = $this->db->fetch_object($resql);
            $currentTms = $row->tms;

            // Vérifier le conflit avec le tms verrouillé
            if ($currentTms != $clientChange['base_tms']) {
                $this->db->rollback();

                // Détecter si c'est un vrai conflit ou un faux
                $conflict = $this->detectConflict($table, $clientChange);
                if ($conflict) {
                    return ['status' => 'conflict', 'data' => $conflict];
                }
                // Faux conflit : retry avec le nouveau tms
                $clientChange['base_tms'] = $currentTms;
                $attempt++;
                continue;
            }

            // Appliquer la modification
            $success = $this->doUpdate($table, $clientChange);

            if ($success) {
                $this->db->commit();
                return ['status' => 'success', 'tms' => dol_now('gmt')];
            }

            $this->db->rollback();
            return ['status' => 'error', 'code' => 'UPDATE_FAILED'];

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    return ['status' => 'error', 'code' => 'MAX_RETRIES_EXCEEDED'];
}
```

**Comportement :**
- Le `FOR UPDATE` verrouille la ligne le temps de la transaction
- Si le `tms` a changé entre la lecture et le verrouillage → retry automatique
- Maximum 3 tentatives avant échec
- Les transactions sont courtes pour minimiser les contentions

### 5.6 Interface de résolution

Le client DOIT présenter une interface de résolution manuelle pour chaque conflit.

```
+---------------------------------------------------------------+
|  CONFLIT DÉTECTÉ - Tiers "Acme Corp"                          |
+---------------------------------------------------------------+
|                                                               |
|  Champ         | Votre version      | Version serveur         |
|  ------------- | ------------------ | ----------------------- |
|  Nom           | Acme Corporation   | Acme Corp (*)           |
|  Email         | -                  | contact@acme.com (*)    |
|  Téléphone     | +33 1 23 45 67 89  | -                       |
|                                                               |
|  (*) Modifié par Marie D. le 19/01/2026 à 14:00              |
|                                                               |
|  [ Garder ma version ]  [ Garder serveur ]  [ Fusionner ]     |
+---------------------------------------------------------------+
```

### 5.4 Règles de fusion

Lors d'une fusion manuelle :
1. L'utilisateur sélectionne champ par champ la valeur à conserver
2. Les champs non conflictuels sont automatiquement fusionnés
3. La version finale est validée avant envoi

### 5.5 Expiration des conflits

- Les conflits non résolus expirent après **7 jours**
- À l'expiration : la version serveur est conservée
- Le client est notifié de l'expiration

---

## 6. Flux de synchronisation

### 6.1 Sync initiale (premier lancement)

```
Client                          Serveur
   |                               |
   |-- POST /register ------------>|
   |<------------ 201 client_uuid -|
   |                               |
   |-- GET /pull?tables=* -------->|
   |<------ 200 {changes: {...}} --|
   |                               |
   |  [Stockage IndexedDB]         |
   |                               |
```

### 6.2 Sync incrémentale (usage normal)

```
Client                          Serveur
   |                               |
   |-- POST /push {changes} ------>|
   |<-- 200 {success, conflicts} --|
   |                               |
   |  [Si conflits]                |
   |  [Afficher UI résolution]     |
   |                               |
   |-- GET /pull?since=... ------->|
   |<------ 200 {changes: {...}} --|
   |                               |
   |  [Mise à jour IndexedDB]      |
   |                               |
```

### 6.3 Résolution de conflit

```
Client                          Serveur
   |                               |
   |-- GET /conflicts ------------>|
   |<------ 200 [{conflict}] ------|
   |                               |
   |  [UI: utilisateur choisit]    |
   |                               |
   |-- POST /conflicts/789/resolve |
   |       {resolution: "merged",  |
   |        data: {...}}           |
   |<------------ 200 resolved ----|
   |                               |
   |  [Mise à jour locale]         |
   |                               |
```

### 6.4 Gestion du mode offline

```
1. Utilisateur modifie un objet
2. Modification stockée dans IndexedDB (pending_changes)
3. Indicateur visuel : "1 modification en attente"

[Retour online]

4. Event "online" détecté
5. Déclenchement automatique de sync()
6. Push des pending_changes
7. Si conflit -> stockage dans pending_conflicts
8. Pull des nouveautés serveur
9. Si pending_conflicts -> affichage UI résolution
```

---

## 7. Intégration smartAuth

### 7.1 Lien avec les devices existants

Le `sync_client` est lié au `smartauth_device` existant :

```php
// Lors du register sync
$device = SmartAuthDevices::getByUUID($db, $device_uuid);
if (!$device) {
    throw new Exception('Device not registered');
}

$syncClient = new SyncClient($db);
$syncClient->fk_device = $device->id;
$syncClient->client_uuid = 'sync_' . generateUUID();
$syncClient->create($user);
```

### 7.2 Permissions

Le scope de synchronisation respecte les permissions Dolibarr :

```php
// Dans SyncController::pull()
$allowedTables = [];
foreach ($requestedTables as $table) {
    $mapping = dolMapping::getByTable($table);
    if ($mapping && $user->hasRight($mapping->module, 'read')) {
        $allowedTables[] = $table;
    }
}
```

### 7.3 Hooks disponibles

```php
// Hook avant push (validation custom)
$hookmanager->executeHooks('smartmaker_beforeSyncPush', [
    'client' => $syncClient,
    'changes' => &$changes
]);

// Hook après résolution conflit
$hookmanager->executeHooks('smartmaker_afterConflictResolution', [
    'conflict' => $conflict,
    'resolution' => $resolution
]);
```

---

## 8. dolMapping - Intégration sync

### 8.1 Architecture : socle commun + modules métier

La synchronisation suit une architecture à deux niveaux :

1. **Socle commun (smartAuth)** : Objets transversaux utilisés par tous les modules métier
2. **Modules métier** : Chaque module déclare ses objets spécifiques via hook

Cette approche évite la duplication : si smartinterventions et smartcommercial ont tous les deux
besoin des tiers offline, ils utilisent la même implémentation fournie par smartAuth.

### 8.2 Socle commun smartAuth

Ces objets sont synchronisés de base par smartAuth car ils sont nécessaires à la plupart des usages terrain.

#### Objets métier de base

| Classe | Table Dolibarr | Description | Priorité |
|--------|----------------|-------------|----------|
| dmThirdparty | llx_societe | Tiers (clients, prospects, fournisseurs) | Haute |
| dmContact | llx_socpeople | Contacts des tiers | Haute |
| dmProduct | llx_product | Produits et services | Haute |
| dmCategory | llx_categorie | Catégories | Moyenne |
| dmUser | llx_user | Utilisateurs (pour "modifié par...") | Moyenne |
| dmAgendaEvent | llx_actioncomm | Événements agenda / RDV | Haute |

#### Dictionnaires (référentiels, lecture seule côté client)

Ces tables sont synchronisées en lecture seule. Elles ne génèrent jamais de conflits
car elles ne sont pas modifiables depuis le client offline.

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmCcountry | llx_c_country | Pays |
| dmCstate | llx_c_departements | Régions / États |
| dmCcivility | llx_c_civility | Civilités (M., Mme, etc.) |
| dmCpaymentterm | llx_c_payment_term | Conditions de paiement |
| dmCpaymenttype | llx_c_paiement | Modes de paiement |
| dmCtypent | llx_c_typent | Types de tiers |
| dmCstcomm | llx_c_stcomm | Statuts commerciaux |
| dmCunits | llx_c_units | Unités de mesure |
| dmCactiontype | llx_c_actioncomm | Types d'événements agenda |
| dmCprospectstatus | llx_c_prospectlevel | Niveaux de prospect |
| dmCincoterm | llx_c_incoterms | Incoterms |
| dmCshipmentmode | llx_c_shipment_mode | Modes d'expédition |
| dmCavailability | llx_c_availability | Délais de disponibilité |
| dmCtypecontact | llx_c_type_contact | Types de contact |
| dmMulticurrency | llx_multicurrency | Devises |

### 8.3 Objets métier (déclarés par les modules)

Chaque module Smartmaker déclare ses objets spécifiques via le hook `smartmaker_registerSyncableObjects`.

#### exemple smartcommercial

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmProposal | llx_propal | Devis |
| dmOrder | llx_commande | Commandes clients |
| dmInvoice | llx_facture | Factures clients |
| dmShipment | llx_expedition | Expéditions |

#### exemple smartpurchase

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmSupplierProposal | llx_supplier_proposal | Demandes de prix |
| dmSupplierOrder | llx_commande_fournisseur | Commandes fournisseurs |
| dmSupplierInvoice | llx_facture_fourn | Factures fournisseurs |
| dmReception | llx_reception | Réceptions |

#### exemple smartinterventions

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmIntervention | llx_fichinter | Fiches d'intervention |
| dmContract | llx_contrat | Contrats |
| dmTicket | llx_ticket | Tickets SAV |
| dmCtickettype | llx_c_ticket_type | Types de tickets |
| dmCticketseverity | llx_c_ticket_severity | Sévérités |
| dmCticketcategory | llx_c_ticket_category | Catégories tickets |
| dmCticketresolution | llx_c_ticket_resolution | Résolutions |

#### exemple smartproject

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmProject | llx_projet | Projets |
| dmTask | llx_projet_task | Tâches |

#### exemple smartstock

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmWarehouse | llx_entrepot | Entrepôts |

#### exemple smartmanufacturing

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmBom | llx_bom_bom | Nomenclatures |
| dmMo | llx_mrp_mo | Ordres de fabrication |

#### exemple smartmember

| Classe | Table Dolibarr | Description |
|--------|----------------|-------------|
| dmMember | llx_adherent | Adhérents |
| dmMemberType | llx_adherent_type | Types d'adhérents |
| dmSubscription | llx_subscription | Cotisations |
| dmDonation | llx_don | Dons |

### 8.4 Déclaration par un module métier

Les modules complémentaires déclarent leurs objets synchronisables via hook.

#### Exemple : smartinterventions

```php
// Dans smartinterventions/class/actions_smartinterventions.class.php
class ActionsSmartInterventions
{
    /**
     * Hook pour déclarer les objets synchronisables
     */
    public function smartmaker_registerSyncableObjects($parameters, &$objects, &$action, $hookmanager)
    {
        // Fiches d'intervention
        $objects['intervention'] = [
            'class' => 'dmIntervention',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmIntervention.php',
            'table' => 'fichinter',
            'label' => 'Interventions',
            'module' => 'ficheinter',
            'priority' => 'high',
            'default_enabled' => true
        ];

        // Contrats
        $objects['contract'] = [
            'class' => 'dmContract',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmContract.php',
            'table' => 'contrat',
            'label' => 'Contrats',
            'module' => 'contrat',
            'priority' => 'medium',
            'default_enabled' => true
        ];

        // Tickets SAV
        $objects['ticket'] = [
            'class' => 'dmTicket',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmTicket.php',
            'table' => 'ticket',
            'label' => 'Tickets',
            'module' => 'ticket',
            'priority' => 'medium',
            'default_enabled' => true
        ];

        // Dictionnaires tickets (lecture seule)
        $objects['c_ticket_type'] = [
            'class' => 'dmCtickettype',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmCtickettype.php',
            'table' => 'c_ticket_type',
            'label' => 'Types de tickets',
            'module' => 'ticket',
            'priority' => 'low',
            'default_enabled' => true,
            'readonly' => true  // Dictionnaire, pas de push client
        ];

        return 0;
    }
}
```

#### Déclaration d'un objet custom (module tiers)

Pour un objet qui n'existe pas encore dans dolMapping :

```php
// Dans monmodule/dolMapping/dmMonObjet.php
require_once DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmBase.php';

class dmMonObjet extends dmBase
{
    protected static $dolibarrClass = 'MonObjet';
    protected static $tableName = 'monmodule_monobjet';
    protected static $moduleRight = 'monmodule';

    protected function getFieldsDefinition(): array
    {
        return [
            'ref' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string'],
            'fk_soc' => ['type' => 'int', 'fk' => 'societe'],
            'montant' => ['type' => 'float'],
            'status' => ['type' => 'int'],
        ];
    }

    protected function getSyncableFields(): array
    {
        return ['ref', 'label', 'fk_soc', 'montant', 'status'];
    }
}

// Dans monmodule/class/actions_monmodule.class.php
class ActionsMonModule
{
    public function smartmaker_registerSyncableObjects($parameters, &$objects, &$action, $hookmanager)
    {
        $objects['monobjet'] = [
            'class' => 'dmMonObjet',
            'file' => '/custom/monmodule/dolMapping/dmMonObjet.php',
            'table' => 'monmodule_monobjet',
            'label' => 'Mon Objet Custom',
            'module' => 'monmodule',
            'priority' => 'low',
            'default_enabled' => false
        ];
        return 0;
    }
}
```

### 8.5 Interface admin

Dans la configuration du module, l'admin peut gérer les objets synchronisables :

```
+------------------------------------------------------------------+
|  SYNCHRONISATION OFFLINE                                         |
+------------------------------------------------------------------+
|                                                                  |
|  SOCLE COMMUN (smartAuth)                          [Toujours activé]
|  ─────────────────────────────────────────────────────────────── |
|  [x] Tiers                          1,234 objets          [Haute]|
|  [x] Contacts                       4,567 objets          [Haute]|
|  [x] Produits / Services            2,100 objets          [Haute]|
|  [x] Agenda / RDV                     890 objets          [Haute]|
|  [x] Catégories                       156 objets        [Moyenne]|
|  [x] Utilisateurs                      45 objets        [Moyenne]|
|  [x] Dictionnaires (15 tables)        ~500 entrées  [Lecture seule]
|                                                                  |
|  MODULES MÉTIER                                                  |
|  ─────────────────────────────────────────────────────────────── |
|  smartinterventions                                              |
|  [x] Interventions                    320 objets          [Haute]|
|  [x] Contrats                         89 objets         [Moyenne]|
|  [x] Tickets SAV                      156 objets        [Moyenne]|
|                                                                  |
|  smartcommercial                                                 |
|  [ ] Devis                            450 objets          [Haute]|
|  [ ] Commandes                        230 objets          [Haute]|
|  [ ] Factures                         890 objets          [Haute]|
|                                                                  |
+------------------------------------------------------------------+
|  Stockage estimé : ~45 Mo    |    Dernière sync : il y a 5 min   |
+------------------------------------------------------------------+
```

### 8.6 Extension dmBase

Ajouter les méthodes sync à la classe de base :

```php
abstract class dmBase
{
    // ... code existant ...

    /**
     * Get tms for sync (uses native Dolibarr tms field)
     */
    public function getTms(): ?string
    {
        return $this->object->tms ?? null;
    }

    /**
     * List of fields to include in sync
     * Override in subclasses to customize
     */
    protected function getSyncableFields(): array
    {
        return array_keys($this->getFieldsDefinition());
    }

    /**
     * Serialize for sync response (includes tms)
     */
    public function toSyncArray(): array
    {
        $data = $this->toArray();
        $data['tms'] = $this->getTms();
        return $data;
    }

    /**
     * Apply changes from sync with validation
     */
    public function applySyncChanges(array $data, User $user): bool
    {
        // Validate against schema
        $schema = ValidationSchemas::getForClass(static::class);
        $sanitized = InputSanitizer::sanitize($data, $schema);

        // Apply changes
        foreach ($sanitized as $field => $value) {
            if (property_exists($this->object, $field)) {
                $this->object->$field = $value;
            }
        }

        return $this->object->update($user) > 0;
    }
}
```

### 8.7 Sérialisation pour sync

```php
// Dans SyncController::pull()
$objects = $mapping->fetchAll($filters);
$syncData = [];

foreach ($objects as $obj) {
    $dm = dolMapping::create($obj);
    $syncData[] = $dm->toSyncArray();  // Données métier + tms
}
```

---

## 9. Sécurité

### 9.1 Authentification

- JWT obligatoire sur tous les endpoints
- Vérification du lien device <-> sync_client
- Révocation cascade si device révoqué

### 9.2 Autorisation

- Respect des permissions Dolibarr (lecture/écriture)
- Filtrage par entity (multi-société)
- Scope de sync limité par configuration

### 9.3 Validation des données

- Sanitization via InputSanitizer existant
- Validation via ValidationSchemas existant
- Vérification `tms` + comparaison données pour éviter écrasement (voir section 5.2)

### 9.4 Rate limiting

Utiliser le RateLimiter existant :

```php
$rateLimiter = new RateLimiter($db);
$rateLimiter->check('sync_push', $user->id, [
    'max_requests' => 60,
    'window_seconds' => 60
]);
```

### 9.5 Audit

Tous les événements sync sont loggués :

```php
$syncEvent = new SyncEvent($db);
$syncEvent->fk_client = $client->id;
$syncEvent->event_type = 'PUSH';
$syncEvent->table_name = 'societe';
$syncEvent->object_id = 42;
$syncEvent->event_data = json_encode(['fields' => ['name', 'phone']]);
$syncEvent->create();
```

---

## 10. Performance

### 10.1 Optimisations serveur

- Index sur `tms` pour requêtes delta
- Pagination obligatoire (max 500 objets/requête)
- Compression gzip des réponses (header `Accept-Encoding: gzip`)
- Cache des résultats de pull (5 min TTL)

### 10.2 Optimisations client

- Sync différentielle (delta uniquement)
- Batch des push (regroupement par table)
- Debounce des modifications (500ms)
- Background sync via Service Worker

### 10.3 Gestion des objets composites volumineux

Les objets composites (invoice avec lignes, order avec lignes) peuvent être volumineux.

**Stratégie : inclusion des sous-objets**

Les lignes sont incluses directement dans l'objet parent (pas de sync séparée) :

```json
{
    "id": 42,
    "ref": "FA2401-0001",
    "fk_soc": 100,
    "total_ttc": 1200.00,
    "tms": "2026-01-19T14:00:00Z",
    "lines": [
        {"rowid": 1, "fk_product": 10, "qty": 2, "price": 500.00},
        {"rowid": 2, "fk_product": 15, "qty": 1, "price": 200.00}
    ]
}
```

**Limites recommandées :**

| Type d'objet | Max lignes incluses | Au-delà |
|--------------|---------------------|---------|
| invoice | 100 lignes | Pagination des lignes |
| order | 100 lignes | Pagination des lignes |
| proposal | 100 lignes | Pagination des lignes |

**Pagination des lignes (objets très volumineux) :**

Pour les documents avec plus de 100 lignes, les lignes sont paginées :

```json
{
    "id": 42,
    "ref": "FA2401-0001",
    "lines_count": 250,
    "lines_included": 100,
    "lines_has_more": true,
    "lines": [/* 100 premières lignes */]
}
```

Le client peut récupérer les lignes suivantes via :
```
GET /pull?table=invoice&id=42&lines_offset=100&lines_limit=100
```

### 10.4 Quotas

| Ressource | Limite | Action si dépassé |
|-----------|--------|-------------------|
| Objets par sync | 500 | Pagination |
| Conflits en attente | 50 | Blocage push |
| Stockage client | Dynamique (voir 10.4) | Purge anciennes données |
| Requêtes sync/min | 60 | Rate limit 429 |

### 10.4 Limites IndexedDB par navigateur

Les limites de stockage IndexedDB varient selon le navigateur et la version.
Elles ne sont plus fixes mais calculées en pourcentage de l'espace disque.

| Navigateur | Limite par origine | Notes |
|------------|-------------------|-------|
| Chrome / Edge | 60% du disque total | Ex: 300 Go sur disque 500 Go |
| Firefox (best-effort) | 10% du disque (max 10 Go) | Mode persistent: 50% (max 8 To) |
| Safari (iOS 17+ / macOS 14+) | ~60% du disque (PWA) | Apps Home Screen traitées comme navigateur |
| Safari (versions antérieures) | 1 Go initial | Prompt utilisateur au-delà |
| Safari mode privé | ~0 | IndexedDB quasi désactivé |

**Détection côté client :**

```javascript
async function getStorageEstimate() {
    if (navigator.storage && navigator.storage.estimate) {
        const estimate = await navigator.storage.estimate();
        return {
            quota: estimate.quota,           // Espace total autorisé (octets)
            usage: estimate.usage,           // Espace utilisé (octets)
            available: estimate.quota - estimate.usage
        };
    }
    // Safari ne supporte pas navigator.storage.estimate()
    return { quota: null, usage: null, available: null };
}
```

**Recommandations :**

1. **Cible pratique** : 500 Mo - 2 Go pour une app terrain typique
2. **Vérification au démarrage** : Alerter si quota < 100 Mo
3. **Safari legacy** : Prévoir fallback ou message d'avertissement pour iOS < 17
4. **Mode privé** : Détecter et informer l'utilisateur que le mode offline est indisponible
5. **Éviction LRU** : Les navigateurs peuvent supprimer les données des origines peu utilisées

**Sources :**
- [MDN - Storage quotas and eviction criteria](https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria)
- [RxDB - IndexedDB Max Storage Limit](https://rxdb.info/articles/indexeddb-max-storage-limit.html)

---

## 11. Maintenance

### 11.1 Cron jobs

```bash
# Nettoyage tombstones > 30 jours
0 2 * * * php /path/to/scripts/sync_maintenance.php --clean-tombstones

# Nettoyage events > 90 jours
0 3 * * 0 php /path/to/scripts/sync_maintenance.php --clean-events

# Expiration conflits > 7 jours
0 4 * * * php /path/to/scripts/sync_maintenance.php --expire-conflicts

# Stats et alertes
0 * * * * php /path/to/scripts/sync_maintenance.php --check-health
```

### 11.2 Monitoring

Métriques à surveiller :

- `sync_clients_active` : Nombre de clients actifs (sync < 24h)
- `sync_conflicts_pending` : Conflits en attente
- `sync_push_latency_p95` : Latence push 95e percentile
- `sync_pull_latency_p95` : Latence pull 95e percentile
- `sync_errors_rate` : Taux d'erreur sync

### 11.3 Alertes

| Condition | Sévérité | Action |
|-----------|----------|--------|
| conflicts_pending > 100 | Warning | Notifier admin |
| error_rate > 5% | Critical | Investigation |
| client_inactive > 7d | Info | Email utilisateur |

---

## 12. Migration

### 12.1 Phase 1 : Schéma

1. Créer les tables sync (clients, tombstones, conflicts, events)
2. Créer la table llx_sync_object_meta (optionnelle, pour objets composites uniquement)
3. Enregistrer les hooks Dolibarr pour les tombstones

### 12.2 Phase 2 : Activation

1. Déployer SyncController
2. Activer les endpoints API
3. Déployer le client PWA

**Note** : Pas d'initialisation nécessaire. Le champ `tms` natif des tables Dolibarr
est déjà présent et maintenu. La sync peut démarrer immédiatement.

### 12.3 Rollback

En cas de problème :
1. Désactiver les endpoints sync
2. Conserver les tables sync (pas de perte de données)
3. Aucun impact sur le fonctionnement normal de Dolibarr

---

## 13. Limitations connues

| Limitation | Impact | Mitigation |
|------------|--------|------------|
| Tombstones 30 jours | Client offline > 30j peut manquer suppressions | Warning à la reconnexion |
| Safari legacy (< iOS 17) | Limite 1 Go, prompt utilisateur | Message d'avertissement |
| Safari mode privé | IndexedDB indisponible | Détection + message erreur |
| Conflit tri-directionnel | 3+ clients modifient simultanément | Résolution séquentielle |

---

## 14. Roadmap

### v1.0 (MVP)

- [x] Schéma de données
- [ ] SyncController (endpoints REST)
- [ ] OfflineSyncManager (logique serveur)
- [ ] Client JavaScript (IndexedDB)
- [ ] UI résolution conflits (basique)
- [ ] Intégration dolMapping

### v1.1

- [ ] Service Worker pour sync background
- [ ] Notifications push (nouveaux conflits)
- [ ] Dashboard admin (stats sync)
- [ ] Export/import scope configuration

### v2.0

- [x] Sync des fichiers/documents (ObjectDocumentController + intégration ECM share hash)
- [ ] Sync sélective par champ
- [ ] Mode "lecture seule" offline
- [ ] Multi-instance sync (plusieurs Dolibarr)

---

## 15. Synchronisation des documents (Blobs)

### 15.1 Objectif

Permettre aux applications clientes de télécharger les fichiers attachés aux objets Dolibarr
(notices techniques produits, photos, PDF, etc.) pour un accès hors ligne.

### 15.2 Architecture

**Approche hybride recommandée :**

| Type de fichier | Stockage client | Raison |
|-----------------|-----------------|--------|
| Images | Cache API (Workbox) | Mise en cache automatique via Service Worker |
| PDFs / documents | IndexedDB (Blob) | Lien explicite avec l'objet, requêtable |

### 15.3 API SmartAuth - ObjectDocumentController

SmartAuth fournit un controller générique pour lister et télécharger les documents
attachés aux objets Dolibarr.

#### Types d'objets supportés

| Type | Module Dolibarr | Répertoire documents |
|------|-----------------|---------------------|
| `product` | product | `documents/produit/{ref}/` |
| `thirdparty` | societe | `documents/societe/{name}/` |
| `project` | projet | `documents/projet/{ref}/` |
| `intervention` | ficheinter | `documents/ficheinter/{ref}/` |
| `category` | categorie | `documents/categorie/{id}/` |

#### Extension par les modules

Les modules peuvent enregistrer des types supplémentaires via :

```php
use SmartAuth\Api\ObjectDocumentController;

ObjectDocumentController::registerObjectType('myobject', [
    'class' => 'MyObject',
    'file' => '/custom/mymodule/class/myobject.class.php',
    'module' => 'mymodule',
    'modulepart' => 'mymodule',
    'subdir_method' => 'getMyObjectSubdir',
]);
```

### 15.4 Endpoints

#### GET /object/{type}/{id}/documents

Liste les documents attachés à un objet. Retourne les métadonnées (pas le contenu),
enrichies avec les informations ECM Dolibarr (`ecm_id`, `share` hash).

Si une entrée `llx_ecm_files` n'existe pas pour un fichier, elle est automatiquement
créée avec un share hash généré. Cela permet de « guérir » la base ECM Dolibarr
au fur et à mesure.

**Paramètres URL :**
- `type` : Type d'objet (product, thirdparty, project, intervention, category)
- `id` : ID de l'objet (rowid)

**Paramètres query :**
- `since` : (optionnel) Timestamp ISO - ne retourne que les fichiers modifiés après cette date

**Réponse 200 :**

```json
{
    "documents": [
        {
            "id": "a1b2c3d4",
            "object_id": 15,
            "filename": "notice_technique_XR500.pdf",
            "relative_path": "notice_technique_XR500.pdf",
            "mime_type": "application/pdf",
            "size": 245000,
            "updated_at": "2026-02-18T10:30:00+00:00",
            "type": "pdf",
            "ecm_id": 1234,
            "share": "abc123def456ghi789jkl012mno345pq"
        },
        {
            "id": "e5f6g7h8",
            "object_id": 15,
            "filename": "photo_produit.jpg",
            "relative_path": "photos/photo_produit.jpg",
            "mime_type": "image/jpeg",
            "size": 156000,
            "updated_at": "2026-02-15T14:00:00+00:00",
            "type": "image",
            "ecm_id": 1235,
            "share": "rst678uvw901xyz234abc567def890ghi"
        }
    ],
    "server_time": "2026-02-18T14:00:00+00:00"
}
```

#### Téléchargement via share hash (recommandé)

**`GET /object/{type}/{id}/document?q={share_hash}`** (base64)
**`GET /object/{type}/{id}/document/binary?q={share_hash}`** (binaire)

Le share hash est obtenu via le champ `share` retourné par l'endpoint `documents`.
Ce mode est recommandé car il évite les problèmes d'encodage d'URL avec les
chemins contenant des sous-répertoires (ex: `photos/photo_31.jpg`).

**Paramètres URL :**
- `type` : Type d'objet
- `id` : ID de l'objet

**Paramètres query :**
- `q` : Share hash ECM du document (32 caractères)

**Réponse 200 (base64) :**

```json
{
    "filename": "notice_technique_XR500.pdf",
    "content-type": "application/pdf",
    "filesize": 245000,
    "content": "JVBERi0xLjQKJ...",
    "encoding": "base64"
}
```

**Réponse 200 (binaire) :**

Headers :
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="notice_technique_XR500.pdf"
Content-Length: 245000
```

**Limite :** Fichiers de 50 Mo maximum en mode base64 (pas de limite en mode binaire).

#### Téléchargement via chemin (legacy)

**`GET /object/{type}/{id}/document/{path}`** (base64)
**`GET /object/{type}/{id}/document/{path}/binary`** (binaire)

Mode legacy pour compatibilité avec les modules existants. Fonctionne uniquement
pour les noms de fichiers simples sans sous-répertoires (le placeholder `{path}`
ne supporte pas les `/` dans l'URL).

**Paramètres URL :**
- `type` : Type d'objet
- `id` : ID de l'objet
- `path` : Nom du fichier (URL-encoded, sans sous-répertoire)

#### Téléchargement groupé (bundle ZIP)

**`POST /object/documents/bundle`**

Télécharge plusieurs documents en une seule requête sous forme d'archive ZIP.
Optimisé pour la synchronisation offline : au lieu de N requêtes individuelles,
le client envoie la liste des share hashes et reçoit un ZIP contenant tous les fichiers.

**Body JSON :**

```json
{
    "shares": ["a1b2c3d4...", "e5f6g7h8...", "..."],
    "max_file_size": 5242880
}
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `shares` | `string[]` | Liste des share hashes à télécharger (max 500) |
| `max_file_size` | `int` | (optionnel) Taille max par fichier en octets (défaut/max : 5 Mo) |

**Réponse** : flux binaire ZIP (`Content-Type: application/zip`)

**Structure du ZIP :**

```
bundle.zip
├── manifest.json          # Métadonnées et statut de chaque fichier
└── files/
    ├── a1b2c3d4...        # Fichier nommé par son share hash
    ├── e5f6g7h8...
    └── ...
```

Les fichiers sont stockés sans compression (méthode STORE) car les images et PDF
sont déjà compressés.

**manifest.json :**

```json
{
    "included": [
        {"share": "a1b2c3d4...", "filename": "photo.jpg", "mime_type": "image/jpeg", "size": 82263}
    ],
    "oversized": [
        {"share": "x9y8z7w6...", "filename": "video.mp4", "mime_type": "video/mp4", "size": 52428800}
    ],
    "remaining": ["hash1...", "hash2..."],
    "errors": [
        {"share": "invalid...", "error": "not_found"}
    ],
    "server_time": 1740900000
}
```

| Champ | Description |
|-------|-------------|
| `included` | Fichiers inclus dans le ZIP avec métadonnées |
| `oversized` | Fichiers ignorés car dépassant `max_file_size` (à télécharger individuellement) |
| `remaining` | Hashes ignorés car la limite totale du bundle (100 Mo) a été atteinte |
| `errors` | Hashes non résolus (`not_found`) ou fichiers physiquement absents (`file_missing`) |

**Limites :**

| Limite | Valeur | Description |
|--------|--------|-------------|
| Shares par requête | 500 | Nombre max de share hashes dans le body |
| Taille par fichier | 5 Mo | Fichiers plus gros vont dans `oversized` |
| Taille totale du ZIP | 100 Mo | Au-delà, les hashes restants vont dans `remaining` |

### 15.5 Intégration avec le sync client

#### Workflow recommandé

```
1. PULL des métadonnées documents
   GET /object/product/{id}/documents?since={last_sync}
   → Réponse inclut ecm_id et share hash pour chaque document

2. Comparaison avec IndexedDB local
   - Nouveaux documents : à télécharger
   - Documents mis à jour (updated_at > local) : à re-télécharger
   - Documents absents de la réponse : à supprimer localement

3. Téléchargement des documents
   Option A (groupé, recommandé) :
     POST /object/documents/bundle  { shares: [...] }
     → Dézipper, stocker chaque fichier dans IndexedDB
   Option B (individuel, fallback pour fichiers > 5 Mo) :
     GET /object/product/{id}/document?q={share_hash}
     → Stocker le Blob dans IndexedDB

4. Mise à jour de last_sync pour les documents
```

#### Schéma IndexedDB recommandé

```javascript
// Nouveau store pour les documents
objectDocuments: {
    keyPath: 'local_id',
    autoIncrement: true,
    indexes: [
        { name: 'object_key', keyPath: ['object_type', 'object_id'] },
        { name: 'object_type_file_type', keyPath: ['object_type', 'object_id', 'type'] },
        { name: 'server_id', keyPath: 'server_id' },
        { name: 'share', keyPath: 'share' },
        { name: 'synced_at', keyPath: 'synced_at' }
    ]
}

// Structure d'un enregistrement
{
    local_id: 1,              // Auto-increment PK
    object_type: "product",   // Type d'objet Dolibarr
    object_id: 15,            // ID de l'objet
    server_id: "a1b2c3d4",    // ID retourné par l'API
    ecm_id: 1234,             // rowid dans llx_ecm_files
    share: "abc123def456...", // Share hash ECM (pour téléchargement)
    type: "pdf",              // "image" | "pdf" | "other"
    filename: "notice.pdf",
    relative_path: "notice.pdf",
    mime_type: "application/pdf",
    blob: Blob,               // Le contenu du fichier
    size: 245000,
    synced_at: "2026-02-18T14:00:00Z",
    server_updated_at: "2026-02-18T10:30:00Z"
}
```

#### Exemple d'implémentation client

```javascript
async function syncObjectDocuments(objectType, objectId, lastSync) {
    // 1. Récupérer les métadonnées (inclut share hash)
    const params = lastSync ? `?since=${lastSync}` : '';
    const response = await api.get(`/object/${objectType}/${objectId}/documents${params}`);
    const serverDocs = response.documents;

    // 2. Récupérer les documents locaux
    const localDocs = await db.objectDocuments
        .where(['object_type', 'object_id']).equals([objectType, objectId])
        .toArray();
    const localById = new Map(localDocs.map(d => [d.server_id, d]));

    // 3. Identifier les documents à télécharger
    const toDownload = [];
    const serverIds = new Set();

    for (const doc of serverDocs) {
        serverIds.add(doc.id);
        const local = localById.get(doc.id);

        if (!local || new Date(doc.updated_at) > new Date(local.server_updated_at)) {
            toDownload.push(doc);
        }
    }

    // 4. Télécharger les nouveaux/mis à jour
    if (toDownload.length > 0) {
        // Séparer petits fichiers (bundle) et gros fichiers (individuels)
        const BUNDLE_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
        const forBundle = toDownload.filter(d => d.size <= BUNDLE_MAX_FILE_SIZE);
        const forIndividual = toDownload.filter(d => d.size > BUNDLE_MAX_FILE_SIZE);

        // 4a. Bundle ZIP pour les petits fichiers
        if (forBundle.length > 0) {
            const shares = forBundle.map(d => d.share);
            const zipResponse = await fetch(`${API_BASE}/object/documents/bundle`, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ shares }),
            });
            const zipBlob = await zipResponse.blob();
            const zip = await JSZip.loadAsync(zipBlob);
            const manifest = JSON.parse(await zip.file('manifest.json').async('text'));

            // Stocker chaque fichier inclus
            for (const meta of manifest.included) {
                const fileData = await zip.file(`files/${meta.share}`).async('blob');
                const doc = forBundle.find(d => d.share === meta.share);
                const local = localById.get(doc.id);
                await db.objectDocuments.put({
                    ...(local ? { local_id: local.local_id } : {}),
                    object_type: objectType,
                    object_id: objectId,
                    server_id: doc.id,
                    ecm_id: doc.ecm_id,
                    share: doc.share,
                    type: doc.type,
                    filename: doc.filename,
                    relative_path: doc.relative_path,
                    mime_type: meta.mime_type,
                    blob: fileData,
                    size: meta.size,
                    synced_at: new Date().toISOString(),
                    server_updated_at: doc.updated_at,
                });
            }
        }

        // 4b. Téléchargement individuel pour les gros fichiers
        for (const doc of forIndividual) {
            const fileResponse = await fetch(
                `${API_BASE}/object/${objectType}/${objectId}/document/binary?q=${doc.share}`,
                { headers: { Authorization: `Bearer ${token}` } }
            );
            const blob = await fileResponse.blob();
            const local = localById.get(doc.id);
            await db.objectDocuments.put({
                ...(local ? { local_id: local.local_id } : {}),
                object_type: objectType,
                object_id: objectId,
                server_id: doc.id,
                ecm_id: doc.ecm_id,
                share: doc.share,
                type: doc.type,
                filename: doc.filename,
                relative_path: doc.relative_path,
                mime_type: doc.mime_type,
                blob: blob,
                size: doc.size,
                synced_at: new Date().toISOString(),
                server_updated_at: doc.updated_at,
            });
        }
    }

    // 5. Supprimer les documents qui n'existent plus sur le serveur
    // (seulement si on a fait un sync complet, pas incrémental)
    if (!lastSync) {
        for (const local of localDocs) {
            if (!serverIds.has(local.server_id)) {
                await db.objectDocuments.delete(local.local_id);
            }
        }
    }
}

function base64ToBlob(base64, mimeType) {
    const byteCharacters = atob(base64);
    const byteNumbers = new Array(byteCharacters.length);
    for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
    }
    const byteArray = new Uint8Array(byteNumbers);
    return new Blob([byteArray], { type: mimeType });
}
```

### 15.6 Gestion du stockage

#### Monitoring du quota

```javascript
async function getStorageInfo() {
    const estimate = await navigator.storage.estimate();
    const docsSize = await getDocumentsTotalSize();

    return {
        quotaTotal: estimate.quota,
        quotaUsed: estimate.usage,
        documentsSize: docsSize,
        documentsPercent: Math.round((docsSize / estimate.usage) * 100)
    };
}

async function getDocumentsTotalSize() {
    const docs = await db.productDocuments.toArray();
    return docs.reduce((sum, d) => sum + d.size, 0);
}
```

#### Stratégies de purge

| Stratégie | Description | Quand l'utiliser |
|-----------|-------------|------------------|
| Tout purger | Supprimer tous les documents en cache | Reset complet |
| Par ancienneté | Supprimer les documents non accédés depuis N jours | Maintenance régulière |
| Par produit | Supprimer les documents des produits non utilisés | Catalogues volumineux |

### 15.7 Permissions

Les endpoints respectent les permissions Dolibarr :

- L'utilisateur doit avoir le droit `read` ou `lire` sur le module concerné
- Le filtrage par entity (multi-société) est appliqué
- Aucune vérification de permissions au niveau fichier individuel (si l'utilisateur peut voir l'objet, il peut voir ses documents)

### 15.8 Sécurité

- **Share hash** : Le mode recommandé utilise des hashes ECM opaques (32 caractères), évitant l'exposition des chemins de fichiers dans l'URL
- **Path traversal** : En mode legacy, les chemins sont validés pour empêcher les attaques `../`
- **Authentification** : JWT obligatoire sur tous les endpoints
- **Types de fichiers** : Seuls les fichiers dans le répertoire documents de l'objet sont accessibles
- **Taille** : Limite de 50 Mo pour le mode base64 (pas de limite pour le mode binaire)
- **Auto-création ECM** : Les entrées `llx_ecm_files` créées automatiquement sont liées à l'objet source (`src_object_type`, `src_object_id`)

---

## Annexes

### A. Codes d'erreur

| Code | Message | Description |
|------|---------|-------------|
| SYNC_001 | CLIENT_NOT_REGISTERED | Client UUID inconnu |
| SYNC_002 | DEVICE_REVOKED | Device JWT révoqué |
| SYNC_003 | SCOPE_DENIED | Table non autorisée |
| SYNC_004 | VERSION_CONFLICT | Conflit de version détecté |
| SYNC_005 | CONFLICT_EXPIRED | Conflit expiré (> 7 jours) |
| SYNC_006 | QUOTA_EXCEEDED | Limite atteinte |
| SYNC_007 | INVALID_RESOLUTION | Résolution invalide |

### B. Diagramme de séquence complet

```
┌─────────┐          ┌─────────┐          ┌─────────┐          ┌─────────┐
│  User   │          │   PWA   │          │   API   │          │   DB    │
└────┬────┘          └────┬────┘          └────┬────┘          └────┬────┘
     │                    │                    │                    │
     │  Modifie objet     │                    │                    │
     │───────────────────>│                    │                    │
     │                    │                    │                    │
     │                    │ Save IndexedDB     │                    │
     │                    │──────────┐         │                    │
     │                    │          │         │                    │
     │                    │<─────────┘         │                    │
     │                    │                    │                    │
     │  [Retour online]   │                    │                    │
     │                    │                    │                    │
     │                    │ POST /push         │                    │
     │                    │───────────────────>│                    │
     │                    │                    │                    │
     │                    │                    │ Check version      │
     │                    │                    │───────────────────>│
     │                    │                    │                    │
     │                    │                    │ Conflict detected  │
     │                    │                    │<───────────────────│
     │                    │                    │                    │
     │                    │ 200 {conflicts}    │                    │
     │                    │<───────────────────│                    │
     │                    │                    │                    │
     │  Affiche conflit   │                    │                    │
     │<───────────────────│                    │                    │
     │                    │                    │                    │
     │  Choisit "merged"  │                    │                    │
     │───────────────────>│                    │                    │
     │                    │                    │                    │
     │                    │ POST /resolve      │                    │
     │                    │───────────────────>│                    │
     │                    │                    │                    │
     │                    │                    │ Update + log       │
     │                    │                    │───────────────────>│
     │                    │                    │                    │
     │                    │ 200 resolved       │                    │
     │                    │<───────────────────│                    │
     │                    │                    │                    │
     │  Confirmation      │                    │                    │
     │<───────────────────│                    │                    │
     │                    │                    │                    │
```

### C. Références

- Prototype existant : `dev-sync-offline/`
- Architecture smartAuth : `api/AuthController.php`
- dolMapping : `dolMapping/dmBase.php`
- Schémas validation : `api/ValidationSchemas.php`
