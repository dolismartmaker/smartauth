<?php

/**
 * Minimal User class for integration tests
 * Based on Dolibarr's user/class/user.class.php
 */
class User extends CommonObject
{
    public $element = 'user';
    public $table_element = 'user';

    // User properties
    public $login;
    public $pass;
    public $pass_crypted;
    public $pass_indatabase;
    public $pass_indatabase_crypted;

    public $civility_id;
    public $civility_code;
    public $lastname;
    public $firstname;
    public $fullname;

    public $gender;
    public $birth;

    public $email;
    public $personal_email;
    public $phone;
    public $phone_pro;
    public $phone_perso;
    public $phone_mobile;
    public $fax;

    public $address;
    public $zip;
    public $town;
    public $state_id;
    public $state_code;
    public $state;
    public $country_id;
    public $country_code;
    public $country;

    public $socid;
    public $contact_id;
    public $fk_member;
    public $fk_user;
    public $fk_user_expense_validator;
    public $fk_user_holiday_validator;

    public $admin;
    public $employee;
    public $statut;
    public $note_public;
    public $note_private;

    public $datec;
    public $datem;
    public $dateemployment;
    public $dateemploymentend;

    public $datelastlogin;
    public $datepreviouslogin;

    public $photo;
    public $lang;

    public $rights;
    public $all_permissions_are_loaded;
    public $nb_rights;

    public $conf;

    public $fields = [
        'login' => ['type' => 'varchar(50)', 'notnull' => 1],
        'pass_crypted' => ['type' => 'varchar(128)'],
        'lastname' => ['type' => 'varchar(50)'],
        'firstname' => ['type' => 'varchar(50)'],
        'email' => ['type' => 'varchar(255)'],
        'admin' => ['type' => 'integer', 'default' => 0],
        'employee' => ['type' => 'integer', 'default' => 1],
        'statut' => ['type' => 'integer', 'default' => 1],
        'entity' => ['type' => 'integer', 'default' => 1],
        'socid' => ['type' => 'integer'],
    ];

    /**
     * Constructor
     */
    public function __construct($db)
    {
        parent::__construct($db);
        $this->rights = new stdClass();
        $this->all_permissions_are_loaded = 0;
    }

    /**
     * Create user
     */
    public function create($user, $notrigger = 0)
    {
        // Hash password if provided
        if (!empty($this->pass) && empty($this->pass_crypted)) {
            $this->pass_crypted = password_hash($this->pass, PASSWORD_DEFAULT);
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * Fetch user by id, login or email
     */
    public function fetch($id = 0, $login = '', $sid = '', $loadpersonalconf = 0, $entity = -1, $email = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "user WHERE";

        if ($id > 0) {
            $sql .= " rowid = " . (int) $id;
        } elseif (!empty($login)) {
            $sql .= " login = '" . $this->db->escape($login) . "'";
        } elseif (!empty($email)) {
            $sql .= " email = '" . $this->db->escape($email) . "'";
        } else {
            $this->error = 'No search criteria provided';
            return -1;
        }

        if ($entity >= 0) {
            $sql .= " AND entity = " . (int) $entity;
        }

        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    $this->setVarsFromFetchObj($obj);
                    $this->fullname = trim($this->firstname . ' ' . $this->lastname);
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
     * Update user
     */
    public function update($user, $notrigger = 0, $nosyncmember = 0, $nosyncmemberpass = 0, $nosynccontact = 0)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete user
     */
    public function delete($user, $notrigger = 0, $entity = 0)
    {
        return $this->deleteCommon($user, $notrigger);
    }

    /**
     * Load user rights
     */
    public function getrights($moduletag = '', $forcereload = 0)
    {
        if ($this->all_permissions_are_loaded && !$forcereload) {
            return;
        }

        // In tests, just set some default rights
        $this->rights->user = new stdClass();
        $this->rights->user->self = new stdClass();
        $this->rights->user->self->creer = 1;
        $this->rights->user->self->password = 1;

        $this->all_permissions_are_loaded = 1;
        $this->nb_rights = 2;
    }

    /**
     * Check password
     */
    public function checkPassword($password)
    {
        if (!empty($this->pass_crypted)) {
            return password_verify($password, $this->pass_crypted);
        }
        return false;
    }

    /**
     * Set password
     */
    public function setPassword($user, $password, $changelater = 0, $notrigger = 0, $nosyncmember = 0)
    {
        $this->pass_crypted = password_hash($password, PASSWORD_DEFAULT);

        $sql = "UPDATE " . MAIN_DB_PREFIX . "user";
        $sql .= " SET pass_crypted = '" . $this->db->escape($this->pass_crypted) . "'";
        $sql .= " WHERE rowid = " . (int) $this->id;

        return $this->db->query($sql) ? 1 : -1;
    }

    /**
     * Get full name
     */
    public function getFullName($langs = null, $option = 0, $nameorder = -1, $maxlen = 0)
    {
        $name = trim($this->firstname . ' ' . $this->lastname);
        if ($maxlen > 0 && strlen($name) > $maxlen) {
            $name = substr($name, 0, $maxlen) . '...';
        }
        return $name;
    }

    /**
     * Init as specimen
     */
    public function initAsSpecimen()
    {
        $this->initAsSpecimenCommon();

        $this->login = 'testuser';
        $this->lastname = 'Test';
        $this->firstname = 'User';
        $this->email = 'testuser@example.com';
        $this->admin = 0;
        $this->employee = 1;
        $this->statut = 1;
        $this->entity = 1;

        return 1;
    }

    /**
     * Check if user has right
     */
    public function hasRight($module, $permlevel1, $permlevel2 = '')
    {
        if ($this->admin) {
            return true;
        }

        if (!empty($permlevel2)) {
            return !empty($this->rights->$module->$permlevel1->$permlevel2);
        }
        return !empty($this->rights->$module->$permlevel1);
    }

    /**
     * Verify password is correct (for login)
     */
    public static function checkLoginPassEntity($usertotest, $passwordtotest, $entitytotest, $authmode = [], $context = '')
    {
        // This is a simplified version for tests
        // In real Dolibarr, this checks against the database
        return $usertotest;
    }
}
