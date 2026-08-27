# SmartMaker Hooks

SmartAuth provides hooks that allow external Dolibarr modules to extend validation and sanitization capabilities.

All SmartMaker hooks are prefixed with `smartmaker_` to avoid conflicts with other modules.

## Available Hooks

### smartmaker_addValidationSchemas

Allows modules to register validation schemas for their API endpoints.

**Context:** `smartmaker`

**Parameters:**
- `$parameters` (array): Empty array, reserved for future use
- `&$schemas` (array): Reference to schemas array, modules add their schemas here
- `&$action` (string): Current action
- `$hookmanager` (HookManager): Dolibarr hook manager instance

**Example implementation:**

```php
// In your module: class/actions_mymodule.class.php

class ActionsMyModule
{
    public function smartmaker_addValidationSchemas($parameters, &$schemas, &$action, $hookmanager)
    {
        // Use SmartAuth InputSanitizer types
        use SmartAuth\Api\InputSanitizer;

        $schemas['mymodule'] = [
            // Schema for POST /mymodule/interventions
            'POST:/interventions' => [
                'client_id' => [
                    'type' => InputSanitizer::TYPE_INT,
                    'required' => true,
                    'min' => 1,
                ],
                'date_intervention' => [
                    'type' => InputSanitizer::TYPE_STRING,
                    'required' => true,
                    'maxLen' => 10,
                ],
                'description' => [
                    'type' => InputSanitizer::TYPE_STRING,
                    'required' => false,
                    'maxLen' => 1000,
                ],
                'status' => [
                    'type' => InputSanitizer::TYPE_ALPHANUMERIC,
                    'required' => false,
                    'default' => 'draft',
                ],
            ],

            // Schema for PUT /mymodule/interventions/{id}
            'PUT:/interventions/{id}' => [
                'status' => [
                    'type' => InputSanitizer::TYPE_ALPHANUMERIC,
                    'required' => false,
                ],
                'notes' => [
                    'type' => InputSanitizer::TYPE_STRING,
                    'maxLen' => 2000,
                ],
            ],
        ];

        return 0;
    }
}
```

**Retrieving schemas:**

```php
use SmartAuth\Api\ValidationSchemas;

// Get schema for a specific module and endpoint
$schema = ValidationSchemas::getSchemaForModule('mymodule', 'POST:/interventions');

// Get all schemas including external modules
$allSchemas = ValidationSchemas::getAllSchemas(true);
```

---

### smartmaker_addSanitizers

Allows modules to register custom sanitization types (callbacks).

**Context:** `smartmaker`

**Parameters:**
- `$parameters` (array): Empty array, reserved for future use
- `&$sanitizers` (array): Reference to sanitizers array, modules add their callbacks here
- `&$action` (string): Current action
- `$hookmanager` (HookManager): Dolibarr hook manager instance

**Example implementation:**

```php
// In your module: class/actions_mymodule.class.php

class ActionsMyModule
{
    public function smartmaker_addSanitizers($parameters, &$sanitizers, &$action, $hookmanager)
    {
        // French phone number sanitizer
        $sanitizers['phone_fr'] = function ($value, $rules, $field) {
            if (!is_string($value)) {
                return null;
            }

            // Remove all non-numeric characters except +
            $clean = preg_replace('/[^0-9+]/', '', $value);

            // Validate French phone format
            if (preg_match('/^(?:\+33|0)[1-9][0-9]{8}$/', $clean)) {
                return $clean;
            }

            // Handle required field
            if ($rules['required'] ?? false) {
                throw new \InvalidArgumentException("Invalid French phone format for field: $field");
            }

            return null;
        };

        // SIRET number sanitizer (French company ID)
        $sanitizers['siret'] = function ($value, $rules, $field) {
            if (!is_string($value)) {
                return null;
            }

            // Remove spaces and dashes
            $clean = preg_replace('/[\s\-]/', '', $value);

            // SIRET is 14 digits
            if (preg_match('/^[0-9]{14}$/', $clean)) {
                return $clean;
            }

            if ($rules['required'] ?? false) {
                throw new \InvalidArgumentException("Invalid SIRET format for field: $field");
            }

            return null;
        };

        // Custom date format sanitizer
        $sanitizers['date_fr'] = function ($value, $rules, $field) {
            if (!is_string($value)) {
                return null;
            }

            // Accept DD/MM/YYYY format
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
                $day = (int) $matches[1];
                $month = (int) $matches[2];
                $year = (int) $matches[3];

                if (checkdate($month, $day, $year)) {
                    // Return as ISO format
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }

            if ($rules['required'] ?? false) {
                throw new \InvalidArgumentException("Invalid date format for field: $field (expected DD/MM/YYYY)");
            }

            return null;
        };

        return 0;
    }
}
```

