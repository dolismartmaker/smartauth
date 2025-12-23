<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\Api {
    class RateLimiter
    {
        protected $db;
        public function __construct($db)
        {
        }
        /**
         * Check if request should be rate limited
         *
         * @param string $identifier IP address or username
         * @param string $action Type of action (login, api_call, etc.)
         * @param int $max_attempts Maximum attempts allowed
         * @param int $window_seconds Time window in seconds
         * @return array ['allowed' => bool, 'retry_after' => int|null]
         */
        public function checkLimit($identifier, $action, $max_attempts = 5, $window_seconds = 300)
        {
        }
        /**
         * Record an attempt
         */
        public function recordAttempt($identifier, $action, $success = false)
        {
        }
        /**
         * Reset rate limit for identifier (e.g., after successful login)
         */
        public function reset($identifier, $action)
        {
        }
        /**
         * Clean entries older than retention period
         */
        private function cleanOldEntries($retention_seconds = 3600)
        {
        }
    }
    class AdvancedRateLimiter extends \SmartAuth\Api\RateLimiter
    {
        /**
         * Progressive delay based on number of failures
         * - 1-3 failures: no delay
         * - 4-5 failures: 30 seconds
         * - 6-10 failures: 5 minutes
         * - 11+ failures: 1 hour
         */
        public function checkLimitProgressive($identifier, $action)
        {
        }
    }
    class AuthController
    {
        // rate limiter
        const SMARTAUTH_RATELIMIT_IP_MAX = 10;
        const SMARTAUTH_RATELIMIT_IP_WINDOW = 300;
        // 5 min
        // Strict pour username (protéger comptes)
        const SMARTAUTH_RATELIMIT_USER_MAX = 5;
        const SMARTAUTH_RATELIMIT_USER_WINDOW = 900;
        // 15 min
        const STATUS_DRAFT = 0;
        const STATUS_VALID = 1;
        const STATUS_LOGOUT = 9;
        /**
         * @api {get} /index List available entities
         * @apiName GetEntities
         * @apiGroup Auth
         * @apiVersion 1.0.0
         *
         * @apiDescription Get the list of available Dolibarr entities.
         * Use this endpoint before login if your Dolibarr uses the MultiCompany module.
         * This allows the user to select the correct entity before authentication.
         *
         * @apiSuccess {Object[]} entities List of available entities
         * @apiSuccess {Number} entities.id Entity ID
         * @apiSuccess {String} entities.label Entity name
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "entities": [
         *         {"id": 1, "label": "Main Company"},
         *         {"id": 2, "label": "Branch Office"}
         *     ]
         * }
         */
        public function index($arr = null)
        {
        }
        /**
         * @api {get} /ping Check token validity
         * @apiName Ping
         * @apiGroup Auth
         * @apiVersion 1.0.0
         * @apiDeprecated Use (#Auth:Refresh) instead.
         *
         * @apiDescription Check if your token is still valid. Redirects to refresh endpoint.
         *
         * @apiHeader {String} Authorization Bearer token
         * @apiHeader {String} X-DeviceId Unique device identifier
         */
        public function ping($arr = null)
        {
        }
        /**
         * @api {get} /refresh Refresh tokens
         * @apiName Refresh
         * @apiGroup Auth
         * @apiVersion 1.0.0
         *
         * @apiDescription Use the refresh token to obtain a new access token and refresh token pair.
         * The current refresh token is invalidated after use (token rotation).
         *
         * @apiHeader {String} Authorization Bearer refresh_token (format: token_id|jwt)
         * @apiHeader {String} X-DeviceId Unique device identifier
         *
         * @apiSuccess {String} access_token New JWT access token
         * @apiSuccess {String} refresh_token New JWT refresh token
         * @apiSuccess {Number} expires_in Access token lifetime in seconds
         * @apiSuccess {String} token_type Token type (Bearer)
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "access_token": "123|eyJ0eXAiOiJKV1Q...",
         *     "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
         *     "expires_in": 3600,
         *     "token_type": "Bearer"
         * }
         *
         * @apiError (401) RefreshTokenRequired Refresh token is missing
         * @apiError (401) InvalidTokenFormat Token format is invalid
         * @apiError (401) InvalidTokenPayload Token payload is invalid
         * @apiError (401) SecurityViolation Token replay attack detected, all sessions revoked
         * @apiError (401) MaxRefreshExceeded Maximum refresh limit reached, login required
         */
        public function refresh($arr = null)
        {
        }
        /**
         * @api {post} /login Login
         * @apiName PostLogin
         * @apiGroup Auth
         * @apiVersion 1.0.0
         *
         * @apiDescription Authenticate user with email/password and obtain JWT tokens.
         * On success, returns both an access token and a refresh token.
         * Rate limiting is applied per IP and per username.
         *
         * @apiHeader {String} X-DeviceId Unique device identifier (UUID or SHA256 hash)
         * @apiHeader {String} Content-Type Must be application/json
         *
         * @apiBody {String} email User email or login
         * @apiBody {String} password User password
         * @apiBody {Number} [entity=1] Dolibarr entity ID (for MultiCompany)
         * @apiBody {Number} [rememberMe=0] Remember me flag
         *
         * @apiSuccess {String} user User email or login
         * @apiSuccess {Number} userid User ID
         * @apiSuccess {Number} entity Entity ID
         * @apiSuccess {String} token Access token (legacy, same as access_token)
         * @apiSuccess {String} access_token JWT access token
         * @apiSuccess {String} refresh_token JWT refresh token
         * @apiSuccess {Number} expires_in Access token lifetime in seconds
         * @apiSuccess {String} token_type Token type (Bearer)
         * @apiSuccess {Object[]} [devices_choice] List of known devices for this user (if device is new)
         * @apiSuccess {Number} rememberMe Remember me flag
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "user": "user@example.com",
         *     "userid": 3,
         *     "entity": 1,
         *     "token": "123|eyJ0eXAiOiJKV1Q...",
         *     "access_token": "123|eyJ0eXAiOiJKV1Q...",
         *     "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
         *     "expires_in": 3600,
         *     "token_type": "Bearer",
         *     "devices_choice": null,
         *     "rememberMe": 0
         * }
         *
         * @apiError (401) AccessDenied Invalid credentials
         * @apiError (429) TooManyRequests Rate limit exceeded
         *
         * @apiErrorExample {json} Rate-Limit-Response:
         * HTTP/1.1 429 Too Many Requests
         * {
         *     "error": "Too many attempts. Please try again later.",
         *     "retry_after": 180
         * }
         */
        public function login($payload)
        {
        }
        /**
         * @api {post} /logout Logout
         * @apiName PostLogout
         * @apiGroup Auth
         * @apiVersion 1.0.0
         *
         * @apiDescription Logout the user and revoke all tokens in the current token family.
         * This invalidates both access and refresh tokens.
         *
         * @apiHeader {String} Authorization Bearer access_token
         * @apiHeader {String} X-DeviceId Unique device identifier
         *
         * @apiSuccess {String} user Empty string (logged out)
         * @apiSuccess {String} token Empty string (revoked)
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "user": "",
         *     "token": ""
         * }
         */
        public function logout($payload)
        {
        }
        /**
         * @api {post} /device Manage device
         * @apiName PostDevice
         * @apiGroup Device
         * @apiVersion 1.0.0
         *
         * @apiDescription Manage device association for the authenticated user.
         * Two use cases:
         * 1. Same UUID: Update the device name/label
         * 2. Different UUID: Switch to an existing device (generates new tokens)
         *
         * @apiHeader {String} Authorization Bearer access_token
         * @apiHeader {String} X-DeviceId Current device UUID
         *
         * @apiBody {String} uuid Device UUID to associate
         * @apiBody {String} [label] Device name/label (for naming the device)
         *
         * @apiSuccess {String} [message] Status message
         * @apiSuccess {String} [token] New access token (if device switched)
         * @apiSuccess {String} [access_token] New access token (if device switched)
         * @apiSuccess {String} [refresh_token] New refresh token (if device switched)
         * @apiSuccess {Number} [expires_in] Token lifetime in seconds (if device switched)
         * @apiSuccess {String} [token_type] Token type (if device switched)
         *
         * @apiSuccessExample {json} Success-Response (same device, name updated):
         * HTTP/1.1 200 OK
         * {
         *     "message": "update device name : success"
         * }
         *
         * @apiSuccessExample {json} Success-Response (device switched):
         * HTTP/1.1 200 OK
         * {
         *     "token": "125|eyJ0eXAiOiJKV1Q...",
         *     "access_token": "125|eyJ0eXAiOiJKV1Q...",
         *     "refresh_token": "126|eyJ0eXAiOiJKV1Q...",
         *     "expires_in": 3600,
         *     "token_type": "Bearer",
         *     "message": "please use this new token"
         * }
         */
        public function device($payload = null)
        {
        }
        /**
         * Check if token is correct and valid
         *
         * Validates JWT token and ensures:
         * - Token exists and is not revoked
         * - Token type is 'access' (not 'refresh')
         * - Token has not expired
         * - JWT signature is valid
         *
         * @return  StdObject  Decoded token payload
         */
        public static function check()
        {
        }
        /**
         * create a new salt stored into database and a key for thirdpart account
         *
         * @param   int  $socid     dolibarr Societe id
         * @param   strning $socmail Societe emali addr
         * @param   int  $entity     dolibarr entity
         *
         * @return  [type]           [return description]
         */
        public function newThirdpartKey($socid, $socmail, $entity = 1)
        {
        }
        private static function _getAuthorizationHeader()
        {
        }
        private static function _getBearerToken()
        {
        }
        /**
         * return array with list of entities if multientity is enabled
         *
         * @return  [type]  [return description]
         */
        private function _api_GetListOfEntities()
        {
        }
        /**
         * return entity for that login
         *
         * @return  [type]  [return description]
         */
        private function _findEntityForUser($login)
        {
        }
        /**
         * fetch a dolibarr user and load its rights
         *
         * @param   [type]$u  [$u description]
         * @param   null      [ description]
         *
         * @return  [type]    [return description]
         */
        private function _FetchUserWithRights($u = null)
        {
        }
        /**
         * Get the server variable REMOTE_ADDR, or the first ip of HTTP_X_FORWARDED_FOR (when using proxy).
         * Source: thanks to prestashop
         *
         * @return string $remote_addr ip of client
         */
        public static function get_client_ip()
        {
        }
        /**
         * Get salt2 for device/app identification with fallback
         *
         * Priority:
         * 1. X-App-ID header (best - unique per device)
         * 2. User-Agent hash (fallback)
         *
         * @return string 16-character salt for key derivation
         */
        private static function _getSalt2($device_uuid = '')
        {
        }
        /**
         * Validate uuid format (UUID or SHA256 hash)
         *
         * @param string $uuid identifier
         * @return bool True if valid format
         */
        private static function _validateUUID($uuid)
        {
        }
        /**
         * Create a new token family for tracking refresh chain
         */
        private function _createTokenFamily($user_id)
        {
        }
        /**
         * Generate access + refresh token pair
         *
         * @param   String  $element     dolibarr element (user or societe_account for the moment)
         * @param   Int  	$element_id  dolibarr id of element
         * @param   Int  	$user_id     user creator id
         * @param   String  $login       login to use for that token
         * @param   Int  	$entity      dolibarr entity
         * @param   Int     $family_id   token family
         * @param   Int 	$device_id   device id (foreign key)
         * @param   String 	$device_uuid device uuid (in case of previous is null and we don't want to use http header value)
         *
         * @return  array               two token (access & refresh)
         */
        private function _generateTokenPair($element, $element_id, $user_id, $login, $entity, $family_id, $device_id, $device_uuid = '')
        {
        }
        /**
         * Unified token generation (replaces _newUserKey)
         */
        private function _generateToken($element, $element_id, $user_id, $login, $entity, $token_type, $lifetime, $family_id, $device_id, $device_uuid = null)
        {
        }
        /**
         * Check token family validity (detect replay attacks)
         */
        private function _checkTokenFamily($family_id, $user_id)
        {
        }
        /**
         * Update token family after successful refresh
         */
        private function _updateTokenFamily($family_id, $new_count)
        {
        }
        /**
         * Revoke entire token family, example of reason: security breach detected)
         *
         * @param   [type]          $family_id  id of family token
         * @param   [type]          $reason     reason of revocation
         *
         */
        private function _revokeTokenFamily($family_id, $reason = 'family_revoked')
        {
        }
        /**
         * Revoke single token
         */
        private function _revokeToken($token_id, $reason = 'manual')
        {
        }
        /**
         * search the rowid of a device thanks to its uuid
         *
         * @param   String  $uuid  string of unique id identifier for the device
         *
         * @return  int     <= 0 in error, > 0 : rowid on success
         */
        public static function getDeviceIDFromUUID($uuid)
        {
        }
        /**
         * search the name of a device
         *
         * @return  int     <= 0 in error, > 0 : rowid on success
         */
        public static function getDeviceName($id = null, $uuid = null)
        {
        }
        /**
         * get all devices for a user, then user can choose his device or
         * give a name to the new one
         *
         * @param   [type]  $user_id  [$user_id description]
         *
         * @return  [type]            [return description]
         */
        private function _getAllDevicesForUser($user_id)
        {
        }
        private function _createDeviceIdIfNeeded($user_id, $device_uuid = '')
        {
        }
        private static function _decodeJWT($token, $checktype)
        {
        }
    }
    class RefreshTokenMonitoring
    {
        /**
         * Get refresh statistics
         */
        public static function getRefreshStats($db, $days = 7)
        {
        }
        /**
         * Detect suspicious refresh patterns
         */
        public static function detectAnomalies($db, $user_id)
        {
        }
    }
    class RouteController
    {
        /**
         * Register a GET route in the routing table
         *
         * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
         * @param   string  $targetClass        Controller class name to instantiate
         * @param   string  $redirectFunction   Method name to call on the controller
         * @param   bool    $protected          Whether JWT authentication is required
         *
         * @return  void
         */
        public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * Register a POST route in the routing table
         *
         * @param   string  $targetAction       URL pattern to match (e.g., '/users')
         * @param   string  $targetClass        Controller class name to instantiate
         * @param   string  $redirectFunction   Method name to call on the controller
         * @param   bool    $protected          Whether JWT authentication is required
         *
         * @return  void
         */
        public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * Register a PUT route in the routing table
         *
         * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
         * @param   string  $targetClass        Controller class name to instantiate
         * @param   string  $redirectFunction   Method name to call on the controller
         * @param   bool    $protected          Whether JWT authentication is required
         *
         * @return  void
         */
        public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * Register a DELETE route in the routing table
         *
         * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
         * @param   string  $targetClass        Controller class name to instantiate
         * @param   string  $redirectFunction   Method name to call on the controller
         * @param   bool    $protected          Whether JWT authentication is required
         *
         * @return  void
         */
        public static function delete($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * Main routing dispatcher that handles HTTP requests
         *
         * This method:
         * - Validates HTTP method matches the target
         * - Parses the request URI and extracts parameters
         * - Handles authentication for protected routes
         * - Loads user and entity context
         * - Executes the target controller method
         * - Logs all requests for audit purposes
         *
         * @param   string  $targetMethod       Expected HTTP method (GET, POST, PUT, DELETE)
         * @param   string  $targetAction       URL pattern with optional placeholders like '/users/{id}'
         * @param   string  $targetClass        Fully qualified controller class name
         * @param   string  $redirectFunction   Method name to invoke on controller
         * @param   bool    $protected          If true, requires valid JWT token
         *
         * @return  void                        Outputs JSON response and exits
         */
        public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * Parse and extract the action path from REQUEST_URI
         *
         * Removes the 'api.php/' prefix to get the clean action path.
         * Example: '/api.php/users/123' becomes 'users/123'
         *
         * @return  string|false    The action path or false on error
         */
        private static function parseAction()
        {
        }
        /**
         * Match request action against target pattern using regex
         *
         * Supports URL placeholders like {id}, {code}, etc.
         * Example: pattern '/users/{id}' matches '/users/123'
         *
         * @param   string  $action         Actual request path from parseAction()
         * @param   string  $targetAction   Pattern with optional {placeholder} syntax
         *
         * @return  bool                    True if action matches pattern
         */
        private static function matchAction($action, $targetAction)
        {
        }
        /**
         * Parse and extract request data based on HTTP method
         *
         * - GET: extracts query string parameters from $_GET
         * - POST/PUT/DELETE: parses JSON body from php://input
         * - Validates JSON syntax and filters malicious input
         *
         * @param   string  $method     HTTP method (GET, POST, PUT, DELETE)
         *
         * @return  array               Associative array of request parameters
         */
        private static function parseRequestData($method)
        {
        }
        /**
         * Extract URL parameters from placeholder syntax
         *
         * Converts URL placeholders into associative array entries.
         * Example:
         *   targetAction: '/users/{id}/posts/{postid}'
         *   action: '/users/123/posts/456'
         *   Returns: ['id' => '123', 'postid' => '456']
         *
         * @param   string  $targetAction   Pattern with {placeholder} syntax
         * @param   string  $action         Actual request path
         * @param   array   $data           Existing data array to merge into
         *
         * @return  array                   Data array with extracted parameters
         */
        private static function extractUrlParameters($targetAction, $action, $data)
        {
        }
        /**
         * Handle JWT authentication and load user context
         *
         * For protected routes:
         * - Validates JWT token via AuthController::Check()
         * - Loads Dolibarr User object
         * - Sets entity context and reloads configuration
         * - Loads associated third-party (Societe) if exists
         *
         * For public routes:
         * - Returns empty context (null user/entity)
         *
         * @param   bool        $protected  Whether authentication is required
         * @param   Database    $db         Dolibarr database connection
         * @param   Conf        $conf       Dolibarr configuration object
         * @param   Societe     $mysoc      Dolibarr company object
         *
         * @return  array|false             [User, entity, token_id, Societe] or false on auth error
         */
        private static function handleAuthentication($protected, $db, $conf, $mysoc)
        {
        }
        /**
         * Execute the target controller method with proper error handling
         *
         * Process:
         * - Validates target class exists
         * - Validates target method exists on class
         * - Builds payload with request data and context
         * - Invokes controller method
         * - Validates response format [message, httpCode]
         * - Logs execution and returns JSON response
         *
         * @param   string      $targetClass        Controller class name
         * @param   string      $redirectFunction   Method name to call
         * @param   array       $data               Request parameters
         * @param   User|null   $user               Authenticated user or null
         * @param   int|null    $entity             Dolibarr entity ID
         * @param   int|null    $token_id           JWT token ID for logging
         * @param   Societe     $buyer              Third-party object
         * @param   int|null    $family_id          Token family
         * @param   int|null    $device_id          Device ID
         *
         * @return  void                            Outputs JSON and exits
         */
        private static function executeAction($targetClass, $redirectFunction, $data, $user, $entity, $token_id, $buyer, $family_id, $device_id)
        {
        }
        /**
         * Insert API request log entry into database
         *
         * Logs include:
         * - Token ID, app UID, entity
         * - Client IP (with proxy support)
         * - HTTP method and status code
         * - Request URL and user agent
         * - Response size in bytes
         *
         * Only logs when SMARTAUTH_COLLECT_LOGS configuration is enabled.
         * SQL values are properly escaped to prevent injection.
         *
         * @param   int|null    $keyid      JWT token ID (null for unauthenticated requests)
         * @param   int         $status     HTTP status code (200, 401, 404, etc.)
         * @param   string      $message    Response message or error description
         * @param   int         $entity     Dolibarr entity ID
         * @param   string      $element    Dolibarr element type (optional)
         *
         * @return  void
         */
        public static function insertLogs($keyid, $status, $message = "", $entity = 0, $element = "")
        {
        }
        /**
         * Get real client IP address with proxy support
         *
         * Handles various proxy configurations:
         * - Checks X-Forwarded-For header when behind proxy
         * - Validates REMOTE_ADDR is not a private IP
         * - Returns first IP from forwarded chain (most reliable)
         * - Falls back to REMOTE_ADDR for direct connections
         *
         * Private IP ranges detected:
         * - 127.x.x.x (localhost)
         * - 10.x.x.x (Class A private)
         * - 172.16-31.x.x (Class B private)
         * - 192.168.x.x (Class C private)
         *
         * @return  string      Client IP address
         */
        public static function get_client_ip()
        {
        }
    }
    class SmartFileControler
    {
        public function download($arr = null)
        {
        }
    }
    class SmartTokenConfig
    {
        // Access token: short-lived, used for API calls
        const ACCESS_TOKEN_LIFETIME = 3600;
        // 1 hour (can be 15min - 24h)
        // Refresh token: long-lived, used to get new access tokens
        const REFRESH_TOKEN_LIFETIME = 2592000;
        // 30 days (can be 7-90 days)
        // Maximum refresh count before forced re-authentication
        const MAX_REFRESH_COUNT = 100;
        // Token types
        const TYPE_ACCESS = 'access';
        const TYPE_REFRESH = 'refresh';
    }
}
namespace {
    function json_reply($message, $code)
    {
    }
}