<?php

/**
 * Tests for dmThirdparty mapping functionality
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmThirdparty;
use SmartAuth\DolibarrMapping\dmSociete;
use SmartAuth\DolibarrMapping\dmBase;
use SmartAuth\DolibarrMapping\dmTrait;
use SmartAuth\DolibarrMapping\dmHelper;
use ReflectionClass;

/**
 * @covers \SmartAuth\DolibarrMapping\dmThirdparty
 * @covers \SmartAuth\DolibarrMapping\dmSociete
 */
class DmThirdpartyTest extends DolibarrRealTestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new dmThirdparty();
    }

    /**
     * Test dmThirdparty instantiation
     */
    public function testDmThirdpartyInstantiation(): void
    {
        $this->assertInstanceOf(dmThirdparty::class, $this->mapper);
    }

    /**
     * Test dmThirdparty class exists in real code
     */
    public function testDmThirdpartyClassExists(): void
    {
        $this->assertTrue(class_exists('SmartAuth\DolibarrMapping\dmThirdparty'));
    }

    /**
     * Test backward-compat alias dmSociete still resolves to dmThirdparty
     */
    public function testDmSocieteAliasResolves(): void
    {
        $this->assertTrue(class_exists('SmartAuth\DolibarrMapping\dmSociete'));
        $alias = new \SmartAuth\DolibarrMapping\dmSociete();
        $this->assertInstanceOf(dmThirdparty::class, $alias);
    }

    /**
     * Test objectType returns correct type
     */
    public function testObjectTypeReturnsObject(): void
    {
        $type = $this->mapper->objectType();
        $this->assertEquals('object', $type);
    }

    /**
     * Test objectDesc returns cached description
     */
    public function testObjectDescReturnsCachedDescription(): void
    {
        $desc = $this->mapper->objectDesc();
        $this->assertInstanceOf(\stdClass::class, $desc);
    }

    /**
     * Test listOfPublishedFields contains expected fields
     */
    public function testListOfPublishedFieldsContainsExpectedFields(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $fields = $property->getValue($this->mapper);

        // Identifiers
        $this->assertArrayHasKey('rowid', $fields);
        $this->assertEquals('id', $fields['rowid']);

        // Name -- declared with the PHP property name 'name' (not the
        // SQL column 'nom'); see comment on dmThirdparty::$listOfPublishedFields.
        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('name', $fields['name']);
        $this->assertArrayNotHasKey('nom', $fields);

        // Address fields
        $this->assertArrayHasKey('address', $fields);
        $this->assertEquals('address', $fields['address']);

        $this->assertArrayHasKey('zip', $fields);
        $this->assertEquals('zip', $fields['zip']);

        $this->assertArrayHasKey('town', $fields);
        $this->assertEquals('city', $fields['town']);

        $this->assertArrayHasKey('fk_departement', $fields);
        $this->assertEquals('state', $fields['fk_departement']);

        $this->assertArrayHasKey('fk_pays', $fields);
        $this->assertEquals('country', $fields['fk_pays']);

        // Contact info
        $this->assertArrayHasKey('phone', $fields);
        $this->assertEquals('phone', $fields['phone']);

        $this->assertArrayHasKey('url', $fields);
        $this->assertEquals('website', $fields['url']);

        $this->assertArrayHasKey('email', $fields);
        $this->assertEquals('email', $fields['email']);

        // Notes
        $this->assertArrayHasKey('note_public', $fields);
        $this->assertEquals('public_note', $fields['note_public']);

        $this->assertArrayHasKey('note_private', $fields);
        $this->assertEquals('private_note', $fields['note_private']);

        // logo is NOT in listOfPublishedFields anymore: it moved to
        // listOfDerivedFields since 2.1.0 (URL-based transport).
        $this->assertArrayNotHasKey('logo', $fields);
    }

    /**
     * Test listOfDerivedFields advertises the three logo variants
     */
    public function testListOfDerivedFieldsExposesLogoVariants(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfDerivedFields');
        $property->setAccessible(true);
        $derived = $property->getValue($this->mapper);

        $this->assertArrayHasKey('logo', $derived);
        $this->assertEquals('logo', $derived['logo']);

        $this->assertArrayHasKey('logo_mini', $derived);
        $this->assertEquals('logo_mini', $derived['logo_mini']);

        // logo_data_url is the deprecated legacy base64 transport.
        // Will be removed in smartauth 2.2.0.
        $this->assertArrayHasKey('logo_data_url', $derived);
        $this->assertEquals('logo_data_url', $derived['logo_data_url']);
    }

    /**
     * Test fieldFilterValueLogo returns the relative JWT binary URL
     */
    public function testFieldFilterValueLogoReturnsRelativeUrl(): void
    {
        $societe = new \stdClass();
        $societe->id = 42;
        $societe->entity = 1;
        $societe->logo = 'company_logo.png';

        $result = $this->mapper->fieldFilterValueLogo($societe);

        $this->assertSame('media/thirdparty/42/logo', $result);
    }

    /**
     * Test fieldFilterValueLogoMini returns the mini variant URL
     */
    public function testFieldFilterValueLogoMiniReturnsRelativeUrl(): void
    {
        $societe = new \stdClass();
        $societe->id = 42;
        $societe->entity = 1;
        $societe->logo = 'company_logo.png';

        $result = $this->mapper->fieldFilterValueLogoMini($societe);

        $this->assertSame('media/thirdparty/42/logo/mini', $result);
    }

    /**
     * Test fieldFilterValueLogo returns null when no logo is set
     */
    public function testFieldFilterValueLogoReturnsNullWhenLogoEmpty(): void
    {
        $societe = new \stdClass();
        $societe->id = 7;
        $societe->entity = 1;
        $societe->logo = '';

        $this->assertNull($this->mapper->fieldFilterValueLogo($societe));
    }

    /**
     * Test fieldFilterValueLogo returns null when id is missing
     */
    public function testFieldFilterValueLogoReturnsNullWhenIdEmpty(): void
    {
        $societe = new \stdClass();
        $societe->id = 0;
        $societe->entity = 1;
        $societe->logo = 'company_logo.png';

        $this->assertNull($this->mapper->fieldFilterValueLogo($societe));
    }

    /**
     * Test fieldFilterValueLogoMini returns null when no logo is set
     */
    public function testFieldFilterValueLogoMiniReturnsNullWhenLogoEmpty(): void
    {
        $societe = new \stdClass();
        $societe->id = 7;
        $societe->entity = 1;
        $societe->logo = '';

        $this->assertNull($this->mapper->fieldFilterValueLogoMini($societe));
    }

    /**
     * Test type property is set correctly
     */
    public function testTypePropertyIsObject(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('type');
        $property->setAccessible(true);
        $type = $property->getValue($this->mapper);

        $this->assertEquals('object', $type);
    }

    /**
     * Test dolibarrClassName is set to Societe
     */
    public function testDolibarrClassNameIsSociete(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('dolibarrClassName');
        $property->setAccessible(true);
        $className = $property->getValue($this->mapper);

        $this->assertSame('Societe', $className);
    }

    /**
     * Test field mapping count
     */
    public function testFieldMappingCount(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $fields = $property->getValue($this->mapper);

        // 12 mapped fields after logo moved to listOfDerivedFields in 2.1.0
        $this->assertCount(12, $fields);
    }

    /**
     * Test that town maps to city (API naming convention)
     */
    public function testTownMapsToCity(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $fields = $property->getValue($this->mapper);

        $this->assertEquals('city', $fields['town']);
    }

    /**
     * Test that the 'name' PHP property maps to the 'name' API key
     * (API naming convention). The Societe SQL column is 'nom' but
     * the mapper addresses the PHP property name that Dolibarr's fetch
     * populates -- see comment on dmThirdparty::$listOfPublishedFields.
     */
    public function testNamePropertyMapsToNameApiKey(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $fields = $property->getValue($this->mapper);

        $this->assertEquals('name', $fields['name']);
    }

    /**
     * Test that url maps to website (API naming convention)
     */
    public function testUrlMapsToWebsite(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);
        $fields = $property->getValue($this->mapper);

        $this->assertEquals('website', $fields['url']);
    }

    /**
     * Test writableFields advertises the expected import allowlist
     */
    public function testWritableFieldsAdvertisesImportAllowlist(): void
    {
        $reflection = new ReflectionClass($this->mapper);
        $property = $reflection->getProperty('writableFields');
        $property->setAccessible(true);
        $writable = $property->getValue($this->mapper);

        // PHP property names (see comment on dmThirdparty::$writableFields).
        $this->assertContains('name', $writable);
        $this->assertNotContains('nom', $writable);
        $this->assertContains('address', $writable);
        $this->assertContains('zip', $writable);
        $this->assertContains('town', $writable);
        $this->assertContains('fk_departement', $writable);
        $this->assertContains('fk_pays', $writable);
        $this->assertContains('phone', $writable);
        $this->assertContains('url', $writable);
        $this->assertContains('email', $writable);
        $this->assertContains('note_public', $writable);
        $this->assertContains('note_private', $writable);

        // rowid must never be writable through importMappedData
        $this->assertNotContains('rowid', $writable);
        // logo writes go through a dedicated upload route, not import
        $this->assertNotContains('logo', $writable);
    }
}
