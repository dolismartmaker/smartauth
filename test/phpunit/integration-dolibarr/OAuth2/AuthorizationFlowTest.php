<?php

/**
 * Integration tests for OAuth2 authorization flow
 *
 * Tests the complete authorization_code flow including:
 * - Client validation
 * - Redirect URI validation
 * - Scope validation
 * - Consent handling
 * - Authorization code generation
 *
 * @covers \SmartAuth\Api\OAuth2\AuthorizationController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

dol_include_once('/smartauth/api/OAuth2/AuthorizationController.php');

use SmartAuth\Api\OAuth2\AuthorizationController;
use SmartAuth\Api\OAuth2\ScopeManager;

class AuthorizationFlowTest extends OAuthTestCase
{
    /**
     * @var AuthorizationController
     */
    private $controller;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new AuthorizationController($this->db);
    }

    /**
     * Test authorization code is created successfully
     */
    public function testAuthorizationCodeCreation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser(['pass' => 'password123']);

        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid', 'profile', 'email'],
        ]);

        $this->assertNotEmpty($codeData['code']);
        $this->assertAuthorizationCodeExists(\SmartAuthOAuthCode::hashCode($codeData['code']));
    }

    /**
     * Test authorization code contains correct data
     */
    public function testAuthorizationCodeContainsCorrectData(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser(['pass' => 'password123']);

        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid', 'profile'],
            'redirect_uri' => 'https://app.example.com/callback',
            'nonce' => 'test-nonce-123',
        ]);

        $code = $codeData['record'];

        $this->assertEquals($client->id, $code->fk_client);
        $this->assertEquals($user->id, $code->fk_user);
        $this->assertEquals('https://app.example.com/callback', $code->redirect_uri);
        $this->assertEquals('test-nonce-123', $code->nonce);
        $this->assertContains('openid', $code->getScopesArray());
        $this->assertContains('profile', $code->getScopesArray());
    }

    /**
     * Test authorization code expires correctly
     */
    public function testAuthorizationCodeExpiry(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);
        $code = $codeData['record'];

        // Code should not be expired immediately
        $this->assertFalse($code->isExpired());

        // Expired code should report as expired
        $expiredCode = $this->createExpiredAuthorizationCode($client, $user);
        $this->assertTrue($expiredCode['record']->isExpired());
    }

    /**
     * Test authorization code is single-use
     */
    public function testAuthorizationCodeSingleUse(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCode($client, $user);
        $code = $codeData['record'];

        // Code should not be used initially
        $this->assertFalse($code->isUsed());
        $this->assertTrue($code->isValid());

        // Mark as used
        $code->markAsUsed();

        // Reload and verify
        $code->fetch($code->id);
        $this->assertTrue($code->isUsed());
        $this->assertFalse($code->isValid());
    }

    /**
     * Test client validation for unknown client
     */
    public function testClientValidationUnknownClient(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateClient');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, 'unknown-client-id');

        $this->assertNull($result);
    }

    /**
     * Test client validation for disabled client
     */
    public function testClientValidationDisabledClient(): void
    {
        $client = $this->createTestClientFromFixture('disabled');

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateClient');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client->client_id);

        $this->assertNull($result);
    }

    /**
     * Test client validation for valid client
     */
    public function testClientValidationValidClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateClient');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client->client_id);

        $this->assertNotNull($result);
        $this->assertEquals($client->id, $result->id);
    }

    /**
     * Test redirect URI validation with valid URI
     */
    public function testRedirectUriValidationValid(): void
    {
        $client = $this->createTestClient([
            'redirect_uris' => ['https://app.example.com/callback', 'https://app.example.com/auth'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateRedirectUri');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client, 'https://app.example.com/callback');

        $this->assertTrue($result);
    }

    /**
     * Test redirect URI validation with invalid URI
     */
    public function testRedirectUriValidationInvalid(): void
    {
        $client = $this->createTestClient([
            'redirect_uris' => ['https://app.example.com/callback'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateRedirectUri');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client, 'https://malicious.com/callback');

        $this->assertFalse($result);
    }

    /**
     * Test redirect URI validation requires HTTPS (except localhost)
     */
    public function testRedirectUriValidationHttpsRequired(): void
    {
        $client = $this->createTestClient([
            'redirect_uris' => ['http://app.example.com/callback'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateRedirectUri');
        $method->setAccessible(true);

        // HTTP on non-localhost should fail
        $result = $method->invoke($this->controller, $client, 'http://app.example.com/callback');
        $this->assertFalse($result);
    }

    /**
     * Test redirect URI validation allows HTTP for localhost
     */
    public function testRedirectUriValidationLocalhostHttp(): void
    {
        $client = $this->createTestClient([
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateRedirectUri');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client, 'http://localhost:3000/callback');
        $this->assertTrue($result);
    }

    /**
     * Test scope validation with all valid scopes
     */
    public function testScopeValidationAllValid(): void
    {
        $client = $this->createTestClient([
            'allowed_scopes' => ['openid', 'profile', 'email'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateScopes');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $client, ['openid', 'profile']);

        $this->assertNotNull($result);
        $this->assertContains('openid', $result);
        $this->assertContains('profile', $result);
    }

    /**
     * Test scope validation with disallowed scope
     */
    public function testScopeValidationWithDisallowed(): void
    {
        $client = $this->createTestClient([
            'allowed_scopes' => ['openid', 'profile'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateScopes');
        $method->setAccessible(true);

        // Request email scope which is not allowed
        $result = $method->invoke($this->controller, $client, ['openid', 'profile', 'email']);

        $this->assertNull($result);
    }

    /**
     * Test scope validation with unknown scope
     *
     * Unknown scopes are filtered out by ScopeManager::filterValidScopes().
     * The remaining valid scopes are returned if they are all allowed for the client.
     */
    public function testScopeValidationWithUnknown(): void
    {
        $client = $this->createTestClient([
            'allowed_scopes' => ['openid', 'profile', 'email'],
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateScopes');
        $method->setAccessible(true);

        // Request openid + unknown scope - unknown will be filtered, openid remains
        $result = $method->invoke($this->controller, $client, ['openid', 'unknown_scope']);

        // Unknown scope is filtered out, openid remains and is returned
        $this->assertNotNull($result);
        $this->assertContains('openid', $result);
        $this->assertNotContains('unknown_scope', $result);
    }

    /**
     * Test response type validation only accepts 'code'
     */
    public function testResponseTypeValidation(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateResponseType');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->controller, 'code'));
        $this->assertFalse($method->invoke($this->controller, 'token'));
        $this->assertFalse($method->invoke($this->controller, 'id_token'));
        $this->assertFalse($method->invoke($this->controller, ''));
    }

    /**
     * Test consent is saved correctly
     */
    public function testConsentIsSaved(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $consent = $this->createConsent($client, $user, ['openid', 'profile', 'email']);

        $this->assertGreaterThan(0, $consent->id);
        $this->assertEquals($client->id, $consent->fk_client);
        $this->assertEquals($user->id, $consent->fk_user);
        $this->assertContains('openid', $consent->getScopesArray());
        $this->assertContains('profile', $consent->getScopesArray());
        $this->assertContains('email', $consent->getScopesArray());
    }

    /**
     * Test existing consent is found
     */
    public function testExistingConsentFound(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create consent
        $this->createConsent($client, $user, ['openid', 'profile']);

        // Fetch consent
        $consent = new \SmartAuthOAuthConsent($this->db);
        $result = $consent->fetchByClientAndUser($client->id, $user->id);

        $this->assertGreaterThan(0, $result);
        $this->assertTrue($consent->hasAllScopes(['openid', 'profile']));
    }

    /**
     * Test consent can have scopes added
     */
    public function testConsentScopesAdded(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create initial consent
        $consent = $this->createConsent($client, $user, ['openid']);

        // Add more scopes
        $consent->addScopes(['profile', 'email'], $user);

        // Verify all scopes present
        $consent->fetch($consent->id);
        $this->assertTrue($consent->hasAllScopes(['openid', 'profile', 'email']));
    }

    /**
     * Test consent can be revoked
     */
    public function testConsentRevocation(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        $consent = $this->createConsent($client, $user, ['openid', 'profile']);

        // Revoke consent
        $consent->revoke();

        // Verify revoked
        $consent->fetch($consent->id);
        $this->assertTrue($consent->isRevoked());
        $this->assertFalse($consent->isActive());
    }

    /**
     * Test revoked consent is not returned by fetchByClientAndUser
     */
    public function testRevokedConsentNotReturned(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create and revoke consent
        $consent = $this->createConsent($client, $user, ['openid', 'profile']);
        $consent->revoke();

        // Try to fetch active consent
        $newConsent = new \SmartAuthOAuthConsent($this->db);
        $result = $newConsent->fetchByClientAndUser($client->id, $user->id);

        $this->assertEquals(0, $result); // No active consent found
    }

    /**
     * Test PKCE validation requirement for public clients
     */
    public function testPKCERequiredForPublicClient(): void
    {
        $client = $this->createTestClientFromFixture('public');

        $this->assertTrue($client->requiresPkce());
        $this->assertFalse($client->isConfidential());
    }

    /**
     * Test PKCE optional for confidential clients
     */
    public function testPKCEOptionalForConfidentialClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $this->assertFalse($client->requiresPkce());
        $this->assertTrue($client->isConfidential());
    }

    /**
     * Test confidential client with PKCE required
     */
    public function testConfidentialClientWithPKCERequired(): void
    {
        $client = $this->createTestClientFromFixture('confidential_pkce');

        $this->assertTrue($client->requiresPkce());
        $this->assertTrue($client->isConfidential());
    }

    /**
     * Test authorization code with PKCE stores challenge
     */
    public function testAuthorizationCodeStoresPKCE(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user);

        $code = $codeData['record'];
        $this->assertNotEmpty($code->code_challenge);
        $this->assertEquals('S256', $code->code_challenge_method);
    }

    /**
     * Test client allowed scopes are respected
     */
    public function testClientAllowedScopes(): void
    {
        $client = $this->createTestClientFromFixture('limited_scopes');

        $allowedScopes = $client->getAllowedScopesArray();

        $this->assertContains('openid', $allowedScopes);
        $this->assertContains('profile', $allowedScopes);
        $this->assertNotContains('email', $allowedScopes);
        $this->assertNotContains('offline_access', $allowedScopes);
    }

    /**
     * Test client allowed grants are respected
     */
    public function testClientAllowedGrants(): void
    {
        $client = $this->createTestClientFromFixture('limited_scopes');

        $this->assertTrue($client->isGrantAllowed('authorization_code'));
        $this->assertFalse($client->isGrantAllowed('refresh_token'));
    }
}
