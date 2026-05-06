<?php

/**
 * Integration tests for PKCE (Proof Key for Code Exchange) flow
 *
 * Tests PKCE functionality per RFC 7636 including:
 * - S256 challenge method
 * - Plain challenge method
 * - PKCE requirement enforcement
 * - Verifier validation
 *
 * @covers \SmartAuth\Api\OAuth2\PKCEHelper
 * @covers \SmartAuth\Api\OAuth2\AuthorizationController
 * @covers \SmartAuth\Api\OAuth2\TokenController
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Api\OAuth2\PKCEHelper;

class PKCEFlowTest extends OAuthTestCase
{
    /**
     * Test complete PKCE flow with S256 method
     */
    public function testCompletePKCEFlowS256(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        // Generate PKCE parameters
        $pkce = $this->generatePKCE(PKCEHelper::METHOD_S256);

        // Create authorization code with PKCE
        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid', 'profile'],
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        $code = $codeData['record'];

        // Verify PKCE was stored
        $this->assertEquals($pkce['challenge'], $code->code_challenge);
        $this->assertEquals(PKCEHelper::METHOD_S256, $code->code_challenge_method);

        // Verify PKCE validation works
        $this->assertTrue($code->verifyPkce($pkce['verifier']));
    }

    /**
     * Test that the legacy plain method is rejected.
     * Even if a malicious or buggy caller stores an authorization code with
     * code_challenge_method = 'plain', verifyPkce() must refuse to validate.
     */
    public function testPKCEPlainMethodIsRejected(): void
    {
        $client = $this->createTestClient([
            'require_pkce' => 1,
            'is_confidential' => 0,
        ]);
        $user = $this->createTestUser();

        $verifier = PKCEHelper::generateVerifier();

        // Force-store the auth code with the (now banned) plain method
        $codeData = $this->createAuthorizationCode($client, $user, [
            'scopes' => ['openid'],
            'code_challenge' => $verifier,
            'code_challenge_method' => PKCEHelper::METHOD_PLAIN,
        ]);

        $code = $codeData['record'];

        $this->assertFalse(
            $code->verifyPkce($verifier),
            'plain method must be rejected even if the stored code carries it'
        );
    }

    /**
     * Test PKCE verification fails with wrong verifier
     */
    public function testPKCEVerificationFailsWithWrongVerifier(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        $pkce = $this->generatePKCE();
        $wrongVerifier = PKCEHelper::generateVerifier();

        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        $code = $codeData['record'];

        // Verification should fail with wrong verifier
        $this->assertFalse($code->verifyPkce($wrongVerifier));
    }

    /**
     * Test PKCE is required for public clients
     */
    public function testPKCERequiredForPublicClient(): void
    {
        $client = $this->createTestClientFromFixture('public');

        // Public clients always require PKCE
        $this->assertTrue($client->requiresPkce());
        $this->assertFalse($client->isConfidential());
    }

    /**
     * Test PKCE optional for confidential clients by default
     */
    public function testPKCEOptionalForConfidentialClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential');

        $this->assertFalse($client->requiresPkce());
        $this->assertTrue($client->isConfidential());
    }

    /**
     * Test PKCE can be required for confidential clients
     */
    public function testPKCECanBeRequiredForConfidentialClient(): void
    {
        $client = $this->createTestClientFromFixture('confidential_pkce');

        $this->assertTrue($client->requiresPkce());
        $this->assertTrue($client->isConfidential());
    }

    /**
     * Test code without PKCE challenge allows any verifier
     */
    public function testCodeWithoutPKCEAllowsAnyVerifier(): void
    {
        $client = $this->createTestClientFromFixture('confidential');
        $user = $this->createTestUser();

        // Create code without PKCE
        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => null,
            'code_challenge_method' => null,
        ]);

        $code = $codeData['record'];

        // Any verifier should be accepted (returns true if no challenge stored)
        $this->assertTrue($code->verifyPkce('any-verifier'));
        $this->assertTrue($code->verifyPkce(''));
    }

    /**
     * Test S256 challenge has correct length
     */
    public function testS256ChallengeLength(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        // SHA256 produces 256 bits = 32 bytes
        // Base64url encoding: 32 bytes * 8 bits / 6 bits per char = 42.67 -> 43 chars
        $this->assertEquals(43, strlen($challenge));
    }

    /**
     * Test verifier generation creates valid verifier
     */
    public function testVerifierGenerationCreatesValidVerifier(): void
    {
        $verifier = PKCEHelper::generateVerifier();

        $this->assertTrue(PKCEHelper::isValidVerifier($verifier));
        $this->assertEquals(64, strlen($verifier)); // Default length
    }

    /**
     * Test verifier with minimum length
     */
    public function testVerifierWithMinimumLength(): void
    {
        $verifier = PKCEHelper::generateVerifier(43);

        $this->assertEquals(43, strlen($verifier));
        $this->assertTrue(PKCEHelper::isValidVerifier($verifier));
    }

    /**
     * Test verifier with maximum length
     */
    public function testVerifierWithMaximumLength(): void
    {
        $verifier = PKCEHelper::generateVerifier(128);

        $this->assertEquals(128, strlen($verifier));
        $this->assertTrue(PKCEHelper::isValidVerifier($verifier));
    }

    /**
     * Test PKCE with Dolibarr internal client
     */
    public function testPKCEWithDolibarrClient(): void
    {
        $client = $this->createTestClientFromFixture('dolibarr');
        $user = $this->createTestUser();

        // Dolibarr client is public and requires PKCE
        $this->assertTrue($client->requiresPkce());
        $this->assertFalse($client->isConfidential());

        // Create code with PKCE
        $pkce = $this->generatePKCE();
        $codeData = $this->createAuthorizationCodeWithPKCE($client, $user);

        $code = $codeData['record'];

        // Verify PKCE works
        $this->assertTrue($code->verifyPkce($codeData['verifier']));
    }

    /**
     * Test PKCE verification is constant-time
     */
    public function testPKCEVerificationIsConstantTime(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        $pkce = $this->generatePKCE();
        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        $code = $codeData['record'];

        // Measure time for correct verifier
        $start1 = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $code->verifyPkce($pkce['verifier']);
        }
        $time1 = microtime(true) - $start1;

        // Measure time for incorrect verifier (same length)
        $wrongVerifier = PKCEHelper::generateVerifier();
        $start2 = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $code->verifyPkce($wrongVerifier);
        }
        $time2 = microtime(true) - $start2;

        // Times should be similar (within 50% of each other)
        // This is a weak test but better than nothing
        $ratio = max($time1, $time2) / min($time1, $time2);
        $this->assertLessThan(3.0, $ratio, 'Verification times should be similar');
    }

    /**
     * Test challenge validation for S256
     */
    public function testChallengeValidationS256(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $this->assertTrue(PKCEHelper::isValidChallenge($challenge, PKCEHelper::METHOD_S256));
    }

    /**
     * Test that the plain method is rejected by isValidChallenge
     *.
     */
    public function testChallengeValidationPlainRejected(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $this->assertFalse(
            PKCEHelper::isValidChallenge($verifier, PKCEHelper::METHOD_PLAIN),
            'plain method must be rejected'
        );
    }

    /**
     * Test invalid challenge format rejected for S256
     */
    public function testInvalidChallengeRejectedS256(): void
    {
        // Too short
        $this->assertFalse(PKCEHelper::isValidChallenge('short', PKCEHelper::METHOD_S256));

        // Contains invalid characters
        $invalidChallenge = str_repeat('a', 42) . '+'; // + is not base64url
        $this->assertFalse(PKCEHelper::isValidChallenge($invalidChallenge, PKCEHelper::METHOD_S256));
    }

    /**
     * Test PKCE prevents code interception attacks
     */
    public function testPKCEPreventsCodeInterception(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        // Legitimate client generates PKCE
        $legitPkce = $this->generatePKCE();

        // Create authorization code with legitimate PKCE
        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $legitPkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        $code = $codeData['record'];

        // Attacker intercepts code but doesn't know verifier
        $attackerVerifier = PKCEHelper::generateVerifier();

        // Attacker's attempt should fail
        $this->assertFalse($code->verifyPkce($attackerVerifier));

        // Legitimate client's verifier should work
        $this->assertTrue($code->verifyPkce($legitPkce['verifier']));
    }

    /**
     * Test PKCE helper validate method
     */
    public function testPKCEHelperValidateMethod(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        // Correct validation
        $this->assertTrue(PKCEHelper::validate($verifier, $challenge, PKCEHelper::METHOD_S256));

        // Wrong verifier
        $wrongVerifier = PKCEHelper::generateVerifier();
        $this->assertFalse(PKCEHelper::validate($wrongVerifier, $challenge, PKCEHelper::METHOD_S256));

        // Wrong method
        $this->assertFalse(PKCEHelper::validate($verifier, $challenge, PKCEHelper::METHOD_PLAIN));
    }

    /**
     * Test PKCE method validation. Only S256 is accepted (CR-3 fix).
     */
    public function testPKCEMethodValidation(): void
    {
        $this->assertTrue(PKCEHelper::isValidMethod(PKCEHelper::METHOD_S256));
        $this->assertFalse(PKCEHelper::isValidMethod(PKCEHelper::METHOD_PLAIN));
        $this->assertFalse(PKCEHelper::isValidMethod('SHA256'));
        $this->assertFalse(PKCEHelper::isValidMethod('s256')); // Case sensitive
        $this->assertFalse(PKCEHelper::isValidMethod(''));
    }

    /**
     * Test PKCE with known test vector (RFC 7636 Appendix B)
     */
    public function testPKCEWithKnownTestVector(): void
    {
        // Test vector from RFC 7636 Appendix B
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $this->assertEquals($expectedChallenge, $challenge);
        $this->assertTrue(PKCEHelper::validate($verifier, $expectedChallenge, PKCEHelper::METHOD_S256));
    }

    /**
     * Test PKCE verifier contains only allowed characters
     */
    public function testVerifierContainsOnlyAllowedCharacters(): void
    {
        // Generate many verifiers and check all characters
        for ($i = 0; $i < 100; $i++) {
            $verifier = PKCEHelper::generateVerifier();

            // Should only contain A-Z, a-z, 0-9, -, ., _, ~
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
        }
    }

    /**
     * Test code challenge storage in database
     */
    public function testCodeChallengeStorageInDatabase(): void
    {
        $client = $this->createTestClientFromFixture('public');
        $user = $this->createTestUser();

        $pkce = $this->generatePKCE();
        $codeData = $this->createAuthorizationCode($client, $user, [
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => PKCEHelper::METHOD_S256,
        ]);

        // Fetch from database
        $code = new \SmartAuthOAuthCode($this->db);
        $result = $code->fetch($codeData['record']->id);

        $this->assertGreaterThan(0, $result);
        $this->assertEquals($pkce['challenge'], $code->code_challenge);
        $this->assertEquals(PKCEHelper::METHOD_S256, $code->code_challenge_method);
    }
}
