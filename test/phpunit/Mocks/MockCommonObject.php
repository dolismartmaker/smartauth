<?php

namespace SmartAuth\Tests\Mocks;

/**
 * Mock CommonObject for unit tests
 * Simulates Dolibarr's CommonObject without requiring a real Dolibarr installation
 */
class MockCommonObject
{
    public $db;
    public $id;
    public $rowid;
    public $ref;
    public $status;
    public $element = 'mock_element';
    public $table_element = 'mock_table';
    public $module = 'mock_module';
    public $fields = [];
    public $error = '';
    public $errors = [];

    public function __construct($db = null)
    {
        $this->db = $db;
    }

    public function createCommon($user, $notrigger = false)
    {
        return $this->id > 0 ? $this->id : 1;
    }

    public function fetchCommon($id, $ref = null)
    {
        $this->id = $id;
        $this->rowid = $id;
        return $id > 0 ? 1 : -1;
    }

    public function updateCommon($user, $notrigger = false)
    {
        return 1;
    }

    public function deleteCommon($user, $notrigger = false)
    {
        return 1;
    }

    public function setStatusCommon($user, $status, $notrigger = 0, $triggercode = '')
    {
        $this->status = $status;
        return 1;
    }

    public function getFieldList($alias = '')
    {
        return '*';
    }

    public function fetchLinesCommon()
    {
        return 1;
    }

    public function initAsSpecimenCommon()
    {
        $this->id = 0;
        return 1;
    }

    public function setErrorsFromObject($object)
    {
        if (!empty($object->error)) {
            $this->error = $object->error;
        }
        if (!empty($object->errors)) {
            $this->errors = array_merge($this->errors, $object->errors);
        }
    }

    public function deleteLineCommon($user, $idline, $notrigger = false)
    {
        return 1;
    }

    public function call_trigger($triggercode, $user)
    {
        return 1;
    }

    public function copy_linked_contact($object, $type)
    {
        return 1;
    }

    public function setVarsFromFetchObj($obj)
    {
        if (!is_object($obj)) {
            return;
        }
        foreach (get_object_vars($obj) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
