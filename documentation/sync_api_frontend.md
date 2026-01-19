# Sync API - Documentation Frontend

Documentation technique pour l'implementation du client de synchronisation offline.

## Vue d'ensemble

L'API Sync permet aux applications mobiles/web de fonctionner en mode offline avec synchronisation bidirectionnelle vers le serveur Dolibarr.

### Architecture

```
+-------------------+       +-------------------+       +-------------------+
|   Application     |       |    IndexedDB      |       |   Serveur API     |
|    Frontend       | <---> |  (Stockage local) | <---> |   SmartAuth       |
+-------------------+       +-------------------+       +-------------------+
```

### Flux de synchronisation

1. **Enregistrement** : Le client s'enregistre aupres du serveur
2. **Pull initial** : Recuperation de toutes les donnees (premier sync)
3. **Travail offline** : Modifications stockees localement
4. **Push** : Envoi des modifications au serveur
5. **Resolution des conflits** : Gestion manuelle si necessaire

---

## Authentification

Toutes les requetes necessitent un token JWT valide.

```http
Authorization: Bearer <access_token>
```

Le token est obtenu via l'endpoint `/auth/login` de SmartAuth.

---

## Endpoints

### POST /sync/register

Enregistre un nouveau client de synchronisation.

**Request:**

```json
{
    "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "app_version": "1.2.3",
    "sync_scope": ["thirdparty", "contact", "product"]
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `client_uuid` | string | Oui | UUID unique du client (genere cote client) |
| `app_version` | string | Non | Version de l'application |
| `sync_scope` | string[] | Non | Types d'objets a synchroniser (defaut: tous) |

**Response (200):**

```json
{
    "client_id": 123,
    "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "server_time": "2025-01-19T10:30:00+00:00",
    "sync_scope": {
        "thirdparty": true,
        "contact": true,
        "product": true
    }
}
```

---

### GET /sync/pull

Recupere les modifications depuis le serveur.

**Query Parameters:**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `client_uuid` | string | Oui | UUID du client |
| `object_type` | string | Oui | Type d'objet (thirdparty, contact, product) |
| `last_sync_at` | string | Non | Timestamp ISO du dernier sync (optionnel) |

**Request:**

```
GET /sync/pull?client_uuid=550e8400-e29b-41d4-a716-446655440000&object_type=thirdparty
```

**Response (200):**

```json
{
    "updated": [
        {
            "id": 1,
            "nom": "Entreprise A",
            "email": "contact@entreprise-a.fr",
            "tms": "2025-01-19T10:00:00+00:00"
        },
        {
            "id": 2,
            "nom": "Entreprise B",
            "email": "info@entreprise-b.fr",
            "tms": "2025-01-19T10:15:00+00:00"
        }
    ],
    "deleted": [
        {
            "id": 5,
            "deleted_at": "2025-01-19T09:00:00+00:00"
        }
    ],
    "server_time": "2025-01-19T10:30:00+00:00"
}
```

> **Important:** Le champ `tms` est le timestamp de derniere modification serveur. Il doit etre conserve pour la detection des conflits lors du push.

---

### POST /sync/push

Envoie les modifications locales vers le serveur.

**Request:**

```json
{
    "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "object_type": "thirdparty",
    "changes": [
        {
            "action": "create",
            "temp_id": "local-123",
            "data": {
                "nom": "Nouvelle Entreprise",
                "email": "nouveau@example.fr"
            }
        },
        {
            "action": "update",
            "id": 1,
            "base_tms": "2025-01-19T10:00:00+00:00",
            "data": {
                "email": "updated@entreprise-a.fr"
            }
        },
        {
            "action": "delete",
            "id": 5,
            "base_tms": "2025-01-19T08:00:00+00:00"
        }
    ]
}
```

**Structure d'un changement:**

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `action` | string | Oui | `create`, `update`, ou `delete` |
| `id` | number | Oui* | ID serveur de l'objet (*sauf pour create) |
| `temp_id` | string | Non | ID temporaire local (pour create) |
| `base_tms` | string | Oui* | Timestamp de base pour detection conflit (*sauf create) |
| `data` | object | Oui* | Donnees de l'objet (*sauf delete) |

**Response (200):**

```json
{
    "success": [1, 2],
    "conflicts": [
        {
            "object_id": 3,
            "client_tms": "2025-01-19T09:00:00+00:00",
            "server_tms": "2025-01-19T09:30:00+00:00",
            "field_conflicts": {
                "email": {
                    "client": "client@example.fr",
                    "server": "server@example.fr"
                }
            }
        }
    ],
    "errors": [
        {
            "id": 4,
            "error": "Validation failed: email format invalid"
        }
    ],
    "id_mapping": {
        "local-123": 456
    },
    "server_time": "2025-01-19T10:30:00+00:00"
}
```

| Champ | Description |
|-------|-------------|
| `success` | IDs des objets synchronises avec succes |
| `conflicts` | Liste des conflits detectes (resolution manuelle requise) |
| `errors` | Liste des erreurs (validation, permissions, etc.) |
| `id_mapping` | Mapping temp_id -> server_id pour les creations |

---

### GET /sync/status

Obtient le statut de synchronisation du client.

**Request:**

```
GET /sync/status?client_uuid=550e8400-e29b-41d4-a716-446655440000
```

**Response (200):**

```json
{
    "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "last_sync_at": "2025-01-19T10:00:00+00:00",
    "pending_conflicts": 2,
    "server_time": "2025-01-19T10:30:00+00:00",
    "sync_scope": {
        "thirdparty": true,
        "contact": true,
        "product": true
    }
}
```

---

### GET /sync/conflicts

Liste les conflits en attente de resolution.

**Request:**

```
GET /sync/conflicts?client_uuid=550e8400-e29b-41d4-a716-446655440000
```

**Response (200):**

```json
{
    "conflicts": [
        {
            "id": 1,
            "table_name": "societe",
            "object_id": 123,
            "client_data": {
                "nom": "Client Version",
                "email": "client@example.fr"
            },
            "server_data": {
                "nom": "Server Version",
                "email": "server@example.fr"
            },
            "client_tms": "2025-01-19T09:00:00+00:00",
            "server_tms": "2025-01-19T09:30:00+00:00",
            "field_conflicts": {
                "nom": {
                    "client": "Client Version",
                    "server": "Server Version"
                },
                "email": {
                    "client": "client@example.fr",
                    "server": "server@example.fr"
                }
            },
            "date_creation": "2025-01-19T10:00:00+00:00"
        }
    ],
    "server_time": "2025-01-19T10:30:00+00:00"
}
```

---

### POST /sync/conflicts/{id}/resolve

Resout un conflit de synchronisation.

**Request:**

```json
{
    "resolution": "merged",
    "data": {
        "nom": "Nom Final Choisi",
        "email": "final@example.fr"
    }
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `resolution` | string | Oui | `client`, `server`, ou `merged` |
| `data` | object | Non* | Donnees finales (*requis si resolution=merged) |

**Strategies de resolution:**

| Strategy | Description |
|----------|-------------|
| `client` | Garder la version client (ecrase le serveur) |
| `server` | Garder la version serveur (abandonne les modifications client) |
| `merged` | Fusion manuelle (necessite le champ `data`) |

**Response (200):**

```json
{
    "success": true,
    "message": "Conflict resolved successfully"
}
```

---

## Types d'objets synchronisables

### Phase 1 (MVP)

| Type | Table Dolibarr | Description |
|------|----------------|-------------|
| `thirdparty` | `llx_societe` | Tiers (clients, fournisseurs) |
| `contact` | `llx_socpeople` | Contacts |
| `product` | `llx_product` | Produits et services |

### Priorite de synchronisation

Les objets ont une priorite de sync qui determine l'ordre de traitement:

- **high**: thirdparty, contact (synchronises en premier)
- **medium**: product
- **low**: dictionnaires (readonly)

---

## Schema IndexedDB recommande

```javascript
const dbSchema = {
    version: 1,
    stores: {
        // Stockage des objets synchronises
        thirdparties: {
            keyPath: 'id',
            indexes: {
                tms: 'tms',
                sync_status: 'sync_status'
            }
        },
        contacts: {
            keyPath: 'id',
            indexes: {
                tms: 'tms',
                fk_soc: 'fk_soc',
                sync_status: 'sync_status'
            }
        },
        products: {
            keyPath: 'id',
            indexes: {
                tms: 'tms',
                sync_status: 'sync_status'
            }
        },

        // File d'attente des modifications locales
        sync_queue: {
            keyPath: 'local_id',
            autoIncrement: true,
            indexes: {
                object_type: 'object_type',
                action: 'action',
                created_at: 'created_at'
            }
        },

        // Metadonnees de synchronisation
        sync_meta: {
            keyPath: 'key'
        }
    }
};
```

### Structure sync_queue

```javascript
{
    local_id: 1,              // Auto-increment
    object_type: 'thirdparty',
    action: 'update',         // create, update, delete
    object_id: 123,           // ID serveur (null pour create)
    temp_id: 'local-abc',     // ID temporaire (pour create)
    base_tms: '2025-01-19T10:00:00+00:00',
    data: { /* modifications */ },
    created_at: '2025-01-19T10:30:00+00:00',
    retry_count: 0
}
```

### Structure sync_meta

```javascript
// Dernier timestamp de sync par type
{ key: 'last_sync_thirdparty', value: '2025-01-19T10:00:00+00:00' }
{ key: 'last_sync_contact', value: '2025-01-19T10:00:00+00:00' }

// UUID client
{ key: 'client_uuid', value: '550e8400-e29b-41d4-a716-446655440000' }
```

---

## Implementation du client

### Classe SyncManager (exemple)

```javascript
class SyncManager {
    constructor(apiBaseUrl, authToken) {
        this.apiBaseUrl = apiBaseUrl;
        this.authToken = authToken;
        this.clientUUID = null;
        this.db = null;
    }

    async init() {
        // Ouvrir IndexedDB
        this.db = await this.openDatabase();

        // Recuperer ou generer client_uuid
        let meta = await this.db.get('sync_meta', 'client_uuid');
        if (!meta) {
            this.clientUUID = crypto.randomUUID();
            await this.db.put('sync_meta', {
                key: 'client_uuid',
                value: this.clientUUID
            });
        } else {
            this.clientUUID = meta.value;
        }
    }

    async register(appVersion) {
        const response = await this.apiCall('POST', '/sync/register', {
            client_uuid: this.clientUUID,
            app_version: appVersion
        });
        return response;
    }

    async pull(objectType) {
        // Recuperer le dernier timestamp de sync
        const lastSyncKey = `last_sync_${objectType}`;
        const meta = await this.db.get('sync_meta', lastSyncKey);
        const lastSyncAt = meta?.value || null;

        const response = await this.apiCall('GET', '/sync/pull', {
            client_uuid: this.clientUUID,
            object_type: objectType,
            last_sync_at: lastSyncAt
        });

        // Appliquer les mises a jour
        for (const item of response.updated) {
            await this.db.put(this.getStoreName(objectType), {
                ...item,
                sync_status: 'synced'
            });
        }

        // Supprimer les objets marques comme supprimes
        for (const deleted of response.deleted) {
            await this.db.delete(this.getStoreName(objectType), deleted.id);
        }

        // Mettre a jour le timestamp
        await this.db.put('sync_meta', {
            key: lastSyncKey,
            value: response.server_time
        });

        return response;
    }

    async push(objectType) {
        // Recuperer les modifications en attente
        const queue = await this.db.getAllFromIndex(
            'sync_queue',
            'object_type',
            objectType
        );

        if (queue.length === 0) return { success: [] };

        // Preparer les changements
        const changes = queue.map(item => ({
            action: item.action,
            id: item.object_id,
            temp_id: item.temp_id,
            base_tms: item.base_tms,
            data: item.data
        }));

        const response = await this.apiCall('POST', '/sync/push', {
            client_uuid: this.clientUUID,
            object_type: objectType,
            changes: changes
        });

        // Traiter les succes
        for (const localItem of queue) {
            const isSuccess = response.success.includes(localItem.object_id) ||
                (localItem.temp_id && response.id_mapping[localItem.temp_id]);

            if (isSuccess) {
                // Supprimer de la queue
                await this.db.delete('sync_queue', localItem.local_id);

                // Mettre a jour l'ID si creation
                if (localItem.temp_id && response.id_mapping[localItem.temp_id]) {
                    const newId = response.id_mapping[localItem.temp_id];
                    const storeName = this.getStoreName(objectType);
                    const obj = await this.db.get(storeName, localItem.temp_id);
                    if (obj) {
                        await this.db.delete(storeName, localItem.temp_id);
                        obj.id = newId;
                        obj.sync_status = 'synced';
                        await this.db.put(storeName, obj);
                    }
                }
            }
        }

        return response;
    }

    // Ajouter une modification a la queue
    async queueChange(objectType, action, objectId, data, baseTms = null) {
        const tempId = action === 'create' ? `local-${Date.now()}` : null;

        await this.db.add('sync_queue', {
            object_type: objectType,
            action: action,
            object_id: objectId,
            temp_id: tempId,
            base_tms: baseTms,
            data: data,
            created_at: new Date().toISOString(),
            retry_count: 0
        });

        return tempId;
    }

    async getConflicts() {
        return this.apiCall('GET', '/sync/conflicts', {
            client_uuid: this.clientUUID
        });
    }

    async resolveConflict(conflictId, resolution, data = null) {
        const body = { resolution };
        if (data) body.data = data;

        return this.apiCall('POST', `/sync/conflicts/${conflictId}/resolve`, body);
    }

    // Synchronisation complete
    async fullSync() {
        const objectTypes = ['thirdparty', 'contact', 'product'];
        const results = { pulls: {}, pushes: {}, errors: [] };

        for (const type of objectTypes) {
            try {
                // Push d'abord
                results.pushes[type] = await this.push(type);
                // Puis pull
                results.pulls[type] = await this.pull(type);
            } catch (error) {
                results.errors.push({ type, error: error.message });
            }
        }

        return results;
    }

    // Helper: appel API
    async apiCall(method, endpoint, params = {}) {
        const url = new URL(this.apiBaseUrl + endpoint);
        const options = {
            method: method,
            headers: {
                'Authorization': `Bearer ${this.authToken}`,
                'Content-Type': 'application/json'
            }
        };

        if (method === 'GET') {
            Object.entries(params).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    url.searchParams.append(key, value);
                }
            });
        } else {
            options.body = JSON.stringify(params);
        }

        const response = await fetch(url, options);
        if (!response.ok) {
            throw new Error(`API error: ${response.status}`);
        }
        return response.json();
    }

    getStoreName(objectType) {
        const mapping = {
            'thirdparty': 'thirdparties',
            'contact': 'contacts',
            'product': 'products'
        };
        return mapping[objectType] || objectType;
    }

    async openDatabase() {
        // Implementation avec idb ou IndexedDB natif
        // ...
    }
}
```

---

## Gestion des conflits (UI)

### Ecran de resolution recommande

```
+--------------------------------------------------+
|  Conflit detecte sur: Entreprise ABC             |
|  Object: thirdparty #123                         |
+--------------------------------------------------+
|                                                  |
|  Champ: nom                                      |
|  +--------------------+  +--------------------+  |
|  | Version Client     |  | Version Serveur    |  |
|  | "ABC Corporation"  |  | "ABC Corp"         |  |
|  +--------------------+  +--------------------+  |
|                                                  |
|  Champ: email                                    |
|  +--------------------+  +--------------------+  |
|  | client@abc.fr      |  | server@abc.fr      |  |
|  +--------------------+  +--------------------+  |
|                                                  |
+--------------------------------------------------+
|  [Garder Client]  [Garder Serveur]  [Fusionner]  |
+--------------------------------------------------+
```

### Flux de resolution

1. Afficher les conflits en attente (`GET /sync/conflicts`)
2. Presenter les differences champ par champ
3. Laisser l'utilisateur choisir pour chaque champ
4. Envoyer la resolution (`POST /sync/conflicts/{id}/resolve`)

---

## Codes d'erreur

| Code HTTP | Description |
|-----------|-------------|
| 400 | Requete invalide (parametre manquant ou invalide) |
| 401 | Token JWT invalide ou expire |
| 404 | Client non enregistre ou conflit non trouve |
| 500 | Erreur serveur interne |

**Exemple de reponse d'erreur:**

```json
{
    "error": "client_uuid is required"
}
```

---

## Bonnes pratiques

### 1. Synchronisation incrementale

Toujours utiliser `last_sync_at` pour ne recuperer que les modifications depuis le dernier sync.

### 2. Ordre des operations

1. **Push** les modifications locales d'abord
2. **Pull** les nouvelles donnees ensuite
3. Cela minimise les conflits

### 3. Gestion du mode offline

```javascript
// Detecter l'etat de connexion
window.addEventListener('online', () => syncManager.fullSync());
window.addEventListener('offline', () => showOfflineIndicator());

// Verifier avant chaque operation
if (navigator.onLine) {
    await syncManager.fullSync();
}
```

### 4. Retry avec backoff

```javascript
async function syncWithRetry(maxRetries = 3) {
    for (let i = 0; i < maxRetries; i++) {
        try {
            return await syncManager.fullSync();
        } catch (error) {
            if (i === maxRetries - 1) throw error;
            await sleep(Math.pow(2, i) * 1000); // Backoff exponentiel
        }
    }
}
```

### 5. Stockage du tms

Toujours conserver le `tms` des objets lors du pull. Ce timestamp est essentiel pour la detection des conflits lors du push.

### 6. IDs temporaires

Pour les creations, utiliser des IDs temporaires (ex: `local-{timestamp}`) et les remplacer par les vrais IDs serveur apres le push via `id_mapping`.

---

## Changelog

- **v1.0.0** (2025-01): Implementation initiale Phase 1 (MVP)
  - Types supportes: thirdparty, contact, product
  - Detection de conflits basee sur tms
  - Resolution manuelle des conflits
