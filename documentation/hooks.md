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
        // Fiches d'intervention
        $objects['intervention'] = [
            'class' => 'dmIntervention',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmIntervention.php',
            'table' => 'fichinter',
            'label' => 'Interventions',
            'module' => 'ficheinter',
            'priority' => 'high',           // high, medium, low
            'default_enabled' => true,      // Activé par défaut dans l'admin
        ];

        // Contrats
        $objects['contract'] = [
            'class' => 'dmContract',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmContract.php',
            'table' => 'contrat',
            'label' => 'Contrats',
            'module' => 'contrat',
            'priority' => 'medium',
            'default_enabled' => true,
        ];

        // Dictionnaire (lecture seule côté client)
        $objects['c_ticket_type'] = [
            'class' => 'dmCtickettype',
            'file' => DOL_DOCUMENT_ROOT.'/custom/smartauth/dolMapping/dmCtickettype.php',
            'table' => 'c_ticket_type',
            'label' => 'Types de tickets',
            'module' => 'ticket',
            'priority' => 'low',
            'default_enabled' => true,
            'readonly' => true,             // Pas de push client (dictionnaire)
        ];

        return 0;
    }
}
```

**Object configuration options:**

| Option | Type | Description |
|--------|------|-------------|
| `class` | string | Nom de la classe dolMapping |
| `file` | string | Chemin vers le fichier de la classe |
| `table` | string | Nom de la table Dolibarr (sans préfixe llx_) |
| `label` | string | Libellé affiché dans l'interface admin |
| `module` | string | Module Dolibarr requis pour les permissions |
| `priority` | string | Priorité de sync: `high`, `medium`, `low` |
| `default_enabled` | bool | Activé par défaut (default: false) |
| `readonly` | bool | Lecture seule, pas de push client (default: false) |

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

## Résumé des hooks disponibles

| Hook | Description | Usage principal |
|------|-------------|-----------------|
| `smartmaker_addValidationSchemas` | Ajouter des schémas de validation | Validation API |
| `smartmaker_addSanitizers` | Ajouter des types de sanitization | Nettoyage données |
| `smartmaker_registerSyncableObjects` | Déclarer des objets synchronisables | Sync offline |
| `smartmaker_beforeSyncPush` | Intercepter avant push sync | Validation/audit |
| `smartmaker_afterConflictResolution` | Réagir après résolution conflit | Workflow/audit |
