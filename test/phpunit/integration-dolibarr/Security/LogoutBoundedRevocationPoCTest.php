<?php

namespace SmartAuth\Tests\IntegrationDolibarr\Security;

use SmartAuth\Api\JwtKeyHelper;
use SmartAuth\Api\OAuth2\LogoutController;
use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;

/**
 * PoC regressions for the RP-initiated logout hardening:
 *
 *   - H-A (audit S-2): any RP holding one old - even expired - signed
 *     id_token could revoke EVERY token of the subject at EVERY client,
 *     forever repeatable. Revocation is now bounded to the hinting
 *     client (aud) and subject-aware.
 *   - H-B (audit S-3): the same bulk revocation was reachable with a bare
 *     GET (no CSRF, no confirmation). It now requires a POST confirmed
 *     by the one-shot session CSRF token.
 *
 * Source-shape assertions follow the house pattern of the other PoC files
 * (CriticalFindingsPoCTest / MediumFindingsPoCTest).
 *
 * @covers \SmartAuth\Api\OAuth2\LogoutController
 */
class LogoutBoundedRevocationPoCTest extends DolibarrRealTestCase
{
    /** @var ?array RSA key pair generated once per process (openssl CLI). */
    private static ?array $rsaKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Stable issuer so the signed hints match OAuthConfig::getIssuer().
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';

        $this->seedRsaKeys();
    }

    /**
     * Generate (once) and inject an RSA key pair into the Dolibarr config
     * cache so JwtKeyHelper picks it up and never falls back to
     * openssl_pkey_new() (broken on this host, see CriticalFindingsPoCTest).
     */
    private function seedRsaKeys(): void
    {
        global $conf;

        if (self::$rsaKeys === null) {
            $tmp = tempnam(sys_get_temp_dir(), 'smartauth_rsa_');
            $cmd = 'openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($tmp) . ' 2>/dev/null';
            exec($cmd, $out, $rc);
            if ($rc !== 0 || !is_file($tmp)) {
                @unlink($tmp);
                $this->markTestSkipped('openssl CLI not available, cannot pre-seed RSA keys');
            }
            $priv = file_get_contents($tmp);
            @unlink($tmp);

            $resource = openssl_pkey_get_private($priv);
            $details = openssl_pkey_get_details($resource);
            $pub = $details['key'];

            self::$rsaKeys = [
                'private' => $priv,
                'public' => $pub,
                'kid' => 'smartauth-test-' . substr(hash('sha256', $pub), 0, 8),
            ];
        }

        $conf->global->SMARTAUTH_OAUTH_RSA_PRIVATE_KEY = self::$rsaKeys['private'];
        $conf->global->SMARTAUTH_OAUTH_RSA_PUBLIC_KEY = self::$rsaKeys['public'];
        $conf->global->SMARTAUTH_OAUTH_RSA_KID = self::$rsaKeys['kid'];
    }

    private function source(): string
    {
        // Security/ -> integration-dolibarr -> phpunit -> test -> project root
        return (string) file_get_contents(dirname(__DIR__, 4) . '/api/OAuth2/LogoutController.php');
    }

    private function invokePrivate($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    /**
     * Sign a JWT with the RSA key JwtKeyHelper will actually verify against
     * (file-based pair when one exists in the data root, the conf-seeded
     * pair otherwise) - the way real id_tokens are signed.
     */
    private function signJwtWithIdpKey(array $payload): string
    {
        $privateKey = JwtKeyHelper::getRsaPrivateKey();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => JwtKeyHelper::getRsaKeyId()];
        $h = JwtKeyHelper::base64UrlEncode(json_encode($header));
        $p = JwtKeyHelper::base64UrlEncode(json_encode($payload));
        $input = $h . '.' . $p;

        openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $input . '.' . JwtKeyHelper::base64UrlEncode($signature);
    }

    /**
     * H-A: the hint path must revoke through the subject-and-client-scoped
     * helper, and the unscoped revokeAllForUser must be gone entirely.
     */
    public function testHintRevocationIsBoundedToHintingClient(): void
    {
        $src = $this->source();

        $this->assertStringContainsString(
            'revokeAllForSubjectAndClient',
            $src,
            'H-A fix: RP-initiated logout must revoke only the hinting client tokens'
        );
        $this->assertStringNotContainsString(
            'revokeAllForUser',
            $src,
            'H-A fix: logout must never bulk-revoke every client of the subject'
        );
    }

    /**
     * H-B: bulk revocation of the session subject requires the confirmed
     * POST guard to be evaluated before it.
     */
    public function testFullRevocationRequiresConfirmedPost(): void
    {
        $src = $this->source();

        $guardPos = strpos($src, 'isConfirmedLogoutPost()');
        $revokePos = strpos($src, 'revokeAllForSubject(');

        $this->assertNotFalse($guardPos, 'H-B fix: bulk revocation must sit behind isConfirmedLogoutPost()');
        $this->assertNotFalse($revokePos, 'H-B fix: revokeAllForSubject call expected');
        $this->assertLessThan(
            $revokePos,
            $guardPos,
            'H-B fix: the CSRF-confirmed POST guard must be evaluated before any bulk revocation'
        );

        $this->assertStringContainsString('hash_equals', $src, 'CSRF comparison must be constant-time');
    }

    /**
     * A hint whose subject contradicts the caller's session must trigger NO
     * action at all: the guard exists inside handleLogout.
     */
    public function testHintSessionMismatchGuardExists(): void
    {
        $src = $this->source();

        $this->assertNotFalse(
            strpos($src, 'does not match session subject'),
            'mismatch guard expected in handleLogout'
        );
    }

    /**
     * decodeIdTokenHint now exposes the full TokenSubject (so external
     * acc:/mbr: subjects get bounded revocation too) while keeping the
     * legacy userId key user-only.
     */
    public function testDecodeIdTokenHintReturnsSubject(): void
    {
        $victim = $this->createTestUser(['login' => 'hint_subj_' . uniqid()]);

        $logout = new LogoutController($this->db);

        $signed = $this->signJwtWithIdpKey([
            'iss' => OAuthConfig::getIssuer(),
            'sub' => 'usr:' . $victim->id,
            'aud' => 'rp-client-arbitrary',
            'exp' => time() + 3600,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $info = $this->invokePrivate($logout, 'decodeIdTokenHint', [$signed]);

        $this->assertNotNull($info['subject']);
        $this->assertSame('user', $info['subject']->getType());
        $this->assertSame((int) $victim->id, (int) $info['subject']->getId());
        $this->assertSame('rp-client-arbitrary', $info['clientId']);
        $this->assertSame((int) $victim->id, (int) $info['userId'], 'legacy userId key must stay populated for user subjects');
    }
}