**Using custom types in schemas:**

```php
$schemas['mymodule'] = [
    'POST:/clients' => [
        'phone' => [
            'type' => 'phone_fr',  // Custom type registered via hook
            'required' => true,
        ],
        'siret' => [
            'type' => 'siret',     // Custom type registered via hook
            'required' => false,
        ],
        'birthdate' => [
            'type' => 'date_fr',   // Custom type registered via hook
            'required' => false,
        ],
    ],
];
```

---

## Built-in Sanitization Types

SmartAuth provides these built-in types via `InputSanitizer`:

| Type | Constant | Description |
|------|----------|-------------|
| `string` | `TYPE_STRING` | General string, HTML stripped, max length enforced |
| `email` | `TYPE_EMAIL` | Valid email address |
| `int` | `TYPE_INT` | Integer with optional min/max |
| `float` | `TYPE_FLOAT` | Float with optional min/max |
| `bool` | `TYPE_BOOL` | Boolean value |
| `uuid` | `TYPE_UUID` | UUID or SHA256 hash |
| `alphanumeric` | `TYPE_ALPHANUMERIC` | Letters, numbers, hyphen, underscore only |
| `array` | `TYPE_ARRAY` | Array of items with specified item type |
| `raw` | `TYPE_RAW` | No sanitization (use with caution) |

---

## Schema Field Options

Each field in a schema can have these options:

| Option | Type | Description |
|--------|------|-------------|
| `type` | string | Sanitization type (built-in or custom) |
| `required` | bool | Whether field is mandatory (default: false) |
| `default` | mixed | Default value if field not provided |
| `maxLen` | int | Maximum string length |
| `min` | int/float | Minimum numeric value |
| `max` | int/float | Maximum numeric value |
| `itemType` | string | For arrays: type of array items |
| `maxItems` | int | For arrays: maximum number of items |

---

## Module Setup

To use SmartMaker hooks, your module must:

1. Have an actions class: `class/actions_yourmodule.class.php`
2. Be enabled in Dolibarr
3. Implement the hook methods as shown above

The hooks are automatically called when SmartAuth loads schemas or sanitizers.

---

## Cache Management

Both schemas and sanitizers are cached after first load. To force reload:

```php
use SmartAuth\Api\ValidationSchemas;
use SmartAuth\Api\InputSanitizer;

// Clear schema cache
ValidationSchemas::clearCache();

// Clear sanitizer cache
InputSanitizer::clearCache();
```

This is mainly useful for testing or when modules are dynamically enabled/disabled.

---

## Synchronisation Offline Hooks

Ces hooks permettent aux modules d'étendre les fonctionnalités de synchronisation offline.

### smartmaker_registerSyncableObjects

Permet aux modules de déclarer leurs objets synchronisables pour le mode offline.

**Context:** `smartmaker`

**Parameters:**
- `$parameters` (array): Empty array, reserved for future use
- `&$objects` (array): Reference to syncable objects array, modules add their objects here
- `&$action` (string): Current action
- `$hookmanager` (HookManager): Dolibarr hook manager instance

**Example implementation:**

```php
// In your module: class/actions_smartinterventions.class.php

class ActionsSmartInterventions
{
    public function smartmaker_registerSyncableObjects($parameters, &$objects, &$action, $hookmanager)
    {
        // Objet propre au module, avec son mapper : le cas recommandé.
        // 'class' est la classe DOLIBARR, 'mapper' la classe dm* qui la mappe.
        $objects['equipment'] = [
            'class' => 'SmartinterEquipment',
            'file' => dol_buildpath('/smartinterventions/class/equipment.class.php', 0),
            'table' => 'smartinterventions_equipment',
            'element' => 'smartinter_equipment',
            'label' => 'Equipements',
            'module' => 'smartinterventions',
            'priority' => 'high',
            'default_enabled' => true,
            'mapper' => '\\SmartInterventions\\Mapping\\dmEquipment',
            'rights' => [
                'read'   => ['smartinterventions', 'read'],
                'create' => ['smartinterventions', 'write'],
                'update' => ['smartinterventions', 'write'],
                'delete' => ['smartinterventions', 'delete'],
            ],
        ];

        // Sans mapper : il FAUT alors déclarer allowed_fields, sinon toute
        // écriture sur ce type est refusée (voir "Contrat d'écriture" plus bas).
        $objects['c_ticket_type'] = [
            'class' => 'CTicketType',
            'file' => dol_buildpath('/monmodule/class/ctickettype.class.php', 0),
            'table' => 'c_ticket_type',
            'element' => 'c_ticket_type',
            'label' => 'Types de tickets',
            'module' => 'ticket',
            'priority' => 'low',
            'default_enabled' => true,
            // Dictionnaire : rien n'est modifiable depuis le client.
            'allowed_fields' => [],
        ];

        return 0;
    }
}
```

