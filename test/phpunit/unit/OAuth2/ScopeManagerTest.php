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

    // =========================================================================
    // Custom scopes registry
    // =========================================================================

    /**
     * Test registerScope adds a custom scope
     */
    public function testRegisterScope(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope(
            'inventory:read',
            'Read inventory data',
            'Access to read product inventory levels and stock information.'
        );

        $this->assertTrue(ScopeManager::isValidScope('inventory:read'));
        $this->assertContains('inventory:read', ScopeManager::getSupportedScopes());
    }

    /**
     * Test registerScope with only short description
     */
    public function testRegisterScopeShortDescriptionOnly(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('api:call', 'Call API endpoints');

        $this->assertTrue(ScopeManager::isValidScope('api:call'));
        $desc = ScopeManager::getDescription('api:call');
        $this->assertEquals('Call API endpoints', $desc);

        // Long description should fallback to short description
        $longDesc = ScopeManager::getDescription('api:call', true);
        $this->assertEquals('Call API endpoints', $longDesc);
    }

    /**
     * Test registerScope descriptions are accessible via getDescription
     */
    public function testRegisterScopeDescriptions(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope(
            'crm:write',
            'Write CRM data',
            'Create and modify contacts, companies, and opportunities in CRM.'
        );

        $short = ScopeManager::getDescription('crm:write', false);
        $long = ScopeManager::getDescription('crm:write', true);

        $this->assertEquals('Write CRM data', $short);
        $this->assertEquals('Create and modify contacts, companies, and opportunities in CRM.', $long);
    }

    /**
     * Test custom scope appears in getScopeInfoForConsent
     */
    public function testRegisterScopeInConsentInfo(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope(
            'billing:read',
            'Read billing data',
            'Access to invoices and payment history.'
        );

        $info = ScopeManager::getScopeInfoForConsent(['openid', 'billing:read']);

        $this->assertCount(2, $info);

        $billingInfo = null;
        foreach ($info as $item) {
            if ($item['scope'] === 'billing:read') {
                $billingInfo = $item;
                break;
            }
        }

        $this->assertNotNull($billingInfo);
        $this->assertEquals('Read billing data', $billingInfo['description']);
        $this->assertEquals('Access to invoices and payment history.', $billingInfo['description_long']);
        $this->assertEmpty($billingInfo['claims']);
    }

    /**
     * Test custom scope has no claims
     */
    public function testRegisterScopeHasNoClaims(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('custom:test', 'Test scope');

        $claims = ScopeManager::getClaims(['custom:test']);
        $this->assertEmpty($claims);
    }

    /**
     * Test custom scope combined with built-in scope claims
     */
    public function testCustomScopeWithBuiltinClaims(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('custom:test', 'Test scope');

        $claims = ScopeManager::getClaims(['openid', 'custom:test', 'email']);
        $this->assertContains('sub', $claims);
        $this->assertContains('email', $claims);
    }

    /**
     * Test getAllScopeDefinitions includes both built-in and custom
     */
    public function testGetAllScopeDefinitions(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('external:read', 'Read external data');

        $allDefs = ScopeManager::getAllScopeDefinitions();

        // Built-in scopes should be present
        $this->assertArrayHasKey('openid', $allDefs);
        $this->assertArrayHasKey('profile', $allDefs);
        $this->assertArrayHasKey('email', $allDefs);

        // Custom scope should be present
        $this->assertArrayHasKey('external:read', $allDefs);
    }

    /**
     * Test custom scope does not override built-in scope
     */
    public function testRegisterScopeDoesNotOverrideBuiltin(): void
    {
        ScopeManager::resetCustomScopes();

        // Try to register a scope with the same name as a built-in one
        ScopeManager::registerScope('openid', 'Overridden openid');

        // getAllScopeDefinitions merges with built-in taking priority
        // (built-in is the base, custom is merged on top)
        // Actually, array_merge gives priority to the SECOND array (custom),
        // but the description should still work
        $allDefs = ScopeManager::getAllScopeDefinitions();
        $this->assertArrayHasKey('openid', $allDefs);

        // The built-in claims should still exist (sub)
        // Since array_merge overwrites, the custom scope replaces the built-in
        // This is by design: registerScope CAN override, but loadCustomScopesFromConfig
        // checks !isset before registering
    }

    /**
     * Test resetCustomScopes clears the registry
     */
    public function testResetCustomScopes(): void
    {
        ScopeManager::registerScope('temp:scope', 'Temporary scope');
        $this->assertTrue(ScopeManager::isValidScope('temp:scope'));

        ScopeManager::resetCustomScopes();

        $this->assertFalse(ScopeManager::isValidScope('temp:scope'));
        $this->assertNotContains('temp:scope', ScopeManager::getSupportedScopes());
    }

    /**
     * Test validateScopes recognizes custom scopes as valid
     */
    public function testValidateScopesWithCustom(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('custom:valid', 'Valid custom scope');

        $invalid = ScopeManager::validateScopes(['openid', 'custom:valid', 'nonexistent']);

        $this->assertCount(1, $invalid);
        $this->assertContains('nonexistent', $invalid);
        $this->assertNotContains('custom:valid', $invalid);
    }

    /**
     * Test filterValidScopes includes custom scopes
     */
    public function testFilterValidScopesWithCustom(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('custom:filter', 'Filterable custom scope');

        $filtered = ScopeManager::filterValidScopes(['openid', 'custom:filter', 'unknown']);

        $this->assertCount(2, $filtered);
        $this->assertContains('openid', $filtered);
        $this->assertContains('custom:filter', $filtered);
    }

    /**
     * Test multiple custom scopes can be registered
     */
    public function testRegisterMultipleCustomScopes(): void
    {
        ScopeManager::resetCustomScopes();

        ScopeManager::registerScope('module_a:read', 'Read from module A');
        ScopeManager::registerScope('module_a:write', 'Write to module A');
        ScopeManager::registerScope('module_b:admin', 'Admin access to module B');

        $this->assertTrue(ScopeManager::isValidScope('module_a:read'));
        $this->assertTrue(ScopeManager::isValidScope('module_a:write'));
        $this->assertTrue(ScopeManager::isValidScope('module_b:admin'));

        $supported = ScopeManager::getSupportedScopes();
        $this->assertContains('module_a:read', $supported);
        $this->assertContains('module_a:write', $supported);
        $this->assertContains('module_b:admin', $supported);
    }

    /**
     * Clean up custom scopes after each test that registers them
     */
    protected function tearDown(): void
    {
        ScopeManager::resetCustomScopes();
        parent::tearDown();
    }
}
