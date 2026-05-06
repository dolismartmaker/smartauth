<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/InputSanitizer.php';

use SmartAuth\Api\InputSanitizer;

/**
 * Integration tests for InputSanitizer
 * Focuses on methods not covered by other tests
 *
 * @covers \SmartAuth\Api\InputSanitizer
 */
class InputSanitizerTest extends DolibarrRealTestCase
{
    /**
     * Test sanitizeIP with valid IPv4
     */
    public function testSanitizeIPValidIPv4(): void
    {
        $this->assertEquals('192.168.1.1', InputSanitizer::sanitizeIP('192.168.1.1'));
        $this->assertEquals('10.0.0.1', InputSanitizer::sanitizeIP('10.0.0.1'));
        $this->assertEquals('8.8.8.8', InputSanitizer::sanitizeIP('8.8.8.8'));
    }

    /**
     * Test sanitizeIP with valid IPv6
     */
    public function testSanitizeIPValidIPv6(): void
    {
        $this->assertEquals('::1', InputSanitizer::sanitizeIP('::1'));
        $this->assertEquals('2001:db8::1', InputSanitizer::sanitizeIP('2001:db8::1'));
    }

    /**
     * Test sanitizeIP with invalid IP
     */
    public function testSanitizeIPInvalidReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeIP('not-an-ip'));
        $this->assertNull(InputSanitizer::sanitizeIP('256.256.256.256'));
        $this->assertNull(InputSanitizer::sanitizeIP(''));
    }

    /**
     * Test sanitizeIP with non-string input
     */
    public function testSanitizeIPNonStringReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeIP(123));
        $this->assertNull(InputSanitizer::sanitizeIP(null));
        $this->assertNull(InputSanitizer::sanitizeIP(['192.168.1.1']));
    }

    /**
     * Test sanitizeIP trims whitespace
     */
    public function testSanitizeIPTrimsWhitespace(): void
    {
        $this->assertEquals('192.168.1.1', InputSanitizer::sanitizeIP('  192.168.1.1  '));
    }

    /**
     * Test sanitizeURL with valid URLs
     */
    public function testSanitizeURLValidURLs(): void
    {
        $this->assertEquals('https://example.com', InputSanitizer::sanitizeURL('https://example.com'));
        $this->assertEquals('http://test.org/path', InputSanitizer::sanitizeURL('http://test.org/path'));
        $this->assertEquals('https://example.com/path?query=value', InputSanitizer::sanitizeURL('https://example.com/path?query=value'));
    }

    /**
     * Test sanitizeURL with invalid URLs
     */
    public function testSanitizeURLInvalidReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeURL('not-a-url'));
        $this->assertNull(InputSanitizer::sanitizeURL('just-text'));
        $this->assertNull(InputSanitizer::sanitizeURL(''));
    }

    /**
     * Test sanitizeURL with non-string input
     */
    public function testSanitizeURLNonStringReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeURL(123));
        $this->assertNull(InputSanitizer::sanitizeURL(null));
        $this->assertNull(InputSanitizer::sanitizeURL(['https://example.com']));
    }

    /**
     * Test sanitizeURL rejects too long URLs
     */
    public function testSanitizeURLRejectsTooLong(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 2000);
        $this->assertNull(InputSanitizer::sanitizeURL($longUrl));
    }

    /**
     * Test sanitizeUsername with valid usernames
     */
    public function testSanitizeUsernameValidUsernames(): void
    {
        $this->assertEquals('john_doe', InputSanitizer::sanitizeUsername('john_doe'));
        $this->assertEquals('user-123', InputSanitizer::sanitizeUsername('user-123'));
        $this->assertEquals('john.doe', InputSanitizer::sanitizeUsername('john.doe'));
        $this->assertEquals('User123', InputSanitizer::sanitizeUsername('User123'));
    }

    /**
     * Test sanitizeUsername rejects invalid characters
     */
    public function testSanitizeUsernameRejectsInvalidChars(): void
    {
        $this->assertNull(InputSanitizer::sanitizeUsername('user@domain'));
        $this->assertNull(InputSanitizer::sanitizeUsername('user name'));
        $this->assertNull(InputSanitizer::sanitizeUsername('user<script>'));
        $this->assertNull(InputSanitizer::sanitizeUsername('user!#$%'));
    }

    /**
     * Test sanitizeUsername with empty input
     */
    public function testSanitizeUsernameEmptyReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeUsername(''));
        $this->assertNull(InputSanitizer::sanitizeUsername('   '));
    }

    /**
     * Test sanitizeUsername with non-string input
     */
    public function testSanitizeUsernameNonStringReturnsNull(): void
    {
        $this->assertNull(InputSanitizer::sanitizeUsername(null));
        $this->assertNull(InputSanitizer::sanitizeUsername(['username']));
    }

    /**
     * Test sanitizeUsername respects max length
     */
    public function testSanitizeUsernameRespectsMaxLength(): void
    {
        $longUsername = str_repeat('a', 300);
        $this->assertNull(InputSanitizer::sanitizeUsername($longUsername));

        $validLong = str_repeat('a', 100);
        $this->assertEquals($validLong, InputSanitizer::sanitizeUsername($validLong, 100));
    }

    /**
     * Test sanitizeUsername accepts numeric input
     */
    public function testSanitizeUsernameAcceptsNumeric(): void
    {
        $this->assertEquals('123456', InputSanitizer::sanitizeUsername(123456));
    }

    /**
     * Test validateEnum with allowed value
     */
    public function testValidateEnumWithAllowedValue(): void
    {
        $allowed = ['active', 'inactive', 'pending'];

        $this->assertEquals('active', InputSanitizer::validateEnum('active', $allowed));
        $this->assertEquals('inactive', InputSanitizer::validateEnum('inactive', $allowed));
    }

    /**
     * Test validateEnum with disallowed value returns default
     */
    public function testValidateEnumWithDisallowedValueReturnsDefault(): void
    {
        $allowed = ['active', 'inactive', 'pending'];

        $this->assertNull(InputSanitizer::validateEnum('invalid', $allowed));
        $this->assertEquals('default', InputSanitizer::validateEnum('invalid', $allowed, 'default'));
    }

    /**
     * Test validateEnum with type strict checking
     */
    public function testValidateEnumStrictTypeChecking(): void
    {
        $allowed = [1, 2, 3];

        $this->assertEquals(1, InputSanitizer::validateEnum(1, $allowed));
        // String '1' should NOT match integer 1 (strict)
        $this->assertNull(InputSanitizer::validateEnum('1', $allowed));
    }

    /**
     * Test sanitizeForLog with basic string
     */
    public function testSanitizeForLogBasicString(): void
    {
        $result = InputSanitizer::sanitizeForLog('test message', 100, $this->db);
        $this->assertEquals('test message', $result);
    }

    /**
     * Test sanitizeForLog truncates long strings
     */
    public function testSanitizeForLogTruncatesLong(): void
    {
        $longString = str_repeat('a', 200);
        $result = InputSanitizer::sanitizeForLog($longString, 50, $this->db);

        $this->assertEquals(50, strlen($result));
    }

    /**
     * Test sanitizeForLog escapes SQL special characters
     */
    public function testSanitizeForLogEscapesSql(): void
    {
        $malicious = "test'; DROP TABLE --";
        $result = InputSanitizer::sanitizeForLog($malicious, 100, $this->db);

        // The result should be escaped (quotes are doubled in SQL)
        // SQLite escapes ' as '' so "test'" becomes "test''"
        $this->assertStringContainsString("''", $result);
    }

    /**
     * Test sanitizeForLog with non-string input
     */
    public function testSanitizeForLogConvertsNonString(): void
    {
        $result = InputSanitizer::sanitizeForLog(12345, 100, $this->db);
        $this->assertEquals('12345', $result);
    }

    /**
     * Test sanitizeArray with string items
     */
    public function testSanitizeArrayWithStrings(): void
    {
        $input = ['  hello  ', '<script>alert(1)</script>', 'normal'];
        $result = InputSanitizer::sanitizeArray($input, InputSanitizer::TYPE_STRING);

        $this->assertCount(3, $result);
        $this->assertEquals('hello', $result[0]);
        $this->assertStringNotContainsString('<script>', $result[1]);
        $this->assertEquals('normal', $result[2]);
    }

    /**
     * Test sanitizeArray with int items
     */
    public function testSanitizeArrayWithInts(): void
    {
        $input = ['1', '2', 'abc', '42'];
        $result = InputSanitizer::sanitizeArray($input, InputSanitizer::TYPE_INT);

        $this->assertCount(4, $result);
        $this->assertSame(1, $result[0]);
        $this->assertSame(2, $result[1]);
        $this->assertSame(0, $result[2]);
        $this->assertSame(42, $result[3]);
    }

    /**
     * Test sanitizeArray respects maxItems
     */
    public function testSanitizeArrayRespectsMaxItems(): void
    {
        $input = range(1, 200);
        $result = InputSanitizer::sanitizeArray($input, InputSanitizer::TYPE_INT, ['maxItems' => 10]);

        $this->assertCount(10, $result);
    }

    /**
     * Test sanitize with schema
     */
    public function testSanitizeWithSchema(): void
    {
        $data = [
            'name' => '  John  ',
            'email' => 'JOHN@EXAMPLE.COM',
            'age' => '25',
            'active' => 'true'
        ];

        $schema = [
            'name' => ['type' => InputSanitizer::TYPE_STRING],
            'email' => ['type' => InputSanitizer::TYPE_EMAIL],
            'age' => ['type' => InputSanitizer::TYPE_INT, 'min' => 0, 'max' => 150],
            'active' => ['type' => InputSanitizer::TYPE_BOOL]
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertEquals('John', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
        $this->assertSame(25, $result['age']);
        $this->assertTrue($result['active']);
    }

    /**
     * Test sanitize throws exception for missing required field
     */
    public function testSanitizeThrowsForMissingRequired(): void
    {
        $data = ['name' => 'John'];

        $schema = [
            'name' => ['type' => InputSanitizer::TYPE_STRING],
            'email' => ['type' => InputSanitizer::TYPE_EMAIL, 'required' => true]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: email');

        InputSanitizer::sanitize($data, $schema);
    }

    /**
     * Test sanitize uses default value when field missing
     */
    public function testSanitizeUsesDefaultValue(): void
    {
        $data = ['name' => 'John'];

        $schema = [
            'name' => ['type' => InputSanitizer::TYPE_STRING],
            'status' => ['type' => InputSanitizer::TYPE_STRING, 'default' => 'active']
        ];

        $result = InputSanitizer::sanitize($data, $schema);

        $this->assertEquals('active', $result['status']);
    }

    /**
     * Test sanitizeAll with mixed data
     */
    public function testSanitizeAllMixedData(): void
    {
        $data = [
            'name' => '  test  ',
            'count' => 42,
            'price' => 19.99,
            'active' => true,
            'nested' => ['key' => 'value']
        ];

        $result = InputSanitizer::sanitizeAll($data);

        $this->assertEquals('test', $result['name']);
        $this->assertSame(42, $result['count']);
        $this->assertSame(19.99, $result['price']);
        $this->assertTrue($result['active']);
        $this->assertIsArray($result['nested']);
        $this->assertEquals('value', $result['nested']['key']);
    }

    /**
     * Test sanitizeAll skips invalid keys
     */
    public function testSanitizeAllSkipsInvalidKeys(): void
    {
        $data = [
            'valid_key' => 'value1',
            'another-valid' => 'value2',
            '<script>' => 'malicious'
        ];

        $result = InputSanitizer::sanitizeAll($data);

        $this->assertArrayHasKey('valid_key', $result);
        $this->assertArrayHasKey('another-valid', $result);
        $this->assertArrayNotHasKey('<script>', $result);
    }

    /**
     * Test sanitizeFloat
     */
    public function testSanitizeFloat(): void
    {
        $this->assertSame(3.14, InputSanitizer::sanitizeFloat('3.14'));
        $this->assertSame(42.0, InputSanitizer::sanitizeFloat(42));
        $this->assertSame(0.0, InputSanitizer::sanitizeFloat('abc'));
    }

    /**
     * Test sanitizeBool
     */
    public function testSanitizeBool(): void
    {
        $this->assertTrue(InputSanitizer::sanitizeBool('true'));
        $this->assertTrue(InputSanitizer::sanitizeBool('1'));
        $this->assertTrue(InputSanitizer::sanitizeBool('yes'));
        $this->assertTrue(InputSanitizer::sanitizeBool(1));

        $this->assertFalse(InputSanitizer::sanitizeBool('false'));
        $this->assertFalse(InputSanitizer::sanitizeBool('0'));
        $this->assertFalse(InputSanitizer::sanitizeBool('no'));
        $this->assertFalse(InputSanitizer::sanitizeBool(0));
    }

    /**
     * Test sanitizeAlphanumeric
     */
    public function testSanitizeAlphanumeric(): void
    {
        $this->assertEquals('test_value-123', InputSanitizer::sanitizeAlphanumeric('test_value-123'));
        $this->assertEquals('testvalue', InputSanitizer::sanitizeAlphanumeric('test@#$value'));
        $this->assertEquals('', InputSanitizer::sanitizeAlphanumeric('@#$%^&'));
    }

    /**
     * Test sanitize with int min/max constraints
     */
    public function testSanitizeIntWithMinMax(): void
    {
        $schema = [
            'value' => ['type' => InputSanitizer::TYPE_INT, 'min' => 10, 'max' => 100]
        ];

        $result = InputSanitizer::sanitize(['value' => 5], $schema);
        $this->assertSame(10, $result['value']);

        $result = InputSanitizer::sanitize(['value' => 150], $schema);
        $this->assertSame(100, $result['value']);

        $result = InputSanitizer::sanitize(['value' => 50], $schema);
        $this->assertSame(50, $result['value']);
    }

    /**
     * Test sanitize with float min/max constraints
     */
    public function testSanitizeFloatWithMinMax(): void
    {
        $schema = [
            'value' => ['type' => InputSanitizer::TYPE_FLOAT, 'min' => 0.0, 'max' => 100.0]
        ];

        $result = InputSanitizer::sanitize(['value' => -5.5], $schema);
        $this->assertSame(0.0, $result['value']);

        $result = InputSanitizer::sanitize(['value' => 150.5], $schema);
        $this->assertSame(100.0, $result['value']);
    }

    /**
     * Test clearCache
     */
    public function testClearCache(): void
    {
        // Load sanitizers to populate cache
        InputSanitizer::loadExternalSanitizers();

        // Clear cache
        InputSanitizer::clearCache();

        // Force reload should work after clear
        $sanitizers = InputSanitizer::loadExternalSanitizers(true);
        $this->assertIsArray($sanitizers);
    }

    /**
     * Test loadExternalSanitizers returns array
     */
    public function testLoadExternalSanitizersReturnsArray(): void
    {
        $sanitizers = InputSanitizer::loadExternalSanitizers();
        $this->assertIsArray($sanitizers);
    }

    /**
     * Test sanitize with UUID type throws on invalid required
     */
    public function testSanitizeUUIDThrowsOnInvalidRequired(): void
    {
        $schema = [
            'device_id' => ['type' => InputSanitizer::TYPE_UUID, 'required' => true]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID format');

        InputSanitizer::sanitize(['device_id' => 'not-a-uuid'], $schema);
    }

    /**
     * Test sanitize with email type throws on invalid required
     */
    public function testSanitizeEmailThrowsOnInvalidRequired(): void
    {
        $schema = [
            'email' => ['type' => InputSanitizer::TYPE_EMAIL, 'required' => true]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        InputSanitizer::sanitize(['email' => 'not-an-email'], $schema);
    }

    /**
     * Test sanitize with raw type passes through unchanged
     */
    public function testSanitizeRawTypePassesThrough(): void
    {
        $schema = [
            'data' => ['type' => InputSanitizer::TYPE_RAW]
        ];

        $original = ['complex' => ['nested' => 'data'], 'html' => '<p>test</p>'];
        $result = InputSanitizer::sanitize(['data' => $original], $schema);

        $this->assertEquals($original, $result['data']);
    }

    /**
     * Test sanitizeAll preserves numeric key "0"
     *
     * Regression test: PHP's empty("0") returns true, which caused
     * numeric key "0" to be incorrectly skipped in sanitizeAll().
     */
    public function testSanitizeAllPreservesNumericKeyZero(): void
    {
        $data = [
            '0' => 'first_value',
            '1' => 'second_value',
            'name' => 'test'
        ];

        $result = InputSanitizer::sanitizeAll($data);

        // Key "0" should NOT be skipped (regression: empty("0") === true in PHP)
        $this->assertArrayHasKey('0', $result);
        $this->assertEquals('first_value', $result['0']);
        $this->assertArrayHasKey('1', $result);
        $this->assertEquals('second_value', $result['1']);
        $this->assertArrayHasKey('name', $result);
    }

    /**
     * Test sanitizeAll with array having only numeric keys
     */
    public function testSanitizeAllWithNumericKeysArray(): void
    {
        // Indexed array with numeric string keys
        $data = ['zero', 'one', 'two'];

        $result = InputSanitizer::sanitizeAll($data);

        // All values should be preserved
        $this->assertCount(3, $result);
        $this->assertEquals('zero', $result['0']);
        $this->assertEquals('one', $result['1']);
        $this->assertEquals('two', $result['2']);
    }

    /**
     * Regression: sanitizeString must be idempotent.
     *
     * SmartAuth is a JSON API; HTML escaping belongs to the consumer (which
     * knows the rendering context: HTML body, attribute, JS, URL...).
     * Applying htmlspecialchars() at the API layer used to corrupt strings
     * across PUT round-trips:
     *   "L'École"
     *     -> 1st save : L&apos;École
     *     -> 2nd save : L&amp;apos;École
     *     -> 3rd save : L&amp;amp;apos;École
     * After the InputSanitizer fix (companion to TODO-SECURITY-01), the
     * sanitizer no longer HTML-escapes, so saving the same value any number
     * of times must yield the same output.
     */
    public function testSanitizeStringIsIdempotentForApostrophe(): void
    {
        $input = "L'École";
        $once = InputSanitizer::sanitizeString($input);
        $twice = InputSanitizer::sanitizeString($once);
        $thrice = InputSanitizer::sanitizeString($twice);

        $this->assertSame($input, $once, 'first pass must preserve apostrophe');
        $this->assertSame($once, $twice, 'sanitizeString must be idempotent');
        $this->assertSame($twice, $thrice, 'sanitizeString must be idempotent over many round-trips');

        // Same expectation for the other characters htmlspecialchars used
        // to mangle: " < > &.
        $samples = [
            'plain text',
            'an "quote" inside',
            "an 'apostrophe' inside",
            'less <than',
            'greater >than',
            'amp & ersand',
            'multi: <a href="x">L\'École & co</a>',
        ];
        foreach ($samples as $s) {
            $first = InputSanitizer::sanitizeString($s);
            $second = InputSanitizer::sanitizeString($first);
            $this->assertSame(
                $first,
                $second,
                'sanitizeString must be idempotent for: ' . $s
            );
            // No HTML entity should leak into the output (quotes / apos /
            // amp / lt / gt remain raw - the caller is responsible for the
            // contextual escape on render).
            $this->assertDoesNotMatchRegularExpression(
                '/&(amp|apos|quot|lt|gt|#0?39);/',
                $first,
                'sanitizeString must not introduce HTML entities for: ' . $s
            );
        }
    }

    /**
     * Regression: sanitizeAll must also preserve apostrophes across passes.
     *
     * sanitizeAll is the entry point used by RouteController on every PUT
     * body. This test covers the full round-trip path that the bug report
     * documented.
     */
    public function testSanitizeAllPreservesApostropheAcrossRoundTrips(): void
    {
        $payload = [
            'name' => "L'École",
            'note' => "It's fine & it's safe",
        ];
        $first = InputSanitizer::sanitizeAll($payload);
        $second = InputSanitizer::sanitizeAll($first);
        $third = InputSanitizer::sanitizeAll($second);

        $this->assertSame("L'École", $first['name']);
        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    /**
     * Defence in depth: sanitizeString still strips HTML tags.
     *
     * Removing the htmlspecialchars step does not relax the strip_tags
     * step - inputs that look like HTML are still de-tagged. PHP's
     * strip_tags() removes the markup but keeps the inner text content,
     * which is fine because the API never renders back as HTML; the
     * consumer is responsible for escaping its rendering context.
     */
    public function testSanitizeStringStillStripsHtmlTags(): void
    {
        $this->assertSame(
            'alert(1)innocent text',
            InputSanitizer::sanitizeString('<script>alert(1)</script>innocent text')
        );
        $this->assertSame(
            'click me',
            InputSanitizer::sanitizeString('<a href="x">click me</a>')
        );

        // Tag content remains as plain text; angle brackets are gone so
        // a downstream HTML render that does not escape would not execute it.
        $this->assertStringNotContainsString(
            '<',
            InputSanitizer::sanitizeString('<img src=x onerror=alert(1)>')
        );
        $this->assertStringNotContainsString(
            '>',
            InputSanitizer::sanitizeString('<img src=x onerror=alert(1)>')
        );
    }
}
