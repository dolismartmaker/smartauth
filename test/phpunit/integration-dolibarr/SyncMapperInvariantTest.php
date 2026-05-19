<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../api/SyncController.php';
require_once __DIR__ . '/../../../api/InputSanitizer.php';
require_once __DIR__ . '/../../../dolMapping/dmBase.php';
require_once __DIR__ . '/../../../dolMapping/dmTrait.php';
require_once __DIR__ . '/../../../dolMapping/dmThirdparty.php';
require_once __DIR__ . '/../../../dolMapping/dmContact.php';
require_once __DIR__ . '/../../../dolMapping/dmProduct.php';

use SmartAuth\Api\SyncController;
use SmartAuth\DolibarrMapping\dmThirdparty;
use SmartAuth\DolibarrMapping\dmContact;
use SmartAuth\DolibarrMapping\dmProduct;
use Societe;
use Contact;
use Product;

/**
 * Invariant I-1 test (see documentation/SPEC_SMARTAUTH_AUTHORIZATION.md section 8.2).
 *
 * Invariant: every API response carrying a business entity must be routed
 * through a declared dm* mapper. No raw Dolibarr field name may leak.
 *
 * For each built-in syncable entity (thirdparty, contact, product) this test
 * calls /sync/pull and asserts that every key present in the response is
 * declared (API-side) in the matching mapper's $listOfPublishedFields. Any
 * Dolibarr-internal or undeclared field reaching the response fails the
 * assertion.
 *
 * Status as of 2026-05-15: all three tests are intentionally guarded by
 * markTestSkipped() because SyncController currently casts (array) $object
 * instead of routing through dm* (drift documented in SPEC section 7.3).
 * Once SyncController is migrated as part of phase A of the SPEC, remove
 * the markTestSkipped() call at the top of each test method and the
 * assertions below become the permanent regression guard.
 *
 * @covers \SmartAuth\Api\SyncController
 * @group invariant
 */
class SyncMapperInvariantTest extends DolibarrRealTestCase
{
    /** @var SyncController */
    private $controller;

    /** @var string */
    private $testClientUUID;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new SyncController();
        $this->testClientUUID = $this->generateUUID();

