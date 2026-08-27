# Spécification : Web Push Notifications pour SmartAuth

> **Version** : 1.1.0-draft
> **Date** : 2026-06-02
> **Statut** : Spécification technique pour implémentation
>
> **Révision 1.2.0** : implémentation Web Push interne, sans librairie externe.
> - Cible PHP : **PHP 7.4** (cf. `composer.json`).
> - Plus de dépendance `minishlink/web-push` : l'envoi (chiffrement aes128gcm
>   RFC 8291 + JWT VAPID RFC 8292 + POST via `getURLContent`) est porté par
>   `api/WebPushCrypto.php`, qui ne s'appuie que sur `ext-openssl`, `hash_hkdf`
>   et `firebase/php-jwt` (déjà embarqué). Cela supprime guzzle et les paquets
>   abandonnés `web-token/*` + `fgrosse/phpasn1` du vendor livré (cf. annexe D).
>
> **Révision 1.1.0** : alignement sur les contraintes réelles du module.
> - Cible PHP : on restait sur **PHP 7.4** avec `minishlink/web-push: ^6.0`
>   (remplacé en 1.2.0 par l'implémentation interne ci-dessus).
> - Modèle d'identité : les subscriptions sont **subject-aware**
>   (`subject_type` + `fk_user`/`fk_societe_account`/`fk_adherent`), en miroir
>   de `llx_smartauth_oauth_tokens`. Le push couvre donc le silo A (`usr:`) ET
>   le silo B (`acc:`/`mbr:`), pas seulement `llx_user`. Voir
>   `documentation/DECISION_2026-06-02_modele-identite-deux-silos.md`.
> - Conventions SQL du module respectées : `CREATE TABLE` dans `llx_*.sql`,
>   index/contraintes dans `llx_*.key.sql`, aucun `CREATE` dans un
>   `update_*.sql`.
> - Séparation porte HTTP (contrôle de permission) / chemin métier interne
>   (envoi depuis triggers et cron, sans `$user`).

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
| Librairie serveur | Oui | `api/WebPushCrypto.php` (interne, openssl + firebase/php-jwt) |
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
│  │  - envoi interne: PushSender                                │    │
│  └──────────┬──────────────────────────────────────────────────┘    │
│             │                                                        │
│  ┌──────────▼──────────┐  ┌────────────────────────────────────┐    │
│  │  PushSender +       │  │  llx_smartauth_push_subscriptions  │    │
│  │  WebPushCrypto       │  │  - endpoint, keys, user, device    │    │
│  │  (interne)          │  └────────────────────────────────────┘    │
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
- Aucune librairie Web Push externe. L'envoi est porté par `api/WebPushCrypto.php`
  (cf. annexe D), qui n'utilise que `firebase/php-jwt` (déjà présent pour les JWT
  du module) et des briques natives PHP.

