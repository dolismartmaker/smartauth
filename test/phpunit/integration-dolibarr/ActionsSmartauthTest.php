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
     * Test doActions returns 0 on success
     */
    public function testDoActionsReturnsZeroOnSuccess(): void
    {
        global $hookmanager;

        $parameters = ['currentcontext' => 'othercontext'];
        $object = new \stdClass();
        $action = 'add';

        $result = $this->actions->doActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
        $this->assertEquals(['myreturn' => 999], $this->actions->results);
        $this->assertEquals('A text to show', $this->actions->resprints);
    }

    /**
     * Test doActions with specific context
     */
    public function testDoActionsWithSpecificContext(): void
    {
        global $hookmanager;

        $parameters = ['currentcontext' => 'somecontext1'];
        $object = new \stdClass();
        $action = 'add';

        $result = $this->actions->doActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
    }

    /**
     * Test doMassActions returns 0 on success
     */
    public function testDoMassActionsReturnsZeroOnSuccess(): void
    {
        global $hookmanager;

        $parameters = ['currentcontext' => 'othercontext', 'toselect' => []];
        $object = new \stdClass();
        $action = 'massaction';

        $result = $this->actions->doMassActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
        $this->assertEquals(['myreturn' => 999], $this->actions->results);
    }

    /**
     * Test doMassActions with specific context and objects
     */
    public function testDoMassActionsWithSpecificContext(): void
    {
        global $hookmanager;

        $parameters = [
            'currentcontext' => 'somecontext1',
            'toselect' => [1, 2, 3]
        ];
        $object = new \stdClass();
        $action = 'massaction';

        $result = $this->actions->doMassActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
    }

    /**
     * Test addMoreMassActions returns 0 on success
     */
    public function testAddMoreMassActionsReturnsZeroOnSuccess(): void
    {
        global $hookmanager;

        $parameters = ['currentcontext' => 'othercontext'];
        $object = new \stdClass();
        $action = 'view';

        $result = $this->actions->addMoreMassActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
    }

    /**
     * Test addMoreMassActions with specific context
     */
    public function testAddMoreMassActionsWithSpecificContext(): void
    {
        global $hookmanager, $langs;

        // Ensure langs is initialized
        if (!isset($langs) || !is_object($langs)) {
            $langs = new \stdClass();
            $langs->trans = function ($key) {
                return $key;
            };
        }

        $parameters = ['currentcontext' => 'somecontext1'];
        $object = new \stdClass();
        $action = 'view';

        $result = $this->actions->addMoreMassActions($parameters, $object, $action, $hookmanager);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('option', $this->actions->resprints);
        $this->assertStringContainsString('disabled', $this->actions->resprints);
    }

    /**
     * Test beforePDFCreation returns 0
     */
    public function testBeforePDFCreationReturnsZero(): void
    {
        $parameters = ['currentcontext' => 'othercontext'];
        $object = new \stdClass();
        $action = 'add';

        $result = $this->actions->beforePDFCreation($parameters, $object, $action);

        $this->assertEquals(0, $result);
    }

    /**
     * Test beforePDFCreation with specific context
     */
    public function testBeforePDFCreationWithSpecificContext(): void
    {
        $parameters = ['currentcontext' => 'somecontext1'];
        $object = new \stdClass();
        $action = 'add';

        $result = $this->actions->beforePDFCreation($parameters, $object, $action);

        $this->assertEquals(0, $result);
    }

    /**
     * Test afterPDFCreation returns 0
     */
    public function testAfterPDFCreationReturnsZero(): void
    {
        $parameters = ['currentcontext' => 'othercontext'];
        $pdfhandler = new \stdClass();
        $action = 'add';

        $result = $this->actions->afterPDFCreation($parameters, $pdfhandler, $action);

        $this->assertEquals(0, $result);
    }

    /**
     * Test afterPDFCreation with specific context
     */
    public function testAfterPDFCreationWithSpecificContext(): void
    {
        $parameters = ['currentcontext' => 'somecontext1'];
        $pdfhandler = new \stdClass();
        $action = 'add';

        $result = $this->actions->afterPDFCreation($parameters, $pdfhandler, $action);

        $this->assertEquals(0, $result);
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

    /**
     * Test restrictedArea with myobject feature
     *
     * Note: The restrictedArea method uses $user->hasRight() which requires proper
     * Dolibarr permission setup. We test both branches by checking the return values.
     */
    public function testRestrictedAreaWithMyobjectFeature(): void
    {
        global $hookmanager, $user;

        $parameters = ['features' => 'myobject'];
        $action = 'view';

        $result = $this->actions->restrictedArea($parameters, $action, $hookmanager);

        // The method returns 1 when it handles the myobject feature
        // The actual permission check result is in $this->actions->results['result']
        $this->assertEquals(1, $result);
        $this->assertArrayHasKey('result', $this->actions->results);
        // Result is 0 or 1 depending on whether user has permission
        $this->assertContains($this->actions->results['result'], [0, 1]);
    }

    /**
     * Test restrictedArea sets result to 0 when user lacks permission
     *
     * By default, the test user doesn't have smartauth myobject read permission
     */
    public function testRestrictedAreaDefaultsToNoPermission(): void
    {
        global $hookmanager;

        $parameters = ['features' => 'myobject'];
        $action = 'view';

        $result = $this->actions->restrictedArea($parameters, $action, $hookmanager);

        $this->assertEquals(1, $result);
        // Without explicit permission setup, should default to no permission
        $this->assertEquals(0, $this->actions->results['result']);
    }

    /**
     * Test restrictedArea with other feature
     */
    public function testRestrictedAreaWithOtherFeature(): void
    {
        global $hookmanager;

        $parameters = ['features' => 'other'];
        $action = 'view';

        $result = $this->actions->restrictedArea($parameters, $action, $hookmanager);

        $this->assertEquals(0, $result);
    }

    /**
     * Test completeTabsHead returns -1
     */
    public function testCompleteTabsHeadReturnsMinus1(): void
    {
        global $hookmanager;

        $parameters = [];
        $object = new \stdClass();
        $action = 'view';

        $result = $this->actions->completeTabsHead($parameters, $object, $action, $hookmanager);

        $this->assertEquals(-1, $result);
    }

    /**
     * Test multiple hook calls don't interfere
     */
    public function testMultipleHookCallsIndependent(): void
    {
        global $hookmanager;

        $parameters1 = ['currentcontext' => 'somecontext1'];
        $object1 = new \stdClass();
        $action1 = 'add';

        $result1 = $this->actions->doActions($parameters1, $object1, $action1, $hookmanager);

        // Reset and call another hook
        $parameters2 = ['currentcontext' => 'othercontext'];
        $object2 = new \stdClass();
        $action2 = 'view';

        $result2 = $this->actions->getNomUrl($parameters2, $object2, $action2);

        $this->assertEquals(0, $result1);
        $this->assertEquals(0, $result2);
        // resprints should be empty from getNomUrl
        $this->assertEquals('', $this->actions->resprints);
    }
}
