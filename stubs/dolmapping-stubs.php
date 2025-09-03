<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\DolibarrMapping;

abstract class dmBase
{
    protected $type;
    /**
     * name of class where you can find extrafields for that object for example Fichinter
     *
     * @var string
     */
    protected $parentClassToUseForExtraFields;
    /**
     * parent element for example fichinter
     *
     * @var string
     */
    protected $parentElementToUseForExtraFields;
    /**
     * parent table name for example fichinter
     *
     * @var string
     */
    protected $parentTableElementToUseForExtraFields;
    /**
     * list of extrafields you want to push as read only on front side
     * (that list should be set via module setup if you want to make that list
     * dynamic for end users)
     *
     * @var array
     */
    protected $extrafieldsRO;
    /**
     * same as $extrafieldsRO but in write, then people can set data into that extrafields
     *
     * @var array
     */
    protected $extrafieldsRW;
    /**
     * list of fields you want to publish on front
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFields;
    /**
     * name of class for lines, for exemple FichinterLigne or InventoryLine
     *
     * @var string
     */
    protected $parentClassNameForLines;
    /**
     * label for "title of lines", for exemple on FichinterLigne lines title could be "History"
     * (note: that label will be translated thanks to internal dolibarr translation system)
     *
     * @var string
     */
    protected $parentLabelForLines;
    /**
     * fields for lines like dolibarr publish for main object, for exemple FichinterLigne
     * FichinterLigne could not have ->fields then we have to do it in our "custom" object
     *
     * @var array
     */
    protected $parentFieldsForLines;
    /**
     * list of fields you want to publish on front for lines
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFieldsForLines;
}
trait dmTrait
{
    private $_dolmapping;
    private $_dolmapclassname;
    private $_dolobjectclassname;
    private $_db;
    private $listOfForeignKeys = [];
    private $_cacheDesc;
    /**
     * object constructor
     */
    public function __construct()
    {
    }
    public function boot()
    {
    }
    /**
     * export object description for client app -- could be better with only serialization (todo/tests)
     *
     * @return  \stdClass  object description
     */
    public function objectDesc()
    {
    }
    /**
     * build all description of an object : field by field, browse dolibarr class and parse $fields
     * then convert it to smart* fields names and types
     *
     * @return  [type]  [return description]
     */
    private function _objectDesc()
    {
    }
    public function objectType()
    {
    }
    /**
     * export object data mapped thanks to _listOfPublishedFields
     *
     * @param   [type]  $obj  [$obj description]
     *
     * @return  [type]        [return description]
     */
    public function exportMappedData($obj)
    {
    }
    /**
     * map extrafield, for example
     * smartinterventions_type_event is a sellist
     * and definition is 'options'=>array('c_actioncomm:libelle:id'=>null)
     * so we have to get value ...
     *
     * @param   [type]  $name   [$name description]
     * @param   [type]  $objectid  [$objectid description]
     *
     * @return  [type]          [return description]
     */
    public function exportExtrafieldData($name, $objectid)
    {
    }
    /**
     * export data for foreign keys ex
     * fk_soc is a int so we get Societe object
     *
     * @param   [type]  $name   [$name description]
     * @param   [type]  $objectid  [$objectid description]
     *
     * @return  [type]          [return description]
     */
    public function exportData($name, $objectid)
    {
    }
}
class dmCcountry extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dict";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = [
        // 'rowid' 			=> 'rowid',
        // 'code' 			    => 'code',
        'label' => 'label',
    ];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmContact extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = ['rowid' => 'rowid', 'civility' => 'civility', 'lastname' => 'lastname', 'firstname' => 'firstname', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_departement' => 'departement', 'fk_pays' => 'country', 'phone' => 'phone', 'phone_mobile' => 'phone_mobile', 'email' => 'email', 'note_public' => 'note_public', 'note_private' => 'note_private', 'fk_soc' => 'customer', 'fk_c_type_contact' => 'type_contact'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmContrat extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = ['rowid' => 'rowid', 'ref' => 'ref', 'ref_customer' => 'ref_customer', 'ref_supplier' => 'ref_supplier', 'date_c' => 'date_c', 'date_contrat' => 'date_contrat', 'fk_soc' => 'fk_soc', 'fk_projet' => 'fk_projet', 'note_public' => 'note_public', 'note_private' => 'note_private'];
    //		'fk_pays' =>array('type'=>'integer:Ccountry:core/class/ccountry.class.php', 'label'=>'Country', 'enabled'=>1, 'visible'=>-1, 'position'=>95),
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmFichinter extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = ['rowid' => 'rowid', 'ref' => 'ref', 'ref_client' => 'ref_client', 'datei' => 'datei', 'description' => 'description', 'note_public' => 'note_public', 'note_private' => 'note_private'];
    //		'fk_pays' =>array('type'=>'integer:Ccountry:core/class/ccountry.class.php', 'label'=>'Country', 'enabled'=>1, 'visible'=>-1, 'position'=>95),
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmHelper
{
    private $listOfForeignKeys = [];
    //dolibarr < - > application mapping for main attributes
    private $_mappingAttributes = ['type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'notnull' => 'required', 'noteditable' => 'readOnly', 'disabled' => 'disabled', 'visible' => 'visible', 'length' => 'max', 'position' => 'position', 'options' => 'options', 'logo' => 'logo'];
    //dolibarr < - > application mapping for extrafields attributes
    private $_mappingExtrafieldsAttributes = ['type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'required' => 'required', 'noteditable' => 'readOnly', 'visible' => 'visible', 'size' => 'max', 'pos' => 'position', 'options' => 'options'];
    /**
     * Filter attribute type integer
     *
     * @param   [type]  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeTypeInteger($str)
    {
    }
    /**
     * Filter attribute type list of selection
     *
     * @param   [type]  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeTypeSellist($str)
    {
    }
    /**
     * custom filter on type field
     * ex: integer:Fichinter:fichinter/class/fichinter.class.php:0
     *     varchar(30)
     *     ...
     *
     * @param   [type]  $str  dolibarr "type" string
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeType($str)
    {
    }
    /**
     * convert dolibarr visible code to smart* values
     *
     *	0=Not visible
     *	1=Visible on list and create/update/view forms
     *	2=Visible on list only
     *	3=Visible on create/update/view form only (not list)
     *	4=Visible on list and update/view form only (not create).
     *	5=Visible on list and view only (not create/not update).
     *	Using a negative value means field is not shown by default on list but can be selected for viewing)
     *
     * @param   [type]  $val  [$val description]
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeVisible($val)
    {
    }
    /**
     * contacts linked to dolibarr object
     *
     * @param   [type]  $val  [$val description]
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeContacts($val)
    {
    }
    /**
     * filter all dolibarr properties to make beautifull objects
     * definitions for smart app
     *
     * @param   [type]  $input     [$input description]
     * @param   [type]  $dolikey   [$dolikey description]
     * @param   [type]  $frontkey  [$frontkey description]
     *
     * @return  [type]             [return description]
     */
    public function propertiesFilter($input, $dolikey = null, $frontkey = null)
    {
    }
    /**
     * filter all dolibarr extrafields to make beautifull objects
     * definitions for smart app
     *
     * @param   [type]  $array  [$array description]
     *
     * @return  [type]          [return description]
     */
    public function extrafieldsFilter($objectElement, $dolikey, $frontkey, $extrafields)
    {
    }
    public function getListOfForeignKeys()
    {
    }
}
class dmProject extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = ['rowid' => 'rowid', 'ref' => 'ref', 'title' => 'title', 'dateo' => 'date_open', 'datee' => 'date_end', 'description' => 'description'];
    //		'fk_pays' =>array('type'=>'integer:Ccountry:core/class/ccountry.class.php', 'label'=>'Country', 'enabled'=>1, 'visible'=>-1, 'position'=>95),
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmSociete extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    //corresponding fields left dolibarr right front app
    protected $listOfPublishedFields = [
        'rowid' => 'rowid',
        'nom' => 'name',
        'address' => 'address',
        'zip' => 'zip',
        'town' => 'city',
        'fk_departement' => 'departement',
        'fk_pays' => 'country',
        'phone' => 'phone',
        // 'phone_mobile' 		=> 'phone_mobile',
        'url' => 'url',
        'email' => 'email',
        'note_public' => 'note_public',
        'note_private' => 'note_private',
        'logo' => 'logo',
    ];
    //		'fk_pays' =>array('type'=>'integer:Ccountry:core/class/ccountry.class.php', 'label'=>'Country', 'enabled'=>1, 'visible'=>-1, 'position'=>95),
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
    /**
     * logo is stored as varchar dolibarr side (file name) but app need a base64 encoded data
     *
     * @param   [type]  $societe  [dolibarr $societe]
     *
     * @return  [type]        [return description]
     */
    public function fieldFilterValueLogo($societe)
    {
    }
}