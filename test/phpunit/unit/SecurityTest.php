<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\LogSanitizer;
use SmartAuth\Api\InputSanitizer;
use ReflectionClass;
use ReflectionMethod;

/**
 * Security-focused unit tests for SmartAuth
 *
 * Tests cover:
 * - Bearer token format validation
 * - Login/email validation
 * - SQL injection prevention
 * - XSS prevention
 * - Log sanitization
 */
class SecurityTest extends TestCase
{
	private AuthController $controller;

	protected function setUp(): void
	{
		$this->controller = new AuthController();

		// Initialize global $conf if needed
		global $conf;
		if (!is_object($conf)) {
			$conf = new \stdClass();
			$conf->cache = [];
			$conf->cache['smartmakers'] = [];
		}
	}

	/**
	 * Helper to access private/protected methods
	 */
	private function getPrivateMethod(string $class, string $methodName): ReflectionMethod
	{
		$reflection = new ReflectionClass($class);
		$method = $reflection->getMethod($methodName);
		$method->setAccessible(true);
		return $method;
	}

	// =========================================================================
	// Bearer Token Format Validation Tests
	// =========================================================================

	/**
	 * Test valid Bearer token format is accepted
	 */
	public function testValidBearerTokenFormatAccepted(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		// Valid format: numeric_id|jwt
		$validToken = '123|eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

		$this->assertTrue($method->invoke(null, $validToken));
	}

	/**
	 * Test Bearer token without pipe separator is rejected
	 */
	public function testBearerTokenWithoutPipeRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		$invalidToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

