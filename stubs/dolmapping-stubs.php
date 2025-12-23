<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\DolibarrMapping;

abstract class dmBase
{
    protected $type;
    /**
     * name of class for parent object, for exemple Fichinter
     *
     * @var string
     */
    protected $parentClassName;
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
     * you can customize / overcharge fields for for parent object like dolibarr publish for main object
     * if you would like to change some settings, for exemple changing a field of Fichinter main object
     * to make it readonly in your specific use case
     *
     * example: $parentFieldsOverride['duree']['type'] = "duration";
     *          $parentFieldsOverride['duree'] = [ 'type' => "duration", 'required' => "required" ];
     *
     * @var array
     */
    protected $parentFieldsOverride;
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
     * Note: auto apply translation for label or help fields
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
     * @return  stdClass       object
     */
    public function exportMappedData($obj)
    {
    }
    /**
     * map extrafield, for example
     * smartinterventions_type_event is a sellist
     * and definition is 'options'=>array('c_actioncomm:libelle:id'=>null)
     * so we have to get values ...
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
     * in bief : follow foreign keys and grab data
     *
     * @param   [type]  $name   [$name description]
     * @param   [type]  $objectid  [$objectid description]
     *
     * @return  [type]          [return description]
     */
    public function exportData($name, $objectid)
    {
    }
    /**
     * get storage path of a linked file
     *
     * @param   CommonObject $object dolibarr object
     * @param   bool $relativepath   if true return only the last part relative to DOL_DATA_ROOT
     * 								 if false, return full file path with /home/server/www/ part
     *
     * @return  array           file path, element
     */
    public function getStoragePath($object, $relativepath = true)
    {
    }
    /**
     * photo is stored as varchar dolibarr side (file name) but app need a base64 encoded data
     *
     * @param   [type]  $societe  [dolibarr $societe]
     *
     * @return  [type]        [return description]
     */
    public function fieldFilterValueSmartPhoto($object, $doliside)
    {
    }
}
/**
 * Mapping for Dolibarr ActionComm -> API AgendaEvent
 * Alias: dmActioncomm (for backward compatibility with Dolibarr internal calls)
 */
class dmAgendaEvent extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'type_code' => 'type_code', 'type_label' => 'type_label', 'datec' => 'created_at', 'datep' => 'date_start', 'datef' => 'date_end', 'duree' => 'duration', 'fk_soc' => 'thirdparty', 'fk_contact' => 'contact', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_action' => 'assigned_to', 'location' => 'location', 'note_public' => 'public_note', 'note_private' => 'private_note', 'percent' => 'progress', 'priority' => 'priority'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Trait for document lines mapping (Facture, Propal, Commande, etc.)
 * Provides common field mappings for line items
 *
 * See documentation/api-naming-convention.md
 */
trait dmLinesTrait
{
    /**
     * Common fields mapping for document lines
     * Dolibarr field => Front field
     *
     * @return array
     */
    protected function getCommonLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to invoice lines (Facture)
     *
     * @return array
     */
    protected function getInvoiceLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to proposal lines (Propal)
     *
     * @return array
     */
    protected function getProposalLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to order lines (Commande)
     *
     * @return array
     */
    protected function getOrderLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to supplier invoice lines (FactureFournisseur)
     *
     * @return array
     */
    protected function getSupplierInvoiceLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to supplier order lines (CommandeFournisseur)
     *
     * @return array
     */
    protected function getSupplierOrderLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to supplier proposal lines (SupplierProposal)
     *
     * @return array
     */
    protected function getSupplierProposalLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to shipment lines (Expedition)
     *
     * @return array
     */
    protected function getShipmentLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to reception lines (Reception)
     *
     * @return array
     */
    protected function getReceptionLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to expense report lines (ExpenseReport)
     *
     * @return array
     */
    protected function getExpenseReportLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to contract lines (Contrat)
     *
     * @return array
     */
    protected function getContractLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to intervention lines (Fichinter)
     *
     * @return array
     */
    protected function getInterventionLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to BOM lines (Bill of Materials)
     *
     * @return array
     */
    protected function getBomLinesMapping() : array
    {
    }
    /**
     * Additional fields specific to MO lines (Manufacturing Order)
     *
     * @return array
     */
    protected function getMoLinesMapping() : array
    {
    }
}
/**
 * Mapping for Dolibarr BOM -> API Bom (Bill of Materials)
 */