**Object configuration options:**

| Option | Type | Description |
|--------|------|-------------|
| `class` | string | Nom de la classe **Dolibarr** (pas le mapper) : `Societe`, `Facture`, `SmartinterEquipment`... |
| `file` | string | Chemin absolu du fichier de cette classe, inclus avant instanciation |
| `table` | string | Nom de la table Dolibarr (sans préfixe llx_) |
| `element` | string | Code élément Dolibarr, utilisé pour le cloisonnement `getEntity()` |
| `label` | string | Libellé affiché dans l'interface admin |
| `module` | string | Module Dolibarr requis pour les permissions |
| `priority` | string | Priorité de sync: `high`, `medium`, `low` |
| `default_enabled` | bool | Activé par défaut à l'enregistrement d'un client (default: false) |
| `rights` | array | Droits Dolibarr par action (`read`/`create`/`update`/`delete`), passés tels quels à `User::hasRight()` |
| `mapper` | string | Classe `dm*` pleinement qualifiée. **Recommandé** : c'est elle qui porte le contrat d'écriture, les gardes de tenant et le mapping API. |
| `allowed_fields` | array | Allowlist d'écriture, **uniquement** pour un type sans `mapper`. Voir ci-dessous. |
| `pk` | string | Clé primaire de la table si ce n'est pas `rowid` (ex: `id` pour `llx_actioncomm`) |
| `supports_lines` | bool | `true` si le type a des lignes de document servies par `objects/{type}/{id}/lines`. La classe doit exposer `fetch_lines()` et son mapper un mapping de lignes. |
| `actions` | array | Actions workflow de DOCUMENT autorisées (`['validate', 'close', ...]`), default-deny. Chaque paire (classe, action) doit exister dans `DocumentActionInvoker`. |
| `line_actions` | array | Actions workflow de LIGNE autorisées, servies par `objects/{type}/{id}/lines/{lineid}/actions/{action}`, default-deny. Dispatch dans `DocumentLineActionInvoker`. Seul `contract` en déclare aujourd'hui (`activate`, `close`). |
| `has_entity` | bool | `false` si la table n'a pas de colonne `entity`. Le mapper doit alors déclarer `isolationWhereSql()`, sinon le type ne sert rien (fail-closed). |
| `pull_where` | string | Fragment SQL de filtre métier appliqué au WHERE du pull (ex: `'tosell = 1'`). Voir avertissement ci-dessous. |

**Contrat d'écriture : `mapper` ou `allowed_fields`, jamais les deux**

Ce qu'un client a le droit d'écrire sur un type est décidé par une seule liste :

1. Si le type déclare un `mapper`, c'est le `$writableFields` de ce mapper qui
   fait foi, sur les deux portes (façade REST `objects/{type}` et push sync).
   Une clé `allowed_fields` déclarée à côté ne serait jamais lue : elle finirait
   par diverger du mapper et par tromper le prochain relecteur. C'est la raison
   pour laquelle les 26 types intégrés de SmartAuth n'en portent plus (cf le
   docbloc de `api/ObjectRegistry.php`).
2. Si le type ne déclare pas de `mapper`, `allowed_fields` devient la seule
   allowlist et elle est **obligatoire**. Un type sans mapper ni
   `allowed_fields` voit **tous** ses champs refusés à l'écriture, avec un
   `LOG_ERR` nommant le type : c'est délibérément fail-closed, l'ancien mode
   "denylist seule" laissait écrire n'importe quelle propriété de la classe.

Une liste vide (`'allowed_fields' => []`) est donc la façon correcte de dire
"lecture seule" pour un dictionnaire sans mapper. Il n'existe pas de clé
`readonly` (elle a été documentée ici par erreur et n'a jamais été implémentée) :
un type en lecture seule est un type dont l'allowlist d'écriture est vide.

**Extrafields : mapper obligatoire**

Un type **sans** mapper ne peut pas écrire d'extrafield, même en listant une clé
`options_*` dans son `allowed_fields` : le push la refuse et le journalise. La
garde de tenant qui vérifie vers quel objet pointe un extrafield de type `link`
est pilotée par `dmBase::getExtrafieldWriteTargets()`, que ce type n'a pas -- on
écrirait donc une référence que rien ne cloisonne.

Pour ouvrir des extrafields en écriture, déclarer un mapper `dm*` dérivant de
`dmBase`, publier la clé dans `$listOfPublishedFields`
(`'options_moncham' => 'mon_champ'`) et l'autoriser dans `$extrafieldsRW`. Les
deux portes (façade REST et push sync) lisent alors la même allowlist et
appliquent la même garde.

**Avertissement sécurité sur `pull_where` :**

- Le fragment est concaténé tel quel dans le SQL (même modèle de confiance que
  la clé `table`). Il DOIT être une chaîne codée en dur dans le module, JAMAIS
  construite à partir d'une entrée de requête ou d'une valeur contrôlée par
  l'utilisateur.
- C'est un filtre de volume/métier, PAS un mécanisme de contrôle d'accès : les
  `rowid` qui cessent de matcher le filtre sont exposés à tous les clients
  autorisés sous forme d'exclusions dans la liste `deleted` (pour que les
  clients offline purgent les enregistrements sortis du périmètre, ex: produit
  passé à `tosell = 0`). Le contrôle d'accès reste porté par `rights` et le
  scoping par entité.
