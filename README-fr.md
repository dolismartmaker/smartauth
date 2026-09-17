# SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Fonctionnalités

SmartAuth fait de votre Dolibarr le point d'authentification unique de tout ce
qui gravite autour : applications mobiles, services tiers et échanges entre
serveurs s'authentifient sur les comptes de l'ERP, sans annuaire séparé à tenir.

Quatre façons de s'authentifier :

- **API JWT** pour les applications mobiles, avec un couple jeton d'accès /
  jeton de rafraîchissement par appareil
- **OAuth2 / OpenID Connect** pour le SSO : Dolibarr devient le fournisseur
  d'identité d'applications tierces (WordPress, Nextcloud, etc.)
- **Client credentials** pour les échanges machine-à-machine, sans aucune
  interaction utilisateur
- **Couplage par QR code** : un poste Dolibarr déjà connecté affiche un QR
  éphémère, le mobile le scanne et reçoit ses jetons après confirmation du
  poste, sans jamais saisir de mot de passe

Ce que vous pilotez depuis l'ERP :

- **Tableau de bord** des jetons actifs, des utilisateurs connectés, des
  blocages et du taux de succès des rafraîchissements
- **Jetons et appareils** listés, consultables et révocables un par un, chaque
  appareil formant une famille isolée : en révoquer un ne déconnecte pas les
  autres
- **Journaux d'authentification** retraçant connexions et appels d'API
- **Clients OAuth2** confidentiels ou publics avec PKCE, créés et gérés depuis
  les pages d'administration
- **Limitation de débit** contre la force brute, par adresse IP et par
  utilisateur, avec détection des attaques par rejeu de jeton
- **Alerte de nouvelle connexion** envoyée par courriel quand un compte est
  utilisé depuis une adresse IP ou un appareil inconnus
- **Géolocalisation GeoIP** optionnelle des adresses de connexion
- **Onglet SmartAuth** sur la fiche utilisateur, pour voir et couper ses
  sessions sans quitter sa page
- **Inscription et récupération de mot de passe** en autonomie, avec validation
  de l'adresse courriel
- **Nettoyage automatique** des jetons et journaux périmés par tâche planifiée

Vous trouverez nos autres modules sur [Dolistore.com](https://www.dolistore.com/index.php?controller=search&orderby=position&orderway=desc&tag=&website=marketplace&search_query=cap-rel&submit_search=).

## Prérequis

- Dolibarr 18.0 ou supérieur
- PHP 7.4 ou supérieur

## Documentation

Documentation utilisateur : [doc.cap-rel.fr/smartauth](https://doc.cap-rel.fr/smartauth/)

## SAV / Aide / Support

Toute demande d'aide / support / sav doit passer par la procédure suivante :

[https://cap-rel.fr/sav-module-dolibarr/](https://cap-rel.fr/sav-module-dolibarr/)

## Licence

### Code principal

Le code est couvert par la licence GNU/GPLv3 ou toute version ultérieure.

See file COPYING for more information.

### Documentation

Toute la documentation est sous licence GNU/GFDL.
