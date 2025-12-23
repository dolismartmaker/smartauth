<?php

/**
 * Minimal ExtraFields class for integration tests
 */
class ExtraFields
{
    public $db;
    public $error = '';
    public $errors = [];

    public $attributes = [];
    public $attribute_type = [];
    public $attribute_size = [];
    public $attribute_computed = [];
    public $attribute_unique = [];
    public $attribute_required = [];
    public $attribute_param = [];
    public $attribute_pos = [];
    public $attribute_default = [];
    public $attribute_langfile = [];
    public $attribute_list = [];

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function fetch_name_optionals_label($elementtype, $forceload = false, $attrname = '')
    {
        // Return empty - no extra fields in tests
        $this->attributes[$elementtype] = [];
        return 0;
    }

    public function load($elementtype, $forceload = false)
    {
        return $this->fetch_name_optionals_label($elementtype, $forceload);
    }

    public function addElement($attrname, $label, $type, $pos, $size, $elementtype, $unique = 0, $required = 0, $default_value = '', $param = '', $alwayseditable = 0, $perms = '', $list = '-1', $help = '', $computed = '', $entity = '', $langfile = '', $enabled = '1', $totalizable = 0, $printable = 0, $moreparams = [])
    {
        return 1;
    }

    public function update($attrname, $label, $type, $size, $elementtype, $unique = 0, $required = 0, $pos = 0, $param = '', $alwayseditable = 0, $perms = '', $list = '', $help = '', $default_value = '', $computed = '', $entity = '', $langfile = '', $enabled = '1', $totalizable = 0, $printable = 0, $moreparams = [])
    {
        return 1;
    }

    public function delete($attrname, $elementtype = '')
    {
        return 1;
    }

    public function getAlignFlag($key, $extrafieldsobjectkey = '')
    {
        return '';
    }

    public function showInputField($key, $value, $moreparam = '', $keysuffix = '', $keyprefix = '', $morecss = '', $object = null, $extrafieldsobjectkey = '')
    {
        return '<input type="text" name="' . $keyprefix . $key . $keysuffix . '" value="' . htmlentities($value) . '">';
    }

    public function showOutputField($key, $value, $moreparam = '', $extrafieldsobjectkey = '')
    {
        return htmlentities($value);
    }

    public function setOptionalsFromPost($extralabels, &$object, $onlykey = '', $todefaultifmissing = 0)
    {
        return 1;
    }
}
