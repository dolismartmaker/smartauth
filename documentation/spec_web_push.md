# Spécification : Web Push Notifications pour SmartAuth

> **Version** : 1.0.0-draft
> **Date** : 2026-02-17
> **Statut** : Spécification technique pour implémentation

---

## Table des matières

1. [Vue d'ensemble](#1-vue-densemble)
2. [Architecture](#2-architecture)
3. [Base de données](#3-base-de-données)
4. [API Backend (SmartAuth)](#4-api-backend-smartauth)
5. [Classe PHP PushController](#5-classe-php-pushcontroller)
6. [Génération automatique des clés VAPID](#6-génération-automatique-des-clés-vapid)
7. [Intégration Frontend (smartcommon)](#7-intégration-frontend-smartcommon)
8. [Service Worker (smartboot)](#8-service-worker-smartboot)
9. [Déclencheurs côté Dolibarr](#9-déclencheurs-côté-dolibarr)
10. [Sécurité](#10-sécurité)
11. [Tests](#11-tests)

---

## 1. Vue d'ensemble

### 1.1 Objectif

Permettre aux applications SmartMaker d'envoyer des notifications push aux utilisateurs, même lorsque l'application n'est pas ouverte, sans dépendre de servic
es propriétaires comme Firebase Cloud Messaging.

### 1.2 Principe des Web Push Notifications

Les Web Push Notifications reposent sur trois composants :

1. **Service Worker** : Script JavaScript qui s'exécute en arrière-plan dans le navigateur
2. **Push API** : API du navigateur pour s'abonner aux notifications
3. **Push Service** : Service maintenu par le navigateur (Mozilla, Google, Apple) qui route les messages

### 1.3 Le protocole VAPID

**VAPID** (Voluntary Application Server Identification) est un protocole standard (RFC 8292) qui permet :

- D'identifier le serveur d'application sans compte externe
- De signer cryptographiquement les messages push
- De chiffrer le contenu des notifications (end-to-end)

**Clés VAPID** :
- **Clé publique** : Partagée avec le frontend, utilisée par le navigateur pour valider l'origine
- **Clé privée** : Conservée sur le serveur, utilisée pour signer les requêtes

### 1.4 Indépendance des GAFAM

| Aspect | Contrôlable | Détail |
|--------|-------------|--------|
| Protocole | Oui | VAPID est un standard ouvert W3C/IETF |
| Clés cryptographiques | Oui | Générées localement, aucune inscription externe |
| Librairie serveur | Oui | `minishlink/web-push` (PHP, open source) |
| Chiffrement du contenu | Oui | End-to-end, le Push Service ne peut pas lire |
| Push Service (transport) | **Non** | Imposé par le navigateur (voir tableau ci-dessous) |

**Push Services par navigateur** :

| Navigateur | Push Service | Opérateur |
|------------|--------------|-----------|
| Chrome, Edge, Opera | FCM (Firebase Cloud Messaging) | Google |
| Firefox | autopush.mozilla.org | Mozilla (open source) |
| Safari | APNs (Apple Push Notification service) | Apple |

> **Note** : Le Push Service ne voit que les métadonnées (endpoint, TTL). Le contenu du message est chiffré et illisible par le transporteur.

### 1.5 Flux simplifié

```
┌─────────────────┐
│   Frontend      │  1. Demande permission
│   (PWA)         │  2. S'abonne via Push API
└────────┬────────┘  3. Reçoit subscription (endpoint)
         │
         │ 4. Envoie subscription au backend
         ▼
┌─────────────────┐
│   SmartAuth     │  5. Stocke subscription
│   (Backend)     │  6. Plus tard : envoie notification
└────────┬────────┘
         │
         │ 7. POST signé VAPID + payload chiffré
         ▼
┌─────────────────┐
│   Push Service  │  8. Route vers le bon appareil
│   (Mozilla/     │
│    Google/Apple)│
└────────┬────────┘
         │
         │ 9. Délivre au Service Worker
         ▼
┌─────────────────┐
│  Service Worker │  10. Affiche la notification
│  (navigateur)   │
└─────────────────┘
```

---

## 2. Architecture

### 2.1 Répartition des responsabilités

| Brique | Responsabilité |
|--------|----------------|
| **SmartAuth** | Stockage subscriptions, génération clés VAPID, envoi des notifications, API |
| **smartcommon** | Hook `usePushNotifications()`, gestion permissions, inscription/désinscription |
| **smartboot** | Template Service Worker (événements `push` et `notificationclick`) |
| **Module métier** | Déclencheurs (hooks Dolibarr) pour envoyer des notifications sur événements |

### 2.2 Diagramme d'architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         APPLICATION PWA                              │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │  React App (smartcommon)                                     │    │
│  │  ┌─────────────────────┐  ┌─────────────────────────────┐   │    │
│  │  │ usePushNotifications│  │ Composants UI               │   │    │
│  │  │ - subscribe()       │  │ - Bouton activer/désactiver │   │    │
│  │  │ - unsubscribe()     │  │ - Indicateur statut         │   │    │
│  │  │ - permission status │  │                             │   │    │
│  │  └──────────┬──────────┘  └─────────────────────────────┘   │    │
│  └─────────────┼───────────────────────────────────────────────┘    │
│                │                                                     │
│  ┌─────────────▼───────────────────────────────────────────────┐    │
│  │  Service Worker (smartboot template)                         │    │
│  │  - Événement 'push' : affiche notification                  │    │
│  │  - Événement 'notificationclick' : ouvre l'app/URL          │    │
│  └─────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              │ HTTPS + JWT
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         SMARTAUTH API                                │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │  PushController                                              │    │
│  │  - GET  /push/vapid-public-key                              │    │
│  │  - POST /push/subscribe                                      │    │
│  │  - DELETE /push/unsubscribe                                  │    │
│  │  - POST /push/send (interne)                                │    │
│  └──────────┬──────────────────────────────────────────────────┘    │
│             │                                                        │
│  ┌──────────▼──────────┐  ┌────────────────────────────────────┐    │
│  │  PushService        │  │  llx_smartauth_push_subscriptions  │    │
│  │  (minishlink/       │  │  - endpoint, keys, user, device    │    │
│  │   web-push)         │  └────────────────────────────────────┘    │
│  └──────────┬──────────┘                                            │
│             │                                                        │
│  ┌──────────▼──────────┐                                            │
│  │  VAPID Keys         │                                            │
│  │  (llx_const)        │                                            │
│  │  - SMARTAUTH_VAPID_ │                                            │
│  │    PUBLIC_KEY       │                                            │
│  │  - SMARTAUTH_VAPID_ │                                            │
│  │    PRIVATE_KEY      │                                            │
│  └─────────────────────┘                                            │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              │ HTTPS POST (signé VAPID)
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     PUSH SERVICE (externe)                           │
│  Mozilla autopush / Google FCM / Apple APNs                         │
│  (Selon le navigateur de l'utilisateur)                             │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.3 Dépendances

**Backend (SmartAuth)** :
```json
{
    "require": {
        "minishlink/web-push": "^8.0"
    }
}
```

**Frontend (smartcommon)** :
- Aucune dépendance externe (utilise Push API native du navigateur)

---

## 3. Base de données

### 3.1 Table `llx_smartauth_push_subscriptions`

```sql
-- smartauth/sql/llx_smartauth_push_subscriptions.sql

CREATE TABLE llx_smartauth_push_subscriptions (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,

    -- Identifiants
    fk_user         INTEGER NOT NULL,           -- Utilisateur Dolibarr (llx_user.rowid)
    fk_device       INTEGER,                    -- Device SmartAuth (llx_smartauth_devices.rowid)
    entity          INTEGER DEFAULT 1 NOT NULL, -- Multi-entité Dolibarr

    -- Subscription Web Push (format standard W3C)
    endpoint        TEXT NOT NULL,              -- URL du Push Service (unique par subscription)
    key_p256dh      VARCHAR(255) NOT NULL,      -- Clé publique client (base64url)
    key_auth        VARCHAR(255) NOT NULL,      -- Secret d'authentification (base64url)

    -- Métadonnées
    user_agent      VARCHAR(255),               -- User-Agent du navigateur
    label           VARCHAR(128),               -- Nom donné par l'utilisateur (optionnel)

    -- Dates
    date_creation   DATETIME NOT NULL,
    date_last_used  DATETIME,                   -- Dernière notification envoyée
    date_last_error DATETIME,                   -- Dernière erreur d'envoi
    last_error      VARCHAR(255),               -- Message de la dernière erreur

    -- Compteurs
    success_count   INTEGER DEFAULT 0,          -- Notifications envoyées avec succès
    error_count     INTEGER DEFAULT 0,          -- Erreurs consécutives

    -- Statut
    status          TINYINT DEFAULT 1 NOT NULL, -- 0=désactivé, 1=actif, 9=expiré

    -- Timestamps
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index
    INDEX idx_fk_user (fk_user),
    INDEX idx_fk_device (fk_device),
    INDEX idx_entity (entity),
    INDEX idx_status (status),

    -- Contrainte d'unicité : un endpoint = une subscription
    UNIQUE KEY uk_endpoint (endpoint(500))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Table `llx_smartauth_push_logs` (optionnel, pour audit)

```sql
-- smartauth/sql/llx_smartauth_push_logs.sql

CREATE TABLE llx_smartauth_push_logs (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,

    fk_subscription     INTEGER,                -- Peut être NULL si subscription supprimée
    fk_user             INTEGER NOT NULL,
    entity              INTEGER DEFAULT 1 NOT NULL,

    -- Détails de l'envoi
    notification_type   VARCHAR(64),            -- Type : 'ticket_new', 'order_validated', etc.
    notification_title  VARCHAR(255),
    notification_body   TEXT,
    notification_data   TEXT,                   -- JSON des données additionnelles

    -- Résultat
    http_status         SMALLINT,               -- Code HTTP retourné par le Push Service
    success             TINYINT(1) DEFAULT 0,
    error_message       VARCHAR(255),

    -- Timestamps
    date_creation       DATETIME NOT NULL,

    INDEX idx_fk_user (fk_user),
    INDEX idx_date (date_creation),
    INDEX idx_type (notification_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Fichiers SQL à créer

```
smartauth/sql/
├── llx_smartauth_push_subscriptions.sql       # Table principale
├── llx_smartauth_push_subscriptions.key.sql   # Clé primaire
├── llx_smartauth_push_logs.sql                # Table logs (optionnel)
└── llx_smartauth_push_logs.key.sql            # Clé primaire logs
```

### 3.4 Migration (si module déjà installé)

```sql
-- smartauth/sql/update_2.1.0.sql (ou version appropriée)

-- Table subscriptions
CREATE TABLE IF NOT EXISTS llx_smartauth_push_subscriptions (
    -- [même structure que ci-dessus]
);

-- Table logs (optionnel)
CREATE TABLE IF NOT EXISTS llx_smartauth_push_logs (
    -- [même structure que ci-dessus]
);
```

---

## 4. API Backend (SmartAuth)

### 4.1 Endpoints

| Méthode | Route | Auth | Description |
|---------|-------|------|-------------|
| GET | `/push/vapid-public-key` | Non | Récupère la clé publique VAPID |
| POST | `/push/subscribe` | Oui | Enregistre une subscription |
| DELETE | `/push/unsubscribe` | Oui | Supprime une subscription |
| POST | `/push/send` | Oui* | Envoie une notification (usage interne) |
| GET | `/push/subscriptions` | Oui | Liste les subscriptions de l'utilisateur |

> \* L'endpoint `/push/send` nécessite des permissions spéciales (admin ou droit `smartauth->push_send`).

### 4.2 Enregistrement des routes

```php
// Dans pwa/api.php du module utilisant SmartAuth

use SmartAuth\Api\Route;
use SmartAuth\Api\PushController;

// Routes push notifications
Route::get('push/vapid-public-key', PushController::class, 'getVapidPublicKey', false);
Route::post('push/subscribe', PushController::class, 'subscribe', true);
Route::delete('push/unsubscribe', PushController::class, 'unsubscribe', true);
Route::get('push/subscriptions', PushController::class, 'listSubscriptions', true);
Route::post('push/send', PushController::class, 'send', true);
```

### 4.3 Spécification des endpoints

#### GET /push/vapid-public-key

Récupère la clé publique VAPID pour l'inscription côté client.

**Authentification** : Non requise

**Réponse succès (200)** :
```json
{
    "publicKey": "BNbxGYNMhEIi5k..."
}
```

**Réponse erreur (500)** :
```json
{
    "error": "VAPID keys not configured"
}
```

---

#### POST /push/subscribe

Enregistre une nouvelle subscription push pour l'utilisateur authentifié.

**Authentification** : Requise (Bearer token)

**Headers** :
```
Authorization: Bearer <access_token>
X-DeviceId: <device_uuid>
```

**Body** :
```json
{
    "subscription": {
        "endpoint": "https://fcm.googleapis.com/fcm/send/abc123...",
        "keys": {
            "p256dh": "BNcRd...",
            "auth": "tBHI..."
        }
    },
    "label": "iPhone de Jean"
}
```

**Validation** :
- `subscription.endpoint` : requis, URL valide
- `subscription.keys.p256dh` : requis, base64url
- `subscription.keys.auth` : requis, base64url
- `label` : optionnel, max 128 caractères

**Réponse succès (201)** :
```json
{
    "id": 42,
    "message": "Subscription registered successfully"
}
```

**Réponse erreur (400)** :
```json
{
    "error": "Invalid subscription format"
}
```

**Réponse erreur (409)** :
```json
{
    "error": "Subscription already exists",
    "id": 42
}
```

---

#### DELETE /push/unsubscribe

Supprime une subscription push.

**Authentification** : Requise

**Body** :
```json
{
    "endpoint": "https://fcm.googleapis.com/fcm/send/abc123..."
}
```

Ou par ID :
```json
{
    "id": 42
}
```

**Réponse succès (200)** :
```json
{
    "message": "Subscription removed successfully"
}
```

**Réponse erreur (404)** :
```json
{
    "error": "Subscription not found"
}
```

---

#### GET /push/subscriptions

Liste les subscriptions de l'utilisateur authentifié.

**Authentification** : Requise

**Réponse succès (200)** :
```json
{
    "subscriptions": [
        {
            "id": 42,
            "label": "iPhone de Jean",
            "user_agent": "Mozilla/5.0...",
            "created_at": "2026-02-17T10:30:00Z",
            "last_used_at": "2026-02-17T14:00:00Z",
            "success_count": 15,
            "status": 1
        }
    ]
}
```

---

#### POST /push/send

Envoie une notification push. Usage interne ou admin.

**Authentification** : Requise + permission `smartauth->push_send`

**Body** :
```json
{
    "user_id": 5,
    "title": "Nouveau ticket",
    "body": "Un ticket #1234 vous a été assigné",
    "icon": "/api.php/icon/192",
    "badge": "/api.php/icon/64",
    "tag": "ticket-1234",
    "data": {
        "type": "ticket_assigned",
        "ticket_id": 1234,
        "url": "/tickets/1234"
    },
    "options": {
        "ttl": 86400,
        "urgency": "normal"
    }
}
```

**Paramètres** :

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `user_id` | int | Oui* | ID utilisateur cible |
| `device_id` | int | Non | ID device spécifique (sinon tous les devices de l'user) |
| `title` | string | Oui | Titre de la notification |
| `body` | string | Oui | Corps du message |
| `icon` | string | Non | URL de l'icône |
| `badge` | string | Non | URL du badge |
| `tag` | string | Non | Tag pour regrouper/remplacer des notifications |
| `data` | object | Non | Données JSON passées au Service Worker |
| `options.ttl` | int | Non | Time-to-live en secondes (défaut: 86400) |
| `options.urgency` | string | Non | `very-low`, `low`, `normal`, `high` |

> \* Soit `user_id`, soit `subscription_id`, soit `broadcast: true` (admin only)

**Réponse succès (200)** :
```json
{
    "sent": 3,
    "failed": 0,
    "results": [
        {"subscription_id": 42, "success": true},
        {"subscription_id": 43, "success": true},
        {"subscription_id": 44, "success": true}
    ]
}
```

**Réponse partielle (207)** :
```json
{
    "sent": 2,
    "failed": 1,
    "results": [
        {"subscription_id": 42, "success": true},
        {"subscription_id": 43, "success": true},
        {"subscription_id": 44, "success": false, "error": "Subscription expired", "removed": true}
    ]
}
```

---

## 5. Classe PHP PushController

### 5.1 Structure du fichier

```php
<?php
// smartauth/api/PushController.php

namespace SmartAuth\Api;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Controller for Web Push Notifications
 *
 * Handles subscription management and notification sending using VAPID protocol.
 */
class PushController
{
    /**
     * Constants for configuration
     */
    const VAPID_PUBLIC_KEY_CONFIG = 'SMARTAUTH_VAPID_PUBLIC_KEY';
    const VAPID_PRIVATE_KEY_CONFIG = 'SMARTAUTH_VAPID_PRIVATE_KEY';
    const VAPID_SUBJECT_CONFIG = 'SMARTAUTH_VAPID_SUBJECT';

    const DEFAULT_TTL = 86400;          // 24 hours
    const MAX_ERROR_COUNT = 3;          // Remove subscription after 3 consecutive errors

    /**
     * @api {get} /push/vapid-public-key Get VAPID public key
     * @apiName GetVapidPublicKey
     * @apiGroup Push
     * @apiVersion 1.0.0
     *
     * @apiDescription Returns the VAPID public key for client-side subscription.
     * This endpoint does not require authentication.
     *
     * @apiSuccess {String} publicKey Base64url-encoded VAPID public key
     *
     * @apiSuccessExample {json} Success-Response:
     * HTTP/1.1 200 OK
     * {
     *     "publicKey": "BNbxGYNMhEIi5k..."
     * }
     *
     * @apiError (500) KeysNotConfigured VAPID keys are not configured
     */
    public function getVapidPublicKey($arr = null)
    {
        global $db;

        $publicKey = VapidKeyHelper::getPublicKey($db);

        if (empty($publicKey)) {
            return [['error' => 'VAPID keys not configured'], 500];
        }

        return [['publicKey' => $publicKey], 200];
    }

    /**
     * @api {post} /push/subscribe Register push subscription
     * @apiName Subscribe
     * @apiGroup Push
     * @apiVersion 1.0.0
     *
     * @apiDescription Registers a new push subscription for the authenticated user.
     *
     * @apiHeader {String} Authorization Bearer access token
     * @apiHeader {String} X-DeviceId Device UUID
     *
     * @apiParam {Object} subscription Web Push subscription object
     * @apiParam {String} subscription.endpoint Push service endpoint URL
     * @apiParam {Object} subscription.keys Subscription keys
     * @apiParam {String} subscription.keys.p256dh Client public key (base64url)
     * @apiParam {String} subscription.keys.auth Auth secret (base64url)
     * @apiParam {String} [label] User-friendly label for this subscription
     *
     * @apiSuccess {Number} id Subscription ID
     * @apiSuccess {String} message Success message
     *
     * @apiError (400) InvalidSubscription Invalid subscription format
     * @apiError (401) Unauthorized Invalid or missing token
     * @apiError (409) AlreadyExists Subscription already registered
     */
    public function subscribe($arr = null)
    {
        global $db, $user;

        // Validate input
        if (empty($arr['subscription']['endpoint']) ||
            empty($arr['subscription']['keys']['p256dh']) ||
            empty($arr['subscription']['keys']['auth'])) {
            return [['error' => 'Invalid subscription format'], 400];
        }

        $endpoint = $arr['subscription']['endpoint'];
        $keyP256dh = $arr['subscription']['keys']['p256dh'];
        $keyAuth = $arr['subscription']['keys']['auth'];
        $label = isset($arr['label']) ? substr($arr['label'], 0, 128) : null;
        $deviceId = isset($arr['device_id']) ? (int)$arr['device_id'] : null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        // Check if subscription already exists
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE endpoint = '".$db->escape($endpoint)."'";
        $sql .= " AND entity = ".(int)$conf->entity;

        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            return [['error' => 'Subscription already exists', 'id' => (int)$obj->rowid], 409];
        }

        // Insert new subscription
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " (fk_user, fk_device, entity, endpoint, key_p256dh, key_auth, user_agent, label, date_creation, status)";
        $sql .= " VALUES (";
        $sql .= (int)$user->id.", ";
        $sql .= ($deviceId ? (int)$deviceId : "NULL").", ";
        $sql .= (int)$conf->entity.", ";
        $sql .= "'".$db->escape($endpoint)."', ";
        $sql .= "'".$db->escape($keyP256dh)."', ";
        $sql .= "'".$db->escape($keyAuth)."', ";
        $sql .= ($userAgent ? "'".$db->escape($userAgent)."'" : "NULL").", ";
        $sql .= ($label ? "'".$db->escape($label)."'" : "NULL").", ";
        $sql .= "NOW(), ";
        $sql .= "1";
        $sql .= ")";

        $resql = $db->query($sql);
        if (!$resql) {
            return [['error' => 'Database error: '.$db->lasterror()], 500];
        }

        $id = $db->last_insert_id(MAIN_DB_PREFIX."smartauth_push_subscriptions");

        return [['id' => $id, 'message' => 'Subscription registered successfully'], 201];
    }

    /**
     * @api {delete} /push/unsubscribe Remove push subscription
     * @apiName Unsubscribe
     * @apiGroup Push
     * @apiVersion 1.0.0
     *
     * @apiHeader {String} Authorization Bearer access token
     *
     * @apiParam {String} [endpoint] Push service endpoint URL
     * @apiParam {Number} [id] Subscription ID
     *
     * @apiSuccess {String} message Success message
     *
     * @apiError (400) MissingParameter Either endpoint or id is required
     * @apiError (404) NotFound Subscription not found
     */
    public function unsubscribe($arr = null)
    {
        global $db, $user, $conf;

        $endpoint = isset($arr['endpoint']) ? $arr['endpoint'] : null;
        $id = isset($arr['id']) ? (int)$arr['id'] : null;

        if (empty($endpoint) && empty($id)) {
            return [['error' => 'Either endpoint or id is required'], 400];
        }

        // Build query - user can only delete their own subscriptions
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE fk_user = ".(int)$user->id;
        $sql .= " AND entity = ".(int)$conf->entity;

        if ($id) {
            $sql .= " AND rowid = ".(int)$id;
        } else {
            $sql .= " AND endpoint = '".$db->escape($endpoint)."'";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            return [['error' => 'Database error'], 500];
        }

        if ($db->affected_rows($resql) === 0) {
            return [['error' => 'Subscription not found'], 404];
        }

        return [['message' => 'Subscription removed successfully'], 200];
    }

    /**
     * @api {get} /push/subscriptions List user subscriptions
     * @apiName ListSubscriptions
     * @apiGroup Push
     * @apiVersion 1.0.0
     *
     * @apiHeader {String} Authorization Bearer access token
     *
     * @apiSuccess {Object[]} subscriptions List of subscriptions
     */
    public function listSubscriptions($arr = null)
    {
        global $db, $user, $conf;

        $sql = "SELECT rowid, label, user_agent, date_creation, date_last_used, success_count, error_count, status";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE fk_user = ".(int)$user->id;
        $sql .= " AND entity = ".(int)$conf->entity;
        $sql .= " ORDER BY date_creation DESC";

        $resql = $db->query($sql);
        if (!$resql) {
            return [['error' => 'Database error'], 500];
        }

        $subscriptions = [];
        while ($obj = $db->fetch_object($resql)) {
            $subscriptions[] = [
                'id' => (int)$obj->rowid,
                'label' => $obj->label,
                'user_agent' => $obj->user_agent,
                'created_at' => $obj->date_creation,
                'last_used_at' => $obj->date_last_used,
                'success_count' => (int)$obj->success_count,
                'status' => (int)$obj->status
            ];
        }

        return [['subscriptions' => $subscriptions], 200];
    }

    /**
     * @api {post} /push/send Send push notification
     * @apiName Send
     * @apiGroup Push
     * @apiVersion 1.0.0
     * @apiPermission push_send
     *
     * @apiDescription Sends a push notification to specified user(s).
     * Requires smartauth->push_send permission.
     *
     * @apiHeader {String} Authorization Bearer access token
     *
     * @apiParam {Number} [user_id] Target user ID
     * @apiParam {Number} [subscription_id] Specific subscription ID
     * @apiParam {String} title Notification title
     * @apiParam {String} body Notification body
     * @apiParam {String} [icon] Icon URL
     * @apiParam {String} [badge] Badge URL
     * @apiParam {String} [tag] Notification tag (for grouping/replacing)
     * @apiParam {Object} [data] Additional data for Service Worker
     * @apiParam {Object} [options] Push options (ttl, urgency)
     *
     * @apiSuccess {Number} sent Number of successful sends
     * @apiSuccess {Number} failed Number of failed sends
     * @apiSuccess {Object[]} results Per-subscription results
     */
    public function send($arr = null)
    {
        global $db, $user, $conf;

        // Check permission
        if (!$user->hasRight('smartauth', 'push_send') && !$user->admin) {
            return [['error' => 'Permission denied'], 403];
        }

        // Validate required fields
        if (empty($arr['title']) || empty($arr['body'])) {
            return [['error' => 'title and body are required'], 400];
        }

        // Build notification payload
        $payload = json_encode([
            'title' => $arr['title'],
            'body' => $arr['body'],
            'icon' => $arr['icon'] ?? null,
            'badge' => $arr['badge'] ?? null,
            'tag' => $arr['tag'] ?? null,
            'data' => $arr['data'] ?? []
        ]);

        // Get target subscriptions
        $subscriptions = $this->getTargetSubscriptions($arr, $db, $conf);

        if (empty($subscriptions)) {
            return [['error' => 'No active subscriptions found'], 404];
        }

        // Get VAPID keys
        $vapidKeys = VapidKeyHelper::getKeys($db);
        if (empty($vapidKeys['publicKey']) || empty($vapidKeys['privateKey'])) {
            return [['error' => 'VAPID keys not configured'], 500];
        }

        // Get VAPID subject (mailto: or https:)
        $vapidSubject = getDolGlobalString(self::VAPID_SUBJECT_CONFIG, 'mailto:admin@'.$_SERVER['HTTP_HOST']);

        // Initialize WebPush
        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidKeys['publicKey'],
                'privateKey' => $vapidKeys['privateKey']
            ]
        ];

        $webPush = new WebPush($auth);

        // Options
        $ttl = isset($arr['options']['ttl']) ? (int)$arr['options']['ttl'] : self::DEFAULT_TTL;
        $urgency = isset($arr['options']['urgency']) ? $arr['options']['urgency'] : 'normal';

        // Queue notifications
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['key_p256dh'],
                    'auth' => $sub['key_auth']
                ]
            ]);

            $webPush->queueNotification(
                $subscription,
                $payload,
                ['TTL' => $ttl, 'urgency' => $urgency]
            );
        }

        // Send all and collect results
        $results = [];
        $sent = 0;
        $failed = 0;

        foreach ($webPush->flush() as $index => $report) {
            $sub = $subscriptions[$index];
            $result = [
                'subscription_id' => $sub['rowid'],
                'success' => $report->isSuccess()
            ];

            if ($report->isSuccess()) {
                $sent++;
                $this->updateSubscriptionSuccess($db, $sub['rowid']);
            } else {
                $failed++;
                $error = $report->getReason();
                $result['error'] = $error;

                // Handle expired/invalid subscriptions
                if ($report->isSubscriptionExpired()) {
                    $this->removeSubscription($db, $sub['rowid']);
                    $result['removed'] = true;
                } else {
                    $this->updateSubscriptionError($db, $sub['rowid'], $error);
                }
            }

            $results[] = $result;
        }

        $httpCode = ($failed > 0 && $sent > 0) ? 207 : 200;

        return [['sent' => $sent, 'failed' => $failed, 'results' => $results], $httpCode];
    }

    /**
     * Get target subscriptions based on parameters
     */
    private function getTargetSubscriptions($arr, $db, $conf)
    {
        $sql = "SELECT rowid, endpoint, key_p256dh, key_auth";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE status = 1";
        $sql .= " AND entity = ".(int)$conf->entity;

        if (!empty($arr['subscription_id'])) {
            $sql .= " AND rowid = ".(int)$arr['subscription_id'];
        } elseif (!empty($arr['user_id'])) {
            $sql .= " AND fk_user = ".(int)$arr['user_id'];
        } elseif (!empty($arr['device_id'])) {
            $sql .= " AND fk_device = ".(int)$arr['device_id'];
        }

        $resql = $db->query($sql);
        $subscriptions = [];

        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $subscriptions[] = [
                    'rowid' => $obj->rowid,
                    'endpoint' => $obj->endpoint,
                    'key_p256dh' => $obj->key_p256dh,
                    'key_auth' => $obj->key_auth
                ];
            }
        }

        return $subscriptions;
    }

    /**
     * Update subscription after successful send
     */
    private function updateSubscriptionSuccess($db, $subscriptionId)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_used = NOW(), success_count = success_count + 1, error_count = 0";
        $sql .= " WHERE rowid = ".(int)$subscriptionId;
        $db->query($sql);
    }

    /**
     * Update subscription after error
     */
    private function updateSubscriptionError($db, $subscriptionId, $error)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_error = NOW(), error_count = error_count + 1";
        $sql .= ", last_error = '".$db->escape(substr($error, 0, 255))."'";
        $sql .= " WHERE rowid = ".(int)$subscriptionId;
        $db->query($sql);

        // Check if we should disable the subscription
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET status = 9";
        $sql .= " WHERE rowid = ".(int)$subscriptionId;
        $sql .= " AND error_count >= ".self::MAX_ERROR_COUNT;
        $db->query($sql);
    }

    /**
     * Remove expired subscription
     */
    private function removeSubscription($db, $subscriptionId)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE rowid = ".(int)$subscriptionId;
        $db->query($sql);
    }
}
```

### 5.2 Schémas de validation

```php
// smartauth/api/ValidationSchemas.php (ajouter au tableau existant)

'push' => [
    'POST:/push/subscribe' => [
        'subscription' => ['type' => InputSanitizer::TYPE_RAW, 'required' => true],
        'label' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 128],
    ],
    'DELETE:/push/unsubscribe' => [
        'endpoint' => ['type' => InputSanitizer::TYPE_STRING],
        'id' => ['type' => InputSanitizer::TYPE_INT],
    ],
    'POST:/push/send' => [
        'user_id' => ['type' => InputSanitizer::TYPE_INT],
        'subscription_id' => ['type' => InputSanitizer::TYPE_INT],
        'title' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true, 'maxLen' => 255],
        'body' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true, 'maxLen' => 1000],
        'icon' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 255],
        'badge' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 255],
        'tag' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 64],
        'data' => ['type' => InputSanitizer::TYPE_RAW],
        'options' => ['type' => InputSanitizer::TYPE_RAW],
    ],
],
```

---

## 6. Génération automatique des clés VAPID

### 6.1 Classe VapidKeyHelper

```php
<?php
// smartauth/api/VapidKeyHelper.php

namespace SmartAuth\Api;

use Minishlink\WebPush\VAPID;

/**
 * Helper class for VAPID key management
 *
 * Handles automatic generation and storage of VAPID keys.
 * Pattern similar to JwtKeyHelper.
 */
class VapidKeyHelper
{
    const PUBLIC_KEY_CONFIG = 'SMARTAUTH_VAPID_PUBLIC_KEY';
    const PRIVATE_KEY_CONFIG = 'SMARTAUTH_VAPID_PRIVATE_KEY';

    /**
     * Get VAPID public key, generating if needed
     *
     * @param \DoliDB $db Database connection
     * @return string|null Base64url-encoded public key
     */
    public static function getPublicKey($db)
    {
        $keys = self::getKeys($db);
        return $keys['publicKey'] ?? null;
    }

    /**
     * Get both VAPID keys, generating if needed
     *
     * @param \DoliDB $db Database connection
     * @return array ['publicKey' => '...', 'privateKey' => '...']
     */
    public static function getKeys($db)
    {
        global $conf;

        $publicKey = getDolGlobalString(self::PUBLIC_KEY_CONFIG, '');
        $privateKey = getDolGlobalString(self::PRIVATE_KEY_CONFIG, '');

        // Generate if missing
        if (empty($publicKey) || empty($privateKey)) {
            $keys = self::generateKeys();
            self::storeKeys($db, $keys);
            return $keys;
        }

        return [
            'publicKey' => $publicKey,
            'privateKey' => $privateKey
        ];
    }

    /**
     * Generate new VAPID key pair
     *
     * @return array ['publicKey' => '...', 'privateKey' => '...']
     */
    public static function generateKeys()
    {
        // minishlink/web-push provides this method
        return VAPID::createVapidKeys();
    }

    /**
     * Store VAPID keys in Dolibarr configuration
     *
     * @param \DoliDB $db Database connection
     * @param array $keys ['publicKey' => '...', 'privateKey' => '...']
     * @return bool Success
     */
    public static function storeKeys($db, $keys)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        $result1 = dolibarr_set_const(
            $db,
            self::PUBLIC_KEY_CONFIG,
            $keys['publicKey'],
            'chaine',
            0,
            'VAPID public key for Web Push',
            $conf->entity
        );

        $result2 = dolibarr_set_const(
            $db,
            self::PRIVATE_KEY_CONFIG,
            $keys['privateKey'],
            'chaine',
            0,
            'VAPID private key for Web Push',
            $conf->entity
        );

        return ($result1 > 0 && $result2 > 0);
    }

    /**
     * Regenerate VAPID keys (invalidates all existing subscriptions!)
     *
     * WARNING: This will invalidate ALL existing push subscriptions.
     * Users will need to re-subscribe.
     *
     * @param \DoliDB $db Database connection
     * @return array New keys ['publicKey' => '...', 'privateKey' => '...']
     */
    public static function regenerateKeys($db)
    {
        global $conf;

        // Generate new keys
        $keys = self::generateKeys();

        // Store them
        self::storeKeys($db, $keys);

        // Mark all existing subscriptions as expired
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET status = 9";
        $sql .= " WHERE entity = ".(int)$conf->entity;
        $db->query($sql);

        return $keys;
    }
}
```

---

## 7. Intégration Frontend (smartcommon)

### 7.1 Hook usePushNotifications

```typescript
// smartcommon/src/hooks/usePushNotifications.ts

import { useState, useEffect, useCallback } from 'react';
import { useApi } from './useApi';

export type PushPermissionState = 'default' | 'granted' | 'denied' | 'unsupported';

export interface PushSubscriptionInfo {
    id: number;
    label: string | null;
    user_agent: string | null;
    created_at: string;
    last_used_at: string | null;
    success_count: number;
    status: number;
}

export interface UsePushNotificationsReturn {
    // State
    permission: PushPermissionState;
    isSubscribed: boolean;
    isLoading: boolean;
    error: string | null;
    subscriptions: PushSubscriptionInfo[];

    // Actions
    subscribe: (label?: string) => Promise<boolean>;
    unsubscribe: () => Promise<boolean>;
    refreshSubscriptions: () => Promise<void>;
}

/**
 * Hook for managing Web Push Notifications
 *
 * @example
 * ```tsx
 * function NotificationSettings() {
 *     const {
 *         permission,
 *         isSubscribed,
 *         isLoading,
 *         subscribe,
 *         unsubscribe
 *     } = usePushNotifications();
 *
 *     if (permission === 'unsupported') {
 *         return <p>Push notifications not supported</p>;
 *     }
 *
 *     if (permission === 'denied') {
 *         return <p>Notifications blocked. Enable in browser settings.</p>;
 *     }
 *
 *     return (
 *         <button
 *             onClick={() => isSubscribed ? unsubscribe() : subscribe()}
 *             disabled={isLoading}
 *         >
 *             {isSubscribed ? 'Disable' : 'Enable'} notifications
 *         </button>
 *     );
 * }
 * ```
 */
export function usePushNotifications(): UsePushNotificationsReturn {
    const api = useApi();

    const [permission, setPermission] = useState<PushPermissionState>('default');
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [subscriptions, setSubscriptions] = useState<PushSubscriptionInfo[]>([]);

    // Check browser support
    const isSupported = 'serviceWorker' in navigator && 'PushManager' in window;

    // Initialize state
    useEffect(() => {
        if (!isSupported) {
            setPermission('unsupported');
            setIsLoading(false);
            return;
        }

        // Get current permission
        setPermission(Notification.permission as PushPermissionState);

        // Check if already subscribed
        checkSubscription();
    }, []);

    const checkSubscription = useCallback(async () => {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            setIsSubscribed(subscription !== null);
        } catch (err) {
            console.error('Error checking subscription:', err);
        } finally {
            setIsLoading(false);
        }
    }, []);

    const refreshSubscriptions = useCallback(async () => {
        try {
            const response = await api.get('/push/subscriptions');
            if (response.subscriptions) {
                setSubscriptions(response.subscriptions);
            }
        } catch (err) {
            console.error('Error fetching subscriptions:', err);
        }
    }, [api]);

    const subscribe = useCallback(async (label?: string): Promise<boolean> => {
        if (!isSupported) {
            setError('Push notifications not supported');
            return false;
        }

        setIsLoading(true);
        setError(null);

        try {
            // Request permission if needed
            if (Notification.permission === 'default') {
                const result = await Notification.requestPermission();
                setPermission(result as PushPermissionState);
                if (result !== 'granted') {
                    setError('Permission denied');
                    return false;
                }
            } else if (Notification.permission === 'denied') {
                setError('Notifications blocked');
                return false;
            }

            // Get VAPID public key from server
            const vapidResponse = await api.get('/push/vapid-public-key', { auth: false });
            if (!vapidResponse.publicKey) {
                setError('Server not configured for push');
                return false;
            }

            // Convert VAPID key to Uint8Array
            const vapidPublicKey = urlBase64ToUint8Array(vapidResponse.publicKey);

            // Subscribe via Push API
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: vapidPublicKey
            });

            // Send subscription to backend
            const subscriptionJson = subscription.toJSON();
            await api.post('/push/subscribe', {
                subscription: {
                    endpoint: subscriptionJson.endpoint,
                    keys: {
                        p256dh: subscriptionJson.keys?.p256dh,
                        auth: subscriptionJson.keys?.auth
                    }
                },
                label
            });

            setIsSubscribed(true);
            await refreshSubscriptions();
            return true;

        } catch (err: any) {
            setError(err.message || 'Subscription failed');
            return false;
        } finally {
            setIsLoading(false);
        }
    }, [api, isSupported, refreshSubscriptions]);

    const unsubscribe = useCallback(async (): Promise<boolean> => {
        setIsLoading(true);
        setError(null);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                // Unsubscribe from browser
                await subscription.unsubscribe();

                // Remove from backend
                await api.delete('/push/unsubscribe', {
                    endpoint: subscription.endpoint
                });
            }

            setIsSubscribed(false);
            await refreshSubscriptions();
            return true;

        } catch (err: any) {
            setError(err.message || 'Unsubscribe failed');
            return false;
        } finally {
            setIsLoading(false);
        }
    }, [api, refreshSubscriptions]);

    return {
        permission,
        isSubscribed,
        isLoading,
        error,
        subscriptions,
        subscribe,
        unsubscribe,
        refreshSubscriptions
    };
}

/**
 * Convert a base64url string to Uint8Array (for VAPID key)
 */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}
```

### 7.2 Composant NotificationToggle

```tsx
// smartcommon/src/components/NotificationToggle.tsx

import React from 'react';
import { usePushNotifications } from '../hooks/usePushNotifications';

interface NotificationToggleProps {
    label?: string;
    className?: string;
}

export function NotificationToggle({ label, className }: NotificationToggleProps) {
    const {
        permission,
        isSubscribed,
        isLoading,
        error,
        subscribe,
        unsubscribe
    } = usePushNotifications();

    if (permission === 'unsupported') {
        return (
            <div className={className}>
                <span>Notifications non supportées par ce navigateur</span>
            </div>
        );
    }

    if (permission === 'denied') {
        return (
            <div className={className}>
                <span>Notifications bloquées</span>
                <small>Modifiez les paramètres du navigateur pour les activer</small>
            </div>
        );
    }

    const handleToggle = async () => {
        if (isSubscribed) {
            await unsubscribe();
        } else {
            await subscribe(label);
        }
    };

    return (
        <div className={className}>
            <label>
                <input
                    type="checkbox"
                    checked={isSubscribed}
                    onChange={handleToggle}
                    disabled={isLoading}
                />
                <span>Recevoir des notifications push</span>
            </label>
            {error && <small className="error">{error}</small>}
        </div>
    );
}
```

### 7.3 Export depuis smartcommon

```typescript
// smartcommon/src/index.ts (ajouter)

export { usePushNotifications } from './hooks/usePushNotifications';
export type {
    PushPermissionState,
    PushSubscriptionInfo,
    UsePushNotificationsReturn
} from './hooks/usePushNotifications';

export { NotificationToggle } from './components/NotificationToggle';
```

---

## 8. Service Worker (smartboot)

### 8.1 Template Service Worker

```javascript
// smartboot/templates/pwa/service-worker.js

// ============================================
// PUSH NOTIFICATIONS
// ============================================

/**
 * Handle incoming push notification
 */
self.addEventListener('push', (event) => {
    if (!event.data) {
        console.warn('Push event without data');
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        // Fallback for text payload
        payload = {
            title: 'Notification',
            body: event.data.text()
        };
    }

    const options = {
        body: payload.body || '',
        icon: payload.icon || '/api.php/icon/192',
        badge: payload.badge || '/api.php/icon/64',
        tag: payload.tag || undefined,
        data: payload.data || {},
        // Vibration pattern: vibrate 200ms, pause 100ms, vibrate 200ms
        vibrate: [200, 100, 200],
        // Keep notification until user interacts
        requireInteraction: payload.requireInteraction || false,
        // Actions (buttons)
        actions: payload.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(payload.title, options)
    );
});

/**
 * Handle notification click
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data || {};
    let targetUrl = data.url || '/';

    // Handle action buttons
    if (event.action) {
        switch (event.action) {
            case 'view':
                targetUrl = data.url || '/';
                break;
            case 'dismiss':
                return; // Just close, don't navigate
            default:
                // Custom action - check if URL provided
                if (data.actions && data.actions[event.action]) {
                    targetUrl = data.actions[event.action];
                }
        }
    }

    // Focus existing window or open new one
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Check if app is already open
                for (const client of windowClients) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.focus();
                        client.navigate(targetUrl);
                        return;
                    }
                }
                // Open new window
                if (clients.openWindow) {
                    return clients.openWindow(targetUrl);
                }
            })
    );
});

/**
 * Handle notification close (for analytics)
 */
self.addEventListener('notificationclose', (event) => {
    const data = event.notification.data || {};

    // Optional: send analytics event
    if (data.trackDismiss) {
        fetch('/api.php/push/track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event: 'dismiss',
                tag: event.notification.tag,
                timestamp: Date.now()
            })
        }).catch(() => {
            // Ignore tracking errors
        });
    }
});

/**
 * Handle push subscription change (browser renewed subscription)
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            try {
                // Get new subscription
                const newSubscription = await self.registration.pushManager.subscribe(
                    event.oldSubscription.options
                );

                // Send to server
                await fetch('/api.php/push/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscription: newSubscription.toJSON(),
                        oldEndpoint: event.oldSubscription?.endpoint
                    })
                });
            } catch (error) {
                console.error('Failed to update subscription:', error);
            }
        })()
    );
});
```

### 8.2 Exemples de payloads

```javascript
// Notification simple
{
    "title": "Nouveau message",
    "body": "Vous avez reçu un message de Jean Dupont",
    "icon": "/api.php/icon/192",
    "data": {
        "url": "/messages/123"
    }
}

// Notification avec actions
{
    "title": "Ticket #1234 assigné",
    "body": "Un nouveau ticket vous a été assigné",
    "tag": "ticket-1234",
    "requireInteraction": true,
    "actions": [
        { "action": "view", "title": "Voir" },
        { "action": "dismiss", "title": "Ignorer" }
    ],
    "data": {
        "type": "ticket_assigned",
        "ticket_id": 1234,
        "url": "/tickets/1234"
    }
}

// Notification groupée (même tag = remplacement)
{
    "title": "3 nouveaux messages",
    "body": "Vous avez des messages non lus",
    "tag": "unread-messages",
    "data": {
        "url": "/messages"
    }
}
```

---

## 9. Déclencheurs côté Dolibarr

### 9.1 Service d'envoi de notifications

```php
<?php
// smartauth/class/pushnotificationservice.class.php

namespace SmartAuth;

/**
 * Service for sending push notifications from Dolibarr triggers
 *
 * Usage in a trigger:
 * ```php
 * use SmartAuth\PushNotificationService;
 *
 * $pushService = new PushNotificationService($this->db);
 * $pushService->notifyUser($userId, 'Nouveau ticket', 'Ticket #123 créé', [
 *     'type' => 'ticket_new',
 *     'url' => '/tickets/123'
 * ]);
 * ```
 */
class PushNotificationService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Send notification to a specific user (all their devices)
     *
     * @param int $userId Target user ID
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data (url, type, etc.)
     * @param array $options Push options (ttl, urgency, tag)
     * @return array ['sent' => int, 'failed' => int]
     */
    public function notifyUser($userId, $title, $body, $data = [], $options = [])
    {
        dol_include_once('/smartauth/api/PushController.php');

        $pushController = new \SmartAuth\Api\PushController();

        $params = [
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'options' => $options
        ];

        // Add icon from PWA config if not specified
        if (empty($params['icon'])) {
            $params['icon'] = '/api.php/icon/192';
        }

        list($result, $httpCode) = $pushController->send($params);

        return [
            'sent' => $result['sent'] ?? 0,
            'failed' => $result['failed'] ?? 0
        ];
    }

    /**
     * Send notification to multiple users
     *
     * @param array $userIds Array of user IDs
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data
     * @return array ['sent' => int, 'failed' => int]
     */
    public function notifyUsers($userIds, $title, $body, $data = [])
    {
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($userIds as $userId) {
            $result = $this->notifyUser($userId, $title, $body, $data);
            $totalSent += $result['sent'];
            $totalFailed += $result['failed'];
        }

        return ['sent' => $totalSent, 'failed' => $totalFailed];
    }

    /**
     * Send notification to users with a specific right
     *
     * @param string $module Module name
     * @param string $right Right name
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data
     * @return array ['sent' => int, 'failed' => int]
     */
    public function notifyUsersWithRight($module, $right, $title, $body, $data = [])
    {
        global $conf;

        // Get users with the specified right
        $sql = "SELECT DISTINCT u.rowid";
        $sql .= " FROM ".MAIN_DB_PREFIX."user as u";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."user_rights as ur ON ur.fk_user = u.rowid";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."rights_def as rd ON rd.id = ur.fk_id";
        $sql .= " WHERE rd.module = '".$this->db->escape($module)."'";
        $sql .= " AND rd.perms = '".$this->db->escape($right)."'";
        $sql .= " AND u.statut = 1";
        $sql .= " AND u.entity IN (0, ".(int)$conf->entity.")";

        $userIds = [];
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $userIds[] = $obj->rowid;
            }
        }

        if (empty($userIds)) {
            return ['sent' => 0, 'failed' => 0];
        }

        return $this->notifyUsers($userIds, $title, $body, $data);
    }
}
```

### 9.2 Exemple de trigger Dolibarr

```php
<?php
// mymodule/core/triggers/interface_99_modMyModule_MyModuleTriggers.class.php

require_once DOL_DOCUMENT_ROOT.'/core/triggers/doaborertriggers.class.php';

class InterfaceMyModuleTriggers extends DolibarrTriggers
{
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        // Load push service
        dol_include_once('/smartauth/class/pushnotificationservice.class.php');
        $pushService = new \SmartAuth\PushNotificationService($this->db);

        switch ($action) {
            // ========================================
            // TICKETS
            // ========================================
            case 'TICKET_CREATE':
                // Notify assigned user
                if (!empty($object->fk_user_assign)) {
                    $pushService->notifyUser(
                        $object->fk_user_assign,
                        $langs->trans('NewTicketAssigned'),
                        $langs->trans('TicketRefAssigned', $object->ref),
                        [
                            'type' => 'ticket_assigned',
                            'ticket_id' => $object->id,
                            'url' => '/tickets/'.$object->id
                        ],
                        ['tag' => 'ticket-'.$object->id]
                    );
                }
                break;

            case 'TICKET_MODIFY':
                // Notify on status change
                if ($object->oldcopy && $object->oldcopy->fk_statut != $object->fk_statut) {
                    // Notify ticket creator
                    if ($object->fk_user_create != $user->id) {
                        $pushService->notifyUser(
                            $object->fk_user_create,
                            $langs->trans('TicketStatusChanged'),
                            $langs->trans('TicketRefStatusChanged', $object->ref, $object->getLibStatut(0)),
                            [
                                'type' => 'ticket_status',
                                'ticket_id' => $object->id,
                                'url' => '/tickets/'.$object->id
                            ],
                            ['tag' => 'ticket-'.$object->id]
                        );
                    }
                }
                break;

            // ========================================
            // ORDERS
            // ========================================
            case 'ORDER_VALIDATE':
                // Notify sales team
                $pushService->notifyUsersWithRight(
                    'commande',
                    'lire',
                    $langs->trans('NewOrderValidated'),
                    $langs->trans('OrderRefValidated', $object->ref),
                    [
                        'type' => 'order_validated',
                        'order_id' => $object->id,
                        'url' => '/orders/'.$object->id
                    ]
                );
                break;

            // ========================================
            // INVOICES
            // ========================================
            case 'BILL_PAYED':
                // Notify creator
                if ($object->user_author_id != $user->id) {
                    $pushService->notifyUser(
                        $object->user_author_id,
                        $langs->trans('InvoicePaid'),
                        $langs->trans('InvoiceRefPaid', $object->ref, price($object->total_ttc)),
                        [
                            'type' => 'invoice_paid',
                            'invoice_id' => $object->id,
                            'url' => '/invoices/'.$object->id
                        ]
                    );
                }
                break;
        }

        return 0;
    }
}
```

### 9.3 Configuration des notifications par module

```php
<?php
// Dans la page admin du module : mymodule/admin/setup.php

// Constantes pour activer/désactiver les notifications par type
$arrayofparameters = [
    'MYMODULE_PUSH_TICKET_CREATE' => [
        'label' => 'PushOnTicketCreate',
        'type' => 'yesno',
        'default' => '1'
    ],
    'MYMODULE_PUSH_TICKET_STATUS' => [
        'label' => 'PushOnTicketStatus',
        'type' => 'yesno',
        'default' => '1'
    ],
    'MYMODULE_PUSH_ORDER_VALIDATE' => [
        'label' => 'PushOnOrderValidate',
        'type' => 'yesno',
        'default' => '0'
    ],
];
```

### 9.4 Vérification dans le trigger

```php
// Dans le trigger, vérifier si la notification est activée
case 'TICKET_CREATE':
    if (!getDolGlobalString('MYMODULE_PUSH_TICKET_CREATE')) {
        break;
    }
    // ... envoyer la notification
    break;
```

---

## 10. Sécurité

### 10.1 Authentification et autorisation

| Endpoint | Auth requise | Contrôles |
|----------|--------------|-----------|
| `GET /push/vapid-public-key` | Non | Aucun (clé publique) |
| `POST /push/subscribe` | Oui | User peut créer ses propres subscriptions |
| `DELETE /push/unsubscribe` | Oui | User peut supprimer uniquement ses subscriptions |
| `GET /push/subscriptions` | Oui | User voit uniquement ses subscriptions |
| `POST /push/send` | Oui | Droit `smartauth->push_send` ou admin |

### 10.2 Permissions Dolibarr

```php
// core/modules/modSmartauth.class.php - Ajouter aux droits

$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
$this->rights[$r][1] = 'Send push notifications';
$this->rights[$r][3] = 0;
$this->rights[$r][4] = 'push_send';
$this->rights[$r][5] = '';
$r++;
```

### 10.3 Chiffrement

| Couche | Mécanisme | Responsable |
|--------|-----------|-------------|
| Transport | HTTPS | Serveur web |
| Payload | ECDH + AES-128-GCM | minishlink/web-push |
| Signature | ECDSA (VAPID) | minishlink/web-push |

Le contenu des notifications est chiffré de bout en bout :
1. Le serveur chiffre avec la clé publique du client (p256dh)
2. Seul le navigateur peut déchiffrer avec sa clé privée
3. Le Push Service (Google, Mozilla, Apple) ne peut pas lire le contenu

### 10.4 Rate limiting

Appliquer les mêmes règles que le reste de l'API SmartAuth :

```php
// Limites recommandées pour /push/send
'push_send' => [
    'requests_per_minute' => 60,
    'requests_per_hour' => 1000
]
```

### 10.5 Validation des entrées

```php
// Validation de l'endpoint (doit être HTTPS)
if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
    return [['error' => 'Invalid endpoint URL'], 400];
}

if (strpos($endpoint, 'https://') !== 0) {
    return [['error' => 'Endpoint must use HTTPS'], 400];
}

// Validation des clés (base64url)
if (!preg_match('/^[A-Za-z0-9_-]+$/', $keyP256dh)) {
    return [['error' => 'Invalid p256dh key format'], 400];
}
```

### 10.6 Protection des clés VAPID

- La clé privée VAPID est stockée dans `llx_const` (table Dolibarr)
- Jamais exposée via l'API
- Accès limité aux administrateurs via l'interface Dolibarr
- Régénération possible mais invalide toutes les subscriptions

### 10.7 Nettoyage des subscriptions expirées

```php
// Cron job ou tâche planifiée
// smartauth/scripts/cleanup_push_subscriptions.php

<?php
require_once '../master.inc.php';

$sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
$sql .= " WHERE status = 9"; // Expired
$sql .= " OR (error_count >= 3 AND date_last_error < DATE_SUB(NOW(), INTERVAL 7 DAY))";

$db->query($sql);

$deleted = $db->affected_rows($sql);
print "Deleted $deleted expired subscriptions\n";
```

---

## 11. Tests

### 11.1 Tests unitaires PHPUnit

```php
<?php
// test/unit/PushControllerTest.php

namespace SmartAuth\Test\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\PushController;
use SmartAuth\Api\VapidKeyHelper;

class PushControllerTest extends TestCase
{
    private $controller;
    private $dbMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(\DoliDB::class);
        $this->controller = new PushController();
    }

    /**
     * @test
     */
    public function subscribe_withValidData_returnsSuccess()
    {
        // Arrange
        $input = [
            'subscription' => [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg'
                ]
            ],
            'label' => 'Test device'
        ];

        $this->dbMock->method('query')->willReturn(true);
        $this->dbMock->method('num_rows')->willReturn(0);
        $this->dbMock->method('last_insert_id')->willReturn(42);

        // Act
        $result = $this->controller->subscribe($input);

        // Assert
        $this->assertEquals(201, $result[1]);
        $this->assertEquals(42, $result[0]['id']);
    }

    /**
     * @test
     */
    public function subscribe_withMissingEndpoint_returnsBadRequest()
    {
        $input = [
            'subscription' => [
                'keys' => ['p256dh' => 'xxx', 'auth' => 'xxx']
            ]
        ];

        $result = $this->controller->subscribe($input);

        $this->assertEquals(400, $result[1]);
        $this->assertStringContainsString('Invalid', $result[0]['error']);
    }

    /**
     * @test
     */
    public function subscribe_withDuplicateEndpoint_returnsConflict()
    {
        $input = [
            'subscription' => [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/existing',
                'keys' => ['p256dh' => 'xxx', 'auth' => 'xxx']
            ]
        ];

        $this->dbMock->method('num_rows')->willReturn(1);
        $this->dbMock->method('fetch_object')->willReturn((object)['rowid' => 99]);

        $result = $this->controller->subscribe($input);

        $this->assertEquals(409, $result[1]);
        $this->assertEquals(99, $result[0]['id']);
    }

    /**
     * @test
     */
    public function unsubscribe_withValidId_returnsSuccess()
    {
        $input = ['id' => 42];

        $this->dbMock->method('query')->willReturn(true);
        $this->dbMock->method('affected_rows')->willReturn(1);

        $result = $this->controller->unsubscribe($input);

        $this->assertEquals(200, $result[1]);
    }

    /**
     * @test
     */
    public function unsubscribe_withNonExistentId_returnsNotFound()
    {
        $input = ['id' => 9999];

        $this->dbMock->method('affected_rows')->willReturn(0);

        $result = $this->controller->unsubscribe($input);

        $this->assertEquals(404, $result[1]);
    }

    /**
     * @test
     */
    public function send_withoutPermission_returnsForbidden()
    {
        global $user;
        $user = $this->createMock(\User::class);
        $user->method('hasRight')->willReturn(false);
        $user->admin = 0;

        $input = ['title' => 'Test', 'body' => 'Test body', 'user_id' => 1];

        $result = $this->controller->send($input);

        $this->assertEquals(403, $result[1]);
    }
}
```

### 11.2 Tests VapidKeyHelper

```php
<?php
// test/unit/VapidKeyHelperTest.php

namespace SmartAuth\Test\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\VapidKeyHelper;

class VapidKeyHelperTest extends TestCase
{
    /**
     * @test
     */
    public function generateKeys_returnsValidKeyPair()
    {
        $keys = VapidKeyHelper::generateKeys();

        $this->assertArrayHasKey('publicKey', $keys);
        $this->assertArrayHasKey('privateKey', $keys);
        $this->assertNotEmpty($keys['publicKey']);
        $this->assertNotEmpty($keys['privateKey']);

        // Public key should be base64url (no +, /, =)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $keys['publicKey']);
    }

    /**
     * @test
     */
    public function getKeys_whenNotConfigured_generatesAndStores()
    {
        // This test requires mocking getDolGlobalString and dolibarr_set_const
        $this->markTestIncomplete('Requires Dolibarr function mocks');
    }
}
```

### 11.3 Tests d'intégration

```php
<?php
// test/integration/PushNotificationIntegrationTest.php

namespace SmartAuth\Test\Integration;

use SmartAuth\Test\DolibarrTestCase;

class PushNotificationIntegrationTest extends DolibarrTestCase
{
    /**
     * @test
     */
    public function fullSubscriptionFlow()
    {
        // 1. Get VAPID public key
        $response = $this->apiGet('/push/vapid-public-key');
        $this->assertEquals(200, $response['code']);
        $this->assertNotEmpty($response['body']['publicKey']);

        // 2. Subscribe
        $subscription = [
            'subscription' => [
                'endpoint' => 'https://test.example.com/push/' . uniqid(),
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg'
                ]
            ],
            'label' => 'Integration test'
        ];

        $response = $this->apiPost('/push/subscribe', $subscription);
        $this->assertEquals(201, $response['code']);
        $subscriptionId = $response['body']['id'];

        // 3. List subscriptions
        $response = $this->apiGet('/push/subscriptions');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(1, $response['body']['subscriptions']);

        // 4. Unsubscribe
        $response = $this->apiDelete('/push/unsubscribe', ['id' => $subscriptionId]);
        $this->assertEquals(200, $response['code']);

        // 5. Verify removed
        $response = $this->apiGet('/push/subscriptions');
        $this->assertCount(0, $response['body']['subscriptions']);
    }

    /**
     * @test
     */
    public function sendNotification_toValidSubscription_succeeds()
    {
        $this->loginAsAdmin();

        // Create a test subscription
        $subscriptionId = $this->createTestSubscription();

        // Note: actual push won't work without real browser subscription
        // This tests the API flow, not actual delivery
        $response = $this->apiPost('/push/send', [
            'subscription_id' => $subscriptionId,
            'title' => 'Test notification',
            'body' => 'This is a test'
        ]);

        // Will fail with invalid subscription but should not error
        $this->assertContains($response['code'], [200, 207]);
    }
}
```

### 11.4 Tests frontend (Jest)

```typescript
// smartcommon/src/hooks/__tests__/usePushNotifications.test.ts

import { renderHook, act } from '@testing-library/react-hooks';
import { usePushNotifications } from '../usePushNotifications';

// Mock navigator
const mockPushManager = {
    getSubscription: jest.fn(),
    subscribe: jest.fn(),
};

const mockServiceWorker = {
    ready: Promise.resolve({
        pushManager: mockPushManager,
    }),
};

Object.defineProperty(navigator, 'serviceWorker', {
    value: mockServiceWorker,
    writable: true,
});

Object.defineProperty(window, 'Notification', {
    value: { permission: 'default', requestPermission: jest.fn() },
    writable: true,
});

describe('usePushNotifications', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        mockPushManager.getSubscription.mockResolvedValue(null);
    });

    it('should detect unsupported browsers', () => {
        // Remove PushManager
        delete (window as any).PushManager;

        const { result } = renderHook(() => usePushNotifications());

        expect(result.current.permission).toBe('unsupported');
    });

    it('should check initial subscription state', async () => {
        const { result, waitForNextUpdate } = renderHook(() => usePushNotifications());

        await waitForNextUpdate();

        expect(result.current.isSubscribed).toBe(false);
        expect(result.current.isLoading).toBe(false);
    });

    it('should subscribe successfully', async () => {
        (Notification as any).permission = 'granted';

        const mockSubscription = {
            endpoint: 'https://test.example.com/push/123',
            toJSON: () => ({
                endpoint: 'https://test.example.com/push/123',
                keys: { p256dh: 'xxx', auth: 'yyy' }
            }),
            unsubscribe: jest.fn(),
        };

        mockPushManager.subscribe.mockResolvedValue(mockSubscription);

        const { result, waitForNextUpdate } = renderHook(() => usePushNotifications());

        await act(async () => {
            const success = await result.current.subscribe('Test label');
            expect(success).toBe(true);
        });

        expect(result.current.isSubscribed).toBe(true);
    });
});
```

### 11.5 Matrice de couverture

| Composant | Tests unitaires | Tests intégration | Couverture cible |
|-----------|-----------------|-------------------|------------------|
| PushController | Oui | Oui | 90% |
| VapidKeyHelper | Oui | - | 80% |
| PushNotificationService | Oui | Oui | 85% |
| usePushNotifications | Oui | - | 80% |
| Service Worker | - | Oui (E2E) | 70% |

### 11.6 Commandes de test

```bash
# Tests unitaires push
composer test -- --filter Push

# Tests intégration push
composer test-integration -- --filter Push

# Tests frontend
cd ../smartcommon && npm test -- --testPathPattern=Push

# Couverture
composer test -- --coverage-html coverage/ --filter Push
```

---

## Annexes

### A. Checklist d'implémentation

- [ ] Ajouter dépendance `minishlink/web-push` au composer.json
- [ ] Créer les tables SQL (subscriptions, logs)
- [ ] Implémenter VapidKeyHelper
- [ ] Implémenter PushController
- [ ] Ajouter les routes dans RouteController
- [ ] Ajouter les schémas de validation
- [ ] Ajouter le droit `push_send` dans modSmartauth
- [ ] Créer le hook usePushNotifications dans smartcommon
- [ ] Ajouter les handlers push au Service Worker smartboot
- [ ] Créer PushNotificationService pour les triggers
- [ ] Écrire les tests
- [ ] Documenter l'API (apiDoc)

### B. Compatibilité navigateurs

| Navigateur | Push API | Service Worker | Notes |
|------------|----------|----------------|-------|
| Chrome 50+ | Oui | Oui | Via FCM |
| Firefox 44+ | Oui | Oui | Via Mozilla autopush |
| Safari 16+ | Oui | Oui | Via APNs (iOS 16.4+) |
| Edge 17+ | Oui | Oui | Via FCM |
| Opera 37+ | Oui | Oui | Via FCM |

### C. Ressources

- [Web Push Protocol (RFC 8030)](https://datatracker.ietf.org/doc/html/rfc8030)
- [VAPID (RFC 8292)](https://datatracker.ietf.org/doc/html/rfc8292)
- [Push API - MDN](https://developer.mozilla.org/en-US/docs/Web/API/Push_API)
- [minishlink/web-push](https://github.com/web-push-libs/web-push-php)
