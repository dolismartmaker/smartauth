<?php

/**
 * Round-trip tests for "lot A" mappers (Phase 5 of mappers centralization).
 *
 * Same pattern as MapperRoundTripPilotTest:
 *   1. build a real Dolibarr object (inline fixture, no shared helper)
 *   2. call exportMappedData() and assert the expected api-side keys
 *   3. call importMappedData() with a payload that includes a NON-writable
 *      field and assert MapperValidationException collects it
 *
 * Mappers covered:
 *   - dmCategory     (Categorie)
 *   - dmSubscription (Subscription, needs an Adherent + AdherentType)
 *   - dmMemberType   (AdherentType)
 *   - dmMember       (Adherent, needs an AdherentType)
 *   - dmDonation     (Don)
 *   - dmMulticurrency (MultiCurrency)
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmCategory;
use SmartAuth\DolibarrMapping\dmDonation;
use SmartAuth\DolibarrMapping\dmMember;
use SmartAuth\DolibarrMapping\dmMemberType;
use SmartAuth\DolibarrMapping\dmMulticurrency;
use SmartAuth\DolibarrMapping\dmSubscription;
use SmartAuth\DolibarrMapping\MapperValidationException;

require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';

/**
 * @covers \SmartAuth\DolibarrMapping\dmTrait::exportMappedData
 * @covers \SmartAuth\DolibarrMapping\dmTrait::importMappedData
 */
class MapperRoundTripLotATest extends DolibarrRealTestCase
{
    /* -----------------------------------------------------------------
     * dmCategory
     * --------------------------------------------------------------- */

    public function testDmCategoryRoundTripExport(): void
    {
        // Use 'customer' (type=2) instead of 'product' (type=0): the
        // dmTrait exporter filters out !empty() values, so type=0 would
        // simply not appear in the payload at all. Picking a non-zero
        // type lets us assert the field round-trips correctly.
        $category = new \Categorie($this->db);
        $category->label       = 'Lot-A Category ' . uniqid();
        $category->description = 'A customer category for round-trip test';
        $category->color       = 'ff0000';
        $category->type        = 'customer';
        $category->visible     = 1;

        $id = $category->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'failed to create test categorie: ' . $category->error);

        $fresh = new \Categorie($this->db);
        $fresh->fetch($id);

