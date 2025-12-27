<?php

namespace SmartAuth\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../../../class/actions_smartauth.class.php';

use ActionsSmartauth;

/**
 * Integration tests for ActionsSmartauth class (Dolibarr hooks)
 */
class ActionsSmartauthTest extends DolibarrRealTestCase
{
    /** @var ActionsSmartauth */
    private $actions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = new ActionsSmartauth($this->db);
    }

    /**
     * Test ActionsSmartauth instantiation
     */
    public function testActionsSmartauthInstantiation(): void
    {
        $this->assertInstanceOf(ActionsSmartauth::class, $this->actions);
    }

    /**
     * Test ActionsSmartauth has db property
     */
    public function testActionsSmartauthHasDb(): void
    {
        $this->assertSame($this->db, $this->actions->db);
    }

    /**
     * Test ActionsSmartauth has error properties
     */
    public function testActionsSmartauthHasErrorProperties(): void
    {
        $this->assertEquals('', $this->actions->error);
        $this->assertIsArray($this->actions->errors);
        $this->assertEmpty($this->actions->errors);
    }

    /**
     * Test ActionsSmartauth has results property
     */
    public function testActionsSmartauthHasResultsProperty(): void
    {
        $this->assertIsArray($this->actions->results);
    }

    /**
     * Test getNomUrl returns 0
     */
    public function testGetNomUrlReturnsZero(): void
    {
        $parameters = [];
        $object = new \stdClass();
        $action = 'view';

        $result = $this->actions->getNomUrl($parameters, $object, $action);

        $this->assertEquals(0, $result);
        $this->assertEquals('', $this->actions->resprints);
    }

    /**
     * Test loadDataForCustomReports returns 1
     */
    public function testLoadDataForCustomReportsReturns1(): void
    {
        global $hookmanager, $langs;

        // Ensure langs is initialized with load method
        if (!isset($langs) || !is_object($langs) || !method_exists($langs, 'load')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $parameters = [
            'tabfamily' => 'other',
            'objecttype' => 'test'
        ];
        $action = 'view';

        $result = $this->actions->loadDataForCustomReports($parameters, $action, $hookmanager);

        $this->assertEquals(1, $result);
        $this->assertArrayHasKey('head', $this->actions->results);
    }

    /**
     * Test loadDataForCustomReports with smartauth tabfamily
     */
    public function testLoadDataForCustomReportsWithSmartauthTabfamily(): void
    {
        global $hookmanager, $langs;

        // Ensure langs is initialized with load method
        if (!isset($langs) || !is_object($langs) || !method_exists($langs, 'load')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
            $langs = new \Translate('', $this->conf);
        }

        $parameters = [
            'tabfamily' => 'smartauth',
            'objecttype' => 'smartauth'
        ];
        $action = 'view';

        $result = $this->actions->loadDataForCustomReports($parameters, $action, $hookmanager);

        $this->assertEquals(1, $result);
        $this->assertArrayHasKey('title', $this->actions->results);
        $this->assertArrayHasKey('picto', $this->actions->results);
        $this->assertArrayHasKey('head', $this->actions->results);
    }

}
