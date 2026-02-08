<?php

/**
 * Unit tests for ScopeManager
 *
 * Tests OAuth2/OIDC scope management functionality.
 *
 * @covers \SmartAuth\Api\OAuth2\ScopeManager
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\ScopeManager;

class ScopeManagerTest extends TestCase
{
    /**
     * Test parsing space-separated scopes string
     */
    public function testParseScopesBasic(): void
    {
        $scopes = ScopeManager::parseScopes('openid profile email');

        $this->assertCount(3, $scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
        $this->assertContains('email', $scopes);
    }

    /**
     * Test parsing scopes with multiple spaces
     */
    public function testParseScopesMultipleSpaces(): void
    {
        $scopes = ScopeManager::parseScopes('openid   profile    email');

        $this->assertCount(3, $scopes);
    }

    /**
     * Test parsing scopes with leading/trailing spaces
     */
    public function testParseScopesTrimmed(): void
    {
        $scopes = ScopeManager::parseScopes('  openid profile  ');

        $this->assertCount(2, $scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
    }

    /**
     * Test parsing empty scope string returns empty array
     */
    public function testParseScopesEmpty(): void
    {
        $this->assertEmpty(ScopeManager::parseScopes(''));
        $this->assertEmpty(ScopeManager::parseScopes('   '));
    }

    /**
     * Test parsing removes duplicate scopes
     */
    public function testParseScopesDuplicates(): void
    {
        $scopes = ScopeManager::parseScopes('openid openid profile openid');

        $this->assertCount(2, $scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
    }

    /**
     * Test formatting scopes array to string
     */
    public function testFormatScopes(): void
    {
        $formatted = ScopeManager::formatScopes(['openid', 'profile', 'email']);

        $this->assertEquals('openid profile email', $formatted);
    }

    /**
     * Test formatting removes duplicates
     */
    public function testFormatScopesDuplicates(): void
    {
        $formatted = ScopeManager::formatScopes(['openid', 'profile', 'openid']);

        $this->assertEquals('openid profile', $formatted);
    }

    /**
     * Test formatting filters empty values
     */
    public function testFormatScopesFiltersEmpty(): void
    {
        $formatted = ScopeManager::formatScopes(['openid', '', 'profile', null]);

        $this->assertStringNotContainsString('  ', $formatted);
    }

    /**
     * Test isValidScope for known scopes
     */
    public function testIsValidScopeKnown(): void
    {
        $this->assertTrue(ScopeManager::isValidScope('openid'));
        $this->assertTrue(ScopeManager::isValidScope('profile'));
        $this->assertTrue(ScopeManager::isValidScope('email'));
        $this->assertTrue(ScopeManager::isValidScope('groups'));
        $this->assertTrue(ScopeManager::isValidScope('roles'));
        $this->assertTrue(ScopeManager::isValidScope('offline_access'));
    }

    /**
     * Test isValidScope for unknown scopes
     */
    public function testIsValidScopeUnknown(): void
    {
        $this->assertFalse(ScopeManager::isValidScope('unknown'));
        $this->assertFalse(ScopeManager::isValidScope('OPENID')); // Case sensitive
        $this->assertFalse(ScopeManager::isValidScope(''));
        $this->assertFalse(ScopeManager::isValidScope('admin'));
    }

    /**
     * Test validateScopes returns empty array for all valid
     */
    public function testValidateScopesAllValid(): void
    {
        $invalid = ScopeManager::validateScopes(['openid', 'profile', 'email']);

        $this->assertEmpty($invalid);
    }

    /**
     * Test validateScopes returns invalid scopes
     */
    public function testValidateScopesWithInvalid(): void
    {
        $invalid = ScopeManager::validateScopes(['openid', 'unknown', 'profile', 'invalid']);

        $this->assertCount(2, $invalid);
        $this->assertContains('unknown', $invalid);
        $this->assertContains('invalid', $invalid);
    }

    /**
     * Test getDescription returns scope description
     */
    public function testGetDescriptionKnown(): void
    {
        $desc = ScopeManager::getDescription('openid');

        $this->assertIsString($desc);
        $this->assertNotEmpty($desc);
        $this->assertNotEquals('openid', $desc); // Should not just return scope name
    }

    /**
     * Test getDescription returns scope name for unknown
     */
    public function testGetDescriptionUnknown(): void
    {
        $desc = ScopeManager::getDescription('unknown_scope');

        $this->assertEquals('unknown_scope', $desc);
    }

    /**
     * Test getDescription with long flag
     */
    public function testGetDescriptionLong(): void
    {
        $short = ScopeManager::getDescription('openid', false);
        $long = ScopeManager::getDescription('openid', true);

        $this->assertIsString($short);
        $this->assertIsString($long);
        // Long description is typically longer or equal
        $this->assertGreaterThanOrEqual(strlen($short), strlen($long));
    }

    /**
     * Test getDescriptions returns array of descriptions
     */
    public function testGetDescriptions(): void
    {
        $descriptions = ScopeManager::getDescriptions(['openid', 'profile', 'email']);

        $this->assertIsArray($descriptions);
        $this->assertCount(3, $descriptions);
        $this->assertArrayHasKey('openid', $descriptions);
        $this->assertArrayHasKey('profile', $descriptions);
        $this->assertArrayHasKey('email', $descriptions);
    }

    /**
     * Test requiresOpenId returns true when openid present
     */
    public function testRequiresOpenIdTrue(): void
    {
        $this->assertTrue(ScopeManager::requiresOpenId(['openid', 'profile']));
        $this->assertTrue(ScopeManager::requiresOpenId(['openid']));
    }

    /**
     * Test requiresOpenId returns false when openid absent
     */
    public function testRequiresOpenIdFalse(): void
    {
        $this->assertFalse(ScopeManager::requiresOpenId(['profile', 'email']));
        $this->assertFalse(ScopeManager::requiresOpenId([]));
    }

    /**
     * Test requiresOfflineAccess returns true when offline_access present
     */
    public function testRequiresOfflineAccessTrue(): void
    {
        $this->assertTrue(ScopeManager::requiresOfflineAccess(['openid', 'offline_access']));
    }

    /**
     * Test requiresOfflineAccess returns false when offline_access absent
     */
    public function testRequiresOfflineAccessFalse(): void
    {
        $this->assertFalse(ScopeManager::requiresOfflineAccess(['openid', 'profile']));
    }

    /**
     * Test getClaims returns claims for scopes
     */
    public function testGetClaimsForOpenid(): void
    {
        $claims = ScopeManager::getClaims(['openid']);

        $this->assertContains('sub', $claims);
    }

    /**
     * Test getClaims returns claims for profile scope
     */
    public function testGetClaimsForProfile(): void
    {
        $claims = ScopeManager::getClaims(['profile']);

        $this->assertContains('name', $claims);
        $this->assertContains('family_name', $claims);
        $this->assertContains('given_name', $claims);
    }

    /**
     * Test getClaims returns claims for email scope
     */
    public function testGetClaimsForEmail(): void
    {
        $claims = ScopeManager::getClaims(['email']);

        $this->assertContains('email', $claims);
        $this->assertContains('email_verified', $claims);
    }

    /**
     * Test getClaims combines claims from multiple scopes
     */
    public function testGetClaimsMultipleScopes(): void
    {
        $claims = ScopeManager::getClaims(['openid', 'profile', 'email']);

        $this->assertContains('sub', $claims);
        $this->assertContains('name', $claims);
        $this->assertContains('email', $claims);
    }

    /**
     * Test getClaims returns unique claims
     */
    public function testGetClaimsUnique(): void
    {
        $claims = ScopeManager::getClaims(['openid', 'openid', 'profile']);

        $uniqueCount = count(array_unique($claims));
        $this->assertEquals($uniqueCount, count($claims));
    }

    /**
     * Test getSupportedScopes returns all known scopes
     */
    public function testGetSupportedScopes(): void
    {
        $scopes = ScopeManager::getSupportedScopes();

        $this->assertIsArray($scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
        $this->assertContains('email', $scopes);
        $this->assertContains('groups', $scopes);
        $this->assertContains('roles', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    /**
     * Test filterValidScopes keeps only valid scopes
     */
    public function testFilterValidScopes(): void
    {
        $filtered = ScopeManager::filterValidScopes(['openid', 'unknown', 'profile', 'invalid']);

        $this->assertCount(2, $filtered);
        $this->assertContains('openid', $filtered);
        $this->assertContains('profile', $filtered);
        $this->assertNotContains('unknown', $filtered);
        $this->assertNotContains('invalid', $filtered);
    }

    /**
     * Test filterAllowedScopes returns intersection
     */
    public function testFilterAllowedScopes(): void
    {
        $requested = ['openid', 'profile', 'email'];
        $allowed = ['openid', 'profile'];

        $filtered = ScopeManager::filterAllowedScopes($requested, $allowed);

        $this->assertCount(2, $filtered);
        $this->assertContains('openid', $filtered);
        $this->assertContains('profile', $filtered);
        $this->assertNotContains('email', $filtered);
    }

    /**
     * Test areAllScopesAllowed returns true when all allowed
     */
    public function testAreAllScopesAllowedTrue(): void
    {
        $requested = ['openid', 'profile'];
        $allowed = ['openid', 'profile', 'email'];

        $this->assertTrue(ScopeManager::areAllScopesAllowed($requested, $allowed));
    }

    /**
     * Test areAllScopesAllowed returns false when some not allowed
     */
    public function testAreAllScopesAllowedFalse(): void
    {
        $requested = ['openid', 'profile', 'email'];
        $allowed = ['openid', 'profile'];

        $this->assertFalse(ScopeManager::areAllScopesAllowed($requested, $allowed));
    }

    /**
     * Test getDisallowedScopes returns scopes not in allowed list
     */
    public function testGetDisallowedScopes(): void
    {
        $requested = ['openid', 'profile', 'email', 'groups'];
        $allowed = ['openid', 'profile'];

        $disallowed = ScopeManager::getDisallowedScopes($requested, $allowed);

        $this->assertCount(2, $disallowed);
        $this->assertContains('email', $disallowed);
        $this->assertContains('groups', $disallowed);
    }

    /**
     * Test normalizeScopes lowercases, dedupes, and sorts
     */
    public function testNormalizeScopes(): void
    {
        $scopes = ['Profile', 'OPENID', 'email', 'openid', 'profile'];

        $normalized = ScopeManager::normalizeScopes($scopes);

        $this->assertCount(3, $normalized);
        // Should be lowercase and sorted
        $this->assertEquals(['email', 'openid', 'profile'], $normalized);
    }

    /**
     * Test getScopeInfoForConsent returns structured info
     */
    public function testGetScopeInfoForConsent(): void
    {
        $scopes = ['openid', 'profile'];

        $info = ScopeManager::getScopeInfoForConsent($scopes);

        $this->assertCount(2, $info);

        foreach ($info as $scopeInfo) {
            $this->assertArrayHasKey('scope', $scopeInfo);
            $this->assertArrayHasKey('description', $scopeInfo);
            $this->assertArrayHasKey('description_long', $scopeInfo);
            $this->assertArrayHasKey('claims', $scopeInfo);
        }
    }

    /**
     * Test getScopeInfoForConsent handles unknown scope
     */
    public function testGetScopeInfoForConsentUnknown(): void
    {
        $scopes = ['custom_scope'];

        $info = ScopeManager::getScopeInfoForConsent($scopes);

        $this->assertCount(1, $info);
        $this->assertEquals('custom_scope', $info[0]['scope']);
        $this->assertEquals('custom_scope', $info[0]['description']);
    }
}