> Dépendances système requises : extensions PHP `openssl`, `mbstring` et `curl`
> (à vérifier sur l'hôte de déploiement). `hash_hkdf` est natif depuis PHP 7.1.

**Frontend (smartcommon)** :
- Aucune dépendance externe (utilise Push API native du navigateur)

---

## 3. Base de données

### 3.1 Identité du sujet (subject-aware)

Une subscription push appartient à un **sujet** SmartAuth, pas seulement à un
`llx_user`. On reprend exactement le schéma de `llx_smartauth_oauth_tokens` :

- `subject_type` discrimine l'espace d'ID : `'user'` / `'account'` / `'member'`.
- `'user'` -> `fk_user` porte `llx_user.rowid` (les deux autres FK à NULL).
- `'account'` -> `fk_societe_account` porte `llx_societe_account.rowid`,
  `fk_user = 0` (sentinelle ; `fk_user` reste `NOT NULL` pour éviter un
  `MODIFY COLUMN` que SQLite ne sait pas faire en update).
- `'member'` -> `fk_adherent` porte `llx_adherent.rowid`, `fk_user = 0`.

C'est ce qui permet au push de servir le silo A interne (`usr:`) **et** le silo
B des abonnés SSO (`acc:`/`mbr:`). Une table keyée sur `fk_user` seul exclurait
toute la population externe.

### 3.2 Table `llx_smartauth_push_subscriptions`

`llx_*.sql` ne contient QUE le `CREATE TABLE` (convention module, zéro index ici).

```sql
-- smartauth/sql/llx_smartauth_push_subscriptions.sql

CREATE TABLE llx_smartauth_push_subscriptions (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

    -- Subject identity (miroir de llx_smartauth_oauth_tokens)
    subject_type        VARCHAR(16) DEFAULT 'user' NOT NULL,
    fk_user             INTEGER NOT NULL,           -- 'user' -> llx_user.rowid ; sinon 0
    fk_societe_account  INTEGER NULL DEFAULT NULL,  -- 'account' -> llx_societe_account.rowid
    fk_adherent         INTEGER NULL DEFAULT NULL,  -- 'member'  -> llx_adherent.rowid

    fk_device           INTEGER NULL DEFAULT NULL,  -- Device SmartAuth (llx_smartauth_devices.rowid)
    entity              INTEGER DEFAULT 1 NOT NULL, -- Multi-entité Dolibarr

    -- Subscription Web Push (format standard W3C)
    endpoint            TEXT NOT NULL,              -- URL du Push Service (unique par subscription)
    key_p256dh          VARCHAR(255) NOT NULL,      -- Clé publique client (base64url)
    key_auth            VARCHAR(255) NOT NULL,      -- Secret d'authentification (base64url)

    -- Métadonnées
    user_agent          VARCHAR(255) NULL,          -- User-Agent du navigateur
    label               VARCHAR(128) NULL,          -- Nom donné par l'utilisateur (optionnel)

    -- Dates
    date_creation       DATETIME NOT NULL,
    date_last_used      DATETIME NULL,              -- Dernière notification envoyée
    date_last_error     DATETIME NULL,              -- Dernière erreur d'envoi
    last_error          VARCHAR(255) NULL,          -- Message de la dernière erreur

    -- Compteurs
    success_count       INTEGER DEFAULT 0,          -- Notifications envoyées avec succès
    error_count         INTEGER DEFAULT 0,          -- Erreurs consécutives

    -- Statut
    status              TINYINT DEFAULT 1 NOT NULL, -- 0=désactivé, 1=actif, 9=expiré

    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
```

Index et contrainte d'unicité dans le fichier `.key.sql` dédié :

```sql
-- smartauth/sql/llx_smartauth_push_subscriptions.key.sql

ALTER TABLE llx_smartauth_push_subscriptions ADD UNIQUE INDEX uk_endpoint (endpoint(500));
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_subject_user (subject_type, fk_user);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_subject_account (subject_type, fk_societe_account);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_subject_member (subject_type, fk_adherent);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_fk_device (fk_device);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_entity (entity);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_status (status);
```

### 3.3 Table `llx_smartauth_push_logs` (optionnel, pour audit)

```sql
-- smartauth/sql/llx_smartauth_push_logs.sql

CREATE TABLE llx_smartauth_push_logs (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

    fk_subscription     INTEGER NULL,               -- NULL si subscription supprimée

    -- Subject identity (même discrimination que la table subscriptions)
    subject_type        VARCHAR(16) DEFAULT 'user' NOT NULL,
    fk_user             INTEGER NOT NULL,
    fk_societe_account  INTEGER NULL DEFAULT NULL,
    fk_adherent         INTEGER NULL DEFAULT NULL,
    entity              INTEGER DEFAULT 1 NOT NULL,

    -- Détails de l'envoi
    notification_type   VARCHAR(64) NULL,           -- 'ticket_new', 'order_validated', etc.
    notification_title  VARCHAR(255) NULL,
    notification_body   TEXT NULL,
    notification_data   TEXT NULL,                  -- JSON des données additionnelles

    -- Résultat
    http_status         SMALLINT NULL,              -- Code HTTP retourné par le Push Service
    success             TINYINT(1) DEFAULT 0,
    error_message       VARCHAR(255) NULL,

    date_creation       DATETIME NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
```

```sql
-- smartauth/sql/llx_smartauth_push_logs.key.sql

ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_log_subject (subject_type, fk_user);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_log_date (date_creation);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_log_type (notification_type);
```

> **RGPD** : `notification_title`/`notification_body` peuvent contenir de la
> donnée personnelle (surtout pour le silo B). La table de logs est optionnelle
> et sa rétention est bornée : purge automatique dans `doScheduledJob()` (cf.
> section 10.7). Ne pas l'activer si l'audit n'est pas requis.

### 3.4 Fichiers SQL à créer

```
smartauth/sql/
├── llx_smartauth_push_subscriptions.sql       # CREATE TABLE uniquement
├── llx_smartauth_push_subscriptions.key.sql   # INDEX / UNIQUE uniquement
├── llx_smartauth_push_logs.sql                # CREATE TABLE (optionnel)
└── llx_smartauth_push_logs.key.sql            # INDEX (optionnel)
```

### 3.5 Migration (si module déjà installé)

**Aucun fichier `update_*.sql` n'est nécessaire pour ces nouvelles tables.**
Dolibarr rejoue les fichiers `sql/llx_*.sql` et `sql/llx_*.key.sql` à chaque
mise à jour du module : une table neuve y est donc créée automatiquement, aussi
bien à l'installation qu'à l'update. Les `update_*.sql` sont réservés aux
`ALTER TABLE` sur des tables déjà existantes -- ce qui n'est pas le cas ici.

---

## 4. API Backend (SmartAuth)

### 4.1 Endpoints

| Méthode | Route | Auth | Description |
|---------|-------|------|-------------|
| GET | `/push/vapid-public-key` | Non | Récupère la clé publique VAPID |
| POST | `/push/subscribe` | Oui | Enregistre une subscription |
| DELETE | `/push/unsubscribe` | Oui | Supprime une subscription |
| GET | `/push/subscriptions` | Oui | Liste les subscriptions de l'utilisateur |

> **`/push/send` n'est PAS exposé en route HTTP par défaut.** L'envoi se fait en
> interne via `PushSender` (triggers métier, cron, action admin), jamais en
> self-service. Une route `true` serait accessible à tout sujet JWT authentifié
> (toute PWA, tout `acc:`/`mbr:`/`usr:`) alors que le body porte la cible : ce
> serait un "qui veut pousser une notif a qui veut". De plus les sujets externes
> (`acc:`/`mbr:`) n'ont pas de permission Dolibarr `smartauth->push_send`, donc le
> contrôle de droit ne les couvrirait pas dans la couche `true`. Si un envoi
> serveur-à-serveur (M2M) est réellement nécessaire, exposer une route en
> `'oauth2'` + scope dédié (ex. `smartauth:push.send`) réservée a un client
> confidentiel avec service user -- jamais accessible a un sujet end-user.

### 4.2 Enregistrement des routes

Les routes coeur du module se déclarent dans **`api/LocalRoutes.php`** (et non
dans le `pwa/api.php` d'un module consommateur) -- même mécanisme que les routes
`login`, `refresh`, `upload`, `qr-pair`, etc. déjà présentes.

```php
// smartauth/api/LocalRoutes.php (ajouter au bloc d'imports puis aux routes)

use SmartAuth\Api\PushController;

// ========== Push Notifications ========== //

// Clé publique VAPID (publique, lecture seule)
Route::get('push/vapid-public-key', PushController::class, 'getVapidPublicKey', false);

// Gestion des subscriptions (JWT mobile OU OAuth2 Bearer -> subject-aware)
Route::post('push/subscribe', PushController::class, 'subscribe', true);
Route::delete('push/unsubscribe', PushController::class, 'unsubscribe', true);
Route::get('push/subscriptions', PushController::class, 'listSubscriptions', true);

// PAS de route 'push/send' par défaut : l'envoi est interne via PushSender
// (triggers, cron, admin). Voir la note ci-dessous. N'ouvrir une route HTTP
// 'send' QUE si un appel M2M serveur-a-serveur est requis, et alors en
// 'oauth2' + scope dedie, jamais en 'true'.
```

> **Rappel `$protected`** : `false` = public, `true` = JWT SmartAuth (mobile),
> `'oauth2'` = Bearer OAuth2 (M2M / apps tierces).
>
> **Pourquoi pas de `Route::post('push/send', ..., true)`** : une route `true`
> est accessible à tout sujet JWT authentifié (toute PWA, tout `acc:`/`mbr:`/
> `usr:`), or le body de `send` porte la cible (`user_id`/`subject_id`). Ce serait
> un "qui veut pousser une notif à qui veut". Et les sujets externes n'ont pas de
> permission Dolibarr `smartauth->push_send`, donc le contrôle de droit ne les
> couvre pas dans la couche `true`. En interne (triggers, cron), on n'utilise PAS
> de route HTTP : on appelle directement `PushSender` (section 5.3) / le service
> de section 9, qui court-circuite toute porte d'autorisation. Si un envoi M2M
> externe devient nécessaire, ajouter alors :
> `Route::post('push/send', PushController::class, 'send', 'oauth2');` en exigeant
> un scope dédié (ex. `smartauth:push.send`) réservé à un client confidentiel
> avec service user.

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

**Réponse succès (201 - création)** :
```json
{
    "id": 42,
    "message": "Subscription registered successfully"
}
```

**Réponse succès (200 - mise à jour / re-binding UPSERT)** :
```json
{
    "id": 42,
    "message": "Subscription updated successfully"
}
```

> Un `endpoint` est globalement unique (un canal push par installation de
> navigateur). S'il existe déjà, il est **ré-attaché au sujet courant** (clés et
> statut rafraîchis) au lieu de renvoyer une erreur 409. Cela évite qu'un
> appareil partagé conserve une subscription pointant vers un sujet précédent,
> ce qui ferait fuiter des notifications vers la mauvaise personne.

**Réponse erreur (400)** :
```json
{
    "error": "Endpoint must be a valid HTTPS URL"
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

#### POST /push/send (non exposé en HTTP par défaut)

Envoie une notification push. **Usage interne uniquement** : appelé via
`PushSender` (section 5.3) depuis les triggers métier, le cron ou une action
admin -- pas de route HTTP self-service (cf §4.1/§4.2). Le contrat de payload
ci-dessous décrit l'entrée de `PushSender::send()` ; il ne s'applique a une route
HTTP que si l'on ouvre volontairement un endpoint M2M en `'oauth2'` + scope dédié.

**Authentification** (si exposé en M2M seulement) : `'oauth2'` + scope
`smartauth:push.send`, client confidentiel avec service user. Jamais `true`.

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

> \* Cible requise : `subscription_id`, OU `device_id`, OU le couple
> (`subject_type`, `subject_id`). `user_id` est accepté en compatibilité et
> équivaut à `subject_type='user'`, `subject_id=user_id`. **Pas de broadcast
> implicite** : sans cible valide, `PushSender` ne renvoie aucune subscription
> (404) plutôt que d'arroser tout le monde. Un vrai broadcast admin reste hors
> périmètre de ce lot.

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

// WebPush/Subscription are used by PushSender, not by the controller.

/**
 * Controller for Web Push Notifications (HTTP endpoints + permission gate).
 *
 * Subscription management is subject-aware. Actual sending is delegated to
 * PushSender so the engine can be reused from triggers/cron without $user.
 */
class PushController
{
    // TTL, error thresholds and the VAPID subject live in PushSender (the
    // sending engine), so they are reusable from triggers/cron. The controller
    // only exposes HTTP endpoints.

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

        // Read-only on this PUBLIC route: never generate keys here (a write
        // triggered by an unauthenticated GET is undesirable). Keys are created
        // at module install (modSmartauth::init) or via the admin button.
        $publicKey = VapidKeyHelper::readPublicKey($db);

        if (empty($publicKey)) {
            dol_syslog('PushController::getVapidPublicKey VAPID keys not configured', LOG_WARNING);
            return [['error' => 'VAPID keys not configured'], 500];
        }

        return [['publicKey' => $publicKey], 200];
    }

    /**
     * Resolve the authenticated subject for write operations.
     *
     * Returns the subject identity columns to store on the subscription.
     *
     * KNOWN LIMITATION (2026-06): the Bearer route layer (RouteController +
     * functions_smartauthoauth) is user-scoped and rejects acc:/mbr: subjects
     * on these protected routes (silo A enforcement). So today this always
     * resolves to subject_type='user'. The schema and this helper are already
     * subject-aware: when external-subject push is needed (silo B / SSO), the
     * route admission is widened and this helper reads the TokenSubject from
     * the auth context instead of global $user. Closed by default, widened on
     * demand -- never the reverse.
     *
     * @return array{subject_type:string, fk_user:int, fk_societe_account:?int, fk_adherent:?int}
     */
    private function resolveSubject()
    {
        global $user;

        // Silo A: internal user (the only subject the Bearer layer admits today)
        return [
            'subject_type'       => 'user',
            'fk_user'            => (int) $user->id,
            'fk_societe_account' => null,
            'fk_adherent'        => null,
        ];
    }

    /**
     * Build a subject-aware SQL WHERE fragment (without leading AND).
     *
     * @param array $subject Output of resolveSubject()
     * @return string
     */
    private function subjectWhere($subject)
    {
        $w = "subject_type = '".$GLOBALS['db']->escape($subject['subject_type'])."'";
        if ($subject['subject_type'] === 'account') {
            $w .= " AND fk_societe_account = ".(int) $subject['fk_societe_account'];
        } elseif ($subject['subject_type'] === 'member') {
            $w .= " AND fk_adherent = ".(int) $subject['fk_adherent'];
        } else {
            $w .= " AND fk_user = ".(int) $subject['fk_user'];
        }
        return $w;
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
     * @apiSuccess (201) {Number} id Subscription ID (created)
     * @apiSuccess (200) {Number} id Subscription ID (existing endpoint re-bound to current subject)
     * @apiSuccess {String} message Success message
     *
     * @apiError (400) InvalidSubscription Invalid format / non-HTTPS endpoint / bad key
     * @apiError (401) Unauthorized Invalid or missing token
     */
    public function subscribe($arr = null)
    {
        global $db, $user, $conf;

        // Validate presence
        if (empty($arr['subscription']['endpoint']) ||
            empty($arr['subscription']['keys']['p256dh']) ||
            empty($arr['subscription']['keys']['auth'])) {
            dol_syslog('PushController::subscribe invalid subscription format', LOG_WARNING);
            return [['error' => 'Invalid subscription format'], 400];
        }

        $endpoint = $arr['subscription']['endpoint'];
        $keyP256dh = $arr['subscription']['keys']['p256dh'];
        $keyAuth = $arr['subscription']['keys']['auth'];

        // Validate endpoint: must be a valid HTTPS URL
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strpos($endpoint, 'https://') !== 0) {
            dol_syslog('PushController::subscribe endpoint not a valid https URL', LOG_WARNING);
            return [['error' => 'Endpoint must be a valid HTTPS URL'], 400];
        }
        // Validate keys: base64url charset
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $keyP256dh) || !preg_match('/^[A-Za-z0-9_-]+$/', $keyAuth)) {
            dol_syslog('PushController::subscribe key not base64url', LOG_WARNING);
            return [['error' => 'Invalid key format (expected base64url)'], 400];
        }

        $label = isset($arr['label']) ? substr($arr['label'], 0, 128) : null;
        $deviceId = isset($arr['device_id']) ? (int)$arr['device_id'] : null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $subject = $this->resolveSubject();

        // UPSERT semantics: an endpoint is globally unique (one push channel per
        // browser install). If it already exists, re-bind it to the CURRENT
        // subject and refresh keys/status -- never return 409. This prevents a
        // shared device from keeping a stale subscription pointed at a previous
        // subject (a stale row would leak notifications to the wrong person).
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE endpoint = '".$db->escape($endpoint)."'";
        $sql .= " AND entity = ".(int)$conf->entity;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::subscribe lookup failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        if ($db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $id = (int) $obj->rowid;

            $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions SET";
            $sql .= " subject_type = '".$db->escape($subject['subject_type'])."'";
            $sql .= ", fk_user = ".(int)$subject['fk_user'];
            $sql .= ", fk_societe_account = ".($subject['fk_societe_account'] !== null ? (int)$subject['fk_societe_account'] : "NULL");
            $sql .= ", fk_adherent = ".($subject['fk_adherent'] !== null ? (int)$subject['fk_adherent'] : "NULL");
            $sql .= ", fk_device = ".($deviceId ? (int)$deviceId : "NULL");
            $sql .= ", key_p256dh = '".$db->escape($keyP256dh)."'";
            $sql .= ", key_auth = '".$db->escape($keyAuth)."'";
            $sql .= ", user_agent = ".($userAgent ? "'".$db->escape($userAgent)."'" : "NULL");
            $sql .= ", label = ".($label ? "'".$db->escape($label)."'" : "NULL");
            $sql .= ", error_count = 0, last_error = NULL, status = 1";
            $sql .= " WHERE rowid = ".$id;
            if (!$db->query($sql)) {
                dol_syslog('PushController::subscribe update failed: '.$db->lasterror(), LOG_ERR);
                return [['error' => 'Database error'], 500];
            }
            return [['id' => $id, 'message' => 'Subscription updated successfully'], 200];
        }

        // Insert new subscription
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " (subject_type, fk_user, fk_societe_account, fk_adherent, fk_device, entity,";
        $sql .= " endpoint, key_p256dh, key_auth, user_agent, label, date_creation, status)";
        $sql .= " VALUES (";
        $sql .= "'".$db->escape($subject['subject_type'])."', ";
        $sql .= (int)$subject['fk_user'].", ";
        $sql .= ($subject['fk_societe_account'] !== null ? (int)$subject['fk_societe_account'] : "NULL").", ";
        $sql .= ($subject['fk_adherent'] !== null ? (int)$subject['fk_adherent'] : "NULL").", ";
        $sql .= ($deviceId ? (int)$deviceId : "NULL").", ";
        $sql .= (int)$conf->entity.", ";
        $sql .= "'".$db->escape($endpoint)."', ";
        $sql .= "'".$db->escape($keyP256dh)."', ";
        $sql .= "'".$db->escape($keyAuth)."', ";
        $sql .= ($userAgent ? "'".$db->escape($userAgent)."'" : "NULL").", ";
        $sql .= ($label ? "'".$db->escape($label)."'" : "NULL").", ";
        $sql .= "'".$db->idate(dol_now())."', ";
        $sql .= "1";
        $sql .= ")";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::subscribe insert failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error: '.$db->lasterror()], 500];
        }

        $id = (int) $db->last_insert_id(MAIN_DB_PREFIX."smartauth_push_subscriptions");

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
        global $db, $conf;

        $endpoint = isset($arr['endpoint']) ? $arr['endpoint'] : null;
        $id = isset($arr['id']) ? (int)$arr['id'] : null;

        if (empty($endpoint) && empty($id)) {
            dol_syslog('PushController::unsubscribe missing endpoint and id', LOG_WARNING);
            return [['error' => 'Either endpoint or id is required'], 400];
        }

        $subject = $this->resolveSubject();

        // A subject can only delete its own subscriptions (subject-aware scope)
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE ".$this->subjectWhere($subject);
        $sql .= " AND entity = ".(int)$conf->entity;

        if ($id) {
            $sql .= " AND rowid = ".(int)$id;
        } else {
            $sql .= " AND endpoint = '".$db->escape($endpoint)."'";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::unsubscribe delete failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        if ((int) $db->affected_rows($resql) === 0) {
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
        global $db, $conf;

        $subject = $this->resolveSubject();

        $sql = "SELECT rowid, label, user_agent, date_creation, date_last_used, success_count, error_count, status";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE ".$this->subjectWhere($subject);
        $sql .= " AND entity = ".(int)$conf->entity;
        $sql .= " ORDER BY date_creation DESC";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::listSubscriptions query failed: '.$db->lasterror(), LOG_ERR);
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
     * @api {post} /push/send Send push notification (OPTIONAL M2M route)
     * @apiName Send
     * @apiGroup Push
     * @apiVersion 1.0.0
     * @apiPermission scope:smartauth:push.send
     *
     * @apiDescription Sends a push notification to specified subject(s).
     * NOT registered by default: the route exists only if a server-to-server
     * (M2M) need is wired in LocalRoutes.php as `'oauth2'` (cf §4.1/§4.2). Never
     * exposed as `true`. For internal sends (triggers, cron, admin action) call
     * PushSender directly -- do NOT go through this HTTP handler.
     *
     * @apiHeader {String} Authorization Bearer OAuth2 access token (M2M client)
     *
     * @apiParam {Number} [user_id] Target user ID (compat: subject_type=user)
     * @apiParam {Number} [subscription_id] Specific subscription ID
     * @apiParam {String} [subject_type] account/member/user
     * @apiParam {Number} [subject_id] Subject ID (with subject_type)
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
        global $db, $conf;

        // HTTP GATE ONLY, for the optional 'oauth2' M2M route. The gate is the
        // OAuth scope, NOT a Dolibarr right: an M2M token has no $user with
        // rights, and external subjects (acc:/mbr:) carry no Dolibarr right
        // either. The 'oauth2' protection layer injects oauth_scopes into $arr.
        // PushSender itself carries NO authorization logic so it stays reusable
        // from triggers/cron where there is no request context.
        $scopes = isset($arr['oauth_scopes']) ? (array) $arr['oauth_scopes'] : [];
        if (!in_array('smartauth:push.send', $scopes, true)) {
            dol_syslog('PushController::send denied: missing scope smartauth:push.send', LOG_WARNING);
            return [['error' => 'insufficient_scope'], 403];
        }

        if (empty($arr['title']) || empty($arr['body'])) {
            return [['error' => 'title and body are required'], 400];
        }

        // Resolve the target. Today only user-scoped targeting is wired; the
        // schema supports account/member targeting (set subject_type +
        // subject_id) for when silo B push is enabled.
        $target = [
            'subscription_id' => isset($arr['subscription_id']) ? (int)$arr['subscription_id'] : null,
            'subject_type'    => isset($arr['subject_type']) ? (string)$arr['subject_type'] : 'user',
            'subject_id'      => isset($arr['subject_id']) ? (int)$arr['subject_id'] : null,
            'user_id'         => isset($arr['user_id']) ? (int)$arr['user_id'] : null,
            'device_id'       => isset($arr['device_id']) ? (int)$arr['device_id'] : null,
        ];
        // Back-compat: user_id implies subject_type=user.
        if ($target['user_id'] && !$target['subject_id']) {
            $target['subject_type'] = 'user';
            $target['subject_id'] = $target['user_id'];
        }

        $sender = new PushSender($db);
        list($result, $httpCode) = $sender->send(
            $target,
            [
                'title' => $arr['title'],
                'body'  => $arr['body'],
                'icon'  => $arr['icon'] ?? null,
                'badge' => $arr['badge'] ?? null,
                'tag'   => $arr['tag'] ?? null,
                'data'  => $arr['data'] ?? [],
            ],
            $arr['options'] ?? []
        );

        return [$result, $httpCode];
    }
}
```

### 5.3 Classe PushSender (moteur d'envoi, sans permission)

`PushSender` porte toute la logique réseau VAPID. Elle **ne fait aucun contrôle
d'autorisation** et ne lit jamais `$user` global : c'est ce qui permet de la
réutiliser depuis les triggers et le cron. Le gate de permission vit uniquement
dans `PushController::send()`.

```php
<?php
// smartauth/api/PushSender.php

namespace SmartAuth\Api;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Web Push sending engine. No authorization logic on purpose:
 * the HTTP permission gate lives in PushController::send().
 */
class PushSender
{
    const DEFAULT_TTL = 86400;   // 24h
    const MAX_ERROR_COUNT = 3;
    const VAPID_SUBJECT_CONFIG = 'SMARTAUTH_VAPID_SUBJECT';

    /** @var \DoliDB */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Resolve target subscriptions, encrypt + dispatch, update bookkeeping.
     *
     * @param array $target  ['subscription_id'|'subject_type'+'subject_id'|'device_id']
     * @param array $message ['title','body','icon','badge','tag','data']
     * @param array $options ['ttl','urgency']
     * @return array [responseArray, httpCode]
     */
    public function send(array $target, array $message, array $options = [])
    {
        global $conf;

        $subscriptions = $this->getTargetSubscriptions($target, (int)$conf->entity);
        if (empty($subscriptions)) {
            dol_syslog('PushSender::send no active subscription for target '.json_encode($target), LOG_NOTICE);
            return [['sent' => 0, 'failed' => 0, 'results' => [], 'error' => 'No active subscriptions found'], 404];
        }

        $vapidKeys = VapidKeyHelper::readKeys($this->db);
        if (empty($vapidKeys['publicKey']) || empty($vapidKeys['privateKey'])) {
            dol_syslog('PushSender::send VAPID keys not configured', LOG_ERR);
            return [['error' => 'VAPID keys not configured'], 500];
        }

        $auth = ['VAPID' => [
            'subject'    => $this->resolveVapidSubject(),
            'publicKey'  => $vapidKeys['publicKey'],
            'privateKey' => $vapidKeys['privateKey'],
        ]];
        $webPush = new WebPush($auth);

        $payload = json_encode([
            'title' => $message['title'],
            'body'  => $message['body'],
            'icon'  => $message['icon'] ?? null,
            'badge' => $message['badge'] ?? null,
            'tag'   => $message['tag'] ?? null,
            'data'  => $message['data'] ?? [],
        ]);

        $ttl = isset($options['ttl']) ? (int)$options['ttl'] : self::DEFAULT_TTL;
        $urgency = isset($options['urgency']) ? $options['urgency'] : 'normal';

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => ['p256dh' => $sub['key_p256dh'], 'auth' => $sub['key_auth']],
                ]),
                $payload,
                ['TTL' => $ttl, 'urgency' => $urgency]
            );
        }

        $results = [];
        $sent = 0;
        $failed = 0;
        foreach ($webPush->flush() as $index => $report) {
            $sub = $subscriptions[$index];
            $row = ['subscription_id' => (int)$sub['rowid'], 'success' => $report->isSuccess()];
            if ($report->isSuccess()) {
                $sent++;
                $this->updateSubscriptionSuccess((int)$sub['rowid']);
            } else {
                $failed++;
                $reason = $report->getReason();
                $row['error'] = $reason;
                dol_syslog('PushSender::send failed sub='.((int)$sub['rowid']).' reason='.$reason, LOG_WARNING);
                if ($report->isSubscriptionExpired()) {
                    $this->removeSubscription((int)$sub['rowid']);
                    $row['removed'] = true;
                } else {
                    $this->updateSubscriptionError((int)$sub['rowid'], $reason);
                }
            }
            $results[] = $row;
        }

        $httpCode = ($failed > 0 && $sent > 0) ? 207 : 200;
        return [['sent' => $sent, 'failed' => $failed, 'results' => $results], $httpCode];
    }

    private function getTargetSubscriptions(array $target, $entity)
    {
        $sql = "SELECT rowid, endpoint, key_p256dh, key_auth";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE status = 1 AND entity = ".(int)$entity;

        if (!empty($target['subscription_id'])) {
            $sql .= " AND rowid = ".(int)$target['subscription_id'];
        } elseif (!empty($target['device_id'])) {
            $sql .= " AND fk_device = ".(int)$target['device_id'];
        } elseif (!empty($target['subject_id'])) {
            $type = $target['subject_type'] ?: 'user';
            $sql .= " AND subject_type = '".$this->db->escape($type)."'";
            if ($type === 'account') {
                $sql .= " AND fk_societe_account = ".(int)$target['subject_id'];
            } elseif ($type === 'member') {
                $sql .= " AND fk_adherent = ".(int)$target['subject_id'];
            } else {
                $sql .= " AND fk_user = ".(int)$target['subject_id'];
            }
        } else {
            return []; // No valid target -> never broadcast implicitly
        }

        $resql = $this->db->query($sql);
        $out = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $out[] = [
                    'rowid'      => $obj->rowid,
                    'endpoint'   => $obj->endpoint,
                    'key_p256dh' => $obj->key_p256dh,
                    'key_auth'   => $obj->key_auth,
                ];
            }
        } else {
            dol_syslog('PushSender::getTargetSubscriptions query failed: '.$this->db->lasterror(), LOG_ERR);
        }
        return $out;
    }

    /**
     * VAPID subject: mailto: or https URL. NEVER derived from $_SERVER['HTTP_HOST']
     * (absent in cron/trigger context and spoofable over HTTP).
     */
    private function resolveVapidSubject()
    {
        global $mysoc;

        $configured = getDolGlobalString(self::VAPID_SUBJECT_CONFIG, '');
        if (!empty($configured)) {
            return $configured;
        }
        if (!empty($mysoc->email)) {
            return 'mailto:'.$mysoc->email;
        }
        // Last-resort constant fallback (must be set at install).
        return 'mailto:'.getDolGlobalString('MAIN_INFO_SOCIETE_MAIL', 'admin@localhost');
    }

    private function updateSubscriptionSuccess($id)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_used = '".$this->db->idate(dol_now())."', success_count = success_count + 1, error_count = 0";
        $sql .= " WHERE rowid = ".(int)$id;
        $this->db->query($sql);
    }

    private function updateSubscriptionError($id, $error)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_error = '".$this->db->idate(dol_now())."', error_count = error_count + 1";
        $sql .= ", last_error = '".$this->db->escape(substr((string)$error, 0, 255))."'";
        $sql .= " WHERE rowid = ".(int)$id;
        $this->db->query($sql);

        // Disable after MAX_ERROR_COUNT consecutive failures
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions SET status = 9";
        $sql .= " WHERE rowid = ".(int)$id." AND error_count >= ".self::MAX_ERROR_COUNT;
        $this->db->query($sql);
    }

    private function removeSubscription($id)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions WHERE rowid = ".(int)$id;
        $this->db->query($sql);
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
    // Only needed if the optional 'oauth2' M2M push/send route is wired (cf
    // §4.2). With no HTTP send route, this schema is unused but kept ready.
    'POST:/push/send' => [
        'user_id' => ['type' => InputSanitizer::TYPE_INT],
        'subject_type' => ['type' => InputSanitizer::TYPE_STRING, 'maxLen' => 16],
        'subject_id' => ['type' => InputSanitizer::TYPE_INT],
        'device_id' => ['type' => InputSanitizer::TYPE_INT],
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

    // VAPID keys are GLOBAL (entity 0), not per-tenant. VAPID identifies the
    // push application server, not the Dolibarr entity. Storing at entity 0
    // guarantees that the public route, subscribe and PushSender all read the
    // same key regardless of the entity context they run in -- getDolGlobalString
    // sees entity-0 consts from every entity, so read always matches write.
    const KEY_ENTITY = 0;

    /**
     * Read the VAPID public key WITHOUT generating it.
     *
     * Used by the public GET /push/vapid-public-key route and by PushSender:
     * neither must trigger a DB write. Keys are created at install
     * (modSmartauth::init -> ensureKeys) or via the admin button.
     *
     * @param \DoliDB $db Database connection
     * @return string|null Base64url-encoded public key, or null if absent
     */
    public static function readPublicKey($db)
    {
        $key = getDolGlobalString(self::PUBLIC_KEY_CONFIG, '');
        return $key !== '' ? $key : null;
    }

    /**
     * Read both VAPID keys WITHOUT generating them.
     *
     * @param \DoliDB $db Database connection
     * @return array ['publicKey' => '...', 'privateKey' => '...'] (values may be '')
     */
    public static function readKeys($db)
    {
        return [
            'publicKey'  => getDolGlobalString(self::PUBLIC_KEY_CONFIG, ''),
            'privateKey' => getDolGlobalString(self::PRIVATE_KEY_CONFIG, ''),
        ];
    }

    /**
     * Ensure VAPID keys exist, generating+storing them once if missing.
     *
     * Call this from modSmartauth::init() (install/enable) and from the admin
     * page. Do NOT call it from a public/unauthenticated request path: key
     * generation is a write and must not be triggerable anonymously.
     *
     * @param \DoliDB $db Database connection
     * @return array ['publicKey' => '...', 'privateKey' => '...']
     */
    public static function ensureKeys($db)
    {
        $keys = self::readKeys($db);
        if (empty($keys['publicKey']) || empty($keys['privateKey'])) {
            $keys = self::generateKeys();
            self::storeKeys($db, $keys);
            dol_syslog('VapidKeyHelper::ensureKeys generated new VAPID key pair', LOG_NOTICE);
        }
        return $keys;
    }

    /**
     * Generate new VAPID key pair
     *
     * @return array ['publicKey' => '...', 'privateKey' => '...']
     */
    public static function generateKeys()
    {
        // Generated in-house via openssl (P-256). See VapidKeyHelper for the
        // robust path (fallback to a minimal openssl.cnf on broken hosts).
        // public = base64url(0x04 || X || Y), private = base64url(d).
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($res);
        return [
            'publicKey'  => base64urlEncode("\x04".$d['ec']['x'].$d['ec']['y']),
            'privateKey' => base64urlEncode($d['ec']['d']),
        ];
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
        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        // entity = self::KEY_ENTITY (0) -> global key pair, see const above.
        $result1 = dolibarr_set_const(
            $db,
            self::PUBLIC_KEY_CONFIG,
            $keys['publicKey'],
            'chaine',
            0,
            'VAPID public key for Web Push',
            self::KEY_ENTITY
        );

        $result2 = dolibarr_set_const(
            $db,
            self::PRIVATE_KEY_CONFIG,
            $keys['privateKey'],
            'chaine',
            0,
            'VAPID private key for Web Push',
            self::KEY_ENTITY
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
        // Generate new keys
        $keys = self::generateKeys();

        // Store them
        self::storeKeys($db, $keys);

        // VAPID keys are global (KEY_ENTITY = 0), so regenerating invalidates
        // EVERY subscription across ALL entities, not just the current one.
        // No entity filter here on purpose.
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET status = 9";
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('VapidKeyHelper::regenerateKeys failed to expire subscriptions: '.$db->lasterror(), LOG_ERR);
        }

        return $keys;
    }
}
```

### 6.2 Génération à l'installation (modSmartauth)

Les clés sont générées une fois à l'activation du module, jamais sur une route
publique. Dans `core/modules/modSmartauth.class.php`, méthode `init()` :

```php
// core/modules/modSmartauth.class.php (dans init(), avant le return parent)

dol_include_once('/smartauth/api/VapidKeyHelper.php');
\SmartAuth\Api\VapidKeyHelper::ensureKeys($this->db);
```

`ensureKeys()` est ré-entrant : si les clés existent déjà (réinstall/upgrade),
il ne régénère rien (sinon il invaliderait toutes les subscriptions). La
régénération volontaire passe par `regenerateKeys()` (bouton admin), qui marque
toutes les subscriptions `status=9`.

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

> **Renouvellement de subscription (`pushsubscriptionchange`)** : comme le
> Service Worker ne peut pas ré-enregistrer côté serveur (pas de token, cf.
> section 8), le hook doit écouter le message `push-resubscribe` posté par le
> SW et rejouer l'envoi authentifié :
>
> ```typescript
> useEffect(() => {
>     if (!isSupported) return;
>     const onMessage = (event: MessageEvent) => {
>         if (event.data?.type === 'push-resubscribe') {
>             // token en main ici -> ré-enregistre la nouvelle subscription
>             api.post('/push/subscribe', { subscription: event.data.subscription });
>         }
>     };
>     navigator.serviceWorker.addEventListener('message', onMessage);
>     return () => navigator.serviceWorker.removeEventListener('message', onMessage);
> }, [api, isSupported]);
> ```

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
 *
 * IMPORTANT - limite connue : `POST /push/subscribe` est une route protégée
 * (JWT/OAuth2), or un Service Worker n'a PAS accès au token (il vit hors du
 * contexte applicatif et ne partage pas le localStorage/mémoire de l'app).
 * Un POST direct depuis ici partirait donc sans `Authorization` -> 401, et la
 * subscription renouvelée ne serait jamais ré-enregistrée côté serveur.
 *
 * Stratégie retenue : le SW NE ré-enregistre PAS lui-même. Il marque l'état
 * "subscription périmée" (re-subscribe local, sans appel serveur) et notifie
 * les clients ouverts ; l'app, au prochain foreground, ré-appelle
 * `usePushNotifications().subscribe()` avec le token en main. Si aucun client
 * n'est ouvert, le ré-enregistrement a lieu à la prochaine ouverture de l'app.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            try {
                // Re-subscribe locally (no server call: no token available here)
                const newSubscription = await self.registration.pushManager.subscribe(
                    event.oldSubscription.options
                );

                // Ask any open client to re-register with its auth token
                const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
                for (const client of all) {
                    client.postMessage({
                        type: 'push-resubscribe',
                        subscription: newSubscription.toJSON(),
                        oldEndpoint: event.oldSubscription && event.oldSubscription.endpoint
                    });
                }
                // If no client is open, the app re-registers on next launch
                // (usePushNotifications re-checks the subscription at mount).
            } catch (error) {
                console.error('Failed to renew subscription:', error);
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
        // Silo A internal user -> subject_type 'user'
        return $this->notifySubject('user', (int) $userId, $title, $body, $data, $options);
    }

    /**
     * Send notification to a subject (subject-aware: user/account/member).
     *
     * Goes through PushSender directly -> NO permission gate. There is no
     * self-service HTTP send route; the optional 'oauth2' scope gate in
     * PushController::send() does not apply to internal trigger/cron callers.
     *
     * @param string $subjectType 'user' | 'account' | 'member'
     * @param int    $subjectId   llx_user / llx_societe_account / llx_adherent rowid
     * @param string $title
     * @param string $body
     * @param array  $data        Additional data (url, type, etc.)
     * @param array  $options     Push options (ttl, urgency, tag)
     * @return array ['sent' => int, 'failed' => int]
     */
    public function notifySubject($subjectType, $subjectId, $title, $body, $data = [], $options = [])
    {
        dol_include_once('/smartauth/api/PushSender.php');

        $message = [
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'icon'  => isset($data['icon']) ? $data['icon'] : '/api.php/icon/192',
            'tag'   => isset($options['tag']) ? $options['tag'] : null,
        ];

        $sender = new \SmartAuth\Api\PushSender($this->db);
        list($result, $httpCode) = $sender->send(
            ['subject_type' => $subjectType, 'subject_id' => (int) $subjectId],
            $message,
            $options
        );

        return [
            'sent'   => $result['sent'] ?? 0,
            'failed' => $result['failed'] ?? 0,
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

> **Traductions** : le titre et le corps sont rendus par le Service Worker via
> `showNotification()` (équivalent `textContent`). Utiliser
> `$langs->transnoentities(...)` et **pas** `$langs->trans(...)` : `trans()`
> renvoie des entités HTML (`&eacute;`) qui s'afficheraient littéralement dans
> la notification. Les exemples ci-dessous utilisent `trans()` par concision ;
> en production, remplacer par `transnoentities()`.

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
| `POST /push/subscribe` | Oui (`true`) | Sujet crée ses propres subscriptions |
| `DELETE /push/unsubscribe` | Oui (`true`) | Sujet supprime uniquement ses subscriptions |
| `GET /push/subscriptions` | Oui (`true`) | Sujet voit uniquement ses subscriptions |
| `POST /push/send` | **Non exposé** | Interne via `PushSender`. Si M2M : `'oauth2'` + scope `smartauth:push.send`, jamais `true` (cf §4.1/§4.2) |

### 10.2 Permissions Dolibarr

La permission `smartauth->push_send` n'est utile QUE si une action admin/serveur
appelle l'envoi (ex. un bouton "Envoyer un test" dans l'admin Dolibarr, ou un
script interne) et veut gater ce droit. Elle ne protège PAS une route HTTP
self-service (il n'y en a pas) et ne s'applique PAS aux sujets externes
`acc:`/`mbr:`, qui n'ont aucune permission Dolibarr. Pour un envoi M2M externe,
le contrôle d'accès passe par le scope OAuth2 `smartauth:push.send`, pas par ce
droit. Ne déclarer cette permission que si une UI/action admin l'exige
réellement.

```php
// core/modules/modSmartauth.class.php - droit OPTIONNEL (UI/action admin only)

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
| Payload | ECDH + AES-128-GCM | api/WebPushCrypto.php (openssl + hash_hkdf) |
| Signature | ECDSA (VAPID) | api/WebPushCrypto.php (firebase/php-jwt, ES256) |

Le contenu des notifications est chiffré de bout en bout :
1. Le serveur chiffre avec la clé publique du client (p256dh)
2. Seul le navigateur peut déchiffrer avec sa clé privée
3. Le Push Service (Google, Mozilla, Apple) ne peut pas lire le contenu

### 10.4 Rate limiting

`/push/send` n'étant pas une route HTTP exposée, le rate limit porte sur la
route self-service réellement appelable par un sujet : `POST /push/subscribe`.
Appliquer le `RateLimiter` existant de SmartAuth (par IP + par sujet), comme sur
les autres routes sensibles, pour éviter qu'un client ne crée des subscriptions
en boucle.

```php
// Limites recommandées pour /push/subscribe
'push_subscribe' => [
    'requests_per_minute' => 30,
    'requests_per_hour' => 200
]
```

> Si une route M2M `'oauth2'` `push/send` est ouverte plus tard, lui appliquer
> son propre rate limit (ex. 60/min, 1000/h) au niveau du client OAuth.

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
- Stockage GLOBAL (`entity = 0`, cf §6.1) : VAPID identifie le serveur
  applicatif, pas le tenant. Lecture (route publique, `PushSender`) et écriture
  (`ensureKeys`/`storeKeys`) doivent viser la même entité, sinon la route
  publique lit une clé que le cron/admin n'a pas écrite.
- Jamais exposée via l'API (seule la clé publique l'est)
- Accès limité aux administrateurs via l'interface Dolibarr
- Régénération possible mais invalide TOUTES les subscriptions de toutes les
  entités (clé globale)

### 10.7 Nettoyage des subscriptions expirées

Le module dispose déjà du cron `SmartAuth::doScheduledJob()`
On y branche la purge push plutôt qu'un script
autonome -- pas de nouveau point d'entrée, pas de `master.inc.php` à la main.

```php
// class/smartauth.class.php - à l'intérieur de doScheduledJob()

// Purge des subscriptions push expirées (status=9) ou en échec répété > 7j.
// Compat SQLite (tests) ET MySQL : pas de DATE_SUB(), on calcule la borne en PHP.
$cutoff = $this->db->idate(dol_now() - 7 * 24 * 3600);

$sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
$sql .= " WHERE status = 9";
$sql .= " OR (error_count >= 3 AND date_last_error < '".$this->db->escape($cutoff)."')";

$resql = $this->db->query($sql);
if ($resql) {
    $deleted = (int) $this->db->affected_rows($resql);   // affected_rows attend le RESQL, pas la chaîne SQL
    dol_syslog('SmartAuth::doScheduledJob purged '.$deleted.' expired push subscriptions', LOG_NOTICE);
} else {
    dol_syslog('SmartAuth::doScheduledJob push purge failed: '.$this->db->lasterror(), LOG_ERR);
}

// Purge optionnelle des logs push (RGPD) si la table d'audit est activée.
// Rétention via constante (défaut 30j), même logique de borne calculée en PHP.
if (getDolGlobalInt('SMARTAUTH_PUSH_LOGS_RETENTION_DAYS', 0) > 0) {
    $days = getDolGlobalInt('SMARTAUTH_PUSH_LOGS_RETENTION_DAYS', 30);
    $logCutoff = $this->db->idate(dol_now() - $days * 24 * 3600);
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_logs";
    $sql .= " WHERE date_creation < '".$this->db->escape($logCutoff)."'";
    $resql = $this->db->query($sql);
    if (!$resql) {
        dol_syslog('SmartAuth::doScheduledJob push_logs purge failed: '.$this->db->lasterror(), LOG_ERR);
    }
}
```

> L'ancien `$db->affected_rows($sql)` (sur la chaîne SQL) était un bug :
> `affected_rows()` prend le **resql** retourné par `query()`. Corrigé ci-dessus.

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
     *
     * UPSERT: an existing endpoint is re-bound to the current subject and
     * returns 200 (update), never 409.
     */
    public function subscribe_withDuplicateEndpoint_rebindsAndReturns200()
    {
        $input = [
            'subscription' => [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/existing',
                'keys' => ['p256dh' => 'BNcRdreALRFX', 'auth' => 'tBHItJI5svbpez7']
            ]
        ];

        $this->dbMock->method('query')->willReturn(true);
        $this->dbMock->method('num_rows')->willReturn(1);
        $this->dbMock->method('fetch_object')->willReturn((object)['rowid' => 99]);

        $result = $this->controller->subscribe($input);

        $this->assertEquals(200, $result[1]);
        $this->assertEquals(99, $result[0]['id']);
    }

    /**
     * @test
     *
     * Endpoint must be a valid HTTPS URL.
     */
    public function subscribe_withNonHttpsEndpoint_returnsBadRequest()
    {
        $input = [
            'subscription' => [
                'endpoint' => 'http://insecure.example.com/push/1',
                'keys' => ['p256dh' => 'BNcRdreALRFX', 'auth' => 'tBHItJI5svbpez7']
            ]
        ];

        $result = $this->controller->subscribe($input);

        $this->assertEquals(400, $result[1]);
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
        global $db;

        // Sending is internal: exercise PushSender directly, NOT an HTTP route.
        $subscriptionId = $this->createTestSubscription();

        // Note: actual push won't work without real browser subscription.
        // This tests the engine flow, not actual delivery.
        $sender = new \SmartAuth\Api\PushSender($db);
        list($result, $code) = $sender->send(
            ['subscription_id' => $subscriptionId],
            ['title' => 'Test notification', 'body' => 'This is a test'],
            []
        );

        // Will fail with invalid subscription but should not throw.
        $this->assertContains($code, [200, 207]);
    }

    /**
     * @test
     *
     * /push/send must NOT be reachable by a self-service JWT subject. With no
     * HTTP send route registered, the router resolves nothing -> 404. If an
     * 'oauth2' route is later wired, a JWT end-user token (no push.send scope)
     * must still be refused (403 insufficient_scope), never allowed.
     */
    public function sendNotification_viaHttpAsEndUser_isRefused()
    {
        $this->loginAsUser();

        $response = $this->apiPost('/push/send', [
            'user_id' => 1,
            'title' => 'Hijack attempt',
            'body' => 'Should never be delivered',
        ]);

        $this->assertContains($response['code'], [403, 404]);
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
| PushSender | Oui | Oui | 85% |
| VapidKeyHelper | Oui | - | 80% |
| PushNotificationService | Oui | Oui | 85% |
| usePushNotifications | Oui | - | 80% |
| Service Worker | - | Oui (E2E) | 70% |

### 11.6 Commandes de test

Le module utilise les configurations PHPUnit dédiées (cf. `~/docs/TESTING.md`),
pas un alias `composer test-integration`.

```bash
# Tests unitaires push (mock Dolibarr)
vendor/bin/phpunit --filter Push

# Tests intégration (vrai Dolibarr + SQLite en RAM)
vendor/bin/phpunit -c phpunit-integration-dolibarr.xml --filter Push

# Tests frontend (smartcommon)
cd ~/dev/smartcommon && npm test -- --testPathPattern=Push
```

---

## Annexes

### A. Checklist d'implémentation

- [ ] Web Push interne via `api/WebPushCrypto.php` (aucune dépendance externe ; openssl + hash_hkdf + firebase/php-jwt)
- [ ] Créer les fichiers SQL : `llx_*.sql` (CREATE) + `llx_*.key.sql` (INDEX), subject-aware
- [ ] Implémenter VapidKeyHelper (`readPublicKey`/`readKeys`/`ensureKeys`/`regenerateKeys`)
- [ ] Brancher `VapidKeyHelper::ensureKeys()` dans `modSmartauth::init()`
- [ ] Implémenter PushController (subscribe/unsubscribe/list/getVapidPublicKey ; PAS de send self-service)
- [ ] Implémenter PushSender (moteur d'envoi, sans permission, réutilisable cron/triggers)
- [ ] Ajouter les routes dans `api/LocalRoutes.php` (subscribe/unsubscribe/list/vapid-public-key ; AUCUNE route send par défaut)
- [ ] Rate-limiter `push/subscribe` via `RateLimiter` (cf §10.4)
- [ ] Ajouter les schémas de validation
- [ ] (Optionnel) droit `push_send` UNIQUEMENT si une action admin d'envoi l'exige (cf §10.2)
- [ ] (Optionnel) route M2M `push/send` en `'oauth2'` + scope `smartauth:push.send` si envoi serveur-à-serveur requis
- [ ] Brancher la purge push dans `SmartAuth::doScheduledJob()`
- [ ] Créer le hook usePushNotifications dans smartcommon (+ listener `push-resubscribe`)
- [ ] Ajouter les handlers push au Service Worker smartboot (dont `pushsubscriptionchange`)
- [ ] Créer PushNotificationService pour les triggers (subject-aware, via PushSender)
- [ ] Écrire les tests (PushController, PushSender, VapidKeyHelper, hook)
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

### D. Implémentation Web Push interne (suppression de minishlink/web-push)

Historique : le module dépendait de `minishlink/web-push ^6.0`, qui tirait
guzzle (transport HTTP) et la chaîne `web-token/jwt-* ^2.0` + `fgrosse/phpasn1`
(JWT VAPID). Sous contrainte PHP 7.4, ces `web-token` v2 / `phpasn1` étaient
abandonnés sans remplacement possible (leur successeur `web-token/jwt-library`
exige PHP 8.1+). À eux seuls, web-push et ses dépendances pesaient ~2,6 MB, et
guzzle ~1,34 MB n'était utilisé que par web-push.

**Décision (révision 1.2.0) :** réécrire l'envoi Web Push en interne plutôt que
de subir cette pile. C'est `api/WebPushCrypto.php`, qui n'utilise que des briques
natives PHP 7.4 :
- `ext-openssl` : génération de clé éphémère P-256, ECDH (`openssl_pkey_derive`),
  chiffrement `aes-128-gcm`.
- `hash_hkdf` (PHP >= 7.1) : dérivation HKDF (IKM / CEK / NONCE).
- `firebase/php-jwt` (déjà embarqué) : signature ES256 du JWT VAPID.
- Transport : `getURLContent()` de Dolibarr (`POSTALREADYFORMATED`), qui respecte
  en prime la conf proxy/TLS (`MAIN_PROXY_*`), contrairement à guzzle.

**Résultat :**
- Vendor livré (`composer i --no-dev`) : de ~5 MB à ~76 Ko (juste
  `firebase/php-jwt`). Plus aucun paquet abandonné.
- Aucune nouvelle dépendance, compatibilité PHP 7.4 conservée.
- Format de stockage des clés VAPID inchangé (`base64url(0x04||X||Y)` /
  `base64url(d)`) : les clés existantes continuent de fonctionner.

**Conformité / tests :** `WebPushCrypto::encryptPayload()` produit exactement le
corps du **vecteur de test de la RFC 8291 (section 5)** (clé éphémère + sel
injectés) -- preuve d'interopérabilité. Couvert par
`test/phpunit/unit/WebPushCryptoTest.php` (vecteur RFC + round-trip
chiffrement/déchiffrement + signature/vérification VAPID ES256).

**Note de sécurité dépendance (`firebase/php-jwt`) :** épinglé à
`>=6.0 <6.10.1`, ce qui résout sur **v6.10.0** -- la **dernière version
compatible PHP 7.4** (6.10.1+ exige PHP 8.0). Ce bump corrige
**CVE-2021-46743** (confusion d'algorithme, la plus sérieuse, corrigée en 6.0.0).
Reste **CVE-2025-45769** ("weak encryption", gravité faible CVSS 2.7), dont le
correctif n'arrive qu'en **v7.0.0 -> PHP 8.0+** : irrémédiable tant que le module
cible PHP 7.4. `composer audit` la listera donc encore (attendu et assumé).
Déclencheur : le jour où le plancher passe à PHP 8.0+, bumper vers `^7` pour la
solder (migration code indolore -- l'API `Key`/`decode` v6/v7 est déjà utilisée).
NB : on ne peut pas poser `config.platform.php=7.4` dans `composer.json` car la
dépendance de dev `cap-rel/dolibarr-integration-sqlite` exige PHP 8.2 ; d'où le
pin de version explicite plutôt qu'un plancher de plateforme.
