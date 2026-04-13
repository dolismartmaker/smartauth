---
title: "Utilisation"
weight: 30
description: "Utilisation quotidienne de SmartAuth : tableau de bord, gestion des jetons, appareils, journaux et onglet utilisateur."
---

# Utilisation

Le module SmartAuth est accessible depuis le menu **Outils > SmartAuth** dans le menu latéral gauche de Dolibarr.

## Tableau de bord

La page d'accueil du module affiche un tableau de bord avec quatre indicateurs clés :

- **Jetons actifs** -- Nombre total de jetons (access + refresh) actuellement valides, avec la variation sur 7 jours
- **Utilisateurs connectés** -- Nombre d'utilisateurs distincts ayant au moins un jeton actif
- **Blocages rate limit** -- Nombre de tentatives bloquées dans les dernières 24 heures et nombre d'adresses IP concernées
- **Taux de succès refresh** -- Pourcentage de rafraîchissements de jetons réussis dans les dernières 24 heures

![Tableau de bord SmartAuth avec les quatre indicateurs de sécurité](screenshots/tableau-de-bord.webp)

### Alertes de sécurité

Le tableau de bord affiche automatiquement des alertes lorsqu'il détecte :

- **Attaques replay** -- Des familles de jetons ont été révoquées suite à une réutilisation suspecte d'un refresh token
- **Tentatives DDoS** -- Une adresse IP a effectué plus de 50 tentatives échouées en une heure
- **Brute force** -- Un compte utilisateur a subi plus de 10 tentatives de connexion échouées en une heure

### Utilisateurs principaux

Le tableau affiche les 10 utilisateurs les plus actifs avec le nombre de jetons, le nombre d'appareils et la date de dernière activité.

### Liens rapides

Le tableau de bord propose des accès directs vers :

- La gestion des jetons
- Les journaux
- La configuration

### Statistiques complémentaires

La partie droite du tableau de bord affiche :

- **Blocages récents** -- Les derniers blocages par rate limiting avec l'adresse IP, l'action et le délai
- **Statistiques des familles de jetons** -- Familles actives, familles révoquées, moyenne de rafraîchissements et attaques replay détectées

## Gestion des jetons

Accessible depuis **Outils > SmartAuth > Jetons**, cette page liste tous les jetons d'authentification actifs.

![Liste des jetons actifs avec les informations de connexion](screenshots/liste-jetons.webp)

Pour chaque jeton, vous consultez :

- L'identifiant du jeton
- L'application associée
- L'utilisateur ou la société liée
- La date de création
- La date de dernière utilisation
- L'adresse IP de la dernière connexion
- La date de péremption

### Révoquer un jeton

Pour révoquer un jeton et déconnecter l'appareil associé :

1. Repérez le jeton dans la liste
2. Cliquez sur l'action de révocation
3. Confirmez la révocation

La révocation est immédiate : l'appareil est déconnecté et devra se ré-authentifier.

## Gestion des appareils

Accessible depuis **Outils > SmartAuth > Périphériques**, cette page liste tous les appareils enregistrés.

![Liste des périphériques enregistrés dans SmartAuth](screenshots/liste-peripheriques.webp)

Pour chaque appareil, vous pouvez :

- **Renommer** -- Attribuer un nom personnalisé pour faciliter l'identification (exemple : "Téléphone de Jean", "Tablette entrepôt")
- **Consulter l'historique** -- Voir les dernières activités de l'appareil
- **Révoquer** -- Déconnecter l'appareil et invalider tous ses jetons

## Journaux (logs)

Accessible depuis **Outils > SmartAuth > Journaux (Logs)**, cette page affiche l'historique des connexions et des appels API.

![Liste des journaux d'authentification avec les détails de chaque requête](screenshots/liste-journaux.webp)

Les journaux contiennent :

- La date et l'heure de la requête
- La méthode HTTP et le point d'accès (endpoint)
- Le statut de la réponse
- L'utilisateur associé
- L'adresse IP source

> **Attention** : La collecte des journaux doit être activée dans la [configuration](/smartauth/configuration) (option SMARTAUTH_COLLECT_LOGS) pour que les entrées apparaissent.

## Onglet utilisateur

SmartAuth ajoute un onglet **SmartAuth** dans la fiche de chaque utilisateur Dolibarr.

![Onglet SmartAuth dans la fiche utilisateur montrant les jetons et l'historique](screenshots/onglet-utilisateur.webp)

Cet onglet affiche :

- Les derniers jetons d'API de l'utilisateur avec un lien vers la liste complète
- L'historique des connexions réalisées par cet utilisateur