		$this->assertFalse($method->invoke(null, $invalidToken));
	}

	/**
	 * Test Bearer token with non-numeric ID is rejected
	 */
	public function testBearerTokenWithNonNumericIdRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		$invalidToken = 'abc|eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature';

		$this->assertFalse($method->invoke(null, $invalidToken));
	}

	/**
	 * Test Bearer token with SQL injection in ID is rejected
	 */
	public function testBearerTokenSQLInjectionInIdRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		$maliciousTokens = [
			"1; DROP TABLE users--|eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig",
			"1' OR '1'='1|eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig",
			"1 UNION SELECT * FROM users|eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig",
		];

		foreach ($maliciousTokens as $token) {
			$this->assertFalse($method->invoke(null, $token), "Should reject: $token");
		}
	}

	/**
	 * Test Bearer token with invalid JWT format is rejected
	 */
	public function testBearerTokenInvalidJWTFormatRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		$invalidTokens = [
			'123|notajwt',
			'123|only.two.parts.here.invalid',
			'123|<script>alert(1)</script>',
			'123|../../../etc/passwd',
		];

		foreach ($invalidTokens as $token) {
			$this->assertFalse($method->invoke(null, $token), "Should reject: $token");
		}
	}

	/**
	 * Test Bearer token exceeding max length is rejected
	 */
	public function testBearerTokenExceedingMaxLengthRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		// Create a token longer than 2048 chars
		$longPayload = str_repeat('a', 2000);
		$longToken = "123|$longPayload.$longPayload.$longPayload";

		$this->assertFalse($method->invoke(null, $longToken));
	}

	/**
	 * Test empty and null Bearer tokens are rejected
	 */
	public function testEmptyBearerTokenRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateBearerTokenFormat');

		$this->assertFalse($method->invoke(null, ''));
		$this->assertFalse($method->invoke(null, null));
	}

	// =========================================================================
	// Login/Email Validation Tests
	// =========================================================================

	/**
	 * Test valid email format is accepted
	 */
	public function testValidEmailAccepted(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$validEmails = [
			'user@example.com',
			'test.user@domain.org',
			'admin@sub.domain.co.uk',
		];

		foreach ($validEmails as $email) {
			$result = $method->invoke($this->controller, $email);
			$this->assertNotEmpty($result, "Should accept: $email");
			$this->assertEquals(strtolower($email), $result);
		}
	}

	/**
	 * Test valid username format is accepted
	 */
	public function testValidUsernameAccepted(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$validUsernames = [
			'admin',
			'user_name',
			'john.doe',
			'test-user',
			'User123',
		];

		foreach ($validUsernames as $username) {
			$result = $method->invoke($this->controller, $username);
			$this->assertEquals($username, $result, "Should accept: $username");
		}
	}

	/**
	 * Test invalid email format is rejected
	 */
	public function testInvalidEmailRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$invalidEmails = [
			'not-an-email@',
			'@nodomain.com',
			'missing.domain@',
			// Note: "spaces in@email.com" becomes "spacesin@email.com" after FILTER_SANITIZE_EMAIL
			// which is then valid, so we test actual invalid formats
			'user@',
			'@domain',
		];

		foreach ($invalidEmails as $email) {
			$result = $method->invoke($this->controller, $email);
			$this->assertEmpty($result, "Should reject: $email");
		}
	}

	/**
	 * Test SQL injection in login is rejected
	 */
	public function testSQLInjectionInLoginRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$maliciousInputs = [
			"admin'; DROP TABLE users; --",
			"' OR '1'='1",
			"admin'--",
			"1; DELETE FROM users",
			"admin\" OR \"1\"=\"1",
		];

		foreach ($maliciousInputs as $input) {
			$result = $method->invoke($this->controller, $input);
			$this->assertEmpty($result, "Should reject: $input");
		}
	}

	/**
	 * Test XSS in login is rejected
	 */
	public function testXSSInLoginRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$maliciousInputs = [
			'<script>alert(1)</script>',
			'admin<img src=x onerror=alert(1)>',
			'user"><script>alert(1)</script>',
		];

		foreach ($maliciousInputs as $input) {
			$result = $method->invoke($this->controller, $input);
			$this->assertEmpty($result, "Should reject: $input");
		}
	}

	/**
	 * Test null bytes in login are stripped
	 */
	public function testNullBytesInLoginStripped(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$inputWithNull = "admin\0injection";
		$result = $method->invoke($this->controller, $inputWithNull);

		$this->assertStringNotContainsString("\0", $result);
	}

	/**
	 * Test overly long login is rejected
	 */
	public function testOverlyLongLoginRejected(): void
	{
		$method = $this->getPrivateMethod(AuthController::class, '_validateAndSanitizeLogin');

		$longInput = str_repeat('a', 300);
		$result = $method->invoke($this->controller, $longInput);

		$this->assertEmpty($result);
	}

	// =========================================================================
	// Log Sanitization Tests
	// =========================================================================

	/**
	 * Test IP address masking
	 */
	public function testIPMasking(): void
	{
		// IPv4
		$this->assertEquals('192.168.xxx.xxx', LogSanitizer::maskIP('192.168.1.100'));
		$this->assertEquals('10.0.xxx.xxx', LogSanitizer::maskIP('10.0.0.1'));

		// Invalid
		$this->assertEquals('0.0.0.0', LogSanitizer::maskIP(''));
		$this->assertEquals('x.x.x.x', LogSanitizer::maskIP('not-an-ip'));
	}

	/**
	 * Test email masking
	 */
	public function testEmailMasking(): void
	{
		$this->assertEquals('us***@example.com', LogSanitizer::maskEmail('user@example.com'));
		$this->assertEquals('ad***@domain.org', LogSanitizer::maskEmail('admin@domain.org'));
		$this->assertEquals('***@***.***', LogSanitizer::maskEmail('invalid-email'));
	}

	/**
	 * Test User-Agent sanitization
	 */
	public function testUserAgentSanitization(): void
	{
		$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
		$sanitized = LogSanitizer::sanitizeUserAgent($ua);

		// Version numbers should be replaced
		$this->assertStringNotContainsString('5.0', $sanitized);
		$this->assertStringNotContainsString('10.0', $sanitized);
		$this->assertStringContainsString('x.x', $sanitized);
	}

	/**
	 * Test User-Agent XSS prevention
	 */
	public function testUserAgentXSSPrevention(): void
	{
		$maliciousUA = 'Mozilla<script>alert(1)</script>/5.0';
		$sanitized = LogSanitizer::sanitizeUserAgent($maliciousUA);

		$this->assertStringNotContainsString('<script>', $sanitized);
		$this->assertStringNotContainsString('</script>', $sanitized);
	}

	/**
	 * Test token masking
	 */
	public function testTokenMasking(): void
	{
		$token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature';
		$masked = LogSanitizer::maskToken($token);

		$this->assertStringStartsWith('eyJh', $masked);
		$this->assertStringEndsWith('ture', $masked);
		$this->assertStringContainsString('...', $masked);
		$this->assertLessThan(strlen($token), strlen($masked));
	}

	/**
	 * Test URL sanitization removes sensitive parameters
	 */
	public function testURLSanitizationRemovesSensitiveParams(): void
	{
		$url = '/api/user?token=secret123&name=john&api_key=abc';
		$sanitized = LogSanitizer::sanitizeURL($url);

		$this->assertStringContainsString('token=***', $sanitized);
		$this->assertStringContainsString('api_key=***', $sanitized);
		$this->assertStringContainsString('name=john', $sanitized);
		$this->assertStringNotContainsString('secret123', $sanitized);
	}

	/**
	 * Test UUID masking
	 */
	public function testUUIDMasking(): void
	{
		// Standard UUID
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$masked = LogSanitizer::maskUUID($uuid);
		$this->assertEquals('550e8400-****-****-****-************', $masked);

		// SHA256 hash
		$hash = 'a1b2c3d4e5f67890abcdef1234567890a1b2c3d4e5f67890abcdef1234567890';
		$masked = LogSanitizer::maskUUID($hash);
		$this->assertStringStartsWith('a1b2c3d4', $masked);
		$this->assertStringContainsString('[hash]', $masked);
	}

	/**
	 * Test sanitizeLogData applies correct masking
	 */
	public function testSanitizeLogDataAppliesCorrectMasking(): void
	{
		$data = [
			'ip' => '192.168.1.100',
			'email' => 'user@example.com',
			'token' => 'eyJhbGciOiJIUzI1NiJ9.payload.sig',
			'user_agent' => 'Mozilla/5.0 (Windows NT 10.0)',
			'password' => 'secret123',
			'device_uuid' => '550e8400-e29b-41d4-a716-446655440000',
		];

		$sanitized = LogSanitizer::sanitizeLogData($data);

		$this->assertStringContainsString('xxx', $sanitized['ip']);
		$this->assertStringContainsString('***', $sanitized['email']);
		$this->assertStringContainsString('...', $sanitized['token']);
		$this->assertStringContainsString('x.x', $sanitized['user_agent']);
		$this->assertEquals('***', $sanitized['password']);
		$this->assertStringContainsString('****', $sanitized['device_uuid']);
	}

	// =========================================================================
	// Input Sanitizer Tests
	// =========================================================================

	/**
	 * Test InputSanitizer email validation
	 */
	public function testInputSanitizerEmailValidation(): void
	{
		$this->assertEquals('user@example.com', InputSanitizer::sanitizeEmail('user@example.com'));
		$this->assertEquals('test@domain.org', InputSanitizer::sanitizeEmail('TEST@Domain.ORG'));
		$this->assertNull(InputSanitizer::sanitizeEmail('not-an-email'));
		$this->assertNull(InputSanitizer::sanitizeEmail(''));
	}

	/**
	 * Test InputSanitizer UUID validation
	 */
	public function testInputSanitizerUUIDValidation(): void
	{
		// Valid UUID
		$this->assertEquals(
			'550e8400-e29b-41d4-a716-446655440000',
			InputSanitizer::sanitizeUUID('550E8400-E29B-41D4-A716-446655440000')
		);

		// Valid SHA256
		$hash = 'a1b2c3d4e5f67890abcdef1234567890a1b2c3d4e5f67890abcdef1234567890';
		$this->assertEquals($hash, InputSanitizer::sanitizeUUID($hash));

		// Invalid
		$this->assertNull(InputSanitizer::sanitizeUUID('invalid-uuid'));
		$this->assertNull(InputSanitizer::sanitizeUUID("'; DROP TABLE--"));
	}

	/**
	 * Test InputSanitizer string sanitization removes XSS
	 */
	public function testInputSanitizerStringSanitizationRemovesXSS(): void
	{
		$malicious = '<script>alert("XSS")</script>';
		$sanitized = InputSanitizer::sanitizeString($malicious);

		$this->assertStringNotContainsString('<script>', $sanitized);
		$this->assertStringNotContainsString('</script>', $sanitized);
	}

	/**
	 * Test InputSanitizer string sanitization removes null bytes
	 */
	public function testInputSanitizerStringSanitizationRemovesNullBytes(): void
	{
		$input = "normal\0text\0here";
		$sanitized = InputSanitizer::sanitizeString($input);

		$this->assertStringNotContainsString("\0", $sanitized);
	}

	/**
	 * Test InputSanitizer IP validation
	 */
	public function testInputSanitizerIPValidation(): void
	{
		$this->assertEquals('192.168.1.1', InputSanitizer::sanitizeIP('192.168.1.1'));
		$this->assertEquals('::1', InputSanitizer::sanitizeIP('::1'));
		$this->assertNull(InputSanitizer::sanitizeIP('not-an-ip'));
		$this->assertNull(InputSanitizer::sanitizeIP('999.999.999.999'));
	}

	// =========================================================================
	// InputSanitizer::sanitizeUsername Tests
	// =========================================================================

	/**
	 * Test sanitizeUsername accepts valid usernames
	 */
	public function testSanitizeUsernameAcceptsValidUsernames(): void
	{
		// Simple alphanumeric
		$this->assertEquals('admin', InputSanitizer::sanitizeUsername('admin'));
		$this->assertEquals('user123', InputSanitizer::sanitizeUsername('user123'));

		// With underscore
		$this->assertEquals('user_name', InputSanitizer::sanitizeUsername('user_name'));

		// With hyphen
		$this->assertEquals('user-name', InputSanitizer::sanitizeUsername('user-name'));

		// With dot
		$this->assertEquals('john.doe', InputSanitizer::sanitizeUsername('john.doe'));

		// Mixed
		$this->assertEquals('john.doe_test-123', InputSanitizer::sanitizeUsername('john.doe_test-123'));
	}

	/**
	 * Test sanitizeUsername rejects invalid usernames
	 */
	public function testSanitizeUsernameRejectsInvalidUsernames(): void
	{
		// Special characters
		$this->assertNull(InputSanitizer::sanitizeUsername('user@name'));
		$this->assertNull(InputSanitizer::sanitizeUsername('user#name'));
		$this->assertNull(InputSanitizer::sanitizeUsername('user$name'));
		$this->assertNull(InputSanitizer::sanitizeUsername('user%name'));

		// Spaces
		$this->assertNull(InputSanitizer::sanitizeUsername('user name'));

		// Empty
		$this->assertNull(InputSanitizer::sanitizeUsername(''));

		// SQL injection
		$this->assertNull(InputSanitizer::sanitizeUsername("admin'; DROP TABLE--"));
		$this->assertNull(InputSanitizer::sanitizeUsername("' OR '1'='1"));

		// XSS
		$this->assertNull(InputSanitizer::sanitizeUsername('<script>alert(1)</script>'));
	}

	/**
	 * Test sanitizeUsername handles null bytes
	 */
	public function testSanitizeUsernameHandlesNullBytes(): void
	{
		// Null bytes are stripped, then the remaining string is validated
		// "admin\0test" becomes "admintest" which is valid
		$this->assertEquals('admintest', InputSanitizer::sanitizeUsername("admin\0test"));

		// Null byte only leaves empty string which is rejected
		$this->assertNull(InputSanitizer::sanitizeUsername("\0"));
	}

	/**
	 * Test sanitizeUsername respects max length
	 */
	public function testSanitizeUsernameRespectsMaxLength(): void
	{
		$longUsername = str_repeat('a', 300);
		$this->assertNull(InputSanitizer::sanitizeUsername($longUsername));

		// With custom max length
		$this->assertNull(InputSanitizer::sanitizeUsername('toolongusername', 5));
		$this->assertEquals('short', InputSanitizer::sanitizeUsername('short', 10));
	}

	/**
	 * Test sanitizeUsername trims whitespace
	 */
	public function testSanitizeUsernameTrimsWhitespace(): void
	{
		$this->assertEquals('admin', InputSanitizer::sanitizeUsername('  admin  '));
		$this->assertEquals('admin', InputSanitizer::sanitizeUsername("\tadmin\n"));
	}

	/**
	 * Test sanitizeUsername handles non-string input
	 */
	public function testSanitizeUsernameHandlesNonStringInput(): void
	{
		$this->assertNull(InputSanitizer::sanitizeUsername(null));
		$this->assertNull(InputSanitizer::sanitizeUsername([]));
		$this->assertNull(InputSanitizer::sanitizeUsername(new \stdClass()));

		// Numeric input should work (converted to string)
		$this->assertEquals('12345', InputSanitizer::sanitizeUsername(12345));
	}
}
