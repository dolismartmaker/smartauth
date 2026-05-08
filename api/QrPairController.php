<?php

/**
 * QrPairController.php
 *
 * HTTP entry points for the cross-device QR pairing flow.
 *
 * The PC side (a Dolibarr-authenticated browser session) is handled by
 * /custom/smartauth/user/qrpair.php and never goes through this controller.
 * Only the mobile side - which is unauthenticated until tokens are issued
 * - hits these public endpoints:
 *
 *   POST /qr-pair/{pairing_id}/claim
 *     Body : { device_label?, device_uuid? }
 *     200  : { claim_token, status: 'claimed' }
 *     The mobile keeps claim_token in memory (never persisted) and uses it
 *     in subsequent /poll calls.
 *
 *   POST /qr-pair/{pairing_id}/poll
 *     Body : { claim_token }
 *     200  : one of
 *       { status: 'pending' }                     -> keep polling
 *       { status: 'cancelled' | 'expired' }       -> stop
 *       { status: 'consumed', access_token, refresh_token, expires_in,
 *         device_uuid }                           -> stop, log the user in
 *
 * The first /poll call that observes status=confirmed triggers token
 * issuance (via AuthController::generateTokenForAuthenticatedUser) and
 * marks the row as consumed atomically. Subsequent polls return 'consumed'
 * with no token (single-use enforcement).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

dol_include_once('/smartauth/class/smartauthqrpairing.class.php');

use SmartAuthQrPairing;
use SmartAuth\Api\AuthController;
use SmartAuth\Api\InputSanitizer;
use SmartAuth\Api\RateLimiter;

class QrPairController
{
    private const RATE_ACTION_CLAIM = 'qr_pair_claim';
    private const RATE_ACTION_POLL = 'qr_pair_poll';
    private const RATE_WINDOW_SECONDS = 60;
    private const RATE_CLAIM_MAX = 30;
    private const RATE_POLL_MAX = 240;

    /**
     * @var \DoliDB|null
     */
    private $injectedDb;

    /**
     * @var SmartAuthQrPairing|null
     */
    private $injectedRepo;

    /**
     * @var AuthController|null
     */
    private $injectedAuth;

    public function __construct(
        $db = null,
        ?SmartAuthQrPairing $repo = null,
        ?AuthController $auth = null
    ) {
        $this->injectedDb = $db;
        $this->injectedRepo = $repo;
        $this->injectedAuth = $auth;
    }

    /**
     * @api {post} /qr-pair/{pairing_id}/claim Mobile claims a QR pairing
     * @apiName ClaimQrPair
     * @apiGroup QrPair
     * @apiVersion 1.0.0
     *
     * @apiParam {String} pairing_id 32-hex pairing id from the QR code
     * @apiParam {String} [device_label] Mobile-supplied label (e.g. "iPhone Eric")
     * @apiParam {String} [device_uuid]  Stable mobile device identifier (UUID)
     *
     * @apiSuccess {String} claim_token Plain claim token (mobile must keep it)
     * @apiSuccess {String} status      Always 'claimed' on success
     */
    public function claim($payload)
    {
        $db = $this->resolveDb();
        $repo = $this->resolveRepo($db);

        $pairingId = $this->normalisePairingId($payload['pairing_id'] ?? '');
        if ($pairingId === null) {
            return [['error' => 'invalid_pairing_id'], 400];
        }

        $clientIp = RouteController::get_client_ip();

        $rateLimiter = new RateLimiter($db);
        $rateCheck = $rateLimiter->checkLimit(
            $clientIp,
            self::RATE_ACTION_CLAIM,
            self::RATE_CLAIM_MAX,
            self::RATE_WINDOW_SECONDS
        );
        if (empty($rateCheck['allowed'])) {
            // Rate-limit hits are not counted (avoids self-amplification).
            return [['error' => 'rate_limited', 'retry_after' => (int) ($rateCheck['retry_after'] ?? 60)], 429];
        }

        $row = $repo->findByPairingId($pairingId, $this->currentEntity());
        if ($row === null) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_CLAIM, [['error' => 'pairing_not_found'], 404]);
        }
        if ($row['status'] !== SmartAuthQrPairing::STATUS_PENDING) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_CLAIM, [['error' => 'pairing_not_claimable', 'status' => $row['status']], 409]);
        }
        if (SmartAuthQrPairing::isExpired($row)) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_CLAIM, [['error' => 'pairing_expired'], 410]);
        }

        $deviceLabelRaw = isset($payload['device_label']) ? (string) $payload['device_label'] : '';
        $deviceLabel = $deviceLabelRaw !== '' ? InputSanitizer::sanitizeString($deviceLabelRaw, 128) : null;

        $deviceUuidHash = null;
        if (!empty($payload['device_uuid'])) {
            $deviceUuid = InputSanitizer::sanitizeUUID($payload['device_uuid']);
            if ($deviceUuid !== null) {
                $deviceUuidHash = hash('sha256', $deviceUuid);
            }
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $claimIp = InputSanitizer::sanitizeIP($clientIp);

        $plainClaimToken = SmartAuthQrPairing::generateClaimToken();
        $claimTokenHash = SmartAuthQrPairing::hashClaimToken($plainClaimToken);

        $ok = $repo->markClaimed(
            $row['rowid'],
            $claimTokenHash,
            $deviceLabel,
            $deviceUuidHash,
            $claimIp,
            $userAgent
        );
        if (!$ok) {
            // The row changed status under our feet (race) - re-fetch and respond.
            $fresh = $repo->findByPairingId($pairingId, $this->currentEntity());
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_CLAIM, [['error' => 'pairing_not_claimable', 'status' => $fresh['status'] ?? 'unknown'], 409]);
        }

        dol_syslog('SmartAuth QrPairController::claim - pairing ' . $pairingId . ' claimed by ip=' . (string) $claimIp, LOG_INFO);

        return $this->recordAndReturn(
            $rateLimiter,
            $clientIp,
            self::RATE_ACTION_CLAIM,
            [
                [
                    'status' => SmartAuthQrPairing::STATUS_CLAIMED,
                    'claim_token' => $plainClaimToken,
                ],
                200,
            ]
        );
    }

    /**
     * @api {post} /qr-pair/{pairing_id}/poll Mobile polls a claimed pairing
     * @apiName PollQrPair
     * @apiGroup QrPair
     * @apiVersion 1.0.0
     *
     * @apiParam {String} pairing_id 32-hex pairing id from the QR code
     * @apiParam {String} claim_token The token returned by /claim
     *
     * @apiSuccess {String} status        'pending' | 'cancelled' | 'expired' | 'consumed'
     * @apiSuccess {String} [access_token] Present when status='consumed'
     * @apiSuccess {String} [refresh_token] Present when status='consumed'
     * @apiSuccess {Number} [expires_in]   Present when status='consumed'
     */
    public function poll($payload)
    {
        $db = $this->resolveDb();
        $repo = $this->resolveRepo($db);

        $pairingId = $this->normalisePairingId($payload['pairing_id'] ?? '');
        if ($pairingId === null) {
            return [['error' => 'invalid_pairing_id'], 400];
        }

        $providedToken = isset($payload['claim_token']) ? (string) $payload['claim_token'] : '';
        if ($providedToken === '' || strlen($providedToken) > 128) {
            return [['error' => 'invalid_claim_token'], 400];
        }

        $clientIp = RouteController::get_client_ip();

        $rateLimiter = new RateLimiter($db);
        $rateCheck = $rateLimiter->checkLimit(
            $clientIp,
            self::RATE_ACTION_POLL,
            self::RATE_POLL_MAX,
            self::RATE_WINDOW_SECONDS
        );
        if (empty($rateCheck['allowed'])) {
            return [['error' => 'rate_limited', 'retry_after' => (int) ($rateCheck['retry_after'] ?? 60)], 429];
        }

        $row = $repo->findByPairingId($pairingId, $this->currentEntity());
        if ($row === null) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['error' => 'pairing_not_found'], 404]);
        }

        // The row must already have been claimed by this same mobile
        // (claim_token_hash set). A correct claim_token is mandatory before
        // we leak any status information that could help an attacker enumerate
        // pairings or race against the legitimate mobile.
        $expectedHash = $row['claim_token_hash'] ?? '';
        if (!is_string($expectedHash) || $expectedHash === '') {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['error' => 'pairing_not_claimed'], 409]);
        }
        if (!hash_equals($expectedHash, SmartAuthQrPairing::hashClaimToken($providedToken))) {
            dol_syslog('SmartAuth QrPairController::poll - claim_token mismatch for pairing ' . $pairingId, LOG_WARNING);
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['error' => 'invalid_claim_token'], 403]);
        }

        // Terminal states
        if ($row['status'] === SmartAuthQrPairing::STATUS_CANCELLED) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['status' => SmartAuthQrPairing::STATUS_CANCELLED], 200]);
        }
        if ($row['status'] === SmartAuthQrPairing::STATUS_CONSUMED) {
            // Single-use: tokens have already been delivered.
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['status' => SmartAuthQrPairing::STATUS_CONSUMED], 200]);
        }
        if (SmartAuthQrPairing::isExpired($row)) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['status' => SmartAuthQrPairing::STATUS_EXPIRED], 200]);
        }

        if ($row['status'] === SmartAuthQrPairing::STATUS_CLAIMED) {
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['status' => SmartAuthQrPairing::STATUS_PENDING], 200]);
        }

        if ($row['status'] !== SmartAuthQrPairing::STATUS_CONFIRMED) {
            // pending without claim_token_hash should be unreachable here.
            return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, [['status' => $row['status']], 200]);
        }

        // Status == confirmed: issue tokens and mark consumed atomically.
        return $this->recordAndReturn($rateLimiter, $clientIp, self::RATE_ACTION_POLL, $this->issueTokensAndConsume($db, $repo, $row));
    }

    /**
     * Record one rate-limit attempt with the actual outcome (success = HTTP
     * 2xx) before returning the response. Replaces the historical pattern
     * of recording success=false unconditionally up-front, which polluted
     * the smartauth_ratelimit success column.
     *
     * Rate-limit (429) responses are NEVER recorded through this helper;
     * they bypass it intentionally so a flooded bucket cannot record extra
     * attempts and self-amplify.
     *
     * @param array{0:array<string,mixed>,1:int} $response
     * @return array{0:array<string,mixed>,1:int}
     */
    private function recordAndReturn(RateLimiter $rateLimiter, string $clientIp, string $action, array $response): array
    {
        $code = isset($response[1]) ? (int) $response[1] : 500;
        $rateLimiter->recordAttempt($clientIp, $action, $code >= 200 && $code < 300);
        return $response;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:array<string,mixed>,1:int}
     */
    private function issueTokensAndConsume($db, SmartAuthQrPairing $repo, array $row): array
    {
        $userId = (int) $row['fk_user'];
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }
        $user = new \User($db);
        if ($user->fetch($userId) <= 0 || empty($user->id)) {
            return [['error' => 'user_not_found'], 404];
        }
        if ((int) ($user->statut ?? 0) !== 1) {
            return [['error' => 'user_disabled'], 403];
        }

        // Reserve the row first: this transitions confirmed -> consumed
        // atomically. If another concurrent poll already consumed it, we
        // bail out to avoid issuing a second pair of tokens for the same
        // pairing_id.
        if (!$repo->markConsumed($row['rowid'], $userId)) {
            // Another concurrent caller raced us. Treat as already used.
            return [['status' => SmartAuthQrPairing::STATUS_CONSUMED], 200];
        }

        $auth = $this->resolveAuth();
        $entity = (int) ($row['entity'] ?? 1);
        // Default label is intentionally empty: this triggers the
        // post-login device-naming page on the mobile (same UX as the
        // password flow). The mobile may still pre-fill a label by
        // sending `device_label` in the /claim payload.
        $deviceLabel = $row['device_label'] !== null && $row['device_label'] !== ''
            ? (string) $row['device_label']
            : '';

        // Use X-DEVICEID sent by the mobile so salt2 derived at token
        // emission matches the one recomputed by _getSalt2() at every
        // subsequent verification. Without this, emission falls back to
        // the User-Agent hash while verification uses X-DEVICEID -> all
        // protected calls return 401 even with a valid-looking token.
        $rawDeviceId = isset($_SERVER['HTTP_X_DEVICEID']) ? (string) $_SERVER['HTTP_X_DEVICEID'] : '';
        $deviceUuid = InputSanitizer::sanitizeUUID($rawDeviceId) ?? '';

        try {
            $tokens = $auth->generateTokenForAuthenticatedUser($user, $entity, $deviceLabel, $deviceUuid);
        } catch (\Throwable $e) {
            dol_syslog('SmartAuth QrPairController::poll - token issuance failed: ' . $e->getMessage(), LOG_ERR);
            return [['error' => 'token_issuance_failed'], 500];
        }

        dol_syslog('SmartAuth QrPairController::poll - issued tokens for user_id=' . $userId . ' pairing=' . $row['pairing_id'], LOG_INFO);

        // Surface the same identity fields as /login so the mobile's
        // onSuccess handler (which often hydrates a Redux store keyed by
        // userid/username) does not crash on missing values when it
        // receives a QR-pair response.
        $userlogin = !empty($user->email) ? (string) $user->email : (string) $user->login;

        return [
            [
                'status' => SmartAuthQrPairing::STATUS_CONSUMED,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
                'device_uuid' => $tokens['device_uuid'],
                'userid' => (int) $user->id,
                'user' => $userlogin,
                'entity' => $entity,
                // Echo the device list so the mobile can drive the same
                // post-login device-choice screen that /login does.
                'devices_choice' => $tokens['devices_choice'] ?? [],
            ],
            200,
        ];
    }

    private function normalisePairingId($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || !preg_match('/^[0-9a-f]{32}$/', $value)) {
            return null;
        }
        return $value;
    }

    private function currentEntity(): int
    {
        global $conf;
        return (int) ($conf->entity ?? 1);
    }

    private function resolveDb()
    {
        if ($this->injectedDb !== null) {
            return $this->injectedDb;
        }
        global $db;
        return $db;
    }

    private function resolveRepo($db): SmartAuthQrPairing
    {
        if ($this->injectedRepo !== null) {
            return $this->injectedRepo;
        }
        return new SmartAuthQrPairing($db);
    }

    private function resolveAuth(): AuthController
    {
        if ($this->injectedAuth !== null) {
            return $this->injectedAuth;
        }
        return new AuthController();
    }
}
