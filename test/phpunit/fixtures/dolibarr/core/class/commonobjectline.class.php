<?php

/**
 * Minimal CommonObjectLine for integration tests
 */
abstract class CommonObjectLine
{
    public $db;
    public $id;
    public $rowid;
    public $fk_element;
    public $rang;
    public $element;
    public $table_element;
    public $error = '';
    public $errors = [];

    public function __construct($db = null)
    {
        $this->db = $db;
    }
}
