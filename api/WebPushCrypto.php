<?php

/**
 * WebPushCrypto.php
 *
 * Self-contained Web Push implementation: VAPID authorization (RFC 8292) and
 * aes128gcm message encryption (RFC 8291 / RFC 8188). Replaces the
 * minishlink/web-push library, which dragged in guzzle and the abandoned
 * web-token/* + fgrosse/phpasn1 packages. This class depends only on:
 *   - ext-openssl (EC key generation, ECDH via openssl_pkey_derive, AES-128-GCM)
 *   - hash_hkdf() (PHP >= 7.1)
 *   - firebase/php-jwt (ES256 signing of the VAPID JWT, already bundled)
 * No new dependency, PHP 7.4 compatible.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api;

use Firebase\JWT\JWT;

class WebPushCrypto
{
    /** Record size advertised in the aes128gcm header. */
    const RECORD_SIZE = 4096;

    /** DER prefix (26 bytes) of a P-256 SubjectPublicKeyInfo, before the 65-byte point. */
    const SPKI_P256_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Encrypt a Web Push payload with the aes128gcm content encoding.
     *
     * @param string $plaintext  Raw payload (typically a JSON string)
     * @param string $p256dh     Subscription public key, base64url (uncompressed P-256 point, 65 bytes)
     * @param string $auth       Subscription auth secret, base64url (16 bytes)
     * @param array  $opts       Test hooks only: ['local_key_pem'=>PEM, 'salt'=>16 raw bytes]
     * @return array{body:string, headers:array<int,string>} Binary body + HTTP headers
     */
    public static function encryptPayload(string $plaintext, string $p256dh, string $auth, array $opts = []): array
    {
        $uaPublic = self::b64uDecode($p256dh);          // receiver public point (65 bytes)
        $authSecret = self::b64uDecode($auth);          // 16 bytes
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \RuntimeException('Invalid subscription p256dh key');
        }
        if (strlen($authSecret) !== 16) {
            throw new \RuntimeException('Invalid subscription auth secret');
        }

        // Sender (application server) ephemeral key pair.
        if (!empty($opts['local_key_pem'])) {
            $asKey = openssl_pkey_get_private($opts['local_key_pem']);
            if ($asKey === false) {
                throw new \RuntimeException('Invalid injected local key');
            }
        } else {
            $asKey = self::newEcKey();
        }
        $asPublic = self::publicPointFromKey($asKey);   // 65 bytes

        // ECDH shared secret (P-256 -> 32 bytes).
        $sharedSecret = openssl_pkey_derive(self::rawPublicToPem($uaPublic), $asKey, 32);
        if ($sharedSecret === false || strlen($sharedSecret) === 0) {
            throw new \RuntimeException('ECDH derivation failed');
        }

        $salt = !empty($opts['salt']) ? $opts['salt'] : random_bytes(16);
        if (strlen($salt) !== 16) {
            throw new \RuntimeException('Salt must be 16 bytes');
        }

        // RFC 8291 3.4: PRK (IKM) = HKDF(salt=auth, ikm=ecdh, info="WebPush: info\0"||ua||as, 32)
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

        // RFC 8188: CEK and NONCE from the message salt.
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // Single record: plaintext + 0x02 delimiter (last record, no extra padding).
        $record = $plaintext . "\x02";
        if (strlen($record) + 16 > self::RECORD_SIZE) {
            throw new \RuntimeException('Web Push payload too large for one record');
        }

        $tag = '';
        $cipher = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('AES-128-GCM encryption failed');
        }

        // RFC 8188 header: salt(16) | rs(4) | idlen(1) | keyid(as_public, 65)
        $header = $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPublic;
        $body = $header . $cipher . $tag;

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
        ];

        return ['body' => $body, 'headers' => $headers];
    }

    /**
     * Build the VAPID Authorization header value (RFC 8292, vapid scheme).
     *
     * @param string $endpoint   Push service endpoint URL
     * @param string $publicKey  VAPID public key, base64url (65-byte point)
     * @param string $privateKey VAPID private key, base64url (32-byte scalar d)
     * @param string $subject    mailto: or https URL identifying the sender
     * @return string Header value: "vapid t=<jwt>, k=<publicKey>"
     */
    public static function vapidAuthorization(string $endpoint, string $publicKey, string $privateKey, string $subject): string
    {
        self::ensureJwtLoaded();

        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid push endpoint URL');
        }
        $audience = $parts['scheme'] . '://' . $parts['host'];

        $payload = [
            'aud' => $audience,
            'exp' => time() + 43200, // 12h, must be < 24h
            'sub' => $subject,
        ];

        $pem = self::rawPrivateToPem(self::b64uDecode($privateKey), self::b64uDecode($publicKey));
        $jwt = JWT::encode($payload, $pem, 'ES256');

        return 'vapid t=' . $jwt . ', k=' . $publicKey;
    }

    /**
     * Decrypt an aes128gcm Web Push body. Primarily for tests and diagnostics:
     * the receiver (browser) normally performs this. Given the recipient's
     * private scalar, it recovers the plaintext, proving encryptPayload() is
     * interoperable.
     *
     * @param string $body              Full aes128gcm message body
     * @param string $recipientPrivate  Recipient private key, base64url (32-byte d)
     * @param string $recipientPublic   Recipient public key, base64url (65-byte point)
     * @param string $auth              Auth secret, base64url (16 bytes)
     * @return string Decrypted plaintext
     */
    public static function decrypt(string $body, string $recipientPrivate, string $recipientPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $payload = substr($body, 21 + $idlen);
        $cipher = substr($payload, 0, -16);
        $tag = substr($payload, -16);

        $uaPublic = self::b64uDecode($recipientPublic);
        $authSecret = self::b64uDecode($auth);
        $uaKey = openssl_pkey_get_private(self::rawPrivateToPem(self::b64uDecode($recipientPrivate), $uaPublic));
        if ($uaKey === false) {
            throw new \RuntimeException('Invalid recipient key');
        }

        $sharedSecret = openssl_pkey_derive(self::rawPublicToPem($asPublic), $uaKey, 32);
        if ($sharedSecret === false) {
            throw new \RuntimeException('ECDH derivation failed (decrypt)');
        }

        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $record = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($record === false) {
            throw new \RuntimeException('AES-128-GCM decryption failed');
        }

        // Strip the RFC 8188 padding delimiter (0x02) and any trailing 0x00.
        $record = rtrim($record, "\x00");
        if (substr($record, -1) === "\x02") {
            $record = substr($record, 0, -1);
        }
        return $record;
    }

    /**
     * Generate a fresh P-256 key pair, retrying with a minimal OpenSSL config if
     * the host openssl.cnf is broken/templated (same robustness as VapidKeyHelper).
     *
     * @return \OpenSSLAsymmetricKey|resource
     */
    private static function newEcKey()
    {
        $params = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($params);
        if ($key !== false) {
            return $key;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'satwpc_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary OpenSSL config');
        }
        file_put_contents($tmp, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");
        try {
            $params['config'] = $tmp;
            $key = openssl_pkey_new($params);
            if ($key === false) {
                throw new \RuntimeException('openssl_pkey_new failed for EC P-256');
            }
            // Drain queued OpenSSL errors so they do not surface elsewhere.
            while (openssl_error_string() !== false) {
                // no-op
            }
            return $key;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Extract the uncompressed public point (65 bytes: 0x04 || X || Y) from a key.
     *
     * @param \OpenSSLAsymmetricKey|resource $key
     * @return string
     */
    private static function publicPointFromKey($key): string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new \RuntimeException('Unable to read EC public point');
        }
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        return "\x04" . $x . $y;
    }

    /**
     * Wrap a raw P-256 public point (65 bytes) into a PEM SubjectPublicKeyInfo.
     *
     * @param string $point 0x04 || X(32) || Y(32)
     * @return string PEM
     */
    private static function rawPublicToPem(string $point): string
    {
        $der = hex2bin(self::SPKI_P256_PREFIX_HEX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Build a PEM SEC1 "EC PRIVATE KEY" from a raw scalar d and the public point.
     *
     * @param string $d     32-byte private scalar
     * @param string $point 65-byte uncompressed public point (0x04 || X || Y)
     * @return string PEM
     */
    private static function rawPrivateToPem(string $d, string $point): string
    {
        $d = str_pad($d, 32, "\x00", STR_PAD_LEFT);
        if (strlen($point) !== 65) {
            throw new \RuntimeException('Invalid public point for EC private key');
        }
        // SEQUENCE(0x77) { version(1), privateKey OCTET STRING(d, 32),
        //   [0] params = OID prime256v1, [1] publicKey BIT STRING(0x00 || point) }
        $der = hex2bin('30770201010420') . $d
            . hex2bin('a00a06082a8648ce3d030107')
            . hex2bin('a1440342') . "\x00" . $point;

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * @param string $data base64url (no padding)
     * @return string raw bytes
     */
    private static function b64uDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url input');
        }
        return $decoded;
    }

    /**
     * Ensure firebase/php-jwt is autoloadable in contexts that do not boot the
     * SmartAuth API front controller (cron, triggers, admin pages).
     *
     * @return void
     */
    private static function ensureJwtLoaded(): void
    {
        if (class_exists('Firebase\\JWT\\JWT')) {
            return;
        }
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
