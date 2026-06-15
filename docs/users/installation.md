---
title: "Installation"
weight: 10
description: "Téléchargement, installation et activation du module SmartAuth pour Dolibarr."
---

# Installation

## Téléchargement

Téléchargez le module depuis le [DoliStore](https://www.dolistore.com/) ou depuis votre espace client CAP-REL.

## Installation du module

1. Décompressez l'archive téléchargée
2. Copiez le dossier `smartauth` dans le répertoire `htdocs/custom/` de votre installation Dolibarr
3. Installez les dépendances Composer :

    ```bash
    cd htdocs/custom/smartauth
    composer install --no-dev
    ```

## Activation

1. Connectez-vous en tant qu'administrateur Dolibarr
2. Allez dans **Accueil > Configuration > Modules/Applications**
3. Recherchez "Smartauth" dans la liste des modules
4. Cliquez sur le bouton d'activation

![Activation du module SmartAuth dans la liste des modules Dolibarr](screenshots/activation-module.webp)

Le module crée automatiquement les tables nécessaires en base de données lors de l'activation.

## Vérification

Après activation, le menu **Outils > SmartAuth** apparaît dans le menu latéral gauche. Vous accédez au tableau de bord du module.

## Accès à la configuration

Rendez-vous dans **Accueil > Configuration > Modules > SmartAuth** pour accéder aux différents onglets de configuration :

- **Paramètres** -- Configuration générale (durée de vie des jetons, utilisateur par défaut, journaux, debug)
- **Serveur OAuth** -- Activation et configuration du serveur OAuth2/OIDC
- **GeoIP** -- Téléchargement et activation de la base GeoIP pour la géolocalisation
- **À propos** -- Informations sur le module et sa version

## Permissions

Le module définit une permission de lecture. Attribuez-la aux utilisateurs qui doivent accéder aux fonctionnalités SmartAuth :

1. Allez dans **Accueil > Utilisateurs & Groupes**
2. Ouvrez la fiche de l'utilisateur concerné
3. Dans l'onglet **Permissions**, activez la permission "Read objects of SmartAuth"

> **Attention** : Seuls les administrateurs Dolibarr ont accès aux pages de configuration du module.

## Portail SSO public (optionnel)

Si vous activez le serveur OAuth2/OIDC, il est fortement recommandé d'exposer le portail SSO public (`htdocs/custom/smartauth/public/`) sur un sous-domaine dédié (exemple : `https://auth.exemple.fr`). Ce portail sert la page de connexion, l'inscription, la réinitialisation de mot de passe et les endpoints OIDC (`/.well-known/openid-configuration`, `/.well-known/jwks.json`, `/oauth/*`).

Voir la section [Portail SSO public](oauth.md#portail-sso-public) du chapitre OAuth2/OIDC pour la configuration complète du vhost Apache, l'exception `/.well-known/` à ajouter, et les constantes Dolibarr à définir (`SMARTAUTH_APP_URL`, `SMARTAUTH_REGISTRATION_ENABLED`, `SMARTAUTH_ACCOUNT_ENABLED`).
