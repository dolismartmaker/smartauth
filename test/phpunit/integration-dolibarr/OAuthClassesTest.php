<?php
/* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        test/phpunit/integration-dolibarr/OAuthClassesTest.php
 * \ingroup     smartauth
 * \brief       Tests for OAuth2 classes (Client, Code, Token, Consent)
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use PHPUnit\Framework\TestCase;
use SmartAuthOAuthClient;
use SmartAuthOAuthCode;
use SmartAuthOAuthToken;
use SmartAuthOAuthConsent;

class OAuthClassesTest extends DolibarrRealTestCase
{
    /**
     * @var SmartAuthOAuthClient
     */
    private $client;

    /**
     * @var SmartAuthOAuthCode
     */
    private $code;

    /**
     * @var SmartAuthOAuthToken
     */
    private $token;

    /**
     * @var SmartAuthOAuthConsent
     */
    private $consent;

    protected function setUp(): void
    {
        parent::setUp();

        // Include OAuth classes from project root
        $projectRoot = dirname(__DIR__, 3);
        require_once $projectRoot . '/class/smartauthoauthclient.class.php';
        require_once $projectRoot . '/class/smartauthoauthcode.class.php';
        require_once $projectRoot . '/class/smartauthoauthtoken.class.php';
        require_once $projectRoot . '/class/smartauthoauthconsent.class.php';
    }

    /**
     * Test that SmartAuthOAuthClient is instantiable
     */
    public function testClientInstantiable()
    {
        global $db;
        $client = new SmartAuthOAuthClient($db);
        $this->assertInstanceOf(SmartAuthOAuthClient::class, $client);
    }

    /**
     * Test that SmartAuthOAuthCode is instantiable
     */
    public function testCodeInstantiable()
    {
        global $db;
        $code = new SmartAuthOAuthCode($db);
        $this->assertInstanceOf(SmartAuthOAuthCode::class, $code);
    }

    /**
     * Test that SmartAuthOAuthToken is instantiable
     */
    public function testTokenInstantiable()
    {
        global $db;
        $token = new SmartAuthOAuthToken($db);
        $this->assertInstanceOf(SmartAuthOAuthToken::class, $token);
    }

    /**
     * Test that SmartAuthOAuthConsent is instantiable
     */
    public function testConsentInstantiable()
    {
        global $db;
        $consent = new SmartAuthOAuthConsent($db);
        $this->assertInstanceOf(SmartAuthOAuthConsent::class, $consent);
    }

    /**
     * Test SmartAuthOAuthClient CRUD operations
     */
    public function testClientCRUD()
    {
        global $db, $user;

        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'TEST-CLIENT-001';
        $client->name = 'Test OAuth Client';
        $client->description = 'A test client for unit testing';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid', 'profile', 'email'));
        $client->setAllowedGrantsArray(array('authorization_code', 'refresh_token'));
        $client->is_confidential = 1;
        $client->require_pkce = 0;

        // Create
        $result = $client->create($user);
        $this->assertGreaterThan(0, $result, 'Client creation should succeed');
        $clientId = $result;

        // Fetch
        $client2 = new SmartAuthOAuthClient($db);
        $result = $client2->fetch($clientId);
        $this->assertGreaterThan(0, $result, 'Client fetch should succeed');
        $this->assertEquals('TEST-CLIENT-001', $client2->ref);
        $this->assertEquals('Test OAuth Client', $client2->name);

        // Update
        $client2->name = 'Updated Test Client';
        $result = $client2->update($user);
        $this->assertGreaterThan(0, $result, 'Client update should succeed');

        // Verify update
        $client3 = new SmartAuthOAuthClient($db);
        $client3->fetch($clientId);
        $this->assertEquals('Updated Test Client', $client3->name);

        // Delete
        $result = $client3->delete($user);
        $this->assertGreaterThan(0, $result, 'Client delete should succeed');

        // Verify delete
        $client4 = new SmartAuthOAuthClient($db);
        $result = $client4->fetch($clientId);
        $this->assertEquals(0, $result, 'Fetch after delete should return 0');
    }

    /**
     * Test SmartAuthOAuthClient secret hashing
     */
    public function testClientSecretHashing()
    {
        global $db;

        $client = new SmartAuthOAuthClient($db);

        // Generate and set secret
        $plainSecret = $client->generateClientSecret();
        $this->assertNotEmpty($plainSecret);
        $this->assertEquals(64, strlen($plainSecret)); // 32 bytes = 64 hex chars

        $client->setClientSecret($plainSecret);
        $this->assertNotEmpty($client->client_secret);
        $this->assertNotEquals($plainSecret, $client->client_secret); // Should be hashed

        // Verify correct secret
        $this->assertTrue($client->verifySecret($plainSecret));

        // Verify wrong secret fails
        $this->assertFalse($client->verifySecret('wrong-secret'));
    }

    /**
     * Test SmartAuthOAuthClient helper methods
     */
    public function testClientHelperMethods()
    {
        global $db;

        $client = new SmartAuthOAuthClient($db);
        $client->setRedirectUrisArray(array('https://example.com/callback', 'https://example.com/auth'));
        $client->setAllowedScopesArray(array('openid', 'profile', 'email'));
        $client->setAllowedGrantsArray(array('authorization_code', 'refresh_token'));
        $client->is_confidential = 0; // Public client
        $client->require_pkce = 1;

        // Redirect URI checks
        $this->assertTrue($client->isRedirectUriAllowed('https://example.com/callback'));
        $this->assertTrue($client->isRedirectUriAllowed('https://example.com/auth'));
        $this->assertFalse($client->isRedirectUriAllowed('https://evil.com/callback'));

        // Scope checks
        $this->assertTrue($client->isScopeAllowed('openid'));
        $this->assertTrue($client->isScopeAllowed('profile'));
        $this->assertFalse($client->isScopeAllowed('admin'));
        $this->assertTrue($client->areScopesAllowed(array('openid', 'profile')));
        $this->assertFalse($client->areScopesAllowed(array('openid', 'admin')));

        // Grant checks
        $this->assertTrue($client->isGrantAllowed('authorization_code'));
        $this->assertFalse($client->isGrantAllowed('client_credentials'));

        // Client type checks
        $this->assertFalse($client->isConfidential());
        $this->assertTrue($client->requiresPkce()); // Public client always requires PKCE
    }

    /**
     * Test SmartAuthOAuthCode CRUD and helpers
     */
    public function testCodeCRUD()
    {
        global $db, $user;

        // First create a client
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'CODE-TEST-CLIENT';
        $client->name = 'Code Test Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid', 'profile'));
        $client->setAllowedGrantsArray(array('authorization_code'));
        $clientResult = $client->create($user);
        $this->assertGreaterThan(0, $clientResult);

        // Generate a code
        $plainCode = SmartAuthOAuthCode::generateCode();
        $this->assertNotEmpty($plainCode);
        $this->assertEquals(64, strlen($plainCode)); // 32 bytes = 64 hex chars

        // Create code object
        $code = new SmartAuthOAuthCode($db);
        $code->code_hash = SmartAuthOAuthCode::hashCode($plainCode);
        $code->fk_client = $clientResult;
        $code->fk_user = $user->id;
        $code->redirect_uri = 'https://example.com/callback';
        $code->setScopesArray(array('openid', 'profile'));
        $code->code_challenge = 'test_challenge';
        $code->code_challenge_method = 'S256';

        $result = $code->create($user);
        $this->assertGreaterThan(0, $result, 'Code creation should succeed');
        $codeId = $result;

        // Fetch by plain code
        $code2 = new SmartAuthOAuthCode($db);
        $result = $code2->fetchByCode($plainCode);
        $this->assertGreaterThan(0, $result, 'Fetch by code should succeed');
        $this->assertEquals($codeId, $code2->id);

        // Check validity
        $this->assertFalse($code2->isExpired());
        $this->assertFalse($code2->isUsed());
        $this->assertTrue($code2->isValid());

        // Mark as used
        $result = $code2->markAsUsed();
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($code2->isUsed());
        $this->assertFalse($code2->isValid());

        // Cleanup
        $code2->delete($user);
        $client->delete($user);
    }

    /**
     * Test SmartAuthOAuthCode PKCE verification
     */
    public function testCodePKCEVerification()
    {
        global $db;

        $code = new SmartAuthOAuthCode($db);

        // Test S256 method
        $verifier = 'test_verifier_12345678901234567890123456789012345678901234';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code->code_challenge = $challenge;
        $code->code_challenge_method = 'S256';

        $this->assertTrue($code->verifyPkce($verifier));
        $this->assertFalse($code->verifyPkce('wrong_verifier'));

        // Plain method is rejected.
        $code2 = new SmartAuthOAuthCode($db);
        $code2->code_challenge = 'plain_challenge';
        $code2->code_challenge_method = 'plain';

        $this->assertFalse($code2->verifyPkce('plain_challenge'), 'plain method must be rejected');
        $this->assertFalse($code2->verifyPkce('wrong_challenge'));

        // Test no PKCE
        $code3 = new SmartAuthOAuthCode($db);
        $code3->code_challenge = null;
        $this->assertTrue($code3->verifyPkce('anything'));
    }

    /**
     * Test SmartAuthOAuthToken CRUD and helpers
     */
    public function testTokenCRUD()
    {
        global $db, $user;

        // First create a client
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'TOKEN-TEST-CLIENT';
        $client->name = 'Token Test Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid', 'profile'));
        $client->setAllowedGrantsArray(array('authorization_code', 'refresh_token'));
        $clientResult = $client->create($user);
        $this->assertGreaterThan(0, $clientResult);

        // Create refresh token
        $plainRefreshToken = SmartAuthOAuthToken::generateRefreshToken();
        $this->assertStringStartsWith('smartauth_rt_', $plainRefreshToken);

        $refreshToken = new SmartAuthOAuthToken($db);
        $refreshToken->token_hash = SmartAuthOAuthToken::hashToken($plainRefreshToken);
        $refreshToken->token_type = SmartAuthOAuthToken::TOKEN_TYPE_REFRESH;
        $refreshToken->fk_client = $clientResult;
        $refreshToken->fk_user = $user->id;
        $refreshToken->setScopesArray(array('openid', 'profile'));
        $refreshToken->expires_at = dol_now() + 2592000; // 30 days

        $result = $refreshToken->create($user);
        $this->assertGreaterThan(0, $result, 'Refresh token creation should succeed');
        $refreshTokenId = $result;

        // Create access token linked to refresh token
        $jti = SmartAuthOAuthToken::generateJti();
        $accessToken = new SmartAuthOAuthToken($db);
        $accessToken->token_hash = SmartAuthOAuthToken::hashToken('access_token_123');
        $accessToken->token_type = SmartAuthOAuthToken::TOKEN_TYPE_ACCESS;
        $accessToken->fk_client = $clientResult;
        $accessToken->fk_user = $user->id;
        $accessToken->setScopesArray(array('openid', 'profile'));
        $accessToken->jti = $jti;
        $accessToken->expires_at = dol_now() + 3600;
        $accessToken->fk_parent = $refreshTokenId;

        $result = $accessToken->create($user);
        $this->assertGreaterThan(0, $result, 'Access token creation should succeed');

        // Fetch refresh token
        $token2 = new SmartAuthOAuthToken($db);
        $result = $token2->fetchByToken($plainRefreshToken);
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($token2->isRefreshToken());
        $this->assertFalse($token2->isAccessToken());
        $this->assertTrue($token2->isValid());

        // Fetch by JTI
        $token3 = new SmartAuthOAuthToken($db);
        $result = $token3->fetchByJti($jti);
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($token3->isAccessToken());

        // Revoke refresh token with children
        $count = $token2->revokeWithChildren();
        $this->assertGreaterThanOrEqual(2, $count);

        // Verify both are revoked
        $token4 = new SmartAuthOAuthToken($db);
        $token4->fetch($refreshTokenId);
        $this->assertTrue($token4->isRevoked());

        // Cleanup
        $accessToken->delete($user);
        $refreshToken->delete($user);
        $client->delete($user);
    }

    /**
     * Test SmartAuthOAuthConsent CRUD and helpers
     */
    public function testConsentCRUD()
    {
        global $db, $user;

        // First create a client
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'CONSENT-TEST-CLIENT';
        $client->name = 'Consent Test Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid', 'profile', 'email', 'groups'));
        $client->setAllowedGrantsArray(array('authorization_code'));
        $clientResult = $client->create($user);
        $this->assertGreaterThan(0, $clientResult);

        // Create consent
        $consent = new SmartAuthOAuthConsent($db);
        $consent->fk_client = $clientResult;
        $consent->fk_user = $user->id;
        $consent->setScopesArray(array('openid', 'profile'));

        $result = $consent->create($user);
        $this->assertGreaterThan(0, $result, 'Consent creation should succeed');
        $consentId = $result;

        // Fetch by client and user
        $consent2 = new SmartAuthOAuthConsent($db);
        $result = $consent2->fetchByClientAndUser($clientResult, $user->id);
        $this->assertGreaterThan(0, $result);
        $this->assertEquals($consentId, $consent2->id);

        // Check scopes
        $this->assertTrue($consent2->hasScope('openid'));
        $this->assertTrue($consent2->hasScope('profile'));
        $this->assertFalse($consent2->hasScope('email'));
        $this->assertTrue($consent2->hasAllScopes(array('openid', 'profile')));
        $this->assertFalse($consent2->hasAllScopes(array('openid', 'email')));

        // Add scopes
        $result = $consent2->addScopes(array('email'), $user);
        $this->assertGreaterThan(0, $result);

        // Verify new scope
        $consent3 = new SmartAuthOAuthConsent($db);
        $consent3->fetch($consentId);
        $this->assertTrue($consent3->hasScope('email'));

        // Test findOrCreate for existing consent
        $consent4 = new SmartAuthOAuthConsent($db);
        $result = $consent4->findOrCreate($clientResult, $user->id, array('groups'), $user);
        $this->assertEquals($consentId, $result); // Should return same consent

        // Verify groups was added
        $consent5 = new SmartAuthOAuthConsent($db);
        $consent5->fetch($consentId);
        $this->assertTrue($consent5->hasScope('groups'));

        // Revoke consent
        $result = $consent5->revoke();
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($consent5->isRevoked());
        $this->assertFalse($consent5->isActive());

        // Cleanup
        $consent5->delete($user);
        $client->delete($user);
    }

    /**
     * Test consent findOrCreate creates new consent when none exists
     */
    public function testConsentFindOrCreateNew()
    {
        global $db, $user;

        // Create a client
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'CONSENT-NEW-CLIENT';
        $client->name = 'New Consent Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid', 'profile'));
        $client->setAllowedGrantsArray(array('authorization_code'));
        $clientResult = $client->create($user);
        $this->assertGreaterThan(0, $clientResult);

        // findOrCreate should create new consent
        $consent = new SmartAuthOAuthConsent($db);
        $result = $consent->findOrCreate($clientResult, $user->id, array('openid', 'profile'), $user);
        $this->assertGreaterThan(0, $result);

        // Verify consent was created
        $consent2 = new SmartAuthOAuthConsent($db);
        $result = $consent2->fetchByClientAndUser($clientResult, $user->id);
        $this->assertGreaterThan(0, $result);
        $this->assertTrue($consent2->hasAllScopes(array('openid', 'profile')));

        // Cleanup
        $consent2->delete($user);
        $client->delete($user);
    }

    /**
     * Test client fetch by client_id
     */
    public function testClientFetchByClientId()
    {
        global $db, $user;

        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'FETCH-BY-ID-CLIENT';
        $client->name = 'Fetch By ID Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid'));
        $client->setAllowedGrantsArray(array('authorization_code'));

        $result = $client->create($user);
        $this->assertGreaterThan(0, $result);

        $generatedClientId = $client->client_id;
        $this->assertNotEmpty($generatedClientId);

        // Fetch by client_id
        $client2 = new SmartAuthOAuthClient($db);
        $result = $client2->fetch(0, null, $generatedClientId);
        $this->assertGreaterThan(0, $result);
        $this->assertEquals('FETCH-BY-ID-CLIENT', $client2->ref);

        // Cleanup
        $client2->delete($user);
    }

    /**
     * Test client generateClientId format
     */
    public function testClientIdFormat()
    {
        global $db;

        $client = new SmartAuthOAuthClient($db);
        $clientId = $client->generateClientId();

        $this->assertStringStartsWith('smartauth_', $clientId);
        // smartauth_ (10 chars) + 32 hex chars (16 bytes * 2) = 42 chars total
        $this->assertEquals(42, strlen($clientId));
    }

    /**
     * Test token static revocation methods
     */
    public function testTokenStaticRevocation()
    {
        global $db, $user;

        // Create a client
        $client = new SmartAuthOAuthClient($db);
        $client->ref = 'REVOKE-ALL-CLIENT';
        $client->name = 'Revoke All Client';
        $client->setRedirectUrisArray(array('https://example.com/callback'));
        $client->setAllowedScopesArray(array('openid'));
        $client->setAllowedGrantsArray(array('authorization_code'));
        $clientResult = $client->create($user);

        // Create multiple tokens
        for ($i = 0; $i < 3; $i++) {
            $token = new SmartAuthOAuthToken($db);
            $token->token_hash = SmartAuthOAuthToken::hashToken('test_token_' . $i);
            $token->token_type = SmartAuthOAuthToken::TOKEN_TYPE_ACCESS;
            $token->fk_client = $clientResult;
            $token->fk_user = $user->id;
            $token->setScopesArray(array('openid'));
            $token->expires_at = dol_now() + 3600;
            $token->create($user);
        }

        // Revoke all tokens for user and client
        $count = SmartAuthOAuthToken::revokeAllForUserAndClient($db, $user->id, $clientResult);
        $this->assertEquals(3, $count);

        // Cleanup
        $client->delete($user);
    }
}