- Référencer les colonnes nues (`tosell = 1`, pas `t.tosell = 1`) : les
  requêtes du pull n'utilisent pas d'alias de table.

**Pagination du pull :** le endpoint `POST /sync/pull` accepte `limit` (1 à
1000, défaut 1000) et `offset` (défaut 0) et renvoie `has_more`. Le client doit
garder `last_sync_at` FIXE pendant toute la passe de pagination et ne stocker le
`server_time` reçu qu'après la dernière page (`has_more = false`). Les
tombstones et exclusions ne sont renvoyés que sur la première page
(`offset = 0`).

---

### smartmaker_beforeSyncPush

Appelé avant l'envoi des modifications client vers le serveur. Permet de valider ou modifier les changements.

**Context:** `smartmaker`

**Parameters:**
- `$parameters` (array): Contains `client` (SyncClient object)
- `&$changes` (array): Reference to changes array, can be modified
- `&$action` (string): Current action
- `$hookmanager` (HookManager): Dolibarr hook manager instance

**Example implementation:**

```php
class ActionsMyModule
{
    public function smartmaker_beforeSyncPush($parameters, &$changes, &$action, $hookmanager)
    {
        $client = $parameters['client'];

        foreach ($changes as $key => &$change) {
            // Exemple: Bloquer les modifications sur les factures validées
            if ($change['table'] === 'invoice' && $change['action'] === 'update') {
                $invoice = new Facture($this->db);
                $invoice->fetch($change['id']);

                if ($invoice->statut == Facture::STATUS_VALIDATED) {
                    // Retirer ce changement de la liste
                    unset($changes[$key]);
                    // Ou lever une erreur
                    // return -1;
                }
            }

            // Exemple: Ajouter des métadonnées
            $change['data']['sync_source'] = 'mobile_app';
            $change['data']['sync_user'] = $client->fk_user;
        }

        return 0;
    }
}
```

**Return values:**
- `0` : Continue normally
- `-1` : Abort push with error

---

### smartmaker_afterConflictResolution

Appelé après la résolution d'un conflit de synchronisation.

**Context:** `smartmaker`

**Parameters:**
- `$parameters` (array): Contains `conflict` (SyncConflict object)
- `&$resolution` (array): Resolution data (resolution type, final data)
- `&$action` (string): Current action
- `$hookmanager` (HookManager): Dolibarr hook manager instance

**Example implementation:**

```php
class ActionsMyModule
{
    public function smartmaker_afterConflictResolution($parameters, &$resolution, &$action, $hookmanager)
    {
        $conflict = $parameters['conflict'];

        // Exemple: Logger la résolution pour audit
        dol_syslog(
            "Sync conflict resolved: " . $conflict->table_name .
            " #" . $conflict->object_id .
            " -> " . $resolution['resolution'],
            LOG_INFO
        );

        // Exemple: Notifier un administrateur si résolution manuelle
        if ($resolution['resolution'] === 'merged') {
            $this->notifyAdmin($conflict, $resolution);
        }

        // Exemple: Déclencher un workflow post-résolution
        if ($conflict->table_name === 'intervention') {
            $this->triggerInterventionWorkflow($conflict->object_id);
        }

        return 0;
    }
}
```

**Resolution types:**
- `client` : Version client conservée
- `server` : Version serveur conservée
- `merged` : Fusion manuelle des données

---

## OAuth2 / OIDC Hooks

Ces hooks permettent à un module externe (ex: ssomanager) d'étendre le comportement
du serveur OAuth2/OIDC SmartAuth sans modifier son code. SmartAuth reste générique:
le métier vit dans le module qui implémente les hooks.

Tous ces hooks utilisent le contexte `smartmaker` et sont invoqués via le helper
interne `SmartAuth\Api\OAuth2\HookHelper`. Aucun token clair, secret client ou
mot de passe ne transite par les paramètres.