class dmBom extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'bomtype' => 'bom_type', 'description' => 'description', 'date_creation' => 'created_at', 'date_valid' => 'validated_at', 'tms' => 'updated_at', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_user_valid' => 'validated_by', 'fk_warehouse' => 'warehouse', 'fk_product' => 'product', 'qty' => 'quantity', 'duration' => 'duration', 'efficiency' => 'efficiency', 'status' => 'status', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'BOMLine';
    protected $parentLabelForLines = 'BomLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_actioncomm dictionary -> API ActionType (Agenda event types)
 * Alias: dmCactioncomm (for backward compatibility)
 */
class dmCactiontype extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['id' => 'id', 'code' => 'code', 'type' => 'type', 'libelle' => 'label', 'active' => 'active', 'color' => 'color', 'picto' => 'icon', 'position' => 'position'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Categorie -> API Category
 * Alias: dmCategorie (for backward compatibility with Dolibarr internal calls)
 */
class dmCategory extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'fk_parent' => 'parent', 'label' => 'label', 'description' => 'description', 'color' => 'color', 'visible' => 'visible', 'type' => 'type', 'fk_soc' => 'thirdparty', 'date_creation' => 'created_at', 'tms' => 'updated_at', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_availability dictionary -> API Availability (Delivery delays)
 */
class dmCavailability extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'active' => 'active', 'position' => 'position'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmCcivility extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dict";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    // Dictionaries expose code + label for use in forms and display
    protected $listOfPublishedFields = ['code' => 'code', 'label' => 'label'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmCcountry extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dict";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    // Dictionaries expose code + label for use in forms and display
    protected $listOfPublishedFields = ['code' => 'code', 'label' => 'label'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_incoterms dictionary -> API Incoterm
 */
class dmCincoterm extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'active' => 'active'];
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
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'civility' => 'civility', 'lastname' => 'lastname', 'firstname' => 'firstname', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_departement' => 'state', 'fk_pays' => 'country', 'phone' => 'phone', 'phone_mobile' => 'mobile', 'email' => 'email', 'note_public' => 'public_note', 'note_private' => 'private_note', 'fk_soc' => 'thirdparty', 'fk_c_type_contact' => 'contact_type'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Contrat -> API Contract
 * Alias: dmContrat (for backward compatibility with Dolibarr internal calls)
 */
class dmContract extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_customer' => 'customer_ref', 'ref_supplier' => 'supplier_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date_contrat' => 'date_contract', 'fk_soc' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_commercial_signature' => 'commercial_signature', 'fk_commercial_suivi' => 'commercial_followup', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'ContratLigne';
    protected $parentLabelForLines = 'ContractLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_payment_term dictionary -> API PaymentTerm
 */
class dmCpaymentterm extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'sortorder' => 'position', 'active' => 'active', 'libelle' => 'label', 'libelle_facture' => 'invoice_label', 'type_cdr' => 'calculation_type', 'nbjour' => 'days', 'decalage' => 'offset'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_paiement dictionary -> API PaymentType
 * Alias: dmCpaiement (for backward compatibility with Dolibarr internal calls)
 */
class dmCpaymenttype extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['id' => 'id', 'code' => 'code', 'libelle' => 'label', 'type' => 'type', 'active' => 'active', 'sortorder' => 'position'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_prospectlevel dictionary -> API ProspectStatus (Prospect levels)
 * Alias: dmCprospectlevel (for backward compatibility)
 */
class dmCprospectstatus extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['code' => 'code', 'label' => 'label', 'sortorder' => 'position', 'active' => 'active'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_shipment_mode dictionary -> API ShipmentMode
 */
class dmCshipmentmode extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'libelle' => 'label', 'description' => 'description', 'tracking' => 'tracking_url_template', 'active' => 'active'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmCstate extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dict";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    // Dictionaries expose code + label for use in forms and display
    protected $listOfPublishedFields = ['code' => 'code', 'label' => 'label'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_stcomm dictionary -> API CommercialStatus
 */
class dmCstcomm extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['id' => 'id', 'code' => 'code', 'libelle' => 'label', 'active' => 'active', 'picto' => 'icon'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_ticket_category dictionary -> API TicketCategory
 */
class dmCticketcategory extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'description' => 'description', 'fk_parent' => 'parent', 'active' => 'active', 'position' => 'position', 'use_default' => 'is_default'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_ticket_resolution dictionary -> API TicketResolution
 */
class dmCticketresolution extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'description' => 'description', 'active' => 'active', 'position' => 'position', 'use_default' => 'is_default'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_ticket_severity dictionary -> API TicketSeverity
 */
class dmCticketseverity extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'description' => 'description', 'color' => 'color', 'active' => 'active', 'position' => 'position', 'use_default' => 'is_default'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_ticket_type dictionary -> API TicketType
 */
class dmCtickettype extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'description' => 'description', 'active' => 'active', 'position' => 'position', 'use_default' => 'is_default'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_type_contact dictionary -> API ContactType (Contact roles on documents)
 */
class dmCtypecontact extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'element' => 'element', 'source' => 'source', 'code' => 'code', 'libelle' => 'label', 'active' => 'active', 'position' => 'position'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_typent dictionary -> API CompanyType
 */
class dmCtypent extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['id' => 'id', 'code' => 'code', 'libelle' => 'label', 'active' => 'active', 'position' => 'position', 'fk_country' => 'country'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr c_units dictionary -> API Unit
 */
class dmCunits extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "dictionary";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'label' => 'label', 'short_label' => 'short_label', 'unit_type' => 'unit_type', 'scale' => 'scale', 'active' => 'active', 'sortorder' => 'position'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Don -> API Donation
 * Alias: dmDon (for backward compatibility with Dolibarr internal calls)
 */
class dmDonation extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'date' => 'date_donation', 'datec' => 'created_at', 'datem' => 'updated_at', 'date_valid' => 'validated_at', 'amount' => 'amount', 'socid' => 'thirdparty_id', 'societe' => 'company_name', 'lastname' => 'lastname', 'firstname' => 'firstname', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'country_id' => 'country', 'email' => 'email', 'phone' => 'phone', 'phone_mobile' => 'mobile', 'fk_project' => 'project', 'fk_typepayment' => 'payment_type', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_user_valid' => 'validated_by', 'public' => 'is_public', 'paid' => 'paid', 'status' => 'status', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr ExpenseReport -> API ExpenseReport
 */
class dmExpenseReport extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'date_debut' => 'date_start', 'date_fin' => 'date_end', 'date_create' => 'created_at', 'tms' => 'updated_at', 'date_valid' => 'validated_at', 'date_approve' => 'approved_at', 'date_refuse' => 'refused_at', 'date_cancel' => 'cancelled_at', 'fk_user_author' => 'user', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_user_valid' => 'validated_by', 'fk_user_approve' => 'approved_by', 'fk_user_refuse' => 'refused_by', 'fk_user_cancel' => 'cancelled_by', 'fk_user_validator' => 'validator', 'fk_c_paiement' => 'payment_method', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'detail_refuse' => 'refuse_reason', 'detail_cancel' => 'cancel_reason', 'status' => 'status', 'paid' => 'paid', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'ExpenseReportLine';
    protected $parentLabelForLines = 'ExpenseReportLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
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
    private $_mappingAttributes = ['name' => 'name', 'type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'notnull' => 'required', 'noteditable' => 'readOnly', 'disabled' => 'disabled', 'visible' => 'visible', 'length' => 'max', 'position' => 'position', 'rows' => 'rows', 'options' => 'options', 'logo' => 'logo'];
    //dolibarr < - > application mapping for extrafields attributes
    private $_mappingExtrafieldsAttributes = ['type' => 'type', 'label' => 'label', 'placeholder' => 'placeholder', 'help' => 'help', 'picto' => 'icon', 'default' => 'defaultValue', 'copytoclipboard' => 'hasCopyButton', 'required' => 'required', 'noteditable' => 'readOnly', 'visible' => 'visible', 'size' => 'max', 'pos' => 'position', 'options' => 'options'];
    // smartmaker add new soft of objects type : photo, audio, video, files and signs
    public $smartNewObjectsTypes = ['smartphoto_' => 'photos', 'smartaudio_' => 'audios', 'smartvideo_' => 'videos', 'smartfile_' => 'files', 'smartsignature_' => 'signature'];
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
     * Filter attribute type list of selection
     *
     * @param   [type]  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    private function _customFilterAttributeOptions($arr)
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
    public function _customFilterAttributeVisible($val)
    {
    }
    /**
     * contacts linked to dolibarr object
     *
     * @param   [type]  $val  [$val description]
     *
     * @return  [type]        [return description]
     */
    public function _customFilterAttributeContacts($val)
    {
    }
    /**
     * filter all dolibarr properties to make beautifull objects
     * definitions for smart app
     *
     * @param   [type]  $input     input data
     * @param   [type]  $dolikey   key name "dolibarr side"
     * @param   [type]  $frontkey  key name "front / react side"
     *
     * @return  array             [return description]
     */
    public function propertiesFilter($input, $dolikey = null, $frontkey = null, $parentOverride = null)
    {
    }
    /**
     * filter all dolibarr extrafields to make beautifull objects front side
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
    /**
     * get cache value for $key
     * @return  [type]            [return description]
     */
    private function _getCacheValue($key, $property, $default)
    {
    }
    /**
     * set values to cache object
     *
     * @param   [type]  $maxWidth   [$maxWidth description]
     * @param   [type]  $maxHeight  [$maxHeight description]
     * @param   [type]  $quality    [$quality description]
     *
     * @return  [type]              [return description]
     */
    public function setGlobalMaxImageSize($maxWidth, $maxHeight = -1, $quality = 90)
    {
    }
}
/**
 * Mapping for Dolibarr Fichinter -> API Intervention
 * Alias: dmFichinter (for backward compatibility with Dolibarr internal calls)
 */
class dmIntervention extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_client' => 'customer_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'datei' => 'date_intervention', 'dateo' => 'date_start', 'datee' => 'date_end', 'fk_soc' => 'thirdparty', 'fk_projet' => 'project', 'fk_contrat' => 'contract', 'fk_user_author' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_user_valid' => 'validated_by', 'description' => 'description', 'duree' => 'duration', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'FichinterLigne';
    protected $parentLabelForLines = 'InterventionLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Facture -> API Invoice
 * Alias: dmFacture (for backward compatibility with Dolibarr internal calls)
 */
class dmInvoice extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_customer' => 'customer_ref', 'type' => 'type', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_invoice', 'date_valid' => 'validated_at', 'date_lim_reglement' => 'date_due', 'delivery_date' => 'date_delivery', 'fk_soc' => 'thirdparty', 'fk_projet' => 'project', 'fk_contrat' => 'contract', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'fk_user_modif' => 'updated_by', 'fk_cond_reglement' => 'payment_terms', 'fk_mode_reglement' => 'payment_method', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'revenuestamp' => 'revenue_stamp', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'close_code' => 'close_code', 'close_note' => 'close_note', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'FactureLigne';
    protected $parentLabelForLines = 'InvoiceLines';
    // Dolibarr field => Front field for lines
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Trait for exposing linked objects in API responses
 * Uses Dolibarr's fetchObjectLinked() mechanism
 *
 * See documentation/api-naming-convention.md
 */
trait dmLinkedObjectsTrait
{
    /**
     * Mapping of Dolibarr element types to API types
     * Used to convert internal Dolibarr names to API-friendly names
     *
     * @var array
     */
    protected static $linkedObjectTypeMapping = [
        // Commercial documents
        'propal' => 'proposal',
        'commande' => 'order',
        'facture' => 'invoice',
        'contrat' => 'contract',
        'fichinter' => 'intervention',
        // Supplier documents
        'supplier_proposal' => 'supplier_proposal',
        'order_supplier' => 'supplier_order',
        'invoice_supplier' => 'supplier_invoice',
        // Logistics
        'shipping' => 'shipment',
        'reception' => 'reception',
        // Other
        'societe' => 'thirdparty',
        'project' => 'project',
        'action' => 'agenda_event',
        'product' => 'product',
        'expensereport' => 'expense_report',
    ];
    /**
     * Get linked objects mapping for API response
     * Returns an array structure suitable for API output
     *
     * @param object $dolibarrObject The Dolibarr object with linkedObjectsIds loaded
     * @return array Array of linked objects grouped by type
     */
    protected function getLinkedObjectsMapping($dolibarrObject) : array
    {
    }
    /**
     * Get linked objects with full object data (when linkedObjects is loaded)
     *
     * @param object $dolibarrObject The Dolibarr object with linkedObjects loaded
     * @return array Array of linked objects with basic fields
     */
    protected function getLinkedObjectsWithData($dolibarrObject) : array
    {
    }
    /**
     * Map Dolibarr element type to API type
     *
     * @param string $dolibarrType The Dolibarr element type
     * @return string The API-friendly type name
     */
    protected function mapLinkedObjectType(string $dolibarrType) : string
    {
    }
    /**
     * Extract basic data from a linked object for API response
     *
     * @param object $obj The linked Dolibarr object
     * @param string $apiType The API type name
     * @return array Basic object data
     */
    protected function extractBasicLinkedObjectData($obj, string $apiType) : array
    {
    }
    /**
     * Structure for API documentation
     * Describes the linked_objects field format
     *
     * @return array
     */
    protected function getLinkedObjectsDescription() : array
    {
    }
}
/**
 * Mapping for Dolibarr Adherent -> API Member
 * Alias: dmAdherent (for backward compatibility with Dolibarr internal calls)
 */
class dmMember extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'login' => 'login', 'civility_id' => 'civility', 'lastname' => 'lastname', 'firstname' => 'firstname', 'gender' => 'gender', 'birth' => 'birthdate', 'company' => 'company', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'state_id' => 'state', 'country_id' => 'country', 'email' => 'email', 'url' => 'website', 'phone' => 'phone', 'phone_perso' => 'phone_personal', 'phone_pro' => 'phone_pro', 'phone_mobile' => 'mobile', 'fax' => 'fax', 'photo' => 'photo', 'public' => 'is_public', 'morphy' => 'nature', 'typeid' => 'member_type', 'fk_soc' => 'thirdparty', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_user_valid' => 'validated_by', 'datec' => 'created_at', 'datem' => 'updated_at', 'datevalid' => 'validated_at', 'datefin' => 'subscription_end', 'status' => 'status', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr AdherentType -> API MemberType
 * Alias: dmAdherentType (for backward compatibility with Dolibarr internal calls)
 */
class dmMemberType extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'label' => 'label', 'description' => 'description', 'morphy' => 'nature', 'duration_value' => 'duration_value', 'duration_unit' => 'duration_unit', 'subscription' => 'subscription_required', 'amount' => 'amount', 'caneditamount' => 'can_edit_amount', 'vote' => 'can_vote', 'note_public' => 'public_note', 'note' => 'private_note', 'mail_valid' => 'mail_validation_template', 'mail_subscription' => 'mail_subscription_template', 'mail_resiliate' => 'mail_resiliate_template', 'mail_exclude' => 'mail_exclude_template', 'email' => 'email', 'status' => 'status'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Mo -> API Mo (Manufacturing Order)
 */
class dmMo extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'mrptype' => 'mrp_type', 'qty' => 'quantity', 'date_creation' => 'created_at', 'date_valid' => 'validated_at', 'tms' => 'updated_at', 'date_start_planned' => 'date_start_planned', 'date_end_planned' => 'date_end_planned', 'fk_user_creat' => 'created_by', 'fk_user_modif' => 'updated_by', 'fk_warehouse' => 'warehouse', 'fk_soc' => 'thirdparty', 'fk_product' => 'product', 'fk_bom' => 'bom', 'fk_project' => 'project', 'status' => 'status', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'MoLine';
    protected $parentLabelForLines = 'MoLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr MultiCurrency -> API Multicurrency
 */
class dmMulticurrency extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'code' => 'code', 'name' => 'name', 'rate' => 'rate', 'date_create' => 'created_at', 'fk_user' => 'created_by'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Commande -> API Order
 * Alias: dmCommande (for backward compatibility with Dolibarr internal calls)
 */
class dmOrder extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_customer' => 'customer_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_order', 'date_valid' => 'validated_at', 'date_livraison' => 'date_delivery', 'fk_soc' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'fk_user_modif' => 'updated_by', 'fk_cond_reglement' => 'payment_terms', 'fk_mode_reglement' => 'payment_method', 'fk_availability' => 'availability', 'fk_shipping_method' => 'shipping_method', 'fk_input_reason' => 'source_reason', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'billed' => 'is_billed', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'OrderLine';
    protected $parentLabelForLines = 'OrderLines';
    // Dolibarr field => Front field for lines
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmProduct extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'description' => 'description', 'type' => 'type', 'price' => 'price_excl_tax', 'price_ttc' => 'price_incl_tax', 'price_min' => 'price_min_excl_tax', 'price_min_ttc' => 'price_min_incl_tax', 'price_base_type' => 'price_base_type', 'tva_tx' => 'vat_rate', 'barcode' => 'barcode', 'weight' => 'weight', 'length' => 'length', 'width' => 'width', 'height' => 'height', 'stock' => 'stock', 'seuil_stock_alerte' => 'stock_alert_threshold', 'note_public' => 'public_note', 'note_private' => 'private_note', 'datec' => 'created_at', 'tosell' => 'for_sale', 'tobuy' => 'for_purchase'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmProject extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'title' => 'title', 'datec' => 'created_at', 'dateo' => 'date_start', 'datee' => 'date_end', 'fk_soc' => 'customer', 'description' => 'description', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Propal -> API Proposal
 * Alias: dmPropal (for backward compatibility with Dolibarr internal calls)
 */
class dmProposal extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_client' => 'customer_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_proposal', 'date_valid' => 'validated_at', 'date_signature' => 'signed_at', 'fin_validite' => 'date_expiry', 'delivery_date' => 'date_delivery', 'fk_soc' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'fk_user_modif' => 'updated_by', 'user_signature' => 'signed_by', 'fk_cond_reglement' => 'payment_terms', 'fk_mode_reglement' => 'payment_method', 'fk_availability' => 'availability', 'fk_shipping_method' => 'shipping_method', 'fk_input_reason' => 'source_reason', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'PropaleLigne';
    protected $parentLabelForLines = 'ProposalLines';
    // Dolibarr field => Front field for lines
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Reception -> API Reception
 */
class dmReception extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_supplier' => 'supplier_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date_reception' => 'date_reception', 'date_delivery' => 'date_delivery', 'date_valid' => 'validated_at', 'socid' => 'thirdparty', 'fk_projet' => 'project', 'origin_id' => 'origin_id', 'origin' => 'origin_type', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'entrepot_id' => 'warehouse', 'tracking_number' => 'tracking_number', 'tracking_url' => 'tracking_url', 'fk_shipping_method' => 'shipping_method', 'trueWeight' => 'weight', 'weight_units' => 'weight_units', 'trueWidth' => 'width', 'width_units' => 'width_units', 'trueHeight' => 'height', 'height_units' => 'height_units', 'trueDepth' => 'depth', 'depth_units' => 'depth_units', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'billed' => 'billed'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'ReceptionLine';
    protected $parentLabelForLines = 'ReceptionLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Expedition -> API Shipment
 * Alias: dmExpedition (for backward compatibility with Dolibarr internal calls)
 */
class dmShipment extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_customer' => 'customer_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date_expedition' => 'date_shipment', 'date_delivery' => 'date_delivery', 'date_valid' => 'validated_at', 'socid' => 'thirdparty', 'fk_projet' => 'project', 'commande_id' => 'order', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'entrepot_id' => 'warehouse', 'tracking_number' => 'tracking_number', 'tracking_url' => 'tracking_url', 'fk_shipping_method' => 'shipping_method', 'trueWeight' => 'weight', 'weight_units' => 'weight_units', 'trueWidth' => 'width', 'width_units' => 'width_units', 'trueHeight' => 'height', 'height_units' => 'height_units', 'trueDepth' => 'depth', 'depth_units' => 'depth_units', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'billed' => 'billed', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'ExpeditionLigne';
    protected $parentLabelForLines = 'ShipmentLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Subscription -> API Subscription (Member subscription)
 */
class dmSubscription extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'fk_adherent' => 'member', 'fk_type' => 'member_type', 'datec' => 'created_at', 'datem' => 'updated_at', 'dateh' => 'date_start', 'datef' => 'date_end', 'amount' => 'amount', 'fk_bank' => 'bank_line', 'note' => 'note'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr FactureFournisseur -> API SupplierInvoice
 * Alias: dmFactureFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierInvoice extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_supplier' => 'supplier_ref', 'label' => 'label', 'type' => 'type', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_invoice', 'date_echeance' => 'date_due', 'socid' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'cond_reglement_id' => 'payment_terms', 'mode_reglement_id' => 'payment_method', 'fk_account' => 'bank_account', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'paye' => 'paid', 'close_code' => 'close_code', 'close_note' => 'close_note', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'SupplierInvoiceLine';
    protected $parentLabelForLines = 'SupplierInvoiceLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr CommandeFournisseur -> API SupplierOrder
 * Alias: dmCommandeFournisseur (for backward compatibility with Dolibarr internal calls)
 */
class dmSupplierOrder extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_supplier' => 'supplier_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_order', 'date_valid' => 'validated_at', 'date_approve' => 'approved_at', 'date_commande' => 'date_order_supplier', 'delivery_date' => 'date_delivery', 'socid' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'fk_user_approve' => 'approved_by', 'cond_reglement_id' => 'payment_terms', 'mode_reglement_id' => 'payment_method', 'fk_account' => 'bank_account', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'billed' => 'billed', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'CommandeFournisseurLigne';
    protected $parentLabelForLines = 'SupplierOrderLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr SupplierProposal -> API SupplierProposal
 */
class dmSupplierProposal extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    use \SmartAuth\DolibarrMapping\dmLinesTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'ref_supplier' => 'supplier_ref', 'datec' => 'created_at', 'tms' => 'updated_at', 'date' => 'date_proposal', 'date_validation' => 'validated_at', 'delivery_date' => 'date_delivery', 'socid' => 'thirdparty', 'fk_projet' => 'project', 'fk_user_author' => 'created_by', 'fk_user_valid' => 'validated_by', 'fk_user_close' => 'closed_by', 'cond_reglement_id' => 'payment_terms', 'mode_reglement_id' => 'payment_method', 'total_ht' => 'total_excl_tax', 'total_tva' => 'total_vat', 'total_localtax1' => 'total_local_tax1', 'total_localtax2' => 'total_local_tax2', 'total_ttc' => 'total_incl_tax', 'note_public' => 'public_note', 'note_private' => 'private_note', 'statut' => 'status', 'fk_multicurrency' => 'multicurrency_id', 'multicurrency_code' => 'multicurrency_code', 'multicurrency_tx' => 'multicurrency_rate', 'multicurrency_total_ht' => 'multicurrency_total_excl_tax', 'multicurrency_total_tva' => 'multicurrency_total_vat', 'multicurrency_total_ttc' => 'multicurrency_total_incl_tax'];
    // Configuration for lines support
    protected $parentClassNameForLines = 'SupplierProposalLine';
    protected $parentLabelForLines = 'SupplierProposalLines';
    // Dolibarr field => Front field for lines
    protected $listOfPublishedFieldsForLines = [];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmTask extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'description' => 'description', 'fk_projet' => 'project', 'fk_task_parent' => 'parent_task', 'date_c' => 'created_at', 'date_start' => 'date_start', 'date_end' => 'date_end', 'planned_workload' => 'planned_workload', 'duration_effective' => 'time_spent', 'progress' => 'progress', 'priority' => 'priority', 'fk_user_creat' => 'created_by'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Societe -> API Thirdparty
 * Alias: dmSociete (for backward compatibility with Dolibarr internal calls)
 */
class dmThirdparty extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'nom' => 'name', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_departement' => 'state', 'fk_pays' => 'country', 'phone' => 'phone', 'url' => 'website', 'email' => 'email', 'note_public' => 'public_note', 'note_private' => 'private_note', 'logo' => 'logo'];
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
    /**
     * return mini logo file
     *
     * @param   [type]  $logoFileName  [$logoFileName description]
     *
     * @return  [type]                 [return description]
     */
    private function _miniLogoFileName($logoFileName)
    {
    }
}
/**
 * Mapping for Dolibarr Ticket -> API Ticket
 */
class dmTicket extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'track_id' => 'track_id', 'subject' => 'subject', 'message' => 'message', 'datec' => 'created_at', 'tms' => 'updated_at', 'date_read' => 'read_at', 'date_close' => 'closed_at', 'date_last_msg_sent' => 'last_message_at', 'fk_soc' => 'thirdparty', 'fk_project' => 'project', 'fk_user_create' => 'created_by', 'fk_user_assign' => 'assigned_to', 'origin_email' => 'origin_email', 'email_from' => 'email_from', 'type_code' => 'type_code', 'type_label' => 'type_label', 'category_code' => 'category_code', 'category_label' => 'category_label', 'severity_code' => 'severity_code', 'severity_label' => 'severity_label', 'resolution' => 'resolution', 'progress' => 'progress', 'timing' => 'timing', 'status' => 'status', 'note_public' => 'public_note', 'note_private' => 'private_note'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
class dmUser extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'login' => 'login', 'lastname' => 'lastname', 'firstname' => 'firstname', 'gender' => 'gender', 'civility_code' => 'civility', 'email' => 'email', 'office_phone' => 'phone', 'user_mobile' => 'mobile', 'job' => 'job_title', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_state' => 'state', 'fk_country' => 'country', 'datec' => 'created_at', 'statut' => 'status'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}
/**
 * Mapping for Dolibarr Entrepot -> API Warehouse
 * Alias: dmEntrepot (for backward compatibility with Dolibarr internal calls)
 */
class dmWarehouse extends \SmartAuth\DolibarrMapping\dmBase
{
    use \SmartAuth\DolibarrMapping\dmTrait;
    protected $type = "object";
    // Dolibarr field => Front field
    // See documentation/api-naming-convention.md
    protected $listOfPublishedFields = ['rowid' => 'id', 'ref' => 'ref', 'label' => 'label', 'description' => 'description', 'lieu' => 'location', 'address' => 'address', 'zip' => 'zip', 'town' => 'city', 'fk_departement' => 'state', 'fk_pays' => 'country', 'phone' => 'phone', 'fax' => 'fax', 'fk_parent' => 'parent_warehouse', 'fk_projet' => 'project', 'statut' => 'status'];
    /**
     * object constructor
     *
     * @return  [type]  [return description]
     */
    public function __construct()
    {
    }
}