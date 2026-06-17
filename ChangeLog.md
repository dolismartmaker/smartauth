# CHANGELOG SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 2.0.24 -- 20260617

 - add webpush stuff
 - better logs collect
 - policy : checkuser rights & passwords
 - update user doc
 - add more defensive code on oauth
 - better protection on spoofing tips
 - add security checks on controllers
 - user can delete / revoke own devices
 - do not hardcode custom anymore for custom dolibarr setup

## 2.0.22 -- 20260603

 - Add webpush subsystem for notificaitons on PWA
 - Add sso portal
 - Better log collect

## 2.0.21 -- 20260526

 - TokenService now scopes its JTI and access-token lookups by entity
 - RevokedJtiController caps the ?since= parameter length to 20 chars
 - Add viewport_mode column to llx_smartauth_user_devices
 - Handle viewport-mode (smartphone / tablet / desktop)

## 2.0.20 -- 20260521

 - Missing public folder
 - Product / Services mapping
 - Handle "" and zero values

## 2.0.18 -- 20260520

 - Mappers Dolibarr -> API
 - Next step for dolMapping objects
 - Include dolMapping of dictionaries
 - Handle extrafields
 - Add tests coverage
 - OAuth2 hook smartmaker_oauth_pre_token
 - TokenService::createAccessToken
 - TokenController propagates the harvested extra_claims
 - JWT revocation list
 - ResponseTrait gains sendJsonResponseWithHeaders() and sendNotModified()

## 2.0.16 -- 20260513

 - Add Idempotency-Key support on POST /upload (replays the 2xx response on retry instead of creating duplicate files when the PWA loses the network mid-upload). New table llx_smartauth_upload_idempotency with auto-purge in doScheduledJob (24h for completed, 10min for stale processing). Backend contract for the smartcommon useUploadQueue hook (cf documentation/SPEC_UPLOAD_IDEMPOTENCY.md).
 - Test harness: cleanSmartAuthTables() now discovers smartauth tables at runtime via sqlite_master / SHOW TABLES, no more hard-coded list to maintain.

## 2.0.14 -- 20260507

 - Full security review
 - Add oAuth stuff for sso

## 2.0.12 -- 20260428

 - Fix sanitizeRequestData : call loadExternalSchemas for external specs like photo uploads
 - Add binary upload stuff

## 2.0.10 -- 20260413

- Fix all includes to use dol_include_once()
- Add Memcached cache flush on module activation/upgrade
- Rewrite About page: module info, feedback form, donation box, useful links, changelog display
- Add admin CSS (css/admin.css.php) for About page layout
- Set $help_url to https://doc.cap-rel.fr/smartauth/
- Normalize all dol_syslog() calls with consistent "SmartAuth" prefix
- Add missing translation keys for en_US (dashboard, user tab, OAuth, GeoIP, about page)
- Fix missing French accents in OAuth and Dolibarr Integration translation keys

## 2.0.8 -- 20260303

add auth solution for m2m

## 2.0.7 -- 20260302

use hash share for downloads
add entries into ecm database if missing
add batch queries on documents index

## 2.0.5 -- 20260219

better tests coverage, fix bugs thanks to tests
fix mapping object for dolibarr like Thirdparty / Societe
add categories in list of objectTypeConfig
add catagories linked to an object

## 2.0.2 -- 20260218

dynamic manifest file - you can choose your icon and name (install app icon on desktop)
new offline sync system for binary files (pdf / others)

## 2.0.1 -- 20260210

oAuth2 server working with wordpress client as POC

## 2.0.0 -- 20260209

Full oAuth2 identity provider solution

## 1.1.2 -- 20260201

Add more tools for offline mode
Add LocalRoute system

## 1.1.0 -- 20260123

Major version
Auto build cache router and auto detect invalidate cache if routes changed
Add full CORS support
Fix GeoIP auto setup
Add PATCH support to router

## 1.0.16 -- 20251227

Fix null pointer in RateLimiter when fetch_object returns null
Fix undefined array keys in dmTrait and class files
Fix handling of non-present properties in $fields of core Dolibarr objects
Fix date_creation in smartlogs class
Fix decoded token handling in AuthController
Fix logs creation when user does not exist in table
AuthController now returns complete smartauth object with token and decoded data
Add status check in AuthController
Remove Kanban mode from list views
Improve test coverage with new integration tests
Optimize test performance with SQLite in RAM
Improve GitLab CI pipeline


## 1.0.14 -- 20251216

new mapping for near than all dolibarr objects
change naming to be as close as possible to dolibarr main api
new documentation
add an api naming convention document (rules)
update existing objects to apply that convention

## 1.0.12 -- 20251203

get real ip in case of proxy
better refresh token
add gps data on llx_ecm_files
add device_id entry
disable mass actions
use cache on get device id
add new route for devices
better next num ref for dolibarr object ref
fix refresh token process

## 1.0.10 -- 20251113

Auto-Install GeoIP database
Change ping to refresh route
Check refresh token
Optimize companies logo size
SmartObject type
SmartFileController (maybe, POC to become or not)
Get metadata from ECM database for files

## 1.0.8 -- 20251103

New dashboard on index
New user page for token list (experimental)
Switch to two token (access & refresh)
Code factoring
Update cron job
Fix for better security
Code cleanup
Use cache and new Rate Limiter
Better dolibarr < 18 compat'
Change photo and other special fields to smart* (prefix)
Fix options and extrafields
New multicompany support

## 1.0.5 -- 20251028

Fix MultiCompany errors
Fix extrafields without complete definition
Use const names (better than integer hardcoded)

## 1.0.4 -- 20250930

Add compressOptions to photo objects

## 1.0.2 -- 20250924

Firs public beta release

## 1.0.1 -- 20250416

* Better payload args passed to functions
* New dolibarrMapping classes

## 1.0.0

Initial version
