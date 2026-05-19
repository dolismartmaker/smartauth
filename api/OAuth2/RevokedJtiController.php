<?php

/**
 * RevokedJtiController.php
 *
 * Published list of revoked JWT IDs (jti). Resource servers (capTodo,
 * capCRM, fxpreflight, ...) poll this endpoint every ~10 minutes to learn
 * which still-unexpired access tokens they must reject, so a contract
 * closure propagates faster than the access_token TTL window. See
 * PERFS.md §3.4 (hybrid revocation: TTL + revocation list).
 *
 * Cache strategy:
 *  - Response is JSON {"as_of": <unix ts>, "jtis": ["..."]}
 *  - ETag is set on every response (weak ETag, sha256 of the canonicalized
 *    list). When the backend re-polls and sends If-None-Match with the
 *    last ETag, the endpoint replies 304 Not Modified with no body so the
 *    bulk of polls stays cheap (PERFS.md §2: "dominé par 304 quasi-gratuits").
 *  - Cache-Control is public + must-revalidate to allow the client to store
 *    the ETag without ever serving stale content.
 *
 * Authentication:
 *  - None for the MVP. The revoked jti list is not a secret: an attacker
 *    who reads it gains no power (they cannot reuse a revoked jti). If the
 *    list ever needs hardening, IP allowlist via doliproxy is the natural
 *    next step.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

dol_include_once('/smartauth/api/OAuth2/ResponseTrait.php');
dol_include_once('/smartauth/api/OAuth2/ResponseException.php');

class RevokedJtiController
{
    use ResponseTrait;

    /**
     * Database connection
     * @var \DoliDB
     */
    private $db;

    /**
     * Token service (drives the listRevokedJtiSince / purge calls)
     * @var TokenService
     */
    private $tokenService;

    /**
     * @param \DoliDB $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->tokenService = new TokenService($db);
    }

    /**
     * Handle GET /oauth/revoked-jti[?since=<unix_ts>]
     *
     * @return void
     */
    public function handleList(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            $this->sendError('invalid_request', 'Method must be GET', 405);
            return;
        }

        // Optional ?since=<unix ts>: backend only wants entries revoked
        // strictly after this timestamp. Negative or non-numeric values are
        // tolerated (treated as 0 = full list).
        $sinceRaw = isset($_GET['since']) ? trim((string) $_GET['since']) : '';
        $sinceTs = (ctype_digit($sinceRaw) ? (int) $sinceRaw : 0);
        if ($sinceTs < 0) {
            $sinceTs = 0;
        }

        // Lazy purge of expired rows. Cheap (indexed scan on expires_at) and
        // saves us a scheduled job. Errors are non-fatal.
        $this->tokenService->purgeExpiredRevokedJti();

        $rows = $this->tokenService->listRevokedJtiSince($sinceTs);

        // Compute as_of = max(revoked_at_ts) over the rows we are about to
        // return. The client should re-poll with since=as_of so we only
        // ever serve the delta. When the list is empty, as_of falls back
        // to the caller's since value so it stays monotonic.
        $asOf = $sinceTs;
        $jtis = [];
        foreach ($rows as $row) {
            $jtis[] = $row['jti'];
            if ($row['revoked_at_ts'] > $asOf) {
                $asOf = $row['revoked_at_ts'];
            }
        }

        // Weak ETag built from a deterministic projection of the response.
        // Two requests yielding the same (as_of, sorted jtis) produce the
        // same ETag so re-polling without changes hits the 304 path.
        $etagSource = $asOf . '|' . implode(',', $jtis);
        $etag = 'W/"' . substr(hash('sha256', $etagSource), 0, 32) . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            $this->sendNotModified($etag);
            return;
        }

        $response = [
            'as_of' => $asOf,
            'jtis' => $jtis,
        ];

        $this->sendJsonResponseWithHeaders($response, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Match an If-None-Match header value against our weak ETag. RFC 7232
     * §3.2: the header is a comma-separated list of entity-tags, with a
     * special "*" value meaning "any". For our endpoint we only ever emit
     * a single weak ETag, so the match is straightforward.
     *
     * @param string $headerValue Raw If-None-Match value as sent by the client
     * @param string $serverEtag  Our current ETag (already quoted, weak prefix)
     * @return bool
     */
    private function etagMatches(string $headerValue, string $serverEtag): bool
    {
        $headerValue = trim($headerValue);
        if ($headerValue === '*') {
            return true;
        }
        foreach (explode(',', $headerValue) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $serverEtag) {
                return true;
            }
        }
        return false;
    }
}
