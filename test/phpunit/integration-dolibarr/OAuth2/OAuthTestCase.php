<?php

/**
 * Base test case for OAuth2 integration tests
 *
 * Provides helper methods for creating test clients, tokens, and authorization codes.
 * Extends DolibarrRealTestCase for database access.
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\OAuth2\PKCEHelper;
use SmartAuth\Api\OAuth2\TokenService;
use SmartAuth\Api\OAuth2\OAuthConfig;

dol_include_once('/smartauth/class/smartauthoauthclient.class.php');
dol_include_once('/smartauth/class/smartauthoauthcode.class.php');
dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');
dol_include_once('/smartauth/class/smartauthoauthconsent.class.php');
dol_include_once('/smartauth/api/OAuth2/PKCEHelper.php');
dol_include_once('/smartauth/api/OAuth2/ScopeManager.php');
dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');

abstract class OAuthTestCase extends DolibarrRealTestCase
{
    /**
     * Token service instance
     * @var TokenService
     */
    protected $tokenService;

    /**
     * Loaded fixtures
     * @var array
     */
    protected static $fixtures;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenService = new TokenService($this->db);

        // Clean OAuth tables
        $this->cleanOAuthTables();

        // Configure OAuth for tests
        $this->configureOAuthForTests();
    }

    /**
     * Load fixtures if not already loaded
     */
    protected function loadFixtures(): array
    {
        if (self::$fixtures === null) {
            $fixturesPath = dirname(__DIR__, 2) . '/fixtures/oauth_clients.php';
            self::$fixtures = require $fixturesPath;
        }
        return self::$fixtures;
    }

    /**
     * Clean OAuth-specific tables between tests
     */
    protected function cleanOAuthTables(): void
    {
        $tables = [
            'smartauth_oauth_tokens',
            'smartauth_oauth_codes',
            'smartauth_oauth_consents',
            'smartauth_oauth_clients',
        ];

        foreach ($tables as $table) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table);
        }
    }

    /**
     * Configure OAuth settings for test environment
     */
    protected function configureOAuthForTests(): void
    {
        global $conf;

        $conf->global->SMARTAUTH_OAUTH_ENABLED = 1;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_OAUTH_ACCESS_TTL = 3600;
        $conf->global->SMARTAUTH_OAUTH_REFRESH_TTL = 2592000;
        $conf->global->SMARTAUTH_OAUTH_CODE_TTL = 600;
        $conf->global->SMARTAUTH_OAUTH_REQUIRE_PKCE = 1;
        $conf->global->SMARTAUTH_OAUTH_CONSENT_REMEMBER = 1;
    }

    /**
     * Create a test OAuth client
     *
     * @param array $overrides Override default values
     * @return \SmartAuthOAuthClient Created client
     */
    protected function createTestClient(array $overrides = []): \SmartAuthOAuthClient
    {
        $defaults = [
            'ref' => 'TEST-' . uniqid(),
            'client_id' => 'test-client-' . uniqid(),
            'name' => 'Test OAuth Client',
            'description' => 'A test OAuth client',
            'redirect_uris' => ['https://app.example.com/callback'],
            'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'allowed_grants' => ['authorization_code', 'refresh_token'],
            'is_confidential' => 1,
            'require_pkce' => 0,
            'access_token_lifetime' => 3600,
            'refresh_token_lifetime' => 2592000,
            'status' => 1,
        ];

        $data = array_merge($defaults, $overrides);

        $client = new \SmartAuthOAuthClient($this->db);
        $client->ref = $data['ref'];
        $client->client_id = $data['client_id'];
        $client->name = $data['name'];
        $client->description = $data['description'];
        $client->setRedirectUrisArray($data['redirect_uris']);
        $client->setAllowedScopesArray($data['allowed_scopes']);
        $client->setAllowedGrantsArray($data['allowed_grants']);
        $client->is_confidential = $data['is_confidential'];
        $client->require_pkce = $data['require_pkce'];
        $client->access_token_lifetime = $data['access_token_lifetime'];
        $client->refresh_token_lifetime = $data['refresh_token_lifetime'];
        $client->status = $data['status'];

        // Set secret for confidential clients
        if (!empty($data['client_secret'])) {
            $client->setClientSecret($data['client_secret']);
        }

        $result = $client->create($this->testUser);
        if ($result < 0) {
            throw new \Exception('Failed to create test client: ' . implode(', ', $client->errors));
        }

        return $client;
    }

    /**
     * Create a test client from fixture
     *
     * @param string $fixtureName Fixture name from oauth_clients.php
     * @param array $overrides Optional overrides
     * @return \SmartAuthOAuthClient Created client
     */
    protected function createTestClientFromFixture(string $fixtureName, array $overrides = []): \SmartAuthOAuthClient
    {
        $fixtures = $this->loadFixtures();

        if (!isset($fixtures[$fixtureName])) {
            throw new \InvalidArgumentException("Unknown fixture: $fixtureName");
        }

        $fixture = array_merge($fixtures[$fixtureName], $overrides);
        return $this->createTestClient($fixture);
    }

    /**
     * Create an authorization code for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @param array $options Additional options (scopes, pkce, nonce, etc.)
     * @return array ['code' => plain code, 'record' => SmartAuthOAuthCode]
     */
    protected function createAuthorizationCode(
        \SmartAuthOAuthClient $client,
        \User $user,
        array $options = []
    ): array {
        $defaults = [
            'redirect_uri' => $client->getRedirectUrisArray()[0],
            'scopes' => ['openid', 'profile'],
            'state' => bin2hex(random_bytes(16)),
            'nonce' => null,
            'code_challenge' => null,
            'code_challenge_method' => null,
        ];

        $options = array_merge($defaults, $options);

        $plainCode = \SmartAuthOAuthCode::generateCode();
        $codeHash = \SmartAuthOAuthCode::hashCode($plainCode);

        $code = new \SmartAuthOAuthCode($this->db);
        $code->code_hash = $codeHash;
        $code->fk_client = $client->id;
        $code->fk_user = $user->id;
        $code->redirect_uri = $options['redirect_uri'];
        $code->setScopesArray($options['scopes']);
        $code->state = $options['state'];
        $code->nonce = $options['nonce'];
        $code->code_challenge = $options['code_challenge'];
        $code->code_challenge_method = $options['code_challenge_method'];
        $code->expires_at = dol_now() + OAuthConfig::getCodeTTL();

        $result = $code->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to create authorization code: ' . implode(', ', $code->errors));
        }

        return [
            'code' => $plainCode,
            'record' => $code,
        ];
    }

    /**
     * Create an authorization code with PKCE
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @param array $options Additional options
     * @return array ['code' => plain code, 'record' => SmartAuthOAuthCode, 'verifier' => PKCE verifier, 'challenge' => PKCE challenge]
     */
    protected function createAuthorizationCodeWithPKCE(
        \SmartAuthOAuthClient $client,
        \User $user,
        array $options = []
    ): array {
        $pkce = $this->generatePKCE();

        $options = array_merge($options, [
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        $result = $this->createAuthorizationCode($client, $user, $options);
        $result['verifier'] = $pkce['verifier'];
        $result['challenge'] = $pkce['challenge'];

        return $result;
    }

    /**
     * Create an access token for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @param array $scopes Granted scopes
     * @return array ['token' => JWT string, 'jti' => JWT ID, 'expires_in' => seconds]
     */
    protected function createAccessToken(
        \SmartAuthOAuthClient $client,
        \User $user,
        array $scopes = ['openid', 'profile']
    ): array {
        $accessToken = $this->tokenService->createAccessToken(
            $user->id,
            $client->client_id,
            $scopes,
            $client->access_token_lifetime
        );

        // Store token record for revocation tracking
        $this->tokenService->storeAccessToken(
            $accessToken['jti'],
            $client->id,
            $user->id,
            $scopes,
            $accessToken['expires_at'],
            null
        );

        return $accessToken;
    }

    /**
     * Create a refresh token for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @param array $scopes Granted scopes
     * @return array ['token' => plain text token, 'token_id' => database row ID]
     */
    protected function createRefreshToken(
        \SmartAuthOAuthClient $client,
        \User $user,
        array $scopes = ['openid', 'profile', 'offline_access']
    ): array {
        return $this->tokenService->createRefreshToken(
            $user->id,
            $client->id,
            $scopes,
            $client->refresh_token_lifetime
        );
    }

    /**
     * Create a user consent for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @param array $scopes Consented scopes
     * @return \SmartAuthOAuthConsent Created consent
     */
    protected function createConsent(
        \SmartAuthOAuthClient $client,
        \User $user,
        array $scopes = ['openid', 'profile']
    ): \SmartAuthOAuthConsent {
        $consent = new \SmartAuthOAuthConsent($this->db);
        $consent->fk_client = $client->id;
        $consent->fk_user = $user->id;
        $consent->setScopesArray($scopes);

        $result = $consent->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to create consent: ' . implode(', ', $consent->errors));
        }

        return $consent;
    }

    /**
     * Generate PKCE code verifier and challenge
     *
     * @param string $method Challenge method (S256 or plain)
     * @return array ['verifier' => string, 'challenge' => string]
     */
    protected function generatePKCE(string $method = PKCEHelper::METHOD_S256): array
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, $method);

        return [
            'verifier' => $verifier,
            'challenge' => $challenge,
        ];
    }

    /**
     * Assert that an authorization code exists in database
     *
     * @param string $codeHash SHA256 hash of the code
     */
    protected function assertAuthorizationCodeExists(string $codeHash): void
    {
        $this->assertDatabaseHas('smartauth_oauth_codes', ['code_hash' => $codeHash]);
    }

    /**
     * Assert that an authorization code has been used
     *
     * @param int $codeId Code ID
     */
    protected function assertAuthorizationCodeUsed(int $codeId): void
    {
        $code = new \SmartAuthOAuthCode($this->db);
        $result = $code->fetch($codeId);

        $this->assertGreaterThan(0, $result);
        $this->assertTrue($code->isUsed(), 'Authorization code should be marked as used');
    }

    /**
     * Assert that a token exists in database
     *
     * @param string $tokenType 'access' or 'refresh'
     * @param int $clientId Client ID
     * @param int $userId User ID
     */
    protected function assertTokenExists(string $tokenType, int $clientId, int $userId): void
    {
        $this->assertDatabaseHas('smartauth_oauth_tokens', [
            'token_type' => $tokenType,
            'fk_client' => $clientId,
            'fk_user' => $userId,
        ]);
    }

    /**
     * Assert that a token is revoked
     *
     * @param int $tokenId Token ID
     */
    protected function assertTokenRevoked(int $tokenId): void
    {
        $token = new \SmartAuthOAuthToken($this->db);
        $result = $token->fetch($tokenId);

        $this->assertGreaterThan(0, $result);
        $this->assertTrue($token->isRevoked(), 'Token should be revoked');
    }

    /**
     * Assert that a consent exists
     *
     * @param int $clientId Client ID
     * @param int $userId User ID
     */
    protected function assertConsentExists(int $clientId, int $userId): void
    {
        $this->assertDatabaseHas('smartauth_oauth_consents', [
            'fk_client' => $clientId,
            'fk_user' => $userId,
        ]);
    }

    /**
     * Count tokens for a user/client pair
     *
     * @param int $userId User ID
     * @param int $clientId Client ID
     * @param string|null $tokenType Optional token type filter
     * @return int Number of tokens
     */
    protected function countTokensForUserClient(int $userId, int $clientId, ?string $tokenType = null): int
    {
        $conditions = [
            'fk_user' => $userId,
            'fk_client' => $clientId,
        ];

        if ($tokenType !== null) {
            $conditions['token_type'] = $tokenType;
        }

        return $this->getTableCount('smartauth_oauth_tokens', $conditions);
    }

    /**
     * Create an expired authorization code for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @return array ['code' => plain code, 'record' => SmartAuthOAuthCode]
     */
    protected function createExpiredAuthorizationCode(
        \SmartAuthOAuthClient $client,
        \User $user
    ): array {
        $plainCode = \SmartAuthOAuthCode::generateCode();
        $codeHash = \SmartAuthOAuthCode::hashCode($plainCode);

        $code = new \SmartAuthOAuthCode($this->db);
        $code->code_hash = $codeHash;
        $code->fk_client = $client->id;
        $code->fk_user = $user->id;
        $code->redirect_uri = $client->getRedirectUrisArray()[0];
        $code->setScopesArray(['openid', 'profile']);
        $code->expires_at = dol_now() - 3600; // Expired 1 hour ago

        $result = $code->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to create expired code: ' . implode(', ', $code->errors));
        }

        return [
            'code' => $plainCode,
            'record' => $code,
        ];
    }

    /**
     * Create an expired refresh token for testing
     *
     * @param \SmartAuthOAuthClient $client OAuth client
     * @param \User $user Dolibarr user
     * @return array ['token' => plain text token, 'record' => SmartAuthOAuthToken]
     */
    protected function createExpiredRefreshToken(
        \SmartAuthOAuthClient $client,
        \User $user,
        bool $backdateCreation = false
    ): array {
        $plainToken = \SmartAuthOAuthToken::generateRefreshToken();
        $tokenHash = \SmartAuthOAuthToken::hashToken($plainToken);

        $token = new \SmartAuthOAuthToken($this->db);
        $token->token_hash = $tokenHash;
        $token->token_type = \SmartAuthOAuthToken::TOKEN_TYPE_REFRESH;
        $token->fk_client = $client->id;
        $token->fk_user = $user->id;
        $token->setScopesArray(['openid', 'profile', 'offline_access']);
        $token->expires_at = dol_now() - 3600; // Expired 1 hour ago

        $result = $token->create($user);
        if ($result < 0) {
            throw new \Exception('Failed to create expired token: ' . implode(', ', $token->errors));
        }

        // Backdate the creation time for cleanup tests
        if ($backdateCreation) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
            $sql .= " SET datec = '" . $this->db->idate(dol_now() - 86400) . "'";
            $sql .= " WHERE rowid = " . $token->id;
            $this->db->query($sql);
        }

        return [
            'token' => $plainToken,
            'record' => $token,
        ];
    }
}
