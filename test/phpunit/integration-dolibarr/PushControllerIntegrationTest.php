<?php

/**
 * Integration tests for Web Push (PushController + PushSender + cron purge).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * @requires PHP >= 8.2
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\Api\PushController;
use SmartAuth\Api\RouteCache;

class PushControllerIntegrationTest extends DolibarrRealTestCase
{
    /** @var PushController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new PushController();
        // The controller resolves the subject from the global $user.
        global $user;
        $user = $this->testUser;
    }

    /**
     * Build a valid subscribe payload with a unique endpoint.
     */
    private function makeSubscription(?string $endpoint = null, ?string $label = null): array
    {
        $endpoint = $endpoint ?? 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8));
        return [
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'BPxabc_DEF-123', 'auth' => 'sometoken_AB'],
            ],
            'label' => $label,
        ];
    }

    public function testSubscribeCreatesThenRebindsSameEndpoint(): void
    {
        $payload = $this->makeSubscription(null, 'Mon navigateur');

        [$body, $code] = $this->controller->subscribe($payload);
        $this->assertSame(201, $code);
        $this->assertArrayHasKey('id', $body);
        $createdId = $body['id'];

        // Same endpoint -> UPSERT (re-bind), HTTP 200, same row id.
        [$body2, $code2] = $this->controller->subscribe($payload);
        $this->assertSame(200, $code2);
        $this->assertSame($createdId, $body2['id']);

        // Exactly one row exists for this endpoint.
        $count = (int) $this->db->num_rows($this->db->query(
            "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions"
            ." WHERE endpoint = '".$this->db->escape($payload['subscription']['endpoint'])."'"
        ));
        $this->assertSame(1, $count);
    }

    public function testSubscribeRejectsNonHttpsEndpoint(): void
    {
        $payload = $this->makeSubscription('http://insecure.example/ep');
        [$body, $code] = $this->controller->subscribe($payload);
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('error', $body);
    }

    public function testSubscribeRejectsNonBase64urlKeys(): void
    {
        $payload = $this->makeSubscription();
        $payload['subscription']['keys']['p256dh'] = 'not valid base64url!!';
        [$body, $code] = $this->controller->subscribe($payload);
        $this->assertSame(400, $code);
    }

    public function testSubscribeRejectsMissingFields(): void
    {
        [$body, $code] = $this->controller->subscribe(['subscription' => ['endpoint' => 'https://x/y']]);
        $this->assertSame(400, $code);
    }

    public function testListReturnsContractShape(): void
    {
        $this->controller->subscribe($this->makeSubscription(null, 'Label A'));

        [$body, $code] = $this->controller->listSubscriptions([]);
        $this->assertSame(200, $code);
        $this->assertArrayHasKey('subscriptions', $body);
        $this->assertCount(1, $body['subscriptions']);

        $sub = $body['subscriptions'][0];
        foreach (['id', 'label', 'user_agent', 'created_at', 'last_used_at', 'success_count', 'status'] as $key) {
            $this->assertArrayHasKey($key, $sub, "missing contract field $key");
        }
        $this->assertSame('Label A', $sub['label']);
        $this->assertSame(1, $sub['status']);
    }

    public function testSubjectIsolationListAndDelete(): void
    {
        // user1 subscribes
        $payload = $this->makeSubscription();
        [$body] = $this->controller->subscribe($payload);
        $user1SubId = $body['id'];

        // Switch to a different subject (another user).
        $other = $this->createTestUser(['login' => 'pushother_'.uniqid()]);
        $saved = $GLOBALS['user'];
        $GLOBALS['user'] = $other;
        try {
            // The other subject sees none of user1's subscriptions.
            [$listBody] = $this->controller->listSubscriptions([]);
            $this->assertCount(0, $listBody['subscriptions']);

            // The other subject cannot delete user1's subscription (404).
            [, $delCode] = $this->controller->unsubscribe(['id' => $user1SubId]);
            $this->assertSame(404, $delCode);
        } finally {
            $GLOBALS['user'] = $saved;
        }

        // user1's subscription is still there.
        $count = (int) $this->db->num_rows($this->db->query(
            "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions WHERE rowid = ".(int) $user1SubId
        ));
        $this->assertSame(1, $count);
    }

    public function testUnsubscribeByEndpointThen404(): void
    {
        $payload = $this->makeSubscription();
        $this->controller->subscribe($payload);
        $endpoint = $payload['subscription']['endpoint'];

        [, $code] = $this->controller->unsubscribe(['endpoint' => $endpoint]);
        $this->assertSame(200, $code);

        // Already gone -> 404.
        [, $code2] = $this->controller->unsubscribe(['endpoint' => $endpoint]);
        $this->assertSame(404, $code2);
    }

    public function testUnsubscribeRequiresEndpointOrId(): void
    {
        [, $code] = $this->controller->unsubscribe([]);
        $this->assertSame(400, $code);
    }

    public function testSendWithoutScopeIsRefused(): void
    {
        // The dormant M2M send() handler must refuse a caller without the
        // dedicated scope (a JWT end-user has no oauth_scopes -> 403).
        [$body, $code] = $this->controller->send(['title' => 't', 'body' => 'b', 'user_id' => 1]);
        $this->assertSame(403, $code);
        $this->assertSame('insufficient_scope', $body['error']);
    }

    public function testGetVapidPublicKey200WhenConfigured500WhenNot(): void
    {
        global $conf;

        // Configured.
        $conf->global->SMARTAUTH_VAPID_PUBLIC_KEY = 'BPxFakePublicKeyForTest';
        [$body, $code] = $this->controller->getVapidPublicKey();
        $this->assertSame(200, $code);
        $this->assertSame('BPxFakePublicKeyForTest', $body['publicKey']);

        // Not configured.
        $conf->global->SMARTAUTH_VAPID_PUBLIC_KEY = '';
        [$body2, $code2] = $this->controller->getVapidPublicKey();
        $this->assertSame(500, $code2);
        $this->assertArrayHasKey('error', $body2);
    }

    public function testDoScheduledJobPurgesExpiredSubscriptions(): void
    {
        $t = MAIN_DB_PREFIX.'smartauth_push_subscriptions';
        $now = $this->db->idate(dol_now());
        $old = $this->db->idate(dol_now() - 10 * 24 * 3600);

        $insert = function (string $endpoint, int $status, int $errCount = 0, ?string $lastErr = null) use ($t, $now) {
            $sql = "INSERT INTO $t (subject_type, fk_user, entity, endpoint, key_p256dh, key_auth, date_creation, status, error_count, date_last_error)";
            $sql .= " VALUES ('user', 1, 1, '".$this->db->escape($endpoint)."', 'p', 'a', '$now', ".(int) $status.", ".(int) $errCount.", ".($lastErr ? "'".$this->db->escape($lastErr)."'" : "NULL").")";
            $this->assertNotFalse($this->db->query($sql));
        };

        $insert('https://x/active', 1);                       // keep
        $insert('https://x/expired', 9);                      // purge (status=9)
        $insert('https://x/errold', 1, 3, $old);              // purge (errors + old)
        $insert('https://x/errnew', 1, 3, $now);              // keep (errors but recent)

        $sa = new \SmartAuth($this->db);
        $sa->doScheduledJob();

        $kept = [];
        $resql = $this->db->query("SELECT endpoint FROM $t ORDER BY endpoint");
        while ($o = $this->db->fetch_object($resql)) {
            $kept[] = $o->endpoint;
        }
        $this->assertContains('https://x/active', $kept);
        $this->assertContains('https://x/errnew', $kept);
        $this->assertNotContains('https://x/expired', $kept);
        $this->assertNotContains('https://x/errold', $kept);
    }

    public function testNoSendRouteExposedAndPushRoutesAreProtectedCorrectly(): void
    {
        // Register routes in isolation and inspect what LocalRoutes.php declares.
        RouteCache::startRegistration();
        include dirname(__DIR__, 3).'/api/LocalRoutes.php';

        $ref = new \ReflectionClass(RouteCache::class);
        $prop = $ref->getProperty('registeredRoutes');
        $prop->setAccessible(true);
        $routes = $prop->getValue();
        // Leave registration mode so we do not leak state to other tests.
        $prop->setValue(null, []);
        $modeProp = $ref->getProperty('registrationMode');
        $modeProp->setAccessible(true);
        $modeProp->setValue(null, false);

        $byAction = [];
        foreach ($routes as $r) {
            $byAction[$r['method'].' '.$r['action']] = $r['protected'];
        }

        // No send route at all.
        foreach ($routes as $r) {
            $this->assertNotSame('push/send', $r['action'], 'push/send must never be a registered route');
        }

        // The 4 push routes exist with the expected protection.
        $this->assertArrayHasKey('GET push/vapid-public-key', $byAction);
        $this->assertFalse($byAction['GET push/vapid-public-key']);
        $this->assertArrayHasKey('POST push/subscribe', $byAction);
        $this->assertTrue($byAction['POST push/subscribe']);
        $this->assertArrayHasKey('DELETE push/unsubscribe', $byAction);
        $this->assertTrue($byAction['DELETE push/unsubscribe']);
        $this->assertArrayHasKey('GET push/subscriptions', $byAction);
        $this->assertTrue($byAction['GET push/subscriptions']);
    }
}