### smartmaker_oauth_pre_authorize

**Quand:** invoqué dans `AuthorizationController::processAuthorizationRequest()`,
juste après validation de la session utilisateur et avant la vérification de
consentement. Permet de bloquer l'émission du code d'autorisation.

**Paramètres:**
- `$parameters` (array): `['user_id', 'client_id', 'client_pk', 'scopes', 'redirect_uri']`
- `&$object` (`SmartAuthOAuthClient`): client OAuth2 demandeur (modifiable)
- `&$action` (string): `pre_authorize`
- `$hookmanager` (HookManager)

**Retour:**
- `0`: pas de blocage, on continue.
- `1`: blocage. Le module DOIT renseigner
  `$hookmanager->resArray['error']` (code OAuth2, ex: `access_denied`) et
  `$hookmanager->resArray['error_description']` (message FR utilisateur).
  SmartAuth redirige le client OAuth2 avec une erreur OAuth2 standard.
- `< 0`: erreur interne, log + 500.

**Exemple:**

```php
class ActionsSsomanager
{
    public function smartmaker_oauth_pre_authorize($parameters, &$object, &$action, $hookmanager)
    {
        $userId = (int) $parameters['user_id'];
        $clientPk = (int) $parameters['client_pk'];

        // Custom gating logic (e.g. check that user has an active contract)
        $checker = new \Ssomanager\Service\ContractAccessChecker($this->db);
        $access = $checker->userHasActiveAccessForClient($userId, $clientPk);

        if (!$access['allowed']) {
            $hookmanager->resArray['error'] = 'access_denied';
            $hookmanager->resArray['error_description'] = 'Aucun abonnement actif pour ce service.';
            return 1;
        }
        return 0;
    }
}
```

---

### smartmaker_oauth_pre_token

**Quand:** invoqué dans `TokenController::handleToken()` pour tous les grants
qui produisent un access_token: `authorization_code`, `refresh_token`,
`client_credentials`. Déclenché APRÈS validation du grant et AVANT génération
du token. Indispensable pour le grant `refresh_token` car le user peut avoir
perdu le droit entre l'émission du code et le refresh.

**Paramètres:**
- `$parameters` (array): `['user_id', 'client_id', 'client_pk', 'scopes', 'grant_type']`
- `&$object` (`SmartAuthOAuthClient`)
- `&$action` (string): `pre_token`
- `$hookmanager` (HookManager)

**Retour:** identique à `pre_authorize`. En cas de blocage, SmartAuth renvoie
une réponse HTTP 400 `invalid_grant` (par défaut) avec `error_description`
fourni par le module.

**Note:** pour le grant `client_credentials`, `$parameters['user_id']` est
l'identifiant du `fk_service_user` du client (résolution déjà faite par
SmartAuth). Le module peut choisir de ne pas appliquer le gating pour ce
grant_type en regardant `$parameters['grant_type']`.

#### Injection de claims dans l'access token (`extra_claims`)

Quand le hook autorise le flux (retour `0`), il peut enrichir le JWT
access_token via `$hookmanager->resArray['extra_claims']`. SmartAuth
fusionne ces claims dans le payload avant signature. Permet par exemple
de baker des entitlements (PERFS.md §3.3) pour que les services backend
n'aient pas à requéter SmartAuth ni la base à chaque appel.

**Forme:** `extra_claims` doit être un tableau associatif `string => mixed`
respectant les contraintes ci-dessous. Tout claim hors normes est silencieusement
filtré par `HookHelper::sanitizeExtraClaims()` avec un log warning -- la
réponse HTTP reste un succès.

**Politique de claims réservés:**

Les claims suivants sont contrôlés par SmartAuth (`TokenService::createAccessToken`
et `addUserClaims`). Un hook qui tente de les écrire via `extra_claims` est
DROPPED (log warning, le claim original SmartAuth est conservé):

- Identité / durée de vie / audience: `iss`, `sub`, `aud`, `exp`, `iat`, `nbf`,
  `jti`, `auth_time`
- OAuth2 / OIDC standard: `scope`, `client_id`, `grant_type`, `token_type`,
  `at_hash`, `nonce`
- Profil / email / groupes (issus du scope OIDC): `name`, `family_name`,
  `given_name`, `updated_at`, `email`, `email_verified`, `groups`, `roles`

