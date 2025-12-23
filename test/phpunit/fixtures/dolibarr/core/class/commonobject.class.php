<?php

/**
 * Minimal CommonObject implementation for integration tests
 * Based on Dolibarr's core/class/commonobject.class.php
 */
abstract class CommonObject
{
    /** @var DoliDB Database handler */
    public $db;

    /** @var int Object ID */
    public $id;

    /** @var int Alias for id */
    public $rowid;

    /** @var string Reference */
    public $ref;

    /** @var string Entity */
    public $entity = 1;

    /** @var int Status */
    public $status;

    /** @var string Error message */
    public $error = '';

    /** @var array Error messages */
    public $errors = [];

    /** @var string Element type */
    public $element = '';

    /** @var string Table name */
    public $table_element = '';

    /** @var string Module name */
    public $module = '';

    /** @var array Fields definition */
    public $fields = [];

    /** @var int Multi-entity management */
    public $ismultientitymanaged = 0;

    /** @var int Extrafields management */
    public $isextrafieldmanaged = 0;

    /** @var int Creation date timestamp */
    public $date_creation;

    /** @var int Modification date timestamp */
    public $date_modification;

    /** @var int Validation date timestamp */
    public $date_validation;

    /** @var int User who created */
    public $fk_user_creat;

    /** @var int User who modified */
    public $fk_user_modif;

    /** @var int User who validated */
    public $fk_user_valid;

    /** @var string Import key */
    public $import_key;

    /** @var array Linked objects cache */
    public $linkedObjectsIds = [];

    /** @var object|null Canvas object */
    public $canvas;

    /**
     * Constructor
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create object in database
     */
    public function createCommon($user, $notrigger = false)
    {
        $error = 0;

        $now = dol_now();

        $fieldvalues = $this->setSaveQuery();

        if (empty($fieldvalues)) {
            $this->error = 'No fields to insert';
            return -1;
        }

        // Add creation fields
        $fieldvalues['date_creation'] = $this->db->idate($now);
        if (!empty($user->id)) {
            $fieldvalues['fk_user_creat'] = $user->id;
        }

        $keys = [];
        $values = [];
        foreach ($fieldvalues as $k => $v) {
            $keys[] = $k;
            if ($v === null || $v === 'NULL') {
                $values[] = 'NULL';
            } elseif (is_numeric($v)) {
                $values[] = $v;
            } else {
                $values[] = "'" . $this->db->escape($v) . "'";
            }
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " (" . implode(',', $keys) . ")";
        $sql .= " VALUES (" . implode(',', $values) . ")";

        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = $this->db->lasterror();
            $error++;
        }

        if (!$error) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            $this->rowid = $this->id;
        }

        if ($error) {
            return -1;
        }