        $this->cleanSyncTables();
    }

    protected function tearDown(): void
    {
        $this->cleanSyncTables();
        parent::tearDown();
    }

    // =========================================================================
    // Per-entity invariant tests
    // =========================================================================

    public function testSyncPullThirdpartyEmitsOnlyMapperDeclaredFields(): void
    {
        $this->registerSyncClientWithScope(['thirdparty']);

        $this->createTestSociete([
            'name' => 'Invariant Test Soc ' . uniqid(),
        ]);

        $this->assertPullEmitsOnlyDeclaredFields('thirdparty', dmThirdparty::class);
    }

    public function testSyncPullContactEmitsOnlyMapperDeclaredFields(): void
    {
        $this->registerSyncClientWithScope(['contact']);

        // Contact requires a parent thirdparty in standard Dolibarr setup.
        $soc = $this->createTestSociete(['name' => 'Parent Soc for Contact ' . uniqid()]);
        $this->createTestContact($soc, [
            'lastname' => 'Invariant',
            'firstname' => 'Test',
        ]);

        $this->assertPullEmitsOnlyDeclaredFields('contact', dmContact::class);
    }

    public function testSyncPullProductEmitsOnlyMapperDeclaredFields(): void
    {
        $this->registerSyncClientWithScope(['product']);

        $this->createTestProduct([
            'ref' => 'INV-' . uniqid(),
            'label' => 'Invariant Test Product',
        ]);

        $this->assertPullEmitsOnlyDeclaredFields('product', dmProduct::class);
    }

    // =========================================================================
    // Core assertion
    // =========================================================================

    /**
     * Call /sync/pull for the given object_type and assert that every field
     * key present in the response is declared (API-side) in the given dm*
     * mapper's $listOfPublishedFields property.
     *
     * @param string $objectType        e.g. 'thirdparty', 'contact', 'product'
     * @param string $mapperClass       Fully qualified dm* mapper class name
     */
    private function assertPullEmitsOnlyDeclaredFields(string $objectType, string $mapperClass): void
    {
        $result = $this->controller->pull([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'object_type' => $objectType,
        ]);

        $this->assertEquals(
            200,
            $result[1],
            "SyncController::pull returned HTTP {$result[1]} for object_type='{$objectType}'"
        );
        $this->assertArrayHasKey('updated', $result[0]);
        $this->assertNotEmpty(
            $result[0]['updated'],
            "Expected at least one '{$objectType}' event in pull response, got 0. "
            . "The created fixture might not be in the client's sync_scope, or pull filtering misbehaves."
        );

        $allowedFields = $this->getDeclaredApiFields($mapperClass);

        $leakedAcrossEvents = [];
        foreach ($result[0]['updated'] as $event) {
            // Each event is a flat object payload (cf SyncControllerIntegrationTest:222).
            if (!is_array($event)) {
                continue;
            }
            foreach (array_keys($event) as $key) {
                if (!in_array($key, $allowedFields, true)) {
                    $leakedAcrossEvents[$key] = true;
                }
            }
        }

        $this->assertEmpty(
            $leakedAcrossEvents,
            sprintf(
                "Invariant I-1 violated for '%s'.\n"
                . "SyncController emitted field(s) NOT declared in %s::\$listOfPublishedFields:\n"
                . "  Leaked: %s\n"
                . "  Allowed by mapper: %s\n"
                . "Root cause: SyncController returns (array) \$object instead of routing through the dm* layer. "
                . "This bypasses any future authorization policy and breaks SPEC_SMARTAUTH_AUTHORIZATION.md.",
                $objectType,
                $mapperClass,
                implode(', ', array_keys($leakedAcrossEvents)),
                implode(', ', $allowedFields)
            )
        );
    }

    /**
     * Sync-level metadata that is always present on every /sync/pull
     * event regardless of the mapper. These keys are added by
     * SyncController post-mapping (formatObjectForSync) and are part
     * of the sync contract, not the entity contract:
     *  - 'tms'              base_tms snapshot for conflict detection
     *                       (SyncEngine.js stores it as server_tms)
     *  - 'nb_linked_files'  count of files attached via ECM
     *  - 'linked_files'     same payload but with the file metadata
     *                       (only when ?with_files=1)
     *  - 'categories'       category bindings via llx_categorie_<table>
     */
    private const SYNC_METADATA_FIELDS = [
        'tms',
        'nb_linked_files',
        'linked_files',
        'categories',
    ];

    /**
     * Read the API-side field names declared in a dm* mapper without
     * instantiating it. Uses ReflectionClass::getDefaultProperties to avoid
     * triggering dmBase::boot() and its Dolibarr global dependencies.
     *
     * Returns the union of:
     *  - listOfPublishedFields (1:1 Dolibarr column -> API key)
     *  - listOfDerivedFields   (computed keys, eg logo / logo_mini)
     *  - SYNC_METADATA_FIELDS  (post-mapping enrichment)
     *
     * @param string $mapperClass Fully qualified dm* class name
     * @return array<int, string> List of API-side field names
     */
    private function getDeclaredApiFields(string $mapperClass): array
    {
        $ref = new \ReflectionClass($mapperClass);
        $defaults = $ref->getDefaultProperties();

        $this->assertArrayHasKey(
            'listOfPublishedFields',
            $defaults,
            "Mapper {$mapperClass} does not declare \$listOfPublishedFields (violates invariant I-5)."
        );

        $list = $defaults['listOfPublishedFields'];
        $this->assertIsArray($list, "{$mapperClass}::\$listOfPublishedFields must be an array.");
        $this->assertNotEmpty($list, "{$mapperClass}::\$listOfPublishedFields must not be empty (violates invariant I-5).");

        $allowed = array_values($list);

        // Derived fields: optional. Only some mappers declare them.
        if (array_key_exists('listOfDerivedFields', $defaults) && is_array($defaults['listOfDerivedFields'])) {
            $allowed = array_merge($allowed, array_values($defaults['listOfDerivedFields']));
        }

        // Sync metadata is universal for /sync/pull events.
        $allowed = array_merge($allowed, self::SYNC_METADATA_FIELDS);

        return array_values(array_unique($allowed));
    }

    // =========================================================================
    // Fixture helpers (local to this invariant test, do not pollute the base)
    // =========================================================================

    /**
     * Generate a UUID v4 string.
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Truncate sync-specific tables to keep tests isolated.
     */
    private function cleanSyncTables(): void
    {
        $tables = [
            'smartauth_sync_events',
            'smartauth_sync_conflicts',
            'smartauth_sync_tombstones',
            'smartauth_sync_clients',
        ];

        foreach ($tables as $table) {
            $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . $table);
        }
    }

    /**
     * Insert a minimal smartauth_devices row and return its primary key.
     */
    private function createSyncTestDevice(): int
    {
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_devices';
        $sql .= ' (ref, fk_user_creat, uuid, label, date_creation, status, entity)';
        $sql .= ' VALUES (';
        $sql .= "'INV-DEV-" . uniqid() . "', ";
        $sql .= (int) $this->testUser->id . ', ';
        $sql .= "'" . $this->db->escape($this->generateUUID()) . "', ";
        $sql .= "'Invariant Test Device', ";
        $sql .= "'" . $this->db->idate(time()) . "', ";
        $sql .= '1, ';
        $sql .= '1)';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \RuntimeException('Failed to insert sync test device: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'smartauth_devices');
    }

    /**
     * Register a sync client for $this->testClientUUID with the given scope.
     * The scope must include the entity types this test will pull.
     *
     * @param array<int, string> $scope List of object types to subscribe to.
     */
    private function registerSyncClientWithScope(array $scope): void
    {
        $deviceId = $this->createSyncTestDevice();

        $result = $this->controller->register([
            'user_id' => $this->testUser->id,
            'client_uuid' => $this->testClientUUID,
            'jwt_device_id' => $deviceId,
            'app_version' => '1.0.0',
            'sync_scope' => $scope,
        ]);

        $this->assertEquals(
            200,
            $result[1],
            'Failed to register sync client: HTTP ' . $result[1] . ' - ' . json_encode($result[0])
        );
    }

    /**
     * Create a Contact attached to a parent Societe.
     *
     * @param Societe $parent  Parent thirdparty (Contact::create requires socid in standard Dolibarr).
     * @param array   $data    Optional overrides: lastname, firstname, email, phone, etc.
     */
    private function createTestContact(Societe $parent, array $data = []): Contact
    {
        require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

        $contact = new Contact($this->db);
        $contact->lastname = $data['lastname'] ?? 'Test ' . uniqid();
        $contact->firstname = $data['firstname'] ?? 'Contact';
        $contact->email = $data['email'] ?? ('c_' . uniqid() . '@example.test');
        $contact->phone_pro = $data['phone'] ?? '';
        $contact->socid = (int) $parent->id;
        $contact->statut = 1;
        $contact->entity = 1;

        $result = $contact->create($this->testUser);
        if ($result <= 0) {
            throw new \RuntimeException('Failed to create test contact: ' . $contact->error);
        }
        return $contact;
    }

    /**
     * Create a minimal Product.
     *
     * @param array $data Optional overrides: ref, label, price, type.
     */
    private function createTestProduct(array $data = []): Product
    {
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        $product = new Product($this->db);
        $product->ref = $data['ref'] ?? ('P-' . uniqid());
        $product->label = $data['label'] ?? 'Test Product ' . uniqid();
        $product->type = $data['type'] ?? 0; // 0 = product, 1 = service
        $product->price = $data['price'] ?? 10.0;
        $product->price_base_type = 'HT';
        $product->tva_tx = 20.0;
        $product->status = 1;
        $product->status_buy = 1;
        $product->entity = 1;

        $result = $product->create($this->testUser);
        if ($result <= 0) {
            throw new \RuntimeException('Failed to create test product: ' . $product->error);
        }
        return $product;
    }
}
