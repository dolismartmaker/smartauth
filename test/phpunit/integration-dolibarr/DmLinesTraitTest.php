<?php

/**
 * Tests for dmLinesTrait mapping functionality
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Tests\IntegrationDolibarr;

use SmartAuth\DolibarrMapping\dmLinesTrait;

/**
 * Concrete class using dmLinesTrait for testing
 */
class TestDmLinesMapper
{
    use dmLinesTrait;

    /**
     * Expose protected method for testing
     */
    public function testGetCommonLinesMapping(): array
    {
        return $this->getCommonLinesMapping();
    }

    public function testGetInvoiceLinesMapping(): array
    {
        return $this->getInvoiceLinesMapping();
    }

    public function testGetProposalLinesMapping(): array
    {
        return $this->getProposalLinesMapping();
    }

    public function testGetOrderLinesMapping(): array
    {
        return $this->getOrderLinesMapping();
    }

    public function testGetSupplierInvoiceLinesMapping(): array
    {
        return $this->getSupplierInvoiceLinesMapping();
    }

    public function testGetSupplierOrderLinesMapping(): array
    {
        return $this->getSupplierOrderLinesMapping();
    }

    public function testGetSupplierProposalLinesMapping(): array
    {
        return $this->getSupplierProposalLinesMapping();
    }

    public function testGetShipmentLinesMapping(): array
    {
        return $this->getShipmentLinesMapping();
    }

    public function testGetReceptionLinesMapping(): array
    {
        return $this->getReceptionLinesMapping();
    }

    public function testGetExpenseReportLinesMapping(): array
    {
        return $this->getExpenseReportLinesMapping();
    }

    public function testGetContractLinesMapping(): array
    {
        return $this->getContractLinesMapping();
    }

    public function testGetInterventionLinesMapping(): array
    {
        return $this->getInterventionLinesMapping();
    }

    public function testGetBomLinesMapping(): array
    {
        return $this->getBomLinesMapping();
    }

    public function testGetMoLinesMapping(): array
    {
        return $this->getMoLinesMapping();
    }
}

