---
title: "GeoIP"
weight: 40
description: "Configuration de la géolocalisation GeoIP pour identifier l'origine géographique des connexions."
---

# GeoIP

SmartAuth peut utiliser la base de données GeoIP pour géolocaliser les adresses IP de connexion. Cette fonctionnalité est optionnelle.

## Installation

1. Allez dans **Accueil > Configuration > Modules > SmartAuth**
2. Ouvrez l'onglet **GeoIP**
3. Cliquez sur **Télécharger et initialiser GeoIP**

![Page de configuration GeoIP avec le bouton de téléchargement](screenshots/configuration-geoip.webp)

SmartAuth télécharge automatiquement la base de données GeoLite2-City et configure les constantes Dolibarr nécessaires :

- `GEOIP_VERSION` est défini à 1
- `MAIN_MODULE_GEOIPMAXMIND` est activé
- `GEOIPMAXMIND_COUNTRY_DATAFILE` pointe vers le fichier téléchargé

Le fichier est stocké dans `DOL_DATA_ROOT/geoipmaxmind/GeoLite2-City.mmdb`.

## Vérification

Après le téléchargement, la page affiche un message de confirmation indiquant que le fichier est installé et que la configuration est terminée.

## Utilisation

Une fois GeoIP activé, les adresses IP des connexions peuvent être associées à un pays et une ville dans les journaux et le tableau de bord.
