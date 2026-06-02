<?php

/**
 * Regression tests for the 22 MEDIUM findings of docs/TODO-SECURITY-01.md.
 *
 * Each test pins the post-fix invariant in place: a future regression
 * (revert / refactor that drops a guard) makes the corresponding
 * assertion fail.
 *
 * Findings covered:
 *   - M-1: RateLimiter atomic enforceLimit() + transaction
 *   - M-2: PasswordReset token stored hashed (sha256)
 *   - M-3: PasswordReset invalidates existing JWT/OAuth2 tokens
 *   - M-4: AuthController::logout reads jwt_family_id (not family_id)
 *   - M-5: SessionManager prefers __Host- cookie + scoped X-Forwarded-Proto trust
 *   - M-6: RouteController::parseRequestData no longer logs raw POST body
 *   - M-7: LogoutController SQL no longer references missing column
 *   - M-8: account.tpl sanitises hook-provided HTML
 *   - M-9: sanitizeContinueUrl rejects /\evil.com
 *   - M-10: emailAlreadyKnown filters by entity
 *   - M-11: getClientByUUID checks device ownership
 *   - M-12: TokenController has rate-limit on /oauth/token
 *   - M-13: authorization code is markAsUsed on PKCE failure
 *   - M-14: state parameter is mandatory and length-limited
 *   - M-15: JwtKeyHelper supports multi-kid (archive + getRsaPublicKeyByKid)
 *   - M-16: bundle ZIP uses a private temp dir + try/finally cleanup
 *   - M-17: revokeAllForUser scoped by entity
 *   - M-18: runClaimsHook restores reserved claims
 *   - M-19: PasswordReset rate-limited per IP too
 *   - M-20: RateLimiter masks identifiers in logs (PII)
 *   - M-21: redirect_uri rejects javascript:/data:/file: schemes
 *   - M-22: SmartUpload has a hard MIME denylist
 *
 * @group security-medium
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Security;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;

dol_include_once('/smartauth/api/RouteController.php');
dol_include_once('/smartauth/api/AuthController.php');
dol_include_once('/smartauth/api/RateLimiter.php');
dol_include_once('/smartauth/api/SmartUpload.php');
dol_include_once('/smartauth/api/PasswordResetController.php');
dol_include_once('/smartauth/api/SyncController.php');
dol_include_once('/smartauth/api/Account/RegistrationService.php');
dol_include_once('/smartauth/api/OAuth2/HookHelper.php');
dol_include_once('/smartauth/class/smartauthoauthtoken.class.php');

class MediumFindingsPoCTest extends DolibarrRealTestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(dirname(__DIR__, 4) . '/' . $relative);
    }

    private function functionBody(string $source, string $name): string
    {
        $needle = 'function ' . $name . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        $i = $brace;
        $len = strlen($source);
        while ($i < $len) {
            $c = $source[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
            $i++;
        }
        return '';
    }

    public function testM1_RateLimiterEnforceLimitIsAtomic(): void
    {
        $src = $this->source('api/RateLimiter.php');
        $body = $this->functionBody($src, 'enforceLimit');
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('$this->db->begin()', $body);
        $this->assertStringContainsString('$this->db->commit()', $body);
        $this->assertStringContainsString('$this->db->rollback()', $body);
        $this->assertStringContainsString('INSERT INTO', $body);
    }

    public function testM2_PasswordResetTokenIsHashed(): void
    {
        // The reset token is now stored (hashed) in llx_smartauth_email_validation
        // via EmailValidationToken, which keeps sha256(plain) and never the plain
        // value. The controller looks it up by hash, not by a plaintext compare.
        $src = $this->source('api/PasswordResetController.php');
        $this->assertStringContainsString('EmailValidationToken::hashToken', $src);
        $this->assertStringContainsString('PURPOSE_PASSWORD_RESET', $src);
        // No plaintext token comparison must survive.
        $this->assertStringNotContainsString('$obj->pass_temp !== $token', $src);

        $tokenSrc = $this->source('api/Account/EmailValidationToken.php');
        $this->assertStringContainsString("hash('sha256', \$plain)", $tokenSrc);
    }

    public function testM3_PasswordResetRevokesExistingTokens(): void
    {
        $src = $this->source('api/PasswordResetController.php');
        $body = $this->functionBody($src, 'revokeAllSubjectTokens');
        $this->assertNotEmpty($body, 'M-3: revokeAllSubjectTokens helper must exist');
        $this->assertStringContainsString('smartauth_auth', $body);
        // Subject-aware revocation (account/member carry fk_user = 0).
        $this->assertStringContainsString('SmartAuthOAuthToken::revokeAllForSubject', $body);
    }

    public function testM4_LogoutReadsJwtFamilyId(): void
    {
        $src = $this->source('api/AuthController.php');
        $body = $this->functionBody($src, 'logout');
        $this->assertStringContainsString("\$payload['jwt_family_id']", $body);
        $this->assertStringContainsString('_revokeTokenFamily', $body);
    }

    public function testM5_SessionManagerPrefersHostPrefixedCookie(): void
    {
        $src = $this->source('api/OAuth2/SessionManager.php');
        $this->assertStringContainsString("__Host-smartauth_session", $src);
        $this->assertStringContainsString('resolveCookieName', $src);
        // X-Forwarded-Proto requires a private REMOTE_ADDR or trusted-proxy entry
        $secureBody = $this->functionBody($src, 'isSecureContext');
        $this->assertStringContainsString('SMARTAUTH_TRUSTED_PROXIES', $secureBody);
    }

    public function testM6_RouteControllerNoLongerLogsRawPostBody(): void
    {
        $src = $this->source('api/RouteController.php');
        $body = $this->functionBody($src, 'parseRequestData');
        $this->assertNotEmpty($body);
        $this->assertStringNotContainsString('substr($raw, 0, 500)', $body);
        $this->assertStringContainsString('raw_length=', $body);
    }

    public function testM7_LogoutControllerSqlNoLongerReferencesMissingColumn(): void
    {
        $src = $this->source('api/OAuth2/LogoutController.php');
        $body = $this->functionBody($src, 'isUriRegisteredForAnyClient');
        $this->assertStringNotContainsString(
            'SELECT rowid, redirect_uris, post_logout_redirect_uris',
            $body,
            'M-7: SELECT must not reference the missing post_logout_redirect_uris column'
        );
    }

    public function testM8_AccountTemplateSanitisesHookHtml(): void
    {
        $src = $this->source('tpl/account.tpl.php');
        $this->assertStringContainsString('sanitiseSectionHtml', $src);
        $this->assertStringNotContainsString('<?= $section[\'html\'] ?>', $src);
        $this->assertStringContainsString('<script', $src);
        $this->assertStringContainsString('javascript', $src);
    }

    public function testM9_SanitizeContinueUrlRejectsBackslashOrigin(): void
    {
        $src = $this->source('api/OAuth2/LoginController.php');
        $body = $this->functionBody($src, 'sanitizeContinueUrl');
        $this->assertStringContainsString("\\\\", $body);
        $this->assertStringContainsString("'/'", $body);
    }

    public function testM10_EmailAlreadyKnownFiltersByEntity(): void
    {
        $src = $this->source('api/Account/RegistrationService.php');
        $body = $this->functionBody($src, 'emailAlreadyKnown');
        $this->assertStringContainsString("getEntity('user')", $body);
        $this->assertStringContainsString("getEntity('socpeople')", $body);
    }

    public function testM11_GetClientByUUIDChecksDeviceOwnership(): void
    {
        $src = $this->source('api/SyncController.php');
        $body = $this->functionBody($src, 'getClientByUUID');
        $this->assertStringContainsString('smartauth_devices', $body);
        $this->assertStringContainsString('sd.fk_user', $body);
        $this->assertStringContainsString('$userId', $body);
    }

    public function testM12_TokenEndpointIsRateLimited(): void
    {
        $src = $this->source('api/OAuth2/TokenController.php');
        $body = $this->functionBody($src, 'handleToken');
        $this->assertStringContainsString('RateLimiter', $body);
        $this->assertStringContainsString("'oauth_token'", $body);
        $this->assertStringContainsString('temporarily_unavailable', $body);
    }

    public function testM13_AuthCodeBurnedOnPkceFailure(): void
    {
        $src = $this->source('api/OAuth2/TokenController.php');
        $body = $this->functionBody($src, 'handleAuthorizationCode');
        // Each error branch in the PKCE block must consume the code
        $this->assertStringContainsString('Code verifier does not match', $body);
        $pos = strpos($body, 'Code verifier does not match');
        $this->assertNotFalse($pos);
        $beforeFailure = substr($body, 0, $pos);
        $this->assertStringContainsString('$authCode->markAsUsed()', $beforeFailure);
    }

    public function testM14_StateParameterIsMandatory(): void
    {
        $src = $this->source('api/OAuth2/AuthorizationController.php');
        $body = $this->functionBody($src, 'processAuthorizationRequest');
        $this->assertNotEmpty($body, 'processAuthorizationRequest() must exist');
        $this->assertMatchesRegularExpression('/strlen\(\s*\$state\s*\)\s*>\s*512/', $body);
        $this->assertStringContainsString("Parametre state requis", $body);
    }

    public function testM15_MultiKidSupport(): void
    {
        $src = $this->source('api/JwtKeyHelper.php');
        $this->assertStringContainsString('getRsaPublicKeyByKid', $src);
        $this->assertStringContainsString('archive', $src);
        $rotateBody = $this->functionBody($src, 'rotateRsaKeyPair');
        $this->assertStringContainsString('archived RSA public key', $rotateBody);

        // TokenService::decodeJwt now resolves the key by kid
        $tokenSrc = $this->source('api/OAuth2/TokenService.php');
        $decodeBody = $this->functionBody($tokenSrc, 'decodeJwt');
        $this->assertStringContainsString('getRsaPublicKeyByKid', $decodeBody);
    }

    public function testM16_BundleZipUsesPrivateTempDirAndTryFinally(): void
    {
        $src = $this->source('api/ObjectDocumentController.php');
        $body = $this->functionBody($src, 'bundle');
        $this->assertStringContainsString("0700", $body);
        $this->assertStringContainsString('catch (\\Throwable', $body);
        $this->assertStringNotContainsString("tempnam(sys_get_temp_dir(), 'smartauth_bundle_')", $body);
    }

    public function testM17_RevokeAllForUserScopedByEntity(): void
    {
        $src = $this->source('class/smartauthoauthtoken.class.php');
        $body = $this->functionBody($src, 'revokeAllForUser');
        $this->assertStringContainsString("getEntity('smartauthoauthtoken')", $body);
    }

    public function testM18_HookCannotOverwriteReservedClaims(): void
    {
        $src = $this->source('api/OAuth2/HookHelper.php');
        $body = $this->functionBody($src, 'runClaimsHook');
        $this->assertStringContainsString('RESERVED_CLAIMS', $body);
        // The constant lists the relevant claims
        $this->assertMatchesRegularExpression("/RESERVED_CLAIMS\s*=\s*\[[^\]]*'iss'/s", $src);
        $this->assertMatchesRegularExpression("/RESERVED_CLAIMS\s*=\s*\[[^\]]*'sub'/s", $src);
        $this->assertMatchesRegularExpression("/RESERVED_CLAIMS\s*=\s*\[[^\]]*'aud'/s", $src);
        $this->assertMatchesRegularExpression("/RESERVED_CLAIMS\s*=\s*\[[^\]]*'exp'/s", $src);
    }

    public function testM19_PasswordResetHasIpRateLimit(): void
    {
        $src = $this->source('api/PasswordResetController.php');
        $body = $this->functionBody($src, 'requestReset');
        $this->assertStringContainsString("'password_reset_ip'", $body);
        $this->assertStringContainsString('RouteController::get_client_ip', $body);
    }

    public function testM20_RateLimiterMasksIdentifierInLogs(): void
    {
        $this->assertSame('a****@example.com', \SmartAuth\Api\RateLimiter::maskIdentifier('alice@example.com'));
        // 10-char input -> first 2 + 6 stars + last 2
        $this->assertSame('al******ce', \SmartAuth\Api\RateLimiter::maskIdentifier('alice123ce'));
        $this->assertSame('****', \SmartAuth\Api\RateLimiter::maskIdentifier('1234'));
        $this->assertSame('(empty)', \SmartAuth\Api\RateLimiter::maskIdentifier(''));

        $src = $this->source('api/RateLimiter.php');
        $checkBody = $this->functionBody($src, 'checkLimit');
        $this->assertStringContainsString('maskIdentifier', $checkBody);
    }

    public function testM21_RedirectUriRejectsDangerousSchemes(): void
    {
        $src = $this->source('api/OAuth2/AuthorizationController.php');
        $body = $this->functionBody($src, 'validateRedirectUri');
        $this->assertStringContainsString("'javascript'", $body);
        $this->assertStringContainsString("'data'", $body);
        $this->assertStringContainsString("'file'", $body);
        $this->assertStringContainsString("'gopher'", $body);
        // Loopback HTTP exception is now opt-in
        $this->assertStringContainsString('SMARTAUTH_OAUTH_ALLOW_LOOPBACK_HTTP', $body);
    }

    public function testM22_SmartUploadHasHardMimeDenylist(): void
    {
        $src = $this->source('api/SmartUpload.php');
        $this->assertStringContainsString('HARD_DENY_MIME', $src);

        // Hot-button entries the audit named explicitly
        $this->assertMatchesRegularExpression(
            "/HARD_DENY_MIME\s*=\s*\[[^\]]*'image\/svg\+xml'/s",
            $src
        );
        $this->assertMatchesRegularExpression(
            "/HARD_DENY_MIME\s*=\s*\[[^\]]*'text\/html'/s",
            $src
        );
        $this->assertMatchesRegularExpression(
            "/HARD_DENY_MIME\s*=\s*\[[^\]]*'application\/x-php'/s",
            $src
        );

        // validate() actually consults the denylist
        $valBody = $this->functionBody($src, 'validate');
        $this->assertStringContainsString('HARD_DENY_MIME', $valBody);
    }
}
