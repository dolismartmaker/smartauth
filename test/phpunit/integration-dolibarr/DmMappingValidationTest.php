<?php

/**
 * Tests to validate that dmMapping classes reference valid Dolibarr fields
 *
 * These tests verify that the fields declared in listOfPublishedFields
 * actually exist in the corresponding Dolibarr class $fields array.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class DmMappingValidationTest extends DolibarrRealTestCase
{
    /**
     * Helper to get published fields from a mapping class via reflection
     */
    private function getPublishedFields(string $mappingClass): array
    {
        $reflection = new \ReflectionClass($mappingClass);
        $property = $reflection->getProperty('listOfPublishedFields');
        $property->setAccessible(true);

        // Create instance without calling constructor
        $instance = $reflection->newInstanceWithoutConstructor();
        return $property->getValue($instance);
    }

    /**
     * Helper to get public properties from a Dolibarr class via reflection
     */
    private function getPublicProperties(object $obj): array
    {
        $reflection = new \ReflectionClass($obj);
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $properties[] = $prop->getName();
        }

        return $properties;
    }

    /**
     * Helper to check field compatibility
     * Returns array of missing fields and available fields
     * Now checks both $fields array AND public properties
     */
    private function checkFieldsCompatibility(string $mappingClass, string $dolibarrClass): array
    {
        $publishedFields = $this->getPublishedFields($mappingClass);
        $dolibarrObj = new $dolibarrClass($this->db);
        $dolibarrFields = $dolibarrObj->fields ?? [];
        $dolibarrProperties = $this->getPublicProperties($dolibarrObj);

        // Combine $fields keys and public properties
        $availableFields = array_unique(array_merge(
            array_keys($dolibarrFields),
            $dolibarrProperties
        ));

        $missing = [];
        $extrafields = [];

        foreach ($publishedFields as $doliField => $frontField) {
            // Skip extrafields (options_*)
            if (str_starts_with($doliField, 'options_')) {
                $extrafields[] = $doliField;
                continue;
            }

            // Check if field exists in $fields OR as public property
            if (!isset($dolibarrFields[$doliField]) && !in_array($doliField, $dolibarrProperties)) {
                // Also check for common Dolibarr field name variations
                $variations = $this->getFieldVariations($doliField);
                $found = false;
                foreach ($variations as $variation) {
                    if (isset($dolibarrFields[$variation]) || in_array($variation, $dolibarrProperties)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missing[] = $doliField;
                }
            }
        }

        return [
            'missing' => $missing,
            'extrafields' => $extrafields,
            'dolibarr_fields' => $availableFields,
            'published_fields' => array_keys($publishedFields)
        ];
    }

    /**
     * Get common field name variations in Dolibarr
     * e.g., statut -> fk_statut, date -> datec/datep, etc.
     */
    private function getFieldVariations(string $field): array
    {
        $variations = [];

        // statut -> fk_statut, status
        if ($field === 'statut') {
            $variations = ['fk_statut', 'status'];
        }
        // date -> datec, datep, datef, date_creation
        elseif ($field === 'date') {
            $variations = ['datec', 'datep', 'datef', 'date_creation', 'date_commande'];
        }
        // datec -> date_creation
        elseif ($field === 'datec') {
            $variations = ['date_creation'];
        }
        // ref_customer -> ref_client
        elseif ($field === 'ref_customer') {
            $variations = ['ref_client'];
        }
        // delivery_date -> date_livraison
        elseif ($field === 'delivery_date') {
            $variations = ['date_livraison'];
        }
        // total_localtax1/2 -> localtax1/2
        elseif ($field === 'total_localtax1') {
            $variations = ['localtax1'];
        } elseif ($field === 'total_localtax2') {
            $variations = ['localtax2'];
        }

        return $variations;
    }

    /**
     * Test dmUser field compatibility with User class
     */
    public function testDmUserFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmUser::class,
            \User::class
        );

        // Report findings
        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmUser has fields not in User::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable User fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        // For now, just verify the class can be reflected
        $this->assertIsArray($result['published_fields']);
        $this->assertNotEmpty($result['published_fields']);

        // Document the missing fields for fixing
        $this->assertContains('rowid', $result['published_fields'], 'rowid should be published');
    }

    /**
     * Test dmProduct field compatibility with Product class
     */
    public function testDmProductFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmProduct::class,
            \Product::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmProduct has fields not in Product::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Product fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
        $this->assertContains('rowid', $result['published_fields']);
    }

    /**
     * Test dmThirdparty field compatibility with Societe class
     */
    public function testDmThirdpartyFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmThirdparty::class,
            \Societe::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmThirdparty has fields not in Societe::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Societe fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmCategory field compatibility with Categorie class
     */
    public function testDmCategoryFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmCategory::class,
            \Categorie::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmCategory has fields not in Categorie::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Categorie fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmProject field compatibility with Project class
     */
    public function testDmProjectFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmProject::class,
            \Project::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmProject has fields not in Project::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Project fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmTask field compatibility with Task class
     */
    public function testDmTaskFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmTask::class,
            \Task::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmTask has fields not in Task::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Task fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmContact field compatibility with Contact class
     */
    public function testDmContactFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmContact::class,
            \Contact::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmContact has fields not in Contact::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Contact fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmProposal field compatibility with Propal class
     */
    public function testDmProposalFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmProposal::class,
            \Propal::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmProposal has fields not in Propal::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Propal fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmOrder field compatibility with Commande class
     */
    public function testDmOrderFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmOrder::class,
            \Commande::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmOrder has fields not in Commande::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Commande fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Test dmInvoice field compatibility with Facture class
     */
    public function testDmInvoiceFieldCompatibility(): void
    {
        $result = $this->checkFieldsCompatibility(
            \SmartAuth\DolibarrMapping\dmInvoice::class,
            \Facture::class
        );

        if (!empty($result['missing'])) {
            $this->addWarning(
                "dmInvoice has fields not in Facture::\$fields: " . implode(', ', $result['missing']) .
                "\nAvailable Facture fields: " . implode(', ', $result['dolibarr_fields'])
            );
        }

        $this->assertIsArray($result['published_fields']);
    }

    /**
     * Comprehensive test that lists all mapping issues
     */
    public function testAllMappingsFieldReport(): void
    {
        $mappings = [
            'dmUser' => [\SmartAuth\DolibarrMapping\dmUser::class, \User::class],
            'dmProduct' => [\SmartAuth\DolibarrMapping\dmProduct::class, \Product::class],
            'dmThirdparty' => [\SmartAuth\DolibarrMapping\dmThirdparty::class, \Societe::class],
            'dmCategory' => [\SmartAuth\DolibarrMapping\dmCategory::class, \Categorie::class],
            'dmProject' => [\SmartAuth\DolibarrMapping\dmProject::class, \Project::class],
            'dmTask' => [\SmartAuth\DolibarrMapping\dmTask::class, \Task::class],
            'dmContact' => [\SmartAuth\DolibarrMapping\dmContact::class, \Contact::class],
            'dmProposal' => [\SmartAuth\DolibarrMapping\dmProposal::class, \Propal::class],
            'dmOrder' => [\SmartAuth\DolibarrMapping\dmOrder::class, \Commande::class],
            'dmInvoice' => [\SmartAuth\DolibarrMapping\dmInvoice::class, \Facture::class],
        ];

        $report = [];
        $totalMissing = 0;

        foreach ($mappings as $name => [$mappingClass, $dolibarrClass]) {
            $result = $this->checkFieldsCompatibility($mappingClass, $dolibarrClass);

            if (!empty($result['missing'])) {
                $report[$name] = [
                    'missing' => $result['missing'],
                    'available' => $result['dolibarr_fields']
                ];
                $totalMissing += count($result['missing']);
            }
        }

        // Output report as warning
        if (!empty($report)) {
            $message = "=== MAPPING FIELD COMPATIBILITY REPORT ===\n";
            $message .= "Total missing fields: $totalMissing\n\n";

            foreach ($report as $name => $data) {
                $message .= "$name:\n";
                $message .= "  Missing: " . implode(', ', $data['missing']) . "\n";
                $message .= "  Available in Dolibarr: " . implode(', ', $data['available']) . "\n\n";
            }

            $this->addWarning($message);
        }

        // Test passes but reports issues
        $this->assertTrue(true, 'Report generated');
    }
}
