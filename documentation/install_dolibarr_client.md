# Installation : Dolibarr comme client OAuth SmartAuth

Ce guide explique comment configurer Dolibarr pour utiliser SmartAuth comme serveur d'authentification OAuth2/OIDC.

## Prerequis

- SmartAuth installe et configure (serveur OAuth actif)
- Dolibarr 16.0 ou superieur
- PHP 7.4 ou superieur avec extension cURL
- Connexion HTTPS entre Dolibarr et SmartAuth

## Etape 1 : Creer le client OAuth dans SmartAuth

### Option A : Via l'interface d'administration

1. Connectez-vous a SmartAuth en tant qu'administrateur
2. Allez dans **Configuration > Modules > SmartAuth > Serveur OAuth**
3. Cliquez sur **Integration Dolibarr**
4. Cliquez sur **Creer le client Dolibarr**
5. Modifiez l'URL de redirection pour correspondre a votre Dolibarr :
   - Exemple : `https://erp.votredomaine.com/index.php`

### Option B : Via SQL

```sql
-- Executez le script fourni avec SmartAuth
source sql/data/llx_smartauth_oauth_clients_dolibarr.sql;

-- Puis mettez a jour l'URL de redirection
UPDATE llx_smartauth_oauth_clients
SET redirect_uris = '["https://erp.votredomaine.com/index.php"]'
WHERE client_id = 'dolibarr-erp';
```

## Etape 2 : Copier le fichier d'authentification

Copiez le fichier `functions_smartauthoauth.php` dans Dolibarr :

```bash
# Depuis le repertoire SmartAuth
cp core/login/functions_smartauthoauth.php /var/www/dolibarr/htdocs/core/login/

# Verifiez les permissions
chown www-data:www-data /var/www/dolibarr/htdocs/core/login/functions_smartauthoauth.php
chmod 644 /var/www/dolibarr/htdocs/core/login/functions_smartauthoauth.php
```

## Etape 3 : Configurer conf.php

Editez le fichier `conf/conf.php` de Dolibarr :

```php
// Authentification via SmartAuth OAuth avec fallback local
$dolibarr_main_authentication = 'smartauthoauth,dolibarr';
```

**Explications :**
- `smartauthoauth` : Essaie d'abord l'authentification OAuth via SmartAuth
- `dolibarr` : Fallback sur l'authentification locale si SmartAuth est indisponible

Pour desactiver completement l'authentification locale (deconseille) :
```php
$dolibarr_main_authentication = 'smartauthoauth';
```

## Etape 4 : Configurer les constantes Dolibarr

