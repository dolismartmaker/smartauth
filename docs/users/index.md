---
title: "SmartAuth"
weight: 1
description: "Module Dolibarr d'authentification et d'autorisation pour les applications mobiles SmartMaker, avec serveur OAuth2/OIDC intégré."
category: "Sécurité"
type: "module-dolibarr"
---

# SmartAuth

## Présentation

**SmartAuth** est un module Dolibarr qui fournit un système complet d'authentification et d'autorisation. Il sert de composant central de l'écosystème SmartMaker pour connecter des applications mobiles, des services tiers et des systèmes machine-à-machine à votre ERP Dolibarr.

## Trois modes d'authentification

### API JWT (applications mobiles)

SmartAuth génère des jetons JWT (access token + refresh token) pour les applications mobiles SmartMaker. Chaque appareil connecté forme une famille de jetons indépendante, ce qui permet de révoquer un appareil sans affecter les autres.

### OAuth2/OIDC (Single Sign-On)

SmartAuth peut fonctionner comme fournisseur d'identité (IdP) pour le SSO. Il implémente le protocole OAuth2 avec OpenID Connect, ce qui permet à des applications tierces (WordPress, Nextcloud, etc.) de déléguer l'authentification à Dolibarr.

### Client Credentials (machine-à-machine)

Pour les communications entre serveurs, SmartAuth prend en charge le grant type `client_credentials` (RFC 6749 Section 4.4). Ce mode ne nécessite aucune interaction utilisateur.

## Fonctionnalités principales

- **Tableau de bord** -- Vue d'ensemble des jetons actifs, utilisateurs connectés, blocages rate limit et taux de succès des rafraîchissements
- **Gestion des jetons** -- Liste, consultation et révocation des jetons JWT actifs
- **Gestion des appareils** -- Suivi des périphériques enregistrés avec possibilité de renommer ou révoquer
- **Journaux d'authentification** -- Historique complet des connexions et actions API
- **Serveur OAuth2/OIDC** -- Endpoints standards (authorization, token, userinfo, discovery, revocation)
- **Clients OAuth** -- Création et gestion de clients OAuth2 (confidentiels ou publics avec PKCE)
- **Rate limiting** -- Protection contre les attaques par force brute (par IP et par utilisateur)
- **Familles de jetons** -- Isolation par appareil avec révocation en cascade
- **Détection d'attaques replay** -- Révocation automatique en cas de réutilisation d'un refresh token
- **GeoIP** -- Géolocalisation optionnelle des adresses IP de connexion
- **Alertes de sécurité** -- Détection automatique des tentatives de brute force et DDoS
- **PWA dynamique** -- Manifest et icônes PWA générés dynamiquement pour les modules SmartMaker
- **Tâche planifiée** -- Nettoyage automatique des jetons et journaux périmés
- **Onglet utilisateur** -- Consultation des jetons et de l'historique directement depuis la fiche utilisateur Dolibarr

![Tableau de bord SmartAuth avec les indicateurs clés de sécurité et les jetons actifs](screenshots/tableau-de-bord.webp)

## Prérequis

- Dolibarr 17.0 ou supérieur
- PHP 7.0 ou supérieur

## Éditeur

SmartAuth est développé par [CAP-REL](https://cap-rel.fr/).

## Support

- Support disponible via le [site CAP-REL](https://cap-rel.fr/)
- Mises à jour régulières
