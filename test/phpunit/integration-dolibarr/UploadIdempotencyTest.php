<?php

/**
 * Integration tests for the upload idempotency layer.
 *
 * Covers both:
 *   - SmartAuthUploadIdempotency DAO (CRUD on llx_smartauth_upload_idempotency)
 *   - UploadController middleware (replay completed / 409 processing / legacy
 *     fall-through on missing or malformed Idempotency-Key)
 *
 * Note: we cannot drive a full multipart upload end-to-end from PHPUnit
 * because SmartUpload::store goes through is_uploaded_file()/move_uploaded_file()
 * which require a real SAPI upload pipeline. We therefore test the
 * middleware behaviour with $_FILES empty (which makes the legacy path
 * return 400 "No file uploaded") and verify replay/409 by pre-seeding the
 * idempotency table directly.
 *
 * @covers \SmartAuthUploadIdempotency
 * @covers \SmartAuth\Api\UploadController
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once dirname(__DIR__, 3) . '/api/UploadController.php';
require_once dirname(__DIR__, 3) . '/class/smartauthuploadidempotency.class.php';

use SmartAuth\Api\UploadController;
use SmartAuthUploadIdempotency;

class UploadIdempotencyTest extends DolibarrRealTestCase
{
    private const TABLE = 'smartauth_upload_idempotency';

    /** @var SmartAuthUploadIdempotency */
    private $repo;

    /** @var UploadController */
    private $controller;

    /** @var string Valid UUID v4 used across tests */
    private $key1 = '11112222-3333-4abc-8def-123456789abc';

    /** @var string Another valid UUID v4 */
    private $key2 = '22223333-4444-4def-9abc-fedcba987654';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new SmartAuthUploadIdempotency($this->db);
        $this->controller = new UploadController();

        // Clean the idempotency table before each test so assertions are
        // deterministic regardless of order.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . self::TABLE);

        // Make sure no idempotency header leaks from a previous test.
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        $_FILES = [];
        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // DAO tests
    // -------------------------------------------------------------------

    public function testIsValidKeyAcceptsUuidV4(): void
    {
        $this->assertTrue(SmartAuthUploadIdempotency::isValidKey($this->key1));
        $this->assertTrue(SmartAuthUploadIdempotency::isValidKey($this->key2));
    }

    public function testIsValidKeyRejectsMalformed(): void
    {
        $this->assertFalse(SmartAuthUploadIdempotency::isValidKey(''));
        $this->assertFalse(SmartAuthUploadIdempotency::isValidKey('not-a-uuid'));
        // V1 UUID (timestamp-based, third group does not start with 4)
        $this->assertFalse(SmartAuthUploadIdempotency::isValidKey('11112222-3333-1abc-8def-123456789abc'));
        // Wrong variant bit (4th group must start with 8/9/a/b)
        $this->assertFalse(SmartAuthUploadIdempotency::isValidKey('11112222-3333-4abc-cdef-123456789abc'));
    }

    public function testFindExistingReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->findExisting($this->key1, (int) $this->testUser->id, 1));
    }

    public function testCreateProcessingThenFindExistingRoundTrip(): void
    {
        $ok = $this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1);
        $this->assertTrue($ok);

        $row = $this->repo->findExisting($this->key1, (int) $this->testUser->id, 1);
        $this->assertNotNull($row);
        $this->assertSame(SmartAuthUploadIdempotency::STATUS_PROCESSING, $row['status']);
        $this->assertSame($this->key1, $row['idempotency_token']);
        $this->assertSame((int) $this->testUser->id, $row['fk_user']);
        $this->assertNull($row['upload_id']);
        $this->assertNull($row['response_body']);
        $this->assertNull($row['http_status']);
    }

    public function testCreateProcessingFailsOnDuplicateKeySameUser(): void
    {
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
        // Second call simulates a concurrent retry: the unique index must
        // reject it, signaling the caller to re-read and serve 409.
        $this->assertFalse($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
    }

    public function testCreateProcessingAllowsSameKeyForDifferentUser(): void
    {
        $otherUser = $this->createTestUser(['login' => 'idem_other_' . uniqid()]);
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $otherUser->id, 1));

        $row1 = $this->repo->findExisting($this->key1, (int) $this->testUser->id, 1);
        $row2 = $this->repo->findExisting($this->key1, (int) $otherUser->id, 1);
        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        $this->assertNotSame($row1['rowid'], $row2['rowid']);
    }

    public function testMarkCompletedStoresResponseAndHttpStatus(): void
    {
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));

        $response = [
            'upload_id' => 'upl_' . str_repeat('a', 60),
            'filename'  => 'photo.jpg',
            'mime'      => 'image/jpeg',
            'size'      => 1234,
            'sha256'    => str_repeat('b', 64),
        ];
        $ok = $this->repo->markCompleted($this->key1, (int) $this->testUser->id, 1, $response['upload_id'], $response, 201);
        $this->assertTrue($ok);

        $row = $this->repo->findExisting($this->key1, (int) $this->testUser->id, 1);
        $this->assertSame(SmartAuthUploadIdempotency::STATUS_COMPLETED, $row['status']);
        $this->assertSame($response['upload_id'], $row['upload_id']);
        $this->assertSame(201, $row['http_status']);
        $decoded = json_decode($row['response_body'], true);
        $this->assertSame($response, $decoded);
        $this->assertNotNull($row['completed_at']);
    }

    public function testMarkCompletedNoOpOnAlreadyCompletedRow(): void
    {
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
        $this->assertTrue($this->repo->markCompleted($this->key1, (int) $this->testUser->id, 1, 'first', ['upload_id' => 'first'], 201));
        // A second markCompleted on the same row must NOT overwrite the
        // first response (status mismatch on the WHERE clause).
        $this->assertFalse($this->repo->markCompleted($this->key1, (int) $this->testUser->id, 1, 'second', ['upload_id' => 'second'], 201));

        $row = $this->repo->findExisting($this->key1, (int) $this->testUser->id, 1);
        $this->assertSame('first', $row['upload_id']);
    }

    public function testDeleteRowRemovesEntry(): void
    {
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
        $this->assertNotNull($this->repo->findExisting($this->key1, (int) $this->testUser->id, 1));

        $this->assertTrue($this->repo->deleteRow($this->key1, (int) $this->testUser->id, 1));
        $this->assertNull($this->repo->findExisting($this->key1, (int) $this->testUser->id, 1));
    }

    public function testDeleteOldRemovesOnlyAgedRows(): void
    {
        $this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1);
        $this->repo->createProcessing($this->key2, (int) $this->testUser->id, 1);

        // Backdate key1 by 25 hours (past the 24h retention) and leave
        // key2 at "now".
        $oldDate = $this->db->idate(dol_now() - 25 * 3600);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . self::TABLE
            . " SET created_at = '" . $oldDate . "'"
            . " WHERE idempotency_token = '" . $this->db->escape($this->key1) . "'"
        );

        $deleted = $this->repo->deleteOld(24 * 3600);
        $this->assertSame(1, $deleted);
        $this->assertNull($this->repo->findExisting($this->key1, (int) $this->testUser->id, 1));
        $this->assertNotNull($this->repo->findExisting($this->key2, (int) $this->testUser->id, 1));
    }

    public function testDeleteStaleProcessingTargetsOnlyProcessing(): void
    {
        // Two rows, both backdated by 11 minutes; the completed one must
        // be left alone, the processing one must be purged.
        $this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1);
        $this->repo->createProcessing($this->key2, (int) $this->testUser->id, 1);
        $this->repo->markCompleted($this->key2, (int) $this->testUser->id, 1, 'done', ['upload_id' => 'done'], 201);

        $oldDate = $this->db->idate(dol_now() - 11 * 60);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . self::TABLE
            . " SET created_at = '" . $oldDate . "'"
        );

        $deleted = $this->repo->deleteStaleProcessing(600);
        $this->assertSame(1, $deleted);
        $this->assertNull($this->repo->findExisting($this->key1, (int) $this->testUser->id, 1));
        $this->assertNotNull($this->repo->findExisting($this->key2, (int) $this->testUser->id, 1));
    }

    public function testDoScheduledJobPurgesIdempotencyRows(): void
    {
        require_once dirname(__DIR__, 3) . '/class/smartauth.class.php';

        // Seed three rows:
        //   - oldCompleted: 25h old, completed -> deleted by deleteOld
        //   - oldProcessing: 25h old, processing -> deleted by both passes
        //   - freshProcessing: 5min old, processing -> survives
        $oldCompleted  = '33334444-5555-4abc-8def-aaaabbbbcccc';
        $oldProcessing = '44445555-6666-4abc-8def-aaaabbbbcccc';
        $freshProcessing = '55556666-7777-4abc-8def-aaaabbbbcccc';

        $this->assertTrue($this->repo->createProcessing($oldCompleted, (int) $this->testUser->id, 1));
        $this->assertTrue($this->repo->markCompleted($oldCompleted, (int) $this->testUser->id, 1, 'x', ['upload_id' => 'x'], 201));
        $this->assertTrue($this->repo->createProcessing($oldProcessing, (int) $this->testUser->id, 1));
        $this->assertTrue($this->repo->createProcessing($freshProcessing, (int) $this->testUser->id, 1));

        $oldDate = $this->db->idate(dol_now() - 25 * 3600);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . self::TABLE
            . " SET created_at = '" . $oldDate . "'"
            . " WHERE idempotency_token IN ('" . $this->db->escape($oldCompleted) . "',"
            . " '" . $this->db->escape($oldProcessing) . "')"
        );

        $scheduler = new \SmartAuth($this->db);
        $this->assertSame(0, $scheduler->doScheduledJob());

        $this->assertNull($this->repo->findExisting($oldCompleted, (int) $this->testUser->id, 1), 'aged completed row must be purged');
        $this->assertNull($this->repo->findExisting($oldProcessing, (int) $this->testUser->id, 1), 'aged processing row must be purged');
        $this->assertNotNull($this->repo->findExisting($freshProcessing, (int) $this->testUser->id, 1), 'fresh processing row must survive');
    }

    // -------------------------------------------------------------------
    // Controller-level tests
    // -------------------------------------------------------------------

    public function testStoreWithoutHeaderUsesLegacyPath(): void
    {
        // No Idempotency-Key, no $_FILES -> legacy behaviour: 400 "No file uploaded".
        // The idempotency table must remain empty.
        list($body, $status) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $body['error']);
        $this->assertSame(0, $this->countIdempotencyRows());
    }

    public function testStoreWithMalformedHeaderFallsBackToLegacyPath(): void
    {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'not-a-uuid';
        list($body, $status) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $body['error']);
        // Malformed -> no DAO interaction at all.
        $this->assertSame(0, $this->countIdempotencyRows());
    }

    public function testStoreWith4xxResponseDeletesIdempotencyRow(): void
    {
        // A valid key + no file -> processFiles returns 400. The middleware
        // must roll back the "processing" row so the client can retry the
        // same key with a corrected payload.
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $this->key1;
        list(, $status) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);
        $this->assertSame(400, $status);
        $this->assertSame(0, $this->countIdempotencyRows(), '4xx response must delete the idempotency row');
    }

    public function testStoreReplaysCompletedResponse(): void
    {
        // Pre-seed a completed row pretending the file was uploaded
        // previously. The controller must NOT touch processFiles (we leave
        // $_FILES empty, which would normally return 400) and instead
        // return the stored response verbatim.
        $cannedResponse = [
            'upload_id' => 'upl_' . str_repeat('c', 60),
            'filename'  => 'photo.jpg',
            'mime'      => 'image/jpeg',
            'size'      => 4242,
            'sha256'    => str_repeat('d', 64),
        ];
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));
        $this->assertTrue($this->repo->markCompleted($this->key1, (int) $this->testUser->id, 1, $cannedResponse['upload_id'], $cannedResponse, 201));

        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $this->key1;
        list($body, $status) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);

        $this->assertSame(201, $status);
        $this->assertSame($cannedResponse, $body);
    }

    public function testStoreReturns409OnProcessingRow(): void
    {
        // Pre-seed a processing row to simulate a concurrent retry hitting
        // the controller while the first request is still running.
        $this->assertTrue($this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1));

        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $this->key1;
        list($body, $status) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);

        $this->assertSame(409, $status);
        $this->assertSame('upload_in_progress', $body['error']);
        $this->assertSame(2000, $body['retry_after_ms']);
    }

    public function testReplayIsScopedPerUser(): void
    {
        // Two users sharing the same key must NOT cross-replay each
        // other's response. user1 -> completed; user2 -> processing.
        $otherUser = $this->createTestUser(['login' => 'idem_scope_' . uniqid()]);

        $this->repo->createProcessing($this->key1, (int) $this->testUser->id, 1);
        $this->repo->markCompleted($this->key1, (int) $this->testUser->id, 1, 'mine', ['upload_id' => 'mine'], 201);
        $this->repo->createProcessing($this->key1, (int) $otherUser->id, 1);

        // Replay for user1.
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $this->key1;
        list($body1, $status1) = $this->controller->store(['user' => $this->testUser, 'entity' => 1]);
        $this->assertSame(201, $status1);
        $this->assertSame('mine', $body1['upload_id']);

        // 409 for user2 (separate processing row).
        list($body2, $status2) = $this->controller->store(['user' => $otherUser, 'entity' => 1]);
        $this->assertSame(409, $status2);
        $this->assertSame('upload_in_progress', $body2['error']);
    }

    private function countIdempotencyRows(): int
    {
        $resql = $this->db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . self::TABLE);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        return (int) $obj->n;
    }
}