Pour modifier `email` ou `name` en fonction du contexte, utiliser le hook
`smartmaker_oauth_userinfo_claims` (qui couvre `/userinfo` ET l'id_token),
pas `extra_claims` du `pre_token`.

**Convention de namespace pour les autres claims:**

- Claims réservés à capsso (SSO CAP-REL): `services`, `tenant_id`. Aucun
  autre module ne doit les écrire.
- Tous les autres claims doivent être préfixés par le code du module qui
  les écrit (ex: `monmodule_xxx`) pour éviter les collisions inter-modules.

**Types de valeurs autorisés:**

- `string`, `int`, `bool`
- `array` dont tous les éléments sont des `string` uniquement

Tout autre type (objets, tableaux imbriqués, tableaux mixtes) est dropped
avec un log warning. Cette restriction garde le JWT compact et la
désérialisation côté service backend non-ambiguë.

**Taille maximale du payload:**

Le payload JSON encodé (claims SmartAuth + extra_claims) ne doit pas dépasser
`TokenService::MAX_JWT_PAYLOAD_BYTES` (3 KB, marge de 1 KB sous la limite
pratique de 4 KB des headers HTTP). Si dépassé, `createAccessToken` lève une
`RuntimeException` et le contrôleur retourne HTTP 500 `server_error`. C'est
un garde-fou volontaire contre les bombes de claims.

**Exemple (capsso bake l'entitlement services et tenant_id):**

```php
class ActionsCapsso
{
    public function smartmaker_oauth_pre_token($parameters, &$object, &$action, $hookmanager)
    {
        // client_credentials: pas de gating user, pas de claims metier
        if (($parameters['grant_type'] ?? '') === 'client_credentials') {
            return 0;
        }

        $userId = (int) $parameters['user_id'];
        $clientPk = (int) $parameters['client_pk'];

        $checker = new \Capsso\Service\ContractAccessChecker($this->db);
        $access = $checker->userHasActiveAccessForClient($userId, $clientPk);

        if (!$access['allowed']) {
            $hookmanager->resArray['error'] = 'invalid_grant';
            $hookmanager->resArray['error_description'] = 'Aucun abonnement actif pour ce service.';
            return 1;
        }

        // Bake the entitlement so backends do not have to query SmartAuth
        // nor the contract DB on each request (PERFS.md §3.3).
        $hookmanager->resArray['extra_claims'] = [
            'services' => $access['active_service_codes'],    // array of strings, filtered by audience
            'tenant_id' => (int) $access['fk_soc'],
        ];
        return 0;
    }
}
```

---

### smartmaker_oauth_userinfo_claims

**Quand:** invoqué dans `UserinfoController::handleUserinfo()` après la
construction des claims standard, et dans `TokenService::createIdToken()`
juste avant la signature de l'id_token OIDC.

**Paramètres:**
- `$parameters` (array): `['user_id', 'client_id', 'client_pk', 'scopes', 'context']`
  - `context`: `'userinfo'` ou `'id_token'`
- `&$claims` (array): claims actuels (modifiable, ex: `sub`, `email`, `name`)
- `&$action` (string): `userinfo_claims`
- `$hookmanager` (HookManager)

**Retour:**
- `0`: les modifications de `$claims` sont prises en compte.
- `< 0`: erreur, les claims standard sont conservés.

**Exemple (override email par service):**

```php
class ActionsSsomanager
{
    public function smartmaker_oauth_userinfo_claims($parameters, &$claims, &$action, $hookmanager)
    {
        $userId = (int) $parameters['user_id'];
        $clientPk = (int) $parameters['client_pk'];

        $sql = "SELECT email FROM " . MAIN_DB_PREFIX . "ssomanager_user_service_email";
        $sql .= " WHERE fk_user = " . $userId;
        $sql .= " AND fk_oauth_client = " . $clientPk;
        $sql .= " AND verified_at IS NOT NULL";
        $resql = $this->db->query($sql);
        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            $claims['email'] = $obj->email;
            $claims['email_verified'] = true;
        }
        return 0;
    }
}
```

**Garantie OIDC:** ne JAMAIS modifier `sub` depuis un hook. Le sujet doit
rester stable.

---

### smartmaker_email_alternative_persist

**Quand:** invoqué par `EmailAlternativeController::handleConfirm()` après
validation et consommation d'un token `email_change`. SmartAuth ne possède pas
la table de persistance des emails alternatifs (elle vit côté ssomanager dans
`llx_ssomanager_user_service_email`). Ce hook est donc le point de sortie
unique pour matérialiser l'override d'email côté module externe.

**Paramètres:**
- `$parameters` (array): `['user_id', 'client_pk', 'client_id', 'email']`
  - `user_id` (int): user concerné
  - `client_pk` (int|null): rowid OAuth2 du service
  - `client_id` (string|null): identifiant public du client OAuth2
  - `email` (string): email confirmé (déjà validé)
- `&$object` (null): non utilisé
- `&$action` (string): `email_alternative_persist`
- `$hookmanager` (HookManager)

**Retour:**
- `0`: aucun module n'a pris en charge la requête. SmartAuth affichera une
  page indiquant que ssomanager doit être activé.
- `1`: persisté avec succès. Le module peut renseigner
  `$hookmanager->resArray['service']` (string) pour afficher le nom du
  service dans la page de confirmation.
- `< 0`: erreur interne, remontée en HTTP 500.

**Exemple:**

```php
class ActionsSsomanager
{
    public function smartmaker_email_alternative_persist($parameters, &$object, &$action, $hookmanager)
    {
        $userId = (int) $parameters['user_id'];
        $clientPk = (int) ($parameters['client_pk'] ?? 0);
        $email = (string) $parameters['email'];

        if ($userId <= 0 || $clientPk <= 0) {
            return 0;
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "ssomanager_user_service_email";
        $sql .= " (fk_user, fk_oauth_client, email, verified_at, datec, entity)";
        $sql .= " VALUES (" . $userId . ", " . $clientPk . ",";
        $sql .= " '" . $this->db->escape($email) . "',";
        $sql .= " '" . $this->db->idate(dol_now()) . "',";
        $sql .= " '" . $this->db->idate(dol_now()) . "',";
        $sql .= " " . ((int) $conf->entity) . ")";
        $sql .= " ON DUPLICATE KEY UPDATE email = VALUES(email), verified_at = VALUES(verified_at)";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }

        // Optional: surface the service name on the SmartAuth confirmation page
        $hookmanager->resArray['service'] = $this->resolveServiceLabel($clientPk);
        return 1;
    }
}
```

---

### smartmaker_account_sections

**Quand:** invoqué par la route `/account` pour permettre à un module externe
d'injecter des sections HTML dans la page de self-service profil utilisateur.

**Paramètres:**
- `$parameters` (array): `['user_id']`
- `&$sections` (array): tableau de sections, chacune au format
  `['title' => string, 'html' => string, 'priority' => int]`. Plus la priorité
  est basse, plus la section apparaît haut dans la page.
- `&$action` (string): `account_sections`
- `$hookmanager` (HookManager)

**Retour:** `0` (succès), `< 0` (erreur, sections du module ignorées).

**Exemple:**

```php
class ActionsSsomanager
{
    public function smartmaker_account_sections($parameters, &$sections, &$action, $hookmanager)
    {
        $userId = (int) $parameters['user_id'];
        $sections[] = [
            'title' => 'Emails alternatifs par service',
            'html' => $this->renderAlternativeEmailsSection($userId),
            'priority' => 50,
        ];
        return 0;
    }
}
```

---

## TokenService: méthodes publiques pour modules

Les méthodes suivantes de `SmartAuth\Api\OAuth2\TokenService` sont exposées
spécifiquement pour les modules externes (ex: ssomanager triggers). Elles ne
nécessitent aucun hook.

### TokenService::revokeAllForUserAndClient(int $fk_user, int $fk_client_pk): int

Révoque tous les access_token et refresh_token actifs du couple
(user, client OAuth2). Stampe `revoked_at = NOW()` sur les enregistrements.
Ne supprime PAS les `oauth_consents`.

Retourne le nombre de tokens révoqués, ou `-1` en cas d'erreur SQL.

### TokenService::revokeAllForUser(int $fk_user): int

Révocation totale, tous clients confondus. Utilisée par `/account` "log out
everywhere" et par les triggers `USER_DISABLE` / `USER_DELETE` /
`COMPANY_DELETE` côté ssomanager.

Retourne le nombre de tokens révoqués, ou `-1` en cas d'erreur SQL.

### TokenService::addRevokedJti(string $jti, int $expiresAt, string $reason): int

Ajoute un `jti` à la liste de révocation publiée (table
`llx_smartauth_revoked_jti`). Idempotent : un re-add du même `jti` retourne
`0` (no-op).

Utilisée par les triggers métier (par ex. `LINECONTRACT_CLOSE` côté capsso)
pour publier explicitement un `jti` que les services backend devront rejeter
avant son expiration naturelle. Cf PERFS.md §3.4 (révocation hybride
TTL + liste).

Retour :
- `1` : `jti` inséré
- `0` : `jti` déjà présent (idempotence)
- `-1` : `jti` vide, `expiresAt` non-positif, ou erreur SQL

### TokenService::listRevokedJtiActiveForUserAndClient(int $fk_user, int $fk_client_pk): array

Liste les access tokens actifs (non révoqués, non expirés) d'un couple
(user, client). Retourne un tableau de
`array{jti: string, expires_at: int}`. Pensé pour être consommé par un
trigger qui veut alimenter la liste de révocation avant d'appeler
`revokeAllForUserAndClient` :

```php
foreach ($svc->listRevokedJtiActiveForUserAndClient($u, $c) as $row) {
    $svc->addRevokedJti($row['jti'], $row['expires_at'], 'contract_closed');
}
$svc->revokeAllForUserAndClient($u, $c);
```

Retourne un tableau vide en cas d'erreur SQL ou de non-match.

### TokenService::listRevokedJtiSince(int $sinceTs = 0): array

Lecture de la liste de révocation filtrée par `revoked_at > $sinceTs` et
`expires_at > NOW()` (les entrées dont l'expiration est dépassée sont
exclues, le JWT serait de toute façon refusé par le check `exp` standard).
Utilisée par `RevokedJtiController`. Retourne un tableau de
`array{jti: string, revoked_at_ts: int}` ordonné par `revoked_at` ASC.

### TokenService::purgeExpiredRevokedJti(): int

Supprime les entrées `expires_at < NOW()`. Appelée en lazy par
`RevokedJtiController::handleList` (PERFS.md §3.4 prescrit une purge
régulière ; en pratique, exécuter lors d'un poll suffit puisque la table
n'a pas vocation à croître au-delà du nombre de tokens actifs ayant été
révoqués). Peut aussi être appelée depuis un cron si la charge le justifie.

Retour : nombre de lignes supprimées, ou `-1` en cas d'erreur SQL.

---

## Endpoint `/oauth/revoked-jti` (liste de révocation publiée)

**Méthode :** `GET /oauth/revoked-jti[?since=<unix_ts>]`

**Objet :** publier la liste des `jti` que les services backend doivent
rejeter avant l'expiration naturelle des access_token. Polled par les
backends toutes les 10 minutes (PERFS.md §3.4). Le format JWT signé +
validation locale interdit toute autre forme de propagation de révocation.

**Paramètre :**
- `since` (int, optionnel) : timestamp Unix. Si fourni, seules les
  entrées avec `revoked_at > since` sont retournées. Permet aux clients
  de ne récupérer que le delta depuis leur dernier poll. Valeurs non
  numériques ou négatives = traitées comme 0 (liste complète).

**Réponse 200 (JSON) :**

```json
{
  "as_of": 1736003600,
  "jtis": ["8f3c9b2e-...", "a1b2c3d4-..."]
}
```

- `as_of` : max(`revoked_at_ts`) sur les entrées retournées. Le client
  doit renvoyer cette valeur en `?since=` au prochain poll pour ne
  récupérer que le delta. Quand la liste est vide, vaut la valeur du
  `since` reçu (monotonie garantie).
- `jtis` : tableau des `jti` (chaînes opaques) à rejeter.

**Headers de cache (RFC 7232) :**

La réponse expose un `ETag` faible calculé à partir de `(as_of, jtis triés)`.
Quand le client envoie `If-None-Match: W/"..."` correspondant, la réponse
est `304 Not Modified` avec un corps vide. C'est ce qui rend le poll
pratiquement gratuit en régime stable (PERFS.md §2 : "dominé par 304
quasi-gratuits").

```
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"<sha256 prefix>"
```

**Authentification :** aucune dans la version actuelle. Les `jti` ne sont
pas des secrets : un attaquant qui lirait la liste n'en tire aucun
pouvoir (il ne peut pas réutiliser un `jti` révoqué pour autre chose
qu'un échec d'authentification). Une protection par IP allowlist côté
`doliproxy` peut être ajoutée si l'analyse de risque l'exige.

**Méthode :** GET uniquement. Toute autre méthode -> 405 `invalid_request`.

---

## Résumé des hooks disponibles

| Hook | Description | Usage principal |
|------|-------------|-----------------|
| `smartmaker_addValidationSchemas` | Ajouter des schémas de validation | Validation API |
| `smartmaker_addSanitizers` | Ajouter des types de sanitization | Nettoyage données |
| `smartmaker_registerSyncableObjects` | Déclarer des objets synchronisables | Sync offline |
| `smartmaker_beforeSyncPush` | Intercepter avant push sync | Validation/audit |
| `smartmaker_afterConflictResolution` | Réagir après résolution conflit | Workflow/audit |
| `smartmaker_oauth_pre_authorize` | Bloquer l'émission d'un code d'autorisation | Gating SSO |
| `smartmaker_oauth_pre_token` | Bloquer l'émission d'un token | Gating SSO (refresh) |
| `smartmaker_oauth_userinfo_claims` | Modifier les claims `/userinfo` et id_token | Override email, claims custom |
| `smartmaker_account_sections` | Ajouter des sections sur la page `/account` | UI extension |
| `smartmaker_email_alternative_persist` | Persister une email alternative validee (table ssomanager) | Override email par service |