Connectez-vous a Dolibarr (avec l'authentification locale pour cette etape).

Allez dans **Accueil > Configuration > Autres** et ajoutez les constantes :

| Constante | Valeur | Description |
|-----------|--------|-------------|
| `SMARTAUTH_OAUTH_ISSUER` | `https://auth.votredomaine.com` | URL du serveur SmartAuth |
| `SMARTAUTH_OAUTH_CLIENT_ID` | `dolibarr-erp` | ID du client OAuth |

### Constantes optionnelles

| Constante | Valeur | Description |
|-----------|--------|-------------|
| `SMARTAUTH_OAUTH_CLIENT_SECRET` | (vide) | Laisser vide pour client public (PKCE) |
| `SMARTAUTH_OAUTH_BYPASS` | `0` | Mettre a `1` pour desactiver temporairement OAuth |
| `SMARTAUTH_FALLBACK_USERS` | `1,2` | IDs des users autorises a utiliser le fallback local |

## Etape 5 : Tester l'integration

1. Deconnectez-vous de Dolibarr
2. Accedez a l'URL de Dolibarr
3. Vous devriez etre redirige vers la page de connexion SmartAuth
4. Connectez-vous avec vos identifiants
5. Autorisez l'application Dolibarr (premiere connexion uniquement)
6. Vous etes redirige vers Dolibarr, connecte

## Depannage

### Erreur : "SmartAuth OAuth not available"

**Causes possibles :**
- SmartAuth n'est pas accessible depuis Dolibarr
- Les constantes `SMARTAUTH_OAUTH_ISSUER` ou `SMARTAUTH_OAUTH_CLIENT_ID` ne sont pas definies
- Le serveur SmartAuth ne repond pas au health check

**Solutions :**
1. Verifiez la connectivite : `curl -I https://auth.votredomaine.com/.well-known/openid-configuration`
2. Verifiez les constantes dans Dolibarr
3. Consultez les logs Dolibarr pour plus de details

### Erreur : "State mismatch"

**Cause :** La session PHP a ete perdue entre le redirect et le callback.

**Solutions :**
1. Verifiez la configuration des sessions PHP
2. Assurez-vous que les cookies sont actives
3. Verifiez que le domaine de session est correct

### Erreur : "redirect_uri mismatch"

**Cause :** L'URL de callback ne correspond pas a celle configuree dans SmartAuth.

**Solutions :**
1. Verifiez l'URL exacte dans SmartAuth (admin > OAuth clients)
2. L'URL doit correspondre exactement (avec ou sans slash final)
3. Mettez a jour `redirect_uris` dans la table `llx_smartauth_oauth_clients`

### Fallback local ne fonctionne pas

**Cause :** La configuration n'inclut pas le fallback.

**Solutions :**
1. Verifiez `$dolibarr_main_authentication = 'smartauthoauth,dolibarr';`
2. Utilisez `SMARTAUTH_OAUTH_BYPASS=1` pour forcer le mode local temporairement

## Securite

### PKCE (Proof Key for Code Exchange)

Le client Dolibarr utilise PKCE par defaut, ce qui signifie :
- Pas besoin de client_secret
- Protection contre l'interception de code
- Securite renforcee pour les clients publics

### Fallback local

Le fallback sur l'authentification locale est utile pour :
- L'installation initiale
- La maintenance du serveur SmartAuth
- Les situations d'urgence

Pour limiter qui peut utiliser le fallback :
```sql
-- Seuls les users 1 et 2 peuvent utiliser l'auth locale
INSERT INTO llx_const (name, value, type, visible, entity)
VALUES ('SMARTAUTH_FALLBACK_USERS', '1,2', 'chaine', 0, 1);
```

### Desactiver temporairement OAuth

En cas de probleme avec SmartAuth :
```sql
-- Activer le mode bypass (auth locale uniquement)
UPDATE llx_const SET value = '1' WHERE name = 'SMARTAUTH_OAUTH_BYPASS';

-- Reactiver OAuth
UPDATE llx_const SET value = '0' WHERE name = 'SMARTAUTH_OAUTH_BYPASS';
```

## Architecture

```
┌─────────────────┐     1. Acces      ┌─────────────────┐
│    Navigateur   │ ─────────────────>│    Dolibarr     │
│                 │                   │                 │
│                 │<───────────────── │ check_user_     │
│                 │  2. Redirect      │ password_       │
│                 │     /authorize    │ smartauthoauth  │
│                 │                   └─────────────────┘
│                 │
│                 │     3. Login
│                 │ ─────────────────>┌─────────────────┐
│                 │                   │   SmartAuth     │
│                 │<───────────────── │   OAuth Server  │
│                 │  4. Redirect      │                 │
│                 │     ?code=xxx     └─────────────────┘
│                 │
│                 │     5. Callback   ┌─────────────────┐
│                 │ ─────────────────>│    Dolibarr     │
│                 │                   │                 │
│                 │                   │ 6. Token        │
│                 │                   │    Exchange     │──┐
│                 │                   │                 │  │
│                 │                   │ 7. Get          │  │  SmartAuth
│                 │                   │    Userinfo     │──┤  /oauth/token
│                 │                   │                 │  │  /oauth/userinfo
│                 │<───────────────── │ 8. Session      │<─┘
│                 │  9. Page Dolibarr │    Dolibarr     │
└─────────────────┘                   └─────────────────┘
```

## Logs

Les logs SmartAuth OAuth sont ecrits dans les logs Dolibarr standard :

```bash
tail -f /var/www/dolibarr/documents/dolibarr.log | grep "SmartAuth OAuth"
```

Niveaux de log :
- `LOG_DEBUG` : Details du flux OAuth
- `LOG_INFO` : Connexions reussies
- `LOG_WARNING` : Erreurs de validation (state, user inactif)
- `LOG_ERR` : Erreurs critiques (token exchange echoue)
