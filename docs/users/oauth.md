---
title: "Serveur OAuth2/OIDC"
weight: 35
description: "Configuration et utilisation du serveur OAuth2/OIDC intégré à SmartAuth pour le SSO et l'authentification machine-à-machine."
---

# Serveur OAuth2/OIDC

SmartAuth intègre un serveur OAuth2 compatible OpenID Connect. Il permet d'utiliser Dolibarr comme fournisseur d'identité (Identity Provider) central pour le Single Sign-On (SSO) avec des applications tierces.

## Activation

1. Allez dans **Accueil > Configuration > Modules > SmartAuth**
2. Ouvrez l'onglet **Serveur OAuth**
3. Cochez **Activer le serveur OAuth/OIDC**
4. Cliquez sur **Enregistrer**

![Page de configuration du serveur OAuth2/OIDC dans SmartAuth](screenshots/configuration-oauth.webp)

## Paramètres du serveur

### URL de l'issuer

L'URL de l'issuer identifie votre serveur OAuth. Si vous laissez ce champ vide, SmartAuth utilise l'URL auto-détectée de votre installation Dolibarr.

### Durées des jetons

| Paramètre | Description | Valeur par défaut |
|-----------|-------------|-------------------|
| Durée access token | Durée de validité d'un access token OAuth, en secondes | 3600 (1 heure) |
| Durée refresh token | Durée de validité d'un refresh token OAuth, en secondes | 2592000 (30 jours) |
| Durée code d'autorisation | Durée de validité d'un code d'autorisation, en secondes | 600 (10 minutes) |

### Sécurité

| Paramètre | Description |
|-----------|-------------|
| PKCE obligatoire pour clients publics | Recommandé pour les applications mobiles et les Single Page Applications (SPA). Activé par défaut. |
| Mémoriser les consentements | Évite de redemander le consentement à chaque connexion pour un même utilisateur et un même client. |

## Endpoints OIDC

Lorsque le serveur OAuth est activé, les endpoints suivants sont disponibles :

| Endpoint | URL |
|----------|-----|
| Discovery | `/.well-known/openid-configuration` |
| JWKS | `/.well-known/jwks.json` |
| Authorization | `/oauth/authorize` |
| Token | `/oauth/token` |
| Userinfo | `/oauth/userinfo` |
| Revocation | `/oauth/revoke` |
| End Session | `/oauth/logout` |

Ces URLs sont affichées directement dans l'onglet de configuration du serveur OAuth après activation.

## Gestion des clients OAuth

### Créer un client

1. Depuis l'onglet **Serveur OAuth**, cliquez sur **Clients OAuth** ou accédez directement à la liste des clients
2. Cliquez sur **Nouveau client**
3. Renseignez les informations du client :

![Formulaire de création d'un client OAuth avec les champs principaux](screenshots/creation-client-oauth.webp)

| Champ | Description |
|-------|-------------|
| Nom | Nom du client (exemple : "Mon application WordPress") |
| URIs de redirection | Une URI par ligne. Doit être une URL valide en HTTPS. |
| Scopes autorisés | Scopes que le client peut demander (openid, profile, email, groups, offline_access) |
| Grant types autorisés | Types d'autorisation : Authorization Code (apps web), Refresh Token, Client Credentials (machine-à-machine) |
| Type de client | Confidentiel (application serveur avec secret) ou Public (application mobile/SPA avec PKCE) |
| PKCE obligatoire | Recommandé pour tous les clients |

### Scopes disponibles

| Scope | Description |
|-------|-------------|
| `openid` | Requis pour OpenID Connect |
| `profile` | Profil utilisateur (nom, etc.) |
| `email` | Adresse email |
| `groups` | Groupes utilisateur |
| `offline_access` | Accès hors ligne (refresh token) |

Des scopes personnalisés peuvent être enregistrés par les modules externes.

### Client confidentiel ou public

- **Client confidentiel** -- Application serveur capable de protéger un secret (exemple : backend WordPress, Nextcloud). Un secret est généré et affiché une seule fois lors de la création.
- **Client public (PKCE)** -- Application mobile ou SPA qui ne peut pas stocker un secret en toute sécurité. L'authentification utilise PKCE (Proof Key for Code Exchange) à la place.

> **Attention** : Le secret client est affiché une seule fois lors de la création. Copiez-le et conservez-le dans un endroit sécurisé. Il ne peut pas être récupéré ultérieurement. Vous pouvez toutefois le régénérer si nécessaire.

### Gérer un client existant

Depuis la liste des clients OAuth, vous pouvez :

- **Consulter** -- Voir les détails du client, ses jetons actifs et le dernier jeton émis
- **Modifier** -- Mettre à jour les URIs de redirection, les scopes et les grants autorisés
- **Activer/Désactiver** -- Activer ou désactiver temporairement un client
- **Régénérer le secret** -- Générer un nouveau secret (l'ancien est immédiatement invalidé)
- **Supprimer** -- Supprimer le client et révoquer tous ses jetons

## Client Credentials (machine-à-machine)

Le grant type `client_credentials` permet l'authentification entre serveurs sans interaction utilisateur.

### Configuration

1. Créez un client OAuth de type **confidentiel**
2. Activez le grant type **Client Credentials**
3. Définissez un **utilisateur de service** sur la fiche du client

L'utilisateur de service détermine les permissions et l'entité pour les opérations effectuées par le client. Si aucun utilisateur de service n'est défini sur le client, SmartAuth utilise l'utilisateur par défaut global (constante SMARTAUTH_DEFAULT_USER).

### Fonctionnement

1. Le client envoie `grant_type=client_credentials` avec son `client_id` et `client_secret`
2. SmartAuth valide les identifiants et les scopes demandés
3. SmartAuth retourne un `access_token` JWT (pas de refresh token ni d'id_token en mode client credentials)

## Intégration Dolibarr (SSO)

SmartAuth peut servir de fournisseur d'authentification pour une autre instance Dolibarr.

### Création du client Dolibarr

1. Allez dans l'onglet **Serveur OAuth**
2. Dans la section **Intégration Dolibarr**, cliquez sur **Créer le client Dolibarr**
3. Indiquez l'URI de redirection de votre instance Dolibarr cible

### Configuration de l'instance Dolibarr cible

Après la création du client, l'onglet affiche les instructions de configuration :

1. Copiez le fichier d'authentification dans l'instance Dolibarr cible :

    ```bash
    cp htdocs/custom/smartauth/core/login/functions_smartauthoauth.php \
       /chemin/vers/dolibarr/htdocs/core/login/
    ```

2. Modifiez le fichier `conf.php` de l'instance cible :

    ```php
    $dolibarr_main_authentication = 'smartauthoauth,dolibarr';
    ```

3. Configurez les constantes Dolibarr sur l'instance cible :

    | Constante | Valeur |
    |-----------|--------|
    | `SMARTAUTH_OAUTH_ISSUER` | URL de votre serveur SmartAuth |
    | `SMARTAUTH_OAUTH_CLIENT_ID` | `dolibarr-erp` |

### Test de connexion

Depuis l'onglet **Serveur OAuth**, utilisez le bouton **Tester la connexion** pour vérifier que le serveur SmartAuth est accessible via l'endpoint de discovery.
