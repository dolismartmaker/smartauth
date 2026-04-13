<?php
/**
 * SmartAuth numbering module - Standard
 *
 * @package     SmartAuth
 * @subpackage  Core
 */

/**
 * Class to manage the Standard numbering rule for SmartAuth
 */
class mod_auth_standard
{
    /**
     * Dolibarr version of the loaded document
     * @var string
     */
    public $version = 'dolibarr';

    /**
     * Prefix for numbering
     * @var string
     */
    public $prefix = 'SA';

    /**
     * @var string Error code (or message)
     */
    public $error = '';

    /**
     * @var string name
     */
    public $name = 'standard';

    /**
     * Return description of numbering module
     *
     * @return string Text with description
     */
    public function info()
    {
        global $langs;
        return $langs->trans("SimpleNumRefModelDesc", $this->prefix);
    }

    /**
     * Return an example of numbering
     *
     * @return string Example
     */
    public function getExample()
    {
        return $this->prefix . "2501-0001";
    }

    /**
     * Checks if the numbers already in the database do not
     * cause conflicts that would prevent this numbering working.
     *
     * @param  object $object Object we need next value for
     * @return bool           false if conflict, true if ok
     */
    public function canBeActivated($object)
    {
        return true;
    }

    /**
     * Return next free value
     *
     * @param  object $object Object we need next value for
     * @return string         Value if OK, empty string if KO
     */
    public function getNextValue($object)
    {
        global $db, $conf;

        // Get the max value from database
        // Use database-agnostic syntax: SUBSTR works in both MySQL and SQLite
        $posindice = strlen($this->prefix) + 6;
        $sql = "SELECT MAX(CAST(SUBSTR(ref, " . $posindice . ") AS INTEGER)) as max";
        $sql .= " FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE ref LIKE '" . $db->escape($this->prefix) . "____-%'";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj) {
                $max = intval($obj->max);
            } else {
                $max = 0;
            }
        } else {
            dol_syslog("SmartAuth mod_auth_standard::getNextValue error: " . $db->lasterror(), LOG_ERR);
            return '';
        }

        $date = !empty($object->date_creation) ? $object->date_creation : time();
        if (is_string($date)) {
            $date = strtotime($date);
        }
        $yymm = date("ym", $date);

        if ($max >= 9999) {
            $num = $max + 1;
        } else {
            $num = sprintf("%04d", $max + 1);
        }

        dol_syslog("SmartAuth mod_auth_standard::getNextValue return " . $this->prefix . $yymm . "-" . $num);
        return $this->prefix . $yymm . "-" . $num;
    }
}
