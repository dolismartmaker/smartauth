<?php

/**
 * Unit tests for WebPushCrypto (aes128gcm encryption + VAPID).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\WebPushCrypto;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * @covers \SmartAuth\Api\WebPushCrypto
 */
class WebPushCryptoTest extends TestCase
{
    /** @var \ReflectionClass */
    private $ref;

    protected function setUp(): void
    {
        $this->ref = new \ReflectionClass(WebPushCrypto::class);
    }

    /**
     * @param string $name
     * @return \ReflectionMethod
     */
    private function method($name)
    {
        $m = $this->ref->getMethod($name);
        $m->setAccessible(true);
        return $m;
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64ud(string $s): string
    {
        $b = strtr($s, '-_', '+/');
        $pad = strlen($b) % 4;
        if ($pad) {
            $b .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b);
    }

    /**
     * Generate a recipient P-256 pair as VAPID-format base64url (public point,
     * private scalar), using the class's own robust EC key generation.
     *
     * @return array{public:string, private:string}
     */
    private function freshRecipient(): array
    {
        $key = $this->method('newEcKey')->invoke(null);
        $point = $this->method('publicPointFromKey')->invoke(null, $key);
        $details = openssl_pkey_get_details($key);
        $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
        return ['public' => self::b64u($point), 'private' => self::b64u($d)];
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $r = $this->freshRecipient();
        $auth = self::b64u(random_bytes(16));
        $plaintext = json_encode(['title' => 'Bonjour', 'body' => 'accentue: eee aaa']);

        $enc = WebPushCrypto::encryptPayload($plaintext, $r['public'], $auth);

        $this->assertArrayHasKey('body', $enc);
        $this->assertContains('Content-Encoding: aes128gcm', $enc['headers']);

        $decrypted = WebPushCrypto::decrypt($enc['body'], $r['private'], $r['public'], $auth);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptionMatchesRfc8291Vector(): void
    {
        // RFC 8291, Section 5 test vector (deterministic with injected key + salt).
        $asPriv = self::b64ud('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $asPub  = self::b64ud('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $uaPub  = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
        $auth   = 'BTBZMqHH6r4Tts7J_aSIgg';
        $salt   = self::b64ud('DGv6ra1nlYgDCS1FRnbzlw');
        $plaintext = 'When I grow up, I want to be a watermelon';

        $pem = $this->method('rawPrivateToPem')->invoke(null, $asPriv, $asPub);
        $enc = WebPushCrypto::encryptPayload($plaintext, $uaPub, $auth, ['local_key_pem' => $pem, 'salt' => $salt]);

        $expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml'
            . 'mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT'
            . 'pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

        $this->assertSame($expected, self::b64u($enc['body']));
    }

    public function testVapidAuthorizationIsValidEs256(): void
    {
        $r = $this->freshRecipient(); // reuse as a VAPID pair (same P-256 format)
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

        $header = WebPushCrypto::vapidAuthorization($endpoint, $r['public'], $r['private'], 'mailto:test@cap-rel.fr');

        $this->assertStringStartsWith('vapid t=', $header);
        $this->assertStringContainsString(', k='.$r['public'], $header);

        preg_match('/^vapid t=(.+), k=(.+)$/', $header, $m);
        $pemPub = $this->method('rawPublicToPem')->invoke(null, self::b64ud($r['public']));
        $decoded = JWT::decode($m[1], new Key($pemPub, 'ES256'));

        $this->assertSame('https://fcm.googleapis.com', $decoded->aud);
        $this->assertSame('mailto:test@cap-rel.fr', $decoded->sub);
        $this->assertGreaterThan(time(), $decoded->exp);
        $this->assertLessThanOrEqual(time() + 86400, $decoded->exp);
    }

    public function testInvalidSubscriptionKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        WebPushCrypto::encryptPayload('x', self::b64u('too-short'), self::b64u(random_bytes(16)));
    }
}