        return $this->id;
    }

    /**
     * Load object from database
     */
    public function fetchCommon($id, $ref = null, $morewhere = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element;
        if ($id > 0) {
            $sql .= " WHERE rowid = " . (int) $id;
        } elseif ($ref) {
            $sql .= " WHERE ref = '" . $this->db->escape($ref) . "'";
        } else {
            $this->error = 'No id or ref provided';
            return -1;
        }

        if ($morewhere) {
            $sql .= " AND " . $morewhere;
        }

        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    $this->setVarsFromFetchObj($obj);
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
     * Update object in database
     */
    public function updateCommon($user, $notrigger = false)
    {
        $error = 0;

        $now = dol_now();

        $fieldvalues = $this->setSaveQuery();

        if (empty($fieldvalues)) {
            $this->error = 'No fields to update';
            return -1;
        }

        // Add modification fields
        $fieldvalues['tms'] = $this->db->idate($now);
        if (!empty($user->id)) {
            $fieldvalues['fk_user_modif'] = $user->id;
        }

        $sets = [];
        foreach ($fieldvalues as $k => $v) {
            if ($v === null || $v === 'NULL') {
                $sets[] = $k . " = NULL";
            } elseif (is_numeric($v)) {
                $sets[] = $k . " = " . $v;
            } else {
                $sets[] = $k . " = '" . $this->db->escape($v) . "'";
            }
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET " . implode(', ', $sets);
        $sql .= " WHERE rowid = " . (int) $this->id;

        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = $this->db->lasterror();
            $error++;
        }

        return $error ? -1 : 1;
    }

    /**
     * Delete object from database
     */
    public function deleteCommon($user, $notrigger = false, $forcechilddeletion = 0)
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE rowid = " . (int) $this->id;

        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Set status
     */
    public function setStatusCommon($user, $status, $notrigger = 0, $triggercode = '')
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET status = " . (int) $status;
        $sql .= " WHERE rowid = " . (int) $this->id;

        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->status = $status;
        return 1;
    }

    /**
     * Prepare field values for save query
     */
    protected function setSaveQuery()
    {
        $fieldvalues = [];

        foreach ($this->fields as $key => $info) {
            if (!isset($this->$key)) {
                continue;
            }
            if (in_array($key, ['rowid', 'date_creation', 'tms', 'fk_user_creat', 'fk_user_modif'])) {
                continue;
            }

            $value = $this->$key;

            if ($value === null && empty($info['notnull'])) {
                $fieldvalues[$key] = 'NULL';
            } else {
                $fieldvalues[$key] = $value;
            }
        }

        return $fieldvalues;
    }

    /**
     * Set object properties from fetch result
     */
    public function setVarsFromFetchObj($obj)
    {
        if (!is_object($obj)) {
            return;
        }

        foreach (get_object_vars($obj) as $key => $value) {
            if ($key === 'rowid') {
                $this->id = $value;
                $this->rowid = $value;
            }
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get field list for SELECT
     */
    public function getFieldList($alias = '')
    {
        $prefix = $alias ? $alias . '.' : '';
        $fields = [];

        $fields[] = $prefix . 'rowid';

        foreach (array_keys($this->fields) as $field) {
            $fields[] = $prefix . $field;
        }

        return implode(', ', $fields);
    }

    /**
     * Call trigger (simplified for tests - does nothing)
     */
    public function call_trigger($triggercode, $user)
    {
        // Triggers are not executed in tests
        return 1;
    }

    /**
     * Validate object
     */
    public function validate($user, $notrigger = 0)
    {
        return $this->setStatusCommon($user, 1, $notrigger);
    }

    /**
     * Set to draft
     */
    public function setDraft($user, $notrigger = 0)
    {
        return $this->setStatusCommon($user, 0, $notrigger);
    }

    /**
     * Get errors as string
     */
    public function errorsToString()
    {
        if (!empty($this->errors)) {
            return implode(', ', $this->errors);
        }
        return $this->error;
    }

    /**
     * Init as specimen (for tests)
     */
    public function initAsSpecimenCommon()
    {
        $this->id = 0;
        $this->ref = 'SPECIMEN';
        $this->status = 0;
        $this->date_creation = dol_now();

        foreach ($this->fields as $key => $info) {
            $type = $info['type'] ?? 'varchar';

            if (strpos($type, 'integer') !== false) {
                $this->$key = 1;
            } elseif (strpos($type, 'double') !== false || strpos($type, 'price') !== false) {
                $this->$key = 10.5;
            } elseif (strpos($type, 'date') !== false) {
                $this->$key = dol_now();
            } else {
                $this->$key = 'Test ' . $key;
            }
        }

        return 1;
    }

    /**
     * Copy linked contacts from another object (simplified)
     */
    public function copy_linked_contact($fromObject, $type = '')
    {
        return 1;
    }

    /**
     * Set errors from another object
     */
    public function setErrorsFromObject($object)
    {
        if (!empty($object->error)) {
            $this->error = $object->error;
        }
        if (!empty($object->errors)) {
            $this->errors = array_merge($this->errors, $object->errors);
        }
    }

    /**
     * Fetch lines (simplified - should be overridden)
     */
    public function fetchLinesCommon($morewhere = '')
    {
        return 1;
    }

    /**
     * Delete line
     */
    public function deleteLineCommon($user, $idline, $notrigger = false)
    {
        // Override in child classes
        return 1;
    }

    /**
     * Get next ref (simplified)
     */
    public function getNextNumRef()
    {
        return 'REF-' . date('YmdHis') . '-' . rand(1000, 9999);
    }
}
