---
title: "FAQ"
weight: 50
description: "Questions fréquentes sur le module SmartAuth pour Dolibarr."
---

# FAQ

## Questions générales

### À quoi sert SmartAuth ?

SmartAuth gère l'authentification et l'autorisation pour les applications mobiles SmartMaker et les services tiers qui se connectent à votre Dolibarr. Il fournit des jetons JWT pour les applications mobiles et un serveur OAuth2/OIDC pour le Single Sign-On.

### SmartAuth remplace-t-il l'authentification native de Dolibarr ?

Non. SmartAuth fonctionne en complément de l'authentification native. L'accès à l'interface web de Dolibarr continue d'utiliser le système de connexion standard. SmartAuth gère uniquement l'authentification des applications externes (mobiles, API, services tiers).

### Quelles versions de Dolibarr sont compatibles ?

SmartAuth nécessite Dolibarr 17.0 ou supérieur et PHP 7.0 ou supérieur.

## Jetons et appareils

### Qu'est-ce qu'une famille de jetons ?

Chaque appareil qui se connecte via SmartAuth crée une famille de jetons. Cette famille regroupe l'access token et le refresh token de l'appareil. La révocation d'une famille déconnecte uniquement cet appareil, sans affecter les autres.

### Pourquoi un jeton a-t-il été automatiquement révoqué ?

SmartAuth détecte les attaques replay. Si un refresh token déjà utilisé est présenté une seconde fois, toute la famille de jetons est immédiatement révoquée. Ce mécanisme protège contre le vol de jetons.

### Comment révoquer tous les jetons d'un utilisateur ?

Rendez-vous dans la fiche de l'utilisateur Dolibarr, onglet **SmartAuth**. Vous visualisez tous les jetons actifs et pouvez les révoquer individuellement. Pour une révocation massive, utilisez la liste des jetons depuis **Outils > SmartAuth > Jetons**.

### Quelle est la durée de vie d'un jeton ?

Par défaut, les jetons expirent après 30 jours. Cette durée est configurable dans les paramètres du module (10, 20, 30 ou 90 jours).

## Serveur OAuth2/OIDC

### Faut-il activer le serveur OAuth pour utiliser les applications mobiles SmartMaker ?

Non. Les applications mobiles SmartMaker utilisent l'authentification JWT, qui fonctionne indépendamment du serveur OAuth. Le serveur OAuth est nécessaire uniquement pour le SSO avec des applications tierces ou l'authentification machine-à-machine.

### Quelle est la différence entre un client confidentiel et un client public ?

Un client confidentiel est une application serveur capable de protéger un secret (exemple : WordPress, Nextcloud). Un client public est une application mobile ou une Single Page Application (SPA) qui ne peut pas stocker de secret de manière sécurisée et utilise PKCE à la place.

### Comment connecter WordPress ou Nextcloud à Dolibarr via SmartAuth ?

1. Activez le serveur OAuth dans la configuration SmartAuth
2. Créez un client OAuth confidentiel avec les URIs de redirection de votre application
3. Configurez le plugin OAuth/OIDC de votre application avec les endpoints et le secret client fournis par SmartAuth

### Qu'est-ce que le mode Client Credentials ?

Le mode Client Credentials permet à un serveur de s'authentifier auprès de Dolibarr sans intervention humaine. Il est utilisé pour les échanges machine-à-machine (synchronisation automatique, imports, exports). Un utilisateur de service doit être défini sur le client OAuth pour déterminer les permissions.

## Rate limiting et sécurité

### Combien de tentatives de connexion sont autorisées ?

SmartAuth applique deux niveaux de rate limiting :

- **Par adresse IP** : 10 tentatives autorisées sur une fenêtre de 5 minutes
- **Par utilisateur** : 5 tentatives autorisées sur une fenêtre de 15 minutes

Au-delà de ces seuils, les tentatives sont bloquées.

### Comment débloquer une adresse IP bloquée ?

Le blocage est temporaire et se lève automatiquement après expiration de la fenêtre de temps (5 minutes pour le blocage par IP). Si vous devez intervenir immédiatement, videz la table `llx_smartauth_ratelimit` en base de données.

### Les journaux consomment-ils beaucoup d'espace ?

Oui, si la collecte des journaux est activée et que votre API reçoit un volume important de requêtes. Activez le nettoyage automatique dans la [configuration](/smartauth/configuration) et définissez une durée de conservation adaptée à votre besoin.

## Problèmes courants

### Le menu SmartAuth n'apparaît pas

Vérifiez que le module est bien activé et que l'utilisateur dispose de la permission "Read objects of SmartAuth".

### Les journaux restent vides

La collecte des journaux est désactivée par défaut. Activez l'option SMARTAUTH_COLLECT_LOGS dans la [configuration](/smartauth/configuration).

### Le test de connexion OAuth échoue

Vérifiez que l'URL de l'issuer est accessible depuis le serveur lui-même (pas de blocage pare-feu en boucle locale). Vérifiez également que le certificat SSL est valide si vous utilisez HTTPS.

### J'obtiens "Forbidden" sur /.well-known/openid-configuration

La configuration Apache par défaut sur Debian/Ubuntu contient une règle globale `<DirectoryMatch "(^|/)\.">` qui interdit l'accès à tout chemin commençant par un point. Cette règle est évaluée avant le `.htaccess` du module, qui ne peut donc pas la lever depuis l'intérieur.

Ajoutez l'exception dans le vhost de votre portail SSO :

```apache
<LocationMatch "^/\.well-known/">
    Require all granted
</LocationMatch>
```

Puis `sudo systemctl reload apache2`. Voir la section [Portail SSO public](oauth.md#portail-sso-public) du chapitre OAuth pour la configuration vhost complète.

### Le lien dans le mail de réinitialisation du mot de passe pointe au mauvais endroit

Le lien généré dans l'email utilise `SMARTAUTH_APP_URL` comme base, ou `DOL_MAIN_URL_ROOT` à défaut. Définissez la constante Dolibarr `SMARTAUTH_APP_URL` avec l'URL racine de votre portail SSO (exemple : `https://auth.exemple.fr`) pour que le lien `reset-password?token=...` ouvre directement le formulaire sur le portail au lieu de l'admin Dolibarr.
