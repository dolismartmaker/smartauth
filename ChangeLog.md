# CHANGELOG SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.2 -- 20260201

Add more tools for offline mode
Add LocalRoute system

## 1.1.0 -- 20260123

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