class DmLinesTraitTest extends DolibarrRealTestCase
{
    private $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TestDmLinesMapper();
    }

    /**
     * Test common lines mapping contains expected identifiers
     */
    public function testCommonLinesMappingContainsIdentifiers(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('rowid', $mapping);
        $this->assertEquals('id', $mapping['rowid']);

        $this->assertArrayHasKey('rang', $mapping);
        $this->assertEquals('position', $mapping['rang']);

        $this->assertArrayHasKey('fk_parent_line', $mapping);
        $this->assertEquals('parent_line_id', $mapping['fk_parent_line']);
    }

    /**
     * Test common lines mapping contains product references
     */
    public function testCommonLinesMappingContainsProductReferences(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('fk_product', $mapping);
        $this->assertEquals('product', $mapping['fk_product']);

        $this->assertArrayHasKey('product_type', $mapping);
        $this->assertEquals('product_type', $mapping['product_type']);

        $this->assertArrayHasKey('product_ref', $mapping);
        $this->assertEquals('product_ref', $mapping['product_ref']);

        $this->assertArrayHasKey('product_label', $mapping);
        $this->assertEquals('product_label', $mapping['product_label']);

        $this->assertArrayHasKey('product_barcode', $mapping);
        $this->assertEquals('product_barcode', $mapping['product_barcode']);
    }

    /**
     * Test common lines mapping contains description fields
     */
    public function testCommonLinesMappingContainsDescriptionFields(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('desc', $mapping);
        $this->assertEquals('description', $mapping['desc']);

        $this->assertArrayHasKey('label', $mapping);
        $this->assertEquals('label', $mapping['label']);
    }

    /**
     * Test common lines mapping contains quantity and pricing
     */
    public function testCommonLinesMappingContainsQuantityAndPricing(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('qty', $mapping);
        $this->assertEquals('quantity', $mapping['qty']);

        $this->assertArrayHasKey('subprice', $mapping);
        $this->assertEquals('unit_price_excl_tax', $mapping['subprice']);

        $this->assertArrayHasKey('remise_percent', $mapping);
        $this->assertEquals('discount_percent', $mapping['remise_percent']);
    }

    /**
     * Test common lines mapping contains VAT fields
     */
    public function testCommonLinesMappingContainsVatFields(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('tva_tx', $mapping);
        $this->assertEquals('vat_rate', $mapping['tva_tx']);

        $this->assertArrayHasKey('vat_src_code', $mapping);
        $this->assertEquals('vat_code', $mapping['vat_src_code']);

        $this->assertArrayHasKey('localtax1_tx', $mapping);
        $this->assertEquals('local_tax1_rate', $mapping['localtax1_tx']);

        $this->assertArrayHasKey('localtax2_tx', $mapping);
        $this->assertEquals('local_tax2_rate', $mapping['localtax2_tx']);
    }

    /**
     * Test common lines mapping contains totals
     */
    public function testCommonLinesMappingContainsTotals(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('total_ht', $mapping);
        $this->assertEquals('total_excl_tax', $mapping['total_ht']);

        $this->assertArrayHasKey('total_tva', $mapping);
        $this->assertEquals('total_vat', $mapping['total_tva']);

        $this->assertArrayHasKey('total_ttc', $mapping);
        $this->assertEquals('total_incl_tax', $mapping['total_ttc']);
    }

    /**
     * Test common lines mapping contains margin fields
     */
    public function testCommonLinesMappingContainsMarginFields(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('pa_ht', $mapping);
        $this->assertEquals('buy_price_excl_tax', $mapping['pa_ht']);

        $this->assertArrayHasKey('marge_tx', $mapping);
        $this->assertEquals('margin_rate', $mapping['marge_tx']);

        $this->assertArrayHasKey('marque_tx', $mapping);
        $this->assertEquals('markup_rate', $mapping['marque_tx']);
    }

    /**
     * Test common lines mapping contains multicurrency fields
     */
    public function testCommonLinesMappingContainsMulticurrencyFields(): void
    {
        $mapping = $this->mapper->testGetCommonLinesMapping();

        $this->assertArrayHasKey('fk_multicurrency', $mapping);
        $this->assertEquals('multicurrency_id', $mapping['fk_multicurrency']);

        $this->assertArrayHasKey('multicurrency_code', $mapping);
        $this->assertEquals('multicurrency_code', $mapping['multicurrency_code']);

        $this->assertArrayHasKey('multicurrency_total_ht', $mapping);
        $this->assertEquals('multicurrency_total_excl_tax', $mapping['multicurrency_total_ht']);
    }

    /**
     * Test invoice lines mapping extends common mapping
     */
    public function testInvoiceLinesMappingExtendsCommonMapping(): void
    {
        $invoiceMapping = $this->mapper->testGetInvoiceLinesMapping();
        $commonMapping = $this->mapper->testGetCommonLinesMapping();

        // Should contain all common fields
        foreach ($commonMapping as $key => $value) {
            $this->assertArrayHasKey($key, $invoiceMapping);
            $this->assertEquals($value, $invoiceMapping[$key]);
        }

        // Should have invoice-specific fields
        $this->assertArrayHasKey('fk_facture', $invoiceMapping);
        $this->assertEquals('invoice_id', $invoiceMapping['fk_facture']);

        $this->assertArrayHasKey('situation_percent', $invoiceMapping);
        $this->assertEquals('situation_percent', $invoiceMapping['situation_percent']);

        $this->assertArrayHasKey('fk_prev_id', $invoiceMapping);
        $this->assertEquals('previous_situation_line_id', $invoiceMapping['fk_prev_id']);
    }

    /**
     * Test proposal lines mapping extends common mapping
     */
    public function testProposalLinesMappingExtendsCommonMapping(): void
    {
        $proposalMapping = $this->mapper->testGetProposalLinesMapping();
        $commonMapping = $this->mapper->testGetCommonLinesMapping();

        // Should contain all common fields
        foreach ($commonMapping as $key => $value) {
            $this->assertArrayHasKey($key, $proposalMapping);
        }

        // Should have proposal-specific fields
        $this->assertArrayHasKey('fk_propal', $proposalMapping);
        $this->assertEquals('proposal_id', $proposalMapping['fk_propal']);

        $this->assertArrayHasKey('product_tobatch', $proposalMapping);
        $this->assertEquals('product_batch_enabled', $proposalMapping['product_tobatch']);
    }

    /**
     * Test order lines mapping extends common mapping
     */
    public function testOrderLinesMappingExtendsCommonMapping(): void
    {
        $orderMapping = $this->mapper->testGetOrderLinesMapping();

        // Should have order-specific fields
        $this->assertArrayHasKey('fk_commande', $orderMapping);
        $this->assertEquals('order_id', $orderMapping['fk_commande']);

        $this->assertArrayHasKey('ref_ext', $orderMapping);
        $this->assertEquals('external_ref', $orderMapping['ref_ext']);
    }

    /**
     * Test supplier invoice lines mapping
     */
    public function testSupplierInvoiceLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetSupplierInvoiceLinesMapping();

        $this->assertArrayHasKey('fk_facture_fourn', $mapping);
        $this->assertEquals('supplier_invoice_id', $mapping['fk_facture_fourn']);

        $this->assertArrayHasKey('fk_code_ventilation', $mapping);
        $this->assertEquals('accounting_code_id', $mapping['fk_code_ventilation']);

        $this->assertArrayHasKey('ref', $mapping);
        $this->assertEquals('ref', $mapping['ref']);
    }

    /**
     * Test supplier order lines mapping
     */
    public function testSupplierOrderLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetSupplierOrderLinesMapping();

        $this->assertArrayHasKey('fk_commande', $mapping);
        $this->assertEquals('supplier_order_id', $mapping['fk_commande']);
    }

    /**
     * Test supplier proposal lines mapping
     */
    public function testSupplierProposalLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetSupplierProposalLinesMapping();

        $this->assertArrayHasKey('fk_supplier_proposal', $mapping);
        $this->assertEquals('supplier_proposal_id', $mapping['fk_supplier_proposal']);
    }

    /**
     * Test shipment lines mapping
     */
    public function testShipmentLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetShipmentLinesMapping();

        $this->assertArrayHasKey('rowid', $mapping);
        $this->assertEquals('id', $mapping['rowid']);

        $this->assertArrayHasKey('fk_expedition', $mapping);
        $this->assertEquals('shipment_id', $mapping['fk_expedition']);

        $this->assertArrayHasKey('qty_shipped', $mapping);
        $this->assertEquals('quantity_shipped', $mapping['qty_shipped']);

        $this->assertArrayHasKey('entrepot_id', $mapping);
        $this->assertEquals('warehouse', $mapping['entrepot_id']);
    }

    /**
     * Test reception lines mapping
     */
    public function testReceptionLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetReceptionLinesMapping();

        $this->assertArrayHasKey('fk_reception', $mapping);
        $this->assertEquals('reception_id', $mapping['fk_reception']);

        $this->assertArrayHasKey('fk_commande', $mapping);
        $this->assertEquals('supplier_order_id', $mapping['fk_commande']);

        $this->assertArrayHasKey('comment', $mapping);
        $this->assertEquals('comment', $mapping['comment']);
    }

    /**
     * Test expense report lines mapping
     */
    public function testExpenseReportLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetExpenseReportLinesMapping();

        $this->assertArrayHasKey('fk_expensereport', $mapping);
        $this->assertEquals('expense_report_id', $mapping['fk_expensereport']);

        $this->assertArrayHasKey('fk_c_type_fees', $mapping);
        $this->assertEquals('fee_type', $mapping['fk_c_type_fees']);

        $this->assertArrayHasKey('fk_projet', $mapping);
        $this->assertEquals('project', $mapping['fk_projet']);

        $this->assertArrayHasKey('value_unit', $mapping);
        $this->assertEquals('unit_value', $mapping['value_unit']);

        $this->assertArrayHasKey('comments', $mapping);
        $this->assertEquals('comments', $mapping['comments']);
    }

    /**
     * Test contract lines mapping
     */
    public function testContractLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetContractLinesMapping();

        $this->assertArrayHasKey('fk_contrat', $mapping);
        $this->assertEquals('contract_id', $mapping['fk_contrat']);

        $this->assertArrayHasKey('date_ouverture_prevue', $mapping);
        $this->assertEquals('date_start_planned', $mapping['date_ouverture_prevue']);

        $this->assertArrayHasKey('date_fin_validite', $mapping);
        $this->assertEquals('date_end_planned', $mapping['date_fin_validite']);

        $this->assertArrayHasKey('statut', $mapping);
        $this->assertEquals('status', $mapping['statut']);
    }

    /**
     * Test intervention lines mapping
     */
    public function testInterventionLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetInterventionLinesMapping();

        $this->assertArrayHasKey('fk_fichinter', $mapping);
        $this->assertEquals('intervention_id', $mapping['fk_fichinter']);

        $this->assertArrayHasKey('duree', $mapping);
        $this->assertEquals('duration', $mapping['duree']);

        $this->assertArrayHasKey('date', $mapping);
        $this->assertEquals('date', $mapping['date']);
    }

    /**
     * Test BOM lines mapping
     */
    public function testBomLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetBomLinesMapping();

        $this->assertArrayHasKey('fk_bom', $mapping);
        $this->assertEquals('bom_id', $mapping['fk_bom']);

        $this->assertArrayHasKey('fk_bom_child', $mapping);
        $this->assertEquals('child_bom', $mapping['fk_bom_child']);

        $this->assertArrayHasKey('qty_frozen', $mapping);
        $this->assertEquals('quantity_frozen', $mapping['qty_frozen']);

        $this->assertArrayHasKey('disable_stock_change', $mapping);
        $this->assertEquals('disable_stock_change', $mapping['disable_stock_change']);

        $this->assertArrayHasKey('efficiency', $mapping);
        $this->assertEquals('efficiency', $mapping['efficiency']);
    }

    /**
     * Test MO lines mapping
     */
    public function testMoLinesMappingHasSpecificFields(): void
    {
        $mapping = $this->mapper->testGetMoLinesMapping();

        $this->assertArrayHasKey('fk_mo', $mapping);
        $this->assertEquals('mo_id', $mapping['fk_mo']);

        $this->assertArrayHasKey('fk_warehouse', $mapping);
        $this->assertEquals('warehouse', $mapping['fk_warehouse']);

        $this->assertArrayHasKey('origin_type', $mapping);
        $this->assertEquals('origin_type', $mapping['origin_type']);

        $this->assertArrayHasKey('role', $mapping);
        $this->assertEquals('role', $mapping['role']);
    }

    /**
     * Test all mappings return arrays
     */
    public function testAllMappingsReturnArrays(): void
    {
        $this->assertIsArray($this->mapper->testGetCommonLinesMapping());
        $this->assertIsArray($this->mapper->testGetInvoiceLinesMapping());
        $this->assertIsArray($this->mapper->testGetProposalLinesMapping());
        $this->assertIsArray($this->mapper->testGetOrderLinesMapping());
        $this->assertIsArray($this->mapper->testGetSupplierInvoiceLinesMapping());
        $this->assertIsArray($this->mapper->testGetSupplierOrderLinesMapping());
        $this->assertIsArray($this->mapper->testGetSupplierProposalLinesMapping());
        $this->assertIsArray($this->mapper->testGetShipmentLinesMapping());
        $this->assertIsArray($this->mapper->testGetReceptionLinesMapping());
        $this->assertIsArray($this->mapper->testGetExpenseReportLinesMapping());
        $this->assertIsArray($this->mapper->testGetContractLinesMapping());
        $this->assertIsArray($this->mapper->testGetInterventionLinesMapping());
        $this->assertIsArray($this->mapper->testGetBomLinesMapping());
        $this->assertIsArray($this->mapper->testGetMoLinesMapping());
    }

    /**
     * Test all mappings are non-empty
     */
    public function testAllMappingsAreNonEmpty(): void
    {
        $this->assertNotEmpty($this->mapper->testGetCommonLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetInvoiceLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetProposalLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetOrderLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetSupplierInvoiceLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetSupplierOrderLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetSupplierProposalLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetShipmentLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetReceptionLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetExpenseReportLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetContractLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetInterventionLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetBomLinesMapping());
        $this->assertNotEmpty($this->mapper->testGetMoLinesMapping());
    }
}