        $mapper  = new dmCategory();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $id);
        $this->assertApiKeyEquals($payload, 'label', $category->label);
        $this->assertApiKeyEquals($payload, 'color', 'ff0000');
        $this->assertApiKeyEquals($payload, 'visible', 1);
        // type=2 corresponds to 'customer' in Categorie::MAP_ID.
        $this->assertApiKeyEquals($payload, 'type', 2);
    }

    public function testDmCategoryImportRejectsReadOnlyId(): void
    {
        $mapper = new dmCategory();

        try {
            // 'id' is exposed but NOT writable (the rowid is assigned by
            // Dolibarr at create time). A payload trying to overwrite it
            // must be rejected.
            $mapper->importMappedData(['id' => 42]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDmCategoryImportRejectsTimestamps(): void
    {
        $mapper = new dmCategory();

        // created_at / updated_at are exposed read-only -- they are filled
        // by Dolibarr triggers, never by a client payload.
        try {
            $mapper->importMappedData([
                'created_at' => 12345,
                'updated_at' => 67890,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('created_at', $errors);
            $this->assertArrayHasKey('updated_at', $errors);
        }
    }

    /* -----------------------------------------------------------------
     * dmMemberType
     * --------------------------------------------------------------- */

    public function testDmMemberTypeRoundTripExport(): void
    {
        $type = $this->createMemberType('Lot-A MemberType ' . uniqid());

        $fresh = new \AdherentType($this->db);
        $fresh->fetch($type->id);

        $mapper  = new dmMemberType();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $type->id);
        $this->assertApiKeyEquals($payload, 'label', $type->label);
        // 'morphy' is stored verbatim ('phy', 'mor' or empty)
        $this->assertApiKeyEquals($payload, 'nature', 'phy');
    }

    public function testDmMemberTypeImportRejectsStatusChange(): void
    {
        $mapper = new dmMemberType();

        // 'status' is intentionally excluded from writableFields (state machine).
        try {
            $mapper->importMappedData(['status' => 0]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
    }

    /* -----------------------------------------------------------------
     * dmMember
     * --------------------------------------------------------------- */

    public function testDmMemberRoundTripExport(): void
    {
        $type   = $this->createMemberType('Lot-A MemberType for Member ' . uniqid());
        $member = $this->createMember($type->id, [
            'login'     => 'lota_' . uniqid(),
            'lastname'  => 'Doe',
            'firstname' => 'Jane',
            'email'     => 'jane_' . uniqid() . '@example.com',
        ]);

        $fresh = new \Adherent($this->db);
        $fresh->fetch($member->id);

        $mapper  = new dmMember();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $member->id);
        $this->assertApiKeyEquals($payload, 'lastname', 'Doe');
        $this->assertApiKeyEquals($payload, 'firstname', 'Jane');
        $this->assertApiKeyEquals($payload, 'login', $member->login);
        // typeid is renamed to 'member_type' on the API side
        $this->assertApiKeyEquals($payload, 'member_type', $type->id);
    }

    public function testDmMemberImportRejectsStatusAndDates(): void
    {
        $mapper = new dmMember();

        // 'status', 'subscription_end', 'validated_at' are exposed but not
        // writable (state machine + Dolibarr-managed timestamps).
        try {
            $mapper->importMappedData([
                'status'           => 1,
                'subscription_end' => 9999999,
                'validated_at'     => 12345,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('status', $errors);
            $this->assertArrayHasKey('subscription_end', $errors);
            $this->assertArrayHasKey('validated_at', $errors);
        }
    }

    /* -----------------------------------------------------------------
     * dmSubscription
     * --------------------------------------------------------------- */

    public function testDmSubscriptionRoundTripExport(): void
    {
        $type   = $this->createMemberType('Lot-A MemberType for Sub ' . uniqid());
        $member = $this->createMember($type->id, [
            'login' => 'lotasub_' . uniqid(),
        ]);

        $dateStart = mktime(0, 0, 0, 1, 1, 2026);
        $dateEnd   = mktime(0, 0, 0, 12, 31, 2026);

        $subscription              = new \Subscription($this->db);
        $subscription->fk_adherent = $member->id;
        $subscription->fk_type     = $type->id;
        $subscription->dateh       = $dateStart;
        $subscription->datef       = $dateEnd;
        $subscription->amount      = 42.50;
        $subscription->note_public = 'Lot-A subscription round-trip';

        $subId = $subscription->create($this->testUser);
        $this->assertGreaterThan(0, $subId, 'failed to create test subscription: ' . $subscription->error);

        $fresh = new \Subscription($this->db);
        $fresh->fetch($subId);

        $mapper  = new dmSubscription();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $subId);
        $this->assertApiKeyEquals($payload, 'member', $member->id);
        $this->assertApiKeyEquals($payload, 'member_type', $type->id);
        $this->assertApiKeyEquals($payload, 'amount', 42.50);
        // Subscription::create stores the note in the 'note' column and
        // re-reads it as $note_public via fetch(). dmSubscription maps the
        // doliside column 'note' to api 'note', so the value surfaces here.
        $this->assertApiKeyEquals($payload, 'note', 'Lot-A subscription round-trip');
    }

    public function testDmSubscriptionImportRejectsReadOnlyTimestamps(): void
    {
        $mapper = new dmSubscription();

        // created_at / updated_at are exposed read-only.
        try {
            $mapper->importMappedData([
                'created_at' => 1000,
                'updated_at' => 2000,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('created_at', $errors);
            $this->assertArrayHasKey('updated_at', $errors);
        }
    }

    /* -----------------------------------------------------------------
     * dmDonation
     * --------------------------------------------------------------- */

    public function testDmDonationRoundTripExport(): void
    {
        // The shipped SQLite snapshot does not include llx_don (the Don
        // module is not activated in the vendor fixture). Create it on
        // the fly the first time we need it -- this is a real schema
        // round-trip, not a stub.
        $this->ensureDonTableExists();

        $now = dol_now();

        $don              = new \Don($this->db);
        $don->date        = $now;
        $don->amount      = 123.45;
        $don->societe     = 'Lot-A Donor Co.';
        $don->firstname   = 'John';
        $don->lastname    = 'Donor';
        $don->email       = 'john_' . uniqid() . '@example.com';
        $don->public      = 1;
        $don->country_id  = 0;
        $don->note_public = 'Lot-A donation note';

        $donId = $don->create($this->testUser);
        $this->assertGreaterThan(0, $donId, 'failed to create test don: ' . $don->error);

        $fresh = new \Don($this->db);
        $fresh->fetch($donId);

        $mapper  = new dmDonation();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $donId);
        $this->assertApiKeyEquals($payload, 'amount', 123.45);
        $this->assertApiKeyEquals($payload, 'lastname', 'Donor');
        $this->assertApiKeyEquals($payload, 'firstname', 'John');
        $this->assertApiKeyEquals($payload, 'company_name', 'Lot-A Donor Co.');
        $this->assertApiKeyEquals($payload, 'public_note', 'Lot-A donation note');
    }

    public function testDmDonationImportRejectsStatusAndValidationFields(): void
    {
        $mapper = new dmDonation();

        // status, paid, validated_at, validated_by are exposed read-only:
        // the donation lifecycle (validate, mark as paid) goes through
        // Dolibarr-managed transitions, never through importMappedData.
        try {
            $mapper->importMappedData([
                'status'       => 1,
                'paid'         => 1,
                'validated_at' => 12345,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('status', $errors);
            $this->assertArrayHasKey('paid', $errors);
            $this->assertArrayHasKey('validated_at', $errors);
        }
    }

    /* -----------------------------------------------------------------
     * dmMulticurrency
     * --------------------------------------------------------------- */

    public function testDmMulticurrencyRoundTripExport(): void
    {
        // Use a random 3-letter "code" to avoid collisions with existing
        // currencies (EUR / USD / ...) -- MultiCurrency::create rejects
        // duplicates via checkCodeAlreadyExists.
        $code = 'X' . strtoupper(substr(uniqid(), -2));

        $mc       = new \MultiCurrency($this->db);
        $mc->code = $code;
        $mc->name = 'Lot-A Currency ' . uniqid();

        $mcId = $mc->create($this->testUser);
        $this->assertGreaterThan(0, $mcId, 'failed to create test multicurrency: ' . $mc->error);

        $fresh = new \MultiCurrency($this->db);
        $fresh->fetch($mcId);

        $mapper  = new dmMulticurrency();
        $payload = $mapper->exportMappedData($fresh);

        $this->assertApiKeyEquals($payload, 'id', $mcId);
        $this->assertApiKeyEquals($payload, 'code', $code);
        $this->assertApiKeyEquals($payload, 'name', $mc->name);
        // The mapper does NOT expose 'rate': MultiCurrency::fetch() leaves
        // $this->rate empty (rates live in llx_multicurrency_rate, fetched
        // via fetchAllCurrencyRate) and the property is a CurrencyRate
        // OBJECT when populated, not a numeric value. A flat mapping would
        // mislead consumers either way.
        $this->assertObjectNotHasProperty('rate', $payload);
    }

    public function testDmMulticurrencyImportRejectsCreatedByAndDate(): void
    {
        $mapper = new dmMulticurrency();

        // created_at and created_by are exposed but stamped by Dolibarr.
        try {
            $mapper->importMappedData([
                'created_at' => 1000,
                'created_by' => 1,
            ]);
            $this->fail('Expected MapperValidationException');
        } catch (MapperValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('created_at', $errors);
            $this->assertArrayHasKey('created_by', $errors);
        }
    }

    /* -----------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------- */

    /**
     * Lazily create the llx_don table if it is missing from the SQLite
     * test snapshot. We use Dolibarr's run_sql() so the MySQL DDL is
     * converted to SQLite syntax through the same code path as a real
     * module install.
     */
    private function ensureDonTableExists(): void
    {
        $resql = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='" . MAIN_DB_PREFIX . "don'"
        );
        if ($resql && $this->db->fetch_object($resql)) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $vendorSqlDir = dirname(__DIR__, 3)
            . '/vendor/cap-rel/dolibarr-integration-sqlite/htdocs/install/mysql/tables/';

        $tableFile = $vendorSqlDir . 'llx_don-don.sql';
        if (!is_file($tableFile)) {
            throw new \RuntimeException("Missing fixture SQL file: $tableFile");
        }

        // run_sql() returns >=1 on success.
        $r = run_sql($tableFile, 1, 0, 1, '', 'default', 32768, 0, 0, 0, 0, '');
        if ($r <= 0) {
            throw new \RuntimeException('Failed to create llx_don table via run_sql()');
        }
    }

    /**
     * Build an AdherentType row. AdherentType::create() inserts only
     * morphy/libelle/entity; everything else (status, vote, mail_valid,
     * caneditamount, duration, amount, subscription) is then pushed by
     * the immediate update() call. We must therefore pre-populate the
     * scalar fields update() reads, otherwise it interpolates empty
     * strings into SQL number columns and either crashes (vote int) or
     * silently inserts garbage (subscription).
     */
    private function createMemberType(string $label): \AdherentType
    {
        $type                 = new \AdherentType($this->db);
        $type->label          = $label;
        $type->morphy         = 'phy';
        $type->status         = 1;
        $type->subscription   = 1;
        $type->amount         = 10.0;
        $type->caneditamount  = 0;
        $type->vote           = 0;
        $type->duration_value = '1';
        $type->duration_unit  = 'y';
        $type->mail_valid     = '';
        $type->note_public    = '';

        $id = $type->create($this->testUser);
        if ($id <= 0) {
            throw new \RuntimeException('Failed to create AdherentType: ' . $type->error);
        }
        return $type;
    }

    /**
     * Build an Adherent row attached to the given AdherentType.
     */
    private function createMember(int $typeId, array $overrides = []): \Adherent
    {
        $member            = new \Adherent($this->db);
        $member->login     = $overrides['login']     ?? 'lota_' . uniqid();
        $member->lastname  = $overrides['lastname']  ?? 'Doe';
        $member->firstname = $overrides['firstname'] ?? 'John';
        $member->email     = $overrides['email']     ?? 'lota_' . uniqid() . '@example.com';
        $member->morphy    = $overrides['morphy']    ?? 'phy';
        $member->typeid    = $typeId;
        $member->public    = 0;

        $id = $member->create($this->testUser);
        if ($id <= 0) {
            throw new \RuntimeException('Failed to create Adherent: ' . $member->error);
        }
        return $member;
    }

    /**
     * Helper: assert that a key exists on the export payload and equals
     * the expected value. exportMappedData returns a stdClass so we
     * normalize to property access.
     */
    private function assertApiKeyEquals(\stdClass $payload, string $apiKey, $expected): void
    {
        $this->assertObjectHasProperty(
            $apiKey,
            $payload,
            "Export payload missing api key '$apiKey'. Got keys: "
                . implode(',', array_keys((array) $payload))
        );

        if ($expected !== null) {
            $this->assertEquals($expected, $payload->{$apiKey});
        }
    }
}
