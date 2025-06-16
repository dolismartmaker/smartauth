<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\DolibarrMapping;

trait dmTrait
{
    private $_dolmapping;
    private $_dolmapclassname;
    private $_dolobjectclassname;
    private $_db;
    private $_listOfForeignKeys = [];
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
class dmCcountry
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    private $_type = "dict";
    //corresponding fields left dolibarr right front app
    private $_listOfPublishedFields = [
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
class dmContact
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    private $_type = "object";
    //corresponding fields left dolibarr right front app
    private $_listOfPublishedFields = ['rowid' => 'rowid', 'civility' => 'civility', 'lastname' => 'lastname', 'firstname' => 'firstname', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_departement' => 'departement', 'fk_pays' => 'country', 'phone' => 'phone', 'phone_mobile' => 'phone_mobile', 'email' => 'email', 'note_public' => 'note_public', 'note_private' => 'note_private', 'fk_soc' => 'customer'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmProject
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    private $_type = "object";
    //corresponding fields left dolibarr right front app
    private $_listOfPublishedFields = ['rowid' => 'rowid', 'ref' => 'ref', 'ref_customer' => 'ref_customer', 'ref_supplier' => 'ref_supplier', 'date_c' => 'date_c', 'date_contrat' => 'date_contrat', 'fk_soc' => 'fk_soc', 'fk_projet' => 'fk_projet', 'note_public' => 'note_public', 'note_private' => 'note_private'];
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
class dmFichinter
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    private $_type = "object";
    //corresponding fields left dolibarr right front app
    private $_listOfPublishedFields = ['rowid' => 'rowid', 'ref' => 'ref', 'ref_client' => 'ref_client', 'datei' => 'datei', 'description' => 'description', 'note_public' => 'note_public', 'note_private' => 'note_private'];
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
    private $_listOfForeignKeys = [];
    //dolibarr < - > application mapping for main attributes
    private $_mappingAttributes = ['type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'notnull' => 'required', 'noteditable' => 'readOnly', 'disabled' => 'disabled', 'visible' => 'visible', 'length' => 'max', 'position' => 'position', 'options' => 'options'];
    //dolibarr < - > application mapping for extrafields attributes
    private $_mappingExtrafieldsAttributes = ['type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'required' => 'required', 'noteditable' => 'readOnly', 'visible' => 'visible', 'size' => 'max', 'pos' => 'position', 'options' => 'options'];
    private function _customFilterAttributeTypeInteger($str)
    {
    }
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
class dmSociete
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    private $_type = "object";
    //corresponding fields left dolibarr right front app
    private $_listOfPublishedFields = [
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
}