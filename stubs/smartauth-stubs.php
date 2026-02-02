<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\Api {
    class RateLimiter
    {
        protected $db;
        /**
         * Cache key for last cleanup timestamp
         */
        const CLEANUP_CACHE_KEY = 'smartauth_ratelimit_last_cleanup';
        /**
         * Minimum interval between cleanups (in seconds)
         * Default: 1 hour
         */
        const CLEANUP_INTERVAL = 3600;
        /**
         * Maximum age of entries to keep (in seconds)
         * Default: 24 hours
         */
        const MAX_ENTRY_AGE = 86400;
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
         *
         * @param int $retention_seconds Maximum age of entries to keep
         * @return int Number of deleted rows, or -1 on error
         */
        public function cleanOldEntries($retention_seconds = null)
        {
        }
        /**
         * Perform cleanup if enough time has passed since last cleanup
         *
         * Uses database-based timestamp to ensure cleanup happens even
         * across multiple PHP processes/servers.
         *
         * @return bool True if cleanup was performed
         */
        private function maybeCleanup()
        {
        }
        /**
         * Get last cleanup timestamp from database
         *
         * @return int Unix timestamp of last cleanup, or 0 if never run
         */
        private function getLastCleanupTime()
        {
        }
        /**
         * Update last cleanup timestamp in database
         */
        private function setLastCleanupTime()
        {
        }
        /**
         * Force cleanup now (for cron jobs or admin actions)
         *
         * @param int $retention_seconds Maximum age of entries to keep
         * @return int Number of deleted rows
         */
        public function forceCleanup($retention_seconds = null)
        {
        }
        /**
         * Get statistics about the rate limit table
         *
         * @return array ['total_entries' => int, 'oldest_entry' => int, 'newest_entry' => int]
         */
        public function getStats()
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
         * @return object{
         *     login: string,
         *     token_id: int,
         *     user_id: int,
         *     entity: int,
         *     token_type: string,
         *     family_id: int,
         *     device_id: int,
         *     refresh_count: int,
         *     exp: int
         * }|null Decoded token payload object with user info, or null on failure (calls json_reply on error)
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
         * Validate Bearer token format
         *
         * Expected format: "token_id|jwt_token"
         * - token_id: numeric identifier (1-20 digits)
         * - jwt_token: standard JWT format (base64url.base64url.base64url)
         *
         * @param string $token Raw token string
         * @return bool True if format is valid
         */
        private static function _validateBearerTokenFormat($token)
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
         * @param   \User|null  current dolibarr user
         *
         * @return  \User    	dolibarr user
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
         * Validate and sanitize login/email input
         *
         * Uses InputSanitizer for consistent validation across the codebase.
         * Accepts both email format and plain username (for backwards compatibility)
         *
         * @param string $input Raw login/email input
         * @return string Sanitized login or empty string if invalid
         */
        private function _validateAndSanitizeLogin($input)
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
         * Generate a JWT token and store it in the database
         *
         * Creates an authentication token (access or refresh) for a user/element,
         * stores it in the smartauth_auth table, and returns the formatted token string.
         *
         * @param string      $element      The element type being authenticated ('user', 'societe_account', etc.)
         * @param int         $element_id   The ID of the element (user ID, societe ID, etc.)
         * @param int         $user_id      The user ID associated with this token
         * @param string      $login        The login/username for the token payload
         * @param int         $entity       The entity ID (for multi-company support)
         * @param string      $token_type   The type of token ('access' or 'refresh')
         * @param int         $lifetime     Token lifetime in seconds
         * @param int         $family_id    The token family ID (parent token ID for token rotation)
         * @param int         $device_id    The device ID associated with this token
         * @param string|null $device_uuid  Optional device UUID for additional identification
         *
         * @return string|null The generated token in format "rowid|jwt_token", or null on failure
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
         * Mark a JWT ID (jti) as used to prevent replay attacks
         * This operation is atomic - if the jti already exists, returns false
         *
         * @param string $jti The JWT ID to mark as used
         * @param int|null $token_id Optional token ID for reference
         * @return bool True if marked successfully (first use), false if already used (replay detected)
         */
        private function _markJtiAsUsed($jti, $token_id = null)
        {
        }
        /**
         * Extract jti from a token without full validation
         * Used for early replay detection before expensive signature verification
         *
         * @param string $token The full token in format "token_id|jwt"
         * @return string|null The jti if found, null otherwise
         */
        private function _extractJtiFromToken($token)
        {
        }
        /**
         * Cleanup old jti entries to prevent table bloat
         * Should be called periodically (e.g., via cron or on each refresh)
         *
         * @param int $max_age_seconds Maximum age of jti entries to keep (default: 30 days)
         * @return int Number of entries deleted
         */
        private function _cleanupOldJti($max_age_seconds = 2592000)
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
        /**
         * Decode and validate a JWT token
         *
         * Decodes the JWT token, validates its signature and expiration,
         * verifies it exists in the database with valid status, and updates
         * usage statistics (last used date, IP address, EOL).
         *
         * @param string $token     The JWT token to decode (format: "rowid|jwt_token")
         * @param string $checktype The expected token type to validate ('access' or 'refresh')
         *
         * @return object{
         *     login: string,
         *     token_id: int,
         *     user_id: int,
         *     entity: int,
         *     token_type: string,
         *     family_id: int,
         *     device_id: int,
         *     refresh_count: int,
         *     exp: int
         * }|null Decoded token payload object with user info, or null on failure (calls json_reply on error)
         */
        private static function _decodeJWT($token, $checktype)
        {
        }
        /**
         * Generate tokens for an already authenticated Dolibarr user
         *
         * Use this when user is already logged in Dolibarr (session-based)
         * and needs a JWT token for API calls (e.g., from embedded React apps)
         *
         * @param User $user Dolibarr User object (already authenticated)
         * @param int $entity Entity ID
         * @param string $device_label Optional device label (default: 'Dolibarr Web')
         * @param string $device_uuid Optional device UUID. If not provided, uses User-Agent hash for consistency with _getSalt2 fallback
         * @return array ['access_token' => string, 'refresh_token' => string, 'expires_in' => int, 'device_uuid' => string]
         */
        public function generateTokenForAuthenticatedUser($user, $entity = 1, $device_label = 'Dolibarr Web', $device_uuid = '')
        {
        }
    }
    class InputSanitizer
    {
        /**
         * Maximum string length for general text fields
         */
        const MAX_STRING_LENGTH = 255;
        /**
         * Cache for external sanitizers loaded via hook
         *
         * @var array|null
         */
        private static ?array $externalSanitizers = null;
        /**
         * Load sanitizers from external modules via hook
         *
         * External modules can register custom sanitization types by implementing
         * the hook smartmaker_addSanitizers in their actions class.
         *
         * Example implementation in a module:
         * ```php
         * public function smartmaker_addSanitizers($parameters, &$sanitizers, &$action, $hookmanager) {
         *     $sanitizers['phone_fr'] = function($value, $rules, $field) {
         *         $clean = preg_replace('/[^0-9+]/', '', $value);
         *         if (preg_match('/^(?:\+33|0)[1-9][0-9]{8}$/', $clean)) {
         *             return $clean;
         *         }
         *         if ($rules['required'] ?? false) {
         *             throw new \InvalidArgumentException("Invalid phone format for field: $field");
         *         }
         *         return null;
         *     };
         *     return 0;
         * }
         * ```
         *
         * @param bool $forceReload Force reloading sanitizers (bypass cache)
         *
         * @return array External sanitizers indexed by type name
         */
        public static function loadExternalSanitizers(bool $forceReload = false) : array
        {
        }
        /**
         * Clear the external sanitizers cache
         * Useful for testing or when modules are dynamically loaded
         *
         * @return void
         */
        public static function clearCache() : void
        {
        }
        /**
         * Maximum string length for short fields (labels, names)
         */
        const MAX_SHORT_LENGTH = 100;
        /**
         * Maximum string length for long text fields
         */
        const MAX_LONG_LENGTH = 1000;
        /**
         * Sanitization type constants
         */
        const TYPE_STRING = 'string';
        const TYPE_EMAIL = 'email';
        const TYPE_INT = 'int';
        const TYPE_FLOAT = 'float';
        const TYPE_BOOL = 'bool';
        const TYPE_UUID = 'uuid';
        const TYPE_ALPHANUMERIC = 'alphanumeric';
        const TYPE_ARRAY = 'array';
        const TYPE_RAW = 'raw';
        // No sanitization (use with caution)
        /**
         * Sanitize all data according to a schema
         *
         * Schema format:
         * [
         *     'fieldname' => ['type' => 'string', 'maxLen' => 100, 'required' => true],
         *     'email' => ['type' => 'email'],
         *     'count' => ['type' => 'int', 'min' => 0, 'max' => 1000],
         * ]
         *
         * @param array $data   Raw input data
         * @param array $schema Validation schema
         *
         * @return array Sanitized data
         * @throws \InvalidArgumentException If required field is missing or validation fails
         */
        public static function sanitize(array $data, array $schema) : array
        {
        }
        /**
         * Sanitize all fields with default string sanitization
         * Used when no schema is provided
         *
         * @param array $data Raw input data
         *
         * @return array Sanitized data
         */
        public static function sanitizeAll(array $data) : array
        {
        }
        /**
         * Dispatch sanitization based on type
         *
         * @param mixed  $value Raw value
         * @param string $type  Sanitization type
         * @param array  $rules Additional rules
         * @param string $field Field name for error messages
         *
         * @return mixed Sanitized value
         */
        private static function sanitizeByType($value, string $type, array $rules, string $field)
        {
        }
        /**
         * Sanitize a string value
         * - Removes null bytes
         * - Strips HTML tags
         * - Converts special characters to HTML entities
         * - Trims whitespace
         * - Limits length
         *
         * @param mixed $value  Raw value
         * @param int   $maxLen Maximum length
         *
         * @return string Sanitized string
         */
        public static function sanitizeString($value, int $maxLen = self::MAX_STRING_LENGTH) : string
        {
        }
        /**
         * Sanitize and validate an email address
         *
         * @param mixed $value Raw value
         *
         * @return string|null Validated email or null if invalid
         */
        public static function sanitizeEmail($value) : ?string
        {
        }
        /**
         * Sanitize and validate a UUID
         * Accepts standard UUID format (8-4-4-4-12) or SHA256 hash (64 hex chars)
         *
         * @param mixed $value Raw value
         *
         * @return string|null Validated UUID or null if invalid
         */
        public static function sanitizeUUID($value) : ?string
        {
        }
        /**
         * Sanitize an integer value
         *
         * @param mixed $value Raw value
         *
         * @return int Sanitized integer
         */
        public static function sanitizeInt($value) : int
        {
        }
        /**
         * Sanitize a float value
         *
         * @param mixed $value Raw value
         *
         * @return float Sanitized float
         */
        public static function sanitizeFloat($value) : float
        {
        }
        /**
         * Sanitize a boolean value
         *
         * @param mixed $value Raw value
         *
         * @return bool Sanitized boolean
         */
        public static function sanitizeBool($value) : bool
        {
        }
        /**
         * Sanitize to alphanumeric characters only (plus hyphen and underscore)
         *
         * @param mixed $value  Raw value
         * @param int   $maxLen Maximum length
         *
         * @return string Sanitized alphanumeric string
         */
        public static function sanitizeAlphanumeric($value, int $maxLen = self::MAX_STRING_LENGTH) : string
        {
        }
        /**
         * Sanitize a username (alphanumeric plus hyphen, underscore, and dot)
         *
         * @param mixed $value  Raw value
         * @param int   $maxLen Maximum length
         *
         * @return string|null Sanitized username or null if invalid
         */
        public static function sanitizeUsername($value, int $maxLen = self::MAX_STRING_LENGTH) : ?string
        {
        }
        /**
         * Sanitize an array of values
         *
         * @param array  $value    Raw array
         * @param string $itemType Type of items in array
         * @param array  $rules    Additional rules
         *
         * @return array Sanitized array
         */
        public static function sanitizeArray(array $value, string $itemType = self::TYPE_STRING, array $rules = []) : array
        {
        }
        /**
         * Sanitize IP address
         *
         * @param mixed $value Raw value
         *
         * @return string|null Validated IP or null if invalid
         */
        public static function sanitizeIP($value) : ?string
        {
        }
        /**
         * Sanitize URL
         *
         * @param mixed $value Raw value
         *
         * @return string|null Validated URL or null if invalid
         */
        public static function sanitizeURL($value) : ?string
        {
        }
        /**
         * Validate a value against a whitelist of allowed values
         *
         * @param mixed $value   Raw value
         * @param array $allowed Allowed values
         * @param mixed $default Default value if not in whitelist
         *
         * @return mixed Value if allowed, default otherwise
         */
        public static function validateEnum($value, array $allowed, $default = null)
        {
        }
        /**
         * Sanitize for SQL-safe logging (escape then truncate)
         * Use this for fields that will be inserted into logs
         *
         * @param mixed    $value  Raw value
         * @param int      $maxLen Maximum length
         * @param Database $db     Dolibarr database object for escaping
         *
         * @return string Sanitized and escaped string
         */
        public static function sanitizeForLog($value, int $maxLen, $db) : string
        {
        }
    }
    class JwtKeyHelper
    {
        /**
         * Minimum required key length
         */
        const MIN_KEY_LENGTH = 32;
        /**
         * Default key length for generation (64 hex chars = 32 bytes)
         */
        const DEFAULT_KEY_LENGTH = 64;
        /**
         * Get or auto-generate JWT key for a module
         *
         * This method provides lazy initialization of JWT keys.
         * If the key doesn't exist or is too short, a new secure key
         * is automatically generated and stored in Dolibarr configuration.
         *
         * Called internally by AuthController. Module name is auto-detected
         * from RouteCache::getModuleName() which is set by RouteCache::init().
         *
         * @param string|null $moduleName Module name (auto-detected from RouteCache if null)
         * @return string The JWT key (at least 32 characters)
         * @throws \InvalidArgumentException If module name cannot be determined
         */
        public static function getKey(?string $moduleName = null) : string
        {
        }
        /**
         * Generate a cryptographically secure random key
         *
         * @param int $length Key length in characters (hex string)
         * @return string Hexadecimal key string
         */
        public static function generateKey(int $length = self::DEFAULT_KEY_LENGTH) : string
        {
        }
        /**
         * Store key in Dolibarr configuration
         *
         * @param object $db Database handler
         * @param string $configKey Configuration key name
         * @param string $key Key value to store
         * @return bool Success
         */
        private static function storeKey($db, string $configKey, string $key) : bool
        {
        }
        /**
         * Check if a valid JWT key exists for a module
         *
         * @param string $moduleName Module name
         * @return bool True if a valid key exists
         */
        public static function hasValidKey(string $moduleName) : bool
        {
        }
        /**
         * Force regeneration of JWT key for a module
         *
         * WARNING: This will invalidate all existing tokens for the module.
         * Use with caution, typically only for security incidents.
         *
         * @param string $moduleName Module name
         * @return string|false New key on success, false on failure
         */
        public static function rotateKey(string $moduleName)
        {
        }
        /**
         * Get the configuration key name for a module
         *
         * @param string $moduleName Module name
         * @return string Configuration key (e.g., 'MYMODULE_JWT_KEY')
         */
        public static function getConfigKeyName(string $moduleName) : string
        {
        }
    }
    class LogSanitizer
    {
        /**
         * Mask an IP address for privacy
         * Keeps first two octets for IPv4, first 4 groups for IPv6
         *
         * @param string $ip Raw IP address
         * @return string Masked IP (e.g., "192.168.xxx.xxx")
         */
        public static function maskIP($ip)
        {
        }
        /**
         * Mask an email address for privacy
         * Shows first 2 chars of local part and domain
         *
         * @param string $email Raw email
         * @return string Masked email (e.g., "us***@example.com")
         */
        public static function maskEmail($email)
        {
        }
        /**
         * Truncate and sanitize User-Agent for logging
         * Removes version numbers and keeps only browser/OS family
         *
         * @param string $userAgent Raw User-Agent string
         * @param int $maxLen Maximum length (default 50)
         * @return string Sanitized User-Agent
         */
        public static function sanitizeUserAgent($userAgent, $maxLen = 50)
        {
        }
        /**
         * Mask a token for logging (shows only first and last 4 chars)
         *
         * @param string $token Raw token
         * @return string Masked token (e.g., "eyJ0...abc1")
         */
        public static function maskToken($token)
        {
        }
        /**
         * Mask a salt or secret key for logging
         * Shows only first 4 chars
         *
         * @param string $salt Raw salt
         * @return string Masked salt (e.g., "a1b2...")
         */
        public static function maskSalt($salt)
        {
        }
        /**
         * Sanitize URL for logging
         * Removes query string parameters that might contain sensitive data
         *
         * @param string $url Raw URL
         * @param int $maxLen Maximum length (default 255)
         * @return string Sanitized URL
         */
        public static function sanitizeURL($url, $maxLen = 255)
        {
        }
        /**
         * Mask device UUID for logging
         * Shows only first 8 chars
         *
         * @param string $uuid Raw UUID
         * @return string Masked UUID (e.g., "a1b2c3d4-****-****-****-************")
         */
        public static function maskUUID($uuid)
        {
        }
        /**
         * Create a safe log entry array from raw data
         * Applies appropriate masking to all sensitive fields
         *
         * @param array $data Raw data array
         * @return array Sanitized data array safe for logging
         */
        public static function sanitizeLogData(array $data)
        {
        }
    }
    class PasswordResetController
    {
        /**
         * Token expiry time in seconds (1 hour)
         */
        private const TOKEN_EXPIRY_SECONDS = 3600;
        /**
         * Rate limit: max requests per window
         */
        private const RATE_LIMIT_MAX_ATTEMPTS = 3;
        /**
         * Rate limit: window in seconds (15 minutes)
         */
        private const RATE_LIMIT_WINDOW = 900;
        /**
         * Request password reset
         *
         * Sends an email with a password reset link if the email exists.
         * Always returns success to prevent email enumeration.
         *
         * @param array|null $arr Request parameters containing 'email'
         * @return array Response
         */
        public function requestReset($arr = null)
        {
        }
        /**
         * Generate a token with embedded expiry timestamp
         *
         * Format: base64(random_token|expiry_timestamp)
         *
         * @return string
         */
        private function generateTokenWithExpiry() : string
        {
        }
        /**
         * Validate a token and check expiry
         *
         * @param string $token Token to validate
         * @return array ['valid' => bool, 'token' => string|null, 'expired' => bool]
         */
        public static function validateToken(string $token) : array
        {
        }
        /**
         * Send password reset email
         *
         * @param User $user User object
         * @param string $token Reset token
         * @return bool Success
         */
        private function sendResetEmail($user, $token)
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
    class RouteCache
    {
        /**
         * Current module name (set via init())
         * @var string
         */
        private static $moduleName = '';
        /**
         * Routes being registered (before cache generation)
         * @var array
         */
        private static $registeredRoutes = [];
        /**
         * Cached routes loaded from file
         * @var array|null
         */
        private static $cachedRoutes = null;
        /**
         * Whether we are in registration mode
         * @var bool
         */
        private static $registrationMode = false;
        /**
         * Source file that defines routes (detected via backtrace)
         * @var string
         */
        private static $sourceFile = '';
        /**
         * Initialize the cache for a specific module
         *
         * Must be called before any other method.
         * Example: RouteCache::init('smartmaker');
         *
         * @param string $moduleName Module name (lowercase, e.g., 'smartmaker')
         * @return void
         */
        public static function init(string $moduleName) : void
        {
        }
        /**
         * Get the cache file path for current module
         *
         * @return string
         */
        public static function getCacheFilePath() : string
        {
        }
        /**
         * Get the version config key for current module
         *
         * @return string Config key like 'SMARTMAKER_VERSION'
         */
        private static function getVersionConfigKey() : string
        {
        }
        /**
         * Get the current module version
         *
         * @return string
         */
        public static function getCurrentVersion() : string
        {
        }
        /**
         * Check if cache is valid (exists and version matches)
         *
         * @return bool
         */
        public static function isCacheValid() : bool
        {
        }
        /**
         * Scan for LocalRoutes.php files in active modules
         *
         * @return array Map of file path => modification time
         */
        private static function scanLocalRoutesFiles() : array
        {
        }
        /**
         * Load routes from cache file
         *
         * @return array|null
         */
        private static function loadCacheFile() : ?array
        {
        }
        /**
         * Start registration mode - routes will be collected for caching
         *
         * @return void
         */
        public static function startRegistration() : void
        {
        }
        /**
         * Register a route (called during registration mode)
         *
         * @param string $method HTTP method
         * @param string $action URL pattern
         * @param string $class Controller class
         * @param string $function Controller method
         * @param bool $protected Whether route requires auth
         * @return void
         */
        public static function register(string $method, string $action, string $class, string $function, bool $protected) : void
        {
        }
        /**
         * End registration mode and save cache
         *
         * @return bool True if cache was saved successfully
         */
        public static function endRegistration() : bool
        {
        }
        /**
         * Include LocalRoutes.php files from modules during registration
         *
         * This allows modules to define routes using the same Route::get(), Route::post()
         * syntax as the main api.php file.
         *
         * @return void
         */
        private static function includeLocalRoutes() : void
        {
        }
        /**
         * Save routes to cache file
         *
         * @param array $routes Routes to cache
         * @return bool
         */
        private static function saveCache(array $routes) : bool
        {
        }
        /**
         * Optimize routes for fast lookup
         *
         * Separates static routes (hash lookup) from dynamic routes (regex matching)
         *
         * @param array $routes Raw routes
         * @return array Optimized structure
         */
        private static function optimizeRoutes(array $routes) : array
        {
        }
        /**
         * Convert action pattern to regex
         *
         * @param string $action Action pattern like 'users/{id}/posts/{postId}'
         * @return string Regex pattern
         */
        private static function actionToRegex(string $action) : string
        {
        }
        /**
         * Load cached routes into memory
         *
         * @return bool True if cache was loaded
         */
        public static function loadCache() : bool
        {
        }
        /**
         * Find a matching route in cache
         *
         * @param string $method HTTP method
         * @param string $action Request action/path
         * @return array|null Route info or null if not found
         */
        public static function findRoute(string $method, string $action) : ?array
        {
        }
        /**
         * Extract URL parameters from action
         *
         * @param string $pattern Pattern like 'users/{id}'
         * @param string $action Actual path like 'users/123'
         * @return array Parameters ['id' => '123']
         */
        private static function extractParams(string $pattern, string $action) : array
        {
        }
        /**
         * Check if we're in registration mode
         *
         * @return bool
         */
        public static function isRegistrationMode() : bool
        {
        }
        /**
         * Get the current module name
         *
         * @return string Module name (lowercase) or empty string if not initialized
         */
        public static function getModuleName() : string
        {
        }
        /**
         * Get all cached routes (for debugging)
         *
         * @return array|null
         */
        public static function getCachedRoutes() : ?array
        {
        }
        /**
         * Clear the cache file
         *
         * @return bool
         */
        public static function clearCache() : bool
        {
        }
    }
    class RouteController
    {
        /**
         * Dispatch request using cached routes (optimized)
         *
         * This is the main entry point when using route caching.
         * It finds the matching route from cache and executes it.
         *
         * @return bool True if a route was matched and executed
         */
        public static function dispatch() : bool
        {
        }
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
         * Register a PATCH route in the routing table
         *
         * @param   string  $targetAction       URL pattern to match (e.g., '/users/{id}')
         * @param   string  $targetClass        Controller class name to instantiate
         * @param   string  $redirectFunction   Method name to call on the controller
         * @param   bool    $protected          Whether JWT authentication is required
         *
         * @return  void
         */
        public static function patch($targetAction, $targetClass, $redirectFunction, $protected = false)
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
         * - Applies sanitization via InputSanitizer middleware
         *
         * @param   string  $method     HTTP method (GET, POST, PUT, DELETE)
         * @param   string|null $targetAction   Route pattern for schema lookup
         *
         * @return  array                       Associative array of sanitized request parameters
         */
        private static function parseRequestData($method, $targetAction = null)
        {
        }
        /**
         * Apply sanitization to request data
         *
         * Uses schema-based validation if a schema exists for the endpoint,
         * otherwise applies default sanitization to all fields.
         *
         * @param   array       $data           Raw request data
         * @param   string|null $targetAction   Route pattern for schema lookup
         *
         * @return  array                       Sanitized data
         */
        private static function sanitizeRequestData(array $data, $targetAction = null)
        {
        }
        /**
         * Sanitize a single field based on schema rules
         *
         * @param   mixed   $value  Raw value
         * @param   array   $rules  Validation rules from schema
         * @param   string  $field  Field name for error messages
         *
         * @return  mixed           Sanitized value
         */
        private static function sanitizeField($value, array $rules, string $field)
        {
        }
        /**
         * Sanitize an unknown field (not in schema)
         *
         * @param   string  $key    Field name
         * @param   mixed   $value  Raw value
         *
         * @return  mixed           Sanitized value
         */
        private static function sanitizeUnknownField(string $key, $value)
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
        /**
         * Handle CORS headers and preflight requests
         *
         * Sets appropriate CORS headers for cross-origin requests and
         * handles OPTIONS preflight requests automatically.
         *
         * Configuration via Dolibarr constants:
         * - SMARTAUTH_CORS_ORIGIN: Allowed origin (default: '*')
         * - SMARTAUTH_CORS_METHODS: Allowed methods (default: 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
         * - SMARTAUTH_CORS_HEADERS: Allowed headers (default: 'Content-Type, Authorization, X-DeviceId')
         *
         * @return void
         */
        public static function handleCORS() : void
        {
        }
        /**
         * Check if CORS headers are properly configured
         *
         * This is a lightweight check that runs once per session.
         * It logs a warning if CORS doesn't appear to be configured,
         * which could indicate a security misconfiguration.
         *
         * The check is performed only when:
         * - Origin header is present (cross-origin request)
         * - Check hasn't been performed in this session
         *
         * @return void
         */
        private static function checkCORSConfiguration()
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
    class SyncController
    {
        /**
         * @var \DoliDB Database connection
         */
        private $db;
        /**
         * Mapping of object types to their configuration
         * Keys: object type names used in API
         * Values: configuration arrays with class, table, module info
         *
         * @var array
         */
        private $syncableObjects = [];
        public function __construct()
        {
        }
        /**
         * Load syncable objects configuration
         * Includes built-in objects and those registered via hooks
         */
        private function loadSyncableObjects()
        {
        }
        /**
         * @api {post} /sync/register Register sync client
         * @apiName RegisterSyncClient
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Register a new sync client for offline synchronization.
         * The client UUID should be unique per device.
         *
         * @apiHeader {String} Authorization Bearer access_token
         * @apiHeader {String} X-DeviceId Device UUID
         *
         * @apiBody {String} client_uuid Unique client identifier (UUID format)
         * @apiBody {String} [app_version] Application version
         * @apiBody {String[]} [sync_scope] List of object types to sync (default: all enabled)
         *
         * @apiSuccess {Number} client_id Internal client ID
         * @apiSuccess {String} client_uuid Client UUID
         * @apiSuccess {String} server_time Current server timestamp
         * @apiSuccess {Object} sync_scope Enabled sync object types
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "client_id": 123,
         *     "client_uuid": "abc-123-def",
         *     "server_time": "2025-01-19T10:30:00+00:00",
         *     "sync_scope": {
         *         "thirdparty": true,
         *         "contact": true,
         *         "product": true
         *     }
         * }
         */
        public function register($payload)
        {
        }
        /**
         * @api {get} /sync/pull Pull changes from server
         * @apiName PullChanges
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Get all changes since last sync for a specific object type.
         *
         * @apiHeader {String} Authorization Bearer access_token
         *
         * @apiQuery {String} client_uuid Client UUID
         * @apiQuery {String} object_type Object type to pull (thirdparty, contact, product...)
         * @apiQuery {String} [last_sync_at] ISO timestamp of last sync (optional, uses stored value if not provided)
         *
         * @apiSuccess {Object[]} updated List of updated/created objects
         * @apiSuccess {Object[]} deleted List of deleted object IDs with timestamps
         * @apiSuccess {String} server_time Current server timestamp for next sync
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "updated": [
         *         {"id": 1, "name": "Company A", "tms": "2025-01-19T10:00:00+00:00"},
         *         {"id": 2, "name": "Company B", "tms": "2025-01-19T10:15:00+00:00"}
         *     ],
         *     "deleted": [
         *         {"id": 5, "deleted_at": "2025-01-19T09:00:00+00:00"}
         *     ],
         *     "server_time": "2025-01-19T10:30:00+00:00"
         * }
         */
        public function pull($payload)
        {
        }
        /**
         * @api {post} /sync/push Push changes to server
         * @apiName PushChanges
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Push local changes to the server. Uses tms-based conflict detection.
         *
         * @apiHeader {String} Authorization Bearer access_token
         *
         * @apiBody {String} client_uuid Client UUID
         * @apiBody {String} object_type Object type being pushed
         * @apiBody {Object[]} changes Array of changes to push
         * @apiBody {Number} changes.id Object ID (0 for new objects)
         * @apiBody {String} changes.action Action: create, update, delete
         * @apiBody {Object} changes.data Object data
         * @apiBody {String} changes.base_tms Base tms when client fetched the object
         *
         * @apiSuccess {Number[]} success IDs of successfully applied changes
         * @apiSuccess {Object[]} conflicts Changes that resulted in conflicts
         * @apiSuccess {Object[]} errors Changes that failed
         * @apiSuccess {Object} id_mapping Mapping of temp_id to server_id for creates
         * @apiSuccess {String} server_time Current server timestamp
         */
        public function push($payload)
        {
        }
        /**
         * @api {get} /sync/status Get sync status
         * @apiName SyncStatus
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Get synchronization status for a client.
         *
         * @apiHeader {String} Authorization Bearer access_token
         *
         * @apiQuery {String} client_uuid Client UUID
         *
         * @apiSuccess {String} client_uuid Client UUID
         * @apiSuccess {String} last_sync_at Last successful sync timestamp
         * @apiSuccess {Number} pending_conflicts Number of unresolved conflicts
         * @apiSuccess {String} server_time Current server time
         * @apiSuccess {Object} sync_scope Enabled sync types
         */
        public function status($payload)
        {
        }
        /**
         * @api {get} /sync/conflicts List pending conflicts
         * @apiName ListConflicts
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Get list of unresolved conflicts for a client.
         *
         * @apiHeader {String} Authorization Bearer access_token
         *
         * @apiQuery {String} client_uuid Client UUID
         *
         * @apiSuccess {Object[]} conflicts List of pending conflicts
         */
        public function conflicts($payload)
        {
        }
        /**
         * @api {post} /sync/conflicts/{id}/resolve Resolve a conflict
         * @apiName ResolveConflict
         * @apiGroup Sync
         * @apiVersion 1.0.0
         *
         * @apiDescription Resolve a sync conflict.
         *
         * @apiHeader {String} Authorization Bearer access_token
         *
         * @apiParam {Number} id Conflict ID
         *
         * @apiBody {String} resolution Resolution strategy: client, server, or merged
         * @apiBody {Object} [data] Merged data (required if resolution=merged)
         *
         * @apiSuccess {Boolean} success Whether resolution was applied
         * @apiSuccess {String} message Result message
         */
        public function resolveConflict($payload)
        {
        }
        // =====================================================================
        // Private helper methods
        // =====================================================================
        /**
         * Determine sync scope from request or defaults
         */
        private function determineSyncScope($requested_scope)
        {
        }
        /**
         * Get client by UUID
         */
        private function getClientByUUID($uuid)
        {
        }
        /**
         * Format object for sync response
         */
        private function formatObjectForSync($obj, $object_type)
        {
        }
        /**
         * Process a CREATE operation
         */
        private function processCreate($config, $data, $user)
        {
        }
        /**
         * Process an UPDATE operation with conflict detection
         */
        private function processUpdate($config, $id, $data, $base_tms, $client_id, $user)
        {
        }
        /**
         * Detect if there's a real data conflict (not just tms mismatch)
         * Returns array of conflicting fields or null if no real conflict
         */
        private function detectRealConflict($client_data, $server_obj, $config)
        {
        }
        /**
         * Normalize value for comparison
         */
        private function normalizeValue($value)
        {
        }
        /**
         * Create a conflict record in the database
         */
        private function createConflictRecord($client_id, $table, $object_id, $client_data, $server_obj, $client_tms, $server_tms, $field_conflicts)
        {
        }
        /**
         * Process a DELETE operation
         */
        private function processDelete($config, $id, $base_tms, $user)
        {
        }
        /**
         * Create a tombstone record for a deleted object
         */
        private function createTombstone($table, $object_id, $user_id)
        {
        }
        /**
         * Apply resolved conflict data to database
         */
        private function applyResolvedData($config, $id, $data, $user)
        {
        }
        /**
         * Get object type from table name
         */
        private function getObjectTypeFromTable($table)
        {
        }
        /**
         * Update client's last sync timestamp
         */
        private function updateClientSyncTimestamp($client_id)
        {
        }
        /**
         * Log a sync event for audit
         */
        private function logSyncEvent($client_id, $event_type, $table_name = null, $object_id = null, $event_data = null)
        {
        }
    }
    class ValidationSchemas
    {
        /**
         * Cache for external schemas loaded via hook
         *
         * @var array|null
         */
        private static ?array $externalSchemas = null;
        /**
         * Get validation schema for a specific endpoint
         *
         * @param string $endpoint Endpoint identifier (e.g., 'login', 'device', 'refresh')
         *
         * @return array|null Validation schema or null if no specific schema defined
         */
        public static function getSchema(string $endpoint) : ?array
        {
        }
        /**
         * Get validation schema for a specific module and endpoint
         *
         * @param string $module   Module identifier (e.g., 'interventions', 'smartauth')
         * @param string $endpoint Endpoint identifier (e.g., 'POST:/interventions')
         *
         * @return array|null Validation schema or null if not found
         */
        public static function getSchemaForModule(string $module, string $endpoint) : ?array
        {
        }
        /**
         * Load validation schemas from external modules via hook
         *
         * External modules can register their schemas by implementing
         * the hook smartmaker_addValidationSchemas in their actions class.
         *
         * Example implementation in a module:
         * ```php
         * public function smartmaker_addValidationSchemas($parameters, &$schemas, &$action, $hookmanager) {
         *     $schemas['mymodule'] = [
         *         'POST:/myendpoint' => [
         *             'field1' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true],
         *             'field2' => ['type' => InputSanitizer::TYPE_INT, 'min' => 0],
         *         ],
         *     ];
         *     return 0;
         * }
         * ```
         *
         * @param bool $forceReload Force reloading schemas (bypass cache)
         *
         * @return array External schemas indexed by module name
         */
        public static function loadExternalSchemas(bool $forceReload = false) : array
        {
        }
        /**
         * Clear the external schemas cache
         * Useful for testing or when modules are dynamically loaded
         *
         * @return void
         */
        public static function clearCache() : void
        {
        }
        /**
         * Get all validation schemas (SmartAuth + external modules)
         *
         * @param bool $includeExternal Include schemas from external modules
         *
         * @return array All schemas indexed by endpoint (or by module then endpoint if external)
         */
        public static function getAllSchemas(bool $includeExternal = false) : array
        {
        }
        /**
         * Map route pattern to schema name
         *
         * @param string $targetAction Route pattern (e.g., 'login', 'device', 'users/{id}')
         *
         * @return string Schema name
         */
        public static function mapRouteToSchema(string $targetAction) : string
        {
        }
        /**
         * Whitelist of allowed enum values for specific fields
         *
         * @return array Enum definitions
         */
        public static function getEnumWhitelists() : array
        {
        }
        /**
         * Validate an enum value against its whitelist
         *
         * @param string $enumName Name of the enum
         * @param mixed  $value    Value to validate
         * @param mixed  $default  Default value if not valid
         *
         * @return mixed Validated value or default
         */
        public static function validateEnum(string $enumName, $value, $default = null)
        {
        }
    }
}
namespace {
    function json_reply($message, $code)
    {
    }
}