# SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

[![pipeline status](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/badges/master/pipeline.svg)](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/-/commits/master)
[![coverage report](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/badges/master/coverage.svg)](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/-/commits/master)

## Features

Another way of life for API stack. That module make it possible to have more than one API key for each user : each API key could be linked to an app (a dolibarr module) then all requests will become "chrooted" to that module and confined into that part.

This is what nextcloud and other makes : you make a link with your smartphone ? there is an api key dedicated for your smartphone. Your smartphone is stollen, just delete his api key, if you have other devices each of them have their one dedicated key, you don't have to update all of them.

Download zip from https://cloud.cap-rel.fr/index.php/s/G6AKiSKCwPR9HLx

## Details of current implementation (to be enhanced)

a new module (**smartLivraison** for example) is going to use **smartAuth**, here is some tech details:

- **smartLivraison** setup makes a random string an store it (`$salt2`)
- **smartLivraison** will "pass the salt" to smartauth stack via the use of global `$smartAuthAppKey` var (point to be enhanced)
- **smartLivraison** will path the appuid (see dolibarr module numero into `core/modules/mod*.class.php`) as global `$smartAuthAppID` (point to be enhanced)

Note: with SALT 1 we hope a user of an other module like ... **smartInterventions** for example could not reuse same token as **smartLivraison**

- **smartAuth** takes the client user agent as base to compute a second part of salt (`$salt1`)
- during the generation of a new key **smartAuth** use a full random sting (one way, "unpredictable") (`$salt`)
- **smartAuth** store data into local `llx_smartauth_auth` table `($smartAuthAppID, $salt1, date_creation, date_eol, fk_user_creat, fk_authid, auth_element, ip, status, entity)`
- we keep the last_insert_id as jwt first character (`number|data`)
- then **smartAuth** will use JWT::encode stack with `$salt . $salt1 . $salt2`;

then after generation of the token, remote device will only use that token as

`Authorization: Bearer $token`

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readmes are licensed under GFDL.

French documentation is available here https://doc.cap-rel.fr/projet_smartauth/accueil