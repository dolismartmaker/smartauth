---
title: "Configuration"
weight: 20
description: "Paramétrage général du module SmartAuth : jetons, journaux, proxies de confiance et mode debug."
---

# Configuration

La configuration du module est accessible depuis **Accueil > Configuration > Modules > SmartAuth**. Elle est organisée en quatre onglets.

## Paramètres généraux

L'onglet **Paramètres** regroupe les réglages principaux du module.

![Page de configuration des paramètres généraux SmartAuth](screenshots/configuration-parametres.webp)

### Durée de vie des jetons

| Option | Description |
|--------|-------------|
| SMARTAUTH_TOKEN_EOL_DAYS | Délai (en jours) au bout duquel un jeton est périmé. Valeurs possibles : 10, 20, 30 ou 90 jours. Par défaut : 30 jours. |

Les jetons périmés sont automatiquement nettoyés par la tâche planifiée si le nettoyage est activé.

### Utilisateur par défaut

| Option | Description |
|--------|-------------|
| SMARTAUTH_DEFAULT_USER | Utilisateur Dolibarr à utiliser pour les actions anonymes. Sert également de fallback global pour les appels machine-à-machine (client credentials) si aucun utilisateur de service n'est défini sur le client OAuth. |

### Mode debug

| Option | Description |
|--------|-------------|
| SMARTAUTH_DEBUG | Active les logs de débogage dans le fichier `dolibarr.log`. Utile pour diagnostiquer les problèmes d'authentification, de jetons ou de PWA. Désactivez cette option en production. |

### Journaux (logs)

| Option | Description |
|--------|-------------|
| SMARTAUTH_COLLECT_LOGS | Active la collecte des journaux web. Chaque requête sur l'API est consignée en base de données. Vous consultez l'historique des actions réalisées via l'API depuis le menu **Outils > SmartAuth > Journaux (Logs)**. |
| SMARTAUTH_CLEAN_LOGS | Active le nettoyage automatique des journaux par la tâche planifiée. |
| SMARTAUTH_LAST_LOGS | Durée de conservation des journaux si le nettoyage automatique est actif. Valeurs possibles : 10, 20, 30, 90, 180 ou 365 jours. |

### Proxies de confiance

| Option | Description |
|--------|-------------|
| SMARTAUTH_TRUSTED_PROXIES | Liste des adresses IP de vos proxies de confiance, séparées par des virgules (exemple : `10.0.0.1,172.17.0.1`). Lorsqu'une requête provient d'une de ces adresses, l'en-tête `X-Forwarded-For` est utilisé pour déterminer l'adresse IP réelle du client. Les adresses IP privées (127.x, 10.x, 172.16-31.x, 192.168.x) sont automatiquement considérées comme de confiance. Laissez vide si vous n'utilisez pas de reverse proxy avec une adresse IP publique. |

## Tâche planifiée

SmartAuth enregistre une tâche planifiée (cron) qui s'exécute une fois par jour. Elle effectue le nettoyage des jetons expirés et des journaux anciens (si le nettoyage automatique est activé).

Pour vérifier ou modifier la fréquence d'exécution :

1. Allez dans **Accueil > Configuration > Tâches planifiées**
2. Recherchez la tâche "SmartAuth"
3. Activez-la si elle ne l'est pas déjà

> **Attention** : La tâche planifiée est désactivée par défaut. Activez-la manuellement après l'installation du module.
