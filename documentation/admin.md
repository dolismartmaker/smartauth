# SmartAuth - Guide d'administration

## Table des matieres

1. [Presentation](#1-presentation)
2. [Prerequis](#2-prerequis)
3. [Installation du module](#3-installation-du-module)
4. [Configuration du serveur OAuth](#4-configuration-du-serveur-oauth)
5. [Configuration du vhost dedie](#5-configuration-du-vhost-dedie)
6. [Gestion des clients OAuth](#6-gestion-des-clients-oauth)
7. [Integration Dolibarr comme client](#7-integration-dolibarr-comme-client)
8. [Integration Nextcloud](#8-integration-nextcloud)
9. [Securite](#9-securite)
10. [Maintenance](#10-maintenance)
11. [Depannage](#11-depannage)

---

## 1. Presentation

SmartAuth est un module Dolibarr qui fournit :

- **API REST JWT** : Authentification pour applications mobiles et SPA
- **Serveur OAuth2/OIDC** : Identity Provider pour SSO (Single Sign-On)

Le serveur OAuth2 permet d'utiliser les comptes Dolibarr pour s'authentifier sur des applications tierces (Nextcloud, apps internes, etc.) via les standards OAuth2 et OpenID Connect.

### Architecture

```
                    ┌─────────────────────────────────────┐
                    │     auth.votredomaine.com           │
                    │     (SmartAuth OAuth Server)        │
                    │                                     │
                    │  - Page de login                    │
                    │  - Page de consentement             │
                    │  - Endpoints OAuth2/OIDC            │
                    └──────────────┬──────────────────────┘
                                   │
                     Authentifie contre la BDD Dolibarr
                                   │
                                   ▼
                           ┌───────────────┐
                           │   Dolibarr    │
                           │   (BDD)       │
                           │  llx_user     │
                           └───────────────┘
                                   ▲
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                          │
        ▼                          ▼                          ▼
┌───────────────┐          ┌───────────────┐          ┌───────────────┐
│   Dolibarr    │          │   Nextcloud   │          │  Autres apps  │
│   (Client)    │          │   (Client)    │          │   (Clients)   │
└───────────────┘          └───────────────┘          └───────────────┘
```

---

## 2. Prerequis

### Serveur

- **PHP** : 7.4 ou superieur (8.2 recommande)
- **Extensions PHP** : curl, openssl, json, mbstring
- **Dolibarr** : 16.0 ou superieur
- **Base de donnees** : MySQL 5.7+ ou MariaDB 10.3+
- **Serveur web** : Apache 2.4+ ou Nginx 1.18+
- **HTTPS** : Certificat SSL valide (Let's Encrypt recommande)

### Reseau

- Le serveur OAuth doit etre accessible depuis toutes les applications clientes
- Port 443 (HTTPS) ouvert
- Nom de domaine dedie pour le serveur OAuth (ex: auth.votredomaine.com)

---

## 3. Installation du module

### 3.1 Deploiement des fichiers

```bash
# Cloner ou copier le module dans le repertoire custom de Dolibarr
cd /var/www/dolibarr/htdocs/custom/
git clone https://votre-repo/smartauth.git

# Installer les dependances PHP (si necessaire)
cd smartauth
composer install --no-dev

# Permissions
chown -R www-data:www-data /var/www/dolibarr/htdocs/custom/smartauth
```

### 3.2 Activer le module

1. Connectez-vous a Dolibarr en tant qu'administrateur
2. Allez dans **Accueil > Configuration > Modules**
3. Recherchez "SmartAuth" et cliquez sur **Activer**

### 3.3 Executer les migrations SQL

Les tables sont creees automatiquement a l'activation du module. Verifiez la presence des tables :

```sql
SHOW TABLES LIKE 'llx_smartauth%';
```

Tables attendues :
- `llx_smartauth_auth` - Tokens API
- `llx_smartauth_devices` - Appareils
- `llx_smartauth_oauth_clients` - Clients OAuth
- `llx_smartauth_oauth_codes` - Codes d'autorisation
- `llx_smartauth_oauth_tokens` - Tokens OAuth
- `llx_smartauth_oauth_consents` - Consentements

---

## 4. Configuration du serveur OAuth

### 4.1 Activer le mode OAuth

1. Allez dans **Configuration > Modules > SmartAuth**
2. Cliquez sur l'onglet **Serveur OAuth**
3. Cochez **Activer le serveur OAuth/OIDC**
4. Configurez l'URL de l'issuer : `https://auth.votredomaine.com`
5. Cliquez sur **Enregistrer**

### 4.2 Parametres disponibles

| Parametre | Defaut | Description |
|-----------|--------|-------------|
| URL de l'issuer | (auto) | URL publique du serveur OAuth |
| Duree access token | 3600s | Validite des tokens d'acces (1h) |
| Duree refresh token | 2592000s | Validite des refresh tokens (30j) |
| Duree code autorisation | 600s | Validite des codes (10 min) |
| PKCE obligatoire | Oui | Requis pour les clients publics |
| Memoriser consentements | Oui | Evite de redemander |

### 4.3 Endpoints OIDC

Une fois active, les endpoints suivants sont disponibles :

| Endpoint | URL |
|----------|-----|
| Discovery | `/.well-known/openid-configuration` |
| JWKS | `/.well-known/jwks.json` |
| Authorization | `/oauth/authorize` |
| Token | `/oauth/token` |
| Userinfo | `/oauth/userinfo` |
| Revocation | `/oauth/revoke` |
| Logout | `/oauth/logout` |
| Login | `/login` |

---

## 5. Configuration du vhost dedie

Le serveur OAuth necessite un vhost dedie pour isoler les endpoints d'authentification.

### 5.1 Configuration Apache

Creez le fichier `/etc/apache2/sites-available/smartauth.conf` :

```apache
<VirtualHost *:443>
    ServerName auth.votredomaine.com
    DocumentRoot /var/www/dolibarr/htdocs/custom/smartauth/public

    # SSL (Let's Encrypt)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/auth.votredomaine.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/auth.votredomaine.com/privkey.pem

    # Configuration SSL securisee
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256
    SSLHonorCipherOrder off

    # Repertoire public
    <Directory /var/www/dolibarr/htdocs/custom/smartauth/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    # Front controller (si mod_rewrite desactive dans .htaccess)
    <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ /index.php [L,QSA]
    </IfModule>

    # Headers de securite
    <IfModule mod_headers.c>
        Header always set X-Frame-Options "DENY"
        Header always set X-Content-Type-Options "nosniff"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
        Header always set Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'"
    </IfModule>

    # Logs dedies
    ErrorLog ${APACHE_LOG_DIR}/smartauth_error.log
    CustomLog ${APACHE_LOG_DIR}/smartauth_access.log combined
</VirtualHost>

# Redirection HTTP vers HTTPS
<VirtualHost *:80>
    ServerName auth.votredomaine.com
    Redirect permanent / https://auth.votredomaine.com/
</VirtualHost>
```

Activez le site :

```bash
# Activer les modules necessaires
a2enmod rewrite ssl headers

# Activer le site
a2ensite smartauth

# Verifier la configuration
apachectl configtest

# Recharger Apache
systemctl reload apache2
```

### 5.2 Configuration Nginx

Creez le fichier `/etc/nginx/sites-available/smartauth` :

```nginx
# Redirection HTTP vers HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name auth.votredomaine.com;
    return 301 https://$server_name$request_uri;
}

# Serveur HTTPS
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name auth.votredomaine.com;

    # SSL (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/auth.votredomaine.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/auth.votredomaine.com/privkey.pem;

    # Configuration SSL securisee
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;

    # HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Document root
    root /var/www/dolibarr/htdocs/custom/smartauth/public;
    index index.php;

    # Headers de securite
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'" always;

    # Front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Bloquer l'acces aux fichiers caches
    location ~ /\. {
        deny all;
    }

    # Cache pour les assets statiques
    location /assets/ {
        expires 1w;
        add_header Cache-Control "public, immutable";
    }

    # Logs dedies
    access_log /var/log/nginx/smartauth_access.log;
    error_log /var/log/nginx/smartauth_error.log;
}
```

Activez le site :

```bash
# Creer le lien symbolique
ln -s /etc/nginx/sites-available/smartauth /etc/nginx/sites-enabled/

# Tester la configuration
nginx -t

# Recharger Nginx
systemctl reload nginx
```

### 5.3 Certificat SSL avec Let's Encrypt

```bash
# Installer certbot
apt install certbot python3-certbot-apache  # ou python3-certbot-nginx

# Obtenir le certificat
certbot certonly --webroot -w /var/www/dolibarr/htdocs/custom/smartauth/public \
    -d auth.votredomaine.com

# Renouvellement automatique (cron)
echo "0 3 * * * root certbot renew --quiet" > /etc/cron.d/certbot
```

### 5.4 Verification

Testez les endpoints :

```bash
# Discovery OIDC
curl -s https://auth.votredomaine.com/.well-known/openid-configuration | jq .

# JWKS (cles publiques)
curl -s https://auth.votredomaine.com/.well-known/jwks.json | jq .

# Page de login
curl -I https://auth.votredomaine.com/login
```

---

## 6. Gestion des clients OAuth

### 6.1 Creer un client

1. Allez dans **SmartAuth > Serveur OAuth > Clients OAuth**
2. Cliquez sur **Nouveau client**
3. Remplissez le formulaire :

| Champ | Description | Exemple |
|-------|-------------|---------|
| Nom | Nom affiche sur la page de consentement | "Mon Application" |
| Description | Description optionnelle | "Application interne" |
| Type | Confidentiel (serveur) ou Public (SPA/mobile) | Public |
| URIs de redirection | URLs de callback autorisees (une par ligne) | `https://app.exemple.com/callback` |
| Scopes | Permissions accordables | openid, profile, email |
| Grants | Flux autorises | authorization_code, refresh_token |

4. Cliquez sur **Creer**
5. **Important** : Copiez le `client_secret` affiche, il ne sera plus visible ensuite

### 6.2 Types de clients

| Type | Usage | Secret | PKCE |
|------|-------|--------|------|
| **Confidentiel** | Applications serveur (PHP, Node.js) | Oui | Optionnel |
| **Public** | SPA, applications mobiles | Non | Obligatoire |

### 6.3 Scopes disponibles

| Scope | Claims retournes |
|-------|------------------|
| `openid` | sub (ID utilisateur) |
| `profile` | name, family_name, given_name |
| `email` | email, email_verified |
| `groups` | Liste des groupes Dolibarr |
| `roles` | Roles mappes (ROLE_USER, ROLE_ADMIN) |
| `offline_access` | Autorise le refresh token |

### 6.4 Regenerer un secret

Si le secret est compromis :

1. Ouvrez la fiche du client
2. Cliquez sur **Regenerer le secret**
3. Confirmez l'action
4. Mettez a jour la configuration dans l'application cliente

---

## 7. Integration Dolibarr comme client

Voir le guide detaille : [install_dolibarr_client.md](install_dolibarr_client.md)

### Resume rapide

1. **Creer le client** dans SmartAuth (type public, PKCE)
2. **Copier le fichier** `core/login/functions_smartauthoauth.php` dans Dolibarr
3. **Configurer conf.php** : `$dolibarr_main_authentication = 'smartauthoauth,dolibarr';`
4. **Ajouter les constantes** :
   - `SMARTAUTH_OAUTH_ISSUER` = `https://auth.votredomaine.com`
   - `SMARTAUTH_OAUTH_CLIENT_ID` = `dolibarr-erp`

---

## 8. Integration Nextcloud

### 8.1 Installer l'app OpenID Connect

```bash
# Via occ
sudo -u www-data php /var/www/nextcloud/occ app:install user_oidc
sudo -u www-data php /var/www/nextcloud/occ app:enable user_oidc
```

### 8.2 Creer le client dans SmartAuth

1. Allez dans **SmartAuth > Clients OAuth > Nouveau**
2. Configuration :
   - Nom : `Nextcloud`
   - Type : `Confidentiel`
   - URIs de redirection : `https://cloud.votredomaine.com/apps/user_oidc/code`
   - Scopes : `openid`, `profile`, `email`, `groups`
   - Grants : `authorization_code`, `refresh_token`
3. Copiez le `client_id` et `client_secret`

### 8.3 Configurer Nextcloud

```bash
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider SmartAuth \
    --clientid="nextcloud-app" \
    --clientsecret="VOTRE_SECRET" \
    --discoveryuri="https://auth.votredomaine.com/.well-known/openid-configuration" \
    --scope="openid profile email groups" \
    --unique-uid="1" \
    --mapping-uid="sub" \
    --mapping-displayName="name" \
    --mapping-email="email" \
    --mapping-groups="groups"
```

### 8.4 Mapping des groupes

Creez les groupes correspondants dans Nextcloud ou activez la creation automatique dans la configuration de l'app user_oidc.

---

## 9. Securite

### 9.1 Checklist de securite

- [ ] HTTPS obligatoire sur le vhost OAuth
- [ ] Headers de securite configures (CSP, HSTS, X-Frame-Options)
- [ ] Certificat SSL valide et a jour
- [ ] Rate limiting actif sur `/login` et `/oauth/token`
- [ ] Logs actives et surveilles
- [ ] Secrets des clients stockes de maniere securisee
- [ ] PKCE obligatoire pour les clients publics

### 9.2 Rate limiting

SmartAuth utilise le rate limiter integre pour proteger contre les attaques brute force :

| Protection | Limite | Fenetre |
|------------|--------|---------|
| Par IP | 10 tentatives | 5 minutes |
| Par utilisateur | 5 tentatives | 15 minutes |

Configuration via constantes Dolibarr :
- `SMARTAUTH_RATELIMIT_IP_MAX`
- `SMARTAUTH_RATELIMIT_IP_WINDOW`
- `SMARTAUTH_RATELIMIT_USER_MAX`
- `SMARTAUTH_RATELIMIT_USER_WINDOW`

### 9.3 Rotation des cles JWT

Les cles RSA utilisees pour signer les JWT peuvent etre regenerees :

```sql
-- Supprimer les cles actuelles (force la regeneration)
DELETE FROM llx_const WHERE name LIKE 'SMARTAUTH_RSA_%';
```

Les nouvelles cles seront generees automatiquement au prochain demarrage.

**Attention** : Tous les tokens existants seront invalides apres la rotation.

### 9.4 Revocation des tokens

Pour revoquer tous les tokens d'un utilisateur :

```sql
-- Via SQL
UPDATE llx_smartauth_oauth_tokens
SET revoked_at = NOW()
WHERE fk_user = ID_UTILISATEUR;
```

Ou via l'interface d'administration du client OAuth.

---

## 10. Maintenance

### 10.1 Nettoyage des tokens expires

Les tokens expires s'accumulent en base. Planifiez un nettoyage regulier :

```sql
-- Supprimer les codes d'autorisation expires (> 1 jour)
DELETE FROM llx_smartauth_oauth_codes
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY);

-- Supprimer les tokens expires (> 30 jours)
DELETE FROM llx_smartauth_oauth_tokens
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Supprimer les consentements revoques (> 90 jours)
DELETE FROM llx_smartauth_oauth_consents
WHERE revoked_at IS NOT NULL
AND revoked_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

Cron recommande :

```bash
# /etc/cron.daily/smartauth-cleanup
mysql -u dolibarr -p dolibarr < /var/www/dolibarr/htdocs/custom/smartauth/sql/cleanup.sql
```

### 10.2 Sauvegarde

Elements a sauvegarder :

```bash
# Tables de base de donnees
mysqldump dolibarr llx_smartauth_oauth_clients llx_smartauth_oauth_consents > smartauth_backup.sql

# Cles JWT (si stockees en fichier)
# Par defaut, les cles sont en BDD dans llx_const
```

### 10.3 Mise a jour du module

```bash
cd /var/www/dolibarr/htdocs/custom/smartauth

# Sauvegarder
git stash

# Mettre a jour
git pull origin main

# Appliquer les migrations si necessaire
# (via l'interface Dolibarr ou manuellement)
```

---

## 11. Depannage

### 11.1 La page de login ne s'affiche pas

**Verifications :**
1. Le vhost est-il correctement configure ?
   ```bash
   curl -I https://auth.votredomaine.com/login
   ```
2. Le front controller fonctionne-t-il ?
   ```bash
   tail -f /var/log/apache2/smartauth_error.log
   ```
3. Le mode OAuth est-il active ?
   - Verifiez dans Dolibarr : SmartAuth > Serveur OAuth

### 11.2 Erreur "redirect_uri mismatch"

**Cause** : L'URI de redirection ne correspond pas exactement a celle configuree.

**Solution** :
1. Verifiez l'URI exacte dans le client OAuth
2. Attention aux details : `http` vs `https`, slash final, port
3. Mettez a jour la configuration du client

### 11.3 Erreur "invalid_client"

**Causes possibles** :
- `client_id` incorrect
- `client_secret` incorrect (pour clients confidentiels)
- Client desactive

**Solution** :
1. Verifiez le `client_id` dans SmartAuth
2. Regenerez le secret si necessaire
3. Verifiez que le client est actif

### 11.4 Le cookie de session n'est pas cree

**Causes possibles** :
- HTTPS non actif
- Probleme de domaine de cookie
- Headers de securite trop restrictifs

**Solution** :
1. Verifiez que le site est en HTTPS
2. Verifiez les logs PHP pour les erreurs de cookie
3. Testez avec un navigateur en mode developpeur (onglet Application > Cookies)

### 11.5 Erreur "PKCE verification failed"

**Cause** : Le `code_verifier` ne correspond pas au `code_challenge` envoyé.

**Solution** :
1. Verifiez que le client stocke correctement le `code_verifier`
2. Assurez-vous d'utiliser la meme valeur entre `/authorize` et `/token`
3. Verifiez l'algorithme (S256 recommande)

### 11.6 Logs utiles

```bash
# Logs Apache/Nginx
tail -f /var/log/apache2/smartauth_error.log
tail -f /var/log/nginx/smartauth_error.log

# Logs Dolibarr
tail -f /var/www/dolibarr/documents/dolibarr.log | grep -i oauth

# Logs PHP
tail -f /var/log/php8.2-fpm.log
```

### 11.7 Mode debug

Pour activer les logs detailles :

```php
// Dans conf/conf.php de Dolibarr
$dolibarr_syslog_level = LOG_DEBUG;
```

---

## Annexes

### A. Variables d'environnement

Aucune variable d'environnement requise. Toute la configuration est stockee dans les constantes Dolibarr (`llx_const`).

### B. Constantes Dolibarr

| Constante | Defaut | Description |
|-----------|--------|-------------|
| `SMARTAUTH_OAUTH_ENABLED` | 0 | Activer le serveur OAuth |
| `SMARTAUTH_OAUTH_ISSUER` | (auto) | URL de l'issuer |
| `SMARTAUTH_OAUTH_ACCESS_TTL` | 3600 | Duree access token (sec) |
| `SMARTAUTH_OAUTH_REFRESH_TTL` | 2592000 | Duree refresh token (sec) |
| `SMARTAUTH_OAUTH_CODE_TTL` | 600 | Duree code autorisation (sec) |
| `SMARTAUTH_OAUTH_REQUIRE_PKCE` | 1 | PKCE obligatoire |
| `SMARTAUTH_OAUTH_CONSENT_REMEMBER` | 1 | Memoriser consentements |
| `SMARTAUTH_OAUTH_BYPASS` | 0 | Mode maintenance |
| `SMARTAUTH_FALLBACK_USERS` | "" | IDs users autorises fallback |

### C. References

- [RFC 6749 - OAuth 2.0](https://datatracker.ietf.org/doc/html/rfc6749)
- [RFC 7636 - PKCE](https://datatracker.ietf.org/doc/html/rfc7636)
- [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html)
- [OpenID Connect Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html)
