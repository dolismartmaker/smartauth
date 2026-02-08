<?php

/**
 * Unit tests for PKCEHelper
 *
 * Tests PKCE (Proof Key for Code Exchange) functionality per RFC 7636.
 *
 * @covers \SmartAuth\Api\OAuth2\PKCEHelper
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\PKCEHelper;

class PKCEHelperTest extends TestCase
{
    /**
     * Test generating code verifier with default length
     */
    public function testGenerateVerifierDefaultLength(): void
    {
        $verifier = PKCEHelper::generateVerifier();

        $this->assertIsString($verifier);
        $this->assertEquals(64, strlen($verifier));
        $this->assertTrue(PKCEHelper::isValidVerifier($verifier));
    }

    /**
     * Test generating code verifier with custom length
     */
    public function testGenerateVerifierCustomLength(): void
    {
        $verifier43 = PKCEHelper::generateVerifier(43);
        $verifier128 = PKCEHelper::generateVerifier(128);

        $this->assertEquals(43, strlen($verifier43));
        $this->assertEquals(128, strlen($verifier128));
        $this->assertTrue(PKCEHelper::isValidVerifier($verifier43));
        $this->assertTrue(PKCEHelper::isValidVerifier($verifier128));
    }

    /**
     * Test generating verifier with invalid length throws exception
     */
    public function testGenerateVerifierInvalidLengthTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PKCEHelper::generateVerifier(42);
    }

    /**
     * Test generating verifier with invalid length throws exception
     */
    public function testGenerateVerifierInvalidLengthTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PKCEHelper::generateVerifier(129);
    }

    /**
     * Test generating code challenge with S256 method
     */
    public function testGenerateChallengeS256(): void
    {
        // Known test vector
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $this->assertEquals($expectedChallenge, $challenge);
    }

    /**
     * Test generating code challenge with plain method
     */
    public function testGenerateChallengePlain(): void
    {
        $verifier = 'test-verifier-12345678901234567890123456789012';

        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_PLAIN);

        $this->assertEquals($verifier, $challenge);
    }

    /**
     * Test generating challenge with unsupported method throws exception
     */
    public function testGenerateChallengeUnsupportedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PKCEHelper::generateChallenge('verifier', 'MD5');
    }

    /**
     * Test validating correct S256 verifier/challenge pair
     */
    public function testValidateS256Correct(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $result = PKCEHelper::validate($verifier, $challenge, PKCEHelper::METHOD_S256);

        $this->assertTrue($result);
    }

    /**
     * Test validating incorrect S256 verifier/challenge pair
     */
    public function testValidateS256Incorrect(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);
        $wrongVerifier = PKCEHelper::generateVerifier();

        $result = PKCEHelper::validate($wrongVerifier, $challenge, PKCEHelper::METHOD_S256);

        $this->assertFalse($result);
    }

    /**
     * Test validating correct plain verifier/challenge pair
     */
    public function testValidatePlainCorrect(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = $verifier; // Plain method: challenge = verifier

        $result = PKCEHelper::validate($verifier, $challenge, PKCEHelper::METHOD_PLAIN);

        $this->assertTrue($result);
    }

    /**
     * Test validating incorrect plain verifier/challenge pair
     */
    public function testValidatePlainIncorrect(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $wrongVerifier = PKCEHelper::generateVerifier();

        $result = PKCEHelper::validate($wrongVerifier, $verifier, PKCEHelper::METHOD_PLAIN);

        $this->assertFalse($result);
    }

    /**
     * Test validation with empty verifier returns false
     */
    public function testValidateEmptyVerifier(): void
    {
        $challenge = PKCEHelper::generateChallenge(PKCEHelper::generateVerifier(), PKCEHelper::METHOD_S256);

        $result = PKCEHelper::validate('', $challenge, PKCEHelper::METHOD_S256);

        $this->assertFalse($result);
    }

    /**
     * Test validation with empty challenge returns false
     */
    public function testValidateEmptyChallenge(): void
    {
        $verifier = PKCEHelper::generateVerifier();

        $result = PKCEHelper::validate($verifier, '', PKCEHelper::METHOD_S256);

        $this->assertFalse($result);
    }

    /**
     * Test validation with unknown method returns false
     */
    public function testValidateUnknownMethod(): void
    {
        $verifier = PKCEHelper::generateVerifier();
        $challenge = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $result = PKCEHelper::validate($verifier, $challenge, 'unknown');

        $this->assertFalse($result);
    }

    /**
     * Test validation with verifier too short returns false
     */
    public function testValidateVerifierTooShort(): void
    {
        $shortVerifier = 'short';
        $challenge = 'somechallenge12345678901234567890123456789012';

        $result = PKCEHelper::validate($shortVerifier, $challenge, PKCEHelper::METHOD_S256);

        $this->assertFalse($result);
    }

    /**
     * Test isValidVerifier with valid verifiers
     */
    public function testIsValidVerifierValid(): void
    {
        $valid43 = str_repeat('a', 43);
        $valid128 = str_repeat('Z', 128);
        $validMixed = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRS';
        $validWithSymbols = 'abc-def_ghi.jkl~mnopqrstuvwxyz0123456789ABC';

        $this->assertTrue(PKCEHelper::isValidVerifier($valid43));
        $this->assertTrue(PKCEHelper::isValidVerifier($valid128));
        $this->assertTrue(PKCEHelper::isValidVerifier($validMixed));
        $this->assertTrue(PKCEHelper::isValidVerifier($validWithSymbols));
    }

    /**
     * Test isValidVerifier with invalid verifiers
     */
    public function testIsValidVerifierInvalid(): void
    {
        $tooShort = str_repeat('a', 42);
        $tooLong = str_repeat('a', 129);
        $invalidChars = 'abc+def/ghi=jklmnopqrstuvwxyz0123456789ABCD'; // Contains +/=

        $this->assertFalse(PKCEHelper::isValidVerifier($tooShort));
        $this->assertFalse(PKCEHelper::isValidVerifier($tooLong));
        $this->assertFalse(PKCEHelper::isValidVerifier($invalidChars));
    }

    /**
     * Test isValidMethod with valid methods
     */
    public function testIsValidMethodValid(): void
    {
        $this->assertTrue(PKCEHelper::isValidMethod('S256'));
        $this->assertTrue(PKCEHelper::isValidMethod('plain'));
    }

    /**
     * Test isValidMethod with invalid methods
     */
    public function testIsValidMethodInvalid(): void
    {
        $this->assertFalse(PKCEHelper::isValidMethod('s256')); // Case sensitive
        $this->assertFalse(PKCEHelper::isValidMethod('PLAIN')); // Case sensitive
        $this->assertFalse(PKCEHelper::isValidMethod('MD5'));
        $this->assertFalse(PKCEHelper::isValidMethod(''));
    }

    /**
     * Test isValidChallenge for S256 method
     */
    public function testIsValidChallengeS256(): void
    {
        // S256 challenge is base64url encoded SHA256 = 43 chars
        $validChallenge = str_repeat('a', 43);
        $invalidLength = str_repeat('a', 42);
        $invalidChars = str_repeat('a', 42) . '+'; // + is not base64url

        $this->assertTrue(PKCEHelper::isValidChallenge($validChallenge, PKCEHelper::METHOD_S256));
        $this->assertFalse(PKCEHelper::isValidChallenge($invalidLength, PKCEHelper::METHOD_S256));
        $this->assertFalse(PKCEHelper::isValidChallenge($invalidChars, PKCEHelper::METHOD_S256));
    }

    /**
     * Test isValidChallenge for plain method
     */
    public function testIsValidChallengePlain(): void
    {
        // Plain challenge must be valid verifier format
        $validChallenge = str_repeat('a', 43);
        $tooShort = str_repeat('a', 42);

        $this->assertTrue(PKCEHelper::isValidChallenge($validChallenge, PKCEHelper::METHOD_PLAIN));
        $this->assertFalse(PKCEHelper::isValidChallenge($tooShort, PKCEHelper::METHOD_PLAIN));
    }

    /**
     * Test isValidChallenge with empty challenge
     */
    public function testIsValidChallengeEmpty(): void
    {
        $this->assertFalse(PKCEHelper::isValidChallenge('', PKCEHelper::METHOD_S256));
        $this->assertFalse(PKCEHelper::isValidChallenge('', PKCEHelper::METHOD_PLAIN));
    }

    /**
     * Test isValidChallenge with unknown method
     */
    public function testIsValidChallengeUnknownMethod(): void
    {
        $challenge = str_repeat('a', 43);

        $this->assertFalse(PKCEHelper::isValidChallenge($challenge, 'unknown'));
    }

    /**
     * Test that generated verifiers are cryptographically random (unique)
     */
    public function testGeneratedVerifiersAreUnique(): void
    {
        $verifiers = [];
        for ($i = 0; $i < 100; $i++) {
            $verifiers[] = PKCEHelper::generateVerifier();
        }

        $unique = array_unique($verifiers);

        $this->assertCount(100, $unique, 'All generated verifiers should be unique');
    }

    /**
     * Test challenge generation is deterministic for same verifier
     */
    public function testChallengeDeterministic(): void
    {
        $verifier = PKCEHelper::generateVerifier();

        $challenge1 = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);
        $challenge2 = PKCEHelper::generateChallenge($verifier, PKCEHelper::METHOD_S256);

        $this->assertEquals($challenge1, $challenge2);
    }
}
