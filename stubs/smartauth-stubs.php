<?php
/**
 * Generated stub declarations for Dolibarr.
 * @see https://dolibarr.org
 * @see https://github.com/rycks/dolibarr-stubs
 */

namespace SmartAuth\Api {
    class AuthController
    {
        /**
         * @api {get} /login List of dolibarr entities
         * @apiName GetLogin
         * @apiGroup Auth
         *
         * @apiSuccess {Array} entities array of dolibarr available entities
         *
         * @apiDescription Get the list of dolibarr entities before login
         * then you can make a login request on the right dolibarr entity
         * if your dolibarr use multicompany module
         */
        public function index($arr = null)
        {
        }
        /**
         * @api {post} /login Login
         *
         * @apiDescription Try to log into dolibarr with login / password and
         * in case of success generate a token for that app / session
         *
         * @apiName PostLogin
         * @apiGroup Auth
         *
         * @apiBody (Login) {String} email     Mandatory dolibarr user name (email)
         * @apiBody (Login) {String} password  Mandatory user password
         * @apiBody (Login) {Number} entity    Mandatory dolibarr entity
         *
         * @apiSuccess {String} user      User login
         * @apiSuccess {Number} userid    User ID
         * @apiSuccess {String} token     Session JWT to use for next requests as Bearer Auth Token (JWT)
         *
         * @apiSuccessExample {json} Success-Response:
         * HTTP/1.1 200 OK
         * {
         *     "statusCode": 200,
         *     "data": {
         *         "user": "eric@cap-rel.fr",
         *         "userid": "3",
         *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUz88NiJ9.eyJsb2dpbiI622RsYyIsImVu88l0eSI6MH0._XWcHLf999kMqkP65dgXcbkqT522W9zbdUiIA3BU0pI"
         *     }
         *  }
         */
        public function login($arr)
        {
        }
        /**
         * @api {post} /logout Logout
         * @apiDescription Logout and close session
         * @apiName PostLogout
         * @apiGroup Auth
         *
         */
        public function logout($arr)
        {
        }
        /**
         * check if token is correct
         *
         * @return  StdObject  decoded token
         */
        public static function check()
        {
        }
        /**
         * create a new salt stored into database and a key
         *
         * @param   [type]  $uid     [$uid description]
         * @param   [type]  $entity  [$entity description]
         *
         * @return  [type]           [return description]
         */
        private function _newKey($uid, $entity)
        {
        }
        private static function getAuthorizationHeader()
        {
        }
        private static function getBearerToken()
        {
        }
        private function _api_GetListOfEntities()
        {
        }
    }
    class RouteController
    {
        public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        /**
         * routage des appels sur l'api
         *
         * @param   [type]  $targetMethod      [$targetMethod description]
         * @param   [type]  $targetAction      [$targetAction description]
         * @param   [type]  $redirectFunction  [$redirectFunction description]
         * @param   [type]  $protected         [$protected description]
         *
         * @return  [type]                     [return description]
         */
        public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
        {
        }
        private function _getAuthorizationHeader()
        {
        }
        private function _getBearerToken()
        {
        }
    }
}
namespace {
    /* Copyright (C) 2021  John BOTELLA    <john.botella@atm-consulting.fr>
     *
     * This program is free software; you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation; either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program. If not, see <https://www.gnu.org/licenses/>.
     */
    /**
     * This class help you create setup render
     */
    class FormSetup
    {
        /**
         * @var DoliDB Database handler.
         */
        public $db;
        /** @var int */
        public $entity;
        /** @var FormSetupItem[]  */
        public $items = array();
        /**
         * @var int
         */
        public $setupNotEmpty = 0;
        /** @var Translate */
        public $langs;
        /** @var Form */
        public $form;
        /** @var int */
        protected $maxItemRank;
        /**
         * this is an html string display before output form
         * @var string
         */
        public $htmlBeforeOutputForm = '';
        /**
         * this is an html string display after output form
         * @var string
         */
        public $htmlAfterOutputForm = '';
        /**
         * this is an html string display on buttons zone
         * @var string
         */
        public $htmlOutputMoreButton = '';
        /**
         *
         * @var array
         */
        public $formAttributes = array(
            'action' => '',
            // set in __construct
            'method' => 'POST',
        );
        /**
         * an list of hidden inputs used only in edit mode
         * @var array
         */
        public $formHiddenInputs = array();
        /**
         * Constructor
         *
         * @param DoliDB $db Database handler
         * @param Translate $outputLangs if needed can use another lang
         */
        public function __construct($db, $outputLangs = \false)
        {
        }
        /**
         * Generate an attributes string form an input array
         *
         * @param 	array 	$attributes 	an array of attributes keys and values,
         * @return 	string					attribute string
         */
        public static function generateAttributesStringFromArray($attributes)
        {
        }
        /**
         * generateOutput
         *
         * @param 	bool 	$editMode 	true will display output on edit mod
         * @return 	string				html output
         */
        public function generateOutput($editMode = \false)
        {
        }
        /**
         * generateTableOutput
         *
         * @param 	bool 	$editMode 	true will display output on edit mod
         * @return 	string				html output
         */
        public function generateTableOutput($editMode = \false)
        {
        }
        /**
         * saveConfFromPost
         *
         * @param 	bool 		$noMessageInUpdate display event message on errors and success
         * @return	 int        -1 if KO, 1 if OK
         */
        public function saveConfFromPost($noMessageInUpdate = \false)
        {
        }
        /**
         * generateLineOutput
         *
         * @param 	FormSetupItem 	$item 		the setup item
         * @param 	bool 			$editMode 	Display as edit mod
         * @return 	string 						the html output for an setup item
         */
        public function generateLineOutput($item, $editMode = \false)
        {
        }
        /**
         * Method used to test  module builder convertion to this form usage
         *
         * @param 	array 	$params 	an array of arrays of params from old modulBuilder params
         * @return 	boolean
         */
        public function addItemsFromParamsArray($params)
        {
        }
        /**
         * From old
         * Method was used to test  module builder convertion to this form usage.
         *
         * @param 	string 	$confKey 	the conf name to store
         * @param 	array 	$params 	an array of params from old modulBuilder params
         * @return 	bool
         */
        public function addItemFromParams($confKey, $params)
        {
        }
        /**
         * Used to export param array for /core/actions_setmoduleoptions.inc.php template
         * Method exists only for manage setup convertion
         *
         * @return array $arrayofparameters for /core/actions_setmoduleoptions.inc.php
         */
        public function exportItemsAsParamsArray()
        {
        }
        /**
         * Reload for each item default conf
         * note: this will override custom configuration
         *
         * @return bool
         */
        public function reloadConfs()
        {
        }
        /**
         * Create a new item
         * the tagret is useful with hooks : that allow externals modules to add setup items on good place
         *
         * @param string	$confKey 				the conf key used in database
         * @param string	$targetItemKey    		target item used to place the new item beside
         * @param bool		$insertAfterTarget		insert before or after target item ?
         * @return FormSetupItem the new setup item created
         */
        public function newItem($confKey, $targetItemKey = \false, $insertAfterTarget = \false)
        {
        }
        /**
         * Sort items according to rank
         *
         * @return bool
         */
        public function sortingItems()
        {
        }
        /**
         * getCurentItemMaxRank
         *
         * @param bool $cache To use cache or not
         * @return int
         */
        public function getCurentItemMaxRank($cache = \true)
        {
        }
        /**
         * set new max rank if needed
         *
         * @param 	int 		$rank 	the item rank
         * @return 	int|void			new max rank
         */
        public function setItemMaxRank($rank)
        {
        }
        /**
         * get item position rank from item key
         *
         * @param	string		$itemKey    	the item key
         * @return	int         				rank on success and -1 on error
         */
        public function getLineRank($itemKey)
        {
        }
        /**
         *  uasort callback function to Sort params items
         *
         *  @param	FormSetupItem	$a  formSetup item
         *  @param	FormSetupItem	$b  formSetup item
         *  @return	int					Return compare result
         */
        public function itemSort(\FormSetupItem $a, \FormSetupItem $b)
        {
        }
    }
    /**
     * This class help to create item for class formSetup
     */
    class FormSetupItem
    {
        /**
         * @var DoliDB Database handler.
         */
        public $db;
        /** @var Translate */
        public $langs;
        /** @var int */
        public $entity;
        /** @var Form */
        public $form;
        /** @var string $confKey the conf key used in database */
        public $confKey;
        /** @var string|false $nameText  */
        public $nameText = \false;
        /** @var string $helpText  */
        public $helpText = '';
        /** @var string $fieldValue  */
        public $fieldValue;
        /** @var string $defaultFieldValue  */
        public $defaultFieldValue = \null;
        /** @var array $fieldAttr  fields attribute only for compatible fields like input text */
        public $fieldAttr = array();
        /** @var bool|string set this var to override field output will override $fieldInputOverride and $fieldOutputOverride too */
        public $fieldOverride = \false;
        /** @var bool|string set this var to override field input */
        public $fieldInputOverride = \false;
        /** @var bool|string set this var to override field output */
        public $fieldOutputOverride = \false;
        /** @var int $rank  */
        public $rank = 0;
        /** @var array set this var for options on select and multiselect items   */
        public $fieldOptions = array();
        /** @var callable $saveCallBack  */
        public $saveCallBack;
        /** @var callable $setValueFromPostCallBack  */
        public $setValueFromPostCallBack;
        /**
         * @var string $errors
         */
        public $errors = array();
        /**
         * TODO each type must have setAs{type} method to help configuration
         *   And set var as protected when its done configuration must be done by method
         *   this is important for retrocompatibility of futures versions
         * @var string $type  'string', 'textarea', 'category:'.Categorie::TYPE_CUSTOMER', 'emailtemplate', 'thirdparty_type'
         */
        protected $type = 'string';
        public $enabled = 1;
        public $cssClass = '';
        /**
         * Constructor
         *
         * @param string	$confKey	the conf key used in database
         */
        public function __construct($confKey)
        {
        }
        /**
         * load conf value from databases
         * @return bool
         */
        public function loadValueFromConf()
        {
        }
        /**
         * reload conf value from databases is an aliase of loadValueFromConf
         * @deprecated
         * @return bool
         */
        public function reloadValueFromConf()
        {
        }
        /**
         * Save const value based on htdocs/core/actions_setmoduleoptions.inc.php
         *
         * @return     int         			-1 if KO, 1 if OK
         */
        public function saveConfValue()
        {
        }
        /**
         * Set an override function for saving data
         *
         * @param callable $callBack a callable function
         * @return void
         */
        public function setSaveCallBack(callable $callBack)
        {
        }
        /**
         * Set an override function for get data from post
         * @param callable $callBack a callable function
         * @return void
         */
        public function setValueFromPostCallBack(callable $callBack)
        {
        }
        /**
         * Save const value based on htdocs/core/actions_setmoduleoptions.inc.php
         *	@return     int         			-1 if KO, 0  nothing to do , 1 if OK
         */
        public function setValueFromPost()
        {
        }
        /**
         * Get help text or generate it
         * @return int|string
         */
        public function getHelpText()
        {
        }
        /**
         * Get field name text or generate it
         * @return false|int|string
         */
        public function getNameText()
        {
        }
        /**
         * generate input field
         * @return bool|string
         */
        public function generateInputField()
        {
        }
        /**
         * generatec default input field
         * @return string
         */
        public function generateInputFieldText()
        {
        }
        /**
         * generate input field for textarea
         * @return string
         */
        public function generateInputFieldTextarea()
        {
        }
        /**
         * generate input field for html
         * @return string
         */
        public function generateInputFieldHtml()
        {
        }
        /**
         * generate input field for categories
         * @return string
         */
        public function generateInputFieldCategories()
        {
        }
        /**
         * generate input field for email template selector
         * @return string
         */
        public function generateInputFieldEmailTemplate()
        {
        }
        /**
         * generate input field for secure key
         * @return string
         */
        public function generateInputFieldSecureKey()
        {
        }
        /**
         * @return string
         */
        public function generateInputFieldMultiSelect()
        {
        }
        /**
         * @return string
         */
        public function generateInputFieldSelect()
        {
        }
        /**
         * @return string
         */
        public function generateInputFieldSelectUser()
        {
        }
        /**
         * get the type : used for old module builder setup conf style conversion and tests
         * because this two class will quickly evolve it's important to not set or get directly $this->type (will be protected) so this method exist
         * to be sure we can manage evolution easily
         *
         * @return string
         */
        public function getType()
        {
        }
        /**
         * set the type from string : used for old module builder setup conf style conversion and tests
         * because this two class will quickly evolve it's important to not set directly $this->type (will be protected) so this method exist
         * to be sure we can manage evolution easily
         *
         * @param string $type possible values based on old module builder setup : 'string', 'textarea', 'category:'.Categorie::TYPE_CUSTOMER', 'emailtemplate', 'thirdparty_type'
         * @deprecated yes this setTypeFromTypeString came deprecated because it exists only for manage setup convertion
         * @return bool
         */
        public function setTypeFromTypeString($type)
        {
        }
        /**
         * Add error
         *
         * @param array|string $errors the error text
         * @return null
         */
        public function setErrors($errors)
        {
        }
        /**
         * generateOutputField
         *
         * @return bool|string 		Generate the output html for this item
         */
        public function generateOutputField()
        {
        }
        /**
         * generateOutputFieldMultiSelect
         *
         * @return string
         */
        public function generateOutputFieldMultiSelect()
        {
        }
        /**
         * generateOutputFieldColor
         *
         * @return string
         */
        public function generateOutputFieldColor()
        {
        }
        /**
         * generateInputFieldColor
         *
         * @return string
         */
        public function generateInputFieldColor()
        {
        }
        /**
         * generateOutputFieldSelect
         *
         * @return string
         */
        public function generateOutputFieldSelect()
        {
        }
        /**
         * generateOutputFieldSelectUser
         *
         * @return string
         */
        public function generateOutputFieldSelectUser()
        {
        }
        /*
         * METHODS FOR SETTING DISPLAY TYPE
         */
        /**
         * Set type of input as string
         *
         * @return self
         */
        public function setAsString()
        {
        }
        /**
         * Set type of input as color
         *
         * @return self
         */
        public function setAsColor()
        {
        }
        /**
         * Set type of input as textarea
         *
         * @return self
         */
        public function setAsTextarea()
        {
        }
        /**
         * Set type of input as html editor
         *
         * @return self
         */
        public function setAsHtml()
        {
        }
        /**
         * Set type of input as emailtemplate selector
         *
         * @param string $templateType email template type
         * @return self
         */
        public function setAsEmailTemplate($templateType)
        {
        }
        /**
         * Set type of input as thirdparty_type selector
         *
         * @return self
         */
        public function setAsThirdpartyType()
        {
        }
        /**
         * Set type of input as Yes
         *
         * @return self
         */
        public function setAsYesNo()
        {
        }
        /**
         * Set type of input as secure key
         *
         * @return self
         */
        public function setAsSecureKey()
        {
        }
        /**
         * Set type of input as product
         *
         * @return self
         */
        public function setAsProduct()
        {
        }
        /**
         * Set type of input as a category selector
         * TODO add default value
         *
         * @param	int		$catType		Type of category ('customer', 'supplier', 'contact', 'product', 'member'). Old mode (0, 1, 2, ...) is deprecated.
         * @return self
         */
        public function setAsCategory($catType)
        {
        }
        /**
         * Set type of input as a simple title. No data to store
         *
         * @return self
         */
        public function setAsTitle()
        {
        }
        /**
         * Set type of input as a simple title. No data to store
         *
         * @param array $fieldOptions A table of field options
         * @return self
         */
        public function setAsMultiSelect($fieldOptions)
        {
        }
        /**
         * Set type of input as a simple title. No data to store
         *
         * @param array $fieldOptions  A table of field options
         * @return self
         */
        public function setAsSelect($fieldOptions)
        {
        }
        /**
         * Set type of input as a simple title. No data to store
         *
         * @return self
         */
        public function setAsSelectUser()
        {
        }
    }
    /* Copyright (C) 2024 Alice Adminson <aadminson@example.com>
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <https://www.gnu.org/licenses/>.
     */
    /**
     * \file    smartauth/class/actions_smartauth.class.php
     * \ingroup smartauth
     * \brief   Example hook overload.
     *
     * Put detailed description here.
     */
    /**
     * Class ActionsSmartauth
     */
    class ActionsSmartauth
    {
        /**
         * @var DoliDB Database handler.
         */
        public $db;
        /**
         * @var string Error code (or message)
         */
        public $error = '';
        /**
         * @var array Errors
         */
        public $errors = array();
        /**
         * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
         */
        public $results = array();
        /**
         * @var string String displayed by executeHook() immediately after return
         */
        public $resprints;
        /**
         * @var int		Priority of hook (50 is used if value is not defined)
         */
        public $priority;
        /**
         * Constructor
         *
         *  @param		DoliDB		$db      Database handler
         */
        public function __construct($db)
        {
        }
        /**
         * Execute action
         *
         * @param	array			$parameters		Array of parameters
         * @param	CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
         * @param	string			$action      	'add', 'update', 'view'
         * @return	int         					<0 if KO,
         *                           				=0 if OK but we want to process standard actions too,
         *                            				>0 if OK and we want to replace standard actions.
         */
        public function getNomUrl($parameters, &$object, &$action)
        {
        }
        /**
         * Overloading the doActions function : replacing the parent's function with the one below
         *
         * @param   array           $parameters     Hook metadatas (context, etc...)
         * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
         * @param   string          $action         Current action (if set). Generally create or edit or null
         * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
         * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
         */
        public function doActions($parameters, &$object, &$action, $hookmanager)
        {
        }
        /**
         * Overloading the doMassActions function : replacing the parent's function with the one below
         *
         * @param   array           $parameters     Hook metadatas (context, etc...)
         * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
         * @param   string          $action         Current action (if set). Generally create or edit or null
         * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
         * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
         */
        public function doMassActions($parameters, &$object, &$action, $hookmanager)
        {
        }
        /**
         * Overloading the addMoreMassActions function : replacing the parent's function with the one below
         *
         * @param   array           $parameters     Hook metadatas (context, etc...)
         * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
         * @param   string          $action         Current action (if set). Generally create or edit or null
         * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
         * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
         */
        public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
        {
        }
        /**
         * Execute action
         *
         * @param	array	$parameters     Array of parameters
         * @param   Object	$object		   	Object output on PDF
         * @param   string	$action     	'add', 'update', 'view'
         * @return  int 		        	<0 if KO,
         *                          		=0 if OK but we want to process standard actions too,
         *  	                            >0 if OK and we want to replace standard actions.
         */
        public function beforePDFCreation($parameters, &$object, &$action)
        {
        }
        /**
         * Execute action
         *
         * @param	array	$parameters     Array of parameters
         * @param   Object	$pdfhandler     PDF builder handler
         * @param   string	$action         'add', 'update', 'view'
         * @return  int 		            <0 if KO,
         *                                  =0 if OK but we want to process standard actions too,
         *                                  >0 if OK and we want to replace standard actions.
         */
        public function afterPDFCreation($parameters, &$pdfhandler, &$action)
        {
        }
        /**
         * Overloading the loadDataForCustomReports function : returns data to complete the customreport tool
         *
         * @param   array           $parameters     Hook metadatas (context, etc...)
         * @param   string          $action         Current action (if set). Generally create or edit or null
         * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
         * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
         */
        public function loadDataForCustomReports($parameters, &$action, $hookmanager)
        {
        }
        /**
         * Overloading the restrictedArea function : check permission on an object
         *
         * @param   array           $parameters     Hook metadatas (context, etc...)
         * @param   string          $action         Current action (if set). Generally create or edit or null
         * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
         * @return  int 		      			  	<0 if KO,
         *                          				=0 if OK but we want to process standard actions too,
         *  	                            		>0 if OK and we want to replace standard actions.
         */
        public function restrictedArea($parameters, &$action, $hookmanager)
        {
        }
        /**
         * Execute action completeTabsHead
         *
         * @param   array           $parameters     Array of parameters
         * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
         * @param   string          $action         'add', 'update', 'view'
         * @param   Hookmanager     $hookmanager    hookmanager
         * @return  int                             <0 if KO,
         *                                          =0 if OK but we want to process standard actions too,
         *                                          >0 if OK and we want to replace standard actions.
         */
        public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
        {
        }
        /* Add here any other hooked methods... */
    }
    //require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    //require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
    /**
     * Class for Auth
     */
    class Auth extends \CommonObject
    {
        public $socid;
        public $labelStatusShort;
        public $labelStatus;
        public $output;
        public $user_validation;
        /**
         * @var string ID of module.
         */
        public $module = 'smartauth';
        /**
         * @var string ID to identify managed object.
         */
        public $element = 'auth';
        /**
         * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
         */
        public $table_element = 'smartauth_auth';
        /**
         * @var int  Does this object support multicompany module ?
         * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
         */
        public $ismultientitymanaged = 0;
        /**
         * @var int  Does object support extrafields ? 0=No, 1=Yes
         */
        public $isextrafieldmanaged = 1;
        /**
         * @var string String with name of icon for auth. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'auth@smartauth' if picto is file 'img/object_auth.png'.
         */
        public $picto = 'fa-file';
        public const STATUS_DRAFT = 0;
        public const STATUS_VALIDATED = 1;
        public const STATUS_CANCELED = 9;
        //cache
        private $_appNameUIDCache = [];
        /**
         *  'type' field format:
         *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
         *  	'select' (list of values are in 'options'),
         *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:Sortfield]]]]',
         *  	'chkbxlst:...',
         *  	'varchar(x)',
         *  	'text', 'text:none', 'html',
         *   	'double(24,8)', 'real', 'price',
         *  	'date', 'datetime', 'timestamp', 'duration',
         *  	'boolean', 'checkbox', 'radio', 'array',
         *  	'mail', 'phone', 'url', 'password', 'ip'
         *		Note: Filter must be a Dolibarr Universal Filter syntax string. Example: "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.status:!=:0) or (t.nature:is:NULL)"
         *  'label' the translation key.
         *  'picto' is code of a picto to show before value in forms
         *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalInt("MY_SETUP_PARAM")' or 'isModEnabled("multicurrency")' ...)
         *  'position' is the sort order of field.
         *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
         *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
         *  'noteditable' says if field is not editable (1 or 0)
         *  'alwayseditable' says if field can be modified also when status is not draft ('1' or '0')
         *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
         *  'index' if we want an index in database.
         *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommanded to name the field fk_...).
         *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
         *  'isameasure' must be set to 1 or 2 if field can be used for measure. Field type must be summable like integer or double(24,8). Use 1 in most cases, or 2 if you don't want to see the column total into list (for example for percentage)
         *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'css'=>'minwidth300 maxwidth500 widthcentpercentminusx', 'cssview'=>'wordbreak', 'csslist'=>'tdoverflowmax200'
         *  'help' and 'helplist' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
         *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
         *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
         *  'arrayofkeyval' to set a list of values if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel"). Note that type can be 'integer' or 'varchar'
         *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
         *  'comment' is not used. You can store here any text of your choice. It is not used by application.
         *	'validate' is 1 if need to validate with $this->validateField()
         *  'copytoclipboard' is 1 or 2 to allow to add a picto to copy value into clipboard (1=picto after label, 2=picto after value)
         *
         *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
         */
        // BEGIN MODULEBUILDER PROPERTIES
        /**
         * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
         */
        public $fields = array('rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'position' => 10, 'notnull' => 1, 'visible' => -1), 'appuid' => array('type' => 'integer', 'label' => 'Appuid', 'enabled' => '1', 'position' => 15, 'notnull' => 0, 'visible' => 1), 'salt' => array('type' => 'varchar(32)', 'label' => 'Salt', 'enabled' => '1', 'position' => 20, 'notnull' => 0, 'visible' => -1), 'date_creation' => array('type' => 'datetime', 'label' => 'Datecreation', 'enabled' => '1', 'position' => 25, 'notnull' => 1, 'visible' => 1), 'date_lastused' => array('type' => 'datetime', 'label' => 'Datelastused', 'enabled' => '1', 'position' => 30, 'notnull' => 0, 'visible' => 1), 'date_eol' => array('type' => 'datetime', 'label' => 'Dateeol', 'enabled' => '1', 'position' => 35, 'notnull' => 0, 'visible' => 1), 'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'position' => 40, 'notnull' => 1, 'visible' => -1), 'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => '1', 'position' => 45, 'notnull' => 1, 'visible' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'), 'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => '1', 'position' => 50, 'notnull' => -1, 'visible' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'), 'ip' => array('type' => 'varchar(50)', 'label' => 'IpAddr', 'enabled' => '1', 'position' => 55, 'notnull' => 0, 'visible' => 1, 'default' => ''), 'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => '1', 'position' => 500, 'notnull' => 1, 'visible' => -1));
        public $rowid;
        public $appuid;
        public $salt;
        public $date_creation;
        public $date_lastused;
        public $date_eol;
        public $tms;
        public $fk_user_creat;
        public $fk_user_modif;
        public $ip;
        public $status;
        // END MODULEBUILDER PROPERTIES
        // If this object has a subtable with lines
        // /**
        //  * @var string    Name of subtable line
        //  */
        // public $table_element_line = 'smartauth_authline';
        // /**
        //  * @var string    Field with ID of parent key if this object has a parent
        //  */
        // public $fk_element = 'fk_auth';
        // /**
        //  * @var string    Name of subtable class that manage subtable lines
        //  */
        // public $class_element_line = 'Authline';
        // /**
        //  * @var array	List of child tables. To test if we can delete object.
        //  */
        // protected $childtables = array('mychildtable' => array('name'=>'Auth', 'fk_element'=>'fk_auth'));
        // /**
        //  * @var array    List of child tables. To know object to delete on cascade.
        //  *               If name matches '@ClassNAme:FilePathClass;ParentFkFieldName' it will
        //  *               call method deleteByParentField(parentId, ParentFkFieldName) to fetch and delete child object
        //  */
        // protected $childtablesoncascade = array('smartauth_authdet');
        // /**
        //  * @var AuthLine[]     Array of subtable lines
        //  */
        // public $lines = array();
        /**
         * Constructor
         *
         * @param DoliDb $db Database handler
         */
        public function __construct(\DoliDB $db)
        {
        }
        /**
         * Create object into database
         *
         * @param  User $user      User that creates
         * @param  bool $notrigger false=launch triggers after, true=disable triggers
         * @return int             <0 if KO, Id of created object if OK
         */
        public function create(\User $user, $notrigger = \false)
        {
        }
        /**
         * Clone an object into another one
         *
         * @param  	User 	$user      	User that creates
         * @param  	int 	$fromid     Id of object to clone
         * @return 	mixed 				New object created, <0 if KO
         */
        public function createFromClone(\User $user, $fromid)
        {
        }
        /**
         * Load object in memory from the database
         *
         * @param int    $id   Id object
         * @param string $ref  Ref
         * @return int         <0 if KO, 0 if not found, >0 if OK
         */
        public function fetch($id, $ref = \null)
        {
        }
        /**
         * Load object lines in memory from the database
         *
         * @return int         <0 if KO, 0 if not found, >0 if OK
         */
        public function fetchLines()
        {
        }
        /**
         * Load list of objects in memory from the database.
         *
         * @param  string      $sortorder    Sort Order
         * @param  string      $sortfield    Sort field
         * @param  int         $limit        limit
         * @param  int         $offset       Offset
         * @param  array       $filter       Filter array. Example array('field'=>'valueforlike', 'customurl'=>...)
         * @param  string      $filtermode   Filter mode (AND or OR)
         * @return array|int                 int <0 if KO, array of pages if OK
         */
        public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
        {
        }
        /**
         * Update object into database
         *
         * @param  User $user      User that modifies
         * @param  bool $notrigger false=launch triggers after, true=disable triggers
         * @return int             <0 if KO, >0 if OK
         */
        public function update(\User $user, $notrigger = \false)
        {
        }
        /**
         * Delete object in database
         *
         * @param User $user       User that deletes
         * @param bool $notrigger  false=launch triggers, true=disable triggers
         * @return int             <0 if KO, >0 if OK
         */
        public function delete(\User $user, $notrigger = \false)
        {
        }
        /**
         *  Delete a line of object in database
         *
         *	@param  User	$user       User that delete
         *  @param	int		$idline		Id of line to delete
         *  @param 	bool 	$notrigger  false=launch triggers after, true=disable triggers
         *  @return int         		>0 if OK, <0 if KO
         */
        public function deleteLine(\User $user, $idline, $notrigger = \false)
        {
        }
        /**
         *	Validate object
         *
         *	@param		User	$user     		User making status change
         *  @param		int		$notrigger		1=Does not execute triggers, 0= execute triggers
         *	@return  	int						<=0 if OK, 0=Nothing done, >0 if KO
         */
        public function validate($user, $notrigger = 0)
        {
        }
        /**
         *	Set draft status
         *
         *	@param	User	$user			Object user that modify
         *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
         *	@return	int						<0 if KO, >0 if OK
         */
        public function setDraft($user, $notrigger = 0)
        {
        }
        /**
         *	Set cancel status
         *
         *	@param	User	$user			Object user that modify
         *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
         *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
         */
        public function cancel($user, $notrigger = 0)
        {
        }
        /**
         *	Set back to validated status
         *
         *	@param	User	$user			Object user that modify
         *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
         *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
         */
        public function reopen($user, $notrigger = 0)
        {
        }
        /**
         * getTooltipContentArray
         *
         * @param 	array 	$params 	Params to construct tooltip data
         * @since 	v18
         * @return 	array
         */
        public function getTooltipContentArray($params)
        {
        }
        /**
         *  Return a link to the object card (with optionaly the picto)
         *
         *  @param  int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
         *  @param  string  $option                     On what the link point to ('nolink', ...)
         *  @param  int     $notooltip                  1=Disable tooltip
         *  @param  string  $morecss                    Add more css on link
         *  @param  int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
         *  @return	string                              String with URL
         */
        public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
        {
        }
        /**
         *	Return a thumb for kanban views
         *
         *	@param      string	    $option                 Where point the link (0=> main card, 1,2 => shipment, 'nolink'=>No link)
         *  @param		array		$arraydata				Array of data
         *  @return		string								HTML Code for Kanban thumb.
         */
        public function getKanbanView($option = '', $arraydata = \null)
        {
        }
        /**
         *  Return the label of the status
         *
         *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
         *  @return	string 			       Label of status
         */
        public function getLabelStatus($mode = 0)
        {
        }
        /**
         *  Return the label of the status
         *
         *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
         *  @return	string 			       Label of status
         */
        public function getLibStatut($mode = 0)
        {
        }
        // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
        /**
         *  Return the label of a given status
         *
         *  @param	int		$status        Id status
         *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
         *  @return string 			       Label of status
         */
        public function LibStatut($status, $mode = 0)
        {
        }
        /**
         *	Load the info information in the object
         *
         *	@param  int		$id       Id of object
         *	@return	void
         */
        public function info($id)
        {
        }
        /**
         * Initialise object with example values
         * Id must be 0 if object instance is a specimen
         *
         * @return void
         */
        public function initAsSpecimen()
        {
        }
        /**
         * 	Create an array of lines
         *
         * 	@return array|int		array of lines if OK, <0 if KO
         */
        public function getLinesArray()
        {
        }
        /**
         *  Returns the reference to the following non used object depending on the active numbering module.
         *
         *  @return string      		Object free reference
         */
        public function getNextNumRef()
        {
        }
        /**
         *  Create a document onto disk according to template module.
         *
         *  @param	    string		$modele			Force template to use ('' to not force)
         *  @param		Translate	$outputlangs	objet lang a utiliser pour traduction
         *  @param      int			$hidedetails    Hide details of lines
         *  @param      int			$hidedesc       Hide description
         *  @param      int			$hideref        Hide ref
         *  @param      null|array  $moreparams     Array to provide more information
         *  @return     int         				0 if KO, 1 if OK
         */
        public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = \null)
        {
        }
        /**
         * Action executed by scheduler
         * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
         * Use public function doScheduledJob($param1, $param2, ...) to get parameters
         *
         * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
         */
        public function doScheduledJob()
        {
        }
        public function getModuleName($id)
        {
        }
        public function getAllModulesNames()
        {
        }
    }
    /**
     * Class AuthLine. You can also remove this and generate a CRUD class for lines objects.
     */
    class AuthLine extends \CommonObjectLine
    {
        // To complete with content of an object AuthLine
        // We should have a field rowid, fk_auth and position
        /**
         * @var int  Does object support extrafields ? 0=No, 1=Yes
         */
        public $isextrafieldmanaged = 0;
        /**
         * Constructor
         *
         * @param DoliDb $db Database handler
         */
        public function __construct(\DoliDB $db)
        {
        }
    }
    /**
     *  Description and activation class for module Smartauth
     */
    class modSmartauth extends \DolibarrModules
    {
        /**
         * Constructor. Define names, constants, directories, boxes, permissions
         *
         * @param DoliDB $db Database handler
         */
        public function __construct($db)
        {
        }
        /**
         *  Function called when module is enabled.
         *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
         *  It also creates data directories
         *
         *  @param      string  $options    Options when enabling module ('', 'noboxes')
         *  @return     int             	1 if OK, 0 if KO
         */
        public function init($options = '')
        {
        }
        /**
         *  Function called when module is disabled.
         *  Remove from database constants, boxes and permissions from Dolibarr database.
         *  Data directories are not deleted
         *
         *  @param      string	$options    Options when enabling module ('', 'noboxes')
         *  @return     int                 1 if OK, 0 if KO
         */
        public function remove($options = '')
        {
        }
    }
}
namespace test\functional {
    /**
     * Class SmartauthFunctionalTest
     *
     * Requires chromedriver for Google Chrome
     * Requires geckodriver for Mozilla Firefox
     *
     * @fixme Firefox (Geckodriver/Marionette) support
     * @todo Opera linux support
     * @todo Windows support (IE, Google Chrome, Mozilla Firefox, Safari)
     * @todo OSX support (Safari, Google Chrome, Mozilla Firefox)
     *
     * @package Testsmartauth
     */
    class SmartauthFunctionalTest extends \PHPUnit_Extensions_Selenium2TestCase
    {
        // TODO: move to a global configuration file?
        /** @var string Base URL of the webserver under test */
        protected static $base_url = 'http://dev.zenfusion.fr';
        /**
         * @var string Dolibarr admin username
         * @see authenticate
         */
        protected static $dol_admin_user = 'admin';
        /**
         * @var string Dolibarr admin password
         * @see authenticate
         */
        protected static $dol_admin_pass = 'admin';
        /** @var int Dolibarr module ID */
        private static $module_id = 436132;
        // TODO: autodetect?
        /** @var array Browsers to test with */
        public static $browsers = array(array('browser' => 'Google Chrome on Linux', 'browserName' => 'chrome', 'sessionStrategy' => 'shared', 'desiredCapabilities' => array()));
        /**
         * Helper function to select links by href
         *
         * @param  string  $value      Href
         * @return mixed               Helper string
         */
        protected function byHref($value)
        {
        }
        /**
         * Global test setup
         * @return void
         */
        public static function setUpBeforeClass()
        {
        }
        /**
         * Unit test setup
         * @return void
         */
        public function setUp()
        {
        }
        /**
         * Verify pre conditions
         * @return void
         */
        protected function assertPreConditions()
        {
        }
        /**
         * Handle Dolibarr authentication
         * @return void
         */
        private function authenticate()
        {
        }
        /**
         * Test enabling developer mode
         * @return bool
         */
        public function testEnableDeveloperMode()
        {
        }
        /**
         * Test enabling the module
         *
         * @depends testEnableDeveloperMode
         * @return bool
         */
        public function testModuleEnabled()
        {
        }
        /**
         * Test access to the configuration page
         *
         * @depends testModuleEnabled
         * @return bool
         */
        public function testConfigurationPage()
        {
        }
        /**
         * Test access to the about page
         *
         * @depends testConfigurationPage
         * @return bool
         */
        public function testAboutPage()
        {
        }
        /**
         * Test about page is rendering Markdown
         *
         * @depends testAboutPage
         * @return bool
         */
        public function testAboutPageRendersMarkdownReadme()
        {
        }
        /**
         * Test box is properly declared
         *
         * @depends testModuleEnabled
         * @return bool
         */
        public function testBoxDeclared()
        {
        }
        /**
         * Test trigger is properly enabled
         *
         * @depends testModuleEnabled
         * @return bool
         */
        public function testTriggerDeclared()
        {
        }
        /**
         * Test trigger is properly declared
         *
         * @depends testTriggerDeclared
         * @return bool
         */
        public function testTriggerEnabled()
        {
        }
        /**
         * Verify post conditions
         * @return void
         */
        protected function assertPostConditions()
        {
        }
        /**
         * Unit test teardown
         * @return void
         */
        public function tearDown()
        {
        }
        /**
         * Global test teardown
         * @return void
         */
        public static function tearDownAfterClass()
        {
        }
    }
}
namespace {
    // autoload_real.php @generated by Composer
    class ComposerAutoloaderInitb76efb59d05331ba879d58f6b554dce3
    {
        private static $loader;
        public static function loadClassLoader($class)
        {
        }
        /**
         * @return \Composer\Autoload\ClassLoader
         */
        public static function getLoader()
        {
        }
    }
}
namespace Composer\Autoload {
    class ComposerStaticInitb76efb59d05331ba879d58f6b554dce3
    {
        public static $files = array('3109cb1a231dcd04bee1f9f620d46975' => __DIR__ . '/..' . '/paragonie/sodium_compat/autoload.php');
        public static $prefixLengthsPsr4 = array('F' => array('Firebase\\JWT\\' => 13));
        public static $prefixDirsPsr4 = array('Firebase\\JWT\\' => array(0 => __DIR__ . '/..' . '/firebase/php-jwt/src'));
        public static $classMap = array('Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php');
        public static function getInitializer(\Composer\Autoload\ClassLoader $loader)
        {
        }
    }
}
namespace Firebase\JWT {
    interface JWTExceptionWithPayloadInterface
    {
        /**
         * Get the payload that caused this exception.
         *
         * @return object
         */
        public function getPayload() : object;
        /**
         * Get the payload that caused this exception.
         *
         * @param object $payload
         * @return void
         */
        public function setPayload(object $payload) : void;
    }
    class BeforeValidException extends \UnexpectedValueException implements \Firebase\JWT\JWTExceptionWithPayloadInterface
    {
        private object $payload;
        public function setPayload(object $payload) : void
        {
        }
        public function getPayload() : object
        {
        }
    }
    /**
     * @implements ArrayAccess<string, Key>
     */
    class CachedKeySet implements \ArrayAccess
    {
        /**
         * @var string
         */
        private $jwksUri;
        /**
         * @var ClientInterface
         */
        private $httpClient;
        /**
         * @var RequestFactoryInterface
         */
        private $httpFactory;
        /**
         * @var CacheItemPoolInterface
         */
        private $cache;
        /**
         * @var ?int
         */
        private $expiresAfter;
        /**
         * @var ?CacheItemInterface
         */
        private $cacheItem;
        /**
         * @var array<string, array<mixed>>
         */
        private $keySet;
        /**
         * @var string
         */
        private $cacheKey;
        /**
         * @var string
         */
        private $cacheKeyPrefix = 'jwks';
        /**
         * @var int
         */
        private $maxKeyLength = 64;
        /**
         * @var bool
         */
        private $rateLimit;
        /**
         * @var string
         */
        private $rateLimitCacheKey;
        /**
         * @var int
         */
        private $maxCallsPerMinute = 10;
        /**
         * @var string|null
         */
        private $defaultAlg;
        public function __construct(string $jwksUri, \Psr\Http\Client\ClientInterface $httpClient, \Psr\Http\Message\RequestFactoryInterface $httpFactory, \Psr\Cache\CacheItemPoolInterface $cache, int $expiresAfter = null, bool $rateLimit = false, string $defaultAlg = null)
        {
        }
        /**
         * @param string $keyId
         * @return Key
         */
        public function offsetGet($keyId) : \Firebase\JWT\Key
        {
        }
        /**
         * @param string $keyId
         * @return bool
         */
        public function offsetExists($keyId) : bool
        {
        }
        /**
         * @param string $offset
         * @param Key $value
         */
        public function offsetSet($offset, $value) : void
        {
        }
        /**
         * @param string $offset
         */
        public function offsetUnset($offset) : void
        {
        }
        /**
         * @return array<mixed>
         */
        private function formatJwksForCache(string $jwks) : array
        {
        }
        private function keyIdExists(string $keyId) : bool
        {
        }
        private function rateLimitExceeded() : bool
        {
        }
        private function getCacheItem() : \Psr\Cache\CacheItemInterface
        {
        }
        private function setCacheKeys() : void
        {
        }
    }
    class ExpiredException extends \UnexpectedValueException implements \Firebase\JWT\JWTExceptionWithPayloadInterface
    {
        private object $payload;
        public function setPayload(object $payload) : void
        {
        }
        public function getPayload() : object
        {
        }
    }
    /**
     * JSON Web Key implementation, based on this spec:
     * https://tools.ietf.org/html/draft-ietf-jose-json-web-key-41
     *
     * PHP version 5
     *
     * @category Authentication
     * @package  Authentication_JWT
     * @author   Bui Sy Nguyen <nguyenbs@gmail.com>
     * @license  http://opensource.org/licenses/BSD-3-Clause 3-clause BSD
     * @link     https://github.com/firebase/php-jwt
     */
    class JWK
    {
        private const OID = '1.2.840.10045.2.1';
        private const ASN1_OBJECT_IDENTIFIER = 0x6;
        private const ASN1_SEQUENCE = 0x10;
        // also defined in JWT
        private const ASN1_BIT_STRING = 0x3;
        private const EC_CURVES = [
            'P-256' => '1.2.840.10045.3.1.7',
            // Len: 64
            'secp256k1' => '1.3.132.0.10',
            // Len: 64
            'P-384' => '1.3.132.0.34',
        ];
        // For keys with "kty" equal to "OKP" (Octet Key Pair), the "crv" parameter must contain the key subtype.
        // This library supports the following subtypes:
        private const OKP_SUBTYPES = ['Ed25519' => true];
        /**
         * Parse a set of JWK keys
         *
         * @param array<mixed> $jwks The JSON Web Key Set as an associative array
         * @param string       $defaultAlg The algorithm for the Key object if "alg" is not set in the
         *                                 JSON Web Key Set
         *
         * @return array<string, Key> An associative array of key IDs (kid) to Key objects
         *
         * @throws InvalidArgumentException     Provided JWK Set is empty
         * @throws UnexpectedValueException     Provided JWK Set was invalid
         * @throws DomainException              OpenSSL failure
         *
         * @uses parseKey
         */
        public static function parseKeySet(array $jwks, string $defaultAlg = null) : array
        {
        }
        /**
         * Parse a JWK key
         *
         * @param array<mixed> $jwk An individual JWK
         * @param string       $defaultAlg The algorithm for the Key object if "alg" is not set in the
         *                                 JSON Web Key Set
         *
         * @return Key The key object for the JWK
         *
         * @throws InvalidArgumentException     Provided JWK is empty
         * @throws UnexpectedValueException     Provided JWK was invalid
         * @throws DomainException              OpenSSL failure
         *
         * @uses createPemFromModulusAndExponent
         */
        public static function parseKey(array $jwk, string $defaultAlg = null) : ?\Firebase\JWT\Key
        {
        }
        /**
         * Converts the EC JWK values to pem format.
         *
         * @param   string  $crv The EC curve (only P-256 & P-384 is supported)
         * @param   string  $x   The EC x-coordinate
         * @param   string  $y   The EC y-coordinate
         *
         * @return  string
         */
        private static function createPemFromCrvAndXYCoordinates(string $crv, string $x, string $y) : string
        {
        }
        /**
         * Create a public key represented in PEM format from RSA modulus and exponent information
         *
         * @param string $n The RSA modulus encoded in Base64
         * @param string $e The RSA exponent encoded in Base64
         *
         * @return string The RSA public key represented in PEM format
         *
         * @uses encodeLength
         */
        private static function createPemFromModulusAndExponent(string $n, string $e) : string
        {
        }
        /**
         * DER-encode the length
         *
         * DER supports lengths up to (2**8)**127, however, we'll only support lengths up to (2**8)**4.  See
         * {@link http://itu.int/ITU-T/studygroups/com17/languages/X.690-0207.pdf#p=13 X.690 paragraph 8.1.3} for more information.
         *
         * @param int $length
         * @return string
         */
        private static function encodeLength(int $length) : string
        {
        }
        /**
         * Encodes a value into a DER object.
         * Also defined in Firebase\JWT\JWT
         *
         * @param   int     $type DER tag
         * @param   string  $value the value to encode
         * @return  string  the encoded object
         */
        private static function encodeDER(int $type, string $value) : string
        {
        }
        /**
         * Encodes a string into a DER-encoded OID.
         *
         * @param   string $oid the OID string
         * @return  string the binary DER-encoded OID
         */
        private static function encodeOID(string $oid) : string
        {
        }
    }
    /**
     * JSON Web Token implementation, based on this spec:
     * https://tools.ietf.org/html/rfc7519
     *
     * PHP version 5
     *
     * @category Authentication
     * @package  Authentication_JWT
     * @author   Neuman Vong <neuman@twilio.com>
     * @author   Anant Narayanan <anant@php.net>
     * @license  http://opensource.org/licenses/BSD-3-Clause 3-clause BSD
     * @link     https://github.com/firebase/php-jwt
     */
    class JWT
    {
        private const ASN1_INTEGER = 0x2;
        private const ASN1_SEQUENCE = 0x10;
        private const ASN1_BIT_STRING = 0x3;
        /**
         * When checking nbf, iat or expiration times,
         * we want to provide some extra leeway time to
         * account for clock skew.
         *
         * @var int
         */
        public static $leeway = 0;
        /**
         * Allow the current timestamp to be specified.
         * Useful for fixing a value within unit testing.
         * Will default to PHP time() value if null.
         *
         * @var ?int
         */
        public static $timestamp = null;
        /**
         * @var array<string, string[]>
         */
        public static $supported_algs = ['ES384' => ['openssl', 'SHA384'], 'ES256' => ['openssl', 'SHA256'], 'ES256K' => ['openssl', 'SHA256'], 'HS256' => ['hash_hmac', 'SHA256'], 'HS384' => ['hash_hmac', 'SHA384'], 'HS512' => ['hash_hmac', 'SHA512'], 'RS256' => ['openssl', 'SHA256'], 'RS384' => ['openssl', 'SHA384'], 'RS512' => ['openssl', 'SHA512'], 'EdDSA' => ['sodium_crypto', 'EdDSA']];
        /**
         * Decodes a JWT string into a PHP object.
         *
         * @param string                 $jwt            The JWT
         * @param Key|ArrayAccess<string,Key>|array<string,Key> $keyOrKeyArray  The Key or associative array of key IDs
         *                                                                      (kid) to Key objects.
         *                                                                      If the algorithm used is asymmetric, this is
         *                                                                      the public key.
         *                                                                      Each Key object contains an algorithm and
         *                                                                      matching key.
         *                                                                      Supported algorithms are 'ES384','ES256',
         *                                                                      'HS256', 'HS384', 'HS512', 'RS256', 'RS384'
         *                                                                      and 'RS512'.
         * @param stdClass               $headers                               Optional. Populates stdClass with headers.
         *
         * @return stdClass The JWT's payload as a PHP object
         *
         * @throws InvalidArgumentException     Provided key/key-array was empty or malformed
         * @throws DomainException              Provided JWT is malformed
         * @throws UnexpectedValueException     Provided JWT was invalid
         * @throws SignatureInvalidException    Provided JWT was invalid because the signature verification failed
         * @throws BeforeValidException         Provided JWT is trying to be used before it's eligible as defined by 'nbf'
         * @throws BeforeValidException         Provided JWT is trying to be used before it's been created as defined by 'iat'
         * @throws ExpiredException             Provided JWT has since expired, as defined by the 'exp' claim
         *
         * @uses jsonDecode
         * @uses urlsafeB64Decode
         */
        public static function decode(string $jwt, $keyOrKeyArray, \stdClass &$headers = null) : \stdClass
        {
        }
        /**
         * Converts and signs a PHP array into a JWT string.
         *
         * @param array<mixed>          $payload PHP array
         * @param string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate $key The secret key.
         * @param string                $alg     Supported algorithms are 'ES384','ES256', 'ES256K', 'HS256',
         *                                       'HS384', 'HS512', 'RS256', 'RS384', and 'RS512'
         * @param string                $keyId
         * @param array<string, string> $head    An array with header elements to attach
         *
         * @return string A signed JWT
         *
         * @uses jsonEncode
         * @uses urlsafeB64Encode
         */
        public static function encode(array $payload, $key, string $alg, string $keyId = null, array $head = null) : string
        {
        }
        /**
         * Sign a string with a given key and algorithm.
         *
         * @param string $msg  The message to sign
         * @param string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate  $key  The secret key.
         * @param string $alg  Supported algorithms are 'EdDSA', 'ES384', 'ES256', 'ES256K', 'HS256',
         *                    'HS384', 'HS512', 'RS256', 'RS384', and 'RS512'
         *
         * @return string An encrypted message
         *
         * @throws DomainException Unsupported algorithm or bad key was specified
         */
        public static function sign(string $msg, $key, string $alg) : string
        {
        }
        /**
         * Verify a signature with the message, key and method. Not all methods
         * are symmetric, so we must have a separate verify and sign method.
         *
         * @param string $msg         The original message (header and body)
         * @param string $signature   The original signature
         * @param string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate  $keyMaterial For Ed*, ES*, HS*, a string key works. for RS*, must be an instance of OpenSSLAsymmetricKey
         * @param string $alg         The algorithm
         *
         * @return bool
         *
         * @throws DomainException Invalid Algorithm, bad key, or OpenSSL failure
         */
        private static function verify(string $msg, string $signature, $keyMaterial, string $alg) : bool
        {
        }
        /**
         * Decode a JSON string into a PHP object.
         *
         * @param string $input JSON string
         *
         * @return mixed The decoded JSON string
         *
         * @throws DomainException Provided string was invalid JSON
         */
        public static function jsonDecode(string $input)
        {
        }
        /**
         * Encode a PHP array into a JSON string.
         *
         * @param array<mixed> $input A PHP array
         *
         * @return string JSON representation of the PHP array
         *
         * @throws DomainException Provided object could not be encoded to valid JSON
         */
        public static function jsonEncode(array $input) : string
        {
        }
        /**
         * Decode a string with URL-safe Base64.
         *
         * @param string $input A Base64 encoded string
         *
         * @return string A decoded string
         *
         * @throws InvalidArgumentException invalid base64 characters
         */
        public static function urlsafeB64Decode(string $input) : string
        {
        }
        /**
         * Convert a string in the base64url (URL-safe Base64) encoding to standard base64.
         *
         * @param string $input A Base64 encoded string with URL-safe characters (-_ and no padding)
         *
         * @return string A Base64 encoded string with standard characters (+/) and padding (=), when
         * needed.
         *
         * @see https://www.rfc-editor.org/rfc/rfc4648
         */
        public static function convertBase64UrlToBase64(string $input) : string
        {
        }
        /**
         * Encode a string with URL-safe Base64.
         *
         * @param string $input The string you want encoded
         *
         * @return string The base64 encode of what you passed in
         */
        public static function urlsafeB64Encode(string $input) : string
        {
        }
        /**
         * Determine if an algorithm has been provided for each Key
         *
         * @param Key|ArrayAccess<string,Key>|array<string,Key> $keyOrKeyArray
         * @param string|null            $kid
         *
         * @throws UnexpectedValueException
         *
         * @return Key
         */
        private static function getKey($keyOrKeyArray, ?string $kid) : \Firebase\JWT\Key
        {
        }
        /**
         * @param string $left  The string of known length to compare against
         * @param string $right The user-supplied string
         * @return bool
         */
        public static function constantTimeEquals(string $left, string $right) : bool
        {
        }
        /**
         * Helper method to create a JSON error.
         *
         * @param int $errno An error number from json_last_error()
         *
         * @throws DomainException
         *
         * @return void
         */
        private static function handleJsonError(int $errno) : void
        {
        }
        /**
         * Get the number of bytes in cryptographic strings.
         *
         * @param string $str
         *
         * @return int
         */
        private static function safeStrlen(string $str) : int
        {
        }
        /**
         * Convert an ECDSA signature to an ASN.1 DER sequence
         *
         * @param   string $sig The ECDSA signature to convert
         * @return  string The encoded DER object
         */
        private static function signatureToDER(string $sig) : string
        {
        }
        /**
         * Encodes a value into a DER object.
         *
         * @param   int     $type DER tag
         * @param   string  $value the value to encode
         *
         * @return  string  the encoded object
         */
        private static function encodeDER(int $type, string $value) : string
        {
        }
        /**
         * Encodes signature from a DER object.
         *
         * @param   string  $der binary signature in DER format
         * @param   int     $keySize the number of bits in the key
         *
         * @return  string  the signature
         */
        private static function signatureFromDER(string $der, int $keySize) : string
        {
        }
        /**
         * Reads binary DER-encoded data and decodes into a single object
         *
         * @param string $der the binary data in DER format
         * @param int $offset the offset of the data stream containing the object
         * to decode
         *
         * @return array{int, string|null} the new offset and the decoded object
         */
        private static function readDER(string $der, int $offset = 0) : array
        {
        }
    }
    class Key
    {
        /** @var string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate */
        private $keyMaterial;
        /** @var string */
        private $algorithm;
        /**
         * @param string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate $keyMaterial
         * @param string $algorithm
         */
        public function __construct($keyMaterial, string $algorithm)
        {
        }
        /**
         * Return the algorithm valid for this key
         *
         * @return string
         */
        public function getAlgorithm() : string
        {
        }
        /**
         * @return string|resource|OpenSSLAsymmetricKey|OpenSSLCertificate
         */
        public function getKeyMaterial()
        {
        }
    }
    class SignatureInvalidException extends \UnexpectedValueException
    {
    }
}
namespace {
    class ParagonIE_Sodium_Compat
    {
        /**
         * This parameter prevents the use of the PECL extension.
         * It should only be used for unit testing.
         *
         * @var bool
         */
        public static $disableFallbackForUnitTests = \false;
        /**
         * Use fast multiplication rather than our constant-time multiplication
         * implementation. Can be enabled at runtime. Only enable this if you
         * are absolutely certain that there is no timing leak on your platform.
         *
         * @var bool
         */
        public static $fastMult = \false;
        const LIBRARY_MAJOR_VERSION = 9;
        const LIBRARY_MINOR_VERSION = 1;
        const LIBRARY_VERSION_MAJOR = 9;
        const LIBRARY_VERSION_MINOR = 1;
        const VERSION_STRING = 'polyfill-1.0.8';
        // From libsodium
        const BASE64_VARIANT_ORIGINAL = 1;
        const BASE64_VARIANT_ORIGINAL_NO_PADDING = 3;
        const BASE64_VARIANT_URLSAFE = 5;
        const BASE64_VARIANT_URLSAFE_NO_PADDING = 7;
        const CRYPTO_AEAD_AES256GCM_KEYBYTES = 32;
        const CRYPTO_AEAD_AES256GCM_NSECBYTES = 0;
        const CRYPTO_AEAD_AES256GCM_NPUBBYTES = 12;
        const CRYPTO_AEAD_AES256GCM_ABYTES = 16;
        const CRYPTO_AEAD_AEGIS128L_KEYBYTES = 16;
        const CRYPTO_AEAD_AEGIS128L_NSECBYTES = 0;
        const CRYPTO_AEAD_AEGIS128L_NPUBBYTES = 16;
        const CRYPTO_AEAD_AEGIS128L_ABYTES = 32;
        const CRYPTO_AEAD_AEGIS256_KEYBYTES = 32;
        const CRYPTO_AEAD_AEGIS256_NSECBYTES = 0;
        const CRYPTO_AEAD_AEGIS256_NPUBBYTES = 32;
        const CRYPTO_AEAD_AEGIS256_ABYTES = 32;
        const CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES = 32;
        const CRYPTO_AEAD_CHACHA20POLY1305_NSECBYTES = 0;
        const CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES = 8;
        const CRYPTO_AEAD_CHACHA20POLY1305_ABYTES = 16;
        const CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES = 32;
        const CRYPTO_AEAD_CHACHA20POLY1305_IETF_NSECBYTES = 0;
        const CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES = 12;
        const CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES = 16;
        const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES = 32;
        const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECBYTES = 0;
        const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES = 24;
        const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES = 16;
        const CRYPTO_AUTH_BYTES = 32;
        const CRYPTO_AUTH_KEYBYTES = 32;
        const CRYPTO_BOX_SEALBYTES = 16;
        const CRYPTO_BOX_SECRETKEYBYTES = 32;
        const CRYPTO_BOX_PUBLICKEYBYTES = 32;
        const CRYPTO_BOX_KEYPAIRBYTES = 64;
        const CRYPTO_BOX_MACBYTES = 16;
        const CRYPTO_BOX_NONCEBYTES = 24;
        const CRYPTO_BOX_SEEDBYTES = 32;
        const CRYPTO_CORE_RISTRETTO255_BYTES = 32;
        const CRYPTO_CORE_RISTRETTO255_SCALARBYTES = 32;
        const CRYPTO_CORE_RISTRETTO255_HASHBYTES = 64;
        const CRYPTO_CORE_RISTRETTO255_NONREDUCEDSCALARBYTES = 64;
        const CRYPTO_KDF_BYTES_MIN = 16;
        const CRYPTO_KDF_BYTES_MAX = 64;
        const CRYPTO_KDF_CONTEXTBYTES = 8;
        const CRYPTO_KDF_KEYBYTES = 32;
        const CRYPTO_KX_BYTES = 32;
        const CRYPTO_KX_PRIMITIVE = 'x25519blake2b';
        const CRYPTO_KX_SEEDBYTES = 32;
        const CRYPTO_KX_KEYPAIRBYTES = 64;
        const CRYPTO_KX_PUBLICKEYBYTES = 32;
        const CRYPTO_KX_SECRETKEYBYTES = 32;
        const CRYPTO_KX_SESSIONKEYBYTES = 32;
        const CRYPTO_GENERICHASH_BYTES = 32;
        const CRYPTO_GENERICHASH_BYTES_MIN = 16;
        const CRYPTO_GENERICHASH_BYTES_MAX = 64;
        const CRYPTO_GENERICHASH_KEYBYTES = 32;
        const CRYPTO_GENERICHASH_KEYBYTES_MIN = 16;
        const CRYPTO_GENERICHASH_KEYBYTES_MAX = 64;
        const CRYPTO_PWHASH_SALTBYTES = 16;
        const CRYPTO_PWHASH_STRPREFIX = '$argon2id$';
        const CRYPTO_PWHASH_ALG_ARGON2I13 = 1;
        const CRYPTO_PWHASH_ALG_ARGON2ID13 = 2;
        const CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE = 33554432;
        const CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE = 4;
        const CRYPTO_PWHASH_MEMLIMIT_MODERATE = 134217728;
        const CRYPTO_PWHASH_OPSLIMIT_MODERATE = 6;
        const CRYPTO_PWHASH_MEMLIMIT_SENSITIVE = 536870912;
        const CRYPTO_PWHASH_OPSLIMIT_SENSITIVE = 8;
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES = 32;
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRPREFIX = '$7$';
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE = 534288;
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE = 16777216;
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_SENSITIVE = 33554432;
        const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_SENSITIVE = 1073741824;
        const CRYPTO_SCALARMULT_BYTES = 32;
        const CRYPTO_SCALARMULT_SCALARBYTES = 32;
        const CRYPTO_SCALARMULT_RISTRETTO255_BYTES = 32;
        const CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES = 32;
        const CRYPTO_SHORTHASH_BYTES = 8;
        const CRYPTO_SHORTHASH_KEYBYTES = 16;
        const CRYPTO_SECRETBOX_KEYBYTES = 32;
        const CRYPTO_SECRETBOX_MACBYTES = 16;
        const CRYPTO_SECRETBOX_NONCEBYTES = 24;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES = 17;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES = 24;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES = 32;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH = 0;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PULL = 1;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_REKEY = 2;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL = 3;
        const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_MESSAGEBYTES_MAX = 0x3fffffff80;
        const CRYPTO_SIGN_BYTES = 64;
        const CRYPTO_SIGN_SEEDBYTES = 32;
        const CRYPTO_SIGN_PUBLICKEYBYTES = 32;
        const CRYPTO_SIGN_SECRETKEYBYTES = 64;
        const CRYPTO_SIGN_KEYPAIRBYTES = 96;
        const CRYPTO_STREAM_KEYBYTES = 32;
        const CRYPTO_STREAM_NONCEBYTES = 24;
        const CRYPTO_STREAM_XCHACHA20_KEYBYTES = 32;
        const CRYPTO_STREAM_XCHACHA20_NONCEBYTES = 24;
        /**
         * Add two numbers (little-endian unsigned), storing the value in the first
         * parameter.
         *
         * This mutates $val.
         *
         * @param string $val
         * @param string $addv
         * @return void
         * @throws SodiumException
         */
        public static function add(&$val, $addv)
        {
        }
        /**
         * @param string $encoded
         * @param int $variant
         * @param string $ignore
         * @return string
         * @throws SodiumException
         */
        public static function base642bin($encoded, $variant, $ignore = '')
        {
        }
        /**
         * @param string $decoded
         * @param int $variant
         * @return string
         * @throws SodiumException
         */
        public static function bin2base64($decoded, $variant)
        {
        }
        /**
         * Cache-timing-safe implementation of bin2hex().
         *
         * @param string $string A string (probably raw binary)
         * @return string        A hexadecimal-encoded string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function bin2hex($string)
        {
        }
        /**
         * Compare two strings, in constant-time.
         * Compared to memcmp(), compare() is more useful for sorting.
         *
         * @param string $left  The left operand; must be a string
         * @param string $right The right operand; must be a string
         * @return int          If < 0 if the left operand is less than the right
         *                      If = 0 if both strings are equal
         *                      If > 0 if the right operand is less than the left
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function compare($left, $right)
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     AEGIS-128L
         *
         * @param string $ciphertext Encrypted message (with MAC appended)
         * @param string $assocData  Authenticated Associated Data (unencrypted)
         * @param string $nonce      Number to be used only Once; must be 32 bytes
         * @param string $key        Encryption key
         *
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_aegis128l_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Encryption
         *
         * Algorithm:
         *     AEGIS-128L
         *
         * @param string $plaintext Message to be encrypted
         * @param string $assocData Authenticated Associated Data (unencrypted)
         * @param string $nonce     Number to be used only Once; must be 32 bytes
         * @param string $key       Encryption key
         *
         * @return string           Ciphertext with 32-byte authentication tag appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_aegis128l_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Return a secure random key for use with the AEGIS-128L
         * symmetric AEAD interface.
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_aegis128l_keygen()
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     AEGIS-256
         *
         * @param string $ciphertext Encrypted message (with MAC appended)
         * @param string $assocData  Authenticated Associated Data (unencrypted)
         * @param string $nonce      Number to be used only Once; must be 32 bytes
         * @param string $key        Encryption key
         *
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_aegis256_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Encryption
         *
         * Algorithm:
         *     AEGIS-256
         *
         * @param string $plaintext Message to be encrypted
         * @param string $assocData Authenticated Associated Data (unencrypted)
         * @param string $nonce Number to be used only Once; must be 32 bytes
         * @param string $key Encryption key
         *
         * @return string           Ciphertext with 32-byte authentication tag appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_aegis256_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Return a secure random key for use with the AEGIS-256
         * symmetric AEAD interface.
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_aegis256_keygen()
        {
        }
        /**
         * Is AES-256-GCM even available to use?
         *
         * @return bool
         * @psalm-suppress UndefinedFunction
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_aes256gcm_is_available()
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     AES-256-GCM
         *
         * This mode uses a 64-bit random nonce with a 64-bit counter.
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         *
         * @param string $ciphertext Encrypted message (with Poly1305 MAC appended)
         * @param string $assocData  Authenticated Associated Data (unencrypted)
         * @param string $nonce      Number to be used only Once; must be 8 bytes
         * @param string $key        Encryption key
         *
         * @return string|bool       The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_aes256gcm_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Encryption
         *
         * Algorithm:
         *     AES-256-GCM
         *
         * @param string $plaintext Message to be encrypted
         * @param string $assocData Authenticated Associated Data (unencrypted)
         * @param string $nonce     Number to be used only Once; must be 8 bytes
         * @param string $key       Encryption key
         *
         * @return string           Ciphertext with a 16-byte GCM message
         *                          authentication code appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_aes256gcm_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Return a secure random key for use with the AES-256-GCM
         * symmetric AEAD interface.
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_aes256gcm_keygen()
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     ChaCha20-Poly1305
         *
         * This mode uses a 64-bit random nonce with a 64-bit counter.
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         *
         * @param string $ciphertext Encrypted message (with Poly1305 MAC appended)
         * @param string $assocData  Authenticated Associated Data (unencrypted)
         * @param string $nonce      Number to be used only Once; must be 8 bytes
         * @param string $key        Encryption key
         *
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_chacha20poly1305_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Authenticated Encryption with Associated Data
         *
         * Algorithm:
         *     ChaCha20-Poly1305
         *
         * This mode uses a 64-bit random nonce with a 64-bit counter.
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         *
         * @param string $plaintext Message to be encrypted
         * @param string $assocData Authenticated Associated Data (unencrypted)
         * @param string $nonce     Number to be used only Once; must be 8 bytes
         * @param string $key       Encryption key
         *
         * @return string           Ciphertext with a 16-byte Poly1305 message
         *                          authentication code appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_chacha20poly1305_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     ChaCha20-Poly1305
         *
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         * Regular mode uses a 64-bit random nonce with a 64-bit counter.
         *
         * @param string $ciphertext Encrypted message (with Poly1305 MAC appended)
         * @param string $assocData  Authenticated Associated Data (unencrypted)
         * @param string $nonce      Number to be used only Once; must be 12 bytes
         * @param string $key        Encryption key
         *
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_aead_chacha20poly1305_ietf_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Return a secure random key for use with the ChaCha20-Poly1305
         * symmetric AEAD interface.
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_chacha20poly1305_keygen()
        {
        }
        /**
         * Authenticated Encryption with Associated Data
         *
         * Algorithm:
         *     ChaCha20-Poly1305
         *
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         * Regular mode uses a 64-bit random nonce with a 64-bit counter.
         *
         * @param string $plaintext Message to be encrypted
         * @param string $assocData Authenticated Associated Data (unencrypted)
         * @param string $nonce Number to be used only Once; must be 8 bytes
         * @param string $key Encryption key
         *
         * @return string           Ciphertext with a 16-byte Poly1305 message
         *                          authentication code appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_chacha20poly1305_ietf_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '')
        {
        }
        /**
         * Return a secure random key for use with the ChaCha20-Poly1305
         * symmetric AEAD interface. (IETF version)
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_chacha20poly1305_ietf_keygen()
        {
        }
        /**
         * Authenticated Encryption with Associated Data: Decryption
         *
         * Algorithm:
         *     XChaCha20-Poly1305
         *
         * This mode uses a 64-bit random nonce with a 64-bit counter.
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         *
         * @param string $ciphertext   Encrypted message (with Poly1305 MAC appended)
         * @param string $assocData    Authenticated Associated Data (unencrypted)
         * @param string $nonce        Number to be used only Once; must be 8 bytes
         * @param string $key          Encryption key
         * @param bool   $dontFallback Don't fallback to ext/sodium
         *
         * @return string|bool         The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext = '', $assocData = '', $nonce = '', $key = '', $dontFallback = \false)
        {
        }
        /**
         * Authenticated Encryption with Associated Data
         *
         * Algorithm:
         *     XChaCha20-Poly1305
         *
         * This mode uses a 64-bit random nonce with a 64-bit counter.
         * IETF mode uses a 96-bit random nonce with a 32-bit counter.
         *
         * @param string $plaintext    Message to be encrypted
         * @param string $assocData    Authenticated Associated Data (unencrypted)
         * @param string $nonce        Number to be used only Once; must be 8 bytes
         * @param string $key          Encryption key
         * @param bool   $dontFallback Don't fallback to ext/sodium
         *
         * @return string           Ciphertext with a 16-byte Poly1305 message
         *                          authentication code appended
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext = '', $assocData = '', $nonce = '', $key = '', $dontFallback = \false)
        {
        }
        /**
         * Return a secure random key for use with the XChaCha20-Poly1305
         * symmetric AEAD interface.
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_aead_xchacha20poly1305_ietf_keygen()
        {
        }
        /**
         * Authenticate a message. Uses symmetric-key cryptography.
         *
         * Algorithm:
         *     HMAC-SHA512-256. Which is HMAC-SHA-512 truncated to 256 bits.
         *     Not to be confused with HMAC-SHA-512/256 which would use the
         *     SHA-512/256 hash function (uses different initial parameters
         *     but still truncates to 256 bits to sidestep length-extension
         *     attacks).
         *
         * @param string $message Message to be authenticated
         * @param string $key Symmetric authentication key
         * @return string         Message authentication code
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_auth($message, $key)
        {
        }
        /**
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_auth_keygen()
        {
        }
        /**
         * Verify the MAC of a message previously authenticated with crypto_auth.
         *
         * @param string $mac Message authentication code
         * @param string $message Message whose authenticity you are attempting to
         *                        verify (with a given MAC and key)
         * @param string $key Symmetric authentication key
         * @return bool           TRUE if authenticated, FALSE otherwise
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_auth_verify($mac, $message, $key)
        {
        }
        /**
         * Authenticated asymmetric-key encryption. Both the sender and recipient
         * may decrypt messages.
         *
         * Algorithm: X25519-XSalsa20-Poly1305.
         *     X25519: Elliptic-Curve Diffie Hellman over Curve25519.
         *     XSalsa20: Extended-nonce variant of salsa20.
         *     Poyl1305: Polynomial MAC for one-time message authentication.
         *
         * @param string $plaintext The message to be encrypted
         * @param string $nonce A Number to only be used Once; must be 24 bytes
         * @param string $keypair Your secret key and your recipient's public key
         * @return string           Ciphertext with 16-byte Poly1305 MAC
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box($plaintext, $nonce, $keypair)
        {
        }
        /**
         * Anonymous public-key encryption. Only the recipient may decrypt messages.
         *
         * Algorithm: X25519-XSalsa20-Poly1305, as with crypto_box.
         *     The sender's X25519 keypair is ephemeral.
         *     Nonce is generated from the BLAKE2b hash of both public keys.
         *
         * This provides ciphertext integrity.
         *
         * @param string $plaintext Message to be sealed
         * @param string $publicKey Your recipient's public key
         * @return string           Sealed message that only your recipient can
         *                          decrypt
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_seal($plaintext, $publicKey)
        {
        }
        /**
         * Opens a message encrypted with crypto_box_seal(). Requires
         * the recipient's keypair (sk || pk) to decrypt successfully.
         *
         * This validates ciphertext integrity.
         *
         * @param string $ciphertext Sealed message to be opened
         * @param string $keypair    Your crypto_box keypair
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_box_seal_open($ciphertext, $keypair)
        {
        }
        /**
         * Generate a new random X25519 keypair.
         *
         * @return string A 64-byte string; the first 32 are your secret key, while
         *                the last 32 are your public key. crypto_box_secretkey()
         *                and crypto_box_publickey() exist to separate them so you
         *                don't accidentally get them mixed up!
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_keypair()
        {
        }
        /**
         * Combine two keys into a keypair for use in library methods that expect
         * a keypair. This doesn't necessarily have to be the same person's keys.
         *
         * @param string $secretKey Secret key
         * @param string $publicKey Public key
         * @return string    Keypair
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey)
        {
        }
        /**
         * Decrypt a message previously encrypted with crypto_box().
         *
         * @param string $ciphertext Encrypted message
         * @param string $nonce      Number to only be used Once; must be 24 bytes
         * @param string $keypair    Your secret key and the sender's public key
         * @return string            The original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_box_open($ciphertext, $nonce, $keypair)
        {
        }
        /**
         * Extract the public key from a crypto_box keypair.
         *
         * @param string $keypair Keypair containing secret and public key
         * @return string         Your crypto_box public key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_publickey($keypair)
        {
        }
        /**
         * Calculate the X25519 public key from a given X25519 secret key.
         *
         * @param string $secretKey Any X25519 secret key
         * @return string           The corresponding X25519 public key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_publickey_from_secretkey($secretKey)
        {
        }
        /**
         * Extract the secret key from a crypto_box keypair.
         *
         * @param string $keypair
         * @return string         Your crypto_box secret key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_box_secretkey($keypair)
        {
        }
        /**
         * Generate an X25519 keypair from a seed.
         *
         * @param string $seed
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress UndefinedFunction
         */
        public static function crypto_box_seed_keypair($seed)
        {
        }
        /**
         * Calculates a BLAKE2b hash, with an optional key.
         *
         * @param string      $message The message to be hashed
         * @param string|null $key     If specified, must be a string between 16
         *                             and 64 bytes long
         * @param int         $length  Output length in bytes; must be between 16
         *                             and 64 (default = 32)
         * @return string              Raw binary
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_generichash($message, $key = '', $length = self::CRYPTO_GENERICHASH_BYTES)
        {
        }
        /**
         * Get the final BLAKE2b hash output for a given context.
         *
         * @param string $ctx BLAKE2 hashing context. Generated by crypto_generichash_init().
         * @param int $length Hash output size.
         * @return string     Final BLAKE2b hash.
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress ReferenceConstraintViolation
         * @psalm-suppress ConflictingReferenceConstraint
         */
        public static function crypto_generichash_final(&$ctx, $length = self::CRYPTO_GENERICHASH_BYTES)
        {
        }
        /**
         * Initialize a BLAKE2b hashing context, for use in a streaming interface.
         *
         * @param string|null $key If specified must be a string between 16 and 64 bytes
         * @param int $length      The size of the desired hash output
         * @return string          A BLAKE2 hashing context, encoded as a string
         *                         (To be 100% compatible with ext/libsodium)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_generichash_init($key = '', $length = self::CRYPTO_GENERICHASH_BYTES)
        {
        }
        /**
         * Initialize a BLAKE2b hashing context, for use in a streaming interface.
         *
         * @param string|null $key If specified must be a string between 16 and 64 bytes
         * @param int $length      The size of the desired hash output
         * @param string $salt     Salt (up to 16 bytes)
         * @param string $personal Personalization string (up to 16 bytes)
         * @return string          A BLAKE2 hashing context, encoded as a string
         *                         (To be 100% compatible with ext/libsodium)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_generichash_init_salt_personal($key = '', $length = self::CRYPTO_GENERICHASH_BYTES, $salt = '', $personal = '')
        {
        }
        /**
         * Update a BLAKE2b hashing context with additional data.
         *
         * @param string $ctx    BLAKE2 hashing context. Generated by crypto_generichash_init().
         *                       $ctx is passed by reference and gets updated in-place.
         * @param-out string $ctx
         * @param string $message The message to append to the existing hash state.
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress ReferenceConstraintViolation
         */
        public static function crypto_generichash_update(&$ctx, $message)
        {
        }
        /**
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_generichash_keygen()
        {
        }
        /**
         * @param int $subkey_len
         * @param int $subkey_id
         * @param string $context
         * @param string $key
         * @return string
         * @throws SodiumException
         */
        public static function crypto_kdf_derive_from_key($subkey_len, $subkey_id, $context, $key)
        {
        }
        /**
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_kdf_keygen()
        {
        }
        /**
         * Perform a key exchange, between a designated client and a server.
         *
         * Typically, you would designate one machine to be the client and the
         * other to be the server. The first two keys are what you'd expect for
         * scalarmult() below, but the latter two public keys don't swap places.
         *
         * | ALICE                          | BOB                                 |
         * | Client                         | Server                              |
         * |--------------------------------|-------------------------------------|
         * | shared = crypto_kx(            | shared = crypto_kx(                 |
         * |     alice_sk,                  |     bob_sk,                         | <- contextual
         * |     bob_pk,                    |     alice_pk,                       | <- contextual
         * |     alice_pk,                  |     alice_pk,                       | <----- static
         * |     bob_pk                     |     bob_pk                          | <----- static
         * | )                              | )                                   |
         *
         * They are used along with the scalarmult product to generate a 256-bit
         * BLAKE2b hash unique to the client and server keys.
         *
         * @param string $my_secret
         * @param string $their_public
         * @param string $client_public
         * @param string $server_public
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_kx($my_secret, $their_public, $client_public, $server_public, $dontFallback = \false)
        {
        }
        /**
         * @param string $seed
         * @return string
         * @throws SodiumException
         */
        public static function crypto_kx_seed_keypair($seed)
        {
        }
        /**
         * @return string
         * @throws Exception
         */
        public static function crypto_kx_keypair()
        {
        }
        /**
         * @param string $keypair
         * @param string $serverPublicKey
         * @return array{0: string, 1: string}
         * @throws SodiumException
         */
        public static function crypto_kx_client_session_keys($keypair, $serverPublicKey)
        {
        }
        /**
         * @param string $keypair
         * @param string $clientPublicKey
         * @return array{0: string, 1: string}
         * @throws SodiumException
         */
        public static function crypto_kx_server_session_keys($keypair, $clientPublicKey)
        {
        }
        /**
         * @param string $kp
         * @return string
         * @throws SodiumException
         */
        public static function crypto_kx_secretkey($kp)
        {
        }
        /**
         * @param string $kp
         * @return string
         * @throws SodiumException
         */
        public static function crypto_kx_publickey($kp)
        {
        }
        /**
         * @param int $outlen
         * @param string $passwd
         * @param string $salt
         * @param int $opslimit
         * @param int $memlimit
         * @param int|null $alg
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_pwhash($outlen, $passwd, $salt, $opslimit, $memlimit, $alg = \null)
        {
        }
        /**
         * !Exclusive to sodium_compat!
         *
         * This returns TRUE if the native crypto_pwhash API is available by libsodium.
         * This returns FALSE if only sodium_compat is available.
         *
         * @return bool
         */
        public static function crypto_pwhash_is_available()
        {
        }
        /**
         * @param string $passwd
         * @param int $opslimit
         * @param int $memlimit
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_pwhash_str($passwd, $opslimit, $memlimit)
        {
        }
        /**
         * Do we need to rehash this password?
         *
         * @param string $hash
         * @param int $opslimit
         * @param int $memlimit
         * @return bool
         * @throws SodiumException
         */
        public static function crypto_pwhash_str_needs_rehash($hash, $opslimit, $memlimit)
        {
        }
        /**
         * @param string $passwd
         * @param string $hash
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_pwhash_str_verify($passwd, $hash)
        {
        }
        /**
         * @param int $outlen
         * @param string $passwd
         * @param string $salt
         * @param int $opslimit
         * @param int $memlimit
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_pwhash_scryptsalsa208sha256($outlen, $passwd, $salt, $opslimit, $memlimit)
        {
        }
        /**
         * !Exclusive to sodium_compat!
         *
         * This returns TRUE if the native crypto_pwhash API is available by libsodium.
         * This returns FALSE if only sodium_compat is available.
         *
         * @return bool
         */
        public static function crypto_pwhash_scryptsalsa208sha256_is_available()
        {
        }
        /**
         * @param string $passwd
         * @param int $opslimit
         * @param int $memlimit
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_pwhash_scryptsalsa208sha256_str($passwd, $opslimit, $memlimit)
        {
        }
        /**
         * @param string $passwd
         * @param string $hash
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_pwhash_scryptsalsa208sha256_str_verify($passwd, $hash)
        {
        }
        /**
         * Calculate the shared secret between your secret key and your
         * recipient's public key.
         *
         * Algorithm: X25519 (ECDH over Curve25519)
         *
         * @param string $secretKey
         * @param string $publicKey
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_scalarmult($secretKey, $publicKey)
        {
        }
        /**
         * Calculate an X25519 public key from an X25519 secret key.
         *
         * @param string $secretKey
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress TooFewArguments
         * @psalm-suppress MixedArgument
         */
        public static function crypto_scalarmult_base($secretKey)
        {
        }
        /**
         * Authenticated symmetric-key encryption.
         *
         * Algorithm: XSalsa20-Poly1305
         *
         * @param string $plaintext The message you're encrypting
         * @param string $nonce A Number to be used Once; must be 24 bytes
         * @param string $key Symmetric encryption key
         * @return string           Ciphertext with Poly1305 MAC
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_secretbox($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypts a message previously encrypted with crypto_secretbox().
         *
         * @param string $ciphertext Ciphertext with Poly1305 MAC
         * @param string $nonce      A Number to be used Once; must be 24 bytes
         * @param string $key        Symmetric encryption key
         * @return string            Original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_secretbox_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * Return a secure random key for use with crypto_secretbox
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_secretbox_keygen()
        {
        }
        /**
         * Authenticated symmetric-key encryption.
         *
         * Algorithm: XChaCha20-Poly1305
         *
         * @param string $plaintext The message you're encrypting
         * @param string $nonce     A Number to be used Once; must be 24 bytes
         * @param string $key       Symmetric encryption key
         * @return string           Ciphertext with Poly1305 MAC
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_secretbox_xchacha20poly1305($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypts a message previously encrypted with crypto_secretbox_xchacha20poly1305().
         *
         * @param string $ciphertext Ciphertext with Poly1305 MAC
         * @param string $nonce      A Number to be used Once; must be 24 bytes
         * @param string $key        Symmetric encryption key
         * @return string            Original plaintext message
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_secretbox_xchacha20poly1305_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * @param string $key
         * @return array<int, string> Returns a state and a header.
         * @throws Exception
         * @throws SodiumException
         */
        public static function crypto_secretstream_xchacha20poly1305_init_push($key)
        {
        }
        /**
         * @param string $header
         * @param string $key
         * @return string Returns a state.
         * @throws Exception
         */
        public static function crypto_secretstream_xchacha20poly1305_init_pull($header, $key)
        {
        }
        /**
         * @param string $state
         * @param string $msg
         * @param string $aad
         * @param int $tag
         * @return string
         * @throws SodiumException
         */
        public static function crypto_secretstream_xchacha20poly1305_push(&$state, $msg, $aad = '', $tag = 0)
        {
        }
        /**
         * @param string $state
         * @param string $msg
         * @param string $aad
         * @return bool|array{0: string, 1: int}
         * @throws SodiumException
         */
        public static function crypto_secretstream_xchacha20poly1305_pull(&$state, $msg, $aad = '')
        {
        }
        /**
         * @return string
         * @throws Exception
         */
        public static function crypto_secretstream_xchacha20poly1305_keygen()
        {
        }
        /**
         * @param string $state
         * @return void
         * @throws SodiumException
         */
        public static function crypto_secretstream_xchacha20poly1305_rekey(&$state)
        {
        }
        /**
         * Calculates a SipHash-2-4 hash of a message for a given key.
         *
         * @param string $message Input message
         * @param string $key SipHash-2-4 key
         * @return string         Hash
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_shorthash($message, $key)
        {
        }
        /**
         * Return a secure random key for use with crypto_shorthash
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_shorthash_keygen()
        {
        }
        /**
         * Returns a signed message. You probably want crypto_sign_detached()
         * instead, which only returns the signature.
         *
         * Algorithm: Ed25519 (EdDSA over Curve25519)
         *
         * @param string $message Message to be signed.
         * @param string $secretKey Secret signing key.
         * @return string           Signed message (signature is prefixed).
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_sign($message, $secretKey)
        {
        }
        /**
         * Validates a signed message then returns the message.
         *
         * @param string $signedMessage A signed message
         * @param string $publicKey A public key
         * @return string               The original message (if the signature is
         *                              valid for this public key)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         */
        public static function crypto_sign_open($signedMessage, $publicKey)
        {
        }
        /**
         * Generate a new random Ed25519 keypair.
         *
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_sign_keypair()
        {
        }
        /**
         * @param string $sk
         * @param string $pk
         * @return string
         * @throws SodiumException
         */
        public static function crypto_sign_keypair_from_secretkey_and_publickey($sk, $pk)
        {
        }
        /**
         * Generate an Ed25519 keypair from a seed.
         *
         * @param string $seed Input seed
         * @return string      Keypair
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_seed_keypair($seed)
        {
        }
        /**
         * Extract an Ed25519 public key from an Ed25519 keypair.
         *
         * @param string $keypair Keypair
         * @return string         Public key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_publickey($keypair)
        {
        }
        /**
         * Calculate an Ed25519 public key from an Ed25519 secret key.
         *
         * @param string $secretKey Your Ed25519 secret key
         * @return string           The corresponding Ed25519 public key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_publickey_from_secretkey($secretKey)
        {
        }
        /**
         * Extract an Ed25519 secret key from an Ed25519 keypair.
         *
         * @param string $keypair Keypair
         * @return string         Secret key
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_secretkey($keypair)
        {
        }
        /**
         * Calculate the Ed25519 signature of a message and return ONLY the signature.
         *
         * Algorithm: Ed25519 (EdDSA over Curve25519)
         *
         * @param string $message Message to be signed
         * @param string $secretKey Secret signing key
         * @return string           Digital signature
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_detached($message, $secretKey)
        {
        }
        /**
         * Verify the Ed25519 signature of a message.
         *
         * @param string $signature Digital sginature
         * @param string $message Message to be verified
         * @param string $publicKey Public key
         * @return bool             TRUE if this signature is good for this public key;
         *                          FALSE otherwise
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_verify_detached($signature, $message, $publicKey)
        {
        }
        /**
         * Convert an Ed25519 public key to a Curve25519 public key
         *
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_ed25519_pk_to_curve25519($pk)
        {
        }
        /**
         * Convert an Ed25519 secret key to a Curve25519 secret key
         *
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_sign_ed25519_sk_to_curve25519($sk)
        {
        }
        /**
         * Expand a key and nonce into a keystream of pseudorandom bytes.
         *
         * @param int $len Number of bytes desired
         * @param string $nonce Number to be used Once; must be 24 bytes
         * @param string $key XSalsa20 key
         * @return string       Pseudorandom stream that can be XORed with messages
         *                      to provide encryption (but not authentication; see
         *                      Poly1305 or crypto_auth() for that, which is not
         *                      optional for security)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_stream($len, $nonce, $key)
        {
        }
        /**
         * DANGER! UNAUTHENTICATED ENCRYPTION!
         *
         * Unless you are following expert advice, do not use this feature.
         *
         * Algorithm: XSalsa20
         *
         * This DOES NOT provide ciphertext integrity.
         *
         * @param string $message Plaintext message
         * @param string $nonce Number to be used Once; must be 24 bytes
         * @param string $key Encryption key
         * @return string         Encrypted text which is vulnerable to chosen-
         *                        ciphertext attacks unless you implement some
         *                        other mitigation to the ciphertext (i.e.
         *                        Encrypt then MAC)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_stream_xor($message, $nonce, $key)
        {
        }
        /**
         * Return a secure random key for use with crypto_stream
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_stream_keygen()
        {
        }
        /**
         * Expand a key and nonce into a keystream of pseudorandom bytes.
         *
         * @param int $len Number of bytes desired
         * @param string $nonce Number to be used Once; must be 24 bytes
         * @param string $key XChaCha20 key
         * @param bool $dontFallback
         * @return string       Pseudorandom stream that can be XORed with messages
         *                      to provide encryption (but not authentication; see
         *                      Poly1305 or crypto_auth() for that, which is not
         *                      optional for security)
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_stream_xchacha20($len, $nonce, $key, $dontFallback = \false)
        {
        }
        /**
         * DANGER! UNAUTHENTICATED ENCRYPTION!
         *
         * Unless you are following expert advice, do not use this feature.
         *
         * Algorithm: XChaCha20
         *
         * This DOES NOT provide ciphertext integrity.
         *
         * @param string $message Plaintext message
         * @param string $nonce Number to be used Once; must be 24 bytes
         * @param string $key Encryption key
         * @return string         Encrypted text which is vulnerable to chosen-
         *                        ciphertext attacks unless you implement some
         *                        other mitigation to the ciphertext (i.e.
         *                        Encrypt then MAC)
         * @param bool $dontFallback
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_stream_xchacha20_xor($message, $nonce, $key, $dontFallback = \false)
        {
        }
        /**
         * DANGER! UNAUTHENTICATED ENCRYPTION!
         *
         * Unless you are following expert advice, do not use this feature.
         *
         * Algorithm: XChaCha20
         *
         * This DOES NOT provide ciphertext integrity.
         *
         * @param string $message Plaintext message
         * @param string $nonce Number to be used Once; must be 24 bytes
         * @param int $counter
         * @param string $key Encryption key
         * @return string         Encrypted text which is vulnerable to chosen-
         *                        ciphertext attacks unless you implement some
         *                        other mitigation to the ciphertext (i.e.
         *                        Encrypt then MAC)
         * @param bool $dontFallback
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function crypto_stream_xchacha20_xor_ic($message, $nonce, $counter, $key, $dontFallback = \false)
        {
        }
        /**
         * Return a secure random key for use with crypto_stream_xchacha20
         *
         * @return string
         * @throws Exception
         * @throws Error
         */
        public static function crypto_stream_xchacha20_keygen()
        {
        }
        /**
         * Cache-timing-safe implementation of hex2bin().
         *
         * @param string $string Hexadecimal string
         * @param string $ignore List of characters to ignore; useful for whitespace
         * @return string        Raw binary string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress TooFewArguments
         * @psalm-suppress MixedArgument
         */
        public static function hex2bin($string, $ignore = '')
        {
        }
        /**
         * Increase a string (little endian)
         *
         * @param string $var
         *
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function increment(&$var)
        {
        }
        /**
         * @param string $str
         * @return bool
         *
         * @throws SodiumException
         */
        public static function is_zero($str)
        {
        }
        /**
         * The equivalent to the libsodium minor version we aim to be compatible
         * with (sans pwhash and memzero).
         *
         * @return int
         */
        public static function library_version_major()
        {
        }
        /**
         * The equivalent to the libsodium minor version we aim to be compatible
         * with (sans pwhash and memzero).
         *
         * @return int
         */
        public static function library_version_minor()
        {
        }
        /**
         * Compare two strings.
         *
         * @param string $left
         * @param string $right
         * @return int
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         */
        public static function memcmp($left, $right)
        {
        }
        /**
         * It's actually not possible to zero memory buffers in PHP. You need the
         * native library for that.
         *
         * @param string|null $var
         * @param-out string|null $var
         *
         * @return void
         * @throws SodiumException (Unless libsodium is installed)
         * @throws TypeError
         * @psalm-suppress TooFewArguments
         */
        public static function memzero(&$var)
        {
        }
        /**
         * @param string $unpadded
         * @param int $blockSize
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function pad($unpadded, $blockSize, $dontFallback = \false)
        {
        }
        /**
         * @param string $padded
         * @param int $blockSize
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function unpad($padded, $blockSize, $dontFallback = \false)
        {
        }
        /**
         * Will sodium_compat run fast on the current hardware and PHP configuration?
         *
         * @return bool
         */
        public static function polyfill_is_fast()
        {
        }
        /**
         * Generate a string of bytes from the kernel's CSPRNG.
         * Proudly uses /dev/urandom (if getrandom(2) is not available).
         *
         * @param int $numBytes
         * @return string
         * @throws Exception
         * @throws TypeError
         */
        public static function randombytes_buf($numBytes)
        {
        }
        /**
         * Generate an integer between 0 and $range (non-inclusive).
         *
         * @param int $range
         * @return int
         * @throws Exception
         * @throws Error
         * @throws TypeError
         */
        public static function randombytes_uniform($range)
        {
        }
        /**
         * Generate a random 16-bit integer.
         *
         * @return int
         * @throws Exception
         * @throws Error
         * @throws TypeError
         */
        public static function randombytes_random16()
        {
        }
        /**
         * @param string $p
         * @param bool $dontFallback
         * @return bool
         * @throws SodiumException
         */
        public static function ristretto255_is_valid_point($p, $dontFallback = \false)
        {
        }
        /**
         * @param string $p
         * @param string $q
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_add($p, $q, $dontFallback = \false)
        {
        }
        /**
         * @param string $p
         * @param string $q
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_sub($p, $q, $dontFallback = \false)
        {
        }
        /**
         * @param string $r
         * @param bool $dontFallback
         * @return string
         *
         * @throws SodiumException
         */
        public static function ristretto255_from_hash($r, $dontFallback = \false)
        {
        }
        /**
         * @param bool $dontFallback
         * @return string
         *
         * @throws SodiumException
         */
        public static function ristretto255_random($dontFallback = \false)
        {
        }
        /**
         * @param bool $dontFallback
         * @return string
         *
         * @throws SodiumException
         */
        public static function ristretto255_scalar_random($dontFallback = \false)
        {
        }
        /**
         * @param string $s
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_invert($s, $dontFallback = \false)
        {
        }
        /**
         * @param string $s
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_negate($s, $dontFallback = \false)
        {
        }
        /**
         * @param string $s
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_complement($s, $dontFallback = \false)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_add($x, $y, $dontFallback = \false)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_sub($x, $y, $dontFallback = \false)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_mul($x, $y, $dontFallback = \false)
        {
        }
        /**
         * @param string $n
         * @param string $p
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function scalarmult_ristretto255($n, $p, $dontFallback = \false)
        {
        }
        /**
         * @param string $n
         * @param string $p
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function scalarmult_ristretto255_base($n, $dontFallback = \false)
        {
        }
        /**
         * @param string $s
         * @param bool $dontFallback
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_reduce($s, $dontFallback = \false)
        {
        }
        /**
         * Runtime testing method for 32-bit platforms.
         *
         * Usage: If runtime_speed_test() returns FALSE, then our 32-bit
         *        implementation is to slow to use safely without risking timeouts.
         *        If this happens, install sodium from PECL to get acceptable
         *        performance.
         *
         * @param int $iterations Number of multiplications to attempt
         * @param int $maxTimeout Milliseconds
         * @return bool           TRUE if we're fast enough, FALSE is not
         * @throws SodiumException
         */
        public static function runtime_speed_test($iterations, $maxTimeout)
        {
        }
        /**
         * Add two numbers (little-endian unsigned), storing the value in the first
         * parameter.
         *
         * This mutates $val.
         *
         * @param string $val
         * @param string $addv
         * @return void
         * @throws SodiumException
         */
        public static function sub(&$val, $addv)
        {
        }
        /**
         * This emulates libsodium's version_string() function, except ours is
         * prefixed with 'polyfill-'.
         *
         * @return string
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress UndefinedFunction
         */
        public static function version_string()
        {
        }
        /**
         * Should we use the libsodium core function instead?
         * This is always a good idea, if it's available. (Unless we're in the
         * middle of running our unit test suite.)
         *
         * If ext/libsodium is available, use it. Return TRUE.
         * Otherwise, we have to use the code provided herein. Return FALSE.
         *
         * @param string $sodium_func_name
         *
         * @return bool
         */
        protected static function use_fallback($sodium_func_name = '')
        {
        }
        /**
         * Libsodium as implemented in PHP 7.2
         * and/or ext/sodium (via PECL)
         *
         * @ref https://wiki.php.net/rfc/libsodium
         * @return bool
         */
        protected static function useNewSodiumAPI()
        {
        }
    }
}
namespace ParagonIE\Sodium {
    class Compat extends \ParagonIE_Sodium_Compat
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Util
     */
    abstract class ParagonIE_Sodium_Core_Util
    {
        const U32_MAX = 0xffffffff;
        /**
         * @param int $integer
         * @param int $size (16, 32, 64)
         * @return int
         */
        public static function abs($integer, $size = 0)
        {
        }
        /**
         * @param string $a
         * @param string $b
         * @return string
         * @throws SodiumException
         */
        public static function andStrings($a, $b)
        {
        }
        /**
         * Convert a binary string into a hexadecimal string without cache-timing
         * leaks
         *
         * @internal You should not use this directly from another application
         *
         * @param string $binaryString (raw binary)
         * @return string
         * @throws TypeError
         */
        public static function bin2hex($binaryString)
        {
        }
        /**
         * Convert a binary string into a hexadecimal string without cache-timing
         * leaks, returning uppercase letters (as per RFC 4648)
         *
         * @internal You should not use this directly from another application
         *
         * @param string $bin_string (raw binary)
         * @return string
         * @throws TypeError
         */
        public static function bin2hexUpper($bin_string)
        {
        }
        /**
         * Cache-timing-safe variant of ord()
         *
         * @internal You should not use this directly from another application
         *
         * @param string $chr
         * @return int
         * @throws SodiumException
         * @throws TypeError
         */
        public static function chrToInt($chr)
        {
        }
        /**
         * Compares two strings.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $left
         * @param string $right
         * @param int $len
         * @return int
         * @throws SodiumException
         * @throws TypeError
         */
        public static function compare($left, $right, $len = \null)
        {
        }
        /**
         * If a variable does not match a given type, throw a TypeError.
         *
         * @param mixed $mixedVar
         * @param string $type
         * @param int $argumentIndex
         * @throws TypeError
         * @throws SodiumException
         * @return void
         */
        public static function declareScalarType(&$mixedVar = \null, $type = 'void', $argumentIndex = 0)
        {
        }
        /**
         * Evaluate whether or not two strings are equal (in constant-time)
         *
         * @param string $left
         * @param string $right
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function hashEquals($left, $right)
        {
        }
        /**
         * Catch hash_update() failures and throw instead of silently proceeding
         *
         * @param HashContext|resource &$hs
         * @param string $data
         * @return void
         * @throws SodiumException
         * @psalm-suppress PossiblyInvalidArgument
         */
        protected static function hash_update(&$hs, $data)
        {
        }
        /**
         * Convert a hexadecimal string into a binary string without cache-timing
         * leaks
         *
         * @internal You should not use this directly from another application
         *
         * @param string $hexString
         * @param string $ignore
         * @param bool $strictPadding
         * @return string (raw binary)
         * @throws RangeException
         * @throws TypeError
         */
        public static function hex2bin($hexString, $ignore = '', $strictPadding = \false)
        {
        }
        /**
         * Turn an array of integers into a string
         *
         * @internal You should not use this directly from another application
         *
         * @param array<int, int> $ints
         * @return string
         */
        public static function intArrayToString(array $ints)
        {
        }
        /**
         * Cache-timing-safe variant of ord()
         *
         * @internal You should not use this directly from another application
         *
         * @param int $int
         * @return string
         * @throws TypeError
         */
        public static function intToChr($int)
        {
        }
        /**
         * Load a 3 character substring into an integer
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return int
         * @throws RangeException
         * @throws TypeError
         */
        public static function load_3($string)
        {
        }
        /**
         * Load a 4 character substring into an integer
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return int
         * @throws RangeException
         * @throws TypeError
         */
        public static function load_4($string)
        {
        }
        /**
         * Load a 8 character substring into an integer
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return int
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function load64_le($string)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $left
         * @param string $right
         * @return int
         * @throws SodiumException
         * @throws TypeError
         */
        public static function memcmp($left, $right)
        {
        }
        /**
         * Multiply two integers in constant-time
         *
         * Micro-architecture timing side-channels caused by how your CPU
         * implements multiplication are best prevented by never using the
         * multiplication operators and ensuring that our code always takes
         * the same number of operations to complete, regardless of the values
         * of $a and $b.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $a
         * @param int $b
         * @param int $size Limits the number of operations (useful for small,
         *                  constant operands)
         * @return int
         */
        public static function mul($a, $b, $size = 0)
        {
        }
        /**
         * Convert any arbitrary numbers into two 32-bit integers that represent
         * a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int|float $num
         * @return array<int, int>
         */
        public static function numericTo64BitInteger($num)
        {
        }
        /**
         * Store a 24-bit integer into a string, treating it as big-endian.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $int
         * @return string
         * @throws TypeError
         */
        public static function store_3($int)
        {
        }
        /**
         * Store a 32-bit integer into a string, treating it as little-endian.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $int
         * @return string
         * @throws TypeError
         */
        public static function store32_le($int)
        {
        }
        /**
         * Store a 32-bit integer into a string, treating it as big-endian.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $int
         * @return string
         * @throws TypeError
         */
        public static function store_4($int)
        {
        }
        /**
         * Stores a 64-bit integer as an string, treating it as little-endian.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $int
         * @return string
         * @throws TypeError
         */
        public static function store64_le($int)
        {
        }
        /**
         * Safe string length
         *
         * @internal You should not use this directly from another application
         *
         * @ref mbstring.func_overload
         *
         * @param string $str
         * @return int
         * @throws TypeError
         */
        public static function strlen($str)
        {
        }
        /**
         * Turn a string into an array of integers
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return array<int, int>
         * @throws TypeError
         */
        public static function stringToIntArray($string)
        {
        }
        /**
         * Safe substring
         *
         * @internal You should not use this directly from another application
         *
         * @ref mbstring.func_overload
         *
         * @param string $str
         * @param int $start
         * @param int $length
         * @return string
         * @throws TypeError
         */
        public static function substr($str, $start = 0, $length = \null)
        {
        }
        /**
         * Compare a 16-character byte string in constant time.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param string $b
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function verify_16($a, $b)
        {
        }
        /**
         * Compare a 32-character byte string in constant time.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param string $b
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function verify_32($a, $b)
        {
        }
        /**
         * Calculate $a ^ $b for two strings.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param string $b
         * @return string
         * @throws TypeError
         */
        public static function xorStrings($a, $b)
        {
        }
        /**
         * Returns whether or not mbstring.func_overload is in effect.
         *
         * @internal You should not use this directly from another application
         *
         * Note: MB_OVERLOAD_STRING === 2, but we don't reference the constant
         * (for nuisance-free PHP 8 support)
         *
         * @return bool
         */
        protected static function isMbStringOverride()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_BLAKE2b
     *
     * Based on the work of Devi Mandiri in devi/salt.
     */
    abstract class ParagonIE_Sodium_Core_BLAKE2b extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * @var SplFixedArray
         */
        protected static $iv;
        /**
         * @var array<int, array<int, int>>
         */
        protected static $sigma = array(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), array(14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3), array(11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4), array(7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8), array(9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13), array(2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9), array(12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11), array(13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10), array(6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5), array(10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0), array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), array(14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3));
        const BLOCKBYTES = 128;
        const OUTBYTES = 64;
        const KEYBYTES = 64;
        /**
         * Turn two 32-bit integers into a fixed array representing a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $high
         * @param int $low
         * @return SplFixedArray
         * @psalm-suppress MixedAssignment
         */
        public static function new64($high, $low)
        {
        }
        /**
         * Convert an arbitrary number into an SplFixedArray of two 32-bit integers
         * that represents a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $num
         * @return SplFixedArray
         */
        protected static function to64($num)
        {
        }
        /**
         * Adds two 64-bit integers together, returning their sum as a SplFixedArray
         * containing two 32-bit integers (representing a 64-bit integer).
         *
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param SplFixedArray $y
         * @return SplFixedArray
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         */
        protected static function add64($x, $y)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param SplFixedArray $y
         * @param SplFixedArray $z
         * @return SplFixedArray
         */
        protected static function add364($x, $y, $z)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param SplFixedArray $y
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function xor64(\SplFixedArray $x, \SplFixedArray $y)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param int $c
         * @return SplFixedArray
         * @psalm-suppress MixedAssignment
         */
        public static function rotr64($x, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @return int
         * @psalm-suppress MixedOperand
         */
        protected static function flatten64($x)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param int $i
         * @return SplFixedArray
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayOffset
         */
        protected static function load64(\SplFixedArray $x, $i)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param int $i
         * @param SplFixedArray $u
         * @return void
         * @psalm-suppress MixedAssignment
         */
        protected static function store64(\SplFixedArray $x, $i, \SplFixedArray $u)
        {
        }
        /**
         * This just sets the $iv static variable.
         *
         * @internal You should not use this directly from another application
         *
         * @return void
         */
        public static function pseudoConstructor()
        {
        }
        /**
         * Returns a fresh BLAKE2 context.
         *
         * @internal You should not use this directly from another application
         *
         * @return SplFixedArray
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         */
        protected static function context()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $buf
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         */
        protected static function compress(\SplFixedArray $ctx, \SplFixedArray $buf)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $r
         * @param int $i
         * @param int $a
         * @param int $b
         * @param int $c
         * @param int $d
         * @param SplFixedArray $v
         * @param SplFixedArray $m
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayOffset
         */
        public static function G($r, $i, $a, $b, $c, $d, \SplFixedArray $v, \SplFixedArray $m)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param int $inc
         * @return void
         * @throws SodiumException
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         */
        public static function increment_counter($ctx, $inc)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $p
         * @param int $plen
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedOperand
         */
        public static function update(\SplFixedArray $ctx, \SplFixedArray $p, $plen)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $out
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedOperand
         */
        public static function finish(\SplFixedArray $ctx, \SplFixedArray $out)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray|null $key
         * @param int $outlen
         * @param SplFixedArray|null $salt
         * @param SplFixedArray|null $personal
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         */
        public static function init($key = \null, $outlen = 64, $salt = \null, $personal = \null)
        {
        }
        /**
         * Convert a string into an SplFixedArray of integers
         *
         * @internal You should not use this directly from another application
         *
         * @param string $str
         * @return SplFixedArray
         * @psalm-suppress MixedArgumentTypeCoercion
         */
        public static function stringToSplFixedArray($str = '')
        {
        }
        /**
         * Convert an SplFixedArray of integers into a string
         *
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $a
         * @return string
         * @throws TypeError
         */
        public static function SplFixedArrayToString(\SplFixedArray $a)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @return string
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedMethodCall
         */
        public static function contextToString(\SplFixedArray $ctx)
        {
        }
        /**
         * Creates an SplFixedArray containing other SplFixedArray elements, from
         * a string (compatible with \Sodium\crypto_generichash_{init, update, final})
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAssignment
         */
        public static function stringToContext($string)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class BLAKE2b extends \ParagonIE_Sodium_Core_BLAKE2b
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_ChaCha20
     */
    class ParagonIE_Sodium_Core_ChaCha20 extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * Bitwise left rotation
         *
         * @internal You should not use this directly from another application
         *
         * @param int $v
         * @param int $n
         * @return int
         */
        public static function rotate($v, $n)
        {
        }
        /**
         * The ChaCha20 quarter round function. Works on four 32-bit integers.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $a
         * @param int $b
         * @param int $c
         * @param int $d
         * @return array<int, int>
         */
        protected static function quarterRound($a, $b, $c, $d)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_ChaCha20_Ctx $ctx
         * @param string $message
         *
         * @return string
         * @throws TypeError
         * @throws SodiumException
         */
        public static function encryptBytes(\ParagonIE_Sodium_Core_ChaCha20_Ctx $ctx, $message = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function stream($len = 64, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStream($len, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStreamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function streamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class ChaCha20 extends \ParagonIE_Sodium_Core_ChaCha20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_ChaCha20_Ctx
     */
    class ParagonIE_Sodium_Core_ChaCha20_Ctx extends \ParagonIE_Sodium_Core_Util implements \ArrayAccess
    {
        /**
         * @var SplFixedArray internally, <int, int>
         */
        protected $container;
        /**
         * ParagonIE_Sodium_Core_ChaCha20_Ctx constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key     ChaCha20 key.
         * @param string $iv      Initialization Vector (a.k.a. nonce).
         * @param string $counter The initial counter value.
         *                        Defaults to 8 0x00 bytes.
         * @throws InvalidArgumentException
         * @throws TypeError
         */
        public function __construct($key = '', $iv = '', $counter = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @param int $value
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return bool
         */
        #[\ReturnTypeWillChange]
        public function offsetExists($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return mixed|null
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\ChaCha20 {
    class Ctx extends \ParagonIE_Sodium_Core_ChaCha20_Ctx
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_ChaCha20_IetfCtx
     */
    class ParagonIE_Sodium_Core_ChaCha20_IetfCtx extends \ParagonIE_Sodium_Core_ChaCha20_Ctx
    {
        /**
         * ParagonIE_Sodium_Core_ChaCha20_IetfCtx constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key     ChaCha20 key.
         * @param string $iv      Initialization Vector (a.k.a. nonce).
         * @param string $counter The initial counter value.
         *                        Defaults to 4 0x00 bytes.
         * @throws InvalidArgumentException
         * @throws TypeError
         */
        public function __construct($key = '', $iv = '', $counter = '')
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\ChaCha20 {
    class IetfCtx extends \ParagonIE_Sodium_Core_ChaCha20_IetfCtx
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_H
     *
     * This just contains the constants in the ref10/base.h file
     */
    class ParagonIE_Sodium_Core_Curve25519_H extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * See: libsodium's crypto_core/curve25519/ref10/base.h
         *
         * @var array<int, array<int, array<int, array<int, int>>>> Basically, int[32][8][3][10]
         */
        protected static $base = array(array(array(array(25967493, -14356035, 29566456, 3660896, -12694345, 4014787, 27544626, -11754271, -6079156, 2047605), array(-12545711, 934262, -2722910, 3049990, -727428, 9406986, 12720692, 5043384, 19500929, -15469378), array(-8738181, 4489570, 9688441, -14785194, 10184609, -12363380, 29287919, 11864899, -24514362, -4438546)), array(array(-12815894, -12976347, -21581243, 11784320, -25355658, -2750717, -11717903, -3814571, -358445, -10211303), array(-21703237, 6903825, 27185491, 6451973, -29577724, -9554005, -15616551, 11189268, -26829678, -5319081), array(26966642, 11152617, 32442495, 15396054, 14353839, -12752335, -3128826, -9541118, -15472047, -4166697)), array(array(15636291, -9688557, 24204773, -7912398, 616977, -16685262, 27787600, -14772189, 28944400, -1550024), array(16568933, 4717097, -11556148, -1102322, 15682896, -11807043, 16354577, -11775962, 7689662, 11199574), array(30464156, -5976125, -11779434, -15670865, 23220365, 15915852, 7512774, 10017326, -17749093, -9920357)), array(array(-17036878, 13921892, 10945806, -6033431, 27105052, -16084379, -28926210, 15006023, 3284568, -6276540), array(23599295, -8306047, -11193664, -7687416, 13236774, 10506355, 7464579, 9656445, 13059162, 10374397), array(7798556, 16710257, 3033922, 2874086, 28997861, 2835604, 32406664, -3839045, -641708, -101325)), array(array(10861363, 11473154, 27284546, 1981175, -30064349, 12577861, 32867885, 14515107, -15438304, 10819380), array(4708026, 6336745, 20377586, 9066809, -11272109, 6594696, -25653668, 12483688, -12668491, 5581306), array(19563160, 16186464, -29386857, 4097519, 10237984, -4348115, 28542350, 13850243, -23678021, -15815942)), array(array(-15371964, -12862754, 32573250, 4720197, -26436522, 5875511, -19188627, -15224819, -9818940, -12085777), array(-8549212, 109983, 15149363, 2178705, 22900618, 4543417, 3044240, -15689887, 1762328, 14866737), array(-18199695, -15951423, -10473290, 1707278, -17185920, 3916101, -28236412, 3959421, 27914454, 4383652)), array(array(5153746, 9909285, 1723747, -2777874, 30523605, 5516873, 19480852, 5230134, -23952439, -15175766), array(-30269007, -3463509, 7665486, 10083793, 28475525, 1649722, 20654025, 16520125, 30598449, 7715701), array(28881845, 14381568, 9657904, 3680757, -20181635, 7843316, -31400660, 1370708, 29794553, -1409300)), array(array(14499471, -2729599, -33191113, -4254652, 28494862, 14271267, 30290735, 10876454, -33154098, 2381726), array(-7195431, -2655363, -14730155, 462251, -27724326, 3941372, -6236617, 3696005, -32300832, 15351955), array(27431194, 8222322, 16448760, -3907995, -18707002, 11938355, -32961401, -2970515, 29551813, 10109425))), array(array(array(-13657040, -13155431, -31283750, 11777098, 21447386, 6519384, -2378284, -1627556, 10092783, -4764171), array(27939166, 14210322, 4677035, 16277044, -22964462, -12398139, -32508754, 12005538, -17810127, 12803510), array(17228999, -15661624, -1233527, 300140, -1224870, -11714777, 30364213, -9038194, 18016357, 4397660)), array(array(-10958843, -7690207, 4776341, -14954238, 27850028, -15602212, -26619106, 14544525, -17477504, 982639), array(29253598, 15796703, -2863982, -9908884, 10057023, 3163536, 7332899, -4120128, -21047696, 9934963), array(5793303, 16271923, -24131614, -10116404, 29188560, 1206517, -14747930, 4559895, -30123922, -10897950)), array(array(-27643952, -11493006, 16282657, -11036493, 28414021, -15012264, 24191034, 4541697, -13338309, 5500568), array(12650548, -1497113, 9052871, 11355358, -17680037, -8400164, -17430592, 12264343, 10874051, 13524335), array(25556948, -3045990, 714651, 2510400, 23394682, -10415330, 33119038, 5080568, -22528059, 5376628)), array(array(-26088264, -4011052, -17013699, -3537628, -6726793, 1920897, -22321305, -9447443, 4535768, 1569007), array(-2255422, 14606630, -21692440, -8039818, 28430649, 8775819, -30494562, 3044290, 31848280, 12543772), array(-22028579, 2943893, -31857513, 6777306, 13784462, -4292203, -27377195, -2062731, 7718482, 14474653)), array(array(2385315, 2454213, -22631320, 46603, -4437935, -15680415, 656965, -7236665, 24316168, -5253567), array(13741529, 10911568, -33233417, -8603737, -20177830, -1033297, 33040651, -13424532, -20729456, 8321686), array(21060490, -2212744, 15712757, -4336099, 1639040, 10656336, 23845965, -11874838, -9984458, 608372)), array(array(-13672732, -15087586, -10889693, -7557059, -6036909, 11305547, 1123968, -6780577, 27229399, 23887), array(-23244140, -294205, -11744728, 14712571, -29465699, -2029617, 12797024, -6440308, -1633405, 16678954), array(-29500620, 4770662, -16054387, 14001338, 7830047, 9564805, -1508144, -4795045, -17169265, 4904953)), array(array(24059557, 14617003, 19037157, -15039908, 19766093, -14906429, 5169211, 16191880, 2128236, -4326833), array(-16981152, 4124966, -8540610, -10653797, 30336522, -14105247, -29806336, 916033, -6882542, -2986532), array(-22630907, 12419372, -7134229, -7473371, -16478904, 16739175, 285431, 2763829, 15736322, 4143876)), array(array(2379352, 11839345, -4110402, -5988665, 11274298, 794957, 212801, -14594663, 23527084, -16458268), array(33431127, -11130478, -17838966, -15626900, 8909499, 8376530, -32625340, 4087881, -15188911, -14416214), array(1767683, 7197987, -13205226, -2022635, -13091350, 448826, 5799055, 4357868, -4774191, -16323038))), array(array(array(6721966, 13833823, -23523388, -1551314, 26354293, -11863321, 23365147, -3949732, 7390890, 2759800), array(4409041, 2052381, 23373853, 10530217, 7676779, -12885954, 21302353, -4264057, 1244380, -12919645), array(-4421239, 7169619, 4982368, -2957590, 30256825, -2777540, 14086413, 9208236, 15886429, 16489664)), array(array(1996075, 10375649, 14346367, 13311202, -6874135, -16438411, -13693198, 398369, -30606455, -712933), array(-25307465, 9795880, -2777414, 14878809, -33531835, 14780363, 13348553, 12076947, -30836462, 5113182), array(-17770784, 11797796, 31950843, 13929123, -25888302, 12288344, -30341101, -7336386, 13847711, 5387222)), array(array(-18582163, -3416217, 17824843, -2340966, 22744343, -10442611, 8763061, 3617786, -19600662, 10370991), array(20246567, -14369378, 22358229, -543712, 18507283, -10413996, 14554437, -8746092, 32232924, 16763880), array(9648505, 10094563, 26416693, 14745928, -30374318, -6472621, 11094161, 15689506, 3140038, -16510092)), array(array(-16160072, 5472695, 31895588, 4744994, 8823515, 10365685, -27224800, 9448613, -28774454, 366295), array(19153450, 11523972, -11096490, -6503142, -24647631, 5420647, 28344573, 8041113, 719605, 11671788), array(8678025, 2694440, -6808014, 2517372, 4964326, 11152271, -15432916, -15266516, 27000813, -10195553)), array(array(-15157904, 7134312, 8639287, -2814877, -7235688, 10421742, 564065, 5336097, 6750977, -14521026), array(11836410, -3979488, 26297894, 16080799, 23455045, 15735944, 1695823, -8819122, 8169720, 16220347), array(-18115838, 8653647, 17578566, -6092619, -8025777, -16012763, -11144307, -2627664, -5990708, -14166033)), array(array(-23308498, -10968312, 15213228, -10081214, -30853605, -11050004, 27884329, 2847284, 2655861, 1738395), array(-27537433, -14253021, -25336301, -8002780, -9370762, 8129821, 21651608, -3239336, -19087449, -11005278), array(1533110, 3437855, 23735889, 459276, 29970501, 11335377, 26030092, 5821408, 10478196, 8544890)), array(array(32173121, -16129311, 24896207, 3921497, 22579056, -3410854, 19270449, 12217473, 17789017, -3395995), array(-30552961, -2228401, -15578829, -10147201, 13243889, 517024, 15479401, -3853233, 30460520, 1052596), array(-11614875, 13323618, 32618793, 8175907, -15230173, 12596687, 27491595, -4612359, 3179268, -9478891)), array(array(31947069, -14366651, -4640583, -15339921, -15125977, -6039709, -14756777, -16411740, 19072640, -9511060), array(11685058, 11822410, 3158003, -13952594, 33402194, -4165066, 5977896, -5215017, 473099, 5040608), array(-20290863, 8198642, -27410132, 11602123, 1290375, -2799760, 28326862, 1721092, -19558642, -3131606))), array(array(array(7881532, 10687937, 7578723, 7738378, -18951012, -2553952, 21820786, 8076149, -27868496, 11538389), array(-19935666, 3899861, 18283497, -6801568, -15728660, -11249211, 8754525, 7446702, -5676054, 5797016), array(-11295600, -3793569, -15782110, -7964573, 12708869, -8456199, 2014099, -9050574, -2369172, -5877341)), array(array(-22472376, -11568741, -27682020, 1146375, 18956691, 16640559, 1192730, -3714199, 15123619, 10811505), array(14352098, -3419715, -18942044, 10822655, 32750596, 4699007, -70363, 15776356, -28886779, -11974553), array(-28241164, -8072475, -4978962, -5315317, 29416931, 1847569, -20654173, -16484855, 4714547, -9600655)), array(array(15200332, 8368572, 19679101, 15970074, -31872674, 1959451, 24611599, -4543832, -11745876, 12340220), array(12876937, -10480056, 33134381, 6590940, -6307776, 14872440, 9613953, 8241152, 15370987, 9608631), array(-4143277, -12014408, 8446281, -391603, 4407738, 13629032, -7724868, 15866074, -28210621, -8814099)), array(array(26660628, -15677655, 8393734, 358047, -7401291, 992988, -23904233, 858697, 20571223, 8420556), array(14620715, 13067227, -15447274, 8264467, 14106269, 15080814, 33531827, 12516406, -21574435, -12476749), array(236881, 10476226, 57258, -14677024, 6472998, 2466984, 17258519, 7256740, 8791136, 15069930)), array(array(1276410, -9371918, 22949635, -16322807, -23493039, -5702186, 14711875, 4874229, -30663140, -2331391), array(5855666, 4990204, -13711848, 7294284, -7804282, 1924647, -1423175, -7912378, -33069337, 9234253), array(20590503, -9018988, 31529744, -7352666, -2706834, 10650548, 31559055, -11609587, 18979186, 13396066)), array(array(24474287, 4968103, 22267082, 4407354, 24063882, -8325180, -18816887, 13594782, 33514650, 7021958), array(-11566906, -6565505, -21365085, 15928892, -26158305, 4315421, -25948728, -3916677, -21480480, 12868082), array(-28635013, 13504661, 19988037, -2132761, 21078225, 6443208, -21446107, 2244500, -12455797, -8089383)), array(array(-30595528, 13793479, -5852820, 319136, -25723172, -6263899, 33086546, 8957937, -15233648, 5540521), array(-11630176, -11503902, -8119500, -7643073, 2620056, 1022908, -23710744, -1568984, -16128528, -14962807), array(23152971, 775386, 27395463, 14006635, -9701118, 4649512, 1689819, 892185, -11513277, -15205948)), array(array(9770129, 9586738, 26496094, 4324120, 1556511, -3550024, 27453819, 4763127, -19179614, 5867134), array(-32765025, 1927590, 31726409, -4753295, 23962434, -16019500, 27846559, 5931263, -29749703, -16108455), array(27461885, -2977536, 22380810, 1815854, -23033753, -3031938, 7283490, -15148073, -19526700, 7734629))), array(array(array(-8010264, -9590817, -11120403, 6196038, 29344158, -13430885, 7585295, -3176626, 18549497, 15302069), array(-32658337, -6171222, -7672793, -11051681, 6258878, 13504381, 10458790, -6418461, -8872242, 8424746), array(24687205, 8613276, -30667046, -3233545, 1863892, -1830544, 19206234, 7134917, -11284482, -828919)), array(array(11334899, -9218022, 8025293, 12707519, 17523892, -10476071, 10243738, -14685461, -5066034, 16498837), array(8911542, 6887158, -9584260, -6958590, 11145641, -9543680, 17303925, -14124238, 6536641, 10543906), array(-28946384, 15479763, -17466835, 568876, -1497683, 11223454, -2669190, -16625574, -27235709, 8876771)), array(array(-25742899, -12566864, -15649966, -846607, -33026686, -796288, -33481822, 15824474, -604426, -9039817), array(10330056, 70051, 7957388, -9002667, 9764902, 15609756, 27698697, -4890037, 1657394, 3084098), array(10477963, -7470260, 12119566, -13250805, 29016247, -5365589, 31280319, 14396151, -30233575, 15272409)), array(array(-12288309, 3169463, 28813183, 16658753, 25116432, -5630466, -25173957, -12636138, -25014757, 1950504), array(-26180358, 9489187, 11053416, -14746161, -31053720, 5825630, -8384306, -8767532, 15341279, 8373727), array(28685821, 7759505, -14378516, -12002860, -31971820, 4079242, 298136, -10232602, -2878207, 15190420)), array(array(-32932876, 13806336, -14337485, -15794431, -24004620, 10940928, 8669718, 2742393, -26033313, -6875003), array(-1580388, -11729417, -25979658, -11445023, -17411874, -10912854, 9291594, -16247779, -12154742, 6048605), array(-30305315, 14843444, 1539301, 11864366, 20201677, 1900163, 13934231, 5128323, 11213262, 9168384)), array(array(-26280513, 11007847, 19408960, -940758, -18592965, -4328580, -5088060, -11105150, 20470157, -16398701), array(-23136053, 9282192, 14855179, -15390078, -7362815, -14408560, -22783952, 14461608, 14042978, 5230683), array(29969567, -2741594, -16711867, -8552442, 9175486, -2468974, 21556951, 3506042, -5933891, -12449708)), array(array(-3144746, 8744661, 19704003, 4581278, -20430686, 6830683, -21284170, 8971513, -28539189, 15326563), array(-19464629, 10110288, -17262528, -3503892, -23500387, 1355669, -15523050, 15300988, -20514118, 9168260), array(-5353335, 4488613, -23803248, 16314347, 7780487, -15638939, -28948358, 9601605, 33087103, -9011387)), array(array(-19443170, -15512900, -20797467, -12445323, -29824447, 10229461, -27444329, -15000531, -5996870, 15664672), array(23294591, -16632613, -22650781, -8470978, 27844204, 11461195, 13099750, -2460356, 18151676, 13417686), array(-24722913, -4176517, -31150679, 5988919, -26858785, 6685065, 1661597, -12551441, 15271676, -15452665))), array(array(array(11433042, -13228665, 8239631, -5279517, -1985436, -725718, -18698764, 2167544, -6921301, -13440182), array(-31436171, 15575146, 30436815, 12192228, -22463353, 9395379, -9917708, -8638997, 12215110, 12028277), array(14098400, 6555944, 23007258, 5757252, -15427832, -12950502, 30123440, 4617780, -16900089, -655628)), array(array(-4026201, -15240835, 11893168, 13718664, -14809462, 1847385, -15819999, 10154009, 23973261, -12684474), array(-26531820, -3695990, -1908898, 2534301, -31870557, -16550355, 18341390, -11419951, 32013174, -10103539), array(-25479301, 10876443, -11771086, -14625140, -12369567, 1838104, 21911214, 6354752, 4425632, -837822)), array(array(-10433389, -14612966, 22229858, -3091047, -13191166, 776729, -17415375, -12020462, 4725005, 14044970), array(19268650, -7304421, 1555349, 8692754, -21474059, -9910664, 6347390, -1411784, -19522291, -16109756), array(-24864089, 12986008, -10898878, -5558584, -11312371, -148526, 19541418, 8180106, 9282262, 10282508)), array(array(-26205082, 4428547, -8661196, -13194263, 4098402, -14165257, 15522535, 8372215, 5542595, -10702683), array(-10562541, 14895633, 26814552, -16673850, -17480754, -2489360, -2781891, 6993761, -18093885, 10114655), array(-20107055, -929418, 31422704, 10427861, -7110749, 6150669, -29091755, -11529146, 25953725, -106158)), array(array(-4234397, -8039292, -9119125, 3046000, 2101609, -12607294, 19390020, 6094296, -3315279, 12831125), array(-15998678, 7578152, 5310217, 14408357, -33548620, -224739, 31575954, 6326196, 7381791, -2421839), array(-20902779, 3296811, 24736065, -16328389, 18374254, 7318640, 6295303, 8082724, -15362489, 12339664)), array(array(27724736, 2291157, 6088201, -14184798, 1792727, 5857634, 13848414, 15768922, 25091167, 14856294), array(-18866652, 8331043, 24373479, 8541013, -701998, -9269457, 12927300, -12695493, -22182473, -9012899), array(-11423429, -5421590, 11632845, 3405020, 30536730, -11674039, -27260765, 13866390, 30146206, 9142070)), array(array(3924129, -15307516, -13817122, -10054960, 12291820, -668366, -27702774, 9326384, -8237858, 4171294), array(-15921940, 16037937, 6713787, 16606682, -21612135, 2790944, 26396185, 3731949, 345228, -5462949), array(-21327538, 13448259, 25284571, 1143661, 20614966, -8849387, 2031539, -12391231, -16253183, -13582083)), array(array(31016211, -16722429, 26371392, -14451233, -5027349, 14854137, 17477601, 3842657, 28012650, -16405420), array(-5075835, 9368966, -8562079, -4600902, -15249953, 6970560, -9189873, 16292057, -8867157, 3507940), array(29439664, 3537914, 23333589, 6997794, -17555561, -11018068, -15209202, -15051267, -9164929, 6580396))), array(array(array(-12185861, -7679788, 16438269, 10826160, -8696817, -6235611, 17860444, -9273846, -2095802, 9304567), array(20714564, -4336911, 29088195, 7406487, 11426967, -5095705, 14792667, -14608617, 5289421, -477127), array(-16665533, -10650790, -6160345, -13305760, 9192020, -1802462, 17271490, 12349094, 26939669, -3752294)), array(array(-12889898, 9373458, 31595848, 16374215, 21471720, 13221525, -27283495, -12348559, -3698806, 117887), array(22263325, -6560050, 3984570, -11174646, -15114008, -566785, 28311253, 5358056, -23319780, 541964), array(16259219, 3261970, 2309254, -15534474, -16885711, -4581916, 24134070, -16705829, -13337066, -13552195)), array(array(9378160, -13140186, -22845982, -12745264, 28198281, -7244098, -2399684, -717351, 690426, 14876244), array(24977353, -314384, -8223969, -13465086, 28432343, -1176353, -13068804, -12297348, -22380984, 6618999), array(-1538174, 11685646, 12944378, 13682314, -24389511, -14413193, 8044829, -13817328, 32239829, -5652762)), array(array(-18603066, 4762990, -926250, 8885304, -28412480, -3187315, 9781647, -10350059, 32779359, 5095274), array(-33008130, -5214506, -32264887, -3685216, 9460461, -9327423, -24601656, 14506724, 21639561, -2630236), array(-16400943, -13112215, 25239338, 15531969, 3987758, -4499318, -1289502, -6863535, 17874574, 558605)), array(array(-13600129, 10240081, 9171883, 16131053, -20869254, 9599700, 33499487, 5080151, 2085892, 5119761), array(-22205145, -2519528, -16381601, 414691, -25019550, 2170430, 30634760, -8363614, -31999993, -5759884), array(-6845704, 15791202, 8550074, -1312654, 29928809, -12092256, 27534430, -7192145, -22351378, 12961482)), array(array(-24492060, -9570771, 10368194, 11582341, -23397293, -2245287, 16533930, 8206996, -30194652, -5159638), array(-11121496, -3382234, 2307366, 6362031, -135455, 8868177, -16835630, 7031275, 7589640, 8945490), array(-32152748, 8917967, 6661220, -11677616, -1192060, -15793393, 7251489, -11182180, 24099109, -14456170)), array(array(5019558, -7907470, 4244127, -14714356, -26933272, 6453165, -19118182, -13289025, -6231896, -10280736), array(10853594, 10721687, 26480089, 5861829, -22995819, 1972175, -1866647, -10557898, -3363451, -6441124), array(-17002408, 5906790, 221599, -6563147, 7828208, -13248918, 24362661, -2008168, -13866408, 7421392)), array(array(8139927, -6546497, 32257646, -5890546, 30375719, 1886181, -21175108, 15441252, 28826358, -4123029), array(6267086, 9695052, 7709135, -16603597, -32869068, -1886135, 14795160, -7840124, 13746021, -1742048), array(28584902, 7787108, -6732942, -15050729, 22846041, -7571236, -3181936, -363524, 4771362, -8419958))), array(array(array(24949256, 6376279, -27466481, -8174608, -18646154, -9930606, 33543569, -12141695, 3569627, 11342593), array(26514989, 4740088, 27912651, 3697550, 19331575, -11472339, 6809886, 4608608, 7325975, -14801071), array(-11618399, -14554430, -24321212, 7655128, -1369274, 5214312, -27400540, 10258390, -17646694, -8186692)), array(array(11431204, 15823007, 26570245, 14329124, 18029990, 4796082, -31446179, 15580664, 9280358, -3973687), array(-160783, -10326257, -22855316, -4304997, -20861367, -13621002, -32810901, -11181622, -15545091, 4387441), array(-20799378, 12194512, 3937617, -5805892, -27154820, 9340370, -24513992, 8548137, 20617071, -7482001)), array(array(-938825, -3930586, -8714311, 16124718, 24603125, -6225393, -13775352, -11875822, 24345683, 10325460), array(-19855277, -1568885, -22202708, 8714034, 14007766, 6928528, 16318175, -1010689, 4766743, 3552007), array(-21751364, -16730916, 1351763, -803421, -4009670, 3950935, 3217514, 14481909, 10988822, -3994762)), array(array(15564307, -14311570, 3101243, 5684148, 30446780, -8051356, 12677127, -6505343, -8295852, 13296005), array(-9442290, 6624296, -30298964, -11913677, -4670981, -2057379, 31521204, 9614054, -30000824, 12074674), array(4771191, -135239, 14290749, -13089852, 27992298, 14998318, -1413936, -1556716, 29832613, -16391035)), array(array(7064884, -7541174, -19161962, -5067537, -18891269, -2912736, 25825242, 5293297, -27122660, 13101590), array(-2298563, 2439670, -7466610, 1719965, -27267541, -16328445, 32512469, -5317593, -30356070, -4190957), array(-30006540, 10162316, -33180176, 3981723, -16482138, -13070044, 14413974, 9515896, 19568978, 9628812)), array(array(33053803, 199357, 15894591, 1583059, 27380243, -4580435, -17838894, -6106839, -6291786, 3437740), array(-18978877, 3884493, 19469877, 12726490, 15913552, 13614290, -22961733, 70104, 7463304, 4176122), array(-27124001, 10659917, 11482427, -16070381, 12771467, -6635117, -32719404, -5322751, 24216882, 5944158)), array(array(8894125, 7450974, -2664149, -9765752, -28080517, -12389115, 19345746, 14680796, 11632993, 5847885), array(26942781, -2315317, 9129564, -4906607, 26024105, 11769399, -11518837, 6367194, -9727230, 4782140), array(19916461, -4828410, -22910704, -11414391, 25606324, -5972441, 33253853, 8220911, 6358847, -1873857)), array(array(801428, -2081702, 16569428, 11065167, 29875704, 96627, 7908388, -4480480, -13538503, 1387155), array(19646058, 5720633, -11416706, 12814209, 11607948, 12749789, 14147075, 15156355, -21866831, 11835260), array(19299512, 1155910, 28703737, 14890794, 2925026, 7269399, 26121523, 15467869, -26560550, 5052483))), array(array(array(-3017432, 10058206, 1980837, 3964243, 22160966, 12322533, -6431123, -12618185, 12228557, -7003677), array(32944382, 14922211, -22844894, 5188528, 21913450, -8719943, 4001465, 13238564, -6114803, 8653815), array(22865569, -4652735, 27603668, -12545395, 14348958, 8234005, 24808405, 5719875, 28483275, 2841751)), array(array(-16420968, -1113305, -327719, -12107856, 21886282, -15552774, -1887966, -315658, 19932058, -12739203), array(-11656086, 10087521, -8864888, -5536143, -19278573, -3055912, 3999228, 13239134, -4777469, -13910208), array(1382174, -11694719, 17266790, 9194690, -13324356, 9720081, 20403944, 11284705, -14013818, 3093230)), array(array(16650921, -11037932, -1064178, 1570629, -8329746, 7352753, -302424, 16271225, -24049421, -6691850), array(-21911077, -5927941, -4611316, -5560156, -31744103, -10785293, 24123614, 15193618, -21652117, -16739389), array(-9935934, -4289447, -25279823, 4372842, 2087473, 10399484, 31870908, 14690798, 17361620, 11864968)), array(array(-11307610, 6210372, 13206574, 5806320, -29017692, -13967200, -12331205, -7486601, -25578460, -16240689), array(14668462, -12270235, 26039039, 15305210, 25515617, 4542480, 10453892, 6577524, 9145645, -6443880), array(5974874, 3053895, -9433049, -10385191, -31865124, 3225009, -7972642, 3936128, -5652273, -3050304)), array(array(30625386, -4729400, -25555961, -12792866, -20484575, 7695099, 17097188, -16303496, -27999779, 1803632), array(-3553091, 9865099, -5228566, 4272701, -5673832, -16689700, 14911344, 12196514, -21405489, 7047412), array(20093277, 9920966, -11138194, -5343857, 13161587, 12044805, -32856851, 4124601, -32343828, -10257566)), array(array(-20788824, 14084654, -13531713, 7842147, 19119038, -13822605, 4752377, -8714640, -21679658, 2288038), array(-26819236, -3283715, 29965059, 3039786, -14473765, 2540457, 29457502, 14625692, -24819617, 12570232), array(-1063558, -11551823, 16920318, 12494842, 1278292, -5869109, -21159943, -3498680, -11974704, 4724943)), array(array(17960970, -11775534, -4140968, -9702530, -8876562, -1410617, -12907383, -8659932, -29576300, 1903856), array(23134274, -14279132, -10681997, -1611936, 20684485, 15770816, -12989750, 3190296, 26955097, 14109738), array(15308788, 5320727, -30113809, -14318877, 22902008, 7767164, 29425325, -11277562, 31960942, 11934971)), array(array(-27395711, 8435796, 4109644, 12222639, -24627868, 14818669, 20638173, 4875028, 10491392, 1379718), array(-13159415, 9197841, 3875503, -8936108, -1383712, -5879801, 33518459, 16176658, 21432314, 12180697), array(-11787308, 11500838, 13787581, -13832590, -22430679, 10140205, 1465425, 12689540, -10301319, -13872883))), array(array(array(5414091, -15386041, -21007664, 9643570, 12834970, 1186149, -2622916, -1342231, 26128231, 6032912), array(-26337395, -13766162, 32496025, -13653919, 17847801, -12669156, 3604025, 8316894, -25875034, -10437358), array(3296484, 6223048, 24680646, -12246460, -23052020, 5903205, -8862297, -4639164, 12376617, 3188849)), array(array(29190488, -14659046, 27549113, -1183516, 3520066, -10697301, 32049515, -7309113, -16109234, -9852307), array(-14744486, -9309156, 735818, -598978, -20407687, -5057904, 25246078, -15795669, 18640741, -960977), array(-6928835, -16430795, 10361374, 5642961, 4910474, 12345252, -31638386, -494430, 10530747, 1053335)), array(array(-29265967, -14186805, -13538216, -12117373, -19457059, -10655384, -31462369, -2948985, 24018831, 15026644), array(-22592535, -3145277, -2289276, 5953843, -13440189, 9425631, 25310643, 13003497, -2314791, -15145616), array(-27419985, -603321, -8043984, -1669117, -26092265, 13987819, -27297622, 187899, -23166419, -2531735)), array(array(-21744398, -13810475, 1844840, 5021428, -10434399, -15911473, 9716667, 16266922, -5070217, 726099), array(29370922, -6053998, 7334071, -15342259, 9385287, 2247707, -13661962, -4839461, 30007388, -15823341), array(-936379, 16086691, 23751945, -543318, -1167538, -5189036, 9137109, 730663, 9835848, 4555336)), array(array(-23376435, 1410446, -22253753, -12899614, 30867635, 15826977, 17693930, 544696, -11985298, 12422646), array(31117226, -12215734, -13502838, 6561947, -9876867, -12757670, -5118685, -4096706, 29120153, 13924425), array(-17400879, -14233209, 19675799, -2734756, -11006962, -5858820, -9383939, -11317700, 7240931, -237388)), array(array(-31361739, -11346780, -15007447, -5856218, -22453340, -12152771, 1222336, 4389483, 3293637, -15551743), array(-16684801, -14444245, 11038544, 11054958, -13801175, -3338533, -24319580, 7733547, 12796905, -6335822), array(-8759414, -10817836, -25418864, 10783769, -30615557, -9746811, -28253339, 3647836, 3222231, -11160462)), array(array(18606113, 1693100, -25448386, -15170272, 4112353, 10045021, 23603893, -2048234, -7550776, 2484985), array(9255317, -3131197, -12156162, -1004256, 13098013, -9214866, 16377220, -2102812, -19802075, -3034702), array(-22729289, 7496160, -5742199, 11329249, 19991973, -3347502, -31718148, 9936966, -30097688, -10618797)), array(array(21878590, -5001297, 4338336, 13643897, -3036865, 13160960, 19708896, 5415497, -7360503, -4109293), array(27736861, 10103576, 12500508, 8502413, -3413016, -9633558, 10436918, -1550276, -23659143, -8132100), array(19492550, -12104365, -29681976, -852630, -3208171, 12403437, 30066266, 8367329, 13243957, 8709688))), array(array(array(12015105, 2801261, 28198131, 10151021, 24818120, -4743133, -11194191, -5645734, 5150968, 7274186), array(2831366, -12492146, 1478975, 6122054, 23825128, -12733586, 31097299, 6083058, 31021603, -9793610), array(-2529932, -2229646, 445613, 10720828, -13849527, -11505937, -23507731, 16354465, 15067285, -14147707)), array(array(7840942, 14037873, -33364863, 15934016, -728213, -3642706, 21403988, 1057586, -19379462, -12403220), array(915865, -16469274, 15608285, -8789130, -24357026, 6060030, -17371319, 8410997, -7220461, 16527025), array(32922597, -556987, 20336074, -16184568, 10903705, -5384487, 16957574, 52992, 23834301, 6588044)), array(array(32752030, 11232950, 3381995, -8714866, 22652988, -10744103, 17159699, 16689107, -20314580, -1305992), array(-4689649, 9166776, -25710296, -10847306, 11576752, 12733943, 7924251, -2752281, 1976123, -7249027), array(21251222, 16309901, -2983015, -6783122, 30810597, 12967303, 156041, -3371252, 12331345, -8237197)), array(array(8651614, -4477032, -16085636, -4996994, 13002507, 2950805, 29054427, -5106970, 10008136, -4667901), array(31486080, 15114593, -14261250, 12951354, 14369431, -7387845, 16347321, -13662089, 8684155, -10532952), array(19443825, 11385320, 24468943, -9659068, -23919258, 2187569, -26263207, -6086921, 31316348, 14219878)), array(array(-28594490, 1193785, 32245219, 11392485, 31092169, 15722801, 27146014, 6992409, 29126555, 9207390), array(32382935, 1110093, 18477781, 11028262, -27411763, -7548111, -4980517, 10843782, -7957600, -14435730), array(2814918, 7836403, 27519878, -7868156, -20894015, -11553689, -21494559, 8550130, 28346258, 1994730)), array(array(-19578299, 8085545, -14000519, -3948622, 2785838, -16231307, -19516951, 7174894, 22628102, 8115180), array(-30405132, 955511, -11133838, -15078069, -32447087, -13278079, -25651578, 3317160, -9943017, 930272), array(-15303681, -6833769, 28856490, 1357446, 23421993, 1057177, 24091212, -1388970, -22765376, -10650715)), array(array(-22751231, -5303997, -12907607, -12768866, -15811511, -7797053, -14839018, -16554220, -1867018, 8398970), array(-31969310, 2106403, -4736360, 1362501, 12813763, 16200670, 22981545, -6291273, 18009408, -15772772), array(-17220923, -9545221, -27784654, 14166835, 29815394, 7444469, 29551787, -3727419, 19288549, 1325865)), array(array(15100157, -15835752, -23923978, -1005098, -26450192, 15509408, 12376730, -3479146, 33166107, -8042750), array(20909231, 13023121, -9209752, 16251778, -5778415, -8094914, 12412151, 10018715, 2213263, -13878373), array(32529814, -11074689, 30361439, -16689753, -9135940, 1513226, 22922121, 6382134, -5766928, 8371348))), array(array(array(9923462, 11271500, 12616794, 3544722, -29998368, -1721626, 12891687, -8193132, -26442943, 10486144), array(-22597207, -7012665, 8587003, -8257861, 4084309, -12970062, 361726, 2610596, -23921530, -11455195), array(5408411, -1136691, -4969122, 10561668, 24145918, 14240566, 31319731, -4235541, 19985175, -3436086)), array(array(-13994457, 16616821, 14549246, 3341099, 32155958, 13648976, -17577068, 8849297, 65030, 8370684), array(-8320926, -12049626, 31204563, 5839400, -20627288, -1057277, -19442942, 6922164, 12743482, -9800518), array(-2361371, 12678785, 28815050, 4759974, -23893047, 4884717, 23783145, 11038569, 18800704, 255233)), array(array(-5269658, -1773886, 13957886, 7990715, 23132995, 728773, 13393847, 9066957, 19258688, -14753793), array(-2936654, -10827535, -10432089, 14516793, -3640786, 4372541, -31934921, 2209390, -1524053, 2055794), array(580882, 16705327, 5468415, -2683018, -30926419, -14696000, -7203346, -8994389, -30021019, 7394435)), array(array(23838809, 1822728, -15738443, 15242727, 8318092, -3733104, -21672180, -3492205, -4821741, 14799921), array(13345610, 9759151, 3371034, -16137791, 16353039, 8577942, 31129804, 13496856, -9056018, 7402518), array(2286874, -4435931, -20042458, -2008336, -13696227, 5038122, 11006906, -15760352, 8205061, 1607563)), array(array(14414086, -8002132, 3331830, -3208217, 22249151, -5594188, 18364661, -2906958, 30019587, -9029278), array(-27688051, 1585953, -10775053, 931069, -29120221, -11002319, -14410829, 12029093, 9944378, 8024), array(4368715, -3709630, 29874200, -15022983, -20230386, -11410704, -16114594, -999085, -8142388, 5640030)), array(array(10299610, 13746483, 11661824, 16234854, 7630238, 5998374, 9809887, -16694564, 15219798, -14327783), array(27425505, -5719081, 3055006, 10660664, 23458024, 595578, -15398605, -1173195, -18342183, 9742717), array(6744077, 2427284, 26042789, 2720740, -847906, 1118974, 32324614, 7406442, 12420155, 1994844)), array(array(14012521, -5024720, -18384453, -9578469, -26485342, -3936439, -13033478, -10909803, 24319929, -6446333), array(16412690, -4507367, 10772641, 15929391, -17068788, -4658621, 10555945, -10484049, -30102368, -4739048), array(22397382, -7767684, -9293161, -12792868, 17166287, -9755136, -27333065, 6199366, 21880021, -12250760)), array(array(-4283307, 5368523, -31117018, 8163389, -30323063, 3209128, 16557151, 8890729, 8840445, 4957760), array(-15447727, 709327, -6919446, -10870178, -29777922, 6522332, -21720181, 12130072, -14796503, 5005757), array(-2114751, -14308128, 23019042, 15765735, -25269683, 6002752, 10183197, -13239326, -16395286, -2176112))), array(array(array(-19025756, 1632005, 13466291, -7995100, -23640451, 16573537, -32013908, -3057104, 22208662, 2000468), array(3065073, -1412761, -25598674, -361432, -17683065, -5703415, -8164212, 11248527, -3691214, -7414184), array(10379208, -6045554, 8877319, 1473647, -29291284, -12507580, 16690915, 2553332, -3132688, 16400289)), array(array(15716668, 1254266, -18472690, 7446274, -8448918, 6344164, -22097271, -7285580, 26894937, 9132066), array(24158887, 12938817, 11085297, -8177598, -28063478, -4457083, -30576463, 64452, -6817084, -2692882), array(13488534, 7794716, 22236231, 5989356, 25426474, -12578208, 2350710, -3418511, -4688006, 2364226)), array(array(16335052, 9132434, 25640582, 6678888, 1725628, 8517937, -11807024, -11697457, 15445875, -7798101), array(29004207, -7867081, 28661402, -640412, -12794003, -7943086, 31863255, -4135540, -278050, -15759279), array(-6122061, -14866665, -28614905, 14569919, -10857999, -3591829, 10343412, -6976290, -29828287, -10815811)), array(array(27081650, 3463984, 14099042, -4517604, 1616303, -6205604, 29542636, 15372179, 17293797, 960709), array(20263915, 11434237, -5765435, 11236810, 13505955, -10857102, -16111345, 6493122, -19384511, 7639714), array(-2830798, -14839232, 25403038, -8215196, -8317012, -16173699, 18006287, -16043750, 29994677, -15808121)), array(array(9769828, 5202651, -24157398, -13631392, -28051003, -11561624, -24613141, -13860782, -31184575, 709464), array(12286395, 13076066, -21775189, -1176622, -25003198, 4057652, -32018128, -8890874, 16102007, 13205847), array(13733362, 5599946, 10557076, 3195751, -5557991, 8536970, -25540170, 8525972, 10151379, 10394400)), array(array(4024660, -16137551, 22436262, 12276534, -9099015, -2686099, 19698229, 11743039, -33302334, 8934414), array(-15879800, -4525240, -8580747, -2934061, 14634845, -698278, -9449077, 3137094, -11536886, 11721158), array(17555939, -5013938, 8268606, 2331751, -22738815, 9761013, 9319229, 8835153, -9205489, -1280045)), array(array(-461409, -7830014, 20614118, 16688288, -7514766, -4807119, 22300304, 505429, 6108462, -6183415), array(-5070281, 12367917, -30663534, 3234473, 32617080, -8422642, 29880583, -13483331, -26898490, -7867459), array(-31975283, 5726539, 26934134, 10237677, -3173717, -605053, 24199304, 3795095, 7592688, -14992079)), array(array(21594432, -14964228, 17466408, -4077222, 32537084, 2739898, 6407723, 12018833, -28256052, 4298412), array(-20650503, -11961496, -27236275, 570498, 3767144, -1717540, 13891942, -1569194, 13717174, 10805743), array(-14676630, -15644296, 15287174, 11927123, 24177847, -8175568, -796431, 14860609, -26938930, -5863836))), array(array(array(12962541, 5311799, -10060768, 11658280, 18855286, -7954201, 13286263, -12808704, -4381056, 9882022), array(18512079, 11319350, -20123124, 15090309, 18818594, 5271736, -22727904, 3666879, -23967430, -3299429), array(-6789020, -3146043, 16192429, 13241070, 15898607, -14206114, -10084880, -6661110, -2403099, 5276065)), array(array(30169808, -5317648, 26306206, -11750859, 27814964, 7069267, 7152851, 3684982, 1449224, 13082861), array(10342826, 3098505, 2119311, 193222, 25702612, 12233820, 23697382, 15056736, -21016438, -8202000), array(-33150110, 3261608, 22745853, 7948688, 19370557, -15177665, -26171976, 6482814, -10300080, -11060101)), array(array(32869458, -5408545, 25609743, 15678670, -10687769, -15471071, 26112421, 2521008, -22664288, 6904815), array(29506923, 4457497, 3377935, -9796444, -30510046, 12935080, 1561737, 3841096, -29003639, -6657642), array(10340844, -6630377, -18656632, -2278430, 12621151, -13339055, 30878497, -11824370, -25584551, 5181966)), array(array(25940115, -12658025, 17324188, -10307374, -8671468, 15029094, 24396252, -16450922, -2322852, -12388574), array(-21765684, 9916823, -1300409, 4079498, -1028346, 11909559, 1782390, 12641087, 20603771, -6561742), array(-18882287, -11673380, 24849422, 11501709, 13161720, -4768874, 1925523, 11914390, 4662781, 7820689)), array(array(12241050, -425982, 8132691, 9393934, 32846760, -1599620, 29749456, 12172924, 16136752, 15264020), array(-10349955, -14680563, -8211979, 2330220, -17662549, -14545780, 10658213, 6671822, 19012087, 3772772), array(3753511, -3421066, 10617074, 2028709, 14841030, -6721664, 28718732, -15762884, 20527771, 12988982)), array(array(-14822485, -5797269, -3707987, 12689773, -898983, -10914866, -24183046, -10564943, 3299665, -12424953), array(-16777703, -15253301, -9642417, 4978983, 3308785, 8755439, 6943197, 6461331, -25583147, 8991218), array(-17226263, 1816362, -1673288, -6086439, 31783888, -8175991, -32948145, 7417950, -30242287, 1507265)), array(array(29692663, 6829891, -10498800, 4334896, 20945975, -11906496, -28887608, 8209391, 14606362, -10647073), array(-3481570, 8707081, 32188102, 5672294, 22096700, 1711240, -33020695, 9761487, 4170404, -2085325), array(-11587470, 14855945, -4127778, -1531857, -26649089, 15084046, 22186522, 16002000, -14276837, -8400798)), array(array(-4811456, 13761029, -31703877, -2483919, -3312471, 7869047, -7113572, -9620092, 13240845, 10965870), array(-7742563, -8256762, -14768334, -13656260, -23232383, 12387166, 4498947, 14147411, 29514390, 4302863), array(-13413405, -12407859, 20757302, -13801832, 14785143, 8976368, -5061276, -2144373, 17846988, -13971927))), array(array(array(-2244452, -754728, -4597030, -1066309, -6247172, 1455299, -21647728, -9214789, -5222701, 12650267), array(-9906797, -16070310, 21134160, 12198166, -27064575, 708126, 387813, 13770293, -19134326, 10958663), array(22470984, 12369526, 23446014, -5441109, -21520802, -9698723, -11772496, -11574455, -25083830, 4271862)), array(array(-25169565, -10053642, -19909332, 15361595, -5984358, 2159192, 75375, -4278529, -32526221, 8469673), array(15854970, 4148314, -8893890, 7259002, 11666551, 13824734, -30531198, 2697372, 24154791, -9460943), array(15446137, -15806644, 29759747, 14019369, 30811221, -9610191, -31582008, 12840104, 24913809, 9815020)), array(array(-4709286, -5614269, -31841498, -12288893, -14443537, 10799414, -9103676, 13438769, 18735128, 9466238), array(11933045, 9281483, 5081055, -5183824, -2628162, -4905629, -7727821, -10896103, -22728655, 16199064), array(14576810, 379472, -26786533, -8317236, -29426508, -10812974, -102766, 1876699, 30801119, 2164795)), array(array(15995086, 3199873, 13672555, 13712240, -19378835, -4647646, -13081610, -15496269, -13492807, 1268052), array(-10290614, -3659039, -3286592, 10948818, 23037027, 3794475, -3470338, -12600221, -17055369, 3565904), array(29210088, -9419337, -5919792, -4952785, 10834811, -13327726, -16512102, -10820713, -27162222, -14030531)), array(array(-13161890, 15508588, 16663704, -8156150, -28349942, 9019123, -29183421, -3769423, 2244111, -14001979), array(-5152875, -3800936, -9306475, -6071583, 16243069, 14684434, -25673088, -16180800, 13491506, 4641841), array(10813417, 643330, -19188515, -728916, 30292062, -16600078, 27548447, -7721242, 14476989, -12767431)), array(array(10292079, 9984945, 6481436, 8279905, -7251514, 7032743, 27282937, -1644259, -27912810, 12651324), array(-31185513, -813383, 22271204, 11835308, 10201545, 15351028, 17099662, 3988035, 21721536, -3148940), array(10202177, -6545839, -31373232, -9574638, -32150642, -8119683, -12906320, 3852694, 13216206, 14842320)), array(array(-15815640, -10601066, -6538952, -7258995, -6984659, -6581778, -31500847, 13765824, -27434397, 9900184), array(14465505, -13833331, -32133984, -14738873, -27443187, 12990492, 33046193, 15796406, -7051866, -8040114), array(30924417, -8279620, 6359016, -12816335, 16508377, 9071735, -25488601, 15413635, 9524356, -7018878)), array(array(12274201, -13175547, 32627641, -1785326, 6736625, 13267305, 5237659, -5109483, 15663516, 4035784), array(-2951309, 8903985, 17349946, 601635, -16432815, -4612556, -13732739, -15889334, -22258478, 4659091), array(-16916263, -4952973, -30393711, -15158821, 20774812, 15897498, 5736189, 15026997, -2178256, -13455585))), array(array(array(-8858980, -2219056, 28571666, -10155518, -474467, -10105698, -3801496, 278095, 23440562, -290208), array(10226241, -5928702, 15139956, 120818, -14867693, 5218603, 32937275, 11551483, -16571960, -7442864), array(17932739, -12437276, -24039557, 10749060, 11316803, 7535897, 22503767, 5561594, -3646624, 3898661)), array(array(7749907, -969567, -16339731, -16464, -25018111, 15122143, -1573531, 7152530, 21831162, 1245233), array(26958459, -14658026, 4314586, 8346991, -5677764, 11960072, -32589295, -620035, -30402091, -16716212), array(-12165896, 9166947, 33491384, 13673479, 29787085, 13096535, 6280834, 14587357, -22338025, 13987525)), array(array(-24349909, 7778775, 21116000, 15572597, -4833266, -5357778, -4300898, -5124639, -7469781, -2858068), array(9681908, -6737123, -31951644, 13591838, -6883821, 386950, 31622781, 6439245, -14581012, 4091397), array(-8426427, 1470727, -28109679, -1596990, 3978627, -5123623, -19622683, 12092163, 29077877, -14741988)), array(array(5269168, -6859726, -13230211, -8020715, 25932563, 1763552, -5606110, -5505881, -20017847, 2357889), array(32264008, -15407652, -5387735, -1160093, -2091322, -3946900, 23104804, -12869908, 5727338, 189038), array(14609123, -8954470, -6000566, -16622781, -14577387, -7743898, -26745169, 10942115, -25888931, -14884697)), array(array(20513500, 5557931, -15604613, 7829531, 26413943, -2019404, -21378968, 7471781, 13913677, -5137875), array(-25574376, 11967826, 29233242, 12948236, -6754465, 4713227, -8940970, 14059180, 12878652, 8511905), array(-25656801, 3393631, -2955415, -7075526, -2250709, 9366908, -30223418, 6812974, 5568676, -3127656)), array(array(11630004, 12144454, 2116339, 13606037, 27378885, 15676917, -17408753, -13504373, -14395196, 8070818), array(27117696, -10007378, -31282771, -5570088, 1127282, 12772488, -29845906, 10483306, -11552749, -1028714), array(10637467, -5688064, 5674781, 1072708, -26343588, -6982302, -1683975, 9177853, -27493162, 15431203)), array(array(20525145, 10892566, -12742472, 12779443, -29493034, 16150075, -28240519, 14943142, -15056790, -7935931), array(-30024462, 5626926, -551567, -9981087, 753598, 11981191, 25244767, -3239766, -3356550, 9594024), array(-23752644, 2636870, -5163910, -10103818, 585134, 7877383, 11345683, -6492290, 13352335, -10977084)), array(array(-1931799, -5407458, 3304649, -12884869, 17015806, -4877091, -29783850, -7752482, -13215537, -319204), array(20239939, 6607058, 6203985, 3483793, -18386976, -779229, -20723742, 15077870, -22750759, 14523817), array(27406042, -6041657, 27423596, -4497394, 4996214, 10002360, -28842031, -4545494, -30172742, -4805667))), array(array(array(11374242, 12660715, 17861383, -12540833, 10935568, 1099227, -13886076, -9091740, -27727044, 11358504), array(-12730809, 10311867, 1510375, 10778093, -2119455, -9145702, 32676003, 11149336, -26123651, 4985768), array(-19096303, 341147, -6197485, -239033, 15756973, -8796662, -983043, 13794114, -19414307, -15621255)), array(array(6490081, 11940286, 25495923, -7726360, 8668373, -8751316, 3367603, 6970005, -1691065, -9004790), array(1656497, 13457317, 15370807, 6364910, 13605745, 8362338, -19174622, -5475723, -16796596, -5031438), array(-22273315, -13524424, -64685, -4334223, -18605636, -10921968, -20571065, -7007978, -99853, -10237333)), array(array(17747465, 10039260, 19368299, -4050591, -20630635, -16041286, 31992683, -15857976, -29260363, -5511971), array(31932027, -4986141, -19612382, 16366580, 22023614, 88450, 11371999, -3744247, 4882242, -10626905), array(29796507, 37186, 19818052, 10115756, -11829032, 3352736, 18551198, 3272828, -5190932, -4162409)), array(array(12501286, 4044383, -8612957, -13392385, -32430052, 5136599, -19230378, -3529697, 330070, -3659409), array(6384877, 2899513, 17807477, 7663917, -2358888, 12363165, 25366522, -8573892, -271295, 12071499), array(-8365515, -4042521, 25133448, -4517355, -6211027, 2265927, -32769618, 1936675, -5159697, 3829363)), array(array(28425966, -5835433, -577090, -4697198, -14217555, 6870930, 7921550, -6567787, 26333140, 14267664), array(-11067219, 11871231, 27385719, -10559544, -4585914, -11189312, 10004786, -8709488, -21761224, 8930324), array(-21197785, -16396035, 25654216, -1725397, 12282012, 11008919, 1541940, 4757911, -26491501, -16408940)), array(array(13537262, -7759490, -20604840, 10961927, -5922820, -13218065, -13156584, 6217254, -15943699, 13814990), array(-17422573, 15157790, 18705543, 29619, 24409717, -260476, 27361681, 9257833, -1956526, -1776914), array(-25045300, -10191966, 15366585, 15166509, -13105086, 8423556, -29171540, 12361135, -18685978, 4578290)), array(array(24579768, 3711570, 1342322, -11180126, -27005135, 14124956, -22544529, 14074919, 21964432, 8235257), array(-6528613, -2411497, 9442966, -5925588, 12025640, -1487420, -2981514, -1669206, 13006806, 2355433), array(-16304899, -13605259, -6632427, -5142349, 16974359, -10911083, 27202044, 1719366, 1141648, -12796236)), array(array(-12863944, -13219986, -8318266, -11018091, -6810145, -4843894, 13475066, -3133972, 32674895, 13715045), array(11423335, -5468059, 32344216, 8962751, 24989809, 9241752, -13265253, 16086212, -28740881, -15642093), array(-1409668, 12530728, -6368726, 10847387, 19531186, -14132160, -11709148, 7791794, -27245943, 4383347))), array(array(array(-28970898, 5271447, -1266009, -9736989, -12455236, 16732599, -4862407, -4906449, 27193557, 6245191), array(-15193956, 5362278, -1783893, 2695834, 4960227, 12840725, 23061898, 3260492, 22510453, 8577507), array(-12632451, 11257346, -32692994, 13548177, -721004, 10879011, 31168030, 13952092, -29571492, -3635906)), array(array(3877321, -9572739, 32416692, 5405324, -11004407, -13656635, 3759769, 11935320, 5611860, 8164018), array(-16275802, 14667797, 15906460, 12155291, -22111149, -9039718, 32003002, -8832289, 5773085, -8422109), array(-23788118, -8254300, 1950875, 8937633, 18686727, 16459170, -905725, 12376320, 31632953, 190926)), array(array(-24593607, -16138885, -8423991, 13378746, 14162407, 6901328, -8288749, 4508564, -25341555, -3627528), array(8884438, -5884009, 6023974, 10104341, -6881569, -4941533, 18722941, -14786005, -1672488, 827625), array(-32720583, -16289296, -32503547, 7101210, 13354605, 2659080, -1800575, -14108036, -24878478, 1541286)), array(array(2901347, -1117687, 3880376, -10059388, -17620940, -3612781, -21802117, -3567481, 20456845, -1885033), array(27019610, 12299467, -13658288, -1603234, -12861660, -4861471, -19540150, -5016058, 29439641, 15138866), array(21536104, -6626420, -32447818, -10690208, -22408077, 5175814, -5420040, -16361163, 7779328, 109896)), array(array(30279744, 14648750, -8044871, 6425558, 13639621, -743509, 28698390, 12180118, 23177719, -554075), array(26572847, 3405927, -31701700, 12890905, -19265668, 5335866, -6493768, 2378492, 4439158, -13279347), array(-22716706, 3489070, -9225266, -332753, 18875722, -1140095, 14819434, -12731527, -17717757, -5461437)), array(array(-5056483, 16566551, 15953661, 3767752, -10436499, 15627060, -820954, 2177225, 8550082, -15114165), array(-18473302, 16596775, -381660, 15663611, 22860960, 15585581, -27844109, -3582739, -23260460, -8428588), array(-32480551, 15707275, -8205912, -5652081, 29464558, 2713815, -22725137, 15860482, -21902570, 1494193)), array(array(-19562091, -14087393, -25583872, -9299552, 13127842, 759709, 21923482, 16529112, 8742704, 12967017), array(-28464899, 1553205, 32536856, -10473729, -24691605, -406174, -8914625, -2933896, -29903758, 15553883), array(21877909, 3230008, 9881174, 10539357, -4797115, 2841332, 11543572, 14513274, 19375923, -12647961)), array(array(8832269, -14495485, 13253511, 5137575, 5037871, 4078777, 24880818, -6222716, 2862653, 9455043), array(29306751, 5123106, 20245049, -14149889, 9592566, 8447059, -2077124, -2990080, 15511449, 4789663), array(-20679756, 7004547, 8824831, -9434977, -4045704, -3750736, -5754762, 108893, 23513200, 16652362))), array(array(array(-33256173, 4144782, -4476029, -6579123, 10770039, -7155542, -6650416, -12936300, -18319198, 10212860), array(2756081, 8598110, 7383731, -6859892, 22312759, -1105012, 21179801, 2600940, -9988298, -12506466), array(-24645692, 13317462, -30449259, -15653928, 21365574, -10869657, 11344424, 864440, -2499677, -16710063)), array(array(-26432803, 6148329, -17184412, -14474154, 18782929, -275997, -22561534, 211300, 2719757, 4940997), array(-1323882, 3911313, -6948744, 14759765, -30027150, 7851207, 21690126, 8518463, 26699843, 5276295), array(-13149873, -6429067, 9396249, 365013, 24703301, -10488939, 1321586, 149635, -15452774, 7159369)), array(array(9987780, -3404759, 17507962, 9505530, 9731535, -2165514, 22356009, 8312176, 22477218, -8403385), array(18155857, -16504990, 19744716, 9006923, 15154154, -10538976, 24256460, -4864995, -22548173, 9334109), array(2986088, -4911893, 10776628, -3473844, 10620590, -7083203, -21413845, 14253545, -22587149, 536906)), array(array(4377756, 8115836, 24567078, 15495314, 11625074, 13064599, 7390551, 10589625, 10838060, -15420424), array(-19342404, 867880, 9277171, -3218459, -14431572, -1986443, 19295826, -15796950, 6378260, 699185), array(7895026, 4057113, -7081772, -13077756, -17886831, -323126, -716039, 15693155, -5045064, -13373962)), array(array(-7737563, -5869402, -14566319, -7406919, 11385654, 13201616, 31730678, -10962840, -3918636, -9669325), array(10188286, -15770834, -7336361, 13427543, 22223443, 14896287, 30743455, 7116568, -21786507, 5427593), array(696102, 13206899, 27047647, -10632082, 15285305, -9853179, 10798490, -4578720, 19236243, 12477404)), array(array(-11229439, 11243796, -17054270, -8040865, -788228, -8167967, -3897669, 11180504, -23169516, 7733644), array(17800790, -14036179, -27000429, -11766671, 23887827, 3149671, 23466177, -10538171, 10322027, 15313801), array(26246234, 11968874, 32263343, -5468728, 6830755, -13323031, -15794704, -101982, -24449242, 10890804)), array(array(-31365647, 10271363, -12660625, -6267268, 16690207, -13062544, -14982212, 16484931, 25180797, -5334884), array(-586574, 10376444, -32586414, -11286356, 19801893, 10997610, 2276632, 9482883, 316878, 13820577), array(-9882808, -4510367, -2115506, 16457136, -11100081, 11674996, 30756178, -7515054, 30696930, -3712849)), array(array(32988917, -9603412, 12499366, 7910787, -10617257, -11931514, -7342816, -9985397, -32349517, 7392473), array(-8855661, 15927861, 9866406, -3649411, -2396914, -16655781, -30409476, -9134995, 25112947, -2926644), array(-2504044, -436966, 25621774, -5678772, 15085042, -5479877, -24884878, -13526194, 5537438, -13914319))), array(array(array(-11225584, 2320285, -9584280, 10149187, -33444663, 5808648, -14876251, -1729667, 31234590, 6090599), array(-9633316, 116426, 26083934, 2897444, -6364437, -2688086, 609721, 15878753, -6970405, -9034768), array(-27757857, 247744, -15194774, -9002551, 23288161, -10011936, -23869595, 6503646, 20650474, 1804084)), array(array(-27589786, 15456424, 8972517, 8469608, 15640622, 4439847, 3121995, -10329713, 27842616, -202328), array(-15306973, 2839644, 22530074, 10026331, 4602058, 5048462, 28248656, 5031932, -11375082, 12714369), array(20807691, -7270825, 29286141, 11421711, -27876523, -13868230, -21227475, 1035546, -19733229, 12796920)), array(array(12076899, -14301286, -8785001, -11848922, -25012791, 16400684, -17591495, -12899438, 3480665, -15182815), array(-32361549, 5457597, 28548107, 7833186, 7303070, -11953545, -24363064, -15921875, -33374054, 2771025), array(-21389266, 421932, 26597266, 6860826, 22486084, -6737172, -17137485, -4210226, -24552282, 15673397)), array(array(-20184622, 2338216, 19788685, -9620956, -4001265, -8740893, -20271184, 4733254, 3727144, -12934448), array(6120119, 814863, -11794402, -622716, 6812205, -15747771, 2019594, 7975683, 31123697, -10958981), array(30069250, -11435332, 30434654, 2958439, 18399564, -976289, 12296869, 9204260, -16432438, 9648165)), array(array(32705432, -1550977, 30705658, 7451065, -11805606, 9631813, 3305266, 5248604, -26008332, -11377501), array(17219865, 2375039, -31570947, -5575615, -19459679, 9219903, 294711, 15298639, 2662509, -16297073), array(-1172927, -7558695, -4366770, -4287744, -21346413, -8434326, 32087529, -1222777, 32247248, -14389861)), array(array(14312628, 1221556, 17395390, -8700143, -4945741, -8684635, -28197744, -9637817, -16027623, -13378845), array(-1428825, -9678990, -9235681, 6549687, -7383069, -468664, 23046502, 9803137, 17597934, 2346211), array(18510800, 15337574, 26171504, 981392, -22241552, 7827556, -23491134, -11323352, 3059833, -11782870)), array(array(10141598, 6082907, 17829293, -1947643, 9830092, 13613136, -25556636, -5544586, -33502212, 3592096), array(33114168, -15889352, -26525686, -13343397, 33076705, 8716171, 1151462, 1521897, -982665, -6837803), array(-32939165, -4255815, 23947181, -324178, -33072974, -12305637, -16637686, 3891704, 26353178, 693168)), array(array(30374239, 1595580, -16884039, 13186931, 4600344, 406904, 9585294, -400668, 31375464, 14369965), array(-14370654, -7772529, 1510301, 6434173, -18784789, -6262728, 32732230, -13108839, 17901441, 16011505), array(18171223, -11934626, -12500402, 15197122, -11038147, -15230035, -19172240, -16046376, 8764035, 12309598))), array(array(array(5975908, -5243188, -19459362, -9681747, -11541277, 14015782, -23665757, 1228319, 17544096, -10593782), array(5811932, -1715293, 3442887, -2269310, -18367348, -8359541, -18044043, -15410127, -5565381, 12348900), array(-31399660, 11407555, 25755363, 6891399, -3256938, 14872274, -24849353, 8141295, -10632534, -585479)), array(array(-12675304, 694026, -5076145, 13300344, 14015258, -14451394, -9698672, -11329050, 30944593, 1130208), array(8247766, -6710942, -26562381, -7709309, -14401939, -14648910, 4652152, 2488540, 23550156, -271232), array(17294316, -3788438, 7026748, 15626851, 22990044, 113481, 2267737, -5908146, -408818, -137719)), array(array(16091085, -16253926, 18599252, 7340678, 2137637, -1221657, -3364161, 14550936, 3260525, -7166271), array(-4910104, -13332887, 18550887, 10864893, -16459325, -7291596, -23028869, -13204905, -12748722, 2701326), array(-8574695, 16099415, 4629974, -16340524, -20786213, -6005432, -10018363, 9276971, 11329923, 1862132)), array(array(14763076, -15903608, -30918270, 3689867, 3511892, 10313526, -21951088, 12219231, -9037963, -940300), array(8894987, -3446094, 6150753, 3013931, 301220, 15693451, -31981216, -2909717, -15438168, 11595570), array(15214962, 3537601, -26238722, -14058872, 4418657, -15230761, 13947276, 10730794, -13489462, -4363670)), array(array(-2538306, 7682793, 32759013, 263109, -29984731, -7955452, -22332124, -10188635, 977108, 699994), array(-12466472, 4195084, -9211532, 550904, -15565337, 12917920, 19118110, -439841, -30534533, -14337913), array(31788461, -14507657, 4799989, 7372237, 8808585, -14747943, 9408237, -10051775, 12493932, -5409317)), array(array(-25680606, 5260744, -19235809, -6284470, -3695942, 16566087, 27218280, 2607121, 29375955, 6024730), array(842132, -2794693, -4763381, -8722815, 26332018, -12405641, 11831880, 6985184, -9940361, 2854096), array(-4847262, -7969331, 2516242, -5847713, 9695691, -7221186, 16512645, 960770, 12121869, 16648078)), array(array(-15218652, 14667096, -13336229, 2013717, 30598287, -464137, -31504922, -7882064, 20237806, 2838411), array(-19288047, 4453152, 15298546, -16178388, 22115043, -15972604, 12544294, -13470457, 1068881, -12499905), array(-9558883, -16518835, 33238498, 13506958, 30505848, -1114596, -8486907, -2630053, 12521378, 4845654)), array(array(-28198521, 10744108, -2958380, 10199664, 7759311, -13088600, 3409348, -873400, -6482306, -12885870), array(-23561822, 6230156, -20382013, 10655314, -24040585, -11621172, 10477734, -1240216, -3113227, 13974498), array(12966261, 15550616, -32038948, -1615346, 21025980, -629444, 5642325, 7188737, 18895762, 12629579))), array(array(array(14741879, -14946887, 22177208, -11721237, 1279741, 8058600, 11758140, 789443, 32195181, 3895677), array(10758205, 15755439, -4509950, 9243698, -4879422, 6879879, -2204575, -3566119, -8982069, 4429647), array(-2453894, 15725973, -20436342, -10410672, -5803908, -11040220, -7135870, -11642895, 18047436, -15281743)), array(array(-25173001, -11307165, 29759956, 11776784, -22262383, -15820455, 10993114, -12850837, -17620701, -9408468), array(21987233, 700364, -24505048, 14972008, -7774265, -5718395, 32155026, 2581431, -29958985, 8773375), array(-25568350, 454463, -13211935, 16126715, 25240068, 8594567, 20656846, 12017935, -7874389, -13920155)), array(array(6028182, 6263078, -31011806, -11301710, -818919, 2461772, -31841174, -5468042, -1721788, -2776725), array(-12278994, 16624277, 987579, -5922598, 32908203, 1248608, 7719845, -4166698, 28408820, 6816612), array(-10358094, -8237829, 19549651, -12169222, 22082623, 16147817, 20613181, 13982702, -10339570, 5067943)), array(array(-30505967, -3821767, 12074681, 13582412, -19877972, 2443951, -19719286, 12746132, 5331210, -10105944), array(30528811, 3601899, -1957090, 4619785, -27361822, -15436388, 24180793, -12570394, 27679908, -1648928), array(9402404, -13957065, 32834043, 10838634, -26580150, -13237195, 26653274, -8685565, 22611444, -12715406)), array(array(22190590, 1118029, 22736441, 15130463, -30460692, -5991321, 19189625, -4648942, 4854859, 6622139), array(-8310738, -2953450, -8262579, -3388049, -10401731, -271929, 13424426, -3567227, 26404409, 13001963), array(-31241838, -15415700, -2994250, 8939346, 11562230, -12840670, -26064365, -11621720, -15405155, 11020693)), array(array(1866042, -7949489, -7898649, -10301010, 12483315, 13477547, 3175636, -12424163, 28761762, 1406734), array(-448555, -1777666, 13018551, 3194501, -9580420, -11161737, 24760585, -4347088, 25577411, -13378680), array(-24290378, 4759345, -690653, -1852816, 2066747, 10693769, -29595790, 9884936, -9368926, 4745410)), array(array(-9141284, 6049714, -19531061, -4341411, -31260798, 9944276, -15462008, -11311852, 10931924, -11931931), array(-16561513, 14112680, -8012645, 4817318, -8040464, -11414606, -22853429, 10856641, -20470770, 13434654), array(22759489, -10073434, -16766264, -1871422, 13637442, -10168091, 1765144, -12654326, 28445307, -5364710)), array(array(29875063, 12493613, 2795536, -3786330, 1710620, 15181182, -10195717, -8788675, 9074234, 1167180), array(-26205683, 11014233, -9842651, -2635485, -26908120, 7532294, -18716888, -9535498, 3843903, 9367684), array(-10969595, -6403711, 9591134, 9582310, 11349256, 108879, 16235123, 8601684, -139197, 4242895))), array(array(array(22092954, -13191123, -2042793, -11968512, 32186753, -11517388, -6574341, 2470660, -27417366, 16625501), array(-11057722, 3042016, 13770083, -9257922, 584236, -544855, -7770857, 2602725, -27351616, 14247413), array(6314175, -10264892, -32772502, 15957557, -10157730, 168750, -8618807, 14290061, 27108877, -1180880)), array(array(-8586597, -7170966, 13241782, 10960156, -32991015, -13794596, 33547976, -11058889, -27148451, 981874), array(22833440, 9293594, -32649448, -13618667, -9136966, 14756819, -22928859, -13970780, -10479804, -16197962), array(-7768587, 3326786, -28111797, 10783824, 19178761, 14905060, 22680049, 13906969, -15933690, 3797899)), array(array(21721356, -4212746, -12206123, 9310182, -3882239, -13653110, 23740224, -2709232, 20491983, -8042152), array(9209270, -15135055, -13256557, -6167798, -731016, 15289673, 25947805, 15286587, 30997318, -6703063), array(7392032, 16618386, 23946583, -8039892, -13265164, -1533858, -14197445, -2321576, 17649998, -250080)), array(array(-9301088, -14193827, 30609526, -3049543, -25175069, -1283752, -15241566, -9525724, -2233253, 7662146), array(-17558673, 1763594, -33114336, 15908610, -30040870, -12174295, 7335080, -8472199, -3174674, 3440183), array(-19889700, -5977008, -24111293, -9688870, 10799743, -16571957, 40450, -4431835, 4862400, 1133)), array(array(-32856209, -7873957, -5422389, 14860950, -16319031, 7956142, 7258061, 311861, -30594991, -7379421), array(-3773428, -1565936, 28985340, 7499440, 24445838, 9325937, 29727763, 16527196, 18278453, 15405622), array(-4381906, 8508652, -19898366, -3674424, -5984453, 15149970, -13313598, 843523, -21875062, 13626197)), array(array(2281448, -13487055, -10915418, -2609910, 1879358, 16164207, -10783882, 3953792, 13340839, 15928663), array(31727126, -7179855, -18437503, -8283652, 2875793, -16390330, -25269894, -7014826, -23452306, 5964753), array(4100420, -5959452, -17179337, 6017714, -18705837, 12227141, -26684835, 11344144, 2538215, -7570755)), array(array(-9433605, 6123113, 11159803, -2156608, 30016280, 14966241, -20474983, 1485421, -629256, -15958862), array(-26804558, 4260919, 11851389, 9658551, -32017107, 16367492, -20205425, -13191288, 11659922, -11115118), array(26180396, 10015009, -30844224, -8581293, 5418197, 9480663, 2231568, -10170080, 33100372, -1306171)), array(array(15121113, -5201871, -10389905, 15427821, -27509937, -15992507, 21670947, 4486675, -5931810, -14466380), array(16166486, -9483733, -11104130, 6023908, -31926798, -1364923, 2340060, -16254968, -10735770, -10039824), array(28042865, -3557089, -12126526, 12259706, -3717498, -6945899, 6766453, -8689599, 18036436, 5803270))), array(array(array(-817581, 6763912, 11803561, 1585585, 10958447, -2671165, 23855391, 4598332, -6159431, -14117438), array(-31031306, -14256194, 17332029, -2383520, 31312682, -5967183, 696309, 50292, -20095739, 11763584), array(-594563, -2514283, -32234153, 12643980, 12650761, 14811489, 665117, -12613632, -19773211, -10713562)), array(array(30464590, -11262872, -4127476, -12734478, 19835327, -7105613, -24396175, 2075773, -17020157, 992471), array(18357185, -6994433, 7766382, 16342475, -29324918, 411174, 14578841, 8080033, -11574335, -10601610), array(19598397, 10334610, 12555054, 2555664, 18821899, -10339780, 21873263, 16014234, 26224780, 16452269)), array(array(-30223925, 5145196, 5944548, 16385966, 3976735, 2009897, -11377804, -7618186, -20533829, 3698650), array(14187449, 3448569, -10636236, -10810935, -22663880, -3433596, 7268410, -10890444, 27394301, 12015369), array(19695761, 16087646, 28032085, 12999827, 6817792, 11427614, 20244189, -1312777, -13259127, -3402461)), array(array(30860103, 12735208, -1888245, -4699734, -16974906, 2256940, -8166013, 12298312, -8550524, -10393462), array(-5719826, -11245325, -1910649, 15569035, 26642876, -7587760, -5789354, -15118654, -4976164, 12651793), array(-2848395, 9953421, 11531313, -5282879, 26895123, -12697089, -13118820, -16517902, 9768698, -2533218)), array(array(-24719459, 1894651, -287698, -4704085, 15348719, -8156530, 32767513, 12765450, 4940095, 10678226), array(18860224, 15980149, -18987240, -1562570, -26233012, -11071856, -7843882, 13944024, -24372348, 16582019), array(-15504260, 4970268, -29893044, 4175593, -20993212, -2199756, -11704054, 15444560, -11003761, 7989037)), array(array(31490452, 5568061, -2412803, 2182383, -32336847, 4531686, -32078269, 6200206, -19686113, -14800171), array(-17308668, -15879940, -31522777, -2831, -32887382, 16375549, 8680158, -16371713, 28550068, -6857132), array(-28126887, -5688091, 16837845, -1820458, -6850681, 12700016, -30039981, 4364038, 1155602, 5988841)), array(array(21890435, -13272907, -12624011, 12154349, -7831873, 15300496, 23148983, -4470481, 24618407, 8283181), array(-33136107, -10512751, 9975416, 6841041, -31559793, 16356536, 3070187, -7025928, 1466169, 10740210), array(-1509399, -15488185, -13503385, -10655916, 32799044, 909394, -13938903, -5779719, -32164649, -15327040)), array(array(3960823, -14267803, -28026090, -15918051, -19404858, 13146868, 15567327, 951507, -3260321, -573935), array(24740841, 5052253, -30094131, 8961361, 25877428, 6165135, -24368180, 14397372, -7380369, -6144105), array(-28888365, 3510803, -28103278, -1158478, -11238128, -10631454, -15441463, -14453128, -1625486, -6494814))), array(array(array(793299, -9230478, 8836302, -6235707, -27360908, -2369593, 33152843, -4885251, -9906200, -621852), array(5666233, 525582, 20782575, -8038419, -24538499, 14657740, 16099374, 1468826, -6171428, -15186581), array(-4859255, -3779343, -2917758, -6748019, 7778750, 11688288, -30404353, -9871238, -1558923, -9863646)), array(array(10896332, -7719704, 824275, 472601, -19460308, 3009587, 25248958, 14783338, -30581476, -15757844), array(10566929, 12612572, -31944212, 11118703, -12633376, 12362879, 21752402, 8822496, 24003793, 14264025), array(27713862, -7355973, -11008240, 9227530, 27050101, 2504721, 23886875, -13117525, 13958495, -5732453)), array(array(-23481610, 4867226, -27247128, 3900521, 29838369, -8212291, -31889399, -10041781, 7340521, -15410068), array(4646514, -8011124, -22766023, -11532654, 23184553, 8566613, 31366726, -1381061, -15066784, -10375192), array(-17270517, 12723032, -16993061, 14878794, 21619651, -6197576, 27584817, 3093888, -8843694, 3849921)), array(array(-9064912, 2103172, 25561640, -15125738, -5239824, 9582958, 32477045, -9017955, 5002294, -15550259), array(-12057553, -11177906, 21115585, -13365155, 8808712, -12030708, 16489530, 13378448, -25845716, 12741426), array(-5946367, 10645103, -30911586, 15390284, -3286982, -7118677, 24306472, 15852464, 28834118, -7646072)), array(array(-17335748, -9107057, -24531279, 9434953, -8472084, -583362, -13090771, 455841, 20461858, 5491305), array(13669248, -16095482, -12481974, -10203039, -14569770, -11893198, -24995986, 11293807, -28588204, -9421832), array(28497928, 6272777, -33022994, 14470570, 8906179, -1225630, 18504674, -14165166, 29867745, -8795943)), array(array(-16207023, 13517196, -27799630, -13697798, 24009064, -6373891, -6367600, -13175392, 22853429, -4012011), array(24191378, 16712145, -13931797, 15217831, 14542237, 1646131, 18603514, -11037887, 12876623, -2112447), array(17902668, 4518229, -411702, -2829247, 26878217, 5258055, -12860753, 608397, 16031844, 3723494)), array(array(-28632773, 12763728, -20446446, 7577504, 33001348, -13017745, 17558842, -7872890, 23896954, -4314245), array(-20005381, -12011952, 31520464, 605201, 2543521, 5991821, -2945064, 7229064, -9919646, -8826859), array(28816045, 298879, -28165016, -15920938, 19000928, -1665890, -12680833, -2949325, -18051778, -2082915)), array(array(16000882, -344896, 3493092, -11447198, -29504595, -13159789, 12577740, 16041268, -19715240, 7847707), array(10151868, 10572098, 27312476, 7922682, 14825339, 4723128, -32855931, -6519018, -10020567, 3852848), array(-11430470, 15697596, -21121557, -4420647, 5386314, 15063598, 16514493, -15932110, 29330899, -15076224))), array(array(array(-25499735, -4378794, -15222908, -6901211, 16615731, 2051784, 3303702, 15490, -27548796, 12314391), array(15683520, -6003043, 18109120, -9980648, 15337968, -5997823, -16717435, 15921866, 16103996, -3731215), array(-23169824, -10781249, 13588192, -1628807, -3798557, -1074929, -19273607, 5402699, -29815713, -9841101)), array(array(23190676, 2384583, -32714340, 3462154, -29903655, -1529132, -11266856, 8911517, -25205859, 2739713), array(21374101, -3554250, -33524649, 9874411, 15377179, 11831242, -33529904, 6134907, 4931255, 11987849), array(-7732, -2978858, -16223486, 7277597, 105524, -322051, -31480539, 13861388, -30076310, 10117930)), array(array(-29501170, -10744872, -26163768, 13051539, -25625564, 5089643, -6325503, 6704079, 12890019, 15728940), array(-21972360, -11771379, -951059, -4418840, 14704840, 2695116, 903376, -10428139, 12885167, 8311031), array(-17516482, 5352194, 10384213, -13811658, 7506451, 13453191, 26423267, 4384730, 1888765, -5435404)), array(array(-25817338, -3107312, -13494599, -3182506, 30896459, -13921729, -32251644, -12707869, -19464434, -3340243), array(-23607977, -2665774, -526091, 4651136, 5765089, 4618330, 6092245, 14845197, 17151279, -9854116), array(-24830458, -12733720, -15165978, 10367250, -29530908, -265356, 22825805, -7087279, -16866484, 16176525)), array(array(-23583256, 6564961, 20063689, 3798228, -4740178, 7359225, 2006182, -10363426, -28746253, -10197509), array(-10626600, -4486402, -13320562, -5125317, 3432136, -6393229, 23632037, -1940610, 32808310, 1099883), array(15030977, 5768825, -27451236, -2887299, -6427378, -15361371, -15277896, -6809350, 2051441, -15225865)), array(array(-3362323, -7239372, 7517890, 9824992, 23555850, 295369, 5148398, -14154188, -22686354, 16633660), array(4577086, -16752288, 13249841, -15304328, 19958763, -14537274, 18559670, -10759549, 8402478, -9864273), array(-28406330, -1051581, -26790155, -907698, -17212414, -11030789, 9453451, -14980072, 17983010, 9967138)), array(array(-25762494, 6524722, 26585488, 9969270, 24709298, 1220360, -1677990, 7806337, 17507396, 3651560), array(-10420457, -4118111, 14584639, 15971087, -15768321, 8861010, 26556809, -5574557, -18553322, -11357135), array(2839101, 14284142, 4029895, 3472686, 14402957, 12689363, -26642121, 8459447, -5605463, -7621941)), array(array(-4839289, -3535444, 9744961, 2871048, 25113978, 3187018, -25110813, -849066, 17258084, -7977739), array(18164541, -10595176, -17154882, -1542417, 19237078, -9745295, 23357533, -15217008, 26908270, 12150756), array(-30264870, -7647865, 5112249, -7036672, -1499807, -6974257, 43168, -5537701, -32302074, 16215819))), array(array(array(-6898905, 9824394, -12304779, -4401089, -31397141, -6276835, 32574489, 12532905, -7503072, -8675347), array(-27343522, -16515468, -27151524, -10722951, 946346, 16291093, 254968, 7168080, 21676107, -1943028), array(21260961, -8424752, -16831886, -11920822, -23677961, 3968121, -3651949, -6215466, -3556191, -7913075)), array(array(16544754, 13250366, -16804428, 15546242, -4583003, 12757258, -2462308, -8680336, -18907032, -9662799), array(-2415239, -15577728, 18312303, 4964443, -15272530, -12653564, 26820651, 16690659, 25459437, -4564609), array(-25144690, 11425020, 28423002, -11020557, -6144921, -15826224, 9142795, -2391602, -6432418, -1644817)), array(array(-23104652, 6253476, 16964147, -3768872, -25113972, -12296437, -27457225, -16344658, 6335692, 7249989), array(-30333227, 13979675, 7503222, -12368314, -11956721, -4621693, -30272269, 2682242, 25993170, -12478523), array(4364628, 5930691, 32304656, -10044554, -8054781, 15091131, 22857016, -10598955, 31820368, 15075278)), array(array(31879134, -8918693, 17258761, 90626, -8041836, -4917709, 24162788, -9650886, -17970238, 12833045), array(19073683, 14851414, -24403169, -11860168, 7625278, 11091125, -19619190, 2074449, -9413939, 14905377), array(24483667, -11935567, -2518866, -11547418, -1553130, 15355506, -25282080, 9253129, 27628530, -7555480)), array(array(17597607, 8340603, 19355617, 552187, 26198470, -3176583, 4593324, -9157582, -14110875, 15297016), array(510886, 14337390, -31785257, 16638632, 6328095, 2713355, -20217417, -11864220, 8683221, 2921426), array(18606791, 11874196, 27155355, -5281482, -24031742, 6265446, -25178240, -1278924, 4674690, 13890525)), array(array(13609624, 13069022, -27372361, -13055908, 24360586, 9592974, 14977157, 9835105, 4389687, 288396), array(9922506, -519394, 13613107, 5883594, -18758345, -434263, -12304062, 8317628, 23388070, 16052080), array(12720016, 11937594, -31970060, -5028689, 26900120, 8561328, -20155687, -11632979, -14754271, -10812892)), array(array(15961858, 14150409, 26716931, -665832, -22794328, 13603569, 11829573, 7467844, -28822128, 929275), array(11038231, -11582396, -27310482, -7316562, -10498527, -16307831, -23479533, -9371869, -21393143, 2465074), array(20017163, -4323226, 27915242, 1529148, 12396362, 15675764, 13817261, -9658066, 2463391, -4622140)), array(array(-16358878, -12663911, -12065183, 4996454, -1256422, 1073572, 9583558, 12851107, 4003896, 12673717), array(-1731589, -15155870, -3262930, 16143082, 19294135, 13385325, 14741514, -9103726, 7903886, 2348101), array(24536016, -16515207, 12715592, -3862155, 1511293, 10047386, -3842346, -7129159, -28377538, 10048127))), array(array(array(-12622226, -6204820, 30718825, 2591312, -10617028, 12192840, 18873298, -7297090, -32297756, 15221632), array(-26478122, -11103864, 11546244, -1852483, 9180880, 7656409, -21343950, 2095755, 29769758, 6593415), array(-31994208, -2907461, 4176912, 3264766, 12538965, -868111, 26312345, -6118678, 30958054, 8292160)), array(array(31429822, -13959116, 29173532, 15632448, 12174511, -2760094, 32808831, 3977186, 26143136, -3148876), array(22648901, 1402143, -22799984, 13746059, 7936347, 365344, -8668633, -1674433, -3758243, -2304625), array(-15491917, 8012313, -2514730, -12702462, -23965846, -10254029, -1612713, -1535569, -16664475, 8194478)), array(array(27338066, -7507420, -7414224, 10140405, -19026427, -6589889, 27277191, 8855376, 28572286, 3005164), array(26287124, 4821776, 25476601, -4145903, -3764513, -15788984, -18008582, 1182479, -26094821, -13079595), array(-7171154, 3178080, 23970071, 6201893, -17195577, -4489192, -21876275, -13982627, 32208683, -1198248)), array(array(-16657702, 2817643, -10286362, 14811298, 6024667, 13349505, -27315504, -10497842, -27672585, -11539858), array(15941029, -9405932, -21367050, 8062055, 31876073, -238629, -15278393, -1444429, 15397331, -4130193), array(8934485, -13485467, -23286397, -13423241, -32446090, 14047986, 31170398, -1441021, -27505566, 15087184)), array(array(-18357243, -2156491, 24524913, -16677868, 15520427, -6360776, -15502406, 11461896, 16788528, -5868942), array(-1947386, 16013773, 21750665, 3714552, -17401782, -16055433, -3770287, -10323320, 31322514, -11615635), array(21426655, -5650218, -13648287, -5347537, -28812189, -4920970, -18275391, -14621414, 13040862, -12112948)), array(array(11293895, 12478086, -27136401, 15083750, -29307421, 14748872, 14555558, -13417103, 1613711, 4896935), array(-25894883, 15323294, -8489791, -8057900, 25967126, -13425460, 2825960, -4897045, -23971776, -11267415), array(-15924766, -5229880, -17443532, 6410664, 3622847, 10243618, 20615400, 12405433, -23753030, -8436416)), array(array(-7091295, 12556208, -20191352, 9025187, -17072479, 4333801, 4378436, 2432030, 23097949, -566018), array(4565804, -16025654, 20084412, -7842817, 1724999, 189254, 24767264, 10103221, -18512313, 2424778), array(366633, -11976806, 8173090, -6890119, 30788634, 5745705, -7168678, 1344109, -3642553, 12412659)), array(array(-24001791, 7690286, 14929416, -168257, -32210835, -13412986, 24162697, -15326504, -3141501, 11179385), array(18289522, -14724954, 8056945, 16430056, -21729724, 7842514, -6001441, -1486897, -18684645, -11443503), array(476239, 6601091, -6152790, -9723375, 17503545, -4863900, 27672959, 13403813, 11052904, 5219329))), array(array(array(20678546, -8375738, -32671898, 8849123, -5009758, 14574752, 31186971, -3973730, 9014762, -8579056), array(-13644050, -10350239, -15962508, 5075808, -1514661, -11534600, -33102500, 9160280, 8473550, -3256838), array(24900749, 14435722, 17209120, -15292541, -22592275, 9878983, -7689309, -16335821, -24568481, 11788948)), array(array(-3118155, -11395194, -13802089, 14797441, 9652448, -6845904, -20037437, 10410733, -24568470, -1458691), array(-15659161, 16736706, -22467150, 10215878, -9097177, 7563911, 11871841, -12505194, -18513325, 8464118), array(-23400612, 8348507, -14585951, -861714, -3950205, -6373419, 14325289, 8628612, 33313881, -8370517)), array(array(-20186973, -4967935, 22367356, 5271547, -1097117, -4788838, -24805667, -10236854, -8940735, -5818269), array(-6948785, -1795212, -32625683, -16021179, 32635414, -7374245, 15989197, -12838188, 28358192, -4253904), array(-23561781, -2799059, -32351682, -1661963, -9147719, 10429267, -16637684, 4072016, -5351664, 5596589)), array(array(-28236598, -3390048, 12312896, 6213178, 3117142, 16078565, 29266239, 2557221, 1768301, 15373193), array(-7243358, -3246960, -4593467, -7553353, -127927, -912245, -1090902, -4504991, -24660491, 3442910), array(-30210571, 5124043, 14181784, 8197961, 18964734, -11939093, 22597931, 7176455, -18585478, 13365930)), array(array(-7877390, -1499958, 8324673, 4690079, 6261860, 890446, 24538107, -8570186, -9689599, -3031667), array(25008904, -10771599, -4305031, -9638010, 16265036, 15721635, 683793, -11823784, 15723479, -15163481), array(-9660625, 12374379, -27006999, -7026148, -7724114, -12314514, 11879682, 5400171, 519526, -1235876)), array(array(22258397, -16332233, -7869817, 14613016, -22520255, -2950923, -20353881, 7315967, 16648397, 7605640), array(-8081308, -8464597, -8223311, 9719710, 19259459, -15348212, 23994942, -5281555, -9468848, 4763278), array(-21699244, 9220969, -15730624, 1084137, -25476107, -2852390, 31088447, -7764523, -11356529, 728112)), array(array(26047220, -11751471, -6900323, -16521798, 24092068, 9158119, -4273545, -12555558, -29365436, -5498272), array(17510331, -322857, 5854289, 8403524, 17133918, -3112612, -28111007, 12327945, 10750447, 10014012), array(-10312768, 3936952, 9156313, -8897683, 16498692, -994647, -27481051, -666732, 3424691, 7540221)), array(array(30322361, -6964110, 11361005, -4143317, 7433304, 4989748, -7071422, -16317219, -9244265, 15258046), array(13054562, -2779497, 19155474, 469045, -12482797, 4566042, 5631406, 2711395, 1062915, -5136345), array(-19240248, -11254599, -29509029, -7499965, -5835763, 13005411, -6066489, 12194497, 32960380, 1459310))), array(array(array(19852034, 7027924, 23669353, 10020366, 8586503, -6657907, 394197, -6101885, 18638003, -11174937), array(31395534, 15098109, 26581030, 8030562, -16527914, -5007134, 9012486, -7584354, -6643087, -5442636), array(-9192165, -2347377, -1997099, 4529534, 25766844, 607986, -13222, 9677543, -32294889, -6456008)), array(array(-2444496, -149937, 29348902, 8186665, 1873760, 12489863, -30934579, -7839692, -7852844, -8138429), array(-15236356, -15433509, 7766470, 746860, 26346930, -10221762, -27333451, 10754588, -9431476, 5203576), array(31834314, 14135496, -770007, 5159118, 20917671, -16768096, -7467973, -7337524, 31809243, 7347066)), array(array(-9606723, -11874240, 20414459, 13033986, 13716524, -11691881, 19797970, -12211255, 15192876, -2087490), array(-12663563, -2181719, 1168162, -3804809, 26747877, -14138091, 10609330, 12694420, 33473243, -13382104), array(33184999, 11180355, 15832085, -11385430, -1633671, 225884, 15089336, -11023903, -6135662, 14480053)), array(array(31308717, -5619998, 31030840, -1897099, 15674547, -6582883, 5496208, 13685227, 27595050, 8737275), array(-20318852, -15150239, 10933843, -16178022, 8335352, -7546022, -31008351, -12610604, 26498114, 66511), array(22644454, -8761729, -16671776, 4884562, -3105614, -13559366, 30540766, -4286747, -13327787, -7515095)), array(array(-28017847, 9834845, 18617207, -2681312, -3401956, -13307506, 8205540, 13585437, -17127465, 15115439), array(23711543, -672915, 31206561, -8362711, 6164647, -9709987, -33535882, -1426096, 8236921, 16492939), array(-23910559, -13515526, -26299483, -4503841, 25005590, -7687270, 19574902, 10071562, 6708380, -6222424)), array(array(2101391, -4930054, 19702731, 2367575, -15427167, 1047675, 5301017, 9328700, 29955601, -11678310), array(3096359, 9271816, -21620864, -15521844, -14847996, -7592937, -25892142, -12635595, -9917575, 6216608), array(-32615849, 338663, -25195611, 2510422, -29213566, -13820213, 24822830, -6146567, -26767480, 7525079)), array(array(-23066649, -13985623, 16133487, -7896178, -3389565, 778788, -910336, -2782495, -19386633, 11994101), array(21691500, -13624626, -641331, -14367021, 3285881, -3483596, -25064666, 9718258, -7477437, 13381418), array(18445390, -4202236, 14979846, 11622458, -1727110, -3582980, 23111648, -6375247, 28535282, 15779576)), array(array(30098053, 3089662, -9234387, 16662135, -21306940, 11308411, -14068454, 12021730, 9955285, -16303356), array(9734894, -14576830, -7473633, -9138735, 2060392, 11313496, -18426029, 9924399, 20194861, 13380996), array(-26378102, -7965207, -22167821, 15789297, -18055342, -6168792, -1984914, 15707771, 26342023, 10146099))), array(array(array(-26016874, -219943, 21339191, -41388, 19745256, -2878700, -29637280, 2227040, 21612326, -545728), array(-13077387, 1184228, 23562814, -5970442, -20351244, -6348714, 25764461, 12243797, -20856566, 11649658), array(-10031494, 11262626, 27384172, 2271902, 26947504, -15997771, 39944, 6114064, 33514190, 2333242)), array(array(-21433588, -12421821, 8119782, 7219913, -21830522, -9016134, -6679750, -12670638, 24350578, -13450001), array(-4116307, -11271533, -23886186, 4843615, -30088339, 690623, -31536088, -10406836, 8317860, 12352766), array(18200138, -14475911, -33087759, -2696619, -23702521, -9102511, -23552096, -2287550, 20712163, 6719373)), array(array(26656208, 6075253, -7858556, 1886072, -28344043, 4262326, 11117530, -3763210, 26224235, -3297458), array(-17168938, -14854097, -3395676, -16369877, -19954045, 14050420, 21728352, 9493610, 18620611, -16428628), array(-13323321, 13325349, 11432106, 5964811, 18609221, 6062965, -5269471, -9725556, -30701573, -16479657)), array(array(-23860538, -11233159, 26961357, 1640861, -32413112, -16737940, 12248509, -5240639, 13735342, 1934062), array(25089769, 6742589, 17081145, -13406266, 21909293, -16067981, -15136294, -3765346, -21277997, 5473616), array(31883677, -7961101, 1083432, -11572403, 22828471, 13290673, -7125085, 12469656, 29111212, -5451014)), array(array(24244947, -15050407, -26262976, 2791540, -14997599, 16666678, 24367466, 6388839, -10295587, 452383), array(-25640782, -3417841, 5217916, 16224624, 19987036, -4082269, -24236251, -5915248, 15766062, 8407814), array(-20406999, 13990231, 15495425, 16395525, 5377168, 15166495, -8917023, -4388953, -8067909, 2276718)), array(array(30157918, 12924066, -17712050, 9245753, 19895028, 3368142, -23827587, 5096219, 22740376, -7303417), array(2041139, -14256350, 7783687, 13876377, -25946985, -13352459, 24051124, 13742383, -15637599, 13295222), array(33338237, -8505733, 12532113, 7977527, 9106186, -1715251, -17720195, -4612972, -4451357, -14669444)), array(array(-20045281, 5454097, -14346548, 6447146, 28862071, 1883651, -2469266, -4141880, 7770569, 9620597), array(23208068, 7979712, 33071466, 8149229, 1758231, -10834995, 30945528, -1694323, -33502340, -14767970), array(1439958, -16270480, -1079989, -793782, 4625402, 10647766, -5043801, 1220118, 30494170, -11440799)), array(array(-5037580, -13028295, -2970559, -3061767, 15640974, -6701666, -26739026, 926050, -1684339, -13333647), array(13908495, -3549272, 30919928, -6273825, -21521863, 7989039, 9021034, 9078865, 3353509, 4033511), array(-29663431, -15113610, 32259991, -344482, 24295849, -12912123, 23161163, 8839127, 27485041, 7356032))), array(array(array(9661027, 705443, 11980065, -5370154, -1628543, 14661173, -6346142, 2625015, 28431036, -16771834), array(-23839233, -8311415, -25945511, 7480958, -17681669, -8354183, -22545972, 14150565, 15970762, 4099461), array(29262576, 16756590, 26350592, -8793563, 8529671, -11208050, 13617293, -9937143, 11465739, 8317062)), array(array(-25493081, -6962928, 32500200, -9419051, -23038724, -2302222, 14898637, 3848455, 20969334, -5157516), array(-20384450, -14347713, -18336405, 13884722, -33039454, 2842114, -21610826, -3649888, 11177095, 14989547), array(-24496721, -11716016, 16959896, 2278463, 12066309, 10137771, 13515641, 2581286, -28487508, 9930240)), array(array(-17751622, -2097826, 16544300, -13009300, -15914807, -14949081, 18345767, -13403753, 16291481, -5314038), array(-33229194, 2553288, 32678213, 9875984, 8534129, 6889387, -9676774, 6957617, 4368891, 9788741), array(16660756, 7281060, -10830758, 12911820, 20108584, -8101676, -21722536, -8613148, 16250552, -11111103)), array(array(-19765507, 2390526, -16551031, 14161980, 1905286, 6414907, 4689584, 10604807, -30190403, 4782747), array(-1354539, 14736941, -7367442, -13292886, 7710542, -14155590, -9981571, 4383045, 22546403, 437323), array(31665577, -12180464, -16186830, 1491339, -18368625, 3294682, 27343084, 2786261, -30633590, -14097016)), array(array(-14467279, -683715, -33374107, 7448552, 19294360, 14334329, -19690631, 2355319, -19284671, -6114373), array(15121312, -15796162, 6377020, -6031361, -10798111, -12957845, 18952177, 15496498, -29380133, 11754228), array(-2637277, -13483075, 8488727, -14303896, 12728761, -1622493, 7141596, 11724556, 22761615, -10134141)), array(array(16918416, 11729663, -18083579, 3022987, -31015732, -13339659, -28741185, -12227393, 32851222, 11717399), array(11166634, 7338049, -6722523, 4531520, -29468672, -7302055, 31474879, 3483633, -1193175, -4030831), array(-185635, 9921305, 31456609, -13536438, -12013818, 13348923, 33142652, 6546660, -19985279, -3948376)), array(array(-32460596, 11266712, -11197107, -7899103, 31703694, 3855903, -8537131, -12833048, -30772034, -15486313), array(-18006477, 12709068, 3991746, -6479188, -21491523, -10550425, -31135347, -16049879, 10928917, 3011958), array(-6957757, -15594337, 31696059, 334240, 29576716, 14796075, -30831056, -12805180, 18008031, 10258577)), array(array(-22448644, 15655569, 7018479, -4410003, -30314266, -1201591, -1853465, 1367120, 25127874, 6671743), array(29701166, -14373934, -10878120, 9279288, -17568, 13127210, 21382910, 11042292, 25838796, 4642684), array(-20430234, 14955537, -24126347, 8124619, -5369288, -5990470, 30468147, -13900640, 18423289, 4177476))));
        /**
         * See: libsodium's crypto_core/curve25519/ref10/base2.h
         *
         * @var array basically int[8][3]
         */
        protected static $base2 = array(array(array(25967493, -14356035, 29566456, 3660896, -12694345, 4014787, 27544626, -11754271, -6079156, 2047605), array(-12545711, 934262, -2722910, 3049990, -727428, 9406986, 12720692, 5043384, 19500929, -15469378), array(-8738181, 4489570, 9688441, -14785194, 10184609, -12363380, 29287919, 11864899, -24514362, -4438546)), array(array(15636291, -9688557, 24204773, -7912398, 616977, -16685262, 27787600, -14772189, 28944400, -1550024), array(16568933, 4717097, -11556148, -1102322, 15682896, -11807043, 16354577, -11775962, 7689662, 11199574), array(30464156, -5976125, -11779434, -15670865, 23220365, 15915852, 7512774, 10017326, -17749093, -9920357)), array(array(10861363, 11473154, 27284546, 1981175, -30064349, 12577861, 32867885, 14515107, -15438304, 10819380), array(4708026, 6336745, 20377586, 9066809, -11272109, 6594696, -25653668, 12483688, -12668491, 5581306), array(19563160, 16186464, -29386857, 4097519, 10237984, -4348115, 28542350, 13850243, -23678021, -15815942)), array(array(5153746, 9909285, 1723747, -2777874, 30523605, 5516873, 19480852, 5230134, -23952439, -15175766), array(-30269007, -3463509, 7665486, 10083793, 28475525, 1649722, 20654025, 16520125, 30598449, 7715701), array(28881845, 14381568, 9657904, 3680757, -20181635, 7843316, -31400660, 1370708, 29794553, -1409300)), array(array(-22518993, -6692182, 14201702, -8745502, -23510406, 8844726, 18474211, -1361450, -13062696, 13821877), array(-6455177, -7839871, 3374702, -4740862, -27098617, -10571707, 31655028, -7212327, 18853322, -14220951), array(4566830, -12963868, -28974889, -12240689, -7602672, -2830569, -8514358, -10431137, 2207753, -3209784)), array(array(-25154831, -4185821, 29681144, 7868801, -6854661, -9423865, -12437364, -663000, -31111463, -16132436), array(25576264, -2703214, 7349804, -11814844, 16472782, 9300885, 3844789, 15725684, 171356, 6466918), array(23103977, 13316479, 9739013, -16149481, 817875, -15038942, 8965339, -14088058, -30714912, 16193877)), array(array(-33521811, 3180713, -2394130, 14003687, -16903474, -16270840, 17238398, 4729455, -18074513, 9256800), array(-25182317, -4174131, 32336398, 5036987, -21236817, 11360617, 22616405, 9761698, -19827198, 630305), array(-13720693, 2639453, -24237460, -7406481, 9494427, -5774029, -6554551, -15960994, -2449256, -14291300)), array(array(-3151181, -5046075, 9282714, 6866145, -31907062, -863023, -18940575, 15033784, 25105118, -7894876), array(-24326370, 15950226, -31801215, -14592823, -11662737, -5090925, 1573892, -2625887, 2198790, -15804619), array(-3099351, 10324967, -2241613, 7453183, -5446979, -2735503, -13812022, -16236442, -32461234, -12290683)));
        /**
         * 37095705934669439343138083508754565189542113879843219016388785533085940283555
         *
         * @var array<int, int>
         */
        protected static $d = array(-10913610, 13857413, -15372611, 6949391, 114729, -8787816, -6275908, -3247719, -18696448, -12055116);
        /**
         * 2 * d = 16295367250680780974490674513165176452449235426866156013048779062215315747161
         *
         * @var array<int, int>
         */
        protected static $d2 = array(-21827239, -5839606, -30745221, 13898782, 229458, 15978800, -12551817, -6495438, 29715968, 9444199);
        /**
         * sqrt(-1)
         *
         * @var array<int, int>
         */
        protected static $sqrtm1 = array(-32595792, -7943725, 9377950, 3500415, 12389472, -272473, -25146209, -2005654, 326686, 11406482);
        /**
         * 1 / sqrt(a - d)
         *
         * @var array<int, int>
         */
        protected static $invsqrtamd = array(6111485, 4156064, -27798727, 12243468, -25904040, 120897, 20826367, -7060776, 6093568, -1986012);
        /**
         *  sqrt(ad - 1) with a = -1 (mod p)
         *
         * @var array<int, int>
         */
        protected static $sqrtadm1 = array(24849947, -153582, -23613485, 6347715, -21072328, -667138, -25271143, -15367704, -870347, 14525639);
        /**
         * 1 - d ^ 2
         *
         * @var array<int, int>
         */
        protected static $onemsqd = array(6275446, -16617371, -22938544, -3773710, 11667077, 7397348, -27922721, 1766195, -24433858, 672203);
        /**
         * (d - 1) ^ 2
         * @var array<int, int>
         */
        protected static $sqdmone = array(15551795, -11097455, -13425098, -10125071, -11896535, 10178284, -26634327, 4729244, -5282110, -10116402);
        /*
         *  2^252+27742317777372353535851937790883648493
            static const unsigned char L[] = {
                0xed, 0xd3, 0xf5, 0x5c, 0x1a, 0x63, 0x12, 0x58, 0xd6, 0x9c, 0xf7,
                0xa2, 0xde, 0xf9, 0xde, 0x14, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
                0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x10
            };
        */
        const L = "\xed\xd3\xf5\\\x1ac\x12X֜\xf7\xa2\xde\xf9\xde\x14\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x10";
    }
    /**
     * Class ParagonIE_Sodium_Core_Curve25519
     *
     * Implements Curve25519 core functions
     *
     * Based on the ref10 curve25519 code provided by libsodium
     *
     * @ref https://github.com/jedisct1/libsodium/blob/master/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c
     */
    abstract class ParagonIE_Sodium_Core_Curve25519 extends \ParagonIE_Sodium_Core_Curve25519_H
    {
        /**
         * Get a field element of size 10 with a value of 0
         *
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_0()
        {
        }
        /**
         * Get a field element of size 10 with a value of 1
         *
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_1()
        {
        }
        /**
         * Add two field elements.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         */
        public static function fe_add(\ParagonIE_Sodium_Core_Curve25519_Fe $f, \ParagonIE_Sodium_Core_Curve25519_Fe $g)
        {
        }
        /**
         * Constant-time conditional move.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $g
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @psalm-suppress MixedAssignment
         */
        public static function fe_cmov(\ParagonIE_Sodium_Core_Curve25519_Fe $f, \ParagonIE_Sodium_Core_Curve25519_Fe $g, $b = 0)
        {
        }
        /**
         * Create a copy of a field element.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_copy(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Give: 32-byte string.
         * Receive: A field element object to use for internal calculations.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @throws RangeException
         * @throws TypeError
         */
        public static function fe_frombytes($s)
        {
        }
        /**
         * Convert a field element to a byte string.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $h
         * @return string
         */
        public static function fe_tobytes(\ParagonIE_Sodium_Core_Curve25519_Fe $h)
        {
        }
        /**
         * Is a field element negative? (1 = yes, 0 = no. Used in calculations.)
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return int
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_isnegative(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Returns 0 if this field element results in all NUL bytes.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_isnonzero(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Multiply two field elements
         *
         * h = f * g
         *
         * @internal You should not use this directly from another application
         *
         * @security Is multiplication a source of timing leaks? If so, can we do
         *           anything to prevent that from happening?
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_mul(\ParagonIE_Sodium_Core_Curve25519_Fe $f, \ParagonIE_Sodium_Core_Curve25519_Fe $g)
        {
        }
        /**
         * Get the negative values for each piece of the field element.
         *
         * h = -f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @psalm-suppress MixedAssignment
         */
        public static function fe_neg(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Square a field element
         *
         * h = f * f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_sq(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Square and double a field element
         *
         * h = 2 * f * f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_sq2(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $Z
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_invert(\ParagonIE_Sodium_Core_Curve25519_Fe $Z)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @ref https://github.com/jedisct1/libsodium/blob/68564326e1e9dc57ef03746f85734232d20ca6fb/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c#L1054-L1106
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $z
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_pow22523(\ParagonIE_Sodium_Core_Curve25519_Fe $z)
        {
        }
        /**
         * Subtract two field elements.
         *
         * h = f - g
         *
         * Preconditions:
         * |f| bounded by 1.1*2^25,1.1*2^24,1.1*2^25,1.1*2^24,etc.
         * |g| bounded by 1.1*2^25,1.1*2^24,1.1*2^25,1.1*2^24,etc.
         *
         * Postconditions:
         * |h| bounded by 1.1*2^26,1.1*2^25,1.1*2^26,1.1*2^25,etc.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @psalm-suppress MixedOperand
         */
        public static function fe_sub(\ParagonIE_Sodium_Core_Curve25519_Fe $f, \ParagonIE_Sodium_Core_Curve25519_Fe $g)
        {
        }
        /**
         * Add two group elements.
         *
         * r = p + q
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Cached $q
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_add(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core_Curve25519_Ge_Cached $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @ref https://github.com/jedisct1/libsodium/blob/157c4a80c13b117608aeae12178b2d38825f9f8f/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c#L1185-L1215
         * @param string $a
         * @return array<int, mixed>
         * @throws SodiumException
         * @throws TypeError
         */
        public static function slide($a)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_frombytes_negate_vartime($s)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $R
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $q
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_madd(\ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $R, \ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $R
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $q
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_msub(\ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $R, \ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P2
         */
        public static function ge_p1p1_to_p2(\ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         */
        public static function ge_p1p1_to_p3(\ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P2
         */
        public static function ge_p2_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P2 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_p2_dbl(\ParagonIE_Sodium_Core_Curve25519_Ge_P2 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         */
        public static function ge_p3_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Cached
         */
        public static function ge_p3_to_cached(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P2
         */
        public static function ge_p3_to_p2(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $h
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p3_tobytes(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $h)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_p3_dbl(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
         */
        public static function ge_precomp_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $b
         * @param int $c
         * @return int
         */
        public static function equal($b, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int|string $char
         * @return int (1 = yes, 0 = no)
         * @throws SodiumException
         * @throws TypeError
         */
        public static function negative($char)
        {
        }
        /**
         * Conditional move
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $t
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $u
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
         */
        public static function cmov(\ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $t, \ParagonIE_Sodium_Core_Curve25519_Ge_Precomp $u, $b)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Cached $t
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Cached $u
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Cached
         */
        public static function ge_cmov_cached(\ParagonIE_Sodium_Core_Curve25519_Ge_Cached $t, \ParagonIE_Sodium_Core_Curve25519_Ge_Cached $u, $b)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Cached[] $cached
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Cached
         * @throws SodiumException
         */
        public static function ge_cmov8_cached(array $cached, $b)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $pos
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayOffset
         */
        public static function ge_select($pos = 0, $b = 0)
        {
        }
        /**
         * Subtract two group elements.
         *
         * r = p - q
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_Cached $q
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
         */
        public static function ge_sub(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core_Curve25519_Ge_Cached $q)
        {
        }
        /**
         * Convert a group element to a byte string.
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P2 $h
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_tobytes(\ParagonIE_Sodium_Core_Curve25519_Ge_P2 $h)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $A
         * @param string $b
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P2
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         */
        public static function ge_double_scalarmult_vartime($a, \ParagonIE_Sodium_Core_Curve25519_Ge_P3 $A, $b)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         */
        public static function ge_scalarmult($a, $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         */
        public static function ge_scalarmult_base($a)
        {
        }
        /**
         * Calculates (ab + c) mod l
         * where l = 2^252 + 27742317777372353535851937790883648493
         *
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param string $b
         * @param string $c
         * @return string
         * @throws TypeError
         */
        public static function sc_muladd($a, $b, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return string
         * @throws TypeError
         */
        public static function sc_reduce($s)
        {
        }
        /**
         * multiply by the order of the main subgroup l = 2^252+27742317777372353535851937790883648493
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $A
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         */
        public static function ge_mul_l(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $A)
        {
        }
        /**
         * @param string $a
         * @param string $b
         * @return string
         */
        public static function sc25519_mul($a, $b)
        {
        }
        /**
         * @param string $s
         * @return string
         */
        public static function sc25519_sq($s)
        {
        }
        /**
         * @param string $s
         * @param int $n
         * @param string $a
         * @return string
         */
        public static function sc25519_sqmul($s, $n, $a)
        {
        }
        /**
         * @param string $s
         * @return string
         */
        public static function sc25519_invert($s)
        {
        }
        /**
         * @param string $s
         * @return string
         */
        public static function clamp($s)
        {
        }
        /**
         * Ensure limbs are less than 28 bits long to prevent float promotion.
         *
         * This uses a constant-time conditional swap under the hood.
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_normalize(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class Curve25519 extends \ParagonIE_Sodium_Core_Curve25519
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Fe
     *
     * This represents a Field Element
     */
    class ParagonIE_Sodium_Core_Curve25519_Fe implements \ArrayAccess
    {
        /**
         * @var array<int, int>
         */
        protected $container = array();
        /**
         * @var int
         */
        protected $size = 10;
        /**
         * @internal You should not use this directly from another application
         *
         * @param array<int, int> $array
         * @param bool $save_indexes
         * @return self
         */
        public static function fromArray($array, $save_indexes = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int|null $offset
         * @param int $value
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return bool
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetExists($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return int
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return array
         */
        public function __debugInfo()
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519 {
    class Fe extends \ParagonIE_Sodium_Core_Curve25519_Fe
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Ge_Cached
     */
    class ParagonIE_Sodium_Core_Curve25519_Ge_Cached
    {
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $YplusX;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $YminusX;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $T2d;
        /**
         * ParagonIE_Sodium_Core_Curve25519_Ge_Cached constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $YplusX
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $YminusX
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $Z
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $T2d
         */
        public function __construct($YplusX = \null, $YminusX = \null, $Z = \null, $T2d = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519\Ge {
    class Cached extends \ParagonIE_Sodium_Core_Curve25519_Ge_Cached
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
     */
    class ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
    {
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $T;
        /**
         * ParagonIE_Sodium_Core_Curve25519_Ge_P1p1 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $z
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $t
         */
        public function __construct($x = \null, $y = \null, $z = \null, $t = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519\Ge {
    class P1p1 extends \ParagonIE_Sodium_Core_Curve25519_Ge_P1p1
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Ge_P2
     */
    class ParagonIE_Sodium_Core_Curve25519_Ge_P2
    {
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Z;
        /**
         * ParagonIE_Sodium_Core_Curve25519_Ge_P2 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $z
         */
        public function __construct($x = \null, $y = \null, $z = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519\Ge {
    class P2 extends \ParagonIE_Sodium_Core_Curve25519_Ge_P2
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Ge_P3
     */
    class ParagonIE_Sodium_Core_Curve25519_Ge_P3
    {
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $T;
        /**
         * ParagonIE_Sodium_Core_Curve25519_Ge_P3 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $z
         * @param ParagonIE_Sodium_Core_Curve25519_Fe|null $t
         */
        public function __construct($x = \null, $y = \null, $z = \null, $t = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519\Ge {
    class P3 extends \ParagonIE_Sodium_Core_Curve25519_Ge_P3
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
     */
    class ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
    {
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $yplusx;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $yminusx;
        /**
         * @var ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public $xy2d;
        /**
         * ParagonIE_Sodium_Core_Curve25519_Ge_Precomp constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $yplusx
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $yminusx
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $xy2d
         */
        public function __construct($yplusx = \null, $yminusx = \null, $xy2d = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Curve25519\Ge {
    class Precomp extends \ParagonIE_Sodium_Core_Curve25519_Ge_Precomp
    {
    }
}
namespace ParagonIE\Sodium\Core\Curve25519 {
    class H extends \ParagonIE_Sodium_Core_Curve25519_H
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Ed25519
     */
    abstract class ParagonIE_Sodium_Core_Ed25519 extends \ParagonIE_Sodium_Core_Curve25519
    {
        const KEYPAIR_BYTES = 96;
        const SEED_BYTES = 32;
        const SCALAR_BYTES = 32;
        /**
         * @internal You should not use this directly from another application
         *
         * @return string (96 bytes)
         * @throws Exception
         * @throws SodiumException
         * @throws TypeError
         */
        public static function keypair()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $pk
         * @param string $sk
         * @param string $seed
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function seed_keypair(&$pk, &$sk, $seed)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $keypair
         * @return string
         * @throws TypeError
         */
        public static function secretkey($keypair)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $keypair
         * @return string
         * @throws TypeError
         */
        public static function publickey($keypair)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function publickey_from_secretkey($sk)
        {
        }
        /**
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function pk_to_curve25519($pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sk_to_pk($sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign($message, $sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message A signed message
         * @param string $pk      Public key
         * @return string         Message (without signature)
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_open($message, $pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_detached($message, $sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sig
         * @param string $message
         * @param string $pk
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function verify_detached($sig, $message, $pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $S
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function check_S_lt_L($S)
        {
        }
        /**
         * @param string $R
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function small_order($R)
        {
        }
        /**
         * @param string $s
         * @return string
         * @throws SodiumException
         */
        public static function scalar_complement($s)
        {
        }
        /**
         * @return string
         * @throws SodiumException
         */
        public static function scalar_random()
        {
        }
        /**
         * @param string $s
         * @return string
         * @throws SodiumException
         */
        public static function scalar_negate($s)
        {
        }
        /**
         * @param string $a
         * @param string $b
         * @return string
         * @throws SodiumException
         */
        public static function scalar_add($a, $b)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @return string
         * @throws SodiumException
         */
        public static function scalar_sub($x, $y)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class Ed25519 extends \ParagonIE_Sodium_Core_Ed25519
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_HChaCha20
     */
    class ParagonIE_Sodium_Core_HChaCha20 extends \ParagonIE_Sodium_Core_ChaCha20
    {
        /**
         * @param string $in
         * @param string $key
         * @param string|null $c
         * @return string
         * @throws TypeError
         */
        public static function hChaCha20($in = '', $key = '', $c = \null)
        {
        }
        /**
         * @param array $ctx
         * @return string
         * @throws TypeError
         */
        protected static function hChaCha20Bytes(array $ctx)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class HChaCha20 extends \ParagonIE_Sodium_Core_HChaCha20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Salsa20
     */
    abstract class ParagonIE_Sodium_Core_Salsa20 extends \ParagonIE_Sodium_Core_Util
    {
        const ROUNDS = 20;
        /**
         * Calculate an salsa20 hash of a single block
         *
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $k
         * @param string|null $c
         * @return string
         * @throws TypeError
         */
        public static function core_salsa20($in, $k, $c = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20($len, $nonce, $key)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $m
         * @param string $n
         * @param int $ic
         * @param string $k
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20_xor_ic($m, $n, $ic, $k)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20_xor($message, $nonce, $key)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $u
         * @param int $c
         * @return int
         */
        public static function rotate($u, $c)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_HSalsa20
     */
    abstract class ParagonIE_Sodium_Core_HSalsa20 extends \ParagonIE_Sodium_Core_Salsa20
    {
        /**
         * Calculate an hsalsa20 hash of a single block
         *
         * HSalsa20 doesn't have a counter and will never be used for more than
         * one block (used to derive a subkey for xsalsa20).
         *
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $k
         * @param string|null $c
         * @return string
         * @throws TypeError
         */
        public static function hsalsa20($in, $k, $c = \null)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class HSalsa20 extends \ParagonIE_Sodium_Core_HSalsa20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Poly1305
     */
    abstract class ParagonIE_Sodium_Core_Poly1305 extends \ParagonIE_Sodium_Core_Util
    {
        const BLOCK_SIZE = 16;
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $m
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function onetimeauth($m, $key)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $mac
         * @param string $m
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function onetimeauth_verify($mac, $m, $key)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class Poly1305 extends \ParagonIE_Sodium_Core_Poly1305
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_Poly1305_State
     */
    class ParagonIE_Sodium_Core_Poly1305_State extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * @var array<int, int>
         */
        protected $buffer = array();
        /**
         * @var bool
         */
        protected $final = \false;
        /**
         * @var array<int, int>
         */
        public $h;
        /**
         * @var int
         */
        protected $leftover = 0;
        /**
         * @var int[]
         */
        public $r;
        /**
         * @var int[]
         */
        public $pad;
        /**
         * ParagonIE_Sodium_Core_Poly1305_State constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key
         * @throws InvalidArgumentException
         * @throws TypeError
         */
        public function __construct($key = '')
        {
        }
        /**
         * Zero internal buffer upon destruction
         */
        public function __destruct()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public function update($message = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param int $bytes
         * @return self
         * @throws TypeError
         */
        public function blocks($message, $bytes)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return string
         * @throws TypeError
         */
        public function finish()
        {
        }
    }
}
namespace ParagonIE\Sodium\Core\Poly1305 {
    class State extends \ParagonIE_Sodium_Core_Poly1305_State
    {
    }
}
namespace ParagonIE\Sodium\Core {
    class Salsa20 extends \ParagonIE_Sodium_Core_Salsa20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_SodiumCompat_Core_SipHash
     *
     * Only uses 32-bit arithmetic, while the original SipHash used 64-bit integers
     */
    class ParagonIE_Sodium_Core_SipHash extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * @internal You should not use this directly from another application
         *
         * @param int[] $v
         * @return int[]
         *
         */
        public static function sipRound(array $v)
        {
        }
        /**
         * Add two 32 bit integers representing a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int[] $a
         * @param int[] $b
         * @return array<int, mixed>
         */
        public static function add(array $a, array $b)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $int0
         * @param int $int1
         * @param int $c
         * @return array<int, mixed>
         */
        public static function rotl_64($int0, $int1, $c)
        {
        }
        /**
         * Implements Siphash-2-4 using only 32-bit numbers.
         *
         * When we split an int into two, the higher bits go to the lower index.
         * e.g. 0xDEADBEEFAB10C92D becomes [
         *     0 => 0xDEADBEEF,
         *     1 => 0xAB10C92D
         * ].
         *
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sipHash24($in, $key)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class SipHash extends \ParagonIE_Sodium_Core_SipHash
    {
    }
    class Util extends \ParagonIE_Sodium_Core_Util
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_X25519
     */
    abstract class ParagonIE_Sodium_Core_X25519 extends \ParagonIE_Sodium_Core_Curve25519
    {
        /**
         * Alters the objects passed to this method in place.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $g
         * @param int $b
         * @return void
         * @psalm-suppress MixedAssignment
         */
        public static function fe_cswap(\ParagonIE_Sodium_Core_Curve25519_Fe $f, \ParagonIE_Sodium_Core_Curve25519_Fe $g, $b = 0)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_mul121666(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * Inline comments preceded by # are from libsodium's ref10 code.
         *
         * @param string $n
         * @param string $p
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_scalarmult_curve25519_ref10($n, $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $edwardsY
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $edwardsZ
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function edwards_to_montgomery(\ParagonIE_Sodium_Core_Curve25519_Fe $edwardsY, \ParagonIE_Sodium_Core_Curve25519_Fe $edwardsZ)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $n
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_scalarmult_curve25519_ref10_base($n)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class X25519 extends \ParagonIE_Sodium_Core_X25519
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_XChaCha20
     */
    class ParagonIE_Sodium_Core_XChaCha20 extends \ParagonIE_Sodium_Core_HChaCha20
    {
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function stream($len = 64, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStream($len = 64, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function streamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStreamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class XChaCha20 extends \ParagonIE_Sodium_Core_XChaCha20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Core_XSalsa20
     */
    abstract class ParagonIE_Sodium_Core_XSalsa20 extends \ParagonIE_Sodium_Core_HSalsa20
    {
        /**
         * Expand a key and nonce into an xsalsa20 keystream.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function xsalsa20($len, $nonce, $key)
        {
        }
        /**
         * Encrypt a string with XSalsa20. Doesn't provide integrity.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function xsalsa20_xor($message, $nonce, $key)
        {
        }
    }
}
namespace ParagonIE\Sodium\Core {
    class Xsalsa20 extends \ParagonIE_Sodium_Core_XSalsa20
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_Crypto
     *
     * ATTENTION!
     *
     * If you are using this library, you should be using
     * ParagonIE_Sodium_Compat in your code, not this class.
     */
    abstract class ParagonIE_Sodium_Crypto
    {
        const aead_chacha20poly1305_KEYBYTES = 32;
        const aead_chacha20poly1305_NSECBYTES = 0;
        const aead_chacha20poly1305_NPUBBYTES = 8;
        const aead_chacha20poly1305_ABYTES = 16;
        const aead_chacha20poly1305_IETF_KEYBYTES = 32;
        const aead_chacha20poly1305_IETF_NSECBYTES = 0;
        const aead_chacha20poly1305_IETF_NPUBBYTES = 12;
        const aead_chacha20poly1305_IETF_ABYTES = 16;
        const aead_xchacha20poly1305_IETF_KEYBYTES = 32;
        const aead_xchacha20poly1305_IETF_NSECBYTES = 0;
        const aead_xchacha20poly1305_IETF_NPUBBYTES = 24;
        const aead_xchacha20poly1305_IETF_ABYTES = 16;
        const box_curve25519xsalsa20poly1305_SEEDBYTES = 32;
        const box_curve25519xsalsa20poly1305_PUBLICKEYBYTES = 32;
        const box_curve25519xsalsa20poly1305_SECRETKEYBYTES = 32;
        const box_curve25519xsalsa20poly1305_BEFORENMBYTES = 32;
        const box_curve25519xsalsa20poly1305_NONCEBYTES = 24;
        const box_curve25519xsalsa20poly1305_MACBYTES = 16;
        const box_curve25519xsalsa20poly1305_BOXZEROBYTES = 16;
        const box_curve25519xsalsa20poly1305_ZEROBYTES = 32;
        const onetimeauth_poly1305_BYTES = 16;
        const onetimeauth_poly1305_KEYBYTES = 32;
        const secretbox_xsalsa20poly1305_KEYBYTES = 32;
        const secretbox_xsalsa20poly1305_NONCEBYTES = 24;
        const secretbox_xsalsa20poly1305_MACBYTES = 16;
        const secretbox_xsalsa20poly1305_BOXZEROBYTES = 16;
        const secretbox_xsalsa20poly1305_ZEROBYTES = 32;
        const secretbox_xchacha20poly1305_KEYBYTES = 32;
        const secretbox_xchacha20poly1305_NONCEBYTES = 24;
        const secretbox_xchacha20poly1305_MACBYTES = 16;
        const secretbox_xchacha20poly1305_BOXZEROBYTES = 16;
        const secretbox_xchacha20poly1305_ZEROBYTES = 32;
        const stream_salsa20_KEYBYTES = 32;
        /**
         * AEAD Decryption with ChaCha20-Poly1305
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Decryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_ietf_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_ietf_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Decryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_xchacha20poly1305_ietf_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_xchacha20poly1305_ietf_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * HMAC-SHA-512-256 (a.k.a. the leftmost 256 bits of HMAC-SHA-512)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $key
         * @return string
         * @throws TypeError
         */
        public static function auth($message, $key)
        {
        }
        /**
         * HMAC-SHA-512-256 validation. Constant-time via hash_equals().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $mac
         * @param string $message
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function auth_verify($mac, $message, $key)
        {
        }
        /**
         * X25519 key exchange followed by XSalsa20Poly1305 symmetric encryption
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box($plaintext, $nonce, $keypair)
        {
        }
        /**
         * X25519-XSalsa20-Poly1305 with one ephemeral X25519 keypair.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $publicKey
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal($message, $publicKey)
        {
        }
        /**
         * Opens a message encrypted via box_seal().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal_open($message, $keypair)
        {
        }
        /**
         * Used by crypto_box() to get the crypto_secretbox() key.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sk
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_beforenm($sk, $pk)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @return string
         * @throws Exception
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_keypair()
        {
        }
        /**
         * @param string $seed
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seed_keypair($seed)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @param string $pKey
         * @return string
         * @throws TypeError
         */
        public static function box_keypair_from_secretkey_and_publickey($sKey, $pKey)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $keypair
         * @return string
         * @throws RangeException
         * @throws TypeError
         */
        public static function box_secretkey($keypair)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $keypair
         * @return string
         * @throws RangeException
         * @throws TypeError
         */
        public static function box_publickey($keypair)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_publickey_from_secretkey($sKey)
        {
        }
        /**
         * Decrypt a message encrypted with box().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_open($ciphertext, $nonce, $keypair)
        {
        }
        /**
         * Calculate a BLAKE2b hash.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string|null $key
         * @param int $outlen
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash($message, $key = '', $outlen = 32)
        {
        }
        /**
         * Finalize a BLAKE2b hashing context, returning the hash.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ctx
         * @param int $outlen
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_final($ctx, $outlen = 32)
        {
        }
        /**
         * Initialize a hashing context for BLAKE2b.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $key
         * @param int $outputLength
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_init($key = '', $outputLength = 32)
        {
        }
        /**
         * Initialize a hashing context for BLAKE2b.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $key
         * @param int $outputLength
         * @param string $salt
         * @param string $personal
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_init_salt_personal($key = '', $outputLength = 32, $salt = '', $personal = '')
        {
        }
        /**
         * Update a hashing context for BLAKE2b with $message
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ctx
         * @param string $message
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_update($ctx, $message)
        {
        }
        /**
         * Libsodium's crypto_kx().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $my_sk
         * @param string $their_pk
         * @param string $client_pk
         * @param string $server_pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function keyExchange($my_sk, $their_pk, $client_pk, $server_pk)
        {
        }
        /**
         * ECDH over Curve25519
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @param string $pKey
         * @return string
         *
         * @throws SodiumException
         * @throws TypeError
         */
        public static function scalarmult($sKey, $pKey)
        {
        }
        /**
         * ECDH over Curve25519, using the basepoint.
         * Used to get a secret key from a public key.
         *
         * @param string $secret
         * @return string
         *
         * @throws SodiumException
         * @throws TypeError
         */
        public static function scalarmult_base($secret)
        {
        }
        /**
         * This throws an Error if a zero public key was passed to the function.
         *
         * @param string $q
         * @return void
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function scalarmult_throw_if_zero($q)
        {
        }
        /**
         * XSalsa20-Poly1305 authenticated symmetric-key encryption.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypt a ciphertext generated via secretbox().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * XChaCha20-Poly1305 authenticated symmetric-key encryption.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_xchacha20poly1305($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypt a ciphertext generated via secretbox_xchacha20poly1305().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_xchacha20poly1305_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * @param string $key
         * @return array<int, string> Returns a state and a header.
         * @throws Exception
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_init_push($key)
        {
        }
        /**
         * @param string $key
         * @param string $header
         * @return string Returns a state.
         * @throws Exception
         */
        public static function secretstream_xchacha20poly1305_init_pull($key, $header)
        {
        }
        /**
         * @param string $state
         * @param string $msg
         * @param string $aad
         * @param int $tag
         * @return string
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_push(&$state, $msg, $aad = '', $tag = 0)
        {
        }
        /**
         * @param string $state
         * @param string $cipher
         * @param string $aad
         * @return bool|array{0: string, 1: int}
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_pull(&$state, $cipher, $aad = '')
        {
        }
        /**
         * @param string $state
         * @return void
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_rekey(&$state)
        {
        }
        /**
         * Detached Ed25519 signature.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_detached($message, $sk)
        {
        }
        /**
         * Attached Ed25519 signature. (Returns a signed message.)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign($message, $sk)
        {
        }
        /**
         * Opens a signed message. If valid, returns the message.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $signedMessage
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_open($signedMessage, $pk)
        {
        }
        /**
         * Verify a detached signature of a given message and public key.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $signature
         * @param string $message
         * @param string $pk
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_verify_detached($signature, $message, $pk)
        {
        }
    }
}
namespace ParagonIE\Sodium {
    class Crypto extends \ParagonIE_Sodium_Crypto
    {
    }
}
namespace {
    /**
     * Class ParagonIE_Sodium_File
     */
    class ParagonIE_Sodium_File extends \ParagonIE_Sodium_Core_Util
    {
        /* PHP's default buffer size is 8192 for fread()/fwrite(). */
        const BUFFER_SIZE = 8192;
        /**
         * Box a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_box(), but produces
         * the same result.
         *
         * @param string $inputFile  Absolute path to a file on the filesystem
         * @param string $outputFile Absolute path to a file on the filesystem
         * @param string $nonce      Number to be used only once
         * @param string $keyPair    ECDH secret key and ECDH public key concatenated
         *
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box($inputFile, $outputFile, $nonce, $keyPair)
        {
        }
        /**
         * Open a boxed file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_box_open(), but produces
         * the same result.
         *
         * Warning: Does not protect against TOCTOU attacks. You should
         * just load the file into memory and use crypto_box_open() if
         * you are worried about those.
         *
         * @param string $inputFile
         * @param string $outputFile
         * @param string $nonce
         * @param string $keypair
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_open($inputFile, $outputFile, $nonce, $keypair)
        {
        }
        /**
         * Seal a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_box_seal(), but produces
         * the same result.
         *
         * @param string $inputFile  Absolute path to a file on the filesystem
         * @param string $outputFile Absolute path to a file on the filesystem
         * @param string $publicKey  ECDH public key
         *
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal($inputFile, $outputFile, $publicKey)
        {
        }
        /**
         * Open a sealed file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_box_seal_open(), but produces
         * the same result.
         *
         * Warning: Does not protect against TOCTOU attacks. You should
         * just load the file into memory and use crypto_box_seal_open() if
         * you are worried about those.
         *
         * @param string $inputFile
         * @param string $outputFile
         * @param string $ecdhKeypair
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal_open($inputFile, $outputFile, $ecdhKeypair)
        {
        }
        /**
         * Calculate the BLAKE2b hash of a file.
         *
         * @param string      $filePath     Absolute path to a file on the filesystem
         * @param string|null $key          BLAKE2b key
         * @param int         $outputLength Length of hash output
         *
         * @return string                   BLAKE2b hash
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress FailedTypeResolution
         */
        public static function generichash($filePath, $key = '', $outputLength = 32)
        {
        }
        /**
         * Encrypt a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_secretbox(), but produces
         * the same result.
         *
         * @param string $inputFile  Absolute path to a file on the filesystem
         * @param string $outputFile Absolute path to a file on the filesystem
         * @param string $nonce      Number to be used only once
         * @param string $key        Encryption key
         *
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox($inputFile, $outputFile, $nonce, $key)
        {
        }
        /**
         * Seal a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_secretbox_open(), but produces
         * the same result.
         *
         * Warning: Does not protect against TOCTOU attacks. You should
         * just load the file into memory and use crypto_secretbox_open() if
         * you are worried about those.
         *
         * @param string $inputFile
         * @param string $outputFile
         * @param string $nonce
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_open($inputFile, $outputFile, $nonce, $key)
        {
        }
        /**
         * Sign a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_sign_detached(), but produces
         * the same result.
         *
         * @param string $filePath  Absolute path to a file on the filesystem
         * @param string $secretKey Secret signing key
         *
         * @return string           Ed25519 signature
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign($filePath, $secretKey)
        {
        }
        /**
         * Verify a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_sign_verify_detached(), but
         * produces the same result.
         *
         * @param string $sig       Ed25519 signature
         * @param string $filePath  Absolute path to a file on the filesystem
         * @param string $publicKey Signing public key
         *
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         * @throws Exception
         */
        public static function verify($sig, $filePath, $publicKey)
        {
        }
        /**
         * @param resource $ifp
         * @param resource $ofp
         * @param int      $mlen
         * @param string   $nonce
         * @param string   $boxKeypair
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function box_encrypt($ifp, $ofp, $mlen, $nonce, $boxKeypair)
        {
        }
        /**
         * @param resource $ifp
         * @param resource $ofp
         * @param int      $mlen
         * @param string   $nonce
         * @param string   $boxKeypair
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function box_decrypt($ifp, $ofp, $mlen, $nonce, $boxKeypair)
        {
        }
        /**
         * Encrypt a file
         *
         * @param resource $ifp
         * @param resource $ofp
         * @param int $mlen
         * @param string $nonce
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function secretbox_encrypt($ifp, $ofp, $mlen, $nonce, $key)
        {
        }
        /**
         * Decrypt a file
         *
         * @param resource $ifp
         * @param resource $ofp
         * @param int $mlen
         * @param string $nonce
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function secretbox_decrypt($ifp, $ofp, $mlen, $nonce, $key)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Poly1305_State $state
         * @param resource $ifp
         * @param string $tag
         * @param int $mlen
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function onetimeauth_verify(\ParagonIE_Sodium_Core_Poly1305_State $state, $ifp, $tag = '', $mlen = 0)
        {
        }
        /**
         * Update a hash context with the contents of a file, without
         * loading the entire file into memory.
         *
         * @param resource|HashContext $hash
         * @param resource $fp
         * @param int $size
         * @return resource|object Resource on PHP < 7.2, HashContext object on PHP >= 7.2
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress PossiblyInvalidArgument
         *                 PHP 7.2 changes from a resource to an object,
         *                 which causes Psalm to complain about an error.
         * @psalm-suppress TypeCoercion
         *                 Ditto.
         */
        public static function updateHashWithFile($hash, $fp, $size = 0)
        {
        }
        /**
         * Sign a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_sign_detached(), but produces
         * the same result. (32-bit)
         *
         * @param string $filePath  Absolute path to a file on the filesystem
         * @param string $secretKey Secret signing key
         *
         * @return string           Ed25519 signature
         * @throws SodiumException
         * @throws TypeError
         */
        private static function sign_core32($filePath, $secretKey)
        {
        }
        /**
         *
         * Verify a file (rather than a string). Uses less memory than
         * ParagonIE_Sodium_Compat::crypto_sign_verify_detached(), but
         * produces the same result. (32-bit)
         *
         * @param string $sig       Ed25519 signature
         * @param string $filePath  Absolute path to a file on the filesystem
         * @param string $publicKey Signing public key
         *
         * @return bool
         * @throws SodiumException
         * @throws Exception
         */
        public static function verify_core32($sig, $filePath, $publicKey)
        {
        }
        /**
         * Encrypt a file (32-bit)
         *
         * @param resource $ifp
         * @param resource $ofp
         * @param int $mlen
         * @param string $nonce
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function secretbox_encrypt_core32($ifp, $ofp, $mlen, $nonce, $key)
        {
        }
        /**
         * Decrypt a file (32-bit)
         *
         * @param resource $ifp
         * @param resource $ofp
         * @param int $mlen
         * @param string $nonce
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function secretbox_decrypt_core32($ifp, $ofp, $mlen, $nonce, $key)
        {
        }
        /**
         * One-time message authentication for 32-bit systems
         *
         * @param ParagonIE_Sodium_Core32_Poly1305_State $state
         * @param resource $ifp
         * @param string $tag
         * @param int $mlen
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function onetimeauth_verify_core32(\ParagonIE_Sodium_Core32_Poly1305_State $state, $ifp, $tag = '', $mlen = 0)
        {
        }
        /**
         * @param resource $resource
         * @return int
         * @throws SodiumException
         */
        private static function ftell($resource)
        {
        }
    }
}
namespace ParagonIE\Sodium {
    class File extends \ParagonIE_Sodium_File
    {
    }
}
namespace {
    class ParagonIE_Sodium_Core_AEGIS_State128L
    {
        /** @var array<int, string> $state */
        protected $state;
        public function __construct()
        {
        }
        /**
         * @internal Only use this for unit tests!
         * @return string[]
         */
        public function getState()
        {
        }
        /**
         * @param array $input
         * @return self
         * @throws SodiumException
         *
         * @internal Only for unit tests
         */
        public static function initForUnitTests(array $input)
        {
        }
        /**
         * @param string $key
         * @param string $nonce
         * @return self
         */
        public static function init($key, $nonce)
        {
        }
        /**
         * @param string $ai
         * @return self
         */
        public function absorb($ai)
        {
        }
        /**
         * @param string $ci
         * @return string
         * @throws SodiumException
         */
        public function dec($ci)
        {
        }
        /**
         * @param string $cn
         * @return string
         */
        public function decPartial($cn)
        {
        }
        /**
         * @param string $xi
         * @return string
         * @throws SodiumException
         */
        public function enc($xi)
        {
        }
        /**
         * @param int $ad_len_bits
         * @param int $msg_len_bits
         * @return string
         */
        public function finalize($ad_len_bits, $msg_len_bits)
        {
        }
        /**
         * @param string $m0
         * @param string $m1
         * @return self
         */
        public function update($m0, $m1)
        {
        }
    }
    class ParagonIE_Sodium_Core_AEGIS_State256
    {
        /** @var array<int, string> $state */
        protected $state;
        public function __construct()
        {
        }
        /**
         * @internal Only use this for unit tests!
         * @return string[]
         */
        public function getState()
        {
        }
        /**
         * @param array $input
         * @return self
         * @throws SodiumException
         *
         * @internal Only for unit tests
         */
        public static function initForUnitTests(array $input)
        {
        }
        /**
         * @param string $key
         * @param string $nonce
         * @return self
         */
        public static function init($key, $nonce)
        {
        }
        /**
         * @param string $ai
         * @return self
         * @throws SodiumException
         */
        public function absorb($ai)
        {
        }
        /**
         * @param string $ci
         * @return string
         * @throws SodiumException
         */
        public function dec($ci)
        {
        }
        /**
         * @param string $cn
         * @return string
         */
        public function decPartial($cn)
        {
        }
        /**
         * @param string $xi
         * @return string
         * @throws SodiumException
         */
        public function enc($xi)
        {
        }
        /**
         * @param int $ad_len_bits
         * @param int $msg_len_bits
         * @return string
         */
        public function finalize($ad_len_bits, $msg_len_bits)
        {
        }
        /**
         * @param string $m
         * @return self
         */
        public function update($m)
        {
        }
    }
    /**
     * Bitsliced implementation of the AES block cipher.
     *
     * Based on the implementation provided by BearSSL.
     *
     * @internal This should only be used by sodium_compat
     */
    class ParagonIE_Sodium_Core_AES extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * @var int[] AES round constants
         */
        private static $Rcon = array(0x1, 0x2, 0x4, 0x8, 0x10, 0x20, 0x40, 0x80, 0x1b, 0x36);
        /**
         * Mutates the values of $q!
         *
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @return void
         */
        public static function sbox(\ParagonIE_Sodium_Core_AES_Block $q)
        {
        }
        /**
         * Mutates the values of $q!
         *
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @return void
         */
        public static function invSbox(\ParagonIE_Sodium_Core_AES_Block $q)
        {
        }
        /**
         * This is some boilerplate code needed to invert an S-box. Rather than repeat the code
         * twice, I moved it to a protected method.
         *
         * Mutates $q
         *
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @return void
         */
        protected static function processInversion(\ParagonIE_Sodium_Core_AES_Block $q)
        {
        }
        /**
         * @param int $x
         * @return int
         */
        public static function subWord($x)
        {
        }
        /**
         * Calculate the key schedule from a given random key
         *
         * @param string $key
         * @return ParagonIE_Sodium_Core_AES_KeySchedule
         * @throws SodiumException
         */
        public static function keySchedule($key)
        {
        }
        /**
         * Mutates $q
         *
         * @param ParagonIE_Sodium_Core_AES_KeySchedule $skey
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @param int $offset
         * @return void
         */
        public static function addRoundKey(\ParagonIE_Sodium_Core_AES_Block $q, \ParagonIE_Sodium_Core_AES_KeySchedule $skey, $offset = 0)
        {
        }
        /**
         * This mainly exists for testing, as we need the round key features for AEGIS.
         *
         * @param string $message
         * @param string $key
         * @return string
         * @throws SodiumException
         */
        public static function decryptBlockECB($message, $key)
        {
        }
        /**
         * This mainly exists for testing, as we need the round key features for AEGIS.
         *
         * @param string $message
         * @param string $key
         * @return string
         * @throws SodiumException
         */
        public static function encryptBlockECB($message, $key)
        {
        }
        /**
         * Mutates $q
         *
         * @param ParagonIE_Sodium_Core_AES_Expanded $skey
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @return void
         */
        public static function bitsliceEncryptBlock(\ParagonIE_Sodium_Core_AES_Expanded $skey, \ParagonIE_Sodium_Core_AES_Block $q)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @return string
         */
        public static function aesRound($x, $y)
        {
        }
        /**
         * Process two AES blocks in one shot.
         *
         * @param string $b0  First AES block
         * @param string $rk0 First round key
         * @param string $b1  Second AES block
         * @param string $rk1 Second round key
         * @return string[]
         */
        public static function doubleRound($b0, $rk0, $b1, $rk1)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_AES_Expanded $skey
         * @param ParagonIE_Sodium_Core_AES_Block $q
         * @return void
         */
        public static function bitsliceDecryptBlock(\ParagonIE_Sodium_Core_AES_Expanded $skey, \ParagonIE_Sodium_Core_AES_Block $q)
        {
        }
    }
    class ParagonIE_Sodium_Core_AEGIS128L extends \ParagonIE_Sodium_Core_AES
    {
        /**
         * @param string $ct
         * @param string $tag
         * @param string $ad
         * @param string $key
         * @param string $nonce
         * @return string
         * @throws SodiumException
         */
        public static function decrypt($ct, $tag, $ad, $key, $nonce)
        {
        }
        /**
         * @param string $msg
         * @param string $ad
         * @param string $key
         * @param string $nonce
         * @return array
         *
         * @throws SodiumException
         */
        public static function encrypt($msg, $ad, $key, $nonce)
        {
        }
        /**
         * @param string $key
         * @param string $nonce
         * @return ParagonIE_Sodium_Core_AEGIS_State128L
         */
        public static function init($key, $nonce)
        {
        }
    }
    class ParagonIE_Sodium_Core_AEGIS256 extends \ParagonIE_Sodium_Core_AES
    {
        /**
         * @param string $ct
         * @param string $tag
         * @param string $ad
         * @param string $key
         * @param string $nonce
         * @return string
         * @throws SodiumException
         */
        public static function decrypt($ct, $tag, $ad, $key, $nonce)
        {
        }
        /**
         * @param string $msg
         * @param string $ad
         * @param string $key
         * @param string $nonce
         * @return array
         * @throws SodiumException
         */
        public static function encrypt($msg, $ad, $key, $nonce)
        {
        }
        /**
         * @param string $key
         * @param string $nonce
         * @return ParagonIE_Sodium_Core_AEGIS_State256
         */
        public static function init($key, $nonce)
        {
        }
    }
    /**
     * @internal This should only be used by sodium_compat
     */
    class ParagonIE_Sodium_Core_AES_Block extends \SplFixedArray
    {
        /**
         * @var array<int, int>
         */
        protected $values = array();
        /**
         * @var int
         */
        protected $size;
        /**
         * @param int $size
         */
        public function __construct($size = 8)
        {
        }
        /**
         * @return self
         */
        public static function init()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param array<int, int> $array
         * @param bool $save_indexes
         * @return self
         *
         * @psalm-suppress MethodSignatureMismatch
         */
        #[\ReturnTypeWillChange]
        public static function fromArray($array, $save_indexes = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int|null $offset
         * @param int $value
         * @return void
         *
         * @psalm-suppress MethodSignatureMismatch
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return bool
         *
         * @psalm-suppress MethodSignatureMismatch
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetExists($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return void
         *
         * @psalm-suppress MethodSignatureMismatch
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return int
         *
         * @psalm-suppress MethodSignatureMismatch
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return array
         */
        public function __debugInfo()
        {
        }
        /**
         * @param int $cl low bit mask
         * @param int $ch high bit mask
         * @param int $s shift
         * @param int $x index 1
         * @param int $y index 2
         * @return self
         */
        public function swapN($cl, $ch, $s, $x, $y)
        {
        }
        /**
         * @param int $x index 1
         * @param int $y index 2
         * @return self
         */
        public function swap2($x, $y)
        {
        }
        /**
         * @param int $x index 1
         * @param int $y index 2
         * @return self
         */
        public function swap4($x, $y)
        {
        }
        /**
         * @param int $x index 1
         * @param int $y index 2
         * @return self
         */
        public function swap8($x, $y)
        {
        }
        /**
         * @return self
         */
        public function orthogonalize()
        {
        }
        /**
         * @return self
         */
        public function shiftRows()
        {
        }
        /**
         * @param int $x
         * @return int
         */
        public static function rotr16($x)
        {
        }
        /**
         * @return self
         */
        public function mixColumns()
        {
        }
        /**
         * @return self
         */
        public function inverseMixColumns()
        {
        }
        /**
         * @return self
         */
        public function inverseShiftRows()
        {
        }
    }
    /**
     * @internal This should only be used by sodium_compat
     */
    class ParagonIE_Sodium_Core_AES_KeySchedule
    {
        /** @var array<int, int> $skey -- has size 120 */
        protected $skey;
        /** @var bool $expanded */
        protected $expanded = \false;
        /** @var int $numRounds */
        private $numRounds;
        /**
         * @param array $skey
         * @param int $numRounds
         */
        public function __construct(array $skey, $numRounds = 10)
        {
        }
        /**
         * Get a value at an arbitrary index. Mostly used for unit testing.
         *
         * @param int $i
         * @return int
         */
        public function get($i)
        {
        }
        /**
         * @return int
         */
        public function getNumRounds()
        {
        }
        /**
         * @param int $offset
         * @return ParagonIE_Sodium_Core_AES_Block
         */
        public function getRoundKey($offset)
        {
        }
        /**
         * Return an expanded key schedule
         *
         * @return ParagonIE_Sodium_Core_AES_Expanded
         */
        public function expand()
        {
        }
    }
    /**
     * @internal This should only be used by sodium_compat
     */
    class ParagonIE_Sodium_Core_AES_Expanded extends \ParagonIE_Sodium_Core_AES_KeySchedule
    {
        /** @var bool $expanded */
        protected $expanded = \true;
    }
    /**
     * Class ParagonIE_Sodium_Core_Base64
     *
     *  Copyright (c) 2016 - 2018 Paragon Initiative Enterprises.
     *  Copyright (c) 2014 Steve "Sc00bz" Thomas (steve at tobtu dot com)
     */
    class ParagonIE_Sodium_Core_Base64_Original
    {
        // COPY ParagonIE_Sodium_Core_Base64_Common STARTING HERE
        /**
         * Encode into Base64
         *
         * Base64 character set "[A-Z][a-z][0-9]+/"
         *
         * @param string $src
         * @return string
         * @throws TypeError
         */
        public static function encode($src)
        {
        }
        /**
         * Encode into Base64, no = padding
         *
         * Base64 character set "[A-Z][a-z][0-9]+/"
         *
         * @param string $src
         * @return string
         * @throws TypeError
         */
        public static function encodeUnpadded($src)
        {
        }
        /**
         * @param string $src
         * @param bool $pad   Include = padding?
         * @return string
         * @throws TypeError
         */
        protected static function doEncode($src, $pad = \true)
        {
        }
        /**
         * decode from base64 into binary
         *
         * Base64 character set "./[A-Z][a-z][0-9]"
         *
         * @param string $src
         * @param bool $strictPadding
         * @return string
         * @throws RangeException
         * @throws TypeError
         * @psalm-suppress RedundantCondition
         */
        public static function decode($src, $strictPadding = \false)
        {
        }
        // COPY ParagonIE_Sodium_Core_Base64_Common ENDING HERE
        /**
         * Uses bitwise operators instead of table-lookups to turn 6-bit integers
         * into 8-bit integers.
         *
         * Base64 character set:
         * [A-Z]      [a-z]      [0-9]      +     /
         * 0x41-0x5a, 0x61-0x7a, 0x30-0x39, 0x2b, 0x2f
         *
         * @param int $src
         * @return int
         */
        protected static function decode6Bits($src)
        {
        }
        /**
         * Uses bitwise operators instead of table-lookups to turn 8-bit integers
         * into 6-bit integers.
         *
         * @param int $src
         * @return string
         */
        protected static function encode6Bits($src)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_Base64UrlSafe
     *
     *  Copyright (c) 2016 - 2018 Paragon Initiative Enterprises.
     *  Copyright (c) 2014 Steve "Sc00bz" Thomas (steve at tobtu dot com)
     */
    class ParagonIE_Sodium_Core_Base64_UrlSafe
    {
        // COPY ParagonIE_Sodium_Core_Base64_Common STARTING HERE
        /**
         * Encode into Base64
         *
         * Base64 character set "[A-Z][a-z][0-9]+/"
         *
         * @param string $src
         * @return string
         * @throws TypeError
         */
        public static function encode($src)
        {
        }
        /**
         * Encode into Base64, no = padding
         *
         * Base64 character set "[A-Z][a-z][0-9]+/"
         *
         * @param string $src
         * @return string
         * @throws TypeError
         */
        public static function encodeUnpadded($src)
        {
        }
        /**
         * @param string $src
         * @param bool $pad   Include = padding?
         * @return string
         * @throws TypeError
         */
        protected static function doEncode($src, $pad = \true)
        {
        }
        /**
         * decode from base64 into binary
         *
         * Base64 character set "./[A-Z][a-z][0-9]"
         *
         * @param string $src
         * @param bool $strictPadding
         * @return string
         * @throws RangeException
         * @throws TypeError
         * @psalm-suppress RedundantCondition
         */
        public static function decode($src, $strictPadding = \false)
        {
        }
        // COPY ParagonIE_Sodium_Core_Base64_Common ENDING HERE
        /**
         * Uses bitwise operators instead of table-lookups to turn 6-bit integers
         * into 8-bit integers.
         *
         * Base64 character set:
         * [A-Z]      [a-z]      [0-9]      +     /
         * 0x41-0x5a, 0x61-0x7a, 0x30-0x39, 0x2b, 0x2f
         *
         * @param int $src
         * @return int
         */
        protected static function decode6Bits($src)
        {
        }
        /**
         * Uses bitwise operators instead of table-lookups to turn 8-bit integers
         * into 6-bit integers.
         *
         * @param int $src
         * @return string
         */
        protected static function encode6Bits($src)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_Ristretto255
     */
    class ParagonIE_Sodium_Core_Ristretto255 extends \ParagonIE_Sodium_Core_Ed25519
    {
        const crypto_core_ristretto255_HASHBYTES = 64;
        const HASH_SC_L = 48;
        const CORE_H2C_SHA256 = 1;
        const CORE_H2C_SHA512 = 2;
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @param int $b
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         */
        public static function fe_cneg(\ParagonIE_Sodium_Core_Curve25519_Fe $f, $b)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core_Curve25519_Fe
         * @throws SodiumException
         */
        public static function fe_abs(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * Returns 0 if this field element results in all NUL bytes.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $f
         * @return int
         * @throws SodiumException
         */
        public static function fe_iszero(\ParagonIE_Sodium_Core_Curve25519_Fe $f)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $u
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $v
         * @return array{x: ParagonIE_Sodium_Core_Curve25519_Fe, nonsquare: int}
         *
         * @throws SodiumException
         */
        public static function ristretto255_sqrt_ratio_m1(\ParagonIE_Sodium_Core_Curve25519_Fe $u, \ParagonIE_Sodium_Core_Curve25519_Fe $v)
        {
        }
        /**
         * @param string $s
         * @return int
         * @throws SodiumException
         */
        public static function ristretto255_point_is_canonical($s)
        {
        }
        /**
         * @param string $s
         * @param bool $skipCanonicalCheck
         * @return array{h: ParagonIE_Sodium_Core_Curve25519_Ge_P3, res: int}
         * @throws SodiumException
         */
        public static function ristretto255_frombytes($s, $skipCanonicalCheck = \false)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Ge_P3 $h
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_p3_tobytes(\ParagonIE_Sodium_Core_Curve25519_Ge_P3 $h)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core_Curve25519_Fe $t
         * @return ParagonIE_Sodium_Core_Curve25519_Ge_P3
         *
         * @throws SodiumException
         */
        public static function ristretto255_elligator(\ParagonIE_Sodium_Core_Curve25519_Fe $t)
        {
        }
        /**
         * @param string $h
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_from_hash($h)
        {
        }
        /**
         * @param string $p
         * @return int
         * @throws SodiumException
         */
        public static function is_valid_point($p)
        {
        }
        /**
         * @param string $p
         * @param string $q
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_add($p, $q)
        {
        }
        /**
         * @param string $p
         * @param string $q
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_sub($p, $q)
        {
        }
        /**
         * @param int $hLen
         * @param ?string $ctx
         * @param string $msg
         * @return string
         * @throws SodiumException
         * @psalm-suppress PossiblyInvalidArgument hash API
         */
        protected static function h2c_string_to_hash_sha256($hLen, $ctx, $msg)
        {
        }
        /**
         * @param int $hLen
         * @param ?string $ctx
         * @param string $msg
         * @return string
         * @throws SodiumException
         * @psalm-suppress PossiblyInvalidArgument hash API
         */
        protected static function h2c_string_to_hash_sha512($hLen, $ctx, $msg)
        {
        }
        /**
         * @param int $hLen
         * @param ?string $ctx
         * @param string $msg
         * @param int $hash_alg
         * @return string
         * @throws SodiumException
         */
        public static function h2c_string_to_hash($hLen, $ctx, $msg, $hash_alg)
        {
        }
        /**
         * @param ?string $ctx
         * @param string $msg
         * @param int $hash_alg
         * @return string
         * @throws SodiumException
         */
        protected static function _string_to_element($ctx, $msg, $hash_alg)
        {
        }
        /**
         * @return string
         * @throws SodiumException
         * @throws Exception
         */
        public static function ristretto255_random()
        {
        }
        /**
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_random()
        {
        }
        /**
         * @param string $s
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_complement($s)
        {
        }
        /**
         * @param string $s
         * @return string
         */
        public static function ristretto255_scalar_invert($s)
        {
        }
        /**
         * @param string $s
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_negate($s)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @return string
         */
        public static function ristretto255_scalar_add($x, $y)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @return string
         */
        public static function ristretto255_scalar_sub($x, $y)
        {
        }
        /**
         * @param string $x
         * @param string $y
         * @return string
         */
        public static function ristretto255_scalar_mul($x, $y)
        {
        }
        /**
         * @param string $ctx
         * @param string $msg
         * @param int $hash_alg
         * @return string
         * @throws SodiumException
         */
        public static function ristretto255_scalar_from_string($ctx, $msg, $hash_alg)
        {
        }
        /**
         * @param string $s
         * @return string
         */
        public static function ristretto255_scalar_reduce($s)
        {
        }
        /**
         * @param string $n
         * @param string $p
         * @return string
         * @throws SodiumException
         */
        public static function scalarmult_ristretto255($n, $p)
        {
        }
        /**
         * @param string $n
         * @return string
         * @throws SodiumException
         */
        public static function scalarmult_ristretto255_base($n)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_SecretStream_State
     */
    class ParagonIE_Sodium_Core_SecretStream_State
    {
        /** @var string $key */
        protected $key;
        /** @var int $counter */
        protected $counter;
        /** @var string $nonce */
        protected $nonce;
        /** @var string $_pad */
        protected $_pad;
        /**
         * ParagonIE_Sodium_Core_SecretStream_State constructor.
         * @param string $key
         * @param string|null $nonce
         */
        public function __construct($key, $nonce = \null)
        {
        }
        /**
         * @return self
         */
        public function counterReset()
        {
        }
        /**
         * @return string
         */
        public function getKey()
        {
        }
        /**
         * @return string
         */
        public function getCounter()
        {
        }
        /**
         * @return string
         */
        public function getNonce()
        {
        }
        /**
         * @return string
         */
        public function getCombinedNonce()
        {
        }
        /**
         * @return self
         */
        public function incrementCounter()
        {
        }
        /**
         * @return bool
         */
        public function needsRekey()
        {
        }
        /**
         * @param string $newKeyAndNonce
         * @return self
         */
        public function rekey($newKeyAndNonce)
        {
        }
        /**
         * @param string $str
         * @return self
         */
        public function xorNonce($str)
        {
        }
        /**
         * @param string $string
         * @return self
         */
        public static function fromString($string)
        {
        }
        /**
         * @return string
         */
        public function toString()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_BLAKE2b
     *
     * Based on the work of Devi Mandiri in devi/salt.
     */
    abstract class ParagonIE_Sodium_Core32_BLAKE2b extends \ParagonIE_Sodium_Core_Util
    {
        /**
         * @var SplFixedArray
         */
        public static $iv;
        /**
         * @var array<int, array<int, int>>
         */
        public static $sigma = array(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), array(14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3), array(11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4), array(7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8), array(9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13), array(2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9), array(12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11), array(13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10), array(6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5), array(10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0), array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), array(14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3));
        const BLOCKBYTES = 128;
        const OUTBYTES = 64;
        const KEYBYTES = 64;
        /**
         * Turn two 32-bit integers into a fixed array representing a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $high
         * @param int $low
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public static function new64($high, $low)
        {
        }
        /**
         * Convert an arbitrary number into an SplFixedArray of two 32-bit integers
         * that represents a 64-bit integer.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $num
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function to64($num)
        {
        }
        /**
         * Adds two 64-bit integers together, returning their sum as a SplFixedArray
         * containing two 32-bit integers (representing a 64-bit integer).
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Int64 $x
         * @param ParagonIE_Sodium_Core32_Int64 $y
         * @return ParagonIE_Sodium_Core32_Int64
         */
        protected static function add64($x, $y)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Int64 $x
         * @param ParagonIE_Sodium_Core32_Int64 $y
         * @param ParagonIE_Sodium_Core32_Int64 $z
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public static function add364($x, $y, $z)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Int64 $x
         * @param ParagonIE_Sodium_Core32_Int64 $y
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws TypeError
         */
        public static function xor64(\ParagonIE_Sodium_Core32_Int64 $x, \ParagonIE_Sodium_Core32_Int64 $y)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Int64 $x
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public static function rotr64(\ParagonIE_Sodium_Core32_Int64 $x, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param int $i
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public static function load64($x, $i)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $x
         * @param int $i
         * @param ParagonIE_Sodium_Core32_Int64 $u
         * @return void
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         */
        public static function store64(\SplFixedArray $x, $i, \ParagonIE_Sodium_Core32_Int64 $u)
        {
        }
        /**
         * This just sets the $iv static variable.
         *
         * @internal You should not use this directly from another application
         *
         * @return void
         * @throws SodiumException
         * @throws TypeError
         */
        public static function pseudoConstructor()
        {
        }
        /**
         * Returns a fresh BLAKE2 context.
         *
         * @internal You should not use this directly from another application
         *
         * @return SplFixedArray
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function context()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $buf
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedAssignment
         */
        protected static function compress(\SplFixedArray $ctx, \SplFixedArray $buf)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $r
         * @param int $i
         * @param int $a
         * @param int $b
         * @param int $c
         * @param int $d
         * @param SplFixedArray $v
         * @param SplFixedArray $m
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayOffset
         */
        public static function G($r, $i, $a, $b, $c, $d, \SplFixedArray $v, \SplFixedArray $m)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param int $inc
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         */
        public static function increment_counter($ctx, $inc)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $p
         * @param int $plen
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedMethodCall
         * @psalm-suppress MixedOperand
         */
        public static function update(\SplFixedArray $ctx, \SplFixedArray $p, $plen)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @param SplFixedArray $out
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedMethodCall
         * @psalm-suppress MixedOperand
         */
        public static function finish(\SplFixedArray $ctx, \SplFixedArray $out)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray|null $key
         * @param int $outlen
         * @param SplFixedArray|null $salt
         * @param SplFixedArray|null $personal
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function init($key = \null, $outlen = 64, $salt = \null, $personal = \null)
        {
        }
        /**
         * Convert a string into an SplFixedArray of integers
         *
         * @internal You should not use this directly from another application
         *
         * @param string $str
         * @return SplFixedArray
         * @psalm-suppress MixedArgumentTypeCoercion
         */
        public static function stringToSplFixedArray($str = '')
        {
        }
        /**
         * Convert an SplFixedArray of integers into a string
         *
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $a
         * @return string
         */
        public static function SplFixedArrayToString(\SplFixedArray $a)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param SplFixedArray $ctx
         * @return string
         * @throws TypeError
         * @psalm-suppress MixedArgument
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function contextToString(\SplFixedArray $ctx)
        {
        }
        /**
         * Creates an SplFixedArray containing other SplFixedArray elements, from
         * a string (compatible with \Sodium\crypto_generichash_{init, update, final})
         *
         * @internal You should not use this directly from another application
         *
         * @param string $string
         * @return SplFixedArray
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayAssignment
         */
        public static function stringToContext($string)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_Util
     */
    abstract class ParagonIE_Sodium_Core32_Util extends \ParagonIE_Sodium_Core_Util
    {
    }
    /**
     * Class ParagonIE_Sodium_Core32_ChaCha20
     */
    class ParagonIE_Sodium_Core32_ChaCha20 extends \ParagonIE_Sodium_Core32_Util
    {
        /**
         * The ChaCha20 quarter round function. Works on four 32-bit integers.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Int32 $a
         * @param ParagonIE_Sodium_Core32_Int32 $b
         * @param ParagonIE_Sodium_Core32_Int32 $c
         * @param ParagonIE_Sodium_Core32_Int32 $d
         * @return array<int, ParagonIE_Sodium_Core32_Int32>
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function quarterRound(\ParagonIE_Sodium_Core32_Int32 $a, \ParagonIE_Sodium_Core32_Int32 $b, \ParagonIE_Sodium_Core32_Int32 $c, \ParagonIE_Sodium_Core32_Int32 $d)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_ChaCha20_Ctx $ctx
         * @param string $message
         *
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function encryptBytes(\ParagonIE_Sodium_Core32_ChaCha20_Ctx $ctx, $message = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function stream($len = 64, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStream($len, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStreamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function streamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_ChaCha20_Ctx
     */
    class ParagonIE_Sodium_Core32_ChaCha20_Ctx extends \ParagonIE_Sodium_Core32_Util implements \ArrayAccess
    {
        /**
         * @var SplFixedArray internally, <int, ParagonIE_Sodium_Core32_Int32>
         */
        protected $container;
        /**
         * ParagonIE_Sodium_Core_ChaCha20_Ctx constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key     ChaCha20 key.
         * @param string $iv      Initialization Vector (a.k.a. nonce).
         * @param string $counter The initial counter value.
         *                        Defaults to 8 0x00 bytes.
         * @throws InvalidArgumentException
         * @throws SodiumException
         * @throws TypeError
         */
        public function __construct($key = '', $iv = '', $counter = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @param int|ParagonIE_Sodium_Core32_Int32 $value
         * @return void
         */
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return bool
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetExists($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $offset
         * @return mixed|null
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_ChaCha20_IetfCtx
     */
    class ParagonIE_Sodium_Core32_ChaCha20_IetfCtx extends \ParagonIE_Sodium_Core32_ChaCha20_Ctx
    {
        /**
         * ParagonIE_Sodium_Core_ChaCha20_IetfCtx constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key     ChaCha20 key.
         * @param string $iv      Initialization Vector (a.k.a. nonce).
         * @param string $counter The initial counter value.
         *                        Defaults to 4 0x00 bytes.
         * @throws InvalidArgumentException
         * @throws SodiumException
         * @throws TypeError
         */
        public function __construct($key = '', $iv = '', $counter = '')
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_H
     *
     * This just contains the constants in the ref10/base.h file
     */
    class ParagonIE_Sodium_Core32_Curve25519_H extends \ParagonIE_Sodium_Core32_Util
    {
        /**
         * See: libsodium's crypto_core/curve25519/ref10/base.h
         *
         * @var array<int, array<int, array<int, array<int, int>>>> Basically, int[32][8][3][10]
         */
        protected static $base = array(array(array(array(25967493, -14356035, 29566456, 3660896, -12694345, 4014787, 27544626, -11754271, -6079156, 2047605), array(-12545711, 934262, -2722910, 3049990, -727428, 9406986, 12720692, 5043384, 19500929, -15469378), array(-8738181, 4489570, 9688441, -14785194, 10184609, -12363380, 29287919, 11864899, -24514362, -4438546)), array(array(-12815894, -12976347, -21581243, 11784320, -25355658, -2750717, -11717903, -3814571, -358445, -10211303), array(-21703237, 6903825, 27185491, 6451973, -29577724, -9554005, -15616551, 11189268, -26829678, -5319081), array(26966642, 11152617, 32442495, 15396054, 14353839, -12752335, -3128826, -9541118, -15472047, -4166697)), array(array(15636291, -9688557, 24204773, -7912398, 616977, -16685262, 27787600, -14772189, 28944400, -1550024), array(16568933, 4717097, -11556148, -1102322, 15682896, -11807043, 16354577, -11775962, 7689662, 11199574), array(30464156, -5976125, -11779434, -15670865, 23220365, 15915852, 7512774, 10017326, -17749093, -9920357)), array(array(-17036878, 13921892, 10945806, -6033431, 27105052, -16084379, -28926210, 15006023, 3284568, -6276540), array(23599295, -8306047, -11193664, -7687416, 13236774, 10506355, 7464579, 9656445, 13059162, 10374397), array(7798556, 16710257, 3033922, 2874086, 28997861, 2835604, 32406664, -3839045, -641708, -101325)), array(array(10861363, 11473154, 27284546, 1981175, -30064349, 12577861, 32867885, 14515107, -15438304, 10819380), array(4708026, 6336745, 20377586, 9066809, -11272109, 6594696, -25653668, 12483688, -12668491, 5581306), array(19563160, 16186464, -29386857, 4097519, 10237984, -4348115, 28542350, 13850243, -23678021, -15815942)), array(array(-15371964, -12862754, 32573250, 4720197, -26436522, 5875511, -19188627, -15224819, -9818940, -12085777), array(-8549212, 109983, 15149363, 2178705, 22900618, 4543417, 3044240, -15689887, 1762328, 14866737), array(-18199695, -15951423, -10473290, 1707278, -17185920, 3916101, -28236412, 3959421, 27914454, 4383652)), array(array(5153746, 9909285, 1723747, -2777874, 30523605, 5516873, 19480852, 5230134, -23952439, -15175766), array(-30269007, -3463509, 7665486, 10083793, 28475525, 1649722, 20654025, 16520125, 30598449, 7715701), array(28881845, 14381568, 9657904, 3680757, -20181635, 7843316, -31400660, 1370708, 29794553, -1409300)), array(array(14499471, -2729599, -33191113, -4254652, 28494862, 14271267, 30290735, 10876454, -33154098, 2381726), array(-7195431, -2655363, -14730155, 462251, -27724326, 3941372, -6236617, 3696005, -32300832, 15351955), array(27431194, 8222322, 16448760, -3907995, -18707002, 11938355, -32961401, -2970515, 29551813, 10109425))), array(array(array(-13657040, -13155431, -31283750, 11777098, 21447386, 6519384, -2378284, -1627556, 10092783, -4764171), array(27939166, 14210322, 4677035, 16277044, -22964462, -12398139, -32508754, 12005538, -17810127, 12803510), array(17228999, -15661624, -1233527, 300140, -1224870, -11714777, 30364213, -9038194, 18016357, 4397660)), array(array(-10958843, -7690207, 4776341, -14954238, 27850028, -15602212, -26619106, 14544525, -17477504, 982639), array(29253598, 15796703, -2863982, -9908884, 10057023, 3163536, 7332899, -4120128, -21047696, 9934963), array(5793303, 16271923, -24131614, -10116404, 29188560, 1206517, -14747930, 4559895, -30123922, -10897950)), array(array(-27643952, -11493006, 16282657, -11036493, 28414021, -15012264, 24191034, 4541697, -13338309, 5500568), array(12650548, -1497113, 9052871, 11355358, -17680037, -8400164, -17430592, 12264343, 10874051, 13524335), array(25556948, -3045990, 714651, 2510400, 23394682, -10415330, 33119038, 5080568, -22528059, 5376628)), array(array(-26088264, -4011052, -17013699, -3537628, -6726793, 1920897, -22321305, -9447443, 4535768, 1569007), array(-2255422, 14606630, -21692440, -8039818, 28430649, 8775819, -30494562, 3044290, 31848280, 12543772), array(-22028579, 2943893, -31857513, 6777306, 13784462, -4292203, -27377195, -2062731, 7718482, 14474653)), array(array(2385315, 2454213, -22631320, 46603, -4437935, -15680415, 656965, -7236665, 24316168, -5253567), array(13741529, 10911568, -33233417, -8603737, -20177830, -1033297, 33040651, -13424532, -20729456, 8321686), array(21060490, -2212744, 15712757, -4336099, 1639040, 10656336, 23845965, -11874838, -9984458, 608372)), array(array(-13672732, -15087586, -10889693, -7557059, -6036909, 11305547, 1123968, -6780577, 27229399, 23887), array(-23244140, -294205, -11744728, 14712571, -29465699, -2029617, 12797024, -6440308, -1633405, 16678954), array(-29500620, 4770662, -16054387, 14001338, 7830047, 9564805, -1508144, -4795045, -17169265, 4904953)), array(array(24059557, 14617003, 19037157, -15039908, 19766093, -14906429, 5169211, 16191880, 2128236, -4326833), array(-16981152, 4124966, -8540610, -10653797, 30336522, -14105247, -29806336, 916033, -6882542, -2986532), array(-22630907, 12419372, -7134229, -7473371, -16478904, 16739175, 285431, 2763829, 15736322, 4143876)), array(array(2379352, 11839345, -4110402, -5988665, 11274298, 794957, 212801, -14594663, 23527084, -16458268), array(33431127, -11130478, -17838966, -15626900, 8909499, 8376530, -32625340, 4087881, -15188911, -14416214), array(1767683, 7197987, -13205226, -2022635, -13091350, 448826, 5799055, 4357868, -4774191, -16323038))), array(array(array(6721966, 13833823, -23523388, -1551314, 26354293, -11863321, 23365147, -3949732, 7390890, 2759800), array(4409041, 2052381, 23373853, 10530217, 7676779, -12885954, 21302353, -4264057, 1244380, -12919645), array(-4421239, 7169619, 4982368, -2957590, 30256825, -2777540, 14086413, 9208236, 15886429, 16489664)), array(array(1996075, 10375649, 14346367, 13311202, -6874135, -16438411, -13693198, 398369, -30606455, -712933), array(-25307465, 9795880, -2777414, 14878809, -33531835, 14780363, 13348553, 12076947, -30836462, 5113182), array(-17770784, 11797796, 31950843, 13929123, -25888302, 12288344, -30341101, -7336386, 13847711, 5387222)), array(array(-18582163, -3416217, 17824843, -2340966, 22744343, -10442611, 8763061, 3617786, -19600662, 10370991), array(20246567, -14369378, 22358229, -543712, 18507283, -10413996, 14554437, -8746092, 32232924, 16763880), array(9648505, 10094563, 26416693, 14745928, -30374318, -6472621, 11094161, 15689506, 3140038, -16510092)), array(array(-16160072, 5472695, 31895588, 4744994, 8823515, 10365685, -27224800, 9448613, -28774454, 366295), array(19153450, 11523972, -11096490, -6503142, -24647631, 5420647, 28344573, 8041113, 719605, 11671788), array(8678025, 2694440, -6808014, 2517372, 4964326, 11152271, -15432916, -15266516, 27000813, -10195553)), array(array(-15157904, 7134312, 8639287, -2814877, -7235688, 10421742, 564065, 5336097, 6750977, -14521026), array(11836410, -3979488, 26297894, 16080799, 23455045, 15735944, 1695823, -8819122, 8169720, 16220347), array(-18115838, 8653647, 17578566, -6092619, -8025777, -16012763, -11144307, -2627664, -5990708, -14166033)), array(array(-23308498, -10968312, 15213228, -10081214, -30853605, -11050004, 27884329, 2847284, 2655861, 1738395), array(-27537433, -14253021, -25336301, -8002780, -9370762, 8129821, 21651608, -3239336, -19087449, -11005278), array(1533110, 3437855, 23735889, 459276, 29970501, 11335377, 26030092, 5821408, 10478196, 8544890)), array(array(32173121, -16129311, 24896207, 3921497, 22579056, -3410854, 19270449, 12217473, 17789017, -3395995), array(-30552961, -2228401, -15578829, -10147201, 13243889, 517024, 15479401, -3853233, 30460520, 1052596), array(-11614875, 13323618, 32618793, 8175907, -15230173, 12596687, 27491595, -4612359, 3179268, -9478891)), array(array(31947069, -14366651, -4640583, -15339921, -15125977, -6039709, -14756777, -16411740, 19072640, -9511060), array(11685058, 11822410, 3158003, -13952594, 33402194, -4165066, 5977896, -5215017, 473099, 5040608), array(-20290863, 8198642, -27410132, 11602123, 1290375, -2799760, 28326862, 1721092, -19558642, -3131606))), array(array(array(7881532, 10687937, 7578723, 7738378, -18951012, -2553952, 21820786, 8076149, -27868496, 11538389), array(-19935666, 3899861, 18283497, -6801568, -15728660, -11249211, 8754525, 7446702, -5676054, 5797016), array(-11295600, -3793569, -15782110, -7964573, 12708869, -8456199, 2014099, -9050574, -2369172, -5877341)), array(array(-22472376, -11568741, -27682020, 1146375, 18956691, 16640559, 1192730, -3714199, 15123619, 10811505), array(14352098, -3419715, -18942044, 10822655, 32750596, 4699007, -70363, 15776356, -28886779, -11974553), array(-28241164, -8072475, -4978962, -5315317, 29416931, 1847569, -20654173, -16484855, 4714547, -9600655)), array(array(15200332, 8368572, 19679101, 15970074, -31872674, 1959451, 24611599, -4543832, -11745876, 12340220), array(12876937, -10480056, 33134381, 6590940, -6307776, 14872440, 9613953, 8241152, 15370987, 9608631), array(-4143277, -12014408, 8446281, -391603, 4407738, 13629032, -7724868, 15866074, -28210621, -8814099)), array(array(26660628, -15677655, 8393734, 358047, -7401291, 992988, -23904233, 858697, 20571223, 8420556), array(14620715, 13067227, -15447274, 8264467, 14106269, 15080814, 33531827, 12516406, -21574435, -12476749), array(236881, 10476226, 57258, -14677024, 6472998, 2466984, 17258519, 7256740, 8791136, 15069930)), array(array(1276410, -9371918, 22949635, -16322807, -23493039, -5702186, 14711875, 4874229, -30663140, -2331391), array(5855666, 4990204, -13711848, 7294284, -7804282, 1924647, -1423175, -7912378, -33069337, 9234253), array(20590503, -9018988, 31529744, -7352666, -2706834, 10650548, 31559055, -11609587, 18979186, 13396066)), array(array(24474287, 4968103, 22267082, 4407354, 24063882, -8325180, -18816887, 13594782, 33514650, 7021958), array(-11566906, -6565505, -21365085, 15928892, -26158305, 4315421, -25948728, -3916677, -21480480, 12868082), array(-28635013, 13504661, 19988037, -2132761, 21078225, 6443208, -21446107, 2244500, -12455797, -8089383)), array(array(-30595528, 13793479, -5852820, 319136, -25723172, -6263899, 33086546, 8957937, -15233648, 5540521), array(-11630176, -11503902, -8119500, -7643073, 2620056, 1022908, -23710744, -1568984, -16128528, -14962807), array(23152971, 775386, 27395463, 14006635, -9701118, 4649512, 1689819, 892185, -11513277, -15205948)), array(array(9770129, 9586738, 26496094, 4324120, 1556511, -3550024, 27453819, 4763127, -19179614, 5867134), array(-32765025, 1927590, 31726409, -4753295, 23962434, -16019500, 27846559, 5931263, -29749703, -16108455), array(27461885, -2977536, 22380810, 1815854, -23033753, -3031938, 7283490, -15148073, -19526700, 7734629))), array(array(array(-8010264, -9590817, -11120403, 6196038, 29344158, -13430885, 7585295, -3176626, 18549497, 15302069), array(-32658337, -6171222, -7672793, -11051681, 6258878, 13504381, 10458790, -6418461, -8872242, 8424746), array(24687205, 8613276, -30667046, -3233545, 1863892, -1830544, 19206234, 7134917, -11284482, -828919)), array(array(11334899, -9218022, 8025293, 12707519, 17523892, -10476071, 10243738, -14685461, -5066034, 16498837), array(8911542, 6887158, -9584260, -6958590, 11145641, -9543680, 17303925, -14124238, 6536641, 10543906), array(-28946384, 15479763, -17466835, 568876, -1497683, 11223454, -2669190, -16625574, -27235709, 8876771)), array(array(-25742899, -12566864, -15649966, -846607, -33026686, -796288, -33481822, 15824474, -604426, -9039817), array(10330056, 70051, 7957388, -9002667, 9764902, 15609756, 27698697, -4890037, 1657394, 3084098), array(10477963, -7470260, 12119566, -13250805, 29016247, -5365589, 31280319, 14396151, -30233575, 15272409)), array(array(-12288309, 3169463, 28813183, 16658753, 25116432, -5630466, -25173957, -12636138, -25014757, 1950504), array(-26180358, 9489187, 11053416, -14746161, -31053720, 5825630, -8384306, -8767532, 15341279, 8373727), array(28685821, 7759505, -14378516, -12002860, -31971820, 4079242, 298136, -10232602, -2878207, 15190420)), array(array(-32932876, 13806336, -14337485, -15794431, -24004620, 10940928, 8669718, 2742393, -26033313, -6875003), array(-1580388, -11729417, -25979658, -11445023, -17411874, -10912854, 9291594, -16247779, -12154742, 6048605), array(-30305315, 14843444, 1539301, 11864366, 20201677, 1900163, 13934231, 5128323, 11213262, 9168384)), array(array(-26280513, 11007847, 19408960, -940758, -18592965, -4328580, -5088060, -11105150, 20470157, -16398701), array(-23136053, 9282192, 14855179, -15390078, -7362815, -14408560, -22783952, 14461608, 14042978, 5230683), array(29969567, -2741594, -16711867, -8552442, 9175486, -2468974, 21556951, 3506042, -5933891, -12449708)), array(array(-3144746, 8744661, 19704003, 4581278, -20430686, 6830683, -21284170, 8971513, -28539189, 15326563), array(-19464629, 10110288, -17262528, -3503892, -23500387, 1355669, -15523050, 15300988, -20514118, 9168260), array(-5353335, 4488613, -23803248, 16314347, 7780487, -15638939, -28948358, 9601605, 33087103, -9011387)), array(array(-19443170, -15512900, -20797467, -12445323, -29824447, 10229461, -27444329, -15000531, -5996870, 15664672), array(23294591, -16632613, -22650781, -8470978, 27844204, 11461195, 13099750, -2460356, 18151676, 13417686), array(-24722913, -4176517, -31150679, 5988919, -26858785, 6685065, 1661597, -12551441, 15271676, -15452665))), array(array(array(11433042, -13228665, 8239631, -5279517, -1985436, -725718, -18698764, 2167544, -6921301, -13440182), array(-31436171, 15575146, 30436815, 12192228, -22463353, 9395379, -9917708, -8638997, 12215110, 12028277), array(14098400, 6555944, 23007258, 5757252, -15427832, -12950502, 30123440, 4617780, -16900089, -655628)), array(array(-4026201, -15240835, 11893168, 13718664, -14809462, 1847385, -15819999, 10154009, 23973261, -12684474), array(-26531820, -3695990, -1908898, 2534301, -31870557, -16550355, 18341390, -11419951, 32013174, -10103539), array(-25479301, 10876443, -11771086, -14625140, -12369567, 1838104, 21911214, 6354752, 4425632, -837822)), array(array(-10433389, -14612966, 22229858, -3091047, -13191166, 776729, -17415375, -12020462, 4725005, 14044970), array(19268650, -7304421, 1555349, 8692754, -21474059, -9910664, 6347390, -1411784, -19522291, -16109756), array(-24864089, 12986008, -10898878, -5558584, -11312371, -148526, 19541418, 8180106, 9282262, 10282508)), array(array(-26205082, 4428547, -8661196, -13194263, 4098402, -14165257, 15522535, 8372215, 5542595, -10702683), array(-10562541, 14895633, 26814552, -16673850, -17480754, -2489360, -2781891, 6993761, -18093885, 10114655), array(-20107055, -929418, 31422704, 10427861, -7110749, 6150669, -29091755, -11529146, 25953725, -106158)), array(array(-4234397, -8039292, -9119125, 3046000, 2101609, -12607294, 19390020, 6094296, -3315279, 12831125), array(-15998678, 7578152, 5310217, 14408357, -33548620, -224739, 31575954, 6326196, 7381791, -2421839), array(-20902779, 3296811, 24736065, -16328389, 18374254, 7318640, 6295303, 8082724, -15362489, 12339664)), array(array(27724736, 2291157, 6088201, -14184798, 1792727, 5857634, 13848414, 15768922, 25091167, 14856294), array(-18866652, 8331043, 24373479, 8541013, -701998, -9269457, 12927300, -12695493, -22182473, -9012899), array(-11423429, -5421590, 11632845, 3405020, 30536730, -11674039, -27260765, 13866390, 30146206, 9142070)), array(array(3924129, -15307516, -13817122, -10054960, 12291820, -668366, -27702774, 9326384, -8237858, 4171294), array(-15921940, 16037937, 6713787, 16606682, -21612135, 2790944, 26396185, 3731949, 345228, -5462949), array(-21327538, 13448259, 25284571, 1143661, 20614966, -8849387, 2031539, -12391231, -16253183, -13582083)), array(array(31016211, -16722429, 26371392, -14451233, -5027349, 14854137, 17477601, 3842657, 28012650, -16405420), array(-5075835, 9368966, -8562079, -4600902, -15249953, 6970560, -9189873, 16292057, -8867157, 3507940), array(29439664, 3537914, 23333589, 6997794, -17555561, -11018068, -15209202, -15051267, -9164929, 6580396))), array(array(array(-12185861, -7679788, 16438269, 10826160, -8696817, -6235611, 17860444, -9273846, -2095802, 9304567), array(20714564, -4336911, 29088195, 7406487, 11426967, -5095705, 14792667, -14608617, 5289421, -477127), array(-16665533, -10650790, -6160345, -13305760, 9192020, -1802462, 17271490, 12349094, 26939669, -3752294)), array(array(-12889898, 9373458, 31595848, 16374215, 21471720, 13221525, -27283495, -12348559, -3698806, 117887), array(22263325, -6560050, 3984570, -11174646, -15114008, -566785, 28311253, 5358056, -23319780, 541964), array(16259219, 3261970, 2309254, -15534474, -16885711, -4581916, 24134070, -16705829, -13337066, -13552195)), array(array(9378160, -13140186, -22845982, -12745264, 28198281, -7244098, -2399684, -717351, 690426, 14876244), array(24977353, -314384, -8223969, -13465086, 28432343, -1176353, -13068804, -12297348, -22380984, 6618999), array(-1538174, 11685646, 12944378, 13682314, -24389511, -14413193, 8044829, -13817328, 32239829, -5652762)), array(array(-18603066, 4762990, -926250, 8885304, -28412480, -3187315, 9781647, -10350059, 32779359, 5095274), array(-33008130, -5214506, -32264887, -3685216, 9460461, -9327423, -24601656, 14506724, 21639561, -2630236), array(-16400943, -13112215, 25239338, 15531969, 3987758, -4499318, -1289502, -6863535, 17874574, 558605)), array(array(-13600129, 10240081, 9171883, 16131053, -20869254, 9599700, 33499487, 5080151, 2085892, 5119761), array(-22205145, -2519528, -16381601, 414691, -25019550, 2170430, 30634760, -8363614, -31999993, -5759884), array(-6845704, 15791202, 8550074, -1312654, 29928809, -12092256, 27534430, -7192145, -22351378, 12961482)), array(array(-24492060, -9570771, 10368194, 11582341, -23397293, -2245287, 16533930, 8206996, -30194652, -5159638), array(-11121496, -3382234, 2307366, 6362031, -135455, 8868177, -16835630, 7031275, 7589640, 8945490), array(-32152748, 8917967, 6661220, -11677616, -1192060, -15793393, 7251489, -11182180, 24099109, -14456170)), array(array(5019558, -7907470, 4244127, -14714356, -26933272, 6453165, -19118182, -13289025, -6231896, -10280736), array(10853594, 10721687, 26480089, 5861829, -22995819, 1972175, -1866647, -10557898, -3363451, -6441124), array(-17002408, 5906790, 221599, -6563147, 7828208, -13248918, 24362661, -2008168, -13866408, 7421392)), array(array(8139927, -6546497, 32257646, -5890546, 30375719, 1886181, -21175108, 15441252, 28826358, -4123029), array(6267086, 9695052, 7709135, -16603597, -32869068, -1886135, 14795160, -7840124, 13746021, -1742048), array(28584902, 7787108, -6732942, -15050729, 22846041, -7571236, -3181936, -363524, 4771362, -8419958))), array(array(array(24949256, 6376279, -27466481, -8174608, -18646154, -9930606, 33543569, -12141695, 3569627, 11342593), array(26514989, 4740088, 27912651, 3697550, 19331575, -11472339, 6809886, 4608608, 7325975, -14801071), array(-11618399, -14554430, -24321212, 7655128, -1369274, 5214312, -27400540, 10258390, -17646694, -8186692)), array(array(11431204, 15823007, 26570245, 14329124, 18029990, 4796082, -31446179, 15580664, 9280358, -3973687), array(-160783, -10326257, -22855316, -4304997, -20861367, -13621002, -32810901, -11181622, -15545091, 4387441), array(-20799378, 12194512, 3937617, -5805892, -27154820, 9340370, -24513992, 8548137, 20617071, -7482001)), array(array(-938825, -3930586, -8714311, 16124718, 24603125, -6225393, -13775352, -11875822, 24345683, 10325460), array(-19855277, -1568885, -22202708, 8714034, 14007766, 6928528, 16318175, -1010689, 4766743, 3552007), array(-21751364, -16730916, 1351763, -803421, -4009670, 3950935, 3217514, 14481909, 10988822, -3994762)), array(array(15564307, -14311570, 3101243, 5684148, 30446780, -8051356, 12677127, -6505343, -8295852, 13296005), array(-9442290, 6624296, -30298964, -11913677, -4670981, -2057379, 31521204, 9614054, -30000824, 12074674), array(4771191, -135239, 14290749, -13089852, 27992298, 14998318, -1413936, -1556716, 29832613, -16391035)), array(array(7064884, -7541174, -19161962, -5067537, -18891269, -2912736, 25825242, 5293297, -27122660, 13101590), array(-2298563, 2439670, -7466610, 1719965, -27267541, -16328445, 32512469, -5317593, -30356070, -4190957), array(-30006540, 10162316, -33180176, 3981723, -16482138, -13070044, 14413974, 9515896, 19568978, 9628812)), array(array(33053803, 199357, 15894591, 1583059, 27380243, -4580435, -17838894, -6106839, -6291786, 3437740), array(-18978877, 3884493, 19469877, 12726490, 15913552, 13614290, -22961733, 70104, 7463304, 4176122), array(-27124001, 10659917, 11482427, -16070381, 12771467, -6635117, -32719404, -5322751, 24216882, 5944158)), array(array(8894125, 7450974, -2664149, -9765752, -28080517, -12389115, 19345746, 14680796, 11632993, 5847885), array(26942781, -2315317, 9129564, -4906607, 26024105, 11769399, -11518837, 6367194, -9727230, 4782140), array(19916461, -4828410, -22910704, -11414391, 25606324, -5972441, 33253853, 8220911, 6358847, -1873857)), array(array(801428, -2081702, 16569428, 11065167, 29875704, 96627, 7908388, -4480480, -13538503, 1387155), array(19646058, 5720633, -11416706, 12814209, 11607948, 12749789, 14147075, 15156355, -21866831, 11835260), array(19299512, 1155910, 28703737, 14890794, 2925026, 7269399, 26121523, 15467869, -26560550, 5052483))), array(array(array(-3017432, 10058206, 1980837, 3964243, 22160966, 12322533, -6431123, -12618185, 12228557, -7003677), array(32944382, 14922211, -22844894, 5188528, 21913450, -8719943, 4001465, 13238564, -6114803, 8653815), array(22865569, -4652735, 27603668, -12545395, 14348958, 8234005, 24808405, 5719875, 28483275, 2841751)), array(array(-16420968, -1113305, -327719, -12107856, 21886282, -15552774, -1887966, -315658, 19932058, -12739203), array(-11656086, 10087521, -8864888, -5536143, -19278573, -3055912, 3999228, 13239134, -4777469, -13910208), array(1382174, -11694719, 17266790, 9194690, -13324356, 9720081, 20403944, 11284705, -14013818, 3093230)), array(array(16650921, -11037932, -1064178, 1570629, -8329746, 7352753, -302424, 16271225, -24049421, -6691850), array(-21911077, -5927941, -4611316, -5560156, -31744103, -10785293, 24123614, 15193618, -21652117, -16739389), array(-9935934, -4289447, -25279823, 4372842, 2087473, 10399484, 31870908, 14690798, 17361620, 11864968)), array(array(-11307610, 6210372, 13206574, 5806320, -29017692, -13967200, -12331205, -7486601, -25578460, -16240689), array(14668462, -12270235, 26039039, 15305210, 25515617, 4542480, 10453892, 6577524, 9145645, -6443880), array(5974874, 3053895, -9433049, -10385191, -31865124, 3225009, -7972642, 3936128, -5652273, -3050304)), array(array(30625386, -4729400, -25555961, -12792866, -20484575, 7695099, 17097188, -16303496, -27999779, 1803632), array(-3553091, 9865099, -5228566, 4272701, -5673832, -16689700, 14911344, 12196514, -21405489, 7047412), array(20093277, 9920966, -11138194, -5343857, 13161587, 12044805, -32856851, 4124601, -32343828, -10257566)), array(array(-20788824, 14084654, -13531713, 7842147, 19119038, -13822605, 4752377, -8714640, -21679658, 2288038), array(-26819236, -3283715, 29965059, 3039786, -14473765, 2540457, 29457502, 14625692, -24819617, 12570232), array(-1063558, -11551823, 16920318, 12494842, 1278292, -5869109, -21159943, -3498680, -11974704, 4724943)), array(array(17960970, -11775534, -4140968, -9702530, -8876562, -1410617, -12907383, -8659932, -29576300, 1903856), array(23134274, -14279132, -10681997, -1611936, 20684485, 15770816, -12989750, 3190296, 26955097, 14109738), array(15308788, 5320727, -30113809, -14318877, 22902008, 7767164, 29425325, -11277562, 31960942, 11934971)), array(array(-27395711, 8435796, 4109644, 12222639, -24627868, 14818669, 20638173, 4875028, 10491392, 1379718), array(-13159415, 9197841, 3875503, -8936108, -1383712, -5879801, 33518459, 16176658, 21432314, 12180697), array(-11787308, 11500838, 13787581, -13832590, -22430679, 10140205, 1465425, 12689540, -10301319, -13872883))), array(array(array(5414091, -15386041, -21007664, 9643570, 12834970, 1186149, -2622916, -1342231, 26128231, 6032912), array(-26337395, -13766162, 32496025, -13653919, 17847801, -12669156, 3604025, 8316894, -25875034, -10437358), array(3296484, 6223048, 24680646, -12246460, -23052020, 5903205, -8862297, -4639164, 12376617, 3188849)), array(array(29190488, -14659046, 27549113, -1183516, 3520066, -10697301, 32049515, -7309113, -16109234, -9852307), array(-14744486, -9309156, 735818, -598978, -20407687, -5057904, 25246078, -15795669, 18640741, -960977), array(-6928835, -16430795, 10361374, 5642961, 4910474, 12345252, -31638386, -494430, 10530747, 1053335)), array(array(-29265967, -14186805, -13538216, -12117373, -19457059, -10655384, -31462369, -2948985, 24018831, 15026644), array(-22592535, -3145277, -2289276, 5953843, -13440189, 9425631, 25310643, 13003497, -2314791, -15145616), array(-27419985, -603321, -8043984, -1669117, -26092265, 13987819, -27297622, 187899, -23166419, -2531735)), array(array(-21744398, -13810475, 1844840, 5021428, -10434399, -15911473, 9716667, 16266922, -5070217, 726099), array(29370922, -6053998, 7334071, -15342259, 9385287, 2247707, -13661962, -4839461, 30007388, -15823341), array(-936379, 16086691, 23751945, -543318, -1167538, -5189036, 9137109, 730663, 9835848, 4555336)), array(array(-23376435, 1410446, -22253753, -12899614, 30867635, 15826977, 17693930, 544696, -11985298, 12422646), array(31117226, -12215734, -13502838, 6561947, -9876867, -12757670, -5118685, -4096706, 29120153, 13924425), array(-17400879, -14233209, 19675799, -2734756, -11006962, -5858820, -9383939, -11317700, 7240931, -237388)), array(array(-31361739, -11346780, -15007447, -5856218, -22453340, -12152771, 1222336, 4389483, 3293637, -15551743), array(-16684801, -14444245, 11038544, 11054958, -13801175, -3338533, -24319580, 7733547, 12796905, -6335822), array(-8759414, -10817836, -25418864, 10783769, -30615557, -9746811, -28253339, 3647836, 3222231, -11160462)), array(array(18606113, 1693100, -25448386, -15170272, 4112353, 10045021, 23603893, -2048234, -7550776, 2484985), array(9255317, -3131197, -12156162, -1004256, 13098013, -9214866, 16377220, -2102812, -19802075, -3034702), array(-22729289, 7496160, -5742199, 11329249, 19991973, -3347502, -31718148, 9936966, -30097688, -10618797)), array(array(21878590, -5001297, 4338336, 13643897, -3036865, 13160960, 19708896, 5415497, -7360503, -4109293), array(27736861, 10103576, 12500508, 8502413, -3413016, -9633558, 10436918, -1550276, -23659143, -8132100), array(19492550, -12104365, -29681976, -852630, -3208171, 12403437, 30066266, 8367329, 13243957, 8709688))), array(array(array(12015105, 2801261, 28198131, 10151021, 24818120, -4743133, -11194191, -5645734, 5150968, 7274186), array(2831366, -12492146, 1478975, 6122054, 23825128, -12733586, 31097299, 6083058, 31021603, -9793610), array(-2529932, -2229646, 445613, 10720828, -13849527, -11505937, -23507731, 16354465, 15067285, -14147707)), array(array(7840942, 14037873, -33364863, 15934016, -728213, -3642706, 21403988, 1057586, -19379462, -12403220), array(915865, -16469274, 15608285, -8789130, -24357026, 6060030, -17371319, 8410997, -7220461, 16527025), array(32922597, -556987, 20336074, -16184568, 10903705, -5384487, 16957574, 52992, 23834301, 6588044)), array(array(32752030, 11232950, 3381995, -8714866, 22652988, -10744103, 17159699, 16689107, -20314580, -1305992), array(-4689649, 9166776, -25710296, -10847306, 11576752, 12733943, 7924251, -2752281, 1976123, -7249027), array(21251222, 16309901, -2983015, -6783122, 30810597, 12967303, 156041, -3371252, 12331345, -8237197)), array(array(8651614, -4477032, -16085636, -4996994, 13002507, 2950805, 29054427, -5106970, 10008136, -4667901), array(31486080, 15114593, -14261250, 12951354, 14369431, -7387845, 16347321, -13662089, 8684155, -10532952), array(19443825, 11385320, 24468943, -9659068, -23919258, 2187569, -26263207, -6086921, 31316348, 14219878)), array(array(-28594490, 1193785, 32245219, 11392485, 31092169, 15722801, 27146014, 6992409, 29126555, 9207390), array(32382935, 1110093, 18477781, 11028262, -27411763, -7548111, -4980517, 10843782, -7957600, -14435730), array(2814918, 7836403, 27519878, -7868156, -20894015, -11553689, -21494559, 8550130, 28346258, 1994730)), array(array(-19578299, 8085545, -14000519, -3948622, 2785838, -16231307, -19516951, 7174894, 22628102, 8115180), array(-30405132, 955511, -11133838, -15078069, -32447087, -13278079, -25651578, 3317160, -9943017, 930272), array(-15303681, -6833769, 28856490, 1357446, 23421993, 1057177, 24091212, -1388970, -22765376, -10650715)), array(array(-22751231, -5303997, -12907607, -12768866, -15811511, -7797053, -14839018, -16554220, -1867018, 8398970), array(-31969310, 2106403, -4736360, 1362501, 12813763, 16200670, 22981545, -6291273, 18009408, -15772772), array(-17220923, -9545221, -27784654, 14166835, 29815394, 7444469, 29551787, -3727419, 19288549, 1325865)), array(array(15100157, -15835752, -23923978, -1005098, -26450192, 15509408, 12376730, -3479146, 33166107, -8042750), array(20909231, 13023121, -9209752, 16251778, -5778415, -8094914, 12412151, 10018715, 2213263, -13878373), array(32529814, -11074689, 30361439, -16689753, -9135940, 1513226, 22922121, 6382134, -5766928, 8371348))), array(array(array(9923462, 11271500, 12616794, 3544722, -29998368, -1721626, 12891687, -8193132, -26442943, 10486144), array(-22597207, -7012665, 8587003, -8257861, 4084309, -12970062, 361726, 2610596, -23921530, -11455195), array(5408411, -1136691, -4969122, 10561668, 24145918, 14240566, 31319731, -4235541, 19985175, -3436086)), array(array(-13994457, 16616821, 14549246, 3341099, 32155958, 13648976, -17577068, 8849297, 65030, 8370684), array(-8320926, -12049626, 31204563, 5839400, -20627288, -1057277, -19442942, 6922164, 12743482, -9800518), array(-2361371, 12678785, 28815050, 4759974, -23893047, 4884717, 23783145, 11038569, 18800704, 255233)), array(array(-5269658, -1773886, 13957886, 7990715, 23132995, 728773, 13393847, 9066957, 19258688, -14753793), array(-2936654, -10827535, -10432089, 14516793, -3640786, 4372541, -31934921, 2209390, -1524053, 2055794), array(580882, 16705327, 5468415, -2683018, -30926419, -14696000, -7203346, -8994389, -30021019, 7394435)), array(array(23838809, 1822728, -15738443, 15242727, 8318092, -3733104, -21672180, -3492205, -4821741, 14799921), array(13345610, 9759151, 3371034, -16137791, 16353039, 8577942, 31129804, 13496856, -9056018, 7402518), array(2286874, -4435931, -20042458, -2008336, -13696227, 5038122, 11006906, -15760352, 8205061, 1607563)), array(array(14414086, -8002132, 3331830, -3208217, 22249151, -5594188, 18364661, -2906958, 30019587, -9029278), array(-27688051, 1585953, -10775053, 931069, -29120221, -11002319, -14410829, 12029093, 9944378, 8024), array(4368715, -3709630, 29874200, -15022983, -20230386, -11410704, -16114594, -999085, -8142388, 5640030)), array(array(10299610, 13746483, 11661824, 16234854, 7630238, 5998374, 9809887, -16694564, 15219798, -14327783), array(27425505, -5719081, 3055006, 10660664, 23458024, 595578, -15398605, -1173195, -18342183, 9742717), array(6744077, 2427284, 26042789, 2720740, -847906, 1118974, 32324614, 7406442, 12420155, 1994844)), array(array(14012521, -5024720, -18384453, -9578469, -26485342, -3936439, -13033478, -10909803, 24319929, -6446333), array(16412690, -4507367, 10772641, 15929391, -17068788, -4658621, 10555945, -10484049, -30102368, -4739048), array(22397382, -7767684, -9293161, -12792868, 17166287, -9755136, -27333065, 6199366, 21880021, -12250760)), array(array(-4283307, 5368523, -31117018, 8163389, -30323063, 3209128, 16557151, 8890729, 8840445, 4957760), array(-15447727, 709327, -6919446, -10870178, -29777922, 6522332, -21720181, 12130072, -14796503, 5005757), array(-2114751, -14308128, 23019042, 15765735, -25269683, 6002752, 10183197, -13239326, -16395286, -2176112))), array(array(array(-19025756, 1632005, 13466291, -7995100, -23640451, 16573537, -32013908, -3057104, 22208662, 2000468), array(3065073, -1412761, -25598674, -361432, -17683065, -5703415, -8164212, 11248527, -3691214, -7414184), array(10379208, -6045554, 8877319, 1473647, -29291284, -12507580, 16690915, 2553332, -3132688, 16400289)), array(array(15716668, 1254266, -18472690, 7446274, -8448918, 6344164, -22097271, -7285580, 26894937, 9132066), array(24158887, 12938817, 11085297, -8177598, -28063478, -4457083, -30576463, 64452, -6817084, -2692882), array(13488534, 7794716, 22236231, 5989356, 25426474, -12578208, 2350710, -3418511, -4688006, 2364226)), array(array(16335052, 9132434, 25640582, 6678888, 1725628, 8517937, -11807024, -11697457, 15445875, -7798101), array(29004207, -7867081, 28661402, -640412, -12794003, -7943086, 31863255, -4135540, -278050, -15759279), array(-6122061, -14866665, -28614905, 14569919, -10857999, -3591829, 10343412, -6976290, -29828287, -10815811)), array(array(27081650, 3463984, 14099042, -4517604, 1616303, -6205604, 29542636, 15372179, 17293797, 960709), array(20263915, 11434237, -5765435, 11236810, 13505955, -10857102, -16111345, 6493122, -19384511, 7639714), array(-2830798, -14839232, 25403038, -8215196, -8317012, -16173699, 18006287, -16043750, 29994677, -15808121)), array(array(9769828, 5202651, -24157398, -13631392, -28051003, -11561624, -24613141, -13860782, -31184575, 709464), array(12286395, 13076066, -21775189, -1176622, -25003198, 4057652, -32018128, -8890874, 16102007, 13205847), array(13733362, 5599946, 10557076, 3195751, -5557991, 8536970, -25540170, 8525972, 10151379, 10394400)), array(array(4024660, -16137551, 22436262, 12276534, -9099015, -2686099, 19698229, 11743039, -33302334, 8934414), array(-15879800, -4525240, -8580747, -2934061, 14634845, -698278, -9449077, 3137094, -11536886, 11721158), array(17555939, -5013938, 8268606, 2331751, -22738815, 9761013, 9319229, 8835153, -9205489, -1280045)), array(array(-461409, -7830014, 20614118, 16688288, -7514766, -4807119, 22300304, 505429, 6108462, -6183415), array(-5070281, 12367917, -30663534, 3234473, 32617080, -8422642, 29880583, -13483331, -26898490, -7867459), array(-31975283, 5726539, 26934134, 10237677, -3173717, -605053, 24199304, 3795095, 7592688, -14992079)), array(array(21594432, -14964228, 17466408, -4077222, 32537084, 2739898, 6407723, 12018833, -28256052, 4298412), array(-20650503, -11961496, -27236275, 570498, 3767144, -1717540, 13891942, -1569194, 13717174, 10805743), array(-14676630, -15644296, 15287174, 11927123, 24177847, -8175568, -796431, 14860609, -26938930, -5863836))), array(array(array(12962541, 5311799, -10060768, 11658280, 18855286, -7954201, 13286263, -12808704, -4381056, 9882022), array(18512079, 11319350, -20123124, 15090309, 18818594, 5271736, -22727904, 3666879, -23967430, -3299429), array(-6789020, -3146043, 16192429, 13241070, 15898607, -14206114, -10084880, -6661110, -2403099, 5276065)), array(array(30169808, -5317648, 26306206, -11750859, 27814964, 7069267, 7152851, 3684982, 1449224, 13082861), array(10342826, 3098505, 2119311, 193222, 25702612, 12233820, 23697382, 15056736, -21016438, -8202000), array(-33150110, 3261608, 22745853, 7948688, 19370557, -15177665, -26171976, 6482814, -10300080, -11060101)), array(array(32869458, -5408545, 25609743, 15678670, -10687769, -15471071, 26112421, 2521008, -22664288, 6904815), array(29506923, 4457497, 3377935, -9796444, -30510046, 12935080, 1561737, 3841096, -29003639, -6657642), array(10340844, -6630377, -18656632, -2278430, 12621151, -13339055, 30878497, -11824370, -25584551, 5181966)), array(array(25940115, -12658025, 17324188, -10307374, -8671468, 15029094, 24396252, -16450922, -2322852, -12388574), array(-21765684, 9916823, -1300409, 4079498, -1028346, 11909559, 1782390, 12641087, 20603771, -6561742), array(-18882287, -11673380, 24849422, 11501709, 13161720, -4768874, 1925523, 11914390, 4662781, 7820689)), array(array(12241050, -425982, 8132691, 9393934, 32846760, -1599620, 29749456, 12172924, 16136752, 15264020), array(-10349955, -14680563, -8211979, 2330220, -17662549, -14545780, 10658213, 6671822, 19012087, 3772772), array(3753511, -3421066, 10617074, 2028709, 14841030, -6721664, 28718732, -15762884, 20527771, 12988982)), array(array(-14822485, -5797269, -3707987, 12689773, -898983, -10914866, -24183046, -10564943, 3299665, -12424953), array(-16777703, -15253301, -9642417, 4978983, 3308785, 8755439, 6943197, 6461331, -25583147, 8991218), array(-17226263, 1816362, -1673288, -6086439, 31783888, -8175991, -32948145, 7417950, -30242287, 1507265)), array(array(29692663, 6829891, -10498800, 4334896, 20945975, -11906496, -28887608, 8209391, 14606362, -10647073), array(-3481570, 8707081, 32188102, 5672294, 22096700, 1711240, -33020695, 9761487, 4170404, -2085325), array(-11587470, 14855945, -4127778, -1531857, -26649089, 15084046, 22186522, 16002000, -14276837, -8400798)), array(array(-4811456, 13761029, -31703877, -2483919, -3312471, 7869047, -7113572, -9620092, 13240845, 10965870), array(-7742563, -8256762, -14768334, -13656260, -23232383, 12387166, 4498947, 14147411, 29514390, 4302863), array(-13413405, -12407859, 20757302, -13801832, 14785143, 8976368, -5061276, -2144373, 17846988, -13971927))), array(array(array(-2244452, -754728, -4597030, -1066309, -6247172, 1455299, -21647728, -9214789, -5222701, 12650267), array(-9906797, -16070310, 21134160, 12198166, -27064575, 708126, 387813, 13770293, -19134326, 10958663), array(22470984, 12369526, 23446014, -5441109, -21520802, -9698723, -11772496, -11574455, -25083830, 4271862)), array(array(-25169565, -10053642, -19909332, 15361595, -5984358, 2159192, 75375, -4278529, -32526221, 8469673), array(15854970, 4148314, -8893890, 7259002, 11666551, 13824734, -30531198, 2697372, 24154791, -9460943), array(15446137, -15806644, 29759747, 14019369, 30811221, -9610191, -31582008, 12840104, 24913809, 9815020)), array(array(-4709286, -5614269, -31841498, -12288893, -14443537, 10799414, -9103676, 13438769, 18735128, 9466238), array(11933045, 9281483, 5081055, -5183824, -2628162, -4905629, -7727821, -10896103, -22728655, 16199064), array(14576810, 379472, -26786533, -8317236, -29426508, -10812974, -102766, 1876699, 30801119, 2164795)), array(array(15995086, 3199873, 13672555, 13712240, -19378835, -4647646, -13081610, -15496269, -13492807, 1268052), array(-10290614, -3659039, -3286592, 10948818, 23037027, 3794475, -3470338, -12600221, -17055369, 3565904), array(29210088, -9419337, -5919792, -4952785, 10834811, -13327726, -16512102, -10820713, -27162222, -14030531)), array(array(-13161890, 15508588, 16663704, -8156150, -28349942, 9019123, -29183421, -3769423, 2244111, -14001979), array(-5152875, -3800936, -9306475, -6071583, 16243069, 14684434, -25673088, -16180800, 13491506, 4641841), array(10813417, 643330, -19188515, -728916, 30292062, -16600078, 27548447, -7721242, 14476989, -12767431)), array(array(10292079, 9984945, 6481436, 8279905, -7251514, 7032743, 27282937, -1644259, -27912810, 12651324), array(-31185513, -813383, 22271204, 11835308, 10201545, 15351028, 17099662, 3988035, 21721536, -3148940), array(10202177, -6545839, -31373232, -9574638, -32150642, -8119683, -12906320, 3852694, 13216206, 14842320)), array(array(-15815640, -10601066, -6538952, -7258995, -6984659, -6581778, -31500847, 13765824, -27434397, 9900184), array(14465505, -13833331, -32133984, -14738873, -27443187, 12990492, 33046193, 15796406, -7051866, -8040114), array(30924417, -8279620, 6359016, -12816335, 16508377, 9071735, -25488601, 15413635, 9524356, -7018878)), array(array(12274201, -13175547, 32627641, -1785326, 6736625, 13267305, 5237659, -5109483, 15663516, 4035784), array(-2951309, 8903985, 17349946, 601635, -16432815, -4612556, -13732739, -15889334, -22258478, 4659091), array(-16916263, -4952973, -30393711, -15158821, 20774812, 15897498, 5736189, 15026997, -2178256, -13455585))), array(array(array(-8858980, -2219056, 28571666, -10155518, -474467, -10105698, -3801496, 278095, 23440562, -290208), array(10226241, -5928702, 15139956, 120818, -14867693, 5218603, 32937275, 11551483, -16571960, -7442864), array(17932739, -12437276, -24039557, 10749060, 11316803, 7535897, 22503767, 5561594, -3646624, 3898661)), array(array(7749907, -969567, -16339731, -16464, -25018111, 15122143, -1573531, 7152530, 21831162, 1245233), array(26958459, -14658026, 4314586, 8346991, -5677764, 11960072, -32589295, -620035, -30402091, -16716212), array(-12165896, 9166947, 33491384, 13673479, 29787085, 13096535, 6280834, 14587357, -22338025, 13987525)), array(array(-24349909, 7778775, 21116000, 15572597, -4833266, -5357778, -4300898, -5124639, -7469781, -2858068), array(9681908, -6737123, -31951644, 13591838, -6883821, 386950, 31622781, 6439245, -14581012, 4091397), array(-8426427, 1470727, -28109679, -1596990, 3978627, -5123623, -19622683, 12092163, 29077877, -14741988)), array(array(5269168, -6859726, -13230211, -8020715, 25932563, 1763552, -5606110, -5505881, -20017847, 2357889), array(32264008, -15407652, -5387735, -1160093, -2091322, -3946900, 23104804, -12869908, 5727338, 189038), array(14609123, -8954470, -6000566, -16622781, -14577387, -7743898, -26745169, 10942115, -25888931, -14884697)), array(array(20513500, 5557931, -15604613, 7829531, 26413943, -2019404, -21378968, 7471781, 13913677, -5137875), array(-25574376, 11967826, 29233242, 12948236, -6754465, 4713227, -8940970, 14059180, 12878652, 8511905), array(-25656801, 3393631, -2955415, -7075526, -2250709, 9366908, -30223418, 6812974, 5568676, -3127656)), array(array(11630004, 12144454, 2116339, 13606037, 27378885, 15676917, -17408753, -13504373, -14395196, 8070818), array(27117696, -10007378, -31282771, -5570088, 1127282, 12772488, -29845906, 10483306, -11552749, -1028714), array(10637467, -5688064, 5674781, 1072708, -26343588, -6982302, -1683975, 9177853, -27493162, 15431203)), array(array(20525145, 10892566, -12742472, 12779443, -29493034, 16150075, -28240519, 14943142, -15056790, -7935931), array(-30024462, 5626926, -551567, -9981087, 753598, 11981191, 25244767, -3239766, -3356550, 9594024), array(-23752644, 2636870, -5163910, -10103818, 585134, 7877383, 11345683, -6492290, 13352335, -10977084)), array(array(-1931799, -5407458, 3304649, -12884869, 17015806, -4877091, -29783850, -7752482, -13215537, -319204), array(20239939, 6607058, 6203985, 3483793, -18386976, -779229, -20723742, 15077870, -22750759, 14523817), array(27406042, -6041657, 27423596, -4497394, 4996214, 10002360, -28842031, -4545494, -30172742, -4805667))), array(array(array(11374242, 12660715, 17861383, -12540833, 10935568, 1099227, -13886076, -9091740, -27727044, 11358504), array(-12730809, 10311867, 1510375, 10778093, -2119455, -9145702, 32676003, 11149336, -26123651, 4985768), array(-19096303, 341147, -6197485, -239033, 15756973, -8796662, -983043, 13794114, -19414307, -15621255)), array(array(6490081, 11940286, 25495923, -7726360, 8668373, -8751316, 3367603, 6970005, -1691065, -9004790), array(1656497, 13457317, 15370807, 6364910, 13605745, 8362338, -19174622, -5475723, -16796596, -5031438), array(-22273315, -13524424, -64685, -4334223, -18605636, -10921968, -20571065, -7007978, -99853, -10237333)), array(array(17747465, 10039260, 19368299, -4050591, -20630635, -16041286, 31992683, -15857976, -29260363, -5511971), array(31932027, -4986141, -19612382, 16366580, 22023614, 88450, 11371999, -3744247, 4882242, -10626905), array(29796507, 37186, 19818052, 10115756, -11829032, 3352736, 18551198, 3272828, -5190932, -4162409)), array(array(12501286, 4044383, -8612957, -13392385, -32430052, 5136599, -19230378, -3529697, 330070, -3659409), array(6384877, 2899513, 17807477, 7663917, -2358888, 12363165, 25366522, -8573892, -271295, 12071499), array(-8365515, -4042521, 25133448, -4517355, -6211027, 2265927, -32769618, 1936675, -5159697, 3829363)), array(array(28425966, -5835433, -577090, -4697198, -14217555, 6870930, 7921550, -6567787, 26333140, 14267664), array(-11067219, 11871231, 27385719, -10559544, -4585914, -11189312, 10004786, -8709488, -21761224, 8930324), array(-21197785, -16396035, 25654216, -1725397, 12282012, 11008919, 1541940, 4757911, -26491501, -16408940)), array(array(13537262, -7759490, -20604840, 10961927, -5922820, -13218065, -13156584, 6217254, -15943699, 13814990), array(-17422573, 15157790, 18705543, 29619, 24409717, -260476, 27361681, 9257833, -1956526, -1776914), array(-25045300, -10191966, 15366585, 15166509, -13105086, 8423556, -29171540, 12361135, -18685978, 4578290)), array(array(24579768, 3711570, 1342322, -11180126, -27005135, 14124956, -22544529, 14074919, 21964432, 8235257), array(-6528613, -2411497, 9442966, -5925588, 12025640, -1487420, -2981514, -1669206, 13006806, 2355433), array(-16304899, -13605259, -6632427, -5142349, 16974359, -10911083, 27202044, 1719366, 1141648, -12796236)), array(array(-12863944, -13219986, -8318266, -11018091, -6810145, -4843894, 13475066, -3133972, 32674895, 13715045), array(11423335, -5468059, 32344216, 8962751, 24989809, 9241752, -13265253, 16086212, -28740881, -15642093), array(-1409668, 12530728, -6368726, 10847387, 19531186, -14132160, -11709148, 7791794, -27245943, 4383347))), array(array(array(-28970898, 5271447, -1266009, -9736989, -12455236, 16732599, -4862407, -4906449, 27193557, 6245191), array(-15193956, 5362278, -1783893, 2695834, 4960227, 12840725, 23061898, 3260492, 22510453, 8577507), array(-12632451, 11257346, -32692994, 13548177, -721004, 10879011, 31168030, 13952092, -29571492, -3635906)), array(array(3877321, -9572739, 32416692, 5405324, -11004407, -13656635, 3759769, 11935320, 5611860, 8164018), array(-16275802, 14667797, 15906460, 12155291, -22111149, -9039718, 32003002, -8832289, 5773085, -8422109), array(-23788118, -8254300, 1950875, 8937633, 18686727, 16459170, -905725, 12376320, 31632953, 190926)), array(array(-24593607, -16138885, -8423991, 13378746, 14162407, 6901328, -8288749, 4508564, -25341555, -3627528), array(8884438, -5884009, 6023974, 10104341, -6881569, -4941533, 18722941, -14786005, -1672488, 827625), array(-32720583, -16289296, -32503547, 7101210, 13354605, 2659080, -1800575, -14108036, -24878478, 1541286)), array(array(2901347, -1117687, 3880376, -10059388, -17620940, -3612781, -21802117, -3567481, 20456845, -1885033), array(27019610, 12299467, -13658288, -1603234, -12861660, -4861471, -19540150, -5016058, 29439641, 15138866), array(21536104, -6626420, -32447818, -10690208, -22408077, 5175814, -5420040, -16361163, 7779328, 109896)), array(array(30279744, 14648750, -8044871, 6425558, 13639621, -743509, 28698390, 12180118, 23177719, -554075), array(26572847, 3405927, -31701700, 12890905, -19265668, 5335866, -6493768, 2378492, 4439158, -13279347), array(-22716706, 3489070, -9225266, -332753, 18875722, -1140095, 14819434, -12731527, -17717757, -5461437)), array(array(-5056483, 16566551, 15953661, 3767752, -10436499, 15627060, -820954, 2177225, 8550082, -15114165), array(-18473302, 16596775, -381660, 15663611, 22860960, 15585581, -27844109, -3582739, -23260460, -8428588), array(-32480551, 15707275, -8205912, -5652081, 29464558, 2713815, -22725137, 15860482, -21902570, 1494193)), array(array(-19562091, -14087393, -25583872, -9299552, 13127842, 759709, 21923482, 16529112, 8742704, 12967017), array(-28464899, 1553205, 32536856, -10473729, -24691605, -406174, -8914625, -2933896, -29903758, 15553883), array(21877909, 3230008, 9881174, 10539357, -4797115, 2841332, 11543572, 14513274, 19375923, -12647961)), array(array(8832269, -14495485, 13253511, 5137575, 5037871, 4078777, 24880818, -6222716, 2862653, 9455043), array(29306751, 5123106, 20245049, -14149889, 9592566, 8447059, -2077124, -2990080, 15511449, 4789663), array(-20679756, 7004547, 8824831, -9434977, -4045704, -3750736, -5754762, 108893, 23513200, 16652362))), array(array(array(-33256173, 4144782, -4476029, -6579123, 10770039, -7155542, -6650416, -12936300, -18319198, 10212860), array(2756081, 8598110, 7383731, -6859892, 22312759, -1105012, 21179801, 2600940, -9988298, -12506466), array(-24645692, 13317462, -30449259, -15653928, 21365574, -10869657, 11344424, 864440, -2499677, -16710063)), array(array(-26432803, 6148329, -17184412, -14474154, 18782929, -275997, -22561534, 211300, 2719757, 4940997), array(-1323882, 3911313, -6948744, 14759765, -30027150, 7851207, 21690126, 8518463, 26699843, 5276295), array(-13149873, -6429067, 9396249, 365013, 24703301, -10488939, 1321586, 149635, -15452774, 7159369)), array(array(9987780, -3404759, 17507962, 9505530, 9731535, -2165514, 22356009, 8312176, 22477218, -8403385), array(18155857, -16504990, 19744716, 9006923, 15154154, -10538976, 24256460, -4864995, -22548173, 9334109), array(2986088, -4911893, 10776628, -3473844, 10620590, -7083203, -21413845, 14253545, -22587149, 536906)), array(array(4377756, 8115836, 24567078, 15495314, 11625074, 13064599, 7390551, 10589625, 10838060, -15420424), array(-19342404, 867880, 9277171, -3218459, -14431572, -1986443, 19295826, -15796950, 6378260, 699185), array(7895026, 4057113, -7081772, -13077756, -17886831, -323126, -716039, 15693155, -5045064, -13373962)), array(array(-7737563, -5869402, -14566319, -7406919, 11385654, 13201616, 31730678, -10962840, -3918636, -9669325), array(10188286, -15770834, -7336361, 13427543, 22223443, 14896287, 30743455, 7116568, -21786507, 5427593), array(696102, 13206899, 27047647, -10632082, 15285305, -9853179, 10798490, -4578720, 19236243, 12477404)), array(array(-11229439, 11243796, -17054270, -8040865, -788228, -8167967, -3897669, 11180504, -23169516, 7733644), array(17800790, -14036179, -27000429, -11766671, 23887827, 3149671, 23466177, -10538171, 10322027, 15313801), array(26246234, 11968874, 32263343, -5468728, 6830755, -13323031, -15794704, -101982, -24449242, 10890804)), array(array(-31365647, 10271363, -12660625, -6267268, 16690207, -13062544, -14982212, 16484931, 25180797, -5334884), array(-586574, 10376444, -32586414, -11286356, 19801893, 10997610, 2276632, 9482883, 316878, 13820577), array(-9882808, -4510367, -2115506, 16457136, -11100081, 11674996, 30756178, -7515054, 30696930, -3712849)), array(array(32988917, -9603412, 12499366, 7910787, -10617257, -11931514, -7342816, -9985397, -32349517, 7392473), array(-8855661, 15927861, 9866406, -3649411, -2396914, -16655781, -30409476, -9134995, 25112947, -2926644), array(-2504044, -436966, 25621774, -5678772, 15085042, -5479877, -24884878, -13526194, 5537438, -13914319))), array(array(array(-11225584, 2320285, -9584280, 10149187, -33444663, 5808648, -14876251, -1729667, 31234590, 6090599), array(-9633316, 116426, 26083934, 2897444, -6364437, -2688086, 609721, 15878753, -6970405, -9034768), array(-27757857, 247744, -15194774, -9002551, 23288161, -10011936, -23869595, 6503646, 20650474, 1804084)), array(array(-27589786, 15456424, 8972517, 8469608, 15640622, 4439847, 3121995, -10329713, 27842616, -202328), array(-15306973, 2839644, 22530074, 10026331, 4602058, 5048462, 28248656, 5031932, -11375082, 12714369), array(20807691, -7270825, 29286141, 11421711, -27876523, -13868230, -21227475, 1035546, -19733229, 12796920)), array(array(12076899, -14301286, -8785001, -11848922, -25012791, 16400684, -17591495, -12899438, 3480665, -15182815), array(-32361549, 5457597, 28548107, 7833186, 7303070, -11953545, -24363064, -15921875, -33374054, 2771025), array(-21389266, 421932, 26597266, 6860826, 22486084, -6737172, -17137485, -4210226, -24552282, 15673397)), array(array(-20184622, 2338216, 19788685, -9620956, -4001265, -8740893, -20271184, 4733254, 3727144, -12934448), array(6120119, 814863, -11794402, -622716, 6812205, -15747771, 2019594, 7975683, 31123697, -10958981), array(30069250, -11435332, 30434654, 2958439, 18399564, -976289, 12296869, 9204260, -16432438, 9648165)), array(array(32705432, -1550977, 30705658, 7451065, -11805606, 9631813, 3305266, 5248604, -26008332, -11377501), array(17219865, 2375039, -31570947, -5575615, -19459679, 9219903, 294711, 15298639, 2662509, -16297073), array(-1172927, -7558695, -4366770, -4287744, -21346413, -8434326, 32087529, -1222777, 32247248, -14389861)), array(array(14312628, 1221556, 17395390, -8700143, -4945741, -8684635, -28197744, -9637817, -16027623, -13378845), array(-1428825, -9678990, -9235681, 6549687, -7383069, -468664, 23046502, 9803137, 17597934, 2346211), array(18510800, 15337574, 26171504, 981392, -22241552, 7827556, -23491134, -11323352, 3059833, -11782870)), array(array(10141598, 6082907, 17829293, -1947643, 9830092, 13613136, -25556636, -5544586, -33502212, 3592096), array(33114168, -15889352, -26525686, -13343397, 33076705, 8716171, 1151462, 1521897, -982665, -6837803), array(-32939165, -4255815, 23947181, -324178, -33072974, -12305637, -16637686, 3891704, 26353178, 693168)), array(array(30374239, 1595580, -16884039, 13186931, 4600344, 406904, 9585294, -400668, 31375464, 14369965), array(-14370654, -7772529, 1510301, 6434173, -18784789, -6262728, 32732230, -13108839, 17901441, 16011505), array(18171223, -11934626, -12500402, 15197122, -11038147, -15230035, -19172240, -16046376, 8764035, 12309598))), array(array(array(5975908, -5243188, -19459362, -9681747, -11541277, 14015782, -23665757, 1228319, 17544096, -10593782), array(5811932, -1715293, 3442887, -2269310, -18367348, -8359541, -18044043, -15410127, -5565381, 12348900), array(-31399660, 11407555, 25755363, 6891399, -3256938, 14872274, -24849353, 8141295, -10632534, -585479)), array(array(-12675304, 694026, -5076145, 13300344, 14015258, -14451394, -9698672, -11329050, 30944593, 1130208), array(8247766, -6710942, -26562381, -7709309, -14401939, -14648910, 4652152, 2488540, 23550156, -271232), array(17294316, -3788438, 7026748, 15626851, 22990044, 113481, 2267737, -5908146, -408818, -137719)), array(array(16091085, -16253926, 18599252, 7340678, 2137637, -1221657, -3364161, 14550936, 3260525, -7166271), array(-4910104, -13332887, 18550887, 10864893, -16459325, -7291596, -23028869, -13204905, -12748722, 2701326), array(-8574695, 16099415, 4629974, -16340524, -20786213, -6005432, -10018363, 9276971, 11329923, 1862132)), array(array(14763076, -15903608, -30918270, 3689867, 3511892, 10313526, -21951088, 12219231, -9037963, -940300), array(8894987, -3446094, 6150753, 3013931, 301220, 15693451, -31981216, -2909717, -15438168, 11595570), array(15214962, 3537601, -26238722, -14058872, 4418657, -15230761, 13947276, 10730794, -13489462, -4363670)), array(array(-2538306, 7682793, 32759013, 263109, -29984731, -7955452, -22332124, -10188635, 977108, 699994), array(-12466472, 4195084, -9211532, 550904, -15565337, 12917920, 19118110, -439841, -30534533, -14337913), array(31788461, -14507657, 4799989, 7372237, 8808585, -14747943, 9408237, -10051775, 12493932, -5409317)), array(array(-25680606, 5260744, -19235809, -6284470, -3695942, 16566087, 27218280, 2607121, 29375955, 6024730), array(842132, -2794693, -4763381, -8722815, 26332018, -12405641, 11831880, 6985184, -9940361, 2854096), array(-4847262, -7969331, 2516242, -5847713, 9695691, -7221186, 16512645, 960770, 12121869, 16648078)), array(array(-15218652, 14667096, -13336229, 2013717, 30598287, -464137, -31504922, -7882064, 20237806, 2838411), array(-19288047, 4453152, 15298546, -16178388, 22115043, -15972604, 12544294, -13470457, 1068881, -12499905), array(-9558883, -16518835, 33238498, 13506958, 30505848, -1114596, -8486907, -2630053, 12521378, 4845654)), array(array(-28198521, 10744108, -2958380, 10199664, 7759311, -13088600, 3409348, -873400, -6482306, -12885870), array(-23561822, 6230156, -20382013, 10655314, -24040585, -11621172, 10477734, -1240216, -3113227, 13974498), array(12966261, 15550616, -32038948, -1615346, 21025980, -629444, 5642325, 7188737, 18895762, 12629579))), array(array(array(14741879, -14946887, 22177208, -11721237, 1279741, 8058600, 11758140, 789443, 32195181, 3895677), array(10758205, 15755439, -4509950, 9243698, -4879422, 6879879, -2204575, -3566119, -8982069, 4429647), array(-2453894, 15725973, -20436342, -10410672, -5803908, -11040220, -7135870, -11642895, 18047436, -15281743)), array(array(-25173001, -11307165, 29759956, 11776784, -22262383, -15820455, 10993114, -12850837, -17620701, -9408468), array(21987233, 700364, -24505048, 14972008, -7774265, -5718395, 32155026, 2581431, -29958985, 8773375), array(-25568350, 454463, -13211935, 16126715, 25240068, 8594567, 20656846, 12017935, -7874389, -13920155)), array(array(6028182, 6263078, -31011806, -11301710, -818919, 2461772, -31841174, -5468042, -1721788, -2776725), array(-12278994, 16624277, 987579, -5922598, 32908203, 1248608, 7719845, -4166698, 28408820, 6816612), array(-10358094, -8237829, 19549651, -12169222, 22082623, 16147817, 20613181, 13982702, -10339570, 5067943)), array(array(-30505967, -3821767, 12074681, 13582412, -19877972, 2443951, -19719286, 12746132, 5331210, -10105944), array(30528811, 3601899, -1957090, 4619785, -27361822, -15436388, 24180793, -12570394, 27679908, -1648928), array(9402404, -13957065, 32834043, 10838634, -26580150, -13237195, 26653274, -8685565, 22611444, -12715406)), array(array(22190590, 1118029, 22736441, 15130463, -30460692, -5991321, 19189625, -4648942, 4854859, 6622139), array(-8310738, -2953450, -8262579, -3388049, -10401731, -271929, 13424426, -3567227, 26404409, 13001963), array(-31241838, -15415700, -2994250, 8939346, 11562230, -12840670, -26064365, -11621720, -15405155, 11020693)), array(array(1866042, -7949489, -7898649, -10301010, 12483315, 13477547, 3175636, -12424163, 28761762, 1406734), array(-448555, -1777666, 13018551, 3194501, -9580420, -11161737, 24760585, -4347088, 25577411, -13378680), array(-24290378, 4759345, -690653, -1852816, 2066747, 10693769, -29595790, 9884936, -9368926, 4745410)), array(array(-9141284, 6049714, -19531061, -4341411, -31260798, 9944276, -15462008, -11311852, 10931924, -11931931), array(-16561513, 14112680, -8012645, 4817318, -8040464, -11414606, -22853429, 10856641, -20470770, 13434654), array(22759489, -10073434, -16766264, -1871422, 13637442, -10168091, 1765144, -12654326, 28445307, -5364710)), array(array(29875063, 12493613, 2795536, -3786330, 1710620, 15181182, -10195717, -8788675, 9074234, 1167180), array(-26205683, 11014233, -9842651, -2635485, -26908120, 7532294, -18716888, -9535498, 3843903, 9367684), array(-10969595, -6403711, 9591134, 9582310, 11349256, 108879, 16235123, 8601684, -139197, 4242895))), array(array(array(22092954, -13191123, -2042793, -11968512, 32186753, -11517388, -6574341, 2470660, -27417366, 16625501), array(-11057722, 3042016, 13770083, -9257922, 584236, -544855, -7770857, 2602725, -27351616, 14247413), array(6314175, -10264892, -32772502, 15957557, -10157730, 168750, -8618807, 14290061, 27108877, -1180880)), array(array(-8586597, -7170966, 13241782, 10960156, -32991015, -13794596, 33547976, -11058889, -27148451, 981874), array(22833440, 9293594, -32649448, -13618667, -9136966, 14756819, -22928859, -13970780, -10479804, -16197962), array(-7768587, 3326786, -28111797, 10783824, 19178761, 14905060, 22680049, 13906969, -15933690, 3797899)), array(array(21721356, -4212746, -12206123, 9310182, -3882239, -13653110, 23740224, -2709232, 20491983, -8042152), array(9209270, -15135055, -13256557, -6167798, -731016, 15289673, 25947805, 15286587, 30997318, -6703063), array(7392032, 16618386, 23946583, -8039892, -13265164, -1533858, -14197445, -2321576, 17649998, -250080)), array(array(-9301088, -14193827, 30609526, -3049543, -25175069, -1283752, -15241566, -9525724, -2233253, 7662146), array(-17558673, 1763594, -33114336, 15908610, -30040870, -12174295, 7335080, -8472199, -3174674, 3440183), array(-19889700, -5977008, -24111293, -9688870, 10799743, -16571957, 40450, -4431835, 4862400, 1133)), array(array(-32856209, -7873957, -5422389, 14860950, -16319031, 7956142, 7258061, 311861, -30594991, -7379421), array(-3773428, -1565936, 28985340, 7499440, 24445838, 9325937, 29727763, 16527196, 18278453, 15405622), array(-4381906, 8508652, -19898366, -3674424, -5984453, 15149970, -13313598, 843523, -21875062, 13626197)), array(array(2281448, -13487055, -10915418, -2609910, 1879358, 16164207, -10783882, 3953792, 13340839, 15928663), array(31727126, -7179855, -18437503, -8283652, 2875793, -16390330, -25269894, -7014826, -23452306, 5964753), array(4100420, -5959452, -17179337, 6017714, -18705837, 12227141, -26684835, 11344144, 2538215, -7570755)), array(array(-9433605, 6123113, 11159803, -2156608, 30016280, 14966241, -20474983, 1485421, -629256, -15958862), array(-26804558, 4260919, 11851389, 9658551, -32017107, 16367492, -20205425, -13191288, 11659922, -11115118), array(26180396, 10015009, -30844224, -8581293, 5418197, 9480663, 2231568, -10170080, 33100372, -1306171)), array(array(15121113, -5201871, -10389905, 15427821, -27509937, -15992507, 21670947, 4486675, -5931810, -14466380), array(16166486, -9483733, -11104130, 6023908, -31926798, -1364923, 2340060, -16254968, -10735770, -10039824), array(28042865, -3557089, -12126526, 12259706, -3717498, -6945899, 6766453, -8689599, 18036436, 5803270))), array(array(array(-817581, 6763912, 11803561, 1585585, 10958447, -2671165, 23855391, 4598332, -6159431, -14117438), array(-31031306, -14256194, 17332029, -2383520, 31312682, -5967183, 696309, 50292, -20095739, 11763584), array(-594563, -2514283, -32234153, 12643980, 12650761, 14811489, 665117, -12613632, -19773211, -10713562)), array(array(30464590, -11262872, -4127476, -12734478, 19835327, -7105613, -24396175, 2075773, -17020157, 992471), array(18357185, -6994433, 7766382, 16342475, -29324918, 411174, 14578841, 8080033, -11574335, -10601610), array(19598397, 10334610, 12555054, 2555664, 18821899, -10339780, 21873263, 16014234, 26224780, 16452269)), array(array(-30223925, 5145196, 5944548, 16385966, 3976735, 2009897, -11377804, -7618186, -20533829, 3698650), array(14187449, 3448569, -10636236, -10810935, -22663880, -3433596, 7268410, -10890444, 27394301, 12015369), array(19695761, 16087646, 28032085, 12999827, 6817792, 11427614, 20244189, -1312777, -13259127, -3402461)), array(array(30860103, 12735208, -1888245, -4699734, -16974906, 2256940, -8166013, 12298312, -8550524, -10393462), array(-5719826, -11245325, -1910649, 15569035, 26642876, -7587760, -5789354, -15118654, -4976164, 12651793), array(-2848395, 9953421, 11531313, -5282879, 26895123, -12697089, -13118820, -16517902, 9768698, -2533218)), array(array(-24719459, 1894651, -287698, -4704085, 15348719, -8156530, 32767513, 12765450, 4940095, 10678226), array(18860224, 15980149, -18987240, -1562570, -26233012, -11071856, -7843882, 13944024, -24372348, 16582019), array(-15504260, 4970268, -29893044, 4175593, -20993212, -2199756, -11704054, 15444560, -11003761, 7989037)), array(array(31490452, 5568061, -2412803, 2182383, -32336847, 4531686, -32078269, 6200206, -19686113, -14800171), array(-17308668, -15879940, -31522777, -2831, -32887382, 16375549, 8680158, -16371713, 28550068, -6857132), array(-28126887, -5688091, 16837845, -1820458, -6850681, 12700016, -30039981, 4364038, 1155602, 5988841)), array(array(21890435, -13272907, -12624011, 12154349, -7831873, 15300496, 23148983, -4470481, 24618407, 8283181), array(-33136107, -10512751, 9975416, 6841041, -31559793, 16356536, 3070187, -7025928, 1466169, 10740210), array(-1509399, -15488185, -13503385, -10655916, 32799044, 909394, -13938903, -5779719, -32164649, -15327040)), array(array(3960823, -14267803, -28026090, -15918051, -19404858, 13146868, 15567327, 951507, -3260321, -573935), array(24740841, 5052253, -30094131, 8961361, 25877428, 6165135, -24368180, 14397372, -7380369, -6144105), array(-28888365, 3510803, -28103278, -1158478, -11238128, -10631454, -15441463, -14453128, -1625486, -6494814))), array(array(array(793299, -9230478, 8836302, -6235707, -27360908, -2369593, 33152843, -4885251, -9906200, -621852), array(5666233, 525582, 20782575, -8038419, -24538499, 14657740, 16099374, 1468826, -6171428, -15186581), array(-4859255, -3779343, -2917758, -6748019, 7778750, 11688288, -30404353, -9871238, -1558923, -9863646)), array(array(10896332, -7719704, 824275, 472601, -19460308, 3009587, 25248958, 14783338, -30581476, -15757844), array(10566929, 12612572, -31944212, 11118703, -12633376, 12362879, 21752402, 8822496, 24003793, 14264025), array(27713862, -7355973, -11008240, 9227530, 27050101, 2504721, 23886875, -13117525, 13958495, -5732453)), array(array(-23481610, 4867226, -27247128, 3900521, 29838369, -8212291, -31889399, -10041781, 7340521, -15410068), array(4646514, -8011124, -22766023, -11532654, 23184553, 8566613, 31366726, -1381061, -15066784, -10375192), array(-17270517, 12723032, -16993061, 14878794, 21619651, -6197576, 27584817, 3093888, -8843694, 3849921)), array(array(-9064912, 2103172, 25561640, -15125738, -5239824, 9582958, 32477045, -9017955, 5002294, -15550259), array(-12057553, -11177906, 21115585, -13365155, 8808712, -12030708, 16489530, 13378448, -25845716, 12741426), array(-5946367, 10645103, -30911586, 15390284, -3286982, -7118677, 24306472, 15852464, 28834118, -7646072)), array(array(-17335748, -9107057, -24531279, 9434953, -8472084, -583362, -13090771, 455841, 20461858, 5491305), array(13669248, -16095482, -12481974, -10203039, -14569770, -11893198, -24995986, 11293807, -28588204, -9421832), array(28497928, 6272777, -33022994, 14470570, 8906179, -1225630, 18504674, -14165166, 29867745, -8795943)), array(array(-16207023, 13517196, -27799630, -13697798, 24009064, -6373891, -6367600, -13175392, 22853429, -4012011), array(24191378, 16712145, -13931797, 15217831, 14542237, 1646131, 18603514, -11037887, 12876623, -2112447), array(17902668, 4518229, -411702, -2829247, 26878217, 5258055, -12860753, 608397, 16031844, 3723494)), array(array(-28632773, 12763728, -20446446, 7577504, 33001348, -13017745, 17558842, -7872890, 23896954, -4314245), array(-20005381, -12011952, 31520464, 605201, 2543521, 5991821, -2945064, 7229064, -9919646, -8826859), array(28816045, 298879, -28165016, -15920938, 19000928, -1665890, -12680833, -2949325, -18051778, -2082915)), array(array(16000882, -344896, 3493092, -11447198, -29504595, -13159789, 12577740, 16041268, -19715240, 7847707), array(10151868, 10572098, 27312476, 7922682, 14825339, 4723128, -32855931, -6519018, -10020567, 3852848), array(-11430470, 15697596, -21121557, -4420647, 5386314, 15063598, 16514493, -15932110, 29330899, -15076224))), array(array(array(-25499735, -4378794, -15222908, -6901211, 16615731, 2051784, 3303702, 15490, -27548796, 12314391), array(15683520, -6003043, 18109120, -9980648, 15337968, -5997823, -16717435, 15921866, 16103996, -3731215), array(-23169824, -10781249, 13588192, -1628807, -3798557, -1074929, -19273607, 5402699, -29815713, -9841101)), array(array(23190676, 2384583, -32714340, 3462154, -29903655, -1529132, -11266856, 8911517, -25205859, 2739713), array(21374101, -3554250, -33524649, 9874411, 15377179, 11831242, -33529904, 6134907, 4931255, 11987849), array(-7732, -2978858, -16223486, 7277597, 105524, -322051, -31480539, 13861388, -30076310, 10117930)), array(array(-29501170, -10744872, -26163768, 13051539, -25625564, 5089643, -6325503, 6704079, 12890019, 15728940), array(-21972360, -11771379, -951059, -4418840, 14704840, 2695116, 903376, -10428139, 12885167, 8311031), array(-17516482, 5352194, 10384213, -13811658, 7506451, 13453191, 26423267, 4384730, 1888765, -5435404)), array(array(-25817338, -3107312, -13494599, -3182506, 30896459, -13921729, -32251644, -12707869, -19464434, -3340243), array(-23607977, -2665774, -526091, 4651136, 5765089, 4618330, 6092245, 14845197, 17151279, -9854116), array(-24830458, -12733720, -15165978, 10367250, -29530908, -265356, 22825805, -7087279, -16866484, 16176525)), array(array(-23583256, 6564961, 20063689, 3798228, -4740178, 7359225, 2006182, -10363426, -28746253, -10197509), array(-10626600, -4486402, -13320562, -5125317, 3432136, -6393229, 23632037, -1940610, 32808310, 1099883), array(15030977, 5768825, -27451236, -2887299, -6427378, -15361371, -15277896, -6809350, 2051441, -15225865)), array(array(-3362323, -7239372, 7517890, 9824992, 23555850, 295369, 5148398, -14154188, -22686354, 16633660), array(4577086, -16752288, 13249841, -15304328, 19958763, -14537274, 18559670, -10759549, 8402478, -9864273), array(-28406330, -1051581, -26790155, -907698, -17212414, -11030789, 9453451, -14980072, 17983010, 9967138)), array(array(-25762494, 6524722, 26585488, 9969270, 24709298, 1220360, -1677990, 7806337, 17507396, 3651560), array(-10420457, -4118111, 14584639, 15971087, -15768321, 8861010, 26556809, -5574557, -18553322, -11357135), array(2839101, 14284142, 4029895, 3472686, 14402957, 12689363, -26642121, 8459447, -5605463, -7621941)), array(array(-4839289, -3535444, 9744961, 2871048, 25113978, 3187018, -25110813, -849066, 17258084, -7977739), array(18164541, -10595176, -17154882, -1542417, 19237078, -9745295, 23357533, -15217008, 26908270, 12150756), array(-30264870, -7647865, 5112249, -7036672, -1499807, -6974257, 43168, -5537701, -32302074, 16215819))), array(array(array(-6898905, 9824394, -12304779, -4401089, -31397141, -6276835, 32574489, 12532905, -7503072, -8675347), array(-27343522, -16515468, -27151524, -10722951, 946346, 16291093, 254968, 7168080, 21676107, -1943028), array(21260961, -8424752, -16831886, -11920822, -23677961, 3968121, -3651949, -6215466, -3556191, -7913075)), array(array(16544754, 13250366, -16804428, 15546242, -4583003, 12757258, -2462308, -8680336, -18907032, -9662799), array(-2415239, -15577728, 18312303, 4964443, -15272530, -12653564, 26820651, 16690659, 25459437, -4564609), array(-25144690, 11425020, 28423002, -11020557, -6144921, -15826224, 9142795, -2391602, -6432418, -1644817)), array(array(-23104652, 6253476, 16964147, -3768872, -25113972, -12296437, -27457225, -16344658, 6335692, 7249989), array(-30333227, 13979675, 7503222, -12368314, -11956721, -4621693, -30272269, 2682242, 25993170, -12478523), array(4364628, 5930691, 32304656, -10044554, -8054781, 15091131, 22857016, -10598955, 31820368, 15075278)), array(array(31879134, -8918693, 17258761, 90626, -8041836, -4917709, 24162788, -9650886, -17970238, 12833045), array(19073683, 14851414, -24403169, -11860168, 7625278, 11091125, -19619190, 2074449, -9413939, 14905377), array(24483667, -11935567, -2518866, -11547418, -1553130, 15355506, -25282080, 9253129, 27628530, -7555480)), array(array(17597607, 8340603, 19355617, 552187, 26198470, -3176583, 4593324, -9157582, -14110875, 15297016), array(510886, 14337390, -31785257, 16638632, 6328095, 2713355, -20217417, -11864220, 8683221, 2921426), array(18606791, 11874196, 27155355, -5281482, -24031742, 6265446, -25178240, -1278924, 4674690, 13890525)), array(array(13609624, 13069022, -27372361, -13055908, 24360586, 9592974, 14977157, 9835105, 4389687, 288396), array(9922506, -519394, 13613107, 5883594, -18758345, -434263, -12304062, 8317628, 23388070, 16052080), array(12720016, 11937594, -31970060, -5028689, 26900120, 8561328, -20155687, -11632979, -14754271, -10812892)), array(array(15961858, 14150409, 26716931, -665832, -22794328, 13603569, 11829573, 7467844, -28822128, 929275), array(11038231, -11582396, -27310482, -7316562, -10498527, -16307831, -23479533, -9371869, -21393143, 2465074), array(20017163, -4323226, 27915242, 1529148, 12396362, 15675764, 13817261, -9658066, 2463391, -4622140)), array(array(-16358878, -12663911, -12065183, 4996454, -1256422, 1073572, 9583558, 12851107, 4003896, 12673717), array(-1731589, -15155870, -3262930, 16143082, 19294135, 13385325, 14741514, -9103726, 7903886, 2348101), array(24536016, -16515207, 12715592, -3862155, 1511293, 10047386, -3842346, -7129159, -28377538, 10048127))), array(array(array(-12622226, -6204820, 30718825, 2591312, -10617028, 12192840, 18873298, -7297090, -32297756, 15221632), array(-26478122, -11103864, 11546244, -1852483, 9180880, 7656409, -21343950, 2095755, 29769758, 6593415), array(-31994208, -2907461, 4176912, 3264766, 12538965, -868111, 26312345, -6118678, 30958054, 8292160)), array(array(31429822, -13959116, 29173532, 15632448, 12174511, -2760094, 32808831, 3977186, 26143136, -3148876), array(22648901, 1402143, -22799984, 13746059, 7936347, 365344, -8668633, -1674433, -3758243, -2304625), array(-15491917, 8012313, -2514730, -12702462, -23965846, -10254029, -1612713, -1535569, -16664475, 8194478)), array(array(27338066, -7507420, -7414224, 10140405, -19026427, -6589889, 27277191, 8855376, 28572286, 3005164), array(26287124, 4821776, 25476601, -4145903, -3764513, -15788984, -18008582, 1182479, -26094821, -13079595), array(-7171154, 3178080, 23970071, 6201893, -17195577, -4489192, -21876275, -13982627, 32208683, -1198248)), array(array(-16657702, 2817643, -10286362, 14811298, 6024667, 13349505, -27315504, -10497842, -27672585, -11539858), array(15941029, -9405932, -21367050, 8062055, 31876073, -238629, -15278393, -1444429, 15397331, -4130193), array(8934485, -13485467, -23286397, -13423241, -32446090, 14047986, 31170398, -1441021, -27505566, 15087184)), array(array(-18357243, -2156491, 24524913, -16677868, 15520427, -6360776, -15502406, 11461896, 16788528, -5868942), array(-1947386, 16013773, 21750665, 3714552, -17401782, -16055433, -3770287, -10323320, 31322514, -11615635), array(21426655, -5650218, -13648287, -5347537, -28812189, -4920970, -18275391, -14621414, 13040862, -12112948)), array(array(11293895, 12478086, -27136401, 15083750, -29307421, 14748872, 14555558, -13417103, 1613711, 4896935), array(-25894883, 15323294, -8489791, -8057900, 25967126, -13425460, 2825960, -4897045, -23971776, -11267415), array(-15924766, -5229880, -17443532, 6410664, 3622847, 10243618, 20615400, 12405433, -23753030, -8436416)), array(array(-7091295, 12556208, -20191352, 9025187, -17072479, 4333801, 4378436, 2432030, 23097949, -566018), array(4565804, -16025654, 20084412, -7842817, 1724999, 189254, 24767264, 10103221, -18512313, 2424778), array(366633, -11976806, 8173090, -6890119, 30788634, 5745705, -7168678, 1344109, -3642553, 12412659)), array(array(-24001791, 7690286, 14929416, -168257, -32210835, -13412986, 24162697, -15326504, -3141501, 11179385), array(18289522, -14724954, 8056945, 16430056, -21729724, 7842514, -6001441, -1486897, -18684645, -11443503), array(476239, 6601091, -6152790, -9723375, 17503545, -4863900, 27672959, 13403813, 11052904, 5219329))), array(array(array(20678546, -8375738, -32671898, 8849123, -5009758, 14574752, 31186971, -3973730, 9014762, -8579056), array(-13644050, -10350239, -15962508, 5075808, -1514661, -11534600, -33102500, 9160280, 8473550, -3256838), array(24900749, 14435722, 17209120, -15292541, -22592275, 9878983, -7689309, -16335821, -24568481, 11788948)), array(array(-3118155, -11395194, -13802089, 14797441, 9652448, -6845904, -20037437, 10410733, -24568470, -1458691), array(-15659161, 16736706, -22467150, 10215878, -9097177, 7563911, 11871841, -12505194, -18513325, 8464118), array(-23400612, 8348507, -14585951, -861714, -3950205, -6373419, 14325289, 8628612, 33313881, -8370517)), array(array(-20186973, -4967935, 22367356, 5271547, -1097117, -4788838, -24805667, -10236854, -8940735, -5818269), array(-6948785, -1795212, -32625683, -16021179, 32635414, -7374245, 15989197, -12838188, 28358192, -4253904), array(-23561781, -2799059, -32351682, -1661963, -9147719, 10429267, -16637684, 4072016, -5351664, 5596589)), array(array(-28236598, -3390048, 12312896, 6213178, 3117142, 16078565, 29266239, 2557221, 1768301, 15373193), array(-7243358, -3246960, -4593467, -7553353, -127927, -912245, -1090902, -4504991, -24660491, 3442910), array(-30210571, 5124043, 14181784, 8197961, 18964734, -11939093, 22597931, 7176455, -18585478, 13365930)), array(array(-7877390, -1499958, 8324673, 4690079, 6261860, 890446, 24538107, -8570186, -9689599, -3031667), array(25008904, -10771599, -4305031, -9638010, 16265036, 15721635, 683793, -11823784, 15723479, -15163481), array(-9660625, 12374379, -27006999, -7026148, -7724114, -12314514, 11879682, 5400171, 519526, -1235876)), array(array(22258397, -16332233, -7869817, 14613016, -22520255, -2950923, -20353881, 7315967, 16648397, 7605640), array(-8081308, -8464597, -8223311, 9719710, 19259459, -15348212, 23994942, -5281555, -9468848, 4763278), array(-21699244, 9220969, -15730624, 1084137, -25476107, -2852390, 31088447, -7764523, -11356529, 728112)), array(array(26047220, -11751471, -6900323, -16521798, 24092068, 9158119, -4273545, -12555558, -29365436, -5498272), array(17510331, -322857, 5854289, 8403524, 17133918, -3112612, -28111007, 12327945, 10750447, 10014012), array(-10312768, 3936952, 9156313, -8897683, 16498692, -994647, -27481051, -666732, 3424691, 7540221)), array(array(30322361, -6964110, 11361005, -4143317, 7433304, 4989748, -7071422, -16317219, -9244265, 15258046), array(13054562, -2779497, 19155474, 469045, -12482797, 4566042, 5631406, 2711395, 1062915, -5136345), array(-19240248, -11254599, -29509029, -7499965, -5835763, 13005411, -6066489, 12194497, 32960380, 1459310))), array(array(array(19852034, 7027924, 23669353, 10020366, 8586503, -6657907, 394197, -6101885, 18638003, -11174937), array(31395534, 15098109, 26581030, 8030562, -16527914, -5007134, 9012486, -7584354, -6643087, -5442636), array(-9192165, -2347377, -1997099, 4529534, 25766844, 607986, -13222, 9677543, -32294889, -6456008)), array(array(-2444496, -149937, 29348902, 8186665, 1873760, 12489863, -30934579, -7839692, -7852844, -8138429), array(-15236356, -15433509, 7766470, 746860, 26346930, -10221762, -27333451, 10754588, -9431476, 5203576), array(31834314, 14135496, -770007, 5159118, 20917671, -16768096, -7467973, -7337524, 31809243, 7347066)), array(array(-9606723, -11874240, 20414459, 13033986, 13716524, -11691881, 19797970, -12211255, 15192876, -2087490), array(-12663563, -2181719, 1168162, -3804809, 26747877, -14138091, 10609330, 12694420, 33473243, -13382104), array(33184999, 11180355, 15832085, -11385430, -1633671, 225884, 15089336, -11023903, -6135662, 14480053)), array(array(31308717, -5619998, 31030840, -1897099, 15674547, -6582883, 5496208, 13685227, 27595050, 8737275), array(-20318852, -15150239, 10933843, -16178022, 8335352, -7546022, -31008351, -12610604, 26498114, 66511), array(22644454, -8761729, -16671776, 4884562, -3105614, -13559366, 30540766, -4286747, -13327787, -7515095)), array(array(-28017847, 9834845, 18617207, -2681312, -3401956, -13307506, 8205540, 13585437, -17127465, 15115439), array(23711543, -672915, 31206561, -8362711, 6164647, -9709987, -33535882, -1426096, 8236921, 16492939), array(-23910559, -13515526, -26299483, -4503841, 25005590, -7687270, 19574902, 10071562, 6708380, -6222424)), array(array(2101391, -4930054, 19702731, 2367575, -15427167, 1047675, 5301017, 9328700, 29955601, -11678310), array(3096359, 9271816, -21620864, -15521844, -14847996, -7592937, -25892142, -12635595, -9917575, 6216608), array(-32615849, 338663, -25195611, 2510422, -29213566, -13820213, 24822830, -6146567, -26767480, 7525079)), array(array(-23066649, -13985623, 16133487, -7896178, -3389565, 778788, -910336, -2782495, -19386633, 11994101), array(21691500, -13624626, -641331, -14367021, 3285881, -3483596, -25064666, 9718258, -7477437, 13381418), array(18445390, -4202236, 14979846, 11622458, -1727110, -3582980, 23111648, -6375247, 28535282, 15779576)), array(array(30098053, 3089662, -9234387, 16662135, -21306940, 11308411, -14068454, 12021730, 9955285, -16303356), array(9734894, -14576830, -7473633, -9138735, 2060392, 11313496, -18426029, 9924399, 20194861, 13380996), array(-26378102, -7965207, -22167821, 15789297, -18055342, -6168792, -1984914, 15707771, 26342023, 10146099))), array(array(array(-26016874, -219943, 21339191, -41388, 19745256, -2878700, -29637280, 2227040, 21612326, -545728), array(-13077387, 1184228, 23562814, -5970442, -20351244, -6348714, 25764461, 12243797, -20856566, 11649658), array(-10031494, 11262626, 27384172, 2271902, 26947504, -15997771, 39944, 6114064, 33514190, 2333242)), array(array(-21433588, -12421821, 8119782, 7219913, -21830522, -9016134, -6679750, -12670638, 24350578, -13450001), array(-4116307, -11271533, -23886186, 4843615, -30088339, 690623, -31536088, -10406836, 8317860, 12352766), array(18200138, -14475911, -33087759, -2696619, -23702521, -9102511, -23552096, -2287550, 20712163, 6719373)), array(array(26656208, 6075253, -7858556, 1886072, -28344043, 4262326, 11117530, -3763210, 26224235, -3297458), array(-17168938, -14854097, -3395676, -16369877, -19954045, 14050420, 21728352, 9493610, 18620611, -16428628), array(-13323321, 13325349, 11432106, 5964811, 18609221, 6062965, -5269471, -9725556, -30701573, -16479657)), array(array(-23860538, -11233159, 26961357, 1640861, -32413112, -16737940, 12248509, -5240639, 13735342, 1934062), array(25089769, 6742589, 17081145, -13406266, 21909293, -16067981, -15136294, -3765346, -21277997, 5473616), array(31883677, -7961101, 1083432, -11572403, 22828471, 13290673, -7125085, 12469656, 29111212, -5451014)), array(array(24244947, -15050407, -26262976, 2791540, -14997599, 16666678, 24367466, 6388839, -10295587, 452383), array(-25640782, -3417841, 5217916, 16224624, 19987036, -4082269, -24236251, -5915248, 15766062, 8407814), array(-20406999, 13990231, 15495425, 16395525, 5377168, 15166495, -8917023, -4388953, -8067909, 2276718)), array(array(30157918, 12924066, -17712050, 9245753, 19895028, 3368142, -23827587, 5096219, 22740376, -7303417), array(2041139, -14256350, 7783687, 13876377, -25946985, -13352459, 24051124, 13742383, -15637599, 13295222), array(33338237, -8505733, 12532113, 7977527, 9106186, -1715251, -17720195, -4612972, -4451357, -14669444)), array(array(-20045281, 5454097, -14346548, 6447146, 28862071, 1883651, -2469266, -4141880, 7770569, 9620597), array(23208068, 7979712, 33071466, 8149229, 1758231, -10834995, 30945528, -1694323, -33502340, -14767970), array(1439958, -16270480, -1079989, -793782, 4625402, 10647766, -5043801, 1220118, 30494170, -11440799)), array(array(-5037580, -13028295, -2970559, -3061767, 15640974, -6701666, -26739026, 926050, -1684339, -13333647), array(13908495, -3549272, 30919928, -6273825, -21521863, 7989039, 9021034, 9078865, 3353509, 4033511), array(-29663431, -15113610, 32259991, -344482, 24295849, -12912123, 23161163, 8839127, 27485041, 7356032))), array(array(array(9661027, 705443, 11980065, -5370154, -1628543, 14661173, -6346142, 2625015, 28431036, -16771834), array(-23839233, -8311415, -25945511, 7480958, -17681669, -8354183, -22545972, 14150565, 15970762, 4099461), array(29262576, 16756590, 26350592, -8793563, 8529671, -11208050, 13617293, -9937143, 11465739, 8317062)), array(array(-25493081, -6962928, 32500200, -9419051, -23038724, -2302222, 14898637, 3848455, 20969334, -5157516), array(-20384450, -14347713, -18336405, 13884722, -33039454, 2842114, -21610826, -3649888, 11177095, 14989547), array(-24496721, -11716016, 16959896, 2278463, 12066309, 10137771, 13515641, 2581286, -28487508, 9930240)), array(array(-17751622, -2097826, 16544300, -13009300, -15914807, -14949081, 18345767, -13403753, 16291481, -5314038), array(-33229194, 2553288, 32678213, 9875984, 8534129, 6889387, -9676774, 6957617, 4368891, 9788741), array(16660756, 7281060, -10830758, 12911820, 20108584, -8101676, -21722536, -8613148, 16250552, -11111103)), array(array(-19765507, 2390526, -16551031, 14161980, 1905286, 6414907, 4689584, 10604807, -30190403, 4782747), array(-1354539, 14736941, -7367442, -13292886, 7710542, -14155590, -9981571, 4383045, 22546403, 437323), array(31665577, -12180464, -16186830, 1491339, -18368625, 3294682, 27343084, 2786261, -30633590, -14097016)), array(array(-14467279, -683715, -33374107, 7448552, 19294360, 14334329, -19690631, 2355319, -19284671, -6114373), array(15121312, -15796162, 6377020, -6031361, -10798111, -12957845, 18952177, 15496498, -29380133, 11754228), array(-2637277, -13483075, 8488727, -14303896, 12728761, -1622493, 7141596, 11724556, 22761615, -10134141)), array(array(16918416, 11729663, -18083579, 3022987, -31015732, -13339659, -28741185, -12227393, 32851222, 11717399), array(11166634, 7338049, -6722523, 4531520, -29468672, -7302055, 31474879, 3483633, -1193175, -4030831), array(-185635, 9921305, 31456609, -13536438, -12013818, 13348923, 33142652, 6546660, -19985279, -3948376)), array(array(-32460596, 11266712, -11197107, -7899103, 31703694, 3855903, -8537131, -12833048, -30772034, -15486313), array(-18006477, 12709068, 3991746, -6479188, -21491523, -10550425, -31135347, -16049879, 10928917, 3011958), array(-6957757, -15594337, 31696059, 334240, 29576716, 14796075, -30831056, -12805180, 18008031, 10258577)), array(array(-22448644, 15655569, 7018479, -4410003, -30314266, -1201591, -1853465, 1367120, 25127874, 6671743), array(29701166, -14373934, -10878120, 9279288, -17568, 13127210, 21382910, 11042292, 25838796, 4642684), array(-20430234, 14955537, -24126347, 8124619, -5369288, -5990470, 30468147, -13900640, 18423289, 4177476))));
        /**
         * See: libsodium's crypto_core/curve25519/ref10/base2.h
         *
         * @var array<int, array<int, array<int, int>>> basically int[8][3]
         */
        protected static $base2 = array(array(array(25967493, -14356035, 29566456, 3660896, -12694345, 4014787, 27544626, -11754271, -6079156, 2047605), array(-12545711, 934262, -2722910, 3049990, -727428, 9406986, 12720692, 5043384, 19500929, -15469378), array(-8738181, 4489570, 9688441, -14785194, 10184609, -12363380, 29287919, 11864899, -24514362, -4438546)), array(array(15636291, -9688557, 24204773, -7912398, 616977, -16685262, 27787600, -14772189, 28944400, -1550024), array(16568933, 4717097, -11556148, -1102322, 15682896, -11807043, 16354577, -11775962, 7689662, 11199574), array(30464156, -5976125, -11779434, -15670865, 23220365, 15915852, 7512774, 10017326, -17749093, -9920357)), array(array(10861363, 11473154, 27284546, 1981175, -30064349, 12577861, 32867885, 14515107, -15438304, 10819380), array(4708026, 6336745, 20377586, 9066809, -11272109, 6594696, -25653668, 12483688, -12668491, 5581306), array(19563160, 16186464, -29386857, 4097519, 10237984, -4348115, 28542350, 13850243, -23678021, -15815942)), array(array(5153746, 9909285, 1723747, -2777874, 30523605, 5516873, 19480852, 5230134, -23952439, -15175766), array(-30269007, -3463509, 7665486, 10083793, 28475525, 1649722, 20654025, 16520125, 30598449, 7715701), array(28881845, 14381568, 9657904, 3680757, -20181635, 7843316, -31400660, 1370708, 29794553, -1409300)), array(array(-22518993, -6692182, 14201702, -8745502, -23510406, 8844726, 18474211, -1361450, -13062696, 13821877), array(-6455177, -7839871, 3374702, -4740862, -27098617, -10571707, 31655028, -7212327, 18853322, -14220951), array(4566830, -12963868, -28974889, -12240689, -7602672, -2830569, -8514358, -10431137, 2207753, -3209784)), array(array(-25154831, -4185821, 29681144, 7868801, -6854661, -9423865, -12437364, -663000, -31111463, -16132436), array(25576264, -2703214, 7349804, -11814844, 16472782, 9300885, 3844789, 15725684, 171356, 6466918), array(23103977, 13316479, 9739013, -16149481, 817875, -15038942, 8965339, -14088058, -30714912, 16193877)), array(array(-33521811, 3180713, -2394130, 14003687, -16903474, -16270840, 17238398, 4729455, -18074513, 9256800), array(-25182317, -4174131, 32336398, 5036987, -21236817, 11360617, 22616405, 9761698, -19827198, 630305), array(-13720693, 2639453, -24237460, -7406481, 9494427, -5774029, -6554551, -15960994, -2449256, -14291300)), array(array(-3151181, -5046075, 9282714, 6866145, -31907062, -863023, -18940575, 15033784, 25105118, -7894876), array(-24326370, 15950226, -31801215, -14592823, -11662737, -5090925, 1573892, -2625887, 2198790, -15804619), array(-3099351, 10324967, -2241613, 7453183, -5446979, -2735503, -13812022, -16236442, -32461234, -12290683)));
        /**
         * 37095705934669439343138083508754565189542113879843219016388785533085940283555
         *
         * @var array<int, int>
         */
        protected static $d = array(-10913610, 13857413, -15372611, 6949391, 114729, -8787816, -6275908, -3247719, -18696448, -12055116);
        /**
         * 2 * d = 16295367250680780974490674513165176452449235426866156013048779062215315747161
         *
         * @var array<int, int>
         */
        protected static $d2 = array(-21827239, -5839606, -30745221, 13898782, 229458, 15978800, -12551817, -6495438, 29715968, 9444199);
        /**
         * sqrt(-1)
         *
         * @var array<int, int>
         */
        protected static $sqrtm1 = array(-32595792, -7943725, 9377950, 3500415, 12389472, -272473, -25146209, -2005654, 326686, 11406482);
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519
     *
     * Implements Curve25519 core functions
     *
     * Based on the ref10 curve25519 code provided by libsodium
     *
     * @ref https://github.com/jedisct1/libsodium/blob/master/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c
     */
    abstract class ParagonIE_Sodium_Core32_Curve25519 extends \ParagonIE_Sodium_Core32_Curve25519_H
    {
        /**
         * Get a field element of size 10 with a value of 0
         *
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_0()
        {
        }
        /**
         * Get a field element of size 10 with a value of 1
         *
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_1()
        {
        }
        /**
         * Add two field elements.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_add(\ParagonIE_Sodium_Core32_Curve25519_Fe $f, \ParagonIE_Sodium_Core32_Curve25519_Fe $g)
        {
        }
        /**
         * Constant-time conditional move.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $g
         * @param int $b
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_cmov(\ParagonIE_Sodium_Core32_Curve25519_Fe $f, \ParagonIE_Sodium_Core32_Curve25519_Fe $g, $b = 0)
        {
        }
        /**
         * Create a copy of a field element.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public static function fe_copy(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * Give: 32-byte string.
         * Receive: A field element object to use for internal calculations.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_frombytes($s)
        {
        }
        /**
         * Convert a field element to a byte string.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $h
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_tobytes(\ParagonIE_Sodium_Core32_Curve25519_Fe $h)
        {
        }
        /**
         * Is a field element negative? (1 = yes, 0 = no. Used in calculations.)
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return int
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_isnegative(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * Returns 0 if this field element results in all NUL bytes.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_isnonzero(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * Multiply two field elements
         *
         * h = f * g
         *
         * @internal You should not use this directly from another application
         *
         * @security Is multiplication a source of timing leaks? If so, can we do
         *           anything to prevent that from happening?
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_mul(\ParagonIE_Sodium_Core32_Curve25519_Fe $f, \ParagonIE_Sodium_Core32_Curve25519_Fe $g)
        {
        }
        /**
         * Get the negative values for each piece of the field element.
         *
         * h = -f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_neg(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * Square a field element
         *
         * h = f * f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_sq(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * Square and double a field element
         *
         * h = 2 * f * f
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_sq2(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $Z
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_invert(\ParagonIE_Sodium_Core32_Curve25519_Fe $Z)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @ref https://github.com/jedisct1/libsodium/blob/68564326e1e9dc57ef03746f85734232d20ca6fb/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c#L1054-L1106
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $z
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fe_pow22523(\ParagonIE_Sodium_Core32_Curve25519_Fe $z)
        {
        }
        /**
         * Subtract two field elements.
         *
         * h = f - g
         *
         * Preconditions:
         * |f| bounded by 1.1*2^25,1.1*2^24,1.1*2^25,1.1*2^24,etc.
         * |g| bounded by 1.1*2^25,1.1*2^24,1.1*2^25,1.1*2^24,etc.
         *
         * Postconditions:
         * |h| bounded by 1.1*2^26,1.1*2^25,1.1*2^26,1.1*2^25,etc.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $g
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedMethodCall
         * @psalm-suppress MixedTypeCoercion
         */
        public static function fe_sub(\ParagonIE_Sodium_Core32_Curve25519_Fe $f, \ParagonIE_Sodium_Core32_Curve25519_Fe $g)
        {
        }
        /**
         * Add two group elements.
         *
         * r = p + q
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Cached $q
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_add(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core32_Curve25519_Ge_Cached $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @ref https://github.com/jedisct1/libsodium/blob/157c4a80c13b117608aeae12178b2d38825f9f8f/src/libsodium/crypto_core/curve25519/ref10/curve25519_ref10.c#L1185-L1215
         * @param string $a
         * @return array<int, mixed>
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayOffset
         */
        public static function slide($a)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_frombytes_negate_vartime($s)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $R
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $q
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_madd(\ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $R, \ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $R
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $q
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_msub(\ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $R, \ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $q)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P2
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p1p1_to_p2(\ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p1p1_to_p3(\ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P2
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p2_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P2 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p2_dbl(\ParagonIE_Sodium_Core32_Curve25519_Ge_P2 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p3_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_Cached
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p3_to_cached(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P2
         */
        public static function ge_p3_to_p2(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $h
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p3_tobytes(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $h)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_p3_dbl(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p)
        {
        }
        /**
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_precomp_0()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $b
         * @param int $c
         * @return int
         * @psalm-suppress MixedReturnStatement
         */
        public static function equal($b, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string|int $char
         * @return int (1 = yes, 0 = no)
         * @throws SodiumException
         * @throws TypeError
         */
        public static function negative($char)
        {
        }
        /**
         * Conditional move
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $t
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $u
         * @param int $b
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp
         * @throws SodiumException
         * @throws TypeError
         */
        public static function cmov(\ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $t, \ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp $u, $b)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $pos
         * @param int $b
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArrayOffset
         * @psalm-suppress MixedArgument
         */
        public static function ge_select($pos = 0, $b = 0)
        {
        }
        /**
         * Subtract two group elements.
         *
         * r = p - q
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_Cached $q
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_sub(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $p, \ParagonIE_Sodium_Core32_Curve25519_Ge_Cached $q)
        {
        }
        /**
         * Convert a group element to a byte string.
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P2 $h
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_tobytes(\ParagonIE_Sodium_Core32_Curve25519_Ge_P2 $h)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $A
         * @param string $b
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P2
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         */
        public static function ge_double_scalarmult_vartime($a, \ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $A, $b)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P3
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_scalarmult_base($a)
        {
        }
        /**
         * Calculates (ab + c) mod l
         * where l = 2^252 + 27742317777372353535851937790883648493
         *
         * @internal You should not use this directly from another application
         *
         * @param string $a
         * @param string $b
         * @param string $c
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sc_muladd($a, $b, $c)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $s
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sc_reduce($s)
        {
        }
        /**
         * multiply by the order of the main subgroup l = 2^252+27742317777372353535851937790883648493
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $A
         * @return ParagonIE_Sodium_Core32_Curve25519_Ge_P3
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ge_mul_l(\ParagonIE_Sodium_Core32_Curve25519_Ge_P3 $A)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Fe
     *
     * This represents a Field Element
     */
    class ParagonIE_Sodium_Core32_Curve25519_Fe implements \ArrayAccess
    {
        /**
         * @var array<int, ParagonIE_Sodium_Core32_Int32>
         */
        protected $container = array();
        /**
         * @var int
         */
        protected $size = 10;
        /**
         * @internal You should not use this directly from another application
         *
         * @param array<int, ParagonIE_Sodium_Core32_Int32> $array
         * @param bool $save_indexes
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromArray($array, $save_indexes = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param array<int, int> $array
         * @param bool $save_indexes
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromIntArray($array, $save_indexes = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param mixed $offset
         * @param mixed $value
         * @return void
         * @throws SodiumException
         * @throws TypeError
         */
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param mixed $offset
         * @return bool
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetExists($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param mixed $offset
         * @return void
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param mixed $offset
         * @return ParagonIE_Sodium_Core32_Int32
         * @psalm-suppress MixedArrayOffset
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return array
         */
        public function __debugInfo()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Ge_Cached
     */
    class ParagonIE_Sodium_Core32_Curve25519_Ge_Cached
    {
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $YplusX;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $YminusX;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $T2d;
        /**
         * ParagonIE_Sodium_Core32_Curve25519_Ge_Cached constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $YplusX
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $YminusX
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $Z
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $T2d
         */
        public function __construct(\ParagonIE_Sodium_Core32_Curve25519_Fe $YplusX = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $YminusX = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $Z = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $T2d = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
     */
    class ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1
    {
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $T;
        /**
         * ParagonIE_Sodium_Core32_Curve25519_Ge_P1p1 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $z
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $t
         *
         * @throws SodiumException
         * @throws TypeError
         */
        public function __construct(\ParagonIE_Sodium_Core32_Curve25519_Fe $x = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $y = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $z = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $t = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Ge_P2
     */
    class ParagonIE_Sodium_Core32_Curve25519_Ge_P2
    {
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Z;
        /**
         * ParagonIE_Sodium_Core32_Curve25519_Ge_P2 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $z
         */
        public function __construct(\ParagonIE_Sodium_Core32_Curve25519_Fe $x = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $y = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $z = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Ge_P3
     */
    class ParagonIE_Sodium_Core32_Curve25519_Ge_P3
    {
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $X;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Y;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $Z;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $T;
        /**
         * ParagonIE_Sodium_Core32_Curve25519_Ge_P3 constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $x
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $y
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $z
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe|null $t
         */
        public function __construct(\ParagonIE_Sodium_Core32_Curve25519_Fe $x = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $y = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $z = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $t = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp
     */
    class ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp
    {
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $yplusx;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $yminusx;
        /**
         * @var ParagonIE_Sodium_Core32_Curve25519_Fe
         */
        public $xy2d;
        /**
         * ParagonIE_Sodium_Core32_Curve25519_Ge_Precomp constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $yplusx
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $yminusx
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $xy2d
         * @throws SodiumException
         * @throws TypeError
         */
        public function __construct(\ParagonIE_Sodium_Core32_Curve25519_Fe $yplusx = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $yminusx = \null, \ParagonIE_Sodium_Core32_Curve25519_Fe $xy2d = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Ed25519
     */
    abstract class ParagonIE_Sodium_Core32_Ed25519 extends \ParagonIE_Sodium_Core32_Curve25519
    {
        const KEYPAIR_BYTES = 96;
        const SEED_BYTES = 32;
        /**
         * @internal You should not use this directly from another application
         *
         * @return string (96 bytes)
         * @throws Exception
         * @throws SodiumException
         * @throws TypeError
         */
        public static function keypair()
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $pk
         * @param string $sk
         * @param string $seed
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function seed_keypair(&$pk, &$sk, $seed)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $keypair
         * @return string
         * @throws TypeError
         */
        public static function secretkey($keypair)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $keypair
         * @return string
         * @throws RangeException
         * @throws TypeError
         */
        public static function publickey($keypair)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function publickey_from_secretkey($sk)
        {
        }
        /**
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function pk_to_curve25519($pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sk_to_pk($sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign($message, $sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message A signed message
         * @param string $pk      Public key
         * @return string         Message (without signature)
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_open($message, $pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress PossiblyInvalidArgument
         */
        public static function sign_detached($message, $sk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $sig
         * @param string $message
         * @param string $pk
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function verify_detached($sig, $message, $pk)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $S
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function check_S_lt_L($S)
        {
        }
        /**
         * @param string $R
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function small_order($R)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core_HChaCha20
     */
    class ParagonIE_Sodium_Core32_HChaCha20 extends \ParagonIE_Sodium_Core32_ChaCha20
    {
        /**
         * @param string $in
         * @param string $key
         * @param string|null $c
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function hChaCha20($in = '', $key = '', $c = \null)
        {
        }
        /**
         * @param array $ctx
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function hChaCha20Bytes(array $ctx)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Salsa20
     */
    abstract class ParagonIE_Sodium_Core32_Salsa20 extends \ParagonIE_Sodium_Core32_Util
    {
        const ROUNDS = 20;
        /**
         * Calculate an salsa20 hash of a single block
         *
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $k
         * @param string|null $c
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function core_salsa20($in, $k, $c = \null)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20($len, $nonce, $key)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $m
         * @param string $n
         * @param int $ic
         * @param string $k
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20_xor_ic($m, $n, $ic, $k)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function salsa20_xor($message, $nonce, $key)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_HSalsa20
     */
    abstract class ParagonIE_Sodium_Core32_HSalsa20 extends \ParagonIE_Sodium_Core32_Salsa20
    {
        /**
         * Calculate an hsalsa20 hash of a single block
         *
         * HSalsa20 doesn't have a counter and will never be used for more than
         * one block (used to derive a subkey for xsalsa20).
         *
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $k
         * @param string|null $c
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function hsalsa20($in, $k, $c = \null)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Int32
     *
     * Encapsulates a 32-bit integer.
     *
     * These are immutable. It always returns a new instance.
     */
    class ParagonIE_Sodium_Core32_Int32
    {
        /**
         * @var array<int, int> - two 16-bit integers
         *
         * 0 is the higher 16 bits
         * 1 is the lower 16 bits
         */
        public $limbs = array(0, 0);
        /**
         * @var int
         */
        public $overflow = 0;
        /**
         * @var bool
         */
        public $unsignedInt = \false;
        /**
         * ParagonIE_Sodium_Core32_Int32 constructor.
         * @param array $array
         * @param bool $unsignedInt
         */
        public function __construct($array = array(0, 0), $unsignedInt = \false)
        {
        }
        /**
         * Adds two int32 objects
         *
         * @param ParagonIE_Sodium_Core32_Int32 $addend
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function addInt32(\ParagonIE_Sodium_Core32_Int32 $addend)
        {
        }
        /**
         * Adds a normal integer to an int32 object
         *
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         */
        public function addInt($int)
        {
        }
        /**
         * @param int $b
         * @return int
         */
        public function compareInt($b = 0)
        {
        }
        /**
         * @param int $m
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function mask($m = 0)
        {
        }
        /**
         * @param array<int, int> $a
         * @param array<int, int> $b
         * @param int $baseLog2
         * @return array<int, int>
         */
        public function multiplyLong(array $a, array $b, $baseLog2 = 16)
        {
        }
        /**
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function mulIntFast($int)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core32_Int32 $right
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function mulInt32Fast(\ParagonIE_Sodium_Core32_Int32 $right)
        {
        }
        /**
         * @param int $int
         * @param int $size
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         */
        public function mulInt($int = 0, $size = 0)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core32_Int32 $int
         * @param int $size
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         */
        public function mulInt32(\ParagonIE_Sodium_Core32_Int32 $int, $size = 0)
        {
        }
        /**
         * OR this 32-bit integer with another.
         *
         * @param ParagonIE_Sodium_Core32_Int32 $b
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function orInt32(\ParagonIE_Sodium_Core32_Int32 $b)
        {
        }
        /**
         * @param int $b
         * @return bool
         */
        public function isGreaterThan($b = 0)
        {
        }
        /**
         * @param int $b
         * @return bool
         */
        public function isLessThanInt($b = 0)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         */
        public function rotateLeft($c = 0)
        {
        }
        /**
         * Rotate to the right
         *
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         */
        public function rotateRight($c = 0)
        {
        }
        /**
         * @param bool $bool
         * @return self
         */
        public function setUnsignedInt($bool = \false)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         */
        public function shiftLeft($c = 0)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedOperand
         */
        public function shiftRight($c = 0)
        {
        }
        /**
         * Subtract a normal integer from an int32 object.
         *
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int32
         * @throws SodiumException
         * @throws TypeError
         */
        public function subInt($int)
        {
        }
        /**
         * Subtract two int32 objects from each other
         *
         * @param ParagonIE_Sodium_Core32_Int32 $b
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function subInt32(\ParagonIE_Sodium_Core32_Int32 $b)
        {
        }
        /**
         * XOR this 32-bit integer with another.
         *
         * @param ParagonIE_Sodium_Core32_Int32 $b
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function xorInt32(\ParagonIE_Sodium_Core32_Int32 $b)
        {
        }
        /**
         * @param int $signed
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromInt($signed)
        {
        }
        /**
         * @param string $string
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromString($string)
        {
        }
        /**
         * @param string $string
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromReverseString($string)
        {
        }
        /**
         * @return array<int, int>
         */
        public function toArray()
        {
        }
        /**
         * @return string
         * @throws TypeError
         */
        public function toString()
        {
        }
        /**
         * @return int
         */
        public function toInt()
        {
        }
        /**
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function toInt32()
        {
        }
        /**
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function toInt64()
        {
        }
        /**
         * @return string
         * @throws TypeError
         */
        public function toReverseString()
        {
        }
        /**
         * @return string
         */
        public function __toString()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Int64
     *
     * Encapsulates a 64-bit integer.
     *
     * These are immutable. It always returns a new instance.
     */
    class ParagonIE_Sodium_Core32_Int64
    {
        /**
         * @var array<int, int> - four 16-bit integers
         */
        public $limbs = array(0, 0, 0, 0);
        /**
         * @var int
         */
        public $overflow = 0;
        /**
         * @var bool
         */
        public $unsignedInt = \false;
        /**
         * ParagonIE_Sodium_Core32_Int64 constructor.
         * @param array $array
         * @param bool $unsignedInt
         */
        public function __construct($array = array(0, 0, 0, 0), $unsignedInt = \false)
        {
        }
        /**
         * Adds two int64 objects
         *
         * @param ParagonIE_Sodium_Core32_Int64 $addend
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function addInt64(\ParagonIE_Sodium_Core32_Int64 $addend)
        {
        }
        /**
         * Adds a normal integer to an int64 object
         *
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public function addInt($int)
        {
        }
        /**
         * @param int $b
         * @return int
         */
        public function compareInt($b = 0)
        {
        }
        /**
         * @param int $b
         * @return bool
         */
        public function isGreaterThan($b = 0)
        {
        }
        /**
         * @param int $b
         * @return bool
         */
        public function isLessThanInt($b = 0)
        {
        }
        /**
         * @param int $hi
         * @param int $lo
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function mask64($hi = 0, $lo = 0)
        {
        }
        /**
         * @param int $int
         * @param int $size
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         */
        public function mulInt($int = 0, $size = 0)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core32_Int64 $A
         * @param ParagonIE_Sodium_Core32_Int64 $B
         * @return array<int, ParagonIE_Sodium_Core32_Int64>
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedInferredReturnType
         */
        public static function ctSelect(\ParagonIE_Sodium_Core32_Int64 $A, \ParagonIE_Sodium_Core32_Int64 $B)
        {
        }
        /**
         * @param array<int, int> $a
         * @param array<int, int> $b
         * @param int $baseLog2
         * @return array<int, int>
         */
        public function multiplyLong(array $a, array $b, $baseLog2 = 16)
        {
        }
        /**
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function mulIntFast($int)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core32_Int64 $right
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function mulInt64Fast(\ParagonIE_Sodium_Core32_Int64 $right)
        {
        }
        /**
         * @param ParagonIE_Sodium_Core32_Int64 $int
         * @param int $size
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         */
        public function mulInt64(\ParagonIE_Sodium_Core32_Int64 $int, $size = 0)
        {
        }
        /**
         * OR this 64-bit integer with another.
         *
         * @param ParagonIE_Sodium_Core32_Int64 $b
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function orInt64(\ParagonIE_Sodium_Core32_Int64 $b)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         */
        public function rotateLeft($c = 0)
        {
        }
        /**
         * Rotate to the right
         *
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedArrayAccess
         */
        public function rotateRight($c = 0)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public function shiftLeft($c = 0)
        {
        }
        /**
         * @param int $c
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public function shiftRight($c = 0)
        {
        }
        /**
         * Subtract a normal integer from an int64 object.
         *
         * @param int $int
         * @return ParagonIE_Sodium_Core32_Int64
         * @throws SodiumException
         * @throws TypeError
         */
        public function subInt($int)
        {
        }
        /**
         * The difference between two Int64 objects.
         *
         * @param ParagonIE_Sodium_Core32_Int64 $b
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function subInt64(\ParagonIE_Sodium_Core32_Int64 $b)
        {
        }
        /**
         * XOR this 64-bit integer with another.
         *
         * @param ParagonIE_Sodium_Core32_Int64 $b
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function xorInt64(\ParagonIE_Sodium_Core32_Int64 $b)
        {
        }
        /**
         * @param int $low
         * @param int $high
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromInts($low, $high)
        {
        }
        /**
         * @param int $low
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromInt($low)
        {
        }
        /**
         * @return int
         */
        public function toInt()
        {
        }
        /**
         * @param string $string
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromString($string)
        {
        }
        /**
         * @param string $string
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public static function fromReverseString($string)
        {
        }
        /**
         * @return array<int, int>
         */
        public function toArray()
        {
        }
        /**
         * @return ParagonIE_Sodium_Core32_Int32
         */
        public function toInt32()
        {
        }
        /**
         * @return ParagonIE_Sodium_Core32_Int64
         */
        public function toInt64()
        {
        }
        /**
         * @param bool $bool
         * @return self
         */
        public function setUnsignedInt($bool = \false)
        {
        }
        /**
         * @return string
         * @throws TypeError
         */
        public function toString()
        {
        }
        /**
         * @return string
         * @throws TypeError
         */
        public function toReverseString()
        {
        }
        /**
         * @return string
         */
        public function __toString()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Poly1305
     */
    abstract class ParagonIE_Sodium_Core32_Poly1305 extends \ParagonIE_Sodium_Core32_Util
    {
        const BLOCK_SIZE = 16;
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $m
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function onetimeauth($m, $key)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $mac
         * @param string $m
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function onetimeauth_verify($mac, $m, $key)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_Poly1305_State
     */
    class ParagonIE_Sodium_Core32_Poly1305_State extends \ParagonIE_Sodium_Core32_Util
    {
        /**
         * @var array<int, int>
         */
        protected $buffer = array();
        /**
         * @var bool
         */
        protected $final = \false;
        /**
         * @var array<int, ParagonIE_Sodium_Core32_Int32>
         */
        public $h;
        /**
         * @var int
         */
        protected $leftover = 0;
        /**
         * @var array<int, ParagonIE_Sodium_Core32_Int32>
         */
        public $r;
        /**
         * @var array<int, ParagonIE_Sodium_Core32_Int64>
         */
        public $pad;
        /**
         * ParagonIE_Sodium_Core32_Poly1305_State constructor.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $key
         * @throws InvalidArgumentException
         * @throws SodiumException
         * @throws TypeError
         */
        public function __construct($key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public function update($message = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param int $bytes
         * @return self
         * @throws SodiumException
         * @throws TypeError
         */
        public function blocks($message, $bytes)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public function finish()
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_SecretStream_State
     */
    class ParagonIE_Sodium_Core32_SecretStream_State
    {
        /** @var string $key */
        protected $key;
        /** @var int $counter */
        protected $counter;
        /** @var string $nonce */
        protected $nonce;
        /** @var string $_pad */
        protected $_pad;
        /**
         * ParagonIE_Sodium_Core32_SecretStream_State constructor.
         * @param string $key
         * @param string|null $nonce
         */
        public function __construct($key, $nonce = \null)
        {
        }
        /**
         * @return self
         */
        public function counterReset()
        {
        }
        /**
         * @return string
         */
        public function getKey()
        {
        }
        /**
         * @return string
         */
        public function getCounter()
        {
        }
        /**
         * @return string
         */
        public function getNonce()
        {
        }
        /**
         * @return string
         */
        public function getCombinedNonce()
        {
        }
        /**
         * @return self
         */
        public function incrementCounter()
        {
        }
        /**
         * @return bool
         */
        public function needsRekey()
        {
        }
        /**
         * @param string $newKeyAndNonce
         * @return self
         */
        public function rekey($newKeyAndNonce)
        {
        }
        /**
         * @param string $str
         * @return self
         */
        public function xorNonce($str)
        {
        }
        /**
         * @param string $string
         * @return self
         */
        public static function fromString($string)
        {
        }
        /**
         * @return string
         */
        public function toString()
        {
        }
    }
    /**
     * Class ParagonIE_SodiumCompat_Core32_SipHash
     *
     * Only uses 32-bit arithmetic, while the original SipHash used 64-bit integers
     */
    class ParagonIE_Sodium_Core32_SipHash extends \ParagonIE_Sodium_Core32_Util
    {
        /**
         * @internal You should not use this directly from another application
         *
         * @param array<int, ParagonIE_Sodium_Core32_Int64> $v
         * @return array<int, ParagonIE_Sodium_Core32_Int64>
         */
        public static function sipRound(array $v)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $in
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sipHash24($in, $key)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_X25519
     */
    abstract class ParagonIE_Sodium_Core32_X25519 extends \ParagonIE_Sodium_Core32_Curve25519
    {
        /**
         * Alters the objects passed to this method in place.
         *
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $g
         * @param int $b
         * @return void
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_cswap(\ParagonIE_Sodium_Core32_Curve25519_Fe $f, \ParagonIE_Sodium_Core32_Curve25519_Fe $g, $b = 0)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $f
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MixedMethodCall
         */
        public static function fe_mul121666(\ParagonIE_Sodium_Core32_Curve25519_Fe $f)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * Inline comments preceded by # are from libsodium's ref10 code.
         *
         * @param string $n
         * @param string $p
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_scalarmult_curve25519_ref10($n, $p)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $edwardsY
         * @param ParagonIE_Sodium_Core32_Curve25519_Fe $edwardsZ
         * @return ParagonIE_Sodium_Core32_Curve25519_Fe
         * @throws SodiumException
         * @throws TypeError
         */
        public static function edwards_to_montgomery(\ParagonIE_Sodium_Core32_Curve25519_Fe $edwardsY, \ParagonIE_Sodium_Core32_Curve25519_Fe $edwardsZ)
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $n
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function crypto_scalarmult_curve25519_ref10_base($n)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_XChaCha20
     */
    class ParagonIE_Sodium_Core32_XChaCha20 extends \ParagonIE_Sodium_Core32_HChaCha20
    {
        /**
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function stream($len = 64, $nonce = '', $key = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function streamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
        /**
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @param string $ic
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function ietfStreamXorIc($message, $nonce = '', $key = '', $ic = '')
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Core32_XSalsa20
     */
    abstract class ParagonIE_Sodium_Core32_XSalsa20 extends \ParagonIE_Sodium_Core32_HSalsa20
    {
        /**
         * Expand a key and nonce into an xsalsa20 keystream.
         *
         * @internal You should not use this directly from another application
         *
         * @param int $len
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function xsalsa20($len, $nonce, $key)
        {
        }
        /**
         * Encrypt a string with XSalsa20. Doesn't provide integrity.
         *
         * @internal You should not use this directly from another application
         *
         * @param string $message
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function xsalsa20_xor($message, $nonce, $key)
        {
        }
    }
    /**
     * Class ParagonIE_Sodium_Crypto
     *
     * ATTENTION!
     *
     * If you are using this library, you should be using
     * ParagonIE_Sodium_Compat in your code, not this class.
     */
    abstract class ParagonIE_Sodium_Crypto32
    {
        const aead_chacha20poly1305_KEYBYTES = 32;
        const aead_chacha20poly1305_NSECBYTES = 0;
        const aead_chacha20poly1305_NPUBBYTES = 8;
        const aead_chacha20poly1305_ABYTES = 16;
        const aead_chacha20poly1305_IETF_KEYBYTES = 32;
        const aead_chacha20poly1305_IETF_NSECBYTES = 0;
        const aead_chacha20poly1305_IETF_NPUBBYTES = 12;
        const aead_chacha20poly1305_IETF_ABYTES = 16;
        const aead_xchacha20poly1305_IETF_KEYBYTES = 32;
        const aead_xchacha20poly1305_IETF_NSECBYTES = 0;
        const aead_xchacha20poly1305_IETF_NPUBBYTES = 24;
        const aead_xchacha20poly1305_IETF_ABYTES = 16;
        const box_curve25519xsalsa20poly1305_SEEDBYTES = 32;
        const box_curve25519xsalsa20poly1305_PUBLICKEYBYTES = 32;
        const box_curve25519xsalsa20poly1305_SECRETKEYBYTES = 32;
        const box_curve25519xsalsa20poly1305_BEFORENMBYTES = 32;
        const box_curve25519xsalsa20poly1305_NONCEBYTES = 24;
        const box_curve25519xsalsa20poly1305_MACBYTES = 16;
        const box_curve25519xsalsa20poly1305_BOXZEROBYTES = 16;
        const box_curve25519xsalsa20poly1305_ZEROBYTES = 32;
        const onetimeauth_poly1305_BYTES = 16;
        const onetimeauth_poly1305_KEYBYTES = 32;
        const secretbox_xsalsa20poly1305_KEYBYTES = 32;
        const secretbox_xsalsa20poly1305_NONCEBYTES = 24;
        const secretbox_xsalsa20poly1305_MACBYTES = 16;
        const secretbox_xsalsa20poly1305_BOXZEROBYTES = 16;
        const secretbox_xsalsa20poly1305_ZEROBYTES = 32;
        const secretbox_xchacha20poly1305_KEYBYTES = 32;
        const secretbox_xchacha20poly1305_NONCEBYTES = 24;
        const secretbox_xchacha20poly1305_MACBYTES = 16;
        const secretbox_xchacha20poly1305_BOXZEROBYTES = 16;
        const secretbox_xchacha20poly1305_ZEROBYTES = 32;
        const stream_salsa20_KEYBYTES = 32;
        /**
         * AEAD Decryption with ChaCha20-Poly1305
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Decryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_ietf_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_chacha20poly1305_ietf_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Decryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_xchacha20poly1305_ietf_decrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * AEAD Encryption with ChaCha20-Poly1305, IETF mode (96-bit nonce)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $ad
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function aead_xchacha20poly1305_ietf_encrypt($message = '', $ad = '', $nonce = '', $key = '')
        {
        }
        /**
         * HMAC-SHA-512-256 (a.k.a. the leftmost 256 bits of HMAC-SHA-512)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $key
         * @return string
         * @throws TypeError
         */
        public static function auth($message, $key)
        {
        }
        /**
         * HMAC-SHA-512-256 validation. Constant-time via hash_equals().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $mac
         * @param string $message
         * @param string $key
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function auth_verify($mac, $message, $key)
        {
        }
        /**
         * X25519 key exchange followed by XSalsa20Poly1305 symmetric encryption
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box($plaintext, $nonce, $keypair)
        {
        }
        /**
         * X25519-XSalsa20-Poly1305 with one ephemeral X25519 keypair.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $publicKey
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal($message, $publicKey)
        {
        }
        /**
         * Opens a message encrypted via box_seal().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seal_open($message, $keypair)
        {
        }
        /**
         * Used by crypto_box() to get the crypto_secretbox() key.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sk
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_beforenm($sk, $pk)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @return string
         * @throws Exception
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_keypair()
        {
        }
        /**
         * @param string $seed
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_seed_keypair($seed)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @param string $pKey
         * @return string
         * @throws TypeError
         */
        public static function box_keypair_from_secretkey_and_publickey($sKey, $pKey)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $keypair
         * @return string
         * @throws RangeException
         * @throws TypeError
         */
        public static function box_secretkey($keypair)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $keypair
         * @return string
         * @throws RangeException
         * @throws TypeError
         */
        public static function box_publickey($keypair)
        {
        }
        /**
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_publickey_from_secretkey($sKey)
        {
        }
        /**
         * Decrypt a message encrypted with box().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $keypair
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function box_open($ciphertext, $nonce, $keypair)
        {
        }
        /**
         * Calculate a BLAKE2b hash.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string|null $key
         * @param int $outlen
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash($message, $key = '', $outlen = 32)
        {
        }
        /**
         * Finalize a BLAKE2b hashing context, returning the hash.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ctx
         * @param int $outlen
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_final($ctx, $outlen = 32)
        {
        }
        /**
         * Initialize a hashing context for BLAKE2b.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $key
         * @param int $outputLength
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_init($key = '', $outputLength = 32)
        {
        }
        /**
         * Initialize a hashing context for BLAKE2b.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $key
         * @param int $outputLength
         * @param string $salt
         * @param string $personal
         * @return string
         * @throws RangeException
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_init_salt_personal($key = '', $outputLength = 32, $salt = '', $personal = '')
        {
        }
        /**
         * Update a hashing context for BLAKE2b with $message
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ctx
         * @param string $message
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function generichash_update($ctx, $message)
        {
        }
        /**
         * Libsodium's crypto_kx().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $my_sk
         * @param string $their_pk
         * @param string $client_pk
         * @param string $server_pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function keyExchange($my_sk, $their_pk, $client_pk, $server_pk)
        {
        }
        /**
         * ECDH over Curve25519
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $sKey
         * @param string $pKey
         * @return string
         *
         * @throws SodiumException
         * @throws TypeError
         */
        public static function scalarmult($sKey, $pKey)
        {
        }
        /**
         * ECDH over Curve25519, using the basepoint.
         * Used to get a secret key from a public key.
         *
         * @param string $secret
         * @return string
         *
         * @throws SodiumException
         * @throws TypeError
         */
        public static function scalarmult_base($secret)
        {
        }
        /**
         * This throws an Error if a zero public key was passed to the function.
         *
         * @param string $q
         * @return void
         * @throws SodiumException
         * @throws TypeError
         */
        protected static function scalarmult_throw_if_zero($q)
        {
        }
        /**
         * XSalsa20-Poly1305 authenticated symmetric-key encryption.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypt a ciphertext generated via secretbox().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * XChaCha20-Poly1305 authenticated symmetric-key encryption.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $plaintext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_xchacha20poly1305($plaintext, $nonce, $key)
        {
        }
        /**
         * Decrypt a ciphertext generated via secretbox_xchacha20poly1305().
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $ciphertext
         * @param string $nonce
         * @param string $key
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function secretbox_xchacha20poly1305_open($ciphertext, $nonce, $key)
        {
        }
        /**
         * @param string $key
         * @return array<int, string> Returns a state and a header.
         * @throws Exception
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_init_push($key)
        {
        }
        /**
         * @param string $key
         * @param string $header
         * @return string Returns a state.
         * @throws Exception
         */
        public static function secretstream_xchacha20poly1305_init_pull($key, $header)
        {
        }
        /**
         * @param string $state
         * @param string $msg
         * @param string $aad
         * @param int $tag
         * @return string
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_push(&$state, $msg, $aad = '', $tag = 0)
        {
        }
        /**
         * @param string $state
         * @param string $cipher
         * @param string $aad
         * @return bool|array{0: string, 1: int}
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_pull(&$state, $cipher, $aad = '')
        {
        }
        /**
         * @param string $state
         * @return void
         * @throws SodiumException
         */
        public static function secretstream_xchacha20poly1305_rekey(&$state)
        {
        }
        /**
         * Detached Ed25519 signature.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_detached($message, $sk)
        {
        }
        /**
         * Attached Ed25519 signature. (Returns a signed message.)
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $message
         * @param string $sk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign($message, $sk)
        {
        }
        /**
         * Opens a signed message. If valid, returns the message.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $signedMessage
         * @param string $pk
         * @return string
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_open($signedMessage, $pk)
        {
        }
        /**
         * Verify a detached signature of a given message and public key.
         *
         * @internal Do not use this directly. Use ParagonIE_Sodium_Compat.
         *
         * @param string $signature
         * @param string $message
         * @param string $pk
         * @return bool
         * @throws SodiumException
         * @throws TypeError
         */
        public static function sign_verify_detached($signature, $message, $pk)
        {
        }
    }
}
namespace {
    // ============================================= end of configuration
    /**
     * auto detect module name and version from file name
     *
     * @return  [type]  [return description]
     */
    function detectModule()
    {
    }
    /**
     * delete a directory
     *
     * @param   [type]  $dir  [$dir description]
     *
     * @return  [type]        [return description]
     */
    function delTree($dir)
    {
    }
    /**
     * check if that filename is concerned by exclude filter
     *
     * @param   [type]  $filename  [$filename description]
     *
     * @return  [type]             [return description]
     */
    function is_excluded($filename)
    {
    }
    /**
     * recursive copy files & dirs
     *
     * @param   [type]  $src  [$src description]
     * @param   [type]  $dst  [$dst description]
     *
     * @return  [type]        [return description]
     */
    function rcopy($src, $dst)
    {
    }
    /**
     * build a zip file with only php code
     */
    function zipDir($folder, &$zip, $root = "")
    {
    }
    function getModuleName($id)
    {
    }
    /* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <https://www.gnu.org/licenses/>.
     */
    /**
     * \file    smartauth/lib/smartauth.lib.php
     * \ingroup smartauth
     * \brief   Library files with common functions for Smartauth
     */
    /**
     * Prepare admin pages header
     *
     * @return array
     */
    function smartauthAdminPrepareHead()
    {
    }
    /* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <https://www.gnu.org/licenses/>.
     */
    /**
     * \file    lib/smartauth_auth.lib.php
     * \ingroup smartauth
     * \brief   Library files with common functions for Auth
     */
    /**
     * Prepare array of tabs for Auth
     *
     * @param	Auth	$object		Auth
     * @return 	array					Array of tabs
     */
    function authPrepareHead($object)
    {
    }
    /**
     * tools.php
     *
     * Copyright (c) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU Affero General Public License as
     * published by the Free Software Foundation, either version 3 of the
     * License, or (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU Affero General Public License for more details.
     *
     * You should have received a copy of the GNU Affero General Public License
     * along with this program.  If not, see <https://www.gnu.org/licenses/>.
     */
    function json_reply($message, $code)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::crypto_kx()
     * @param string $my_secret
     * @param string $their_public
     * @param string $client_public
     * @param string $server_public
     * @return string
     * @throws SodiumException
     * @throws TypeError
     */
    function sodium_crypto_kx($my_secret, $their_public, $client_public, $server_public)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::library_version_major()
     * @return int
     */
    function sodium_library_version_major()
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::library_version_minor()
     * @return int
     */
    function sodium_library_version_minor()
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::version_string()
     * @return string
     */
    function sodium_version_string()
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::randombytes_buf()
     * @param int $amount
     * @return string
     * @throws Exception
     */
    function sodium_randombytes_buf($amount)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::randombytes_uniform()
     * @param int $upperLimit
     * @return int
     * @throws Exception
     */
    function sodium_randombytes_uniform($upperLimit)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::randombytes_random16()
     * @return int
     * @throws Exception
     */
    function sodium_randombytes_random16()
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::crypto_aead_aegis128l_decrypt()
     * @param string $ciphertext
     * @param string $additional_data
     * @param string $nonce
     * @param string $key
     * @return string
     * @throws SodiumException
     */
    function sodium_crypto_aead_aegis128l_decrypt($ciphertext, $additional_data, $nonce, $key)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::crypto_aead_aegis128l_encrypt()
     * @param string $message
     * @param string $additional_data
     * @param string $nonce
     * @param string $key
     * @return string
     * @throws SodiumException
     * @throws TypeError
     */
    function sodium_crypto_aead_aegis128l_encrypt($message, $additional_data, $nonce, $key)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::crypto_aead_aegis256_encrypt()
     * @param string $ciphertext
     * @param string $additional_data
     * @param string $nonce
     * @param string $key
     * @return string
     * @throws SodiumException
     */
    function sodium_crypto_aead_aegis256_decrypt($ciphertext, $additional_data, $nonce, $key)
    {
    }
    /**
     * @see ParagonIE_Sodium_Compat::crypto_aead_aegis256_encrypt()
     * @param string $message
     * @param string $additional_data
     * @param string $nonce
     * @param string $key
     * @return string
     * @throws SodiumException
     * @throws TypeError
     */
    function sodium_crypto_aead_aegis256_encrypt($message, $additional_data, $nonce, $key)
    {
    }
}