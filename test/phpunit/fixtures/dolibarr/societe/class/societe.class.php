<?php

/**
 * Minimal Societe (third party) class for integration tests
 * Based on Dolibarr's societe/class/societe.class.php
 */
class Societe extends CommonObject
{
    public $element = 'societe';
    public $table_element = 'societe';
    public $fk_element = 'fk_soc';

    // Main properties
    public $name;
    public $name_alias;
    public $nom;  // Deprecated, use name
    public $particulier;
    public $address;
    public $zip;
    public $town;
    public $state_id;
    public $state_code;
    public $state;
    public $region_id;
    public $region_code;
    public $region;
    public $country_id;
    public $country_code;
    public $country;

    public $email;
    public $phone;
    public $fax;
    public $url;
    public $socialnetworks = [];

    public $barcode;

    public $idprof1;
    public $idprof2;
    public $idprof3;
    public $idprof4;
    public $idprof5;
    public $idprof6;

    public $tva_intra;
    public $tva_assuj;

    public $capital;
    public $typent_id;
    public $typent_code;
    public $effectif;
    public $effectif_id;
    public $forme_juridique_code;
    public $forme_juridique;

    public $client;      // 0=not customer, 1=customer, 2=prospect, 3=customer+prospect
    public $prospect;
    public $fournisseur; // 0=not supplier, 1=supplier

    public $code_client;
    public $code_fournisseur;
    public $code_compta_client;
    public $code_compta_fournisseur;

    public $note_public;
    public $note_private;

    public $stcomm_id;
    public $status;

    public $price_level;
    public $outstanding_limit;
    public $order_min_amount;
    public $supplier_order_min_amount;

    public $parent;
    public $default_lang;

    public $logo;
    public $logo_squarred;

    public $fields = [
        'nom' => ['type' => 'varchar(128)', 'notnull' => 1],
        'name_alias' => ['type' => 'varchar(128)'],
        'entity' => ['type' => 'integer', 'default' => 1],
        'address' => ['type' => 'text'],
        'zip' => ['type' => 'varchar(25)'],
        'town' => ['type' => 'varchar(50)'],
        'country_id' => ['type' => 'integer'],
        'email' => ['type' => 'varchar(128)'],
        'phone' => ['type' => 'varchar(20)'],
        'fax' => ['type' => 'varchar(20)'],
        'url' => ['type' => 'varchar(255)'],
        'client' => ['type' => 'integer', 'default' => 0],
        'fournisseur' => ['type' => 'integer', 'default' => 0],
        'code_client' => ['type' => 'varchar(24)'],
        'code_fournisseur' => ['type' => 'varchar(24)'],
        'status' => ['type' => 'integer', 'default' => 1],
    ];

    /**
     * Constructor
     */
    public function __construct($db)
    {
        parent::__construct($db);
    }

    /**
     * Create third party
     */
    public function create($user, $notrigger = 0)
    {
        // Set nom from name for compatibility
        if (!empty($this->name) && empty($this->nom)) {
            $this->nom = $this->name;
        }
        if (!empty($this->nom) && empty($this->name)) {
            $this->name = $this->nom;
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * Fetch third party
     */
    public function fetch($rowid = 0, $ref = '', $ref_ext = '', $barcode = '', $idprof1 = '', $idprof2 = '', $idprof3 = '', $idprof4 = '', $idprof5 = '', $idprof6 = '', $email = '', $ref_alias = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "societe WHERE";

        if ($rowid > 0) {
            $sql .= " rowid = " . (int) $rowid;
        } elseif (!empty($ref)) {
            $sql .= " nom = '" . $this->db->escape($ref) . "'";
        } elseif (!empty($email)) {
            $sql .= " email = '" . $this->db->escape($email) . "'";
        } else {
            $this->error = 'No search criteria provided';
            return -1;
        }

        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    $this->setVarsFromFetchObj($obj);
                    $this->name = $obj->nom;
                    $this->db->free($resql);
                    return $this->id;
                }
            }
            $this->db->free($resql);
            return 0;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Update third party
     */
    public function update($id, $user = null, $notrigger = 0, $allowmodcodeclient = 0, $allowmodcodefournisseur = 0, $action = 'update', $nosyncmember = 1)
    {
        // Ensure nom is set
        if (!empty($this->name) && empty($this->nom)) {
            $this->nom = $this->name;
        }

        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete third party
     */
    public function delete($id, $user = null, $notrigger = 0)
    {
        $this->id = $id;
        return $this->deleteCommon($user, $notrigger);
    }

    /**
     * Return full name
     */
    public function getNomUrl($withpicto = 0, $option = '', $maxlen = 0, $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
    {
        return $this->name ?: $this->nom;
    }

    /**
     * Get name
     */
    public function getName()
    {
        return $this->name ?: $this->nom;
    }

    /**
     * Set to customer/prospect/supplier
     */
    public function set_as_client()
    {
        $this->client = 1;
        return 1;
    }

    public function set_as_prospect()
    {
        $this->client = 2;
        return 1;
    }

    public function set_as_fournisseur()
    {
        $this->fournisseur = 1;
        return 1;
    }

    /**
     * Init as specimen
     */
    public function initAsSpecimen()
    {
        $this->initAsSpecimenCommon();

        $this->name = 'Test Company';
        $this->nom = 'Test Company';
        $this->address = '123 Test Street';
        $this->zip = '75001';
        $this->town = 'Paris';
        $this->country_id = 1;
        $this->email = 'contact@testcompany.com';
        $this->phone = '+33123456789';
        $this->client = 1;
        $this->fournisseur = 0;
        $this->status = 1;

        return 1;
    }

    /**
     * Load the MySoc object (company info)
     */
    public function setMysoc($conf)
    {
        // In tests, just set some basic info
        $this->name = $conf->global->MAIN_INFO_SOCIETE_NOM ?? 'Test Company';
        $this->nom = $this->name;
        $this->country_id = 1;
        $this->country_code = 'FR';
        return 1;
    }
}
