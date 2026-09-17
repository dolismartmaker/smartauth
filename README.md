# SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Features

SmartAuth turns your Dolibarr into the single authentication point of everything
around it: mobile applications, third-party services and server-to-server
exchanges all authenticate against the ERP accounts, with no separate directory
to maintain.

Four ways to authenticate:

- **JWT API** for mobile applications, with one access / refresh token pair per
  device
- **OAuth2 / OpenID Connect** for SSO: Dolibarr becomes the identity provider of
  third-party applications (WordPress, Nextcloud, and so on)
- **Client credentials** for machine-to-machine exchanges, with no user
  interaction at all
- **QR code pairing**: an already authenticated Dolibarr workstation displays a
  short-lived QR code, the mobile scans it and receives its tokens once the
  workstation confirms, without ever typing a password

What you drive from the ERP:

- **Dashboard** of active tokens, connected users, rate limit blocks and refresh
  success rate
- **Tokens and devices** listed, inspectable and revocable one by one, each
  device forming an isolated family: revoking one does not log out the others
- **Authentication logs** tracing logins and API calls
- **OAuth2 clients**, confidential or public with PKCE, created and managed from
  the administration pages
- **Rate limiting** against brute force, per IP address and per user, with token
  replay detection
- **New login alert** sent by email whenever an account is used from an unknown
  IP address or device
- **Optional GeoIP** lookup of the connecting addresses
- **SmartAuth tab** on the user record, to review and cut one's own sessions
  without leaving the page
- **Self-service registration and password recovery**, with email validation
- **Automatic cleanup** of expired tokens and logs through a scheduled job

Other external modules are available on [Dolistore.com](https://www.dolistore.com/index.php?controller=search&orderby=position&orderway=desc&tag=&website=marketplace&search_query=cap-rel&submit_search=).

## Requirements

- Dolibarr 18.0 or above
- PHP 7.4 or above

## Documentation

User documentation: [doc.cap-rel.fr/smartauth](https://doc.cap-rel.fr/smartauth/)

## Translations

Translations can be completed manually by editing files into directories *langs*.

<!--

## Installation

### From the ZIP file and GUI interface

If the module is a ready to deploy zip file, so with a name module_xxx-version.zip (like when downloading it from a market place like [Dolistore](https://www.dolistore.com)),
go into menu ```Home - Setup - Modules - Deploy external module``` and upload the zip file.

Note: If this screen tell you that there is no "custom" directory, check that your setup is correct:

- In your Dolibarr installation directory, edit the ```htdocs/conf/conf.php``` file and check that following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading ```//```) and assign a sensible value according to your Dolibarr installation

    For example :

    - UNIX:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
        ```

    - Windows:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
        ```

### From a GIT repository

Clone the repository in ```$dolibarr_main_document_root_alt/smartauth```

```sh
cd ....../custom
git clone git@github.com:gitlogin/smartauth.git smartauth
```

### <a name="final_steps"></a>Final steps

From your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup" -> "Modules"
  - You should now be able to find and enable the module

-->

## Support

Every help, support or maintenance request goes through:

[https://cap-rel.fr/sav-module-dolibarr/](https://cap-rel.fr/sav-module-dolibarr/)

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readmes are licensed under GFDL.
